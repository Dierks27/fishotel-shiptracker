# Changelog

All notable changes to FisHotel ShipTracker will be documented in this file.

## [1.9.18]

The rest of what a box costs — its materials — and a count of supplies.

- **Supplies (Settings → Boxes & label costs):** a third list, nothing seeded: name, unit (each / sheets / feet / free text), "count it down", a reorder level for counted supplies, and an optional use so a new box finds the supply by itself — heat pack, ice pack, fish bag, foam, paper, or the box of one standard box. At most one supply per use (a box supply: one per standard box); a standard box that is renamed keeps its box supply. Supplies keep a stable id that is never reused. `FST_Supplies::get_supplies()` for the theme.
- **Purchases (DB version 1.4.0, new table `wp_fst_supply_purchases`):** log each case, sheet or roll — date, how many, total paid, where it was bought; add, correct and remove on the settings tab (own forms, nonce `fst_supply_action` + `manage_woocommerce`). The price of one = total paid ÷ how many of the latest purchase (by date, then id), kept to four decimals so $18.99 for 144 is not rounded to 13 cents each; every line and every total is whole cents. The foam supply keeps its sheet size (8 ft × 4 ft, ½ in by default).
- **Standard boxes** gain a foam cost and a paper cost (two separate flat amounts, default 0.00), the feet of paper per box (optional) and the ice packs that go in when cooling is needed. Beside the two amounts a suggestion is shown with its working — foam: the box surface, all six sides, 2(LW + LH + WH) in square feet × the price per square foot of the latest foam purchase × (1 + the new foam waste setting, 15% by default), e.g. "4.2 sq ft of a 32 sq ft sheet"; paper: feet per box × the price per foot of the latest paper purchase. A suggestion is only filled in when it is clicked.
- **Materials per box (new table `wp_fst_supply_usage`):** lines of supply + quantity + unit cost and the two flat amounts, with the total, all snapshotted when recorded — a later purchase at another price or a changed box setting never rewrites a recorded box. Keyed by tracking number, so "one box, one cost" holds for a shared box and what was used is one query. `FST_Supplies::get_box_materials()` / `set_box_materials()` / `clear_box_materials()`; `FST_Shipment::get_order_boxes()` entries gain `materials`, `materials_total` and `total_cost`.
- **Defaults:** when tracking is added with a standard box and no `materials`, the box's usual materials are recorded: one of its own supply, its foam and paper cost, a fish bag per fish (the quantities of the shipment's line items), one heat pack when the order's weather action is `heat_pack`, the box's ice-pack count when it is `cooling`. A custom size records nothing by itself. The weather action is read defensively from the order meta the FisHotel Misc plugin's Weather section writes (`_fh_weather_action`; a source order of a combined box takes its wrapper's; a note made for another delivery date is ignored) — no dependency; filters `fst_order_pack_action` and `fst_box_fish_count`.
- **Stock (new table `wp_fst_supply_counts`):** on hand = bought − used on boxes ± recounts. A recount sets the real number and stores the difference with the date and who counted. Removing the last shipment row of a tracking number gives the box's supplies back; changing a box's materials gives back the old lines and takes the new. Never negative on screen: below zero shows 0 with "recount needed". A reorder notice (counted supplies at or under their level, with how many are on hand) on the ShipTracker dashboard widget and at the top of the Supplies list; `apply_filters( 'fst_low_supplies', array() )` returns the same list to other plugins.
- **How it went out:** an order with no label of its own can be marked "Local pickup" (a real zero label cost; it may carry materials, which count down like a box's) or "Went in another order's box" (that order's box or boxes then count this order's shipping paid against their cost, exactly like a shared fan-out box). Stored as order meta through the order object (HPOS-safe) — never a shipment row: no customer e-mail, no status change, no polling. Clearing it, or adding tracking to the order, puts it back to normal. `FST_Order::set_ship_mode()` behind the new AJAX action `fst_set_ship_mode` (nonce `fst_nonce`, `manage_woocommerce`) and `POST /fst/v1/order/{id}/ship-mode`; `FST_Ship_Mode::describe()` to read it.
- **Input:** `fst_add_tracking` / `POST /fst/v1/shipment` and `fst_set_label_cost` / `POST /fst/v1/shipment/{id}/cost` accept an optional `materials` — `{ lines: [ { supply_id | supply, qty } ], foam_cost, paper_cost }`, as an array or a JSON string. On add: absent = the defaults above, present = taken as sent, null = none. On update: absent = left alone, empty = removed, and `lines` / `foam_cost` / `paper_cost` are each kept when absent. Quantities are 0 or more with at most two decimals, below a million (0 removes the line), an unknown supply is an error (`fst_unknown_supply`), money follows the label cost rules (`fst_invalid_materials`); everything is checked before anything is saved.
- **REST:** `GET /fst/v1/shipping-options` also returns `supplies[]` (id, name, unit, role, price_each, on_hand …), each standard box's defaults and `supply_id`, `foam_waste_percent` and `ship_modes`. `GET /fst/v1/order/{id}/shipments` returns per shipment the box's `materials`, `materials_total` and `total_cost`, and for the order its `ship_mode`, the `weather_action` seen, the `fish_count` and `default_materials` for every standard box and a custom one, so a script or the theme can prefill.
- **Meta box:** the plugin's own Shipment Tracking box shows a box's materials, edits them with the label cost (offering the usual amounts while none are recorded) and has "How it went out" on an order without a shipment.
- **Analytics → Box Costs** (was Label Costs): every "cost" is now label + materials, with the split — label, boxes, foam, paper, bags, packs, other — for all boxes, by box size and by label source; the losses table shows label and materials; new lines for free shipping given (orders that shipped with a label and paid no shipping: how many, what they cost), local pickups (how many, their materials) and orders that went in another order's box. A new **Supplies** report for the range: bought (how many, money), used, on hand now, at or under the reorder level — and for foam and paper what was bought beside what the per-box amounts added up to, so a ballpark can be corrected. Boxes without a label cost stay out of the differences as before; their materials are still counted in the supplies figures.
- Packing review fixes: a line recorded before its supply's first purchase (at 0.00) takes the price each the next time the box's materials are saved, instead of staying at 0.00 for good — and logging an opening purchase for every supply is now a documented first step; a line already on a box keeps the name, use and unit it was recorded with when the box is saved again, so a supply that was renamed or lost its use no longer moves a recorded cost between the parts of the split; a local pickup or "went in another box" mark that is saved again without a date keeps the day it was made (a pickup whose materials were corrected no longer left the report of its week); an order that carries other orders can no longer be marked as a local pickup; tracking saved with the order form (the non-AJAX path of the plugin's own box) now ends a local pickup / "went in another box" mark like the AJAX path does, so the order is not counted both ways.
- Database: upgrading from 1.2.0 or from 1.3.0 only adds (three tables; from 1.2.0 the four cost columns as well) — every existing row is kept as it is.
- Tests (plain PHP, no WordPress): `tests/supplies-stock-test.php`, `supplies-defaults-test.php`, `supplies-report-test.php`, `ship-mode-test.php`; `tests/bootstrap.php` learned the supplies tables' queries, order meta writes and filters.

## [1.9.17]

Record what each box actually cost to ship, and report it against the shipping customers paid.

- **Storage (DB version 1.3.0):** `wp_fst_shipments` gains `label_cost DECIMAL(10,2)`, `box_weight DECIMAL(6,2)` (lb), `box_dims VARCHAR(40)` (normalised `LxWxH` in inches) and `label_source VARCHAR(40)`, all `NULL` by default — `NULL` means "not recorded", never 0. Added through the same `dbDelta` upgrade path as the earlier columns, and on fresh activation.
- **One box, one cost:** a tracking number is one physical box. When fan-out writes several rows for it, the four fields are stored on one row only (the first row the call created, or the row that already held them) and the other rows stay `NULL`; the box is read by tracking number. If the row holding the fields is removed while sibling rows remain, the fields move to the oldest surviving row. New `FST_Shipment::get_box_cost()`, `set_box_cost()`, `clear_box_cost()` and `get_order_boxes()`; a cost change leaves `updated_at` alone.
- **Settings → Boxes & label costs:** editable Standard Boxes (name, length, width, height in inches) and Label Sources lists, nothing seeded, saved through the existing settings form. `FST_Settings::get_standard_boxes()` / `get_label_sources()` for the theme, and `GET /fst/v1/shipping-options` for scripts.
- **Input:** `FST_Order::add_tracking()` (meta box, `fst_add_tracking`, `POST /fst/v1/shipment`) accepts optional `label_cost`, `box_weight`, `box_dims`, `label_source`. Money tolerates `$` and thousands separators and is rounded to cents; box sizes such as `10 x 10 x 10`, `10*10*10` and `10X10X10 in` become `10x10x10`, and a standard box name is stored as its size. Invalid values return `fst_invalid_label_cost` / `fst_invalid_box_weight` / `fst_invalid_box_dims` (HTTP 400) before anything is saved.
- **Update later:** `FST_Order::set_label_cost()` behind the new AJAX action `fst_set_label_cost` (nonce `fst_nonce`, `manage_woocommerce`) and `POST /fst/v1/shipment/{id}/cost` — an empty value clears a field, an absent key leaves it, and the change applies to the box of that shipment. `GET /fst/v1/order/{id}/shipments` and `GET /fst/v1/shipment/{id}` return the four fields and `shared_box`.
- **Meta box:** the plugin's own Shipment Tracking box has the optional cost fields on the add form (box as a dropdown of the standard boxes plus Custom, source as a dropdown when sources are saved) and shows / edits them on existing shipments.
- **Import label costs** (Settings → Boxes & label costs; `manage_woocommerce` + nonce): upload or paste a CSV with a header row; columns are found by header text; a "these labels were bought through" choice covers a file without a source column; only existing tracking numbers and only the four cost fields are changed, and only where none is recorded unless Overwrite is ticked. Reports matched / already had a cost / not found / invalid. 2 MB limit enforced before reading; BOM, quoted fields and CRLF handled; a tracking number mangled into scientific notation is reported as invalid, not guessed.
- **Analytics → Label Costs:** boxes shipped, with / without a recorded cost, total label cost, shipping customers paid, difference, average cost per box; tables by box size and by label source, the average cost per box size per source side by side (cheaper source highlighted), and the 20 boxes that lost the most. Boxes without a recorded cost are left out of the differences, and the page says so. A shared box sums its orders' shipping; an order in several boxes is counted once (split evenly between its boxes). "Shipping paid" is the order shipping total excluding tax, less shipping refunded.
- Fix: the Analytics date range now includes its last day. `ship_date` is a datetime and the end date was compared as midnight, so everything shipped on the last day of the range — today, for the 7 / 30 / 90 day presets — was missing from Total Shipments, the status and carrier breakdowns, the volume chart and the delivery-time averages. The new Label Costs section counts the same boxes as those cards.
- Label cost review fixes: the import refuses a file in which more than one column could be the cost (it names them and writes nothing) instead of taking the first; a currency sign with no amount (`$`, `USD`) — or a weight unit with no number — is invalid rather than "empty", so it can no longer clear a recorded value; the Label Costs cards show a dash instead of $0.00 for total cost, shipping paid and difference when no box in the range has a recorded cost; label sources that differ only in letter case are one row in the report; the import lifts PHP's time limit so a large file is not cut off part-way.
- The Settings tab switcher now also toggles tab content that sits outside the settings form (the import form), which makes the Migration tab open on click as well.
- Tests (plain PHP, no WordPress): `tests/label-cost-args-test.php`, `label-cost-box-test.php`, `label-cost-import-test.php`, `label-cost-report-test.php`, plus the extended `add-tracking-args-test.php`.

## [1.9.16]

- Extracted the body of `ajax_add_tracking()` into `FST_Order::add_tracking( array $args )` (returns array|`WP_Error`). The AJAX handler is now a thin `$_POST` wrapper around it — same field names, same string error messages, same success payload (now also carrying `failed_order_ids`, per-shipment `shipments[]` summaries and `order_statuses{}`), so the plugin meta box and the theme's Shipments box behave exactly as before.
- New pure `FST_Order::normalize_add_tracking_args()` (order id, tracking number, carrier auto-detect, `line_item_ids` sanitisation incl. comma-separated strings, optional `ship_date` Y-m-d) with a WordPress-free test: `php tests/add-tracking-args-test.php`.
- `POST /fst/v1/shipment` now calls that service instead of inserting a bare row, so API callers get fulfillment fan-out, line-item coverage, the same-order duplicate check, carrier auto-detect, EasyPost routing / first poll, order notes and the Processing → Shipped transition. Params are schema-validated (`carrier` enum `auto|ups|usps`, `line_item_ids` int array, optional `ship_date`). Returns 201 with the service result, or `{error, code}` mapped to 400 / 404 (`fst_order_not_found`) / 409 (`fst_duplicate_tracking`) with the admin's messages. Note: the old endpoint rejected a tracking number that existed on *any* order; the duplicate check is now per order, matching the admin and the combined-box fan-out.
- New `GET /fst/v1/order/{id}/shipments` (admin): effective shipments via `get_effective_shipments()` (now public) plus line-item coverage, `fully_shipped` and, for fulfillments, `source_order_ids`, so the label skill can pick `line_item_ids` and confirm the save.
- For a fulfillment order that endpoint also returns `source_orders[]` — each source's own `line_items[]` with `covered`, `fully_shipped` and `shipments[]` — because order item ids are globally unique: the fulfillment's own ids never match a source (every source is skipped, `fst_nothing_added`), and omitting `line_item_ids` stores a covers-all row on every source, flipping a partly boxed source to Shipped. README/CLAUDE.md now tell API callers to POST the uncovered source item ids and to omit `line_item_ids` only when the box holds every item of every source.
- `normalize_add_tracking_args()` rejects a tracking number of `"0"` again (`empty()` semantics, as the AJAX guard always had); the extraction had loosened it to `'' ===`. Covered by the plain-PHP test.
- README: the `rest_invalid_param` row notes that it is WordPress's schema envelope (`code`/`message`/`data.params`), not the plugin's `{error, code}`.
- Verified Application Password (HTTP Basic over HTTPS) auth works with `admin_check()` — nothing in the plugin blocks cookie-less REST auth. README.md and CLAUDE.md document both endpoints with curl examples and a "For the label skill" section.

## [1.9.15]

- Fan-out no longer creates a "covers all items" row on a fulfillment source order that has nothing in the posted `line_item_ids[]` box. That untouched sibling was being emailed "shipped", auto-completed on delivery and blocked from its own tracking later. The skipped sources are named in an order note on the fulfillment; the submitting fulfillment order itself is exempt so the fan-out kill switch still lands tracking on it directly.
- EasyPost webhooks now update every `wp_fst_shipments` row sharing the tracker (fan-out siblings), including siblings whose own tracker registration failed, so each source order gets its status, events, emails and auto-complete. Admin email deduplicated to one per webhook.
- Monotonic status guard on the webhook and the direct poll: an older or replayed payload cannot regress a row, and a terminal status (delivered, return to sender, failure) never moves back. An `unknown` payload is ignored once a real status has been recorded (no second "shipped" email on promotion or backfill), and keeps the stored status detail.
- Status Actions and auto-complete no longer flip refunded, cancelled or failed orders to Shipped/Completed; an order note records the skipped transition.
- Public `/fst/v1/track` returns the generic not-found error instead of an HTTP 500 when the order number resolves to a refund.

## [1.9.14]

- Fulfillment detection now uses the `_fishotel_is_fulfillment` post meta instead of `'fulfillment' === $order->get_status()`. The theme sets the meta to `'1'` on creation and it stays put, but the order status can be flipped past Fulfillment (Shipped, Processing after manual revert, Completed) — when that happened, `ajax_add_tracking` mis-classified the order as regular, rendered the picker for fulfillment items, and saved the shipment on the fulfillment row directly with empty `line_item_ids` instead of fanning out to source orders. Updated the three status-based checks in `class-fst-order.php` (meta-box picker render, `ajax_add_tracking` validation, and `resolve_target_order_ids` fan-out gate).

## [1.9.13]

- Auto-complete default flipped from `'0'` to `'1'` in `process_status_change()` to match the rest of the codebase (activator seeds the option to `1`; settings form reads with default `true`). No behavior change on any install where the activator has run; matters only on the edge case where `get_option` runs before the option exists.

## [1.9.12]

- Restored the General Settings → "Auto-Complete Orders" checkbox. The option was being saved by `class-fst-settings.php` but `process_status_change()` never read it, so orders sat at Shipped after every shipment delivered. Added a standalone, coverage-guarded auto-complete block in `FST_Tracker::process_status_change()` that fires after the existing Status Actions block: when a shipment transitions to delivered, the option is `'1'`, and every line item is in a delivered shipment, the order is transitioned to Completed. Multi-shipment orders still hold until the last shipment delivers. No-op if the option is off or the order is already Completed.

## [1.9.11]

- `FST_Tracker::process_status_change()` now consults line-item coverage before auto-advancing the WooCommerce order to "shipped". v1.9.6 already added the symmetric guard for the "delivered → completed" auto-complete path, but the "in_transit/out_for_delivery → shipped" path still flipped the order on the first matching event. On a multi-shipment order where one box reached Out for Delivery while other items were still unshipped, the order would prematurely advance to Shipped and fire the customer-facing "shipped" email. Now: when the configured `order_status` target is `shipped` and `is_order_fully_shipped()` returns false, the transition is skipped and an order note is logged instead. Added a private `is_order_fully_shipped()` mirror on `FST_Tracker` matching the canonical helper on `FST_Order`.

## [1.9.10]

- Stat aggregations now count unique physical shipments instead of rows. Combined-box fulfillments produce one shipment row per source order sharing a single `tracking_number`; previously a 5-box day reported as 7 In Transit, Delivered, etc. Switched `COUNT(*)` → `COUNT(DISTINCT tracking_number)` in `count_by_status()`, `count_by_carrier()`, `count_per_day()`, and `deliveries_per_day()`. `avg_delivery_days()` and `avg_delivery_days_by_carrier()` now average over a per-tracking dedupe subquery so a combined-box shipment contributes one data point instead of N. No schema change. (`get_late_shipments()` still returns one row per fan-out — out of scope here.)

## [1.9.9]

- Added `fst_send_customer_email` and `fst_send_admin_email` filters in `FST_Tracker::process_status_change()`. Each filter receives `(bool $send, FST_Shipment $shipment, WC_Order $order, string $new_status)` and defaults to `true`, so behavior is unchanged for anyone not hooking it. The theme can return `false` to suppress duplicate "your order has shipped" emails when multiple source orders ship in the same fulfillment box (consolidated email is dispatched separately).

## [1.9.8]

- Shipment Tracking meta-box now renders a per-item checkbox picker ("Items in this shipment") on regular orders, with a "Select all unshipped items" master toggle. Already-shipped items show crossed out and disabled. Submitting with nothing selected returns a clear error instead of silently mis-scoping the shipment.
- `ajax_add_tracking()` requires an explicit `line_item_ids[]` selection for non-fulfillment orders. v1.9.7's silent "default to all items" fallback caused mixed orders (e.g. fish + EA meds) to auto-flip to Shipped the moment any single box was tracked, firing a misleading "your order has shipped" email while the rest was still at FisHotel. Fulfillment orders still default to all source items per source (the fan-out path doesn't render the picker because fulfillment items don't 1:1 with source items).

## [1.9.7]

- Hotfix: the Shipment Tracking meta box (and the non-AJAX `save_tracking_data` fallback) now default a shipment's `line_item_ids` to every line item on the target order when the request doesn't POST a subset. v1.9.6 made the coverage check strict — without this default, meta-box adds produced `line_item_ids = NULL` and the order was stuck in Processing forever because the lone shipment contributed zero coverage. Explicit subsets (fulfillment fan-out for partial boxes) still intersect against the source order's items as before, so the strict partial-shipment behavior introduced in v1.9.6 is preserved for that path.

## [1.9.6]

- Fix: `is_order_fully_shipped()` no longer assumes a shipment with empty/NULL `line_item_ids` covers every item on the order. That assumption was misfiring on partial fulfillments — e.g. adding EA-pellet tracking to a mixed order with live fish (#34183) auto-flipped the order Processing → Shipped and triggered a misleading "your order has shipped" email while the fish were still in QT. Coverage now requires every order line item to appear in some shipment's explicit `line_item_ids` list. Empty order / zero shipments → false. Companion `is_order_fully_delivered()` in the tracker class is intentionally unchanged in this hotfix.

## [1.9.5]

- Hotfix: removed the `wc-fulfillment` status guard from `get_effective_shipments` so the display fan-in keeps working after theme v1.18.2's auto-advance flips fulfillments to `wc-shipped` / `wc-completed`. Without this, FF-34197 / FF-34202 rendered "—" forever once their sources shipped, even though the inverse `_fishotel_fulfilled_by_order` query returns the right sources. Inverse query now uses `status => 'any'` to cover the whole fulfillment lifecycle. Non-fulfillment orders are unaffected (nothing points up at them, query returns empty).

## [1.9.4]

- Hotfix: fulfillment display fan-in now uses an inverse `wc_get_orders()` query against `_fishotel_fulfilled_by_order` (set on each SOURCE order — per the theme, the single source of truth for the link) instead of reading `_fishotel_fulfillment_sources` from the fulfillment. The cache meta on the fulfillment can be empty or stale on real data, which left FF-34197 / FF-34202 rendering "—" in the Tracking column even after v1.9.3. Query passes every registered order status so custom statuses (wc-shipped, etc.) on source orders are included. Same dedupe-by-tracking-number behavior, same kill switch (`FST_FULFILLMENT_DISPLAY_AGGREGATION_OFF`).

## [1.9.3]

- Display fix: the Tracking column on the WooCommerce Orders list and the Shipment Tracking meta box on fulfillment edit pages now fan IN from `_fishotel_fulfillment_sources` when the fulfillment itself has no direct shipments (the inverse view of the v1.9.0 write-side fan-out). Previously a fulfillment with shipped sources rendered as "—" / "no tracking" because the rows are keyed to source order IDs. Shipments are deduped by tracking number so one badge renders per physical box. Write paths (create/remove/poll) still use direct lookups and only touch rows actually owned by their order.
- Kill switch: define `FST_FULFILLMENT_DISPLAY_AGGREGATION_OFF` to disable the fan-in and fall back to direct lookup.

## [1.9.2]

- "Delivery Date" column on the WooCommerce Orders list always shows the customer-requested delivery date from `_fishotel_shipping_date` now, even when tracking has been added. The pre-1.9.2 behavior switched to the shipment's `ship_date` once tracking was attached, which broke the column's purpose (planning upcoming shipments). Per-shipment ship dates are still visible inside the order's Shipment Tracking meta box.

## [1.9.1]

- Hotfix: fan-out now parses the comma-separated string format the FisHotel theme actually uses for `_fishotel_fulfillment_sources` ("34168,34159"). v1.9.0 only checked `is_array()`, so fan-out silently fell through to the legacy single-shipment path on real fulfillment orders. Array format is still accepted for forward compatibility.

## [1.9.0]

- Added multi-source shipment fan-out: when a tracking number is added to a Fulfillment order (status `wc-fulfillment`), it now creates one shipment row per source order listed in `_fishotel_fulfillment_sources` instead of a single row against the (Guest) fulfillment order, so customer emails actually reach Ken
- Added `line_item_ids` column on `wp_fst_shipments` (DB version 1.2.0) for partial-fulfillment tracking; NULL preserves the pre-1.9 "covers everything" semantic for existing rows
- Customer emails for partial shipments now list only the items in that box and rewrite the subject from "Your order #X has shipped" → "Items from your order #X have shipped"
- Source orders only flip to "Shipped" once every line item is covered by a shipment (partial fulfillments stay in Processing with a note)
- Source orders only auto-complete on Delivered once every line item is covered by a Delivered shipment
- Duplicate tracking numbers are now allowed across DIFFERENT source orders (combined-box scenario); same tracking# on the same order is still rejected
- New `get_by_order_and_tracking()` shipment lookup
- Kill switches: define `FST_FULFILLMENT_FANOUT_OFF` in wp-config.php, or set `fst_fulfillment_fanout_enabled = '0'`, to restore the pre-1.9 single-shipment behavior

## [1.8.0]

- Added EasyPost integration to track USPS packages regardless of the Mailer ID in the barcode (works around the April 2026 USPS Access Control change that returns HTTP 403 for dropshipper-printed labels)
- New EasyPost settings under Carriers: Production/Test API keys, webhook secret with generator, test mode, USPS routing (Auto/Always/Never), connection test, and the webhook URL to register
- New webhook endpoint `POST /wp-json/fst/v1/easypost-webhook` with HMAC-SHA256 signature verification — EasyPost pushes status updates instead of us polling
- New USPS tracking numbers are auto-registered with EasyPost when configured; EasyPost-tracked shipments are skipped in the cron poll loop
- USPS direct polls that return HTTP 403 are now auto-promoted to EasyPost
- One-shot backfill on upgrade registers in-flight USPS shipments with EasyPost
- Added `easypost_tracker_id` and `tracker_provider` columns (DB version 1.1.0)
- Fixed USPS API base host (`api.usps.com` → `apis.usps.com`)
- UPS tracking is unchanged

## [1.3.0]

- Added analytics dashboard with KPIs and 30-day shipment charts
- Bug fixes and debug cleanup

## [1.2.9]

- Fixed sort toggle not working in both directions on shipments dashboard

## [1.2.8]

- Added sortable columns on the shipments dashboard

## [1.2.7]

- Handle UPS responses with no package data gracefully

## [1.2.6]

- Fixed UPS status always showing "unknown"

## [1.2.5]

- Added debug logging for UPS tracking responses

## [1.2.4]

- Fixed settings form not submitting due to HTML5 validation bug

## [1.2.3]

- Improved carrier test connection with better error messages

## [1.2.2]

- Fixed carrier credentials not saving

## [1.2.1]

- Fixed recheck/remove buttons
- Added carrier test handlers

## [1.2.0]

- Branded email templates
- Email designer polish

## [1.1.2]

- Added "Check for Updates" link
- Improved GitHub updater

## [1.1.1]

- Simplified updater to use tags instead of releases

## [1.1.0]

- Initial release
- UPS and USPS tracking via OAuth 2.0
- WooCommerce order integration
- Customer My Account tracking page
- Automated email notifications
- Migration tool for AST and TrackShip
- GitHub-based auto-updater
