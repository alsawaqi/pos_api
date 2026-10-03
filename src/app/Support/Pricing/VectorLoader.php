<?php

declare(strict_types=1);

namespace App\Support\Pricing;

use DateTimeImmutable;

/** Golden-vector JSON to the typed pricing input used by the PHP port. */
final class VectorLoader
{
    /** @param array<string, mixed> $json */
    public static function inputFromArray(array $json): PricingInput
    {
        $lines = [];
        foreach (self::mapList($json['lines'] ?? null) as $line) {
            $lines[] = new PricingLine(
                unitPriceBaisas: self::intOf($line['unitPriceBaisas'] ?? null),
                qty: self::intOf($line['qty'] ?? null, 1),
                productId: self::nullableInt($line['productId'] ?? null),
                categoryId: self::nullableInt($line['categoryId'] ?? null),
                gifted: ($line['gifted'] ?? false) === true,
                bundleKey: isset($line['bundleKey']) ? (string) $line['bundleKey'] : '',
            );
        }

        $discountRules = [];
        foreach (self::mapList($json['discountRules'] ?? null) as $discount) {
            $targets = [];
            foreach (self::mapList($discount['targets'] ?? null) as $target) {
                $targets[] = new DiscountTarget(
                    targetType: isset($target['targetType']) ? (string) $target['targetType'] : '',
                    targetId: self::intOf($target['targetId'] ?? null),
                );
            }
            $discountRules[] = new DiscountRule(
                id: self::intOf($discount['id'] ?? null),
                name: isset($discount['name']) ? (string) $discount['name'] : '',
                scope: isset($discount['scope']) ? (string) $discount['scope'] : 'order',
                amountType: isset($discount['amountType']) ? (string) $discount['amountType'] : 'fixed',
                fixedBaisas: self::nullableInt($discount['fixedBaisas'] ?? null),
                percent: self::nullableFloat($discount['percent'] ?? null),
                validityStart: self::date($discount['validityStart'] ?? null),
                validityEnd: self::date($discount['validityEnd'] ?? null),
                dayOfWeekMask: self::nullableInt($discount['dayOfWeekMask'] ?? null),
                timeStart: self::nullableString($discount['timeStart'] ?? null),
                timeEnd: self::nullableString($discount['timeEnd'] ?? null),
                branchScope: self::intList($discount['branchScope'] ?? null),
                stackable: ($discount['stackable'] ?? false) === true,
                requiresManagerApproval: ($discount['requiresManagerApproval'] ?? false) === true,
                isActive: ($discount['isActive'] ?? true) !== false,
                autoApply: ($discount['autoApply'] ?? false) === true,
                targets: $targets,
            );
        }

        $offers = [];
        foreach (self::mapList($json['offers'] ?? null) as $offer) {
            $offers[] = new OfferSpec(
                id: self::intOf($offer['id'] ?? null),
                name: isset($offer['name']) ? (string) $offer['name'] : '',
                type: isset($offer['type']) ? (string) $offer['type'] : '',
                nameAr: self::nullableString($offer['nameAr'] ?? null),
                config: self::map($offer['config'] ?? null),
                autoApply: ($offer['autoApply'] ?? true) !== false,
                validityStart: self::date($offer['validityStart'] ?? null),
                validityEnd: self::date($offer['validityEnd'] ?? null),
                dayOfWeekMask: self::nullableInt($offer['dayOfWeekMask'] ?? null),
                timeStart: self::nullableString($offer['timeStart'] ?? null),
                timeEnd: self::nullableString($offer['timeEnd'] ?? null),
                branchScope: self::intList($offer['branchScope'] ?? null),
                maxPerOrder: self::nullableInt($offer['maxPerOrder'] ?? null),
                isActive: ($offer['isActive'] ?? true) !== false,
            );
        }

        $compJson = array_key_exists('comp', $json) && $json['comp'] !== null
            ? self::map($json['comp'])
            : null;
        $comp = $compJson === null ? null : new CompSelection(
            lineIndex: self::nullableInt($compJson['lineIndex'] ?? null),
            qty: self::nullableInt($compJson['qty'] ?? null),
            reasonId: self::nullableInt($compJson['reasonId'] ?? null),
            reason: isset($compJson['reason']) ? (string) $compJson['reason'] : '',
        );

        $taxes = [];
        foreach (self::mapList($json['taxes'] ?? null) as $tax) {
            $taxes[] = new TaxSpec(
                name: isset($tax['name']) ? (string) $tax['name'] : '',
                ratePercent: self::floatOf($tax['ratePercent'] ?? null),
                nameAr: self::nullableString($tax['nameAr'] ?? null),
            );
        }

        return new PricingInput(
            lines: $lines,
            now: new DateTimeImmutable((string) $json['now']),
            discountRules: $discountRules,
            offers: $offers,
            orderDiscount: self::orderDiscount($json['orderDiscount'] ?? null),
            comp: $comp,
            taxes: $taxes,
            isDeliveryProvider: ($json['isDeliveryProvider'] ?? false) === true,
            branchId: self::intOf($json['branchId'] ?? null, 1),
            // LAUNCH-P4 — mithqal_pricing v0.3.0 vectors; absent = exclusive.
            pricesIncludeTax: ($json['pricesIncludeTax'] ?? false) === true,
        );
    }

    /** @param array<string, mixed> $json */
    public static function inputFromJson(array $json): PricingInput
    {
        return self::inputFromArray($json);
    }

    private static function orderDiscount(mixed $value): OrderDiscountSelection
    {
        if ($value === null) {
            return OrderDiscountSelection::none();
        }
        $json = self::map($value);

        return match (isset($json['kind']) ? (string) $json['kind'] : null) {
            'fixed' => OrderDiscountSelection::fixed(
                fixedBaisas: self::intOf($json['fixedBaisas'] ?? null),
                label: isset($json['label']) ? (string) $json['label'] : '',
                discountId: self::nullableInt($json['discountId'] ?? null),
                reason: isset($json['reason']) ? (string) $json['reason'] : '',
                loyaltyRuleId: self::nullableInt($json['loyaltyRuleId'] ?? null),
                loyaltyPoints: self::intOf($json['loyaltyPoints'] ?? null),
                loyaltyStamps: self::intOf($json['loyaltyStamps'] ?? null),
            ),
            'percentage' => OrderDiscountSelection::percentage(
                percent: self::floatOf($json['percent'] ?? null),
                label: isset($json['label']) ? (string) $json['label'] : '',
                discountId: self::nullableInt($json['discountId'] ?? null),
                reason: isset($json['reason']) ? (string) $json['reason'] : '',
            ),
            default => OrderDiscountSelection::none(),
        };
    }

    /** @return array<string, mixed> */
    private static function map(mixed $value): array
    {
        return is_array($value) ? $value : [];
    }

    /** @return list<array<string, mixed>> */
    private static function mapList(mixed $value): array
    {
        if (! is_array($value)) {
            return [];
        }

        return array_map(self::map(...), array_values($value));
    }

    /** @return list<int> */
    private static function intList(mixed $value): array
    {
        if (! is_array($value)) {
            return [];
        }

        return array_map(static fn (mixed $entry): int => self::intOf($entry), array_values($value));
    }

    private static function intOf(mixed $value, int $fallback = 0): int
    {
        return is_int($value) || is_float($value) ? (int) $value : $fallback;
    }

    private static function nullableInt(mixed $value): ?int
    {
        return is_int($value) || is_float($value) ? (int) $value : null;
    }

    private static function floatOf(mixed $value, float $fallback = 0.0): float
    {
        return is_int($value) || is_float($value) ? (float) $value : $fallback;
    }

    private static function nullableFloat(mixed $value): ?float
    {
        return is_int($value) || is_float($value) ? (float) $value : null;
    }

    private static function nullableString(mixed $value): ?string
    {
        return is_string($value) ? $value : null;
    }

    private static function date(mixed $value): ?DateTimeImmutable
    {
        return is_string($value) ? new DateTimeImmutable($value) : null;
    }
}
