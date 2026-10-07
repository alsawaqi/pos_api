<?php

declare(strict_types=1);

namespace App\Support\Pricing;

use App\Models\Product;
use App\Models\Tax;
use App\Support\Catalogue\ComboLines;
use App\Support\Catalogue\SaleDates;
use App\Support\Money;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * Warn-only checks over the device-authoritative order pricing projection.
 *
 * The validator deliberately never re-runs live discount or offer rules. Its
 * only live input is the company's tax configuration, protected by the
 * opened-at drift guard. Every exception degrades to the canonical unchecked
 * error result so observability can never reject an order.
 */
final class WireValidator
{
    private const ENGINE = 'php-mithqal/0.4.0';

    /**
     * @param  array<string, mixed>  $order
     * @return array<string, mixed>
     */
    public function validate(
        array $order,
        int $companyId,
        int $branchId,
        int $eventId,
    ): array {
        try {
            // The setting gate is intentionally first. An explicitly disabled
            // company never inspects even the optional payload flag.
            if ($this->enforcement($companyId) === 'off') {
                return ['checked' => false, 'reason' => 'off'];
            }

            if (($order['pricing_engine'] ?? null) !== 1) {
                return ['checked' => false, 'reason' => 'no_flag'];
            }

            $result = $this->compare($order, $companyId);
            if ($result['match'] === false) {
                $this->warning('pricing mismatch', [
                    'event_id' => $eventId,
                    'order_uuid' => (string) ($order['uuid'] ?? ''),
                    'company_id' => $companyId,
                    'branch_id' => $branchId,
                    'source' => (string) ($order['source'] ?? ''),
                    'failure_codes' => array_values(array_unique(array_column($result['failures'], 'code'))),
                    'deltas' => $this->deltas($result['failures']),
                ]);
            }

            return $result;
        } catch (Throwable $exception) {
            $this->warning('pricing validator error', [
                'event_id' => $eventId,
                'order_uuid' => (string) ($order['uuid'] ?? ''),
                'company_id' => $companyId,
                'branch_id' => $branchId,
                'source' => (string) ($order['source'] ?? ''),
                'exception' => $exception::class,
                'message' => $exception->getMessage(),
            ]);

            return ['checked' => false, 'reason' => 'error'];
        }
    }

    private function enforcement(int $companyId): string
    {
        $raw = DB::table('pos_company_settings')
            ->where('company_id', $companyId)
            ->where('key', 'pricing_enforcement')
            ->value('value');

        $value = is_string($raw) ? json_decode($raw, true) : $raw;

        return $value === 'off' ? 'off' : 'warn';
    }

    /**
     * @param  array<string, mixed>  $order
     * @return array<string, mixed>
     */
    private function compare(array $order, int $companyId): array
    {
        $failures = [];
        $suppressed = [];
        $informational = [];
        $lines = is_array($order['lines'] ?? null) ? array_values($order['lines']) : [];
        $discounts = is_array($order['discounts'] ?? null) ? array_values($order['discounts']) : [];
        $comps = is_array($order['comps'] ?? null) ? array_values($order['comps']) : [];
        $subtotal = (int) ($order['subtotal_baisas'] ?? 0);
        $discountTotal = (int) ($order['discount_total_baisas'] ?? 0);
        $compTotal = (int) ($order['comp_total_baisas'] ?? 0);
        $taxTotal = (int) ($order['tax_total_baisas'] ?? 0);
        $grandTotal = (int) ($order['grand_total_baisas'] ?? 0);
        $fractionalLines = [];

        // S1 — exact line multiplication for mathematically integral qty.
        foreach ($lines as $index => $line) {
            $line = (array) $line;
            $qty = (float) ($line['qty'] ?? 0);
            // Combo fix order 2 (C-18) — a sane maximum: flagged, never refused.
            if ($qty > ComboAllocation::MAX_COPIES) {
                $this->failOnce($failures, 'line_qty', ['line_index' => $index, 'qty_at_most' => ComboAllocation::MAX_COPIES],
                    ['line_index' => $index, 'qty' => $line['qty']]);
            }
            if ($qty != floor($qty)) {
                $fractionalLines[$index] = true;
                if (! in_array('line_qty_non_integer', $informational, true)) {
                    $informational[] = 'line_qty_non_integer';
                }

                continue;
            }

            $expected = (int) ($line['unit_price_baisas'] ?? 0) * (int) $qty;
            $actual = (int) ($line['line_total_baisas'] ?? 0);
            if ($expected !== $actual) {
                $this->failOnce($failures, 'line_total', [
                    'line_index' => $index,
                    'line_total_baisas' => $expected,
                ], [
                    'line_index' => $index,
                    'line_total_baisas' => $actual,
                ]);
            }
        }

        // S2 — main_pos tolerates the unreachable mixed demo-cart omission.
        $lineSum = array_sum(array_map(
            static fn (mixed $line): int => (int) (((array) $line)['line_total_baisas'] ?? 0),
            $lines,
        ));
        $mixedCart = ($order['source'] ?? null) === 'main_pos'
            && $lineSum < $subtotal;
        if ($mixedCart) {
            $informational[] = 'mixed_cart_unverifiable';
        }
        if ($lineSum !== $subtotal && ! $mixedCart) {
            $this->failOnce(
                $failures,
                'subtotal',
                ($order['source'] ?? null) === 'main_pos'
                    ? ['line_total_sum_at_most' => $subtotal]
                    : $subtotal,
                $lineSum,
            );
        }

        // S3 — observe the strict zero-baisa identity without changing the
        // existing server write-path's +/-1 acceptance. LAUNCH-P4: with
        // prices_include_tax the grand total already contains the tax.
        $inclusive = filter_var($order['prices_include_tax'] ?? false, FILTER_VALIDATE_BOOLEAN);
        $identity = $subtotal - $discountTotal - $compTotal + ($inclusive ? 0 : $taxTotal);
        if ($identity !== $grandTotal) {
            $this->failOnce($failures, 'identity_zero', $identity, $grandTotal);
        }

        // S9 is intentionally before S4/S5/S6: one bad index is the root
        // warning, and the dependent checks are skipped to avoid noise. A
        // mixed machine cart has no reliable snapshot-index or row-sum map,
        // so all four dependent checks degrade to the informational code.
        if (! $mixedCart) {
            $badIndex = $this->badLineIndex($discounts, $comps, count($lines));
            if ($badIndex !== null) {
                $this->failOnce($failures, 'line_index', [
                    'integer_between' => [0, max(0, count($lines) - 1)],
                ], $badIndex);
            } else {
                $this->checkDiscountRows($discounts, $subtotal, $discountTotal, $failures);
                $this->checkCompPipeline(
                    $lines,
                    $discounts,
                    $comps,
                    $subtotal,
                    $discountTotal,
                    $compTotal,
                    $fractionalLines,
                    $failures,
                );
            }
        }

        // S7 — totals-only bounds.
        $netSubtotal = $subtotal - $discountTotal;
        if ($discountTotal > $subtotal || $compTotal > $netSubtotal) {
            $this->failOnce($failures, 'bounds', [
                'discount_total_max' => $subtotal,
                'comp_total_max' => max(0, $netSubtotal),
            ], [
                'discount_total_baisas' => $discountTotal,
                'comp_total_baisas' => $compTotal,
            ]);
        }

        // LAUNCH combo add-on — combo and meal lines: flagged, never refused.
        $this->checkCombos($order, $lines, $companyId, $failures);

        $delivery = ($order['order_type'] ?? null) === 'delivery';
        if ($delivery) {
            $lineOrOfferRows = count(array_filter(
                $discounts,
                static fn (mixed $row): bool => isset(((array) $row)['line_index'])
                    || isset(((array) $row)['offer_id']),
            ));
            if ($taxTotal !== 0 || $lineOrOfferRows !== 0) {
                $this->failOnce($failures, 'delivery_tax', [
                    'tax_total_baisas' => 0,
                    'line_or_offer_discount_rows' => 0,
                ], [
                    'tax_total_baisas' => $taxTotal,
                    'line_or_offer_discount_rows' => $lineOrOfferRows,
                ]);
            }
        } else {
            $this->checkTax($order, $companyId, $subtotal, $discountTotal, $compTotal, $taxTotal, $inclusive, $failures, $suppressed);
        }

        $result = [
            'checked' => true,
            'engine' => self::ENGINE,
            'match' => $failures === [],
            'failures' => $failures,
        ];
        if ($suppressed !== []) {
            $result['suppressed'] = $suppressed;
        }
        if ($informational !== []) {
            $result['informational'] = $informational;
        }

        return $result;
    }

    /**
     * @param  list<mixed>  $discounts
     * @param  list<mixed>  $comps
     * @return array{collection: string, row: int, value: mixed}|null
     */
    private function badLineIndex(
        array $discounts,
        array $comps,
        int $lineCount,
    ): ?array {
        foreach (['discounts' => $discounts, 'comps' => $comps] as $collection => $rows) {
            foreach ($rows as $rowIndex => $rawRow) {
                $row = (array) $rawRow;
                if (! array_key_exists('line_index', $row) || $row['line_index'] === null) {
                    continue;
                }

                $lineIndex = $row['line_index'];
                if (! is_int($lineIndex) || $lineIndex < 0 || $lineIndex >= $lineCount) {
                    return ['collection' => $collection, 'row' => $rowIndex, 'value' => $lineIndex];
                }
            }
        }

        return null;
    }

    /**
     * @param  list<mixed>  $discounts
     * @param  list<array{code: string, expected: mixed, actual: mixed}>  $failures
     */
    private function checkDiscountRows(array $discounts, int $subtotal, int $discountTotal, array &$failures): void
    {
        $rowSum = 0;
        $nonOrderSum = 0;
        $orderRows = [];
        foreach ($discounts as $rawRow) {
            $row = (array) $rawRow;
            $amount = (int) ($row['amount_baisas'] ?? 0);
            $rowSum += $amount;
            if (! isset($row['offer_id']) && ! isset($row['line_index'])) {
                $orderRows[] = $row;
            } else {
                $nonOrderSum += $amount;
            }
        }

        $clampRowsMatch = $discountTotal === $subtotal
            && $orderRows === []
            && $nonOrderSum >= $discountTotal;
        $sumMatches = $rowSum === $discountTotal || $clampRowsMatch;
        if (count($orderRows) > 1
            || ($discountTotal > 0 && $discounts === [])
            || ! $sumMatches) {
            $this->failOnce($failures, 'discount_rows', [
                'discount_total_baisas' => $discountTotal,
                'order_rows_at_most' => 1,
            ], [
                'row_sum_baisas' => $rowSum,
                'order_rows' => count($orderRows),
            ]);
        }

        // S5 is meaningful only when there is exactly one order row.
        if (count($orderRows) === 1) {
            $expected = max(0, $discountTotal - $nonOrderSum);
            $actual = (int) ($orderRows[0]['amount_baisas'] ?? 0);
            if ($expected !== $actual) {
                $this->failOnce($failures, 'order_row_splitback', $expected, $actual);
            }
        }
    }

    /**
     * @param  list<mixed>  $lines
     * @param  list<mixed>  $discounts
     * @param  list<mixed>  $comps
     * @param  array<int, true>  $fractionalLines
     * @param  list<array{code: string, expected: mixed, actual: mixed}>  $failures
     */
    private function checkCompPipeline(
        array $lines,
        array $discounts,
        array $comps,
        int $subtotal,
        int $discountTotal,
        int $actualCompTotal,
        array $fractionalLines,
        array &$failures,
    ): void {
        $giftIndexes = [];
        $managerRows = [];
        foreach ($comps as $rawRow) {
            $row = (array) $rawRow;
            if (($row['is_gift'] ?? false) === true) {
                if (! isset($row['line_index'])) {
                    $this->failOnce($failures, 'comp_pipeline', 'gift row with line_index', $row);

                    return;
                }
                $giftIndexes[(int) $row['line_index']] = true;
            } else {
                $managerRows[] = $row;
            }
        }

        if (count($managerRows) > 1) {
            $this->failOnce($failures, 'comp_pipeline', ['manager_rows_at_most' => 1], ['manager_rows' => count($managerRows)]);

            return;
        }

        $managerRow = $managerRows[0] ?? null;
        $managerIndex = $managerRow !== null && isset($managerRow['line_index'])
            ? (int) $managerRow['line_index']
            : null;

        // Fractional lines are informational. If S6 would need that line's
        // net (gift or line comp), omit this comparison instead of warning.
        foreach (array_keys($fractionalLines) as $fractionalIndex) {
            if (isset($giftIndexes[$fractionalIndex]) || $managerIndex === $fractionalIndex) {
                return;
            }
        }

        $lineDiscountByIndex = [];
        foreach ($discounts as $rawRow) {
            $row = (array) $rawRow;
            if (isset($row['line_index']) && ! isset($row['offer_id'])) {
                $lineIndex = (int) $row['line_index'];
                $lineDiscountByIndex[$lineIndex] = ($lineDiscountByIndex[$lineIndex] ?? 0)
                    + (int) ($row['amount_baisas'] ?? 0);
            }
        }

        $pricingLines = [];
        foreach ($lines as $index => $rawLine) {
            $line = (array) $rawLine;
            $qty = isset($fractionalLines[$index])
                ? max(1, (int) floor((float) ($line['qty'] ?? 1)))
                : (int) ($line['qty'] ?? 1);
            $pricingLines[] = new PricingLine(
                productId: isset($line['product_id']) ? (int) $line['product_id'] : null,
                categoryId: null,
                unitPriceBaisas: (int) ($line['unit_price_baisas'] ?? 0),
                qty: $qty,
                gifted: isset($giftIndexes[$index]),
            );
        }

        $selection = null;
        $selectionQty = null;
        if ($managerRow !== null) {
            if ($managerIndex !== null && array_key_exists('qty', $managerRow) && $managerRow['qty'] !== null) {
                $parsedQty = filter_var($managerRow['qty'], FILTER_VALIDATE_INT);
                $selectionQty = $parsedQty === false ? null : $parsedQty;
            }
            $selection = new CompSelection(
                lineIndex: $managerIndex,
                qty: $selectionQty,
                reasonId: isset($managerRow['comp_reason_id']) ? (int) $managerRow['comp_reason_id'] : null,
                reason: (string) ($managerRow['note'] ?? ''),
            );
        }

        $giftAmounts = Comps::giftAmountsBaisasFor($pricingLines, $lineDiscountByIndex);
        $giftedTotal = array_sum($giftAmounts);
        $netSubtotal = max(0, $subtotal - $discountTotal);
        $expectedCompTotal = Comps::compTotalBaisasFor(
            $pricingLines,
            $lineDiscountByIndex,
            $giftedTotal,
            $netSubtotal,
            $selection,
        );
        $expectedRows = Comps::compWireRowsFor($giftAmounts, $expectedCompTotal, $selection);

        $expectedCanonical = array_map(
            static fn (CompWireRow $row): array => [
                'amount_baisas' => $row->amountBaisas,
                'is_gift' => $row->isGift,
                'line_index' => $row->lineIndex,
                'comp_reason_id' => $row->reasonId,
                'qty' => $row->isGift ? null : $selectionQty,
            ],
            $expectedRows,
        );
        $actualCanonical = array_map(static function (mixed $rawRow): array {
            $row = (array) $rawRow;
            $parsedQty = filter_var($row['qty'] ?? null, FILTER_VALIDATE_INT);

            return [
                'amount_baisas' => (int) ($row['amount_baisas'] ?? 0),
                'is_gift' => ($row['is_gift'] ?? false) === true,
                'line_index' => isset($row['line_index']) ? (int) $row['line_index'] : null,
                'comp_reason_id' => isset($row['comp_reason_id']) ? (int) $row['comp_reason_id'] : null,
                'qty' => $parsedQty === false ? null : $parsedQty,
            ];
        }, $comps);
        $this->sortRows($expectedCanonical);
        $this->sortRows($actualCanonical);

        if ($expectedCompTotal !== $actualCompTotal || $expectedCanonical !== $actualCanonical) {
            $this->failOnce($failures, 'comp_pipeline', [
                'comp_total_baisas' => $expectedCompTotal,
                'rows' => $expectedCanonical,
            ], [
                'comp_total_baisas' => $actualCompTotal,
                'rows' => $actualCanonical,
            ]);
        }
    }

    /**
     * @param  array<string, mixed>  $order
     * @param  list<array{code: string, expected: mixed, actual: mixed}>  $failures
     * @param  list<string>  $suppressed
     */
    private function checkTax(
        array $order,
        int $companyId,
        int $subtotal,
        int $discountTotal,
        int $compTotal,
        int $actualTaxTotal,
        bool $inclusive,
        array &$failures,
        array &$suppressed,
    ): void {
        $openedAt = Carbon::parse((string) ($order['opened_at'] ?? ''));
        // LAUNCH-P4 — only the EFFECTIVE taxes count: none when the merchant
        // is not VAT-registered. A registration or "prices include VAT"
        // change after the order opened suppresses the recompute, like a tax
        // row edit (the device priced with what it had).
        $policy = CompanyTaxPolicy::for($companyId);
        if (($policy->companyUpdatedAt !== null && $policy->companyUpdatedAt->gt($openedAt))
            || ($policy->settingUpdatedAt !== null && $policy->settingUpdatedAt->gt($openedAt))) {
            $suppressed[] = 'tax_recompute';

            return;
        }

        $taxRows = Tax::withTrashed()
            ->where('company_id', $companyId)
            ->orderBy('sort_order')
            ->orderBy('id')
            ->get();

        foreach ($taxRows as $tax) {
            if ($tax->updated_at === null
                || ($tax->created_at !== null && $tax->created_at->gt($openedAt))
                || $tax->updated_at->gt($openedAt)
                || ($tax->deleted_at !== null && $tax->deleted_at->gt($openedAt))) {
                $suppressed[] = 'tax_recompute';

                return;
            }
        }

        $taxes = [];
        foreach (($policy->vatRegistered ? $taxRows : []) as $tax) {
            if ($tax->deleted_at !== null || ! (bool) $tax->is_active) {
                continue;
            }
            $taxes[] = new TaxSpec(
                name: (string) $tax->name,
                nameAr: $tax->name_ar !== null ? (string) $tax->name_ar : null,
                ratePercent: (float) $tax->rate_percent,
            );
        }

        $netSubtotal = max(0, $subtotal - $discountTotal);
        $taxedBase = max(0, min($netSubtotal - $compTotal, $netSubtotal));
        $expected = $inclusive
            ? Taxes::inclusiveTaxTotalBaisasFor($taxedBase, $taxes)
            : Taxes::taxTotalBaisasFor($taxedBase, $taxes);
        if ($expected !== $actualTaxTotal) {
            $this->failOnce($failures, 'tax_recompute', $expected, $actualTaxTotal);
        }
    }

    /**
     * LAUNCH combo add-on — a combo or meal line's items against its live
     * lines (flagged, never refused): every item names a line of its combo
     * / meal; a fixed line's items are its product (extra 0) or an upgrade
     * (at the upgrade price) adding up to its quantity (none sent = served
     * as is); a choice line's items are offered by it (its category, not
     * unticked, at the item's extra price) adding up to pick N; a meal line
     * names an active meal whose mains include its product; items on a line
     * that is neither a combo nor a meal are wrong too; device-sent revenue
     * shares, when sent for every item, add up to the line total. The first
     * problem is reported as `combo`.
     *
     * @param  list<mixed>  $lines
     * @param  list<array{code: string, expected: mixed, actual: mixed}>  $failures
     */
    private function checkCombos(array $order, array $lines, int $companyId, array &$failures): void
    {
        $productIds = [];
        $mealIds = [];
        foreach ($lines as $line) {
            $line = (array) $line;
            $productIds[] = (int) ($line['product_id'] ?? 0);
            foreach (is_array($line['combo'] ?? null) ? $line['combo'] : [] as $choice) {
                $productIds[] = (int) (((array) $choice)['product_id'] ?? 0);
            }
            if (isset($line['meal_id'])) {
                $mealIds[] = (int) $line['meal_id'];
            }
        }
        $products = Product::withTrashed()->where('company_id', $companyId)->whereIn('id', $productIds ?: [0])->get()->keyBy('id');
        $comboIds = $products->filter(static fn (Product $p): bool => $p->isCombo())->keys()->map(static fn ($id): int => (int) $id)->all();
        // Fix order 1 (C-3) — a meal line names the meal its main belongs to
        // among the ACTIVE meals on sale on the order's day.
        $openedAt = (string) ($order['opened_at'] ?? '');
        $meals = ComboLines::meals($companyId, SaleDates::day($openedAt !== '' ? Carbon::parse($openedAt) : null));
        $owners = ComboLines::load($comboIds, array_values(array_intersect($meals->keys()->map(static fn ($id): int => (int) $id)->all(), $mealIds)));

        foreach ($lines as $index => $rawLine) {
            $line = (array) $rawLine;
            $product = $products->get((int) ($line['product_id'] ?? 0));
            $choices = is_array($line['combo'] ?? null) ? array_values(array_map(static fn ($c): array => (array) $c, $line['combo'])) : [];
            $mealId = isset($line['meal_id']) ? (int) $line['meal_id'] : null;
            if ($mealId !== null) {
                $meal = $meals->get($mealId);
                if ($meal === null || $product === null || ComboLines::mealFor($meals, $product)?->id !== $meal->id) {
                    $this->failOnce($failures, 'combo', ['line_index' => $index, 'meal_main' => $mealId], ['line_index' => $index, 'product_id' => (int) ($line['product_id'] ?? 0)]);

                    return;
                }
                $ownerLines = $owners['meals']->get($mealId, collect());
            } elseif ($product !== null && $product->isCombo()) {
                $ownerLines = $owners['combos']->get((int) $product->id, collect());
            } else {
                if ($choices !== []) {
                    $this->failOnce($failures, 'combo', ['line_index' => $index, 'combo' => false], ['line_index' => $index, 'choices' => count($choices)]);
                }

                continue;
            }

            $byLine = $ownerLines->keyBy('id');
            $sums = [];
            foreach ($choices as $choice) {
                $lineId = (int) ($choice['line_id'] ?? 0);
                $owner = $byLine->get($lineId);
                $item = $products->get((int) ($choice['product_id'] ?? 0));
                $extra = null;
                if ($owner !== null && $item !== null) {
                    if ($owner->kind === ComboLines::FIXED) {
                        $upgrade = ComboLines::upgradeFor($owner, (int) $item->id);
                        $extra = (int) $item->id === $owner->product_id ? 0 : ($upgrade !== null ? Money::toBaisas($upgrade->upgrade_price) : null);
                    } elseif (ComboLines::choiceOffers($owner, $item)) {
                        $extra = ComboLines::choiceExtraBaisas($owner, (int) $item->id);
                    }
                }
                if ($extra === null) {
                    $this->failOnce($failures, 'combo', ['line_index' => $index, 'item_of_line' => $lineId], [
                        'line_index' => $index, 'product_id' => (int) ($choice['product_id'] ?? 0),
                    ]);

                    return;
                }
                if ((int) ($choice['extra_price_baisas'] ?? 0) !== $extra) {
                    $this->failOnce($failures, 'combo', ['line_index' => $index, 'extra_price_baisas' => $extra], [
                        'line_index' => $index, 'extra_price_baisas' => (int) ($choice['extra_price_baisas'] ?? 0),
                    ]);

                    return;
                }
                $sums[$lineId] = ($sums[$lineId] ?? 0) + (float) ($choice['qty'] ?? 0);
            }
            foreach ($ownerLines as $owner) {
                $count = $sums[$owner->id] ?? null;
                $wanted = $owner->kind === ComboLines::FIXED ? $owner->quantity : $owner->pick_count;
                if (($owner->kind === ComboLines::CHOICE || $count !== null) && (float) ($count ?? 0) !== (float) $wanted) {
                    $this->failOnce($failures, 'combo', ['line_index' => $index, 'line_id' => $owner->id, 'items' => $wanted],
                        ['line_index' => $index, 'line_id' => $owner->id, 'items' => $count ?? 0]);

                    return;
                }
            }
            $shares = array_map(static fn (array $choice): mixed => $choice['allocated_revenue_baisas'] ?? null, $choices);
            if ($mealId !== null) {
                $shares[] = $line['main_allocated_revenue_baisas'] ?? null;
            }
            $sent = array_filter($shares, static fn (mixed $share): bool => $share !== null);
            // Fix order 1 (tester call 3, C-13) — the shares add up to what the line paid after ALL its line-level discounts.
            $paid = ComboAllocation::paidOnWire($line, is_array($order['discounts'] ?? null) ? array_values($order['discounts']) : [], (int) $index);
            if ($sent !== [] && (count($sent) !== count($shares) || array_sum($sent) !== $paid)) {
                $this->failOnce($failures, 'combo', ['line_index' => $index, 'allocated_revenue_baisas' => $paid],
                    ['line_index' => $index, 'allocated_revenue_baisas' => array_sum($sent)]);

                return;
            }
        }
    }

    /**
     * @param  list<array<string, mixed>>  $rows
     */
    private function sortRows(array &$rows): void
    {
        usort($rows, static fn (array $left, array $right): int => strcmp(
            (string) json_encode($left),
            (string) json_encode($right),
        ));
    }

    /**
     * @param  list<array{code: string, expected: mixed, actual: mixed}>  $failures
     */
    private function failOnce(array &$failures, string $code, mixed $expected, mixed $actual): void
    {
        foreach ($failures as $failure) {
            if ($failure['code'] === $code) {
                return;
            }
        }
        $failures[] = ['code' => $code, 'expected' => $expected, 'actual' => $actual];
    }

    /**
     * @param  list<array{code: string, expected: mixed, actual: mixed}>  $failures
     * @return list<array{code: string, delta: int|float|null}>
     */
    private function deltas(array $failures): array
    {
        return array_map(static fn (array $failure): array => [
            'code' => $failure['code'],
            'delta' => is_numeric($failure['expected']) && is_numeric($failure['actual'])
                ? $failure['actual'] - $failure['expected']
                : null,
        ], $failures);
    }

    /**
     * Logging itself is best-effort: a logging transport failure must never
     * turn the warn-only validator into an order rejection.
     *
     * @param  array<string, mixed>  $context
     */
    private function warning(string $message, array $context): void
    {
        try {
            Log::warning($message, $context);
        } catch (Throwable) {
            // Warn mode is fail-open even if the logger is unavailable.
        }
    }
}
