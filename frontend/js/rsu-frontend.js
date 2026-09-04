(function () {
  'use strict';

  // One shared preference for "my vehicle" across the update page tabs and
  // the [rsu_history] vehicle filter (rsu-history.js reads the same key).
  // Shape: { vehicle: 'r1', generation: { r1: 'gen2' } }. The pre-2.33 keys
  // are read once as a fallback and then superseded.
  var PREF_KEY = 'rsu_preferences';
  var LEGACY_VEHICLE_KEY = 'rsu_preferred_platform';

  function readPrefs() {
    var prefs = {};
    try {
      var raw = localStorage.getItem(PREF_KEY);
      if (raw) {
        var parsed = JSON.parse(raw);
        if (parsed && typeof parsed === 'object') prefs = parsed;
      } else {
        var legacy = localStorage.getItem(LEGACY_VEHICLE_KEY);
        if (legacy) prefs.vehicle = legacy;
      }
    } catch (e) {
      /* storage unavailable — nothing persists */
    }
    if (typeof prefs.vehicle !== 'string') prefs.vehicle = '';
    if (!prefs.generation || typeof prefs.generation !== 'object') prefs.generation = {};
    return prefs;
  }

  function writePrefs(prefs) {
    try {
      localStorage.setItem(PREF_KEY, JSON.stringify(prefs));
      localStorage.removeItem(LEGACY_VEHICLE_KEY);
    } catch (e) {
      /* silent */
    }
  }

  function setPreferredVehicle(slug) {
    var prefs = readPrefs();
    prefs.vehicle = slug || '';
    writePrefs(prefs);
  }

  function setPreferredGeneration(vehicle, generation) {
    var prefs = readPrefs();
    if (!generation || generation === 'all') {
      delete prefs.generation[vehicle];
    } else {
      prefs.generation[vehicle] = generation;
    }
    writePrefs(prefs);
  }

  function isSlug(value) {
    return typeof value === 'string' && /^[a-zA-Z0-9_-]+$/.test(value);
  }

  function init() {
    var containers = document.querySelectorAll('.rsu-update');
    containers.forEach(function (container) {
      setupTabs(container);
      setupGenerationFilters(container);
      setupAnchors(container);
    });
  }

  function hasPlatform(container, slug) {
    if (!isSlug(slug)) return false;
    return !!container.querySelector('.rsu-tab[data-platform="' + slug + '"]');
  }

  /**
   * The panel that contains the element a hash points at, if any.
   */
  function panelForHash(container, hash) {
    if (!hash || !isSlug(hash)) return null;
    var target = container.querySelector('#' + hash);
    if (!target) return null;
    return target.closest('.rsu-panel');
  }

  function setupTabs(container) {
    var tablist = container.querySelector('.rsu-tabs');
    if (!tablist) return;

    var tabs = tablist.querySelectorAll('.rsu-tab');
    if (tabs.length === 0) return;

    // Set proper ARIA tabindex on all tabs.
    tabs.forEach(function (tab) {
      if (!tab.classList.contains('rsu-tab--active')) {
        tab.setAttribute('tabindex', '-1');
      }
    });

    if (tabs.length < 2) return;

    // A hash naming a vehicle wins, then a hash pointing into a vehicle's
    // panel (a shared section link), then the remembered vehicle. PHP
    // already rendered the configured default, so JS only steps in when one
    // of those is present. The URL is left alone on load; it only changes
    // when the reader picks a tab.
    var hash = window.location.hash.replace('#', '');
    var override = '';
    if (hasPlatform(container, hash)) {
      override = hash;
    } else {
      var hashPanel = panelForHash(container, hash);
      if (hashPanel && hashPanel.dataset.platform) {
        override = hashPanel.dataset.platform;
      } else {
        var preferred = readPrefs().vehicle;
        if (hasPlatform(container, preferred)) override = preferred;
      }
    }

    if (override) {
      activateTab(container, override, false);
    }

    function choose(platform) {
      activateTab(container, platform, true);
      setPreferredVehicle(platform);
      history.replaceState(null, '', '#' + platform);
    }

    // Click handler.
    tabs.forEach(function (tab) {
      tab.addEventListener('click', function (e) {
        e.preventDefault();
        choose(tab.dataset.platform);
      });
    });

    // Keyboard navigation (arrow keys, Enter, Space).
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
      } else if (e.key === 'Enter' || e.key === ' ') {
        e.preventDefault();
        choose(tabArray[currentIndex].dataset.platform);
        return;
      }

      if (newIndex >= 0) {
        tabArray[newIndex].focus();
        choose(tabArray[newIndex].dataset.platform);
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
      // Cancel any in-flight enter animation so rapid switching can't stack
      // multiple animationend listeners (each firing a stale scrollTo).
      panel.classList.remove('rsu-panel--enter');
      if (panel._rsuAnimHandler) {
        panel.removeEventListener('animationend', panel._rsuAnimHandler);
        panel._rsuAnimHandler = null;
      }
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
      // Preserve scroll position across panel height changes.
      var scrollY = window.scrollY;

      targetPanel.classList.add('rsu-panel--active');
      targetPanel.hidden = false;

      window.scrollTo(0, scrollY);

      if (animate) {
        targetPanel.classList.add('rsu-panel--enter');
        // Remove animation class after it completes. Track the handler so a
        // subsequent activation can detach it if the animation is interrupted.
        var handler = function () {
          targetPanel.classList.remove('rsu-panel--enter');
          targetPanel.removeEventListener('animationend', handler);
          targetPanel._rsuAnimHandler = null;
          window.scrollTo(0, scrollY);
        };
        targetPanel._rsuAnimHandler = handler;
        targetPanel.addEventListener('animationend', handler);
      }
    }
  }

  /**
   * Per-panel "Show notes for: All / Gen 1 / Gen 2" control. Elements tagged
   * for another generation get .rsu-gen-hidden; the panel's data-rsu-gen
   * attribute lets CSS drop the now-redundant pills. Remembered per vehicle.
   */
  function setupGenerationFilters(container) {
    var filters = container.querySelectorAll('.rsu-gen-filter');
    filters.forEach(function (filter) {
      var panel = filter.closest('.rsu-panel');
      if (!panel) return;

      var vehicle = panel.dataset.platform || '';
      var buttons = filter.querySelectorAll('.rsu-gen-filter__btn');
      if (buttons.length < 2) return;

      function apply(generation, persist) {
        var found = false;
        buttons.forEach(function (btn) {
          var isActive = (btn.dataset.generation || 'all') === generation;
          if (isActive) found = true;
          btn.classList.toggle('rsu-gen-filter__btn--active', isActive);
          btn.setAttribute('aria-pressed', isActive ? 'true' : 'false');
        });
        if (!found) return;

        if (generation === 'all') {
          delete panel.dataset.rsuGen;
        } else {
          panel.dataset.rsuGen = generation;
        }

        // Tagged elements (sections, paragraphs, notes, list items, jump
        // links) for another generation are hidden; the pills themselves
        // are handled by CSS off data-rsu-gen.
        var tagged = panel.querySelectorAll('[data-generation]:not(.rsu-gen-pill)');
        tagged.forEach(function (el) {
          var hide = generation !== 'all' && el.dataset.generation !== generation;
          el.classList.toggle('rsu-gen-hidden', hide);
        });

        if (persist && vehicle) setPreferredGeneration(vehicle, generation);
      }

      buttons.forEach(function (btn) {
        btn.addEventListener('click', function () {
          apply(btn.dataset.generation || 'all', true);
        });
      });

      var remembered = vehicle ? readPrefs().generation[vehicle] : '';
      if (isSlug(remembered)) apply(remembered, false);
    });
  }

  /**
   * Section links inside a hidden panel cannot be scrolled to by the browser
   * on load, so once the right tab is active, finish the jump ourselves.
   * Jump-list clicks inside a panel are left to the browser; the target is
   * already visible.
   */
  function setupAnchors(container) {
    var hash = window.location.hash.replace('#', '');
    if (!hash || !isSlug(hash)) return;

    var target = container.querySelector('#' + hash);
    if (!target || !target.closest('.rsu-panel')) return;

    // Let the panel switch (and any theme layout) settle first.
    window.requestAnimationFrame(function () {
      target.scrollIntoView({ block: 'start' });
    });
  }

  // Initialize when DOM is ready.
  if (document.readyState === 'loading') {
    document.addEventListener('DOMContentLoaded', init);
  } else {
    init();
  }
})();
