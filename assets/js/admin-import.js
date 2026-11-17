(function ($) {
	"use strict";

	function bindBulkImport() {
		var $root = $("#sidrena-cijena-bulk-import");
		if (!$root.length || typeof sidrenaCijenaAdmin === "undefined") {
			return;
		}

		var i18n = sidrenaCijenaAdmin.i18n || {};
		var $file = $("#sidrena-import-file");
		var $drop = $("#sidrena-import-drop");
		var $meta = $("#sidrena-import-filemeta");
		var $analyze = $("#sidrena-import-analyze");
		var $map = $("#sidrena-import-map");
		var $preview = $("#sidrena-import-preview");
		var $progress = $("#sidrena-import-progress");
		var $bar = $("#sidrena-import-bar");
		var $status = $("#sidrena-import-status");
		var $result = $("#sidrena-import-result");
		var $run = $("#sidrena-import-run");
		var job = null;

		function setFileMeta(file) {
			if (!file) {
				$meta.attr("hidden", "hidden").text("");
				return;
			}
			$meta.removeAttr("hidden").text(file.name + " · " + Math.max(1, Math.round(file.size / 1024)) + " KB");
		}

		function fillSelect($select, fields, guessed, allowEmpty) {
			var html = "";
			if (allowEmpty) {
				html += '<option value="">' + $("<div>").text(i18n.noImport || "Do not import").html() + "</option>";
			} else {
				html += '<option value="">' + $("<div>").text(i18n.chooseSku || "Select SKU field").html() + "</option>";
			}
			fields.forEach(function (field) {
				html +=
					'<option value="' +
					$("<div>").text(field).html() +
					'"' +
					(guessed === field ? " selected" : "") +
					">" +
					$("<div>").text(field).html() +
					"</option>";
			});
			$select.html(html);
		}

		function renderPreview(fields, rows) {
			if (!rows || !rows.length) {
				$preview.empty();
				return;
			}
			var html = "<table><thead><tr>";
			fields.forEach(function (field) {
				html += "<th>" + $("<div>").text(field).html() + "</th>";
			});
			html += "</tr></thead><tbody>";
			rows.forEach(function (row) {
				html += "<tr>";
				fields.forEach(function (field) {
					html += "<td>" + $("<div>").text(row[field] || "").html() + "</td>";
				});
				html += "</tr>";
			});
			html += "</tbody></table>";
			$preview.html(html);
		}

		function fail(message) {
			$progress.attr("hidden", "hidden");
			$result.removeAttr("hidden").attr("class", "sidrena-cijena-import-result is-error").text(message || i18n.error);
			$analyze.prop("disabled", false);
			$run.prop("disabled", false);
		}

		function setProgress(percent, text) {
			$progress.removeAttr("hidden");
			$bar.css("width", Math.max(0, Math.min(100, percent)) + "%");
			$status.text(text || "");
		}

		$drop.on("dragover dragenter", function (event) {
			event.preventDefault();
			$drop.addClass("is-dragover");
		});

		$drop.on("dragleave drop", function () {
			$drop.removeClass("is-dragover");
		});

		$drop.on("drop", function (event) {
			event.preventDefault();
			var files = event.originalEvent && event.originalEvent.dataTransfer
				? event.originalEvent.dataTransfer.files
				: null;
			if (files && files[0]) {
				$file.prop("files", files);
				setFileMeta(files[0]);
				$map.attr("hidden", "hidden");
				$result.attr("hidden", "hidden");
			}
		});

		$file.on("change", function () {
			setFileMeta(this.files && this.files[0] ? this.files[0] : null);
			$map.attr("hidden", "hidden");
			$result.attr("hidden", "hidden");
			$progress.attr("hidden", "hidden");
			job = null;
		});

		$analyze.on("click", function () {
			var file = $file[0] && $file[0].files ? $file[0].files[0] : null;
			if (!file) {
				fail(i18n.noFile);
				return;
			}

			var data = new FormData();
			data.append("action", "sidrena_cijena_import_parse");
			data.append("nonce", sidrenaCijenaAdmin.importNonce);
			data.append("file", file);

			$analyze.prop("disabled", true);
			$result.attr("hidden", "hidden");
			setProgress(12, i18n.analyzing);

			$.ajax({
				url: sidrenaCijenaAdmin.ajaxUrl,
				method: "POST",
				data: data,
				processData: false,
				contentType: false,
			})
				.done(function (response) {
					$analyze.prop("disabled", false);
					if (!response || !response.success || !response.data) {
						fail(response && response.data && response.data.message ? response.data.message : i18n.error);
						return;
					}

					job = response.data;
					var standalone = !!(sidrenaCijenaAdmin && sidrenaCijenaAdmin.standalone);
					$(".sidrena-import-standalone-only").prop("hidden", !standalone);
					$(".sidrena-import-woo-only").prop("hidden", standalone);
					fillSelect($("#sidrena-import-map-sku"), job.fields, job.guessed && job.guessed.sku, true);
					fillSelect($("#sidrena-import-map-id"), job.fields, job.guessed && job.guessed.id, true);
					fillSelect($("#sidrena-import-map-name"), job.fields, job.guessed && job.guessed.name, true);
					fillSelect($("#sidrena-import-map-url"), job.fields, job.guessed && job.guessed.url, true);
					fillSelect($("#sidrena-import-map-regular"), job.fields, job.guessed && job.guessed.regular, true);
					fillSelect($("#sidrena-import-map-sale"), job.fields, job.guessed && job.guessed.sale, true);
					fillSelect($("#sidrena-import-map-sidrena"), job.fields, job.guessed && job.guessed.sidrena, true);
					fillSelect($("#sidrena-import-map-datum"), job.fields, job.guessed && job.guessed.datum, true);
					fillSelect($("#sidrena-import-map-vrsta"), job.fields, job.guessed && job.guessed.vrsta, true);
					fillSelect($("#sidrena-import-map-poslovnica"), job.fields, job.guessed && job.guessed.poslovnica, true);
					fillSelect($("#sidrena-import-map-jedinica"), job.fields, job.guessed && job.guessed.jedinica, true);
					fillSelect($("#sidrena-import-map-jedinica-cijena"), job.fields, job.guessed && job.guessed.jedinica_cijena, true);
					fillSelect($("#sidrena-import-map-marka"), job.fields, job.guessed && job.guessed.marka, true);
					fillSelect($("#sidrena-import-map-oblik"), job.fields, job.guessed && job.guessed.oblik, true);
					fillSelect($("#sidrena-import-map-cjm"), job.fields, job.guessed && job.guessed.cjm, true);
					fillSelect($("#sidrena-import-map-cjm-suffix"), job.fields, job.guessed && job.guessed.cjm_suffix, true);
					fillSelect($("#sidrena-import-map-dostupnost"), job.fields, job.guessed && job.guessed.dostupnost, true);
					renderPreview(job.fields, job.preview);
					$map.removeAttr("hidden");
					setProgress(100, job.total + " " + (i18n.rowsLabel || "rows"));
					window.setTimeout(function () {
						$progress.attr("hidden", "hidden");
					}, 700);
				})
				.fail(function (xhr) {
					var message = i18n.error;
					if (xhr.responseJSON && xhr.responseJSON.data && xhr.responseJSON.data.message) {
						message = xhr.responseJSON.data.message;
					}
					fail(message);
				});
		});

		$run.on("click", function () {
			if (!job || !job.token) {
				fail(i18n.noFile);
				return;
			}

			var sku = $("#sidrena-import-map-sku").val();
			var idColumn = $("#sidrena-import-map-id").val();
			var standalone = !!(sidrenaCijenaAdmin && sidrenaCijenaAdmin.standalone);
			if (!standalone && !sku && !idColumn) {
				fail(i18n.skuOrIdRequired || i18n.skuRequired);
				return;
			}

			var outlet = $("#sidrena-import-poslovnica").val();
			if (!outlet) {
				fail(i18n.chooseOutlet || "Select the outlet to import into.");
				return;
			}

			$run.prop("disabled", true);
			$analyze.prop("disabled", true);
			$result.attr("hidden", "hidden");
			setProgress(0, i18n.importing);

			function runBatch(state) {
				$.post(sidrenaCijenaAdmin.ajaxUrl, {
					action: "sidrena_cijena_import_run",
					nonce: sidrenaCijenaAdmin.importNonce,
					token: job.token,
					offset: state.offset,
					updated: state.updated,
					skipped: state.skipped,
					missing: state.missing,
					created: state.created,
					special: state.special || 0,
					import_poslovnica: outlet,
					map: {
						sku: sku,
						id: idColumn,
						name: $("#sidrena-import-map-name").val(),
						url: $("#sidrena-import-map-url").val(),
						regular: $("#sidrena-import-map-regular").val(),
						sale: $("#sidrena-import-map-sale").val(),
						sidrena: $("#sidrena-import-map-sidrena").val(),
						datum: $("#sidrena-import-map-datum").val(),
						vrsta: $("#sidrena-import-map-vrsta").val(),
						poslovnica: $("#sidrena-import-map-poslovnica").val(),
						jedinica: $("#sidrena-import-map-jedinica").val(),
						jedinica_cijena: $("#sidrena-import-map-jedinica-cijena").val(),
						marka: $("#sidrena-import-map-marka").val(),
						oblik: $("#sidrena-import-map-oblik").val(),
						cjm: $("#sidrena-import-map-cjm").val(),
						cjm_suffix: $("#sidrena-import-map-cjm-suffix").val(),
						dostupnost: $("#sidrena-import-map-dostupnost").val(),
					},
				})
					.done(function (response) {
						if (!response || !response.success || !response.data) {
							fail(response && response.data && response.data.message ? response.data.message : i18n.error);
							return;
						}

						var data = response.data;
						setProgress(
							data.percent,
							i18n.importing + " " + data.offset + " / " + data.total
						);

						if (!data.done) {
							runBatch({
								offset: data.offset,
								updated: data.updated,
								skipped: data.skipped,
								missing: data.missing,
								created: data.created,
								special: data.special,
							});
							return;
						}

						$run.prop("disabled", false);
						$analyze.prop("disabled", false);
						setProgress(100, i18n.importDone);
						$result
							.removeAttr("hidden")
							.attr("class", "sidrena-cijena-import-result is-success")
							.html(
								"<strong>" +
									(i18n.importDone || "") +
									"</strong> " +
									data.updated +
									" " +
									(i18n.updated || "") +
									" · " +
									data.skipped +
									" " +
									(i18n.skipped || "") +
									" · " +
									data.missing +
									" " +
									(i18n.missing || "") +
									(typeof data.created !== "undefined"
										? " · " + data.created + " " + (i18n.created || "")
										: "")
							);
						// CX-SID-110: rows whose retail price is a special form's, with no name of that form in the file.
						if (data.special) {
							$result.append(
								$("<p>").text(data.special + " " + (i18n.special || ""))
							);
						}
						job = null;
					})
					.fail(function (xhr) {
						var message = i18n.error;
						if (xhr.responseJSON && xhr.responseJSON.data && xhr.responseJSON.data.message) {
							message = xhr.responseJSON.data.message;
						}
						fail(message);
					});
			}

			runBatch({ offset: 0, updated: 0, skipped: 0, missing: 0, created: 0, special: 0 });
		});
	}

	$(function () {
		bindBulkImport();
	});
})(jQuery);
