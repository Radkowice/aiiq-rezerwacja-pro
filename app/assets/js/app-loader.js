(function () {
  var fallbackTimer = null;
  var hidden = false;

  function getLoader() {
    return document.getElementById('appLoader');
  }

  function getErrorEl(loader) {
    return loader ? loader.querySelector('[data-loader-error]') : null;
  }

  function getRefreshBtn(loader) {
    return loader ? loader.querySelector('[data-loader-refresh]') : null;
  }

  function clearFallback() {
    if (fallbackTimer) {
      window.clearTimeout(fallbackTimer);
      fallbackTimer = null;
    }
  }

  function ensureRefreshHandler(loader) {
    var refreshBtn = getRefreshBtn(loader);

    if (!refreshBtn || refreshBtn.getAttribute('data-loader-bound') === '1') {
      return;
    }

    refreshBtn.setAttribute('data-loader-bound', '1');
    refreshBtn.addEventListener('click', function () {
      window.location.reload();
    });
  }

  function show() {
    hidden = false;
    if (document.body) {
      document.body.classList.add('app-loading');
    }

    var loader = getLoader();
    if (!loader) return;

    loader.classList.remove('is-error');
    loader.setAttribute('aria-hidden', 'false');
    ensureRefreshHandler(loader);
  }

  function hide() {
    if (hidden) return;

    hidden = true;
    clearFallback();

    if (document.body) {
      document.body.classList.remove('app-loading');
    }

    var loader = getLoader();
    if (!loader) return;

    loader.classList.remove('is-error');
    loader.setAttribute('aria-hidden', 'true');
  }

  function fail(message) {
    clearFallback();
    hidden = false;

    if (document.body) {
      document.body.classList.add('app-loading');
    }

    var loader = getLoader();
    if (!loader) return;

    var errorEl = getErrorEl(loader);
    loader.classList.add('is-error');
    loader.setAttribute('aria-hidden', 'false');

    if (errorEl) {
      errorEl.textContent = message || 'Nie udało się załadować aplikacji. Odśwież stronę i spróbuj ponownie.';
    }

    ensureRefreshHandler(loader);
  }

  function initFallback(timeoutMs) {
    clearFallback();

    var delay = parseInt(timeoutMs, 10);
    if (!delay || delay < 1000) {
      delay = 10000;
    }

    fallbackTimer = window.setTimeout(function () {
      hide();
    }, delay);
  }

  window.AppLoader = {
    show: show,
    hide: hide,
    fail: fail,
    initFallback: initFallback
  };
})();
