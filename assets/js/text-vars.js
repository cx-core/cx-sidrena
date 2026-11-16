/* CX Sidrena: the shop's ordinary text colour and font, for the bazna line and the lowest-price caption (spec §2.1,
 * CX-SID-41). They sit inside the price, and a theme colours and styles prices its own way; the lines must read as the
 * shop's ordinary text, not as a price. Read from <body> and set on <html> as --cx-sidrena-text (the colour),
 * --cx-sidrena-text-75 (the same at 75 %) and --cx-sidrena-font, which frontend.css uses.
 * Printed inline right after <body> (wp_body_open: the stylesheets are in, no line drawn yet), or at the start of the
 * footer for a theme without wp_body_open; and loaded again as this file at the end of the page (a stylesheet printed
 * later, a security policy that blocks inline scripts). */
window.cxSidrenaTextVars = function () {
	var body = document.body;
	if (!body || !window.getComputedStyle) {
		return;
	}
	var style = window.getComputedStyle(body);
	var color = style.color;
	var m = /^rgba?\(([^)]+)\)$/.exec(color);
	var color75;
	if (m) {
		var p = m[1].split(/[\s,\/]+/).filter(Boolean);
		var a = p.length > 3 ? parseFloat(p[3]) / (/%$/.test(p[3]) ? 100 : 1) : 1;
		color = "rgba(" + p[0] + ", " + p[1] + ", " + p[2] + ", " + a + ")";
		color75 = "rgba(" + p[0] + ", " + p[1] + ", " + p[2] + ", " + Math.round(a * 75) / 100 + ")";
	} else {
		// A colour in another syntax (oklch, color()): 75 % through color-mix, or the full colour where the browser has none.
		var mix = "color-mix(in srgb, " + color + " 75%, transparent)";
		color75 = window.CSS && CSS.supports && CSS.supports("color", mix) ? mix : color;
	}
	var root = document.documentElement.style;
	root.setProperty("--cx-sidrena-text", color);
	root.setProperty("--cx-sidrena-text-75", color75);
	root.setProperty("--cx-sidrena-font", style.fontFamily);
};
window.cxSidrenaTextVars();
