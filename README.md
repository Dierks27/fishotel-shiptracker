# FisHotel ShipTracker

Self-hosted shipment tracking for WooCommerce. Tracks UPS & USPS packages, sends automated email notifications, and provides a branded tracking experience for customers.

## Requirements

- WordPress 5.8+
- PHP 7.4+
- WooCommerce 7.0+ (tested up to 9.6)

## Installation

1. Download the latest release from the [Releases page](https://github.com/Dierks27/fishotel-shiptracker/releases).
2. In your WordPress admin, go to **Plugins > Add New > Upload Plugin**.
3. Upload the ZIP file and click **Install Now**.
4. Activate the plugin.

Alternatively, clone this repository into your `wp-content/plugins/` directory:

```bash
cd wp-content/plugins/
git clone https://github.com/Dierks27/fishotel-shiptracker.git
```

## Configuration

After activation, navigate to **WooCommerce > ShipTracker** in the WordPress admin.

### Carrier Setup

**UPS:**
1. Go to the **Carriers** tab.
2. Enter your UPS OAuth Client ID and Client Secret.
3. Toggle sandbox mode on/off as needed.
4. Click **Test Connection** to verify credentials.

**USPS:**
1. Go to the **Carriers** tab.
2. Enter your USPS OAuth Client ID and Client Secret.
3. Click **Test Connection** to verify credentials.

### Settings

| Setting | Default | Description |
|---------|---------|-------------|
| Default Carrier | UPS | Carrier pre-selected when adding shipments |
| Auto-Detect Carrier | Enabled | Automatically identify carrier from tracking number format |
| Auto-Complete Orders | Enabled | Mark orders complete when shipment is delivered |
| Polling Interval | 3600s | How often to check carriers for tracking updates |
| Data Retention | 1095 days | How long to keep completed shipment data |

### Email Notifications

The **Email Templates** tab lets you customize HTML email notifications sent at each shipment status change. Use the built-in email designer to preview and test templates.

## Features

- **Shipment Tracking** - Automatic polling of UPS and USPS APIs via OAuth 2.0
- **Order Integration** - Add tracking numbers directly from the WooCommerce order edit screen
- **Customer Portal** - Tracking info displayed on the My Account > Orders page
- **Email Notifications** - Branded HTML emails sent on status changes
- **Analytics Dashboard** - KPIs and 30-day shipment charts
- **Migration Tool** - Import shipments from Advanced Shipment Tracking (AST) or TrackShip
- **Auto-Updates** - Receive plugin updates directly from GitHub releases
- **HPOS Compatible** - Supports WooCommerce High-Performance Order Storage

## REST API

The plugin exposes REST endpoints under the `fst/v1` namespace (`https://fishotel.com/wp-json/fst/v1/...`):

| Endpoint | Method | Auth | Description |
|----------|--------|------|-------------|
| `/fst/v1/shipment` | POST | Admin | Add tracking to an order — same code path as the admin meta box (fan-out, coverage, dedupe, auto-detect, first poll, order status) |
| `/fst/v1/order/{id}/shipments` | GET | Admin | Effective shipments + line-item coverage for an order |
| `/fst/v1/shipment/{id}` | GET | Admin | Shipment details + events |
| `/fst/v1/shipment/{id}/recheck` | POST | Admin | Force a tracking update |
| `/fst/v1/analytics` | GET | Admin | Status counts and late shipments |
| `/fst/v1/track` | POST | Public | Customer lookup by order number + billing email |
| `/fst/v1/easypost-webhook` | POST | HMAC | EasyPost tracker push |

"Admin" means the authenticated user needs the `manage_woocommerce` capability (Administrator or Shop Manager).

### Authentication with an Application Password

Admin endpoints accept WordPress **Application Passwords** as HTTP Basic auth, so a script can call them without a browser session or nonce. Requirements: WordPress 5.6+, the site served over **HTTPS** (WordPress refuses application passwords on plain HTTP), and a password created under **Users → Profile → Application Passwords** for an Administrator / Shop Manager account. Nothing in this plugin hooks `determine_current_user` or `rest_authentication_errors`, and WooCommerce's own key/secret auth only applies to its `wc/*` namespaces, so `fst/v1` sees the normal WordPress user.

Smoke test — should return your user, not `rest_not_logged_in`:

```bash
curl -s -u 'jeff:abcd efgh ijkl mnop qrst uvwx' \
  'https://fishotel.com/wp-json/wp/v2/users/me?context=edit'
```

If that returns 401 on a host where cookie login works, the server is stripping the `Authorization` header before PHP sees it (common with Apache + CGI/FastCGI). Add to the top of `.htaccess`:

```apache
RewriteEngine On
RewriteCond %{HTTP:Authorization} ^(.*)
RewriteRule ^(.*) - [E=HTTP_AUTHORIZATION:%1]
```

### `POST /fst/v1/shipment`

| Param | Type | Required | Notes |
|-------|------|----------|-------|
| `order_id` | int | yes | The order number shown in admin (it is the order ID) |
| `tracking_number` | string | yes | |
| `carrier` | `auto` \| `ups` \| `usps` | no | Default `auto` (detects UPS `1Z…` / USPS 20–22 digits; otherwise the configured default carrier) |
| `line_item_ids` | int[] | regular orders: yes | `WC_Order_Item` IDs in this box. Regular order: the ids from `line_items[]` of `GET /order/{id}/shipments`. **Fulfillment order: the *source* orders' item ids** from `source_orders[].line_items[]` — never the fulfillment's own ids (they match no source, so every source is skipped and you get `fst_nothing_added`). Omit only when the box holds every item of every source: the fan-out then stores a covers-all row on each source and flips each one to Shipped. A comma-separated string is also accepted. |
| `ship_date` | `Y-m-d` | no | Defaults to now |

```bash
curl -s -X POST 'https://fishotel.com/wp-json/fst/v1/shipment' \
  -u 'jeff:abcd efgh ijkl mnop qrst uvwx' \
  -H 'Content-Type: application/json' \
  -d '{"order_id":34183,"tracking_number":"1Z50W72W0115498880","carrier":"ups","line_item_ids":[4711]}'
```

`201` response (the same result the admin meta box gets, plus a post-poll snapshot):

```json
{
  "message": "Tracking added successfully!",
  "shipment_id": 912,
  "shipment_ids": [912],
  "skipped_order_ids": [],
  "failed_order_ids": [],
  "carrier": "ups",
  "shipments": [
    { "id": 912, "order_id": 34183, "tracking_number": "1Z50W72W0115498880", "carrier": "ups",
      "status": "label_created", "status_label": "Label Created", "tracker_provider": "",
      "line_item_ids": [4711], "ship_date": "2026-10-10 09:15:02", "tracking_url": "https://www.ups.com/track?tracknum=1Z50W72W0115498880" }
  ],
  "order_statuses": { "34183": "shipped" }
}
```

For a fulfillment order `shipment_ids` has one entry per source order that received a row, `skipped_order_ids` names the sources with none of the posted items in the box, and `order_statuses` shows each source's status after the save (`shipped` once every item of that source is covered).

Errors come back as `{"error": "<message>", "code": "<code>"}` with the message the admin meta box shows:

| HTTP | `code` | When |
|------|--------|------|
| 400 | `fst_missing_args` | `order_id` or `tracking_number` missing |
| 400 | `fst_no_items` | Regular order and no `line_item_ids` |
| 400 | `fst_nothing_added` | None of the `line_item_ids` belong to the target order(s) |
| 400 | `fst_invalid_ship_date` | `ship_date` not a valid `Y-m-d` |
| 400 | `rest_invalid_param` | WordPress schema check, e.g. `carrier` not one of `auto`, `ups`, `usps`. Raised by WordPress before the plugin runs, so it uses WordPress's envelope `{"code","message","data":{"status","params","details"}}` — read `message` and `data.params`, there is no `error` key |
| 404 | `fst_order_not_found` | No such order (a refund ID counts as not found) |
| 409 | `fst_duplicate_tracking` | That tracking number is already on this order (it is allowed on *different* source orders) |
| 500 | `fst_create_failed` | Database insert failed |

### `GET /fst/v1/order/{id}/shipments`

```bash
curl -s 'https://fishotel.com/wp-json/fst/v1/order/34183/shipments' \
  -u 'jeff:abcd efgh ijkl mnop qrst uvwx'
```

```json
{
  "order": { "id": 34183, "number": "34183", "status": "processing", "is_fulfillment": false, "source_order_ids": [] },
  "line_items": [ { "id": 4711, "name": "Flame Angel", "quantity": 1, "covered": false } ],
  "fully_shipped": false,
  "shipments": [],
  "source_orders": []
}
```

`shipments` are the *effective* shipments: direct rows for a regular order, or the fan-in from the source orders for a fulfillment (deduped by tracking number). `covered` is true when some shipment lists that item explicitly; `fully_shipped` is `null` for fulfillments because coverage lives on the source orders (`source_order_ids` is where a POST fans out to).

For a fulfillment order, `source_orders[]` has one entry per `source_order_ids` id with that source's **own** items, coverage and rows — this is where the ids to POST come from:

```json
{
  "order": { "id": 34200, "number": "34200", "status": "fulfillment", "is_fulfillment": true, "source_order_ids": [34168, 34159] },
  "line_items": [ { "id": 4790, "name": "Combined box", "quantity": 1, "covered": false } ],
  "fully_shipped": null,
  "shipments": [],
  "source_orders": [
    { "id": 34168, "number": "34168", "status": "processing", "fully_shipped": false,
      "line_items": [ { "id": 4711, "name": "Flame Angel", "quantity": 1, "covered": false },
                      { "id": 4712, "name": "Yellow Tang", "quantity": 1, "covered": false } ],
      "shipments": [] },
    { "id": 34159, "number": "34159", "status": "processing", "fully_shipped": false,
      "line_items": [ { "id": 4650, "name": "Clownfish pair", "quantity": 1, "covered": false } ],
      "shipments": [] }
  ]
}
```

### For the label skill: POST the tracking here instead of driving the admin screen

The Finiship label skill used to save the tracking number by filling the order-edit meta box in a browser, which broke every time the markup changed. Use the API instead — it is the exact same service the meta box calls, so fan-out, coverage, dedupe, EasyPost routing, the first poll, order notes and the Processing → Shipped transition all happen.

1. `GET /fst/v1/order/{ORDER}/shipments` — read `order.is_fulfillment`, `line_items[]` and, for a fulfillment, `source_orders[]`.
2. `POST /fst/v1/shipment` with `order_id`, `tracking_number`, `carrier: "ups"` and:
   - regular order, one box with everything → `line_item_ids` = the ids of every item with `"covered": false`;
   - multi-box → only the ids going in *this* box;
   - fulfillment order → `line_item_ids` = the `"covered": false` ids from **every** `source_orders[].line_items[]` that are actually in the box (the union across sources). Never the fulfillment's own `line_items[]` ids. Omit `line_item_ids` only when the box holds every item of every source — a source whose items are only partly in the box would otherwise be flipped to Shipped and emailed while the rest is still in QT.
3. Confirm from the `201` body: `shipment_ids` is non-empty and `order_statuses[ORDER]` is `"shipped"` (regular order) or `order_statuses` lists each fully-boxed source as `"shipped"` (fulfillment; partially boxed sources stay `"processing"`). Or re-run step 1 and check `fully_shipped` / `source_orders[].fully_shipped`. A `409` means the number was already saved — treat it as done.

Keep the application password out of the skill file; read it from the device's credential store or an environment variable at run time.

## Development

### File Structure

```
fishotel-shiptracker/
├── fishotel-shiptracker.php   # Main plugin entry point
├── admin/
│   ├── css/admin.css          # Admin styles
│   ├── js/admin.js            # Admin JavaScript
│   └── views/                 # Admin page templates
├── includes/
│   ├── class-fst-activator.php    # Database setup
│   ├── class-fst-deactivator.php  # Cleanup on deactivation
│   ├── class-fst-email.php        # Email notification system
│   ├── class-fst-migrator.php     # AST/TrackShip migration
│   ├── class-fst-myaccount.php    # Customer account pages
│   ├── class-fst-order.php        # Order meta box integration
│   ├── class-fst-rest-api.php     # REST API endpoints
│   ├── class-fst-settings.php     # Settings page
│   ├── class-fst-shipment.php     # Shipment data model
│   ├── class-fst-tracker.php      # Polling engine
│   ├── class-fst-updater.php      # GitHub auto-updater
│   └── carriers/
│       ├── class-fst-carrier.php      # Abstract carrier base
│       ├── class-fst-carrier-ups.php  # UPS API integration
│       └── class-fst-carrier-usps.php # USPS API integration
├── assets/
│   └── css/frontend.css       # Customer-facing styles
└── tests/
    └── add-tracking-args-test.php  # Plain-PHP test of the add-tracking argument normaliser
```

### Tests

The add-tracking argument normaliser (`FST_Order::normalize_add_tracking_args()`) is pure PHP and has a WordPress-free test:

```bash
php tests/add-tracking-args-test.php
```

## License

This software is proprietary. Copyright (C) 2024-2026 FisHotel. All rights reserved. See the [LICENSE](LICENSE) file for details.
