let TENANT_ID = null;
let SETTINGS = null;
let calendarSettings = {
  work_start: '00:00',
  work_end: '23:59',
  consultation_duration: 60,
  consultation_break: 0,
  booking_buffer: 0,
  booking_start_month_offset: 0,
  booking_month_range: 1
};

const HOLIDAYS = [
  '01-01',
  '01-06',
  '05-01',
  '05-02',
  '05-03',
  '08-15',
  '11-01',
  '11-11',
  '12-24',
  '12-25',
  '12-26'
];

let FRONT_PAYMENT_REQUIRED = false;
let FRONT_CALENDAR_ENABLED = false;
let frontLegalDocumentsEnabled = false;
let frontLegalProviderCompanyName = '';
let FRONT_STAFF_ENABLED = false;
let FRONT_STAFF = [];
let FRONT_FILTERED_STAFF = [];
let FRONT_STAFF_REQUIRED = false;
let FRONT_STAFF_MODULE_AVAILABLE = false;
let FRONT_SERVICE_OPTIONS = [];
let FRONT_SELECTED_SERVICE_SIGNATURE = '';
let FRONT_PUBLIC_SERVICES = [];
let FRONT_USING_PUBLIC_SERVICES = false;
let selectedServiceId = '';
let selectedService = null;
let FRONT_MONTH_AVAILABILITY_CACHE = {};
let FRONT_MONTH_AVAILABILITY_LOADING = {};
let FRONT_MONTH_AVAILABILITY_REQUEST_ID = 0;
let FRONT_SERVICE_GLOBAL = {
  service_name: '',
  service_description: '',
  price_amount: null,
  price_currency: 'PLN',
  payment_required: false,
  payment_message: ''
};

const FRONT_SERVICES_UNAVAILABLE_MESSAGE = 'Nie udało się pobrać listy usług. Odśwież stronę lub spróbuj ponownie za chwilę.';

let FRONT_FORM_FIELDS = {
  show_email: true,
  show_phone: true,
  show_notes: true
};

window.AIIQ_PUBLIC_PLAN_CONTEXT = null;

function getPublicPlanContext() {
  return window.AIIQ_PUBLIC_PLAN_CONTEXT || null;
}

function isPublicFreePlan() {
  const context = getPublicPlanContext();
  return context?.is_free === true || context?.plan_code === 'free';
}

function publicPlanHasFeature(featureKey) {
  const context = getPublicPlanContext();

  if (!context || !context.features || !Object.prototype.hasOwnProperty.call(context.features, featureKey)) {
    return true;
  }

  return context.features[featureKey] === true;
}

function setFrontLegalConsentVisible(isVisible) {
  const consentWrap = getEl('frontLegalConsent');
  const termsConsentInput = getEl('termsConsent');

  if (consentWrap) {
    consentWrap.hidden = !isVisible;
    consentWrap.classList.toggle('hidden', !isVisible);
  }

  if (termsConsentInput) {
    termsConsentInput.required = isVisible;

    if (!isVisible) {
      termsConsentInput.checked = false;
      termsConsentInput.disabled = true;
      termsConsentInput.removeAttribute('required');
    }
  }
}

async function loadSettings() {
  try {
    const res = await fetch(`/api/system/service-settings-public.php?_=${Date.now()}`, {
      cache: 'no-store'
    });

    const data = await res.json();

    if (!res.ok || data.success !== true || !data.service) {
      throw new Error('Nie udało się pobrać publicznych ustawień kalendarza');
    }

    const service = data.service;

    SETTINGS = {
      success: true,
      settings: service
    };

    TENANT_ID = null;
    FRONT_CALENDAR_ENABLED = service.calendar_enabled === true;

    calendarSettings = {
      work_start: service.work_start || '00:00',
      work_end: service.work_end || '23:59',
      consultation_duration: parseInt(service.consultation_duration || 60, 10),
      consultation_break: parseInt(service.consultation_break || 0, 10),
      booking_buffer: parseInt(service.booking_buffer || 0, 10),
      booking_start_month_offset: parseInt(service.booking_start_month_offset || 0, 10),
      booking_month_range: parseInt(service.booking_month_range || 1, 10)
    };

    ALL_TIMES = generateTimeSlots();

  } catch (e) {
    console.error('Błąd publicznych ustawień kalendarza:', e);
    FRONT_CALENDAR_ENABLED = false;
  }
}

function getEl(id) {
  return document.getElementById(id);
}

function bindBookingButton() {
  const bookBtn = document.getElementById('bookBtn');

  if (!bookBtn || bookBtn.dataset.bookingBound === '1') {
    return;
  }

  bookBtn.dataset.bookingBound = '1';

  bookBtn.addEventListener('click', (event) => {
    event.preventDefault();

    if (typeof saveBooking === 'function') {
      saveBooking();
    }
  });
}

function showTenantNotFoundView(message = '') {
  const bookingApp = getEl('bookingApp');
  const tenantNotFoundView = getEl('tenantNotFoundView');
  const tenantNotFoundTitle = tenantNotFoundView?.querySelector('h1');

  if (bookingApp) {
    bookingApp.hidden = true;
  }

  if (tenantNotFoundTitle && message) {
    tenantNotFoundTitle.textContent = message;
  }

  if (tenantNotFoundView) {
    tenantNotFoundView.hidden = false;
  }

  if (window.AppLoader) {
    window.AppLoader.hide();
  }
}

const wait = ms => new Promise(resolve => setTimeout(resolve, ms));

function normalizeFrontHexColor(value, fallback = '#ffffff') {
  const color = String(value || fallback).trim();
  const match = color.match(/^#([0-9a-f]{3}|[0-9a-f]{6})$/i);

  if (!match) {
    return normalizeFrontHexColor(fallback, '#ffffff');
  }

  const hex = match[1].length === 3
    ? match[1].split('').map(char => char + char).join('')
    : match[1];

  return `#${hex.toLowerCase()}`;
}

function getFrontRgbFromHex(value) {
  const hex = normalizeFrontHexColor(value).slice(1);

  return {
    r: parseInt(hex.slice(0, 2), 16),
    g: parseInt(hex.slice(2, 4), 16),
    b: parseInt(hex.slice(4, 6), 16)
  };
}

function getFrontRelativeLuminance(value) {
  const rgb = getFrontRgbFromHex(value);
  const channels = [rgb.r, rgb.g, rgb.b].map(channel => {
    const normalized = channel / 255;
    return normalized <= 0.03928
      ? normalized / 12.92
      : Math.pow((normalized + 0.055) / 1.055, 2.4);
  });

  return (0.2126 * channels[0]) + (0.7152 * channels[1]) + (0.0722 * channels[2]);
}

function getFrontContrastRatio(colorA, colorB) {
  const luminanceA = getFrontRelativeLuminance(colorA);
  const luminanceB = getFrontRelativeLuminance(colorB);
  const lighter = Math.max(luminanceA, luminanceB);
  const darker = Math.min(luminanceA, luminanceB);

  return (lighter + 0.05) / (darker + 0.05);
}

function getReadableFrontTextColor(backgroundColor, darkColor = '#111827', lightColor = '#ffffff') {
  const background = normalizeFrontHexColor(backgroundColor);
  const dark = normalizeFrontHexColor(darkColor, '#111827');
  const light = normalizeFrontHexColor(lightColor, '#ffffff');

  return getFrontContrastRatio(background, dark) >= getFrontContrastRatio(background, light)
    ? dark
    : light;
}

function applyCalendarFrontStyle(style = {}) {
  const root = document.documentElement;
  const bgColor = String(style.bg_color || '#ffffff').trim() || '#ffffff';
  const cardColor = String(style.card_color || '#ffffff').trim() || '#ffffff';
  const cellColor = String(style.cell_color || '#ffffff').trim() || '#ffffff';
  const activeColor = String(style.active_color || '#2563eb').trim() || '#2563eb';
  const blockedColor = String(style.blocked_color || '#e5e7eb').trim() || '#e5e7eb';
  const buttonColor = String(style.button_color || activeColor || cellColor).trim();

  root.style.setProperty('--front-bg-color', bgColor);
  root.style.setProperty('--front-card-color', cardColor);
  root.style.setProperty('--front-cell-color', cellColor);
  root.style.setProperty('--front-active-color', activeColor);
  root.style.setProperty('--front-blocked-color', blockedColor);
  root.style.setProperty('--front-text-color', getReadableFrontTextColor(cardColor));
  root.style.setProperty('--front-muted-text-color', getReadableFrontTextColor(bgColor, '#475569', '#f8fafc'));
  root.style.setProperty('--front-calendar-text-color', getReadableFrontTextColor(cellColor));
  root.style.setProperty('--front-calendar-cell-text-color', getReadableFrontTextColor(cellColor));
  root.style.setProperty('--front-calendar-disabled-text-color', getReadableFrontTextColor(blockedColor, '#374151', '#ffffff'));
  root.style.setProperty('--front-calendar-blocked-text-color', getReadableFrontTextColor(blockedColor, '#374151', '#ffffff'));
  root.style.setProperty('--front-calendar-active-text-color', getReadableFrontTextColor(activeColor));
  root.style.setProperty('--front-button-color', buttonColor);
  root.style.setProperty('--front-button-text-color', getReadableFrontTextColor(buttonColor));

  const radius = String(style.radius || '16').replace(/[^\d]/g, '');
  const width = String(style.width || '520').replace(/[^\d]/g, '');

  root.style.setProperty('--front-radius', `${radius || 16}px`);
  root.style.setProperty('--front-width', `${width || 520}px`);
}

function setFieldLabel(inputId, text) {
  const input = getEl(inputId);
  if (!input) return;

  const label = document.querySelector(`label[for="${inputId}"]`);
  if (label && text) {
    label.textContent = text;
  }

  if (text) {
    input.placeholder = text;
  }
}

function setFieldVisibility(inputId, visible) {
  const input = getEl(inputId);
  if (!input) return;

  const label = document.querySelector(`label[for="${inputId}"]`);

  input.style.display = visible ? '' : 'none';
  input.disabled = !visible;

  if (label) {
    label.style.display = visible ? '' : 'none';
  }
}

function applyCalendarFormFields(fields = {}) {
   FRONT_FORM_FIELDS = {
    show_email: true,
    show_phone: fields.show_phone !== false,
    show_notes: fields.show_notes !== false
  };

  setFieldLabel('name', fields.name_label || 'Imię i nazwisko');
  setFieldLabel('email', fields.email_label || 'E-mail');
  setFieldLabel('phone', fields.phone_label || 'Telefon');
  setFieldLabel('note', fields.notes_label || 'Wiadomość');

  setFieldVisibility('email', true);
  setFieldVisibility('phone', FRONT_FORM_FIELDS.show_phone);
  setFieldVisibility('note', FRONT_FORM_FIELDS.show_notes);
}

function hideFrontLogo(logoEl) {
  if (!logoEl) return;

  logoEl.hidden = true;
  logoEl.alt = '';
  logoEl.onload = null;
  logoEl.onerror = null;
  logoEl.removeAttribute('src');
  logoEl.closest('.front-branding')?.classList.remove('has-front-logo');
}

function setFrontLogo(logoEl, logoUrl, titleText) {
  if (!logoEl) return;

  hideFrontLogo(logoEl);

  if (!logoUrl || !publicPlanHasFeature('branding_logo')) {
    return;
  }

  let resolvedLogoUrl = '';

  try {
    const parsedLogoUrl = new URL(logoUrl, window.location.origin);

    if (!['http:', 'https:'].includes(parsedLogoUrl.protocol)) {
      return;
    }

    resolvedLogoUrl = parsedLogoUrl.href;
  } catch (error) {
    return;
  }

  logoEl.closest('.front-branding')?.classList.add('has-front-logo');

  logoEl.onload = () => {
    logoEl.alt = titleText || 'Logo';
    logoEl.hidden = false;
  };

  logoEl.onerror = () => {
    hideFrontLogo(logoEl);
  };

  logoEl.src = resolvedLogoUrl;
}

async function loadFrontBranding() {
  const res = await fetch('/api/system/branding-public.php', {
    cache: 'no-store'
  });

  const data = await res.json().catch(() => null);

  if (res.status === 404 && data?.error === 'tenant_not_found') {
    showTenantNotFoundView(data.message || 'Ten adres nie jest zarejestrowany w AI-IQ Rezerwacja Pro.');
    return false;
  }

  if (!res.ok || data?.success !== true || !data?.branding) {
    throw new Error('Nie udało się potwierdzić domeny kalendarza');
  }

  const branding = data.branding;
  window.AIIQ_PUBLIC_PLAN_CONTEXT = data.plan_context || null;

const clientName = (branding.client_name || '').trim();
const logoUrl = (branding.logo_url_front || '').trim();
const faviconUrl = (branding.favicon_url_front || '').trim();
    
    const calendarFrontStyle = branding.calendar_front_style || {};
const calendarFormFields = branding.calendar_form_fields || {};

applyCalendarFrontStyle(calendarFrontStyle);
applyCalendarFormFields(calendarFormFields);
const titleText = clientName || 'Rezerwacja konsultacji';

const titleEl = getEl('serviceTitleFront');
if (titleEl) {
  titleEl.textContent = titleText;
}
    const logoEl = getEl('frontLogo');
    setFrontLogo(logoEl, logoUrl, titleText);
    
    if (faviconUrl && publicPlanHasFeature('branding_favicon')) {
  let faviconEl = document.querySelector('link[rel="icon"]');

  if (!faviconEl) {
    faviconEl = document.createElement('link');
    faviconEl.rel = 'icon';
    document.head.appendChild(faviconEl);
  }

  faviconEl.href = faviconUrl;
}

  return true;
}

async function loadFrontLegalDocumentsLinks() {
  const termsLink = getEl('frontTermsLink');
  const privacyLink = getEl('frontPrivacyLink');
  const providerNameEl = getEl('frontProviderCompanyName');
  const termsConsentInput = getEl('termsConsent');

  if (!termsLink || !privacyLink) return;

  frontLegalDocumentsEnabled = false;
  frontLegalProviderCompanyName = '';

  if (isPublicFreePlan() || !publicPlanHasFeature('legal_documents')) {
    setFrontLegalConsentVisible(false);
    return;
  }

  setFrontLegalConsentVisible(true);

  if (providerNameEl) {
    providerNameEl.textContent = '';
  }

  const setLinksDisabled = () => {
    termsLink.href = '#';
    privacyLink.href = '#';
    termsLink.dataset.disabled = 'true';
    privacyLink.dataset.disabled = 'true';

    if (termsConsentInput) {
      termsConsentInput.checked = false;
      termsConsentInput.disabled = true;
      termsConsentInput.removeAttribute('required');
    }
  };

  const handleDisabledClick = event => {
    const link = event.currentTarget;

    if (!link || link.dataset.disabled !== 'true') {
      return;
    }

    event.preventDefault();

    const message = frontLegalProviderCompanyName
      ? `Usługodawca nie przygotował regulaminu oraz polityki prywatności. Skontaktuj się z: ${frontLegalProviderCompanyName}.`
      : 'Usługodawca nie przygotował regulaminu oraz polityki prywatności.';

    showError(message);
  };

  termsLink.onclick = handleDisabledClick;
  privacyLink.onclick = handleDisabledClick;
  setLinksDisabled();

  try {
    const res = await fetch('/api/system/legal-documents-public.php', {
      cache: 'no-store'
    });

    const data = await res.json();
    frontLegalProviderCompanyName = String(data?.provider?.company_full_name || '').trim();

    if (providerNameEl) {
      providerNameEl.textContent = frontLegalProviderCompanyName;
    }

    if (!res.ok || !data.success || !data.enabled || !data.documents) {
      frontLegalDocumentsEnabled = false;
      setLinksDisabled();
      return;
    }

    frontLegalDocumentsEnabled = true;
    termsLink.href = '/dokumenty/regulamin.html';
    privacyLink.href = '/dokumenty/polityka-prywatnosci.html';
    termsLink.dataset.disabled = 'false';
    privacyLink.dataset.disabled = 'false';

    if (termsConsentInput) {
      termsConsentInput.disabled = false;
      termsConsentInput.required = true;
    }

    termsLink.textContent = data.documents.terms_title || 'regulamin';
    privacyLink.textContent = data.documents.privacy_title || 'politykę prywatności';
  } catch (error) {
    frontLegalDocumentsEnabled = false;
    setLinksDisabled();
    console.error('loadFrontLegalDocumentsLinks error:', error);
  }
}

function formatFrontPrice(amount, currency = 'PLN') {
  const numericAmount = Number(amount || 0);

  if (!numericAmount || numericAmount <= 0) {
    return '';
  }

  return new Intl.NumberFormat('pl-PL', {
    style: 'currency',
    currency: currency || 'PLN'
  }).format(numericAmount);
}

function hasFrontValue(value) {
  return value !== null && value !== undefined && String(value).trim() !== '';
}

function normalizeFrontPublicStaff(staff = {}) {
  return {
    id: String(staff.id || '').trim(),
    display_name: String(staff.display_name || '').trim(),
    description: String(staff.description || '').trim(),
    color: String(staff.color || '').trim(),
    sort_order: Number.isFinite(Number(staff.sort_order)) ? Number(staff.sort_order) : 0
  };
}

function normalizeFrontPublicService(service = {}) {
  const assignedStaff = Array.isArray(service.assigned_staff)
    ? service.assigned_staff
        .map(normalizeFrontPublicStaff)
        .filter(staff => staff.id !== '')
    : [];

  return {
    id: String(service.id || '').trim(),
    service_name: String(service.name || service.service_name || '').trim(),
    service_description: String(service.description || '').trim(),
    price_amount: service.price_amount ?? null,
    price_currency: service.price_currency || 'PLN',
    payment_required: service.payment_required === true,
    payment_message: String(service.payment_message || '').trim(),
    service_duration_minutes: hasFrontValue(service.duration)
      ? service.duration
      : service.consultation_duration,
    service_break_minutes: service.break_minutes ?? null,
    booking_buffer_minutes: service.booking_buffer_minutes ?? null,
    sort_order: Number.isFinite(Number(service.sort_order)) ? Number(service.sort_order) : 0,
    assigned_staff: assignedStaff
  };
}

function getSelectedFrontStaff() {
  const staffId = getSelectedStaffId();

  if (!staffId) return null;

  if (FRONT_USING_PUBLIC_SERVICES) {
    return FRONT_FILTERED_STAFF.find(person => String(person.id || '') === staffId) || null;
  }

  return FRONT_STAFF.find(person => String(person.id || '') === staffId) || null;
}

function getEffectiveFrontService(staff = null) {
  if (FRONT_USING_PUBLIC_SERVICES) {
    return selectedService || FRONT_PUBLIC_SERVICES[0] || FRONT_SERVICE_GLOBAL;
  }

  const globalService = FRONT_SERVICE_GLOBAL;
  const hasStaff = staff && typeof staff === 'object';
  const paymentRequired = hasStaff && typeof staff.payments_enabled === 'boolean'
    ? globalService.payment_required === true && staff.payments_enabled === true
    : globalService.payment_required === true;

  return {
    service_name: hasStaff && hasFrontValue(staff.service_name)
      ? String(staff.service_name).trim()
      : String(globalService.service_name || '').trim(),
    service_description: hasStaff && hasFrontValue(staff.service_description)
      ? String(staff.service_description).trim()
      : String(globalService.service_description || '').trim(),
    price_amount: hasStaff && hasFrontValue(staff.service_price)
      ? staff.service_price
      : globalService.price_amount,
    price_currency: globalService.price_currency || 'PLN',
    payment_required: paymentRequired,
    payment_message: String(globalService.payment_message || '').trim(),
    service_duration_minutes: hasStaff && hasFrontValue(staff.service_duration_minutes)
      ? staff.service_duration_minutes
      : calendarSettings.consultation_duration,
    service_break_minutes: hasStaff && hasFrontValue(staff.service_break_minutes)
      ? staff.service_break_minutes
      : calendarSettings.consultation_break,
    booking_buffer_minutes: calendarSettings.booking_buffer
  };
}

function normalizeFrontServiceNumber(value) {
  if (value === null || value === undefined || String(value).trim() === '') {
    return '';
  }

  const number = Number(value);
  return Number.isFinite(number) ? String(number) : String(value).trim();
}

function getFrontServiceSignature(service) {
  return [
    String(service?.service_name || '').trim().toLowerCase(),
    String(service?.service_description || '').trim().toLowerCase(),
    normalizeFrontServiceNumber(service?.price_amount),
    service?.payment_required === true ? '1' : '0',
    normalizeFrontServiceNumber(service?.service_duration_minutes),
    normalizeFrontServiceNumber(service?.service_break_minutes),
    normalizeFrontServiceNumber(service?.booking_buffer_minutes)
  ].join('|');
}

function buildFrontServiceOptions() {
  if (FRONT_USING_PUBLIC_SERVICES) {
    return FRONT_PUBLIC_SERVICES.map(service => ({
      signature: `service:${service.id}`,
      source: 'public-services',
      service,
      label: service.service_name || 'Usługa'
    }));
  }

  const options = new Map();
  const addService = (service, source = 'global') => {
    const signature = getFrontServiceSignature(service);

    if (!signature || options.has(signature)) {
      return;
    }

    const serviceName = String(service?.service_name || '').trim();

    options.set(signature, {
      signature,
      source,
      service,
      label: serviceName || 'Usługa'
    });
  };

  addService(getEffectiveFrontService(null), 'global');

  if (Array.isArray(FRONT_STAFF) && FRONT_STAFF.length > 0) {
    FRONT_STAFF.forEach(staff => {
      const effectiveService = getEffectiveFrontService(staff);

      addService(effectiveService, 'staff');
    });
  }

  return Array.from(options.values());
}

function getSelectedFrontServiceOption() {
  if (!FRONT_SERVICE_OPTIONS.length) {
    selectedService = null;
    selectedServiceId = '';
    return null;
  }

  const serviceSelect = getEl('serviceSelect');
  const selectedSignature = serviceSelect?.value || FRONT_SELECTED_SERVICE_SIGNATURE;

  const option = FRONT_SERVICE_OPTIONS.find(option => option.signature === selectedSignature)
    || FRONT_SERVICE_OPTIONS[0];

  selectedService = option?.service || null;
  selectedServiceId = selectedService?.id || '';

  return option;
}

function getEffectiveFrontMinNoticeMinutes(service = null) {
  const selectedOption = service ? null : getSelectedFrontServiceOption();
  const effectiveService = service || selectedOption?.service || selectedService || null;
  const serviceBufferRaw = effectiveService?.booking_buffer_minutes;
  const serviceBuffer = serviceBufferRaw === null || serviceBufferRaw === undefined || serviceBufferRaw === ''
    ? null
    : parseInt(serviceBufferRaw, 10);

  if (Number.isFinite(serviceBuffer) && serviceBuffer > 0) {
    return Math.max(0, serviceBuffer);
  }

  const globalBuffer = parseInt(calendarSettings.booking_buffer || 0, 10);
  return Number.isFinite(globalBuffer) && globalBuffer > 0 ? globalBuffer : 0;
}

function frontSlotRespectsMinNotice(dateStr, time, minNoticeMinutes = null) {
  const noticeMinutes = minNoticeMinutes === null || minNoticeMinutes === undefined
    ? getEffectiveFrontMinNoticeMinutes()
    : Math.max(0, parseInt(minNoticeMinutes || 0, 10) || 0);

  if (noticeMinutes <= 0) {
    return true;
  }

  if (!dateStr || !/^\d{2}:\d{2}$/.test(String(time || '').slice(0, 5))) {
    return false;
  }

  const slotDate = new Date(`${dateStr}T${String(time).slice(0, 5)}:00`);

  if (Number.isNaN(slotDate.getTime())) {
    return false;
  }

  const minAllowedDate = new Date(Date.now() + (noticeMinutes * 60 * 1000));
  return slotDate >= minAllowedDate;
}

function filterFrontTimesByMinNotice(dateStr, times, service = null) {
  if (!Array.isArray(times) || times.length === 0) {
    return [];
  }

  const noticeMinutes = getEffectiveFrontMinNoticeMinutes(service);

  if (noticeMinutes <= 0) {
    return [...times];
  }

  return times.filter(time => frontSlotRespectsMinNotice(dateStr, time, noticeMinutes));
}

function getStaffForSelectedService() {
  if (FRONT_USING_PUBLIC_SERVICES) {
    if (!FRONT_STAFF_MODULE_AVAILABLE) {
      return [];
    }

    const selectedOption = getSelectedFrontServiceOption();
    const staff = selectedOption?.service?.assigned_staff;

    return Array.isArray(staff) ? staff : [];
  }

  if (!FRONT_STAFF_ENABLED || !Array.isArray(FRONT_STAFF) || !FRONT_STAFF.length) {
    return [];
  }

  const selectedOption = getSelectedFrontServiceOption();
  const selectedSignature = selectedOption?.signature || FRONT_SELECTED_SERVICE_SIGNATURE;

  if (!selectedSignature) {
    return [];
  }

  return FRONT_STAFF.filter(staff => {
    const staffServiceSignature = getFrontServiceSignature(getEffectiveFrontService(staff));
    return staffServiceSignature === selectedSignature;
  });
}

function resetFrontDateAndTimeSelection() {
  const dateInput = getEl('date');
  const timeInput = getEl('time');

  if (dateInput) {
    dateInput.value = '';
  }

  if (timeInput) {
    timeInput.innerHTML = '<option value="">Wybierz godzinę *</option>';
  }
}

function clearFrontMonthAvailabilityCache() {
  FRONT_MONTH_AVAILABILITY_CACHE = {};
  FRONT_MONTH_AVAILABILITY_LOADING = {};
  FRONT_MONTH_AVAILABILITY_REQUEST_ID += 1;
}

function formatFrontMonthKey(date) {
  return `${date.getFullYear()}-${String(date.getMonth() + 1).padStart(2, '0')}`;
}

function getFrontMonthAvailabilityParams(monthKey) {
  const params = new URLSearchParams({
    month: monthKey
  });

  if (FRONT_USING_PUBLIC_SERVICES === true && selectedServiceId) {
    params.set('service_id', selectedServiceId);
  }

  const staffId = getSelectedStaffId();

  if (staffId) {
    params.set('staff_id', staffId);
  }

  return params;
}

function getFrontMonthAvailabilityCacheKey(monthKey) {
  const params = getFrontMonthAvailabilityParams(monthKey);
  return [
    monthKey,
    params.get('service_id') || '',
    params.get('staff_id') || ''
  ].join('|');
}

async function loadFrontMonthAvailability(monthKey, expectedCacheKey = '') {
  if (!monthKey) {
    return false;
  }

  const cacheKey = expectedCacheKey || getFrontMonthAvailabilityCacheKey(monthKey);

  if (FRONT_MONTH_AVAILABILITY_CACHE[cacheKey]) {
    return true;
  }

  if (FRONT_MONTH_AVAILABILITY_LOADING[cacheKey]) {
    return false;
  }

  const requestId = FRONT_MONTH_AVAILABILITY_REQUEST_ID + 1;
  FRONT_MONTH_AVAILABILITY_REQUEST_ID = requestId;
  FRONT_MONTH_AVAILABILITY_LOADING[cacheKey] = true;

  try {
    const params = getFrontMonthAvailabilityParams(monthKey);
    const res = await fetch(`/api/booking/availability-month.php?${params.toString()}`, {
      cache: 'no-store'
    });
    const data = await res.json();

    if (
      requestId !== FRONT_MONTH_AVAILABILITY_REQUEST_ID ||
      getFrontMonthAvailabilityCacheKey(monthKey) !== cacheKey
    ) {
      return false;
    }

    if (!res.ok || data.success !== true || !data.days || typeof data.days !== 'object') {
      throw new Error(data.message || data.error || 'Nie udało się pobrać miesięcznej dostępności');
    }

    FRONT_MONTH_AVAILABILITY_CACHE[cacheKey] = data.days;
    return true;
  } catch (error) {
    if (requestId === FRONT_MONTH_AVAILABILITY_REQUEST_ID) {
      console.error('loadFrontMonthAvailability error:', error);
    }
    return false;
  } finally {
    delete FRONT_MONTH_AVAILABILITY_LOADING[cacheKey];
  }
}

async function refreshFrontCalendarForCurrentSelection() {
  try {
    if (!availabilityData) {
      await getSettings();
    }

    renderCalendarUI();
    await renderTimeOptions();
  } catch (error) {
    console.error('refreshFrontCalendarForCurrentSelection error:', error);
  }
}

function updateStaffForSelectedService() {
  const staffBox = getEl('frontStaffBox');
  const staffEl = getEl('staff');
  const singleInfoEl = getEl('frontStaffSingleInfo');
  const selectedOption = getSelectedFrontServiceOption();

  FRONT_FILTERED_STAFF = getStaffForSelectedService();
  FRONT_STAFF_REQUIRED = FRONT_FILTERED_STAFF.length > 0;

  if (!staffBox || !staffEl) {
    renderFrontService(selectedOption ? selectedOption.service : getEffectiveFrontService(null));
    return;
  }

  staffBox.classList.remove('is-single', 'is-multiple');
  staffBox.classList.add('hidden');
  staffBox.style.display = 'none';
  staffEl.innerHTML = '<option value="">Wybierz osobę</option>';
  staffEl.value = '';
  staffEl.disabled = true;

  if (singleInfoEl) {
    singleInfoEl.textContent = '';
    singleInfoEl.classList.add('hidden');
  }

  if (!FRONT_STAFF_REQUIRED) {
    renderFrontService(selectedOption ? selectedOption.service : getEffectiveFrontService(null));
    return;
  }

  staffBox.classList.remove('hidden');
  staffBox.style.display = '';

  if (FRONT_FILTERED_STAFF.length === 1) {
    const staff = FRONT_FILTERED_STAFF[0];
    const option = document.createElement('option');
    option.value = String(staff.id || '');
    option.textContent = String(staff.display_name || 'Osoba');
    staffEl.appendChild(option);
    staffEl.value = option.value;
    staffEl.disabled = false;
    staffBox.classList.add('is-single');

    if (singleInfoEl) {
      singleInfoEl.textContent = `Osoba obsługująca: ${option.textContent}`;
      singleInfoEl.classList.remove('hidden');
    }

    renderFrontService(getEffectiveFrontService(staff));
    return;
  }

  staffBox.classList.add('is-multiple');
  staffEl.disabled = false;

  FRONT_FILTERED_STAFF.forEach(staff => {
    const option = document.createElement('option');
    option.value = String(staff.id || '');
    option.textContent = String(staff.display_name || 'Osoba');
    staffEl.appendChild(option);
  });

  renderFrontService(selectedOption ? selectedOption.service : getEffectiveFrontService(null));
}

function renderFrontServiceSelect() {
  const selectBox = getEl('frontServiceSelectBox');
  const serviceSelect = getEl('serviceSelect');

  FRONT_SERVICE_OPTIONS = buildFrontServiceOptions();

  if (!selectBox || !serviceSelect) {
    const firstOption = FRONT_SERVICE_OPTIONS[0];
    renderFrontService(firstOption ? firstOption.service : getEffectiveFrontService(null));
    return;
  }

  serviceSelect.innerHTML = '';

  FRONT_SERVICE_OPTIONS.forEach(option => {
    const selectOption = document.createElement('option');
    selectOption.value = option.signature;
    selectOption.textContent = option.label;
    serviceSelect.appendChild(selectOption);
  });

  if (FRONT_SERVICE_OPTIONS.length <= 1) {
    selectBox.classList.add('hidden');
  } else {
    selectBox.classList.remove('hidden');
  }

  if (!FRONT_SELECTED_SERVICE_SIGNATURE || !FRONT_SERVICE_OPTIONS.some(option => option.signature === FRONT_SELECTED_SERVICE_SIGNATURE)) {
    FRONT_SELECTED_SERVICE_SIGNATURE = FRONT_SERVICE_OPTIONS[0]?.signature || '';
  }

  serviceSelect.value = FRONT_SELECTED_SERVICE_SIGNATURE;
  getSelectedFrontServiceOption();

  updateStaffForSelectedService();
}

function renderFrontService(service) {
  const titleEl = getEl('serviceTitleFront');
  const serviceBox = getEl('frontServiceBox');
  const serviceText = getEl('frontServiceText');
  const serviceDescriptionEl = getEl('frontServiceDescription');
  const paymentBox = getEl('frontServicePaymentBox');
  const priceRow = getEl('frontServicePriceRow');
  const priceEl = getEl('frontServicePrice');
  const paymentMessageEl = getEl('frontPaymentMessage');
  const paymentRedirectInfoEl = getEl('frontPaymentRedirectInfo');
  const canShowPayments = publicPlanHasFeature('online_payments') && publicPlanHasFeature('payu');
  const canShowProServiceDetails = !isPublicFreePlan();

  const serviceName = String(service?.service_name || '').trim();
  const serviceDescription = canShowProServiceDetails ? String(service?.service_description || '').trim() : '';
  const hasPayablePrice = Number(service?.price_amount) > 0;
  const paymentRequired = canShowPayments && hasPayablePrice && service?.payment_required === true;
  const paymentMessage = paymentRequired ? String(service?.payment_message || '').trim() : '';
  const priceText = formatFrontPrice(service?.price_amount, service?.price_currency || 'PLN');

  FRONT_PAYMENT_REQUIRED = paymentRequired;

  if (titleEl) {
    titleEl.textContent = serviceName || 'Rezerwacja terminu';
  }

  let shouldShowBox = false;

  if (serviceDescription && serviceText && serviceDescriptionEl) {
    serviceDescriptionEl.textContent = serviceDescription;
    serviceText.classList.remove('hidden');
    shouldShowBox = true;
  } else {
    if (serviceDescriptionEl) serviceDescriptionEl.textContent = '';
    if (serviceText) serviceText.classList.add('hidden');
  }

  const hasPrice = Boolean(priceText);

  if ((hasPrice || paymentRequired) && paymentBox) {
    const hasPaymentMessage = Boolean(paymentMessage);

    if (priceRow) {
      priceRow.classList.toggle('hidden', !hasPrice);
    }

    if (priceEl) {
      priceEl.textContent = hasPrice ? priceText : '';
    }

    if (paymentMessageEl) {
      paymentMessageEl.textContent = paymentRequired
        ? (hasPaymentMessage
          ? paymentMessage
          : 'Po rezerwacji nastąpi przekierowanie do płatności online.')
        : '';
    }

    paymentBox.classList.remove('hidden');
    shouldShowBox = true;
  } else {
    if (priceRow) priceRow.classList.add('hidden');
    if (priceEl) priceEl.textContent = '';
    if (paymentMessageEl) paymentMessageEl.textContent = '';
    if (paymentBox) paymentBox.classList.add('hidden');
  }

  if (paymentRedirectInfoEl) {
    paymentRedirectInfoEl.classList.toggle('hidden', !paymentRequired);
  }

  if (serviceBox) {
    serviceBox.classList.toggle('hidden', !shouldShowBox);
  }
}

function updateFrontServiceForSelectedStaff() {
  const selectedStaff = FRONT_STAFF_REQUIRED ? getSelectedFrontStaff() : null;

  if (selectedStaff) {
    renderFrontService(getEffectiveFrontService(selectedStaff));
    return;
  }

  const selectedOption = getSelectedFrontServiceOption();
  renderFrontService(selectedOption ? selectedOption.service : getEffectiveFrontService(null));
}

async function loadFrontServiceSettings() {
  const publicServicesStatus = await loadFrontPublicServices();

  if (publicServicesStatus === 'available') {
    renderFrontServiceSelect();
    return;
  }

  if (publicServicesStatus === 'error') {
    const error = new Error(FRONT_SERVICES_UNAVAILABLE_MESSAGE);
    error.code = 'services_unavailable';
    throw error;
  }

  await loadFrontLegacyServiceSettings();
  renderFrontServiceSelect();
}

async function loadFrontPublicServices() {
  try {
    const res = await fetch('/api/services/public-list.php', {
      cache: 'no-store'
    });

    const data = await res.json();

    if (!res.ok || data.success !== true || !Array.isArray(data.services)) {
      FRONT_PUBLIC_SERVICES = [];
      FRONT_USING_PUBLIC_SERVICES = false;
      selectedServiceId = '';
      selectedService = null;
      return 'error';
    }

    if (data.services.length === 0) {
      FRONT_PUBLIC_SERVICES = [];
      FRONT_USING_PUBLIC_SERVICES = false;
      selectedServiceId = '';
      selectedService = null;
      return 'empty';
    }

    FRONT_PUBLIC_SERVICES = data.services
      .map(normalizeFrontPublicService)
      .filter(service => service.id !== '' && service.service_name !== '');

    FRONT_USING_PUBLIC_SERVICES = FRONT_PUBLIC_SERVICES.length > 0;

    if (!FRONT_USING_PUBLIC_SERVICES) {
      selectedServiceId = '';
      selectedService = null;
      return 'error';
    }

    FRONT_SELECTED_SERVICE_SIGNATURE = FRONT_PUBLIC_SERVICES[0]
      ? `service:${FRONT_PUBLIC_SERVICES[0].id}`
      : '';

    return 'available';
  } catch (error) {
    console.error('loadFrontPublicServices error:', error);
    FRONT_PUBLIC_SERVICES = [];
    FRONT_USING_PUBLIC_SERVICES = false;
    selectedServiceId = '';
    selectedService = null;
    return 'error';
  }
}

async function loadFrontLegacyServiceSettings() {
  try {
    const res = await fetch('/api/system/service-settings-public.php', {
      cache: 'no-store'
    });

    const data = await res.json();

    if (!res.ok || data.success !== true || !data.service) {
      console.warn('Dane usługi frontu niedostępne:', data);
      return;
    }

    const service = data.service;

    FRONT_SERVICE_GLOBAL = {
      service_name: String(service.service_name || '').trim(),
      service_description: String(service.service_description || '').trim(),
      price_amount: service.price_amount ?? null,
      price_currency: service.price_currency || 'PLN',
      payment_required: service.payment_required === true,
      payment_message: String(service.payment_message || '').trim()
    };

  } catch (e) {
    console.error('loadFrontLegacyServiceSettings error:', e);
  }
}

const paymentRedirectInfo = getEl('frontPaymentRedirectInfo');

function showLoader() {
  const loader = getEl('calendarLoader');
  const calendar = getEl('calendar');

  if (loader) loader.style.display = 'block';
  if (calendar) calendar.style.opacity = '0.3';
}

function hideLoader() {
  const loader = getEl('calendarLoader');
  const calendar = getEl('calendar');

  if (loader) loader.style.display = 'none';
  if (calendar) calendar.style.opacity = '1';
}

function showError(message) {
  const errorBox = getEl('errorBox');
  const successBox = getEl('successBox');

  if (successBox) successBox.style.display = 'none';
  if (errorBox) {
    errorBox.textContent = message;
    errorBox.style.display = 'block';
  }
}

function showSuccess(message) {
  const errorBox = getEl('errorBox');
  const successBox = getEl('successBox');

  if (errorBox) errorBox.style.display = 'none';
  if (successBox) {
    successBox.textContent = message;
    successBox.style.display = 'block';
  }
}

function applyFrontCalendarEnabledState() {
  const bookBtn = getEl('bookBtn');
  const calendar = getEl('calendar');
  const dateEl = getEl('date');
  const timeEl = getEl('time');

  if (FRONT_CALENDAR_ENABLED === true) {
    if (bookBtn) {
      bookBtn.disabled = false;
      bookBtn.textContent = 'Zarezerwuj';
    }

    if (calendar) {
      calendar.classList.remove('calendar-disabled');
      if (calendar.dataset.disabledState === '1') {
        calendar.innerHTML = '';
        delete calendar.dataset.disabledState;
      }
    }

    if (timeEl) {
      timeEl.disabled = false;
    }

    return;
  }

  if (bookBtn) {
    bookBtn.disabled = true;
    bookBtn.textContent = 'Kalendarz wyłączony';
  }

  if (calendar) {
    calendar.classList.add('calendar-disabled');
    calendar.dataset.disabledState = '1';
    calendar.innerHTML = `
      <div class="calendar-disabled-message" role="status" aria-live="polite">
        <span class="calendar-disabled-icon" aria-hidden="true">📅</span>
        <div>
          <strong>Kalendarz jest obecnie wyłączony.</strong>
          <span>Rezerwacja online nie jest teraz dostępna.</span>
        </div>
      </div>
    `;
  }

  if (dateEl) {
    dateEl.value = '';
  }

  if (timeEl) {
    timeEl.innerHTML = '<option value="">Kalendarz wyłączony</option>';
    timeEl.disabled = true;
  }

  showError('Kalendarz rezerwacji jest obecnie konfigurowany przez administratora. Spróbuj ponownie później.');
}

function getSelectedStaffId() {
  const staffEl = getEl('staff');

  return staffEl ? staffEl.value.trim() : '';
}

function resetFrontStaffSelectionState() {
  const staffBox = getEl('frontStaffBox');
  const staffEl = getEl('staff');
  const singleInfoEl = getEl('frontStaffSingleInfo');

  FRONT_STAFF_MODULE_AVAILABLE = false;
  FRONT_STAFF_ENABLED = false;
  FRONT_STAFF = [];
  FRONT_FILTERED_STAFF = [];
  FRONT_STAFF_REQUIRED = false;

  if (staffBox) {
    staffBox.classList.remove('is-single', 'is-multiple');
    staffBox.classList.add('hidden');
    staffBox.style.display = 'none';
  }

  if (staffEl) {
    staffEl.innerHTML = '<option value="">Wybierz osobę</option>';
    staffEl.value = '';
    staffEl.disabled = true;
  }

  if (singleInfoEl) {
    singleInfoEl.textContent = '';
    singleInfoEl.classList.add('hidden');
  }
}

function isFrontStaffLockedResponse(response) {
  return response && response.status === 403;
}

async function loadFrontStaff() {
  const staffBox = getEl('frontStaffBox');
  const staffEl = getEl('staff');

  resetFrontStaffSelectionState();
  if (!staffBox || !staffEl) return;

  try {
    const res = await fetch('/api/staff/public-list.php', {
      cache: 'no-store'
    });

    const data = await res.json().catch(() => null);

    if (
      isFrontStaffLockedResponse(res) ||
      !res.ok ||
      data?.success !== true ||
      data.staff_enabled !== true ||
      !Array.isArray(data.staff) ||
      data.staff.length === 0
    ) {
      renderFrontServiceSelect();
      return;
    }

    FRONT_STAFF_MODULE_AVAILABLE = true;

    if (FRONT_USING_PUBLIC_SERVICES) {
      const staffById = new Map();

      FRONT_PUBLIC_SERVICES.forEach(service => {
        (service.assigned_staff || []).forEach(staff => {
          if (staff.id) {
            staffById.set(staff.id, staff);
          }
        });
      });

      FRONT_STAFF = Array.from(staffById.values());
      FRONT_STAFF_ENABLED = FRONT_STAFF.length > 0;
      renderFrontServiceSelect();
      return;
    }

    FRONT_STAFF = data.staff;
    FRONT_STAFF_ENABLED = true;
    renderFrontServiceSelect();
  } catch (error) {
    console.error('loadFrontStaff error:', error);
  }
}

function clearMessages() {
  const errorBox = getEl('errorBox');
  const successBox = getEl('successBox');

  if (errorBox) errorBox.style.display = 'none';
  if (successBox) successBox.style.display = 'none';
}

function clearFieldErrors() {
  ['name', 'email', 'phone', 'date', 'time'].forEach(id => {
    const el = getEl(id);
    if (el) el.classList.remove('field-error');
  });
}

function markFieldError(id) {
  const el = getEl(id);
  if (el) el.classList.add('field-error');
}

function validateEmail(email) {
  return /^[^\s@]+@[^\s@]+\.[^\s@]+$/.test(String(email || '').trim());
}

function normalizeDigits(value) {
  return String(value || '').replace(/\D+/g, '');
}

function normalizePolishPhone(value) {
  let digits = normalizeDigits(value);

  if (digits.length === 11 && digits.startsWith('48')) {
    digits = digits.slice(2);
  }

  return digits;
}

function validatePhone(phone) {
  const value = String(phone || '').trim();

  if (!value) {
    return false;
  }

  if (!/^\+?[0-9\s-]+$/.test(value)) {
    return false;
  }

  if ((value.match(/\+/g) || []).length > 1) {
    return false;
  }

  if (value.includes('+') && !value.startsWith('+')) {
    return false;
  }

  const digits = value.replace(/\D+/g, '');

  if (value.startsWith('+48')) {
    return digits.length === 11 && digits.startsWith('48');
  }

  if (value.startsWith('+')) {
    return false;
  }

  return digits.length === 9;
}

function validatePersonName(value) {
  const name = String(value || '').trim().replace(/\s+/g, ' ');

  if (name.length < 5 || name.length > 120) {
    return false;
  }

  if (/[0-9]/.test(name)) {
    return false;
  }

  return /^[\p{L}]+(?:[ -][\p{L}]+)+$/u.test(name);
}

function togglePassword() {
  const pass = getEl('password');
  if (!pass) return;
  pass.type = pass.type === 'password' ? 'text' : 'password';
}

function formatLocalDate(date) {
  const year = date.getFullYear();
  const month = String(date.getMonth() + 1).padStart(2, '0');
  const day = String(date.getDate()).padStart(2, '0');
  return `${year}-${month}-${day}`;
}

function getMaxAllowedDate(today) {
  const startOffset = parseInt(calendarSettings.booking_start_month_offset || 0, 10);
  const monthRange = parseInt(calendarSettings.booking_month_range || 1, 10);

  const startMonth = new Date(today.getFullYear(), today.getMonth() + startOffset, 1);
  const endMonth = new Date(startMonth.getFullYear(), startMonth.getMonth() + monthRange, 0);

  return endMonth;
}

function isDateAllowed(dateStr) {
  if (!dateStr) return false;

  const today = new Date();
  today.setHours(0, 0, 0, 0);

  const selected = new Date(`${dateStr}T00:00:00`);
  if (Number.isNaN(selected.getTime())) return false;

  selected.setHours(0, 0, 0, 0);

  const { minMonthDate } = getMonthRangeLimits();
  const maxDate = getMaxAllowedDate(today);

  const minAllowedDate = new Date(
    Math.max(minMonthDate.getTime(), today.getTime())
  );

  const maxAllowedDate = new Date(
    maxDate.getFullYear(),
    maxDate.getMonth(),
    maxDate.getDate(),
    23, 59, 59, 999
  );

  return selected >= minAllowedDate && selected <= maxAllowedDate;
}

function getMonthRangeLimits() {
  const today = new Date();

  const startOffset = parseInt(calendarSettings.booking_start_month_offset || 0, 10);
  const monthRange = parseInt(calendarSettings.booking_month_range || 1, 10);

  const minMonthDate = new Date(today.getFullYear(), today.getMonth() + startOffset, 1);
  const maxMonthDate = new Date(minMonthDate.getFullYear(), minMonthDate.getMonth() + monthRange - 1, 1);

  return { minMonthDate, maxMonthDate };
}

function isSameMonth(dateA, dateB) {
  return (
    dateA.getFullYear() === dateB.getFullYear() &&
    dateA.getMonth() === dateB.getMonth()
  );
}

function isGlobalRuleBlocked(dateStr, blockSettings = {}) {
  const date = new Date(`${dateStr}T00:00:00`);
  const dayOfWeek = date.getDay();
  const mmdd = dateStr.slice(5);

  if (blockSettings.block_saturdays && dayOfWeek === 6) return true;
  if (blockSettings.block_sundays && dayOfWeek === 0) return true;
  if (blockSettings.block_holidays && HOLIDAYS.includes(mmdd)) return true;

  return false;
}

async function renderTimeOptions() {
  const timeEl = getEl('time');
  const dateEl = getEl('date');

  if (!timeEl || !dateEl) return;

  const selectedDate = dateEl.value;

  timeEl.innerHTML = '<option value="">Wybierz godzinę *</option>';

  if (!selectedDate) return;

  try {
    let availableTimes = [];

    if (FRONT_STAFF_REQUIRED) {
      const staffId = getSelectedStaffId();

      if (!staffId) {
        timeEl.innerHTML = '<option value="">Najpierw wybierz osobę</option>';
        return;
      }

      const availabilityParams = new URLSearchParams({
        staff_id: staffId,
        date: selectedDate
      });

      if (FRONT_USING_PUBLIC_SERVICES === true && selectedServiceId) {
        availabilityParams.set('service_id', selectedServiceId);
      }

      const staffRes = await fetch(`/api/staff/public-availability.php?${availabilityParams.toString()}`, {
        cache: 'no-store'
      });

      const staffData = await staffRes.json().catch(() => null);

      if (isFrontStaffLockedResponse(staffRes)) {
        resetFrontStaffSelectionState();
        renderFrontServiceSelect();

        const settings = await getSettings(selectedDate);
        availableTimes = Array.isArray(settings?.availableTimes)
          ? settings.availableTimes
          : [];
      } else {
        if (!staffRes.ok || staffData?.success !== true || !Array.isArray(staffData?.availableTimes)) {
          throw new Error(staffData?.message || staffData?.error || 'Nie udało się pobrać dostępności osoby');
        }

        availableTimes = staffData.availableTimes;
      }
    } else {
      const settings = await getSettings(selectedDate);
      availableTimes = Array.isArray(settings?.availableTimes)
        ? settings.availableTimes
        : [];
    }

    const filteredTimes = filterFrontTimesByMinNotice(selectedDate, availableTimes);

    if (!filteredTimes.length) return;

    filteredTimes.forEach(time => {
      const option = document.createElement('option');
      option.value = time;
      option.textContent = time;
      timeEl.appendChild(option);
    });
  } catch (e) {
    console.error('renderTimeOptions error:', e);
    timeEl.innerHTML = '';

    const option = document.createElement('option');
    option.value = '';
    option.textContent = 'Nie udało się pobrać dostępnych godzin dla wybranego pracownika. Wybierz inny termin albo spróbuj ponownie.';
    timeEl.appendChild(option);
  }
}

async function loadCalendarSettings() {        
  try {
    const res = await fetch('/api/system/settings.php', {
      cache: 'no-store'
    });

    if (!res.ok) {
      throw new Error('Nie udało się pobrać ustawień kalendarza');
    }

    const data = await res.json();

    if (data?.success && data.settings) {
      calendarSettings = {
  work_start: data.settings.work_start || '00:00',
  work_end: data.settings.work_end || '23:59',
  consultation_duration: parseInt(data.settings.consultation_duration || 60, 10),
  consultation_break: parseInt(data.settings.consultation_break || 0, 10),
  booking_buffer: parseInt(data.settings.booking_buffer || 0, 10),
  booking_start_month_offset: parseInt(data.settings.booking_start_month_offset || 0, 10),
  booking_month_range: parseInt(data.settings.booking_month_range || 1, 10)
};
    }

    ALL_TIMES = generateTimeSlots();
  } catch (e) {
    console.error('loadCalendarSettings error:', e);
  }
}

function buildSettingsFromBlockedPayload(blockedPayload = {}, date = '') {
  const blockedDates = Array.isArray(blockedPayload.blockedDates) ? blockedPayload.blockedDates : [];
  const blockedTimes = blockedPayload.blockedTimes && typeof blockedPayload.blockedTimes === 'object'
    ? blockedPayload.blockedTimes
    : {};

  const availabilityExceptions = Array.isArray(blockedPayload.availabilityExceptions)
    ? blockedPayload.availabilityExceptions
    : [];

  const allTimes = generateTimeSlots();

  const blockSettings = blockedPayload.blockSettings && typeof blockedPayload.blockSettings === 'object'
    ? {
        block_saturdays: !!blockedPayload.blockSettings.block_saturdays,
        block_sundays: !!blockedPayload.blockSettings.block_sundays,
        block_holidays: !!blockedPayload.blockSettings.block_holidays
      }
    : {
        block_saturdays: false,
        block_sundays: false,
        block_holidays: false
      };

  const selectedDate = date;
  let availableTimes = [...allTimes];

  if (selectedDate) {
    const isException = availabilityExceptions.includes(selectedDate);

    const isFullBlocked =
      !isException && (
        isGlobalRuleBlocked(selectedDate, blockSettings) ||
        blockedDates.includes(selectedDate)
      );

    if (isFullBlocked) {
      availableTimes = [];
    } else {
      const blockedForDay = Array.isArray(blockedTimes[selectedDate])
        ? blockedTimes[selectedDate]
        : [];

      if (!isException && blockedForDay.includes('all')) {
        availableTimes = [];
      } else {
        availableTimes = allTimes.filter(t => !blockedForDay.includes(t));
      }
    }
  }

  const days = {};

  blockedDates.forEach(dateKey => {
    if (availabilityExceptions.includes(dateKey)) {
      return;
    }

    days[dateKey] = [];
  });

  Object.entries(blockedTimes).forEach(([dateKey, times]) => {
    if (!Array.isArray(times)) return;

    if (availabilityExceptions.includes(dateKey)) {
      return;
    }

    if (times.includes('all')) {
      days[dateKey] = [];
    } else {
      days[dateKey] = allTimes.filter(t => !times.includes(t));
    }
  });

  return {
    workingHours: allTimes,
    availableTimes,
    days,
    blockedDates,
    blockedTimes,
    blockSettings,
    availabilityExceptions
  };
}

async function getSettings(date = '', options = {}) {
  const forceFresh = options && options.forceFresh === true;

  if (!forceFresh && availabilityData && availabilityData.success === true) {
    return buildSettingsFromBlockedPayload(availabilityData, date);
  }

  const res = await fetch('/api/booking/blocked.php', {
    cache: 'no-store'
  });

  if (!res.ok) {
    throw new Error('Nie udało się pobrać blokad.');
  }

  const data = await res.json();

  if (data && data.success === true) {
    availabilityData = data;
  }

  return buildSettingsFromBlockedPayload(data, date);
}

function generateTimeSlots() {
  const slots = [];

  const [startH, startM] = calendarSettings.work_start.split(':').map(Number);
  const [endH, endM] = calendarSettings.work_end.split(':').map(Number);

  const duration = calendarSettings.consultation_duration;
  const breakTime = calendarSettings.consultation_break;

  let current = startH * 60 + startM;
  const end = endH * 60 + endM;

  while (current + duration <= end) {
    const hours = Math.floor(current / 60);
    const minutes = current % 60;

    slots.push(
      `${String(hours).padStart(2, '0')}:${String(minutes).padStart(2, '0')}`
    );

    current += duration + breakTime;
  }

  return slots;
}


function renderCalendarUI() {
  const container = getEl('calendar');
  if (!container) return;
  
  
  const today = new Date();
  const selectedDate = getEl('date')?.value || '';
  const { minMonthDate, maxMonthDate } = getMonthRangeLimits();

  const viewMonthStart = new Date(viewDate.getFullYear(), viewDate.getMonth(), 1);

  if (viewMonthStart < minMonthDate) {
    viewDate = new Date(minMonthDate.getFullYear(), minMonthDate.getMonth(), 1);
  }

  if (viewMonthStart > maxMonthDate) {
    viewDate = new Date(maxMonthDate.getFullYear(), maxMonthDate.getMonth(), 1);
  }

  const year = viewDate.getFullYear();
  const month = viewDate.getMonth();
  const monthKey = formatFrontMonthKey(viewDate);
  const monthAvailabilityCacheKey = getFrontMonthAvailabilityCacheKey(monthKey);
  const monthAvailability = FRONT_MONTH_AVAILABILITY_CACHE[monthAvailabilityCacheKey] || null;

  if (!monthAvailability && !FRONT_MONTH_AVAILABILITY_LOADING[monthAvailabilityCacheKey]) {
    loadFrontMonthAvailability(monthKey, monthAvailabilityCacheKey).then(loaded => {
      if (
        loaded &&
        formatFrontMonthKey(viewDate) === monthKey &&
        getFrontMonthAvailabilityCacheKey(monthKey) === monthAvailabilityCacheKey
      ) {
        renderCalendarUI();
      }
    });
  }

  const monthNames = [
    'Styczeń', 'Luty', 'Marzec', 'Kwiecień', 'Maj', 'Czerwiec',
    'Lipiec', 'Sierpień', 'Wrzesień', 'Październik', 'Listopad', 'Grudzień'
  ];

  let firstDay = new Date(year, month, 1).getDay();
  firstDay = firstDay === 0 ? 6 : firstDay - 1;

  const daysInMonth = new Date(year, month + 1, 0).getDate();

  let html = `
    <div class="calendar-header">
      <button id="prevMonth" type="button">‹</button>
      <div class="month-title">${monthNames[month]} ${year}</div>
      <button id="nextMonth" type="button">›</button>
    </div>

    <div class="calendar-weekdays">
      <div>Pn</div>
      <div>Wt</div>
      <div>Śr</div>
      <div>Cz</div>
      <div>Pt</div>
      <div>So</div>
      <div>Nd</div>
    </div>

    <div class="calendar-grid">
  `;

  for (let i = 0; i < firstDay; i++) {
    html += '<div class="day empty"></div>';
  }

  for (let day = 1; day <= daysInMonth; day++) {
    const dateStr = `${year}-${String(month + 1).padStart(2, '0')}-${String(day).padStart(2, '0')}`;

    const allowed = isDateAllowed(dateStr);
    const isToday = dateStr === formatLocalDate(today);
    const isSelected = dateStr === selectedDate;
    const monthAvailabilityEntry = monthAvailability &&
      Object.prototype.hasOwnProperty.call(monthAvailability, dateStr)
      ? monthAvailability[dateStr]
      : null;
    const monthAvailabilityBlocksDay = monthAvailabilityEntry !== null && (
      monthAvailabilityEntry.available !== true ||
      Number(monthAvailabilityEntry.times_count || 0) <= 0
    );

    const classes = ['day'];
    let dayDisabled = false;

    if (!allowed || monthAvailabilityBlocksDay) {
      dayDisabled = true;
    } else {
      const blockSettings = availabilityData?.blockSettings || {
        block_saturdays: false,
        block_sundays: false,
        block_holidays: false
      };

     const isException = (availabilityData?.availabilityExceptions || []).includes(dateStr);

const isFullBlocked =
  !isException && (
    isGlobalRuleBlocked(dateStr, blockSettings) ||
    (availabilityData?.blockedDates || []).includes(dateStr)
  );

      let available = [];

      if (isFullBlocked) {
        available = [];
      } else if (
        availabilityData &&
        availabilityData.days &&
        Object.prototype.hasOwnProperty.call(availabilityData.days, dateStr)
      ) {
        available = availabilityData.days[dateStr];
      } else {
        available = ALL_TIMES;
      }

      const availableCount = filterFrontTimesByMinNotice(dateStr, available).length;

      if (availableCount <= 0) {
        dayDisabled = true;
      } else if (availableCount === 1) {
        classes.push('medium');
      } else {
        classes.push('available');
      }
    }

    if (dayDisabled) classes.push('disabled');
    if (isToday) classes.push('today');
    if (isSelected && !dayDisabled) classes.push('selected');

    html += `<div class="${classes.join(' ')}" data-date="${dateStr}">${day}</div>`;
  }

  html += '</div>';
  container.innerHTML = html;

  const prevBtn = getEl('prevMonth');
  const nextBtn = getEl('nextMonth');

  if (prevBtn) {
    prevBtn.disabled = isSameMonth(viewDate, minMonthDate);
    prevBtn.onclick = () => {
      const newDate = new Date(viewDate.getFullYear(), viewDate.getMonth() - 1, 1);

      if (newDate >= minMonthDate) {
        viewDate = newDate;
        renderCalendarUI();
      }
    };
  }

  if (nextBtn) {
    nextBtn.disabled = isSameMonth(viewDate, maxMonthDate);
    nextBtn.onclick = () => {
      const newDate = new Date(viewDate.getFullYear(), viewDate.getMonth() + 1, 1);

      if (newDate <= maxMonthDate) {
        viewDate = newDate;
        renderCalendarUI();
      }
    };
  }

  document.querySelectorAll('.day[data-date]:not(.disabled)').forEach(el => {
    el.addEventListener('click', () => {
      const date = el.getAttribute('data-date');
      const dateInput = getEl('date');

      if (dateInput) {
        dateInput.value = date;
      }

      renderCalendarUI();
      renderTimeOptions();
    });
  });
}

async function loadMyAccount() {
  try {
    const res = await fetch('/api/auth/me.php', {
      credentials: 'include'
    });

    const data = await res.json();

    if (!res.ok) {
      console.error('Błąd:', data);
      return;
    }

    document.getElementById('my-email').textContent = data.user.email;
    document.getElementById('my-role').textContent = data.user.role;
  } catch (err) {
    console.error('Fetch error:', err);
  }
}


async function saveBooking() {
  clearMessages();
  clearFieldErrors();

  const bookBtn = getEl('bookBtn');
  const nameInput = getEl('name');
  const emailInput = getEl('email');
  const phoneInput = getEl('phone');
  const dateInput = getEl('date');
  const timeInput = getEl('time');
  const noteInput = getEl('note');
  const termsConsentInput = getEl('termsConsent');
  
  const websiteInput = getEl('website');
const formStartedAtInput = getEl('formStartedAt');

  if (!bookBtn || !nameInput || !emailInput || !phoneInput || !dateInput || !timeInput || !noteInput) {
    showError('Brakuje wymaganych pól formularza.');
    return;
  }

  if (publicPlanHasFeature('legal_documents') && frontLegalDocumentsEnabled !== true) {
    const message = frontLegalProviderCompanyName
      ? `Usługodawca nie przygotował regulaminu oraz polityki prywatności. Rezerwacja online nie jest obecnie dostępna. Skontaktuj się z ${frontLegalProviderCompanyName}.`
      : 'Usługodawca nie przygotował regulaminu oraz polityki prywatności. Rezerwacja online nie jest obecnie dostępna.';

    showError(message);
    return;
  }
  
  if (publicPlanHasFeature('legal_documents') && (!termsConsentInput || !termsConsentInput.checked)) {
  showError('Zaakceptuj regulamin i politykę prywatności');
  if (termsConsentInput) termsConsentInput.focus();
  return;
}

if (websiteInput && websiteInput.value.trim() !== '') {
  showError('Nie udało się zapisać rezerwacji. Spróbuj ponownie za chwilę');
  return;
}

 if (!SETTINGS) {
  showError('Błąd konfiguracji systemu');
  return;
}

if (FRONT_USING_PUBLIC_SERVICES === true) {
  getSelectedFrontServiceOption();

  if (!selectedServiceId || !selectedService) {
    showError('Wybierz usługę przed rezerwacją.');
    const serviceSelect = getEl('serviceSelect');
    if (serviceSelect) serviceSelect.focus();
    return;
  }
}

const booking = {
  name: nameInput.value.trim(),
  email: emailInput.value.trim(),
  phone: phoneInput.value.trim(),
  date: dateInput.value,
  time: timeInput.value,
  note: noteInput.value.trim(),
  
  website: websiteInput ? websiteInput.value.trim() : '',
  form_started_at: formStartedAtInput ? formStartedAtInput.value : '',
  terms_accepted: publicPlanHasFeature('legal_documents')
    ? (termsConsentInput && termsConsentInput.checked ? '1' : '0')
    : '1'
};

// Backend w book.php waliduje usługę, cenę, płatność i przypisanie pracownika.
if (FRONT_USING_PUBLIC_SERVICES === true && selectedServiceId) {
  booking.service_id = selectedServiceId;
}

if (FRONT_STAFF_REQUIRED) {
  const staffId = getSelectedStaffId();

  if (!staffId) {
    showError('Wybierz osobę obsługującą rezerwację');
    const staffEl = getEl('staff');
    if (staffEl) staffEl.focus();
    return;
  }

  booking.staff_id = staffId;
}

  if (!validatePersonName(booking.name)) {
    markFieldError('name');
    showError('Podaj poprawne imię i nazwisko.');
    nameInput.focus();
    return;
  }

    if (!booking.email) {
    markFieldError('email');
    showError('Podaj adres e-mail');
    emailInput.focus();
    return;
  }

  if (!validateEmail(booking.email)) {
    markFieldError('email');
    showError('Podaj poprawny adres e-mail');
    emailInput.focus();
    return;
  }

  if (FRONT_FORM_FIELDS.show_phone) {
  if (!booking.phone) {
    markFieldError('phone');
    showError('Podaj numer telefonu');
    phoneInput.focus();
    return;
  }

  if (!validatePhone(booking.phone)) {
    markFieldError('phone');
    showError('Podaj poprawny numer telefonu');
    phoneInput.focus();
    return;
  }
} else {
  booking.phone = '';
}

  if (!booking.date) {
    markFieldError('date');
    showError('Wybierz datę konsultacji');
    dateInput.focus();
    return;
  }

  if (!isDateAllowed(booking.date)) {
    markFieldError('date');
    showError('Wybrana data jest niedostępna');
    dateInput.focus();
    return;
  }

  if (!booking.time) {
    markFieldError('time');
    showError('Wybierz godzinę konsultacji');
    timeInput.focus();
    return;
  }

  bookBtn.disabled = true;
  bookBtn.innerHTML = '⏳ Zapisywanie...';

  try {
    let finalAvailable = [];

    if (FRONT_STAFF_REQUIRED) {
      const staffId = booking.staff_id || getSelectedStaffId();

      if (!staffId) {
        showError('Wybierz osobę obsługującą rezerwację');
        const staffEl = getEl('staff');
        if (staffEl) staffEl.focus();
        return;
      }

      const availabilityParams = new URLSearchParams({
        staff_id: staffId,
        date: booking.date
      });

      if (FRONT_USING_PUBLIC_SERVICES === true && selectedServiceId) {
        availabilityParams.set('service_id', selectedServiceId);
      }

      const staffRes = await fetch(`/api/staff/public-availability.php?${availabilityParams.toString()}`, {
        cache: 'no-store'
      });

      const staffData = await staffRes.json().catch(() => null);

      if (isFrontStaffLockedResponse(staffRes)) {
        resetFrontStaffSelectionState();
        renderFrontServiceSelect();
        delete booking.staff_id;

        const settings = await getSettings(booking.date, { forceFresh: true });
        finalAvailable = Array.isArray(settings?.availableTimes)
          ? settings.availableTimes
          : [];
      } else {
        if (!staffRes.ok || staffData?.success !== true || !Array.isArray(staffData?.availableTimes)) {
          throw new Error('Nie udało się pobrać dostępności osoby');
        }

        finalAvailable = staffData.availableTimes;
      }
    } else {
      const settings = await getSettings(booking.date, { forceFresh: true });
      finalAvailable = Array.isArray(settings?.availableTimes)
        ? settings.availableTimes
        : [];
    }

    finalAvailable = filterFrontTimesByMinNotice(booking.date, finalAvailable);

    if (!finalAvailable.includes(booking.time)) {
      showError('Wybrana godzina jest już niedostępna');
      return;
    }

    const formStartedAtRaw = formStartedAtInput ? formStartedAtInput.value.trim() : '';

    if (formStartedAtRaw !== '') {
      const formStartedAt = Number(formStartedAtRaw);
      const elapsedMs = Date.now() - formStartedAt;
      const minFillTimeMs = 3000;

      if (Number.isFinite(elapsedMs) && elapsedMs >= 0 && elapsedMs < minFillTimeMs) {
        await wait(minFillTimeMs - elapsedMs + 150);
      }

      const formFillTimeMs = Date.now() - formStartedAt;

      if (Number.isFinite(formFillTimeMs) && formFillTimeMs >= 0) {
        booking.form_fill_time_ms = formFillTimeMs;
      }
    }

    const res = await fetch('/api/booking/book.php', {
      method: 'POST',
      headers: {
        'Content-Type': 'application/json'
      },
      body: JSON.stringify(booking)
    });

    const text = await res.text();
    let result = null;

    try {
      result = JSON.parse(text);
    } catch (e) {
      console.error('Nieprawidłowy JSON z book.php:', text);
      showError('Rezerwacja mogła zostać zapisana, ale odpowiedź serwera była nieprawidłowa');
      return;
    }

    if (!res.ok || result.success !== true) {
      showError(result.message || result.error || 'Nie udało się zapisać rezerwacji');
      return;
    }
    
    if (result.payment_required === true) {
      bookBtn.innerHTML = '⏳ Przekierowanie do płatności...';

      const payuRes = await fetch('/api/payments/payu-create-order.php', {
        method: 'POST',
        headers: {
          'Content-Type': 'application/json'
        },
        body: JSON.stringify({})
      });

      const payuText = await payuRes.text();
      let payuResult = null;

      try {
        payuResult = JSON.parse(payuText);
      } catch (e) {
        console.error('Nieprawidłowy JSON z payu-create-order.php:', payuText);
        showError('Rezerwacja została zapisana, ale odpowiedź płatności była nieprawidłowa.');
        return;
      }

      if (!payuRes.ok || payuResult.success !== true || !payuResult.payment_url) {
        showError(payuResult?.message || payuResult?.error || 'Rezerwacja została zapisana, ale nie udało się utworzyć płatności.');
        return;
      }

      window.location.href = payuResult.payment_url;
      return;
    }

    showSuccess('Dziękujemy! Rezerwacja została zapisana. Potwierdzenie wyślemy na email.');

    nameInput.value = '';
    emailInput.value = '';
    phoneInput.value = '';
    dateInput.value = '';
    noteInput.value = '';
    timeInput.innerHTML = '<option value="">Wybierz godzinę *</option>';

    try {
      await getSettings('', { forceFresh: true });
      clearFrontMonthAvailabilityCache();
      viewDate = new Date();
      renderCalendarUI();
      await renderTimeOptions();
    } catch (uiError) {
      console.error('Błąd odświeżania UI po zapisie:', uiError);
    }

    setTimeout(() => {
      clearMessages();
    }, 5000);

  } catch (error) {
    console.error('saveBooking error:', error);
    showError('Błąd połączenia z serwerem. Spróbuj ponownie za chwilę.');
  } finally {
    bookBtn.disabled = false;
    bookBtn.innerHTML = 'Zarezerwuj';
  }
}


function applyFrontBrandingData(branding = {}, planContext = null) {
  window.AIIQ_PUBLIC_PLAN_CONTEXT = planContext || null;

  const clientName = String(branding.client_name || '').trim();
  const logoUrl = String(branding.logo_url_front || '').trim();
  const faviconUrl = String(branding.favicon_url_front || '').trim();
  const calendarFrontStyle = branding.calendar_front_style || {};
  const calendarFormFields = branding.calendar_form_fields || {};

  applyCalendarFrontStyle(calendarFrontStyle);
  applyCalendarFormFields(calendarFormFields);

  const titleText = clientName || 'Rezerwacja konsultacji';
  const titleEl = getEl('serviceTitleFront');

  if (titleEl) {
    titleEl.textContent = titleText;
  }

  const logoEl = getEl('frontLogo');

  setFrontLogo(logoEl, logoUrl, titleText);

  if (faviconUrl && publicPlanHasFeature('branding_favicon')) {
    let faviconEl = document.querySelector('link[rel="icon"]');

    if (!faviconEl) {
      faviconEl = document.createElement('link');
      faviconEl.rel = 'icon';
      document.head.appendChild(faviconEl);
    }

    faviconEl.href = faviconUrl;
  }
}

function applyFrontServiceSettingsData(service = null) {
  if (!service || typeof service !== 'object') {
    SETTINGS = null;
    FRONT_CALENDAR_ENABLED = false;
    return;
  }

  SETTINGS = {
    success: true,
    settings: service
  };

  TENANT_ID = null;
  FRONT_CALENDAR_ENABLED = service.calendar_enabled === true;

  calendarSettings = {
    work_start: service.work_start || '00:00',
    work_end: service.work_end || '23:59',
    consultation_duration: parseInt(service.consultation_duration || 60, 10),
    consultation_break: parseInt(service.consultation_break || 0, 10),
    booking_buffer: parseInt(service.booking_buffer || 0, 10),
    booking_start_month_offset: parseInt(service.booking_start_month_offset || 0, 10),
    booking_month_range: parseInt(service.booking_month_range || 1, 10)
  };

  FRONT_SERVICE_GLOBAL = {
    service_name: String(service.service_name || '').trim(),
    service_description: String(service.service_description || '').trim(),
    price_amount: service.price_amount ?? null,
    price_currency: service.price_currency || 'PLN',
    payment_required: service.payment_required === true,
    payment_message: String(service.payment_message || '').trim()
  };

  ALL_TIMES = generateTimeSlots();
}

function applyFrontPublicServicesData(servicesPayload = {}) {
  if (servicesPayload?.success !== true || !Array.isArray(servicesPayload.services)) {
    FRONT_PUBLIC_SERVICES = [];
    FRONT_USING_PUBLIC_SERVICES = false;
    selectedServiceId = '';
    selectedService = null;
    return false;
  }

  const services = Array.isArray(servicesPayload.services)
    ? servicesPayload.services
    : [];

  FRONT_PUBLIC_SERVICES = services
    .map(normalizeFrontPublicService)
    .filter(service => service.id !== '' && service.service_name !== '');

  FRONT_USING_PUBLIC_SERVICES = FRONT_PUBLIC_SERVICES.length > 0;

  if (FRONT_USING_PUBLIC_SERVICES) {
    FRONT_SELECTED_SERVICE_SIGNATURE = FRONT_PUBLIC_SERVICES[0]
      ? `service:${FRONT_PUBLIC_SERVICES[0].id}`
      : '';
    return true;
  }

  if (services.length > 0) {
    selectedServiceId = '';
    selectedService = null;
    return false;
  }

  selectedServiceId = '';
  selectedService = null;
  return true;
}

function applyFrontStaffData(staffPayload = {}) {
  resetFrontStaffSelectionState();

  const bootstrapStaff = Array.isArray(staffPayload.staff)
    ? staffPayload.staff
    : [];

  const hasAssignedStaffInServices = Array.isArray(FRONT_PUBLIC_SERVICES)
    && FRONT_PUBLIC_SERVICES.some(service => Array.isArray(service.assigned_staff) && service.assigned_staff.length > 0);

  FRONT_STAFF_MODULE_AVAILABLE = staffPayload.success === true || hasAssignedStaffInServices;

  if (FRONT_USING_PUBLIC_SERVICES) {
    const staffById = new Map();

    FRONT_PUBLIC_SERVICES.forEach(service => {
      (service.assigned_staff || []).forEach(staff => {
        if (staff.id) {
          staffById.set(staff.id, staff);
        }
      });
    });

    FRONT_STAFF = Array.from(staffById.values());
    FRONT_STAFF_ENABLED = FRONT_STAFF.length > 0;
    return;
  }

  FRONT_STAFF = bootstrapStaff;
  FRONT_STAFF_ENABLED = staffPayload.staff_enabled === true && FRONT_STAFF.length > 0;
}

function applyFrontLegalDocumentsData(legalPayload = {}) {
  const termsLink = getEl('frontTermsLink');
  const privacyLink = getEl('frontPrivacyLink');
  const providerNameEl = getEl('frontProviderCompanyName');
  const termsConsentInput = getEl('termsConsent');

  if (!termsLink || !privacyLink) return;

  frontLegalDocumentsEnabled = false;
  frontLegalProviderCompanyName = '';

  if (isPublicFreePlan() || !publicPlanHasFeature('legal_documents')) {
    setFrontLegalConsentVisible(false);
    return;
  }

  setFrontLegalConsentVisible(true);

  const providerName = String(legalPayload?.provider?.company_full_name || '').trim();
  frontLegalProviderCompanyName = providerName;

  if (providerNameEl) {
    providerNameEl.textContent = providerName;
  }

  const setLinksDisabled = () => {
    termsLink.href = '#';
    privacyLink.href = '#';
    termsLink.dataset.disabled = 'true';
    privacyLink.dataset.disabled = 'true';

    if (termsConsentInput) {
      termsConsentInput.checked = false;
      termsConsentInput.disabled = true;
      termsConsentInput.removeAttribute('required');
    }
  };

  const handleDisabledClick = event => {
    const link = event.currentTarget;

    if (!link || link.dataset.disabled !== 'true') {
      return;
    }

    event.preventDefault();

    const message = frontLegalProviderCompanyName
      ? `Usługodawca nie przygotował regulaminu oraz polityki prywatności. Skontaktuj się z: ${frontLegalProviderCompanyName}.`
      : 'Usługodawca nie przygotował regulaminu oraz polityki prywatności.';

    showError(message);
  };

  termsLink.onclick = handleDisabledClick;
  privacyLink.onclick = handleDisabledClick;
  setLinksDisabled();

  if (legalPayload.success !== true || legalPayload.enabled !== true || !legalPayload.documents) {
    return;
  }

  frontLegalDocumentsEnabled = true;
  termsLink.href = legalPayload.documents?.links?.terms || '/dokumenty/regulamin.html';
  privacyLink.href = legalPayload.documents?.links?.privacy || '/dokumenty/polityka-prywatnosci.html';
  termsLink.dataset.disabled = 'false';
  privacyLink.dataset.disabled = 'false';

  if (termsConsentInput) {
    termsConsentInput.disabled = false;
    termsConsentInput.required = true;
  }

  termsLink.textContent = legalPayload.documents.terms_title || 'regulamin';
  privacyLink.textContent = legalPayload.documents.privacy_title || 'politykę prywatności';
}

function applyFrontBlockedData(blockedPayload = {}) {
  availabilityData = blockedPayload && blockedPayload.success === true
    ? blockedPayload
    : null;
}

async function loadFrontBootstrap() {
  let res;
  let data;

  try {
    res = await fetch('/api/front/bootstrap.php', {
      cache: 'no-store'
    });

    data = await res.json().catch(() => null);
  } catch (error) {
    console.error('loadFrontBootstrap error:', error);
    return false;
  }

  if (res.status === 404 && data?.error === 'tenant_not_found') {
    showTenantNotFoundView(data.message || 'Ten adres nie jest zarejestrowany w AI-IQ Rezerwacja Pro.');
    return 'tenant_not_found';
  }

  if (!res.ok || data?.success !== true) {
    console.warn('Bootstrap frontu niedostępny, używam starego ładowania.', data?.error || res.status);
    return false;
  }

  applyFrontBrandingData(data.branding || {}, data.plan_context || null);
  applyFrontServiceSettingsData(data.service || null);
  const servicesLoaded = applyFrontPublicServicesData(data.services || {});

  if (!servicesLoaded) {
    return 'services_unavailable';
  }

  applyFrontStaffData(data.staff || {});
  applyFrontLegalDocumentsData(data.legal || {});
  applyFrontBlockedData(data.blocked || {});
  renderFrontServiceSelect();

  return true;
}

document.addEventListener('DOMContentLoaded', async () => {

  bindBookingButton();

  const formStartedAtInput = getEl('formStartedAt');
  if (formStartedAtInput) {
    formStartedAtInput.value = String(Date.now());
  }

  try {
    const bootstrapResult = await loadFrontBootstrap();

    if (bootstrapResult === 'tenant_not_found') {
      return;
    }

    if (bootstrapResult === 'services_unavailable') {
      showError(FRONT_SERVICES_UNAVAILABLE_MESSAGE);
      if (window.AppLoader) {
        window.AppLoader.fail(FRONT_SERVICES_UNAVAILABLE_MESSAGE);
      }
      return;
    }

    if (bootstrapResult !== true) {
      const tenantAvailable = await loadFrontBranding();

      if (!tenantAvailable) {
        return;
      }

      const legalDocumentsPromise = loadFrontLegalDocumentsLinks().catch(error => {
        console.error('loadFrontLegalDocumentsLinks background error:', error);
      });

      await Promise.all([
        loadFrontServiceSettings(),
        loadSettings()
      ]);

      applyFrontCalendarEnabledState();

      if (!FRONT_CALENDAR_ENABLED) {
        if (window.AppLoader) {
          window.AppLoader.hide();
        }
        return;
      }

      await loadFrontStaff();

      const { minMonthDate } = getMonthRangeLimits();
      viewDate = new Date(minMonthDate.getFullYear(), minMonthDate.getMonth(), 1);

      await getSettings();
      ALL_TIMES = generateTimeSlots();
      legalDocumentsPromise.catch(() => null);
    } else {
      applyFrontCalendarEnabledState();

      if (!FRONT_CALENDAR_ENABLED) {
        if (window.AppLoader) {
          window.AppLoader.hide();
        }
        return;
      }

      const { minMonthDate } = getMonthRangeLimits();
      viewDate = new Date(minMonthDate.getFullYear(), minMonthDate.getMonth(), 1);
      ALL_TIMES = generateTimeSlots();
    }
  } catch (e) {
    console.error(e);

    if (e?.code === 'services_unavailable') {
      showError(FRONT_SERVICES_UNAVAILABLE_MESSAGE);
      if (window.AppLoader) {
        window.AppLoader.fail(FRONT_SERVICES_UNAVAILABLE_MESSAGE);
      }
      return;
    }

    if (window.AppLoader) {
      window.AppLoader.fail('Nie udało się potwierdzić adresu kalendarza. Odśwież stronę i spróbuj ponownie.');
    }

    return;
  }

  renderCalendarUI();

  const dateEl = getEl('date');
  const timeEl = getEl('time');
  const staffEl = getEl('staff');
  const serviceSelect = getEl('serviceSelect');

  if (dateEl) {
    dateEl.addEventListener('change', () => {
      renderCalendarUI();
      renderTimeOptions();
    });
  }

  if (timeEl && !timeEl.value) {
    timeEl.innerHTML = '<option value="">Wybierz godzinę *</option>';
  }

  if (serviceSelect) {
    serviceSelect.addEventListener('change', async () => {
      FRONT_SELECTED_SERVICE_SIGNATURE = serviceSelect.value;

      resetFrontDateAndTimeSelection();
      updateStaffForSelectedService();
      clearFrontMonthAvailabilityCache();
      await refreshFrontCalendarForCurrentSelection();
    });
  }

  if (staffEl) {
    staffEl.addEventListener('change', async () => {
      updateFrontServiceForSelectedStaff();
      resetFrontDateAndTimeSelection();
      clearFrontMonthAvailabilityCache();
      await refreshFrontCalendarForCurrentSelection();
    });
  }

  const phoneEl = getEl('phone');
  if (phoneEl) {
    phoneEl.addEventListener('input', () => {
      phoneEl.value = phoneEl.value
        .replace(/[^\d+\-\s]/g, '')
        .replace(/(?!^)\+/g, '')
        .slice(0, 20);
    });
  }

  await renderTimeOptions();

  if (window.AppLoader) {
    window.AppLoader.hide();
  }
});
