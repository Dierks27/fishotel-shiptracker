# FisHotel ShipTracker - Project Overview & Instruction Manual

**Version:** 1.9.18
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
- Records what each box actually cost to ship (label cost, box size, weight, where the label was bought) and reports it against the shipping customers paid
- Supplies (boxes, bags, heat packs, ice packs, foam, paper): a purchase log, stock on hand with recounts and a reorder notice, and the materials each box used — so "cost" is label + materials
- Local pickups and orders that went in another order's box, recorded on the order without inventing a shipment
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

### Boxes & label costs
Three editable lists, all empty until the owner fills them in (nothing is seeded), saved through the normal settings form:
- **Standard Boxes** - name + length, width, height in inches. Offered as a dropdown wherever a label cost is entered; a box name is also accepted as `box_dims` and stored as its size. Since 1.9.18 each box also holds what it starts with: `foam_cost` and `paper_cost` (two separate flat amounts, "0.00" by default), `paper_feet` (feet of paper per box, optional) and `ice_packs` (how many when cooling is needed). Option `fst_standard_boxes`, read with `FST_Settings::get_standard_boxes()`.
- **Label Sources** - the partners labels are bought through (e.g. "Partner A", "Partner B"). Option `fst_label_sources`, read with `FST_Settings::get_label_sources()`. A shipment may still hold free text that is not on the list.
- **Supplies** - name, unit (`each` / `sheets` / `feet` / free text), `counted` ("count it down"), `reorder_level` (counted supplies only), and an optional `role` so the rest can find it: `heat_pack`, `ice_pack`, `fish_bag`, `foam`, `paper`, or `box` with `box_name` = one standard box. At most one supply per role, except `box` (one per standard box); a second one keeps its row and loses the role. A standard box renamed in a save takes its box supply along (`FST_Settings::renamed_boxes()`: rows are posted under the number they were shown with). The `foam` supply also keeps `sheet_length` / `sheet_width` in feet (8 and 4 by default) and `thickness` (0.5 in). Options `fst_supplies` and `fst_supply_last_id` (ids are stable and never reused); read with `FST_Supplies::get_supplies()` (adds `price_each`, `on_hand`, `recount_needed`, `low`) or `get_supplies_raw()` (no database).
- **Foam waste** - `fst_foam_waste_percent`, 15 by default; only used by the foam suggestion.

Beside each standard box's foam and paper cost a **suggestion** is shown with its working, and filled in only when it is clicked (`.fst-suggestion` in admin.js): foam = box surface 2(LW + LH + WH) in square feet × the price per square foot of the latest foam purchase (total paid ÷ sheets ÷ sheet area) × (1 + waste percent) — `FST_Supplies::foam_suggestion()`; paper = `paper_feet` × the price per foot of the latest paper purchase — `paper_suggestion()`. Nothing uses a suggestion until the owner saves it as the box's amount.

Below the settings form, **Purchases & stock** (forms of their own: `manage_woocommerce` + nonce `fst_supply_action`, handled by `FST_Settings::handle_supply_action()`):
- **Log / correct / remove a purchase** - supply, date, how many, total paid, note. Table `wp_fst_supply_purchases`. Price each = total paid ÷ how many of the LATEST purchase (by date, then id) — `FST_Supplies::latest_purchase()` / `price_each()`, four decimals.
- **Stock** (counted supplies) - on hand = bought − used ± recounts (`FST_Supplies::get_stock()`: three `SUM … GROUP BY supply_id` queries). **Recount** sets the real number and stores the difference with the date and who (`wp_fst_supply_counts`). Never negative on screen: below zero shows 0 with "recount needed".
- **Reorder notice** - counted supplies at or under their reorder level, with on hand: at the top of the Supplies list and on the dashboard widget (`FST_Settings::render_reorder_notice()`), and for other plugins `apply_filters( 'fst_low_supplies', array() )` → `FST_Supplies::get_low_supplies()` rows (`id`, `name`, `unit`, `on_hand`, `reorder_level`, `recount_needed`).

Below the lists, **Import label costs** (its own form: `manage_woocommerce` + nonce `fst_import_label_costs`): upload a CSV (2 MB limit, checked before the file is read) or paste the rows.
- The header row is required; columns are found by their header text: tracking / tracking number / tracking #, and cost / amount / total / charge / price. Optional: weight (pounds — a column headed oz / kg is ignored), dimensions / box (or separate Length, Width, Height columns), source.
- The cost column is never guessed: `detect_csv_columns()` returns every header that fits in `cost_candidates` and sets `cost` only when there is exactly one. With more (`Customer Paid Amount` + `Label Amount`) `read_import()` stops with an error naming them and nothing is written. Headers containing order / insur / tax / fee / surcharge / base … are not candidates, so `Base Charge, Fuel Surcharge, Total Charge` still reads `Total Charge`.
- **These labels were bought through** applies to the whole file when it has no source column.
- Only tracking numbers that already exist are touched, and only the four cost fields (not even `updated_at`). Without **Overwrite**, a box that already has a cost keeps it and only its empty fields are filled.
- The report lists: matched, already had a cost, not found, invalid (with row numbers and reasons). A tracking number a spreadsheet turned into scientific notation (`9.40011E+21`) is reported as invalid, never guessed; a tracking number listed twice with different costs is invalid on both rows.
- Handles a UTF-8 / UTF-16 BOM, CRLF, quoted fields and tab- or semicolon-separated text.
- `FST_Label_Cost::import()` lifts PHP's time limit (`set_time_limit( 0 )`) before it writes: every row has its own queries, and a full 2 MB file must not stop part-way without a report.

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

### Recording What a Box Cost to Ship
The price of a label is known when it is bought, so it is recorded with the tracking number. Four optional fields travel with `add_tracking()` (meta box, `fst_add_tracking`, `POST /fst/v1/shipment`):

| Field | Stored as | Accepted |
|-------|-----------|----------|
| `label_cost` | `DECIMAL(10,2)`, e.g. `18.40` | 0 or more; `$` and thousands separators tolerated (`$1,018.4`); rounded to cents. Negative / non-numeric / a currency sign with no amount (`$`) → `fst_invalid_label_cost` |
| `box_weight` | `DECIMAL(6,2)` pounds | above 0; a trailing `lb` / `lbs` tolerated → else (a unit with no number included) `fst_invalid_box_weight` |
| `box_dims` | `LxWxH` inches, e.g. `10x10x10` | `10x10x10`, `10 x 10 x 10`, `10*10*10`, `10X10X10 in`, a list of three numbers, or the **name** of a standard box (stored as its size) → else `fst_invalid_box_dims` |
| `label_source` | up to 40 characters | free text; a value matching a saved label source takes the saved spelling |

An empty value means "not recorded" and is stored as `NULL` — never `0`. A cost of `0` is a real, recorded cost.

**One box, one cost.** A tracking number is one physical box. When fan-out writes several rows for one tracking number, the four fields are stored on ONE row only (the first row that call created; if a row from an earlier call already holds them, that row keeps holding them) and every other row of the tracking number stays `NULL`. The box is always read by tracking number, never by row, so no sum can count a box twice. When the row holding the fields is removed while sibling rows remain, the fields move to the oldest surviving row (`FST_Shipment::delete()`).

Helpers on `FST_Shipment` (the theme should probe `method_exists( 'FST_Shipment', 'get_order_boxes' )`):
- `get_box_cost( $tracking_number )` → `tracking_number`, `label_cost`, `box_weight`, `box_dims`, `label_source` (decimal strings; `null` = not recorded), `shared_box`, `order_ids[]`, `shipment_ids[]`, `cost_shipment_id`; `null` when no shipment has that number.
- `set_box_cost( $tracking_number, array $fields, $preferred_id = 0 )` → only the keys present change; a `null` value clears that field. Leaves `updated_at` alone.
- `clear_box_cost( $tracking_number )`.
- `get_order_boxes( $order_ids )` → one entry per distinct tracking number of the order(s), oldest first: the `get_box_cost()` keys plus `shipment_id`, `carrier`, `status`, `ship_date`, and since 1.9.18 `materials`, `materials_total`, `total_cost` (see "Recording What Went Into a Box").

**Changing it later:** `FST_Order::set_label_cost( $shipment_id, array $args )`, behind the AJAX action `fst_set_label_cost` (nonce `fst_nonce` in `nonce`, `manage_woocommerce`; POST `shipment_id` + any of the four fields) and `POST /fst/v1/shipment/{id}/cost`. A field sent empty clears it; a field that is not sent is left alone. It acts on the box of that shipment, whichever row is named. AJAX success data / REST 200 body: `message`, `shipment_id`, `tracking_number`, the four fields, `shared_box`, `order_ids[]`, and since 1.9.18 `materials`, `materials_total`, `total_cost`; AJAX errors are a string message, as for `fst_add_tracking`. `materials` may be posted too (alone or with the four fields).

The plugin's own meta box (used when the theme's Shipments box is off) has the same optional fields on the add form — box as a dropdown of the standard boxes plus "Custom size", source as a dropdown when sources are saved — and shows / edits them on each existing shipment.

### Recording What Went Into a Box (Materials)
Besides its label a box costs its materials: lines of supply + quantity + unit cost, and two flat amounts (foam, paper). The record is `FST_Supplies::get_box_materials( $tracking_number )`:

```php
array(
    'lines'      => array( array( 'supply_id' => 3, 'name' => 'Fish bag', 'role' => 'fish_bag', 'unit' => 'each', 'qty' => '6.00', 'unit_cost' => '0.1100', 'cost' => '0.66' ), … ),
    'foam_cost'  => '1.80',
    'paper_cost' => '0.40',
    'total'      => '4.71',
    'split'      => array( 'boxes' => '1.25', 'foam' => '1.80', 'paper' => '0.40', 'bags' => '0.66', 'packs' => '0.60', 'other' => '0.00' ),
)
```
or `null` when none are recorded (a record of zero — foam 0.00, paper 0.00, no lines — is not the same as no record).

Rules:
- **One box, one cost** applies: materials are keyed by tracking number in `wp_fst_supply_usage`, never by shipment row. A fan-out to five source orders stores them once.
- **Snapshots.** Name, role, unit, unit cost and line cost are copied onto the usage row when recorded; the foam and paper amounts are rows of their own (`kind` = `foam` / `paper`, `supply_id` 0, `qty` 0). A later purchase or settings change never rewrites a recorded box. On an edit, a line already on the box keeps its recorded name, role, unit and unit cost — a supply renamed or given another use since does not move a recorded cost to another part of the split; a new line takes the supply as it is now, at the current price each. One exception: a line recorded at 0 was never priced (its supply had no purchase logged yet), so it takes the price each once there is one. `unit_cost` on a line overrides the price, 0 included — and because 0 on record reads as "not priced", a line that must stay free is sent with `unit_cost` 0 each time. Install step: log an opening purchase for every supply before the first box is recorded; until then its lines cost 0.00 and stay so until that box's materials are saved again.
- **Input** (`materials` on `add_tracking()` / `set_label_cost()` / `set_ship_mode()`): `{ lines: [ { supply_id | supply (name), qty, unit_cost? } ], foam_cost, paper_cost }` as an array or a JSON string — `FST_Supplies::normalize_materials()`. Quantities 0 or more with at most 2 decimals and below a million (0 removes the line, the same supply twice is added up) → else `fst_invalid_materials`; unknown supply → `fst_unknown_supply`; money as for `label_cost`. Everything is validated before anything is written.
- **Add** (`fst_add_tracking`, `POST /fst/v1/shipment`): key ABSENT + `box_dims` is a standard box → the defaults below are recorded (only when the box has no materials yet); key present → taken as sent; `null` → none. A custom size records nothing by itself.
- **Update** (`fst_set_label_cost`, `POST /shipment/{id}/cost`): key absent → left alone; `''` / `null` → removed; inside it `lines`, `foam_cost`, `paper_cost` are each kept when absent. Nothing is defaulted. `materials` alone is a valid body.
- **Defaults** (`FST_Supplies::default_materials( $box, $supplies, $prices, $fish_count, $action )`): a standard box → 1 of that box's supply (when it has one), its `foam_cost`, its `paper_cost`; any box → fish bags = the fish in the box, 1 heat pack when the action is `heat_pack`; a standard box → its `ice_packs` when the action is `cooling`. A custom box: foam and paper 0.00, no box supply. A role nobody plays adds no line.
- **Fish in the box** = the sum of the quantities of the shipment's line items (`FST_Supplies::fish_count( $order, $item_ids )`; `null` = all of the order's items; a fan-out adds up its source rows). Filter `fst_box_fish_count` ( `$count`, `$order`, `$item_ids` ).
- **Weather action** = `FST_Supplies::order_pack_action( $order )`: the order meta `_fh_weather_action` written by the FisHotel Misc plugin's Weather section (`heat_pack`, `none`, `cooling`, `ups_store_cold`, `ups_store_hot`, `hold_cold`, `hold_hot`, `unavailable`), read through `get_meta()` — no dependency on that plugin. A source order without a note takes the note of its wrapper (`_fishotel_fulfilled_by_order`); a note whose `_fh_weather_checked_for` differs from the order's `_fishotel_shipping_date` is not used. Filter `fst_order_pack_action` ( `$action`, `$order` ) supplies or overrides it. Only `heat_pack` and `cooling` add anything.
- **Stock** follows the usage table: `set_box_materials()` replaces the box's rows (old lines given back, new ones taken); `FST_Shipment::delete()` clears a box's materials when its LAST row is removed (a shared box keeps them while another order's row remains).

Helpers the theme may use (probe `class_exists( 'FST_Supplies' )` / `method_exists( 'FST_Supplies', 'get_default_materials' )`): `get_supplies()`, `get_box_materials()`, `get_order_materials( $order_id )` (a local pickup's), `get_default_materials( $fish_count, $action )` → `{ boxes: [ { name, dims, materials } ], custom: materials }`, `fish_count()`, `order_pack_action()`, `get_low_supplies()`. `FST_Shipment::get_order_boxes()` entries carry `materials`, `materials_total` and `total_cost` (label + materials; `null` while the label cost is not recorded).

The plugin's own meta box shows the materials under the label cost, edits them in the same form (a quantity per supply — foam and paper supplies are left out there, they are the two flat amounts — offering the usual amounts while nothing is recorded) and posts them as a JSON string.

### How It Went Out: Local Pickup / Another Order's Box
For an order with NO label of its own — `FST_Order::set_ship_mode( $order_id, array $args )`, behind the AJAX action `fst_set_ship_mode` (nonce `fst_nonce` in `nonce`, `manage_woocommerce`; POST `order_id`, `mode`, `with_order_id`, optional `date` Y-m-d, optional `materials`) and `POST /fst/v1/order/{id}/ship-mode`:

| `mode` | Meaning |
|--------|---------|
| `local` | Local pickup. A real label cost of zero. May carry `materials` (same shape as a box's), stored in `wp_fst_supply_usage` keyed by the order id (`tracking_number` = '') so they count down like a box's |
| `with_order` | Went in another order's box: `with_order_id`. That order's box or boxes then count this order's shipping paid against their cost, exactly like a shared fan-out box (split evenly between that order's boxes, spare cents to the earliest) |
| `label` | Clear it — back to "no shipment yet" (`none` and an empty mode mean the same) |

Stored as order meta through the order object, so it is HPOS-safe: `_fst_ship_mode` (`local` / `with_order`; absent = label), `_fst_ship_with_order`, `_fst_ship_mode_date`, `_fst_ship_mode_by`. Without a `date` a new mark is dated now; a mark that stays what it was (same mode, same other order) keeps its day and who made it — a local pickup is reported under that day, and neither screen sends one when a pickup's materials are saved again. It is NEVER a row in `wp_fst_shipments`: no customer e-mail, no status change, no polling — only a private order note. Read it with `FST_Ship_Mode::get( $order )` (`mode`, `with_order_id`, `date`) or `FST_Ship_Mode::describe( $order )` (adds `with_order_number`, `materials`, `materials_total`).

Rules (`FST_Ship_Mode::check()`, pure): the order must have no shipments (`fst_order_has_shipments`, HTTP 409); the other order must exist (`fst_order_not_found`), must not be the order itself, must not itself be `with_order` (no chains) or `local` (no box), and an order that carries others cannot be carried, nor be a local pickup — it would have no box for them to be in (`fst_invalid_ship_mode`). Adding tracking to a marked order removes the mark (and a pickup's materials) with a note — through `add_tracking()` and through the order form's non-AJAX `save_tracking_data()` alike. AJAX success data / REST 200 body: `message`, `order_id` and the `describe()` keys; AJAX errors are a string message.

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
- **Dashboard Widget** (wp-admin home) - Shows active shipment counts, exceptions, late alerts, and recently delivered orders at a glance, and — when a counted supply is at or under its reorder level — a "Time to reorder" notice with how many are on hand
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
Select from presets (7 Days, 30 Days, 90 Days, 6 Months, 1 Year, All Time) or use custom date pickers. All data filters by **ship date** (when the order actually shipped), not the date the record was created in the plugin. The end date is included in full (`FST_Shipment::range_end()` turns a bare `Y-m-d` into the end of that day), so a range ending today contains what shipped today.

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

### Box Costs (label + materials)
For the boxes shipped in the selected range (ship date, the last day included, same Unknown / Label Created exclusion), built by `FST_Label_Cost::get_report()` → `build_report()`. Since 1.9.18 every "cost" is the label plus the box's materials:
- **Cards** - boxes shipped, with / without a recorded cost, total cost, shipping customers paid, the difference (paid − cost), average cost per box. The four amounts are `null` in `summary` (shown as a dash, never $0.00) when no box in the range has a recorded cost.
- **What the Cost Is Made Of** - `split`: label, boxes, foam, paper, bags, packs (heat + ice), other, total — for all boxes, and per box size (`split_by_size[dims]`) and per label source (`split_by_source[source]`). The parts always add up to the total. `materials` holds the materials total, `without_label_cost` (materials on boxes that are left out) and `boxes_with_materials`.
- **By Box Size** - boxes, average cost, average shipping paid, average difference.
- **By Label Source** - boxes, total and average cost. Sources are grouped without regard to letter case, under the first spelling seen (saved sources already take their saved spelling on input; this covers free text).
- **Average Cost per Box Size, by Label Source** - side by side, the cheaper source for each size highlighted (named sources only; a tie highlights nobody).
- **The 20 Boxes That Lost the Most** - linked order numbers, tracking, size, source, label cost, materials, shipping paid, difference.
- **Free shipping given** (`free_shipping`) - orders that shipped with a label of their own and paid 0.00 shipping: how many, and what they cost (their share of the label + materials of their boxes; a shared box is shared out evenly between its own orders). **Local pickups** (`local_pickups`) - how many in the range (by the day they were marked), and their materials. **Went in another order's box** (`in_other_box`) - how many.
- **Supplies** (`supplies`, `FST_Supplies::build_supplies_report()`) - per supply in the range: bought (how many, money), used (on the boxes shipped and the local pickups made in the range — with or without a label cost), cost of what was used, on hand now, reorder level / low. For foam and paper (`ballparks`): what was bought beside what the per-box flat amounts added up to, so the ballpark can be corrected.

Rules: boxes without a recorded LABEL cost are counted as shipped but left out of every total, average and difference, their materials included (the page says so, and says how much shipping was paid for them) — `NULL` is never treated as 0. Their materials are still counted in the Supplies figures. A box with a label cost and no materials recorded costs its label. "Shipping paid" is the order's shipping total excluding tax, less any shipping refunded. A box shared by several orders counts the shipping of all of them; an order that went out in several boxes has its shipping split evenly between its boxes (spare cents to the earliest), so it is counted once. Money is summed in whole cents and shown through `wc_price()`.

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
The plugin creates five custom tables:

**`wp_fst_shipments`** - Main shipment records
- id, order_id, tracking_number, carrier, status, status_detail
- ship_date, est_delivery, delivered_date
- last_checked, last_event_at, tracking_data, check_count
- easypost_tracker_id, tracker_provider (EasyPost integration; DB version 1.1.0)
- line_item_ids (DB version 1.2.0; JSON array of WC_Order_Item IDs in this shipment, NULL = covers all items of order_id)
- label_cost DECIMAL(10,2), box_weight DECIMAL(6,2) (lb), box_dims VARCHAR(40) ("LxWxH" inches), label_source VARCHAR(40) (DB version 1.3.0; all NULL = not recorded, never 0 by default). These describe the BOX (tracking number), are filled on one row per tracking number only, and are read / written through `FST_Shipment::get_box_cost()` / `set_box_cost()` — never sum `label_cost` over raw rows
- created_at, updated_at
- Indexes on: order_id, tracking_number, status, carrier, last_checked, easypost_tracker_id, tracker_provider
- Note: `tracking_number` is no longer globally unique — duplicates are allowed across DIFFERENT `order_id` values (combined-box fan-out). The same tracking_number+order_id pair is still rejected.

**`wp_fst_tracking_events`** - Individual tracking events per shipment
- id, shipment_id, status, description, location, event_time, raw_data, created_at
- Indexes on: shipment_id, status, event_time

**`wp_fst_supply_purchases`** (DB version 1.4.0) - The purchase log
- id, supply_id, purchase_date DATE, qty DECIMAL(12,2), total_paid DECIMAL(10,2), note VARCHAR(190), created_at
- Indexes on: supply_id, purchase_date

**`wp_fst_supply_usage`** (DB version 1.4.0) - The materials of a box, or of a local pickup
- id, tracking_number (the box; '' for a local pickup), order_id (0 for a box; the order of a local pickup), kind (`supply` / `foam` / `paper`), supply_id (0 for the two flat amounts), supply_name, role, unit, qty DECIMAL(12,2), unit_cost DECIMAL(12,4), line_cost DECIMAL(10,2), recorded_at
- Everything but the keys is a snapshot. Read / write through `FST_Supplies::get_box_materials()` / `set_box_materials()` (and `…_order_materials()`); "used" is `SUM(qty) … WHERE supply_id > 0 GROUP BY supply_id`
- Indexes on: tracking_number, order_id, supply_id

**`wp_fst_supply_counts`** (DB version 1.4.0) - Recounts
- id, supply_id, counted DECIMAL(12,2) (the real number), delta DECIMAL(12,2) (the correction it made), counted_at, user_id
- Index on: supply_id

Upgrades only add (`dbDelta`): from 1.2.0 the four cost columns and the three supplies tables, from 1.3.0 the three tables. No existing row changes. The supplies list itself is an option (`fst_supplies`), like the standard boxes.

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
│   ├── class-fst-shipment.php       # Shipment data model (CRUD, queries, analytics, box cost helpers)
│   ├── class-fst-label-cost.php     # Label costs: field normalisation, one-box-one-cost, CSV import, report maths (no DB)
│   ├── class-fst-supplies.php       # Supplies list, purchases, stock / recounts, materials of a box (defaults, snapshots), suggestions, supplies report
│   ├── class-fst-ship-mode.php      # Local pickup / went in another order's box: rules + order meta
│   ├── class-fst-tracker.php        # Polling engine (cron-driven) + EasyPost poll/promote/backfill
│   ├── class-fst-easypost.php       # EasyPost aggregator (trackers, webhook, signature, backfill)
│   ├── class-fst-updater.php        # GitHub release auto-updater
│   └── carriers/
│       ├── class-fst-carrier.php      # Abstract carrier base class
│       ├── class-fst-carrier-ups.php  # UPS API (OAuth 2.0 + tracking)
│       └── class-fst-carrier-usps.php # USPS API (OAuth 2.0 + tracking)
├── assets/
│   └── css/frontend.css             # Customer-facing styles
└── tests/                           # Plain PHP, no WordPress: for t in tests/*-test.php; do php "$t" || break; done
    ├── bootstrap.php                # Stubs, in-memory $wpdb and WC_Order double for the tests
    ├── add-tracking-args-test.php   # FST_Order::normalize_add_tracking_args()
    ├── label-cost-args-test.php     # Money / weight / box-size spellings, standard box names, absent vs empty
    ├── label-cost-box-test.php      # One box, one cost: fan-out rows, removing the holding row
    ├── label-cost-import-test.php   # CSV reading, header detection, row validation, the import itself
    ├── label-cost-report-test.php   # Report maths: shared boxes, multi-box orders, NULL never 0
    ├── supplies-stock-test.php      # Price each from the latest purchase, on hand across purchases / usage / recount / a removed box, reorder notice
    ├── supplies-defaults-test.php   # Supplies list, defaults per box (standard, custom, fish count, weather), snapshots, suggestions, validation
    ├── supplies-report-test.php     # Label + materials split adding up, free shipping, local pickups, supplies report
    ├── ship-mode-test.php           # Ship mode rules, the service (order meta only), shared-cost maths for "went in another box"
    └── hook-callbacks-test.php      # Static scan: every add_action / add_filter / menu callback names a method or function that exists
```

### REST API Endpoints

| Endpoint | Method | Auth | Description |
|----------|--------|------|-------------|
| `/fst/v1/shipment` | POST | Admin | Add tracking to an order via `FST_Order::add_tracking()` — the same service as the meta box (fan-out, coverage, dedupe, auto-detect, EasyPost/first poll, order notes + status) |
| `/fst/v1/order/{id}/shipments` | GET | Admin | Effective shipments (`get_effective_shipments()`) + line-item coverage for an order |
| `/fst/v1/shipment/{id}` | GET | Admin | Get shipment details + events (the four cost fields are the box's, plus `shared_box`) |
| `/fst/v1/shipment/{id}/cost` | POST | Admin | Record / change what the box of that shipment cost via `FST_Order::set_label_cost()` |
| `/fst/v1/shipping-options` | GET | Admin | `standard_boxes[]` (`name`, `length`, `width`, `height`, `dims`, and the box defaults `foam_cost`, `paper_cost`, `paper_feet`, `ice_packs`, `supply_id`), `label_sources[]`, `supplies[]` (`id`, `name`, `unit`, `counted`, `reorder_level`, `role`, `box_name`, `price_each`, `on_hand`, `recount_needed`, `low`), `foam_waste_percent`, `ship_modes[]` |
| `/fst/v1/order/{id}/ship-mode` | POST | Admin | Mark an order with no label of its own via `FST_Order::set_ship_mode()`: `mode` (`label` / `local` / `with_order`), `with_order_id`, optional `date`, `materials` (local) |
| `/fst/v1/shipment/{id}/recheck` | POST | Admin | Force tracking update |
| `/fst/v1/track` | POST | Public | Customer lookup by order # + email |
| `/fst/v1/analytics` | GET | Admin | Status counts and late shipments |
| `/fst/v1/easypost-webhook` | POST | HMAC | EasyPost tracker.created/updated push |

"Admin" = `permission_callback` is `admin_check()` → `current_user_can( 'manage_woocommerce' )`. It works with cookie + `X-WP-Nonce` (admin.js) **and** with WordPress Application Passwords sent as HTTP Basic auth over HTTPS: the plugin hooks neither `determine_current_user` nor `rest_authentication_errors`, and WooCommerce's key/secret auth only covers `wc/*`. If Basic auth yields `rest_not_logged_in`, the host is stripping the `Authorization` header — see README.md for the `.htaccess` fix.

**`POST /shipment` params:** `order_id` (int, required — the order number in admin is the ID), `tracking_number` (required), `carrier` (`auto`|`ups`|`usps`, default `auto`, schema-enforced), `line_item_ids` (int array or comma-separated; required for regular orders; for a fulfillment order pass the *source* orders' item ids from `source_orders[].line_items[]` — never the fulfillment's own ids — and omit only when the box holds every item of every source), `ship_date` (optional `Y-m-d`, stored as midnight), the optional box cost fields `label_cost`, `box_weight`, `box_dims`, `label_source` (see "Recording What a Box Cost to Ship"; not schema-typed — the service validates them) and the optional `materials` (see "Recording What Went Into a Box"; the REST handler uses `has_param()`, so a JSON `null` means "record none" while a missing key means "the standard box's defaults"; also 400 `fst_invalid_materials` / `fst_unknown_supply`). Returns **201** with the service result (`message`, `shipment_id`, `shipment_ids`, `skipped_order_ids`, `failed_order_ids`, `carrier`, `shipments[]` post-poll summaries, `order_statuses{}`), or `{"error","code"}` mapped from the service `WP_Error`: 400 `fst_missing_args` / `fst_no_items` / `fst_nothing_added` / `fst_invalid_ship_date` / `fst_invalid_label_cost` / `fst_invalid_box_weight` / `fst_invalid_box_dims`, 404 `fst_order_not_found`, 409 `fst_duplicate_tracking`, 500 `fst_create_failed`. The messages are the ones the admin meta box shows.

```bash
curl -s -X POST 'https://fishotel.com/wp-json/fst/v1/shipment' \
  -u 'jeff:abcd efgh ijkl mnop qrst uvwx' \
  -H 'Content-Type: application/json' \
  -d '{"order_id":34183,"tracking_number":"1Z50W72W0115498880","carrier":"ups","line_item_ids":[4711]}'

curl -s 'https://fishotel.com/wp-json/fst/v1/order/34183/shipments' -u 'jeff:abcd efgh ijkl mnop qrst uvwx'

# Tracking + what the box cost, in one call (valid box / source names: GET /shipping-options)
curl -s -X POST 'https://fishotel.com/wp-json/fst/v1/shipment' \
  -u 'jeff:abcd efgh ijkl mnop qrst uvwx' \
  -H 'Content-Type: application/json' \
  -d '{"order_id":34183,"tracking_number":"1Z50W72W0115498880","carrier":"ups","line_item_ids":[4711],"label_cost":18.40,"box_weight":11,"box_dims":"10x10x10","label_source":"Partner A"}'

# Add or correct the cost later ("" or null clears a field, a missing key is left alone)
curl -s -X POST 'https://fishotel.com/wp-json/fst/v1/shipment/912/cost' \
  -u 'jeff:abcd efgh ijkl mnop qrst uvwx' \
  -H 'Content-Type: application/json' \
  -d '{"label_cost":"19.25","label_source":"Partner B"}'
```

**`POST /shipment/{id}/cost`** takes any of `label_cost`, `box_weight`, `box_dims`, `label_source`, `materials` and writes to the box (tracking number) of shipment `{id}`. Returns **200** with `message`, `shipment_id`, `tracking_number`, the four fields, `shared_box`, `order_ids[]`, `materials`, `materials_total`, `total_cost`; errors as `{"error","code"}`: 404 `fst_shipment_not_found`, 400 `fst_no_cost_fields` / `fst_invalid_label_cost` / `fst_invalid_box_weight` / `fst_invalid_box_dims` / `fst_invalid_materials` / `fst_unknown_supply`. A 409 on `POST /shipment` writes no cost — use this endpoint to add it.

**`POST /order/{id}/ship-mode`** returns **200** with `message`, `order_id`, `mode`, `with_order_id`, `date`, `with_order_number`, `materials`, `materials_total`; errors as `{"error","code"}`: 404 `fst_order_not_found`, 409 `fst_order_has_shipments`, 400 `fst_invalid_ship_mode` / `fst_invalid_materials` / `fst_unknown_supply`.

**`GET /order/{id}/shipments`** returns `order` (`id`, `number`, `status`, `is_fulfillment`, `source_order_ids` = where a POST fans out to), `line_items[]` (`id`, `name`, `quantity`, `covered`), `fully_shipped` (null for fulfillments — coverage lives on the sources), `shipments[]` (row columns minus `tracking_data`, with `line_item_ids` decoded, `status_label`, `tracking_url`, and the box's `label_cost`, `box_weight`, `box_dims`, `label_source` + `shared_box`, `materials`, `materials_total`, `total_cost`) and `source_orders[]` (fulfillments only, one per `source_order_ids` entry: `id`, `number`, `status`, `line_items[]` with `covered`, `fully_shipped`, `shipments[]` — all from that source's own rows; these are the ids a POST needs). Since 1.9.18 also, for the order: `ship_mode` (`FST_Ship_Mode::describe()`), `weather_action` (the action seen, '' when none), `fish_count` (the quantities of the items not in a box yet; all of them when none or all are; a fulfillment counts its sources' items) and `default_materials` (`boxes[]`: `name`, `dims`, `materials` for every standard box; `custom`: the materials of a custom box) so a script or the theme can prefill.

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
- Box Costs leave out every box without a recorded label cost — the "Without a Cost" card says how many. Add the costs on the orders or import them under Settings > Boxes & label costs
- A supply's "Used" on the Analytics page counts the boxes SHIPPED in the range (a box still "Unknown" / "Label Created" is not in it yet); "On hand" under Settings counts every recorded box at once

### A supply's count looks wrong
- On hand = bought − used on boxes ± recounts. Check the purchase log (a case logged twice, or with the wrong "how many") and recount: Settings > Boxes & label costs > Purchases & stock
- "Recount needed" means more was used than the books say was ever bought — log the missing purchase, or recount
- A box only takes supplies off the shelf when its materials are recorded: a custom-size box, or tracking added without a box size, records none by itself

### A box counts a supply at $0.00
- The box was recorded before that supply had a purchase logged, so there was no price each yet. Log the purchase (Settings > Boxes & label costs > Purchases & stock), then open the box and save its materials again: the line takes the price each. Other boxes are not touched
- A line sent with `unit_cost` 0 (a free one) reads the same on record; it stays 0 only as long as it is sent with 0 — the theme's Shipments box does that for the lines added through "Add supply…"

### Plugin update requires two clicks
- Fixed in v1.5.2. If on an older version, the second click completes the update normally.
