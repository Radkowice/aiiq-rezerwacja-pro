window.AIIQ_PUBLIC_PLAN_CONTEXT = null;

const RESCHEDULE_HOLIDAYS = [
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
  '12-26',
];

const rescheduleState = {
  token: '',
  booking: null,
  selectedDate: '',
  selectedTime: '',
  viewDate: new Date(),
  saving: false,
  canReschedule: true,
  calendarSettings: {
    booking_start_month_offset: 0,
    booking_month_range: 1,
  },
  blockData: {
    blockedDates: [],
    blockSettings: {
      block_saturdays: false,
      block_sundays: false,
      block_holidays: false,
    },
    availabilityExceptions: [],
  },
  monthAvailabilityCache: {},
  monthAvailabilityLoadingMonth: '',
  monthAvailabilityRequestId: 0,
};

function getEl(id) {
  return document.getElementById(id);
}

function getTokenFromUrl() {
  const params = new URLSearchParams(window.location.search);
  return (params.get('token') || '').trim();
}

function setHidden(el, hidden) {
  if (el) {
    el.hidden = hidden;
  }
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

function clearMessages() {
  const errorBox = getEl('errorBox');
  const successBox = getEl('successBox');

  if (errorBox) {
    errorBox.textContent = '';
    errorBox.style.display = 'none';
  }

  if (successBox) {
    successBox.textContent = '';
    successBox.style.display = 'none';
  }
}

function hideLoader() {
  if (window.AppLoader) {
    window.AppLoader.hide();
    return;
  }

  document.body.classList.remove('app-loading');
}

function getPublicPlanContext() {
  return window.AIIQ_PUBLIC_PLAN_CONTEXT || null;
}

function publicPlanHasFeature(featureKey) {
  const context = getPublicPlanContext();

  if (!context || !context.features || !Object.prototype.hasOwnProperty.call(context.features, featureKey)) {
    return true;
  }

  return context.features[featureKey] === true;
}

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

async function loadFrontBranding() {
  try {
    const res = await fetch('/api/system/branding-public.php', {
      cache: 'no-store',
    });

    const data = await res.json();

    if (!res.ok || data.success !== true || !data.branding) {
      return;
    }

    const branding = data.branding;
    window.AIIQ_PUBLIC_PLAN_CONTEXT = data.plan_context || null;

    applyCalendarFrontStyle(branding.calendar_front_style || {});

    const logoUrl = String(branding.logo_url_front || '').trim();
    const logoEl = getEl('frontLogo');

    if (logoEl && logoUrl && publicPlanHasFeature('branding_logo')) {
      logoEl.src = logoUrl;
      logoEl.alt = String(branding.client_name || 'Logo').trim() || 'Logo';
      logoEl.hidden = false;
    } else if (logoEl) {
      logoEl.removeAttribute('src');
      logoEl.hidden = true;
    }

    const faviconUrl = String(branding.favicon_url_front || '').trim();

    if (faviconUrl && publicPlanHasFeature('branding_favicon')) {
      let faviconEl = document.querySelector('link[rel="icon"]');

      if (!faviconEl) {
        faviconEl = document.createElement('link');
        faviconEl.rel = 'icon';
        document.head.appendChild(faviconEl);
      }

      faviconEl.href = faviconUrl;
    }
  } catch (error) {
    console.error('loadFrontBranding error:', error);
  }
}

function formatAmount(amount, currency) {
  if (amount === null || amount === undefined || amount === '') {
    return '';
  }

  const numericAmount = Number(amount);

  if (!Number.isFinite(numericAmount)) {
    return '';
  }

  const displayCurrency = String(currency || 'PLN').trim() || 'PLN';
  return `${numericAmount.toLocaleString('pl-PL', {
    minimumFractionDigits: 2,
    maximumFractionDigits: 2,
  })} ${displayCurrency}`;
}

function setText(id, value, fallback = '-') {
  const el = getEl(id);

  if (el) {
    el.textContent = String(value || fallback);
  }
}

function formatLocalDate(date) {
  return `${date.getFullYear()}-${String(date.getMonth() + 1).padStart(2, '0')}-${String(date.getDate()).padStart(2, '0')}`;
}

function formatMonthKey(date) {
  return `${date.getFullYear()}-${String(date.getMonth() + 1).padStart(2, '0')}`;
}

function getMonthStart(date) {
  return new Date(date.getFullYear(), date.getMonth(), 1);
}

function addMonths(date, amount) {
  return new Date(date.getFullYear(), date.getMonth() + amount, 1);
}

function getMonthRangeLimits() {
  const today = new Date();
  today.setHours(0, 0, 0, 0);

  const startOffset = Math.max(0, parseInt(rescheduleState.calendarSettings.booking_start_month_offset || 0, 10));
  const monthRange = Math.max(1, parseInt(rescheduleState.calendarSettings.booking_month_range || 1, 10));

  const minMonthDate = new Date(today.getFullYear(), today.getMonth() + startOffset, 1);
  const maxMonthDate = new Date(minMonthDate.getFullYear(), minMonthDate.getMonth() + monthRange - 1, 1);
  const maxAllowedDate = new Date(maxMonthDate.getFullYear(), maxMonthDate.getMonth() + 1, 0);
  const minAllowedDate = new Date(Math.max(today.getTime(), minMonthDate.getTime()));

  minAllowedDate.setHours(0, 0, 0, 0);
  maxAllowedDate.setHours(23, 59, 59, 999);

  return { minMonthDate, maxMonthDate, minAllowedDate, maxAllowedDate };
}

function isGlobalRuleBlocked(dateStr, blockSettings = {}) {
  const date = new Date(`${dateStr}T00:00:00`);
  const dayOfWeek = date.getDay();
  const mmdd = dateStr.slice(5);

  if (blockSettings.block_saturdays && dayOfWeek === 6) return true;
  if (blockSettings.block_sundays && dayOfWeek === 0) return true;
  if (blockSettings.block_holidays && RESCHEDULE_HOLIDAYS.includes(mmdd)) return true;

  return false;
}

function isDateWithinAllowedRange(date) {
  const { minAllowedDate, maxAllowedDate } = getMonthRangeLimits();
  const selected = new Date(date.getFullYear(), date.getMonth(), date.getDate());

  return selected >= minAllowedDate && selected <= maxAllowedDate;
}

function isDateBlocked(dateStr) {
  const blockData = rescheduleState.blockData || {};
  const blockedDates = Array.isArray(blockData.blockedDates) ? blockData.blockedDates : [];
  const exceptions = Array.isArray(blockData.availabilityExceptions) ? blockData.availabilityExceptions : [];
  const blockSettings = blockData.blockSettings && typeof blockData.blockSettings === 'object'
    ? blockData.blockSettings
    : {};

  if (exceptions.includes(dateStr)) {
    return false;
  }

  return blockedDates.includes(dateStr) || isGlobalRuleBlocked(dateStr, blockSettings);
}

async function loadRescheduleCalendarSettings() {
  try {
    const res = await fetch('/api/system/service-settings-public.php', {
      cache: 'no-store',
    });
    const data = await res.json();
    const service = data && data.success === true && data.service ? data.service : null;

    if (service) {
      rescheduleState.calendarSettings = {
        booking_start_month_offset: parseInt(service.booking_start_month_offset || 0, 10),
        booking_month_range: parseInt(service.booking_month_range || 1, 10),
      };
    }
  } catch (error) {
    console.error('loadRescheduleCalendarSettings error:', error);
  }
}

async function loadRescheduleBlockData(booking) {
  const staff = booking && booking.staff ? booking.staff : null;
  const params = new URLSearchParams();
  const staffRef = staff
    ? String(staff.staff_ref || staff.staffRef || staff.id || '').trim()
    : '';

  if (staffRef) {
    params.set('staff_ref', staffRef);
  }

  const url = params.toString() ? `/api/booking/blocked.php?${params.toString()}` : '/api/booking/blocked.php';

  try {
    const res = await fetch(url, {
      cache: 'no-store',
    });
    const data = await res.json();

    if (!res.ok || data.success !== true) {
      throw new Error(data.error || data.message || 'Nie udało się pobrać blokad.');
    }

    rescheduleState.blockData = {
      blockedDates: Array.isArray(data.blockedDates) ? data.blockedDates : [],
      blockSettings: data.blockSettings && typeof data.blockSettings === 'object'
        ? {
          block_saturdays: !!data.blockSettings.block_saturdays,
          block_sundays: !!data.blockSettings.block_sundays,
          block_holidays: !!data.blockSettings.block_holidays,
        }
        : {
          block_saturdays: false,
          block_sundays: false,
          block_holidays: false,
        },
      availabilityExceptions: Array.isArray(data.availabilityExceptions) ? data.availabilityExceptions : [],
    };
  } catch (error) {
    console.error('loadRescheduleBlockData error:', error);
    showError('Nie udało się pobrać blokad kalendarza. Odśwież stronę i spróbuj ponownie.');
  }
}

async function loadMonthAvailability(monthKey) {
  if (!monthKey || !rescheduleState.token) {
    return false;
  }

  if (rescheduleState.monthAvailabilityCache[monthKey]) {
    return true;
  }

  const requestId = rescheduleState.monthAvailabilityRequestId + 1;
  rescheduleState.monthAvailabilityRequestId = requestId;
  rescheduleState.monthAvailabilityLoadingMonth = monthKey;

  try {
    const params = new URLSearchParams({
      month: monthKey,
      token: rescheduleState.token,
    });
    const res = await fetch(`/api/booking/availability-month.php?${params.toString()}`, {
      cache: 'no-store',
    });
    const data = await res.json();

    if (requestId !== rescheduleState.monthAvailabilityRequestId) {
      return false;
    }

    if (!res.ok || data.success !== true || !data.days || typeof data.days !== 'object') {
      throw new Error(data.message || 'Nie udało się pobrać dostępności kalendarza.');
    }

    rescheduleState.monthAvailabilityCache[monthKey] = data.days;
    return true;
  } catch (error) {
    if (requestId === rescheduleState.monthAvailabilityRequestId) {
      console.error('loadMonthAvailability error:', error);
      showError('Nie udało się pobrać dostępności kalendarza. Odśwież stronę i spróbuj ponownie.');
    }
    return false;
  } finally {
    if (requestId === rescheduleState.monthAvailabilityRequestId) {
      rescheduleState.monthAvailabilityLoadingMonth = '';
    }
  }
}

function setTimeOptions(options, placeholder = 'Wybierz godzinę') {
  const timeEl = getEl('time');

  if (!timeEl) return;

  timeEl.innerHTML = '';

  const empty = document.createElement('option');
  empty.value = '';
  empty.textContent = placeholder;
  timeEl.appendChild(empty);

  options.forEach((time) => {
    const option = document.createElement('option');
    option.value = time;
    option.textContent = time;
    timeEl.appendChild(option);
  });

  timeEl.disabled = options.length === 0;
}

function updateSubmitState() {
  const btn = getEl('rescheduleBtn');

  if (!btn) return;

  btn.disabled = !rescheduleState.canReschedule || rescheduleState.saving || !rescheduleState.selectedDate || !rescheduleState.selectedTime;
  btn.textContent = rescheduleState.saving ? 'Zapisywanie...' : 'Zmień rezerwację';
}

function renderCalendar() {
  const container = getEl('calendar');
  const dateInput = getEl('date');

  if (!container) return;

  const today = new Date();
  today.setHours(0, 0, 0, 0);

  const { minMonthDate, maxMonthDate } = getMonthRangeLimits();

  if (getMonthStart(rescheduleState.viewDate) < minMonthDate) {
    rescheduleState.viewDate = minMonthDate;
  }

  if (getMonthStart(rescheduleState.viewDate) > maxMonthDate) {
    rescheduleState.viewDate = maxMonthDate;
  }

  const view = getMonthStart(rescheduleState.viewDate);
  const year = view.getFullYear();
  const month = view.getMonth();
  const monthKey = formatMonthKey(view);
  const monthAvailability = rescheduleState.monthAvailabilityCache[monthKey] || null;

  if (!monthAvailability && rescheduleState.monthAvailabilityLoadingMonth !== monthKey) {
    loadMonthAvailability(monthKey).then((loaded) => {
      if (loaded && formatMonthKey(getMonthStart(rescheduleState.viewDate)) === monthKey) {
        renderCalendar();
      }
    });
  }

  const monthNames = [
    'Styczeń', 'Luty', 'Marzec', 'Kwiecień', 'Maj', 'Czerwiec',
    'Lipiec', 'Sierpień', 'Wrzesień', 'Październik', 'Listopad', 'Grudzień',
  ];

  let firstDay = new Date(year, month, 1).getDay();
  firstDay = firstDay === 0 ? 6 : firstDay - 1;
  const daysInMonth = new Date(year, month + 1, 0).getDate();

  container.innerHTML = '';

  const header = document.createElement('div');
  header.className = 'calendar-header';

  const prev = document.createElement('button');
  prev.type = 'button';
  prev.textContent = '‹';
  prev.disabled = year === minMonthDate.getFullYear() && month === minMonthDate.getMonth();
  prev.addEventListener('click', () => {
    rescheduleState.viewDate = addMonths(rescheduleState.viewDate, -1);
    renderCalendar();
  });

  const title = document.createElement('div');
  title.className = 'month-title';
  title.textContent = `${monthNames[month]} ${year}`;

  const next = document.createElement('button');
  next.type = 'button';
  next.textContent = '›';
  next.disabled = year === maxMonthDate.getFullYear() && month === maxMonthDate.getMonth();
  next.addEventListener('click', () => {
    rescheduleState.viewDate = addMonths(rescheduleState.viewDate, 1);
    renderCalendar();
  });

  header.append(prev, title, next);
  container.appendChild(header);

  const weekdays = document.createElement('div');
  weekdays.className = 'calendar-weekdays';
  ['Pn', 'Wt', 'Śr', 'Cz', 'Pt', 'So', 'Nd'].forEach((day) => {
    const el = document.createElement('div');
    el.textContent = day;
    weekdays.appendChild(el);
  });
  container.appendChild(weekdays);

  const grid = document.createElement('div');
  grid.className = 'calendar-grid';

  for (let i = 0; i < firstDay; i += 1) {
    const empty = document.createElement('div');
    empty.className = 'day empty';
    grid.appendChild(empty);
  }

  for (let day = 1; day <= daysInMonth; day += 1) {
    const date = new Date(year, month, day);
    const dateStr = formatLocalDate(date);
    const button = document.createElement('div');
    button.className = 'day';
    button.dataset.date = dateStr;
    button.textContent = String(day);

    const dayAvailability = monthAvailability && monthAvailability[dateStr] ? monthAvailability[dateStr] : null;
    const hasAvailableTimes = dayAvailability && dayAvailability.available === true && Number(dayAvailability.times_count || 0) > 0;
    const disabled = !rescheduleState.canReschedule
      || date < today
      || !isDateWithinAllowedRange(date)
      || !hasAvailableTimes;

    if (disabled) {
      button.classList.add('disabled');
    } else {
      button.classList.add('available');
      button.addEventListener('click', () => selectDate(dateStr));
    }

    if (dateStr === rescheduleState.selectedDate) {
      button.classList.add('selected');
    }

    grid.appendChild(button);
  }

  container.appendChild(grid);

  if (dateInput) {
    dateInput.value = rescheduleState.selectedDate;
  }
}

async function loadAvailableTimes(date) {
  const res = await fetch(`/api/booking/reschedule.php?token=${encodeURIComponent(rescheduleState.token)}&date=${encodeURIComponent(date)}`, {
    cache: 'no-store',
  });

  const data = await res.json();

  if (!res.ok || data.success !== true || !Array.isArray(data.availableTimes)) {
    throw new Error(data.message || 'Nie udało się pobrać dostępnych godzin.');
  }

  if (data.availableTimes.length === 0) {
    setTimeOptions([], 'Brak dostępnych godzin');
    showSuccess('W wybranym dniu nie ma dostępnych godzin. Wybierz inną datę.');
    return;
  }

  setTimeOptions(data.availableTimes, 'Wybierz godzinę');
}

async function selectDate(date) {
  rescheduleState.selectedDate = date;
  rescheduleState.selectedTime = '';
  clearMessages();
  renderCalendar();
  setTimeOptions([], 'Ładowanie godzin...');
  updateSubmitState();

  try {
    await loadAvailableTimes(date);
  } catch (error) {
    setTimeOptions([], 'Nie udało się pobrać godzin');
    showError(error.message || 'Nie udało się pobrać dostępnych godzin.');
  }
}

function renderBooking(booking) {
  const content = getEl('rescheduleContent');
  const service = booking.service || {};
  const staff = booking.staff || null;
  const serviceDescription = String(service.description || '').trim();
  const paymentRequired = booking.payment_required === true;
  const amountText = paymentRequired
    ? formatAmount(booking.payment_amount ?? service.price_amount, booking.payment_currency || service.price_currency)
    : '';
  const paymentStatusText = paymentRequired ? (booking.payment_status_label || 'Nie dotyczy') : '';
  const currentDate = booking.current_date_label || booking.current_date || '';
  const currentTime = booking.current_time_label || booking.current_time || '';

  rescheduleState.booking = booking;
  rescheduleState.monthAvailabilityCache = {};
  rescheduleState.monthAvailabilityLoadingMonth = '';
  rescheduleState.monthAvailabilityRequestId += 1;

  setText('rescheduleServiceName', service.name || 'Usługa');
  setText('rescheduleServiceDescription', serviceDescription, '');
  setText('rescheduleAmount', amountText, '');
  setText('reschedulePaymentStatus', paymentStatusText, '');
  setText('rescheduleStaffName', staff && staff.display_name ? staff.display_name : 'Brak przypisanej osoby obsługującej');
  setText('rescheduleCustomerName', booking.customer_name);
  setText('rescheduleCustomerEmail', booking.customer_email);
  setText('rescheduleCustomerPhone', booking.customer_phone);
  setText('rescheduleCurrentDateTime', `${currentDate} ${currentTime}`.trim());

  setHidden(getEl('rescheduleServiceDescriptionRow'), serviceDescription === '');
  setHidden(getEl('rescheduleAmountRow'), amountText === '');
  setHidden(getEl('reschedulePaymentStatusRow'), !paymentRequired);

  if (content) {
    content.hidden = false;
  }

  renderCalendar();
  setTimeOptions([], 'Najpierw wybierz datę');
  updateSubmitState();
}

async function loadBooking(token) {
  const res = await fetch(`/api/booking/reschedule.php?token=${encodeURIComponent(token)}`, {
    cache: 'no-store',
  });

  const data = await res.json();

  if (!res.ok || data.success !== true) {
    throw new Error(data.message || 'Nie udało się wczytać danych rezerwacji.');
  }

  const booking = data.booking || {};
  rescheduleState.canReschedule = data.can_reschedule !== false;
  await loadRescheduleCalendarSettings();
  renderBooking(booking);

  if (!rescheduleState.canReschedule) {
    setTimeOptions([], 'Zmiana terminu niedostępna');
    showError(data.message || 'Nie możesz już samodzielnie zmienić terminu tej rezerwacji. Skontaktuj się z obsługą.');
    updateSubmitState();
    return;
  }

  showSuccess('Dane rezerwacji zostały wczytane. Wybierz nowy termin.');
}

async function submitReschedule() {
  if (rescheduleState.saving || !rescheduleState.selectedDate || !rescheduleState.selectedTime) {
    return;
  }

  clearMessages();
  rescheduleState.saving = true;
  updateSubmitState();

  try {
    const res = await fetch('/api/booking/reschedule.php', {
      method: 'POST',
      headers: {
        'Content-Type': 'application/json',
        Accept: 'application/json',
      },
      body: JSON.stringify({
        token: rescheduleState.token,
        date: rescheduleState.selectedDate,
        time: rescheduleState.selectedTime,
      }),
    });

    const data = await res.json();

    if (!res.ok || data.success !== true) {
      throw new Error(data.message || 'Nie udało się zmienić terminu rezerwacji.');
    }

    rescheduleState.canReschedule = data.can_reschedule !== false;
    renderBooking(data.booking || {});
    rescheduleState.selectedDate = '';
    rescheduleState.selectedTime = '';
    setTimeOptions([], 'Najpierw wybierz datę');
    renderCalendar();
    showSuccess('Termin rezerwacji został zmieniony.');
  } catch (error) {
    showError(error.message || 'Nie udało się zmienić terminu rezerwacji.');
  } finally {
    rescheduleState.saving = false;
    updateSubmitState();
  }
}

document.addEventListener('DOMContentLoaded', async () => {
  const token = getTokenFromUrl();
  rescheduleState.token = token;

  const timeEl = getEl('time');
  const button = getEl('rescheduleBtn');

  if (timeEl) {
    timeEl.addEventListener('change', () => {
      rescheduleState.selectedTime = timeEl.value || '';
      updateSubmitState();
    });
  }

  if (button) {
    button.addEventListener('click', submitReschedule);
  }

  try {
    await loadFrontBranding();

    if (!token) {
      showError('Brak tokenu rezerwacji. Otwórz link z wiadomości e-mail.');
      return;
    }

    if (!publicPlanHasFeature('reschedule_booking')) {
      showError('Funkcja przełożenia rezerwacji jest dostępna w wyższych planach.');
      return;
    }

    await loadBooking(token);
  } catch (error) {
    showError(error.message || 'Nie udało się wczytać danych rezerwacji.');
  } finally {
    hideLoader();
  }
});
