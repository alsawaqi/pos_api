# Unified staff checkout — server prerequisite (3A)

This commit is a server prerequisite, NOT the completed payment-page integration.
The till and handheld UI/payment code are unchanged. Nothing was deployed.

## Read-only checkout contract

`GET /api/v1/device/qr/orders/{uuid}/checkout`

Bearer device authentication; existing `qr-table-device-read` throttle.
Only active, assigned `fixed_pos` and `handheld` devices. Admission precedes lookup.
The order must belong to that company and branch and be `qr_web`, either quick
with no table or dine-in with a table. Other shapes and tenants return 404.

The caller must already hold this order's live, in-flight attended claim:

- status awaiting_payment, charge_outcome null;
- charge_device_id equals the authenticated device;
- claimed_at and deadline present, deadline strictly in the future;
- charge amount present and equal to the bill's grand total in integer baisas;
- no nonzero station donation round-up attached to this attended checkout.

Otherwise: HTTP 409 `qr_checkout_claim_required`, with no writes. This read never
acquires, extends, clears or repairs a claim, revives a credential, prices a line,
changes a total or creates a payment. It locks the order only for a consistent
snapshot; existing writers remain responsible for money and claim transitions.

Success uses the existing QR envelope:

```text
data.order    = existing pending-order mapOrder fields ONLY, no route/action flags
data.customer = null OR {id, name, phone}, scoped to order.customer_id + company
data.claim    = {order_uuid, charge_amount_baisas, charge_claimed_at, charge_deadline_at}
meta          = {money_unit: "baisas"}
errors        = []
```

The full customer phone is for this claimed, attended checkout only. Soft-deleted
customers retain the attribution already held by the bill; foreign/missing/null
customer links return null. No wallet, loyalty balance, device credentials, session
tokens or secret hashes are returned. Responses have `Cache-Control: private,
no-store`. Do not put this snapshot or the full phone into an inbox polling cache,
analytics, printed test evidence or the addition-request journal.

Existing public, pending-list, board and station response shapes stay unchanged.
`PresentQrPendingOrderAction::mapOrder` only changes visibility for internal reuse;
its body is unchanged.

## Existing payment rules verified, NOT changed

Both attended device types accept a single Cash, Card, Bank POS or Gift tender,
and exact-total Cash+Card / Cash+Bank POS splits, using existing `order.pay`.
That statement describes the server contract, not newly enabled UI choices.
Gift must retain the normal page's manager authorization before any client use.

Tests prove claim -> same-holder replay -> successful payment to the existing
UUID; exact original total/customer/reference; one bill; same event replay adds
no payment; another payment event fails; competing device claim fails. One baisa
short or over, pending-reconciliation card status, competing-device payment,
expired claim and uncertain outcome cannot change the bill or create a payment.

PayOrderHandler, ClaimQrSettlementAction, ClaimQrChargeAction, ReleaseQrChargeAction,
QrChargeRecoveryGuard, pricing, inventory, lifetimes, sync handlers and schema are
unchanged. No migration, configuration key or new sync event.

## Required client continuation (3B/3C — not implemented by this commit)

1. Preserve the stage-2 unresolved-addition guard, then acquire the existing QR
   claim BEFORE presenting a money-ready checkout; obtain this fresh snapshot.
2. Host the existing normal payment presentation using immutable server lines
   and amounts. Do not call resumeHeldOrder, createDraft, CartItem.fromMap,
   payAndPrint or any normal order.create path for this bill. Do not replace the
   staff's current cart or write dining_tables from the snapshot.
3. Keep money/identity-editing callbacks disabled while the claim freezes the
   bill. Display customer information; do not turn a displayed phone into a
   client-side change to order ownership, loyalty, prices, discounts or tax.
4. Revalidate with the SAME-HOLDER claim replay immediately before physical
   tender. A snapshot read is not a substitute. Compare UUID, frozen amount and
   claim identity/deadline, retaining the existing pre-tender expiry margin.
5. Use integer-baisa tender allocations, exactly summing to the frozen amount.
   Bank POS is an external-terminal record, not an integrated Card capture.
   Retain manager-gated Gift and never copy a normal checkout's unsafe retry
   choice into an uncertain QR payment attempt.
6. Persist an immutable standalone order.pay attempt and retain unknown results
   across restart; no second tender, new event ID or new order on a lost ACK.
   Explicit refusal, pending ACK, cancelled/pre-dispatch terminal result and
   post-dispatch unknown need separate recovery paths. Partial split captures
   must preserve every captured leg and cannot be treated as a safe cancellation.
7. Back/Cancel must run the existing safe release flow, never locally mark paid,
   clear charge provenance, reset a table or assume a failed release succeeded.
   Existing to-counter/recovery actions remain the only way to clear evidence.
8. The handheld has no equivalent claim/outbox/recovery implementation at its
   stage-2 parent. Add and test that boundary before enabling its payment button.
   Keep the old till QR entry points fenced by the same unresolved-attempt guard.
9. Prove no cart mutation/order.create with spies; preserve all existing normal
   payment, QR-money, table and integrated-skip assertions. Run both full clean
   Flutter suites/analyzers and narrow/wide EN/AR checkout evidence before rollout.

The server full-suite/Pint literal results and commit pin belong in the workspace
`documentation/QR-003_UNIFIED_STAFF_FLOW_PROGRESS.md` stage 3A record.
