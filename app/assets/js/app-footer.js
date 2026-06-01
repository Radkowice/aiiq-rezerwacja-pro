(function () {
  'use strict';

  const AI_IQ_URL = 'https://www.ai-iq.pl';

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
