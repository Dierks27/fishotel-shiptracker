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

The plugin exposes REST endpoints under the `fst/v1` namespace:

| Endpoint | Method | Description |
|----------|--------|-------------|
| `/fst/v1/shipments` | GET | List shipments (admin) |
| `/fst/v1/shipments` | POST | Create a shipment (admin) |
| `/fst/v1/shipments/{id}` | DELETE | Remove a shipment (admin) |
| `/fst/v1/shipments/{id}/recheck` | POST | Force a tracking update (admin) |
| `/fst/v1/track` | POST | Track a shipment (public) |

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
└── assets/
    └── css/frontend.css       # Customer-facing styles
```

## License

This software is proprietary. Copyright (C) 2024-2026 FisHotel. All rights reserved. See the [LICENSE](LICENSE) file for details.
