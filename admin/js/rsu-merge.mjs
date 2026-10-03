/**
 * Generation merge for the section builder.
 *
 * A JavaScript port of RSU_Migrate::merge_generations() so the editor can
 * fold two release-note PDFs for the same vehicle (one per generation) into
 * one section list: shared content stays untagged, differences carry a
 * generation tag down to the individual bullet. The algorithm, thresholds
 * and quirks (PHP's similar_text, byte-based) are kept identical so both
 * paths produce the same result; the PHP side remains the reference.
 */

var encoder = typeof TextEncoder !== 'undefined' ? new TextEncoder() : null;

function bytes(str) {
	if (encoder) return encoder.encode(str);
	var out = [];
	for (var i = 0; i < str.length; i++) out.push(str.charCodeAt(i) & 0xff);
	return out;
}

// PHP's php_similar_str: longest common run, first found wins on ties.
function similarStr(t1, s1, l1, t2, s2, l2) {
	var max = 0, count = 0, pos1 = 0, pos2 = 0;
	for (var p = 0; p < l1; p++) {
		for (var q = 0; q < l2; q++) {
			var l = 0;
			while (p + l < l1 && q + l < l2 && t1[s1 + p + l] === t2[s2 + q + l]) l++;
			if (l > max) { max = l; count++; pos1 = p; pos2 = q; }
		}
	}
	return { max: max, count: count, pos1: pos1, pos2: pos2 };
}

function similarChar(t1, s1, l1, t2, s2, l2) {
	var r = similarStr(t1, s1, l1, t2, s2, l2);
	var sum = r.max;
	if (sum) {
		if (r.pos1 && r.pos2 && r.count > 1) {
			sum += similarChar(t1, s1, r.pos1, t2, s2, r.pos2);
		}
		if (r.pos1 + r.max < l1 && r.pos2 + r.max < l2) {
			sum += similarChar(t1, s1 + r.pos1 + r.max, l1 - r.pos1 - r.max, t2, s2 + r.pos2 + r.max, l2 - r.pos2 - r.max);
		}
	}
	return sum;
}

/** Percentage similarity, as PHP's similar_text($a, $b, $percent) reports it. */
export function similarText(a, b) {
	var t1 = bytes(String(a)), t2 = bytes(String(b));
	if (t1.length + t2.length === 0) return 0;
	var sim = similarChar(t1, 0, t1.length, t2, 0, t2.length);
	return sim * 2 * 100 / (t1.length + t2.length);
}

// PHP strtolower() and \s are ASCII-only; match that so both sides agree.
function normalizeText(text) {
	return String(text == null ? '' : text)
		.replace(/[ \t\r\n\f\v]+/g, ' ')
		.replace(/^[ \t\r\n\f\v]+|[ \t\r\n\f\v]+$/g, '')
		.replace(/[A-Z]/g, function (c) { return c.toLowerCase(); });
}
var normalizeHeading = normalizeText;

function itemText(item) {
	return item && typeof item === 'object' ? (item.text || '') : String(item == null ? '' : item);
}

function clone(v) { return JSON.parse(JSON.stringify(v)); }
function withGen(obj, gen) { var c = clone(obj); c.generation = gen; return c; }

// Text a non-list block carries: paragraph content, or a note's inner blocks
// flattened (the PHP side only ever saw legacy notes with `content`).
function blockText(b) {
	if (b.type === 'note' && Array.isArray(b.blocks)) {
		return b.blocks.map(function (inner) {
			return inner.type === 'list'
				? (inner.items || []).map(itemText).join('\n')
				: (inner.content || '');
		}).join('\n');
	}
	return b.content || '';
}

function blocksEqual(b1, b2) {
	if (b1.type !== b2.type) return false;
	if (b1.type === 'list') {
		var items1 = b1.items || [], items2 = b2.items || [];
		if (items1.length !== items2.length) return false;
		for (var i = 0; i < items1.length; i++) {
			if (normalizeText(itemText(items1[i])) !== normalizeText(itemText(items2[i]))) return false;
		}
		return true;
	}
	return normalizeText(blockText(b1)) === normalizeText(blockText(b2));
}

function blockSimilarity(b1, b2) {
	if (b1.type !== b2.type) return 0;
	if (b1.type === 'list') {
		var items1 = b1.items || [], items2 = b2.items || [];
		if (!items1.length && !items2.length) return 100;
		var overlap = 0;
		items1.forEach(function (item1) {
			var norm1 = normalizeText(itemText(item1));
			for (var j = 0; j < items2.length; j++) {
				var norm2 = normalizeText(itemText(items2[j]));
				if (norm1 === norm2 || similarText(norm1, norm2) > 80) { overlap++; break; }
			}
		});
		if (overlap > 0) {
			var total = Math.max(items1.length, items2.length);
			return 60 + (40 * overlap / total);
		}
		return 0;
	}
	return similarText(normalizeText(blockText(b1)), normalizeText(blockText(b2)));
}

function mergeListBlocks(list1, list2, genA, genB) {
	var items1 = list1.items || [], items2 = list2.items || [];
	var merged = [], used2 = {};

	function entry(item, gen) {
		var e = { text: itemText(item) };
		if (item && typeof item === 'object' && item.level === 1) e.level = 1;
		if (gen) e.generation = gen;
		return e;
	}

	items1.forEach(function (item1) {
		var norm1 = normalizeText(itemText(item1));
		var match = null, best = 0;
		for (var j = 0; j < items2.length; j++) {
			if (used2[j]) continue;
			var norm2 = normalizeText(itemText(items2[j]));
			if (norm1 === norm2) { match = j; best = 1.0; break; }
			var pct = similarText(norm1, norm2);
			if (pct > 80 && pct > best) { match = j; best = pct; }
		}
		if (match !== null) {
			used2[match] = true;
			var item2 = items2[match];
			if (norm1 === normalizeText(itemText(item2))) {
				merged.push(entry(item1, ''));
			} else {
				merged.push(entry(item1, genA));
				merged.push(entry(item2, genB));
			}
		} else {
			merged.push(entry(item1, genA));
		}
	});
	items2.forEach(function (item2, j) {
		if (!used2[j]) merged.push(entry(item2, genB));
	});
	return { type: 'list', items: merged };
}

function consolidateListBlocks(blocks) {
	var result = [], i = 0;
	while (i < blocks.length) {
		if (blocks[i].type === 'list') {
			var combined = [];
			while (i < blocks.length && blocks[i].type === 'list') {
				var blockGen = blocks[i].generation || '';
				(blocks[i].items || []).forEach(function (item) {
					var e = { text: itemText(item) };
					if (item && typeof item === 'object' && item.level === 1) e.level = 1;
					if (item && typeof item === 'object' && item.generation) e.generation = item.generation;
					else if (blockGen) e.generation = blockGen;
					combined.push(e);
				});
				i++;
			}
			if (combined.length) result.push({ type: 'list', items: combined });
		} else {
			result.push(blocks[i]);
			i++;
		}
	}
	return result;
}

function mergeSection(s1, s2, genA, genB) {
	var merged = { heading: s2.heading, blocks: [] };
	var blocks1 = s1.blocks || [], blocks2 = s2.blocks || [];
	var used2 = {}, matches = {};

	blocks1.forEach(function (b1, i1) {
		var bestMatch = null, bestScore = 0;
		for (var i2 = 0; i2 < blocks2.length; i2++) {
			var b2 = blocks2[i2];
			if (used2[i2] || b1.type !== b2.type) continue;
			if (blocksEqual(b1, b2)) { bestMatch = i2; bestScore = 100; break; }
			var score = blockSimilarity(b1, b2);
			if (score > 60 && score > bestScore) { bestMatch = i2; bestScore = score; }
		}
		if (bestMatch !== null) used2[bestMatch] = true;
		matches[i1] = bestMatch;
	});

	var emitted2 = {};
	blocks1.forEach(function (b1, i1) {
		if (matches[i1] !== null) {
			for (var i2 = 0; i2 < matches[i1]; i2++) {
				if (!emitted2[i2] && !used2[i2]) {
					merged.blocks.push(withGen(blocks2[i2], genB));
					emitted2[i2] = true;
				}
			}
			var b2 = blocks2[matches[i1]];
			emitted2[matches[i1]] = true;
			if (blocksEqual(b1, b2)) {
				merged.blocks.push(clone(b1));
			} else if (b1.type === 'list') {
				merged.blocks.push(mergeListBlocks(b1, b2, genA, genB));
			} else {
				merged.blocks.push(withGen(b1, genA));
				merged.blocks.push(withGen(b2, genB));
			}
		} else {
			merged.blocks.push(withGen(b1, genA));
		}
	});
	blocks2.forEach(function (b2, i2) {
		if (!emitted2[i2]) merged.blocks.push(withGen(b2, genB));
	});

	merged.blocks = consolidateListBlocks(merged.blocks);
	return merged;
}

/**
 * Merge two section lists for the same vehicle.
 *
 * @param {Array}  genASections Sections parsed for the earlier generation.
 * @param {Array}  genBSections Sections parsed for the later generation.
 * @param {string} genA         Generation slug for the first list (default "gen1").
 * @param {string} genB         Generation slug for the second list (default "gen2").
 * @return {Array} Merged sections with generation tags where content differs.
 */
export function mergeGenerations(genASections, genBSections, genA, genB) {
	genA = genA || 'gen1';
	genB = genB || 'gen2';
	var gen1 = clone(genASections || []), gen2 = clone(genBSections || []);

	var map1 = {}, map2 = {}, order1 = [], order2 = [];
	gen1.forEach(function (s) { var k = normalizeHeading(s.heading); map1[k] = s; order1.push(k); });
	gen2.forEach(function (s) { var k = normalizeHeading(s.heading); map2[k] = s; order2.push(k); });

	var merged = [], processed = {};
	order1.forEach(function (k) {
		processed[k] = true;
		if (Object.prototype.hasOwnProperty.call(map2, k)) merged.push(mergeSection(map1[k], map2[k], genA, genB));
		else merged.push(withGen(map1[k], genA));
	});
	order2.forEach(function (k) {
		if (!processed[k]) merged.push(withGen(map2[k], genB));
	});

	// "Additional Improvements" always closes the list.
	var additional = [], rest = [];
	merged.forEach(function (s) {
		(normalizeHeading(s.heading) === 'additional improvements' ? additional : rest).push(s);
	});
	return rest.concat(additional);
}
