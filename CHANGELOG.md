# Changelog

All notable changes to FisHotel ShipTracker will be documented in this file.

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
