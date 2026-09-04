(function () {
  'use strict';

  // Shares the "my vehicle" preference with the update-page tabs
  // (rsu-frontend.js). Picking R2 here selects the R2 tab on the next
  // release page, and vice versa. "All Vehicles" clears the preference.
  var PREF_KEY = 'rsu_preferences';
  var LEGACY_KEYS = ['rsu_history_filter', 'rsu_preferred_platform'];

  function readPrefs() {
    var prefs = {};
    try {
      var raw = localStorage.getItem(PREF_KEY);
      if (raw) {
        var parsed = JSON.parse(raw);
        if (parsed && typeof parsed === 'object') prefs = parsed;
      } else {
        for (var i = 0; i < LEGACY_KEYS.length; i++) {
          var legacy = localStorage.getItem(LEGACY_KEYS[i]);
          if (legacy && legacy !== 'all') {
            prefs.vehicle = legacy;
            break;
          }
        }
      }
    } catch (e) {
      /* storage unavailable — selection just won't persist */
    }
    if (typeof prefs.vehicle !== 'string') prefs.vehicle = '';
    return prefs;
  }

  function setPreferredVehicle(slug) {
    var prefs = readPrefs();
    prefs.vehicle = slug || '';
    try {
      localStorage.setItem(PREF_KEY, JSON.stringify(prefs));
      LEGACY_KEYS.forEach(function (key) {
        localStorage.removeItem(key);
      });
    } catch (e) {
      /* silent */
    }
  }

  function init() {
    var containers = document.querySelectorAll('.rsu-history');
    containers.forEach(function (container) {
      setupFilter(container);
    });
  }

  function setupFilter(container) {
    var bar = container.querySelector('.rsu-history__filter');
    if (!bar) return;

    var buttons = bar.querySelectorAll('.rsu-history__filter-btn');
    if (buttons.length < 2) return;

    // Restore the remembered vehicle, but only if it is still present.
    var stored = readPrefs().vehicle;
    var initial = 'all';
    if (stored) {
      for (var i = 0; i < buttons.length; i++) {
        if (buttons[i].getAttribute('data-vehicle') === stored) {
          initial = stored;
          break;
        }
      }
    }

    buttons.forEach(function (btn) {
      btn.addEventListener('click', function () {
        var vehicle = btn.getAttribute('data-vehicle') || 'all';
        applyFilter(container, buttons, vehicle);
        setPreferredVehicle(vehicle === 'all' ? '' : vehicle);
      });
    });

    applyFilter(container, buttons, initial);
  }

  function applyFilter(container, buttons, vehicle) {
    // Toggle the active button state.
    buttons.forEach(function (btn) {
      var isActive = (btn.getAttribute('data-vehicle') || 'all') === vehicle;
      btn.classList.toggle('rsu-history__filter-btn--active', isActive);
      btn.setAttribute('aria-pressed', isActive ? 'true' : 'false');
    });

    var years = container.querySelectorAll('.rsu-history__year');
    var firstVisibleOpen = false;

    years.forEach(function (year) {
      var rows = year.querySelectorAll('tbody tr');
      var visible = 0;

      rows.forEach(function (row) {
        var slugs = (row.getAttribute('data-vehicles') || '').split(/\s+/);
        var match = vehicle === 'all' || slugs.indexOf(vehicle) !== -1;
        row.hidden = !match;
        if (match) visible++;
      });

      // Hide a whole year section when it has no matching updates.
      year.hidden = visible === 0;

      // Update the per-year count to reflect the active filter.
      var count = year.querySelector('.rsu-history__year-count');
      if (count) {
        count.textContent = visible + ' update' + (visible !== 1 ? 's' : '');
      }

      // Keep the topmost visible year expanded so the list never collapses
      // to nothing after a filter change.
      if (!year.hidden) {
        if (!firstVisibleOpen) {
          year.open = true;
          firstVisibleOpen = true;
        }
      }
    });
  }

  if (document.readyState === 'loading') {
    document.addEventListener('DOMContentLoaded', init);
  } else {
    init();
  }
})();
