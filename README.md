# CX Sidrena

A WordPress and WooCommerce plugin for Croatian shops. It shows the *bazna cijena* (also called *sidrena cijena*) next
to the price, shows the lowest price in the 30 days before a sale, and publishes the public price list with a 30-day
archive.

CX Sidrena 0.1.0 is [MJDD Sidrena i bazna cijena](https://mjdd.hr) 1.26.0 (GPL-2.0-or-later) under a new name, with
the *Bazna cijena – dodatak* add-on 1.1.1 built in. A shop that runs MJDD, with or without the add-on, can move over
without entering any *bazna cijena* again, and its customers see no difference.

## Responsibility

CX Sidrena helps merchants display prices and publish price lists as required by the Croatian Consumer Protection Act (Zakon o zaštiti potrošača, Official Gazette NN 19/22, 59/23, 59/26) and its implementing regulations. It is not legal advice.

The merchant remains solely responsible for the accuracy of all prices it displays, including the bazna cijena and the lowest price in the 30 days before a sale, and for its shop's compliance with the regulations in force. Default settings reflect the regulations, and where final texts were not yet published, the draft regulations, known on the release date. Regulations may change; check them before relying on the defaults.

This program is free software, distributed under the GNU General Public License v2 or later, WITHOUT ANY WARRANTY; without even the implied warranty of MERCHANTABILITY or FITNESS FOR A PARTICULAR PURPOSE.

## What it does

- ***Bazna cijena* on every product and variation**, shown below the price. A new variation added in the admin gets
  its *bazna cijena* as well. The shop has one default reference date, and a product can have its own, for example
  02.05.2025. for food and cosmetics and 10.09.2026. for other goods.
- **Lowest price in the 30 days before a sale.** Every change of the price a customer pays is recorded per product and
  variation. When a sale starts, the lowest price of the 30 days before it is fixed for the whole sale. It is shown as
  the struck-through price, with a line *Najniža cijena u 30 dana prije sniženja*.
  - The history starts when the plugin is installed. A sale that was already running then has no history, so the
    regular price is used.
  - Prices written straight into the database, for example by an ERP that bypasses WooCommerce, are not seen.
  - The line can be hidden per shop (`wp cx-sidrena n30 off`). The history is recorded either way.
- **Public price list** at `/sidrena-cijena.xml` (or `.csv`).
  - It is rebuilt about a minute after a change of price, sale or stock, and every day after 00:05, WordPress time.
  - An optional HTML page is at `/sidrena-cjenik/`, and an archive of the last 30 files at `/arhiva-sidrene-cijene/`.
  - Column names and values in the price list are Croatian. They are data for Croatian consumers, not interface text,
    so they are not translated.
- **Check page** under *Tools → Bazna cijena: check*, also available as `wp cx-sidrena check`. It shows products
  without a *bazna cijena*, products whose own date is later than the reference date, when the price list was last
  built, how cron runs and how the plugin is updated on this site. `wp cx-sidrena check` exits with 1 when something
  needs fixing.
- **Languages.** The admin is in English, with a Croatian translation included. Customers of a Croatian shop see
  Croatian.

Also inherited from MJDD, but not tested by us yet:
- services and made-to-order items that are not WooCommerce products;
- several outlets, each with its own price list;
- unit price fields, entered by hand;
- use without WooCommerce.

## Requirements

- WordPress 6.2 or later and PHP 7.4 or later.
- WooCommerce 7.9 or later; tested up to 11.3. MJDD's mode without WooCommerce is still in the code; see above.
- A real cron job. WordPress cron runs only when someone visits the site, so on a quiet shop, or one behind a full-page
  cache, the daily price list can be late. Setting up the cron job is part of installing CX Sidrena: set
  `DISABLE_WP_CRON` to `true` in `wp-config.php` and call `wp-cron.php` from the server's cron (for example in cPanel,
  *Cron Jobs*) every 5 to 15 minutes.

The automated tests run on WordPress 6.2 with WooCommerce 7.9 on PHP 8.3, on WordPress 6.9 with WooCommerce 10.8, and
on WordPress 7.1 with WooCommerce 11.3, the last two on PHP 7.4 and 8.4.

## Installation

1. Download `cx-sidrena.zip` from the latest [release](https://github.com/cx-core/cx-sidrena/releases/latest).
   Do not use GitHub's *Download ZIP* or the source code archives: only the zip attached to a release is the tested
   build.
2. In WordPress, go to *Plugins → Add New Plugin → Upload Plugin*, choose the zip and activate it. On a shop that runs
   MJDD, activation moves it over; see the next section.
3. Open *WooCommerce → Sidrena cijena* and check the *Settings* (label, default reference date) and the *Public price
   list* tab.
4. Set up the cron job (see *Requirements*).

## Moving from MJDD Sidrena i bazna cijena

Install and activate CX Sidrena while MJDD (and the add-on, if you have it) is still active. On activation, CX Sidrena:

1. copies every MJDD setting and MJDD's price list and archive files under its own names (MJDD's own data stays where
   it is);
2. switches MJDD and the add-on off and removes their scheduled jobs;
3. refreshes the permalinks, so the public addresses keep working without anyone opening the admin;
4. leaves a notice in the admin saying what it did.

Product data is shared, not copied: the *bazna cijena*, its date, the price history and the fixed lowest prices stay on
each product and are read where they are. The public addresses, shortcodes, the `sidrena_cijena()` function, filters
and CSS classes keep their names, so themes and links keep working.

- **Lowest price line:** it stays shown on a shop where the add-on was active, and stays hidden on a shop where it was
  not, so nothing changes for customers. Turn it on with `wp cx-sidrena n30 on`.
- **Deleting MJDD and the add-on** after the move is safe. It does not touch CX Sidrena's data or any product data.
- **MJDD switched on again:** if someone switches MJDD or the add-on on while CX Sidrena is active, CX Sidrena switches
  it off again and says so in the admin.
- **Moving over a second time** does not double the price history or the archive.

### Going back to MJDD

1. Deactivate CX Sidrena.
2. Activate MJDD, and the add-on if you had it.
3. If you want MJDD to stay on the version you had, turn off its automatic updates. While the add-on is active, it
   turns them back on.

MJDD's archive will not contain the price lists built while CX Sidrena ran. They are in `uploads/cx-sidrena/`.

## Updates

CX Sidrena updates only from the published releases of this repository, and only with the release's own
`cx-sidrena.zip`. It never takes an update from wordpress.org.
- Each release is tested before it is published.
- Commits on `main`, tags, drafts and pre-releases never reach a shop.
- Automatic updates are switched on for CX Sidrena only, not for other plugins. While CX Sidrena is active, MJDD and the
  add-on are not updated automatically.

To update by hand instead, add `define( 'CX_SIDRENA_AUTO_UPDATE', false );` to `wp-config.php`. A new release is then
still offered in *Plugins*.

Some hosts switch off automatic updates for the whole site (Installatron does this, for example, with
`AUTOMATIC_UPDATER_DISABLED`). On such a shop nothing updates by itself, so update by hand from the latest release. The
check page says how the plugin is updated on each site.

## Removing

There is no "delete all data" button. Deleting CX Sidrena in *Plugins* removes its settings, the public price list and
its archive. `wp cx-sidrena purge` asks for confirmation, removes the same and deactivates the plugin.

Neither ever deletes product data: the *bazna cijena*, its date, the price history and the lowest prices stay on every
product. They are the shop's record of the prices it showed, and MJDD reads the same fields.

## WP-CLI

| command | what it does |
|---|---|
| `wp cx-sidrena status` | how many products have a *bazna cijena* |
| `wp cx-sidrena fill [--dry-run] [--today] [--safe]` | copies the regular price into an empty *bazna cijena*; never overwrites one. `--today` also writes today's date where the product has no own date (products first offered after 10.09.2026.) |
| `wp cx-sidrena check` | the check page; exit code 1 when something needs fixing |
| `wp cx-sidrena n30 on\|off\|status` | shows or hides the lowest price line |
| `wp cx-sidrena purge [--yes]` | see *Removing* |

## For theme developers

- `[sidrena-cijena]`: the *bazna cijena* line in a product loop or template. `prikaz="redovna"`, `"akcijska"` or
  `"sidrena"` prints only that amount; `id="d1"` prints a row entered by hand.
- `[sidrena-cjenik]`: the searchable price list table on any page. `oznaka="001"` limits it to one outlet.
- `[sidrena-arhiva]`: the archive list.
- `do_action( 'sidrena_cijena' );` or `sidrena_cijena();` in PHP.
- The filter `sidrena_cijena_price_to_display`, for shops that calculate the displayed price outside WooCommerce Tax.
- CSS classes: `sidrena-cijena` (the *bazna cijena* line), `bazna-najniza30` (the lowest price line).

## Reporting bugs and suggesting features

- Bugs and feature requests go to [GitHub Issues](https://github.com/cx-core/cx-sidrena/issues/new/choose). Please
  include the versions of CX Sidrena, WordPress, WooCommerce and PHP, and the theme.
- Without a GitHub account, you can write to sidrena@cx-core.com instead.
- **Security vulnerabilities:** do not open a public issue. Report them privately to sidrena@cx-core.com.

## License and credits

GPL-2.0-or-later, see [`license.txt`](license.txt).

Copyright 2026 Hrvoje Puzin.

Based on MJDD Sidrena i bazna cijena 1.26.0, Copyright (C) 2026 Jure Zadravec / MJDD (<https://mjdd.hr>),
GPL-2.0-or-later. Their copyright and license notices are kept unchanged.
