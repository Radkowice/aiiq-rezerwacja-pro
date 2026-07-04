async function checkAuth() {
  try {
    const data = await getAdminAccountDataReady();

    if (!data || data.success !== true) {
      window.location.href = '/logowanie.html';
    }
  } catch (e) {
    window.location.href = '/logowanie.html';
  }
}

function createAdminRequestError(message, response = null, data = null) {
  const error = new Error(message || 'Nie udało się wykonać operacji.');
  error.status = response?.status || 0;
  error.data = data;
  return error;
}

async function readAdminResponsePayload(response) {
  const text = await response.text();

  if (!text) {
    return {
      text: '',
      data: null
    };
  }

  try {
    return {
      text,
      data: JSON.parse(text)
    };
  } catch {
    return {
      text,
      data: null
    };
  }
}

async function adminRequest(url, options = {}) {
  let response;

  try {
    response = await fetch(url, {
      credentials: 'include',
      ...options
    });
  } catch (error) {
    throw createAdminRequestError(
      error?.message || 'Nie udało się połączyć z serwerem.',
      null,
      null
    );
  }

  if (response.ok) {
    return response;
  }

  const payload = await readAdminResponsePayload(response);
  const message = payload.data?.error
    || payload.data?.message
    || payload.text
    || `Nie udało się wykonać operacji. Status: ${response.status}`;

  throw createAdminRequestError(message, response, payload.data);
}

window.adminRequest = adminRequest;

async function apiFetch(url, options = {}) {
  try {
    const res = await adminRequest(url, options);

    const text = await res.text();

    if (!text) {
      return null;
    }

    let data;
    try {
      data = JSON.parse(text);
    } catch {
      throw createAdminRequestError(text || 'Nieprawidłowa odpowiedź serwera', res, null);
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
    button_border_color: isDark ? '#cbd5e1' : '#64748b'
  };
}

const DEFAULT_RESERVATIONS_STYLE = {
  bg_color: '#e5ebf2',
  card_color: '#f8fafc',
  table_color: '#eef2f7',
  header_color: '#cbd5e1',
  border_color: '#94a3b8',
  radius: '16'
};

function applyReservationsStyle(style = {}) {
  const root = document.documentElement;

  const bgColor = normalizeHexColor(style.bg_color, DEFAULT_RESERVATIONS_STYLE.bg_color);
  const cardColor = normalizeHexColor(style.card_color, DEFAULT_RESERVATIONS_STYLE.card_color);
  const tableColor = normalizeHexColor(style.table_color, DEFAULT_RESERVATIONS_STYLE.table_color);
  const headerColor = normalizeHexColor(style.header_color, DEFAULT_RESERVATIONS_STYLE.header_color);
  const borderColor = normalizeHexColor(style.border_color, DEFAULT_RESERVATIONS_STYLE.border_color);

  const readableTextColors = getReadableReservationsColors(tableColor);
  const readableButtonColors = getReadableReservationsColors(cardColor);
  const readableHeaderColors = getReadableReservationsColors(headerColor);

  root.style.setProperty('--reservations-bg-color', bgColor);
  root.style.setProperty('--reservations-card-color', cardColor);
  root.style.setProperty('--reservations-table-color', tableColor);
  root.style.setProperty('--reservations-header-color', headerColor);
  root.style.setProperty('--reservations-border-color', borderColor);

  root.style.setProperty('--reservations-text-color', readableTextColors.text_color);
  root.style.setProperty('--reservations-muted-color', readableTextColors.muted_color);
  root.style.setProperty('--reservations-button-text-color', readableButtonColors.button_text_color);
  root.style.setProperty('--reservations-button-border-color', readableButtonColors.button_border_color);
  root.style.setProperty('--reservations-header-text-color', readableHeaderColors.text_color);

  const radius = String(style.radius || DEFAULT_RESERVATIONS_STYLE.radius).replace(/[^\d]/g, '');
  root.style.setProperty('--reservations-radius', `${radius || DEFAULT_RESERVATIONS_STYLE.radius}px`);
}

function getReservationsStyleFromInputs() {
  const bgColor = normalizeHexColor(
    document.getElementById('reservations-bg-color')?.value,
    DEFAULT_RESERVATIONS_STYLE.bg_color
  );

  const cardColor = normalizeHexColor(
    document.getElementById('reservations-card-color')?.value,
    DEFAULT_RESERVATIONS_STYLE.card_color
  );

  const tableColor = normalizeHexColor(
    document.getElementById('reservations-table-color')?.value,
    DEFAULT_RESERVATIONS_STYLE.table_color
  );

  const headerColor = normalizeHexColor(
    document.getElementById('reservations-header-color')?.value,
    DEFAULT_RESERVATIONS_STYLE.header_color
  );

  const borderColor = normalizeHexColor(
    document.getElementById('reservations-border-color')?.value,
    DEFAULT_RESERVATIONS_STYLE.border_color
  );

  const readableColors = getReadableReservationsColors(tableColor);
  const readableButtonColors = getReadableReservationsColors(cardColor);

  return {
    bg_color: bgColor,
    card_color: cardColor,
    table_color: tableColor,
    header_color: headerColor,
    border_color: borderColor,
    radius: document.getElementById('reservations-radius')?.value || DEFAULT_RESERVATIONS_STYLE.radius,

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

async function fetchAdminBootstrapData() {
  try {
    const data = await apiFetch('/api/admin/bootstrap.php');

    if (data && data.success === true) {
      window.AIIQ_ADMIN_BOOTSTRAP = data;
      return data;
    }
  } catch (error) {
    if (error?.status === 401 || error?.status === 403) {
      throw error;
    }

    console.warn('Admin bootstrap unavailable, falling back to /api/auth/me.php', error);
  }

  const fallbackData = await apiFetch('/api/auth/me.php');

  if (fallbackData && fallbackData.success === true) {
    window.AIIQ_ADMIN_BOOTSTRAP = {
      ...fallbackData,
      fallback: true
    };
  }

  return fallbackData;
}

async function loadAccountData() {
  try {
    const data = await fetchAdminBootstrapData();
    
    if (!data || !data.success) return data;

    if (data.plan_context) {
      window.AIIQ_PLAN_CONTEXT = data.plan_context;
    }

    const user = data.user || {};
    const branding = data.branding || {};

    // USER
    document.getElementById('account-email').value = user.email || '';
    document.getElementById('account-role').value = user.role || '';

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
  reservationsBgColor.value = reservationsStyle.bg_color || DEFAULT_RESERVATIONS_STYLE.bg_color;
}

if (reservationsCardColor) {
  reservationsCardColor.value = reservationsStyle.card_color || DEFAULT_RESERVATIONS_STYLE.card_color;
}

if (reservationsTableColor) {
  reservationsTableColor.value = reservationsStyle.table_color || DEFAULT_RESERVATIONS_STYLE.table_color;
}

if (reservationsHeaderColor) {
  reservationsHeaderColor.value = reservationsStyle.header_color || DEFAULT_RESERVATIONS_STYLE.header_color;
}

if (reservationsBorderColor) {
  reservationsBorderColor.value = reservationsStyle.border_color || DEFAULT_RESERVATIONS_STYLE.border_color;
}

if (reservationsRadius) {
  reservationsRadius.value = reservationsStyle.radius || DEFAULT_RESERVATIONS_STYLE.radius;
}

applyReservationsStyle(reservationsStyle);
    
    const logoPreview = document.getElementById('account-logo-preview');
const logoEmpty = document.getElementById('account-logo-empty');

if (logoPreview && logoEmpty) {
  const logoUrl = (branding.logo_url_front || '').trim();
  const deleteLogoBtn = document.getElementById('delete-logo-front-btn');

  if (logoUrl) {
    const previewVersion = encodeURIComponent(branding.updated_at || Date.now());
    logoPreview.style.display = 'none';
    logoEmpty.style.display = 'none';
    logoPreview.onload = () => {
      logoPreview.onload = null;
      logoPreview.onerror = null;
      logoPreview.style.display = 'block';
      logoEmpty.style.display = 'none';
      if (deleteLogoBtn) deleteLogoBtn.style.display = 'inline-flex';
    };
    logoPreview.onerror = () => {
      logoPreview.onload = null;
      logoPreview.onerror = null;
      logoPreview.removeAttribute('src');
      logoPreview.style.display = 'none';
      logoEmpty.textContent = 'Nie udało się załadować podglądu logo.';
      logoEmpty.style.display = 'block';
      if (deleteLogoBtn) deleteLogoBtn.style.display = 'inline-flex';
    };
    logoPreview.src = `/api/system/logo-front.php?v=${previewVersion}`;
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
    const previewVersion = encodeURIComponent(branding.updated_at || Date.now());
    faviconPreview.style.display = 'none';
    faviconEmpty.style.display = 'none';
    faviconPreview.onload = () => {
      faviconPreview.onload = null;
      faviconPreview.onerror = null;
      faviconPreview.style.display = 'block';
      faviconEmpty.style.display = 'none';
      if (deleteFaviconBtn) deleteFaviconBtn.style.display = 'inline-flex';
    };
    faviconPreview.onerror = () => {
      faviconPreview.onload = null;
      faviconPreview.onerror = null;
      faviconPreview.removeAttribute('src');
      faviconPreview.style.display = 'none';
      faviconEmpty.textContent = 'Nie udało się załadować podglądu favicony.';
      faviconEmpty.style.display = 'block';
      if (deleteFaviconBtn) deleteFaviconBtn.style.display = 'inline-flex';
    };
    faviconPreview.src = `/api/system/favicon-front.php?v=${previewVersion}`;
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

    return data;
   
  } catch (err) {
    console.error(' error:', err);
    return null;
  }
}

function getAdminAccountDataReady() {
  const ready = window.adminAccountDataReady;

  if (ready && typeof ready.then === 'function') {
    return ready;
  }

  window.adminAccountDataReady = loadAccountData();
  return window.adminAccountDataReady;
}

// auto start po załadowaniu
document.addEventListener('DOMContentLoaded', () => {
  if (!window.adminAccountDataReady) {
    window.adminAccountDataReady = loadAccountData();
  }
  bindReservationsStylePreview();
});

const themeSelect = document.getElementById('account-theme');

if (themeSelect) {
  themeSelect.addEventListener('change', () => {
    applyAdminTheme(themeSelect.value);
  });
}

let isChangingEmail = false;
const changeEmailFallbackMessage = 'Nie udało się zmienić emaila. Sprawdź dane i spróbuj ponownie.';

function getSafeChangeEmailMessage(errorOrData) {
  const status = Number(errorOrData?.status || 0);
  const data = errorOrData?.data || errorOrData || {};
  const message = String(data?.error || data?.message || errorOrData?.message || '').trim();

  const invalidEmailPattern = /poprawny adres e-?mail|niepoprawny e-?mail|nieprawidłowy e-?mail/i;

  if (status === 400 && (!message || invalidEmailPattern.test(message))) {
    return 'Niepoprawny email';
  }

  if (!message) {
    return changeEmailFallbackMessage;
  }

  const technicalPattern = /tenant_id|user_id|subscription_id|payment_id|booking_id|staff_id|password_hash|new_password_hash|supabase|service_role|apikey|authorization|bearer|curl|sql|stack|trace|exception|schema|uuid|brak konfiguracji|nie udało się pobrać użytkownika|nie znaleziono użytkownika|sesja nie pasuje/i;
  const uuidPattern = /[0-9a-f]{8}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{12}/i;

  if (technicalPattern.test(message) || uuidPattern.test(message)) {
    return changeEmailFallbackMessage;
  }

  return message;
}

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
        icon: '✖',
        variant: 'danger'
      });
      return;
    }

    await window.openAdminConfirm({
      title: 'Błąd',
      message: 'Nieprawidłowa odpowiedź serwera',
      confirmText: 'OK',
      cancelText: 'Zamknij',
      icon: '✖',
      variant: 'danger'
    });

  } catch (e) {
    console.error(e);

    const status = Number(e?.status || 0);
    const isControlledError = [400, 401, 403, 409, 422, 429].includes(status);

    await window.openAdminConfirm({
      title: isControlledError ? 'Błąd' : 'Błąd połączenia',
      message: isControlledError ? getSafeChangeEmailMessage(e) : 'Nie udało się połączyć z serwerem',
      confirmText: 'OK',
      cancelText: 'Zamknij',
      icon: '✖',
      variant: 'danger'
    });
  } finally {
    isChangingEmail = false;
    if (btn) btn.disabled = false;
  }
});

let isChangingPassword = false;
const passwordChangeRateLimitMessage = 'Kod został już wysłany. Spróbuj ponownie za kilka minut.';
const passwordChangeFallbackMessage = 'Nie udało się zmienić hasła. Sprawdź dane i spróbuj ponownie.';

function isPasswordChangeRateLimited(status, data) {
  const message = String(data?.error || data?.message || '');

  return Number(status) === 429 || message.includes('Zbyt wiele prób');
}

function isControlledPasswordChangeStatus(status) {
  return [400, 401, 403, 422, 429].includes(Number(status));
}

function getSafePasswordChangeMessage(data) {
  const message = String(data?.error || data?.message || '').trim();

  if (!message) {
    return passwordChangeFallbackMessage;
  }

  const technicalPattern = /tenant_id|user_id|code_id|subscription_id|payment_id|booking_id|staff_id|password_hash|new_password_hash|supabase|service_role|apikey|authorization|bearer|curl|sql|stack|trace|exception|schema|uuid|brak konfiguracji|nie udało się pobrać użytkownika|nie znaleziono użytkownika|sesja nie pasuje/i;
  const uuidPattern = /[0-9a-f]{8}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{12}/i;

  if (technicalPattern.test(message) || uuidPattern.test(message)) {
    return passwordChangeFallbackMessage;
  }

  return message;
}

async function showPasswordChangeRateLimitMessage() {
  await window.openAdminConfirm({
    title: 'Zbyt wiele prób',
    message: passwordChangeRateLimitMessage,
    confirmText: 'OK',
    cancelText: 'Zamknij',
    icon: '⚠️',
    variant: 'danger'
  });
}

async function fetchPasswordChangeRequest(payload) {
  const response = await fetch('/api/user/change-password.php', {
    method: 'POST',
    headers: { 'Content-Type': 'application/json' },
    credentials: 'include',
    body: JSON.stringify(payload)
  });

  const rawBody = await response.text();
  let data = null;

  if (rawBody) {
    try {
      data = JSON.parse(rawBody);
    } catch (e) {
      if (Number(response.status) === 429) {
        return { ok: false, rateLimited: true, status: response.status };
      }

      if (isControlledPasswordChangeStatus(response.status)) {
        return { ok: false, controlled: true, status: response.status };
      }

      throw new Error('invalid_json');
    }
  }

  if (response.ok && data?.success === true) {
    return { ok: true, data, status: response.status };
  }

  if (isPasswordChangeRateLimited(response.status, data)) {
    return { ok: false, rateLimited: true, status: response.status };
  }

  if (isControlledPasswordChangeStatus(response.status) && data?.success === false) {
    return { ok: false, controlled: true, data, status: response.status };
  }

  if (response.ok && data?.success === false) {
    return { ok: false, controlled: true, data, status: response.status };
  }

  if (response.ok) {
    return { ok: false, data, status: response.status };
  }

  throw new Error('change_password_failed');
}

async function requestPasswordChangeCode(successTitle, successMessage) {
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

  const result = await fetchPasswordChangeRequest({
    current_password,
    new_password,
    confirm_password
  });
  const data = result.data;

  if (result.rateLimited === true) {
    await showPasswordChangeRateLimitMessage();
    return;
  }

  if (result.controlled === true) {
    await window.openAdminConfirm({
      title: 'Błąd',
      message: getSafePasswordChangeMessage(data),
      confirmText: 'OK',
      cancelText: 'Zamknij',
      icon: '✖',
      variant: 'danger'
    });
    return;
  }

  if (!data) return;

  if (data.success === true) {
    await window.openAdminConfirm({
      title: successTitle,
      message: successMessage,
      confirmText: 'OK',
      cancelText: 'Zamknij',
      icon: '✉️',
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

  if (data.error || data.message) {
    await window.openAdminConfirm({
      title: 'Błąd',
      message: getSafePasswordChangeMessage(data),
      confirmText: 'OK',
      cancelText: 'Zamknij',
      icon: '✖',
      variant: 'danger'
    });
  }
}

document.getElementById('change-password-btn')?.addEventListener('click', async (e) => {
  e.preventDefault();

  if (isChangingPassword) return;
  isChangingPassword = true;

  const btn = document.getElementById('change-password-btn');
  if (btn) btn.disabled = true;

  try {
    await requestPasswordChangeCode(
      'Kod wysłany',
      'Wysłaliśmy kod na Twój email. Wpisz go poniżej, aby zmienić hasło.'
    );

  } catch (e) {
    await window.openAdminConfirm({
      title: 'Błąd połączenia',
      message: 'Wystąpił problem z odpowiedzią serwera. Jeśli kod został wysłany, sprawdź email i wpisz go poniżej.',
      confirmText: 'OK',
      cancelText: 'Zamknij',
      icon: '✖',
      variant: 'danger'
    });
  } finally {
    isChangingPassword = false;
    if (btn) btn.disabled = false;
  }
});

document.getElementById('resend-password-code-btn')?.addEventListener('click', async (e) => {
  e.preventDefault();

  if (isChangingPassword) return;
  isChangingPassword = true;

  const btn = document.getElementById('resend-password-code-btn');
  if (btn) btn.disabled = true;

  try {
    await requestPasswordChangeCode(
      'Nowy kod wysłany',
      'Nowy kod został wysłany. Wpisz go poniżej, aby zmienić hasło.'
    );

  } catch (e) {
    await window.openAdminConfirm({
      title: 'Błąd połączenia',
      message: 'Wystąpił problem z odpowiedzią serwera. Jeśli kod został wysłany, sprawdź email i wpisz go poniżej.',
      confirmText: 'OK',
      cancelText: 'Zamknij',
      icon: '✖',
      variant: 'danger'
    });
  } finally {
    isChangingPassword = false;
    if (btn) btn.disabled = false;
  }
});

let isConfirmingPassword = false;
const passwordCodeInvalidMessage = 'Kod jest nieprawidłowy lub nieważny.';

function isControlledPasswordCodeStatus(status) {
  return [400, 410, 422, 429].includes(Number(status));
}

async function showPasswordCodeInvalidMessage() {
  await window.openAdminConfirm({
    title: 'Błąd',
    message: passwordCodeInvalidMessage,
    confirmText: 'OK',
    cancelText: 'Zamknij',
    icon: '✖',
    variant: 'danger'
  });
}

async function fetchPasswordCodeConfirmation(code) {
  const response = await fetch('/api/user/confirm-password-change.php', {
    method: 'POST',
    headers: { 'Content-Type': 'application/json' },
    credentials: 'include',
    body: JSON.stringify({ code })
  });

  const rawBody = await response.text();
  let data = null;

  if (rawBody) {
    try {
      data = JSON.parse(rawBody);
    } catch (e) {
      if (isControlledPasswordCodeStatus(response.status)) {
        return { ok: false, controlled: true, status: response.status };
      }

      throw new Error('invalid_json');
    }
  }

  if (response.ok && data?.success === true) {
    return { ok: true, data, status: response.status };
  }

  if (
    isControlledPasswordCodeStatus(response.status)
    || ((response.ok || response.status < 500) && data?.success === false)
  ) {
    return { ok: false, controlled: true, status: response.status };
  }

  throw new Error('confirm_failed');
}

document.getElementById('confirm-password-code-btn')?.addEventListener('click', async (e) => {
  e.preventDefault();

  if (isConfirmingPassword) return;
  isConfirmingPassword = true;

  const btn = document.getElementById('confirm-password-code-btn');
  if (btn) btn.disabled = true;

  try {
    const code = document.getElementById('password-code')?.value.trim();

    if (!/^\d{6}$/.test(code || '')) {
      await showPasswordCodeInvalidMessage();
      return;
    }

    const result = await fetchPasswordCodeConfirmation(code);

    if (result.ok === true) {
      await window.openAdminConfirm({
        title: 'Sukces',
        message: 'Hasło zostało zmienione',
        confirmText: 'OK',
        cancelText: 'Zamknij',
        icon: '✅',
        variant: 'primary'
      });

      document.getElementById('password-code-section')?.classList.add('hidden');
      document.getElementById('password-code').value = '';

      return;
    }

    if (result.controlled === true) {
      await showPasswordCodeInvalidMessage();
      return;
    }

  } catch (e) {
    await window.openAdminConfirm({
      title: 'Błąd połączenia',
      message: 'Nie udało się połączyć z serwerem',
      confirmText: 'OK',
      cancelText: 'Zamknij',
      icon: '✖',
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

function startPasswordTimer(duration = 600) {
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
let deleteAccountDataLossConfirmed = false;

document.getElementById('delete-account-btn')?.addEventListener('click', async (e) => {
  e.preventDefault();

  if (isDeletingAccount) return;
  isDeletingAccount = true;

  const btn = document.getElementById('delete-account-btn');
  if (btn) btn.disabled = true;

  try {
    const firstConfirm = await window.openAdminConfirm({
      title: 'Usuń konto',
      html: `
        <p>Usunięte zostaną: konto administratora, konto firmy, rezerwacje, ustawienia usług, personel, blokady, ustawienia e-mail, integracje, dokumenty, branding oraz pozostałe dane powiązane z firmą.</p>
      `,
      checkboxText: 'Posiadam historię rezerwacji przed usunięciem lub świadomie jej nie posiadam. Wiem, że zostaną utracone wszystkie dane oraz konto moje i pracowników.',
      requireCheckbox: true,
      confirmText: 'Rozumiem, przejdź dalej',
      cancelText: 'Anuluj',
      icon: '⚠️',
      variant: 'danger'
    });

    if (!firstConfirm) return;

    deleteAccountDataLossConfirmed = true;

    const passwordInput = document.getElementById('delete-password');
    const password = passwordInput?.value || '';

    if (!password.trim()) {
      await window.openAdminConfirm({
        title: 'Brak hasła',
        message: 'Wpisz hasło, aby usunąć konto.',
        confirmText: 'OK',
        cancelText: 'Zamknij',
        icon: '⚠️',
        variant: 'danger'
      });
      passwordInput?.focus();
      return;
    }

    const data = await apiFetch('/api/user/delete-account.php', {
      method: 'POST',
      headers: { 'Content-Type': 'application/json' },
      body: JSON.stringify({
        action: 'request_code',
        password,
        data_loss_confirmed: true
      })
    });

    if (!data) return;

    if (data.success === true) {
      document.getElementById('delete-code-section')?.classList.remove('hidden');
      document.getElementById('delete-code')?.focus();

      await window.openAdminConfirm({
        title: 'Kod wysłany',
        message: data.message || 'Wysłaliśmy kod potwierdzający na adres e-mail administratora.',
        confirmText: 'OK',
        cancelText: 'Zamknij',
        icon: '✉️',
        variant: 'primary'
      });
      return;
    }

    await window.openAdminConfirm({
      title: 'Błąd',
      message: data.error || 'Nieprawidłowa odpowiedź serwera',
      confirmText: 'OK',
      cancelText: 'Zamknij',
      icon: '✖',
      variant: 'danger'
    });
  } catch (e) {
    console.error(e);

    await window.openAdminConfirm({
      title: 'Błąd połączenia',
      message: e.message || 'Nie udało się wysłać kodu potwierdzającego',
      confirmText: 'OK',
      cancelText: 'Zamknij',
      icon: '✖',
      variant: 'danger'
    });
  } finally {
    isDeletingAccount = false;
    if (btn) btn.disabled = false;
  }
});

document.getElementById('confirm-delete-account-btn')?.addEventListener('click', async (e) => {
  e.preventDefault();

  if (isDeletingAccount) return;
  isDeletingAccount = true;

  const btn = document.getElementById('confirm-delete-account-btn');
  if (btn) btn.disabled = true;

  try {
    const password = document.getElementById('delete-password')?.value || '';
    const code = document.getElementById('delete-code')?.value.trim() || '';

    if (!password.trim() || !deleteAccountDataLossConfirmed) {
      await window.openAdminConfirm({
        title: 'Brak potwierdzenia',
        message: 'Najpierw potwierdź utratę danych i zweryfikuj hasło.',
        confirmText: 'OK',
        cancelText: 'Zamknij',
        icon: '⚠️',
        variant: 'danger'
      });
      return;
    }

    if (!code) {
      await window.openAdminConfirm({
        title: 'Brak kodu',
        message: 'Wpisz kod potwierdzający z wiadomości e-mail.',
        confirmText: 'OK',
        cancelText: 'Zamknij',
        icon: '⚠️',
        variant: 'danger'
      });
      document.getElementById('delete-code')?.focus();
      return;
    }

    if (!/^\d{6}$/.test(code)) {
      await window.openAdminConfirm({
        title: 'Nieprawidłowy kod',
        message: 'Kod potwierdzenia musi mieć 6 cyfr.',
        confirmText: 'OK',
        cancelText: 'Zamknij',
        icon: '⚠️',
        variant: 'danger'
      });
      document.getElementById('delete-code')?.focus();
      return;
    }

    const finalConfirm = await window.openAdminConfirm({
      title: 'Ostatnie potwierdzenie',
      message: 'To ostatnie potwierdzenie. Po usunięciu konta dane zostaną trwale usunięte i nie będzie możliwości ich odzyskania.',
      confirmText: 'Tak, usuń konto',
      cancelText: 'Anuluj',
      icon: '🗑️',
      variant: 'danger'
    });

    if (!finalConfirm) return;

    const data = await apiFetch('/api/user/delete-account.php', {
      method: 'POST',
      headers: { 'Content-Type': 'application/json' },
      body: JSON.stringify({
        action: 'confirm_delete',
        password,
        code,
        data_loss_confirmed: true,
        final_confirmation: true
      })
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

    await window.openAdminConfirm({
      title: 'Błąd',
      message: data.error || 'Nieprawidłowa odpowiedź serwera',
      confirmText: 'OK',
      cancelText: 'Zamknij',
      icon: '✖',
      variant: 'danger'
    });
  } catch (e) {
    console.error(e);

    await window.openAdminConfirm({
      title: 'Błąd połączenia',
      message: e.message || 'Nie udało się usunąć konta',
      confirmText: 'OK',
      cancelText: 'Zamknij',
      icon: '✖',
      variant: 'danger'
    });
  } finally {
    isDeletingAccount = false;
    if (btn) btn.disabled = false;
  }
});
