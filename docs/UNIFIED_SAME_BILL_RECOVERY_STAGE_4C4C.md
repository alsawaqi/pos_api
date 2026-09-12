# Unified staff flow 4C4C: same-bill recovery server prerequisite

Date: 2026-09-12. Parent: `2e9858ee88bfe7b2400d77b7b3f85ad85d3aafbc`.

This is the server prerequisite for explicitly reviewed local draft recovery.
It does not enable either client's recovery UI, submit an unsent addition, make
a payment, clear a seating, print food, or delete any local draft. No push,
device, APK, shared database, or test-stack operation is part of this change.
Client integration is paused pending the separate owner confirmation requested
by the implementation coordinator. This commit is not a deployment or a claim
that either app's recovery workflow is complete.

## Contract

- GET `/api/v1/device/tables/{tableId}/draft-recovery` takes `order_uuid`,
  `kind=legacy_hold|staff_rounds`, and 1–100 distinct `event_ids[]` for staff
  rounds only. Attended admission precedes lookup/validation. It uses the
  existing `qr-table-device-read` throttle and a single read-only snapshot.
- Its data is `{recovery_policy:same_bill_recovery_v1,preview_token,expires_at,
  proof}`. Proof retains the original `same_bill_draft_v1` shape, with
  `read_only:true`, `archive_authorized:false`, and
  `delta_policy:proven_local_rounds_only`. A legacy proof identifies only the
  original held-event items, even when later customer/staff rounds exist.
- POST the same path takes the same normalized proof request plus a UUID
  `client_request_id`, the exact `preview_token`, and lower-case SHA-256
  `local_snapshot_hash`. It uses the existing `qr-table-device-write` throttle.
- Successful data is `{status:processed,result:{outcome:draft_recovered,
  recovery_policy:same_bill_recovery_v1,client_request_id,local_snapshot_hash,
  order_uuid,table_id,table_session_uuid,preview_token,archive_authorized:true,
  event_id}}`. The client must match every identity/hash field before atomically
  archiving its captured original rows; this is not authority to guess a delta.
- Five-minute signed previews bind all frozen bill, round, item, credential,
  seating and immutable acknowledgment evidence. Signature-only device telemetry
  exclusions are `last_seen_at`, `updated_at`, `last_ip`, `last_lat`, `last_lng`,
  `last_battery`, `app_version`; credential exclusions are only `last_seen_at`
  and `updated_at`. Device assignment/type/status/token and meaningful credential
  fields remain bound. Ordinary heartbeat/status polling does not stale a review.
- Every response is private/no-store. No raw device state, IP, phone, customer
  identity, credential secret or original sync payload is returned in proof.

## Atomicity and replay

POST uses the existing resolver's lock order: device, branch tables, orders,
credentials, primary/other seatings; journal last. No nested read-only transaction
is opened inside that write transaction. The extracted detail reader also runs
inside the preview's existing single read-only snapshot.

An immutable existing `attached` journal row has action
`same_bill_draft_recovered`, request hash, original acknowledgment IDs, local
snapshot hash and original result. No new event type or schema was introduced.
Exact request replay returns that result and that row's ID before considering
preview expiry, payment, closed seating or archived table. Mismatched input for
the same request ID returns `draft_recovery_request_conflict` without writes.
Inactive/reassigned devices still cannot replay another scope's receipt.

A fresh request validates the signed PRE-baseline snapshot and calls the existing
`EnsureLegacyTableBillBaselineAction` under the order lock where necessary. The
original item IDs/amounts remain; accounting-only baseline is not a kitchen
submission. Evidence is checked again, then the recovery journal is queued and
all journal writes flush at the final write boundary. The response's event ID is
the recovery row, never a preceding accounting-baseline row. Baseline and receipt
are in one transaction.

Only an expired, unapplied token after the replay check produces 409
`draft_recovery_preview_stale` plus top-level `draft_recovery_final_no_write`.
That object is the complete normalized POST input plus `table_id`; staff event IDs
are sorted, and legacy input omits them. No other refusal proves final non-application.
Original drafts must always remain archived/preserved, not discarded on refusal.

## Evidence boundaries

All bill rounds must be accepted, non-review and uncancelled. Every priced-line
ownership ID must uniquely match an actual original item, including another
device/customer round's frozen name, quantity, notes, money and addons. The full
header/round/item arithmetic must agree. Pending, rejected, held, merged-review,
orphan/duplicate IDs and malformed data refuse; no repair is guessed.

Staff input must list the COMPLETE own accepted sync acknowledgment subset.
Customer/other-device items never become local additions. Historical alias keys
also identify received/failed/unresolved own attempts. A completed attached alias
is admissible only through its exact same-device, same-table, persisted alias →
primary chain and matching winner acknowledgment; offline merged-review aliases
remain refused. Additional own `order.hold`/`order.create` history for the same
bill (including failed/received history) is not silently ignored.

Unjoined open bills only; payment/charge residue, transfer, loyalty, commission,
donation, stock, fractional, discount/comp and conflicting-bill evidence refuse.
The legacy proof works before and after the deterministic accounting baseline;
the original `/draft-proof` endpoint remains unchanged and non-authorizing.

Device-only table detail adds `rounds[].client_request_id`: persisted value for a
staff round, null for a customer round. This allows a future client to verify an
unsent-delta response against the exact stored request and round without injecting
server-priced lines into a cart. Public status/feed/payment paths are unchanged.

## Verification

Clean immutable archive candidate: `0d3f1811b832e89776e0756eb865f41f1141ad96`.
This note is documentation-only after that tested PHP tree.

Harness: `unified-draft-recovery-api-suite.sh` in the external artifact directory.
It uses `php -d memory_limit=512M`, `--network none`, `/work/src`, scratch `.env`,
read-only existing vendor, SQLite `:memory:` default and both existing temporary
SQLite concurrency classes in the full suite. It runs no stack services.

Parent observed independently by root: `OK (1674 tests, 63900 assertions)`.

```text
Focused /tmp/qr003-draft-recovery.OMVvB5/result.log
Time: 00:22.985, Memory: 99.00 MB
OK (202 tests, 1629 assertions)

Full /tmp/qr003-draft-recovery.i3RO52/result.log
Time: 01:12.492, Memory: 227.00 MB
OK (1738 tests, 64531 assertions)

Pint /tmp/qr003-draft-recovery.KV1wEv/result.log
PASS ........................................................... 7 files
```

Delta: **+64 tests / +631 assertions**, zero failures; no existing assertion or
skip changed. Full logs include literal `UNIFIED_DRAFT_RECOVERY_STAFF_*` and
`UNIFIED_DRAFT_RECOVERY_LEGACY_*` preview/ACK/final-no-write payloads after real
customer adoption. Both include an actual status poll and device heartbeat
between preview and successful finalize, with frozen domain rows unchanged.

The new tests cover raw-row equality for every refusal, scope/token/input
conflicts, expiry, same-ACK replay after payment/claim/closure/archive, baseline
item/round ownership, duplicate prevention, complete alias/own history, malformed
and nonlocal frozen-line identity, and persisted staff request IDs. Existing
table detail/proof, payment, kitchen, sync and concurrency suites remain green.

No migration, config, generated file, dependency, payment handler, totals writer,
client or existing test file changed.

## Anything in this work order that is wrong

No separate new work order was supplied for this approved recovery increment.
Two existing implementation assumptions needed explicit handling: routine
heartbeat/status telemetry must not invalidate a frozen financial preview, and
an attached alias ACK names the alias plus its primary winner rather than only
the primary seating. Both are guarded and tested above. Local integration and
the independent verification agent's stack checks remain separate work; this
server prerequisite alone is not a claim that client recovery is installed.
