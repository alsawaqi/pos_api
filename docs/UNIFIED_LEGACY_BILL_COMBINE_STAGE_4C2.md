# Unified staff flow — stage 4C2: server-side legacy bill combine

2026-09-12. Local implementation only. This is the SERVER portion, not the
completed manager review UI or a release/device-testing claim.

Owner approved explicit manager-reviewed combining of an older unpaid staff
table bill into the QR bill, retaining the QR reference and frozen prices.
The earlier stage-4C1 approval question is resolved. Nothing combines by reading
the board, opening Dine-In, polling or submitting a normal round.

## Pins and scope

- API worktree /home/abdallah644/worktrees/qr003-unified-staff-pos-api,
  branch codex/qr003-unified-staff, parent dcfe7531ffd6ed125dca238b3284ffa6332b0faa.
  Parent is 47 ahead of the existing origin/main; no fetch or push.
- Source API main remains 8c85104d692e84a1e811488bbe1fa16a9de17302 with ?? src/=60.
- Machine ccf28a2e94e717029b3e858fb0d2152819e8ed5c and handheld
  f2bbc99aadb3082255a02708f96dc95fe2c12fa7 remain clean and unchanged.
- Admin main 27a939ba635680616e422d4fbf4b4cfecb64ffdc and T9 worktree
  4fe9d88ad4cf1c1677fc911f19503a4d8cdcd9a9 unchanged. The existing orders schema
  deliberately uses a string status; no migration or test-schema mirror edit.
- No generated file, config, new sync/journal event type, client change,
  stack/shared-database/device/APK/printing/real-payment operation.

## Contract

GET /api/v1/device/tables/{numeric_table_id}/combine-preview
with source_order_uuid selects the explicit old bill. It returns a private,
no-store read-only preview: both references, sources, statuses, opened times,
frozen lines/addons/totals, combined total, five-minute preview_token,
requires_manager_pin=true, kitchen_submission=false and the reason
same_party_duplicate_bill. No phone, customer id, PIN, credential or recipe
snapshot is exposed. This endpoint works even when normal detail correctly
refuses the two-bill conflict. It does not choose a source bill automatically.

POST /api/v1/device/tables/{numeric_table_id}/combine requires:

- source_order_uuid and target_order_uuid from that preview;
- immutable UUID client_request_id and the exact preview_token;
- reason=same_party_duplicate_bill (manager must verify the same party);
- manager PIN, verified server-side under the existing company approval-position
  policy. The existing pos-login throttle protects the write endpoint.

PIN verification runs before branch locks; the exact staff/policy snapshot is
rechecked inside the transaction. No PIN/hash is persisted in the journal.
The signed preview binds the requesting device/company/branch, expiry, table,
seating, credential, both raw headers, children, discounts, coverage and rounds.
A changed bill, changed target, changed line, new round or expired preview is
refused. Client-supplied prices are never read.

Success is HTTP 200, data.status=processed, data.result.outcome=combined.
Exact retries by the same authenticated device require manager authorization
again, then return the original result/event id, even after the preview expires
or the target later changes. A reused request id with different intent refuses.
The journal stores the request hash, source/target ids, manager, reason, original
to copied item-id map and immutable result. Journal event type remains merged;
payload.action=legacy_bill_combined distinguishes it from a seating merge.

## Eligibility and explicit refusals

Only an active assigned fixed_pos or handheld in the branch may act. Source:
separate open/held main_pos or handheld dine-in bill on this primary table,
with no credential, seating or round ownership/history. Target: the same
table's open primary seating and open qr_web bill with matching non-released
credential. This does not attach two shared seatings or move/join tables.

Both bills must have no payment rows, any of the six charge-provenance fields,
pending transfer, delivery association, loyalty/commission/donation evidence or
prior stock movements. Billing/closed/terminal states are refused. A third
unpaid bill or joined coverage is refused, never arbitrarily picked.

Supported frozen source accounting: integer catalogue-product quantities,
gross unit/line totals, addon snapshots, attributed line/order discounts and
frozen tax. Header/line/discount invariants must reconcile exactly. Comp/gift
rows or amounts, fractional/custom lines, positive-quantity void lines,
inconsistent attribution and unsupported coverage fail closed. No accounting
repair, comp-to-discount conversion or current-catalogue repricing occurs.

The target header must already equal its accepted-round ledger and have no
comps. Pending target rounds remain unchanged; the preview binds them too.

Refusal codes: device_not_attended, table_not_found, order_not_found,
combine_table_not_open, combine_bill_ineligible, combine_payment_or_transfer,
combine_accounting_evidence, combine_coverage_conflict,
combine_accounting_unsupported, combine_preview_stale, combine_request_conflict,
combine_unavailable and invalid_pin. Domain refusals write no business rows;
normal device-authentication/sync-envelope audit remains unchanged.

## Atomic accounting and history

The source is retained, including all original items/addons/discounts, money,
reference and notes. Only its status=combined, closed_at and updated_at change.
Combined is NOT paid, void or refunded. No void handler/inventory reversal runs.

Frozen children are copied to the target with new ids, with ownership remapped
and all other original fields/timestamps preserved. Existing target children,
rounds, identity, reference and receipt are untouched. A new accepted staff
round represents the imported frozen subtotal/tax/total and links its new item
ids. The unchanged RefreshQrOrderTotalsAction then recomputes the sum, so a
future round cannot erase the imported amount. Existing frozen cancellation
accounting applies to the linked imported lines.

The import has accepted_seq=NULL and kitchen_printed_at=NULL: no automatic
kitchen submission and no fabricated printing evidence. A normal later round
keeps its ordinary claim/print flow. Existing clients treat the feed event as a
dirty-board signal and reload the canonical seating; no table status changes.

Only the new combined status is added to the locked Create/Hold/Transfer
terminal guard and locked Pay/Void refusals. All other payment, claim/replay,
recovery, pricing, stock and legacy replacement behavior is unchanged. No
assertion in an existing test was changed, skipped or removed.

## Locks and transaction boundary

Reuses ResolveStaffSeatingAction's device -> branch tables (id order) -> branch
orders (id order) -> credentials (id order) -> primary/alias seatings ordering,
including its restart if an order appeared while waiting for credentials.
No accepted/temp sequence is allocated. Child/header writes follow under the
already-held parent order locks; the existing journal flush is LAST and commits
atomically. Same-request replay preserves its original event id only for this
new internal combine operation. Other resolver outcomes are unchanged.

The Postgres best-practices skill's short-transaction/consistent-lock-order
guidance informed reuse of this ordering and moving the expensive PIN check
before it. There are no external calls under the transaction. This is code and
isolated SQLite evidence, not a live-Postgres concurrency claim.

## Test evidence

Harness: unified-combine-api-suite.sh in the Codex artifact directory. Clean
git archives, /work layout, scratch .env, read-only existing vendor, --network
none, php -d memory_limit=512M, sqlite :memory: plus the unchanged two existing
temporary SQLite concurrency classes. No qr003local run.

Fresh parent export /tmp/qr003-combine.YBQBue:

    Time: 01:03.478, Memory: 203.00 MB
    OK (1460 tests, 62556 assertions)

Final focused candidate 316e11587593a174013a8cf8b66ad6ca0a9c651c,
export /tmp/qr003-combine.h8U3S8:

    Time: 00:02.934, Memory: 89.00 MB
    OK (52 tests, 179 assertions)

Pint --test -v on all ten changed PHP files, /tmp/qr003-combine.5FSqgE:

    PASS   .......................................................... 10 files

Full final candidate 316e11587593a174013a8cf8b66ad6ca0a9c651c,
export /tmp/qr003-combine.DFcMyg:

    Time: 01:02.363, Memory: 209.00 MB
    OK (1512 tests, 62738 assertions)

Delta versus the freshly rerun parent: +52 tests / +182 assertions, no failures.
The full count also includes the existing route-driven assertions for the new
routes; the new focused class alone observes 52 / 179.

Final commit SHA and committed-HEAD results are recorded in the shared
QR-003_UNIFIED_LEGACY_BILL_COMBINE_HANDBACK.md. No client suite/analyzer rerun is
claimed; those repos did not change.

Coverage includes raw-row equality on refusals, tenant/device/PIN policy,
stale/expired/tampered preview, charge provenance, paid/third/joined/shared
source refusals, exact post-loss retry, immutable originals, frozen append and
cancellation, no kitchen feed addition, no inventory at combine, three units
deducted exactly once at survivor payment, and injected journal-write rollback.
Old hold/create/transfer/pay/void paths assert the exact combined-status error.

Frozen arithmetic fixture: old bill subtotal 2468, discount 168, tax 115,
total 2415 baisas + QR 1000 = 3415. Another normal 1000 round gives 4415.
Cancel one imported unit: retained imported subtotal 1234, line discount 34,
order discount 50, tax 58, total 1208; whole bill 3208. Archived source remains
2415. Live catalogue changed to 99 OMR: zero catalogue reads at combine and no
price change. Stock 10 -> 10 at combine -> 7 at the single survivor payment.

Literal synthetic HTTP result (focused test):

```json
{"data":{"status":"processed","result":{"outcome":"combined","source_order_uuid":"3789f34d-8694-46af-9c82-0463ef533a08","source_status":"combined","order_uuid":"1f1e91e6-7d79-432e-b137-0b9ddedb9545","table_session_uuid":"bf3b0ab2-3c6b-4325-9796-1d88915352da","temp_reference":"T-0911-001","receipt_number":null,"round_id":2,"grand_total_baisas":3415,"approved_by_staff_id":700,"event_id":3}},"meta":[],"errors":[]}
```

## Remaining client work / do not roll out this intermediate stage

Both client manager-review screens, durable immutable combine intents, explicit
retry, and recoverable CAS-checked local draft retirement remain to implement.
Do not delete a held draft, clear a table or rewrite outbox rows merely because
a preview succeeds. Only an exact confirmed source/target ACK plus proof that
the local snapshot has not changed and has no pending writes may retire it.
Same-bill local drafts need a separate ownership/acknowledgement/delta proof;
this two-bill combine is not a substitute.

An API cannot observe an offline device or an external tender already underway
in a legacy local checkout. The client stage must prevent starting/combining
against those unacknowledged local operations and protect the source before
any tender. These server tests prove DB atomicity/refusal, NOT absence of an
external card capture. No standalone deployment/install of this stage.
It also does not prevent a legacy DIFFERENT UUID creating a fresh third bill.
Coordinated client migration and independent stack/device checks remain gates.

## Anything in this implementation instruction that is wrong

Nothing contradicts the approved same-party, explicit manager policy. However,
that policy alone is not a complete contract for comps/gifts, fractional/custom
lines, joined tables or two already-shared histories. Those cases are explicitly
refused; they are NOT reported as implemented. Nor can a server-only combine
resolve local pending deltas or guarantee no offline external tender is underway.
The full requested cashier flow remains incomplete until the client phase.
