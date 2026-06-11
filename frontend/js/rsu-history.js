(function () {
  'use strict';

  var STORAGE_KEY = 'rsu_history_filter';

  function init() {
    var containers = document.querySelectorAll('.rsu-history');
    containers.forEach(function (container) {
      setupFilter(container);
    });
  }

  function getStored() {
    try {
      return localStorage.getItem(STORAGE_KEY) || '';
    } catch (e) {
      return '';
    }
  }

  function setStored(value) {
    try {
      localStorage.setItem(STORAGE_KEY, value);
    } catch (e) {
      /* storage unavailable — selection just won't persist */
    }
  }

  function setupFilter(container) {
    var bar = container.querySelector('.rsu-history__filter');
    if (!bar) return;

    var buttons = bar.querySelectorAll('.rsu-history__filter-btn');
    if (buttons.length < 2) return;

    // Restore a remembered selection, but only if that vehicle is still present.
    var stored = getStored();
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
        setStored(vehicle);
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
