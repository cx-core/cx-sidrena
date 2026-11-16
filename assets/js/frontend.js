(function ($) {
	"use strict";

	// Lines of a variable parent: hidden while a size is chosen, so no line shows twice (spec §2.4).
	var PARENT_LINES = ".sidrena-cijena--variable, .bazna-najniza30--variable";
	// A product in a list (shop, category, search, related, block collections): its line comes from the server with its
	// own product. The page's line must never be put there (Argus CX-SID-25 B1: cards showed another product's bazna).
	var CARDS = "li.product, .wc-block-product, .wc-block-grid__product, .wp-block-woocommerce-product-template li, .related, .upsells, .cross-sells";
	// Carts and the customer's lists (block and classic cart, mini-cart, checkout, wishlist, saved for later): their lines
	// are other products', and the cart shows no bazna at all (CX-SID-14). The page's line never goes there (CX-SID-57:
	// the block mini-cart showed the page's bazna next to every item).
	var CARTS = ".wc-block-mini-cart__drawer, .wp-block-woocommerce-mini-cart-contents, .wc-block-cart, .wp-block-woocommerce-cart, " +
		".wc-block-checkout, .wp-block-woocommerce-checkout, .wc-block-components-order-summary, .wc-block-cart-items, " +
		".wc-block-shopper-list-item, .widget_shopping_cart, .woocommerce-mini-cart, .cart_list, .woocommerce-cart-form, " +
		".cart_totals, #order_review, .woocommerce-checkout-review-order";
	// On a page that does not mark where its product is: what is never the product's own price (side carts, drawers and
	// quick views open as dialogs or fixed layers; header and footer hold mini-carts and lists).
	var NOT_MAIN = "dialog, [role='dialog'], [aria-modal='true'], header, footer, [role='banner'], [role='contentinfo']";

	function parseRgb(value) {
		var m = /rgba?\(([^)]+)\)/.exec(value || "");
		if (!m) {
			return null;
		}
		var p = m[1].split(/[\s,\/]+/).filter(Boolean).map(Number);
		return { r: p[0], g: p[1], b: p[2], a: p.length > 3 ? p[3] : 1 };
	}

	function luminance(c) {
		function ch(v) {
			v /= 255;
			return v <= 0.03928 ? v / 12.92 : Math.pow((v + 0.055) / 1.055, 2.4);
		}
		return 0.2126 * ch(c.r) + 0.7152 * ch(c.g) + 0.0722 * ch(c.b);
	}

	function backgroundOf(el) {
		for (var e = el; e && e.nodeType === 1; e = e.parentElement) {
			var c = parseRgb(window.getComputedStyle(e).backgroundColor);
			if (c && c.a > 0) {
				return c;
			}
		}
		return { r: 255, g: 255, b: 255, a: 1 };
	}

	function contrast(a, b) {
		var l1 = luminance(a);
		var l2 = luminance(b);
		return (Math.max(l1, l2) + 0.05) / (Math.min(l1, l2) + 0.05);
	}

	// A colour with opacity a over an opaque background, as the reader sees it.
	function over(c, a, bg) {
		return { r: c.r * a + bg.r * (1 - a), g: c.g * a + bg.g * (1 - a), b: c.b * a + bg.b * (1 - a) };
	}

	// The least step from c toward black or white (whichever the background is further from) that reads at 4.5:1.
	function pulled(c, bg) {
		var black = { r: 0, g: 0, b: 0 };
		var white = { r: 255, g: 255, b: 255 };
		var to = contrast(black, bg) >= contrast(white, bg) ? black : white;
		var best = to;
		var lo = 0;
		var hi = 1;
		for (var i = 0; i < 24; i++) {
			var t = (lo + hi) / 2;
			var x = {
				r: Math.round(c.r + (to.r - c.r) * t),
				g: Math.round(c.g + (to.g - c.g) * t),
				b: Math.round(c.b + (to.b - c.b) * t)
			};
			if (contrast(x, bg) >= 4.5) {
				best = x;
				hi = t;
			} else {
				lo = t;
			}
		}
		return "rgb(" + best.r + ", " + best.g + ", " + best.b + ")";
	}

	// Our lines are in the shop's ordinary text colour (body) at 75 % (frontend.css, CX-SID-41), never the price's. Per
	// line, against its real background: 75 % if that reads at 4.5:1, else the full colour (cx-sidrena-solid), else the
	// full colour pulled toward black or white just enough (spec §2.1, §2.3). The owner's own colour is used as chosen.
	function ensureContrast(root) {
		var nodes = (root || document).querySelectorAll(
			".sidrena-cijena:not(.cx-sidrena-checked), .bazna-najniza30:not(.cx-sidrena-checked)"
		);
		if (!nodes.length) {
			return;
		}
		var text = config().ownColor ? null : parseRgb(
			window.getComputedStyle(document.documentElement).getPropertyValue("--cx-sidrena-text")
		);
		Array.prototype.forEach.call(nodes, function (el) {
			el.classList.add("cx-sidrena-checked");
			if (!text) {
				return;
			}
			var bg = backgroundOf(el);
			if (contrast(over(text, 0.75 * text.a, bg), bg) >= 4.5) {
				return;
			}
			var full = over(text, text.a, bg);
			if (contrast(full, bg) >= 4.5) {
				el.classList.add("cx-sidrena-solid");
				return;
			}
			el.style.setProperty("color", pulled(full, bg), "important");
		});
	}

	function textBox(el) {
		var range = document.createRange();
		range.selectNodeContents(el);
		var rects = Array.prototype.filter.call(range.getClientRects(), function (r) {
			return r.height > 0;
		});
		if (!rects.length) {
			return null;
		}
		return {
			top: Math.min.apply(null, rects.map(function (r) { return r.top; })),
			bottom: Math.max.apply(null, rects.map(function (r) { return r.bottom; }))
		};
	}

	// The caption belongs to the struck amount above it: at most 2 px between the price text and the caption text (spec
	// §2.3). The space under the price comes from the theme's line height, which CSS cannot know, so measure and pull
	// the caption up to 2 px. Never closer, never down; without this script the CSS margin of 2 px stays.
	function tieCaptions(root) {
		var nodes = (root || document).querySelectorAll(".bazna-najniza30");
		Array.prototype.forEach.call(nodes, function (el) {
			el.style.marginTop = "";
			if (!el.offsetParent || !el.parentElement) {
				return;
			}
			var bottom = null;
			Array.prototype.forEach.call(el.parentElement.querySelectorAll(".woocommerce-Price-amount"), function (a) {
				if (a.closest(".bazna-najniza30, .sidrena-cijena")) {
					return;
				}
				var box = textBox(a);
				if (box && (bottom === null || box.bottom > bottom)) {
					bottom = box.bottom;
				}
			});
			var caption = textBox(el);
			if (bottom === null || !caption) {
				return;
			}
			var gap = caption.top - bottom;
			if (gap > 2.5) {
				el.style.marginTop = (parseFloat(window.getComputedStyle(el).marginTop) - (gap - 2)) + "px";
			}
		});
	}

	function config() {
		return typeof sidrenaCijenaFront !== "undefined" ? sidrenaCijenaFront : {};
	}

	function sidrenaHtml() {
		return config().html || "";
	}

	function scope($form) {
		return $form.closest(".product, .summary, li.product");
	}

	function stripLegacyIcon() {
		document.querySelectorAll(".sidrena-cijena__icon").forEach(function (el) {
			el.remove();
		});

		document.querySelectorAll(".sidrena-cijena__info").forEach(function (info) {
			var tooltip = info.querySelector(".sidrena-cijena__tooltip");
			var parent = info.parentNode;
			if (tooltip && parent) {
				parent.appendChild(tooltip);
				parent.classList.add("sidrena-cijena--has-tooltip");
			}
			info.remove();
		});
	}

	function isNestedPrice(el) {
		if (!el || !el.classList || !el.classList.contains("price")) {
			return false;
		}

		var parent = el.parentElement;
		return !!(parent && parent.closest && parent.closest(".price"));
	}

	function alreadyHasSidrena(el) {
		if (!el) {
			return true;
		}

		if (el.querySelector(".sidrena-cijena")) {
			return true;
		}

		var outer = el.closest && el.closest("p.price, .price");
		if (outer && outer !== el && outer.querySelector(".sidrena-cijena")) {
			return true;
		}

		return false;
	}

	function insertSidrena(el, html) {
		var lowest = el.querySelector(".lowest_price");
		var actual = el.querySelector(".actual_price");

		if (lowest && el.contains(lowest)) {
			var before = el.innerHTML.slice(0, el.innerHTML.toLowerCase().indexOf("lowest_price"));
			if (
				before.indexOf("woocommerce-Price-amount") !== -1 ||
				before.indexOf("<ins") !== -1 ||
				before.indexOf("<del") !== -1 ||
				before.indexOf("actual_price") !== -1
			) {
				lowest.insertAdjacentHTML("beforebegin", html);
				return;
			}
		}

		if (actual) {
			actual.insertAdjacentHTML("afterend", html);
			return;
		}

		el.insertAdjacentHTML("beforeend", html);
	}

	function dedupeIn(root) {
		if (!root) {
			return;
		}

		var nodes = root.querySelectorAll(".sidrena-cijena");
		for (var i = 1; i < nodes.length; i++) {
			nodes[i].remove();
		}
	}

	// The product an element belongs to: the nearest element that names one (WooCommerce's id="product-N", the post class
	// post-N, a data-product_id). 0 when nothing names one. <body> says postid-N, never post-N.
	function namedProduct(el) {
		for (var e = el; e && e.nodeType === 1 && e !== document.body; e = e.parentElement) {
			var m = /^product-(\d+)$/.exec(e.id || "");
			if (!m) {
				m = /(?:^|\s)post-(\d+)(?:\s|$)/.exec(e.getAttribute("class") || "");
			}
			if (!m) {
				m = /^(\d+)$/.exec(e.getAttribute("data-product_id") || e.getAttribute("data-product-id") || "");
			}
			if (m) {
				return parseInt(m[1], 10);
			}
		}
		return 0;
	}

	// Where the page shows its own product: WooCommerce's single product container (#product-N), a builder's single
	// template with the post classes (Elementor Pro: .post-N), WooCommerce's block template wrapper.
	function pageProductRoots(id) {
		var roots = [];
		var byId = document.getElementById("product-" + id);
		if (byId) {
			roots.push(byId);
		}
		Array.prototype.forEach.call(document.querySelectorAll(".post-" + id + ", .wp-block-group.woocommerce.product"), function (e) {
			if (e !== document.body && roots.indexOf(e) === -1) {
				roots.push(e);
			}
		});
		return roots;
	}

	function inFixedLayer(el) {
		for (var e = el; e && e.nodeType === 1 && e !== document.body; e = e.parentElement) {
			if (window.getComputedStyle(e).position === "fixed") {
				return true;
			}
		}
		return false;
	}

	// The page's line belongs to the page's product, so it goes only into that product's own price (CX-SID-57). An allow
	// rule, not a list of places to avoid: MJDD's selectors (price_selectors()) are generic and also match carts, side
	// carts, quick views and lists, which no list of exclusions can name in full.
	function isPageProductPrice(el) {
		var id = parseInt(config().productId, 10) || 0;
		if (!id || !el.closest || el.closest(CARDS) || el.closest(CARTS)) {
			return false;
		}
		var named = namedProduct(el);
		if (named) {
			return named === id;
		}
		var roots = pageProductRoots(id);
		if (roots.length) {
			return roots.some(function (r) {
				return r.contains(el);
			});
		}
		return !el.closest(NOT_MAIN) && !inFixedLayer(el);
	}

	function injectInto(el) {
		var html = sidrenaHtml();
		if (!html || !el || !isPageProductPrice(el)) {
			return;
		}

		if (
			el.classList.contains("elementor-widget-container") &&
			el.querySelector(".price")
		) {
			return;
		}

		if (isNestedPrice(el)) {
			return;
		}

		if (alreadyHasSidrena(el)) {
			dedupeIn(el);
			return;
		}

		insertSidrena(el, html);
		dedupeIn(el);
		stripLegacyIcon();
	}

	function injectBuilderPrices() {
		var html = sidrenaHtml();
		var selectors = config().selectors || [];
		if (!html || !selectors.length) {
			return;
		}

		selectors.forEach(function (selector) {
			try {
				document.querySelectorAll(selector).forEach(injectInto);
			} catch (e) {
				// Invalid selector from a stale cache - skip.
			}
		});
	}

	function watchBuilderPrices() {
		var roots = config().watchRoots || [];
		if (!roots.length || typeof MutationObserver === "undefined") {
			return;
		}

		var watched = [];
		var debounceTimer = null;
		var running = false;

		function connectAll() {
			watched.forEach(function (entry) {
				entry.observer.observe(entry.root, { childList: true, subtree: true });
			});
		}

		function disconnectAll() {
			watched.forEach(function (entry) {
				entry.observer.disconnect();
			});
		}

		function runInject() {
			if (running) {
				return;
			}

			running = true;
			disconnectAll();
			try {
				injectBuilderPrices();
				ensureContrast();
			} finally {
				connectAll();
				running = false;
			}
		}

		function scheduleRun() {
			clearTimeout(debounceTimer);
			debounceTimer = setTimeout(runInject, 150);
		}

		roots.forEach(function (selector) {
			document.querySelectorAll(selector).forEach(function (root) {
				var observer = new MutationObserver(scheduleRun);
				observer.observe(root, { childList: true, subtree: true });
				watched.push({ observer: observer, root: root });
			});
		});
	}

	function lineText(el) {
		var text = el.querySelector(".sidrena-cijena__text");
		return (text || el).textContent.replace(/\s+/g, " ").trim();
	}

	// The parent's own price box (not the chosen size's, not a related product's card).
	function parentPrice($form) {
		return scope($form).find(".price, .wp-block-woocommerce-product-price").filter(function () {
			return !$(this).closest(CARDS + ", .woocommerce-variation-price").length;
		}).first();
	}

	// Every size costs the same: WooCommerce shows no price for the chosen size and the parent's block stays. The size's
	// bazna line goes into that block: nothing changes when it says the same as the parent's line, otherwise it takes the
	// parent line's place (Norman, CX-SID-25; under the size select it stood apart from its price and caption).
	// The parent's line keeps its element (so no clean-up that keeps the first line of a price box removes the size's):
	// only its content is swapped, and put back on reset.
	function restoreInPlace($form) {
		scope($form).find(".sidrena-cijena--chosen").remove();
		scope($form).find(".sidrena-cijena--swapped").each(function () {
			this.innerHTML = $(this).data("cxSidrenaParent");
			$(this).removeClass("sidrena-cijena--swapped");
		});
	}

	function applyInPlace($form, variation) {
		restoreInPlace($form);
		if (!variation.sidrena_cijena_html) {
			return;
		}

		var $line = $(variation.sidrena_cijena_html).filter(".sidrena-cijena").first();
		if (!$line.length) {
			return;
		}
		$line.addClass("sidrena-cijena--chosen");

		var $parentLine = scope($form).find(".sidrena-cijena--variable").filter(function () {
			return !$(this).closest(CARDS + ", .woocommerce-variation-price").length;
		}).first();
		if ($parentLine.length) {
			if (lineText($parentLine.get(0)) !== lineText($line.get(0))) {
				$parentLine.data("cxSidrenaParent", $parentLine.get(0).innerHTML).addClass("sidrena-cijena--swapped");
				$parentLine.get(0).innerHTML = $line.get(0).innerHTML;
			}
		} else {
			var $price = parentPrice($form);
			if (!$price.length) {
				$form.find(".woocommerce-variation-price").append($line);
				return;
			}
			$price.append($line);
		}

		ensureContrast(scope($form).get(0));
	}

	function applyVariationSidrena($form, variation) {
		var $variationPrice = $form.find(".woocommerce-variation-price");
		if (!variation) {
			return;
		}

		if (variation.sidrena_cijena_inplace) {
			$variationPrice.find(".sidrena-cijena").remove();
			applyInPlace($form, variation);
			tieCaptions(scope($form).get(0));
			return;
		}
		restoreInPlace($form);
		if (!$variationPrice.length) {
			return;
		}

		if (variation.sidrena_cijena_html) {
			$variationPrice.find(".sidrena-cijena").remove();

			var $lowest = $variationPrice.find(".lowest_price").first();
			if ($lowest.length) {
				$lowest.before(variation.sidrena_cijena_html);
			} else {
				$variationPrice.append(variation.sidrena_cijena_html);
			}

			dedupeIn($variationPrice.get(0));
		}

		// The chosen size shows its own lines: the parent's same line hides, so no line shows twice. When every size costs
		// the same, the size shows only its bazna line and the parent keeps its struck price with the caption.
		if ($variationPrice.find(".sidrena-cijena").length) {
			scope($form).find(".sidrena-cijena--variable").hide();
		}
		if ($variationPrice.find(".bazna-najniza30").length) {
			scope($form).find(".bazna-najniza30--variable").hide();
		}

		ensureContrast($variationPrice.get(0));
		tieCaptions(scope($form).get(0));
	}

	// A bar fixed to the bottom of the screen (Storefront's account / search / cart bar on a phone) would cover the
	// "Cjenik" link at the end of the page (Norman, CX-SID-25): the link keeps that much space under it.
	function clearBottomBars() {
		var link = document.querySelector(".cx-sidrena-cjenik-link");
		if (!link || !document.elementsFromPoint) {
			return;
		}

		var vh = window.innerHeight;
		var cover = 0;
		[0.2, 0.5, 0.8].forEach(function (fx) {
			document.elementsFromPoint(window.innerWidth * fx, vh - 2).forEach(function (el) {
				for (var e = el; e && e !== document.body && e !== document.documentElement; e = e.parentElement) {
					if (getComputedStyle(e).position === "fixed") {
						var r = e.getBoundingClientRect();
						if (r.height > 0 && r.height < vh / 3 && r.bottom >= vh - 2) {
							cover = Math.max(cover, vh - r.top);
						}
						break;
					}
				}
			});
		});
		link.style.marginBottom = cover > 0 ? Math.ceil(cover) + "px" : "";
	}

	$(function () {
		stripLegacyIcon();
		clearBottomBars();
		$(window).on("load", clearBottomBars);
		injectBuilderPrices();
		ensureContrast();
		watchBuilderPrices();

		// Measure once the fonts are in: a web font changes the line boxes.
		if (document.fonts && document.fonts.ready) {
			document.fonts.ready.then(function () {
				tieCaptions();
			});
		} else {
			tieCaptions();
		}
		var resizeTimer = null;
		$(window).on("resize orientationchange", function () {
			window.clearTimeout(resizeTimer);
			resizeTimer = window.setTimeout(function () {
				tieCaptions();
				clearBottomBars();
			}, 200);
		});

		$(document.body).on(
			"updated_wc_div wcpa_fields_updated wcpa_price_updated",
			function () {
				injectBuilderPrices();
				ensureContrast();
			}
		);

		$("form.variations_form").each(function () {
			var $form = $(this);
			var pending = null;

			function showForVariation(variation) {
				window.clearTimeout(pending);
				pending = null;
				applyVariationSidrena($form, variation);
				injectBuilderPrices();
			}

			$form.on("show_variation", function (event, variation) {
				showForVariation(variation);
			});

			// found_variation runs before WooCommerce builds the price box.
			$form.on("found_variation", function (event, variation) {
				window.clearTimeout(pending);
				pending = window.setTimeout(function () {
					showForVariation(variation);
				}, 0);
			});

			$form.on("reset_data hide_variation", function () {
				window.clearTimeout(pending);
				pending = null;
				restoreInPlace($form);
				scope($form).find(PARENT_LINES).show();
				tieCaptions(scope($form).get(0));
				$form.find(".woocommerce-variation-price .sidrena-cijena").remove();
				injectBuilderPrices();
			});
		});
	});
})(jQuery);
