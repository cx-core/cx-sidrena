/**
 * Import tab, "Price list from a file" (CX-SID-185, SPEC-UVOZ 1d320dd3; backend CX-SID-181, proposal 4009 + 4011).
 * A place of sale's whole price list, or the webshop's services, from a till's CSV/XML: File → Columns → reading →
 * Preview → Confirm → Done. The screen never writes: it calls the bulk import's endpoints and shows what they answer.
 * Reading (run) writes nothing; Confirm is one request that writes (no background job, no Stop). Texts come translated
 * from PHP (cxUvoz.t, keys are SPEC-UVOZ §T numbers). Also here: the top choice (Bazna cijene of products | Price list
 * from a file), the drop zones of both, and the one History of every kind of import.
 * CX-SID-197 (SPEC-DUCAN cb32799b, contract UGOVOR-v3-KONACNI 06956850 with 195/196/198): the till's file as it is.
 * Excel is read by the server; on Columns the owner sees the start of the file, picks the row with the column names (the
 * same file is parsed again) and picks columns by their examples; a place's remembered columns come preset. The preview
 * adds the group headings, "New, dated today" (198), returned items and SKUs like existing ones (U9).
 */
(function () {
	'use strict';

	var C = window.cxUvoz;
	var root = document.getElementById('cx-import');
	if (!C || !root) {
		return;
	}
	var T = C.t;

	// --------------------------------------------------------------------------------------------------------------
	// Helpers
	// --------------------------------------------------------------------------------------------------------------

	/** printf for the texts from PHP: %s, %d and %1$s; nothing else is used. */
	var sprintf = function (fmt) {
		var args = Array.prototype.slice.call(arguments, 1);
		var i = 0;
		return String(fmt).replace(/%(\d+\$)?([sd])/g, function (m, pos) {
			var v = pos ? args[parseInt(pos, 10) - 1] : args[i++];
			return String(v === undefined || v === null ? '' : v);
		});
	};
	var $ = function (sel, r) {
		return (r || document).querySelector(sel);
	};
	var $$ = function (sel, r) {
		return Array.prototype.slice.call((r || document).querySelectorAll(sel));
	};
	var el = function (tag, attrs, kids) {
		var e = document.createElement(tag);
		Object.keys(attrs || {}).forEach(function (k) {
			var v = attrs[k];
			if (v === null || v === undefined || v === false) {
				return;
			}
			if (k === 'text') {
				e.textContent = v;
			} else if (k === 'class') {
				e.className = v;
			} else if (v === true) {
				e.setAttribute(k, '');
			} else {
				e.setAttribute(k, v);
			}
		});
		(kids || []).forEach(function (k) {
			if (k !== null && k !== undefined && k !== false) {
				e.appendChild(typeof k === 'string' ? document.createTextNode(k) : k);
			}
		});
		return e;
	};
	var sleep = function (ms) {
		return new Promise(function (r) {
			setTimeout(r, ms);
		});
	};
	var num = function (v) {
		return parseInt(v, 10) || 0;
	};
	var fmtDate = function (d) {
		var m = /^(\d{4})-(\d{2})-(\d{2})/.exec(String(d || ''));
		if (m) {
			return parseInt(m[3], 10) + '.' + parseInt(m[2], 10) + '.' + m[1] + '.';
		}
		m = /^(\d{1,2})\.(\d{1,2})\.(\d{4})\.?$/.exec(String(d || '').trim());
		return m ? parseInt(m[1], 10) + '.' + parseInt(m[2], 10) + '.' + m[3] + '.' : String(d || '');
	};
	var fmtPrice = function (a) {
		return a === null || a === undefined || a === '' ? '' : C.priceFormat.replace('%s', String(a).replace('.', C.decimal));
	};
	var todayYmd = function () {
		return C.today;
	};

	/** POST to admin-ajax; resolves with { ok, data } (data = the JSON's data), rejects on a network failure. */
	function post(action, fields) {
		var body = fields instanceof FormData ? fields : new FormData();
		if (!(fields instanceof FormData)) {
			Object.keys(fields || {}).forEach(function (k) {
				var v = fields[k];
				if (v && typeof v === 'object') {
					Object.keys(v).forEach(function (kk) {
						body.append(k + '[' + kk + ']', v[kk] === null || v[kk] === undefined ? '' : v[kk]);
					});
				} else {
					body.append(k, v === null || v === undefined ? '' : v);
				}
			});
		}
		body.append('action', action);
		body.append('nonce', C.nonce);
		return fetch(C.ajaxUrl, { method: 'POST', body: body, credentials: 'same-origin' }).then(function (r) {
			return r.json().catch(function () {
				return { success: false, data: {} };
			});
		}).then(function (j) {
			return { ok: !!(j && j.success), data: (j && j.data) || {} };
		});
	}

	/** A reading step may drop: tried again from the same offset (run writes nothing, so a repeat is harmless). */
	async function postRetry(action, fields, onRetry) {
		for (var i = 0; ; i++) {
			try {
				return await post(action, fields);
			} catch (e) {
				if (i >= 4) {
					throw e;
				}
				if (onRetry) {
					onRetry();
				}
				await sleep(1500 * (i + 1));
			}
		}
	}

	// --------------------------------------------------------------------------------------------------------------
	// Drop zones (both choices): a file dropped anywhere on the zone goes into its file input.
	// --------------------------------------------------------------------------------------------------------------

	function bindDrop(zone) {
		var input = $('input[type="file"]', zone);
		if (!input || zone.dataset.dropBound) {
			return;
		}
		zone.dataset.dropBound = '1';
		['dragenter', 'dragover'].forEach(function (ev) {
			zone.addEventListener(ev, function (e) {
				e.preventDefault();
				zone.classList.add('is-over');
			});
		});
		['dragleave', 'drop'].forEach(function (ev) {
			zone.addEventListener(ev, function () {
				zone.classList.remove('is-over');
			});
		});
		zone.addEventListener('drop', function (e) {
			e.preventDefault();
			if (e.dataTransfer && e.dataTransfer.files && e.dataTransfer.files.length) {
				input.files = e.dataTransfer.files;
				input.dispatchEvent(new Event('change', { bubbles: true }));
			}
		});
	}

	// --------------------------------------------------------------------------------------------------------------
	// Targets: the webshop (services only) and the places of sale (SPEC §4.0)
	// --------------------------------------------------------------------------------------------------------------

	var targets = C.targets || { places: [], webshop_services: 0, isti_cjenik: false };
	var places = targets.isti_cjenik ? [] : (targets.places || []);
	/** The targets of a confirmation's answer (CX-SID-201): the same as a reload would give, so the place just imported
	 *  shows its price list (T10) and its next file the sentences of a later import (T96b, T96c, T97b). An answer
	 *  without them (an older server) leaves the page load's. */
	var takeTargets = function (t) {
		if (!t || !Array.isArray(t.places)) {
			return;
		}
		targets = t;
		places = t.isti_cjenik ? [] : t.places;
	};
	var codeKey = function (c) {
		return String(c || '').trim().toUpperCase();
	};
	var placeOf = function (oznaka) {
		for (var i = 0; i < places.length; i++) {
			if (codeKey(places[i].oznaka) === codeKey(oznaka)) {
				return places[i];
			}
		}
		return null;
	};
	/** A place "shows the webshop's products" when its dodjela is svi, or odabrani with products (not nijedan). */
	var carriesWeb = function (p) {
		return !!p && p.dodjela !== 'nijedan';
	};
	var placeLabel = function (p) {
		return sprintf(T.T8, p.naziv || p.oznaka, p.oznaka);
	};
	var defaultCilj = function () {
		var za = String(C.za || '');
		if (za.toLowerCase() === 'webshop') {
			return 'webshop';
		}
		if (za && placeOf(za)) {
			return placeOf(za).oznaka;
		}
		return places.length ? places[0].oznaka : 'webshop';
	};

	// --------------------------------------------------------------------------------------------------------------
	// The flow
	// --------------------------------------------------------------------------------------------------------------

	var panel = document.getElementById('cx-uvoz');
	var S = {
		cilj: defaultCilj(),
		step: 'file', // file | columns | reading | preview | confirm | done
		job: null, // parse answer: token, fields, total, preview, filename, guessed (+ CX-SID-197: header, saved, head_rows, …)
		file: null, // the chosen file, kept for a parse with another header row (SPEC-DUCAN §2.2)
		guessedAny: false, // the server proposed at least one column (T25 vs T25o)
		reparsing: false, // a parse with another header row is on its way: Next waits for it (Argus: race 1)
		map: {},
		run: null, // the last reading step (done): counts, goods, services, reasons, consent, place_switch
		list: '',
		page: 1,
		seq: 0, // a reading or a list answer for an older file draws nothing
		busy: false,
		error: '',
		result: null
	};

	// The fields of our price list a column can go to (SPEC §4.2). The map keys are the bulk import's (4009 c).
	var FIELDS = [
		{ key: 'name', label: T.fName, req: 'one' },
		{ key: 'regular', label: T.fRegular, req: 'one' },
		{ key: 'sku', label: T.fSku, req: 'code' },
		{ key: 'id', label: T.T29b, req: 'code' },
		{ key: 'vrsta', label: T.fType },
		{ key: 'sale', label: T.fSale },
		{ key: 'sidrena', label: T.fBazna },
		{ key: 'datum', label: T.fBaznaDate }
	];
	var MORE = [
		{ key: 'marka', label: T.fBrand },
		{ key: 'jedinica', label: T.fUnit },
		{ key: 'jedinica_cijena', label: T.fUnitPrice },
		{ key: 'oblik', label: T.fSpecial }
	];
	// What run sends for every key the bulk import knows; the ones not shown stay empty (not imported).
	var ALL_KEYS = ['sku', 'id', 'name', 'url', 'regular', 'sale', 'sidrena', 'datum', 'vrsta', 'poslovnica', 'jedinica',
		'jedinica_cijena', 'marka', 'oblik', 'cjm', 'cjm_suffix', 'dostupnost'];

	// od_danas (198, T88) and like_code (U9, T95) show only when they hold rows (SPEC-DUCAN §3.2, §3.3), so a file that
	// passes today keeps the same tiles.
	var TILES = [
		['new', T.tNew],
		['od_danas', T.T88, true],
		['changed', T.T38],
		['same', T.tSame],
		['waits', T.T39],
		['skipped', T.T40],
		['like_code', T.T95, true],
		['removed', T.T48]
	];
	var tileShown = function (t) {
		return !t[2] || num(counts()[t[0]]) > 0;
	};

	var REASON = {
		waits: function () { return T.T47; },
		no_code: function () { return T.T47c; },
		no_bazna: function () { return T.T47n; },
		no_name: function () { return T.T42a; },
		no_price: function () { return T.T42b; },
		unclear_amount: function (d) { return sprintf(T.T43, d); },
		goods_to_webshop: function () { return T.T41; },
		other_place: function (d) { return sprintf(T.T47b, d); },
		removed: function () { return T.T48c; },
		special_form: function () { return T.T47s; },
		duplicate_code: function (d) { return sprintf(T.T47d, d); },
		no_date: function () { return T.T47; },
		like_code: function (d) { return sprintf(T.T95a, d); }
	};
	/** The text by list and code together (Norman, TEXTS-dodaci d772403f): no_bazna is T47 under Waits (a service)
	 *  and T47n under Won't be imported (goods); no_date only waits. */
	var reasonText = function (r, list) {
		if (!r || !r.code) {
			return '';
		}
		if (list === 'waits') {
			return T.T47;
		}
		return REASON[r.code] ? REASON[r.code](r.detail) : String(r.detail || r.code);
	};

	var isPlace = function () {
		return S.cilj !== 'webshop';
	};
	var place = function () {
		return isPlace() ? placeOf(S.cilj) : null;
	};
	var canGoOn = function () {
		return !!(S.map.name && S.map.regular && (S.map.sku || S.map.id));
	};
	var counts = function () {
		return (S.run && S.run.counts) || {};
	};
	var entering = function () {
		var c = counts();
		return num(c.new) + num(c.od_danas) + num(c.changed) + num(c.same) + num(c.waits);
	};
	/** The server's today (198), for T88a and T88b; the page's own as a fallback. */
	var serverToday = function () {
		return (S.run && S.run.today) || C.today;
	};
	/** A date written "10.9.2026." closes the sentence with its own dot: no second one (T97 ends in "%s."). */
	var endDot = function (text) {
		return String(text).replace(/\.\.$/, '.');
	};
	/** At most n characters, with "…" (SPEC-DUCAN §2.2, §2.3). */
	var cut = function (v, n) {
		var t = String(v === null || v === undefined ? '' : v);
		return t.length > n ? t.slice(0, n - 1) + '…' : t;
	};

	function setStep(step) {
		S.step = step;
		S.error = S.error && step === S.errorStep ? S.error : '';
		render();
		// A step shown with its error (a failed read, back on Columns) focuses that error, which sits under the column
		// map and would stay below the screen behind the heading (Norman, CX-SID-185). Else the step's heading.
		var head = (S.error && $('.cx-ui__err', panel)) || $('[data-focus]', panel);
		if (head) {
			head.focus();
		}
	}

	function reset(toStep) {
		S.seq++;
		S.job = null;
		S.file = null;
		S.guessedAny = false;
		S.reparsing = false;
		S.pendingRow = null;
		S.map = {};
		S.run = null;
		S.list = '';
		S.page = 1;
		S.busy = false;
		S.result = null;
		setStep(toStep || 'file');
	}

	/** Leaving a flow past the File step asks first (SPEC §1, T12). */
	var past = function () {
		return S.step !== 'file' && S.step !== 'done';
	};

	// ---------- the top card: Price list for ----------

	function targetCard() {
		var sel = el('select', { id: 'cx-uvoz-za' });
		sel.appendChild(el('option', { value: 'webshop', text: T.T11a }));
		places.forEach(function (p) {
			sel.appendChild(el('option', { value: p.oznaka, text: placeLabel(p) }));
		});
		sel.value = S.cilj;
		sel.addEventListener('change', function () {
			if (past() && !window.confirm(T.T12)) {
				sel.value = S.cilj;
				return;
			}
			S.cilj = sel.value;
			reset('file');
		});

		var rows = [];
		var row = function (text, kind, extra) {
			return el('div', { class: 'cx-ui__row' + (kind ? ' cx-ui__' + kind : ''), 'data-state': 'target' }, [
				el('span', { class: 'cx-ui__st', 'aria-hidden': 'true', text: kind === 'warn' ? '!' : 'i' }),
				el('div', {}, [el('p', { text: text })].concat(extra || []))
			]);
		};
		var outletsBtn = function () {
			return el('a', { class: 'button', href: C.outletsUrl, text: T.T18 });
		};
		if (targets.isti_cjenik) {
			rows.push(row(T.T19, '', [outletsBtn()]));
		} else if (!places.length) {
			rows.push(row(T.T17, '', [outletsBtn()]));
		}
		if (S.cilj === 'webshop') {
			rows.push(row(num(targets.webshop_services) ? T.T7 + ' ' + sprintf(T.T10w, num(targets.webshop_services)) : T.T7));
		} else {
			var p = place();
			if (carriesWeb(p)) {
				rows.push(row(hasWebRows(p) ? sprintf(T.T80, p.naziv || p.oznaka, num(p.web_rows)) : sprintf(T.T80n, p.naziv || p.oznaka)));
			} else if (p && p.imported) {
				rows.push(row(sprintf(T.T10, p.imported.on || '', num(p.imported.items))));
			} else {
				rows.push(row(T.T9));
			}
		}

		return el('section', { class: 'cx-ui__card cx-uvoz__target' }, [
			el('label', { for: 'cx-uvoz-za', text: T.T11 }),
			el('div', {}, [sel])
		].concat(rows));
	}

	/** web_rows: how many webshop products the place shows in its live price list; null before its first file, and
	 *  then the texts without a number (T80n, T80pn, T80cn). */
	var hasWebRows = function (p) {
		return !!p && p.web_rows !== null && p.web_rows !== undefined;
	};

	// ---------- steps bar ----------

	function stepsBar() {
		var order = ['file', 'columns', 'preview', 'confirm'];
		var at = { file: 0, columns: 1, reading: 1, preview: 2, confirm: 3, done: 4 }[S.step];
		var labels = [T.T13, T.T14, T.T15, T.T16];
		return el('ol', { class: 'cx-ui__steps', 'aria-hidden': 'true' }, order.map(function (s, i) {
			return el('li', { class: i < at ? 'is-done' : (i === at ? 'is-on' : '') }, [
				el('span', { class: 'n', text: i < at ? '✓' : String(i + 1) }),
				labels[i]
			]);
		}));
	}

	var errLine = function () {
		return S.error ? el('p', { class: 'cx-ui__err', role: 'alert', tabindex: '-1', text: S.error }) : null;
	};
	var showError = function (text, step) {
		S.error = text;
		S.errorStep = step || S.step;
	};

	// ---------- step 1: File ----------

	function fileStep() {
		// Excel too (SPEC-DUCAN §1): the server reads it (195), the screen never refuses a file by its name.
		var input = el('input', {
			type: 'file',
			id: 'cx-uvoz-file',
			accept: '.csv,.txt,.xml,.xls,.xlsx,text/csv,text/xml,application/xml,application/vnd.ms-excel,application/vnd.openxmlformats-officedocument.spreadsheetml.sheet'
		});
		var zone = el('div', { class: 'cx-ui__drop' }, [
			el('span', { class: 'cx-ui__dropicon', 'aria-hidden': 'true', text: '⇪' }),
			el('label', { for: 'cx-uvoz-file', text: T.T20a }),
			el('p', { class: 'cx-ui__muted', text: T.orDrop }),
			input
		]);
		bindDrop(zone);
		var go = el('button', { type: 'button', class: 'button button-primary', text: S.busy ? T.reading : T.upload, disabled: S.busy });
		go.addEventListener('click', function () {
			upload(input.files && input.files[0]);
		});
		var tpl = el('a', { class: 'button', href: C.templateUrl + '&cilj=' + encodeURIComponent(S.cilj), text: T.template });
		return [
			el('h3', { tabindex: '-1', 'data-focus': '1', class: 'screen-reader-text', text: T.T13 }),
			zone,
			el('p', { class: 'description', text: T.T20n }),
			errLine(),
			el('div', { class: 'cx-ui__foot' }, [go, tpl])
		];
	}

	/** The parse request: the file, its target (the remembered columns are per target, U7) and, when the owner picks it,
	 *  the header row (0 = the file has none). */
	function parseRequest(file, redak) {
		var fd = new FormData();
		fd.append('file', file);
		fd.append('cilj', S.cilj);
		if (redak !== undefined && redak !== null) {
			fd.append('redak', String(redak));
		}
		return post('sidrena_cijena_import_parse', fd).catch(function () {
			return { ok: false, data: {} };
		});
	}

	/** A parse error's text (SPEC-DUCAN §1, §4.5). */
	var parseError = function (code) {
		return {
			xls: T.T21n,
			ods: T.T21o,
			other: T.T21x,
			empty: T.T22,
			encoding: T.T23
		}[code] || T.error;
	};

	/** Take a parse answer: the job, and the map preset from what the server proposed (or remembered, then saved is
	 *  'applied' and guessed is that map, SPEC-DUCAN §2.5). */
	function takeParse(data) {
		S.job = data;
		S.map = {};
		S.guessedAny = false;
		var usable = {};
		visibleColumns().forEach(function (c) {
			usable[c.field] = true;
		});
		FIELDS.concat(MORE).forEach(function (f) {
			var g = data.guessed && data.guessed[f.key];
			S.map[f.key] = g && usable[g] ? g : '';
			if (S.map[f.key]) {
				S.guessedAny = true;
			}
		});
	}

	async function upload(file) {
		if (!file) {
			showError(T.noFile);
			render();
			return;
		}
		var mine = ++S.seq;
		S.busy = true;
		S.error = '';
		render();
		var res = await parseRequest(file);
		if (mine !== S.seq) {
			return; // another file, or the owner left (Argus: race 2)
		}
		S.busy = false;
		if (!res.ok || !res.data.token) {
			showError(parseError(res.data && res.data.code));
			render();
			return;
		}
		S.file = file;
		takeParse(res.data);
		setStep('columns');
	}

	/** The owner picked another header row: the same file again, with that row (SPEC-DUCAN §2.2). The old token is
	 *  simply not used any more; the server keeps no rows above the names. */
	async function reparse(redak) {
		var mine = ++S.seq;
		S.reparsing = true;
		S.pendingRow = redak;
		S.error = '';
		render();
		focusHeader();
		var res = await parseRequest(S.file, redak);
		if (mine !== S.seq || S.step !== 'columns') {
			return; // Back, another file or another row since (Argus: race 2)
		}
		S.reparsing = false;
		S.pendingRow = null;
		if (!res.ok || !res.data.token) {
			// The old answer stays as it was, with its row: the select goes back (SPEC-DUCAN §2.2).
			showError(T.error);
			render();
			focusHeader();
			return;
		}
		takeParse(res.data);
		render();
		focusHeader();
	}

	var focusHeader = function () {
		var sel = $('#cx-uvoz-hdr', panel);
		if (sel) {
			sel.focus();
		}
	};

	// ---------- step 2: Columns ----------

	/** The file's columns as the screen shows them: number, key for the map, name and examples (contract 196). A column
	 *  that is empty in the whole file is not shown (SPEC-DUCAN §2.2). Without the contract (an older server) the
	 *  fields and the preview give the same. */
	var visibleColumns = function () {
		var j = S.job || {};
		if (Array.isArray(j.columns)) {
			return j.columns.filter(function (c) {
				return c && !c.empty;
			});
		}
		return (j.fields || []).map(function (f, i) {
			return {
				n: i + 1,
				field: f,
				name: f,
				examples: (j.preview || []).map(function (r) {
					return r ? r[f] : '';
				})
			};
		});
	};
	/** "Column N" for a column without a name (T92). */
	var colName = function (c) {
		return c.name ? String(c.name) : sprintf(T.T92, num(c.n));
	};
	/** The first non-empty example of a column, at most 40 characters (SPEC-DUCAN §2.3). */
	var colExample = function (c) {
		var ex = (c.examples || []).filter(function (v) {
			return v !== null && v !== undefined && String(v).trim() !== '';
		})[0];
		return ex === undefined ? '' : cut(ex, 40);
	};
	var columnOf = function (field) {
		return visibleColumns().filter(function (c) {
			return c.field === field;
		})[0] || null;
	};

	function fieldRow(f) {
		var id = 'cx-uvoz-map-' + f.key;
		var sel = el('select', { id: id, 'data-key': f.key });
		sel.appendChild(el('option', { value: '', text: T.T26 }));
		visibleColumns().forEach(function (c) {
			var ex = colExample(c);
			sel.appendChild(el('option', { value: c.field, text: ex ? sprintf(T.T27b, colName(c), ex) : colName(c) }));
		});
		sel.value = S.map[f.key] || '';
		var eg = el('span', { class: 'cx-uvoz__eg' });
		var setEg = function () {
			var c = sel.value ? columnOf(sel.value) : null;
			var v = c ? colExample(c) : '';
			eg.textContent = v !== '' ? sprintf(T.T27, v) : '';
		};
		setEg();
		sel.addEventListener('change', function () {
			S.map[f.key] = sel.value;
			setEg();
			syncColumns();
		});
		var label = el('label', { for: id }, [
			f.label,
			f.req ? el('span', { class: 'cx-uvoz__req', text: ' · ' + (f.req === 'one' ? T.T28 : T.T28b) }) : null
		]);
		var kids = [label, sel, eg];
		if (f.key === 'vrsta' || f.key === 'sidrena' || f.key === 'datum') {
			kids.push(el('span', { class: 'cx-uvoz__under', 'data-under': f.key }));
		}
		return el('div', { class: 'cx-uvoz__field' }, kids);
	}

	/** The sentence under Type, Bazna cijena and Bazna cijena date while no column is chosen (SPEC-DUCAN §2.4, §2.7). */
	var underText = function (key) {
		if (S.map[key]) {
			return '';
		}
		var p = place();
		var heading = S.job && S.job.services_heading;
		if (key === 'vrsta') {
			if (heading) {
				return sprintf(isPlace() ? T.T94 : T.T94w, heading);
			}
			return isPlace() ? T.T31 : T.T32;
		}
		if (!isPlace()) {
			return ''; // the webshop's services wait without a bazna or a date (T47 in the preview)
		}
		var later = !!(p && p.later);
		if (key === 'sidrena') {
			// A later import with a Datum column: only new rows without a date take the price as their bazna (198 works per
			// row); new goods with a date and no bazna are not imported (T96c, Norman TEXTS-dodaci D1, Rook 4304).
			if (later) {
				return S.map.datum ? T.T96c : T.T96b;
			}
			return T.T96;
		}
		if (later) {
			return T.T97b;
		}
		// Without the shop's default date (an older server) T97 would end in ": ." : better nothing.
		return p && p.default_date ? endDot(sprintf(T.T97, fmtDate(p.default_date))) : '';
	};

	function syncColumns() {
		var next = $('[data-act="next"]', panel);
		var why = $('[data-why="next"]', panel);
		if (next) {
			next.disabled = !canGoOn() || S.reparsing;
		}
		if (why) {
			why.hidden = canGoOn();
		}
		['vrsta', 'sidrena', 'datum'].forEach(function (k) {
			var under = $('[data-under="' + k + '"]', panel);
			if (under) {
				under.textContent = underText(k);
			}
		});
		// The frame's column heads name our field mapped to each column, at once (SPEC-DUCAN §2.2 SHOULD).
		$$('[data-col-field]', panel).forEach(function (th) {
			var f = th.getAttribute('data-col-field');
			var to = FIELDS.concat(MORE).filter(function (x) {
				return S.map[x.key] === f;
			}).map(function (x) {
				return x.label;
			});
			$('.cx-uvoz__mapped', th).textContent = to.join(', ');
		});
	}

	function columnsStep() {
		var map = el('div', { class: 'cx-uvoz__map' }, FIELDS.map(fieldRow));
		var more = el('details', { class: 'cx-uvoz__more' }, [
			el('summary', { text: T.T29 }),
			el('div', { class: 'cx-uvoz__map' }, MORE.map(fieldRow))
		]);
		if (MORE.some(function (f) { return S.map[f.key]; })) {
			more.open = true;
		}
		var back = el('button', { type: 'button', class: 'button', text: T.T35 });
		back.addEventListener('click', function () {
			reset('file');
		});
		var next = el('button', { type: 'button', class: 'button button-primary', 'data-act': 'next', text: T.T33 });
		next.addEventListener('click', function () {
			// Not while a parse with another header row runs: its answer brings the token the run must use (race 1).
			if (canGoOn() && !S.reparsing) {
				read();
			}
		});
		var sheets = S.job.sheets;
		var out = [
			el('h3', { tabindex: '-1', 'data-focus': '1', text: T.T24 }),
			el('p', { class: 'description', 'data-state': 'sentence', text: columnsSentence() }),
			el('p', { class: 'cx-ui__muted', 'data-state': 'rows' }, [
				sprintf(T.T36, S.job.filename, num(S.job.total)),
				sheets && num(sheets.count) > 1 ? ' ' + sprintf(T.T99, sheets.first || '') : null
			]),
			fileStart(),
			map,
			more,
			errLine(),
			el('div', { class: 'cx-ui__foot' }, [back, el('span', { class: 'cx-ui__sp' }), el('span', { class: 'cx-ui__muted', 'data-why': 'next', text: T.T34 }), next])
		];
		setTimeout(syncColumns, 0);
		return out;
	}

	/** Exactly one sentence under the heading (SPEC-DUCAN §2.1): remembered and the same shape (T25z), remembered and
	 *  another shape (T25r), something proposed (T25), nothing proposed (T25o, NALAZI 1). */
	var columnsSentence = function () {
		var saved = S.job && S.job.saved;
		if (saved === 'applied') {
			return T.T25z;
		}
		if (saved === 'different') {
			return T.T25r;
		}
		return S.guessedAny ? T.T25 : T.T25o;
	};

	/** The header row in effect: the one asked for while its parse runs, else the server's (1 for an older server). */
	var headerRow = function () {
		if (S.reparsing && S.pendingRow !== null && S.pendingRow !== undefined) {
			return num(S.pendingRow);
		}
		var h = S.job && S.job.header;
		return h ? num(h.row) : 1;
	};

	/** "Start of the file" (SPEC-DUCAN §2.2): the header-row choice and the file's first rows as they are, each with
	 *  its state in words. Only with the contract's head_rows (an older server draws nothing). */
	function fileStart() {
		var j = S.job;
		if (!j || !Array.isArray(j.head_rows)) {
			return null;
		}
		var row = headerRow();
		var cols = Array.isArray(j.columns) ? j.columns : [];

		// The choice: "in row N: first cells" for the rows the server offers, the current one always among them
		// (Argus hdr12), and "the file has none" last.
		var sel = el('select', { id: 'cx-uvoz-hdr', disabled: S.reparsing });
		var opts = (j.header_options || []).slice();
		var cur = num(j.header && j.header.row);
		if (cur > 0 && !opts.some(function (o) { return num(o.row) === cur; })) {
			var hr = j.head_rows.filter(function (r) { return num(r.row) === cur; })[0];
			opts.push({ row: cur, cells: hr ? hr.cells.filter(function (c) { return String(c).trim() !== ''; }).slice(0, 3) : [] });
			opts.sort(function (a, b) { return num(a.row) - num(b.row); });
		}
		opts.forEach(function (o) {
			sel.appendChild(el('option', { value: String(num(o.row)), text: cut(sprintf(T.T90a, num(o.row), (o.cells || []).join(' · ')), 60) }));
		});
		sel.appendChild(el('option', { value: '0', text: T.T90b }));
		sel.value = String(row);
		sel.addEventListener('change', function () {
			reparse(num(sel.value));
		});
		var choose = el('p', { class: 'cx-uvoz__hdr' }, [
			el('label', { for: 'cx-uvoz-hdr', text: T.T90 }),
			' ',
			sel,
			S.reparsing ? el('span', { class: 'cx-ui__muted', 'data-state': 'reparsing', text: ' ' + T.reading }) : null
		]);

		// The table: row number, the shown columns (empty ones hidden), the row's state as text.
		var shown = [];
		cols.forEach(function (c, i) {
			if (c && !c.empty) {
				shown.push(i);
			}
		});
		var rows = j.head_rows.slice();
		// At most 9 rows: 4 above the names when they are low in the file (SPEC-DUCAN §2.2); the server cuts the same.
		if (rows.length > 9 && row > 0) {
			rows = rows.filter(function (r) { return num(r.row) >= row - 4 && num(r.row) <= row + 4; });
		}
		rows = rows.slice(0, 9);
		var stateOf = function (r) {
			var k = r.kind;
			if (k === 'above' || (k === 'empty' && row > 0 && num(r.row) < row)) {
				return ['above', T.T98a];
			}
			if (k === 'names') {
				return ['names', T.T98b];
			}
			if (k === 'section') {
				return ['section', T.T98c];
			}
			return ['data', ''];
		};
		var head = el('tr', {}, [el('th', { scope: 'col', class: 'cx-uvoz__rn' })].concat(shown.map(function (i) {
			var c = cols[i];
			return el('th', { scope: 'col', 'data-col-field': c.field }, [
				el('span', { class: 'cx-uvoz__cn', text: String(num(c.n)) }),
				el('span', { class: 'cx-uvoz__mapped' })
			]);
		}), [el('th', { scope: 'col', class: 'cx-uvoz__st' })]));
		var body = rows.map(function (r) {
			var st = stateOf(r);
			return el('tr', { class: 'cx-uvoz__r-' + st[0], 'data-row': String(num(r.row)) }, [
				el('th', { scope: 'row', class: 'cx-uvoz__rn', text: String(num(r.row)) })
			].concat(shown.map(function (i) {
				var v = r.cells && r.cells[i] !== undefined && r.cells[i] !== null ? String(r.cells[i]) : '';
				return el('td', { title: v || null, text: v });
			}), [el('td', { class: 'cx-uvoz__st', text: st[1] })]));
		});
		var table = el('table', { class: 'cx-uvoz__start' }, [
			el('caption', { text: T.T98 }),
			el('thead', {}, [head]),
			el('tbody', {}, body)
		]);
		return el('div', { class: 'cx-uvoz__file' }, [
			choose,
			row >= 2 ? el('p', { class: 'cx-ui__muted', 'data-state': 'above', text: T.T91 }) : null,
			el('div', { class: 'cx-uvoz__frame' }, [table])
		]);
	}

	// ---------- reading (the bar between Columns and Preview, SPEC §4.3) ----------

	function readingStep() {
		var pct = S.reading ? S.reading.percent : 0;
		var cancel = el('button', { type: 'button', class: 'button', text: T.cancel });
		cancel.addEventListener('click', function () {
			S.seq++;
			setStep('columns');
		});
		return [
			el('h3', { tabindex: '-1', 'data-focus': '1', text: T.reading }),
			el('div', { class: 'cx-ui__progress', role: 'progressbar', 'aria-valuemin': '0', 'aria-valuemax': '100', 'aria-valuenow': String(Math.round(pct)) }, [
				el('i', { style: 'width:' + Math.max(0, Math.min(100, pct)) + '%' })
			]),
			el('p', { class: 'cx-ui__muted', 'data-count': '1', text: S.reading ? sprintf(T.T57, num(S.reading.offset), num(S.reading.total)) : '' }),
			S.retrying ? el('p', { class: 'cx-ui__muted', text: T.retry }) : null,
			el('p', { class: 'description', text: T.T59 }),
			errLine(),
			el('div', { class: 'cx-ui__foot' }, [cancel])
		];
	}

	async function read() {
		var mine = ++S.seq;
		S.reading = { offset: 0, total: num(S.job.total), percent: 0 };
		S.retrying = false;
		S.error = '';
		setStep('reading');
		var offset = 0;
		var map = {};
		ALL_KEYS.forEach(function (k) {
			map[k] = S.map[k] || '';
		});
		for (;;) {
			var res;
			try {
				res = await postRetry('sidrena_cijena_import_run', { token: S.job.token, offset: offset, cilj: S.cilj, map: map }, function () {
					S.retrying = true;
					render();
				});
			} catch (e) {
				res = { ok: false, data: {} };
			}
			if (mine !== S.seq) {
				return; // cancelled, or another file
			}
			S.retrying = false;
			if (!res.ok) {
				var code = res.data && res.data.code;
				showError(code === 'isti_cjenik' ? T.T19 : (/place/.test(code || '') ? T.T52b : T.error), 'columns');
				setStep('columns');
				return;
			}
			S.reading = { offset: num(res.data.offset), total: num(res.data.total), percent: Number(res.data.percent) || 0 };
			if (res.data.done) {
				S.run = res.data;
				S.list = firstList();
				S.page = 1;
				setStep('preview');
				loadList();
				return;
			}
			offset = num(res.data.offset);
			render();
		}
	}

	// ---------- step 3: Preview ----------

	var firstList = function () {
		var c = counts();
		for (var i = 0; i < TILES.length; i++) {
			if (num(c[TILES[i][0]])) {
				return TILES[i][0];
			}
		}
		return 'new';
	};

	/** "Group headings (N) are not items…: a, b, c, d, e, …" (T93): at most 5 names (SPEC-DUCAN §3.1). */
	var sectionsLine = function () {
		var sc = S.run && S.run.sections;
		if (!sc || !num(sc.count)) {
			return null;
		}
		var names = (sc.names || []).slice(0, 5);
		var list = names.join(', ') + (num(sc.count) > names.length ? ', …' : '');
		return el('p', { class: 'cx-ui__muted', 'data-state': 'sections', text: sprintf(T.T93, num(sc.count), list) });
	};

	function previewStep() {
		var c = counts();
		var sw = S.run.place_switch;
		var tiles = el('div', { class: 'cx-status__tiles', role: 'group', 'aria-label': T.T15 }, TILES.filter(tileShown).map(function (t) {
			var b = el('button', { type: 'button', class: 'cx-status__tile cx-uvoz__count', 'data-list': t[0], 'aria-pressed': S.list === t[0] ? 'true' : 'false' }, [
				el('span', { class: 'cx-status__num', text: String(num(c[t[0]])) }),
				el('span', { class: 'cx-status__desc', text: t[1] })
			]);
			b.addEventListener('click', function () {
				S.list = t[0];
				S.page = 1;
				$$('.cx-uvoz__count', panel).forEach(function (x) {
					x.setAttribute('aria-pressed', x === b ? 'true' : 'false');
				});
				loadList();
			});
			return b;
		}));
		var back = el('button', { type: 'button', class: 'button', text: T.T35 });
		back.addEventListener('click', function () {
			S.seq++;
			setStep('columns');
		});
		var next = el('button', { type: 'button', class: 'button button-primary', text: T.T33, disabled: !entering() });
		next.addEventListener('click', function () {
			setStep('confirm');
		});
		return [
			el('h3', { tabindex: '-1', 'data-focus': '1', text: T.T15 }),
			el('p', { class: 'cx-ui__muted' }, [
				sprintf(T.T36, S.job.filename, num(S.job.total)),
				' · ',
				sprintf(T.T30, num(S.run.goods), num(S.run.services))
			]),
			sectionsLine(),
			num(S.run.returned_earlier) ? el('p', { class: 'cx-ui__muted', 'data-state': 'returned', text: sprintf(T.T89a, num(S.run.returned_earlier)) }) : null,
			sw ? el('p', { class: 'cx-ui__warn', 'data-state': 'switch', text: hasWebRows(sw) ? sprintf(T.T80p, num(sw.web_rows)) : T.T80pn }) : null,
			// SKUs like existing ones: said before any click, as their number may be the whole file (SPEC-DUCAN §3.3).
			num(c.like_code) ? el('p', { class: 'cx-ui__warn', 'data-state': 'like_code', text: sprintf(T.T95b, num(c.like_code)) }) : null,
			tiles,
			el('p', { class: 'cx-ui__muted', 'data-state': 'list-note', hidden: true }),
			el('table', { class: 'cx-ui__table cx-uvoz__table' }, [el('thead'), el('tbody')]),
			el('p', { class: 'cx-uvoz__pages' }),
			entering() ? null : el('p', { class: 'cx-ui__muted', text: T.T49 }),
			el('div', { class: 'cx-ui__foot' }, [back, el('span', { class: 'cx-ui__sp' }), next])
		];
	}

	async function loadList() {
		var mine = S.seq;
		var list = S.list;
		var page = S.page;
		var res = await post('sidrena_cijena_import_list', { token: S.job.token, list: list, page: page }).catch(function () {
			return { ok: false, data: {} };
		});
		if (mine !== S.seq || S.step !== 'preview' || list !== S.list || page !== S.page) {
			return;
		}
		var table = $('.cx-uvoz__table', panel);
		var pages = $('.cx-uvoz__pages', panel);
		var note = $('[data-state="list-note"]', panel);
		if (!table) {
			return;
		}
		if (note) {
			// Above the list of "New, dated today": why these items get today's date (T88a, SPEC-DUCAN §3.2).
			note.hidden = list !== 'od_danas';
			note.textContent = list === 'od_danas' ? sprintf(T.T88a, fmtDate(serverToday())) : '';
		}
		var head = $('thead', table);
		var body = $('tbody', table);
		head.textContent = '';
		body.textContent = '';
		pages.textContent = '';
		if (!res.ok) {
			body.appendChild(el('tr', {}, [el('td', { text: T.error })]));
			return;
		}
		var why = list === 'waits' || list === 'skipped' || list === 'removed' || list === 'like_code';
		head.appendChild(el('tr', {}, [T.cLine, T.cName, T.cSku, T.cType, T.T40b, T.cBazna, T.cDate, why ? T.cWhy : null].filter(Boolean).map(function (h) {
			return el('th', { scope: 'col', text: h });
		})));
		(res.data.rows || []).forEach(function (r) {
			// A row that is not in the file (Will be removed) has line 0: its Line cell is empty (SPEC §4.4).
			var line = num(r.line) > 0 ? String(num(r.line)) : '';
			var cells = [
				el('td', { text: line }),
				el('td', { text: r.naziv || '' }),
				el('td', { class: 'cx-ui__mono cx-uvoz__nw', text: r.sifra || '' }),
				el('td', { class: 'cx-uvoz__nw', text: r.vrsta === 'usluga' ? T.T45 : (r.vrsta === 'roba' ? T.T44 : '') }),
				el('td', { class: 'cx-uvoz__nw', text: change(pair(r, 'cijena'), fmtPrice) }),
				el('td', { class: 'cx-uvoz__nw', text: change(pair(r, 'bazna'), fmtPrice) }),
				el('td', { class: 'cx-uvoz__nw', text: dateCell(r.datum, r.returned) })
			];
			if (why) {
				cells.push(el('td', { text: reasonText(r.reason, list) }));
			}
			body.appendChild(el('tr', { 'data-line': line || null }, cells));
		});
		pager(pages, num(res.data.page) || page, num(res.data.pages), function (p) {
			S.page = p;
			loadList();
		});
		table.dataset.shown = list + ':' + page;
	}

	/** One field of a list row as {old, new}: the row carries old and new as whole rows (or null). */
	var pair = function (r, k) {
		return { old: r.old ? r.old[k] : null, new: r.new ? r.new[k] : null };
	};
	/** "old → new" for a value that changes, else the value (SPEC §4.4, the codeChange look). */
	var change = function (v, f) {
		if (!v || typeof v !== 'object') {
			return f(v);
		}
		var o = f(v.old);
		var n = f(v.new);
		return v.old !== null && v.old !== undefined && v.old !== '' && o !== n && n !== '' ? o + ' → ' + n : (n || o);
	};
	/** A date with its mark: the shop's default (T46), a returned item's earlier date (T89) or today's (T89d, 198).
	 *  returned is on the date (contract 198) or, from an older answer, on the row. */
	var dateCell = function (d, rowReturned) {
		if (!d) {
			return '';
		}
		if (typeof d !== 'object') {
			return fmtDate(d);
		}
		var ret = d.returned || rowReturned || null;
		if (ret === 'earlier') {
			return sprintf(T.T89, fmtDate(d.value));
		}
		if (ret === 'today') {
			return sprintf(T.T89d, fmtDate(d.value));
		}
		return d.default ? sprintf(T.T46, fmtDate(d.value)) : fmtDate(d.value);
	};

	function pager(holder, page, pages, go) {
		holder.textContent = '';
		if (pages <= 1) {
			return;
		}
		var prev = el('button', { type: 'button', class: 'button', text: T.prev, disabled: page <= 1 });
		var nxt = el('button', { type: 'button', class: 'button', text: T.next, disabled: page >= pages });
		prev.addEventListener('click', function () {
			go(page - 1);
		});
		nxt.addEventListener('click', function () {
			go(page + 1);
		});
		holder.appendChild(prev);
		holder.appendChild(el('span', { class: 'cx-ui__muted', text: ' ' + sprintf(T.page, page, pages) + ' ' }));
		holder.appendChild(nxt);
	}

	// ---------- step 4: Confirm ----------

	function confirmStep() {
		var c = counts();
		var sw = S.run.place_switch;
		var p = place();
		var by = el('input', { type: 'text', id: 'cx-uvoz-by', maxlength: '100', value: S.by || '' });
		var on = el('input', { type: 'date', id: 'cx-uvoz-on', value: S.on || todayYmd(), max: todayYmd() });
		var ok = el('input', { type: 'checkbox', id: 'cx-uvoz-consent' });
		ok.checked = !!S.consent;
		var go = el('button', { type: 'button', class: 'button button-primary', 'data-act': 'import' });
		var sync = function () {
			S.by = by.value;
			S.on = on.value;
			S.consent = ok.checked;
			go.disabled = S.busy || !(by.value.trim() && on.value && ok.checked);
			go.textContent = S.busy ? T.importing : sprintf(T.importN, entering());
		};
		[by, on, ok].forEach(function (x) {
			x.addEventListener('input', sync);
			x.addEventListener('change', sync);
		});
		go.addEventListener('click', confirm);
		var back = el('button', { type: 'button', class: 'button', text: T.T35, disabled: S.busy });
		back.addEventListener('click', function () {
			setStep('preview');
			loadList();
		});
		sync();
		return [
			el('h3', { tabindex: '-1', 'data-focus': '1', text: T.T16 }),
			el('p', { class: 'description', text: T.whoConfirmed }),
			el('p', {}, [el('label', { for: 'cx-uvoz-by', text: T.confirmedBy }), el('br'), by]),
			el('p', {}, [el('label', { for: 'cx-uvoz-on', text: T.cDate }), el('br'), on]),
			el('p', {}, [el('label', { class: 'cx-uvoz__consent' }, [ok, el('span', { text: S.run.consent || '' })])]),
			sw && p ? el('p', { class: 'cx-ui__warn', 'data-state': 'switch', text: hasWebRows(sw) ? sprintf(T.T80c, p.naziv || p.oznaka, num(sw.web_rows)) : sprintf(T.T80cn, p.naziv || p.oznaka) }) : null,
			num(c.removed) ? el('p', { class: 'cx-ui__warn', 'data-state': 'removed', text: sprintf(T.T48b, num(c.removed)) }) : null,
			// SKUs like existing ones while old SKUs go: confirming may leave the list without those items (T95c).
			num(c.like_code) && num(c.removed) ? el('p', { class: 'cx-ui__warn', 'data-state': 'like_code', text: sprintf(T.T95c, num(c.like_code)) }) : null,
			// The owner confirms by name knowing these items are dated today (T88b, SPEC-DUCAN §3.2).
			num(c.od_danas) ? el('p', { class: 'cx-ui__warn', 'data-state': 'od_danas', text: sprintf(T.T88b, num(c.od_danas), fmtDate(serverToday())) }) : null,
			errLine(),
			el('div', { class: 'cx-ui__foot' }, [back, el('span', { class: 'cx-ui__sp' }), go])
		];
	}

	async function confirm() {
		if (S.busy) {
			return;
		}
		if (!S.by || !S.by.trim()) {
			showError(T.noName);
			render();
			return;
		}
		if (!S.on) {
			showError(T.noDate);
			render();
			return;
		}
		if (!S.consent) {
			showError(T.noConsent);
			render();
			return;
		}
		S.busy = true;
		S.error = '';
		render();
		// The rows not imported, for Done (the job is gone after Confirm): Won't be imported and SKUs like existing ones
		// (contract 198: like_code counts in result.skipped), first page of each, in file order.
		S.skipped = { rows: [], total: 0 };
		var lists = ['skipped', 'like_code'].filter(function (l) {
			return num(counts()[l]);
		});
		for (var li = 0; li < lists.length; li++) {
			var sk = await post('sidrena_cijena_import_list', { token: S.job.token, list: lists[li], page: 1 }).catch(function () {
				return { ok: false, data: {} };
			});
			if (sk.ok) {
				S.skipped.rows = S.skipped.rows.concat(sk.data.rows || []);
				S.skipped.total += num(sk.data.total);
			}
		}
		S.skipped.rows.sort(function (a, b) {
			return num(a.line) - num(b.line);
		});
		S.skipped.rows = S.skipped.rows.slice(0, 50);
		var res = await post('sidrena_cijena_import_confirm', {
			token: S.job.token,
			consent: '1',
			confirmed_by: S.by.trim(),
			confirmed_on: S.on
		}).catch(function () {
			return { ok: false, data: {} };
		});
		S.busy = false;
		// A second click on a confirmation already written (a double click, a slow answer): the server answers with
		// the first confirmation's outcome, and nothing is written again (CX-SID-181 C181-confirm-twice).
		if (!res.ok && res.data && res.data.code === 'already_confirmed' && res.data.import_id) {
			res = { ok: true, data: res.data };
		}
		if (!res.ok) {
			var code = res.data && res.data.code;
			if (code === 'changed_since_preview' || code === 'place_changed_since_preview' || code === 'db' || code === 'isti_cjenik') {
				// Nothing was written and the job is spent: the file goes again from the start (SPEC §4.5, T52 / T52b;
				// a database error T52c, Norman TEXTS-dodaci; the same price list for all places switched on after the
				// preview, CX-SID-113: T19 as on the run, Rook 22.11.).
				var text = code === 'changed_since_preview' ? T.T52 : (code === 'db' ? T.T52c : (code === 'isti_cjenik' ? T.T19 : T.T52b));
				reset('file');
				showError(text, 'file');
				render();
				return;
			}
			var msg = {
				nothing: T.T49,
				no_name: T.noName,
				no_consent: T.noConsent,
				bad_date: T.noDate
			}[code] || (code === 'expired' && res.data.message ? res.data.message : T.error);
			showError(msg);
			render();
			return;
		}
		S.result = res.data;
		takeTargets(res.data && res.data.targets);
		S.by = '';
		S.consent = false;
		setStep('done');
		loadHistory(1);
	}

	// ---------- Done ----------

	function doneStep() {
		var r = S.result || {};
		var w = r.written || {};
		var p = place();
		var goods = num(w.goods);
		var services = num(w.services);
		var line = isPlace()
			? sprintf(T.T53, p ? p.naziv || p.oznaka : S.cilj, goods + services, goods, services)
			: sprintf(T.T54, services);
		var extra = [];
		if (num(r.od_danas)) {
			extra.push(el('p', { 'data-state': 'od_danas', text: sprintf(T.T88c, num(r.od_danas)) }));
		}
		if (r.place_switched && p) {
			extra.push(el('p', { 'data-state': 'switched', text: sprintf(T.T53c, p.naziv || p.oznaka) }));
		}
		if (num(r.removed)) {
			extra.push(el('p', { 'data-state': 'removed', text: sprintf(T.T53b, num(r.removed)) }));
		}
		if (num(r.waits)) {
			extra.push(el('p', { class: 'cx-ui__warn', 'data-state': 'waits', text: sprintf(T.T55, num(r.waits)) }));
		}
		if (num(r.skipped)) {
			// The rows not imported, each with its line and reason as in the preview (kept before Confirm: the job is
			// gone after it). At most one page (50); the preview had them all.
			var sk = S.skipped || { rows: [], total: 0 };
			var items = sk.rows.map(function (x) {
				return el('li', { text: sprintf(T.lineN, x.line) + (x.naziv ? ' (' + x.naziv + ')' : '') + ': ' + reasonText(x.reason, 'skipped') });
			});
			if (sk.total > sk.rows.length) {
				items.push(el('li', { text: '…' }));
			}
			var ul = el('ul', {}, items);
			extra.push(el('div', { 'data-state': 'skipped' }, [el('p', { text: sprintf(T.notImported, num(r.skipped)) }), ul]));
		}
		var again = el('button', { type: 'button', class: 'button', text: T.again });
		again.addEventListener('click', function () {
			reset('file');
		});
		return [
			el('div', { class: 'cx-ui__done', tabindex: '-1', 'data-focus': '1' }, [
				el('span', { class: 'cx-ui__st', 'aria-hidden': 'true', text: '✓' }),
				el('p', { text: line })
			]),
			el('div', {}, extra),
			el('p', { class: 'cx-ui__muted', text: T.T56 }),
			el('div', { class: 'cx-ui__foot' }, [
				r.url ? el('a', { class: 'button button-primary', href: r.url, target: '_blank', rel: 'noopener noreferrer', text: T.openList }) : null,
				again
			])
		];
	}

	// ---------- draw ----------

	function render() {
		if (!panel) {
			return;
		}
		panel.textContent = '';
		panel.appendChild(targetCard());
		var body;
		switch (S.step) {
			case 'columns':
				body = columnsStep();
				break;
			case 'reading':
				body = readingStep();
				break;
			case 'preview':
				body = previewStep();
				break;
			case 'confirm':
				body = confirmStep();
				break;
			case 'done':
				body = doneStep();
				break;
			default:
				body = fileStep();
		}
		panel.appendChild(el('section', { class: 'cx-ui__card cx-uvoz__flow', 'data-step': S.step }, [stepsBar()].concat(body)));
	}

	// --------------------------------------------------------------------------------------------------------------
	// The top choice (SPEC §1): Bazna cijene of products | Price list from a file
	// --------------------------------------------------------------------------------------------------------------

	var radios = $$('input[name="cx-uvoz-choice"]', root);
	var current = function () {
		var r = radios.filter(function (x) {
			return x.checked;
		})[0];
		return r ? r.value : 'bazne';
	};
	var chosen = current();
	/** The bazna import is past its File step when its Preview is open. */
	var baznePast = function () {
		var pv = $('.cx-import__step[data-step="preview"]', root);
		return !!pv && !pv.hidden;
	};
	var showChoice = function (v) {
		chosen = v;
		root.dataset.choice = v;
		$$('[data-panel]', root).forEach(function (p) {
			p.hidden = p.dataset.panel !== v;
		});
		$$('[data-hint]', root).forEach(function (h) {
			h.hidden = h.dataset.hint !== v;
		});
	};
	radios.forEach(function (r) {
		r.addEventListener('change', function () {
			if (!r.checked || r.value === chosen) {
				return;
			}
			var busy = chosen === 'cjenik' ? past() : baznePast();
			if (busy && !window.confirm(T.T12)) {
				radios.forEach(function (x) {
					x.checked = x.value === chosen;
				});
				return;
			}
			if (chosen === 'cjenik') {
				reset('file');
			}
			showChoice(r.value);
		});
	});
	// While a bazna import runs in the background, the choice is locked and its progress shows (SPEC §1).
	var lock = function () {
		var running = root.classList.contains('is-running');
		radios.forEach(function (x) {
			x.disabled = running;
		});
		if (running && chosen !== 'bazne') {
			radios.forEach(function (x) {
				x.checked = x.value === 'bazne';
			});
			showChoice('bazne');
		}
	};
	new MutationObserver(lock).observe(root, { attributes: true, attributeFilter: ['class'] });
	lock();

	// --------------------------------------------------------------------------------------------------------------
	// History: every kind of import, newest first (SPEC §1.4, T60–T62b)
	// --------------------------------------------------------------------------------------------------------------

	var histSeq = 0;
	async function loadHistory(page) {
		var mine = ++histSeq;
		var res = await post('sidrena_cijena_import_history', { page: page || 1 }).catch(function () {
			return { ok: false, data: {} };
		});
		if (mine !== histSeq) {
			return;
		}
		var body = $('.cx-import__history-table tbody', root);
		if (!body) {
			return;
		}
		body.textContent = '';
		if (!res.ok) {
			return;
		}
		var rows = res.data.rows || [];
		if (!rows.length) {
			body.appendChild(el('tr', {}, [el('td', { colspan: '7', text: T.noHistory })]));
		}
		rows.forEach(function (r) {
			var tg = r.target || {};
			var what = r.kind === 'mjesto'
				? sprintf(T.T62a, tg.naziv ? sprintf(T.T8, tg.naziv, tg.oznaka) : tg.oznaka || '')
				: (r.kind === 'usluge' ? T.T62b : T.T61);
			var count = r.finished === false ? T.inProgress : String(r.updated !== undefined ? num(r.updated)
				: (r.written && typeof r.written === 'object' ? num(r.written.goods) + num(r.written.services) : num(r.written)));
			body.appendChild(el('tr', { 'data-kind': r.kind || 'import' }, [
				el('td', { text: r.at || '' }),
				el('td', { text: what }),
				el('td', { text: r.user || '' }),
				el('td', { text: (r.confirmed_by || '') + (r.confirmed_on ? ', ' + fmtDate(r.confirmed_on) : '') }),
				el('td', { text: count }),
				el('td', { text: r.file || '' }),
				el('td', {}, [el('code', { title: r.sha256 || '', text: r.short || '' })])
			]));
		});
		pager($('.cx-import__history-pages', root), page || 1, num(res.data.pages), loadHistory);
		body.dataset.loaded = String(num(body.dataset.loaded) + 1);
	}
	document.addEventListener('cx-import:history', function (e) {
		loadHistory((e.detail && e.detail.page) || 1);
	});

	// --------------------------------------------------------------------------------------------------------------
	// Start
	// --------------------------------------------------------------------------------------------------------------

	$$('.cx-ui__drop', root).forEach(bindDrop);
	if (C.hasTargets) {
		render();
	} else {
		// Without the price-list import (an older bulk import) only Bazna cijene of products is offered.
		radios.forEach(function (x) {
			if (x.value === 'cjenik') {
				x.disabled = true;
			}
		});
		showChoice('bazne');
	}
	loadHistory(1);
})();
