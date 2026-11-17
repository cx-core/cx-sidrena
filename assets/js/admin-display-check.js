/**
 * CX Sidrena Status (CX-SID-92): what a customer sees on a product page. A theme can hide our bazna line or the struck
 * lowest price with its own CSS, or draw its own price; the server cannot see that. So Status opens one product's page in
 * a hidden frame of this same site, once at a computer's width and once at a phone's (two GET loads, nothing else of
 * ours), measures there whether the bazna line with its amount is in sight and, where the caption is in sight, the struck
 * amount it is about, and stores the finding (wp_ajax_cx_sidrena_display_seen). It runs when Status opens and the
 * stored finding is missing or out of date (another theme or version, older than 7 days), or on "Check again".
 * The frame is sandboxed: the page's own scripts run as for any visit, but it cannot submit a form, open a window or
 * navigate this screen away. It sits in this window's view, behind the screen and see-through (admin.css), and the part
 * measured is scrolled into its view first: a theme that shows the price only once it is in view (an entrance
 * animation) shows it there as it does for a customer. Only the frame's page scrolls, never this screen.
 */
(function () {
	'use strict';

	var C = window.cxDisplayCheck;
	var box = document.getElementById('cx-display');
	if (!C || !box) {
		return;
	}
	var LOAD_TIMEOUT = 25000;
	var SETTLE = 1500; // after the load: the page's own scripts (ours too) place what they place
	var REVEAL = 1000; // after a part is scrolled into view: a theme that shows it only in view has shown it
	var TOP = 80; // where a part is scrolled to, from the top of the frame (the frame's top is this window's top)
	var running = false;

	var byId = function (id) {
		return document.getElementById(id);
	};
	var show = function (el, on) {
		if (el) {
			el.hidden = !on;
		}
	};

	/**
	 * In sight: a box, and nothing up the tree that hides it (display, visibility, opacity), not pushed off the page.
	 */
	var inSight = function (el) {
		if (!el || !el.isConnected) {
			return false;
		}
		var win = el.ownerDocument.defaultView;
		var r = el.getBoundingClientRect();
		if (r.width < 1 || r.height < 1) {
			return false;
		}
		if (r.right + win.scrollX <= 0 || r.bottom + win.scrollY <= 0) {
			return false;
		}
		var cs = win.getComputedStyle(el);
		if (cs.visibility === 'hidden' || cs.visibility === 'collapse') {
			return false;
		}
		for (var e = el; e && e.nodeType === 1; e = e.parentElement) {
			var s = win.getComputedStyle(e);
			if (s.display === 'none' || parseFloat(s.opacity) === 0) {
				return false;
			}
		}
		return true;
	};

	/** Lists of other products (related, up-sells, grids, the mini-cart, widgets): their lines are not this product's. */
	var LISTS = '.products, .related, .upsells, .cross-sells, .wc-block-product-template, .wp-block-woocommerce-product-collection,'
		+ ' .wc-block-grid, .wc-block-mini-cart, .woocommerce-mini-cart, .widget, .wp-block-woocommerce-related-products';
	var own = function (doc, sel) {
		return Array.prototype.slice.call(doc.querySelectorAll(sel)).filter(function (el) {
			return !el.closest(LISTS);
		});
	};

	/** The first of these with a box (a part hidden by visibility has one; one hidden by display has none). */
	var boxed = function (els) {
		for (var i = 0; i < els.length; i++) {
			var r = els[i].getBoundingClientRect();
			if (r.width >= 1 && r.height >= 1) {
				return els[i];
			}
		}
		return null;
	};

	/** Whether a part of the frame's page is in this window's view now (the frame's top left is this window's). */
	var inView = function (el) {
		var r = el.getBoundingClientRect();
		var h = Math.min(el.ownerDocument.defaultView.innerHeight, window.innerHeight);
		return r.top >= 0 && r.bottom <= h;
	};

	/** Scrolls the frame's own page (not this screen) so the part is near the frame's top, then waits for the theme. */
	var bring = function (el) {
		return new Promise(function (resolve) {
			if (el) {
				var win = el.ownerDocument.defaultView;
				win.scrollTo(win.scrollX, Math.max(0, win.scrollY + el.getBoundingClientRect().top - TOP));
			}
			setTimeout(resolve, REVEAL);
		});
	};

	/**
	 * The finding on the page in the frame: our line measured once it is scrolled into view, then the caption, scrolled
	 * into view too when it is elsewhere on the page (a part a theme shows on entering the view may hide again on leaving).
	 */
	var measure = function (doc) {
		var lines = own(doc, '.sidrena-cijena');
		var out = { found: lines.length > 0, line: false, caption: false, struck: null };
		return bring(boxed(lines))
			.then(function () {
				out.line = lines.some(function (l) {
					var amount = l.querySelector('.sidrena-cijena__amount .woocommerce-Price-amount') || l.querySelector('.sidrena-cijena__amount');
					return inSight(l) && inSight(amount);
				});
				var cap = boxed(own(doc, '.bazna-najniza30'));
				return cap && !inView(cap) ? bring(cap) : null;
			})
			.then(function () {
				var caps = own(doc, '.bazna-najniza30').filter(inSight);
				caps.forEach(function (c) {
					var del = c.parentElement ? c.parentElement.querySelector('del') : null;
					if (del) {
						out.struck = out.struck === true || inSight(del);
					}
				});
				out.caption = caps.length > 0;
				return out;
			});
	};

	/** One load of the page at one width: the finding, or a reason it could not be measured. */
	var loadAt = function (width) {
		return new Promise(function (resolve) {
			var frame = document.createElement('iframe');
			frame.className = 'cx-display__frame';
			frame.setAttribute('aria-hidden', 'true');
			frame.setAttribute('tabindex', '-1');
			frame.setAttribute('sandbox', 'allow-scripts allow-same-origin');
			frame.style.width = width + 'px';
			var done = false;
			var finish = function (out) {
				if (done) {
					return;
				}
				done = true;
				clearTimeout(timer);
				frame.remove();
				resolve(out);
			};
			var timer = setTimeout(function () {
				finish({ error: 'timeout' });
			}, LOAD_TIMEOUT);
			frame.addEventListener('load', function () {
				var doc = null;
				try {
					doc = frame.contentDocument;
				} catch (e) {
					doc = null;
				}
				// A page the shop does not allow in a frame (X-Frame-Options, CSP) is an error page of another origin.
				if (!doc || !doc.body || doc.URL === 'about:blank') {
					finish({ error: 'blocked' });
					return;
				}
				var cls = doc.body.classList;
				if (!cls.contains('single-product') && !cls.contains('postid-' + C.product) && !doc.getElementById('product-' + C.product)) {
					finish({ error: 'not_product' });
					return;
				}
				setTimeout(function () {
					var m;
					try {
						m = measure(doc);
					} catch (e) {
						m = Promise.reject(e);
					}
					m.then(finish, function () {
						finish({ error: 'other' });
					});
				}, SETTLE);
			});
			frame.src = C.url;
			document.body.appendChild(frame);
		});
	};

	var store = function (state, widths, reason) {
		var body = new URLSearchParams();
		body.set('action', 'cx_sidrena_display_seen');
		body.set('nonce', C.nonce);
		body.set('state', state);
		body.set('widths', JSON.stringify(widths));
		body.set('reason', reason || '');
		return fetch(C.ajaxUrl, { method: 'POST', credentials: 'same-origin', body: body }).then(function (r) {
			return r.json();
		});
	};

	var render = function (res) {
		var missed = res.missed || [];
		show(byId('cx-display-bad'), missed.length > 0);
		show(byId('cx-display-unchecked'), res.state === 'unchecked');
		show(byId('cx-display-ok'), !!res.seen);
		show(byId('cx-status-all-set'), missed.length === 0);
		var where = box.querySelector('.cx-display__where');
		if (where) {
			where.textContent = res.where || '';
		}
		var link = box.querySelector('.cx-display__product');
		if (link && res.url) {
			link.href = res.url;
		}
		box.setAttribute('data-state', res.state);
	};

	var run = function () {
		if (running) {
			return;
		}
		running = true;
		show(byId('cx-display-checking'), true);
		show(byId('cx-display-unchecked'), false);
		show(byId('cx-display-ok'), false);
		box.setAttribute('data-state', 'checking');
		// The URL is this site's own product page: never another origin.
		var same = false;
		try {
			same = new URL(C.url, window.location.href).origin === window.location.origin;
		} catch (e) {
			same = false;
		}
		var widths = {};
		var reason = '';
		var chain = Promise.resolve();
		if (!same) {
			reason = 'other_origin';
		} else {
			C.widths.forEach(function (w) {
				chain = chain.then(function () {
					if (reason) {
						return null;
					}
					return loadAt(w).then(function (out) {
						if (out.error) {
							reason = out.error;
						} else {
							widths[w] = out;
						}
					});
				});
			});
		}
		chain
			.then(function () {
				return store(reason ? 'unchecked' : 'checked', reason ? {} : widths, reason);
			})
			.then(function (r) {
				if (r && r.success) {
					render(r.data);
				}
			})
			.catch(function () {
				// Nothing stored: the next opening of Status tries again.
			})
			.then(function () {
				running = false;
				show(byId('cx-display-checking'), false);
				if (box.getAttribute('data-state') === 'checking') {
					box.setAttribute('data-state', 'none');
				}
			});
	};

	Array.prototype.slice.call(box.querySelectorAll('.cx-display__again')).forEach(function (b) {
		b.addEventListener('click', run);
	});
	if (C.due) {
		run();
	}
})();
