async function checkAuth() {
  try {
    const res = await fetch('/api/auth/me.php', {
      credentials: 'include'
    });

    if (res.status !== 200) {
      window.location.href = '/logowanie.html';
    }
  } catch (e) {
    window.location.href = '/logowanie.html';
  }
}

async function apiFetch(url, options = {}) {
  try {
    const res = await fetch(url, {
      credentials: 'include',
      ...options
    });

    // ?? AUTO LOGOUT
    if (res.status === 401) {
      window.location.href = '/logowanie.html';
      return null;
    }

    const text = await res.text();

    let data;
    try {
      data = JSON.parse(text);
    } catch {
      throw new Error(text || 'Nieprawidłowa odpowiedź serwera');
    }

    return data;

  } catch (err) {
    console.error('API error:', err);
    throw err;
  }
}

function applyAdminTheme(theme) {
  document.body.classList.remove('theme-light', 'theme-gray', 'theme-dark');
  document.body.classList.add(`theme-${theme || 'light'}`);
}

function normalizeHexColor(value, fallback = '#ffffff') {
  const color = String(value || '').trim();

  if (/^#[0-9a-f]{6}$/i.test(color)) {
    return color;
  }

  if (/^#[0-9a-f]{3}$/i.test(color)) {
    return `#${color[1]}${color[1]}${color[2]}${color[2]}${color[3]}${color[3]}`;
  }

  return fallback;
}

function getColorLuminance(hexColor) {
  const color = normalizeHexColor(hexColor).replace('#', '');

  const r = parseInt(color.slice(0, 2), 16) / 255;
  const g = parseInt(color.slice(2, 4), 16) / 255;
  const b = parseInt(color.slice(4, 6), 16) / 255;

  const toLinear = value => {
    return value <= 0.03928
      ? value / 12.92
      : Math.pow((value + 0.055) / 1.055, 2.4);
  };

  return (0.2126 * toLinear(r)) + (0.7152 * toLinear(g)) + (0.0722 * toLinear(b));
}

function getReadableReservationsColors(baseColor) {
  const luminance = getColorLuminance(baseColor);
  const isDark = luminance < 0.42;

  return {
    text_color: isDark ? '#f8fafc' : '#0f172a',
    muted_color: isDark ? '#cbd5e1' : '#334155',
    button_text_color: isDark ? '#f8fafc' : '#0f172a',
    button_border_color: isDark ? '#cbd5e1' : '#111827'
  };
}

function applyReservationsStyle(style = {}) {
  const root = document.documentElement;

  const bgColor = normalizeHexColor(style.bg_color, '#ffffff');
  const cardColor = normalizeHexColor(style.card_color, '#ffffff');
  const tableColor = normalizeHexColor(style.table_color, '#ffffff');
  const headerColor = normalizeHexColor(style.header_color, '#2563eb');
  const borderColor = normalizeHexColor(style.border_color, '#dbeafe');

  const readableTextColors = getReadableReservationsColors(tableColor);
  const readableButtonColors = getReadableReservationsColors(cardColor);

  root.style.setProperty('--reservations-bg-color', bgColor);
  root.style.setProperty('--reservations-card-color', cardColor);
  root.style.setProperty('--reservations-table-color', tableColor);
  root.style.setProperty('--reservations-header-color', headerColor);
  root.style.setProperty('--reservations-border-color', borderColor);

  root.style.setProperty('--reservations-text-color', readableTextColors.text_color);
  root.style.setProperty('--reservations-muted-color', readableTextColors.muted_color);
  root.style.setProperty('--reservations-button-text-color', readableButtonColors.button_text_color);
  root.style.setProperty('--reservations-button-border-color', readableButtonColors.button_border_color);

  const radius = String(style.radius || '14').replace(/[^\d]/g, '');
  root.style.setProperty('--reservations-radius', `${radius || 14}px`);
}

function getReservationsStyleFromInputs() {
  const bgColor = normalizeHexColor(
    document.getElementById('reservations-bg-color')?.value,
    '#ffffff'
  );

  const cardColor = normalizeHexColor(
    document.getElementById('reservations-card-color')?.value,
    '#ffffff'
  );

  const tableColor = normalizeHexColor(
    document.getElementById('reservations-table-color')?.value,
    '#ffffff'
  );

  const headerColor = normalizeHexColor(
    document.getElementById('reservations-header-color')?.value,
    '#2563eb'
  );

  const borderColor = normalizeHexColor(
    document.getElementById('reservations-border-color')?.value,
    '#dbeafe'
  );

  const readableColors = getReadableReservationsColors(tableColor);
  const readableButtonColors = getReadableReservationsColors(cardColor);

  return {
    bg_color: bgColor,
    card_color: cardColor,
    table_color: tableColor,
    header_color: headerColor,
    border_color: borderColor,
    radius: document.getElementById('reservations-radius')?.value || '14',

    text_color: readableColors.text_color,
    muted_color: readableColors.muted_color,
    button_text_color: readableButtonColors.button_text_color,
    button_border_color: readableButtonColors.button_border_color
  };
}

function bindReservationsStylePreview() {
  [
    'reservations-bg-color',
    'reservations-card-color',
    'reservations-table-color',
    'reservations-header-color',
    'reservations-border-color',
    'reservations-radius'
  ].forEach(id => {
    const el = document.getElementById(id);
    if (!el) return;

    el.addEventListener('input', () => {
      applyReservationsStyle(getReservationsStyleFromInputs());
    });
  });
}

function getCalendarFrontStyleFromInputs() {
  return {
    bg_color: document.getElementById('front-bg-color')?.value || '#ffffff',
    card_color: document.getElementById('front-card-color')?.value || '#ffffff',
    cell_color: document.getElementById('front-cell-color')?.value || '#ffffff',
    active_color: document.getElementById('front-active-color')?.value || '#2563eb',
    blocked_color: document.getElementById('front-blocked-color')?.value || '#e5e7eb',
    radius: document.getElementById('front-radius')?.value || '16',
    width: document.getElementById('front-width')?.value || '520'
  };
}

function setCalendarFrontStyleInputs(style = {}) {
  const frontBgColor = document.getElementById('front-bg-color');
  const frontCardColor = document.getElementById('front-card-color');
  const frontCellColor = document.getElementById('front-cell-color');
  const frontActiveColor = document.getElementById('front-active-color');
  const frontBlockedColor = document.getElementById('front-blocked-color');
  const frontRadius = document.getElementById('front-radius');
  const frontWidth = document.getElementById('front-width');

  if (frontBgColor) frontBgColor.value = style.bg_color || '#ffffff';
  if (frontCardColor) frontCardColor.value = style.card_color || '#ffffff';
  if (frontCellColor) frontCellColor.value = style.cell_color || '#ffffff';
  if (frontActiveColor) frontActiveColor.value = style.active_color || '#2563eb';
  if (frontBlockedColor) frontBlockedColor.value = style.blocked_color || '#e5e7eb';
  if (frontRadius) frontRadius.value = style.radius || '16';
  if (frontWidth) frontWidth.value = style.width || '520';
}

function getCalendarFormFieldsFromInputs() {
  return {
    name_label: document.getElementById('label-name')?.value || 'Imię i nazwisko',
    email_label: document.getElementById('label-email')?.value || 'E-mail',
    phone_label: document.getElementById('label-phone')?.value || 'Telefon',
    notes_label: document.getElementById('label-notes')?.value || 'Wiadomość',
    show_email: true,
    show_phone: !!document.getElementById('toggle-phone-field')?.checked,
    show_notes: !!document.getElementById('toggle-notes-field')?.checked
  };
}

function setCalendarFormFieldsInputs(fields = {}) {
  const labelName = document.getElementById('label-name');
  const labelEmail = document.getElementById('label-email');
  const labelPhone = document.getElementById('label-phone');
  const labelNotes = document.getElementById('label-notes');

  const togglePhone = document.getElementById('toggle-phone-field');
  const toggleNotes = document.getElementById('toggle-notes-field');

  if (labelName) labelName.value = fields.name_label || 'Imię i nazwisko';
  if (labelEmail) labelEmail.value = fields.email_label || 'E-mail';
  if (labelPhone) labelPhone.value = fields.phone_label || 'Telefon';
  if (labelNotes) labelNotes.value = fields.notes_label || 'Wiadomość';

  if (togglePhone) togglePhone.checked = fields.show_phone !== false;
  if (toggleNotes) toggleNotes.checked = fields.show_notes !== false;
}

async function loadAccountData() {
  try {
    const data = await apiFetch('/api/auth/me.php');
    
    if (!data || !data.success) return;

    const user = data.user || {};
    const branding = data.branding || {};

    // USER
    document.getElementById('account-email').value = user.email || '';
    document.getElementById('account-role').value = user.role || '';
    document.getElementById('account-company-id').value = branding.company_id || user.tenant_id || '';

    // BRANDING
    document.getElementById('account-company-name').value = branding.client_name || '';
    document.getElementById('account-client-number').value = branding.client_number || '';
    document.getElementById('account-theme').value = branding.admin_theme || 'light';
   const serviceTitleFrontInput = document.getElementById('service-title-front');

if (serviceTitleFrontInput) {
  serviceTitleFrontInput.value = branding.service_title_front || '';
}
    
    const calendarFrontStyle = branding.calendar_front_style || {};
const calendarFormFields = branding.calendar_form_fields || {};

setCalendarFrontStyleInputs(calendarFrontStyle);
setCalendarFormFieldsInputs(calendarFormFields);
    
    const reservationsStyle = branding.reservations_style || {};

const reservationsBgColor = document.getElementById('reservations-bg-color');
const reservationsCardColor = document.getElementById('reservations-card-color');
const reservationsTableColor = document.getElementById('reservations-table-color');
const reservationsHeaderColor = document.getElementById('reservations-header-color');
const reservationsBorderColor = document.getElementById('reservations-border-color');
const reservationsRadius = document.getElementById('reservations-radius');

if (reservationsBgColor) {
  reservationsBgColor.value = reservationsStyle.bg_color || '#ffffff';
}

if (reservationsCardColor) {
  reservationsCardColor.value = reservationsStyle.card_color || '#ffffff';
}

if (reservationsTableColor) {
  reservationsTableColor.value = reservationsStyle.table_color || '#ffffff';
}

if (reservationsHeaderColor) {
  reservationsHeaderColor.value = reservationsStyle.header_color || '#2563eb';
}

if (reservationsBorderColor) {
  reservationsBorderColor.value = reservationsStyle.border_color || '#d1d5db';
}

if (reservationsRadius) {
  reservationsRadius.value = reservationsStyle.radius || '12';
}

applyReservationsStyle(reservationsStyle);
    
    const logoPreview = document.getElementById('account-logo-preview');
const logoEmpty = document.getElementById('account-logo-empty');

if (logoPreview && logoEmpty) {
  const logoUrl = (branding.logo_url_front || '').trim();
  const deleteLogoBtn = document.getElementById('delete-logo-front-btn');

  if (logoUrl) {
    logoPreview.src = logoUrl;
    logoPreview.style.display = 'block';
    logoEmpty.style.display = 'none';

    if (deleteLogoBtn) {
      deleteLogoBtn.style.display = 'inline-flex';
    }
  } else {
    logoPreview.removeAttribute('src');
    logoPreview.style.display = 'none';
    logoEmpty.style.display = 'block';

    if (deleteLogoBtn) {
      deleteLogoBtn.style.display = 'none';
    }
  }
}

const faviconPreview = document.getElementById('account-favicon-preview');
const faviconEmpty = document.getElementById('account-favicon-empty');

if (faviconPreview && faviconEmpty) {
  const faviconUrl = (branding.favicon_url_front || '').trim();
  const deleteFaviconBtn = document.getElementById('delete-favicon-front-btn');

  if (faviconUrl) {
    faviconPreview.src = faviconUrl;
    faviconPreview.style.display = 'block';
    faviconEmpty.style.display = 'none';

    if (deleteFaviconBtn) {
      deleteFaviconBtn.style.display = 'inline-flex';
    }
  } else {
    faviconPreview.removeAttribute('src');
    faviconPreview.style.display = 'none';
    faviconEmpty.style.display = 'block';

    if (deleteFaviconBtn) {
      deleteFaviconBtn.style.display = 'none';
    }
  }
}
    
    applyAdminTheme(branding.admin_theme || 'light');
   
  } catch (err) {
    console.error(' error:', err);
  }
}

// auto start po załadowaniu
document.addEventListener('DOMContentLoaded', () => {
  window.adminAccountDataReady = loadAccountData();
  bindReservationsStylePreview();
});

const themeSelect = document.getElementById('account-theme');

if (themeSelect) {
  themeSelect.addEventListener('change', () => {
    applyAdminTheme(themeSelect.value);
  });
}

let isChangingEmail = false;

document.getElementById('change-email-btn')?.addEventListener('click', async (e) => {
  e.preventDefault();

  if (isChangingEmail) return;
  isChangingEmail = true;

  const btn = document.getElementById('change-email-btn');
  if (btn) btn.disabled = true;

  try {
    const email = document.getElementById('new-email').value.trim();

    if (!email) {
      await window.openAdminConfirm({
        title: 'Brak danych',
        message: 'Podaj nowy email',
        confirmText: 'OK',
        cancelText: 'Zamknij',
        icon: '⚠️',
        variant: 'danger'
      });
      return;
    }

    const data = await apiFetch('/api/user/change-email.php', {
      method: 'POST',
      headers: { 'Content-Type': 'application/json' },
      body: JSON.stringify({ email })
    });

    if (!data) return;

    if (data.success === true) {
         await window.openAdminConfirm({
        title: 'Sukces',
        message: data.message || 'Email został zmieniony, powiadomienie wysłaliśmy na email',
        confirmText: 'OK',
        cancelText: 'Zamknij',
        icon: '✅',
        variant: 'primary'
      });

      const accountEmail = document.getElementById('account-email');
      if (accountEmail) {
        accountEmail.value = data.email || email;
      }

      return;
    }

    if (data.error) {
      await window.openAdminConfirm({
        title: 'Błąd',
        message: data.error,
        confirmText: 'OK',
        cancelText: 'Zamknij',
        icon: '❌',
        variant: 'danger'
      });
      return;
    }

    await window.openAdminConfirm({
      title: 'Błąd',
      message: 'Nieprawidłowa odpowiedź serwera',
      confirmText: 'OK',
      cancelText: 'Zamknij',
      icon: '❌',
      variant: 'danger'
    });

  } catch (e) {
    console.error(e);

    await window.openAdminConfirm({
      title: 'Błąd połączenia',
      message: 'Nie udało się połączyć z serwerem',
      confirmText: 'OK',
      cancelText: 'Zamknij',
      icon: '❌',
      variant: 'danger'
    });
  } finally {
    isChangingEmail = false;
    if (btn) btn.disabled = false;
  }
});

let isChangingPassword = false;

document.getElementById('change-password-btn')?.addEventListener('click', async (e) => {
  e.preventDefault();

  if (isChangingPassword) return;
  isChangingPassword = true;

  const btn = document.getElementById('change-password-btn');
  if (btn) btn.disabled = true;

  try {
    const current_password = document.getElementById('current-password')?.value || '';
    const new_password = document.getElementById('new-password')?.value || '';
    const confirm_password = document.getElementById('confirm-password')?.value || '';

    if (!current_password || !new_password || !confirm_password) {
      await window.openAdminConfirm({
        title: 'Brak danych',
        message: 'Wypełnij wszystkie pola',
        confirmText: 'OK',
        cancelText: 'Zamknij',
        icon: '⚠️',
        variant: 'danger'
      });
      return;
    }

    const isValid =
      new_password.length >= 8 &&
      /[a-z]/.test(new_password) &&
      /[A-Z]/.test(new_password) &&
      /[0-9]/.test(new_password) &&
      /[^A-Za-z0-9]/.test(new_password);

    if (!isValid) {
      await window.openAdminConfirm({
        title: 'Za słabe hasło',
        message: 'Hasło musi zawierać min. 8 znaków, dużą i małą literę, cyfrę oraz znak specjalny.',
        confirmText: 'OK',
        cancelText: 'Zamknij',
        icon: '⚠️',
        variant: 'danger'
      });
      return;
    }

    const data = await apiFetch('/api/user/change-password.php', {
      method: 'POST',
      headers: { 'Content-Type': 'application/json' },
      body: JSON.stringify({
        current_password,
        new_password,
        confirm_password
      })
    });

    if (!data) return;

    if (data.success === true) {
          await window.openAdminConfirm({
        title: 'Kod wysłany',
        message: 'Wysłaliśmy kod na Twój email. Wpisz go poniżej, aby zmienić hasło.',
        confirmText: 'OK',
        cancelText: 'Zamknij',
        icon: '📩',
        variant: 'primary'
      });

      const codeSection = document.getElementById('password-code-section');

      if (codeSection) {
        codeSection.classList.remove('hidden');
        codeSection.style.display = 'block';
        codeSection.scrollIntoView({ behavior: 'smooth' });
        document.getElementById('password-code')?.focus();
        startPasswordTimer(600);
      }

      return;
    }

    if (data.error) {
      await window.openAdminConfirm({
        title: 'Błąd',
        message: data.error,
        confirmText: 'OK',
        cancelText: 'Zamknij',
        icon: '❌',
        variant: 'danger'
      });
      return;
    }

  } catch (e) {
    console.error(e);

    await window.openAdminConfirm({
      title: 'Błąd połączenia',
      message: 'Wystąpił problem z odpowiedzią serwera. Jeśli kod został wysłany, sprawdź email i wpisz go poniżej.',
      confirmText: 'OK',
      cancelText: 'Zamknij',
      icon: '❌',
      variant: 'danger'
    });
  } finally {
    isChangingPassword = false;
    if (btn) btn.disabled = false;
  }
});

let isConfirmingPassword = false;

document.getElementById('confirm-password-code-btn')?.addEventListener('click', async (e) => {
  e.preventDefault();

  if (isConfirmingPassword) return;
  isConfirmingPassword = true;

  const btn = document.getElementById('confirm-password-code-btn');
  if (btn) btn.disabled = true;

  try {
    const code = document.getElementById('password-code')?.value.trim();

    if (!code) {
      await window.openAdminConfirm({
        title: 'Brak kodu',
        message: 'Wpisz kod z emaila',
        confirmText: 'OK',
        cancelText: 'Zamknij',
        icon: '⚠️',
        variant: 'danger'
      });
      return;
    }

    const data = await apiFetch('/api/user/confirm-password-change.php', {
      method: 'POST',
      headers: { 'Content-Type': 'application/json' },
      body: JSON.stringify({ code })
    });

    if (!data) return;

    if (data.success === true) {
      await window.openAdminConfirm({
        title: 'Sukces',
        message: 'Hasło zostało zmienione',
        confirmText: 'OK',
        cancelText: 'Zamknij',
        icon: '✅',
        variant: 'primary'
      });

      // reset UI
      document.getElementById('password-code-section')?.classList.add('hidden');
      document.getElementById('password-code').value = '';

      return;
    }

    if (data.error) {
      await window.openAdminConfirm({
        title: 'Błąd',
        message: data.error,
        confirmText: 'OK',
        cancelText: 'Zamknij',
        icon: '❌',
        variant: 'danger'
      });
      return;
    }

  } catch (e) {
    console.error(e);

    await window.openAdminConfirm({
      title: 'Błąd połączenia',
      message: 'Nie udało się połączyć z serwerem',
      confirmText: 'OK',
      cancelText: 'Zamknij',
      icon: '❌',
      variant: 'danger'
    });
  } finally {
    isConfirmingPassword = false;
    if (btn) btn.disabled = false;
  }
});

// === PODGLĄD HASŁA (OCZKO) ===
document.querySelectorAll('.toggle-password').forEach(btn => {
    btn.addEventListener('click', () => {
        const input = document.getElementById(btn.dataset.target);
        if (!input) return;

        const isHidden = input.type === 'password';

        input.type = isHidden ? 'text' : 'password';
        btn.textContent = isHidden ? '🙈' : '👁';
        btn.setAttribute('aria-label', isHidden ? 'Ukryj hasło' : 'Pokaż hasło');
        btn.setAttribute('aria-pressed', isHidden ? 'true' : 'false');
    });
});


// === WALIDACJA I SIŁA HASŁA ===
const passwordInput = document.getElementById('new-password');
const strengthBox = document.getElementById('password-strength');

const rules = {
  length: value => value.length >= 8,
  lower: value => /[a-z]/.test(value),
  upper: value => /[A-Z]/.test(value),
  number: value => /[0-9]/.test(value),
  special: value => /[^A-Za-z0-9]/.test(value)
};

function evaluatePasswordStrength(value) {
 const password = String(value || '');
const lower = password.toLowerCase();

const hasLower = /[a-z]/.test(password);
const hasUpper = /[A-Z]/.test(password);
const hasNumber = /[0-9]/.test(password);
const hasSpecial = /[^A-Za-z0-9]/.test(password);

const meetsMinimum =
  password.length >= 8 &&
  hasLower &&
  hasUpper &&
  hasNumber &&
  hasSpecial;

const normalized = lower
  .normalize('NFD')
  .replace(/[\u0300-\u036f]/g, '');

const hasWeakPattern =
  /haslo|password|admin|qwerty|abc123|1234|12345|123456|qwer|asdf|zxcv/i.test(normalized);

const hasRepeat = /(.)\1{3,}/.test(password);

if (!meetsMinimum) {
  return { label: 'Słabe hasło', className: 'weak' };
}

if (password.length >= 15 && !hasWeakPattern && !hasRepeat) {
  return { label: 'Mocne hasło', className: 'strong' };
}

return { label: 'Średnie hasło', className: 'medium' };
 }

if (passwordInput) {
  passwordInput.addEventListener('input', () => {
    const value = passwordInput.value;

    Object.entries(rules).forEach(([key, test]) => {
      const el = document.querySelector(`[data-rule="${key}"]`);
      if (!el) return;

      if (test(value)) {
        el.classList.add('valid');
        el.classList.remove('invalid');
      } else {
        el.classList.add('invalid');
        el.classList.remove('valid');
      }
    });

    if (!strengthBox) return;

    strengthBox.classList.remove('weak', 'medium', 'strong');

    if (value.length === 0) {
      strengthBox.textContent = '';
      return;
    }

    const result = evaluatePasswordStrength(value);
    strengthBox.textContent = result.label;
    strengthBox.classList.add(result.className);
  });
}

// === LICZNIK KODU (10 MINUT) ===
let passwordTimerInterval = null;
let passwordTimeLeft = 0;

function startPasswordTimer(duration = 600) { // 600 = 10 min
  passwordTimeLeft = duration;

  const timerEl = document.getElementById('password-code-timer');

  if (!timerEl) return;

  if (passwordTimerInterval) {
    clearInterval(passwordTimerInterval);
  }

  passwordTimerInterval = setInterval(() => {
    const minutes = Math.floor(passwordTimeLeft / 60);
    const seconds = passwordTimeLeft % 60;

    timerEl.textContent = `Kod ważny jeszcze: ${minutes}:${seconds.toString().padStart(2, '0')}`;

    if (passwordTimeLeft <= 0) {
      clearInterval(passwordTimerInterval);
      timerEl.textContent = 'Kod wygasł. Kliknij „Wyślij ponownie”.';
    }

    passwordTimeLeft--;
  }, 1000);
}

let isDeletingAccount = false;

document.getElementById('delete-account-btn')?.addEventListener('click', async (e) => {
  e.preventDefault();

  if (isDeletingAccount) return;
  isDeletingAccount = true;

  const btn = document.getElementById('delete-account-btn');
  if (btn) btn.disabled = true;

  try {
    const password = document.getElementById('delete-password')?.value || '';

    if (!password.trim()) {
      await window.openAdminConfirm({
        title: 'Brak hasła',
        message: 'Wpisz hasło, aby usunąć konto.',
        confirmText: 'OK',
        cancelText: 'Zamknij',
        icon: '⚠️',
        variant: 'danger'
      });
      return;
    }

    const firstConfirm = await window.openAdminConfirm({
      title: 'Usuń konto',
      message: 'Czy na pewno chcesz usunąć konto? Ta operacja usuwa Twoje dane logowania.',
      confirmText: 'Tak, usuń',
      cancelText: 'Anuluj',
      icon: '⚠️',
      variant: 'danger'
    });

    if (!firstConfirm) return;

    const secondConfirm = await window.openAdminConfirm({
      title: 'Ostateczne potwierdzenie',
      message: 'Potwierdź ponownie: konto zostanie usunięte, a po operacji zostaniesz wylogowany.',
      confirmText: 'Tak, potwierdzam',
      cancelText: 'Anuluj',
      icon: '🗑️',
      variant: 'danger'
    });

    if (!secondConfirm) return;

    const data = await apiFetch('/api/user/delete-account.php', {
      method: 'POST',
      headers: { 'Content-Type': 'application/json' },
      body: JSON.stringify({ password })
    });

    if (!data) return;

    if (data.success === true) {
      await window.openAdminConfirm({
        title: 'Konto usunięte',
        message: data.message || 'Twoje konto zostało usunięte.',
        confirmText: 'OK',
        cancelText: 'Zamknij',
        icon: '✅',
        variant: 'primary'
      });

      window.location.href = '/logowanie.html';
      return;
    }

    if (data.error) {
      await window.openAdminConfirm({
        title: 'Błąd',
        message: data.error,
        confirmText: 'OK',
        cancelText: 'Zamknij',
        icon: '❌',
        variant: 'danger'
      });
      return;
    }

    await window.openAdminConfirm({
      title: 'Błąd',
      message: 'Nieprawidłowa odpowiedź serwera',
      confirmText: 'OK',
      cancelText: 'Zamknij',
      icon: '❌',
      variant: 'danger'
    });
  } catch (e) {
    console.error(e);

    await window.openAdminConfirm({
      title: 'Błąd połączenia',
      message: 'Nie udało się usunąć konta',
      confirmText: 'OK',
      cancelText: 'Zamknij',
      icon: '❌',
      variant: 'danger'
    });
  } finally {
    isDeletingAccount = false;
    if (btn) btn.disabled = false;
  }
});
