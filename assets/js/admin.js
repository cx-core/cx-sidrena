(function ($) {
	"use strict";

	function syncCjm($toggle) {
		var $wrap = $toggle.closest(".sidrena-cijena-cjm, .sidrena-cijena-variation-fields, .sidrena-cijena-admin-fields");
		var $suffix = $wrap.find(".sidrena-cijena-cjm-suffix");
		if ($toggle.is(":checked")) {
			$suffix.removeAttr("hidden").show();
		} else {
			$suffix.attr("hidden", "hidden").hide();
		}
	}

	function stripDateFromPrefix(prefix) {
		return String(prefix || "")
			.replace(/\s*\(\s*\d{1,2}\.\d{1,2}\.\d{4}\.?\s*\)\s*/g, "")
			.replace(/\s*:\s*\d{1,2}\.\d{1,2}\.\d{4}\.?\s*/g, "")
			.replace(/\s+\d{1,2}\.\d{1,2}\.\d{4}\.?\s*/g, " ")
			.replace(/\s+/g, " ")
			.trim();
	}

	function normalizePreviewDate(date) {
		date = $.trim(String(date || ""));
		if (!date) {
			return "";
		}
		// As the customer sees it: 10.9.2026. (the stored date keeps its leading zeros).
		return date
			.replace(/\.+$/, "")
			.split(".")
			.map(function (part) {
				return part.replace(/^0+(?=\d)/, "");
			})
			.join(".") + ".";
	}

	function bindPrefixPreview() {
		var $code = $("#sidrena-cijena-preview");
		if (!$code.length) {
			return;
		}

		function updatePreview() {
			var prefix = stripDateFromPrefix($("#sidrena_cijena_prefix").val());
			var date = normalizePreviewDate($("#sidrena_cijena_default_date").val());
			var style = $('input[name="sidrena_cijena_date_style"]:checked').val();

			if (!prefix && style !== "plain") {
				prefix = (window.sidrenaCijenaAdmin && sidrenaCijenaAdmin.defaultPrefix) || "Bazna cijena";
			}

			var label = prefix;
			if (date) {
				if (!prefix) {
					label = date;
				} else {
					label = style === "plain" ? prefix + " " + date : prefix + " (" + date + ")";
				}
			}

			$code.text(label + (prefix ? ": " : " ") + "19,90 €");
		}

		$(document).on(
			"input change",
			"#sidrena_cijena_prefix, #sidrena_cijena_default_date, input[name='sidrena_cijena_date_style']",
			updatePreview
		);

		updatePreview();
	}

	// Colour of the lines (CX-SID-41): the preview takes the own colour; under 4.5:1 on white, one line of warning (saving
	// is not blocked). Picking a colour chooses "Own colour".
	function bindLineColor() {
		var $color = $("#cx_line_color");
		if (!$color.length) {
			return;
		}
		var $line = $("#sidrena-cijena-preview");
		var $warn = $("#cx-line-color-warn");

		function onWhite(hex) {
			var m = /^#([0-9a-f]{2})([0-9a-f]{2})([0-9a-f]{2})$/i.exec(hex || "");
			if (!m) {
				return 21;
			}
			var l = [m[1], m[2], m[3]].map(function (h) {
				var v = parseInt(h, 16) / 255;
				return v <= 0.03928 ? v / 12.92 : Math.pow((v + 0.055) / 1.055, 2.4);
			});
			return 1.05 / (0.2126 * l[0] + 0.7152 * l[1] + 0.0722 * l[2] + 0.05);
		}

		function sync() {
			var own = $('input[name="cx_line_color_mode"]:checked').val() === "own";
			$line.css("color", own ? $color.val() : "");
			$warn.prop("hidden", !(own && onWhite($color.val()) < 4.5));
		}

		$color.on("input change", function () {
			$('input[name="cx_line_color_mode"][value="own"]').prop("checked", true);
			sync();
		});
		$(document).on("change", 'input[name="cx_line_color_mode"]', sync);
		sync();
	}

	function bindTooltipToggle() {
		var $toggle = $("#sidrena_cijena_enable_tooltip");
		if (!$toggle.length) {
			return;
		}

		function sync() {
			$(".sidrena-cijena-tooltip-fields").prop("hidden", !$toggle.is(":checked"));
		}

		$toggle.on("change", sync);
		sync();
	}

	function bindCjm() {
		$(".sidrena-cijena-cjm-toggle").each(function () {
			syncCjm($(this));
		});
	}

	function copyText(text) {
		if (navigator.clipboard && window.isSecureContext) {
			return navigator.clipboard.writeText(text);
		}

		var deferred = $.Deferred();
		var $ta = $("<textarea>")
			.css({ position: "fixed", left: "-9999px", top: "0" })
			.val(text)
			.appendTo("body");
		$ta[0].select();
		try {
			document.execCommand("copy");
			deferred.resolve();
		} catch (e) {
			deferred.reject(e);
		}
		$ta.remove();
		return deferred.promise();
	}


	$(function () {
		bindPrefixPreview();
		bindLineColor();
		bindTooltipToggle();
		bindCjm();

		$(document).on("change", ".sidrena-cijena-cjm-toggle", function () {
			syncCjm($(this));
		});

		$("#woocommerce-product-data").on(
			"woocommerce_variations_loaded woocommerce_variations_added",
			bindCjm
		);

		// Net quantity (CX-SID-38, Norman SPEC §2.3): the status line shows what was saved. Once the number or the unit
		// changes, it says the new unit price shows after saving, so the old figure is not read as current. Nothing is
		// worked out here. Delegated: a size's panel is loaded later.
		$(document).on("input change", ".cx-neto__q, .cx-neto__u", function () {
			var $box = $(this).closest(".cx-neto");
			var text = $box.attr("data-stale");
			if (!text) {
				return;
			}
			var $status = $box.find(".cx-neto-status");
			if (!$status.length) {
				$status = $('<p class="description cx-neto-status"></p>').appendTo($box.find(".cx-neto__help"));
			}
			$status.text(text).addClass("cx-neto-status--stale");
		});

		$(document).on("click", ".sidrena-cijena-copy", function () {
			var $btn = $(this);
			var text = $btn.attr("data-copy") || $btn.data("copy") || "";
			var html = $btn.html();
			var done =
				typeof sidrenaCijenaAdmin !== "undefined" && sidrenaCijenaAdmin.i18n
					? sidrenaCijenaAdmin.i18n.copied
					: "Kopirano";

			if (!text) {
				return;
			}

			copyText(String(text)).then(function () {
				$btn.text(done);
				window.setTimeout(function () {
					$btn.html(html);
				}, 1600);
			});
		});

		openAdvancedForHash();
		$(window).on("hashchange", openAdvancedForHash);

		// Four forms on one page: Outlets and Additional items save only themselves. Changes in the main Settings form
		// would be lost without a word, so ask first (Norman, CX-SID-23).
		var mainDirty = false;
		$(document).on("input change", "[form='cx-settings-form'], #cx-settings-form :input", function () {
			mainDirty = true;
		});
		$(document).on("submit", "#sidrena-poslovnice-form, #sidrena-cijena-catalog-form", function (e) {
			var msg = window.sidrenaCijenaAdmin && sidrenaCijenaAdmin.unsavedConfirm;
			if (mainDirty && msg && !window.confirm(msg)) {
				e.preventDefault();
			}
		});
	});

	// Settings: a link to a section inside "Show advanced settings" (an old tab's address, a save that returns there)
	// opens it first, so the browser can scroll to it.
	function openAdvancedForHash() {
		var details = document.getElementById("cx-advanced");
		var id = window.location.hash.slice(1);
		if (!details || !id) {
			return;
		}
		var target = document.getElementById(id);
		if (target && details.contains(target) && !details.open) {
			details.open = true;
			target.scrollIntoView();
		}
	}
})(jQuery);
