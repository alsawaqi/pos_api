# SoftPOS profiles (PAY-002 P1)

The POS-owned profile maps a bank ID to a fixed provider code, never a bank name.
The charity bank catalogue stays read-only. Providers are `mosambee_dhofar`,
`mosambee_muscat`, and `none`; inactive, missing, and `none` resolve to no usable profile.

## Device contract

Both full and delta config always include `meta.softpos`. Activation includes
the same block at `data.device.softpos`:

```json
{
  "provider": "mosambee_muscat",
  "package": "com.mosambee.muscat.softpos",
  "currency": "0512",
  "refund_needs_transaction_id": false,
  "void_needs_session_id": true,
  "requires_manual_first_launch": true,
  "login_requires_approved_code": true,
  "blocked_reason": null,
  "blocked_at": null
}
```

P2 clients send `X-Mithqal-SoftPos-Capable: 1`. Without it, any provider other
than Dhofar withholds `terminal_id` and `terminal_pin` and reports
`softpos_app_update_required`. With the header, an unusable profile reports
`softpos_not_configured` with null provider/package/currency and withheld credentials.
A persistent mismatch block also withholds credentials. Clients must clear cached
credentials when null and must not initiate a tap while blocked. Profile changes
touch assigned devices; every delta resolves the latest profile even when no
catalogue rows have changed.

## Accepted tenders

An `order.pay` card tender may include optional `softpos_provider` and
`softpos_package`. The server snapshots its resolved provider and package, stores
the reported provider separately, and never trusts the reported package as a bank mapping.
This applies to attended QR settlement, station payment and late-authorization
orphan payment rows. At this parent, QR `claim-settlement` reserves and
`release-charge` releases a claim; neither writes a tender. Their eventual payment
is submitted through `order.pay`, which owns this snapshot.

`bank_response` may contain a parsed or JSON-string `receiptResponse`, or directly
contain receipt fields. Supported receipt keys are `transactionId`,
`retrievalReferenceNumber`, `batchNumber`, `cardNumber`, `cardType`, `date`, and
`time`. Missing/malformed fields become null. Indexed card numbers must have a mask
and expose at most six leading and four trailing digits; rejected numbers are never
copied into logs. The existing raw bank_response remains evidence.
Dates are interpreted in `POS_BUSINESS_TIMEZONE` (default `Asia/Muscat`) and
stored in UTC. Supported formats: dd/mm/yyyy, dd-mm-yyyy or yyyy-mm-dd with HH:mm:ss;
ddmmyyyy with HHmmss; dd/mm/yyyy with hh:mm:ss AM/PM.

## Mismatch and recovery

A reported provider that differs from the resolved provider (including no profile)
records the tender, sets `softpos_mismatch`, blocks the device with reason
`softpos_mismatch`, and adds `result.softpos_mismatch: true` to its sync ACK.
Late non-Dhofar legacy tenders with no reported provider get the same treatment.
An already-blocked device's accepted card tender is still recorded, with note
`device_blocked`. Existing sync replay and order locks prevent duplicate settlement.
A blocked station's new `claim-charge` is refused with `softpos_blocked` before a tap.

An operator corrects the profile, explicitly unblocks through the admin device page,
and the device refreshes its config. Unblock is audited and does not edit payment
evidence. Old clients cannot clear the update-required gate by being unblocked.

## Reconciliation and reports

The existing admin bank selector filters by payment bank snapshot, with the legacy
device fallback only for unsnapshotted rows. Sale matching uses amount + roundup_amount;
reversal matching uses its negative amount and its own authorization code. Provider
and direction are visible in reconciliation. Payment-method counts exclude reversals;
payment sums net their negative rows. Merchant bank visibility is unchanged.

## Card reversal contract for P2

P1 is server-only. Both fixed POS and handheld use this same online contract.
Payment stations and customer tablets cannot reserve reversals.

1. Read GET /api/v1/device/orders/{order_uuid}/payments for can_void, can_refund,
   refundable_baisas, refundable_lines and void_window_ends_at.
2. Reserve POST /api/v1/device/payments/{payment_uuid}/reversals:
   kind (void/refund), manager_pin, client_request_id (UUID), reason_code,
   optional reason_note, void_reason_id for void; for refund supply nonempty
   lines [{order_item_id, qty}] XOR custom_amount_baisas.
3. Use the returned data.reversal_uuid, amount_baisas, currency, description,
   original_transaction_id and softpos {provider, package, needs_session,
   needs_transaction_id}. These profile values are frozen at reservation.
   Muscat requires a fresh bank session for refund as well as void.
4. After the bank outcome, POST /api/v1/device/payments/reversals/{uuid}/result:
   status approved/declined/uncertain/cancelled, client_request_id (a separate UUID),
   optional response_code, description, receipt_json (object), reversal_transaction_id,
   rrn and auth_code. Record the bank's reversal auth/reference, not the original sale's.
5. Recover GET /api/v1/device/payments/reversals?status=pending,uncertain after restart.
   Recover uncertain to approved/declined only with nonempty receipt_json.
   Never initiate a second bank attempt merely because a server response was lost.
   Replay the original request ID and body to retrieve its stored outcome.

Reserve and result IDs have independent scopes. Changing the body for an existing
ID is an idempotency conflict; repeat reserve returns current status, repeat result
returns the exact recorded result response. PIN is reverified on reserve replay,
not persisted in the request fingerprint. Results can still be recorded by the
creating device after a card block. They cannot be reported by another device.

Void is permitted only on the capture day in POS_BUSINESS_TIMEZONE. An approved
refund or any open reversal prevents a whole-order void from duplicating stock effects.
Refund line quantity reservations count pending and uncertain rows until resolved.

Owner-approved arithmetic correction: amount already excludes the separately
stored round-up donation. Refund cap is amount - refunded_total, and void amount
is amount. Refunding all remaining line quantities allocates the exact remaining
sale balance with integer baisas/largest remainder. Custom refunds have no stock.
A selected split-tender card payment has only its own cap. Split orders stay paid;
a fully refunded single-tender order becomes refunded.

Approved results write a negative payment ledger row. Voids reuse the existing
inventory/loyalty/donation/commission core. Refunds return unit stock only, preserve
donations and commissions, and claw back earned loyalty only on full refund.
Declined/cancelled never change the sale's financial state. Uncertain marks review;
the scheduled pos:reversals-sweep moves pending older than 15 minutes to uncertain.
The admin issues page resolves uncertain operations using required evidence_note.

Clients must clear sensitive manager PIN input after reserve and use the server's
amount and profile; they must not recompute money or bank routing locally.
Client intents, buttons, recovery UI, and VOID/REFUND slips remain P2 work.
