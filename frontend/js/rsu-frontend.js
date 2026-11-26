(function () {
  'use strict';

  // The reader's "my vehicle" choice from the update page tabs, remembered
  // across release pages.
  // Shape: { vehicle: 'r1' }. The pre-2.33 key is read once as a fallback
  // and then superseded.
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

  function isSlug(value) {
    return typeof value === 'string' && /^[a-zA-Z0-9_-]+$/.test(value);
  }

  function init() {
    var containers = document.querySelectorAll('.rsu-update');
    containers.forEach(function (container) {
      setupOffset(container);
      setupTabs(container);
      setupJump(container);
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
      // With the bar pinned, a switch lands at the top of the new notes
      // instead of somewhere in the middle of them.
      var y;
      var bar = container.querySelector('.rsu-bar');
      if (bar && isPinned(container, bar)) {
        var firstPanel = container.querySelector('.rsu-panel');
        y = firstPanel.getBoundingClientRect().top + window.scrollY - stickyOffset(container) - bar.offsetHeight;
      }
      activateTab(container, platform, true, y);
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

  // Instant, even where the theme sets scroll-behavior: smooth, so keeping
  // or resetting the reader's place never animates across the page.
  function jumpTo(y) {
    try {
      window.scrollTo({ top: y, left: 0, behavior: 'instant' });
    } catch (e) {
      window.scrollTo(0, y);
    }
  }

  function activateTab(container, platform, animate, y) {
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
      // Preserve scroll position across panel height changes, unless the
      // caller asked for a specific position.
      var scrollY = typeof y === 'number' ? Math.max(0, y) : window.scrollY;

      targetPanel.classList.add('rsu-panel--active');
      targetPanel.hidden = false;

      jumpTo(scrollY);

      if (animate) {
        targetPanel.classList.add('rsu-panel--enter');
        // Remove animation class after it completes. Track the handler so a
        // subsequent activation can detach it if the animation is interrupted.
        var handler = function () {
          targetPanel.classList.remove('rsu-panel--enter');
          targetPanel.removeEventListener('animationend', handler);
          targetPanel._rsuAnimHandler = null;
          jumpTo(scrollY);
        };
        targetPanel._rsuAnimHandler = handler;
        targetPanel.addEventListener('animationend', handler);
      }
    }

    refreshJump(container);
  }

  /**
   * Height of whatever the theme pins to the top of the viewport (a sticky
   * header, the admin bar), so the bar can sit just below it. A value the
   * theme sets for --rsu-sticky-offset wins; otherwise it is measured, and
   * re-measured on scroll because headers often only become fixed then.
   */
  function stickyOffset(container) {
    return parseFloat(getComputedStyle(container).getPropertyValue('--rsu-sticky-offset')) || 0;
  }

  function isPinned(container, bar) {
    return bar.getBoundingClientRect().top <= stickyOffset(container) + 1;
  }

  function setupOffset(container) {
    if (stickyOffset(container) > 0) return;

    // A pinned header's content can hang below its own box (Blocksy's
    // floating header card is 16px taller than its fixed wrapper), so walk
    // down from the box edge while the point is still inside the header.
    function visibleBottom(node, x, bottom) {
      var limit = window.innerHeight / 3;
      var y = Math.ceil(bottom);
      while (y < limit) {
        var hit = document.elementsFromPoint(x, y + 1).some(function (el) {
          return node.contains(el);
        });
        if (!hit) break;
        y += 2;
      }
      return y;
    }

    // The fixed or sticky element (outside this update) covering point
    // (x, py), if it is short enough to be a header.
    function pinnedAt(x, py) {
      var stack = document.elementsFromPoint(x, py);
      for (var j = 0; j < stack.length; j++) {
        if (container.contains(stack[j])) continue;
        for (var node = stack[j]; node && node !== document.body; node = node.parentElement) {
          var pos = getComputedStyle(node).position;
          if (pos === 'fixed' || pos === 'sticky') {
            var rect = node.getBoundingClientRect();
            if (rect.top <= py && rect.bottom > py && rect.height < window.innerHeight / 3) {
              return node;
            }
            break;
          }
        }
      }
      return null;
    }

    // Stack up everything pinned at the top: the admin bar, then a header
    // that may float a few pixels below it.
    function pinnedBottom() {
      var x = Math.round(window.innerWidth / 2);
      var y = 0;
      for (var i = 0; i < 4; i++) {
        var node = null;
        for (var d = 1; d <= 25 && !node; d += 4) {
          node = pinnedAt(x, y + d);
        }
        if (!node) break;
        y = visibleBottom(node, x, node.getBoundingClientRect().bottom);
      }
      return y;
    }

    var queued = false;
    function update() {
      queued = false;
      container.style.setProperty('--rsu-sticky-offset', pinnedBottom() + 'px');
    }
    function queue() {
      if (!queued) {
        queued = true;
        window.requestAnimationFrame(update);
      }
    }
    update();
    window.addEventListener('scroll', queue, { passive: true });
    window.addEventListener('resize', queue);
  }

  /**
   * The "Sections" dropdown in the bar lists the visible vehicle's sections
   * (read from that panel's hidden jump list) and scrolls to the one picked.
   */
  function refreshJump(container) {
    var select = container.querySelector('.rsu-jump');
    if (!select) return;
    var panel = container.querySelector('.rsu-panel:not([hidden])') || container.querySelector('.rsu-panel');
    var links = panel ? panel.querySelectorAll('.rsu-toc__link') : [];
    var wrap = select.closest('.rsu-jump-wrap');

    while (select.options.length > 1) select.remove(1);
    Array.prototype.forEach.call(links, function (link) {
      var option = document.createElement('option');
      option.value = link.getAttribute('href');
      option.textContent = link.textContent;
      select.appendChild(option);
    });
    select.selectedIndex = 0;
    if (wrap) wrap.hidden = links.length < 3;
  }

  function setupJump(container) {
    var select = container.querySelector('.rsu-jump');
    if (!select) return;
    refreshJump(container);
    select.addEventListener('change', function () {
      var hash = select.value.replace('#', '');
      select.selectedIndex = 0;
      if (!isSlug(hash)) return;
      var target = container.querySelector('#' + hash);
      if (!target) return;
      target.scrollIntoView({ block: 'start' });
      history.replaceState(null, '', '#' + hash);
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
