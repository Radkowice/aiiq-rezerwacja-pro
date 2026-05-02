const textarea = document.getElementById('booking-email-content');
const toolbar = document.querySelector('.email-toolbar');
const emojiPicker = document.querySelector('.emoji-picker');
const previewContent = document.getElementById('email-preview-content');
const previewSubject = document.getElementById('email-preview-subject');
const subjectInput = document.getElementById('booking-email-subject');
const serviceNameInput = document.getElementById('booking-email-service-name');

const saveEmailSettingsBtn = document.getElementById('save-email-settings-btn');

const smtpFromNameInput = document.getElementById('smtp-from-name');
const smtpFromEmailInput = document.getElementById('smtp-from-email');
const smtpHostInput = document.getElementById('smtp-host');
const smtpPortInput = document.getElementById('smtp-port');
const smtpUsernameInput = document.getElementById('smtp-username');
const smtpPasswordInput = document.getElementById('smtp-password');

const toggleBtn = document.getElementById('toggle-smtp-password');

let currentTarget = 'content';


// =====================
// TOGGLE HASŁA
// =====================
document.addEventListener('click', (e) => {
  const btn = e.target.closest('#toggle-smtp-password');
  if (!btn) return;

  e.preventDefault();
  e.stopPropagation();

  const input = document.getElementById('smtp-password');
  if (!input) return;

  const isPassword = input.type === 'password';

  input.type = isPassword ? 'text' : 'password';
  btn.textContent = isPassword ? '🙈' : '👁';
  btn.setAttribute('aria-pressed', isPassword ? 'true' : 'false');
});


// =====================
// PODGLĄD EMAILA
// =====================
function updateEmailPreview() {
  if (previewContent && textarea) {
    const content = (textarea.value || '').replace(/\n/g, '<br>');
    previewContent.innerHTML = content || 'Treść wiadomości pojawi się tutaj';
  }

  if (previewSubject && subjectInput) {
    previewSubject.textContent =
      subjectInput.value || 'Temat wiadomości pojawi się tutaj';
  }
}


// =====================
// WALIDACJA
// =====================
function validateEmailSettings() {
  const errors = [];

  if (!smtpFromNameInput?.value.trim()) errors.push('Nazwa nadawcy');
  if (!smtpFromEmailInput?.value.trim()) errors.push('Email nadawcy');
  if (!smtpHostInput?.value.trim()) errors.push('Host SMTP');

  if (!smtpPortInput?.value || Number.isNaN(parseInt(smtpPortInput.value, 10))) {
    errors.push('Port SMTP');
  }

  if (!smtpUsernameInput?.value.trim()) errors.push('Login SMTP');

  // Hasło SMTP nie jest wymagane przy zwykłej edycji
  if (!subjectInput?.value.trim()) errors.push('Temat wiadomości');
  if (!textarea?.value.trim()) errors.push('Treść wiadomości');
  if (!serviceNameInput?.value.trim()) errors.push('Nazwa usługi (nagłówek)');

  return errors;
}


// =====================
// EMOJI TOGGLE
// =====================
document.querySelectorAll('[data-action="emoji-toggle"]').forEach((btn) => {
  btn.addEventListener('click', () => {
    currentTarget = btn.dataset.target || 'content';

    if (emojiPicker) {
      emojiPicker.style.display =
        emojiPicker.style.display === 'block' ? 'none' : 'block';
    }
  });
});


// =====================
// TOOLBAR TREŚCI
// =====================
if (textarea && toolbar) {
  toolbar.addEventListener('click', async (e) => {
    const btn = e.target.closest('button');
    if (!btn) return;

    const action = btn.dataset.action;
    if (!action || action === 'emoji-toggle') return;

    const start = textarea.selectionStart;
    const end = textarea.selectionEnd;
    const text = textarea.value;
    const selected = text.substring(start, end);

    let result = '';

    switch (action) {
      case 'bold':
        result = `<b>${selected || 'pogrubiony tekst'}</b>`;
        break;

      case 'italic':
        result = `<i>${selected || 'kursywa'}</i>`;
        break;

      case 'center':
        result = `<div style="text-align:center">${selected || 'wyśrodkowany tekst'}</div>`;
        break;

      case 'br':
        result = '<br>';
        break;
        
        case 'ph-name':
  result = '{name}';
  break;

      case 'link': {
        if (typeof window.openAdminInputModal !== 'function') {
          const urlFallback = prompt('Dodaj link', 'https://twoja-strona.pl');
          if (!urlFallback) return;
          result = `<a href="${urlFallback}" target="_blank">${selected || urlFallback}</a>`;
          break;
        }

        const url = await window.openAdminInputModal({
          title: 'Dodaj link',
          placeholder: 'https://twoja-strona.pl',
          confirmText: 'Wstaw link'
        });

        if (!url) return;

        result = `<a href="${url}" target="_blank">${selected || url}</a>`;
        break;
      }

      case 'name':
        result = '{name}';
        break;

      default:
        return;
    }

    textarea.value =
      text.substring(0, start) +
      result +
      text.substring(end);

    textarea.focus();
    textarea.selectionStart = textarea.selectionEnd = start + result.length;

    updateEmailPreview();
  });
}


// =====================
// EMOJI PICKER
// =====================
if (emojiPicker) {
  emojiPicker.addEventListener('click', (e) => {
    const emojiEl = e.target.closest('span');
    if (!emojiEl) return;

    const emoji = emojiEl.textContent.trim();
    if (!emoji) return;

    if (currentTarget === 'subject' && subjectInput) {
      const start = subjectInput.selectionStart ?? subjectInput.value.length;
      const end = subjectInput.selectionEnd ?? subjectInput.value.length;
      const text = subjectInput.value;

      subjectInput.value =
        text.substring(0, start) +
        emoji +
        text.substring(end);

      subjectInput.focus();
      subjectInput.selectionStart = subjectInput.selectionEnd = start + emoji.length;
    } else if (textarea) {
      const start = textarea.selectionStart;
      const end = textarea.selectionEnd;
      const text = textarea.value;

      textarea.value =
        text.substring(0, start) +
        emoji +
        text.substring(end);

      textarea.focus();
      textarea.selectionStart = textarea.selectionEnd = start + emoji.length;
    }

    updateEmailPreview();
    emojiPicker.style.display = 'none';
  });
}


// =====================
// LIVE PREVIEW
// =====================
if (textarea) {
  textarea.addEventListener('input', updateEmailPreview);
}

if (subjectInput) {
  subjectInput.addEventListener('input', updateEmailPreview);
}

// =====================
// ZAPIS DO SUPABASE
// =====================

if (saveEmailSettingsBtn) {
  saveEmailSettingsBtn.addEventListener('click', async () => {
    const startTime = Date.now();
    const defaultText = 'Zapisz ustawienia email';
    const errors = validateEmailSettings();

    if (errors.length > 0) {
      saveEmailSettingsBtn.disabled = true;
      saveEmailSettingsBtn.textContent = 'BŁĄD - UZUPEŁNIJ POLA';

      const messageHtml = `
        <div style="text-align:left;">
          Uzupełnij wymagane pola:<br><br>
          ${errors.map((item) => `• ${item}`).join('<br>')}
        </div>
      `;

      if (typeof window.openAdminConfirm === 'function') {
        await window.openAdminConfirm({
          title: 'Błąd - uzupełnij brakujące pola',
          html: messageHtml,
          confirmText: 'Rozumiem',
          cancelText: 'Zamknij',
          variant: 'primary',
          icon: '⚠️'
        });
      } else {
        alert('Uzupełnij wymagane pola:\n\n- ' + errors.join('\n- '));
      }

      finishButtonState(saveEmailSettingsBtn, defaultText, startTime);
      return;
    }

    try {
      saveEmailSettingsBtn.disabled = true;
      saveEmailSettingsBtn.textContent = 'Zapisywanie...';

      const payload = {
        smtp_name: smtpFromNameInput?.value.trim() || '',
        smtp_email: smtpFromEmailInput?.value.trim() || '',
        smtp_host: smtpHostInput?.value.trim() || '',
        smtp_port: parseInt(smtpPortInput?.value || '587', 10),
        smtp_user: smtpUsernameInput?.value.trim() || '',
        mail_subject: subjectInput?.value.trim() || '',
        mail_body: textarea?.value || '',
        service_name: document.querySelector('[data-section="email"] #booking-email-service-name')?.value.trim() || '',
        admin_mail_subject: '',
        admin_mail_body: ''
      };
      
      console.log('EMAIL SAVE PAYLOAD:', payload);

      // Nie nadpisuj pustym stringiem
      if (smtpPasswordInput?.value.trim()) {
        payload.smtp_pass = smtpPasswordInput.value.trim();
      }

      const res = await fetch('/api/email/save-email-settings.php', {
        method: 'POST',
        headers: {
          'Content-Type': 'application/json'
        },
        body: JSON.stringify(payload)
      });

      const data = await res.json();

      if (!res.ok || !data.success) {
        throw new Error(data.error || 'Błąd zapisu ustawień');
      }

      saveEmailSettingsBtn.textContent = 'Zapisywanie ustawień email...';

      if (smtpPasswordInput) {
        smtpPasswordInput.value = '';
        smtpPasswordInput.type = 'password';
      }

      if (toggleBtn) {
        toggleBtn.textContent = '👁';
        toggleBtn.setAttribute('aria-pressed', 'false');
      }

      finishButtonState(saveEmailSettingsBtn, defaultText, startTime);
    } catch (err) {
      console.error(err);
      saveEmailSettingsBtn.textContent = 'BŁĄD ZAPISU';
      finishButtonState(saveEmailSettingsBtn, defaultText, startTime);
    }
  });
}

// =====================
// ODCZYT Z SUPABASE
// =====================
async function loadEmailSettings() {
  try {
    const res = await fetch('/api/email/get-email-settings.php');
    const data = await res.json();

    if (!res.ok || !data.success) {
      console.error('Błąd ładowania ustawień', data);
      return;
    }

    const smtp = data.data?.smtp || {};
    const clientTpl = data.data?.client_template || {};

    if (smtpFromNameInput) smtpFromNameInput.value = smtp.from_name || '';
    if (smtpFromEmailInput) smtpFromEmailInput.value = smtp.from_email || '';
    if (smtpHostInput) smtpHostInput.value = smtp.smtp_host || '';
    if (smtpPortInput) smtpPortInput.value = smtp.smtp_port || '';
    if (smtpUsernameInput) smtpUsernameInput.value = smtp.smtp_username || '';

    // hasła nie wczytujemy
    if (smtpPasswordInput) {
      smtpPasswordInput.value = '';
      smtpPasswordInput.type = 'password';
    }

    if (toggleBtn) {
      toggleBtn.textContent = '👁';
      toggleBtn.setAttribute('aria-pressed', 'false');
    }

    if (subjectInput) subjectInput.value = clientTpl.subject || '';
    if (textarea) textarea.value = clientTpl.body_html || '';
    if (serviceNameInput) serviceNameInput.value = clientTpl.service_name || '';

    updateEmailPreview();

  } catch (err) {
    console.error('Błąd loadEmailSettings', err);
  }
}

// =====================
// PLACEHOLDERY TEMATU {date} {time}
// =====================

document.querySelectorAll('[data-subject-placeholder]').forEach((btn) => {
  btn.addEventListener('click', () => {
    if (!subjectInput) return;

    const placeholder = btn.dataset.subjectPlaceholder;

    const start = subjectInput.selectionStart ?? subjectInput.value.length;
    const end = subjectInput.selectionEnd ?? subjectInput.value.length;
    const text = subjectInput.value;

    subjectInput.value =
      text.substring(0, start) +
      placeholder +
      text.substring(end);

    subjectInput.focus();
    subjectInput.selectionStart = subjectInput.selectionEnd =
      start + placeholder.length;

    updateEmailPreview();
  });
});

document.addEventListener('click', async (e) => {
  const btn = e.target.closest('#test-email-connection');
  if (!btn) return;

  e.preventDefault();
  e.stopPropagation();

  btn.innerText = 'Sprawdzanie...';
  btn.disabled = true;

  const data = {
    smtp_host: document.getElementById('smtp-host')?.value.trim() || '',
    smtp_port: document.getElementById('smtp-port')?.value.trim() || '',
    smtp_username: document.getElementById('smtp-username')?.value.trim() || '',
    smtp_password: document.getElementById('smtp-password')?.value || '',
    smtp_email: document.getElementById('smtp-from-email')?.value.trim() || '',
    smtp_name: document.getElementById('smtp-from-name')?.value.trim() || ''
  };

  try {
    const res = await fetch('/api/email/test-email-connection.php', {
      method: 'POST',
      headers: {
        'Content-Type': 'application/json'
      },
      credentials: 'include',
      body: JSON.stringify(data)
    });

    const result = await res.json();

    if (typeof window.openAdminConfirm === 'function') {
      await window.openAdminConfirm({
        title: result.success ? 'Test SMTP' : 'Błąd SMTP',
        message: result.success
          ? 'Połączenie SMTP działa poprawnie.'
          : (result.error || 'Nie udało się połączyć z serwerem SMTP.'),
        confirmText: 'OK',
        cancelText: 'Zamknij',
        icon: result.success ? '✅' : '❌',
        variant: result.success ? 'primary' : 'danger'
      });
    } else {
      alert(result.success
        ? 'Połączenie SMTP działa poprawnie.'
        : (result.error || 'Nie udało się połączyć z serwerem SMTP.'));
    }

  } catch (e) {
    if (typeof window.openAdminConfirm === 'function') {
      await window.openAdminConfirm({
        title: 'Błąd połączenia',
        message: 'Nie udało się połączyć z serwerem.',
        confirmText: 'OK',
        cancelText: 'Zamknij',
        icon: '❌',
        variant: 'danger'
      });
    } else {
      alert('Nie udało się połączyć z serwerem.');
    }
  } finally {
    btn.innerText = 'Sprawdź połączenie SMTP';
    btn.disabled = false;
  }
});

document.addEventListener('click', (e) => {
  if (e.target.closest('#toggle-smtp-password')) {
    console.log('SMTP EYE CLICK');
  }

  if (e.target.closest('#test-email-connection')) {
    console.log('SMTP TEST CLICK');
  }
});

loadEmailSettings();
updateEmailPreview();
