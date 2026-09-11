# Unified Dine-In — stage 4A read prerequisite

Implemented 2026-09-12. This is the server read prerequisite, not the client
navigation/occupancy/editor rollout. No device or running stack was changed.

## Endpoint

`GET /api/v1/device/tables/{numeric_table_id}/detail`

Authenticated active/assigned fixed_pos or handheld only. Admission precedes
table lookup and the stored device assignment is checked again in the snapshot.
Company, branch, floor, table, canonical seating and bill links are scoped.
The existing `qr-table-device-read` throttle is reused, not redefined.
All controller responses are `Cache-Control: private, no-store`.

The physical `table` and the canonical `seating` can name different tables when
the requested table is joined. `seating.uuid` and `seating.table_id` identify the
primary party. `selected_table_session_uuid` identifies the clicked live member.
Aliases never create an additional bill. The returned bill keeps its original
UUID, reference, source, items and integer-baisa totals.

Top-level data keys:

- `table`: id, label, floor_id, configured status, archived.
- `occupied`: live seating, unpaid covering bill, or unexpired legacy credential.
  This is a display projection. It never updates pos_tables/dining_tables.
- `orphaned`: unpaid bill without a live seating on the selected table.
- `seating`: primary UUID/table, selected member UUID, status, origin, reference,
  opened/expires/billing timestamps, joined table IDs; null for unseated legacy bills.
- `credential`: status (including released), origin, expired flag. No token,
  secret, fingerprint, coordinates, client UUID or phone.
- `bill`: existing safe order mapper minus customer_id, plate_number and delivery;
  adds the existing charge classification. No claim credentials or action grants.
- `rounds`: ALL bill-linked staff/customer rounds, ordered by round_no then id,
  plus bill-less rounds on the exact family/credential chain. Includes rejected,
  pending and accepted history, catalogue holds and cancellation display evidence.
  Old handover credentials do not hide retained bill rounds.

Round fields: id, round_no, status, entered_by, needs_review, priced_lines,
subtotal_baisas, tax_baisas, total_baisas, submitted_at, resolved_at,
kitchen_printed_at. Frozen lines use an explicit allowlist. Unknown line/add-on/
cancellation metadata and confirm_payload are never emitted. Full customer
details remain confined to the existing claimed-checkout endpoint.

Empty/new parties never inherit a previous paid bill's history. Unpaid legacy
and expired-seating bills remain visible without a backfill or revival.
Conflicting covering bills or inconsistent canonical links return
`409 table_bill_conflict` without choosing the latest bill or changing anything.
Unknown/foreign tables return 404 table_not_found. Unsupported/nested Postgres
snapshot contexts return 503 table_snapshot_unavailable before reading.

## Consistency and privacy

Postgres: a fresh, transaction-local `REPEATABLE READ, READ ONLY` snapshot is set
before its first query. No session default is changed; no FOR UPDATE/SHARE locks,
sequence allocation, journal flush, lazy expiry, money write or external call.
SQLite tests use an ordinary read transaction. This endpoint is read-only and
does not add a lock-order edge to the existing writer graph.

The Postgres skill's short-transaction/consistent-lock/query-scope guidance
informed this choice. The transaction behavior is documented by PostgreSQL:
https://www.postgresql.org/docs/current/sql-set-transaction.html
No Postgres instance was run for this implementation. The Postgres setup is
covered by a mocked connection-order test, NOT a live Postgres verification.

## Client continuation contract

This response is a display snapshot, never authority to charge or append:

1. Use the existing table board for floor occupancy; this detail for the selected
   shared bill. Do not insert server-priced items into either local cart.
2. New staff rounds use the existing table-session round action, the resolved
   canonical UUID/table, a durable request ID and only item/quantity/modifier/note
   inputs. Preserve retry identity across an uncertain response. Do not create a
   separate local order for a customer-occupied table.
3. Customer and staff review routes remain distinct. `entered_by` identifies
   which existing route to use; server refusal always wins over a stale display.
4. QR/adopted QR bill payment keeps claim -> private checkout -> normal payment
   page -> same-holder replay -> immutable standalone order.pay. The read does
   not broaden QR claim eligibility to unadopted staff-only bills.
5. Never treat this read as a print claim. Keep the existing kitchen claim/result
   workflow; no printing was introduced by this prerequisite.
6. Clearing, reopening, release and manager recovery still use the existing
   guarded actions. Reading does not mark a bill paid/free or revive a phone.
7. Complete till AND handheld integration, remove separate QR Tables navigation,
   and test offline/legacy-local reconciliation before any device rollout.

The existing payment handlers, claims/release/reopen, pricing, stock, journal,
board/feed/public contracts and all old assertions remain unchanged.

## Local verification

Full clean-export baseline: 1,384 tests / 61,917 assertions.
Focused new coverage: 46 tests / 416 assertions, zero failures.
Pint --test: PASS, 6 changed PHP files. No bare Pint or migration.
Read query count: 11 for one round and 11 for 31 rounds, including authentication.
The test snapshots compare every pos_* table before/after each protected read.

Harness: unified-table-detail-api-suite.sh in the shared Codex artifact directory.
Clean git archives, /work layout, scratch .env, read-only vendor, --network none,
php -d memory_limit=512M, sqlite :memory: default and the two pre-existing
temporary SQLite concurrency harnesses. Final full-suite output and commit SHA
are recorded in the shared implementation handback.

First focused draft: 35 tests, 95 assertions, 23 failures caused by two new-code
Laravel API mistakes (orWhereKey and modelKeys on an empty base collection).
Fixed in implementation; no assertion was changed, skipped or removed.
