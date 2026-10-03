# LAUNCH-P3 — Recipes in the device API (Part B)

Spec: `documentation/LAUNCH-P3_WORK_ORDER.md` (Part B, data contract). pos_admin owns
the migrations; pos_api only reads `pos_ingredients.is_prep`, `prep_yield_quantity`,
`pos_ingredient_recipes` and `pos_product_recipe_versions`. Without the P3 columns
there are simply no prep items, so this code may deploy before or after them.

## P3-4 Prep items

`App\Support\Recipes\PrepExploder` is the one explode rule. A line of prep P with
quantity q becomes, for each component c, `q × c.quantity ÷ P.prep_yield_quantity`
of c, recursively, at most 3 prep levels; lines of the same raw ingredient merge.
Arithmetic is exact (rationals); quantities round half up to 4 decimals and costs to
6 only at the end. The cost of a prep is the cost of its exploded raw lines.

**Per-unit amounts keep 8 decimals (fix order 1, M1-b).** An amount that is
multiplied later — an order line's recipe copy and an add-on option's stock-use copy
(per ONE unit), and the kitchen screen's per-piece lines — keeps 8 decimals
(`PrepExploder::PER_UNIT_SCALE`). 15 ml of a saffron syrup copies 0.00003 kg of
saffron, so the portal's cost of goods from the copy equals the theoretical cost.
Pay and void (`ConsumeInventoryAction`) keep the P2 rule: the per-unit plan rounds to
the ledger's 4 decimals before × qty; the portal's save-time guard (M1-a) refuses a
recipe whose exploded per-unit line would round to 0 or move by more than 1 %.
Amounts written to the ledger as they are (a batch's lines, recipe × pieces) round to 4.

Guards (the portal refuses these on save; the API must never fail a sale or loop): a
cycle, a 4th level, a prep without a yield (> 0) and a component of another company
are skipped (that part deducts and costs nothing) and logged as a warning.

Exploded wherever a recipe is copied or evaluated:

| Path | Where |
|---|---|
| Till / handheld order lines | `CreateOrderHandler` via `RecipeCopy` |
| QR checkout, QR and staff round appends | `OrderLineSnapshotter` via `RecipeCopy` |
| Add-on option lines (PD3b) and the legacy single-ingredient add-on | `RecipeCopy::addonStockUse` (a legacy add-on naming a prep is copied as its exploded `add` lines) |
| Kitchen batch start (recipe × pieces, and extras) | `StartProductionAction` |
| Kitchen screen recipe lines and `max_producible` | `DeviceKitchenController` |
| Device config `products[].recipe`, `low_stock`, add-on `consumption` | `BuildDeviceConfigAction` |
| Product-waste cost of a cooked item | `ProductWasteHandler` |

Copies keep their shape (raw ingredient lines `{ingredient_id, qty, unit, unit_cost}`
per one unit), so pay/void, the pos_admin reversal copy and the portal cost of goods
read them unchanged. No `via_prep_ingredient_id` is stored: merged lines can come from
several preps, so it would be ambiguous, and no reader needs it.

A prep item never gets a stock row: it is left out of the device config ingredient
list (purged via `deleted.ingredients` when an ingredient becomes a prep), and a stale
`stock.count` or `restock.request` line naming one is skipped (reported in the result
as `skipped_prep_ingredient_ids`; an event naming only prep items fails). The kitchen
extras picker lists prep items with `is_prep: true`; a prep extra explodes.

Device payloads keep their shape; `is_prep` and `skipped_prep_ingredient_ids` are
additive keys older apps ignore.

## P3-5 Batch cost

Finishing a batch stamps the `produced` product movement's `unit_cost` with
Σ(|quantity| × unit_cost_at_time) of the batch's `production_consumption` movements
(std + extras, at the cost each left with) ÷ pieces, 6 decimals. Cancelling returns
each ingredient at the unit cost frozen on its `production_consumption` movement
(the live cost only when a batch has none).

## P3-6 The recipe of the moment of sale

Rule (`App\Support\Recipes\RecipeInForce`):

- The sale moment of a device order is the order event's `client_timestamp`, clamped
  to the server's now. Ingest parses the stamp with its UTC offset and stores it in UTC
  (`…T15:00:00+04:00` is stored as 11:00:00); moments compare in UTC at whole seconds.
- **Floor (fix order 1, M2).** A device clock running behind (a real-time-clock reset,
  a wrong manual time or time zone) must not copy a recipe from before the sale could
  have happened. The moment is never earlier than the latest of:
  - the device's credential epoch: `assignment_activated_at`, else `token_issued_at`
    (P0 makes a device send its unsent sales before it is moved, so no sale it pushes
    predates its current activation);
  - the product's `created_at` (a device cannot sell a product before it exists). When
    the product's **first** version is `"[]"` and dated within 60 s after `created_at`,
    the product was created with its recipe in one save (the portal wizard writes both
    in one transaction), so that version's `edited_at` is the floor.

  The floor never moves a later moment, so a real offline sale keeps its recipe. A
  floored moment is logged. What it cannot fix: a clock behind by X still applies the
  recipe in force X earlier when that is after the floors (a recipe first set or edited
  within X). Pilot devices keep automatic date and time on.
- **Flag (M2).** Any pushed event whose `client_timestamp` is more than 24 h behind the
  time the server received it is logged and settles with
  `result.integrity_flags: ["client_time_behind_24h"]` (additive; the event is still
  processed). It is a long offline backlog or a clock running behind.
- `pos_product_recipe_versions` holds the recipe **before** each edit, dated at the
  edit. The recipe in force at T is the `recipe_json` of the first version with
  `edited_at > T`; with none, the current `pos_product_recipes`. An edit in the same
  second as T is already in force. `"[]"` means there was no recipe then.
- An unreadable version falls back to the current recipe (logged).
- Re-sent open orders (`order.hold` again, the finalizing `order.create`,
  `order.transfer`): each incoming line that matches an existing line by product,
  quantity and add-on set keeps the copies it already had (recipe, components and each
  add-on's stock-use copy). Price, discount or note edits do not make a line "changed".
  - **Kept copies follow the product's type (L2).** A kept line keeps its recipe only
    while the product is still made-to-order (`ingredient`); switched to cooked or unit
    it moves the shelf instead, untracked nothing. A kept product-as-add-on copy takes
    the linked product's current `stock_mode` and keeps its recipe only while that is
    `ingredient`.
  - **Identical lines (L2).** When a re-send has fewer lines of one key than the order
    had, the newest copies are kept (the removed line is taken to be the older one);
    otherwise each line keeps its own copy, in line order.
  - **New or changed lines (L1)** copy at the re-send's moment, but never earlier than
    the order's last accepted write: that write's `client_timestamp`, or — when this
    re-send's stamp does not move past it — the time the server received that write.
    The handheld stamps every `order.hold` / `order.transfer` with the order's open
    time, so without this a line added after a recipe edit copied the pre-edit recipe.
    A till stamps each hold with its own time, so an offline till re-hold keeps it.
- Device staff table rounds (`table.session.round`) copy at the round's client moment
  too (same credential-epoch and product floors), also when held for review and
  confirmed later. QR customer orders and rounds, kitchen batches and the device
  config are live: the current recipe.

Read from the versions: the product's own recipe lines only (`ingredient_id`, base
`quantity`, `unit`). Prep items explode through their current recipe, unit costs are
the live ones, and `stock_mode` is the current one.

## P3-7 Untracked products

Only a made-to-order product (`stock_mode = 'ingredient'`) copies a recipe at sale:
cooked (consumed at production), unit (bought in) and untracked products never deduct
a leftover recipe, on the device and QR paths alike.
