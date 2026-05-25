# Changelog

All notable changes to FisHotel ShipTracker will be documented in this file.

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
