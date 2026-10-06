<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\V1\Device;

use App\Actions\Qr\BuildQrBranchMenuAction;
use App\Actions\Qr\LoadQrPricingInputAction;
use App\Actions\Qr\QrCatalogueException;
use App\Actions\Qr\QrPricePresenter;
use App\Actions\Tablet\SubmitTabletOrderAction;
use App\Actions\Tablet\TabletLoyalty;
use App\Actions\Tablet\TabletOrderException;
use App\Actions\Tablet\TabletOrderPresenter;
use App\Http\Requests\Api\V1\Tablet\TabletLookupRequest;
use App\Http\Requests\Api\V1\Tablet\TabletOrderRequest;
use App\Http\Requests\Api\V1\Tablet\TabletQuoteRequest;
use App\Models\Branch;
use App\Models\Device;
use App\Models\Table;
use App\Support\Pricing\CompanyTaxPolicy;
use App\Support\Pricing\Totals;
use App\Support\QrApiResponse;
use DateTimeImmutable;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * LAUNCH-P6 Part A item 2 — the customer tablet's own routes (its device
 * token only; EnsureCustomerTablet). Everything is scoped to the tablet's
 * merchant and branch from the token, never from the request.
 */
final class DeviceTabletController
{
    /** GET device/tablet/bootstrap */
    public function bootstrap(Request $request): JsonResponse
    {
        $device = $this->device($request);
        $branch = Branch::query()->whereKey($device->branch_id)->where('company_id', $device->company_id)->first();
        $tables = Table::query()->select(['pos_tables.uuid', 'pos_tables.label', 'pos_floors.name as floor_name', 'pos_floors.name_ar as floor_name_ar'])
            ->join('pos_floors', 'pos_floors.id', '=', 'pos_tables.floor_id')
            ->where('pos_tables.company_id', $device->company_id)->where('pos_floors.company_id', $device->company_id)
            ->where('pos_floors.branch_id', $device->branch_id)->whereNull('pos_floors.deleted_at')
            ->where('pos_tables.status', 'active')
            ->orderBy('pos_floors.display_order')->orderBy('pos_tables.display_order')->orderBy('pos_tables.id')
            ->get();
        $tax = CompanyTaxPolicy::for((int) $device->company_id);

        return QrApiResponse::success([
            'branch' => ['uuid' => $branch?->uuid, 'name' => $branch?->name, 'name_ar' => $branch?->name_ar],
            // No amounts and no customer data: a table is a name and an area.
            'tables' => $tables->map(static fn ($table): array => [
                'uuid' => (string) $table->uuid, 'name' => (string) $table->label,
                'area' => $table->floor_name, 'area_ar' => $table->floor_name_ar,
            ])->values()->all(),
            'order_types' => ['dine_in', 'quick', 'to_go'],
            // Card (SoftPOS on the tablet) comes in Phase 10.
            'card_enabled' => false,
            'currency' => ['code' => 'OMR', 'decimals' => 3],
            'tax' => ['vat_registered' => $tax->vatRegistered, 'prices_include_tax' => $tax->pricesIncludeTax()],
        ], ['money_unit' => 'baisas']);
    }

    /** GET device/tablet/menu?order_type=dine_in|quick|to_go — the customer-safe QR menu (tester call 2). */
    public function menu(Request $request, BuildQrBranchMenuAction $menu): JsonResponse
    {
        $type = $request->query('order_type');
        if (! in_array($type, ['dine_in', 'quick', 'to_go'], true)) {
            return QrApiResponse::failure('validation_failed', 'The request was invalid.', 422);
        }
        $device = $this->device($request);

        return QrApiResponse::success(
            $menu->handle((int) $device->company_id, (int) $device->branch_id, null, $type) + ['order_type' => $type],
            ['money_unit' => 'baisas'],
        );
    }

    /** POST device/tablet/quote — the server's prices for the cart (tester call 3). */
    public function quote(TabletQuoteRequest $request, LoadQrPricingInputAction $pricing, QrPricePresenter $presenter): JsonResponse
    {
        $device = $this->device($request);
        try {
            $loaded = $pricing->handle((int) $device->company_id, (int) $device->branch_id,
                SubmitTabletOrderAction::withoutNotes($request->validated('lines')),
                DateTimeImmutable::createFromInterface(now()), null, (string) $request->validated('order_type'));
            $quote = $presenter->quote($loaded, Totals::priceOrder($loaded->pricingInput));
        } catch (QrCatalogueException $exception) {
            return QrApiResponse::failure($exception->codeName, $exception->getMessage(), 422);
        }

        return QrApiResponse::success(['quote' => $quote], ['money_unit' => 'baisas']);
    }

    /**
     * POST device/tablet/loyalty/lookup — points only, never a name (tester
     * call 8); an unknown phone is a "new" customer, created only at submit.
     */
    public function lookup(TabletLookupRequest $request): JsonResponse
    {
        $device = $this->device($request);
        $phone = TabletLoyalty::phone((string) $request->validated('phone'));
        if ($phone === null) {
            return QrApiResponse::failure('phone_invalid', 'Enter an Omani phone number.', 422);
        }
        $customer = TabletLoyalty::customer((int) $device->company_id, $phone);

        return QrApiResponse::success([
            'phone' => $phone,
            'customer' => $customer === null ? 'new' : 'existing',
            'accounts' => $customer === null ? [] : TabletLoyalty::accounts((int) $device->company_id, (int) $customer->id),
        ], ['money_unit' => 'baisas']);
    }

    /** POST device/tablet/orders */
    public function store(TabletOrderRequest $request, SubmitTabletOrderAction $submit, TabletOrderPresenter $presenter): JsonResponse
    {
        $device = $this->device($request);
        try {
            $result = $submit->handle($device, $request->validated());
        } catch (TabletOrderException $exception) {
            return $exception->response();
        }

        return QrApiResponse::success(
            $presenter->forTablet($result['tablet']) + ['replayed' => $result['replayed']],
            ['money_unit' => 'baisas'],
            $result['replayed'] ? 200 : 201,
        );
    }

    private function device(Request $request): Device
    {
        /** @var Device $device */
        $device = $request->user();

        return $device;
    }
}
