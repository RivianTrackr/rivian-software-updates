(function ($) {
  'use strict';

  $(document).ready(function () {
    var $wrap = $('.rsu-admin-wrap');
    if (!$wrap.length) return;

    var $toggle = $('#rsu-is-update');
    var $fields = $('#rsu-fields');
    var $tabs = $('#rsu-editor-tabs');

    // Toggle software update fields visibility.
    $toggle.on('change', function () {
      if (this.checked) {
        $fields.slideDown(200);
      } else {
        $fields.slideUp(200);
      }
    });

    // Platform checkbox: show/hide editor tabs and panels.
    $('.rsu-platform-checkbox').on('change', function () {
      var platform = $(this).data('platform');
      var $tab = $tabs.find('[data-platform="' + platform + '"]');
      var $panel = $('#rsu-editor-panel-' + platform);

      if (this.checked) {
        $tab.show();
        // If no tab is active, activate this one.
        if (!$tabs.find('.rsu-editor-tab--active:visible').length) {
          activateTab(platform);
        }
      } else {
        $tab.hide().removeClass('rsu-editor-tab--active');
        $panel.addClass('rsu-editor-panel--hidden').hide();
        // If we hid the active tab, activate the first visible one.
        if (!$tabs.find('.rsu-editor-tab--active:visible').length) {
          var $first = $tabs.find('.rsu-editor-tab:visible').first();
          if ($first.length) {
            activateTab($first.data('platform'));
          }
        }
      }
    });

    // Tab switching.
    $tabs.on('click', '.rsu-editor-tab', function (e) {
      e.preventDefault();
      activateTab($(this).data('platform'));
    });

    function activateTab(platform) {
      // Deactivate all.
      $tabs.find('.rsu-editor-tab').removeClass('rsu-editor-tab--active')
        .attr('aria-selected', 'false');
      $('.rsu-editor-panel').addClass('rsu-editor-panel--hidden');

      // Activate selected.
      $tabs.find('[data-platform="' + platform + '"]')
        .addClass('rsu-editor-tab--active')
        .attr('aria-selected', 'true');

      var $panel = $('#rsu-editor-panel-' + platform);
      $panel.removeClass('rsu-editor-panel--hidden').show();

      // Refresh TinyMCE if it exists (fixes rendering after show).
      var editorId = 'rsu_content_' + platform;
      if (typeof tinymce !== 'undefined') {
        var editor = tinymce.get(editorId);
        if (editor) {
          // Small delay to let the DOM settle before refresh.
          setTimeout(function () {
            editor.fire('show');
          }, 50);
        }
      }
    }

    // Copy from: copy content from one platform editor to another.
    $('.rsu-copy-from-select').on('change', function () {
      var sourceSlug = $(this).val();
      var targetSlug = $(this).data('target');

      if (!sourceSlug) return;

      if (!confirm('Copy content from the ' + sourceSlug + ' editor? This will overwrite the current content in this editor.')) {
        $(this).val('');
        return;
      }

      var sourceId = 'rsu_content_' + sourceSlug;
      var targetId = 'rsu_content_' + targetSlug;
      var content = '';

      // Get content from source (TinyMCE or textarea).
      if (typeof tinymce !== 'undefined' && tinymce.get(sourceId)) {
        content = tinymce.get(sourceId).getContent();
      } else {
        content = $('#' + sourceId).val();
      }

      // Set content on target.
      if (typeof tinymce !== 'undefined' && tinymce.get(targetId)) {
        tinymce.get(targetId).setContent(content);
      } else {
        $('#' + targetId).val(content);
      }

      $(this).val('');
    });

    // On load, ensure the first active tab is shown.
    var $activeTab = $tabs.find('.rsu-editor-tab:visible').first();
    if ($activeTab.length && !$tabs.find('.rsu-editor-tab--active:visible').length) {
      activateTab($activeTab.data('platform'));
    }
  });
})(jQuery);
