# Unified staff flow — same-bill evidence, stage 4C4A

2026-09-12. Server-only prerequisite, not completed client reconciliation.
Parent eaf7fe53e20b62b8482420d453a5fe27a51a02c2 on codex/qr003-unified-staff.
No push/fetch, deployment, stack, shared-database, device, APK or payment run.

## Built contract

GET /api/v1/device/tables/{tableId}/draft-proof, existing device authentication
and qr-table-device-read throttle. Parameters: order_uuid, kind=staff_rounds
with 1–100 distinct UUID event_ids[], or kind=legacy_hold with no event_ids.
Response is private/no-store; money_unit=baisas. No client currently calls it.

ReadTableDetailAction now exposes inspect(), an extension inside its existing
consistent tenant-checked snapshot. Its ordinary handle() returns the exact
old detail projection. No extra transaction, row lock or write is introduced.
Postgres uses the existing REPEATABLE READ, READ ONLY transaction; only SQLite
was executed in this task. The database-safety skill informed this placement.

Returned proof_policy=same_bill_draft_v1, read_only=true,
archive_authorized=false. A proof is evidence, not a write ACK or reservation.
An open, unjoined, non-archived same UUID/seating is required. Payment/transfer
residue, pending rounds, comps/discounts and inconsistent/fractional items refuse.

The owner-device rule permits only the existing station-adoption exception:
the QR credential must match company/branch/table/seating/bill header device,
and that device must be a station, checked with Device::isPaymentStation().
Every proof still requires the CALLER's own processed immutable SyncEvent.
This is important because SubmitDineInQrRoundAction:266 can change order.device_id
to the station. Merely setting source=QR or a station header is insufficient.
Existing combine owner restrictions and all write/payment authorization stay.

staff_rounds returns only requested acknowledged rounds, in deterministic
event-id order: event/request/seating key, round id/number, exact order_item_ids
and frozen names, notes, quantities, prices and add-ons. It requires accepted,
non-merged, unchanged lines; exact request/ACK/bill/seating identity; unique item
ownership; per-round line/subtotal/tax/total agreement; and accepted-round sums
equal to the bill header. Other-device/customer lines are never subtracted to
guess a local delta. Missing/failed/received ACKs, duplicate rounds, cancelled/
held/changed lines and malformed stored request/ACK/add-on shapes refuse.
delta_policy=proven_local_rounds_only is a limit for a future client, not an
assertion that an unsent delta has already been proven on this device.

legacy_hold reads the latest bill.client_event_id order.hold ACK from the caller.
Its frozen line multiset must match current items not owned by any round.
It returns delta_policy=blocked_until_baseline_adoption, NEVER archive/delta
permission. Missing, mismatched or foreign original history stays refused.

No schema/config/event type, price lookup, bill rewrite, item copy, archive,
print claim, stock movement or tender is performed by the endpoint.

## Automated evidence

Fresh parent clean export /tmp/qr003-draft-proof.9ujqzg:

    Time: 01:02.229, Memory: 209.00 MB
    OK (1518 tests, 62769 assertions)

Final code candidate e10ac07a068ea29f752891dff4d0fceb83e1b8ff,
/tmp/qr003-draft-proof.drRXU6:

    Time: 00:59.698, Memory: 215.00 MB
    OK (1594 tests, 63269 assertions)

Delta +76 tests / +500 assertions. All original assertions/skips untouched.
Existing protocol-inertness whitelist was NOT changed; the existing Device
helper is used. Existing detail contracts and fixed query-count tests stay.

Final-code Pint --test, /tmp/qr003-draft-proof.2zUOwf:

    PASS   ........................................................... 5 files

Read-only code review identified missing round/header balance and malformed
request shape checks. Nine regression cases were added before fixing them:
test-only tree babcc3fe36a408dc3249cb440d78f70300024627 had six red failures.
After fixes: focused proof+detail suite 113 tests/864 assertions, then five
additional legacy malformed-add-on refusals were included in the final suite.

Harness: clean git archive, scratch .env, /work, read-only existing vendor,
--network none, PHP memory_limit=512M, SQLite :memory: plus the two existing
temporary file-backed concurrency harnesses. No Postgres/stack claim.
Harness file is unified-draft-proof-api-suite.sh in the desktop artifact folder.

## Blocker: legacy items are not an accepted-round baseline

The following is an isolated synthetic fixture, not an inspected real sale:

1. Real order.hold sync creates two 1.000 OMR items, frozen total 2.000.
2. Historical same-bill seating link is represented in the fixture.
3. Real table.session.round appends three 1.000 OMR items.
4. There are 5.000 OMR of item rows, but the header becomes 3.000.

Literal output:

    UNIFIED_LEGACY_BASELINE_GAP={"original_legacy_baisas":2000,"new_round_baisas":3000,"item_rows_baisas":5000,"observed_header_baisas":3000,"proof_refusal":"draft_proof_evidence_changed"}

RefreshQrOrderTotalsAction deliberately sums accepted rounds only. The old
held items have no owning round; hiding the local cache and appending a delta
would therefore be unsafe. This stage detects/refuses the inconsistent proof;
it DOES NOT repair that writer or make the original append path safe for it.

Owner decision requested before enabling recovery: permit a one-time,
idempotent baseline representation of those already-existing frozen items in
the same bill's round history, with no reprice, new bill/reference, duplicate
stock/tender or kitchen ticket. Then prove future rounds preserve the original
value and add durable client review/retry/compare-and-swap archival. Ineligible
accounting or missing provenance must remain blocked, never guessed.

Do not use this endpoint alone to retire local rows, resend items or unblock
payment. Both clients remain at the prior stage. Bell notifications follow
completed reconciliation, then separate verification and authorized rollout.

## Anything in this implementation instruction that is wrong

The assumption that every historically linked shared bill already has a round
baseline is false. Exact cache/ACK equality alone is not sufficient to retire
that cache and enable future appends. The explicit accounting baseline above
is still unapproved/unimplemented. No protected totals/payment handler was
changed to conceal the issue. This commit completes only the read-only server
proof prerequisite; the broader same-bill recovery work remains incomplete.
