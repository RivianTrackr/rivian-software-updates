/**
 * PDF release-notes structure extraction (pure logic, no pdf.js dependency).
 *
 * Takes text-content lines reconstructed from pdf.js and rebuilds the
 * document structure — headings, paragraphs, two-level bullets, NOTES
 * callouts — as plain text in the shape parseTextToSections() expects:
 * headings on their own blank-line-separated lines, "• " bullets with
 * four-space-indented "◦ " sub-bullets, and "NOTES" blocks kept contiguous.
 */

// ── Line reconstruction from a pdf.js TextContent object ──
// Groups text items by baseline y (within tolerance), sorts left-to-right,
// and joins runs into { text, x, y, size, page } line objects, top-down.
export function linesFromTextContent(textContent, pageNumber) {
	var rows = [];

	textContent.items.forEach(function (item) {
		if (!item.str || !item.str.trim()) return;
		var x = item.transform[4];
		var y = item.transform[5];
		var size = Math.hypot(item.transform[2], item.transform[3]);

		// Find an existing row on (roughly) the same baseline.
		var row = null;
		for (var i = 0; i < rows.length; i++) {
			if (Math.abs(rows[i].y - y) <= 2) { row = rows[i]; break; }
		}
		if (!row) {
			row = { y: y, items: [] };
			rows.push(row);
		}
		row.items.push({ str: item.str, x: x, width: item.width || 0, size: size, fontName: item.fontName });
	});

	// Top of page first (PDF y-axis points up).
	rows.sort(function (a, b) { return b.y - a.y; });

	return rows.map(function (row) {
		row.items.sort(function (a, b) { return a.x - b.x; });

		// Bullet marker: a lone single-character first item set in a different
		// font than the text that follows (symbol fonts render bullets as
		// letters like "l"), or a literal bullet glyph.
		var items = row.items;
		var bullet = false;
		if (items.length >= 2) {
			var first = items[0];
			var glyph = first.str.trim();
			if (glyph.length === 1
				&& (first.fontName !== items[1].fontName || /^[•●○◦▪▸►]$/.test(glyph))
				&& items[1].x - (first.x + first.width) >= 1) {
				bullet = true;
				items = items.slice(1);
			}
		}

		var text = '';
		var prevEnd = null;
		var maxSize = 0;
		items.forEach(function (it) {
			if (prevEnd !== null && it.x - prevEnd > 1 && text && !/\s$/.test(text) && !/^\s/.test(it.str)) {
				text += ' ';
			}
			text += it.str;
			prevEnd = it.x + it.width;
			if (it.size > maxSize) maxSize = it.size;
		});
		return {
			text: text.replace(/\s+/g, ' ').trim(),
			x: row.items[0].x,
			y: row.y,
			size: maxSize,
			bullet: bullet,
			page: pageNumber
		};
	}).filter(function (line) { return line.text !== ''; });
}

// ── Boilerplate detection ──
var BOILERPLATE = [
	/^R\d+\s*[–—-]\s*Model Year/i,   // "R2 – Model Year 2027 – Software Version 2026.24"
	/^Update Details$/i,
	/^THIS IS DRAFT CONTENT$/i,
	/^\d{1,3}$/,                      // bare page numbers
	/^©\s*\d{4}\s+Rivian/i,
	/RIVIAN1|customerservice@rivian\.com/i,
	/^(January|February|March|April|May|June|July|August|September|October|November|December)\s+\d{4}$/i
];

function isBoilerplate(text) {
	return BOILERPLATE.some(function (re) { return re.test(text); });
}

var BULLET_RE = /^([•●○◦▪▸►])\s*(.+)$/;
var NOTES_RE = /^NOTES?:?$/i;
var FOOTER_Y = 70; // page-bottom zone (PDF units) — dates, phone, copyright

// ── Structure rebuild ──
// pages: array of line arrays (from linesFromTextContent), in page order.
// Returns plain text ready for parseTextToSections().
export function buildReleaseNotesText(pages) {
	var lines = [];
	pages.forEach(function (pageLines) {
		pageLines.forEach(function (line) {
			if (line.y < FOOTER_Y) return;
			if (isBoilerplate(line.text)) return;
			lines.push(line);
		});
	});
	if (!lines.length) return '';

	// Body font size = most common size, weighted by text length.
	var sizeWeights = {};
	lines.forEach(function (line) {
		var key = (Math.round(line.size * 2) / 2).toFixed(1);
		sizeWeights[key] = (sizeWeights[key] || 0) + line.text.length;
	});
	var bodySize = 0, best = -1;
	Object.keys(sizeWeights).forEach(function (key) {
		if (sizeWeights[key] > best) { best = sizeWeights[key]; bodySize = parseFloat(key); }
	});

	// Left margin of regular body text (paragraph starts).
	var bodyMarginX = Infinity;
	lines.forEach(function (line) {
		if (!line.bullet && !BULLET_RE.test(line.text) && line.size <= bodySize + 1 && line.x < bodyMarginX) {
			bodyMarginX = line.x;
		}
	});
	if (!isFinite(bodyMarginX)) bodyMarginX = 0;

	// Join a wrapped line onto accumulated text; keep hyphenated words whole.
	function joinWrapped(acc, next) {
		return acc + (/-$/.test(acc) ? '' : ' ') + next;
	}

	// Walk lines into blocks: heading / para / list / note.
	var blocks = [];
	var list = null;       // { kind:'list', items:[{text,x}] }
	var para = null;       // { kind:'para', text, x, y, page }
	var note = null;       // { kind:'note', paras:[], items:[{text,x}] }
	var lastBullet = null; // last bullet item (for wrap continuations)

	function closePara() {
		if (para && para.text) {
			if (note) note.paras.push(para.text);
			else blocks.push(para);
		}
		para = null;
	}
	function closeList() {
		if (list && list.items.length) blocks.push(list);
		list = null;
		lastBullet = null;
	}
	function closeNote() {
		closePara();
		if (note && (note.paras.length || note.items.length)) blocks.push(note);
		note = null;
		lastBullet = null;
	}

	lines.forEach(function (line) {
		var bulletText = null;
		if (line.bullet) {
			bulletText = line.text;
		} else {
			var m = line.text.match(BULLET_RE);
			if (m) bulletText = m[2].trim();
		}

		if (bulletText === null && line.size >= bodySize + 1.2) {
			closePara(); closeList(); closeNote();
			blocks.push({ kind: 'heading', text: line.text });
			return;
		}

		if (bulletText === null && NOTES_RE.test(line.text)) {
			closePara(); closeList(); closeNote();
			note = { kind: 'note', paras: [], items: [] };
			return;
		}

		if (bulletText !== null) {
			closePara();
			var item = { text: bulletText, x: line.x };
			if (note) {
				note.items.push(item);
			} else {
				if (!list) list = { kind: 'list', items: [] };
				list.items.push(item);
			}
			lastBullet = item;
			return;
		}

		// Plain line: bullet wrap continuation if it's indented past both the
		// bullet glyph of the item it follows and the body margin.
		if (lastBullet && line.x > lastBullet.x + 6 && line.x > bodyMarginX + 6) {
			lastBullet.text = joinWrapped(lastBullet.text, line.text);
			return;
		}

		// A body-margin paragraph ends any open list or note.
		closeList();
		if (note && line.x <= bodyMarginX + 2) closeNote();

		if (para) {
			// Same paragraph if directly below the previous line and roughly
			// left-aligned with the block — allow a rightward shift of up to
			// 20 units for lines that start with an inline icon.
			var xShift = line.x - para.x;
			var sameBlock = line.page === para.lastPage
				&& xShift >= -2 && xShift <= 20
				&& (para.lastY - line.y) < bodySize * 1.9;
			var pageBreak = line.page === para.lastPage + 1;
			if (sameBlock || pageBreak) {
				para.text = joinWrapped(para.text, line.text);
				para.lastY = line.y;
				para.lastPage = line.page;
				return;
			}
			closePara();
		}
		para = { kind: 'para', text: line.text, x: line.x, lastY: line.y, lastPage: line.page };
	});
	closePara(); closeList(); closeNote();

	// ── Emit text ──
	function emitItems(items) {
		var minX = Infinity;
		items.forEach(function (it) { if (it.x < minX) minX = it.x; });
		return items.map(function (it) {
			var sub = it.x > minX + 6;
			return (sub ? '    ◦ ' : '• ') + it.text;
		}).join('\n');
	}

	var chunks = blocks.map(function (block) {
		if (block.kind === 'heading') return block.text;
		if (block.kind === 'para') return block.text;
		if (block.kind === 'list') return emitItems(block.items);
		// note
		var parts = ['NOTES'];
		block.paras.forEach(function (p) { parts.push(p); });
		if (block.items.length) parts.push(emitItems(block.items));
		return parts.join('\n');
	});

	return chunks.join('\n\n');
}
