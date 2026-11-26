(function () {
  'use strict';

  // [rsu_history]: years past the first few render visible (so they work
  // without JavaScript) and are folded here behind a "Show … and earlier"
  // button. Opening them moves focus to the first year that appeared.

  function setup(container) {
    var more = container.querySelector('.rsu-history__more');
    var older = container.querySelectorAll('.rsu-history__year--older');
    if (!more || !older.length) return;

    var button = more.querySelector('.rsu-history__more-btn');
    container.classList.add('rsu-history--folded');
    more.hidden = false;

    button.addEventListener('click', function () {
      container.classList.remove('rsu-history--folded');
      button.setAttribute('aria-expanded', 'true');
      more.hidden = true;
      var heading = older[0].querySelector('.rsu-history__year-label');
      if (heading) heading.focus({ preventScroll: false });
    });
  }

  function init() {
    document.querySelectorAll('.rsu-history').forEach(setup);
  }

  if (document.readyState === 'loading') {
    document.addEventListener('DOMContentLoaded', init);
  } else {
    init();
  }
})();
