(function () {
  'use strict';

  var STORAGE_KEY = 'rsu_preferred_platform';

  function init() {
    var containers = document.querySelectorAll('.rsu-update');
    containers.forEach(function (container) {
      setupTabs(container);
    });
  }

  function setupTabs(container) {
    var tablist = container.querySelector('.rsu-tabs');
    if (!tablist) return;

    var tabs = tablist.querySelectorAll('.rsu-tab');
    if (tabs.length < 2) return;

    // Determine initial platform: URL hash > localStorage > data-rsu-default.
    var hash = window.location.hash.replace('#', '');
    var stored = getPreferred();
    var defaultPlatform = container.dataset.rsuDefault || 'gen2';
    var initial = null;

    // Check hash first.
    if (hash && container.querySelector('[data-platform="' + hash + '"]')) {
      initial = hash;
    } else if (stored && container.querySelector('[data-platform="' + stored + '"]')) {
      initial = stored;
    } else {
      initial = defaultPlatform;
    }

    // Activate initial tab if not already.
    var activeTab = tablist.querySelector('.rsu-tab--active');
    if (!activeTab || activeTab.dataset.platform !== initial) {
      activateTab(container, initial, false);
    }

    // Click handler.
    tabs.forEach(function (tab) {
      tab.addEventListener('click', function (e) {
        e.preventDefault();
        var platform = tab.dataset.platform;
        activateTab(container, platform, true);
        setPreferred(platform);
        // Update URL hash without scrolling.
        history.replaceState(null, '', '#' + platform);
      });
    });

    // Keyboard navigation (arrow keys).
    tablist.addEventListener('keydown', function (e) {
      var tabArray = Array.from(tabs);
      var currentIndex = tabArray.indexOf(document.activeElement);
      if (currentIndex === -1) return;

      var newIndex = -1;

      if (e.key === 'ArrowRight' || e.key === 'ArrowDown') {
        e.preventDefault();
        newIndex = (currentIndex + 1) % tabArray.length;
      } else if (e.key === 'ArrowLeft' || e.key === 'ArrowUp') {
        e.preventDefault();
        newIndex = (currentIndex - 1 + tabArray.length) % tabArray.length;
      } else if (e.key === 'Home') {
        e.preventDefault();
        newIndex = 0;
      } else if (e.key === 'End') {
        e.preventDefault();
        newIndex = tabArray.length - 1;
      }

      if (newIndex >= 0) {
        tabArray[newIndex].focus();
        var platform = tabArray[newIndex].dataset.platform;
        activateTab(container, platform, true);
        setPreferred(platform);
        history.replaceState(null, '', '#' + platform);
      }
    });
  }

  function activateTab(container, platform, animate) {
    var tabs = container.querySelectorAll('.rsu-tab');
    var panels = container.querySelectorAll('.rsu-panel');

    // Deactivate all.
    tabs.forEach(function (tab) {
      tab.classList.remove('rsu-tab--active');
      tab.setAttribute('aria-selected', 'false');
      tab.setAttribute('tabindex', '-1');
    });
    panels.forEach(function (panel) {
      panel.classList.remove('rsu-panel--active');
      panel.hidden = true;
    });

    // Activate target.
    var targetTab = container.querySelector('.rsu-tab[data-platform="' + platform + '"]');
    var targetPanel = container.querySelector('#rsu-panel-' + platform);

    if (targetTab) {
      targetTab.classList.add('rsu-tab--active');
      targetTab.setAttribute('aria-selected', 'true');
      targetTab.removeAttribute('tabindex');
    }
    if (targetPanel) {
      targetPanel.classList.add('rsu-panel--active');
      targetPanel.hidden = false;

      if (animate) {
        targetPanel.classList.add('rsu-panel--enter');
        // Remove animation class after it completes.
        targetPanel.addEventListener('animationend', function handler() {
          targetPanel.classList.remove('rsu-panel--enter');
          targetPanel.removeEventListener('animationend', handler);
        });
      }
    }
  }

  function getPreferred() {
    try {
      return localStorage.getItem(STORAGE_KEY);
    } catch (e) {
      return null;
    }
  }

  function setPreferred(platform) {
    try {
      localStorage.setItem(STORAGE_KEY, platform);
    } catch (e) {
      // Silent fail.
    }
  }

  // Initialize when DOM is ready.
  if (document.readyState === 'loading') {
    document.addEventListener('DOMContentLoaded', init);
  } else {
    init();
  }
})();
