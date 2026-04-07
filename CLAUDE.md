# FisHotel ShipTracker - Project Overview & Instruction Manual

**Version:** 1.5.3
**Author:** FisHotel
**Type:** WordPress / WooCommerce Plugin (Proprietary)
**Repository:** Dierks27/fishotel-shiptracker

---

## What This Plugin Does

FisHotel ShipTracker is a self-hosted shipment tracking plugin for WooCommerce. It connects to UPS and USPS via their OAuth 2.0 APIs, automatically polls for tracking updates, and keeps your customers informed with branded email notifications.

Key capabilities:
- Automatic tracking of UPS and USPS shipments
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

Credentials are OAuth 2.0 client credentials obtained from:
- UPS: https://developer.ups.com
- USPS: https://developer.usps.com

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
- created_at, updated_at
- Indexes on: order_id, tracking_number, status, carrier, last_checked

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
│   ├── class-fst-tracker.php        # Polling engine (cron-driven)
│   ├── class-fst-updater.php        # GitHub release auto-updater
│   └── carriers/
│       ├── class-fst-carrier.php      # Abstract carrier base class
│       ├── class-fst-carrier-ups.php  # UPS API (OAuth 2.0 + tracking)
│       └── class-fst-carrier-usps.php # USPS API (OAuth 2.0 + tracking)
└── assets/
    └── css/frontend.css             # Customer-facing styles
```

### REST API Endpoints

| Endpoint | Method | Auth | Description |
|----------|--------|------|-------------|
| `/fst/v1/shipment/{id}` | GET | Admin | Get shipment details + events |
| `/fst/v1/shipment` | POST | Admin | Create a new shipment |
| `/fst/v1/shipment/{id}/recheck` | POST | Admin | Force tracking update |
| `/fst/v1/track` | POST | Public | Customer lookup by order # + email |
| `/fst/v1/analytics` | GET | Admin | Status counts and late shipments |

The public `/track` endpoint validates order number + billing email and returns the same error for missing orders and email mismatches to prevent order enumeration.

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
