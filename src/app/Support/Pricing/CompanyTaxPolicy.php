<?php

declare(strict_types=1);

namespace App\Support\Pricing;

use App\Models\Tax;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

/**
 * LAUNCH-P4 — a merchant's VAT policy, the one reader every server path uses
 * (device config, the order write and its pricing check, QR and table rounds).
 *
 *   vat_registered      admin-owned: pos_companies.vat_registered_at is set.
 *                       Not registered = no VAT at all: the effective tax
 *                       list is empty, so nothing computes or prints VAT.
 *   prices_include_vat  the merchant's switch, pos_company_settings key
 *                       `tax.prices_include_vat`; default TRUE (owner
 *                       decision: a registered merchant's menu prices
 *                       include VAT). Only an explicit false turns it off.
 *   vat_number          admin-owned: pos_companies.vat_number.
 *
 * Effective taxes = the company's active pos_taxes rows (sort order, then
 * id) when registered, else none. A server-priced order includes tax when
 * the merchant is registered and the switch is on.
 */
final readonly class CompanyTaxPolicy
{
    public const PRICES_INCLUDE_VAT_KEY = 'tax.prices_include_vat';

    private function __construct(
        public int $companyId,
        public bool $vatRegistered,
        public bool $pricesIncludeVat,
        public ?string $vatNumber,
        public ?Carbon $companyUpdatedAt,
        public ?Carbon $settingUpdatedAt,
    ) {}

    public static function for(int $companyId): self
    {
        $company = DB::table('pos_companies')->where('id', $companyId)
            ->first(['vat_number', 'vat_registered_at', 'updated_at']);
        $setting = DB::table('pos_company_settings')->where('company_id', $companyId)
            ->where('key', self::PRICES_INCLUDE_VAT_KEY)->first(['value', 'updated_at']);
        $raw = $setting?->value;
        $value = is_string($raw) ? json_decode($raw, true) : $raw;
        $number = $company?->vat_number !== null ? trim((string) $company->vat_number) : '';

        return new self(
            companyId: $companyId,
            vatRegistered: $company !== null && $company->vat_registered_at !== null,
            pricesIncludeVat: ! in_array($value, [false, 0, '0', 'false'], true),
            vatNumber: $number !== '' ? $number : null,
            companyUpdatedAt: $company?->updated_at !== null ? Carbon::parse($company->updated_at) : null,
            settingUpdatedAt: $setting?->updated_at !== null ? Carbon::parse($setting->updated_at) : null,
        );
    }

    /** Whether a server-priced order of this merchant has VAT inside its prices. */
    public function pricesIncludeTax(): bool
    {
        return $this->vatRegistered && $this->pricesIncludeVat;
    }

    /** @return Collection<int, Tax> the active tax rows that apply, none when not registered */
    public function effectiveTaxes(): Collection
    {
        if (! $this->vatRegistered) {
            return collect();
        }

        return Tax::query()->where('company_id', $this->companyId)->where('is_active', true)
            ->orderBy('sort_order')->orderBy('id')->get();
    }

    /** @return list<TaxSpec> */
    public function taxSpecs(): array
    {
        return $this->effectiveTaxes()->map(static fn (Tax $tax): TaxSpec => new TaxSpec(
            name: (string) $tax->name,
            ratePercent: (float) $tax->rate_percent,
            nameAr: $tax->name_ar !== null ? (string) $tax->name_ar : null,
        ))->values()->all();
    }

    /** @return array{vat_registered: bool, prices_include_vat: bool, vat_number: string|null} the device config `company.tax` block */
    public function deviceBlock(): array
    {
        return [
            'vat_registered' => $this->vatRegistered,
            'prices_include_vat' => $this->pricesIncludeVat,
            'vat_number' => $this->vatNumber,
        ];
    }
}
