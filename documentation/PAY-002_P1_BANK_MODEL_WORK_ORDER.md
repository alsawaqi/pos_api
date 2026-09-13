# PAY-002 P1 — bank ↔ SoftPOS model, propagation, and card void/refund server model: work order (Revision 2)

**Revision 2, 2026-09-13**, by the planning agent. Supersedes Revision 1 (2026-09-12/13). Changes: scope statement (server-only, both devices through shared contracts), explicit server-before-client sequence, §0.4 order B1 → B2 → B3 → B5 → B4, B5 rewritten after a line-by-line audit of the current pos_api code (facts and file:line below), verified branch points, and the list of remaining owner decisions in §6.

Source plan: `PAY-002_DUAL_ACQUIRER_SOFTPOS_ANALYSIS_AND_PLAN.md`. Decisions S1–S5 approved 2026-09-12. Owner answers 2026-09-12/13 built in (§0.6).

---

## 0. Scope, sequence, base commits, rules

### 0.1 Scope — P1 is server-only, for both devices

P1 changes **pos_admin, pos_api and pos_merchant only**. It builds every server contract the till and the handheld will both use: the bank ↔ SoftPOS profile, the config block, the card-tender snapshots, the mismatch/block rules, and the void/refund endpoints. **No Flutter code changes in P1.** The client work (buttons, SoftPOS intents, recovery, slips) for **both pos_machine and pos_handheld** is P2 (`PAY-002_P2_CLIENT_BRIDGE_WORK_ORDER.md`), which lists each app's files separately. The station gets the profile and health check in P2 but **never** void/refund.

### 0.2 Sequence — server before client

1. P1 lands and is independently verified on `qr003local` (server probes only).
2. P2 is built against the P1 contracts (the P1 export stays on the stack for P2's verification).
3. P3 (Bank Muscat on devices) needs both.
No client is built against an unverified server contract; no server change is deployed alone to old clients (old clients keep working: every new field is optional or defaulted, see B3.5).

### 0.3 Verified branch points (2026-09-13, read-only `git rev-parse`)

| Repo | Base for P1 | Branch | Note |
|---|---|---|---|
| pos_api | **`9216fc6`** | `codex/qr003-unified-staff` (worktree `~/worktrees/qr003-unified-staff-pos-api`, clean) | moved from `6a80685` on 2026-09-12; parent of the P1 branch must be recorded |
| pos_admin | **`4fe9d88`** | `codex/qr003-t9` | main `27a939b` untouched (5 dirty lines preserved) |
| pos_merchant | **`d8dc4f1`** | `codex/qr003-t9` | needed for B5 (movement type enum, order detail) |
| pos_web | — | — | untouched |
| pos_machine | (P2) `179abd8` | `codex/qr003-unified-fixes` | for reference only; not touched in P1 |
| pos_handheld | (P2) `9e1aa23` | `codex/qr003-unified-fixes` | idem |
| pos_station | (P2) `a570608` | `codex/qr003-t10` | idem |

Branch for P1: `codex/pay002-p1` in pos_api, pos_admin, pos_merchant, created from the bases above. Dependencies: pos_api tests run against pos_api's own test schema; the real schema comes from pos_admin migrations — keep both in the same commit sequence (migration first, mirror second).

### 0.4 Order of work

**B1 → B2 → B3 → B5 → B4** (schema → admin → api propagation → void/refund → handback). One commit per item, item id in the message.

### 0.5 Rules

No push, fetch, checkout switching, reset, stash, clean or merge on the source main checkouts. No production access, no `192.168.100.136` stacks other than `qr003local`, no `testday`, no `charity_db`. **No change to the charity-owned `banks` table** (pos_admin reads it only: `pos_admin/src/app/Models/Bank.php:11-38`, `BanksController.php` index only, stub `2026_05_26_010000_ensure_banks_stub.php`). Database writes only in isolated test databases and disposable `qr003local`. No real payment, APK, device, stack or deployment operation. Never weaken, skip or delete an assertion; do not alter fixtures to pass. `vendor/bin/pint --test` only. Schema only through pos_admin migrations; pos_api's `0000_00_00_000000_create_test_schema.php` mirrors every new table/column/index.

Definition of done per item: new tests fail on the parent and pass on the candidate (show both); full clean-export suites green (`git archive`, `--network none`, scratch `.env`, read-only vendor, `php -d memory_limit=512M`, SQLite `:memory:` plus the two temporary SQLite concurrency harnesses; Pest for admin/merchant); literal outputs in the handback.

### 0.6 Owner answers carried into this order

| Owner | Consequence |
|---|---|
| Banks today: Bank Dhofar, Oman Arab Bank, Bank Nizwa; Bank Muscat not yet created (owner creates it in the charity app: **B0**). | Backfill Dhofar → `mosambee_dhofar`; others → `none`; Muscat profile set in admin UI later. |
| Wrong-bank payment: record, flag, block the device's card tenders until refresh. | B3.4 |
| Old app on a non-Dhofar bank: block card payments until updated. | B3.5 (pre-tap block by withholding terminal credentials; late tender recorded + flagged) |
| Per-bank reports: pos_admin only; merchants never see the bank. | B3.6 admin only |
| Bank ↔ SoftPOS link must not depend on bank id or name. | Provider **code** from a fixed enum in code, on a POS-owned profile row; snapshotted on payments and reversals. |
| Manager void and refund of card payments on till and handheld; station excluded. | B5 server model here; P2 clients. |
| Returned goods back to stock only for ready-made items; cooked items never. | B5: `unit` stock-mode lines return; `ingredient`/`cooked`/`untracked` do not. |

**B0 — owner prerequisite:** create the "Bank Muscat" row in the charity application's bank list.

---

## B1 — Schema (pos_admin migrations + pos_api test-schema mirror)

1. **`pos_bank_softpos_profiles`**: `id`; `bank_id` unsignedBigInteger **unique**, FK `banks.id` **restrict** on delete; `softpos_provider` string(32) — values from enum `SoftPosProvider { mosambee_dhofar, mosambee_muscat, none }` (pos_admin `app/Enums/SoftPosProvider.php`, mirrored `pos_api/app/Support/SoftPos/SoftPosProvider.php`; never a DB enum); `softpos_package` string(128) nullable, filled on save from the provider default (`com.mosambee.dhofar.softpos`, `com.mosambee.muscat.softpos`, null for `none`); `currency_code` char(4) default `'0512'`; `refund_needs_transaction_id` boolean (Dhofar true, Muscat false — from the two bank documents); `void_needs_session_id` boolean (Dhofar false, Muscat true); `min_app_version` string(32) nullable (reserved); `is_active` boolean default true; `notes` text nullable; `created_by_user_id`, `updated_by_user_id` nullable; `provider_changed_at` timestamp nullable; timestamps.
2. **Backfill migration** (idempotent; no-op when no bank rows): per existing bank without a profile, name matches `/dhofar/i` → `mosambee_dhofar` (+ default package, Dhofar flags); every other bank → `none`. Logs its actions. Never updates an existing profile.
3. **`pos_payments`** (current columns: `create_pos_payments_table.php:49-95`, `2026_06_17_010000:32-41`, `2026_08_02_010000:25`; `amount` is **decimal(12,3) OMR, documented POSITIVE**, `bank_response` jsonb raw receipt, `softpos_reference` (64) + `softpos_auth_code` (32) non-unique index): add `softpos_provider` string(32) nullable, `softpos_package` string(128) nullable, `softpos_reported_provider` string(32) nullable, `softpos_mismatch` boolean default false (indexed), `softpos_mismatch_note` string(255) nullable, `softpos_transaction_id` string(64) nullable indexed, `softpos_rrn` string(32) nullable indexed, `softpos_batch_number` string(16) nullable, `softpos_card_masked` string(24) nullable, `softpos_card_type` string(16) nullable, `softpos_receipt_at` timestamp nullable, `refunded_total` decimal(12,3) default 0, `voided_at` timestamp nullable, `reversal_id` unsignedBigInteger nullable (set on the **reversal ledger rows**, see B5.1.4), `direction` string(8) default `'sale'` (`sale` | `reversal`). Update the create-migration's "POSITIVE amount" comment in the mirror/test schema docblock: reversal rows carry a **negative** amount (decision B5.1.4).
4. **`pos_devices`**: `card_tenders_blocked_reason` string(64) nullable, `card_tenders_blocked_at`, `card_tenders_unblocked_at` timestamps nullable.
5. **`pos_payment_reversals`** and **`pos_payment_reversal_lines`**: see B5.1.
6. **`pos_orders`**: `refunded_total` decimal(12,3) default 0.
7. Record (read-only) the existing `pos_devices.bank_id` FK on-delete behaviour from `2026_05_26_020000_add_bank_id_to_pos_devices.php` in the handback; do not change it.

---

## B2 — pos_admin

1. **Model + policy.** `BankSoftPosProfile` (fillable, casts, `belongsTo Bank`), gated by the existing device-control platform permission (name the key used in the handback). Admin audit rows via the existing audit mechanism: `bank_softpos_profile.created`, `.updated`, `.provider_changed`, `device.card_tenders.unblocked`, `payment_reversal.resolved`.
2. **Endpoints** (`admin/api/v1`): `GET bank-softpos-profiles` (every bank from `banks`, active first, with profile or null); `PUT bank-softpos-profiles/{bank}` (create/update: `softpos_provider`, `softpos_package` optional, `currency_code`, `refund_needs_transaction_id`, `void_needs_session_id`, `is_active`, `notes`; a provider change on a bank with assigned devices requires `confirm_provider_change: true`, else 409 `softpos_provider_change_needs_confirmation` with the device count; a change stamps `provider_changed_at` and bumps `updated_at` on every assigned device so the next config delta carries the new block).
3. **UI.** Settings → "Card terminal apps": banks table (name, short name, active, SoftPOS app, package, currency, flags, last change, by whom); edit dialog with the fixed provider list, package auto-filled and editable, the two flags defaulted per provider, confirmation naming the assigned devices. EN acceptable for admin (say so if AR is skipped).
4. **Device pages.** Register/Assign: bank dropdown shows "Bank — SoftPOS app"; assigning a `payment_station`, or any device with a `terminal_id`, to a bank whose profile is missing/inactive/`none` is refused with a readable error. Device detail/list: "Card terminal: <bank> · <provider label> · <package>", block state (reason, time) and an **Unblock** action (clears the block only; the device still needs a config refresh). `DeviceResource` gains `softpos: {provider, package, currency, blocked_reason, blocked_at}`.
5. **"Card terminal issues" page**: payments with `softpos_mismatch = true`, devices currently blocked, and **reversals in `uncertain`** with a resolve dialog (B5.2.5). No merchant view.

Tests (Pest): profile CRUD + permission; backfill idempotency with three seeded banks; provider change confirmation + device bump; assignment refusals; resource shape; issues page query incl. uncertain reversals; audit rows; reversal resolution endpoint.

---

## B3 — pos_api: propagation, tender snapshots, mismatch, block, reconciliation

1. **Resolution helper** `ResolveDeviceSoftPos(Device): ?SoftPosProfileDto` — the device's bank profile; `none`/missing/inactive → null.
2. **`device/config` meta** (full and delta, always present) and **`auth/device/activate`** (`DeviceActivateController.php:44-45`): `softpos: {provider, package, currency, refund_needs_transaction_id, void_needs_session_id, requires_manual_first_launch: true, login_requires_approved_code: true, blocked_reason, blocked_at}`; `terminal_id`/`terminal_pin` stay in `meta` as today (`BuildDeviceConfigAction.php:457-461`) except in B3.5.
3. **Card tender payload** (`order.pay`, QR `release-charge` and `claim-settlement` card paths; today `PayOrderHandler.php:222-245` persists `softpos_reference`, `softpos_auth_code`, `bank_response`): accept optional `softpos_provider`, `softpos_package`; persist server-resolved `softpos_provider`/`softpos_package`, `softpos_reported_provider`; **extract from the receipt JSON** (`bank_response`) into the new columns: `transactionId` → `softpos_transaction_id`, `retrievalReferenceNumber` → `softpos_rrn`, `batchNumber` → `softpos_batch_number`, `cardNumber` → `softpos_card_masked` (must already be masked; if it contains more than 6 leading + 4 trailing digits, store null and log), `cardType`, `date`+`time` → `softpos_receipt_at` (business timezone). Missing keys → null, never a failure.
4. **Mismatch (record, flag, block).** Reported provider ≠ resolved, or reported while resolved is null: payment written as today, `softpos_mismatch = true` + note, device `card_tenders_blocked_reason = softpos_mismatch` + `blocked_at`, ack `result.softpos_mismatch: true`. Never a refusal.
5. **Old app on a non-Dhofar bank (block).** Resolved provider ≠ `mosambee_dhofar` and request without header `X-Mithqal-SoftPos-Capable: 1` (P2 clients send it): config and activation return `terminal_id = null`, `terminal_pin = null`, `softpos.blocked_reason = softpos_app_update_required`. A late card tender from such a device without a reported provider → B3.4 treatment (record, flag, block). With the header, full block regardless of bank.
6. **Reconciliation (admin only).** `BankReconciliationService` (`pos_admin/src/app/Services/Admin/BankReconciliationService.php:168-224`: reads `card` rows by `captured_at`, matches on normalized `terminal_id + softpos_auth_code`, compares `amount` with 0.0005 tolerance, computes `bank_fee`): add a bank filter and `softpos_provider` column; treat `direction = reversal` rows as negative lines matched by their own auth code; matchable sale amount = `amount + roundup_amount` (closes hazard F-1). Update `AdminSalesReportAction`/`SalesSummaryAction` and any admin report that counts card rows to **exclude `direction = reversal` from counts** while letting sums net (list every reader touched in the handback).
7. **Guards.** Card tenders from a blocked device are recorded and flagged (`device_blocked` note); QR `claim-charge` for a blocked payment station is refused pre-tap with `softpos_blocked`.
8. **Business timezone.** New `config/pos.php` with `business_timezone` (env `POS_BUSINESS_TIMEZONE`, default `Asia/Muscat`) — none exists today (`config/app.php:68` is UTC; no `pos.php`). Used by B3.3 and B5; CORE-002 A3 will reuse it.
9. **Docs.** `docs/SOFTPOS_PROFILES.md`: block shape, header, tender fields, mismatch and block lifecycle.

Tests (PHPUnit): config/activation block per provider and `none`; delta after a provider change; tender snapshots and receipt extraction from a Dhofar-shaped and a Muscat-shaped `receiptResponse` (sample JSON from the bank documents, incl. missing keys and an unmasked PAN); mismatch flag + block + ack flag; credential withholding with/without header; blocked station `claim-charge` refused; blocked till tender recorded; reconciliation with a reversal row; replay idempotency unchanged; concurrency: two tenders under mismatch → one payment row.

---

## B5 — Card void and refund: server model (Revision 2, audited against the code)

### B5.0 Facts the design rests on (pos_api at `9216fc6`, pos_admin migrations)

- `pos_payments.amount` is decimal OMR, documented positive; `CloseShiftHandler.php:321-341` sums `amount` per method for `status = success` in the window (all methods, any order status); `BankReconciliationService` reads `card` rows with no status filter (:168-172). **Decision:** reversals are recorded as **`pos_payments` rows with `direction = reversal` and a negative `amount`** (their own `softpos_auth_code`/`softpos_reference`/`bank_response` from the void/refund receipt, `reversal_id` set). Shift tender lines then net automatically; reconciliation matches the bank's reversal line by its auth code; report counters exclude reversal rows (B3.6).
- `STATUS_REFUNDED` exists but has no writer (`Order.php:48`); `STATUS_VOID` is set only in `VoidOrderHandler.php:159`. The void core (status, items, inventory `reverse()` gated by `!$voidReason->affects_inventory`, loyalty inverse rows clamped to balance, round-up donation → `void` and payment round-up fields nulled, unclaimed commissions deleted) is **inline in the sync handler** (`VoidOrderHandler.php:68-345`); no reusable action; **payments are left at `success` after a void** (docblock :52-55).
- `ConsumeInventoryAction` has only whole-order `consume()/reverse()` (:33-42); product-unit rows go to `pos_product_stock_movements` with `BranchProduct.stock_qty` atomic increments (:364-403); types are `sale_consumption, produced, waste, give_away, carry_over` (`ProductStockMovement.php:37-53`); merchant enum `ProductStockMovementType` adds `received, allocation_*, transfer_*, adjustment`. No refund/return type anywhere.
- Manager proof today: `VerifyManagerPinAction::verify(Device, string $pin): PosStaff` (`:38-53`; company scope, `manager_approval_positions`, bcrypt); production cancel and table combine re-verify the **PIN server-side** and store `approved_by_staff_id` (`CancelProductionAction.php:44,112`; `CombineLegacyTableBillAction.php:72-83,138`). Comps only check tenant membership of a bare staff id (`CreateOrderHandler.php:569-573`, `TenantReferenceGuard.php:31-37`). **Decision: reversals take `manager_pin` and re-verify it server-side.**
- No business timezone exists (B3.8 creates it).
- `pending_verification` is the P-G7 delivery no-tender state resolved in pos_merchant (`ConfirmDeliveryOrdersAction.php:104-112`); `charge_outcome = uncertain` is the QR claim state resolved by `fallback-to-counter` or admin approve/reject of `pending_reconciliation` payments (`ApprovePendingReconciliationAction`, `RejectPendingReconciliationAction`). **Decision:** a reversal is allowed only on a card payment with `status = success`, `pending_reconciliation = false`, `direction = sale`, `voided_at` null, on an order whose status is `paid` (or `refunded`-in-progress for further partial refunds); anything else is refused.
- Attended guard pattern: `PresentQrPendingOrderAction::assertAttended()` (`fixed_pos`/`handheld`, assigned, active; `QrChargeException('device_not_attended', 409)`), responses via `QrApiResponse::success/failure` (`Support/QrApiResponse.php:25-34`), idempotency by `client_request_id` compared against the stored request (`AppendQuickQrOrderItemsAction.php:54-69`, `idempotency_conflict` 409).

### B5.1 Schema

1. `pos_payment_reversals`: `id`, `uuid` unique, `company_id`, `branch_id`, `order_id`, `payment_id` (original sale row), `kind` string(8) (`void` | `refund`), `amount` decimal(12,3) (positive, server-computed), `amount_baisas` int (same value, for the device), `currency_code` char(4), `status` string(16) (`pending` | `approved` | `declined` | `uncertain` | `cancelled`), `softpos_provider`, `softpos_package`, `bank_id`, `terminal_id`, `original_softpos_transaction_id` nullable, `reversal_softpos_transaction_id` nullable, `reversal_rrn` nullable, `reversal_auth_code` string(32) nullable, `response_code` string(16) nullable, `response_description` string(255) nullable, `receipt_json` jsonb nullable, `reason_code` string(32) nullable (void: the `pos_void_reasons.code`; refund: `refund_reason` free code), `reason_note` string(255) nullable, `void_reason_id` nullable FK, `requested_by_staff_id` nullable, `approved_by_staff_id` (FK pos_staff), `device_id`, `client_request_id` string(64), `request_fingerprint` string(64) (sha256 of the normalized request body), `attempted_at`, `completed_at`, `resolved_by_user_id` nullable (admin), `resolved_note` string(255) nullable, `ledger_payment_id` nullable (the negative payment row), timestamps. Unique `(device_id, client_request_id)`. Partial unique index `(payment_id) WHERE status = 'pending'` (SQLite mirror: enforce in code under the lock; Postgres: real partial index).
2. `pos_payment_reversal_lines`: `id`, `reversal_id`, `order_item_id`, `qty` decimal(10,3), `amount` decimal(12,3), `amount_baisas` int, `stock_mode_at_refund` string(16), `returned_to_stock` boolean default false, `product_stock_movement_id` nullable. Unique `(reversal_id, order_item_id)`.
3. Movement type `refund_return` added to pos_api `ProductStockMovement` constants and to pos_merchant `ProductStockMovementType` (and any UI label map) — merchant branch required.

### B5.2 Endpoints (device, attended, online)

**Common preconditions** (checked in order, before any write, under `lockForUpdate` on the original payment and its order): device attended (`fixed_pos`/`handheld`), assigned, active, not `card_tenders_blocked`; payment in device's company+branch; payment `method = card`, `direction = sale`, `status = success`, `pending_reconciliation = false`, `voided_at` null (`payment_not_reversible`); device resolved profile non-null and equal to the payment's `softpos_provider` and `bank_id` (`reversal_bank_mismatch`); `manager_pin` present and verified by `VerifyManagerPinAction` (`invalid_manager_pin` 401, same throttle as `verify-manager-pin`); no `pending` reversal on the payment (`reversal_in_progress` 409); order status `paid` or (refund only) already partially refunded.

1. **`POST /device/payments/{payment_uuid}/reversals`** — body `{kind, manager_pin, client_request_id (uuid), reason_code, reason_note?, void_reason_id? (void), lines?: [{order_item_id, qty}] (refund), custom_amount_baisas? (refund with no lines)}`.
   - **Void**: `void_reason_id` required (company-scoped, active); allowed only when `captured_at` is **today in `business_timezone`** and no approved reversal exists on the payment (`void_window_closed` → client offers refund instead); amount = full payment `amount` **minus `roundup_amount`** (the round-up donation is reversed by the void core, not by the bank reversal; if the donation was already forwarded, the existing rule keeps it and the void core flags it as today).
   - **Refund**: `lines` non-empty XOR `custom_amount_baisas`. Line amounts: for each line, gross share = item's frozen line total after line discounts/offers × `qty / sold_qty`; order-level discounts and comps are apportioned to lines proportionally to gross with largest-remainder rounding so that refunding every remaining quantity of every line yields exactly `payment.amount − roundup_amount − refunded_total`; tax apportioned the same way. All in integer baisas via `Money::toBaisas`, then stored as decimal. Per-item cap: Σ refunded qty across approved and pending reversals ≤ sold qty (`refund_qty_exceeds_sold`). Total cap: amount ≤ `payment.amount − roundup_amount − refunded_total` (`refund_exceeds_balance`). Custom amount: same cap, no lines, no stock. **Split tenders**: the cap is that card row's own amount. Dhofar profile (`refund_needs_transaction_id`) requires `softpos_transaction_id` on the sale (`original_reference_missing`); Muscat does not.
   - Writes the `pending` reversal (+ lines), stamps `attempted_at`, and returns `{reversal_uuid, kind, amount_baisas, currency, softpos: {provider, package, needs_session, needs_transaction_id}, original_transaction_id, description: "Mithqal <VOID|REFUND> <order reference>"}`.
   - Idempotent: same `(device, client_request_id)` with equal `request_fingerprint` → the stored reversal and its current status; different fingerprint → 409 `idempotency_conflict`.
2. **`POST /device/payments/reversals/{uuid}/result`** — body `{status: approved|declined|uncertain|cancelled, response_code?, description?, receipt_json?, reversal_transaction_id?, rrn?, auth_code?, client_request_id}`. Only the creating device; only from `pending` (or from `uncertain` to `approved|declined` with `receipt_json` present — device recovery). Under the same locks:
   - **approved void** → reversal `approved`, `completed_at`; ledger row: `pos_payments` `direction = reversal`, `method = card`, `status = success`, `amount = −reversal.amount`, `captured_at = now`, own auth code/reference/receipt, `reversal_id`; original payment `voided_at = now`; the order goes through **`VoidOrderCoreAction`** (extracted from `VoidOrderHandler` in B5.3) with the given void reason (inventory per `affects_inventory`, loyalty inverse, round-up void, commissions), note `VOID (card reversal <uuid>)`.
   - **approved refund** → reversal `approved`; ledger row as above with `−amount`; `payment.refunded_total += amount`; `order.refunded_total += amount`; order status → `refunded` when `refunded_total ≥ Σ card sale amounts − roundup` for a single-tender order, else stays `paid`; each line with `stock_mode_at_refund = unit` → `ReturnRefundedUnitStockAction`: `BranchProduct.stock_qty += qty` (atomic, same branch) and one `pos_product_stock_movements` row type `refund_return` (positive qty, `reference_type = pos_payment_reversals`), `returned_to_stock = true`; other stock modes write nothing; loyalty: **full refund** (order reaches `refunded`) → inverse earn rows exactly as the void core; partial → no loyalty change; round-up never refunded; commissions untouched in P1 (owner decision R3).
   - **declined / cancelled** → reversal closed with code and description; nothing else changes.
   - **uncertain** → reversal `uncertain`, `attempted_at` kept; original payment gets `bank_response.reversal_pending_review = true`; no ledger row; the payment stays non-reversible until resolved (B5.2.5).
   - Idempotent by `(device, client_request_id)`; a repeat with the same fingerprint returns the same outcome; a second different result for a closed reversal → 409 `reversal_already_closed`.
3. **`GET /device/payments/reversals?status=pending,uncertain`** — this device's open reversals (restart recovery). Pending reversals older than **15 minutes** with no result are auto-moved to `uncertain` by the existing scheduler cadence (`pos:reversals-sweep` every 5 min) so they surface on the admin page.
4. **`GET /device/orders/{uuid}/payments`** — the card payments of a paid order with their reversibility (`can_void`, `can_refund`, `refundable_baisas`, `refundable_lines[{order_item_id, remaining_qty}]`, `void_window_ends_at`) so both clients render the same options from one server answer.
5. **Admin resolution** (pos_admin, "Card terminal issues"): `POST admin/api/v1/payment-reversals/{uuid}/resolve` `{outcome: approved|declined, evidence_note (required), reversal_auth_code?, reversal_transaction_id?}` → applies the same approved/declined transitions as B5.2.2 with `resolved_by_user_id`; audit row. This replaces any dependency on the CORE-002 A8 resolver, which does not exist yet.

### B5.3 Refactor

Extract the paid-order void core from `VoidOrderHandler.php` into `App\Actions\Orders\VoidOrderCoreAction` (status/items/inventory/loyalty/round-up/commission, same preconditions except the sync-specific ones), have the handler delegate, and prove behaviour is unchanged: every existing void test passes untouched, plus one new test asserting identical row states via the handler and via the action for the same fixture.

### B5.4 Merchant portal (pos_merchant, small)

Order detail shows reversals (kind, amount, status, approver, time, bank response code) and lines; the bank name is **not** shown to merchants (owner). Enum label for `refund_return` in stock movement lists.

### B5.5 Tests (PHPUnit unless noted)

Every refusal code above with zero writes asserted; void allowed at 23:59 Muscat and refused at 00:01 next day (UTC container clock); void amount excludes round-up; refund arithmetic: one line, several lines, proportional order discount and comp with largest-remainder rounding, exact full-order sum, split-tender cap, custom amount, qty cap across two partial refunds then an overflow; Dhofar `original_reference_missing` vs Muscat allowed; manager PIN invalid / non-manager position / other company refused; one pending per payment under ten concurrent reserves (SQLite harness); idempotent reserve and result; approved void side effects (order void, ledger negative row, inventory per reason flag, loyalty inverse, round-up void, commissions); approved refund side effects (ledger row, totals, `refunded` transition on full, `refund_return` movements only for `unit` lines, none for `ingredient`/`cooked`/`untracked`, loyalty only on full); declined/cancelled no side effects; uncertain → non-reversible → admin resolve approved/declined (Pest, pos_admin); sweep moves stale pending to uncertain; shift Z tender lines net the reversal; reconciliation lists the reversal by its own auth code; result from another device refused; `VoidOrderCoreAction` equivalence.

### B5.6 Client contract summary for P2 (built in P2, both apps)

Reserve (`manager_pin`, lines/custom amount) → launch SoftPOS `void`/`refund` with `original_transaction_id`, and for Bank Muscat a fresh `sessionId` and `currency` → `result` → print VOID/REFUND slip → restart recovery via the `GET` in B5.2.3. Station: none.

---

## B4 — Handback

Per item B1, B2, B3, B5: files, migrations, config keys, parent-fail/candidate-pass evidence, full-suite literal output for pos_admin (Pest), pos_merchant (Pest), pos_api (PHPUnit clean export + the two concurrency harnesses), Pint `--test` on changed PHP files, commit and parent SHAs per repo, the permission key used in B2.1, the list of report readers touched in B3.6, and a "what could be wrong" note. State explicitly: no client, device, APK, stack, production, `banks`-table or charity change; no push or fetch.

---

## C. Independent verification (planning agent)

On `qr003local` with the P1 exports: seed a fourth synthetic bank "Test Bank Muscat" in the stack's own database and profiles for all four; probe config/activation for Dhofar, Muscat (with/without header) and `none` devices; push card tenders with matching, mismatching and absent provider and read `pos_payments` + `pos_devices`; provider change → delta; blocked station `claim-charge` refused; reversal flow by curl: reserve → result approved/declined/uncertain → ledger rows, stock movements, order status, shift Z netting, reconciliation lines; admin pages in the browser (profiles, device detail, issues incl. reversal resolution, reconciliation filter); merchant order detail. Verdict: `PAY-002_P1_BANK_MODEL_VERIFICATION.md`.

---

## 6. Remaining owner decisions (defaults applied until answered)

| # | Question | Default in this order |
|---|---|---|
| R1 | Round-up on a refund: never refunded (donation stays), and a void reverses it as today? | Yes |
| R2 | Partial refund and loyalty points: leave points unchanged on partial refunds, reverse only on a full refund? | Yes |
| R3 | Refunds and delivery/sale commissions: leave commissions untouched in P1 and adjust in a later report change? | Yes (untouched) |
| R4 | Bank Muscat refunds without the original transaction reference (customer taps the card again): allowed? | Yes |
| R5 | Void and refund require the device to be online (no offline reversal queue)? | Yes |
| R6 | A stale pending reversal (no result after 15 minutes) becomes "uncertain" for admin resolution? | Yes |
