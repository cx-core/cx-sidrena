/**
 * CX Sidrena: the bazna cijena line on the product and variation screens (CX-SID-24).
 * Change opens the three fields and enables them; closed, they are disabled again, so nothing is posted and a save can
 * never change the bazna by accident. Delegated, because WooCommerce loads variations by ajax.
 */
(function ($) {
	"use strict";

	$(document).on("click", ".cx-bazna__toggle", function () {
		var $box = $(this).closest(".cx-bazna");
		var $edit = $box.children(".cx-bazna__edit");
		var open = $edit.prop("hidden");

		$edit.prop("hidden", !open);
		$edit.find(":input").prop("disabled", !open);
		$(this).attr("aria-expanded", open ? "true" : "false");

		// In a variation, WooCommerce saves only variations it marked as changed: a change event on an input does that.
		if ($box.closest(".woocommerce_variation").length) {
			$edit.find("input[name$='[open]']").trigger("change");
		}
		if (open) {
			$edit.find(".wc_input_price").first().trigger("focus");
		}
	});

	// WooCommerce's own date picker, in the form the owner reads dates: 10.9.2026.
	$(document).on("focus", ".cx-bazna__date", function () {
		var $input = $(this);
		if ($input.hasClass("hasDatepicker") || !$.fn.datepicker) {
			return;
		}
		$input.datepicker({ dateFormat: "d.m.yy.", firstDay: 1, changeMonth: true, changeYear: true, onSelect: function () {
			$input.trigger("change");
		} });
		$input.datepicker("show");
	});
})(jQuery);
