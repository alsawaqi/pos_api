# Unified staff flow — stage 4C4B: legacy same-bill accounting baseline

Owner-approved implementation, 2026-09-12. Parent:
`e8de34bac059e6a90bf6097309a12d2a450e262d` on `codex/qr003-unified-staff`.

## What changes

An eligible original held bill, already linked to the same seating, receives
one accepted accounting-only round before a new writer can refresh its totals.
The original item/add-on/cost snapshots stay in place with their original ids.
No copy, catalogue repricing, new bill, reference, stock movement, tender,
receipt or kitchen ticket is created by this correction.

The original processed `order.hold` acknowledgement, its device/tenant scope,
exact frozen item multiset and accounting must agree. The only alternative
header admitted is the reproduced defect: header equals accepted rounds alone
while original held items are still present. It is corrected to baseline plus
accepted rounds. Any other discrepancy is refused for separate review.

Original prices, item names, add-ons and tax come from retained snapshots, not
the current catalogue. Comps, discounts, fractional/custom lines, joins,
unverifiable/changed original items, transfer/payment/stock evidence and
already-quoted unbaselined legacy bills remain refused. Reopen/review is needed
before eligible unquoted accounting can change; no live claim is modified.

## Identity, concurrency and atomicity

- Internal action: `EnsureLegacyTableBillBaselineAction::handle(Order)`.
  It is not a new endpoint or a backfill command.
- Caller already holds the order lock. No extra device/table/credential lock,
  no historical sync-event lock, no nested read-only snapshot and no early
  journal flush is introduced. Parent serialization plus existing unique
  `(table_session_id, client_request_id)` excludes duplicate baselines.
- Baseline request id: `legacy-baseline:<order UUID>`. A pre-existing conflicting
  row is refused, never overwritten. Original hold ACK stays immutable.
- Baseline owns original `order_item_id`s; `qr_session_id`, `accepted_seq`,
  `confirm_payload` and `kitchen_printed_at` are null; lines have
  `accounting_only=true`. No fabricated printing evidence.
- Round number is current bill maximum plus one. A first baseline is round 1,
  then the new round is 2. Repairing existing history appends the accounting
  row without renumbering previously acknowledged rounds.
- Journal uses existing `attached`, action `legacy_bill_baselined`, provenance
  ids and before/after accounting. Caller flush stays last. Rollback removes
  baseline, correction and queued journal together.

## Writer and payment gates

`AppendStaffRoundAction`, `ConfirmStaffRoundAction`, `CancelStaffLineAction`,
`SubmitDineInQrRoundAction`, `ConfirmDineInQrRoundAction`,
`FinishDineInQrOrderAction` and `ClaimQrSettlementAction` call the baseline
before mutation/accepted-round counting. Existing replay/admission gates stay
ahead of correction. The customer baseline precedes round numbering and
credential adoption, with no change to the credential-specific identity rule.

`ClaimQrChargeAction` gets only a read-only legacy completeness gate before a
NEW charge. Same-holder live claim replay and its existing frozen amount stay
unchanged. Existing hard-deleted QR orphan recovery is retained only for the
original unanchored, accepted-roundless, safe-fallback shape. A held bill with
accepted rounds is not treated as that orphan.

Unanchored round-origin histories are not fabricated into legacy holds. Empty
histories keep their existing path. Where actual items exist, accepted money
and item gross must balance; missing anchor plus unrepresented items refuses.
Shipped pre-link pending/merged cancellation semantics remain unchanged.

Business refusal code: `legacy_baseline_review_required`. Direct QR/charge
operations return 409. Staff round/cancellation sync catches this one domain
refusal AFTER the action rollback and uses the existing `processed` /
`result.outcome=bill_unpaid`, `needs_review=true`, `refusal_code` review path.
It creates no new sync or journal event type and cannot wedge retries. The
new incoming sync ACK is the only write on such a refused sync request.

## Printing and draft ownership

Accepted-feed exclusion through null `accepted_seq` is not sufficient alone:
the shared kitchen claim/result locked path also refuses accounting-only
lines. Existing combine imports are recognized through their exact
tenant/seating/bill/round journal, not a request-id prefix. Claim, claim replay,
failed-ticket retry and result recording all refuse without writes. Historical
non-accounting nullable-sequence rounds remain printable.

Read-only draft proof cannot count an accounting-only replay as a new local
staff delta. It still never grants archival permission. No client calls the
baseline directly; durable local draft recovery remains the next stage.

## Boundaries

`RefreshQrOrderTotalsAction`, `CreateOrderHandler`, `PayOrderHandler` and
`VoidOrderHandler` remain byte-unchanged. No schema, migration, config,
dependency, generated file, client, device, APK, production/stack database,
real tender, push or fetch. The database safety guidance influenced reuse of
the existing order lock and delayed journal flush rather than new lock edges.

The single changed shipped assertion is the reproduced bug expectation in
`TableDraftProofTest`: header `3.000` -> `5.000`; item sum `5000`, proof refusal
and all no-write assertions stay. Its printed observed-header literal likewise
changes `3000` -> `5000`. All other existing assertions/skips remain intact.

Exact clean-export suite/Pint outputs, commit ids, per-file inventory and
remaining work are recorded in the shared
`documentation/QR-003_UNIFIED_LEGACY_BASELINE_HANDBACK.md`.

## Anything in this work order that is wrong

There is no new numbered work order for this increment; the owner explicitly
approved correcting the accounting blocker reported in stage 4C4A. The prior
round-only header behavior and the direct-print import loophole were wrong
and are corrected here. This is not completed client reconciliation, bells,
independent Postgres verification or rollout. Do not deploy this increment alone.
