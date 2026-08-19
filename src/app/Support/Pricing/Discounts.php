<?php

declare(strict_types=1);

namespace App\Support\Pricing;

use DateTimeImmutable;

final class Discounts
{
    /** @param list<DiscountRule> $rules */
    public static function bestLineDiscount(
        PricingLine $line,
        int $lineIndex,
        array $rules,
        DateTimeImmutable $now,
        ?int $branchId,
        bool $isDeliveryProvider,
    ): ?LineDiscountResult {
        if ($branchId === null || $isDeliveryProvider) {
            return null;
        }

        $best = null;
        $bestAmountOmr = 0.0;
        foreach ($rules as $rule) {
            if ($rule->isOrderScope()
                || ! Applicability::ruleAppliesAt($rule, $now, $branchId)
                || ! $rule->appliesToProduct($line->productId, $line->categoryId)) {
                continue;
            }
            $amount = $rule->amountForOmr($line->lineTotalOmr());
            if ($amount > $bestAmountOmr) {
                $bestAmountOmr = $amount;
                $best = $rule;
            }
        }
        if ($best === null || $bestAmountOmr <= 0) {
            return null;
        }

        return new LineDiscountResult(
            lineIndex: $lineIndex,
            amountBaisas: Money::omrToBaisas($bestAmountOmr),
            ruleId: $best->id,
            amountType: $best->amountType,
            label: $best->name,
        );
    }

    /**
     * @param  list<PricingLine>  $lines
     * @param  list<DiscountRule>  $rules
     * @return list<LineDiscountResult>
     */
    public static function lineDiscountsFor(
        array $lines,
        array $rules,
        DateTimeImmutable $now,
        ?int $branchId,
        bool $isDeliveryProvider,
    ): array {
        $results = [];
        foreach ($lines as $index => $line) {
            $result = self::bestLineDiscount($line, $index, $rules, $now, $branchId, $isDeliveryProvider);
            if ($result !== null) {
                $results[] = $result;
            }
        }

        return $results;
    }

    public static function orderDiscountBaisasFor(OrderDiscountSelection $slot, int $rawSubtotal): int
    {
        if (! $slot->isActive()) {
            return 0;
        }

        return match ($slot->kind) {
            OrderDiscountKind::FixedAmount => $slot->fixedBaisas,
            OrderDiscountKind::Percentage => (int) round($rawSubtotal * $slot->percent / 100),
            OrderDiscountKind::None => 0,
        };
    }

    /**
     * @param  list<PricingLine>  $lines
     * @param  list<DiscountRule>  $rules
     */
    public static function selectAutoOrderDiscount(
        array $lines,
        array $rules,
        DateTimeImmutable $now,
        ?int $branchId,
        bool $isDeliveryProvider,
    ): ?DiscountRule {
        if ($branchId === null || $isDeliveryProvider || $lines === []) {
            return null;
        }

        $rawOmr = Money::baisasToOmr(LineTotals::rawSubtotalBaisas($lines));
        $best = null;
        $bestAmount = 0.0;
        foreach ($rules as $rule) {
            if (! $rule->isOrderScope() || ! $rule->autoApply || $rule->requiresManagerApproval) {
                continue;
            }
            if (! Applicability::ruleAppliesAt($rule, $now, $branchId)) {
                continue;
            }
            $amount = $rule->amountForOmr($rawOmr);
            if ($amount > $bestAmount) {
                $bestAmount = $amount;
                $best = $rule;
            }
        }

        return $bestAmount > 0 ? $best : null;
    }

    public static function ruleAsOrderSelection(DiscountRule $rule): OrderDiscountSelection
    {
        return $rule->amountType === 'percent'
            ? OrderDiscountSelection::percentage($rule->percent ?? 0.0, $rule->name, $rule->id)
            : OrderDiscountSelection::fixed($rule->fixedBaisas ?? 0, $rule->name, $rule->id);
    }
}
