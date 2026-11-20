=== CX Sidrena ===
Tags: woocommerce, croatia, sidrena cijena, bazna cijena, consumer protection
Requires at least: 6.2
Tested up to: 7.1
Requires PHP: 7.4
Stable tag: 0.3.0
License: GPLv2 or later
License URI: https://www.gnu.org/licenses/gpl-2.0.html

Croatian bazna (sidrena) cijena, the lowest price in the 30 days before a sale, and a public pricelist. Based on MJDD Sidrena i bazna cijena 1.26.0.

== Description ==

CX Sidrena shows the bazna cijena next to a product's price and the lowest price in the 30 days before a sale, and publishes the shop's price list with its archive. It follows the Croatian Consumer Protection Act (Zakon o zaštiti potrošača, NN 19/22 and 59/26) and, where the implementing rules are still drafts from the public consultation (10333 for the bazna cijena, 10332 for the price list), those drafts. When the final texts are published, we adapt the plugin.

= What the customer sees =

* Under the price, the line `Bazna cijena na 10.9.2026.: 8,50 €`: on the product page, on product cards (shop, category, search, related products) and in the product blocks. It is shown also when it equals the price, and it is never struck. The label is a setting.
* On a sale: WooCommerce's price, the regular price struck and the sale price, then the line *Najniža cijena u 30 dana prije sniženja: 8,50 €*, then the bazna line. The lowest price is fixed when the sale starts and stays while it lasts. Every sale is shown as a sale; whether its price is below the lowest price in 30 days is the owner's call, and Status reminds the owner where it is not. On a shop that moved from MJDD without the add-on the line is off until the owner turns it on (Settings, or `wp cx-sidrena n30 on`).
* The sale badge shows a percentage worked out from the regular price, rounded down, or *Akcija*, or nothing (Settings).
* A variable product shows the range of its variations' bazna cijene until a size is chosen, then that size's line.
* Both lines take the colour and font of the shop's ordinary text, not the colour of the sale price.
* The cart, mini-cart and checkout blocks show one price per item: no struck regular price, no "Save", no bazna cijena and no lowest price.
* A small *Cjenik* link at the bottom of every page, once the first price list is published.

= What the shop owner gets =

WooCommerce → Bazna cijena has three screens:

* **Status (Stanje)**: whether today's price list is published (with **Update now** (Osvježi sada)), products that need attention (no bazna cijena, a date later than expected, and as a reminder a sale price not below the lowest price in 30 days), and fixes in place. **Use regular price** (Upotrijebi redovnu cijenu) and **Fix all** (Popravi sve) set a missing bazna cijena from the regular price only after a person confirms it by name.
* **Import (Uvoz)**: bazna cijene from a CSV file. You see a preview of every change first. Nothing is written until a person gives the shop owner's confirmation, with their name and the date; then it runs as one background job. It writes only the bazna cijena, its date, its source and the starting lowest price in 30 days, never the regular or sale price.
* **Settings (Postavke)**: Dates (Datumi; the bazna date for each product category), the label customers see with a preview, its colour, the discount badge, and the public price list. **Show advanced settings** (Prikaži napredne postavke) holds the options inherited from MJDD.

On a product and on each variation the bazna cijena is a read-only line with its date and where it came from. **Change** (Promijeni) asks for the amount, the date and a reason, and records the change. The product list has a Bazna cijena column and a filter for products that need attention.

A product created after installation gets its bazna cijena on its first save: the regular price, with the date by the rule below. A product that existed before is never filled by itself; Status lists it. A copy made with "Copy to a new draft" starts as a new product, without the original's bazna cijena and price history.

**Dates.** A bazna cijena without its own date takes the later of two dates: its category's date, or the day the product was added. A product's own date always wins. When two of its categories have different dates, the earlier one counts. A date the customer sees never moves by itself: when a product changes category, it keeps its old date as its own.

**Price history.** Every change of a product's regular price, sale price, sale dates, bazna cijena and lowest price in 30 days is recorded: when, by which route (admin, quick or bulk edit, REST, WooCommerce CSV, WP-CLI, import, Status) and by whom. Changes made directly in the database are not seen. `wp cx-sidrena history <id|sku>` shows the history of one product.

**WooCommerce's product CSV import** (Products → Import) reads our columns too: the bazna cijena and its date, CJM and its suffix, the name of a special form of sale, the unit of measure and the unit price. An empty cell changes nothing, also in a "Meta: …" column of any of our fields. `-` deletes CJM, its suffix, the unit or the unit price (for CJM, `no` does too). A unit price that is not a clear amount (`abc`, `1,2,3`) changes nothing. So a file exported from WooCommerce, with or without custom meta, imports back without changing any of our fields.

= Public price list =

* Addresses: `/sidrena-cijena.xml` or `/sidrena-cijena.csv` (one format, a setting; XML by default) for goods, and `/sidrena-cijena-usluge.xml` (or `.csv`) for services (a place without services answers 404 there: "U ovom cjeniku nema usluga."); a shop that sells only services has them at the first address. With outlets, each has `/sidrena-cijena-<label>.xml` and `/sidrena-cijena-usluge-<label>.xml`. Each address stays the same and answers with the current file itself, under its legal name; the address of the other format (e.g. `.csv` when the setting is XML) leads (a redirect) to the current file. Also the page `/sidrena-cjenik/` and the shortcode `[sidrena-cjenik]`.
* What is in it: everything any consumer can buy, at the price a visitor pays. It is always built as a visitor, whoever starts it, so prices for a role or for one customer stay out. Rows without a retail price are left out.
* Columns, in the order of the Pravilnik o cjeniku (NN 132/2026, art. 3) and nothing else: for goods `naziv`, `sifra`, `marka`, `neto_kolicina`, `jedinica_mjere`, `maloprodajna_cijena`, `poseban_oblik` (`DA`/`NE`), `cijena_jedinice_mjere`, `barkod`, `ean_barkod` (a code of exactly 8 or 13 digits), `bazna_cijena`; for services `naziv`, `maloprodajna_cijena`, `poseban_oblik`, `bazna_cijena`. `neto_kolicina`, `jedinica_mjere` and `cijena_jedinice_mjere` are filled for a product or size with a net quantity (product screen), otherwise empty. `poseban_oblik` is `DA` for every product and size on sale, `NE` otherwise. The page `/sidrena-cjenik/` has the same order, then the date of the bazna cijena and, when you turn it on, availability (Settings → Public price list → Availability; Postavke → Javni cjenik → Dostupnost).
* When it is built: about a minute after a change of a price, a sale or stock, once more every morning after 00:05 Croatian time, and with **Update now** (Osvježi sada) on Status. A file is always replaced whole, never half written.
* Archive: a price list with new content, and the first one of each day, is also stored under its legal name (Pravilnik o cjeniku, art. 6(3)): the shop's OIB, MBO or MIBPG, the kind of the place, its address and label, `ROBA` or `USLUGA`, the storage number and the time as `GGGGMMDD_SSMM`, e.g. `<OIB>_prodavaonica_Ilica 150 Zagreb_P-01_ROBA_104_20261001_0745.xml`. Enter the number in Settings → Public price list → **OIB, MBO or MIBPG** (Postavke → Javni cjenik); until then no new price list is published (what was already published stays as it is), and Status and Settings say so. Goods and services are in separate files with one storage number per build, and no published file is written over. The current price lists, and each replaced one for 15 days after a newer one replaced it (art. 3(6)), are public at `/arhiva-sidrene-cijene/` and through `[sidrena-arhiva]`. Older files are taken out of public reach and kept, compressed, for 4 years by default; Settings → Public price list → **Keep older price lists** (Postavke → Javni cjenik → Čuvanje starijih cjenika) sets anything from 30 days to forever, and **Saved older price lists** (Spremljeni stariji cjenici) lets you download them.
* The price list runs on WordPress's scheduled events, which run only when someone visits the site. **Set a server cron** (see the FAQ), or the morning price list waits for the first visit.

= Responsibility =

CX Sidrena helps a merchant display prices and publish a price list. It is not legal advice. The merchant remains solely responsible for the accuracy of every price the shop displays, including the bazna cijena and the lowest price in the 30 days before a sale, and for the shop's compliance with the regulations in force. Defaults follow the Act and the draft rules known on the release date; check them before relying on the defaults.

This program is free software, distributed under the GNU General Public License v2 or later, WITHOUT ANY WARRANTY; without even the implied warranty of MERCHANTABILITY or FITNESS FOR A PARTICULAR PURPOSE.

CX Sidrena is MJDD Sidrena i bazna cijena 1.26.0 by Jure Zadravec / MJDD (https://mjdd.hr, GPL-2.0-or-later) under a new name, with the Bazna cijena – dodatak add-on 1.1.1 built in. Development of CX Sidrena: Hrvoje Puzin, https://github.com/cx-core/cx-sidrena

== Installation ==

1. Download `cx-sidrena.zip` from the latest release at https://github.com/cx-core/cx-sidrena/releases and upload it in Plugins → Add New → Upload Plugin.
2. Activate it. On a shop that runs MJDD Sidrena i bazna cijena (with or without the Bazna cijena – dodatak add-on), activation moves the shop over: see **Moving from MJDD Sidrena i bazna cijena** below.
3. WooCommerce → Bazna cijena → Settings → **Dates** (Postavke → Datumi): check the date of each category and save. Until Dates are saved, **Fix all** (Popravi sve) on Status does not run.
4. Settings → **Public price list** (Postavke → Javni cjenik): fill in **Shop address** (Adresa trgovine); it goes into the name of each archived file.
5. Set a server cron: see **Server cron** below.
6. Open **Status** (Stanje) and deal with the products that need attention.

== Frequently Asked Questions ==

= Server cron =

In cPanel → Cron Jobs, add a job that runs every 15 minutes (`*/15 * * * *`) with this command, using the site's address exactly as in the browser:

`wget -q -O - "https://example.com/wp-cron.php?doing_wp_cron" >/dev/null 2>&1`

If the host has no `wget`:

`curl -s -L "https://example.com/wp-cron.php?doing_wp_cron" >/dev/null 2>&1`

With the cron in place you may also add `define( 'DISABLE_WP_CRON', true );` to `wp-config.php`, so that visits no longer start WP-Cron. Never add it without the cron job: scheduled events would stop altogether. To check, open `/sidrena-cjenik/` the next morning: the "Ažurirano" line should show today's date and a time shortly after 00:05.

= Moving from MJDD Sidrena i bazna cijena =

Install CX Sidrena next to MJDD and activate it. It copies MJDD's settings and its public price list and archive files under its own name, switches MJDD and the Bazna cijena – dodatak add-on off, and shows on Status what it did. The bazna prices, dates and price history on the products are the same fields and stay where they are, and the public addresses (`/sidrena-cijena.xml`, `/sidrena-cjenik`, `/arhiva-sidrene-cijene`) keep working. Customers then see the label "Bazna cijena na …", without a tooltip. MJDD's own data is left as it was, so you can go back: deactivate CX Sidrena and activate MJDD (and the add-on). MJDD and the add-on cannot run next to CX Sidrena; if one of them is activated, CX Sidrena switches it off again and says so.

On a shop that ran MJDD **without** the add-on, the lowest price in 30 days stays hidden, as it was: customers see WooCommerce's struck regular price without the line, until you turn it on in Settings or with `wp cx-sidrena n30 on`.

= Lowest price in 30 days =

The price a customer pays is recorded for every product and variation from the day CX Sidrena is installed, on every save through WooCommerce. When a sale starts, the lowest price of the 30 days before it is fixed for the whole sale. A sale that was already running at installation uses the regular price, or a starting lowest price you import (Import, column "Najniža cijena u 30 dana"). `wp cx-sidrena n30 on|off|status` shows or hides the line for the whole shop; the history is recorded either way. It is shown on a new installation and after a move from MJDD with the add-on. A variable product whose sizes cost the same shows the lowest of its sizes (or "X – Y"), and a chosen size its own; one whose sizes have different prices shows the line once a size is chosen. Every sale is shown as a sale and the price list says DA for it, also where the sale price is not below the lowest price (Status lists those for the owner).

If WooCommerce Lowest Price (Igor Kovačić 1.x or Media X 2.x) is active, CX Sidrena leaves the lowest price to that plugin and shows only the bazna line.

= WP-CLI =

`wp cx-sidrena`:

* `status`: how many products have a bazna cijena
* `check`: products without a bazna cijena or with a later date than expected, the price list and how the plugin is updated; exit code 1 when something is not in order
* `n30 on|off|status`: show or hide the lowest price in 30 days
* `fill [--confirmed-by=<name>] [--confirmed-on=<Y-m-d>] [--dry-run]`: the bazna cijena from the regular price, where it is missing; without a name it only counts
* `import <file> [--confirmed-by=<name>] [--confirmed-on=<Y-m-d>]`: the same import as the Import screen; without a name it only shows the preview
* `job [status|cancel]`: the running fill or import
* `sale-rule [--out=<file>]`: a file for `import` with a suggested bazna cijena for products on sale that have none
* `export-bazna [--out=<file>]`: the bazna cijene in the shape `import` reads back
* `archive [list] [--kept]`, `archive get <name> [--out=<file>]`: the archive of published price lists
* `dates [<id>]`: the bazna date of each category, or of one product
* `history <id|sku>`: the price history of a product
* `purge`: see **Removing CX Sidrena**

= Tax (VAT) =

The bazna cijena is entered like the WooCommerce regular price (with or without tax, as WooCommerce → Settings → Tax says prices are entered). The bazna on the product page and every amount of the price list (retail price, unit price, bazna) are always with tax, as the law requires, at the rate of the shop's own address, also when the shop shows its prices without tax and for a customer abroad or exempt from VAT. If the shop computes VAT outside WooCommerce Tax, hook `sidrena_cijena_price_to_display` (arguments: `$amount`, `$product`, `$raw`); `$amount` already includes WooCommerce's tax.

= Themes and page builders =

The line is added through `woocommerce_get_price_html`, so it shows wherever a theme prints a WooCommerce price. For page builders that skip that filter (Elementor, JetWooBuilder, ShopEngine, WPBakery, Oxygen, Divi, Bricks, Beaver Builder, Breakdance, Brizy, Avada, Woodmart, Flatsome, WooCommerce blocks), CX Sidrena adds it with PHP hooks and a script that puts it only into the price of the page's own product. This support is inherited from MJDD 1.26.0, not yet re-tested in CX Sidrena. If a theme still shows no line, use the shortcode or hook below.

= Shortcodes and hook =

* `[sidrena-cijena]`, `[sidrena-cijena id="123"]`: the bazna line of a product. `prikaz="redovna"`, `prikaz="akcijska"` or `prikaz="sidrena"` prints only that amount.
* `[sidrena-cjenik]`: the searchable price list table, on any page.
* `[sidrena-arhiva]`: the list of archived price list files.
* In a template: `do_action( 'sidrena_cijena' );` or `sidrena_cijena( $product );`

= Custom CSS =

The bazna line is `.sidrena-cijena` (parts: `.sidrena-cijena__prefix`, `.sidrena-cijena__sep`, `.sidrena-cijena__amount`; a variable product before a size is chosen: `.sidrena-cijena--variable`). The line with the lowest price in 30 days is `.bazna-najniza30` (`.bazna-najniza30--variable` on a variable product before a size is chosen). Both are smaller than the price, never bold and never struck; their size and weight are set with `!important`, so a rule of yours needs it too. For the colour use Settings → Label shown to customers → Colour (Postavke → Natpis koji vide kupci → Boja).

= Without WooCommerce, outlets and extra items =

Without WooCommerce, CX Sidrena keeps MJDD's own price list: rows entered by hand or imported from CSV/XML, published the same way. With several outlets, each has its own price list, page and archive. Services and items that are not WooCommerce products can be added to the price list. All three are in **Show advanced settings** (Prikaži napredne postavke). Inherited from MJDD 1.26.0, not yet re-tested in CX Sidrena.

The bulk import writes only the price list chosen on its form (the webshop or one outlet). The price list downloaded from Settings has the rows of every outlet, each with its id: imported back, a row that belongs to another outlet or to the webshop is left as it was, and the result says how many such rows there were. To change them, import the file into that outlet.

= Languages =

The plugin's own texts are in English with a Croatian translation included; German and Slovenian come from MJDD and are not complete. "Bazna cijena" is not translated, and the values in the price list are Croatian.

= Updates =

CX Sidrena updates itself from the published releases at https://github.com/cx-core/cx-sidrena, only with the release's own `cx-sidrena.zip`, never from wordpress.org. To update by hand instead, add `define( 'CX_SIDRENA_AUTO_UPDATE', false );` to `wp-config.php`. When automatic updates are switched off for the whole site (by the hosting, or `AUTOMATIC_UPDATER_DISABLED` in `wp-config.php`), nothing updates by itself; Settings → Show advanced settings (Postavke → Prikaži napredne postavke) and `wp cx-sidrena check` say how the plugin is updated on this site. While CX Sidrena is active, MJDD and the add-on are not updated automatically.

= Removing CX Sidrena =

Deleting CX Sidrena in Plugins removes its settings (including Dates, outlets and the rows entered by hand), the live price lists and its scheduled tasks. `wp cx-sidrena purge` does the same, after asking, and deactivates the plugin. Neither ever removes the bazna prices, dates and sources on products, the lowest price data, the price history and confirmations, or the archive of published price lists: they are your record of the prices you published, and MJDD Sidrena i bazna cijena reads the same product fields.

== Changelog ==

= 0.3.0 =
* No new price list without the shop's OIB, MBO or MIBPG (Pravilnik o cjeniku, art. 6(3)): until the number is entered, nothing new is published, what was already published stays public, and Status and Settings say so in red. Enter it in Settings → Public price list before the update.
* Every amount of the price list and the bazna cijena on the page are with tax (ZZP art. 4), also on a shop that enters or shows prices without tax. A shop that shows prices with tax sees no change.
* The unit price (€/kg, €/l): a net quantity on the product (and on each size) fills neto_kolicina, jedinica_mjere and cijena_jedinice_mjere in the price list and shows the unit price next to the price on the product page; not under 50 g or 50 ml, nor where it equals the price. Without a net quantity nothing changes.
* A sale shows WooCommerce's struck regular price and the line "Najniža cijena u 30 dana prije sniženja: X" with its amount; every sale is a sale for the customer and DA in the price list, also one whose price is not below the lowest price in 30 days (Status reminds the owner). The badge's percentage is worked out from the regular price.
* Several places of sale (outlets), each with its own price list, address, type, code and products (all or picked); the import into a place no longer rewrites the list of places; a place with only rows by agreement has its page and no file.
* Services and goods outside WooCommerce in the price list and on its page, with their own bazna cijena and date; a WooCommerce product marked "Usluga" goes into the services file; rows "price by agreement" under a heading true for every row.
* Brand, EAN, barcode and šifra in the price list come from the owner's data.
* Archive: a replaced price list stays public 15 days from its replacement (art. 3(6)), not 30 days from the newest; files are matched to their owner by kind and address, not only by code; no two archive headings are the same bare code.
* The stable address (/sidrena-cijena.xml, .csv) answers with the file itself, named in Content-Disposition, instead of a redirect.
* The lowest price in 30 days has its own switch in Settings, and Status says whether it is shown; WooCommerce's own sale badge on block cards follows our badge rule.
* Import: WooCommerce's product CSV import leaves our fields alone when their cell is empty; the bulk import writes only the price list chosen on its form, rows of other places stay as they are and are counted; a name with "<" no longer breaks the items page, and one click no longer empties the catalog; a product without a bazna cijena stays in the Additional items.
* The suggested bazna cijena for products on sale (Import tab, its file and WP-CLI) says it is worked out from today's sale and price, and that the price before that sale on the reference day should be checked.
* The price list's times in the admin are on the legal (Croatian) clock.
* After the update the price list is built once more, so the new columns and rules show at once.

= 0.2.3 =
* Price list: a sale price that is not lower than the lowest price in the 30 days before the sale is no special form of sale. The price list, XML, CSV and archive now say poseban_oblik NE for it, as the product page shows it as a plain price; a size of a variable product follows what its page shows. The retail price in the file is still the price the customer pays.
* The public price list table is in the shop's language, whatever the profile language of the admin who builds it.
* "Save and generate the price list now" while another build is running: the save is kept and the price list is built once more right after, with what was saved; a build that fails tries again within a few minutes. The message says what happened instead of pointing at the uploads folder permissions. Settings shows the storage number the next build will take.
* A new install publishes no price list until the first bazna cijena is entered: until then the price list page says "Cjenik još nije objavljen.", the XML and CSV addresses answer 404, there is no footer link, and Status is red with what to do. A shop that has already published goes on as before, and nothing published is deleted.
* Without WooCommerce, bulk import: a price is compared as an amount, so a file saved by Excel (23,5 instead of 23,50) changes nothing and makes no new version; manual rows are written with two decimals in the files. A price that cannot be read for sure (1.234) is not imported: the row stays as it was and the result says which rows. 1,234.50 is now read.
* Bulk import keeps its job in the database and writes the catalog only at the end, so it works with W3 Total Cache's object-cache drop-in, and an import that is cut off writes nothing.
* After the update the price list is built once more, so the first two changes show at once and not only at 00:05.

= 0.2.2 =
* Price list files follow the Pravilnik o cjeniku (NN 132/2026-1584, in force 17.11.2026.): the columns in its order and nothing else, for goods naziv, sifra, marka, neto_kolicina, jedinica_mjere, maloprodajna_cijena, poseban_oblik (DA/NE), cijena_jedinice_mjere, barkod, ean_barkod, bazna_cijena, and for services naziv, maloprodajna_cijena, poseban_oblik, bazna_cijena. id, vrsta, url, the date of the bazna cijena, availability and the name of the special form are no longer in the file. neto_kolicina stays empty until a product has a field for it. After the update the price list is built once more on the next page view, so the live file does not keep the old columns until the morning.
* File names follow the Pravilnik (art. 6): the shop's OIB, MBO or MIBPG, the place, ROBA or USLUGA, the storage number and the time. Enter the number in Settings > Public price list > OIB, MBO or MIBPG; until then the price list still goes out, named without it, and Status and Settings show a yellow line. Goods and services are in separate files; services are at /sidrena-cijena-usluge.xml (and .csv). The stable addresses answer with a redirect to the current file, and the archive page lists goods and services under their own headings.
* The public price list page shows the same columns in the same order, then the date of the bazna cijena; a place with goods and services has a download button for each.
* Public price list from 721 px: a table where it fits, cards where it does not, also from 1024 px with many columns and for [sidrena-cjenik] in a narrow column, so the bazna cijena and its date are never hidden off to the right. Turning a tablet or phone, or resizing the window, no longer loses the reader's place: where the table turns into cards or back, the reader stays on the same row.
* Without WooCommerce: the price list downloaded from the admin carries id, vrsta, naziv_posebnog_oblika and sidrena_datum after the legal columns, so it imports back unchanged; the bulk import no longer reads poseban_oblik as the name of the special form, and reads the services of our XML.
* Import reads an underscore in a column name as a space (bazna_cijena, sifra).

= 0.2.1 =
* When a theme wraps the price in its own element, the bazna line and the lowest-price caption no longer go into the struck sale price: they stand after the theme's price, the caption first. On a sale whose struck price is not the regular price, or that is not lower, the theme's price element stays and only its amounts change.
* The chosen variation's bazna line is inside that variation's price, at 0.8 of its size, with the euro sign on the line.
* Lines a theme adds after the page has loaded (quick view, "load more") get the same readable colour as on the product page, and the lowest-price caption stays right under the struck price there.
* Public price list: on a phone each row fits its box (the right edge was cut off), also in the [sidrena-cjenik] shortcode and the "price on request" table; from 721 to 1023 px (a tablet upright) the rows are cards, so the bazna cijena and its date are no longer hidden off to the right.
* Settings > Dates suggests 02.05.2025. for the six groups (food, drink, cosmetics, cleaning products, toiletries, household products) and 10.09.2026. for equipment, devices, parts, animals and pet food; a category that may hold goods of both dates is marked "Check". Dates already saved do not change. The Dates table fits a phone.
* Status checks in a browser what a customer sees on a product page: when the theme hides the bazna cijena (or the struck lowest price next to its caption) or draws its own price, a yellow row says so, with a link to the product and "Check again".
* Without WooCommerce: a [sidrena-cijena id="dN"] shortcode after text reads as one line ("Cijena knjige: 18,45 €") with the bazna line under it, an amount never breaks from its euro sign, and Products and Import are on Settings, not under advanced settings.
* Admin: other plugins' notices are shown below the tabs and are readable; the Products table shows the required columns first; buttons, the active tab and fields are easier to read, with a visible keyboard focus, and a disabled button looks disabled.

= 0.2.0 =
* Price history: every change of a product's price, sale, bazna cijena and lowest price in 30 days is recorded (when, how and by whom), whatever wrote it, and every bazna cijena knows where it came from. Neither deleting the plugin nor `wp cx-sidrena purge` removes the history.
* A bazna cijena without its own date takes the later of its category's date and the day the product was added. The dates per category are a setting (Settings > Dates). The update from 0.1.0 changes no date a customer sees.
* Filling empty bazna cijene from the regular price, and importing them from a CSV, write nothing until a person confirms by name; they run as one background job and write only the bazna cijena, its date, its source and the starting lowest price in 30 days. MJDD's old fill without a confirmation is gone. WooCommerce's CSV import leaves a bazna cijena alone on an empty cell, and the export has an ID column.
* With WooCommerce, MJDD's old bulk import (and its CSV template) is gone from Settings: the bazna cijena is imported only through Import, with a confirmation. Without WooCommerce it stays for the shop's own price list rows.
* WooCommerce > Bazna cijena has new screens: Status (what needs attention, with fixes in place, and the state of the price list), Import, and Settings with Dates per category. MJDD's old tab addresses lead to the new place.
* On a product and a variation the bazna cijena is a read-only line with its date and source; it is changed only with Change, with a reason, and the change is recorded. The product list has a Bazna cijena column. A copy made with "Copy to a new draft" starts as a new product, without the original's bazna cijena and price history.
* The customer sees one price block: the line "Bazna cijena na 10.9.2026.: 8,50 €" and, on a sale, the lowest price in the 30 days before the sale, struck, with its caption. A sale price that is not lower than that is shown as a plain price, without a badge. A variable product shows the range of its variations' bazna cijene.
* The sale badge and a theme's discount percentage follow one rule: a percentage only from the lowest price in 30 days, rounded down, otherwise "Akcija", or no badge (Settings).
* The bazna line and the lowest-price caption take the colour and font of the shop's ordinary text, not the sale price's colour. The owner can set an own colour.
* The cart, mini-cart and checkout blocks, and the product blocks that read the Store API, show one price per line: no struck regular price and no "Uštedi".
* Public price list: every change makes a new file with the moment it was made in its name and its own storage number; no published file is written over. Files of the last 30 days are public, older ones are kept out of public reach for as long as the owner chooses. A live file is replaced whole, never half written.
* The public price list holds what any consumer can buy, at the price a visitor pays: no rows without a retail price, no prices by role or for one customer, hidden products a visitor can buy are in. The availability column is off unless the owner turns it on. New column poseban_oblik (yes/no), and the brand from WooCommerce Brands when no other is chosen.
* On a product page the bazna line goes only into that product's own price. It no longer shows next to the items of the block mini-cart, or in a theme's side cart, quick view or list of other products (a bug since 0.1.0).
* Status shows the red "Scheduled tasks did not run" banner only when the morning build has really not run by 08:00 Croatian time; a site whose WordPress time zone is not Europe/Zagreb (for example UTC) no longer gets it every morning.
* The public price list page no longer prints one bazna date under its title; each row has its own date.

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
