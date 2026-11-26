/**
 * CX Sidrena price list (CX-SID-111): the search box and the pages of each price list on the page, on the public price
 * list page and with [sidrena-cjenik] (any number of them on one page).
 *
 * Until 0.3.2 this was a script inside the price list's own HTML. Where WordPress runs wptexturize() over HTML that
 * already holds a shortcode's output (a block theme's whole page, Elementor's Text Editor), it took part of that script
 * for a tag and turned each "&&" in it into "&#038;&#038;": the browser refused the whole script, the search did nothing
 * and there were no pages, so rows from the 201st on could not be reached. As a file it is never texturized.
 *
 * The rows past the first page come from the server already hidden (and the pages' bar too), so until this script runs,
 * late (at the end of the page) or held back by a caching plugin, the reader sees the first page, never all rows. It
 * binds each price list once, finds every part inside that list only, and applies what is already typed in the box.
 *
 * Goods and services each have their own pages (CX-SID-205, CX-SID-209): a price list built since 0.3.3 has a pager
 * after each table. One built by 0.3.2 or earlier, still served until its next build, has one pager after the last
 * table, and there both tables are paged together, as 0.3.2 did. The script tells them apart by the order of the
 * tables and pagers, not by a version (groupsOf()).
 *
 * A table the search finds nothing in hides its frame (with its header) and its heading (CX-SID-208), so the reader never
 * sees "Usluge" or "Price by agreement" over a table with no row. Decided per table, not per group: a 0.3.2 list has one
 * group over both tables (tablesOf()).
 */
(function () {
	'use strict';

	if (!document.querySelectorAll) {
		return;
	}

	function matches(row, q) {
		return !q || (row.getAttribute('data-search') || '').indexOf(q) !== -1;
	}

	// The page numbers to show: all up to 9 pages, else the first, the last and two either side of the current one,
	// with 0 for a gap.
	function pageList(total, current) {
		var list = [];
		var n;
		if (total <= 9) {
			for (n = 1; n <= total; n++) {
				list.push(n);
			}
			return list;
		}
		var keep = {};
		keep[1] = true;
		keep[total] = true;
		for (n = current - 2; n <= current + 2; n++) {
			if (n >= 1 && n <= total) {
				keep[n] = true;
			}
		}
		var nums = [];
		for (n = 1; n <= total; n++) {
			if (keep[n]) {
				nums.push(n);
			}
		}
		var prev = 0;
		for (n = 0; n < nums.length; n++) {
			if (prev && nums[n] - prev > 1) {
				list.push(0);
			}
			list.push(nums[n]);
			prev = nums[n];
		}
		return list;
	}

	// The priced tables of one price list and their pagers, in the order of the page: a table adds its rows to the open
	// group, a pager closes it. Since 0.3.3 (CX-SID-205) each table has its pager right after it, so goods and services are
	// two groups, each with its own pages. A list built by 0.3.2 or earlier has one pager after the last table (the
	// services' heading between them), so it is one group: the goods, then the services, paged together with that pager,
	// exactly as 0.3.2 did. A group with no pager after it (none is ever printed so) shows all its rows. The table of
	// items on request (.cjenik-upit) is not priced and never paged. A group keeps all its tables' frames (wraps): a page
	// button scrolls to the first one still shown, as on a 0.3.2 list the search may hide the goods' frame (CX-SID-208).
	function groupsOf(root) {
		var parts = root.querySelectorAll('.cjenik-table, .cjenik-pager');
		var groups = [];
		var open = null;
		var i;
		var j;
		for (i = 0; i < parts.length; i++) {
			var part = parts[i];
			if (part.classList && part.classList.contains('cjenik-pager')) {
				if (open) {
					var size = parseInt(part.getAttribute('data-page-size'), 10);
					open.pager = part;
					open.size = size > 0 ? size : 200;
					groups.push(open);
					open = null;
				}
				continue;
			}
			if (!open) {
				open = { rows: [], wraps: [], pager: null, size: 0, page: 1 };
			}
			var wrap = part.closest ? part.closest('.cjenik-table-wrap') : null;
			if (wrap) {
				open.wraps.push(wrap);
			}
			var rows = part.querySelectorAll('tbody tr');
			for (j = 0; j < rows.length; j++) {
				open.rows.push(rows[j]);
			}
		}
		if (open) {
			groups.push(open);
		}
		return groups;
	}

	// Every table of one price list with what is shown for it: its frame (the header is in it) and, for the services'
	// table, the heading "Usluge", for the items on request their heading and note. Headings by class, never as the
	// frame's neighbour: a block theme may put its own element between them (CX-SID-205 C205-10).
	function tablesOf(root) {
		var found = root.querySelectorAll('.cjenik-table, .cjenik-upit');
		var list = [];
		var i;
		var j;
		for (i = 0; i < found.length; i++) {
			var table = found[i];
			var parts = [];
			var wrap = table.closest ? table.closest('.cjenik-table-wrap') : null;
			if (wrap) {
				parts.push(wrap);
			}
			var heads = [];
			var cls = ' ' + table.className + ' ';
			if (cls.indexOf(' cjenik-upit ') !== -1) {
				heads = root.querySelectorAll('.cjenik-upit-title, .cjenik-upit-note');
			} else if (cls.indexOf(' cjenik-table--usluge ') !== -1) {
				heads = root.querySelectorAll('.cjenik-usluge-title');
			}
			for (j = 0; j < heads.length; j++) {
				parts.push(heads[j]);
			}
			list.push({ rows: table.querySelectorAll('tbody tr'), parts: parts });
		}
		return list;
	}

	function firstShown(list) {
		for (var i = 0; i < list.length; i++) {
			if (!list[i].hidden) {
				return list[i];
			}
		}
		return null;
	}

	function bind(root) {
		if (root.getAttribute('data-cjenik-search') === '1') {
			return;
		}
		// An empty price list has no box: nothing to do.
		var input = root.querySelector('.cjenik-filter');
		if (!input) {
			return;
		}
		root.setAttribute('data-cjenik-search', '1');
		var groups = groupsOf(root);
		var tables = tablesOf(root);
		var upitRows = root.querySelectorAll('.cjenik-upit tbody tr');
		var empty = root.querySelector('.cjenik-empty-filter');

		function addButton(g, num) {
			var btn = document.createElement('button');
			btn.type = 'button';
			btn.className = 'cjenik-page' + (num === g.page ? ' is-current' : '');
			btn.textContent = String(num);
			if (num === g.page) {
				btn.setAttribute('aria-current', 'page');
			}
			btn.addEventListener('click', function () {
				g.page = num;
				render(false);
				var to = firstShown(g.wraps);
				if (to && to.scrollIntoView) {
					to.scrollIntoView(true);
				}
			});
			g.pager.appendChild(btn);
		}

		// One group: hide what the search leaves out, show the group's current page, draw its pager. Returns the rows found.
		function renderGroup(g, q, resetPage) {
			var matched = [];
			var i;
			if (resetPage) {
				g.page = 1;
			}
			for (i = 0; i < g.rows.length; i++) {
				if (matches(g.rows[i], q)) {
					matched.push(g.rows[i]);
				} else {
					g.rows[i].hidden = true;
				}
			}
			if (!g.pager) {
				for (i = 0; i < matched.length; i++) {
					matched[i].hidden = false;
				}
				return matched.length;
			}
			var pages = Math.ceil(matched.length / g.size);
			if (pages < 1) {
				pages = 1;
			}
			if (g.page > pages) {
				g.page = pages;
			}
			var start = (g.page - 1) * g.size;
			var end = start + g.size;
			for (i = 0; i < matched.length; i++) {
				matched[i].hidden = i < start || i >= end;
			}
			g.pager.innerHTML = '';
			if (pages <= 1) {
				g.pager.hidden = true;
				return matched.length;
			}
			g.pager.hidden = false;
			var items = pageList(pages, g.page);
			for (i = 0; i < items.length; i++) {
				if (items[i] === 0) {
					var gap = document.createElement('span');
					gap.className = 'cjenik-page-gap';
					gap.textContent = '\u2026';
					gap.setAttribute('aria-hidden', 'true');
					g.pager.appendChild(gap);
				} else {
					addButton(g, items[i]);
				}
			}
			return matched.length;
		}

		// A search filters every table and takes each back to its first page; a page button changes only its own table.
		function render(resetPage) {
			var q = (input.value || '').toLowerCase().trim();
			var shown = 0;
			var i;
			var j;
			for (i = 0; i < groups.length; i++) {
				shown += renderGroup(groups[i], q, resetPage);
			}
			for (i = 0; i < upitRows.length; i++) {
				var showUpit = matches(upitRows[i], q);
				upitRows[i].hidden = !showUpit;
				if (showUpit) {
					shown++;
				}
			}
			// A table with no row for the search (on any of its pages, not only this one) hides its frame and heading;
			// with no search nothing is hidden, so the page as loaded stays as it was.
			for (i = 0; i < tables.length; i++) {
				var hit = !q;
				for (j = 0; j < tables[i].rows.length && !hit; j++) {
					hit = matches(tables[i].rows[j], q);
				}
				for (j = 0; j < tables[i].parts.length; j++) {
					tables[i].parts[j].hidden = !hit;
				}
			}
			if (empty) {
				empty.hidden = shown !== 0;
			}
		}

		input.addEventListener('input', function () {
			render(true);
		});
		render(false);
	}

	function bindAll() {
		var roots = document.querySelectorAll('.sidrena-cjenik');
		for (var i = 0; i < roots.length; i++) {
			bind(roots[i]);
		}
	}

	// At the end of the page the price lists are already there; a list printed later (or a script run early by a
	// plugin that moves scripts) is bound when the page is read.
	bindAll();
	if (document.readyState === 'loading') {
		document.addEventListener('DOMContentLoaded', bindAll);
	}
})();
