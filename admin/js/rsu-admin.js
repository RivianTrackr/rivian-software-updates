(function ($) {
  'use strict';

  $(document).ready(function () {
    var $wrap = $('.rsu-admin-wrap');
    if (!$wrap.length) return;

    var $toggle = $('#rsu-is-update');
    var $fields = $('#rsu-fields');
    var $tabs = $('#rsu-editor-tabs');

    // ── Toggle software update fields ──
    $toggle.on('change', function () {
      if (this.checked) {
        $fields.slideDown(200);
      } else {
        $fields.slideUp(200);
      }
    });

    // ── Platform checkbox: show/hide tabs and panels ──
    $('.rsu-platform-checkbox').on('change', function () {
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
    $tabs.on('click', '.rsu-editor-tab', function (e) {
      e.preventDefault();
      activateTab($(this).data('platform'));
    });

    function activateTab(platform) {
      $tabs.find('.rsu-editor-tab').removeClass('rsu-editor-tab--active')
        .attr('aria-selected', 'false');
      $('.rsu-editor-panel').addClass('rsu-editor-panel--hidden');

      $tabs.find('[data-platform="' + platform + '"]')
        .addClass('rsu-editor-tab--active')
        .attr('aria-selected', 'true');

      $('#rsu-editor-panel-' + platform)
        .removeClass('rsu-editor-panel--hidden').show();
    }

    // ── On load, ensure first active tab is shown ──
    var $activeTab = $tabs.find('.rsu-editor-tab:visible').first();
    if ($activeTab.length && !$tabs.find('.rsu-editor-tab--active:visible').length) {
      activateTab($activeTab.data('platform'));
    }

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
      var placeholder = '';
      var content = '';

      if (type === 'list') {
        placeholder = 'One bullet point per line';
        content = Array.isArray(block.items) ? block.items.join('\n') : '';
      } else {
        placeholder = type === 'note' ? 'Note text...' : 'Paragraph text...';
        content = block.content || '';
      }

      var $block = $(
        '<div class="rsu-block" data-index="' + bi + '" data-type="' + type + '">' +
          '<div class="rsu-block__header">' +
            '<span class="rsu-block__drag dashicons dashicons-move" title="Drag to reorder"></span>' +
            '<span class="rsu-block__label">' + label + '</span>' +
            '<button type="button" class="rsu-block__remove" title="Remove block">&times;</button>' +
          '</div>' +
          '<textarea class="rsu-block__content" placeholder="' + placeholder + '" rows="3"></textarea>' +
        '</div>'
      );

      $block.find('.rsu-block__content').val(content);

      return $block;
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
          var raw = $b.find('.rsu-block__content').val();

          if (type === 'list') {
            section.blocks.push({
              type: 'list',
              items: raw.split('\n').filter(function (line) { return line.trim() !== ''; })
            });
          } else {
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
        // Sortable may fail on hidden elements; non-critical.
      }
    }

    // ══════════════════════════════════════════════
    // Section Builder — event handlers
    // (registered BEFORE init so they work even if init fails)
    // ══════════════════════════════════════════════

    // ── Add Section ──
    $wrap.on('click', '.rsu-add-section', function () {
      var $builder = $(this).closest('.rsu-section-builder');

      // Ensure builder data exists.
      if (!$builder.data('_sections')) {
        var $jsonInput = $builder.closest('.rsu-editor-panel')
          .find('.rsu-sections-json[data-platform="' + $builder.data('platform') + '"]');
        var initial = [];
        try { initial = JSON.parse($jsonInput.val()) || []; } catch (e) { initial = []; }
        $builder.data('_sections', initial);
        $builder.data('_jsonInput', $jsonInput);
      }

      readFromDOM($builder);

      var sections = $builder.data('_sections');
      sections.push({
        heading: '',
        blocks: [{ type: 'paragraph', content: '' }]
      });
      $builder.data('_sections', sections);

      renderSections($builder);

      // Focus the new heading.
      $builder.find('.rsu-section:last .rsu-section__heading').focus();
    });

    // ── Add Block ──
    $wrap.on('click', '.rsu-add-block', function () {
      var $builder = $(this).closest('.rsu-section-builder');
      var $section = $(this).closest('.rsu-section');
      var type = $(this).data('type');

      readFromDOM($builder);

      var sectionIndex = $section.index();
      var sections = $builder.data('_sections');
      var newBlock = type === 'list'
        ? { type: 'list', items: [] }
        : { type: type, content: '' };

      sections[sectionIndex].blocks.push(newBlock);
      $builder.data('_sections', sections);

      renderSections($builder);

      // Focus the new block's textarea.
      $builder.find('.rsu-section').eq(sectionIndex)
        .find('.rsu-block:last .rsu-block__content').focus();
    });

    // ── Remove Section ──
    $wrap.on('click', '.rsu-section__remove', function () {
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
    $wrap.on('click', '.rsu-block__remove', function () {
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
    $wrap.on('input', '.rsu-section__heading, .rsu-block__content', function () {
      var $builder = $(this).closest('.rsu-section-builder');
      readFromDOM($builder);
    });

    // ── Auto-resize textareas ──
    $wrap.on('input', '.rsu-block__content', function () {
      this.style.height = 'auto';
      this.style.height = (this.scrollHeight) + 'px';
    });

    // ── Copy from: copy sections from one platform to another ──
    $('.rsu-copy-from-select').on('change', function () {
      var sourceSlug = $(this).val();
      var targetSlug = $(this).data('target');

      if (!sourceSlug) return;

      if (!confirm('Copy sections from the ' + sourceSlug + ' editor? This will overwrite the current sections.')) {
        $(this).val('');
        return;
      }

      var $sourceBuilder = $('.rsu-section-builder[data-platform="' + sourceSlug + '"]');
      var $targetBuilder = $('.rsu-section-builder[data-platform="' + targetSlug + '"]');

      readFromDOM($sourceBuilder);
      var sourceSections = JSON.parse(JSON.stringify($sourceBuilder.data('_sections')));

      $targetBuilder.data('_sections', sourceSections);
      renderSections($targetBuilder);

      $(this).val('');
    });

    // ══════════════════════════════════════════════
    // Section Builder — initialization
    // (runs after handlers are registered)
    // ══════════════════════════════════════════════

    $('.rsu-section-builder').each(function () {
      var $builder = $(this);
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

      renderSections($builder);
    });

    // Trigger initial resize for loaded content.
    setTimeout(function () {
      $('.rsu-block__content').each(function () {
        this.style.height = 'auto';
        this.style.height = (this.scrollHeight) + 'px';
      });
    }, 100);
  });
})(jQuery);
