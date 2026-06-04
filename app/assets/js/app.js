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

    console.log('Publiczne ustawienia kalendarza załadowane:', SETTINGS);
  } catch (e) {
    console.error('Błąd publicznych ustawień kalendarza:', e);
    FRONT_CALENDAR_ENABLED = false;
  }
}

function getEl(id) {
  return document.getElementById(id);
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

function applyCalendarFrontStyle(style = {}) {
  const root = document.documentElement;

  root.style.setProperty('--front-bg-color', style.bg_color || '#ffffff');
  root.style.setProperty('--front-card-color', style.card_color || '#ffffff');
  root.style.setProperty('--front-cell-color', style.cell_color || '#ffffff');
  root.style.setProperty('--front-active-color', style.active_color || '#2563eb');
  root.style.setProperty('--front-blocked-color', style.blocked_color || '#e5e7eb');

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
    if (logoEl && logoUrl && publicPlanHasFeature('branding_logo')) {
      logoEl.src = logoUrl;
      logoEl.alt = titleText || 'Logo';
      logoEl.hidden = false;
    } else if (logoEl) {
      logoEl.removeAttribute('src');
      logoEl.alt = '';
      logoEl.hidden = true;
    }
    
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
    booking_buffer_minutes: hasStaff && hasFrontValue(staff.booking_buffer_minutes)
      ? staff.booking_buffer_minutes
      : calendarSettings.booking_buffer
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

function getStaffForSelectedService() {
  if (FRONT_USING_PUBLIC_SERVICES) {
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
    availabilityData = await getSettings();
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
  const canShowPayments = publicPlanHasFeature('online_payments');
  const canShowProServiceDetails = !isPublicFreePlan();

  const serviceName = String(service?.service_name || '').trim();
  const serviceDescription = canShowProServiceDetails ? String(service?.service_description || '').trim() : '';
  const paymentRequired = canShowPayments && service?.payment_required === true;
  const paymentMessage = paymentRequired ? String(service?.payment_message || '').trim() : '';
  const priceText = paymentRequired ? formatFrontPrice(service?.price_amount, service?.price_currency || 'PLN') : '';

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

  if (paymentRequired && paymentBox) {
    const hasPrice = Boolean(priceText);
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
  const hasPublicServices = await loadFrontPublicServices();

  if (hasPublicServices) {
    renderFrontServiceSelect();
    return;
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

    if (!res.ok || data.success !== true || !Array.isArray(data.services) || data.services.length === 0) {
      FRONT_PUBLIC_SERVICES = [];
      FRONT_USING_PUBLIC_SERVICES = false;
      selectedServiceId = '';
      selectedService = null;
      return false;
    }

    FRONT_PUBLIC_SERVICES = data.services
      .map(normalizeFrontPublicService)
      .filter(service => service.id !== '' && service.service_name !== '');

    FRONT_USING_PUBLIC_SERVICES = FRONT_PUBLIC_SERVICES.length > 0;

    if (!FRONT_USING_PUBLIC_SERVICES) {
      selectedServiceId = '';
      selectedService = null;
      return false;
    }

    FRONT_SELECTED_SERVICE_SIGNATURE = FRONT_PUBLIC_SERVICES[0]
      ? `service:${FRONT_PUBLIC_SERVICES[0].id}`
      : '';

    return true;
  } catch (error) {
    console.error('loadFrontPublicServices error:', error);
    FRONT_PUBLIC_SERVICES = [];
    FRONT_USING_PUBLIC_SERVICES = false;
    selectedServiceId = '';
    selectedService = null;
    return false;
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
  const timeEl = getEl('time');

  if (FRONT_CALENDAR_ENABLED === true) {
    if (bookBtn) {
      bookBtn.disabled = false;
      bookBtn.textContent = 'Zarezerwuj';
    }

    if (calendar) {
      calendar.classList.remove('calendar-disabled');
    }

    return;
  }

  if (bookBtn) {
    bookBtn.disabled = true;
    bookBtn.textContent = 'Kalendarz wyłączony';
  }

  if (calendar) {
    calendar.classList.add('calendar-disabled');
  }

  if (timeEl) {
    timeEl.innerHTML = '<option value="">Kalendarz wyłączony</option>';
  }

  showError('Kalendarz rezerwacji jest obecnie konfigurowany przez administratora. Spróbuj ponownie później.');
}

function getSelectedStaffId() {
  const staffEl = getEl('staff');

  return staffEl ? staffEl.value.trim() : '';
}

async function loadFrontStaff() {
  const staffBox = getEl('frontStaffBox');
  const staffEl = getEl('staff');

  FRONT_STAFF_ENABLED = false;
  FRONT_STAFF = [];
  FRONT_FILTERED_STAFF = [];
  FRONT_STAFF_REQUIRED = false;

  if (!staffBox || !staffEl) return;

  staffBox.classList.add('hidden');
  staffBox.style.display = 'none';
  staffEl.innerHTML = '<option value="">Wybierz osobę</option>';
  staffEl.disabled = true;

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

  try {
    const res = await fetch('/api/staff/public-list.php', {
      cache: 'no-store'
    });

    const data = await res.json();

    if (!res.ok || data.success !== true || data.staff_enabled !== true || !Array.isArray(data.staff) || data.staff.length === 0) {
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

function setDateLimits() {
  const dateInput = getEl('date');
  if (!dateInput) return;

  const today = new Date();
  const { minMonthDate } = getMonthRangeLimits();

  dateInput.min = formatLocalDate(minMonthDate);
  dateInput.max = formatLocalDate(getMaxAllowedDate(today));
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
    let bufferMinutesForToday = parseInt(calendarSettings.booking_buffer || 0, 10);

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

      const staffData = await staffRes.json();

      if (!staffRes.ok || staffData.success !== true || !Array.isArray(staffData.availableTimes)) {
        throw new Error(staffData.message || staffData.error || 'Nie udało się pobrać dostępności osoby');
      }

      const staffTimes = staffData.availableTimes;
      availableTimes = staffTimes;

      const staffBufferRaw = staffData.settings?.booking_buffer_minutes;
      const staffBufferMinutes = staffBufferRaw === null || staffBufferRaw === undefined || staffBufferRaw === ''
        ? null
        : parseInt(staffBufferRaw, 10);

      if (Number.isFinite(staffBufferMinutes)) {
        bufferMinutesForToday = staffBufferMinutes;
      }
    } else {
      const settings = await getSettings(selectedDate);
      availableTimes = Array.isArray(settings?.availableTimes)
        ? settings.availableTimes
        : [];
    }
      
      const todayStr = formatLocalDate(new Date());

let filteredTimes = [...availableTimes];

if (selectedDate === todayStr) {
  const now = new Date();
  const nowMinutes = now.getHours() * 60 + now.getMinutes();
  const bufferMinutes = parseInt(bufferMinutesForToday || 0, 10);
  const minAllowedMinutes = nowMinutes + bufferMinutes;

  filteredTimes = availableTimes.filter(time => {
    const [hours, minutes] = time.split(':').map(Number);
    const slotMinutes = hours * 60 + minutes;
    return slotMinutes >= minAllowedMinutes;
  });
}

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
    console.log('Załadowane settings.php:', calendarSettings, ALL_TIMES);
  } catch (e) {
    console.error('loadCalendarSettings error:', e);
  }
}

async function getSettings(date = '') {
  const res = await fetch('/api/booking/blocked.php', {
    cache: 'no-store'
  });

  if (!res.ok) {
    throw new Error('Nie udało się pobrać blokad.');
  }

  const data = await res.json();

  const blockedDates = Array.isArray(data.blockedDates) ? data.blockedDates : [];
  const blockedTimes = data.blockedTimes && typeof data.blockedTimes === 'object'
    ? data.blockedTimes
    : {};

  const availabilityExceptions = Array.isArray(data.availabilityExceptions)
    ? data.availabilityExceptions
    : [];

  const allTimes = generateTimeSlots();

  const blockSettings = data.blockSettings && typeof data.blockSettings === 'object'
    ? {
        block_saturdays: !!data.blockSettings.block_saturdays,
        block_sundays: !!data.blockSettings.block_sundays,
        block_holidays: !!data.blockSettings.block_holidays
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

      let availableCount = available.length;

      if (dateStr === formatLocalDate(today)) {
        const nowMinutes = today.getHours() * 60 + today.getMinutes();
        const bufferMinutes = parseInt(calendarSettings.booking_buffer || 0, 10);
        const minAllowedMinutes = nowMinutes + bufferMinutes;

        availableCount = available.filter(time => {
          const [hours, minutes] = time.split(':').map(Number);
          const slotMinutes = hours * 60 + minutes;
          return slotMinutes >= minAllowedMinutes;
        }).length;
      }

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
    prevBtn.onclick = async () => {
      showLoader();

      try {
        availabilityData = await getSettings();
        const newDate = new Date(viewDate.getFullYear(), viewDate.getMonth() - 1, 1);

        if (newDate >= minMonthDate) {
          viewDate = newDate;
          renderCalendarUI();
        }
      } catch (e) {
        console.error(e);
      } finally {
        hideLoader();
      }
    };
  }

  if (nextBtn) {
    nextBtn.disabled = isSameMonth(viewDate, maxMonthDate);
    nextBtn.onclick = async () => {
      showLoader();

      try {
        availabilityData = await getSettings();
        const newDate = new Date(viewDate.getFullYear(), viewDate.getMonth() + 1, 1);

       if (newDate <= maxMonthDate) {
  viewDate = newDate;
  renderCalendarUI();
}
      } catch (e) {
        console.error(e);
      } finally {
        hideLoader();
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

      const staffData = await staffRes.json();

      if (!staffRes.ok || staffData.success !== true || !Array.isArray(staffData.availableTimes)) {
        throw new Error('Nie udało się pobrać dostępności osoby');
      }

      finalAvailable = [...staffData.availableTimes];

      const staffBufferRaw = staffData.settings?.booking_buffer_minutes;
      const staffBufferMinutes = staffBufferRaw === null || staffBufferRaw === undefined || staffBufferRaw === ''
        ? null
        : parseInt(staffBufferRaw, 10);

      if (Number.isFinite(staffBufferMinutes) && staffBufferMinutes > 0 && booking.date === formatLocalDate(new Date())) {
        const now = new Date();
        const nowMinutes = now.getHours() * 60 + now.getMinutes();
        const minAllowedMinutes = nowMinutes + staffBufferMinutes;

        finalAvailable = finalAvailable.filter(time => {
          const [hours, minutes] = time.split(':').map(Number);
          const slotMinutes = hours * 60 + minutes;
          return slotMinutes >= minAllowedMinutes;
        });
      }
    } else {
      const settings = await getSettings(booking.date);
      const available = settings?.availableTimes || [];

      finalAvailable = [...available];

      const todayStr = formatLocalDate(new Date());

      if (booking.date === todayStr) {
        const now = new Date();
        const nowMinutes = now.getHours() * 60 + now.getMinutes();
        const bufferMinutes = parseInt(calendarSettings.booking_buffer || 0, 10);
        const minAllowedMinutes = nowMinutes + bufferMinutes;

        finalAvailable = available.filter(time => {
          const [hours, minutes] = time.split(':').map(Number);
          const slotMinutes = hours * 60 + minutes;
          return slotMinutes >= minAllowedMinutes;
        });
      }
    }

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
      const bookingId = result.booking_id || '';

      if (!bookingId) {
        showError('Rezerwacja została zapisana, ale nie udało się rozpocząć płatności. Skontaktuj się z obsługą.');
        return;
      }

      bookBtn.innerHTML = '⏳ Przekierowanie do płatności...';

      const payuRes = await fetch('/api/payments/payu-create-order.php', {
        method: 'POST',
        headers: {
          'Content-Type': 'application/json'
        },
        body: JSON.stringify({
          booking_id: bookingId
        })
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
      availabilityData = await getSettings();
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

document.addEventListener('DOMContentLoaded', async () => {

 const formStartedAtInput = getEl('formStartedAt');
if (formStartedAtInput) {
  formStartedAtInput.value = String(Date.now());
}
  try {
    const tenantAvailable = await loadFrontBranding();

    if (!tenantAvailable) {
      return;
    }

    await loadFrontServiceSettings();
       await loadSettings();
    applyFrontCalendarEnabledState();

    if (!FRONT_CALENDAR_ENABLED) {
      if (window.AppLoader) {
        window.AppLoader.hide();
      }
      return;
    }

    await loadCalendarSettings();
    await loadFrontStaff();
    await loadFrontLegalDocumentsLinks();

    const { minMonthDate } = getMonthRangeLimits();
    viewDate = new Date(minMonthDate.getFullYear(), minMonthDate.getMonth(), 1);

    availabilityData = await getSettings();
    ALL_TIMES = generateTimeSlots();
  } catch (e) {
    console.error(e);

    if (window.AppLoader) {
      window.AppLoader.fail('Nie udało się potwierdzić adresu kalendarza. Odśwież stronę i spróbuj ponownie.');
    }

    return;
  }

  setDateLimits();
  renderCalendarUI();

  const dateEl = getEl('date');
  const timeEl = getEl('time');
  const staffEl = getEl('staff');
  const serviceSelect = getEl('serviceSelect');

  if (dateEl) {
    dateEl.addEventListener('keydown', e => e.preventDefault());
    dateEl.addEventListener('paste', e => e.preventDefault());
    dateEl.addEventListener('drop', e => e.preventDefault());
    dateEl.addEventListener('click', () => {
      if (typeof dateEl.showPicker === 'function') {
        dateEl.showPicker();
      }
    });
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
