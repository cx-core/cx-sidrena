(function ($) {
	"use strict";

	// What the form sent, kept in this tab until the page after the save: a refused save (cx_outlet_error) stores nothing
	// on the server, so the form puts the sent values back from here (CX-SID-74 with CX-SID-131; a transient does not
	// survive the request with a W3TC object-cache drop-in).
	var SENT_KEY = "cxSidrenaOutletsSent";
	var SENT_MAX_AGE = 60 * 60 * 1000;

	function bindPoslovnice() {
		var $form = $("#sidrena-poslovnice-form");
		if (!$form.length || typeof sidrenaCijenaAdmin === "undefined") {
			return;
		}

		var i18n = sidrenaCijenaAdmin.i18n || {};
		var $list = $("#sidrena-poslovnice");
		var $json = $("#sidrena-poslovnice-json");
		var $loaded = $("#sidrena-poslovnice-loaded");
		var $refused = $("#sidrena-poslovnice-refused");
		var $webCode = $("#sidrena_cijena_file_oznaka");
		var $webMsg = $webCode.siblings(".js-msg");
		if (!$list.length || !$json.length) {
			return;
		}
		var woo = !sidrenaCijenaAdmin.standalone;
		var labels = sidrenaCijenaAdmin.productLabels || {};
		var choices = sidrenaCijenaAdmin.catalogChoices || [];
		var uid = 0;

		function fmt(text, args) {
			var next = 0;
			return String(text || "").replace(/%(\d+\$)?s/g, function (m, pos) {
				var k = pos ? parseInt(pos, 10) - 1 : next++;
				return args[k] == null ? "" : String(args[k]);
			});
		}

		// As CX_Sidrena_Settings::normalize_oznaka() (with sanitize_text_field's tags and %xx): A-Z, a-z, 0-9 and '-',
		// no '-' at either end. Two codes that differ only in letter case are one code (CX-SID-74).
		function normCode(value) {
			var v = String(value == null ? "" : value)
				.replace(/<[^>]*>/g, "")
				.replace(/%[a-fA-F0-9]{2}/g, "")
				.replace(/[^A-Za-z0-9-]/g, "");
			return v.replace(/^-+|-+$/g, "");
		}

		function codeKey(value) {
			return normCode(value).toUpperCase();
		}

		function idList(value) {
			return ($.isArray(value) ? value : []).map(String);
		}

		function field(cls, label, $control, helpText, wide) {
			var id = "sidrena-p" + $control.data("uid") + "-" + cls;
			var $wrap = $("<p>", {
				class: "sidrena-poslovnica__field" + (wide ? " sidrena-poslovnica__field--wide" : ""),
			});
			$control.attr("id", id);
			$("<label>", { for: id }).text(label || "").appendTo($wrap);
			$control.appendTo($wrap);
			$("<span>", { class: "cx-field__msg js-msg", id: id + "-msg", hidden: true }).appendTo($wrap);
			if (helpText) {
				$("<span>", { class: "description js-help", id: id + "-help" }).text(helpText).appendTo($wrap);
				$control.attr("aria-describedby", id + "-help");
			}
			return $wrap;
		}

		function textInput(className, value, n, type) {
			return $("<input>", {
				type: type || "text",
				class: className,
				value: value == null ? "" : value,
			}).data("uid", n);
		}

		function productControl(products, n) {
			if (woo) {
				var $select = $("<select>", {
					class: "sidrena-poslovnica__select js-products",
					multiple: "multiple",
					"aria-label": i18n.outletPicked || "",
				});
				products.forEach(function (id) {
					$("<option>", {
						value: id,
						text: labels[id] || "#" + id,
						selected: true,
					}).appendTo($select);
				});
				return $select;
			}

			if (!choices.length) {
				return $("<p>", { class: "description js-products-empty" }).text(i18n.noCatalog || "");
			}

			var selected = {};
			products.forEach(function (id) {
				selected[id] = true;
			});
			var $box = $("<div>", { class: "sidrena-poslovnica__checks js-products" });
			choices.forEach(function (choice) {
				var $label = $("<label>");
				$("<input>", {
					type: "checkbox",
					value: String(choice.id),
					checked: !!selected[String(choice.id)],
				}).appendTo($label);
				$label.append(document.createTextNode(" " + (choice.label || "")));
				$box.append($label);
			});
			return $box;
		}

		// WooCommerce's own words for the picker's messages (wc_enhanced_select_params, translated by WooCommerce).
		function pickerLanguage() {
			var p = window.wc_enhanced_select_params;
			if (!p) {
				return undefined;
			}
			return {
				errorLoading: function () {
					return p.i18n_searching;
				},
				inputTooShort: function (args) {
					var left = args.minimum - args.input.length;
					return 1 === left ? p.i18n_input_too_short_1 : String(p.i18n_input_too_short_n).replace("%qty%", left);
				},
				inputTooLong: function (args) {
					var over = args.input.length - args.maximum;
					return 1 === over ? p.i18n_input_too_long_1 : String(p.i18n_input_too_long_n).replace("%qty%", over);
				},
				loadingMore: function () {
					return p.i18n_load_more;
				},
				maximumSelected: function (args) {
					return 1 === args.maximum
						? p.i18n_selection_too_long_1
						: String(p.i18n_selection_too_long_n).replace("%qty%", args.maximum);
				},
				noResults: function () {
					return p.i18n_no_matches;
				},
				searching: function () {
					return p.i18n_searching;
				},
			};
		}

		function initSelect($card) {
			var $select = $card.find("select.js-products");
			if (!$select.length || typeof $.fn.selectWoo !== "function") {
				return;
			}
			var options = {
				width: "100%",
				placeholder: i18n.searchProducts || "",
				ajax: {
					url: sidrenaCijenaAdmin.ajaxUrl,
					dataType: "json",
					delay: 250,
					data: function (params) {
						return {
							term: params.term,
							action: "woocommerce_json_search_products_and_variations",
							security: sidrenaCijenaAdmin.productSearchNonce,
						};
					},
					processResults: function (data) {
						var results = [];
						if (data && typeof data === "object") {
							$.each(data, function (id, text) {
								results.push({ id: id, text: text });
							});
						}
						return { results: results };
					},
				},
				minimumInputLength: 2,
			};
			var language = pickerLanguage();
			if (language) {
				options.language = language;
			}
			$select.selectWoo(options);
		}

		// One place. row: as the page got it (form_rows(): dodjela, objavljeno, no stock) or as it was sent (restore).
		function card(row) {
			var n = ++uid;
			var products = idList(row.products);
			var locked = !!row.objavljeno;
			var dodjela = "svi" === row.dodjela ? "svi" : "odabrani";
			// CX-SID-185 (T81): with WooCommerce a saved place on "odabrani" with no product and rows of its own is "No
			// webshop products". It is stored as it was (odabrani, 0 products); only the form shows it as the third choice
			// (SPEC-UVOZ §4.0). Without rows of its own it publishes nothing, so it stays on Odabrani with an empty list
			// and Save says M4, as before 185 (Rook U16, Argus/Norman 22.11.).
			if (woo && ("bez" === row.dodjela || ("odabrani" === dodjela && !products.length && !!row.id && !!row.vlastiti))) {
				dodjela = "bez";
			}
			var $card = $("<article>", { class: "sidrena-poslovnica" });
			$card.data("row", {
				id: row.id || "",
				loaded: idList(row.products_loaded !== undefined ? row.products_loaded : row.products),
				objavljeno: locked,
				oznaka: row.stored_oznaka !== undefined ? row.stored_oznaka : row.oznaka || "",
				vlastiti: !!row.vlastiti,
				imported: row.imported || null,
			});
			$("<input>", { type: "hidden", class: "js-id", value: row.id || "" }).appendTo($card);
			var $grid = $("<div>", { class: "sidrena-poslovnica__grid" }).appendTo($card);
			field("naziv", i18n.outletName, textInput("js-naziv", row.naziv, n), i18n.outletNameHelp, true).appendTo($grid);
			field("oblik", i18n.outletOblik, textInput("js-oblik", row.oblik, n).attr("aria-required", "true"), i18n.outletOblikHelp).appendTo($grid);
			field("adresa", i18n.outletAddress, textInput("js-adresa", row.adresa, n).attr("aria-required", "true"), i18n.outletAddressHelp).appendTo($grid);
			var $code = textInput("js-oznaka", row.oznaka || "", n).attr("placeholder", i18n.outletCodeEmpty || "");
			if (locked) {
				// D1: a place that has published keeps its code (its price list's address), shown exactly as stored.
				$code.prop("readOnly", true);
			}
			field("oznaka", i18n.outletCode, $code, locked ? i18n.outletCodeLocked : i18n.outletCodeHelp).appendTo($grid);
			field(
				"pohrana",
				i18n.outletSeq,
				// min = the real next number: the counter only goes up (CX-SID-119), so the browser refuses a lower one.
				// A new outlet has none yet: 1.
				textInput("js-pohrana", row.pohrana || 1, n, "number").attr({ min: row.pohrana_min || row.pohrana || 1, step: 1 }),
				i18n.outletSeqHelp
			).appendTo($grid);

			// Products: all of the webshop's published products, or the picked ones (CX-SID-74 dodjela).
			var group = "sidrena-p" + n + "-dodjela";
			var $set = $("<fieldset>", { class: "sidrena-poslovnica__products" }).appendTo($grid);
			$("<legend>").text(i18n.outletProducts || "").appendTo($set);
			var opts = [
				["svi", i18n.outletAll],
				["odabrani", i18n.outletPicked],
			];
			if (woo) {
				opts.push(["bez", i18n.outletNone]);
			}
			opts.forEach(function (opt) {
				var $label = $("<label>", { class: "sidrena-poslovnica__choice" });
				$("<input>", {
					type: "radio",
					class: "js-dodjela",
					name: group,
					id: group + "-" + opt[0],
					value: opt[0],
					checked: opt[0] === dodjela,
					// Only a place with its own rows may have no webshop product: else it would publish a price list
					// with nothing in it (404). A place shown on it keeps it (SPEC-UVOZ §4.0, Argus/Rook t21).
					disabled: "bez" === opt[0] && "bez" !== dodjela && !row.vlastiti,
				}).appendTo($label);
				$label.append(document.createTextNode(" " + (opt[1] || "")));
				$set.append($label);
			});
			if (woo) {
				// Under the third choice: why it is off (T85, T85n), or what it means (T82), with the way to Import (T71).
				$("<p>", { class: "description sidrena-poslovnica__none js-none-note", id: group + "-none" }).appendTo($set);
			}
			var $picker = $("<div>", { class: "sidrena-poslovnica__picker js-picker", hidden: "odabrani" !== dodjela }).appendTo($set);
			productControl(products, n).appendTo($picker);
			$("<span>", { class: "cx-field__msg js-msg", id: group + "-msg", hidden: true }).appendTo($picker);
			$("<span>", { class: "description", id: group + "-help" })
				.text(woo ? i18n.outletSearchHelp || "" : i18n.outletProductsHelp || "")
				.appendTo($picker);
			$("<p>", { class: "description sidrena-poslovnica__prices js-prices" }).text(i18n.outletPrices || "").appendTo($set);
			if (woo) {
				noteTexts($card);
			}

			$("<p>", { class: "sidrena-poslovnica__actions" })
				.append(
					$("<button>", {
						type: "button",
						class: "button-link button-link-delete js-remove-outlet",
					})
						.append($("<span>", { class: "dashicons dashicons-trash", "aria-hidden": "true" }))
						.append(document.createTextNode(i18n.removeOutlet || ""))
				)
				.appendTo($card);

			return $card;
		}

		function addCard(row) {
			var $card = card(row);
			$list.append($card);
			initSelect($card);
			return $card;
		}

		function render(rows) {
			$list.empty();
			rows.forEach(function (row) {
				addCard(row || {});
			});
		}

		// The choice on the form: svi, odabrani, or (with WooCommerce) bez, sent as odabrani with no product.
		function dodjelaOf($card) {
			var v = $card.find(".js-dodjela:checked").val();
			return "svi" === v || "bez" === v ? v : "odabrani";
		}

		function fmtCount(text, args) {
			var next = 0;
			return String(text || "").replace(/%(\d+\$)?[sd]/g, function (m, pos) {
				var k = pos ? parseInt(pos, 10) - 1 : next++;
				return args[k] == null ? "" : String(args[k]);
			});
		}

		function importLink(row) {
			if (!row.id || !row.oznaka || !sidrenaCijenaAdmin.importUrl) {
				return null;
			}
			return $("<a>", { href: sidrenaCijenaAdmin.importUrl + "&za=" + encodeURIComponent(row.oznaka) }).text(i18n.outletImportLink || "");
		}

		// CX-SID-185 (SPEC-UVOZ §4.0, §6): the sentence under the third choice and the one about the place's prices, by
		// the choice on the form: the webshop's products (T70), its own items only (T82), or imported from a file (T72).
		function noteTexts($card) {
			var row = $card.data("row") || {};
			var choice = dodjelaOf($card);
			var $none = $card.find(".js-none-note");
			var $prices = $card.find(".js-prices");
			if (sidrenaCijenaAdmin.istiCjenik) {
				// The same price list for all places is saved on (CX-SID-113): every place publishes the webshop's whole
				// list (T76 above says so), and a place's own price list is refused (T77). Nothing about one here (Norman).
				$none.empty().prop("hidden", true);
				$prices.empty().prop("hidden", true);
				return;
			}
			var off = $card.find(".js-dodjela[value=bez]").prop("disabled");
			$none.empty().prop("hidden", !off);
			if (off) {
				$none.text(row.id ? (i18n.outletNoneLocked || "") + " " : i18n.outletNoneNew || "");
				if (row.id) {
					$none.append(importLink(row));
				}
			}
			var text;
			if ("bez" === choice) {
				text = row.imported && row.imported.on
					? fmtCount(i18n.outletPricesImported, [row.imported.on, row.imported.items || 0])
					: i18n.outletNoneHelp || "";
			} else {
				text = i18n.outletPricesWeb || "";
			}
			$prices.text(text + " ");
			var link = importLink(row);
			if (link) {
				$prices.append(link);
			}
		}

		// What the picker holds now (also while "svi" is chosen: going back to "odabrani" keeps it).
		function pickedProducts($card) {
			var $select = $card.find("select.js-products");
			if ($select.length) {
				var value = $select.val() || [];
				if (!$.isArray(value)) {
					value = [value];
				}
				return value.map(String);
			}

			var ids = [];
			$card.find(".js-products input:checked").each(function () {
				ids.push(String($(this).val()));
			});
			return ids;
		}

		function pickedLabels($card) {
			var out = {};
			$card.find("select.js-products option:selected").each(function () {
				out[String($(this).val())] = $(this).text();
			});
			return out;
		}

		// The API agreed with the server (CX-SID-74 3031/3033/3096): never "stock" (only an import sets it); for
		// "odabrani" the picked products and the ones the page showed (products_loaded), and the server applies only the
		// difference to its fresh list; for "svi" products: [] and the server keeps the stored picks.
		function collectCard($card) {
			var row = $card.data("row") || {};
			var choice = dodjelaOf($card);
			var dodjela = "svi" === choice ? "svi" : "odabrani";
			var out = {
				id: $card.find(".js-id").val() || "",
				naziv: $card.find(".js-naziv").val() || "",
				oblik: $card.find(".js-oblik").val() || "",
				adresa: $card.find(".js-adresa").val() || "",
				oznaka: $card.find(".js-oznaka").val() || "",
				pohrana: $card.find(".js-pohrana").val() || 1,
				dodjela: dodjela,
				products: [],
			};
			if ("odabrani" === choice) {
				out.products = pickedProducts($card);
				out.products_loaded = row.loaded || [];
			} else if ("bez" === choice) {
				// No webshop product: none sent, and every saved pick as shown, so the server's difference
				// (class-poslovnice.php, keep − (loaded − products)) leaves 0, also the picks hidden under "svi" (Rook t13).
				out.products_loaded = row.loaded || [];
			}
			return out;
		}

		function collect() {
			var rows = [];
			$list.find(".sidrena-poslovnica").each(function () {
				rows.push(collectCard($(this)));
			});
			return rows;
		}

		// ---- Checks before sending (the server makes the same ones and decides; CX-SID-74). ----

		function clearMsg($control, $msg) {
			$msg.prop("hidden", true).text("");
			var describedBy = String($control.attr("aria-describedby") || "")
				.split(/\s+/)
				.filter(function (id) {
					return id && id !== $msg.attr("id");
				})
				.join(" ");
			if (describedBy) {
				$control.attr("aria-describedby", describedBy);
			} else {
				$control.removeAttr("aria-describedby");
			}
			$control.removeAttr("aria-invalid").removeClass("cx-field--invalid");
			if ($control[0] && $control[0].setCustomValidity) {
				$control[0].setCustomValidity("");
			}
		}

		function setMsg($control, $msg, text) {
			$msg.text(text).prop("hidden", false);
			var ids = String($control.attr("aria-describedby") || "").split(/\s+/);
			if (ids.indexOf($msg.attr("id")) < 0) {
				ids.unshift($msg.attr("id"));
			}
			$control.attr("aria-describedby", ids.join(" ").trim()).attr("aria-invalid", "true").addClass("cx-field--invalid");
			if ($control[0] && $control[0].setCustomValidity) {
				$control[0].setCustomValidity(text);
			}
		}

		// The element the owner reaches for the products message: the picker's search field, a checkbox, or the
		// "Selected products" choice when there is nothing to check.
		function productsTarget($card) {
			var $search = $card.find(".js-picker .select2-search__field").first();
			if ($search.length) {
				return $search;
			}
			var $check = $card.find(".js-products input[type=checkbox]").first();
			if ($check.length) {
				return $check;
			}
			return $card.find(".js-dodjela[value=odabrani]");
		}

		function clearCard($card) {
			$card.find(".js-naziv, .js-oblik, .js-adresa, .js-oznaka").each(function () {
				var $c = $(this);
				clearMsg($c, $c.siblings(".js-msg"));
			});
			var $pmsg = $card.find(".js-picker > .js-msg");
			$card.find(".js-picker .select2-search__field, .js-products input[type=checkbox], .js-dodjela").each(function () {
				clearMsg($(this), $pmsg);
			});
		}

		// A new card the owner left empty is not a place (the server skips it the same way).
		function isEmptyNew(sent) {
			return !sent.id && "" === sent.naziv.trim() && "" === sent.adresa.trim() && "" === normCode(sent.oznaka) && !sent.products.length;
		}

		// Which code each card may not have: the server's rule (plan_form_save()): the webshop's code and every code that
		// did not change stay; a new or changed code that one of them, or an earlier card, already has is refused (so of
		// two new ones the lower card). A code that did not change is never refused. A changed published code: D1.
		function codeProblems(cards) {
			var taken = {};
			var web = webKey();
			if (web) {
				taken[web] = { webshop: true };
			}
			cards.forEach(function (c) {
				if (c.unchanged) {
					taken[c.key] = { card: c };
				}
			});
			var out = {};
			cards.forEach(function (c) {
				if (c.skip || c.unchanged) {
					return;
				}
				if (c.id && c.row.objavljeno) {
					out[c.n] = { locked: true };
					return;
				}
				if ("" === c.key) {
					return;
				}
				if (taken[c.key]) {
					out[c.n] = { holder: taken[c.key], code: c.key };
				}
				taken[c.key] = { card: c };
			});
			return out;
		}

		// The webshop's label as the server reads it: empty is 001 (CX-SID-74).
		function webKey() {
			return codeKey($webCode.val()) || "001";
		}

		// The webshop's label as it would be saved (its case kept, empty = 001), for the messages (Norman W1).
		function webShown() {
			return normCode($webCode.val()) || "001";
		}

		// The webshop's label as stored (the field's value as the page came; empty = 001).
		function webStoredKey() {
			return codeKey($webCode.length ? $webCode[0].defaultValue : "") || "001";
		}

		// The server's rule (Tux via Rook, 18.11.): oznaka:webshop only when the webshop's label CHANGES (an emptied field
		// is 001) to one a place has. A label both already had stays as it was (not this form's to refuse, Status says
		// it). A new or changed place on the webshop's label gets its own message too (oznaka:N, codeProblems): both marked.
		function webProblem(cards) {
			if (webKey() === webStoredKey()) {
				return null;
			}
			var web = webKey();
			var hit = null;
			cards.forEach(function (c) {
				if (!hit && !c.skip && c.unchanged && c.key === web) {
					hit = c;
				}
			});
			cards.forEach(function (c) {
				if (!hit && !c.skip && "" !== c.key && c.key === web) {
					hit = c;
				}
			});
			return hit ? { card: hit, code: webShown() } : null;
		}

		function webMessage(code, card) {
			var text = card && card.naziv ? fmt(i18n.outletWebCodeTaken, [code, card.naziv]) : fmt(i18n.outletWebCodeOther, [code]);
			// An empty field is the label 001: say so first (Norman W1c).
			if ("" === codeKey($webCode.val())) {
				text = (i18n.outletWebCodeEmpty || "") + " " + text;
			}
			return text;
		}

		function cardsState() {
			var cards = [];
			$list.find(".sidrena-poslovnica").each(function (n) {
				var $card = $(this);
				var row = $card.data("row") || {};
				var sent = collectCard($card);
				var key = codeKey(sent.oznaka);
				cards.push({
					n: n,
					$card: $card,
					row: row,
					sent: sent,
					choice: dodjelaOf($card),
					id: sent.id,
					key: key,
					naziv: sent.naziv.trim(),
					skip: isEmptyNew(sent),
					unchanged: !!sent.id && "" !== key && key === codeKey(row.oznaka),
				});
			});
			return cards;
		}

		function codeMessage(problem) {
			if (problem.locked) {
				return i18n.outletCodeLocked || "";
			}
			var holder = problem.holder || {};
			if (holder.webshop) {
				return fmt(i18n.outletCodeWebshop, [problem.code]);
			}
			if (holder.card && holder.card.naziv) {
				return fmt(i18n.outletCodeTaken, [problem.code, holder.card.naziv]);
			}
			return fmt(i18n.outletCodeOther, [problem.code]);
		}

		// Marks every problem; returns the elements to reach, top to bottom.
		function check() {
			var cards = cardsState();
			var codes = codeProblems(cards);
			var wrong = [];
			clearMsg($webCode, $webMsg);
			var web = webProblem(cards);
			if (web) {
				setMsg($webCode, $webMsg, webMessage(web.code, web.card));
				wrong.push($webCode);
			}
			cards.forEach(function (c) {
				clearCard(c.$card);
				if (c.skip) {
					return;
				}
				var $oblik = c.$card.find(".js-oblik");
				var $adresa = c.$card.find(".js-adresa");
				var $code = c.$card.find(".js-oznaka");
				if ("" === c.sent.oblik.trim()) {
					setMsg($oblik, $oblik.siblings(".js-msg"), i18n.outletNoOblik || "");
					wrong.push($oblik);
				}
				if ("" === c.sent.adresa.trim()) {
					setMsg($adresa, $adresa.siblings(".js-msg"), i18n.outletNoAddress || "");
					wrong.push($adresa);
				}
				if (codes[c.n]) {
					setMsg($code, $code.siblings(".js-msg"), codeMessage(codes[c.n]));
					wrong.push($code);
				}
				// A place with manual rows of its own has a price list without any product (Norman, M4).
				if ("odabrani" === c.choice && !c.sent.products.length && !c.row.vlastiti) {
					var $target = productsTarget(c.$card);
					setMsg($target, c.$card.find(".js-picker > .js-msg"), i18n.outletNoProducts || "");
					c.$card.find(".js-picker > .js-msg").prop("hidden", false);
					wrong.push($target);
				}
			});
			return wrong;
		}

		// The first wrong field gets the focus in the middle of the screen (clear of the admin bar) and the browser's
		// bubble with the same text.
		function reach($el) {
			var el = $el && $el[0];
			if (!el) {
				return;
			}
			if (el.scrollIntoView) {
				el.scrollIntoView({ block: "center" });
			}
			if (el.focus) {
				el.focus({ preventScroll: true });
			}
			if (el.reportValidity) {
				el.reportValidity();
			}
		}

		function webshopFields() {
			return {
				oblik: $("#sidrena_cijena_file_oblik").val(),
				oznaka: $("#sidrena_cijena_file_oznaka").val(),
				pohrana: $("#sidrena_cijena_file_pohrana").val(),
			};
		}

		function remember() {
			var cards = [];
			$list.find(".sidrena-poslovnica").each(function () {
				var $card = $(this);
				var row = $card.data("row") || {};
				var sent = collectCard($card);
				cards.push({
					id: sent.id,
					naziv: sent.naziv,
					oblik: sent.oblik,
					adresa: sent.adresa,
					oznaka: sent.oznaka,
					pohrana: sent.pohrana,
					dodjela: dodjelaOf($card),
					products: pickedProducts($card),
					products_loaded: row.loaded || [],
					labels: pickedLabels($card),
					objavljeno: !!row.objavljeno,
					stored_oznaka: row.oznaka || "",
				});
			});
			try {
				window.sessionStorage.setItem(
					SENT_KEY,
					JSON.stringify({ at: Date.now(), cards: cards, loaded: readLoaded(), web: webshopFields() })
				);
			} catch (e) {
				// No storage (full or off): a refused save then shows the stored state (S2).
			}
		}

		function readLoaded() {
			try {
				var ids = JSON.parse($loaded.val() || "[]");
				return $.isArray(ids) ? ids.map(String) : [];
			} catch (e) {
				return [];
			}
		}

		// Read once: whatever the page after a save, the kept copy is gone after it.
		// The reason of a refused save is read once, then taken out of the address (other parameters and the #part stay),
		// so a reload shows the stored state without the message (Norman, CX-SID-142 SHOULD 1). Not through WordPress's
		// removable_query_args: its script in the head would take it out before this one could read it.
		function dropRefusalFromAddress() {
			if (!window.history || "function" !== typeof window.history.replaceState) {
				return;
			}
			var kept = window.location.search
				.replace(/^\?/, "")
				.split("&")
				.filter(function (part) {
					return "" !== part && "cx_outlet_error" !== part.split("=")[0];
				});
			try {
				window.history.replaceState(
					window.history.state,
					"",
					window.location.pathname + (kept.length ? "?" + kept.join("&") : "") + window.location.hash
				);
			} catch (e) {
				// An address the browser will not rewrite: the message comes back on a reload, nothing else changes.
			}
		}

		function takeSent() {
			var raw = null;
			try {
				raw = window.sessionStorage.getItem(SENT_KEY);
				window.sessionStorage.removeItem(SENT_KEY);
			} catch (e) {
				return null;
			}
			if (!raw) {
				return null;
			}
			try {
				var sent = JSON.parse(raw);
				if (!sent || !$.isArray(sent.cards) || !(Date.now() - (sent.at || 0) < SENT_MAX_AGE)) {
					return null;
				}
				return sent;
			} catch (e) {
				return null;
			}
		}

		// cx_outlet_error=adresa:N|vrsta:N|oznaka:N|zauzeto (N = the card's place in the sent list), or isti:CODE (CX-SID-113:
		// the same price list for all places refused while the place CODE has its own prices).
		function parseRefusal(text) {
			var out = [];
			String(text || "")
				.split("|")
				.forEach(function (part) {
					var m = /^(adresa|vrsta|oznaka):(\d+)$/.exec(part.trim());
					var isti = /^isti:([A-Za-z0-9-]+)$/.exec(part.trim());
					if (isti) {
						out.push({ reason: "isti", code: isti[1] });
					} else if (m) {
						out.push({ reason: m[1], n: parseInt(m[2], 10) });
					} else if ("oznaka:webshop" === part.trim()) {
						out.push({ reason: "webshop" });
					} else if ("nepotvrdeno" === part.trim()) {
						out.push({ reason: "nepotvrdeno" });
					} else if ("zauzeto" === part.trim()) {
						out.push({ reason: "zauzeto" });
					}
				});
			return out;
		}

		function showRefused(text) {
			if ($refused.length && text) {
				$refused.text(text).prop("hidden", false);
			}
		}

		// T75 (Norman, CX-SID-113) with the place's name as stored (its code when it has none, or when it is not shown).
		function istiText(reasons, rows) {
			var hit = null;
			reasons.forEach(function (r) {
				if (!hit && "isti" === r.reason) {
					hit = r;
				}
			});
			if (!hit) {
				return "";
			}
			var name = hit.code;
			(rows || []).forEach(function (row) {
				if (row && String(row.oznaka) === hit.code && row.naziv) {
					name = String(row.naziv);
				}
			});
			return fmt(i18n.istiRefused || "", [name]);
		}

		// The page after a refused save: what was sent comes back, each reason next to its place (S1), or, when only
		// "zauzeto", Norman's T13. Without the kept copy, the stored state and one reason (S2).
		function restore(sent, reasons, rows) {
			var now = {};
			rows.forEach(function (row) {
				if (row && row.id) {
					now[String(row.id)] = row;
				}
			});
			var cards = sent.cards.map(function (c) {
				var cur = c.id ? now[String(c.id)] : null;
				var row = $.extend({}, c);
				$.each(c.labels || {}, function (id, text) {
					if (!labels[id]) {
						labels[id] = text;
					}
				});
				if (cur) {
					// What the page knows now: published since (D1), the real next storage number.
					row.objavljeno = !!cur.objavljeno;
					row.vlastiti = !!cur.vlastiti;
					row.imported = cur.imported || null;
					row.stored_oznaka = cur.oznaka;
					row.pohrana_min = cur.pohrana;
					row.pohrana = Math.max(parseInt(c.pohrana, 10) || 1, parseInt(cur.pohrana, 10) || 1);
					if (row.objavljeno && codeKey(c.oznaka) !== codeKey(cur.oznaka)) {
						row.oznaka = cur.oznaka;
						row.code_locked_now = true;
					}
				} else {
					row.pohrana_min = 1;
				}
				return row;
			});
			render(cards);
			$loaded.val(JSON.stringify($.isArray(sent.loaded) ? sent.loaded : readLoaded()));
			if (sent.web) {
				$("#sidrena_cijena_file_oblik").val(sent.web.oblik);
				$("#sidrena_cijena_file_oznaka").val(sent.web.oznaka);
				var $wp = $("#sidrena_cijena_file_pohrana");
				var min = parseInt($wp.attr("min"), 10) || 1;
				$wp.val(Math.max(min, parseInt(sent.web.pohrana, 10) || 1));
			}

			var $cards = $list.find(".sidrena-poslovnica");
			var state = cardsState();
			var codes = codeProblems(state);
			var other = false;
			var isti = istiText(reasons, rows);
			reasons.forEach(function (r) {
				if ("zauzeto" === r.reason || "isti" === r.reason) {
					return;
				}
				other = true;
				if ("webshop" === r.reason) {
					// The webshop's label (as sent) is a place's: that place by name if this page shows it.
					var holder = null;
					state.forEach(function (c) {
						if (!holder && !c.skip && c.key === webKey()) {
							holder = c;
						}
					});
					setMsg($webCode, $webMsg, webMessage(webShown(), holder) || "");
					return;
				}
				var $card = $cards.eq(r.n);
				if (!$card.length) {
					return;
				}
				var $c;
				var text;
				if ("adresa" === r.reason) {
					$c = $card.find(".js-adresa");
					text = i18n.outletNoAddress;
				} else if ("vrsta" === r.reason) {
					$c = $card.find(".js-oblik");
					text = i18n.outletNoOblik;
				} else {
					$c = $card.find(".js-oznaka");
					var problem;
					if (cards[r.n] && cards[r.n].code_locked_now) {
						problem = { locked: true }; // published meanwhile: its code stays (D1), the field shows it
					} else if (codes[r.n] && !codes[r.n].locked) {
						problem = codes[r.n];
					} else {
						// Taken by a place this page does not show (added meanwhile in another tab).
						problem = { holder: {}, code: codeKey($c.val()) };
					}
					text = codeMessage(problem);
				}
				setMsg($c, $c.siblings(".js-msg"), text || "");
			});
			var webToo = reasons.some(function (r) {
				return "webshop" === r.reason;
			});
			showRefused(isti || (webToo ? i18n.outletsRefusedFields : other || !reasons.length ? i18n.outletsRefused : i18n.outletsBusy));
		}

		// S2 (Norman 3132): each kind of reason once, in the order address, type, label, joined by a space. "zauzeto"
		// alone: no line (as T13, which needs the sent values).
		function storedOnly(reasons) {
			var has = {};
			reasons.forEach(function (r) {
				has[r.reason] = true;
			});
			var why = [];
			if (has.adresa) {
				why.push(i18n.outletsWhyAddress || "");
			}
			if (has.vrsta) {
				why.push(i18n.outletsWhyOblik || "");
			}
			if (has.oznaka) {
				why.push(i18n.outletsWhyCode || "");
			}
			if (has.webshop) {
				why.push(i18n.outletsWhyWebCode || "");
			}
			if (!why.length) {
				return;
			}
			showRefused(fmt(i18n.outletsRefusedStored, [why.join(" ")]));
		}

		// The stored places as the page got them. If they cannot be read, saving stays off: an empty list sent by Save
		// would delete every place (CX-SID-142).
		var initial = null;
		try {
			initial = JSON.parse($json.val());
		} catch (e) {
			initial = null;
		}
		if (!$.isArray(initial)) {
			$("#sidrena-poslovnice-unreadable, #sidrena-poslovnice-saveoff").prop("hidden", false);
			$form
				.find("button[type=submit], input[type=submit], #sidrena-poslovnica-add")
				.prop("disabled", true)
				.attr("aria-describedby", "sidrena-poslovnice-unreadable");
			$form.on("submit", function (e) {
				e.preventDefault();
			});
			return;
		}

		var refusal = null;
		try {
			refusal = new URLSearchParams(window.location.search).get("cx_outlet_error");
		} catch (e) {
			refusal = null;
		}
		if (null !== refusal) {
			dropRefusalFromAddress();
		}
		// Places the page showed (stored, or loaded ids): only then can a save with no place in it mean "all removed".
		var shownAny = initial.length > 0 || readLoaded().length > 0;
		var sent = takeSent();
		var reasons = null !== refusal ? parseRefusal(refusal) : [];
		var unconfirmed = reasons.some(function (r) {
			return "nepotvrdeno" === r.reason;
		});
		if (unconfirmed) {
			// The server refused a save from a page that did not confirm it loaded (Tux, CX-SID-142): what it sent is not
			// put back, it came from a page that did not load. takeSent() already removed the kept copy (places and the
			// webshop's fields), so a reload of this address shows the stored state too (Norman C3).
			render(initial);
			showRefused(i18n.outletsUnconfirmed || "");
		} else if (null !== refusal && sent) {
			restore(sent, reasons, initial);
		} else {
			render(initial);
			if (null !== refusal) {
				storedOnly(reasons);
				showRefused(istiText(reasons, initial));
			}
		}
		// T75 (CX-SID-113): the save that turned the same price list for all places on was refused. The page draws the
		// stored value (off), but the box was sent ticked and T75 says to turn it off: show it as sent (Norman SHOULD 1).
		if (
			reasons.some(function (r) {
				return "isti" === r.reason;
			})
		) {
			$("#sidrena_cijena_isti_cjenik").prop("checked", true);
		}

		$("#sidrena-poslovnica-add").on("click", function () {
			var $card = addCard({ oblik: "prodavaonica", oznaka: "", pohrana: 1, dodjela: "svi", products: [] });
			$card.find(".js-naziv").trigger("focus");
		});

		$list.on("click", ".js-remove-outlet", function () {
			$(this).closest(".sidrena-poslovnica").remove();
		});

		$list.on("change", ".js-dodjela", function () {
			var $card = $(this).closest(".sidrena-poslovnica");
			$card.find(".js-picker").prop("hidden", "odabrani" !== dodjelaOf($card));
			var $pmsg = $card.find(".js-picker > .js-msg");
			$card.find(".js-picker .select2-search__field, .js-products input[type=checkbox], .js-dodjela").each(function () {
				clearMsg($(this), $pmsg);
			});
			if (woo) {
				noteTexts($card);
			}
		});

		// A field the owner changes loses its message; the next Save checks everything again.
		$webCode.on("input change", function () {
			clearMsg($webCode, $webMsg);
		});

		$list.on("input change", ".js-naziv, .js-oblik, .js-adresa, .js-oznaka", function () {
			clearMsg($(this), $(this).siblings(".js-msg"));
		});
		$list.on("change", "select.js-products, .js-products input[type=checkbox]", function () {
			var $card = $(this).closest(".sidrena-poslovnica");
			var $pmsg = $card.find(".js-picker > .js-msg");
			$card.find(".js-picker .select2-search__field, .js-products input[type=checkbox], .js-dodjela").each(function () {
				clearMsg($(this), $pmsg);
			});
		});

		// A picker left open (e.g. after removing a product with its x): selectWoo closes it on the body's mousedown and
		// then focuses its search field, which scrolls the page to it, so the button is no longer under the pointer at
		// mouseup and the click never happens (3239, Argus SHOULD 1; selectWoo.full.js:1480 and :1984). The press on Save
		// therefore does not reach the body while a picker is open; the click closes the pickers itself, after sending
		// or after the checks.
		function openPickers() {
			return $list.find("select.js-products").filter(function () {
				var s = $(this).data("select2");
				return !!(s && s.isOpen && s.isOpen());
			});
		}
		$form.on("mousedown", "button[type=submit], input[type=submit]", function (e) {
			if (openPickers().length) {
				e.stopPropagation();
			}
		});

		// Before the browser's own checks (a click, also Enter in a field): the browser then shows its bubble only for
		// what this form says, never an old message.
		$form.on("click", "button[type=submit], input[type=submit]", function (e) {
			var wrong = check();
			if (wrong.length) {
				e.preventDefault();
				var $open = openPickers();
				if (!$open.length) {
					reach(wrong[0]);
					return;
				}
				// Closing focuses the picker again a moment later (BaseSelection close, setTimeout 1): reach after it.
				$open.selectWoo("close");
				window.setTimeout(function () {
					reach(wrong[0]);
				}, 20);
			}
		});

		$form.on("submit", function (e) {
			var wrong = check();
			if (wrong.length) {
				e.preventDefault();
				reach(wrong[0]);
				return;
			}
			$refused.prop("hidden", true).text("");
			$json.val(JSON.stringify(collect()));
			// Set here, never at load: the server stores nothing from a page that did not get this far (Tux, 142).
			$("#sidrena-poslovnice-ok").val("1");
			// Every place removed on purpose: the page showed places and no card has anything (an empty new card is
			// skipped by the server too).
			var kept = cardsState().some(function (c) {
				return !c.skip;
			});
			$("#sidrena-poslovnice-cleared").val(shownAny && !kept ? "1" : "");
			remember();
		});
	}

	$(function () {
		bindPoslovnice();
	});
})(jQuery);
