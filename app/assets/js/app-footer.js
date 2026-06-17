(function () {
  'use strict';

  const AI_IQ_URL = 'https://www.ai-iq.pl';
  const PLATFORM_ORIGIN = 'https://rezerwacja-ai-iq.pl';

  function isPlatformSubdomain(hostname) {
    return String(hostname || '').toLowerCase().endsWith('.rezerwacja-ai-iq.pl');
  }

  function buildAppInfoUrl() {
    const url = new URL('/o-aplikacji.html', PLATFORM_ORIGIN);

    if (isPlatformSubdomain(window.location.hostname)) {
      url.searchParams.set('return', `${window.location.origin}/`);
    }

    return url.toString();
  }

  function updateAppInfoLinks(root) {
    root.querySelectorAll('a[href]').forEach((link) => {
      let url;

      try {
        url = new URL(link.getAttribute('href'), window.location.href);
      } catch (error) {
        return;
      }

      if (url.pathname === '/o-aplikacji.html') {
        link.href = buildAppInfoUrl();
      }
    });
  }

  function buildFooterMain() {
    const main = document.createElement('span');
    main.className = 'app-footer-main';

    const year = String(new Date().getFullYear());
    main.append(document.createTextNode(`© ${year} System Rezerwacji Pro · Obsługiwane przez `));

    const link = document.createElement('a');
    link.href = AI_IQ_URL;
    link.target = '_blank';
    link.rel = 'noopener noreferrer';
    link.textContent = 'AI-IQ';

    main.append(link);

    return main;
  }

  function renderFooter(root) {
    if (!root) return;

    const extras = Array.from(root.querySelectorAll('[data-app-footer-extra]'));
    root.replaceChildren(buildFooterMain());

    extras.forEach((extra) => {
      updateAppInfoLinks(extra);
      root.appendChild(extra);
    });
  }

  function initFooters() {
    document.querySelectorAll('[data-app-footer]').forEach(renderFooter);
  }

  if (document.readyState === 'loading') {
    document.addEventListener('DOMContentLoaded', initFooters);
  } else {
    initFooters();
  }
})();
