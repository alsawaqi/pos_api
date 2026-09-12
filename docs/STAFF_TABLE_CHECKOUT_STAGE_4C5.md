# Unified staff flow — staff-only canonical table checkout (4C5)

Parent: 88d1daf3650b899491aeaaef20d8cf129d2c49d7. Local implementation only.

Staff dine-in bills (`main_pos` / `handheld`, no QR credential) use their real
canonical seating and existing charge/tender protocol. They never acquire a
fake QR source or credential. The additive `checkout_policy=staff_table_claim_v1`
on table detail, claimed checkout and recovery proof is required by new clients;
an old server does not authorize retiring a staff-only payable local copy.

The owning device must exclude its own local payable copies before claiming.
Another attended device is refused with `staff_bill_owner_required` until the
original device has the exact server-journaled same-bill recovery receipt.
Recovery clients persist their fence before submitting that receipt; losing the
acknowledgement cannot reopen the old payable copy. This does not make old clients
safe automatically: rollout and independent multi-device verification remain gates.

All new write transactions reuse the resolver's short, sorted branch graph lock:
device → tables → orders → sessions → seatings → journal. No network or printing
occurs in these transactions. Existing QR-only paths retain their behavior.

Claims reject invalid graph/tenant/transfer links, archived tables, payments,
pending rounds (including bill-less family rows), unbalanced frozen money, charge
residue, and geofence failure. Same-holder replays preserve every domain row;
competing, uncertain, expired or mismatched claims do not allocate another claim.
Cash/card/bank POS/gift and split tenders use unchanged PayOrderHandler, exact
integer amounts, unchanged identity and same-bill payment replay semantics.

Cancelled/declined claims can explicitly reopen: clear six charge fields and
the seating billing timestamp, log previous evidence, append `reopened`.
Uncertain/lapsed claims can only use attended recovery: move to held while
preserving all charge evidence. Existing recovery guards continue to control
subsequent tender/void; no automatic retry or ambiguous-claim reset is added.

No migrations, config keys, event types, stock recalculation, devices or stack
changes. PayOrderHandler, VoidOrderHandler, CreateOrderHandler,
RefreshQrOrderTotalsAction and ReleaseQrChargeAction are unchanged.

Regression coverage: StaffTableCheckoutTest plus every existing suite test.
Immutable-export full-suite/Pint results and literal deltas are recorded in the
shared QR-003_UNIFIED_STAFF_TABLE_CHECKOUT_HANDBACK.md after completion.
