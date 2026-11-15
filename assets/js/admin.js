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
		return date.replace(/\.+$/, "") + ".";
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

			$code.text(label + (prefix ? ": " : " ") + "19,99 €");
		}

		$(document).on(
			"input change",
			"#sidrena_cijena_prefix, #sidrena_cijena_default_date, input[name='sidrena_cijena_date_style']",
			updatePreview
		);

		updatePreview();
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


	function formatProgress(seen, total) {
		var tpl = sidrenaCijenaAdmin.i18n.progress || "Processing… %1$s / %2$s";
		return tpl.replace("%1$s", String(seen)).replace("%2$s", String(total));
	}

	function bindSnapshot() {
		var $buttons = $(".sidrena-cijena-snapshot-btn");
		var $status = $(".sidrena-cijena-snapshot-status");
		var $progress = $("#sidrena-snapshot-progress");
		var $bar = $("#sidrena-snapshot-bar");
		var resume = null;

		if (!$buttons.length || typeof sidrenaCijenaAdmin === "undefined") {
			return;
		}

		function setProgress(seen, total, done) {
			var percent = 0;
			if (done) {
				percent = 100;
			} else if (total > 0) {
				percent = Math.max(0, Math.min(100, Math.round((seen / total) * 100)));
			}
			$progress.removeAttr("hidden");
			$bar.css("width", percent + "%");
		}

		$buttons.on("click", function () {
			var stampToday = this.id === "sidrena-cijena-run-snapshot-today";
			var page = 1;
			var offset = 0;
			var child = 0;
			var attempts = 0;
			var doneText = stampToday
				? sidrenaCijenaAdmin.i18n.doneToday
				: sidrenaCijenaAdmin.i18n.done;

			if (stampToday && !window.confirm(sidrenaCijenaAdmin.i18n.todayConfirm)) {
				return;
			}

			if (resume && resume.stampToday === stampToday) {
				page = resume.page;
				offset = resume.offset;
				child = resume.child;
			}

			$buttons.prop("disabled", true);
			$status.text(sidrenaCijenaAdmin.i18n.running);
			setProgress(0, 0, false);

			function remember() {
				resume = {
					stampToday: stampToday,
					page: page,
					offset: offset,
					child: child,
				};
			}

			function runBatch() {
				$.ajax({
					url: sidrenaCijenaAdmin.ajaxUrl,
					method: "POST",
					timeout: 90000,
					data: {
						action: "sidrena_cijena_snapshot",
						nonce: sidrenaCijenaAdmin.nonce,
						page: page,
						offset: offset,
						child: child,
						stamp_today: stampToday ? "1" : "0",
					},
				})
					.done(function (response) {
						if (!response || !response.success || !response.data) {
							failBatch();
							return;
						}

						attempts = 0;
						var data = response.data;
						var seen = parseInt(data.seen, 10) || 0;
						var total = parseInt(data.total, 10) || 0;

						if (data.done) {
							resume = null;
							setProgress(total || seen, total || seen, true);
							$status.text(doneText);
							$buttons.prop("disabled", false);
							return;
						}

						page = parseInt(data.next_page, 10) || page;
						offset = parseInt(data.next_offset, 10) || 0;
						child = parseInt(data.next_child, 10) || 0;
						remember();
						setProgress(seen, total, false);
						$status.text(formatProgress(seen, total));
						runBatch();
					})
					.fail(function () {
						failBatch();
					});
			}

			function failBatch() {
				attempts += 1;
				remember();
				if (attempts < 5) {
					$status.text(sidrenaCijenaAdmin.i18n.retry || sidrenaCijenaAdmin.i18n.running);
					window.setTimeout(runBatch, 1200 * attempts);
					return;
				}
				$status.text(sidrenaCijenaAdmin.i18n.stalled || sidrenaCijenaAdmin.i18n.error);
				$buttons.prop("disabled", false);
			}

			runBatch();
		});
	}


	$(function () {
		bindPrefixPreview();
		bindTooltipToggle();
		bindCjm();

		$(document).on("change", ".sidrena-cijena-cjm-toggle", function () {
			syncCjm($(this));
		});

		$("#woocommerce-product-data").on(
			"woocommerce_variations_loaded woocommerce_variations_added",
			bindCjm
		);

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

		bindSnapshot();
	});
})(jQuery);
