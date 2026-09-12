# Stage 4C3 server prerequisite — approved local-owner restriction

Owner approved 2026-09-12. Parent 6a80685ab7cd21d05c90aba6877689af3c6bdf24.

Preview and locked confirmation require source.device_id == calling device.
Both bills reject transferred_from_device_id and transferred_at as well as
the existing pending transfer destination. No change to ordinary offline
payments, transfer behaviour, QR claims, totals, inventory or print logic.
Preview adds combine_policy=local_owner_v1 and table_session_uuid so clients
can fail closed on an old server and validate the exact seating ACK.

Approved test change: the former other-handheld/fresh-preview success now
refuses combine_source_device_required; after explicitly assigning its own
source bill, handheld success is still asserted. The old foreign-preview
signature refusal is retained. New tests exercise lost/changed ownership and
transfer history at preview and confirmation, with raw-row no-write equality.

Recovery: successful request lookup remains first under the existing lock.
An authenticated, manager-approved retry of an expired, unapplied token gives
the existing 409 combine_preview_stale plus combine_final_no_write containing
the exact non-PIN intent fields and table_id. Expiry is checked before current
bill eligibility, so a later changed bill does not strand an unapplied intent.
The same expired token cannot later combine. Committed requests replay their
original result; invalid PIN and unexpired stale requests give no release proof.
Clients must match every proof field and preserve the local draft on release.
No client may infer this proof from time, a generic 409, or a transport failure.

Clean candidate tree 108e913522654439549c05958096a1f034597dfa:

    /tmp/qr003-combine.7rj5nt: Time: 00:03.009, Memory: 89.00 MB
    OK (58 tests, 210 assertions)
    /tmp/qr003-combine.vkfYe4: Time: 00:53.412, Memory: 209.00 MB
    OK (1518 tests, 62769 assertions)
    /tmp/qr003-combine.E0Umkc: PASS 11 files (vendor/bin/pint --test -v)

Full delta vs parent 1512/62738: +6 tests / +31 assertions. Pint covered the
four changed PHP files plus earlier combine files using the existing harness.
Clean exports, scratch .env, read-only vendor, network none, SQLite memory
plus existing concurrency harnesses; no stack/device/shared DB/push/fetch.

Client UI, local proof, pending-work checks and recovery are a separate part
of this stage. This server prerequisite alone is not a rollout approval.
