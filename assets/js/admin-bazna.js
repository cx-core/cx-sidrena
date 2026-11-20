/**
 * CX Sidrena owner screens (CX-SID-42): Settings > Dates, the Status fixes ("Use regular price", "Fix all", "Keep
 * earlier") and the Import tab. The screen never writes bazna itself: it calls the model's endpoints (the Status fill,
 * IMPORT-API.md) and shows what they answer. Texts come translated from PHP (cxBazna.t).
 */
(function () {
	'use strict';

	var C = window.cxBazna;
	if (!C) {
		return;
	}
	var T = C.t;
	/** printf for the texts from PHP: %s, %d and %1$s; nothing else is used. */
	var sprintf = function (fmt) {
		var args = Array.prototype.slice.call(arguments, 1);
		var i = 0;
		return String(fmt).replace(/%(\d+\$)?([sd])/g, function (m, pos) {
			var v = pos ? args[parseInt(pos, 10) - 1] : args[i++];
			return String(v === undefined ? '' : v);
		});
	};
	var $ = function (sel, root) {
		return (root || document).querySelector(sel);
	};
	var $$ = function (sel, root) {
		return Array.prototype.slice.call((root || document).querySelectorAll(sel));
	};
	var sleep = function (ms) {
		return new Promise(function (r) {
			setTimeout(r, ms);
		});
	};

	/** POST to admin-ajax; resolves with { ok, data } (data = the JSON's data), rejects on a network failure. */
	function post(action, fields, nonce) {
		var body = fields instanceof FormData ? fields : new FormData();
		if (!(fields instanceof FormData)) {
			Object.keys(fields || {}).forEach(function (k) {
				body.append(k, fields[k]);
			});
		}
		body.append('action', action);
		body.append('nonce', nonce);
		return fetch(C.ajaxUrl, { method: 'POST', body: body, credentials: 'same-origin' }).then(function (r) {
			return r.json().catch(function () {
				return { success: false, data: { code: 'bad_response', message: T.error } };
			});
		}).then(function (j) {
			return { ok: !!(j && j.success), data: (j && j.data) || {} };
		});
	}

	/** A request that may drop: tried again a few times (the model makes each row idempotent). */
	async function postRetry(action, fields, nonce, onRetry) {
		for (var i = 0; ; i++) {
			try {
				return await post(action, fields, nonce);
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

	var fmtDate = function (d) {
		var m = /^(\d{1,2})\.(\d{1,2})\.(\d{4})\.?$/.exec(String(d || '').trim());
		return m ? parseInt(m[1], 10) + '.' + parseInt(m[2], 10) + '.' + m[3] + '.' : String(d || '');
	};
	var fmtYmd = function (ymd) {
		var m = /^(\d{4})-(\d{2})-(\d{2})$/.exec(ymd || '');
		return m ? parseInt(m[3], 10) + '.' + parseInt(m[2], 10) + '.' + m[1] + '.' : String(ymd || '');
	};
	var fmtPrice = function (a) {
		return a === null || a === undefined || a === '' ? '' : C.priceFormat.replace('%s', String(a).replace('.', C.decimal));
	};
	var el = function (tag, attrs, kids) {
		var e = document.createElement(tag);
		Object.keys(attrs || {}).forEach(function (k) {
			if (k === 'text') {
				e.textContent = attrs[k];
			} else if (k === 'class') {
				e.className = attrs[k];
			} else {
				e.setAttribute(k, attrs[k]);
			}
		});
		(kids || []).forEach(function (k) {
			if (k !== null && k !== undefined) {
				e.appendChild(typeof k === 'string' ? document.createTextNode(k) : k);
			}
		});
		return e;
	};

	// ---------------------------------------------------------------------------------------------------------------
	// Settings > Dates: a subcategory follows its parent until it is changed
	// ---------------------------------------------------------------------------------------------------------------

	function initDates() {
		var table = $('.cx-dates__table');
		if (!table) {
			return;
		}
		var rows = $$('.cx-dates__row', table);
		var follow = function (term, value) {
			rows.forEach(function (r) {
				if (r.dataset.parent === term && r.dataset.set === '0') {
					var radio = $('input[value="' + value + '"]', r);
					if (radio) {
						radio.checked = true;
					}
					follow(r.dataset.term, value);
				}
			});
		};
		table.addEventListener('change', function (e) {
			var row = e.target.closest('.cx-dates__row');
			if (!row || e.target.type !== 'radio') {
				return;
			}
			if (row.dataset.parent) {
				row.dataset.set = '1';
				var hint = $('.cx-dates__follows', row);
				if (hint) {
					hint.hidden = true;
				}
			}
			follow(row.dataset.term, e.target.value);
		});
	}

	// ---------------------------------------------------------------------------------------------------------------
	// Status: Use regular price, Fix all (one dialog), Keep earlier, the progress bar
	// ---------------------------------------------------------------------------------------------------------------

	function initStatus() {
		var box = $('#cx-status');
		var dialog = $('#cx-fill-dialog');
		if (!box || !dialog) {
			return;
		}
		var notice = $('#cx-fill-notice');
		var err = $('.cx-dialog__error', dialog);
		var pending = null;

		var say = function (text, kind) {
			notice.className = 'notice inline cx-fill-notice notice-' + (kind || 'success');
			$('p', notice).textContent = text;
			notice.hidden = !text;
		};
		// Keyboard users continue where the result is said, not at the top of the page (Norman, CX-SID-42).
		var focusNotice = function () {
			if (!notice.hidden) {
				notice.setAttribute('tabindex', '-1');
				notice.focus();
			}
		};
		var refresh = async function () {
			var r = await fetch(window.location.href.split('#')[0], { credentials: 'same-origin' });
			var doc = new DOMParser().parseFromString(await r.text(), 'text/html');
			var fresh = doc.getElementById('cx-status');
			if (fresh) {
				box.innerHTML = fresh.innerHTML;
			}
			box.dataset.refreshed = String(parseInt(box.dataset.refreshed || '0', 10) + 1);
		};
		var showErr = function (text) {
			err.textContent = text;
			err.hidden = !text;
		};

		var poll = async function (job) {
			var bar = $('#cx-fill-progress');
			bar.hidden = false;
			bar.dataset.job = job;
			$$('.cx-fix-one, .cx-fix-all').forEach(function (b) {
				b.disabled = true;
			});
			for (;;) {
				var res;
				try {
					res = await postRetry('cx_sidrena_status_progress', { job: job }, C.statusNonce, function () {
						say(T.retry, 'warning');
					});
				} catch (e) {
					say(T.error, 'error');
					return;
				}
				if (!res.ok) {
					say(res.data.message || T.error, 'error');
					return;
				}
				var d = res.data;
				$('progress', bar).max = Math.max(1, d.total);
				$('progress', bar).value = d.processed;
				$('.cx-fill-progress__count', bar).textContent = d.processed + ' / ' + d.total;
				if (d.finished) {
					await refresh();
					say(d.message || '');
					focusNotice();
					return;
				}
				await sleep(1500);
			}
		};

		var open = function (btn, ids, all) {
			pending = { ids: ids, all: all, row: btn.closest('tr') };
			// One row: the product and the price that will be written; Fix all: how many.
			$('.cx-dialog__count', dialog).textContent = btn.dataset.label || sprintf(T.count, parseInt(btn.dataset.count || '1', 10));
			$('#cx-fill-consent', dialog).checked = false;
			showErr('');
			dialog.showModal();
			$('#cx-fill-by', dialog).focus();
		};

		box.addEventListener('click', async function (e) {
			var one = e.target.closest('.cx-fix-one');
			var all = e.target.closest('.cx-fix-all');
			var keep = e.target.closest('.cx-keep-earlier');
			if (one && !one.disabled) {
				open(one, [one.dataset.id], false);
			} else if (all && !all.disabled) {
				open(all, [], true);
			} else if (keep && !keep.disabled) {
				keep.disabled = true;
				var res = await post('cx_sidrena_two_cat_ok', { id: keep.dataset.id }, C.statusNonce).catch(function () {
					return { ok: false, data: {} };
				});
				if (!res.ok) {
					keep.disabled = false;
					say(res.data.message || T.error, 'error');
					return;
				}
				keep.closest('tr').remove();
				await refresh();
				// The notice of an earlier fix no longer belongs here; keyboard users go on from the list.
				say('');
				var head = $('#cx-attention');
				if (head) {
					head.setAttribute('tabindex', '-1');
					head.focus();
				}
			}
		});

		$('.cx-dialog__cancel', dialog).addEventListener('click', function () {
			dialog.close();
		});

		$('form', dialog).addEventListener('submit', async function (e) {
			e.preventDefault();
			var name = $('#cx-fill-by', dialog).value.trim();
			if (!name) {
				showErr(T.noName);
				$('#cx-fill-by', dialog).focus();
				return;
			}
			if (!$('#cx-fill-consent', dialog).checked) {
				showErr(T.noConsent);
				return;
			}
			var ok = $('.cx-dialog__ok', dialog);
			ok.disabled = true;
			showErr('');
			var res;
			try {
				res = await post('cx_sidrena_status_fill', {
					ids: pending.ids.join(','),
					all: pending.all ? '1' : '0',
					confirmed_by: name,
					consent: '1'
				}, C.statusNonce);
			} catch (x) {
				res = { ok: false, data: { message: T.error } };
			}
			ok.disabled = false;
			if (!res.ok) {
				showErr(res.data.message || T.error);
				return;
			}
			dialog.close();
			if (res.data.finished) {
				if (pending.row) {
					pending.row.remove();
				}
				await refresh();
				say(res.data.message || '');
				focusNotice();
			} else {
				poll(res.data.job);
			}
		});

		var running = $('#cx-fill-progress');
		if (running && running.dataset.job) {
			poll(running.dataset.job);
		}
	}

	// ---------------------------------------------------------------------------------------------------------------
	// Import: Upload → Preview → Confirm → progress → Done, and History (CX_Sidrena_Import, CX-SID-22)
	// ---------------------------------------------------------------------------------------------------------------

	function initImport() {
		var root = $('#cx-import');
		if (!root) {
			return;
		}
		var S = {};
		/** The import being followed: { id, who, mode, stop, inflight }. mode: 'own' (confirmed on this page), 'found'
		 *  (was running when the page opened), 'other' (another import refused this page's Confirm). who: the model's
		 *  running shape (who confirmed, when it started), kept for Continue and for the Stop question. */
		var RUN = null;
		var step = function (name) {
			return $('.cx-import__step[data-step="' + name + '"]', root);
		};
		var running = step('running');
		var runHead = $('.cx-import__running-head', running);
		var ownHead = runHead.textContent;
		var note = $('.cx-import__note', running);
		var showNote = function (text) {
			note.textContent = text || '';
			note.hidden = !text;
		};
		var showError = function (section, text, link) {
			var e = $('.cx-import__error', section);
			e.textContent = text || '';
			if (text && link) {
				e.appendChild(document.createTextNode(' '));
				e.appendChild(el('a', { href: link.href, text: link.text }));
			}
			e.hidden = !text;
		};
		var showInfo = function (text) {
			var i = $('.cx-import__info', step('confirm'));
			i.textContent = text || '';
			i.hidden = !text;
		};
		var show = function (names) {
			$$('.cx-import__step', root).forEach(function (s) {
				s.hidden = names.indexOf(s.dataset.step) < 0;
			});
		};
		var waits = function (row) {
			return !S.datesSaved && row.new && (row.new.date_from === 'category' || row.new.date_from === 'day_added');
		};
		var dateCell = function (v) {
			if (!v) {
				return T.none;
			}
			var suffix = v.date_from === 'category' ? ' ' + T.fromCategory : (v.date_from === 'day_added' ? ' ' + T.dayAdded : '');
			return fmtPrice(v.bazna) + ' · ' + fmtDate(v.date) + suffix;
		};
		// CX-SID-122, Norman T4: one line per brand, EAN or barcode the price list then shows differently.
		var codeLines = function (list, fmt) {
			return el('td', { class: 'cx-import__codes' }, (list || []).map(function (c) {
				var label = (T.codeLabels || {})[c[0]] || c[0];
				var text = fmt === 'kept'
					? sprintf(T.codeKept, label, c[1])
					: sprintf(T.codeChange, label, c[1] === '' ? T.none : c[1], c[2] === '' ? T.none : c[2]);
				return el('div', { text: text });
			}));
		};
		// The file's lowest price in 30 days (K9): now → new; a row without it in the file shows nothing.
		var lowestCell = function (l) {
			if (!l) {
				return '';
			}
			return (l.now === null || l.now === undefined || l.now === '' ? T.none : fmtPrice(l.now)) + ' → ' + fmtPrice(l.new);
		};
		var pager = function (holder, page, pages, go) {
			holder.textContent = '';
			if (pages <= 1) {
				return;
			}
			var prev = el('button', { type: 'button', class: 'button', text: T.prev });
			var next = el('button', { type: 'button', class: 'button', text: T.next });
			prev.disabled = page <= 1;
			next.disabled = page >= pages;
			prev.addEventListener('click', function () {
				go(page - 1);
			});
			next.addEventListener('click', function () {
				go(page + 1);
			});
			holder.appendChild(prev);
			holder.appendChild(el('span', { class: 'cx-import__page', text: ' ' + sprintf(T.page, page, pages) + ' ' }));
			holder.appendChild(next);
		};

		var listSeq = 0;
		var loadList = async function (list, page) {
			var seq = ++listSeq;
			S.list = list;
			$$('.cx-import__count', root).forEach(function (b) {
				b.setAttribute('aria-pressed', b.dataset.list === list ? 'true' : 'false');
				b.classList.toggle('is-active', b.dataset.list === list);
			});
			var res = await post('cx_sidrena_import_preview', { job: S.job, list: list, page: page }, C.importNonce).catch(function () {
				return { ok: false, data: { message: T.error } };
			});
			if (seq !== listSeq) {
				return; // another tile was clicked meanwhile: its answer draws the table
			}
			var table = $('.cx-import__table', root);
			var head = $('thead', table);
			var body = $('tbody', table);
			table.dataset.shown = '';
			head.textContent = '';
			body.textContent = '';
			if (!res.ok) {
				showError(step('confirm'), res.data.message || T.error);
				return;
			}
			// A file without a Bazna cijena column (only codes): Now and New are not shown (Norman T4).
			var cols = {
				update: [T.product].concat(S.hasBazna ? [T.now, T.new] : [], S.hasLowest ? [T.lowest] : [], S.hasCodes ? [T.codesHead] : []),
				unchanged: [T.product, T.bazna].concat(S.hasCodes ? [T.codesHead] : []),
				not_found: [T.line, T.sku, T.nameInFile],
				invalid: [T.line, T.sku, T.reason]
			}[list];
			head.appendChild(el('tr', {}, cols.map(function (c) {
				return el('th', { scope: 'col', text: c });
			})));
			if (!res.data.rows.length) {
				body.appendChild(el('tr', {}, [el('td', { colspan: String(cols.length), text: T.empty })]));
			}
			res.data.rows.forEach(function (r) {
				var cells;
				var product = function () {
					return el('td', {}, [
						r.edit_url ? el('a', { href: r.edit_url, text: r.name }) : r.name,
						' ',
						el('small', { class: 'cx-import__sku', text: r.sku })
					]);
				};
				if (list === 'update') {
					// A row that changes only codes: its bazna stays ("no change").
					var newCell = el('td', {}, [r.bazna_same ? T.noChange : dateCell(r.new)]);
					if (waits(r)) {
						newCell.appendChild(el('span', { class: 'cx-import__waits', text: ' ' + T.waits }));
					}
					cells = [product()];
					if (S.hasBazna) {
						cells.push(el('td', { text: dateCell(r.now) }), newCell);
					}
					if (S.hasLowest) {
						cells.push(el('td', { class: 'cx-import__lowest', text: lowestCell(r.lowest) }));
					}
					if (S.hasCodes) {
						cells.push(codeLines(r.codes, 'change'));
					}
				} else if (list === 'unchanged') {
					cells = [product(), el('td', { text: dateCell(r.now) })];
					if (S.hasCodes) {
						// Rook 3348 (1): a code that loses to WooCommerce's says so.
						cells.push(codeLines(r.codes_kept, 'kept'));
					}
				} else if (list === 'not_found') {
					cells = [el('td', { text: String(r.line) }), el('td', { text: r.sku }), el('td', { text: r.name || '' })];
				} else {
					cells = [el('td', { text: String(r.line) }), el('td', { text: r.sku }), el('td', { text: r.message })];
				}
				var tr = el('tr', {}, cells);
				if (list === 'update' && waits(r)) {
					tr.className = 'cx-import__row--waits';
				}
				body.appendChild(tr);
			});
			pager($('.cx-import__pages', step('preview')), res.data.page, res.data.pages, function (p) {
				loadList(list, p);
			});
			table.dataset.shown = list + ':' + res.data.page;
		};

		var loadHistory = async function (page) {
			var res = await post('cx_sidrena_import_history', { page: page || 1 }, C.importNonce).catch(function () {
				return { ok: false, data: {} };
			});
			var body = $('.cx-import__history-table tbody', root);
			body.textContent = '';
			if (!res.ok) {
				return;
			}
			if (!res.data.rows.length) {
				body.appendChild(el('tr', {}, [el('td', { colspan: '6', text: T.noHistory })]));
			}
			res.data.rows.forEach(function (r) {
				body.appendChild(el('tr', {}, [
					el('td', { text: r.at }),
					el('td', { text: r.user }),
					el('td', { text: r.confirmed_by + ', ' + fmtDate(r.confirmed_on) }),
					// An import still running has no final count yet.
					el('td', { text: r.finished === false ? T.inProgress : String(r.updated) }),
					el('td', { text: r.file }),
					el('td', {}, [el('code', { title: r.sha256, text: r.short })])
				]));
			});
			pager($('.cx-import__history-pages', root), page || 1, res.data.pages, loadHistory);
			body.dataset.loaded = String(parseInt(body.dataset.loaded || '0', 10) + 1);
		};

		// The previous file's table goes as soon as another file is started, not when the new preview answers (Argus O4).
		var clearPreview = function () {
			listSeq++; // an answer still on its way for the old file draws nothing
			var table = $('.cx-import__table', root);
			$('thead', table).textContent = '';
			$('tbody', table).textContent = '';
			table.dataset.shown = '';
			$('.cx-import__pages', step('preview')).textContent = '';
		};

		var restart = function () {
			S = {};
			clearPreview();
			$('#cx-import-file').value = '';
			$('#cx-import-consent').checked = false;
			showError(step('upload'), '');
			showError(step('confirm'), '');
			showInfo('');
			show(['upload']);
		};

		// The end of an import this page followed (or stopped): Done, or for another one, Confirm is free again.
		var finish = function (r, mode) {
			r = r || { updated: 0, skipped: 0, skipped_rows: [] };
			RUN = null;
			if (mode === 'other') {
				running.hidden = true;
				var go = $('.cx-import__go', root);
				go.disabled = S.importable < 1;
				showError(step('confirm'), '');
				showInfo(T.otherDone);
				go.focus();
				loadHistory(1);
				return;
			}
			var own = mode === 'own' && S.file;
			$('.cx-import__result p', root).textContent = [sprintf(T.imported, r.updated)].concat(own ? [
				sprintf(T.confirmedBy, S.by, fmtYmd(S.on)),
				sprintf(T.fingerprint, S.file.short)
			] : []).join(' ');
			var stopped = $('.cx-import__stopped', root);
			stopped.hidden = !(r.cancelled > 0);
			$('p', stopped).textContent = r.cancelled > 0 ? sprintf(T.stopped, r.cancelled) : '';
			var sk = $('.cx-import__skipped', root);
			var list = $('ul', sk);
			list.textContent = '';
			sk.hidden = !(r.skipped_rows && r.skipped_rows.length);
			if (!sk.hidden) {
				$('.cx-import__skipped-head', sk).textContent = sprintf(T.notImported, r.skipped_rows.length);
				r.skipped_rows.forEach(function (x) {
					list.appendChild(el('li', { text: x.sku + ': ' + x.message }));
				});
			}
			// Rows that waited for Dates were not imported (Norman, CX-SID-42).
			var waited = $('.cx-import__waited', root);
			var noDate = own ? S.noDate : 0;
			waited.hidden = !noDate;
			$('.cx-import__waited-text', waited).textContent = noDate ? sprintf(T.waited, noDate) : '';
			show(['done']);
			$('.cx-import__result', root).focus();
			$$('button', step('confirm')).forEach(function (b) {
				b.disabled = false;
			});
			loadHistory(1);
		};

		// Follow an import to its end. It runs on the server without this page (WP-cron); each request here also writes
		// its next rows. A lost connection is said and can be followed again (Continue), and Stop is always there.
		// total: rows of this page's own import (from Confirm); who carries them for an import found or refused.
		var follow = async function (id, who, mode, total) {
			RUN = { id: id, who: who, mode: mode, stop: false, inflight: null };
			var run = RUN;
			var bar = $('.cx-import__progress', running);
			// Another import than the bar shows starts from its own numbers, never the previous one's (Norman 1).
			if (bar.dataset.id !== String(id)) {
				var n = who ? who.total : (total || 0);
				var done0 = who ? who.processed : 0;
				bar.dataset.id = String(id);
				$('progress', bar).max = Math.max(1, n);
				$('progress', bar).value = done0;
				$('.cx-fill-progress__count', bar).textContent = n ? done0 + ' / ' + n : '';
				showNote('');
			}
			// Someone else's import is named as such (Norman 3).
			runHead.textContent = mode === 'other' ? T.otherRunning : ownHead;
			var whoLine = $('.cx-import__running-who', running);
			whoLine.textContent = who ? sprintf(T.runningWho, who.by, who.started) : '';
			whoLine.hidden = !who;
			$('.cx-import__resume', running).hidden = true;
			$('.cx-import__stop', running).disabled = false;
			showError(running, '');
			running.hidden = false;
			var last = -1;
			var d;
			for (;;) {
				var st;
				try {
					run.inflight = postRetry('cx_sidrena_import_step', { import_id: id }, C.importNonce, function () {
						showError(running, T.retry);
					});
					st = await run.inflight;
				} catch (x) {
					run.inflight = null;
					if (run.stop) {
						return;
					}
					// Only this page lost the shop: the import goes on there; Continue shows it again (Norman 5).
					showNote('');
					showError(running, T.lost);
					$('.cx-import__resume', running).hidden = false;
					return;
				}
				run.inflight = null;
				if (run.stop || RUN !== run) {
					return; // Stop took over
				}
				if (!st.ok) {
					if (st.data.code === 'not_found') {
						// Not running any more (it ended or was stopped elsewhere): start again; History says how it ended.
						RUN = null;
						restart();
						showError(step('upload'), st.data.message || T.error);
						loadHistory(1);
						return;
					}
					// A step that failed on the server (it skips a row that fails twice): say so; Continue asks again.
					showError(running, st.data.message || T.error);
					$('.cx-import__resume', running).hidden = false;
					return;
				}
				d = st.data;
				showError(running, '');
				$('progress', bar).max = Math.max(1, d.total);
				$('progress', bar).value = d.processed;
				$('.cx-fill-progress__count', bar).textContent = d.processed + ' / ' + d.total;
				if (d.finished) {
					break;
				}
				if (d.processed === last) {
					await sleep(1500); // another request (WP-cron) holds this step: ask again shortly
				}
				last = d.processed;
			}
			finish(d.result, mode);
		};

		$('.cx-import__resume', running).addEventListener('click', function () {
			var r = RUN;
			if (r) {
				follow(r.id, r.who, r.mode); // the who/started line stays (Norman 4)
			}
		});

		$('.cx-import__stop', running).addEventListener('click', async function () {
			var r = RUN;
			if (!r || !window.confirm(r.who ? sprintf(T.stopAskWho, r.who.by, r.who.started) : T.stopAsk)) {
				return;
			}
			var btn = this;
			btn.disabled = true;
			showNote('');
			r.stop = true;
			// Let the step on its way end first, or the stop would wait for the same lock.
			if (r.inflight) {
				await r.inflight.catch(function () {});
			}
			var res = await post('cx_sidrena_import_cancel', { import_id: r.id }, C.importNonce).catch(function () {
				return { ok: false, data: { message: T.error } };
			});
			if (!res.ok) {
				btn.disabled = false;
				if (res.data.code === 'not_found') {
					follow(r.id, r.who, r.mode); // it ended meanwhile: one more read shows how
					return;
				}
				if (res.data.code === 'busy') {
					// A step is being written: the import never paused, so say it goes on and keep following; Stop stays
					// for another try, and there is no Continue to press (Norman 2).
					follow(r.id, r.who, r.mode);
					showNote(T.notStopped);
					return;
				}
				// Not stopped for another reason: said; Stop again, or Continue to follow it.
				showError(running, res.data.message || T.error);
				$('.cx-import__resume', running).hidden = false;
				return;
			}
			finish(res.data.result, r.mode);
		});

		$('#cx-import-upload').addEventListener('submit', async function (e) {
			e.preventDefault();
			var up = step('upload');
			var file = $('#cx-import-file').files[0];
			if (!file) {
				showError(up, T.noFile);
				return;
			}
			var btn = $('button[type="submit"]', up);
			btn.disabled = true;
			showError(up, '');
			var label = btn.textContent;
			btn.textContent = T.reading;
			var fd = new FormData();
			fd.append('file', file);
			var res = await post('cx_sidrena_import_upload', fd, C.importNonce).catch(function () {
				return { ok: false, data: { message: T.error } };
			});
			btn.disabled = false;
			btn.textContent = label;
			if (!res.ok) {
				showError(up, res.data.message || T.error);
				return;
			}
			var d = res.data;
			clearPreview();
			S = {
				job: d.job,
				file: d.file,
				counts: d.counts,
				datesSaved: d.dates_saved !== false,
				hasLowest: !!d.has_lowest,
				hasBazna: d.has_bazna !== false,
				hasCodes: !!d.has_codes
			};
			// The sentence that is confirmed is the one for this file (Kai 3337, Norman T5); the server keeps the same.
			if (d.consent) {
				$('#cx-import-consent-text').textContent = d.consent;
			}
			$('.cx-import__file', root).textContent = d.file.name + ' · ' + d.file.short;
			$$('.cx-import__count', root).forEach(function (b) {
				$('.cx-status__num', b).textContent = String(d.counts[b.dataset.list] || 0);
			});
			var noDate = S.datesSaved ? 0 : (d.counts.no_date || 0);
			var nd = $('.cx-import__nodate', root);
			nd.hidden = !noDate;
			nd.textContent = noDate ? sprintf(T.noDateRows, noDate) : '';
			S.importable = Math.max(0, (d.counts.update || 0) - noDate);
			S.noDate = noDate;
			var go = $('.cx-import__go', root);
			go.textContent = sprintf(T.importN, S.importable);
			go.disabled = S.importable < 1;
			showInfo('');
			show(['preview', 'confirm']);
			$('.cx-import__preview-head', root).focus();
			var first = ['update', 'not_found', 'invalid', 'unchanged'].filter(function (l) {
				return d.counts[l] > 0;
			})[0] || 'update';
			loadList(first, 1);
		});

		$$('.cx-import__count', root).forEach(function (b) {
			b.addEventListener('click', function () {
				loadList(b.dataset.list, 1);
			});
		});
		$$('.cx-import__restart', root).forEach(function (b) {
			b.addEventListener('click', restart);
		});

		$('.cx-import__go', root).addEventListener('click', async function () {
			var conf = step('confirm');
			var by = $('#cx-import-by').value.trim();
			var on = $('#cx-import-on').value;
			if (!by) {
				showError(conf, T.noName);
				$('#cx-import-by').focus();
				return;
			}
			if (!on) {
				showError(conf, T.noDate);
				return;
			}
			if (!$('#cx-import-consent').checked) {
				showError(conf, T.noConsent);
				return;
			}
			var go = this;
			go.disabled = true;
			showError(conf, '');
			showInfo('');
			var res = await post('cx_sidrena_import_confirm', {
				job: S.job,
				confirmed_by: by,
				confirmed_on: on,
				consent: '1',
				sha256: S.file.sha256
			}, C.importNonce).catch(function () {
				return { ok: false, data: { message: T.error } };
			});
			if (!res.ok) {
				var other = res.data.code === 'running' ? res.data.running : null;
				if (other && other.kind === 'import' && other.import_id) {
					// Another import runs: show it, with Stop; when it ends, this file can go (its preview is kept).
					showError(conf, res.data.message || T.error);
					follow(other.import_id, other, 'other');
					// Its card opens below Confirm: bring it into view, with the keyboard on its heading (Norman 3).
					running.scrollIntoView({ block: 'nearest' });
					runHead.focus({ preventScroll: true });
					return;
				}
				go.disabled = false;
				// A fill from Status runs: its bar is on Status.
				showError(conf, res.data.message || T.error, other && other.kind === 'fill' ? { href: C.statusUrl, text: T.openStatus } : null);
				return;
			}
			S.by = by;
			S.on = on;
			$$('button', conf).forEach(function (b) {
				b.disabled = true;
			});
			show(['preview', 'confirm', 'running']);
			$('.cx-import__running-head', running).focus();
			follow(res.data.import_id, null, 'own', res.data.total);
		});

		loadHistory(1);
		// An import that runs (started on another page, by someone else, or before a reload): follow it.
		post('cx_sidrena_import_current', {}, C.importNonce).then(function (res) {
			var cur = res.ok && res.data.running;
			if (cur && cur.kind === 'import' && cur.import_id && !RUN) {
				show(['running']);
				follow(cur.import_id, cur, 'found');
			}
			root.dataset.current = cur ? 'running' : 'none';
		}).catch(function () {
			root.dataset.current = 'error';
		});
	}

	initDates();
	initStatus();
	initImport();
})();
