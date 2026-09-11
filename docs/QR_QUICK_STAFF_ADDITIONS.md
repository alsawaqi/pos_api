# Unified staff flow: stage 1 — QR quick-order additions

Server-only prerequisite for the owner's 2026-09-11 request, not a UI rollout.

## Endpoint

POST /api/v1/device/qr/pending-orders/{order_uuid}/items

Authenticated, active, assigned fixed_pos or handheld; existing qr-table-device-write throttle.
Company/branch are taken from the device, never the request.

~~~json
{
  "client_request_id": "d5a1532b-dada-46d1-bfea-4c031e7c61b6",
  "lines": [{"product_id": 105, "qty": 2, "addon_ids": [], "notes": null}]
}
~~~

No client money is accepted. Lines reuse existing QR validation limits. Staff may
sell branch-sold, non-internal products hidden from the public menu; public
menu/quote visibility stays unchanged.

Success uses data/meta/errors, meta.money_unit = baisas. data.order is the current
QR-pending order shape. data.addition contains id, round_no, subtotal_baisas,
tax_baisas, total_baisas and server-frozen priced_lines with ownership item IDs.
data.replayed distinguishes a no-write retry.

## Safety and accounting

- Only held, unpaid qr_web quick orders with no charge provenance accept NEW items.
  Station-routed orders must first use the existing safe to-counter action.
  Additions never clear live/uncertain/expired/declined/cancelled payment evidence.
- Existing request ID on the same bill returns a no-write acknowledgement, even
  after claim/payment. Changed lines with that ID return idempotency_conflict.
  Clients must persist an unresolved request ID and its original payload.
- Lock order: attended device -> order -> stock rows in product-ID order.
  The bill lock serializes additions with payment claims.
- The shared server engine prices only the NEW batch. Historic customer items,
  discounts, identity, session, reference and comp amounts remain frozen.
  Header money is existing baisas plus the new batch, as with staff table rounds.
- Stock admission counts prior unpaid items and pending rounds. Inventory is
  deducted only by existing payment processing, not by this endpoint.
- The existing append-only round table records additions linked to the quick bill,
  not to a phone credential or table seating. No migration or sync event is added.
  Quick additions stay outside the dine-in auto-print feed.
- Public quick status reports the updated total and same reference without a
  response-shape change. Existing payment handlers/coordinator are untouched.

Refusals: device_not_attended (409), order_not_found (404), charge_already_claimed
(409), qr_charge_recovery_required (409), order_not_editable (409),
idempotency_conflict (409), validation_failed/client_priced_payload_rejected or
existing catalogue errors (422). Refusals preserve bill and payment rows.

## Verification and next stages

QrQuickStaffAdditionTest covers both staff device types, five session states,
identity/line preservation, retry safety, charges, stock, validation, visibility,
exact discount/add-on/tax accounting, public status and print-feed exclusion.
Two additions to the existing QrDineInConcurrencyTest harness cover ten replays
and addition-versus-claim races. Existing assertions remain unchanged.

Verify clean exports, read-only vendor, --network none, SQLite :memory: default,
existing temporary SQLite concurrency harnesses, and vendor/bin/pint --test.
No PostgreSQL/device verification or deployment is claimed by this stage.

Still to build: QR Quick Orders inbox and staff item editor; deduplicated bell
notifications; unified Dine-In controls; normal payment-page integration retaining
reservation/recovery safeguards; handheld parity; EN/AR and device acceptance.
