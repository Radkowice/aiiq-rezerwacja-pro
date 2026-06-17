(function () {
  'use strict';

  function isAllowedReservationUrl(url) {
    return (
      url
      && url.protocol === 'https:'
      && (
        url.hostname === 'rezerwacja-ai-iq.pl'
        || url.hostname.endsWith('.rezerwacja-ai-iq.pl')
      )
    );
  }

  function parseAllowedUrl(value) {
    if (!value) return null;

    try {
      const url = new URL(value);
      return isAllowedReservationUrl(url) ? url : null;
    } catch (error) {
      return null;
    }
  }

  function resolveReturnUrl() {
    const params = new URLSearchParams(window.location.search);
    const returnUrl = parseAllowedUrl(params.get('return'));

    if (returnUrl) {
      return returnUrl;
    }

    return parseAllowedUrl(document.referrer);
  }

  function initBackToReservationLink() {
    const link = document.getElementById('backToReservationLink');
    if (!link) return;

    const returnUrl = resolveReturnUrl();

    if (returnUrl) {
      link.href = `${returnUrl.origin}/`;
    }
  }

  if (document.readyState === 'loading') {
    document.addEventListener('DOMContentLoaded', initBackToReservationLink);
  } else {
    initBackToReservationLink();
  }
})();
