=== CX Sidrena ===
Tags: woocommerce, croatia, sidrena cijena, bazna cijena, consumer protection
Requires at least: 6.2
Tested up to: 7.1
Requires PHP: 7.4
Stable tag: 0.1.0
License: GPLv2 or later
License URI: https://www.gnu.org/licenses/gpl-2.0.html

Croatian bazna (sidrena) cijena, the lowest price in the 30 days before a sale, and a public pricelist. Based on MJDD Sidrena i bazna cijena 1.26.0.

== Description ==

With WooCommerce active, CX Sidrena stores each product and variation regular price in its own field and shows it next to the current price. The statutory name from 17 November 2026 is bazna cijena. Sidrena cijena is the same amount. The default label is Bazna cijena, and the prefix stays editable. The first tab, **Dodatne stavke**, is the same row repeater for services and made-to-order items that are not Woo products. Those rows are appended to the end of the public XML/CSV. Without WooCommerce, that repeater is the whole pricelist (**Proizvodi**) plus a public HTML page at `/sidrena-cjenik`.

The prefix is text only; the date is added automatically in one of two formats:

`Bazna cijena (10.09.2026.) : 19,99 €`
`Bazna cijena na 10.09.2026. : 19,99 €`

Hovering the row shows a tooltip with an explanation (can be turned off). The prefix and tooltip can be translated with WPML, Polylang, TranslatePress, or Loco Translate.

The line under the product price has the CSS class `sidrena-cijena` so you can style it in the theme or Customizer (Appearance → Customize → Additional CSS). The default size is 14px (12px on mobile), without bold.

= Features =

* Works with WooCommerce, or standalone on a plain WordPress site
* Activation does not fill empty sidrena prices. Use the fill buttons or WP-CLI when you want that copy
* Display follows WooCommerce tax settings (same VAT treatment as the regular price)
* Filter `sidrena_cijena_price_to_display` when MPC is calculated outside WooCommerce Tax
* Field below the sale price on simple products and on every variation
* WooCommerce → Sidrena cijena tabs: Dodatne stavke, Settings, Import/Export, Public feed (top-level menu when Woo is not active; the first tab is then Proizvodi)
* With Woo: **Dodatne stavke** repeater for services and made-to-order items. Each row has a type (proizvod or usluga), its own sidrena date, and, with several outlets, a webshop or poslovnica. Shortcode `[sidrena-cijena id="d1"]`
* Without Woo: product repeater (name, SKU, URL, regular, sale, sidrena, Dostupno/Nedostupno) feeding the same export; each row has `[sidrena-cijena id="x"]` for pages
* Public HTML pricelist at `/sidrena-cjenik` (always without Woo; on a shop only after **HTML stranica /sidrena-cjenik** is turned on)
* Prefix without a date; storefront format: `Prefix (date)` or `Prefix date` (the word “na” belongs in the prefix). An empty prefix with `Prefix date` prints only the date and the amount
* Default reference date plus an optional per-product date (FMCG 02.05.2025. vs other goods 10.09.2026.)
* Tooltip on hover of the whole row (switch, on by default)
* Prefix and tooltip translations in WPML, Polylang, TranslatePress, or Loco Translate
* Export of the client pricelist (CSV or XML, same columns as the public feed) and of products missing a sidrena price
* Bulk import CSV/XML by SKU or catalog/product ID, with price column mapping (creates catalog rows when Woo is off)
* Public price list at `/sidrena-cijena.xml` or `/sidrena-cijena.csv` (choose format in settings; manual or daily after 00:05)
* Public feed includes `vrsta`, `sidrena_datum`, `dostupno`/`nedostupno`, barcode from GTIN, UPC, EAN or ISBN, and brand (`marka`) from a chosen taxonomy or a manual field. Each outlet keeps its own availability
* 30-day archive at `/arhiva-sidrene-cijene` (`prodavaonica_Ilica 150 Zagreb_P-01_104_01.10.2026_07:45.xml` or `.csv`)
* Optional multiple outlets: `/sidrena-cijena.xml` stays the webshop file. Each poslovnica has its own feed (`/sidrena-cijena-001.xml`), HTML page (`/sidrena-cjenik/001`), and 30-file archive. `/sidrena-cjenik` is the webshop table and includes the webshop download
* Shortcode `[sidrena-cjenik]` prints the searchable pricelist table (name, SKU, barcode, prices, availability) on any page. `oznaka="001"` limits it to one outlet. `[sidrena-arhiva oznaka="001"]` does the same for the archive list
* Optional unit of measure (CJM). When the setting is on, each Woo product and each manual row (Proizvodi / Dodatne stavke) can append a unit to the prefix, e.g. "za kg/l"
* Public feed publishes one retail price. While a sale price is active the sale-form column is `akcija`, unless that product or manual row sets another name
* Automatic display below the price (can be turned off; the shortcode remains)
* Shortcode `[sidrena-cijena]` for product loops and custom templates. `prikaz="redovna"`, `prikaz="akcijska"`, or `prikaz="sidrena"` prints only that amount
* Universal hook: `do_action( 'sidrena_cijena' );` or `sidrena_cijena();`
* WooCommerce CSV product import / export columns
* HPOS compatibility
* Lowest price in the 30 days before a sale (WooCommerce): every change of the price a customer pays is recorded per product and variation; when a sale starts, the lowest price of the 30 days before it is fixed for the whole sale and shown as the struck-through price, with the line *Najniža cijena u 30 dana prije sniženja*
* A new variation added in the admin gets its bazna cijena too
* Tools → Bazna cijena: provjera (and `wp cx-sidrena check`): products without a bazna cijena, products whose own date is later than the reference day, when the public pricelist was last written, and how the plugin is updated on this site
* Moves a shop over from MJDD Sidrena i bazna cijena (with or without the Bazna cijena – dodatak add-on) without entering any price again
* Updates only from the published releases of github.com/cx-core/cx-sidrena

CX Sidrena is MJDD Sidrena i bazna cijena 1.26.0 by Jure Zadravec / MJDD (https://mjdd.hr, GPL-2.0-or-later) under a new name, with the Bazna cijena – dodatak add-on 1.1.1 built in. Development of CX Sidrena: Hrvoje Puzin, https://github.com/cx-core/cx-sidrena

= Responsibility =

CX Sidrena helps merchants display prices and publish price lists as required by the Croatian Consumer Protection Act (Zakon o zaštiti potrošača, Official Gazette NN 19/22, 59/23, 59/26) and its implementing regulations. It is not legal advice.

The merchant remains solely responsible for the accuracy of all prices it displays, including the bazna cijena and the lowest price in the 30 days before a sale, and for its shop's compliance with the regulations in force. Default settings reflect the regulations, and where final texts were not yet published, the draft regulations, known on the release date. Regulations may change; check them before relying on the defaults.

This program is free software, distributed under the GNU General Public License v2 or later, WITHOUT ANY WARRANTY; without even the implied warranty of MERCHANTABILITY or FITNESS FOR A PARTICULAR PURPOSE.

== Installation ==

1. Download `cx-sidrena.zip` from the latest release at https://github.com/cx-core/cx-sidrena/releases and upload it in Plugins → Add New → Upload Plugin (or copy the `cx-sidrena` folder to `wp-content/plugins/`).
2. Activate the plugin. On a shop that runs MJDD Sidrena i bazna cijena (and the Bazna cijena – dodatak add-on), activation moves it over: see **Moving from MJDD Sidrena i bazna cijena** below.

**With WooCommerce**

3. WooCommerce → Sidrena cijena → **Dodatne stavke** (first tab): optional rows for services and made-to-order items that are not Woo products. They are added at the end of the public XML/CSV. Copy `[sidrena-cijena id="d1"]` from a row onto a page.
4. **Settings**: prefix, date format, default date, tooltip.
5. Edit the sidrena price and date on a Woo product if needed (below the sale price).
6. **Import/Export**: export the price list or bulk-import CSV/XML by SKU.
7. **Public feed**: choose XML or CSV, generate `/sidrena-cijena.xml` or `/sidrena-cijena.csv`, or leave daily regeneration after 00:05. Turn on **HTML stranica /sidrena-cjenik** if you also want the full HTML page. The archive is at `/arhiva-sidrene-cijene`.
8. **Several outlets**: Settings → **Više poslovnica?**. After saving, the **Poslovnice** tab appears. Each outlet gets `/sidrena-cijena-001.xml` (its object code) and its own 30-file archive. Dodatne stavke are appended to every outlet file.
9. **Table on a page**: paste `[sidrena-cjenik]` (or `[sidrena-cjenik oznaka="001"]` for one outlet). The shortcode is also shown on the Public feed tab, with a copy button.

**Without WooCommerce**

3. Sidrena cijena → **Proizvodi**: add rows (name, SKU, URL, regular price, sale price, sidrena price, Dostupno/Nedostupno) or import CSV/XML. Copy `[sidrena-cijena id="x"]` from a row onto any page so prices stay in admin. Paste `[sidrena-cjenik]` where you want the full searchable table.
4. **Settings**: prefix, date format, default date, tooltip. Turn on **Više poslovnica?** if you have more than one sales outlet.
5. Public HTML pricelist: `/sidrena-cjenik`. XML/CSV feed and 30-day archive work the same as with Woo.

== Frequently Asked Questions ==

= WP-CLI =

The admin **Fill empty fields** button processes 40 products per AJAX request. For a full catalog:

`wp cx-sidrena status`
`wp cx-sidrena fill --dry-run`
`wp cx-sidrena fill`
`wp cx-sidrena fill --today`

Other commands:

`wp cx-sidrena check` - the same check as Tools → Bazna cijena: provjera; exit code 1 when something is not in order
`wp cx-sidrena n30 on|off|status` - show or hide the lowest price in 30 days (the price history is recorded either way)
`wp cx-sidrena purge` - delete all CX Sidrena data and deactivate the plugin (asks for confirmation; see **Removing CX Sidrena**)

Default fill is a SQL copy of `_regular_price` into empty `_sidrena_cijena` (does not overwrite values already stored). Variable/grouped parents are skipped; variations are filled. `--today` also writes today's date as the per-product reference date when that field is empty (products first put on sale after 10.09.2026.).

`wp cx-sidrena fill --safe` uses WooCommerce product objects (same as the admin button, slower). Fallback: `wp eval-file wp-content/plugins/cx-sidrena/bin/fill.php`

= Tax (VAT) =

The sidrena price is stored in the same form as the WooCommerce regular price. On the storefront the plugin uses `wc_get_price_to_display()`, so the display follows WooCommerce → Settings → Tax (entered including or excluding tax, shop display with or without VAT). You do not need to re-enter prices after an update.

If the shop shows MPC with a custom VAT calculation (not WooCommerce Tax), hook `sidrena_cijena_price_to_display` so the sidrena amount matches the regular price. See **Hook and shortcode**.

= Settings =

Sidrena cijena settings (WooCommerce submenu, or a top-level menu without Woo).

The admin and storefront text is Croatian when the site language is Croatian (or when WordPress has no other match). English, German, and Slovenian are included for en_US, en_GB, de_DE, and sl_SI. A saved prefix and tooltip stay as you typed them.

* **Automatic sidrena price display** - Woo only; below the current price; when off, only `[sidrena-cijena]` / `[sidrena-cijena id="x"]` remain
* **Prefix** - words only, e.g. `Bazna cijena` (do not type the date here). `Sidrena cijena` is accepted and is not rewritten. If the saved prefix is only `Cijena`, an admin notice offers the statutory label.
* **Date format** - `Prefix (date)` or `Prefix date` (e.g. put `Sidrena cijena na` in the prefix). Leave the prefix empty with `Prefix date` to print only the date and the amount, with no colon
* **Default reference date** - e.g. `10.09.2026.` (used for every product without its own date)
* **Per-product reference date** - Woo only; leave empty for the default; for FMCG enter `02.05.2025.`
* **Tooltip** - switch and text (translate in WPML / Polylang / Loco)
* **Marka** - taxonomy dropdown (Woo) plus a manual brand. The feed uses the product's term, then the manual brand. Each row on Proizvodi / Dodatne stavke has its own Marka field.
* **Cijena za jedinicu mjere (CJM)** - off by default. When on, Woo products and every manual row get a CJM switch plus a unit phrase (e.g. `za kg/l`). The phrase is added only to that row's sidrena label. The public feed uses separate fields: `jedinica_mjere` and `cijena_jedinice_mjere`, entered on the product or the manual row.
* **Više poslovnica?** - off by default (one feed for the whole site). When on, the **Poslovnice** tab stores, for each outlet, the legal filename fields (store type, address, object code, storage sequence) and the products that belong to it. An outlet with no products does not publish a file. The tab appears after you save the switch.
* **Dodatne stavke** (Woo, first tab) - same repeater for services and made-to-order items. Required: name, regular price, sidrena price. Each row has a type (proizvod or usluga), its own sidrena date (empty keeps the shop default), and a Dostupno/Nedostupno dropdown. With several outlets, choose Webshop or a poslovnica. A service is written only into that feed. Shortcode `[sidrena-cijena id="d1"]`.
* **Products** tab (without Woo) - the same repeater for the whole public pricelist, including the availability dropdown. Saved as one JSON field to avoid `max_input_vars` limits.
* **HTML stranica /sidrena-cjenik** - on the Public feed tab, Woo only, off by default. When on, `/sidrena-cjenik` publishes the full HTML pricelist. The shortcode `[sidrena-cjenik]` works either way.
* At the bottom of the Woo settings tab: **Fill empty fields from the regular price** - empty fields only; existing sidrena values are left alone. The date stays the shop default (e.g. 10.09.2026.). **Fill empty sidrena prices with today's date** - same fill, plus today's date on products that have no date yet (new products after the reference day). Activation does not fill prices. The buttons walk the catalog in short steps, including each variation, show a progress bar, and resume if a request is cut off. An admin notice points at these two buttons until you run one or dismiss it. For large catalogs use WP-CLI: `wp cx-sidrena fill` or `wp cx-sidrena fill --today` (see **WP-CLI**).
* There is no button that deletes all data. Use `wp cx-sidrena purge` (see **Removing CX Sidrena**).

= Import / export =

**Import/Export** tab:

* **Bulk import** - CSV or XML. Rows match first by SKU, then by ID. With Woo: a matching Woo product is updated (including stock on the webshop). If neither SKU nor ID hits a product, or the row is vrsta usluga, it is saved as a manual row (Dodatne stavke), not as a new Woo product. The template has one product example and one service example, including type, date, and outlet. Regular, sale, and sidrena prices are mapped separately; the default is **Do not import**. Without Woo: rows with a matching SKU or ID in that catalog are updated, other rows are appended (name, regular, and sidrena are required for new rows). Empty cells are skipped. The progress bar uses AJAX batches.
* **Export price list** - the client cjenik, same CSV/XML columns as the public feed: `id`, `vrsta`, `naziv`, `sku`, `marka`, `url`, `jedinica_mjere`, `cijena_jedinice_mjere`, `maloprodajna_cijena`, `naziv_posebnog_oblika`, `sidrena_cijena`, `sidrena_datum`, `barkod`, `dostupnost`. Published products only. Variations are separate rows. Dodatne stavke are at the end. A file that contains only services leaves out SKU, brand, unit, barcode, and availability.
* **Export products without a sidrena price** - internal columns `naziv`, `sku`, `cijena_redovna`, `cijena_akcijska`, `sidrena_cijena`, including unpublished products, only where sidrena is empty
* Sidrena price, date, and CJM remain columns in WooCommerce **Products → Import/Export** (for the catalog, not for the price list itself)

After importing sidrena prices, regenerate the public feed if you use it.

= Public feed =

**Public feed** tab. Price list of published products and variations (or the standalone catalog): `https://shop.example/sidrena-cijena.xml` or `.csv` (format is a setting; default XML).

Fields: `id`, `vrsta`, `naziv`, `sku`, `marka`, `url`, `jedinica_mjere`, `cijena_jedinice_mjere`, `maloprodajna_cijena`, `naziv_posebnog_oblika`, `sidrena_cijena`, `sidrena_datum`, `barkod`, `dostupnost`. `vrsta` is `proizvod` or `usluga`. `sidrena_datum` is that row's date, or the shop default when the row date is empty. `naziv` for a variation is the parent title plus every attribute, including when WooCommerce stores only the parent name. `marka` comes from the taxonomy chosen in settings, then from the manual brand in settings. A manual row uses its own Marka field first. `maloprodajna_cijena` is the sale price while that sale is active, otherwise the regular price, using the same tax display as the shop. `naziv_posebnog_oblika` is `akcija` in that case, or the name entered on the product or manual row. It is empty when no sale price is active. `jedinica_mjere` and `cijena_jedinice_mjere` stay empty until entered. Barcode is read from WooCommerce GTIN/UPC/EAN/ISBN (`_global_unique_id`). On a shop, the webshop file takes availability from stock status. Each outlet file uses the availability stored for that poslovnica, not the webshop stock. Rows from **Dodatne stavke** are appended after the shop products (`id` prefixed with `d`, barcode empty, availability from that row's dropdown) and only in the feed they belong to. Without WooCommerce, every row has a Dostupno/Nedostupno dropdown (default Dostupno). A file that contains only services leaves out SKU, brand, unit, barcode, and availability, and the HTML table does not draw those empty columns.

Generated with the "Generate public feed" button, daily after 00:05 (on by default; WordPress timezone, so the file is ready before 08:00), and about a minute after a WooCommerce price, sale, or stock change. A burst of saves waits until a minute after the last one, then writes the file once. The daily run stays. A visit to a missing file does not rebuild the catalog in that request: the response is 503 and one background rebuild is queued, with a 15-minute cooldown. The file is stored in `wp-content/uploads/cx-sidrena/`. The HTML page and `[sidrena-cjenik]` serve the table written in that same run. Until the file exists they show a short preparing message and queue one rebuild; they do not walk the catalog.

The daily cron also stores a legally named file in the archive, following the 18 September 2026 ministry example `prodavaonica_Ilica 150 Zagreb_P-01_104_01.10.2026_07:45.xml` (date DD.MM.YYYY, time HH:MM; spaces and hyphens stay; 30 files maximum; the oldest is deleted). Regenerating on the same calendar day replaces that day's file and does not advance the storage number. On a Windows server the time is written `07-45` because a colon cannot be stored in a filename. Older `YYYYMMDD_HHMM` and `sidrena-cijena_DD-MM-YYYY` files stay listed. HTML listing: `https://shop.example/arhiva-sidrene-cijene/`. Set store type, address and object code on the Public feed tab.

**Several outlets.** Turn on **Više poslovnica?** in Settings. Those filename fields move to the **Poslovnice** tab, one set per outlet, plus the products assigned to it. `/sidrena-cijena.xml` stays the webshop file: Woo products plus manual rows assigned to the webshop. Each outlet with products is published at `https://shop.example/sidrena-cijena-001.xml` (or `.csv`; `001` is that outlet's object code), with its own HTML table and an archive of at most 30 files. `/sidrena-cjenik` is the webshop table and its download button is the webshop feed. Each outlet HTML page is `https://shop.example/sidrena-cjenik/001/`, with that outlet's table and download. Availability on an outlet file is the flag stored for that poslovnica. Format (XML or CSV) and the daily run after 00:05 still apply to every outlet. With the switch off, the single `/sidrena-cijena.xml` feed is unchanged.

**Table shortcode.** `[sidrena-cjenik]` prints only the searchable table (plugin design) on any page or in an Oxygen shortcode element. `[sidrena-cjenik oznaka="001"]` limits the rows to one outlet. Columns follow the public feed: name, SKU, brand, barcode, unit, unit price, retail price, sale-form name, sidrena price, availability. The name links to the product URL when one is set. The Public feed tab lists these shortcodes with a copy button.

**Archive shortcode.** `[sidrena-arhiva]` prints only the archived filenames and dates, in the same light-blue list. `[sidrena-arhiva oznaka="001"]` limits the list to one object code. The public archive page groups files by outlet when several are on. The Public feed tab shows these shortcodes with a copy button.

Without WooCommerce, the same table is also a page at `https://shop.example/sidrena-cjenik/`. On a WooCommerce shop that page is off until you turn on **HTML stranica /sidrena-cjenik** on the Public feed tab.

= CJM =

In settings, enable **Cijena za jedinicu mjere (CJM)**. On each Woo product and variation, and on each row of **Proizvodi** or **Dodatne stavke**, a **CJM** switch appears. When that row's switch is on, you enter a unit phrase (e.g. `za kg/l`).

`Bazna cijena` + `za kg/l` + parentheses format = `Bazna cijena za kg/l (10.09.2026.)`
`Bazna cijena` + `za kg/l` + “na” format = `Bazna cijena za kg/l na 10.09.2026.`

= Compatibility =

Works alongside the Croatian 30-day Omnibus plugins **WooCommerce Lowest Price** by Igor Kovačić (1.x) and Media X / Borko Livić (2.x). Sidrena cijena is the fixed reference price; those plugins show the lowest price in the last 30 days while a product is on sale. When both are active the storefront order is: current price → sidrena cijena → 30-day lowest price, without a duplicate sidrena line. WPML and Polylang store each translation as its own product and do not copy the sidrena field, so the storefront uses the default-language sidrena when the translated product has none.

The core filter is `woocommerce_get_price_html` (priority 9999). It covers Storefront, Astra, GeneratePress, OceanWP, Kadence, Blocksy, and most themes that call `$product->get_price_html()`.

Builders that often skip that filter have extra PHP hooks plus JS injection into their `.price` container:

* **Elementor / AnyWhere Elementor** - widget `woocommerce-product-price`
* **JetWooBuilder / ShopEngine** - `jet-single-price`, `shopengine-product-price`
* **WPBakery** - shortcode `[product_price]`, wrapper `.wpb_wrapper > p.price`
* **Oxygen** - Product Price element (`.oxy-product-price`)
* **Divi 4 / Divi 5** - module `et_pb_wc_price` / block `divi/wc-product-price`
* **Bricks** - element `product-price`
* **Beaver Builder** - WooCommerce price module
* **Breakdance / Brizy** - CSS selectors for price elements
* **Avada / Fusion** - Woo Price theme builder element
* **Woodmart / Flatsome** - `.wd-single-price`, `.product-page-price`
* **WooCommerce Blocks** - `woocommerce/product-price`

If a theme still does not show the line, put the shortcode or hook below the price in the template or loop.

= Hook and shortcode =

Output goes through `woocommerce_get_price_html` (product page, shop, archive, related products - anywhere the theme calls `get_price_html()`), Woo blocks, and popular page builders.

For custom product loops (Elementor, page builder, custom PHP) use:

`[sidrena-cijena id="123"]`
`[sidrena-cijena id="123" prikaz="sidrena"]`
`[sidrena-cjenik]`
`[sidrena-cjenik oznaka="001"]`
`[sidrena-arhiva]`
`[sidrena-arhiva oznaka="001"]`

`[sidrena-cjenik]` prints only the searchable pricelist table, using the plugin table design. Put it on any page or in an Oxygen shortcode element. With multiple outlets, `oznaka` limits the table to that poslovnica. Without it, the table lists every product.

`[sidrena-arhiva]` prints only the archive file list: each legal filename and its date. It does not add a title or a back link. `oznaka` keeps one object code. Older filenames that have no object code stay in the list only when `oznaka` is omitted.

Without WooCommerce, `[sidrena-cijena id="123"]` prints that catalog row's current price and sidrena line. On a shop, `[sidrena-cijena id="d1"]` does the same for a row on **Dodatne stavke**. Add `prikaz="redovna"`, `prikaz="akcijska"`, or `prikaz="sidrena"` to print only that amount, for example `120,00 €`. Without `prikaz`, the full line with the label and tooltip stays. An empty sale price prints nothing. A variable product with a price range prints `od` plus the amount. Paste it once and change prices in Sidrena cijena, not on the page.

In a PHP template:

`echo do_shortcode( '[sidrena-cijena]' );`
`do_action( 'sidrena_cijena' );`
`sidrena_cijena();`
`sidrena_cijena( $product );`

Filter the storefront amount after WooCommerce tax display. Arguments: `$amount` (float), `$product` (`WC_Product`), `$raw` (stored sidrena string, same basis as `_regular_price`). Does not change the value stored in the product field.

```
add_filter( 'sidrena_cijena_price_to_display', function( $amount, $product, $raw ) {
    return (float) $amount * 1.25;
}, 10, 3 );
```

= Kako prikazati sidrenu cijenu za dostavu? =

Dostavu postavite kao uslugu (kartica Dodatne stavke ili Proizvodi, vrsta Usluga) te koristite shortcode za programatski ispis, npr. `[sidrena-cijena id="d1" prikaz="sidrena"]`. Na trgovini s WooCommerceom ručni redak ide s prefiksom `d`. Integracija sidrenih cijena za dostave nije dio plugina zbog raznih dostupnih načina kalkulacije i prikaza istih.

= Custom CSS =

The line under the product price has the class `sidrena-cijena`. Use it for color, size, or alignment. The tooltip is on hover/focus of the whole row (no “i” icon). Default: 14px / 12px on mobile, `font-weight: 400`.

HTML structure:

`<span class="sidrena-cijena sidrena-cijena--has-tooltip">`
`  <span class="sidrena-cijena__text">…</span>`
`  <span class="sidrena-cijena__tooltip">…</span>`
`</span>`

Classes:

* `.sidrena-cijena` - the whole row under the product price (main class for styling)
* `.sidrena-cijena__prefix` - prefix text with the date (e.g. Bazna cijena (10.09.2026.))
* `.sidrena-cijena__sep` - colon between prefix and amount, or a space when the prefix is empty and the date format is plain
* `.sidrena-cijena__amount` - sidrena price amount
* `.sidrena-cijena__tooltip` - tooltip text (hover/focus on the whole row, no icon)
* `.sidrena-cijena--variable` - variable product while no variation is selected

Example:

`.sidrena-cijena { font-size: 14px; color: #555; }`
`.sidrena-cijena__amount { font-weight: 700; color: #111; }`

= Lowest price in 30 days =

On a product on sale, the struck-through price is the lowest price the product had in the 30 days before the sale started, with the line *Najniža cijena u 30 dana prije sniženja* below it. The history starts when the plugin is installed: a sale that was already running then uses the regular price. The line can be hidden with `wp cx-sidrena n30 off`; the history is recorded either way. It is shown by default on a new installation and on a shop that moved from MJDD together with the Bazna cijena – dodatak add-on, and hidden on a shop that moved from MJDD alone.

= Moving from MJDD Sidrena i bazna cijena =

Install CX Sidrena next to MJDD and activate it. It copies MJDD's settings and its public pricelist and archive files under its own name, switches MJDD and the Bazna cijena – dodatak add-on off, and shows what it did. The bazna prices, dates and price history on the products are the same fields and stay where they are, and the public addresses (`/sidrena-cijena.xml`, `/sidrena-cjenik`, `/arhiva-sidrene-cijene`) keep working. MJDD's own data is left as it was, so you can go back: deactivate CX Sidrena and activate MJDD (and the add-on). MJDD and the add-on cannot run next to CX Sidrena; if one of them is activated, CX Sidrena switches it off again and says so.

= Updates =

CX Sidrena updates itself from the published releases at https://github.com/cx-core/cx-sidrena, only with the release's own `cx-sidrena.zip`, never from wordpress.org. To update by hand instead, add `define( 'CX_SIDRENA_AUTO_UPDATE', false );` to `wp-config.php`. When automatic updates are switched off for the whole site (by the hosting, or `AUTOMATIC_UPDATER_DISABLED` in `wp-config.php`), nothing updates by itself; Tools → Bazna cijena: provjera says how the plugin is updated on this site. While CX Sidrena is active, MJDD and the add-on are not updated automatically.

= Removing CX Sidrena =

Deleting CX Sidrena in Plugins removes its settings, the public pricelist and its archive. `wp cx-sidrena purge` does the same and deactivates the plugin. Neither ever removes the bazna prices, dates or price history on products: they are your record of the prices you published, and MJDD Sidrena i bazna cijena reads the same fields.

== Screenshots ==

1. Sidrena cijena on a WooCommerce product: regular price, sale price, and the sidrena line with tooltip.

== Changelog ==

= 0.1.0 =
* CX Sidrena: MJDD Sidrena i bazna cijena 1.26.0 under a new name (folder, main file, classes, options, cron hooks and WP-CLI command), with everything a customer sees unchanged.
* The Bazna cijena – dodatak add-on 1.1.1 is built in: the lowest price in the 30 days before a sale, the check page, and the bazna cijena for a new variation added in the admin.
* The check lists a product only when its own date is later than the reference day (an earlier date, for example 02.05.2025., is correct).
* Activation next to MJDD (and the add-on) moves the shop over; MJDD's data is left as it was.
* The button that deleted all data is gone; `wp cx-sidrena purge` asks for confirmation and never removes product prices, dates or history, and neither does deleting the plugin.
* Updates only from the published releases on GitHub.

The versions below are those of MJDD Sidrena i bazna cijena, on which CX Sidrena is based.

= 1.26.0 =
* The interface is Croatian by default. English, German, and Slovenian catalogs ship in the plugin: en_US, en_GB, de_DE, and sl_SI.
* Croatian is also shipped as hr, so a Croatian site does not fall back to an empty translation.

= 1.25.0 =
* The default storefront label is Bazna cijena. A saved prefix of Sidrena cijena is left as typed. If the label is only Cijena, an admin notice offers the switch and can be dismissed.
* The default tooltip uses the statutory wording: the bazna cijena is the retail price on the reference day, not the sale price and not the lowest price in 30 days.
* The public XML/CSV is rebuilt about a minute after a product price, sale, or stock change, including trash and delete. The daily run after 00:05 stays.
* Plugin title includes bazna cijena. The slug is unchanged. Draft pravilnik fields (net quantity, a bazna_cijena column, exemptions, and publishing only when a price changes) wait for a version after 13 November 2026, once the text is in Narodne novine.

= 1.24.8 =
* Proizvodi and Dodatne stavke show 50 rows per page, so a large manual catalog stays usable.
* Bulk import matches by SKU first, then by ID. Map either column. A row that hits neither becomes a new manual item.
* On WPML and Polylang, a translated product uses the default-language sidrena when its own field is empty.

= 1.24.7 =
* `/sidrena-cjenik` keeps the webshop table and always offers the webshop XML/CSV download when several outlets are on. Each outlet has its own HTML page at `/sidrena-cjenik/{oznaka}` with that outlet's table and download.
* `[sidrena-arhiva oznaka="001"]` lists archive files for one object code. The public archive page groups files by outlet.
* An empty prefix with the plain date format prints only the date and the amount, with no colon.

= 1.24.6 =
* Services agreed on request can be listed under the priced rows. They print at the bottom of the HTML cjenik, with the criteria that form the price. They stay out of the XML, CSV, and archive.

= 1.24.5 =
* Archive links for the ministry filenames (spaces, time colon, commas in an address) open the file. Older `sidrena-cijena_DD-MM-YYYY` links stay as they were.
* The WordPress.org plugin banner is the MJDD price-tag artwork.

= 1.24.4 =
* Filling empty sidrena prices walks variable products one variation at a time, in short steps, with a progress bar. If a request is cut off, the next step continues from that variation. Existing sidrena prices are not overwritten.
* A variation row in the public XML, CSV, and HTML pricelist uses the parent title plus every attribute, including when WooCommerce stores only the parent name.
* The archive file list no longer depends on brace patterns in `glob()`, so hosts without that flag still list XML and CSV files.

= 1.24.3 =
* The Public feed tab places the address, HTML cjenik, archive, and the two shortcodes in two columns.
* Each outlet feed uses that outlet's own availability. Webshop stock is not copied onto other poslovnice.

= 1.24.2 =
* `[sidrena-cijena prikaz="redovna"]`, `prikaz="akcijska"`, or `prikaz="sidrena"` prints only that amount. Without `prikaz` the full line stays. An empty sale price prints nothing. A variable product with a range prints `od` plus the amount.

= 1.24.1 =
* A CSV/XML row sent to Webshop with an empty SKU, or with vrsta usluga, is saved on Dodatne stavke.
* The WordPress footer thank-you is hidden on the Sidrena cijena screen.

= 1.24.0 =
* Manual rows have a type (proizvod or usluga), their own sidrena date, and, when several outlets are on, a choice of webshop or poslovnica. An empty date keeps the shop default. The shortcode uses that row's date.
* Public XML/CSV adds `vrsta` and `sidrena_datum`. With several outlets, `/sidrena-cijena.xml` stays the webshop file. Each poslovnica keeps its own feed, HTML table, and 30-file archive. A service is written only into the feed it belongs to. A file that contains only services leaves out SKU, brand, unit, barcode, and availability.
* The import template includes one product example and one service example. A SKU that is not a Woo product becomes a manual row. Import can map type, date, and outlet.

= 1.23.0 =
* The public HTML pricelist and `[sidrena-cjenik]` serve the table saved with the feed. A missing file shows a preparing message and queues one rebuild instead of loading every product on the request.
* The Public feed tab shows the HTML cjenik address in the same light-blue card as the archive and the table shortcode, whether or not the page is turned on.
* `[sidrena-arhiva]` prints only the archive filenames and dates.
* Activation no longer copies regular prices into empty sidrena fields. An admin notice points at the two fill buttons until you run one or dismiss it.
* When daily generation is on and `DISABLE_WP_CRON` is not set, the Public feed tab warns that 00:05 runs on the next PHP request and how to call `wp-cron.php` from system cron. It also warns when the last successful file is older than 26 hours.

= 1.22.0 =
* CSV/XML import keeps valid UTF-8 and converts other files from ISO-8859-2 or Windows-1250, so Croatian letters such as š and ž stay intact.
* The plugin directory description stays within the 2,500-word limit. Setup notes (WP-CLI, tax, settings, the public feed, CJM, compatibility, the shortcode, and custom CSS) are in Frequently Asked Questions.

= 1.21.0 =
* Archive filenames follow the ministry example: `prodavaonica_Ilica 150 Zagreb_P-01_104_01.10.2026_07:45`. Spaces, hyphens, and typed case stay. Date is DD.MM.YYYY and time is HH:MM. Windows servers write the time as `07-45`.
* Public XML/CSV and the HTML pricelist include `marka`. Settings: pick a product taxonomy and/or type a fallback brand. Manual rows (Proizvodi / Dodatne stavke) have their own Marka field.

= 1.20.0 =
* Variable products: when WooCommerce leaves the variation price empty because the remaining in-stock variations share one price, the sidrena line is still shown with that variation's price.
* The variable sidrena range uses visible variations only. The storefront script waits until the variation price box exists before inserting the line.

= 1.19.0 =
* A missing public feed no longer rebuilds the whole catalog during the visit. The request returns 503 and queues one background rebuild, with a lock and a 15-minute cooldown.
* `[sidrena-cijena]` shows a price only for a published product the visitor can open. Drafts, private products, and password-protected products stay hidden. Catalog-hidden products are left out of the public XML, CSV, and HTML cjenik.
* Staged import files are stored outside the uploads directory and removed with the full data wipe. Error text from a failed wipe or feed write is logged, not shown to the visitor.

= 1.18.0 =
* Import/Export → Export price list downloads the client cjenik. CSV and XML use the same columns as the public feed, for published products only.
* Export of products missing a sidrena price stays the internal price list (name, SKU, regular, sale, sidrena).

= 1.17.0 =
* Public XML/CSV uses one retail price (`maloprodajna_cijena`) plus `naziv_posebnog_oblika`. An active sale price writes `akcija` unless the product or manual row sets another name.
* Public feed also has `jedinica_mjere` and `cijena_jedinice_mjere`. The CJM label suffix stays on the storefront line and is no longer a feed column.
* Sidrena and retail amounts in the public feed follow the shop tax display.

= 1.16.0 =
* Unit of measure (CJM) on each manual row of Proizvodi and Dodatne stavke, when that setting is on. The same switch stays on Woo products.
* The unit phrase is part of that row's sidrena label, the public feed field `mjerna_jedinica`, and a Mjerna jedinica column in the HTML table.

= 1.15.0 =
* HTML pricelist table (shortcode and `/sidrena-cjenik`) includes barcode and Dostupno/Nedostupno as well as the prices.
* Without WooCommerce, each catalog row has a Dostupno/Nedostupno dropdown. Import can map that column.
* WooCommerce shops can turn on the public page `/sidrena-cjenik` from the Public feed tab. It stays off until then.
* WooCommerce: first tab **Dodatne stavke** uses the same row repeater. Saved rows are appended to the end of the shop XML/CSV (and each outlet file). Shortcode `[sidrena-cijena id="d1"]`.

= 1.14.0 =
* Settings switch "Više poslovnica?". When on, the Poslovnice tab stores the legal filename fields per outlet and the products that belong to it.
* Each outlet publishes `/sidrena-cijena-{oznaka}.xml` (or `.csv`) and its own 30-file archive. Daily generation after 00:05 covers every outlet that has products.
* Shortcode `[sidrena-cjenik]` prints only the pricelist table (plugin design) on any page. Optional `oznaka="001"` limits the rows to one outlet.

= 1.13.0 =
* Standalone catalog: copy / trash / add-row icons, and a per-row `[sidrena-cijena id="x"]` shortcode for non-Woo pages.
* Public feed always includes `dostupno`/`nedostupno` from WooCommerce stock and barcode from GTIN, UPC, EAN or ISBN.

= 1.12.2 =
* Variable products: stop the MutationObserver loop that froze the product page after selecting a variation.
* Update the sidrena line when switching variations instead of keeping the first variation’s price.

= 1.12.1 =
* Variable products: do not strip the selected variation’s sidrena line. Dedupe stays inside the price node, not the whole `.summary`.

= 1.12.0 =
* Public archive filenames follow the HOK webinar template: `OBLIK_ADRESA_OZNAKA_POHRANA_YYYYMMDD_HHMM` (date format YYYYMMDD, time HHMM).
* Daily regeneration runs after 00:05 so the day's pricelist is published before the 08:00 deadline.
* Public feed settings: store type, address, object code, and storage sequence.

= 1.11.0 =
* Works without WooCommerce: product repeater, same XML/CSV feed, public HTML pricelist at `/sidrena-cjenik`.
* Polylang: `pll_translate_string()` is called with the language code (or `pll__()`), fixing a PHP 8 critical error.

= 1.10.1 =
* CSV exports neutralize spreadsheet formula prefixes (=, +, -, @, tab, CR) to prevent formula injection from product data.
* Direct file access guards on remaining PHP entry points (ABSPATH).

= 1.10.0 =
* Public feed format setting: XML or CSV (`/sidrena-cijena.xml` or `/sidrena-cijena.csv`). Archive files keep the type they were generated as.

= 1.9.9 =
* Directory identity: MJDD Sidrena Cijena (slug mjdd-sidrena-cijena). Product meta, settings, shortcode, and storefront CSS class are unchanged.
* Public archive CSS is registered and printed via WordPress style APIs.
* Uploads paths use wp_upload_dir() only.

= 1.9.8 =
* Settings button to fill empty sidrena prices with today's date (products added after 10.09.2026.). WP-CLI: `wp cx-sidrena fill --today`.

= 1.9.7 =
* Settings action to delete all plugin data and deactivate the plugin.

= 1.9.6 =
* Filter `sidrena_cijena_price_to_display` for shops that add VAT outside WooCommerce Tax.

= 1.9.5 =
* Compatible with WooCommerce Lowest Price by Igor Kovačić and Media X (Borko Livić): one sidrena line, placed above the 30-day lowest price.

= 1.9.4 =
* English readme for the WordPress.org Plugin Directory.
* CSV export no longer uses direct fclose() on php://output.

= 1.9.3 =
* Prefix and tooltip are translated per language (WPML, Polylang, TranslatePress, Loco).
* Tooltip switch, on by default.
* Automatic display switch; when off, only the shortcode remains.

= 1.9.2 =
* Admin REST/AJAX no longer triggers public XML generation or a permalink flush.
* Date format: Prefix (date) or Prefix date. The word “na” belongs in the prefix, not as a separate option.

= 1.9.1 =
* Prefix preview in settings updates immediately when the date format changes.

= 1.9.0 =
* Sidrena price is always shown below the price; the “only on sale” option was removed.
* Shortcode [sidrena-cijena] for product loops.

= 1.8.0 =
* 30-day public XML archive at /arhiva-sidrene-cijene (cron at 08:00).
* Sidrena price is no longer filled automatically when saving a product.

= 1.7.0 =
* Public XML: “Regenerate public XML daily at 08:00” option (on by default).

= 1.6.1 =
* Storefront format: Prefix (date) or Prefix na date.

= 1.6.0 =
* Bulk CSV/XML import by SKU, price mapping, and AJAX progress bar.

= 1.5.0 =
* Admin split into Settings, Import/Export, and Public XML tabs.

= 1.4.1 =
* Storefront sidrena price uses WooCommerce tax display. If prices are entered without VAT and the shop shows prices with VAT, sidrena no longer stays as a net amount.

= 1.4.0 =
* Prefix is text only (Sidrena cijena); the date is added from the default or the product date.

= 1.3.1 =
* Sidrena price: 14px on desktop, 12px on mobile.

= 1.3.0 =
* Public XML price list at /sidrena-cijena.xml, regeneration from WooCommerce → Sidrena cijena.

= 1.2.2 =
* Sidrena price is no longer bold (Elementor Product Price).

= 1.2.1 =
* Removed the “i” icon; the tooltip stays on hover of the whole row.

= 1.2.0 =
* Compatibility for Elementor, WPBakery, Oxygen, Divi, Bricks, Beaver, Avada, Woodmart, Flatsome, JetWooBuilder, and Woo blocks.

= 1.1.1 =
* Sidrena price also shows in the Elementor Product Price widget.

= 1.1.0 =
* Price list and audit export (CSV/XML) in settings.
* CJM switch and prefix suffix on products.
* Universal display hook next to WooCommerce blocks.

= 1.0.0 =
* First release.
