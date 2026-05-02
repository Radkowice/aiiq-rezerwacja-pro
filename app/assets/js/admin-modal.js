(function () {
  function ensureAdminModal() {
    let overlay = document.getElementById('adminConfirmOverlay');
    if (overlay) return overlay;

    overlay = document.createElement('div');
    overlay.id = 'adminConfirmOverlay';
    overlay.className = 'admin-confirm-overlay';
    overlay.innerHTML = `
      <div class="admin-confirm-modal" role="dialog" aria-modal="true" aria-labelledby="adminConfirmTitle">
        <div class="admin-confirm-icon" id="adminConfirmIcon">⚠️</div>
        <div class="admin-confirm-title" id="adminConfirmTitle">Potwierdzenie</div>
        <div class="admin-confirm-message" id="adminConfirmMessage"></div>
        <div class="admin-confirm-actions">
          <button type="button" class="admin-confirm-btn cancel" id="adminConfirmCancel">Anuluj</button>
          <button type="button" class="admin-confirm-btn ok primary" id="adminConfirmOk">OK</button>
        </div>
      </div>
    `;
    document.body.appendChild(overlay);
    return overlay;
  }

  function openAdminConfirm({
    title = 'Potwierdzenie',
    message = '',
    html = '',
    confirmText = 'OK',
    cancelText = 'Anuluj',
    variant = 'primary',
    icon = '⚠️'
  } = {}) {
    return new Promise((resolve) => {
      const overlay = ensureAdminModal();
      const iconEl = document.getElementById('adminConfirmIcon');
      const titleEl = document.getElementById('adminConfirmTitle');
      const messageEl = document.getElementById('adminConfirmMessage');
      const cancelBtn = document.getElementById('adminConfirmCancel');
      const okBtn = document.getElementById('adminConfirmOk');

      if (!overlay || !titleEl || !messageEl || !cancelBtn || !okBtn) {
        const fallbackText =
          message ||
          String(html || '')
            .replace(/<br\s*\/?>/gi, '\n')
            .replace(/<[^>]*>/g, '')
            .trim() ||
          title ||
          'Czy na pewno?';

        resolve(window.confirm(fallbackText));
        return;
      }

      if (iconEl) iconEl.textContent = icon || '⚠️';
      titleEl.textContent = title || 'Potwierdzenie';

      if (html) {
        messageEl.innerHTML = html;
      } else {
        messageEl.innerHTML = String(message || '').replace(/\n/g, '<br>');
      }

      cancelBtn.textContent = cancelText || 'Anuluj';
      okBtn.textContent = confirmText || 'OK';
      okBtn.className = `admin-confirm-btn ok ${variant || 'primary'}`;

      overlay.classList.add('show');
      document.body.classList.add('modal-open');

      const close = (result) => {
        overlay.classList.remove('show');
        document.body.classList.remove('modal-open');
        cancelBtn.onclick = null;
        okBtn.onclick = null;
        document.removeEventListener('keydown', onKeyDown);
        resolve(result);
      };

      const onKeyDown = (event) => {
        if (event.key === 'Escape') close(false);
      };

      cancelBtn.onclick = () => close(false);
      okBtn.onclick = () => close(true);
      overlay.onclick = (event) => {
        if (event.target === overlay) close(false);
      };

      document.addEventListener('keydown', onKeyDown);
    });
  }

  window.openAdminConfirm = openAdminConfirm;
})();

function openAdminInputModal({
  title = 'Wprowadź link',
  placeholder = 'https://...',
  confirmText = 'Dodaj',
  cancelText = 'Anuluj'
} = {}) {
  return new Promise((resolve) => {
    const overlay = document.createElement('div');
    overlay.className = 'admin-confirm-overlay show';

    overlay.innerHTML = `
      <div class="admin-confirm-modal">
        <div class="admin-confirm-title">${title}</div>
        <input type="text" id="adminModalInput" class="admin-input-field" placeholder="${placeholder}">
        <div class="admin-confirm-actions">
          <button class="admin-confirm-btn cancel">${cancelText}</button>
          <button class="admin-confirm-btn ok primary">${confirmText}</button>
        </div>
      </div>
    `;

    document.body.appendChild(overlay);
    document.body.classList.add('modal-open');

    const input = overlay.querySelector('#adminModalInput');
    const cancelBtn = overlay.querySelector('.cancel');
    const okBtn = overlay.querySelector('.ok');

    input.focus();

    cancelBtn.onclick = () => {
      overlay.remove();
      document.body.classList.remove('modal-open');
      resolve(null);
    };

    okBtn.onclick = () => {
      const value = input.value.trim();
      overlay.remove();
      document.body.classList.remove('modal-open');
      resolve(value);
    };
  });
}

window.openAdminInputModal = openAdminInputModal;