(function ($) {
  'use strict';

  // ══════════════════════════════════════════════
  // Section Builder — functions
  // ══════════════════════════════════════════════

  // ── Render all sections for a builder ──
  function renderSections($builder) {
    var sections = $builder.data('_sections') || [];
    var $list = $builder.find('.rsu-sections-list');
    $list.empty();

    if (!sections.length) {
      $list.append('<div class="rsu-sections-empty">No sections yet. Click "+ Add Section" to get started.</div>');
      syncJSON($builder);
      return;
    }

    sections.forEach(function (section, si) {
      $list.append(buildSectionEl(section, si));
    });

    syncJSON($builder);
    initSortable($builder);

    // Auto-resize all textareas after rendering.
    // Run twice: once soon for fast layout, once later to catch late reflows.
    var resizeFn = function () {
      $builder.find('.rsu-block__content, .rsu-bullet-row__input').each(function () {
        autoResizeTextarea(this);
      });
    };
    setTimeout(resizeFn, 10);
    setTimeout(resizeFn, 150);
  }

  // ── Build a single section DOM element ──
  function buildSectionEl(section, si) {
    var $section = $(
      '<div class="rsu-section" data-index="' + si + '">' +
        '<div class="rsu-section__header">' +
          '<span class="rsu-section__drag dashicons dashicons-move" title="Drag to reorder"></span>' +
          '<input type="text" class="rsu-section__heading" placeholder="Section heading (e.g. Cold Weather Improvements)" value="" />' +
          '<button type="button" class="rsu-section__remove" title="Remove section">&times;</button>' +
        '</div>' +
        '<div class="rsu-blocks-list"></div>' +
        '<div class="rsu-section__footer">' +
          '<div class="rsu-add-block-group">' +
            '<button type="button" class="button button-small rsu-add-block" data-type="paragraph">+ Paragraph</button>' +
            '<button type="button" class="button button-small rsu-add-block" data-type="list">+ Bullet List</button>' +
            '<button type="button" class="button button-small rsu-add-block" data-type="note">+ Note</button>' +
          '</div>' +
        '</div>' +
      '</div>'
    );

    $section.find('.rsu-section__heading').val(section.heading || '');

    var $blocksList = $section.find('.rsu-blocks-list');
    if (section.blocks && section.blocks.length) {
      section.blocks.forEach(function (block, bi) {
        $blocksList.append(buildBlockEl(block, bi));
      });
    }

    return $section;
  }

  // ── Build a single block DOM element ──
  function buildBlockEl(block, bi) {
    var type = block.type || 'paragraph';
    var label = type === 'list' ? 'Bullet List' : type === 'note' ? 'Note' : 'Paragraph';

    if (type === 'list') {
      var items = Array.isArray(block.items) ? block.items : [];
      var $block = $(
        '<div class="rsu-block" data-index="' + bi + '" data-type="list">' +
          '<div class="rsu-block__header">' +
            '<span class="rsu-block__drag dashicons dashicons-move" title="Drag to reorder"></span>' +
            '<span class="rsu-block__label">' + label + '</span>' +
            '<button type="button" class="rsu-block__remove" title="Remove block">&times;</button>' +
          '</div>' +
          '<div class="rsu-bullet-list"></div>' +
          '<button type="button" class="rsu-bullet-add" title="Add bullet point">+ Add bullet</button>' +
        '</div>'
      );

      var $listContainer = $block.find('.rsu-bullet-list');
      if (items.length === 0) items = [''];
      items.forEach(function (item) {
        $listContainer.append(buildBulletRow(item));
      });

      return $block;
    }

    var placeholder = type === 'note' ? 'Note text...' : 'Paragraph text...';
    var content = block.content || '';

    var $block = $(
      '<div class="rsu-block" data-index="' + bi + '" data-type="' + type + '">' +
        '<div class="rsu-block__header">' +
          '<span class="rsu-block__drag dashicons dashicons-move" title="Drag to reorder"></span>' +
          '<span class="rsu-block__label">' + label + '</span>' +
          '<button type="button" class="rsu-block__remove" title="Remove block">&times;</button>' +
        '</div>' +
        '<textarea class="rsu-block__content" placeholder="' + placeholder + '" rows="1"></textarea>' +
      '</div>'
    );

    $block.find('.rsu-block__content').val(content);

    return $block;
  }

  // ── Build a single bullet row ──
  function buildBulletRow(value) {
    var $row = $(
      '<div class="rsu-bullet-row">' +
        '<span class="rsu-bullet-row__marker">&bull;</span>' +
        '<textarea class="rsu-bullet-row__input" placeholder="Bullet point text..." rows="1"></textarea>' +
        '<button type="button" class="rsu-bullet-row__remove" title="Remove bullet">&times;</button>' +
      '</div>'
    );

    $row.find('.rsu-bullet-row__input').val(value || '');

    return $row;
  }

  // ── Auto-resize a single textarea ──
  function autoResizeTextarea(el) {
    el.style.height = 'auto';
    el.style.height = el.scrollHeight + 'px';
  }

  // ── Sync the in-memory sections to the hidden JSON input ──
  function syncJSON($builder) {
    var $jsonInput = $builder.data('_jsonInput');
    if ($jsonInput && $jsonInput.length) {
      $jsonInput.val(JSON.stringify($builder.data('_sections') || []));
    }
  }

  // ── Read DOM state back into sections data ──
  function readFromDOM($builder) {
    var sections = [];
    $builder.find('.rsu-sections-list .rsu-section').each(function () {
      var $s = $(this);
      var section = {
        heading: $s.find('.rsu-section__heading').val().trim(),
        blocks: []
      };

      $s.find('.rsu-blocks-list .rsu-block').each(function () {
        var $b = $(this);
        var type = $b.data('type');

        if (type === 'list') {
          var items = [];
          $b.find('.rsu-bullet-row__input').each(function () {
            var val = $(this).val().trim();
            if (val !== '') items.push(val);
          });
          section.blocks.push({ type: 'list', items: items });
        } else {
          var raw = $b.find('.rsu-block__content').val();
          section.blocks.push({
            type: type,
            content: raw.trim()
          });
        }
      });

      sections.push(section);
    });

    $builder.data('_sections', sections);
    syncJSON($builder);
    return sections;
  }

  // ── Initialize jQuery UI sortable ──
  function initSortable($builder) {
    if (!$.fn.sortable) return;

    try {
      $builder.find('.rsu-sections-list').sortable({
        handle: '.rsu-section__drag',
        items: '> .rsu-section',
        axis: 'y',
        tolerance: 'pointer',
        update: function () {
          readFromDOM($builder);
        }
      });

      $builder.find('.rsu-blocks-list').sortable({
        handle: '.rsu-block__drag',
        items: '> .rsu-block',
        axis: 'y',
        tolerance: 'pointer',
        connectWith: '.rsu-blocks-list',
        update: function () {
          readFromDOM($builder);
        }
      });
    } catch (e) {
      // Non-critical — drag reordering won't work but editing still will.
    }
  }

  // ── Ensure builder data is bootstrapped ──
  function ensureBuilder($builder) {
    if ($builder.data('_sections')) return;

    var platform = $builder.data('platform');
    var $jsonInput = $builder.closest('.rsu-editor-panel')
      .find('.rsu-sections-json[data-platform="' + platform + '"]');
    var sections = [];

    try {
      sections = JSON.parse($jsonInput.val()) || [];
    } catch (e) {
      sections = [];
    }

    $builder.data('_sections', sections);
    $builder.data('_jsonInput', $jsonInput);
  }

  // ══════════════════════════════════════════════
  // Event handlers — delegated from document so
  // they work regardless of when the DOM renders
  // ══════════════════════════════════════════════

  // ── Platform checkbox: show/hide tabs and panels ──
  $(document).on('change', '.rsu-platform-checkbox', function () {
    var $tabs = $('#rsu-editor-tabs');
    var platform = $(this).data('platform');
    var $tab = $tabs.find('[data-platform="' + platform + '"]');
    var $panel = $('#rsu-editor-panel-' + platform);

    if (this.checked) {
      $tab.show();
      if (!$tabs.find('.rsu-editor-tab--active:visible').length) {
        activateTab(platform);
      }
    } else {
      $tab.hide().removeClass('rsu-editor-tab--active');
      $panel.addClass('rsu-editor-panel--hidden').hide();
      if (!$tabs.find('.rsu-editor-tab--active:visible').length) {
        var $first = $tabs.find('.rsu-editor-tab:visible').first();
        if ($first.length) {
          activateTab($first.data('platform'));
        }
      }
    }
  });

  // ── Tab switching ──
  $(document).on('click', '.rsu-editor-tab', function (e) {
    e.preventDefault();
    activateTab($(this).data('platform'));
  });

  function activateTab(platform) {
    var $tabs = $('#rsu-editor-tabs');
    $tabs.find('.rsu-editor-tab').removeClass('rsu-editor-tab--active')
      .attr('aria-selected', 'false');
    $('.rsu-editor-panel').addClass('rsu-editor-panel--hidden');

    $tabs.find('[data-platform="' + platform + '"]')
      .addClass('rsu-editor-tab--active')
      .attr('aria-selected', 'true');

    var $panel = $('#rsu-editor-panel-' + platform)
      .removeClass('rsu-editor-panel--hidden').show();

    // Auto-resize textareas now that the panel is visible.
    setTimeout(function () {
      $panel.find('.rsu-block__content, .rsu-bullet-row__input').each(function () {
        autoResizeTextarea(this);
      });
    }, 10);
  }

  // ── Add Section ──
  $(document).on('click', '.rsu-add-section', function (e) {
    e.preventDefault();
    e.stopPropagation();

    var $builder = $(this).closest('.rsu-section-builder');
    if (!$builder.length) return;

    ensureBuilder($builder);
    readFromDOM($builder);

    var sections = $builder.data('_sections');
    sections.push({
      heading: '',
      blocks: [{ type: 'paragraph', content: '' }]
    });
    $builder.data('_sections', sections);

    renderSections($builder);

    $builder.find('.rsu-section:last .rsu-section__heading').focus();
  });

  // ── Add Block ──
  $(document).on('click', '.rsu-add-block', function (e) {
    e.preventDefault();
    e.stopPropagation();

    var $builder = $(this).closest('.rsu-section-builder');
    var $section = $(this).closest('.rsu-section');
    var type = $(this).data('type');

    ensureBuilder($builder);
    readFromDOM($builder);

    var sectionIndex = $section.index();
    var sections = $builder.data('_sections');
    var newBlock = type === 'list'
      ? { type: 'list', items: [] }
      : { type: type, content: '' };

    sections[sectionIndex].blocks.push(newBlock);
    $builder.data('_sections', sections);

    renderSections($builder);

    $builder.find('.rsu-section').eq(sectionIndex)
      .find('.rsu-block:last').find('.rsu-block__content, .rsu-bullet-row__input').last().focus();
  });

  // ── Remove Section ──
  $(document).on('click', '.rsu-section__remove', function (e) {
    e.preventDefault();
    e.stopPropagation();

    if (!confirm('Remove this section?')) return;

    var $builder = $(this).closest('.rsu-section-builder');
    var $section = $(this).closest('.rsu-section');

    readFromDOM($builder);
    var sections = $builder.data('_sections');
    sections.splice($section.index(), 1);
    $builder.data('_sections', sections);
    renderSections($builder);
  });

  // ── Remove Block ──
  $(document).on('click', '.rsu-block__remove', function (e) {
    e.preventDefault();
    e.stopPropagation();

    var $builder = $(this).closest('.rsu-section-builder');
    var $section = $(this).closest('.rsu-section');
    var $block = $(this).closest('.rsu-block');

    readFromDOM($builder);
    var sections = $builder.data('_sections');
    var si = $section.index();
    sections[si].blocks.splice($block.index(), 1);
    $builder.data('_sections', sections);
    renderSections($builder);
  });

  // ── Live sync on input ──
  $(document).on('input', '.rsu-section__heading, .rsu-block__content, .rsu-bullet-row__input', function () {
    var $builder = $(this).closest('.rsu-section-builder');
    readFromDOM($builder);
  });

  // ── Auto-resize textareas ──
  $(document).on('input focus', '.rsu-block__content, .rsu-bullet-row__input', function () {
    autoResizeTextarea(this);
  });

  // ── Add bullet ──
  $(document).on('click', '.rsu-bullet-add', function (e) {
    e.preventDefault();
    e.stopPropagation();
    var $block = $(this).closest('.rsu-block');
    var $row = buildBulletRow('');
    $block.find('.rsu-bullet-list').append($row);
    $row.find('.rsu-bullet-row__input').focus();
    var $builder = $(this).closest('.rsu-section-builder');
    readFromDOM($builder);
  });

  // ── Remove bullet ──
  $(document).on('click', '.rsu-bullet-row__remove', function (e) {
    e.preventDefault();
    e.stopPropagation();
    var $row = $(this).closest('.rsu-bullet-row');
    var $block = $(this).closest('.rsu-block');
    var $builder = $(this).closest('.rsu-section-builder');
    $row.remove();
    // Ensure at least one bullet row remains
    if (!$block.find('.rsu-bullet-row').length) {
      var $newRow = buildBulletRow('');
      $block.find('.rsu-bullet-list').append($newRow);
      $newRow.find('.rsu-bullet-row__input').focus();
    }
    readFromDOM($builder);
  });

  // ── Copy from: copy sections from one platform to another ──
  $(document).on('change', '.rsu-copy-from-select', function () {
    var sourceSlug = $(this).val();
    var targetSlug = $(this).data('target');

    if (!sourceSlug) return;

    if (!confirm('Copy sections from the ' + sourceSlug + ' editor? This will overwrite the current sections.')) {
      $(this).val('');
      return;
    }

    var $sourceBuilder = $('.rsu-section-builder[data-platform="' + sourceSlug + '"]');
    var $targetBuilder = $('.rsu-section-builder[data-platform="' + targetSlug + '"]');

    ensureBuilder($sourceBuilder);
    ensureBuilder($targetBuilder);
    readFromDOM($sourceBuilder);
    var sourceSections = JSON.parse(JSON.stringify($sourceBuilder.data('_sections')));

    $targetBuilder.data('_sections', sourceSections);
    renderSections($targetBuilder);

    $(this).val('');
  });

  // ══════════════════════════════════════════════
  // Initialization — runs when DOM is ready,
  // with retry for Block Editor delayed rendering
  // ══════════════════════════════════════════════

  function initBuilders() {
    var $builders = $('.rsu-section-builder');
    if (!$builders.length) return false;

    $builders.each(function () {
      var $builder = $(this);
      if ($builder.data('_initialized')) return;

      ensureBuilder($builder);
      renderSections($builder);
      $builder.data('_initialized', true);
    });

    // Ensure first active tab is shown.
    var $tabs = $('#rsu-editor-tabs');
    if ($tabs.length) {
      var $activeTab = $tabs.find('.rsu-editor-tab:visible').first();
      if ($activeTab.length && !$tabs.find('.rsu-editor-tab--active:visible').length) {
        activateTab($activeTab.data('platform'));
      }
    }

    // Auto-resize textareas with existing content.
    setTimeout(function () {
      $('.rsu-block__content, .rsu-bullet-row__input').each(function () {
        autoResizeTextarea(this);
      });
    }, 100);

    return true;
  }

  $(document).ready(function () {
    if (!initBuilders()) {
      // Meta box may not be in the DOM yet (Block Editor).
      // Retry a few times with increasing delays.
      var attempts = 0;
      var retryInterval = setInterval(function () {
        attempts++;
        if (initBuilders() || attempts >= 10) {
          clearInterval(retryInterval);
        }
      }, 500);
    }
  });

})(jQuery);
