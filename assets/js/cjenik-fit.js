/**
 * CX Sidrena price list (CX-SID-104): from 721 px, each table of the price list (the priced ones and the one "by agreement")
 * is shown as a table where it fits in its frame, and as cards where it does not, at any width. On the public price
 * list page and with [sidrena-cjenik].
 *
 * The stylesheet alone (cjenik.css, cjenik-embed.css) shows cards up to 1023 px and a table from 1024: so without this
 * script, a long table on an iPad is cards even where it would fit, and a table with many columns (or the shortcode in a
 * narrow column of a theme) scrolls sideways on a wide screen, the sidrena cijena and its date out of sight. This script
 * lays each table out as a table, measures it and, in the same step (no paint in between), keeps it or makes it cards:
 *   .cjenik-fit    a table that fits, at any width from 721 px (the stylesheet reads it only up to 1023 px, where it
 *                  keeps the stylesheet's cards off the table; from 1024 px a table is a table anyway)
 *   .cjenik-cards  a table that does not fit, from 1024 px (the stylesheet's cards apply to it there too)
 * Up to 720 px: no class, cards, as without the script.
 * It measures again when the window changes width, when the search or the pages show other rows, and once the fonts
 * and the page have loaded. A change of height alone (a phone's or a tablet's toolbars while scrolling, the keyboard)
 * changes nothing it measures, so it does not measure then (CX-SID-104b, Norman 104-1).
 *
 * While it measures, each frame keeps its height (CX-SID-104b, Argus N1): the table laid out for the measuring is
 * several times shorter than its cards, and without that the page got shorter for a moment, the browser moved the
 * scroll position up to the end of that shorter page, and the reader landed far from where they were reading.
 *
 * When a window change does turn a table into cards or back (a phone turned over 720 px, a window made wider or
 * narrower across a breakpoint), the stylesheet has already shown the other layout for that change, and the page is
 * several times longer or shorter: the reader ended up far away, often at the end of the page (CX-SID-104c, Argus N4,
 * Norman 104-4). So the row at the top edge of the window is measured before the change and again after it, in the
 * same step, and the page is scrolled by the difference: the same row stays at the top. Only then, never on a change
 * that keeps every table as it was. "Before" is where the reader was before the window changed, kept while they scroll,
 * so it holds in a browser without scroll anchoring too.
 */
(function () {
	'use strict';

	if (!window.matchMedia || !document.querySelectorAll || !document.documentElement.classList) {
		return;
	}
	var FIT = 'cjenik-fit';
	var CARDS = 'cjenik-cards';
	var phone = window.matchMedia('(max-width: 720px)');
	var tables = Array.prototype.slice.call(document.querySelectorAll('.sidrena-cjenik .cjenik-table, .sidrena-cjenik .cjenik-upit'));
	if (!tables.length) {
		return;
	}

	// The width the tables were last measured at: the window's (the stylesheet's breakpoints) and the page's (the frames).
	var width = function () {
		return window.innerWidth + 'x' + document.documentElement.clientWidth;
	};
	var measured = '';

	// The shown rows of the tables, in the order of the page (the search and the pages hide some).
	var shown = [];
	var collect = function () {
		shown = [];
		tables.forEach(function (table) {
			var body = table.tBodies && table.tBodies[0];
			for (var i = 0; body && i < body.rows.length; i++) {
				if (!body.rows[i].hidden) {
					shown.push(body.rows[i]);
				}
			}
		});
	};
	// The row at the top edge of the window: the first shown row whose bottom is below that edge, if its top is not below
	// it (when the window's top is above the tables or between them, no row, and nothing is kept). With where it is.
	// Rows lie one under another, so it is found by halving (a few reads, also while scrolling).
	var reader = function () {
		var lo = 0;
		var hi = shown.length - 1;
		var found = -1;
		while (lo <= hi) {
			var mid = (lo + hi) >> 1;
			if (shown[mid].getBoundingClientRect().bottom > 0) {
				found = mid;
				hi = mid - 1;
			} else {
				lo = mid + 1;
			}
		}
		if (found < 0) {
			return null;
		}
		var box = shown[found].getBoundingClientRect();
		return box.top < 1 && box.height > 0 ? { row: shown[found], top: box.top, height: box.height } : null;
	};
	// Where the reader was before the window changed width: kept while they scroll, never after a change of width the
	// tables have not been measured for yet. A browser without scroll anchoring (Safari) has already moved the rows by
	// then (the table's text wraps anew), so where they are when the change is handled is not where the reader was.
	var place = null;
	var remember = function () {
		if (width() === measured) {
			place = reader();
		}
	};
	var layouts = function () {
		return tables.map(function (table) {
			return table.className;
		}).join('|');
	};

	var fit = function () {
		collect();
		var anchor = width() !== measured && place ? place : reader();
		measured = width();
		var before = layouts();
		tables.forEach(function (table) {
			var frame = table.parentElement; // .cjenik-table-wrap: it scrolls sideways when the table is wider
			if (phone.matches || !frame) {
				table.classList.remove(CARDS);
				table.classList.remove(FIT);
				return;
			}
			// The frame keeps the height it has now until the decision is made, so the page never gets shorter here.
			var held = frame.style.minHeight;
			frame.style.minHeight = frame.getBoundingClientRect().height + 'px';
			// Laid out as a table (up to 1023 px only with .cjenik-fit), then measured: reading the size lays it out.
			// A table that fits keeps .cjenik-fit from 1024 px too (CX-SID-104c, Norman 104-4, Argus N4): it changes nothing
			// there, but when the window then gets narrower than 1024 px (an iPad turned upright, a window made narrower),
			// the stylesheet keeps showing the table. Without it the stylesheet showed cards first, the browser kept the
			// reader's place in those cards, and when this script made it a table again the page was several times shorter
			// and the reader ended up at its end.
			table.classList.remove(CARDS);
			table.classList.add(FIT);
			if (frame.scrollWidth > frame.clientWidth) {
				table.classList.remove(FIT);
				table.classList.add(CARDS);
			}
			frame.style.minHeight = held;
		});
		// A table changed between a table and cards: the reader's row back to where it was (CX-SID-104c). A row partly
		// scrolled past the top stays scrolled past by the same share of its new height (a card is taller than its row).
		if (anchor && layouts() !== before) {
			var now = anchor.row.getBoundingClientRect();
			if (now.height > 0) {
				var want = anchor.top < 0 ? anchor.top * now.height / anchor.height : anchor.top;
				if (Math.abs(now.top - want) >= 1) {
					window.scrollBy(0, now.top - want);
				}
			}
		}
		place = reader();
	};

	var queued = false;
	var later = function () {
		if (queued) {
			return;
		}
		queued = true;
		window.requestAnimationFrame(function () {
			queued = false;
			if (width() !== measured) {
				fit();
			}
		});
	};

	fit();
	window.addEventListener('scroll', remember, { passive: true });
	window.addEventListener('resize', later);
	window.addEventListener('load', fit);
	if (document.fonts && document.fonts.ready) {
		document.fonts.ready.then(fit);
	}
	// The search and the pages hide and show rows (the attribute hidden): other rows, another width.
	if (window.MutationObserver) {
		var watch = new MutationObserver(fit);
		tables.forEach(function (table) {
			var body = table.tBodies && table.tBodies[0];
			if (body) {
				watch.observe(body, { attributes: true, attributeFilter: ['hidden'], subtree: true });
			}
		});
	}
})();
