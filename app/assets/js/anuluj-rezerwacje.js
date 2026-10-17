'use strict';
(() => {
  // Usuń token z widocznego adresu; pierwotne żądanie HTML mogło już
  // zawierać token w URL oraz w logach serwera/proxy.
  if (window.location.search || window.location.hash) {
    window.history.replaceState(null, '', window.location.pathname);
  }
})();
