# FisHotel ShipTracker - Project Overview & Instruction Manual

**Version:** 1.9.16
**Author:** FisHotel
**Type:** WordPress / WooCommerce Plugin (Proprietary)
**Repository:** Dierks27/fishotel-shiptracker

---

## What This Plugin Does

FisHotel ShipTracker is a self-hosted shipment tracking plugin for WooCommerce. It connects to UPS and USPS via their OAuth 2.0 APIs, automatically polls for tracking updates, and keeps your customers informed with branded email notifications. USPS can optionally be routed through EasyPost (a tracking aggregator) so packages printed under any Mailer ID can be tracked despite the April 2026 USPS Access Control restriction.

Key capabilities:
- Automatic tracking of UPS and USPS shipments
- Optional EasyPost routing for USPS (webhook-driven, MID-independent)
- Branded HTML email notifications on every status change
- Customer-facing tracking on the My Account page
- Admin analytics dashboard with date range filtering
- WordPress admin dashboard widget for at-a-glance status
- Ship Date column on WooCommerce Orders page
- Migration tool for importing from AST/TrackShip plugins
- Auto-updates from GitHub releases

---

## Requirements

- WordPress 5.8+
- PHP 7.4+
- WooCommerce 7.0+ (tested up to 9.6)
- UPS and/or USPS OAuth API credentials

---

## Installation

1. Download the latest release ZIP from GitHub Releases.
2. WordPress Admin > Plugins > Add New > Upload Plugin > select ZIP > Install Now.
3. Activate the plugin.
4. Navigate to **ShipTracker > Settings** to configure.

For updates: the plugin checks GitHub for new releases automatically. When an update is available, use the standard WordPress plugin update mechanism.

---

## Admin Menu Structure

After activation, the plugin adds a **ShipTracker** menu (airplane icon) with:

| Menu Item | Slug | Description |
|-----------|------|-------------|
| Shipments | `fst-dashboard` | Main shipment list with filters and sorting |
| Analytics | `fst-analytics` | Charts, KPIs, and date range reporting |
| Settings | `fst-settings` | All configuration (tabs described below) |

The **Migration** tool is located under Settings > Migration tab.

---

## Settings Tabs

### General
- **Default Carrier** - Pre-selected carrier when adding shipments (UPS or USPS)
- **Auto-Detect Carrier** - Automatically identifies carrier from tracking number format
- **Auto-Complete Orders** - Marks WooCommerce orders as "Completed" when shipment is delivered

### Carriers
- **UPS** - Client ID, Client Secret, Test Connection button
- **USPS** - Client ID, Client Secret, Test Connection button
- **EasyPost (USPS Aggregator)** - Production/Test API keys, Webhook Secret (with Generate button), Test Mode, USPS Routing (Auto/Always/Never), Test Connection button, and the read-only Webhook URL to paste into the EasyPost dashboard

Credentials are OAuth 2.0 client credentials obtained from:
- UPS: https://developer.ups.com
- USPS: https://developer.usps.com
- EasyPost: https://www.easypost.com (API key + webhook secret)

**EasyPost routing modes:** `auto` uses EasyPost when configured (recommended), `always` prefers EasyPost for every USPS shipment, `never` forces direct USPS polling. When configured, new USPS shipments are registered with EasyPost (which pushes updates to the webhook), USPS direct polls that hit a 403 are auto-promoted to EasyPost, and EasyPost-tracked shipments are skipped in the cron poll loop.

### Status Actions
Configure what happens when a shipment reaches each status:
- Send customer email (yes/no)
- Send admin email (yes/no)
- Change WooCommerce order status (No Change / Completed / Processing / On Hold)

Configurable for each status: Label Created, Pre-Transit, In Transit, Out for Delivery, Delivered, Exception, Available for Pickup, Return to Sender, Delivery Failed.

### Email Designer
Customize the subject and body of emails sent for each shipment status.

**Available shortcodes:**
- `{customer_name}`, `{order_number}`, `{tracking_number}`, `{carrier}`
- `{status}`, `{status_detail}`, `{est_delivery}`, `{ship_date}`
- `{carrier_tracking_url}`

**Widget shortcodes (render HTML):**
- `{tracking_progress}` - Visual progress bar
- `{tracking_events}` - Recent tracking updates list
- `{order_summary}` - Order items and total
- `{track_button}` - "Track on carrier site" button

Leave fields blank to use built-in defaults. Use the **Send Test Email** button to preview.

### Tracking Page
Customize the customer-facing tracking display colors:
- Background Color, Accent Color, Text Color
- Custom CSS field for additional styling

### Advanced
- **Polling Interval** - How often to check carriers for updates (seconds, minimum 300)
- **Debug Logging** - Writes detailed logs to `wp-content/fst-debug.log`
- **Data Retention** - How long to keep shipment history in days (0 = forever, default 1095 / 3 years)

### Migration
Import tracking data from Advanced Shipment Tracking (AST) or TrackShip plugins:
1. Click **Scan for Tracking Data** to preview what's available
2. Review the summary (orders, shipments, carriers, statuses)
3. Click **Start Import** to create ShipTracker records
4. Safe to run multiple times - duplicates are automatically skipped

---

## Daily Workflow

### Adding Tracking to an Order
1. Go to WooCommerce > Orders > click an order
2. In the **Shipment Tracking** meta box (sidebar), enter the tracking number
3. Select carrier (or leave on auto-detect)
4. Click **Add Tracking**
5. The plugin will immediately poll the carrier API for the first status

Scripts (the Finiship label skill) do the same thing through `POST /fst/v1/shipment` with an application password — see REST API Endpoints below. Both paths call `FST_Order::add_tracking()`; `ajax_add_tracking()` is only a `$_POST` wrapper around it, so the AJAX field names (`order_id`, `tracking_number`, `carrier`, `line_item_ids[]`) and payloads are unchanged.

### Fulfillment Order Fan-Out
When tracking is added to a Fulfillment order (a combined-box meta order with status `wc-fulfillment` owned by the Phase 2.5 fulfillment workflow), ShipTracker fans the tracking number out to every source order listed in the `_fishotel_fulfillment_sources` meta. This creates one `wp_fst_shipments` row per source order, all sharing the same tracking number — which is how customers on the source orders actually receive shipment emails (the fulfillment order itself is `customer = Guest`).

The optional POST field `line_item_ids[]` (sent alongside the tracking number) marks the shipment as a **partial fulfillment**: only those `WC_Order_Item` IDs are considered "in this box". Each created shipment row stores only the line item IDs that belong to its own source order (the input list is intersected with that order's items). A shipment with `line_item_ids = NULL` (the default and the pre-1.9 shape for existing rows) is treated as covering ALL items of its `order_id`.

Source-order status transitions are derived from coverage:
- **Add tracking** → source order flips to `wc-shipped` only when *every* line item is covered by a shipment. Partial fulfillments stay in `wc-processing` and get an order note.
- **All shipments delivered** → source order auto-completes only when *every* line item is covered by a shipment with `status = 'delivered'`. Partial deliveries get a note instead.

The same tracking number is allowed across DIFFERENT source orders (combined-box scenario). It is still rejected if it already exists on the same `order_id`.

Kill switches (both default to ON):
- Define `FST_FULFILLMENT_FANOUT_OFF` constant in wp-config.php to bypass fan-out — tracking lands on the fulfillment order_id directly.
- Set the `fst_fulfillment_fanout_enabled` option to `'0'` for the same effect via the database.

### Monitoring Shipments
- **Dashboard Widget** (wp-admin home) - Shows active shipment counts, exceptions, late alerts, and recently delivered orders at a glance
- **ShipTracker > Shipments** - Full list with clickable status cards to filter by In Transit, Out for Delivery, Exception, etc. Default sort is by Ship Date (newest first)
- **ShipTracker > Analytics** - KPIs and charts filtered by date range

### WooCommerce Orders Page
Two custom columns are added to the Orders list:
- **Tracking** - Shows colored status badge (In Transit, Delivered, etc.)
- **Ship Date** - Shows the date the order was shipped

Your coworkers can use the Ship Date column to identify which orders need documents printed for the day.

### Automated Behavior
The plugin runs on a cron schedule (default: every hour) to:
1. Check all active (non-terminal) shipments for tracking updates
2. Update statuses and store tracking events
3. Fire email notifications based on Status Actions configuration
4. Auto-complete orders when shipments are delivered (if enabled)

Out for Delivery shipments are polled more frequently. Shipments older than 30 days are polled less frequently (once per day).

---

## Analytics Dashboard

### Date Range
Select from presets (7 Days, 30 Days, 90 Days, 6 Months, 1 Year, All Time) or use custom date pickers. All data filters by **ship date** (when the order actually shipped), not the date the record was created in the plugin.

### What's Excluded
Shipments with "Unknown" or "Label Created" status are excluded from all analytics. These are typically old/stale records that were never actually tracked and would skew the numbers.

### KPIs
- **Total Shipments** - Count of shipments in the date range (excluding unknown/label_created)
- **Delivered** - Successfully delivered count
- **Success Rate** - Delivered / Total as a percentage
- **Avg. Delivery Time** - Average days from ship date to delivery date
- **Late Shipments** - Currently past estimated delivery and not yet delivered

### Charts
- **Shipment Volume** - Bar chart showing shipped (blue) and delivered (green) per day/bucket with Y-axis scale and individual value labels
- **Status Breakdown** - Horizontal bars showing percentage of each status
- **Carrier Volume** - Shipments per carrier with percentages
- **Avg. Delivery Time by Carrier** - Comparison of carrier speed

---

## Customer Experience

### My Account Page
Customers see tracking information on their My Account > Orders page:
- Shipment status with colored badge
- Ship date, estimated delivery, and delivered date
- Visual progress bar showing shipment journey
- Tracking events timeline

### Email Notifications
Customers receive branded HTML emails when their shipment status changes (based on Status Actions configuration). Emails include tracking details, progress bar, and a button to track on the carrier's website.

---

## Technical Architecture

### Database Tables
The plugin creates two custom tables:

**`wp_fst_shipments`** - Main shipment records
- id, order_id, tracking_number, carrier, status, status_detail
- ship_date, est_delivery, delivered_date
- last_checked, last_event_at, tracking_data, check_count
- easypost_tracker_id, tracker_provider (EasyPost integration; DB version 1.1.0)
- line_item_ids (DB version 1.2.0; JSON array of WC_Order_Item IDs in this shipment, NULL = covers all items of order_id)
- created_at, updated_at
- Indexes on: order_id, tracking_number, status, carrier, last_checked, easypost_tracker_id, tracker_provider
- Note: `tracking_number` is no longer globally unique — duplicates are allowed across DIFFERENT `order_id` values (combined-box fan-out). The same tracking_number+order_id pair is still rejected.

**`wp_fst_tracking_events`** - Individual tracking events per shipment
- id, shipment_id, status, description, location, event_time, raw_data, created_at
- Indexes on: shipment_id, status, event_time

### File Structure
```
fishotel-shiptracker/
├── fishotel-shiptracker.php       # Main plugin entry, singleton, admin menu, dashboard widget
├── admin/
│   ├── css/admin.css              # Admin styles
│   ├── js/admin.js                # Admin JS (AJAX handlers, carrier detect, tabs)
│   └── views/
│       ├── analytics-dashboard.php  # Analytics page with date range and charts
│       ├── migration.php            # Migration scan/import UI
│       └── shipments-list.php       # Shipments table with status cards
├── includes/
│   ├── class-fst-activator.php      # Database table creation, defaults
│   ├── class-fst-deactivator.php    # Cleanup on deactivation
│   ├── class-fst-email.php          # Email template rendering and sending
│   ├── class-fst-migrator.php       # AST/TrackShip data import
│   ├── class-fst-myaccount.php      # Customer My Account integration
│   ├── class-fst-order.php          # Order meta box, columns (Tracking + Ship Date)
│   ├── class-fst-rest-api.php       # REST API endpoints
│   ├── class-fst-settings.php       # Settings page with all tabs
│   ├── class-fst-shipment.php       # Shipment data model (CRUD, queries, analytics)
│   ├── class-fst-tracker.php        # Polling engine (cron-driven) + EasyPost poll/promote/backfill
│   ├── class-fst-easypost.php       # EasyPost aggregator (trackers, webhook, signature, backfill)
│   ├── class-fst-updater.php        # GitHub release auto-updater
│   └── carriers/
│       ├── class-fst-carrier.php      # Abstract carrier base class
│       ├── class-fst-carrier-ups.php  # UPS API (OAuth 2.0 + tracking)
│       └── class-fst-carrier-usps.php # USPS API (OAuth 2.0 + tracking)
├── assets/
│   └── css/frontend.css             # Customer-facing styles
└── tests/
    └── add-tracking-args-test.php   # Plain-PHP test of FST_Order::normalize_add_tracking_args() (php tests/add-tracking-args-test.php)
```

### REST API Endpoints

| Endpoint | Method | Auth | Description |
|----------|--------|------|-------------|
| `/fst/v1/shipment` | POST | Admin | Add tracking to an order via `FST_Order::add_tracking()` — the same service as the meta box (fan-out, coverage, dedupe, auto-detect, EasyPost/first poll, order notes + status) |
| `/fst/v1/order/{id}/shipments` | GET | Admin | Effective shipments (`get_effective_shipments()`) + line-item coverage for an order |
| `/fst/v1/shipment/{id}` | GET | Admin | Get shipment details + events |
| `/fst/v1/shipment/{id}/recheck` | POST | Admin | Force tracking update |
| `/fst/v1/track` | POST | Public | Customer lookup by order # + email |
| `/fst/v1/analytics` | GET | Admin | Status counts and late shipments |
| `/fst/v1/easypost-webhook` | POST | HMAC | EasyPost tracker.created/updated push |

"Admin" = `permission_callback` is `admin_check()` → `current_user_can( 'manage_woocommerce' )`. It works with cookie + `X-WP-Nonce` (admin.js) **and** with WordPress Application Passwords sent as HTTP Basic auth over HTTPS: the plugin hooks neither `determine_current_user` nor `rest_authentication_errors`, and WooCommerce's key/secret auth only covers `wc/*`. If Basic auth yields `rest_not_logged_in`, the host is stripping the `Authorization` header — see README.md for the `.htaccess` fix.

**`POST /shipment` params:** `order_id` (int, required — the order number in admin is the ID), `tracking_number` (required), `carrier` (`auto`|`ups`|`usps`, default `auto`, schema-enforced), `line_item_ids` (int array or comma-separated; required for regular orders; for a fulfillment order pass the *source* orders' item ids from `source_orders[].line_items[]` — never the fulfillment's own ids — and omit only when the box holds every item of every source), `ship_date` (optional `Y-m-d`, stored as midnight). Returns **201** with the service result (`message`, `shipment_id`, `shipment_ids`, `skipped_order_ids`, `failed_order_ids`, `carrier`, `shipments[]` post-poll summaries, `order_statuses{}`), or `{"error","code"}` mapped from the service `WP_Error`: 400 `fst_missing_args` / `fst_no_items` / `fst_nothing_added` / `fst_invalid_ship_date`, 404 `fst_order_not_found`, 409 `fst_duplicate_tracking`, 500 `fst_create_failed`. The messages are the ones the admin meta box shows.

```bash
curl -s -X POST 'https://fishotel.com/wp-json/fst/v1/shipment' \
  -u 'jeff:abcd efgh ijkl mnop qrst uvwx' \
  -H 'Content-Type: application/json' \
  -d '{"order_id":34183,"tracking_number":"1Z50W72W0115498880","carrier":"ups","line_item_ids":[4711]}'

curl -s 'https://fishotel.com/wp-json/fst/v1/order/34183/shipments' -u 'jeff:abcd efgh ijkl mnop qrst uvwx'
```

**`GET /order/{id}/shipments`** returns `order` (`id`, `number`, `status`, `is_fulfillment`, `source_order_ids` = where a POST fans out to), `line_items[]` (`id`, `name`, `quantity`, `covered`), `fully_shipped` (null for fulfillments — coverage lives on the sources), `shipments[]` (row columns minus `tracking_data`, with `line_item_ids` decoded, `status_label`, `tracking_url`) and `source_orders[]` (fulfillments only, one per `source_order_ids` entry: `id`, `number`, `status`, `line_items[]` with `covered`, `fully_shipped`, `shipments[]` — all from that source's own rows; these are the ids a POST needs).

#### For the label skill: POST the tracking here instead of driving the admin screen

The Finiship label skill's "save tracking on the order" step should not fill the order-edit meta box in a browser any more. Flow: `GET /order/{id}/shipments` → pick the uncovered `line_items[].id` for this box; for a fulfillment order pick the uncovered ids from every `source_orders[].line_items[]` that are in the box (never the fulfillment's own ids), and omit `line_item_ids` only when the box holds every item of every source → `POST /shipment` with `carrier: "ups"` → confirm `shipment_ids` is non-empty and `order_statuses[id] === "shipped"` in the 201 body — for a fulfillment, each fully-boxed source is `"shipped"` and a partially boxed source stays `"processing"` (a 409 means it was already saved). Keep the application password in the device credential store / an env var, never in the skill file.

The public `/track` endpoint validates order number + billing email and returns the same error for missing orders and email mismatches to prevent order enumeration.

The `/easypost-webhook` endpoint has no WordPress capability check (`permission_callback => __return_true`); it is authenticated by an HMAC-SHA256 signature in the `X-Hmac-Signature` header, verified against the configured webhook secret. Invalid/missing signatures return 401, malformed JSON returns 400, and an unresolved shipment returns 422.

### Shipment Statuses
| Status | Terminal? | Description |
|--------|-----------|-------------|
| `unknown` | No | Initial/unresolved state |
| `label_created` | No | Shipping label printed |
| `pre_transit` | No | Package accepted, not yet moving |
| `in_transit` | No | In transit to destination |
| `out_for_delivery` | No | On the truck for delivery |
| `delivered` | Yes | Successfully delivered |
| `exception` | No | Delivery exception occurred |
| `return_to_sender` | Yes | Being returned to sender |
| `failure` | Yes | Delivery permanently failed |

### Auto-Update Mechanism
The plugin checks GitHub for new releases every 6 hours (cached). To release a new version:
1. Bump version in `fishotel-shiptracker.php` (header + `FST_VERSION` constant)
2. Commit and push to main
3. Create a GitHub release with tag `v{version}` (e.g., `v1.5.3`)
4. WordPress will detect the update and show it on the Plugins page

---

## Troubleshooting

### Shipments not updating
- Check Settings > Advanced > Polling Interval (default 3600s = 1 hour)
- Enable Debug Logging and check `wp-content/fst-debug.log`
- Verify carrier credentials with the Test Connection buttons
- Check that WordPress cron is running (`wp cron event list`)

### Emails not sending
- Use the Test Email button in Settings > Email Designer
- Verify Status Actions are configured to send emails for the desired statuses
- Check that your WordPress site can send email (WP Mail SMTP or similar)

### Analytics showing unexpected numbers
- Analytics exclude "Unknown" and "Label Created" statuses
- All date filtering uses **ship_date**, not the date records were created
- Use the date range selector to narrow down the timeframe

### Plugin update requires two clicks
- Fixed in v1.5.2. If on an older version, the second click completes the update normally.
