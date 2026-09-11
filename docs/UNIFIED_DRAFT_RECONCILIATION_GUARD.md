# Unified staff-draft reconciliation — stage 4C1 server prerequisite

2026-09-12. Local implementation only. This is the server overwrite guard,
NOT the completed staff-held draft linking/merge UI. Do not deploy it alone
to older clients that still finalize shared bills through order.create.

## Pin and files

Repo pos_api, codex/qr003-unified-staff.
Parent 76a00afd1badca4631897c63f07d1fee9edb5662.
Worktree /home/abdallah644/worktrees/qr003-unified-staff-pos-api.
Final commit and ahead count are in the shared
documentation/QR-003_UNIFIED_DRAFT_RECONCILIATION_HANDBACK.md.

Only application change: CreateOrderHandler::writeOrder, +14/-0.
New tests: SharedTableSnapshotWriteGuardTest, 30 cases.
No migration, endpoint, event type, config, client or generated file.

## Why this must precede linking

The legacy order.hold, order.create and order.transfer paths all use
CreateOrderHandler::writeOrder. Its non-terminal same-UUID upsert purges existing
items/addons/discounts/comps and replaces them from a device cart snapshot.
A shared table bill can acquire customer or other-device rounds after that
snapshot was saved. Replacing it would drop their items and invalidate the
rounds' order_item_id ownership. Merely hiding the local draft does not fix it.

The red test-only tree e24576db019d7e1ce76f227d4ec56a79b4e0b484 showed:

    FAILURES!
    Tests: 24, Assertions: 88, Failures: 21.

All 21 failing cases expected refusal but observed processed. The three ordinary
unshared compatibility cases already passed.

## Built guard

Within the EXISTING order-row transaction/lock, after the existing tenant,
awaiting-payment and terminal guards, refuse replacement when any of these is
true for the EXISTING bill:

- order.table_session_id is not null;
- a table session, including historical/closed history, points to order_id;
- a QR/staff round points to this order with a non-null table_session_id.

Incoming order_type/source/table_id cannot bypass the ownership test.
The exception uses the existing legacy sync failure protocol:

    HTTP 200
    data.results[0].status = failed
    data.results[0].result.error = shared_table_snapshot_replacement_forbidden

This is not a table.session.* business verdict or a successful merge ACK.
No partial overwrite, automatic void, item reprice or bill merge occurs.
The sync envelope/authentication keeps its existing audit; business tables are
raw-row equal on every refusal.

Ordinary unshared held/order-create/transfer upserts remain unchanged.
An already-processed event replay stays processed/duplicate=true and returns its
original result without running the writer. Existing failed events remain
explicitly refused, never rewritten or deleted to force an ACK.

The append-only staff round endpoint still works on the same bill after a
refused snapshot, including actual staff -> customer QR adoption -> staff round.
That test observes ONE order and round numbers [1, 2, 3].

## Locking / skill influence

The Postgres best-practices skill's short-transaction and consistent-lock-order
guidance informed the guard placement: it checks the locked canonical order,
not a pre-transaction snapshot. No new table/credential/seating lock is acquired
after the order lock. Up to two bounded EXISTS reads of existing indexed links
are added for a pre-existing unlinked order; the direct order pointer short
circuits them. No new isolation/session setting or transaction-external call.
No live Postgres concurrency or performance result is claimed.

## Isolated verification

Unchanged parent, clean export /tmp/qr003-draft-safety.zebm6v:

    Time: 00:46.250, Memory: 203.00 MB
    OK (1430 tests, 62336 assertions)

Final PHP candidate tree 902a64872b558fcd6926e858319131e924eb5c40:

    Time: 00:52.667, Memory: 203.00 MB
    OK (1460 tests, 62556 assertions)

Delta +30 tests / +220 assertions, zero failures. Focused new cases:

    Time: 00:01.854, Memory: 83.00 MB
    OK (30 tests, 220 assertions)

Pint --test on both changed PHP files:

    PASS   ........................................................... 2 files

No existing assertion/skip changed. Existing concurrency classes remain included.
A committed-HEAD rerun is recorded in the shared handback.

Harness: unified-draft-safety-api-suite.sh under
C:/Users/Admin/.codex/visualizations/2026/09/03/01a0657c-23ce-7441-bd68-e02d05110e68.
Clean archives, /work layout, scratch .env, existing read-only vendor, --network
none, php -d memory_limit=512M, SQLite :memory: plus existing disposable temporary
SQLite concurrency harnesses. No shared DB or qr003local stack run.
No bare Pint, push, fetch, main change, APK/device, printer or real payment.

## Remaining implementation / owner decision

This guard alone does NOT reconcile a local draft, adopt a legacy bill into a
seating, prevent a DIFFERENT order UUID from being created at an occupied table,
archive a client draft, or extend staff-only payment admission.
Both clients remain at their stage-4B commits with conflicting drafts blocked.

There are two distinct next paths:
1. Local draft/rounds already belong to the same canonical bill: prove exact
   ownership, acknowledgements and pending deltas, then retire only the verified
   local copy with recoverable evidence and no outbox rewrite.
2. Two separate unpaid server bills: choosing a surviving reference, carrying
   frozen items/money and retiring the source is a real business merge. The
   owner was asked whether to permit manager-approved combining into the QR bill,
   retaining original item prices. No answer or authority is inferred.

Do not deploy/install this intermediate prerequisite alone. Next client changes
must stop legacy snapshot finalization of shared bills and preserve exact retries,
pending writes, local drafts, frozen money and the existing payment protections.

## Anything in this implementation instruction that is wrong

The previous plan treated draft linking as though all drafts were only local.
Some are independently mirrored server bills, and the old writer is destructive
for shared histories. This prerequisite fixes that writer, but the broader
reconciliation stage remains incomplete pending the separate-bill policy.
