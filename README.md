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

### Boxes & Label Costs

The **Boxes & label costs** settings tab holds three lists. All start empty — fill them in once:

- **Standard Boxes** — a name and the length, width and height in inches of each box you usually ship in. They appear as a dropdown wherever a label cost is entered; a custom size can always be typed instead. Each box also has what it starts with: a **foam cost** and a **paper cost** (two separate flat amounts, your ballpark per box), the **feet of paper** it takes (optional) and how many **ice packs** go in when the weather calls for cooling.
- **Label Sources** — the name of each partner you buy labels through (for example "Partner A" and "Partner B"). The Analytics page compares their average cost per box size.
- **Supplies** — everything else a box costs: the boxes themselves, fish bags, heat packs, ice packs, foam, paper, and anything else you want on the books. See below.

When tracking is added to an order you can also enter what the label cost, the box, its weight and where the label was bought. All four are optional and can be added or corrected later on the shipment. A tracking number is one physical box: when one box holds several orders its cost is stored once, so it is never counted twice.

They are stored on `wp_fst_shipments` (database version 1.3.0, added automatically on update): `label_cost DECIMAL(10,2)`, `box_weight DECIMAL(6,2)` (pounds), `box_dims VARCHAR(40)` (`LxWxH` in inches, e.g. `10x10x10`) and `label_source VARCHAR(40)`. All four are `NULL` until recorded — an unrecorded cost is never treated as 0.

Database version 1.4.0 (also added automatically on update; nothing existing is changed) adds three tables: `wp_fst_supply_purchases` (the purchase log), `wp_fst_supply_usage` (the materials of each box, keyed by tracking number — or by order for a local pickup) and `wp_fst_supply_counts` (recounts).

#### Supplies, purchases and stock

Each supply has a name, a unit (`each`, `sheets`, `feet` or your own words), **Count it down** (keep a count of how many are left) with an optional **Reorder at** level, and **Used as** — what lets a new box find the supply by itself: *Heat pack*, *Ice pack*, *Fish bag*, *Foam*, *Paper*, or *The box: &lt;one of your standard boxes&gt;*. Only one supply can have each use (a box supply: one per standard box); a second one keeps its row but loses the use. Renaming a standard box keeps its box supply tied to it.

Below the lists, **Purchases & stock**:

- **Log a purchase** each time you buy more: the supply, the date, how many came in the case (sheets of foam, feet of paper on the roll), what you paid in total, and optionally where you bought it. Purchases can be corrected and removed.
- **The price of one** = total paid ÷ how many, of the *latest* purchase (by date; two on the same day: the one logged last). New boxes use it. Boxes already recorded keep the price they were recorded at.
- **Stock** (supplies that are counted down): on hand = bought − used on boxes ± recounts. Removing a box from an order gives its supplies back. When the shelf and the books disagree, **recount**: enter how many are really there, and the difference is stored with the date and who counted. The screen never shows less than nothing — a count that would be below zero shows 0 with "recount needed".
- **Reorder notice** — supplies at or under their reorder level are listed, with how many are on hand, at the top of the Supplies list and on the ShipTracker dashboard widget. Other plugins can read the same list with `apply_filters( 'fst_low_supplies', array() )`.

**Foam and paper** are awkward to count per box, so they are a flat amount per box, and their purchases are only logged (a sheet, a roll) so you can see what you spend. The foam supply keeps its sheet size (8 ft × 4 ft, ½ in thick unless you change it). Beside each standard box's foam cost a **suggestion** is shown with its working — the box surface, all six sides, 2 × (L×W + L×H + W×H), at the price per square foot of your latest foam purchase, plus the **foam waste** percent (15% unless you change it): "Suggested: $1.80 — 4.2 sq ft of a 32 sq ft sheet, plus 15% waste". The paper suggestion is the box's feet of paper × the price per foot of your latest paper purchase. A suggestion is never applied by itself: click it to fill the field in, then save.

#### What went into a box

A box records its **materials**: lines of supply + quantity + what one cost, and the two flat amounts (foam, paper), with a total. They are stored once per tracking number — like the label cost, a box shared by several orders is one box — and they are a snapshot: a later purchase at another price, or a changed foam cost on the standard box, never rewrites a box that was already recorded.

When tracking is added with a **standard box** and no materials are sent, the box's usual materials are recorded:

- one of that box's own supply (when a supply is "The box: …" for it);
- the box's foam cost and paper cost;
- one fish bag per fish in the box — the quantities of the line items in this shipment added up;
- **one heat pack** when the order's weather action is *heat pack*; the box's **ice-pack count** when it is *cooling*.

A custom size starts with nothing (no foam, no paper, no box) — send or enter what was used. Everything can be changed afterwards on the shipment; a quantity of 0 removes the line.

The weather action is read from the order when the FisHotel Misc plugin's Weather section has noted it (order meta `_fh_weather_action`: `heat_pack`, `cooling`, `none`, …; a combined box is noted on its wrapper order). ShipTracker does not depend on that plugin: without a note there is simply no pack by default. The filter `fst_order_pack_action` ( `$action`, `$order` ) can supply the action from anywhere else, and `fst_box_fish_count` ( `$count`, `$order`, `$item_ids` ) can adjust the fish count (an order with dry goods on it).

#### Local pickups and orders that went in another box

Some orders never get a label of their own. On an order with no shipment, **How it went out** offers:

- **Local pickup** — no label: a real label cost of zero. It may still have used materials (bags), which are recorded with the order and counted down like a box's.
- **Went in another order's box** — pick the order. That order's box (or boxes) then counts this order's shipping paid against its cost, exactly like a box shared by a combined shipment.

This is a fact about the order, stored as order meta (`_fst_ship_mode`, `_fst_ship_with_order`, `_fst_ship_mode_date`) — never a shipment: nothing is tracked, no customer e-mail is sent and the order status is left alone. Choosing "With a label" again clears it; so does adding tracking to the order.

#### Import label costs

On the same tab, **Import label costs** records costs in bulk from a spreadsheet export:

1. Upload a CSV (up to 2 MB) or paste the rows, header row included — cells copied straight from a spreadsheet work too.
2. The columns are found by their header text: a tracking column (`tracking`, `tracking number`, `tracking #`) and a cost column (`cost`, `amount`, `total`, `charge`, `price`) are required; `weight` (pounds), `dimensions` / `box` and `source` are used when present. The file must have exactly one column that could be the cost: with two (say `Customer Paid Amount` and `Label Amount`) nothing is imported and the message names them — remove the one that is not what the label cost and import again. Order, insurance, tax, fee, surcharge and base columns are not taken for the cost.
3. If the file has no source column, pick **These labels were bought through** for the whole file.
4. Leave **Overwrite** unticked to keep costs that are already recorded (only their empty fields are filled); tick it to replace them.

Only tracking numbers that already exist in ShipTracker are updated, and nothing but the four cost fields is ever changed. The result lists how many rows were matched, already had a cost, were not found or were invalid, with the row numbers. A tracking number that a spreadsheet turned into scientific notation (`9.40011E+21`) is reported as invalid — format that column as text and export again.

```csv
Tracking #,Total Charge,Weight,Box
1Z50W72W0115498880,18.40,11,10x10x10
1Z50W72W0115498881,"$1,018.40",20,Large
```

#### Box cost report

**ShipTracker > Analytics > Box Costs** shows, for the boxes shipped in the selected date range — "cost" is the label **plus the materials**:

- boxes shipped, how many have / do not have a recorded label cost, total cost, the shipping customers paid, the difference and the average cost per box (the four amounts show a dash, not $0.00, while no box in the range has a recorded cost);
- **What the Cost Is Made Of** — label, boxes, foam, paper, bags, packs (heat and ice) and other, adding up to the total: for all boxes, for each box size and for each label source;
- **By Box Size** — boxes, average cost, average shipping paid, average difference;
- **By Label Source** — boxes, total and average cost — and the average cost per box size for each source side by side, with the cheaper source highlighted. A source typed by hand counts as one whatever its letter case (`Partner C` and `partner c` are one row);
- **The 20 Boxes That Lost the Most** — order numbers (linked), tracking number, size, source, label cost, materials, shipping paid, difference;
- **Free shipping given** — how many orders shipped with a label and paid nothing for shipping, and what they cost you; **Local pickups** — how many, and the materials they used; **Went in another order's box** — how many.

Then **Supplies**, for the same range: per supply what was bought (how many, what you paid), what was used on the boxes shipped and the local pickups made, how many are on hand now, and whether it is at or under its reorder level. For foam and paper: what you bought beside what the per-box amounts added up to — when the two are far apart over a long range, correct the foam or paper cost of your standard boxes.

Boxes without a recorded label cost are left out of every total, average and difference (their materials too); the page says how many there are. Their materials are still counted in the Supplies figures. "Shipping paid" is the order's shipping total before tax, less any shipping refunded. A box shared by several orders counts the shipping of all of them, and an order that went out in several boxes has its shipping split evenly between them, so it is counted once.

### Email Notifications

The **Email Templates** tab lets you customize HTML email notifications sent at each shipment status change. Use the built-in email designer to preview and test templates.

## Features

- **Shipment Tracking** - Automatic polling of UPS and USPS APIs via OAuth 2.0
- **Order Integration** - Add tracking numbers directly from the WooCommerce order edit screen
- **Customer Portal** - Tracking info displayed on the My Account > Orders page
- **Email Notifications** - Branded HTML emails sent on status changes
- **Analytics Dashboard** - KPIs and 30-day shipment charts
- **Label Costs** - Record what each box cost to ship (label cost, box size, weight, label source), import costs from CSV, and see cost against shipping paid by box size and label source
- **Supplies** - Boxes, bags, heat packs, ice packs, foam and paper: a purchase log, the price of one from the latest purchase, stock on hand with recounts and a reorder notice, and the materials each box used
- **Local Pickups** - Mark an order as picked up, or as gone in another order's box, without inventing a shipment
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
| `/fst/v1/shipment/{id}/cost` | POST | Admin | Record or change what the box of that shipment cost |
| `/fst/v1/shipping-options` | GET | Admin | The standard boxes (with what each starts with), label sources and supplies saved in Settings |
| `/fst/v1/order/{id}/ship-mode` | POST | Admin | Mark an order with no label of its own: local pickup, or went in another order's box |
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
| `label_cost` | number or string | no | What the label cost: 0 or more. `$` and thousands separators are tolerated (`"$1,018.4"`), more than two decimals are rounded to cents. A currency sign with no amount (`"$"`) is invalid, not empty |
| `box_weight` | number or string | no | Pounds, above 0 (`11`, `"11 lbs"`) |
| `box_dims` | string | no | Box size in inches as `LxWxH` — `10x10x10`, `10 x 10 x 10`, `10*10*10` and `10X10X10 in` are all stored as `10x10x10` — or the **name** of a standard box from `GET /shipping-options` (stored as its size) |
| `label_source` | string | no | Where the label was bought: a name from `GET /shipping-options`, or free text (40 characters) |
| `materials` | object | no | What went into the box besides the label: `{ "lines": [ { "supply_id": 3, "qty": 6 }, { "supply": "Heat pack", "qty": 1 } ], "foam_cost": "1.80", "paper_cost": "0.40" }`. A line names its supply by `supply_id` or by `supply` (its name, any letter case) — both from `GET /shipping-options`. **Leave the key out** and a standard box records its usual materials (see "What went into a box"); **send it** and it is taken exactly as sent; send `null` to record none. Form-encoded callers may send the same object as a JSON string |

The four cost fields describe the **box** and are all optional; leaving one out (or empty) records nothing for it — it is stored as "not recorded", never as 0. They are stored once per tracking number, however many source orders a fulfillment fans out to, and come back on every shipment of that box together with `shared_box`.

`materials` are stored once per tracking number too. Quantities are 0 or more with at most two decimals (0 removes the line; the same supply on two lines is added up); `foam_cost` and `paper_cost` follow the `label_cost` rules. An optional `unit_cost` on a line (up to four decimals) overrides the supply's current price. Each shipment in the reply carries `materials` (`lines[]` with `supply_id`, `name`, `role`, `unit`, `qty`, `unit_cost`, `cost`; `foam_cost`; `paper_cost`; `total`; `split`), `materials_total` and `total_cost` (label + materials, `null` while the label cost is not recorded).

```bash
curl -s -X POST 'https://fishotel.com/wp-json/fst/v1/shipment' \
  -u 'jeff:abcd efgh ijkl mnop qrst uvwx' \
  -H 'Content-Type: application/json' \
  -d '{"order_id":34183,"tracking_number":"1Z50W72W0115498880","carrier":"ups","line_item_ids":[4711]}'
```

With the cost of the box (the price is known the moment the label is bought):

```bash
curl -s -X POST 'https://fishotel.com/wp-json/fst/v1/shipment' \
  -u 'jeff:abcd efgh ijkl mnop qrst uvwx' \
  -H 'Content-Type: application/json' \
  -d '{"order_id":34183,"tracking_number":"1Z50W72W0115498880","carrier":"ups","line_item_ids":[4711],"label_cost":18.40,"box_weight":11,"box_dims":"10x10x10","label_source":"Partner A"}'
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
      "line_item_ids": [4711], "label_cost": "18.40", "box_weight": "11.00", "box_dims": "10x10x10",
      "label_source": "Partner A", "shared_box": false,
      "ship_date": "2026-10-10 09:15:02", "tracking_url": "https://www.ups.com/track?tracknum=1Z50W72W0115498880" }
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
| 400 | `fst_invalid_label_cost` | `label_cost` negative, not a number, or a currency sign with no amount |
| 400 | `fst_invalid_box_weight` | `box_weight` not a number above 0 |
| 400 | `fst_invalid_box_dims` | `box_dims` neither three sizes above 0 nor a standard box name |
| 400 | `fst_invalid_materials` | `materials` is not an object of lines and amounts, a quantity is negative / not a number / has more than two decimals, or `foam_cost` / `paper_cost` / `unit_cost` is not an amount of 0 or more |
| 400 | `fst_unknown_supply` | A materials line names a supply that is not on the Supplies list |
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
  "source_orders": [],
  "ship_mode": { "mode": "label", "with_order_id": null, "date": null, "with_order_number": null, "materials": null, "materials_total": null },
  "weather_action": "heat_pack",
  "fish_count": 1,
  "default_materials": {
    "boxes": [
      { "name": "Small", "dims": "10x10x10",
        "materials": { "lines": [ { "supply_id": 6, "name": "Small box", "role": "box", "unit": "each", "qty": "1.00", "unit_cost": "1.2500", "cost": "1.25" },
                                  { "supply_id": 3, "name": "Fish bag", "role": "fish_bag", "unit": "each", "qty": "1.00", "unit_cost": "0.1100", "cost": "0.11" },
                                  { "supply_id": 1, "name": "Heat pack", "role": "heat_pack", "unit": "each", "qty": "1.00", "unit_cost": "0.6000", "cost": "0.60" } ],
                       "foam_cost": "1.80", "paper_cost": "0.40", "total": "4.16",
                       "split": { "boxes": "1.25", "foam": "1.80", "paper": "0.40", "bags": "0.11", "packs": "0.60", "other": "0.00" } } }
    ],
    "custom": { "lines": [ "…the bag and the heat pack…" ], "foam_cost": "0.00", "paper_cost": "0.00", "total": "0.71", "split": { "…": "…" } }
  }
}
```

`ship_mode` is how an order with no label of its own went out (`label` = nothing recorded, `local`, `with_order`; a local pickup carries its `materials`). `weather_action` is the weather action seen for the order (`""` when there is none), `fish_count` the fish the next box is taken to hold — the quantities of the items not in a box yet (all of them when none or all are) — and `default_materials` what each standard box, and a custom one, would start with for this order, so a script or a form can prefill.

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

`shipments[]` entries (here and in `source_orders[].shipments[]`) carry `label_cost`, `box_weight`, `box_dims`, `label_source` — the values of the **box**, `null` when not recorded — and `shared_box` (`true` when other orders have the same tracking number), plus the box's `materials` (`null` when none are recorded), `materials_total` and `total_cost`. `GET /fst/v1/shipment/{id}` returns the same keys on `shipment`.

### `POST /fst/v1/shipment/{id}/cost`

Record or correct what a box cost after the tracking was saved. `{id}` is any shipment of the box (from `shipment_ids` or `shipments[].id`); the change applies to the box — the tracking number — so for a shared box it does not matter which order's shipment is named.

Send any of `label_cost`, `box_weight`, `box_dims`, `label_source` (same formats as above). A field sent as `""` or `null` is cleared; a field that is not sent is left as it is.

`materials` may be sent too, alone or with the others: not sent, the box's materials are left alone; `""` or `null` removes them; inside it, `lines`, `foam_cost` and `paper_cost` are each left as recorded when absent. A line that was already on the box keeps the price it was recorded at; a new line takes the supply's current price. Nothing is defaulted here.

```bash
curl -s -X POST 'https://fishotel.com/wp-json/fst/v1/shipment/912/cost' \
  -u 'jeff:abcd efgh ijkl mnop qrst uvwx' \
  -H 'Content-Type: application/json' \
  -d '{"label_cost":"19.25","label_source":"Partner B"}'
```

```json
{
  "message": "Label cost saved.",
  "shipment_id": 912,
  "tracking_number": "1Z50W72W0115498880",
  "label_cost": "19.25",
  "box_weight": "11.00",
  "box_dims": "10x10x10",
  "label_source": "Partner B",
  "shared_box": false,
  "order_ids": [34183],
  "materials": null,
  "materials_total": null,
  "total_cost": "19.25"
}
```

Errors: `404 fst_shipment_not_found`, `400 fst_no_cost_fields` (neither a cost field nor `materials` sent), `400 fst_invalid_label_cost` / `fst_invalid_box_weight` / `fst_invalid_box_dims` / `fst_invalid_materials` / `fst_unknown_supply`.

### `POST /fst/v1/order/{id}/ship-mode`

For an order with no label of its own. Body: `mode` (`local`, `with_order` or `label`), `with_order_id` (the order whose box it went in, for `with_order`), an optional `date` (`Y-m-d`, the day it went out; default now) and, for a local pickup, optional `materials` (same shape as above; not sent = left alone, `null` = removed).

```bash
# A local pickup that used two bags
curl -s -X POST 'https://fishotel.com/wp-json/fst/v1/order/34190/ship-mode' \
  -u 'jeff:abcd efgh ijkl mnop qrst uvwx' \
  -H 'Content-Type: application/json' \
  -d '{"mode":"local","materials":{"lines":[{"supply":"Fish bag","qty":2}]}}'

# The fish went in the box of order 34183
curl -s -X POST 'https://fishotel.com/wp-json/fst/v1/order/34191/ship-mode' \
  -u 'jeff:abcd efgh ijkl mnop qrst uvwx' \
  -H 'Content-Type: application/json' \
  -d '{"mode":"with_order","with_order_id":34183}'
```

```json
{
  "message": "Marked as gone in the box of order #34183.",
  "order_id": 34191,
  "mode": "with_order",
  "with_order_id": 34183,
  "date": "2026-11-12 09:15:02",
  "with_order_number": "34183",
  "materials": null,
  "materials_total": null
}
```

`{"mode":"label"}` clears it (back to "no shipment yet"). Errors: `404 fst_order_not_found` (the order, or the order named in `with_order_id`), `409 fst_order_has_shipments` (the order has tracking of its own), `400 fst_invalid_ship_mode` (unknown mode, no `with_order_id`, its own box, an order that is itself in another box or is a local pickup, or an order that carries others), `400 fst_invalid_materials` / `fst_unknown_supply`.

### `GET /fst/v1/shipping-options`

The standard boxes, label sources and supplies saved under **Settings > Boxes & label costs**, so a script sends values the reports can group. Any list may be empty.

```bash
curl -s 'https://fishotel.com/wp-json/fst/v1/shipping-options' \
  -u 'jeff:abcd efgh ijkl mnop qrst uvwx'
```

```json
{
  "standard_boxes": [
    { "name": "Small", "length": "10", "width": "10", "height": "10", "foam_cost": "1.80", "paper_cost": "0.40", "paper_feet": "3", "ice_packs": "2", "dims": "10x10x10", "supply_id": 6 },
    { "name": "Large", "length": "16", "width": "16", "height": "16", "foam_cost": "0.00", "paper_cost": "0.00", "paper_feet": "", "ice_packs": "0", "dims": "16x16x16", "supply_id": null }
  ],
  "label_sources": ["Partner A", "Partner B"],
  "supplies": [
    { "id": 1, "name": "Heat pack", "unit": "each", "counted": true, "reorder_level": "10.00", "role": "heat_pack", "box_name": "",
      "price_each": "0.6000", "on_hand": "34.00", "recount_needed": false, "low": false },
    { "id": 6, "name": "Small box", "unit": "each", "counted": true, "reorder_level": null, "role": "box", "box_name": "Small",
      "price_each": "1.2500", "on_hand": "24.00", "recount_needed": false, "low": false }
  ],
  "foam_waste_percent": "15",
  "ship_modes": ["label", "local", "with_order"]
}
```

Send a box as its `dims` or its `name`, and a source as its name. Free text is still accepted for both a custom size and a source that is not on the list. A box's defaults are its `foam_cost`, `paper_cost`, `ice_packs` and `supply_id` (the supply that is "the box" for it, or `null`). A supply's `role` is `heat_pack`, `ice_pack`, `fish_bag`, `foam`, `paper`, `box` or `""`; `price_each` is `null` until a purchase is logged, and `on_hand` is `null` for a supply that is not counted down (it is never below `"0.00"` — `recount_needed` says when the books are).

### For the label skill: POST the tracking here instead of driving the admin screen

The Finiship label skill used to save the tracking number by filling the order-edit meta box in a browser, which broke every time the markup changed. Use the API instead — it is the exact same service the meta box calls, so fan-out, coverage, dedupe, EasyPost routing, the first poll, order notes and the Processing → Shipped transition all happen.

1. `GET /fst/v1/order/{ORDER}/shipments` — read `order.is_fulfillment`, `line_items[]` and, for a fulfillment, `source_orders[]`.
2. `POST /fst/v1/shipment` with `order_id`, `tracking_number`, `carrier: "ups"` and:
   - regular order, one box with everything → `line_item_ids` = the ids of every item with `"covered": false`;
   - multi-box → only the ids going in *this* box;
   - fulfillment order → `line_item_ids` = the `"covered": false` ids from **every** `source_orders[].line_items[]` that are actually in the box (the union across sources). Never the fulfillment's own `line_items[]` ids. Omit `line_item_ids` only when the box holds every item of every source — a source whose items are only partly in the box would otherwise be flipped to Shipped and emailed while the rest is still in QT.
   - what the box cost: `label_cost` (the price paid for the label), `box_dims`, `box_weight` and `label_source` (which partner the label was bought through — whoever offered the best rate for this box). `GET /fst/v1/shipping-options` lists the box and source names to use. If the tracking was already saved (`409`), send the cost with `POST /fst/v1/shipment/{id}/cost` instead.
   - what went into the box: leave `materials` out when the box is a standard box packed the usual way — the box, the bags, the heat pack or ice packs and the foam and paper amounts are recorded by themselves. Send `materials` only when it differs (start from `default_materials` of step 1 and change the quantities).
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
│   ├── class-fst-label-cost.php   # Label costs: validation, CSV import, report maths
│   ├── class-fst-supplies.php     # Supplies: list, purchases, stock, the materials of a box
│   ├── class-fst-ship-mode.php    # Local pickup / went in another order's box (order meta)
│   ├── class-fst-tracker.php      # Polling engine
│   ├── class-fst-updater.php      # GitHub auto-updater
│   └── carriers/
│       ├── class-fst-carrier.php      # Abstract carrier base
│       ├── class-fst-carrier-ups.php  # UPS API integration
│       └── class-fst-carrier-usps.php # USPS API integration
├── assets/
│   └── css/frontend.css       # Customer-facing styles
└── tests/
    ├── bootstrap.php               # Stubs and an in-memory database for the tests
    ├── add-tracking-args-test.php  # The add-tracking argument normaliser
    ├── label-cost-args-test.php    # Label cost / weight / box size / source validation
    ├── label-cost-box-test.php     # One box, one cost across fan-out rows
    ├── label-cost-import-test.php  # The label cost CSV import
    ├── label-cost-report-test.php  # The label cost report maths
    ├── supplies-stock-test.php     # Price each, purchases, stock on hand, recounts, a removed box
    ├── supplies-defaults-test.php  # What a box starts with, snapshots, suggestions, validation
    ├── supplies-report-test.php    # Label + materials split, free shipping, local pickups, supplies report
    └── ship-mode-test.php          # Local pickup / another order's box: rules and shared-cost maths
```

### Tests

The tests are plain PHP and need neither WordPress nor a database:

```bash
for t in tests/*-test.php; do php "$t" || break; done
```

| Test | Covers |
|------|--------|
| `add-tracking-args-test.php` | `FST_Order::normalize_add_tracking_args()` |
| `label-cost-args-test.php` | Every money, weight and box-size spelling, standard box names, empty vs absent on an update |
| `label-cost-box-test.php` | One box, one cost: the real fan-out and delete paths against an in-memory database |
| `label-cost-import-test.php` | CSV reading (BOM, CRLF, quoted fields), header detection, row validation, the import itself |
| `label-cost-report-test.php` | Report maths: shared boxes, orders in several boxes, a missing cost never counted as 0 |
| `supplies-stock-test.php` | The price of one from the latest purchase, the purchase log, on hand across purchases / usage / a recount / a removed box, never below zero, the reorder notice |
| `supplies-defaults-test.php` | The supplies list, the defaults of a box (standard, custom, fish count, each weather action, no weather data), snapshots unaffected by later prices or settings, the suggested foam and paper cost, input validation |
| `supplies-report-test.php` | The label + materials split adding up to the totals (overall, by size, by source), boxes without a label cost, the free shipping and local pickup lines, the supplies report and the foam / paper ballparks |
| `ship-mode-test.php` | The rules of a local pickup and of an order that went in another order's box, the service (order meta only), and that box's shared-cost maths |

## License

This software is proprietary. Copyright (C) 2024-2026 FisHotel. All rights reserved. See the [LICENSE](LICENSE) file for details.
