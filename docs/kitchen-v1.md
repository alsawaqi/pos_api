# Kitchen v1 cloud contract — K1 candidate

Local implementation, default disabled (`KITCHEN_V2_ENABLED=false`). No branch is opted in by migration. K2 supplies merchant UI/authenticated configuration calls; K3 supplies actual LAN host, durable source outbox, certificates and secure device time; K4 integrates real channel actions and waste; K5 supplies KDS UI. This document is not deployment approval.

## Schema ownership and setup boundary

pos_admin owns `2026_10_08_100001_create_kitchen_v2_schema.php`. Eleven additive `pos_kv2_*` tables hold branch activation/coordinator epochs, source policy inheritance, immutable configuration, enrolled device certificate/area bindings, canonical submissions, frozen revisions, area work, destination deliveries, immutable attempts, event receipts and configuration audit. pos_api runs an exact byte-copy fixture only in `testing`. The new KDS admin device type is `kitchen_display`, activation app `kds`; it needs no bank/terminal or donation settings and is denied all non-kitchen API/broadcast access except minimal device identity/heartbeat.

`Configuration` is an **internal command service**, not an exposed merchant HTTP endpoint. Its company ID, authorized branch list and actor must come from K2's existing authenticated merchant/admin policy; never accept those arguments from request JSON. `setPolicy` supports `staff` (main_pos + handheld), `qr_web`, `customer_tablet`, modes `manual`/`immediate`. Full-payment modes are rejected. Branch override affects one source only; all branches requires access to every company branch, replaces that source's overrides and updates company default for future branches. `setRouting`, `enroll`, `assign`, `suspend` are audited. Config changes generate versions without changing applied policy or accepted work.

An assigned coordinator must be an active enrolled till/handheld. Assignment checks enrolled active peers, unresolved legacy claims and unresolved new deliveries, and requires an explicit operator isolation evidence string. That audit string is **not physical proof of isolation**. K3/K6 still must demonstrate the controlled handover procedure, drain/journal transfer and old-executor shutdown. Suspending increments the epoch and retains all records; the legacy claim fence stays closed. Migration rollback refuses to erase populated evidence. No automatic raw-printer failover exists.

## HTTP endpoints

All paths start `/api/v1/device/kitchen-v2`, use the existing scoped device bearer credential, current company/branch/device assignment and enrollment. Human attended-device mutations require existing X-Staff-Token. KDS mutations require its narrow X-Kitchen-Grant; staff activity/branch/kitchen.screen and enrolled area are rechecked. Feature disabled returns 503. No secrets belong in JSON event payloads.

| Verb/path | Contract |
|---|---|
| GET configuration | Assigned coordinator only; desired bundle/hash, desired/applied/staged version, activation ID/state and epoch |
| POST configuration/ack | Journaled `prepare_configuration`, `commit_configuration`, `finish_configuration`; version/hash/activation_id required |
| POST submissions | Journaled `submit`; creates one canonical kitchen intent, never an order/payment |
| POST events | `approve`, `reject`, `amend`, `cancel`, `item_done`, `done_all`, `undo`, `served`, `collected`, `link` |
| GET snapshot | Branch/area projection; view active/ready/recent/pending, numeric `after` cursor, 100 rows; KDS cannot read pending inbox |
| GET events | 100 journal invalidations after sequence; no raw payload, grants, staff/customer/cost configuration. KDS gets only relevant work-area invalidations |
| GET deliveries | Coordinator only; 100 immutable delivery snapshots/states after numeric ID |
| POST delivery-results | Coordinator only: `claim_delivery`, `delivery_result`, plus staff-authorized `resume_delivery`, `reprint`, `reassign_delivery` |
| POST grants | `kind: host` for coordinator or staff otherwise. Standard JWS ES256, separate configured signing key. Refresh recommendation 300 s; hard maximum lifetime 43,200 s |
| POST sync | Coordinator-only atomic batch, 1–50 `{device_id, grant, event}` entries. Verify origin enrollment, certificate binding, epoch, staff and scope; reuse original event hash/receipt. Credentials never enter journal. Wrong order/parent, expired authority or stale configuration is an explicit failure; client must retain/quarantine the batch |

Every mutation event carries `protocol_version:1`, lowercase UUID `event_id`, integer `epoch`, ISO8601 `occurred_at`, typed `action`. Work actions carry `submission_uuid` and integer `expected_revision`. Input limit is 1 MiB, with at most 500 submission lines; unknown top-level fields and unknown line fields are refused. DB transaction commits event result, sequence and domain effects before HTTP success. Same event/payload/authenticated origin returns original receipt with `replayed:true`; changed payload conflicts. Stable source keys also prevent new event/submission UUIDs duplicating one original source domain event or cloud round/quick order.

`occurred_at` is retained source evidence; recorded/released/done server timestamps are authoritative for this cloud model. K3 must establish authenticated LAN release/time anchors and label uncertain ages. The cloud model is not proof that device wall clocks or reboot continuity are secure. Transport acceptance never means physical paper.

## Activation and loss of ACK

Coordinator must first freeze new local admissions, persist its proposed activation ID/version/hash and call prepare. Cloud freezes new submissions while prepared/committed. After the prepare receipt is durable locally, call commit. Cloud records the new applied version while remaining frozen. Persist that decision locally, call finish, recover its outcome using configuration GET or exact event replay before unfreezing local admissions. If any reply is lost, retain frozen state and recover; do not silently fall back to the old version. Complete K3 crash tests at each local durable-write boundary. New stale-version submissions are rejected and retained. Accepted waiting approvals retain their original manual rule; policy edits never approve them en masse.

## Submissions, routing and lifecycle

See executable fixtures in `src/resources/kitchen/v1/` and `KitchenV2Test`. UUIDs identify order, round, intent, source domain event and lines. Quantities are positive fixed six-decimal **strings**, never floating point. Existing numeric product/category IDs are tenant validated. Kitchen snapshots include names, Arabic name, notes, modifiers/removals, allergens and meal/component context; no monetary/customer/staff payloads. RFC 8785 JCS hashes use root23/php-json-canonicalization; exact vector is versioned. JSON middleware preserves whitespace/empty strings on kitchen routes.

Staff submit is an explicit submitted domain intent (never draft/held); the source device must match till/handheld. Cloud QR/tablet intents must reference an existing scoped order, eligible authoritative round/order quantities and valid catalogue. Valid financial QR held/awaiting_payment does not itself hold food. Domain review/invalid pricing/accounting states are blocked. Pending loyalty alone is not a kitchen gate. All actual existing channel triggers remain K4; do not wire arbitrary clients around the authoritative pricing/domain action.

Explicit item routes replace category rules. Add and deduplicate all-items copies. Required area work is independent of copy destinations; fallback fills missing preparation assignment. If any line lacks preparation routing, retain Needs routing and create no automatic deliveries. Current branches have immutable applied snapshots, so address/routing changes do not resend accepted jobs.

Item Done completes the displayed whole quantity in the assigned area; Done all affects only that area/current revision. All required areas Done creates Ready with a UUID readiness cycle. Undo before handover withdraws readiness; a later Ready uses a new cycle. Served/Collected requires current cycle; later rounds cannot reuse a served batch. Printing is never Done. Amendments create immutable revisions, preserve Done only for identical line+area work, cancel unsent old jobs/claims and generate delta/cancel notices for possibly dispatched work. Stale Done cannot affect new work.

Deliveries persist claim before sending. Only queued/known-failed-before-send work can retry with a new attempt. Sending/sent_unconfirmed/uncertain cannot automatically retry. Socket success is only sent_unconfirmed; no generic confirmed-paper result is accepted. Audited reprint gets a new ID. Reviewed reassignment is allowed only for known-unsent work, cancels the old claim and retains both snapshots. Model-specific physical confirmation belongs to K6.

`link` attaches an existing scoped financial order by stable UUID without creating a sale or another delivery. `Compatibility::preparation` returns historical revision/area evidence, exact quantities and component snapshots, with done / review_required / not_released. It is not a waste writer. Existing printed evidence and the cancellation service still own stock/waste semantics in K4. New released work is fenced from the old expired-QR cancellation path until audited preparation review is integrated. No fake kitchen_printed_at is written.

## Grant and runtime boundaries

Issuer config is `kitchen.private_key_path`, `key_id` and a public-key map in `kitchen.public_keys`. No production issuer/certificates/keys were created. Claims include issuer, audience, jti, iat/nbf/exp, company/branch/device, assignment, coordinator epoch, standard cnf x5t#S256 certificate thumbprint, allowed area IDs, staff and scopes. No HMAC APP_KEY goes to devices. Verification pins ES256 and rejects changed audience, identity, areas, scope, lifetime or signature. Current online revocation is checked; offline devices may remain unaware for the remainder of their maximum 12-hour window. KDS online staff/PIN provisioning UX and actual mTLS/Keystore/grant refresh are later implementation.

During sync, expired or revoked authority is rejected without applying the batch. Clients must retain original identity and request supervised reconciliation, never relabel it as a new tenant. Applying historical already-accepted LAN intents across configuration/assignment changes needs the explicit K3 recovery lane and evidence; this K1 implementation deliberately rejects stale new submissions. Full network-partition, local disk, Android lifecycle and source cross-store recovery are not validated by these API tests.

## Verification

K1 integration tests cover tenant reads/writes, narrow KDS signed grants, policy inheritance, hash/replay conflicts, activation ACK loss, multi-area Done, amendment/cancel/reassignment, printer uncertainty, all-source admission fixtures, expiry guards, pending links and transaction rollback. Standalone `tests/Support/kitchen_v2_postgres_race.php` has a strict disposable DB guard and must run against the real admin migrations; old API SQLite test migrations have forward-reference assumptions unsuitable for PostgreSQL. Run the shared handoff's isolated commands, never normal production DB configuration.

Dependencies added: firebase/php-jwt 7.2.1 and root23/php-json-canonicalization 1.0.1. Existing locked package versions were not updated. Composer audit reported advisories in five pre-existing dependencies and none in the two additions; follow up separately before a production release. Sources: https://github.com/googleapis/php-jwt and https://github.com/root23/php-json-canonicalization, RFC https://www.rfc-editor.org/rfc/rfc8785.html.

### Delivery context and revision deltas

Each immutable delivery snapshot includes `submission_uuid`, `order_uuid`, `round_uuid`, `source`, `revision` and the complete kitchen `context` (reference/table/order type/notes). Reprints and reassigned copies retain that original identity. `lines` is the full original or the changed-line delta; cancellation/change snapshots also include `removed_line_uuids` and full `removed_lines` for human-readable notices. `previous_context` and `context_changed` identify context-only changes without re-sending unchanged cooking lines. Consumers must not interpret an empty context-only delta as a new order.

Amendment/cancellation folds all possibly-sent destination history, not only the latest revision, and cancels all known-unsent predecessor jobs. Old immutable snapshots stay unchanged; the board's current context follows the current revision. Transport success remains no guarantee of physical paper.
