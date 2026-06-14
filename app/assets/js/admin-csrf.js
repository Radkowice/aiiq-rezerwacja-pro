const csrfMeta = document.querySelector('meta[name="csrf-token"]');
window.CSRF_TOKEN = csrfMeta ? csrfMeta.getAttribute('content') || '' : '';
