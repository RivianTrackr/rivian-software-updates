/**
 * Section Builder — release notes meta box editor.
 * Vanilla JS, no jQuery dependency. Built by esbuild to rsu-admin.min.js
 * and enqueued in the footer; init() retries cover late meta box renders.
 */
var RSUSectionBuilder = (function () {
	'use strict';

	// ── DOM helpers ──
	function qs(sel, ctx) { return (ctx || document).querySelector(sel); }
	function qsa(sel, ctx) { return Array.prototype.slice.call((ctx || document).querySelectorAll(sel)); }
	function closest(el, sel) { while (el && !el.matches(sel)) { el = el.parentElement; } return el; }

	// ── Styled confirm dialog ──
	function rsuConfirm(message) {
		return new Promise(function (resolve) {
			var dialog = document.createElement('dialog');
			dialog.className = 'rsu-confirm-dialog';
			dialog.innerHTML =
				'<div class="rsu-confirm-dialog__body"><p class="rsu-confirm-dialog__message"></p></div>' +
				'<div class="rsu-confirm-dialog__actions">' +
					'<button type="button" class="rsu-confirm-dialog__cancel">Cancel</button>' +
					'<button type="button" class="rsu-confirm-dialog__ok">Confirm</button>' +
				'</div>';
			// Set message as text, never markup, so dynamic values can't inject HTML.
			qs('.rsu-confirm-dialog__message', dialog).textContent = message;
			document.body.appendChild(dialog);
			dialog.showModal();
			qs('.rsu-confirm-dialog__cancel', dialog).addEventListener('click', function () { dialog.close(); dialog.remove(); resolve(false); });
			qs('.rsu-confirm-dialog__ok', dialog).addEventListener('click', function () { dialog.close(); dialog.remove(); resolve(true); });
			dialog.addEventListener('cancel', function () { dialog.remove(); resolve(false); });
		});
	}

	// ── Debounce helper ──
	var _debounceTimers = {};
	function debounce(key, fn, delay) {
		clearTimeout(_debounceTimers[key]);
		_debounceTimers[key] = setTimeout(function () {
			delete _debounceTimers[key];
			fn();
		}, delay || 300);
	}

	// Cancel a pending debounce. Returns true if one was pending.
	function flushDebounce(key) {
		if (!(key in _debounceTimers)) return false;
		clearTimeout(_debounceTimers[key]);
		delete _debounceTimers[key];
		return true;
	}

	// Per-builder sync debounce key (one builder per vehicle).
	function syncKey(builder) {
		return 'sync-' + builder.getAttribute('data-vehicle');
	}

	// Immediately sync any builder with a pending debounced edit, so the
	// hidden JSON input is current before the post is serialized for save.
	function flushAllPending() {
		qsa('.rsu-section-builder').forEach(function (b) {
			if (!b._sections) return;
			if (flushDebounce(syncKey(b))) readFromDOM(b);
		});
	}

	// ── Toast notification ──
	function showToast(msg, duration) {
		var existing = qs('.rsu-toast');
		if (existing) existing.remove();
		var el = createElement('<div class="rsu-toast">' + msg + '</div>');
		document.body.appendChild(el);
		requestAnimationFrame(function () { el.classList.add('rsu-toast--visible'); });
		setTimeout(function () {
			el.classList.remove('rsu-toast--visible');
			setTimeout(function () { el.remove(); }, 300);
		}, duration || 2000);
	}

	// ── Undo stack (per builder) ──
	var MAX_UNDO = 20;
	function pushUndo(builder) {
		if (!builder._undoStack) builder._undoStack = [];
		builder._undoStack.push(JSON.stringify(builder._sections));
		if (builder._undoStack.length > MAX_UNDO) builder._undoStack.shift();
	}
	function popUndo(builder) {
		if (!builder._undoStack || !builder._undoStack.length) return false;
		builder._sections = JSON.parse(builder._undoStack.pop());
		renderSections(builder);
		showToast('Undone');
		return true;
	}

	function createElement(html) {
		var div = document.createElement('div');
		div.innerHTML = html.trim();
		return div.firstChild;
	}

	// ── Get builder and its data ──
	function getBuilder(el) {
		var builder = closest(el, '.rsu-section-builder');
		if (!builder) return null;
		if (!builder._sections) {
			var vehicle = builder.getAttribute('data-vehicle');
			var panel = closest(builder, '.rsu-editor-panel');
			var jsonInput = qs('.rsu-sections-json[data-vehicle="' + vehicle + '"]', panel);
			builder._jsonInput = jsonInput;
			var raw = jsonInput ? jsonInput.value : '[]';
			try {
				builder._sections = JSON.parse(raw) || [];
			} catch (e) {
				console.warn('RSU: Failed to parse sections JSON, starting with empty sections.', e.message);
				builder._sections = [];
			}

			// Parse generations from data attribute.
			var genAttr = builder.getAttribute('data-generations');
			try {
				builder._generations = JSON.parse(genAttr) || {};
			} catch (e) {
				console.warn('RSU: Failed to parse generations JSON.', e.message);
				builder._generations = {};
			}
		}
		return builder;
	}

	// ── Get generation options HTML for a builder ──
	function genOptionsHTML(builder, selected) {
		var gens = builder._generations || {};
		var keys = Object.keys(gens);
		if (keys.length < 2) return ''; // No selector needed for single generation
		var html = '<select class="rsu-gen-select' + (selected ? ' rsu-gen-select--active' : '') + '" title="Generation scope">';
		html += '<option value="">All</option>';
		keys.forEach(function(slug) {
			var label = gens[slug].label || slug;
			html += '<option value="' + slug + '"' + (selected === slug ? ' selected' : '') + '>' + label + ' Only</option>';
		});
		html += '</select>';
		return html;
	}

	// ── Sync data to hidden input ──
	function syncJSON(builder) {
		if (builder._jsonInput) {
			builder._jsonInput.value = JSON.stringify(builder._sections || []);
		}
	}

	// ── Read a single block element into a block object (recursive for notes) ──
	function readBlockFromEl(bEl) {
		var type = bEl.getAttribute('data-type');
		var genSelect = qs(':scope > .rsu-block__header > .rsu-gen-select', bEl);
		var blockGen = genSelect ? genSelect.value : '';

		if (type === 'list') {
			var items = [];
			qsa(':scope > .rsu-bullet-list > .rsu-bullet-row', bEl).forEach(function (row) {
				var input = qs('.rsu-bullet-row__input', row);
				var itemGenSelect = qs('.rsu-bullet-row__gen .rsu-gen-select', row);
				var val = input ? input.value.trim() : '';
				if (val !== '') {
					var item = { text: val };
					if (row.classList.contains('rsu-bullet-row--indent')) {
						item.level = 1;
					}
					if (itemGenSelect && itemGenSelect.value) {
						item.generation = itemGenSelect.value;
					}
					items.push(item);
				}
			});
			var listBlock = { type: 'list', items: items };
			if (blockGen) listBlock.generation = blockGen;
			return listBlock;
		}

		if (type === 'note') {
			var noteBlocks = [];
			qsa(':scope > .rsu-note-blocks > .rsu-block', bEl).forEach(function (innerEl) {
				noteBlocks.push(readBlockFromEl(innerEl));
			});
			var noteBlock = { type: 'note', blocks: noteBlocks };
			if (blockGen) noteBlock.generation = blockGen;
			return noteBlock;
		}

		var textarea = qs(':scope > .rsu-block__content', bEl);
		var raw = textarea ? textarea.value : '';
		var paraBlock = { type: type, content: raw.trim() };
		if (blockGen) paraBlock.generation = blockGen;
		return paraBlock;
	}

	// ── Read current DOM state back into data ──
	function readFromDOM(builder) {
		var sections = [];
		qsa('.rsu-sections-list .rsu-section', builder).forEach(function (sEl) {
			var headingInput = qs('.rsu-section__heading', sEl);
			var sectionGenSelect = qs('.rsu-section__header > .rsu-gen-select', sEl);
			var section = {
				heading: headingInput ? headingInput.value.trim() : '',
				blocks: []
			};
			if (sectionGenSelect && sectionGenSelect.value) {
				section.generation = sectionGenSelect.value;
			}

			qsa(':scope > .rsu-blocks-list > .rsu-block', sEl).forEach(function (bEl) {
				section.blocks.push(readBlockFromEl(bEl));
			});

			sections.push(section);
		});

		builder._sections = sections;
		syncJSON(builder);
	}

	// ── Render sections ──
	function renderSections(builder) {
		var sections = builder._sections || [];
		var list = qs('.rsu-sections-list', builder);
		list.innerHTML = '';

		if (!sections.length) {
			list.innerHTML = '<div class="rsu-sections-empty"><span class="dashicons dashicons-text-page" style="font-size:28px;width:28px;height:28px;color:#d1d5db;display:block;margin:0 auto 8px;"></span>No sections yet.<br><span style="font-size:12px;color:#c3c4c7;">Click "+ Add Section" below to start building release notes.</span></div>';
			syncJSON(builder);
			return;
		}

		sections.forEach(function (section, si) {
			list.appendChild(buildSectionEl(builder, section, si));
		});

		syncJSON(builder);

		// Enable drag-and-drop for sections.
		enableDragAndDrop(list, '.rsu-section', '.rsu-section__drag', function () {
			readFromDOM(builder);
		});

		// Enable drag-and-drop for blocks within each section.
		qsa('.rsu-blocks-list', builder).forEach(function (blocksList) {
			enableDragAndDrop(blocksList, '.rsu-block', '.rsu-block__drag', function () {
				readFromDOM(builder);
			});
		});

		requestAnimationFrame(autoResize);
		setTimeout(autoResize, 500);
	}

	// ── Drag and drop ──
	var dragState = { el: null, placeholder: null };

	function enableDragAndDrop(container, itemSel, handleSel, onDrop) {
		qsa(itemSel, container).forEach(function (item) {
			var handle = qs(handleSel, item);
			if (!handle) return;

			handle.addEventListener('mousedown', function (e) {
				e.preventDefault();
				startDrag(container, item, itemSel, e.clientY, onDrop);
			});

			handle.addEventListener('touchstart', function (e) {
				var touch = e.touches[0];
				startDrag(container, item, itemSel, touch.clientY, onDrop);
			}, { passive: true });
		});
	}

	function startDrag(container, item, itemSel, startY, onDrop) {
		var items = qsa(itemSel, container);
		var index = items.indexOf(item);
		if (index === -1) return;

		// Create placeholder.
		var placeholder = document.createElement('div');
		placeholder.className = 'rsu-drag-placeholder';
		placeholder.style.height = item.offsetHeight + 'px';

		// Style the dragged item.
		var rect = item.getBoundingClientRect();
		item.classList.add('rsu-drag-active');
		item.style.top = rect.top + 'px';
		item.style.left = rect.left + 'px';
		item.style.width = rect.width + 'px';

		// Insert placeholder.
		item.parentNode.insertBefore(placeholder, item);

		dragState.el = item;
		dragState.placeholder = placeholder;

		var offsetY = startY - rect.top;
		var cachedRects = null;

		function cacheRects() {
			var siblings = qsa(itemSel + ':not(.rsu-drag-active)', container);
			cachedRects = siblings.map(function (s) {
				var r = s.getBoundingClientRect();
				return { el: s, midY: r.top + r.height / 2 };
			});
		}
		cacheRects();

		function onMove(clientY) {
			item.style.top = (clientY - offsetY) + 'px';

			// Re-cache rects after placeholder moves (layout shifts).
			cacheRects();
			for (var i = 0; i < cachedRects.length; i++) {
				if (clientY < cachedRects[i].midY) {
					container.insertBefore(placeholder, cachedRects[i].el);
					return;
				}
			}
			// Past all items — append.
			container.appendChild(placeholder);
		}

		function onMouseMove(e) { onMove(e.clientY); }
		function onTouchMove(e) { onMove(e.touches[0].clientY); }

		function finish() {
			document.removeEventListener('mousemove', onMouseMove);
			document.removeEventListener('mouseup', finish);
			document.removeEventListener('touchmove', onTouchMove);
			document.removeEventListener('touchend', finish);

			// Reset styles.
			item.classList.remove('rsu-drag-active');
			item.style.top = '';
			item.style.left = '';
			item.style.width = '';

			// Insert item where placeholder is.
			container.insertBefore(item, placeholder);
			placeholder.remove();

			dragState.el = null;
			dragState.placeholder = null;

			if (onDrop) onDrop();
		}

		document.addEventListener('mousemove', onMouseMove);
		document.addEventListener('mouseup', finish);
		document.addEventListener('touchmove', onTouchMove, { passive: true });
		document.addEventListener('touchend', finish);
	}

	// ── Build section element ──
	function buildSectionEl(builder, section, si) {
		var sectionGen = section.generation || '';
		var collapsed = section._collapsed ? ' rsu-section--collapsed' : '';
		var el = createElement(
			'<div class="rsu-section' + collapsed + '" data-index="' + si + '">' +
				'<div class="rsu-section__header">' +
					'<button type="button" class="rsu-section__toggle" title="Collapse / expand" data-action="toggle-section">&#9660;</button>' +
					'<span class="rsu-section__drag dashicons dashicons-move" title="Drag to reorder"></span>' +
					'<input type="text" class="rsu-section__heading" placeholder="Section heading (e.g. Cold Weather Improvements)" />' +
					genOptionsHTML(builder, sectionGen) +
					'<button type="button" class="rsu-section__dupe" title="Duplicate section" data-action="dupe-section"><span class="dashicons dashicons-admin-page" style="font-size:14px;width:14px;height:14px;"></span></button>' +
					'<button type="button" class="rsu-section__remove" title="Remove section" data-action="remove-section">&times;</button>' +
				'</div>' +
				'<div class="rsu-blocks-list"></div>' +
				'<div class="rsu-section__footer">' +
					'<div class="rsu-add-block-group">' +
						'<button type="button" class="button button-small rsu-add-block" data-action="add-block" data-type="paragraph">+ Paragraph</button>' +
						'<button type="button" class="button button-small rsu-add-block" data-action="add-block" data-type="list">+ Bullet List</button>' +
						'<button type="button" class="button button-small rsu-add-block" data-action="add-block" data-type="note">+ Note</button>' +
					'</div>' +
				'</div>' +
			'</div>'
		);

		qs('.rsu-section__heading', el).value = section.heading || '';

		qs('.rsu-section__heading', el).addEventListener('input', function () {
			var b = getBuilder(this);
			debounce(syncKey(b), function () { readFromDOM(b); });
		});

		// Section-level gen select change handler.
		var sectionGenSel = qs('.rsu-section__header > .rsu-gen-select', el);
		if (sectionGenSel) {
			sectionGenSel.addEventListener('change', function() {
				this.classList.toggle('rsu-gen-select--active', !!this.value);
				readFromDOM(getBuilder(this));
			});
		}

		var blocksList = qs('.rsu-blocks-list', el);
		if (section.blocks && section.blocks.length) {
			section.blocks.forEach(function (block, bi) {
				blocksList.appendChild(buildBlockEl(builder, block, bi));
			});
		}

		return el;
	}

	// ── Build block element ──
	function buildBlockEl(builder, block, bi) {
		var type = block.type || 'paragraph';
		var label = type === 'list' ? 'Bullet List' : type === 'note' ? 'Note' : 'Paragraph';
		var blockGen = block.generation || '';

		if (type === 'list') {
			var items = Array.isArray(block.items) ? block.items : [];
			var el = createElement(
				'<div class="rsu-block" data-index="' + bi + '" data-type="list">' +
					'<div class="rsu-block__header">' +
						'<span class="rsu-block__drag dashicons dashicons-move" title="Drag to reorder"></span>' +
						'<span class="rsu-block__label">' + label + '</span>' +
						genOptionsHTML(builder, blockGen) +
						'<button type="button" class="rsu-block__remove" title="Remove block" data-action="remove-block">&times;</button>' +
					'</div>' +
					'<div class="rsu-bullet-list"></div>' +
					'<button type="button" class="rsu-bullet-add" data-action="add-bullet" title="Add bullet point">+ Add bullet</button>' +
				'</div>'
			);

			var listContainer = qs('.rsu-bullet-list', el);
			if (items.length === 0) items = [{ text: '' }];
			items.forEach(function (item) {
				if (typeof item === 'string') item = { text: item };
				listContainer.appendChild(buildBulletRow(builder, item));
			});

			// Gen select change handler.
			var genSel = qs('.rsu-gen-select', el);
			if (genSel) {
				genSel.addEventListener('change', function() {
					this.classList.toggle('rsu-gen-select--active', !!this.value);
					readFromDOM(getBuilder(this));
				});
			}

			return el;
		}

		if (type === 'note') {
			// Notes hold nested blocks (paragraphs and lists). Legacy `content` becomes one paragraph.
			var noteBlocks = Array.isArray(block.blocks) ? block.blocks.slice() : [];
			if (!noteBlocks.length && typeof block.content === 'string' && block.content.trim() !== '') {
				noteBlocks = [{ type: 'paragraph', content: block.content }];
			}
			if (!noteBlocks.length) {
				noteBlocks = [{ type: 'paragraph', content: '' }];
			}

			var noteEl = createElement(
				'<div class="rsu-block" data-index="' + bi + '" data-type="note">' +
					'<div class="rsu-block__header">' +
						'<span class="rsu-block__drag dashicons dashicons-move" title="Drag to reorder"></span>' +
						'<span class="rsu-block__label">' + label + '</span>' +
						genOptionsHTML(builder, blockGen) +
						'<button type="button" class="rsu-block__remove" title="Remove block" data-action="remove-block">&times;</button>' +
					'</div>' +
					'<div class="rsu-note-blocks"></div>' +
					'<div class="rsu-note-add">' +
						'<button type="button" class="button button-small rsu-add-note-block" data-action="add-note-block" data-type="paragraph">+ Paragraph</button>' +
						'<button type="button" class="button button-small rsu-add-note-block" data-action="add-note-block" data-type="list">+ Bullet List</button>' +
					'</div>' +
				'</div>'
			);

			var inner = qs('.rsu-note-blocks', noteEl);
			noteBlocks.forEach(function (nb, ni) {
				inner.appendChild(buildBlockEl(builder, nb, ni));
			});

			// Gen select change handler (own header only).
			var noteGen = qs(':scope > .rsu-block__header > .rsu-gen-select', noteEl);
			if (noteGen) {
				noteGen.addEventListener('change', function() {
					this.classList.toggle('rsu-gen-select--active', !!this.value);
					readFromDOM(getBuilder(this));
				});
			}

			return noteEl;
		}

		var placeholder = 'Paragraph text...';
		var content = block.content || '';

		var el = createElement(
			'<div class="rsu-block" data-index="' + bi + '" data-type="' + type + '">' +
				'<div class="rsu-block__header">' +
					'<span class="rsu-block__drag dashicons dashicons-move" title="Drag to reorder"></span>' +
					'<span class="rsu-block__label">' + label + '</span>' +
					genOptionsHTML(builder, blockGen) +
					'<button type="button" class="rsu-block__remove" title="Remove block" data-action="remove-block">&times;</button>' +
				'</div>' +
				'<textarea class="rsu-block__content" placeholder="' + placeholder + '" rows="1"></textarea>' +
			'</div>'
		);

		var textarea = qs('.rsu-block__content', el);
		textarea.value = content;

		textarea.addEventListener('input', function () {
			autoResizeTextarea(this);
			var b = getBuilder(this);
			debounce(syncKey(b), function () { readFromDOM(b); });
		});

		textarea.addEventListener('focus', function () {
			autoResizeTextarea(this);
		});

		// Gen select change handler (own header only).
		var genSel = qs(':scope > .rsu-block__header > .rsu-gen-select', el);
		if (genSel) {
			genSel.addEventListener('change', function() {
				this.classList.toggle('rsu-gen-select--active', !!this.value);
				readFromDOM(getBuilder(this));
			});
		}

		return el;
	}

	// ── Build a single bullet row ──
	function buildBulletRow(builder, item) {
		if (typeof item === 'string') item = { text: item };
		var text = item.text || '';
		var level = (item.level === 1) ? 1 : 0;
		var itemGen = item.generation || '';
		var genHTML = genOptionsHTML(builder, itemGen);
		var genCell = genHTML ? '<div class="rsu-bullet-row__gen">' + genHTML + '</div>' : '';
		var indentClass = level === 1 ? ' rsu-bullet-row--indent' : '';
		var marker = level === 1 ? '&#9702;' : '&bull;';

		var row = createElement(
			'<div class="rsu-bullet-row' + indentClass + '">' +
				'<span class="rsu-bullet-row__marker">' + marker + '</span>' +
				'<button type="button" class="rsu-bullet-row__indent" title="Indent / outdent (Tab / Shift+Tab)" data-action="toggle-bullet-indent" aria-label="Toggle indent">' +
					'<span class="dashicons dashicons-editor-indent"></span>' +
				'</button>' +
				'<textarea class="rsu-bullet-row__input" placeholder="Bullet point text..." rows="1"></textarea>' +
				genCell +
				'<button type="button" class="rsu-bullet-row__remove" title="Remove bullet" data-action="remove-bullet">&times;</button>' +
			'</div>'
		);

		var input = qs('.rsu-bullet-row__input', row);
		input.value = text;

		input.addEventListener('input', function () {
			autoResizeTextarea(this);
			var b = getBuilder(this);
			debounce(syncKey(b), function () { readFromDOM(b); });
		});

		input.addEventListener('focus', function () {
			autoResizeTextarea(this);
		});

		input.addEventListener('keydown', function (e) {
			if (e.key === 'Tab') {
				e.preventDefault();
				setBulletIndent(row, e.shiftKey ? 0 : 1);
				readFromDOM(getBuilder(this));
			} else if (e.key === 'Enter' && !e.shiftKey && !e.ctrlKey && !e.metaKey && !e.altKey) {
				// Enter: insert a new bullet below, inheriting this row's indent.
				e.preventDefault();
				var b = getBuilder(this);
				var level = row.classList.contains('rsu-bullet-row--indent') ? 1 : 0;
				var newRow = buildBulletRow(b, { text: '', level: level });
				row.parentNode.insertBefore(newRow, row.nextSibling);
				qs('.rsu-bullet-row__input', newRow).focus();
				readFromDOM(b);
			} else if (e.key === 'Backspace' && this.value === '') {
				// Backspace on an empty bullet: remove it and focus the previous row.
				var listContainer = row.parentNode;
				if (qsa('.rsu-bullet-row', listContainer).length < 2) return;
				e.preventDefault();
				var b2 = getBuilder(this);
				var focusTarget = row.previousElementSibling || row.nextElementSibling;
				row.remove();
				var first = listContainer.firstElementChild;
				if (first && first.classList.contains('rsu-bullet-row--indent')) {
					setBulletIndent(first, 0);
				}
				if (focusTarget) {
					var inp = qs('.rsu-bullet-row__input', focusTarget);
					if (inp) {
						inp.focus();
						inp.setSelectionRange(inp.value.length, inp.value.length);
					}
				}
				readFromDOM(b2);
			}
		});

		// Gen select change handler.
		var genSel = qs('.rsu-gen-select', row);
		if (genSel) {
			genSel.addEventListener('change', function() {
				this.classList.toggle('rsu-gen-select--active', !!this.value);
				readFromDOM(getBuilder(this));
			});
		}

		return row;
	}

	// ── Set a bullet row's indent level (0 or 1) and update its marker ──
	function setBulletIndent(row, level) {
		// First row in a list cannot be indented.
		var listContainer = row.parentNode;
		if (listContainer && listContainer.firstElementChild === row) {
			level = 0;
		}
		var marker = qs('.rsu-bullet-row__marker', row);
		if (level === 1) {
			row.classList.add('rsu-bullet-row--indent');
			if (marker) marker.innerHTML = '&#9702;';
		} else {
			row.classList.remove('rsu-bullet-row--indent');
			if (marker) marker.innerHTML = '&bull;';
		}
	}

	// ── Auto-resize a single textarea ──
	function autoResizeTextarea(ta) {
		ta.style.height = '0';
		ta.style.height = Math.max(ta.scrollHeight, 36) + 'px';
	}

	// ── Auto-resize existing textareas on load ──
	function autoResize() {
		qsa('.rsu-block__content, .rsu-bullet-row__input').forEach(function (ta) {
			autoResizeTextarea(ta);
		});
	}

	// ══════════════════════════════════════════════
	// Public API (called via onclick attributes)
	// ══════════════════════════════════════════════

	function addSection(btn) {
		var builder = getBuilder(btn);
		if (!builder) return;

		readFromDOM(builder);
		builder._sections.push({
			heading: '',
			blocks: []
		});

		renderSections(builder);

		var lastHeading = builder.querySelectorAll('.rsu-section__heading');
		if (lastHeading.length) {
			lastHeading[lastHeading.length - 1].focus();
		}
	}

	function addBlock(btn, type) {
		var builder = getBuilder(btn);
		var sectionEl = closest(btn, '.rsu-section');
		if (!builder || !sectionEl) return;

		readFromDOM(builder);

		var sectionIndex = qsa('.rsu-sections-list .rsu-section', builder).indexOf(sectionEl);
		var newBlock;
		if (type === 'list') {
			newBlock = { type: 'list', items: [] };
		} else if (type === 'note') {
			newBlock = { type: 'note', blocks: [] };
		} else {
			newBlock = { type: type, content: '' };
		}

		builder._sections[sectionIndex].blocks.push(newBlock);
		renderSections(builder);

		var sectionEls = qsa('.rsu-section', builder);
		if (sectionEls[sectionIndex]) {
			var inputs = qsa('.rsu-block__content, .rsu-bullet-row__input', sectionEls[sectionIndex]);
			if (inputs.length) inputs[inputs.length - 1].focus();
		}
	}

	function removeSection(btn) {
		var builder = getBuilder(btn);
		var sectionEl = closest(btn, '.rsu-section');
		if (!builder || !sectionEl) return;

		rsuConfirm('Remove this section?').then(function (ok) {
			if (!ok) return;

			readFromDOM(builder);
			pushUndo(builder);
			var sections = qsa('.rsu-sections-list .rsu-section', builder);
			var idx = sections.indexOf(sectionEl);
			builder._sections.splice(idx, 1);
			renderSections(builder);

			// Move focus to next/previous section or add button.
			var remaining = qsa('.rsu-sections-list .rsu-section', builder);
			if (remaining.length) {
				var focusIdx = Math.min(idx, remaining.length - 1);
				var heading = qs('.rsu-section__heading', remaining[focusIdx]);
				if (heading) heading.focus();
			} else {
				var addBtn = qs('.rsu-add-section', builder);
				if (addBtn) addBtn.focus();
			}
		});
	}

	function removeBlock(btn) {
		var blockEl = closest(btn, '.rsu-block');
		if (!blockEl) return;
		var builder = getBuilder(btn);
		if (!builder) return;

		readFromDOM(builder);
		pushUndo(builder);
		blockEl.remove();
		readFromDOM(builder);
		renderSections(builder);
	}

	// Add a paragraph or list block inside the closest note block.
	function addNoteBlock(btn, type) {
		var noteEl = closest(btn, '.rsu-block[data-type="note"]');
		if (!noteEl) return;
		var builder = getBuilder(btn);
		if (!builder) return;

		var inner = qs(':scope > .rsu-note-blocks', noteEl);
		if (!inner) return;

		readFromDOM(builder);
		pushUndo(builder);

		var newBlock = type === 'list' ? { type: 'list', items: [] } : { type: 'paragraph', content: '' };
		var newEl = buildBlockEl(builder, newBlock, inner.children.length);
		inner.appendChild(newEl);
		readFromDOM(builder);

		var input = qs('.rsu-block__content, .rsu-bullet-row__input', newEl);
		if (input) input.focus();
	}

	// Toggle indent on a bullet row (action button or programmatic).
	function toggleBulletIndent(btn) {
		var row = closest(btn, '.rsu-bullet-row');
		if (!row) return;
		var nextLevel = row.classList.contains('rsu-bullet-row--indent') ? 0 : 1;
		setBulletIndent(row, nextLevel);
		var builder = getBuilder(btn);
		if (builder) readFromDOM(builder);
		var input = qs('.rsu-bullet-row__input', row);
		if (input) input.focus();
	}

	function addBullet(btn) {
		var blockEl = closest(btn, '.rsu-block');
		if (!blockEl) return;
		var builder = getBuilder(btn);
		var listContainer = qs('.rsu-bullet-list', blockEl);
		var row = buildBulletRow(builder, { text: '' });
		listContainer.appendChild(row);
		qs('.rsu-bullet-row__input', row).focus();
		readFromDOM(builder);
	}

	function removeBullet(btn) {
		var row = closest(btn, '.rsu-bullet-row');
		var blockEl = closest(btn, '.rsu-block');
		if (!row || !blockEl) return;
		var builder = getBuilder(btn);
		var listContainer = qs('.rsu-bullet-list', blockEl);
		row.remove();
		if (!qs('.rsu-bullet-row', listContainer)) {
			var newRow = buildBulletRow(builder, { text: '' });
			listContainer.appendChild(newRow);
			qs('.rsu-bullet-row__input', newRow).focus();
		}
		readFromDOM(builder);
	}

	// ══════════════════════════════════════════════
	// Initialization
	// ══════════════════════════════════════════════

	function init() {
		qsa('.rsu-section-builder').forEach(function (builder) {
			if (builder._initialized) return;
			getBuilder(builder);
			renderSections(builder);
			builder._initialized = true;
		});

		var tabs = document.getElementById('rsu-editor-tabs');
		if (tabs) {
			var visibleTabs = qsa('.rsu-editor-tab', tabs).filter(function (t) { return t.style.display !== 'none'; });
			var hasActive = visibleTabs.some(function (t) { return t.classList.contains('rsu-editor-tab--active'); });
			if (visibleTabs.length && !hasActive) {
				activateTab(visibleTabs[0].getAttribute('data-vehicle'));
			} else if (hasActive) {
				// Normalize roving tabindex for the active tab rendered by PHP.
				var preActive = visibleTabs.filter(function (t) { return t.classList.contains('rsu-editor-tab--active'); })[0];
				if (preActive) activateTab(preActive.getAttribute('data-vehicle'));
			}
		}

		setTimeout(autoResize, 100);
	}

	// Tab switching.
	function activateTab(vehicle) {
		var tabs = document.getElementById('rsu-editor-tabs');
		if (!tabs) return;

		qsa('.rsu-editor-tab', tabs).forEach(function (t) {
			t.classList.remove('rsu-editor-tab--active');
			t.setAttribute('aria-selected', 'false'); t.setAttribute('tabindex', '-1');
		});
		qsa('.rsu-editor-panel').forEach(function (p) {
			p.classList.add('rsu-editor-panel--hidden');
		});

		var tab = qs('[data-vehicle="' + vehicle + '"]', tabs);
		if (tab) {
			tab.classList.add('rsu-editor-tab--active');
			tab.setAttribute('aria-selected', 'true'); tab.setAttribute('tabindex', '0');
		}

		var panel = document.getElementById('rsu-editor-panel-' + vehicle);
		if (panel) {
			panel.classList.remove('rsu-editor-panel--hidden');
			panel.style.display = '';
			setTimeout(autoResize, 10);
		}
	}

	// Vehicle checkboxes.
	document.addEventListener('change', function (e) {
		if (e.target.classList.contains('rsu-vehicle-checkbox')) {
			var veh = e.target.getAttribute('data-vehicle');
			var tabsEl = document.getElementById('rsu-editor-tabs');
			var tabEl = qs('[data-vehicle="' + veh + '"]', tabsEl);
			var panelEl = document.getElementById('rsu-editor-panel-' + veh);

			if (e.target.checked) {
				if (tabEl) tabEl.style.display = '';
				var visActive = qsa('.rsu-editor-tab', tabsEl).filter(function (t) {
					return t.style.display !== 'none' && t.classList.contains('rsu-editor-tab--active');
				});
				if (!visActive.length) activateTab(veh);
			} else {
				if (tabEl) { tabEl.style.display = 'none'; tabEl.classList.remove('rsu-editor-tab--active'); }
				if (panelEl) { panelEl.classList.add('rsu-editor-panel--hidden'); panelEl.style.display = 'none'; }
				var firstVis = qsa('.rsu-editor-tab', tabsEl).filter(function (t) { return t.style.display !== 'none'; });
				if (firstVis.length) activateTab(firstVis[0].getAttribute('data-vehicle'));
			}
		}
	});

	// Tab clicks.
	document.addEventListener('click', function (e) {
		var tab = closest(e.target, '.rsu-editor-tab');
		if (tab) {
			e.preventDefault();
			activateTab(tab.getAttribute('data-vehicle'));
		}
	});

	// Tab keyboard navigation (WAI-ARIA: arrows, Home/End, Enter/Space).
	document.addEventListener('keydown', function (e) {
		var tab = closest(e.target, '.rsu-editor-tab');
		if (!tab) return;
		var tabsEl = document.getElementById('rsu-editor-tabs');
		if (!tabsEl) return;
		var list = qsa('.rsu-editor-tab', tabsEl).filter(function (t) { return t.style.display !== 'none'; });
		var idx = list.indexOf(tab);
		if (idx === -1) return;
		var newIdx = -1;
		if (e.key === 'ArrowRight' || e.key === 'ArrowDown') { e.preventDefault(); newIdx = (idx + 1) % list.length; }
		else if (e.key === 'ArrowLeft' || e.key === 'ArrowUp') { e.preventDefault(); newIdx = (idx - 1 + list.length) % list.length; }
		else if (e.key === 'Home') { e.preventDefault(); newIdx = 0; }
		else if (e.key === 'End') { e.preventDefault(); newIdx = list.length - 1; }
		else if (e.key === 'Enter' || e.key === ' ') { e.preventDefault(); activateTab(tab.getAttribute('data-vehicle')); return; }
		if (newIdx >= 0) {
			var target = list[newIdx];
			activateTab(target.getAttribute('data-vehicle'));
			target.focus();
		}
	});

	// Copy from.
	var _copyInProgress = false;
	document.addEventListener('change', function (e) {
		if (!e.target.classList.contains('rsu-copy-from-select')) return;
		if (_copyInProgress) return;

		var sourceSlug = e.target.value;
		var targetSlug = e.target.getAttribute('data-target');
		if (!sourceSlug) return;

		_copyInProgress = true;
		var selectEl = e.target;
		// Safety timeout: reset flag after 10s in case dialog hangs.
		var copyTimeout = setTimeout(function () { _copyInProgress = false; selectEl.value = ''; }, 10000);

		rsuConfirm('Copy sections from ' + sourceSlug + '? This will overwrite the current sections.').then(function (ok) {
			clearTimeout(copyTimeout);
			if (!ok) { selectEl.value = ''; _copyInProgress = false; return; }

			var srcBuilder = qs('.rsu-section-builder[data-vehicle="' + sourceSlug + '"]');
			var tgtBuilder = qs('.rsu-section-builder[data-vehicle="' + targetSlug + '"]');
			if (!srcBuilder || !tgtBuilder) { selectEl.value = ''; _copyInProgress = false; return; }

			getBuilder(srcBuilder);
			getBuilder(tgtBuilder);
			readFromDOM(srcBuilder);
			pushUndo(tgtBuilder);

			tgtBuilder._sections = JSON.parse(JSON.stringify(srcBuilder._sections));
			renderSections(tgtBuilder);
			selectEl.value = '';
			_copyInProgress = false;
			showToast('Sections copied');
		});
	});

	// ── Toggle section collapse ──
	function toggleSection(btn) {
		var sectionEl = closest(btn, '.rsu-section');
		if (!sectionEl) return;
		sectionEl.classList.toggle('rsu-section--collapsed');
	}

	// ── Duplicate section ──
	function dupeSection(btn) {
		var builder = getBuilder(btn);
		var sectionEl = closest(btn, '.rsu-section');
		if (!builder || !sectionEl) return;

		readFromDOM(builder);
		pushUndo(builder);
		var idx = qsa('.rsu-sections-list .rsu-section', builder).indexOf(sectionEl);
		var copy = JSON.parse(JSON.stringify(builder._sections[idx]));
		copy.heading = copy.heading ? copy.heading + ' (copy)' : '(copy)';
		builder._sections.splice(idx + 1, 0, copy);
		renderSections(builder);

		var headings = qsa('.rsu-section__heading', builder);
		if (headings[idx + 1]) headings[idx + 1].focus();
	}

	// ── Paste release notes import ──

	// Render parsed sections into the preview pane (DOM APIs only, never
	// innerHTML, so pasted text can't inject markup).
	function renderImportPreview(previewEl, sections) {
		previewEl.innerHTML = '';
		if (!sections.length) {
			var empty = document.createElement('p');
			empty.className = 'rsu-import-preview__empty';
			empty.textContent = 'Nothing detected yet — headings and bullets will appear here as you paste.';
			previewEl.appendChild(empty);
			return;
		}
		sections.forEach(function (section) {
			var sEl = document.createElement('div');
			sEl.className = 'rsu-import-preview__section';
			var h = document.createElement('div');
			h.className = 'rsu-import-preview__heading' + (section.heading ? '' : ' rsu-import-preview__heading--empty');
			h.textContent = section.heading || '(no heading)';
			sEl.appendChild(h);
			(section.blocks || []).forEach(function (block) {
				if (block.type === 'list') {
					var ul = document.createElement('ul');
					ul.className = 'rsu-import-preview__list';
					block.items.forEach(function (item) {
						var li = document.createElement('li');
						li.textContent = item.text;
						if (item.level === 1) li.className = 'rsu-import-preview__sub';
						ul.appendChild(li);
					});
					sEl.appendChild(ul);
				} else {
					var p = document.createElement('p');
					p.className = 'rsu-import-preview__para';
					p.textContent = block.content;
					sEl.appendChild(p);
				}
			});
			previewEl.appendChild(sEl);
		});
	}

	function showImport(btn) {
		var vehicle = btn.getAttribute('data-vehicle');
		var dialog = createElement(
			'<dialog class="rsu-import-dialog">' +
				'<div class="rsu-import-dialog__header">' +
					'<h3>Paste Release Notes</h3>' +
					'<p>Paste plain-text release notes below. Check the preview to confirm headings, bullets, and sub-bullets were detected correctly before importing.</p>' +
				'</div>' +
				'<div class="rsu-import-dialog__body">' +
					'<textarea class="rsu-import-dialog__textarea" placeholder="Paste release notes here...\n\nCold Weather Improvements\n• Battery preconditioning now more efficient\n• Cabin heating reduced warm-up time\n\nNavigation\nUpdated route planning algorithm."></textarea>' +
					'<div class="rsu-import-dialog__preview-label">Preview</div>' +
					'<div class="rsu-import-dialog__preview"></div>' +
				'</div>' +
				'<div class="rsu-import-dialog__actions">' +
					'<button type="button" class="rsu-import-dialog__cancel">Cancel</button>' +
					'<button type="button" class="rsu-import-dialog__submit" disabled>Import</button>' +
				'</div>' +
			'</dialog>'
		);
		document.body.appendChild(dialog);
		dialog.showModal();

		var textarea = qs('.rsu-import-dialog__textarea', dialog);
		var previewEl = qs('.rsu-import-dialog__preview', dialog);
		var submitBtn = qs('.rsu-import-dialog__submit', dialog);
		textarea.focus();

		function refreshPreview() {
			var parsed = textarea.value.trim() ? parseTextToSections(textarea.value) : [];
			renderImportPreview(previewEl, parsed);
			submitBtn.disabled = !parsed.length;
			submitBtn.textContent = parsed.length
				? 'Import ' + parsed.length + ' section' + (parsed.length > 1 ? 's' : '')
				: 'Import';
		}
		refreshPreview();
		textarea.addEventListener('input', function () {
			debounce('import-preview', refreshPreview, 150);
		});

		qs('.rsu-import-dialog__cancel', dialog).addEventListener('click', function () {
			dialog.close(); dialog.remove();
		});
		dialog.addEventListener('cancel', function () { dialog.remove(); });

		submitBtn.addEventListener('click', function () {
			var text = textarea.value;
			dialog.close(); dialog.remove();
			if (!text.trim()) return;

			var builder = qs('.rsu-section-builder[data-vehicle="' + vehicle + '"]');
			if (!builder) return;
			getBuilder(builder);
			readFromDOM(builder);

			var parsed = parseTextToSections(text);
			if (parsed.length) {
				pushUndo(builder);
				builder._sections = builder._sections.concat(parsed);
				renderSections(builder);
				_dirty = true;
				showToast('Imported ' + parsed.length + ' section' + (parsed.length > 1 ? 's' : ''));
			} else {
				showToast('No sections detected in pasted text');
			}
		});
	}

	// ── Parse plain text into sections ──
	function parseTextToSections(text) {
		var lines = text.split(/\r?\n/);
		var sections = [];
		var current = null;
		var currentBlock = null;

		function flushBlock() {
			if (!currentBlock) return;
			if (currentBlock.type === 'list' && currentBlock.items.length) {
				if (current) current.blocks.push(currentBlock);
			} else if (currentBlock.type === 'paragraph' && currentBlock.content.trim()) {
				if (current) current.blocks.push(currentBlock);
			}
			currentBlock = null;
		}

		function flushSection() {
			flushBlock();
			if (current && (current.heading || current.blocks.length)) {
				sections.push(current);
			}
			current = null;
		}

		for (var i = 0; i < lines.length; i++) {
			var line = lines[i];
			var trimmed = line.trim();

			// Skip empty lines.
			if (!trimmed) {
				// Flush paragraph block on blank line.
				if (currentBlock && currentBlock.type === 'paragraph') {
					flushBlock();
				}
				continue;
			}

			// Detect bullet points: •, -, *, or numbered (1., 2.).
			var bulletMatch = trimmed.match(/^([•●○◦▪▸►\-\*]|\d+[\.\)])\s*(.+)/);
			if (bulletMatch) {
				// If we're in a paragraph block, flush it first.
				if (currentBlock && currentBlock.type === 'paragraph') {
					flushBlock();
				}
				if (!current) {
					current = { heading: '', blocks: [] };
				}
				if (!currentBlock || currentBlock.type !== 'list') {
					flushBlock();
					currentBlock = { type: 'list', items: [] };
				}
				// Sub-bullet: indented line, or a hollow/square marker (○ ◦ ▪)
				// which Rivian's notes use for second-level items.
				var indentWidth = line.match(/^[\t ]*/)[0].replace(/\t/g, '    ').length;
				var marker = bulletMatch[1];
				var isSub = indentWidth >= 2 || marker === '○' || marker === '◦' || marker === '▪';
				var bulletItem = { text: bulletMatch[2].trim() };
				if (isSub) bulletItem.level = 1;
				currentBlock.items.push(bulletItem);
				continue;
			}

			// Detect headings: short lines (< 80 chars) that are followed by bullets or blank line,
			// or lines that look like titles (no trailing punctuation except colon).
			var isHeading = false;
			if (trimmed.length < 80 && !trimmed.match(/[.!?,;]$/) && trimmed.length > 1) {
				// Check next non-empty line for bullets or if this is alone.
				var nextIdx = i + 1;
				while (nextIdx < lines.length && !lines[nextIdx].trim()) nextIdx++;
				if (nextIdx >= lines.length || lines[nextIdx].trim().match(/^(?:[•●○◦▪▸►\-\*]|\d+[\.\)])\s/)) {
					isHeading = true;
				}
				// If next line is also a short non-bullet line, this might be a heading for a paragraph section.
				if (!isHeading && nextIdx < lines.length && lines[nextIdx].trim().length > 0) {
					// Treat as heading if we don't already have an active section,
					// or the line stands alone after a blank line (title-like).
					var prevBlank = i === 0 || !lines[i - 1].trim();
					if (!current || !current.heading || prevBlank) {
						isHeading = true;
					}
				}
			}

			if (isHeading) {
				flushSection();
				current = { heading: trimmed.replace(/:$/, ''), blocks: [] };
				currentBlock = null;
				continue;
			}

			// Regular text: add to paragraph block.
			if (!current) {
				current = { heading: '', blocks: [] };
			}
			if (currentBlock && currentBlock.type === 'list') {
				flushBlock();
			}
			if (!currentBlock || currentBlock.type !== 'paragraph') {
				flushBlock();
				currentBlock = { type: 'paragraph', content: '' };
			}
			currentBlock.content += (currentBlock.content ? '\n' : '') + trimmed;
		}

		flushSection();
		return sections;
	}

	// ── Unsaved changes warning ──
	var _dirty = false;
	var _origReadFromDOM = readFromDOM;
	readFromDOM = function (builder) {
		_dirty = true;
		return _origReadFromDOM(builder);
	};

	window.addEventListener('beforeunload', function (e) {
		if (_dirty) {
			e.preventDefault();
			e.returnValue = '';
		}
	});

	// Flush pending edits, then clear dirty flag on form submit (classic editor).
	document.addEventListener('submit', function () {
		flushAllPending();
		_dirty = false;
	});

	// Flush on blur so the hidden input is current before any save the Block
	// Editor triggers (it serializes the meta box form without a submit event).
	document.addEventListener('focusout', function (e) {
		var builder = closest(e.target, '.rsu-section-builder');
		if (!builder || !builder._sections) return;
		if (flushDebounce(syncKey(builder))) readFromDOM(builder);
	});

	// ── Delegated event handler for data-action buttons ──
	document.addEventListener('click', function (e) {
		var actionEl = e.target.closest('[data-action]');
		if (!actionEl) return;
		var action = actionEl.getAttribute('data-action');

		switch (action) {
			case 'add-section': addSection(actionEl); break;
			case 'add-block': addBlock(actionEl, actionEl.getAttribute('data-type')); break;
			case 'add-note-block': addNoteBlock(actionEl, actionEl.getAttribute('data-type')); break;
			case 'remove-section': removeSection(actionEl); break;
			case 'remove-block': removeBlock(actionEl); break;
			case 'add-bullet': addBullet(actionEl); break;
			case 'remove-bullet': removeBullet(actionEl); break;
			case 'toggle-bullet-indent': toggleBulletIndent(actionEl); break;
			case 'toggle-section': toggleSection(actionEl); break;
			case 'dupe-section': dupeSection(actionEl); break;
			case 'show-import': showImport(actionEl); break;
			case 'undo': undoAction(actionEl); break;
		}
	});

	// ── Undo action ──
	function undoAction(btn) {
		var builder = getBuilder(btn);
		if (builder) popUndo(builder);
	}

	// ── Keyboard shortcuts ──
	document.addEventListener('keydown', function (e) {
		// Ctrl/Cmd+S: flush pending edits before the editor's save handler runs.
		if ((e.ctrlKey || e.metaKey) && !e.shiftKey && (e.key === 's' || e.key === 'S')) {
			flushAllPending();
		}

		// Ctrl/Cmd+Z: Undo (when focus is inside a builder)
		if ((e.ctrlKey || e.metaKey) && e.key === 'z' && !e.shiftKey) {
			var focused = document.activeElement;
			if (focused && closest(focused, '.rsu-section-builder')) {
				var builder = getBuilder(focused);
				if (builder && builder._undoStack && builder._undoStack.length) {
					e.preventDefault();
					popUndo(builder);
				}
			}
		}

		// Ctrl/Cmd+Shift+S: Add section
		if ((e.ctrlKey || e.metaKey) && e.shiftKey && (e.key === 's' || e.key === 'S')) {
			var focused = document.activeElement;
			var builder = focused ? closest(focused, '.rsu-section-builder') : null;
			if (!builder) {
				// Find active panel's builder.
				var activePanel = qs('.rsu-editor-panel:not(.rsu-editor-panel--hidden)');
				if (activePanel) builder = qs('.rsu-section-builder', activePanel);
			}
			if (builder) {
				e.preventDefault();
				var addBtn = qs('[data-action="add-section"]', builder);
				if (addBtn) addSection(addBtn);
			}
		}

		// Enter in section heading: add first block if section is empty.
		if (e.key === 'Enter' && !e.ctrlKey && !e.metaKey && !e.shiftKey) {
			var focused = document.activeElement;
			if (focused && focused.classList.contains('rsu-section__heading')) {
				var sectionEl = closest(focused, '.rsu-section');
				var blocks = sectionEl ? qsa('.rsu-block', sectionEl) : [];
				if (!blocks.length) {
					e.preventDefault();
					var addPara = qs('[data-action="add-block"][data-type="paragraph"]', sectionEl);
					if (addPara) addBlock(addPara, 'paragraph');
				}
			}
		}
	});

	// Run init immediately, plus retry for Block Editor.
	init();
	if (document.readyState === 'loading') {
		document.addEventListener('DOMContentLoaded', init);
	}
	var retryCount = 0;
	var retryTimer = setInterval(function () {
		retryCount++;
		var builders = qsa('.rsu-section-builder');
		var uninitialized = builders.filter(function (b) { return !b._initialized; });
		if (uninitialized.length) init();
		if (retryCount >= 10 || !uninitialized.length) clearInterval(retryTimer);
	}, 500);

	return {
		addSection: addSection,
		addBlock: addBlock,
		addNoteBlock: addNoteBlock,
		removeSection: removeSection,
		removeBlock: removeBlock,
		addBullet: addBullet,
		removeBullet: removeBullet,
		toggleBulletIndent: toggleBulletIndent,
		activateTab: activateTab,
		toggleSection: toggleSection,
		dupeSection: dupeSection,
		showImport: showImport,
		init: init
	};
})();

// Expose globally — the bundle is wrapped in an IIFE by esbuild, and the
// builder was historically reachable as window.RSUSectionBuilder.
window.RSUSectionBuilder = RSUSectionBuilder;
