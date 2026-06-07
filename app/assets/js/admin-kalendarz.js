(() => {
  let calendarSettings = null;

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

  let adminViewDate = new Date();
  let selectedAdminDate = null;
  let blockScope = 'global';
  let selectedBlockStaffId = '';
  let blockStaff = [];
  let staffWorkingHours = [];
  let staffWorkingHoursDate = '';
  let adminServices = [];
  let adminServicesLoaded = false;
  let adminCalendarBookings = [];
  let adminCalendarBookingsLoaded = false;
  let staffAvailability = [];
  let staffAvailabilityLoadedFor = '';

  let blockSettings = {
    block_saturdays: false,
    block_sundays: false,
    block_holidays: false
  };

  let blockedData = {
    blockedDates: [],
    blockedTimes: {},
    globalBlockedDates: [],
    staffBlockedDates: [],
    globalBlockedTimes: {},
    staffBlockedTimes: {},
    blockedDateScopes: {},
    blockedTimeScopes: {},
    workingHours: [],
    availabilityExceptions: [],
    globalAvailabilityExceptions: [],
    staffAvailabilityExceptions: []
  };

  document.addEventListener('DOMContentLoaded', async () => {
    if (!document.getElementById('adminCalendar')) return;

    initCalendarControls();
    setRangeMinDates();

    try {
      await initAdminCalendarAfterAuth();
    } catch (error) {
      console.error('initAdminCalendar error:', error);
      showMessage(error.message || 'Nie udało się uruchomić kalendarza admina', 'error');
    }
  });

  function delay(ms) {
    return new Promise(resolve => window.setTimeout(resolve, ms));
  }

  function createHttpError(message, response) {
    const error = new Error(message);
    error.status = response?.status || 0;
    return error;
  }

  function isStartupAuthError(error) {
    return error && (error.status === 401 || error.status === 403);
  }

  async function waitForAdminAccountDataReady() {
    const ready = window.adminAccountDataReady;

    if (ready && typeof ready.then === 'function') {
      try {
        await ready;
      } catch (error) {
        console.warn('adminAccountDataReady error before blocks init:', error);
      }

      return;
    }

    await delay(100);

    const lateReady = window.adminAccountDataReady;
    if (lateReady && typeof lateReady.then === 'function') {
      try {
        await lateReady;
      } catch (error) {
        console.warn('late adminAccountDataReady error before blocks init:', error);
      }
    }
  }

  async function initAdminCalendarAfterAuth() {
    await waitForAdminAccountDataReady();

    try {
      await initAdminCalendar();
    } catch (error) {
      if (!isStartupAuthError(error)) {
        throw error;
      }

      await delay(600);
      await waitForAdminAccountDataReady();
      await initAdminCalendar();
    }
  }

  async function initAdminCalendar() {
  await loadBlockStaffOptions();

  if (hasActiveBlockStaff()) {
    await loadAdminServicesForBlockMode();
  }

  const settingsReady = await loadCalendarSettings();

  if (!settingsReady) {
    renderCalendarNotConfiguredState();
    return;
  }

  await refreshAdminCalendarData();

  if (!selectedAdminDate) {
    selectedAdminDate = getFirstSelectableDateInCurrentMonth();
  }

  await renderAdminCalendar();
  await renderAdminTimeSlots();
}

  async function loadCalendarSettings() {
  const res = await fetch('/api/system/settings.php', {
    cache: 'no-store',
    credentials: 'include'
  });

  if (!res.ok) {
      throw createHttpError('Nie udało się pobrać blokad', res);
  }

  const data = await res.json();

  if (!data.success || !data.settings) {
    return false;
  }

  const settings = data.settings;

  const workStart = String(settings.work_start || '').trim();
  const workEnd = String(settings.work_end || '').trim();
  const duration = parseInt(settings.consultation_duration, 10);
  const breakTime = parseInt(settings.consultation_break ?? 0, 10);
  const bookingBuffer = parseInt(settings.booking_buffer ?? 0, 10);

  if (!isValidTimeString(workStart) || !isValidTimeString(workEnd)) {
    return false;
  }

  if (!Number.isFinite(duration) || duration <= 0) {
    return false;
  }

  if (!Number.isFinite(breakTime) || breakTime < 0) {
    return false;
  }

  calendarSettings = {
    work_start: workStart,
    work_end: workEnd,
    consultation_duration: duration,
    consultation_break: breakTime,
    booking_buffer: Number.isFinite(bookingBuffer) && bookingBuffer >= 0 ? bookingBuffer : 0
  };

  return true;
}

  function initCalendarControls() {
    const blockSaturdaysEl = document.getElementById('block-saturdays');
    const blockSundaysEl = document.getElementById('block-sundays');
    const blockHolidaysEl = document.getElementById('block-holidays');
    const rangeBtn = document.getElementById('block-range-btn');
    const unblockRangeBtn = document.getElementById('unblock-range-btn');
    const rangeFrom = document.getElementById('range-from');
    const refreshBtn = document.getElementById('admin-block-refresh-btn');
    const blockStaffSelect = document.getElementById('block-staff-select');
    const blockStaffSelectWrap = document.getElementById('block-staff-select-wrap');
    syncBlockSettingsVisibility();
    syncReservationLegendVisibility();

    document.querySelectorAll('input[name="block-scope"]').forEach((input) => {
      input.addEventListener('change', async () => {
        if (!input.checked) return;

        blockScope = input.value === 'staff' ? 'staff' : 'global';

        if (blockStaffSelectWrap) {
          blockStaffSelectWrap.hidden = blockScope !== 'staff';
        }

        if (blockScope === 'global') {
          selectedBlockStaffId = '';
          staffWorkingHours = [];
          staffWorkingHoursDate = '';
          staffAvailability = [];
          staffAvailabilityLoadedFor = '';
          if (blockStaffSelect) blockStaffSelect.value = '';
        } else {
          selectedBlockStaffId = blockStaffSelect.value || '';
          staffWorkingHours = [];
          staffWorkingHoursDate = '';
          staffAvailability = [];
          staffAvailabilityLoadedFor = '';
        }

        syncReservationLegendVisibility();
        await refreshAdminCalendarData();
        await renderAdminCalendar();
        await renderAdminTimeSlots();
      });
    });

    if (blockStaffSelect) {
      blockStaffSelect.addEventListener('change', async () => {
        selectedBlockStaffId = blockStaffSelect.value || '';
        staffWorkingHours = [];
        staffWorkingHoursDate = '';
        staffAvailability = [];
        staffAvailabilityLoadedFor = '';
        syncReservationLegendVisibility();
        await refreshAdminCalendarData();
        await renderAdminCalendar();
        await renderAdminTimeSlots();
      });
    }

    if (refreshBtn) {
      refreshBtn.addEventListener('click', async () => {
        await refreshAdminBlocksView(refreshBtn);
      });
    }

    if (unblockRangeBtn) {
      unblockRangeBtn.addEventListener('click', async () => {
        await handleUnblockRange();
      });
    }

    if (blockSaturdaysEl) {
      blockSaturdaysEl.addEventListener('change', () => {
                handleGlobalSettingChange(
          'block_saturdays',
          blockSaturdaysEl.checked,
          blockSaturdaysEl,
          'Blokada sobót',
          blockSaturdaysEl.checked
            ? 'Zablokować wszystkie soboty w tym roku?'
            : 'Odblokować wszystkie soboty w tym roku?'
        );
      });
    }

    if (blockSundaysEl) {
      blockSundaysEl.addEventListener('change', () => {
                handleGlobalSettingChange(
          'block_sundays',
          blockSundaysEl.checked,
          blockSundaysEl,
          'Blokada niedziel',
          blockSundaysEl.checked
            ? 'Zablokować wszystkie niedziele w tym roku?'
            : 'Odblokować wszystkie niedziele w tym roku?'
        );
      });
    }

    if (blockHolidaysEl) {
      blockHolidaysEl.addEventListener('change', () => {
                handleGlobalSettingChange(
          'block_holidays',
          blockHolidaysEl.checked,
          blockHolidaysEl,
          'Blokada świąt',
          blockHolidaysEl.checked
            ? 'Zablokować wszystkie święta w tym roku?'
            : 'Odblokować wszystkie święta w tym roku?'
        );
      });
    }

    if (rangeBtn) {
      rangeBtn.addEventListener('click', async () => {
        await handleBlockRange();
      });
    }

    if (rangeFrom) {
      rangeFrom.addEventListener('change', syncRangeLimits);
    }
  }

  async function handleGlobalSettingChange(key, newValue, checkboxEl, title, text) {
    checkboxEl.checked = !newValue;

    showConfirmModal({
      title,
      text,
      onConfirm: async () => {
        try {
          checkboxEl.checked = newValue;

          const nextSettings = {
            ...blockSettings,
            [key]: newValue
          };

          await saveBlockSettings(nextSettings);
          blockSettings = nextSettings;

          await refreshAdminBlocksViewAfterSettingsSave();

          showMessage('Zapisano ustawienia blokad.', 'success');
        } catch (error) {
          console.error('handleGlobalSettingChange error:', error);
          checkboxEl.checked = !newValue;
          showMessage(error.message || 'Nie udało się zapisać ustawień blokad', 'error');
        }
      }
    });
  }

  async function refreshAdminCalendarData() {
    await loadBlockedData();
    updateBlockedStats();
  }

  async function refreshAdminBlocksViewAfterSettingsSave() {
    staffWorkingHoursDate = '';
    staffWorkingHours = [];
    staffAvailabilityLoadedFor = '';
    staffAvailability = [];

    await refreshAdminCalendarData();
    await renderAdminCalendar();
    await renderAdminTimeSlots();
  }

  async function refreshAdminBlocksView(button = null) {
    const defaultText = button ? button.textContent : '';
    const startTime = Date.now();

    try {
      if (button) {
        button.disabled = true;
        button.textContent = 'Odświeżanie...';
      }

      adminCalendarBookingsLoaded = false;
      adminCalendarBookings = [];
      staffWorkingHoursDate = '';
      staffWorkingHours = [];
      staffAvailabilityLoadedFor = '';
      staffAvailability = [];

      await refreshAdminBlocksAfterMutation();
      showMessage('Odświeżono dane blokad.', 'success');

      if (button) {
        button.textContent = 'Odświeżono';
      }
    } catch (error) {
      console.error('refreshAdminBlocksView error:', error);
      showMessage(error.message || 'Nie udało się odświeżyć blokad.', 'error');

      if (button) {
        button.textContent = 'Błąd';
      }
    } finally {
      if (button) {
        if (typeof finishButtonState === 'function') {
          finishButtonState(button, defaultText, startTime, 900);
        } else {
          window.setTimeout(() => {
            button.disabled = false;
            button.textContent = defaultText || 'Odśwież';
          }, 900);
        }
      }
    }
  }

  window.refreshAdminCalendarData = refreshAdminCalendarData;

  function dispatchStaffBlocksChangedEvent() {
    if (blockScope !== 'staff' || typeof window.dispatchEvent !== 'function') {
      return;
    }

    window.dispatchEvent(new CustomEvent('aiiq:staff-blocks-changed'));
  }

  async function refreshAdminBlocksAfterMutation() {
    adminCalendarBookingsLoaded = false;
    adminCalendarBookings = [];
    staffWorkingHoursDate = '';
    staffWorkingHours = [];
    staffAvailabilityLoadedFor = '';
    staffAvailability = [];

    await refreshAdminCalendarData();
    await renderAdminCalendar();
    await renderAdminTimeSlots();
    dispatchStaffBlocksChangedEvent();
  }

  async function loadBlockStaffOptions() {
    const select = document.getElementById('block-staff-select');
    if (!select) return;

    try {
      const res = await fetch('/api/staff/list.php', {
        cache: 'no-store',
        credentials: 'include'
      });

      if (!res.ok) {
        throw new Error('Nie udało się pobrać pracowników');
      }

      const data = await res.json();
      blockStaff = Array.isArray(data.staff)
        ? data.staff.filter(person => person && person.is_active !== false)
        : [];

      select.innerHTML = '<option value="">Wybierz pracownika</option>'
        + blockStaff.map(person => {
          const name = person.display_name || 'Pracownik bez nazwy';
          return '<option value="' + escapeHtmlAttr(person.id || '') + '">' + escapeHtmlText(name) + '</option>';
        }).join('');

      if (selectedBlockStaffId && blockStaff.some(person => person.id === selectedBlockStaffId)) {
        select.value = selectedBlockStaffId;
      } else {
        selectedBlockStaffId = '';
      }
      syncReservationLegendVisibility();
    } catch (error) {
      console.error('loadBlockStaffOptions error:', error);
      showMessage(error.message || 'Nie udało się wczytać pracowników', 'error');
    }
  }
  
  function renderCalendarNotConfiguredState() {
  const calendarContainer = document.getElementById('adminCalendar');
  const timeSlotsContainer = document.getElementById('adminTimeSlots');

  const html = `
    <div class="calendar-empty-state">
      <div class="calendar-empty-icon"></div>
      <h3>Najpierw skonfiguruj kalendarz</h3>
      <p>
        Aby zarządzać blokadami terminów, ustaw dni pracy, godziny dostępności
        oraz długość wizyty w zakładce <strong>Ustawienia</strong>.
      </p>
      <p class="calendar-empty-note">
        Po zapisaniu ustawień kalendarza wróć do zakładki Blokady i odśwież - wtedy pojawią się dni i godziny do blokowania.
      </p>
    </div>
  `;

  if (calendarContainer) {
    calendarContainer.innerHTML = html;
  }

  if (timeSlotsContainer) {
    timeSlotsContainer.innerHTML = '';
  }

  updateBlockedStats();
}

  async function loadBlockedData() {
    const params = new URLSearchParams();

    if (blockScope === 'staff' && selectedBlockStaffId) {
      params.set('staff_id', selectedBlockStaffId);
    }

    const url = params.toString()
      ? '/api/booking/blocked.php?' + params.toString()
      : '/api/booking/blocked.php';

    const res = await fetch(url, {
      cache: 'no-store',
      credentials: 'include'
    });

    if (!res.ok) {
      throw createHttpError('Nie udało się pobrać blokad', res);
    }

    const data = await res.json();
    if (!data.success) {
      throw new Error(data.error || 'Nie udało się pobrać blokad');
    }

    blockedData = {
      blockedDates: normalizeBlockedDates(data),
      blockedTimes: data.blockedTimes && typeof data.blockedTimes === 'object' ? data.blockedTimes : {},
      globalBlockedDates: Array.isArray(data.globalBlockedDates) ? data.globalBlockedDates : [],
      staffBlockedDates: Array.isArray(data.staffBlockedDates) ? data.staffBlockedDates : [],
      globalBlockedTimes: data.globalBlockedTimes && typeof data.globalBlockedTimes === 'object'
        ? data.globalBlockedTimes
        : {},
      staffBlockedTimes: data.staffBlockedTimes && typeof data.staffBlockedTimes === 'object'
        ? data.staffBlockedTimes
        : {},
      blockedDateScopes: data.blockedDateScopes && typeof data.blockedDateScopes === 'object'
        ? data.blockedDateScopes
        : {},
      blockedTimeScopes: data.blockedTimeScopes && typeof data.blockedTimeScopes === 'object'
        ? data.blockedTimeScopes
        : {},
      workingHours: Array.isArray(data.workingHours) && data.workingHours.length
        ? data.workingHours
        : generateTimeSlots(),
      availabilityExceptions: Array.isArray(data.globalAvailabilityExceptions)
        ? data.globalAvailabilityExceptions
        : (Array.isArray(data.availabilityExceptions) ? data.availabilityExceptions : []),
      globalAvailabilityExceptions: Array.isArray(data.globalAvailabilityExceptions)
        ? data.globalAvailabilityExceptions
        : (Array.isArray(data.availabilityExceptions) ? data.availabilityExceptions : []),
      staffAvailabilityExceptions: Array.isArray(data.staffAvailabilityExceptions)
        ? data.staffAvailabilityExceptions
        : []
    };

    blockSettings = data.blockSettings && typeof data.blockSettings === 'object'
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
    syncBlockSettingsUI();
    syncBlockSettingsVisibility();
  }

  async function loadStaffWorkingHoursForDate(date) {
    if (blockScope !== 'staff' || !selectedBlockStaffId || !date) {
      staffWorkingHours = [];
      staffWorkingHoursDate = '';
      return;
    }

    if (staffWorkingHoursDate === date) {
      return;
    }

    staffWorkingHours = [];
    staffWorkingHoursDate = date;

    try {
      const params = new URLSearchParams({
        staff_id: selectedBlockStaffId,
        date,
        mode: 'slots'
      });

      const res = await fetch('/api/staff/availability.php?' + params.toString(), {
        cache: 'no-store',
        credentials: 'include'
      });

      const data = await res.json().catch(() => null);

      if (!res.ok || !data || data.success !== true) {
        throw new Error(data?.error || 'Nie udało się pobrać grafiku pracownika');
      }

      staffWorkingHours = Array.isArray(data.workingHours) ? data.workingHours : [];
    } catch (error) {
      console.error('loadStaffWorkingHoursForDate error:', error);
      staffWorkingHours = [];
      staffWorkingHoursDate = '';
    }
  }

  async function loadAdminServices() {
    if (adminServicesLoaded) {
      return;
    }

    const res = await fetch('/api/services/list.php', {
      cache: 'no-store',
      credentials: 'include'
    });

    const data = await res.json().catch(() => null);

    if (!res.ok || !data || data.success !== true) {
      throw new Error(data?.error || 'Nie udało się pobrać usług');
    }

    adminServices = Array.isArray(data.services) ? data.services : [];
    adminServicesLoaded = true;
  }

  async function loadAdminServicesForBlockMode() {
    try {
      await loadAdminServices();
    } catch (error) {
      console.error('loadAdminServicesForBlockMode error:', error);
      adminServices = [];
      adminServicesLoaded = true;
    }
  }

  async function loadStaffAvailabilityForDate(date) {
    if (blockScope !== 'staff' || !selectedBlockStaffId || !date) {
      staffAvailability = [];
      staffAvailabilityLoadedFor = '';
      return;
    }

    const cacheKey = `${selectedBlockStaffId}|${date}`;
    if (staffAvailabilityLoadedFor === cacheKey) {
      return;
    }

    const params = new URLSearchParams({
      staff_id: selectedBlockStaffId
    });

    const res = await fetch('/api/staff/availability.php?' + params.toString(), {
      cache: 'no-store',
      credentials: 'include'
    });

    const data = await res.json().catch(() => null);

    if (!res.ok || !data || data.success !== true) {
      throw new Error(data?.error || 'Nie udało się pobrać grafiku pracownika');
    }

    staffAvailability = Array.isArray(data.availability) ? data.availability : [];
    staffAvailabilityLoadedFor = cacheKey;
  }

  async function getAdminStaffServiceCards(date) {
    await loadAdminServices();
    await loadStaffAvailabilityForDate(date);
    await loadAdminCalendarBookings();

    const staffId = normalizeBookingStaffId(selectedBlockStaffId);
    const staff = getSelectedBlockStaff();
    const assignedServices = adminServices
      .filter(service => service && Array.isArray(service.staff_ids) && service.staff_ids.some(id => normalizeBookingStaffId(id) === staffId))
      .filter(service => service && service.is_active !== false);

    const services = assignedServices.map(service => {
      const settings = getEffectiveServiceSettings(service, staff);
      return {
        id: String(service.id || ''),
        name: String(service.name || 'Usługa'),
        duration: settings.duration,
        break: settings.break,
        buffer: settings.buffer,
        slots: generateServiceSlotsForDate(date, settings)
      };
    });

    const settingsByServiceId = new Map();
    services.forEach(service => {
      if (service.id) {
        settingsByServiceId.set(normalizeServiceId(service.id), {
          duration: service.duration,
          break: service.break,
          buffer: service.buffer
        });
      }
    });

    const fallbackSettings = getEffectiveServiceSettings(null, staff);
    const busyIntervals = buildAdminStaffBusyIntervals(date, settingsByServiceId, fallbackSettings);

    return services.map(service => ({
      ...service,
      slots: service.slots.map(time => getAdminServiceSlot(date, time, service, busyIntervals))
    }));
  }

  async function loadAdminCalendarBookings() {
    if (adminCalendarBookingsLoaded) {
      return;
    }

    const res = await fetch('/api/booking/bookings.php?view=all', {
      cache: 'no-store',
      credentials: 'include'
    });

    const data = await res.json().catch(() => null);

    if (!res.ok || !Array.isArray(data)) {
      throw new Error('Nie udało się pobrać rezerwacji do kalendarza');
    }

    adminCalendarBookings = data;
    adminCalendarBookingsLoaded = true;
  }

  function getSelectedBlockStaff() {
    return blockStaff.find(person => normalizeBookingStaffId(person?.id) === normalizeBookingStaffId(selectedBlockStaffId)) || null;
  }

  function getEffectiveServiceSettings(service, staff) {
    const duration = firstPositiveInteger(
      service?.duration_minutes,
      service?.duration,
      service?.service_duration_minutes,
      staff?.service_duration_minutes,
      calendarSettings?.consultation_duration
    );
    const breakTime = firstNonNegativeInteger(
      service?.break_minutes,
      service?.service_break_minutes,
      staff?.service_break_minutes,
      calendarSettings?.consultation_break
    );
    const buffer = firstNonNegativeInteger(
      service?.booking_buffer_minutes,
      staff?.booking_buffer_minutes,
      calendarSettings?.booking_buffer,
      0
    );

    return {
      duration: duration || 60,
      break: breakTime || 0,
      buffer: buffer || 0
    };
  }

  function firstPositiveInteger(...values) {
    for (const value of values) {
      const number = parseInt(value, 10);
      if (Number.isFinite(number) && number > 0) {
        return number;
      }
    }

    return null;
  }

  function firstNonNegativeInteger(...values) {
    for (const value of values) {
      const number = parseInt(value, 10);
      if (Number.isFinite(number) && number >= 0) {
        return number;
      }
    }

    return null;
  }

  function generateServiceSlotsForDate(date, settings) {
    const weekday = getIsoWeekday(date);
    const slots = [];

    staffAvailability
      .filter(entry => Number(entry?.weekday) === weekday && entry?.is_active !== false)
      .forEach(entry => {
        const start = normalizeBookingTime(entry.start_time);
        const end = normalizeBookingTime(entry.end_time);

        if (!isValidTimeString(start) || !isValidTimeString(end)) {
          return;
        }

        let current = timeToMinutes(start);
        const endMinutes = timeToMinutes(end);
        const step = Math.max(1, settings.duration) + Math.max(0, settings.break);

        while (current + settings.duration <= endMinutes) {
          slots.push(minutesToTime(current));
          current += step;
        }
      });

    return Array.from(new Set(slots)).sort();
  }

  function buildAdminStaffBusyIntervals(date, settingsByServiceId, fallbackSettings) {
    return getStaffBookingsForDate(date).map(booking => {
      const start = timeToMinutes(normalizeBookingTime(booking.booking_time || booking.time || ''));
      if (!Number.isFinite(start)) {
        return null;
      }

      const serviceId = normalizeServiceId(booking.service_id || booking.serviceId || '');
      const settings = serviceId && settingsByServiceId.has(serviceId)
        ? settingsByServiceId.get(serviceId)
        : fallbackSettings;
      const duration = Math.max(1, settings.duration);
      const breakTime = Math.max(0, settings.break);
      const buffer = Math.max(0, settings.buffer);
      const reservedEnd = start + duration;
      const activeEnd = reservedEnd + breakTime;
      const total = duration + breakTime + buffer;

      return {
        start,
        end: start + total,
        reservedEnd,
        activeEnd,
        serviceId,
        booking
      };
    }).filter(Boolean);
  }

  function getAdminServiceSlot(date, time, service, busyIntervals) {
    const start = timeToMinutes(time);
    const end = start + Math.max(1, service.duration) + Math.max(0, service.break) + Math.max(0, service.buffer);
    const visitEnd = start + Math.max(1, service.duration);
    const serviceId = normalizeServiceId(service.id);
    let staffBusy = false;
    let bufferBusy = false;

    for (const interval of busyIntervals) {
      if (!rangesOverlap(start, end, interval.start, interval.end)) {
        continue;
      }

      const sameService = serviceId !== '' && interval.serviceId !== '' && serviceId === interval.serviceId;
      const sameStart = start === interval.start;

      if (sameService && sameStart) {
        return {
          time,
          status: 'reserved',
          reservation: normalizeAdminReservation(interval.booking, service.name, time)
        };
      }

      const overlapsReservedTime = rangesOverlap(start, visitEnd, interval.start, interval.reservedEnd);
      const overlapsOnlyBufferOrBreak = !overlapsReservedTime
        || start >= interval.reservedEnd
        || visitEnd <= interval.start;

      if (overlapsOnlyBufferOrBreak) {
        bufferBusy = true;
      } else {
        staffBusy = true;
      }
    }

    const block = getAdminSlotBlockInfo(date, time, start, end);
    if (block.status) {
      return {
        time,
        status: block.status,
        blockSource: block.source
      };
    }

    if (staffBusy) {
      return {
        time,
        status: 'staff_busy'
      };
    }

    if (bufferBusy) {
      return {
        time,
        status: 'booking_buffer'
      };
    }

    return {
      time,
      status: 'available'
    };
  }

  function normalizeAdminReservation(booking, serviceName, fallbackTime) {
    return {
      name: booking?.name || booking?.client_name || 'Klient',
      email: booking?.email || '-',
      phone: booking?.phone || '-',
      time: normalizeBookingTime(booking?.booking_time || booking?.time || fallbackTime),
      service: booking?.service_name_snapshot || booking?.service_name || serviceName || 'Usługa',
      notes: booking?.notes || booking?.description || ''
    };
  }

  function getAdminSlotBlockInfo(date, time, candidateStart = NaN, candidateEnd = NaN) {
    const dateInfo = getDateBlockInfo(date);

    if (dateInfo.blockedByGlobalManual) {
      return { status: 'blocked_global', source: 'global_date' };
    }

    if (dateInfo.blockedByStaffManual) {
      return { status: 'blocked_staff', source: 'staff_date' };
    }

    if (dateInfo.blockedBySettings && !dateInfo.hasGlobalException && !dateInfo.hasStaffException) {
      return { status: 'blocked_global', source: 'settings' };
    }

    if (hasGlobalTimeBlock(date, time, candidateStart, candidateEnd)) {
      return { status: 'blocked_global', source: 'global_time' };
    }

    if (hasStaffTimeBlock(date, time)) {
      return { status: 'blocked_staff', source: 'staff_time' };
    }

    return { status: '', source: '' };
  }

  function getStaffBookingsForDate(date) {
    const source = adminCalendarBookingsLoaded ? adminCalendarBookings : window._bookingsData;

    if (!Array.isArray(source) || !selectedBlockStaffId) {
      return [];
    }

    const targetDate = normalizeBookingDate(date);
    const targetStaff = normalizeBookingStaffId(selectedBlockStaffId);

    return source.filter(item =>
      normalizeBookingStaffId(item?.staff_id) === targetStaff &&
      normalizeBookingDate(item?.booking_date || item?.date || '') === targetDate
    );
  }

  function timeToMinutes(time) {
    const normalized = normalizeBookingTime(time);

    if (!isValidTimeString(normalized)) {
      return NaN;
    }

    const [hours, minutes] = normalized.split(':').map(Number);
    return (hours * 60) + minutes;
  }

  function minutesToTime(minutes) {
    const safeMinutes = ((minutes % 1440) + 1440) % 1440;
    const hours = Math.floor(safeMinutes / 60);
    const mins = safeMinutes % 60;
    return `${String(hours).padStart(2, '0')}:${String(mins).padStart(2, '0')}`;
  }

  function rangesOverlap(startA, endA, startB, endB) {
    return startA < endB && endA > startB;
  }

  function normalizeServiceId(value) {
    return String(value || '').trim().toLowerCase();
  }

  function getIsoWeekday(dateStr) {
    const day = new Date(`${dateStr}T00:00:00`).getDay();
    return day === 0 ? 7 : day;
  }

  function normalizeBlockedDates(data) {
    if (Array.isArray(data.blockedDates)) {
      return data.blockedDates
        .map(item => typeof item === 'string' ? item : item?.date)
        .filter(Boolean);
    }

    if (Array.isArray(data.blockedDateItems)) {
      return data.blockedDateItems
        .map(item => item?.date)
        .filter(Boolean);
    }

    return [];
  }

  async function saveBlockSettings(nextSettings) {
    const res = await fetch('/api/booking/blocked.php', {
      method: 'POST',
      headers: {
        'Content-Type': 'application/json'
      },
      credentials: 'include',
      body: JSON.stringify({
        action: 'saveBlockSettings',
        block_saturdays: !!nextSettings.block_saturdays,
        block_sundays: !!nextSettings.block_sundays,
        block_holidays: !!nextSettings.block_holidays
      })
    });

    const text = await res.text();

    let data = null;
    try {
      data = JSON.parse(text);
    } catch {
      throw new Error(text || 'Nieprawidłowa odpowiedź serwera');
    }

    if (!res.ok || !data.success) {
      throw new Error(data.error || 'Nie udało się zapisać ustawień blokad');
    }
  }

  function syncBlockSettingsUI() {
    const blockSaturdaysEl = document.getElementById('block-saturdays');
    const blockSundaysEl = document.getElementById('block-sundays');
    const blockHolidaysEl = document.getElementById('block-holidays');

    if (blockSaturdaysEl) blockSaturdaysEl.checked = blockSettings.block_saturdays;
    if (blockSundaysEl) blockSundaysEl.checked = blockSettings.block_sundays;
    if (blockHolidaysEl) blockHolidaysEl.checked = blockSettings.block_holidays;
  }

  function syncBlockSettingsVisibility() {
    const blockSettingsEl = document.getElementById('block-settings');
    const noteEl = document.getElementById('staff-block-settings-note');

    if (blockSettingsEl) blockSettingsEl.hidden = blockScope === 'staff';
    if (noteEl) noteEl.hidden = blockScope !== 'staff';
  }

  function hasActiveBlockStaff() {
    return Array.isArray(blockStaff) && blockStaff.length > 0;
  }

  function hasActiveAdminServices() {
    return Array.isArray(adminServices)
      && adminServices.some(service => service && service.is_active !== false);
  }

  function shouldUseStaffServicesBlockMode() {
    return hasActiveBlockStaff() && hasActiveAdminServices();
  }

  function shouldShowCalendarDayReservationMarkers() {
    if (blockScope === 'staff') {
      return !!selectedBlockStaffId;
    }

    return true;
  }

  function shouldShowBookingsInCurrentBlockScope() {
    if (blockScope === 'staff') {
      return !!selectedBlockStaffId;
    }

    return true;
  }

  function syncReservationLegendVisibility() {
    const legend = document.getElementById('adminReservationLegend');
    if (!legend) return;

    legend.hidden = !shouldShowCalendarDayReservationMarkers();
  }

  function shouldShowGlobalDayReservationMarkers() {
    return blockScope !== 'staff' && hasActiveBlockStaff();
  }

  function getAdminBookingsSource() {
    if (adminCalendarBookingsLoaded && Array.isArray(adminCalendarBookings)) {
      return adminCalendarBookings;
    }

    return Array.isArray(window._bookingsData) ? window._bookingsData : [];
  }

  function getGlobalDayReservations(date) {
    if (!shouldShowGlobalDayReservationMarkers()) {
      return [];
    }

    const targetDate = normalizeBookingDate(date);
    return getAdminBookingsSource().filter(item =>
      normalizeBookingDate(item?.booking_date || item?.date || '') === targetDate
    );
  }

  function getCalendarDayReservations(date) {
    if (!shouldShowCalendarDayReservationMarkers()) {
      return [];
    }

    const targetDate = normalizeBookingDate(date);
    const source = getAdminBookingsSource();

    return source.filter(item => {
      if (normalizeBookingDate(item?.booking_date || item?.date || '') !== targetDate) {
        return false;
      }

      if (blockScope === 'staff') {
        return normalizeBookingStaffId(item?.staff_id) === normalizeBookingStaffId(selectedBlockStaffId);
      }

      return true;
    });
  }

  function getDayReservationBadgeText(bookings) {
    const count = Array.isArray(bookings) ? bookings.length : 0;
    const rescheduled = hasAnyRescheduledReservation(bookings);

    if (count > 1) {
      return rescheduled ? `R x${count}↻` : `R x${count}`;
    }

    return rescheduled ? 'R↻' : 'R';
  }

  function buildGlobalDayReservationsTooltip(bookings, date) {
    if (!Array.isArray(bookings) || bookings.length === 0) {
      return '';
    }

    const lines = [
      `Rezerwacje w dniu ${date}:`,
      ...bookings.slice(0, 6).map(formatGlobalDayReservationTooltipItem)
    ];

    if (bookings.length > 6) {
      lines.push(`+ ${bookings.length - 6} więcej`);
    }

    return lines.join('\n\n');
  }

  function formatGlobalDayReservationTooltipItem(booking) {
    const date = normalizeBookingDate(booking?.booking_date || booking?.date || '-');
    const time = normalizeBookingTime(booking?.booking_time || booking?.time || '') || '-';
    const service = getMeaningfulReservationServiceName(booking);
    const staff = getMeaningfulReservationStaffName(booking);
    const lines = [
      `🕒 Godzina: ${time}`,
      `📅 Termin: ${date}`
    ];

    if (service) {
      lines.push(`🧾 Usługa: ${service}`);
    }

    if (staff) {
      lines.push(`👤 Pracownik: ${staff}`);
    }

    if (isReservationRescheduled(booking)) {
      const count = getReservationRescheduleCount(booking);
      const changedAt = formatAdminBookingDateTime(booking?.rescheduled_at);
      lines.push(`↻ Przeniesiona${count > 0 ? `: ${count} z 3` : ''}`);
      if (changedAt) {
        lines.push(`Ostatnia zmiana: ${changedAt}`);
      }
    }

    return lines.join('\n');
  }

  function buildGlobalDayBlockWarningHtml(bookings, date) {
    const rows = Array.isArray(bookings)
      ? bookings.slice(0, 5).map(booking => {
        const time = normalizeBookingTime(booking?.booking_time || booking?.time || '') || '-';
        const service = getMeaningfulReservationServiceName(booking);
        const staff = getMeaningfulReservationStaffName(booking);

        return `
          <li>
            <strong>${escapeHtmlText(time)}</strong>
            ${service ? `<span>🧾 ${escapeHtmlText(service)}</span>` : ''}
            ${staff ? `<span>👤 ${escapeHtmlText(staff)}</span>` : ''}
            ${isReservationRescheduled(booking) ? `<span>↻ Przeniesiona${getReservationRescheduleCount(booking) > 0 ? ` ${escapeHtmlText(getReservationRescheduleCount(booking))} z 3` : ''}</span>` : ''}
          </li>
        `;
      }).join('')
      : '';
    const more = Array.isArray(bookings) && bookings.length > 5
      ? `<p class="warning-more">+ ${bookings.length - 5} więcej</p>`
      : '';

    return `
      <div class="warning-box warning-box-strong">
        <strong>Ten dzień ma już rezerwacje pracowników.</strong><br>
        Globalna blokada może wprowadzić pracowników w błąd.
        Sprawdź rezerwacje przed zablokowaniem dnia.
        <ul class="day-reservation-warning-list">
          ${rows}
        </ul>
        ${more}
        <small>Termin: ${escapeHtmlText(date)}</small>
      </div>
    `;
  }

  function getMeaningfulReservationServiceName(booking) {
    return normalizeMeaningfulReservationLabel(
      booking?.service_name_snapshot ||
      booking?.service_name ||
      booking?.service ||
      booking?.service_title ||
      ''
    );
  }

  function getMeaningfulReservationStaffName(booking) {
    return normalizeMeaningfulReservationLabel(
      booking?.staff_display_name ||
      booking?.staff_name ||
      booking?.staff ||
      ''
    );
  }

  function normalizeMeaningfulReservationLabel(value) {
    const label = String(value || '').trim();

    if (!label) return '';

    const technical = label.toLowerCase();
    if (
      technical === 'usługa' ||
      technical === 'usluga' ||
      technical === 'undefined' ||
      technical === 'null' ||
      technical === 'bez przypisanego pracownika'
    ) {
      return '';
    }

    return label;
  }

  async function renderAdminCalendar() {
    const container = document.getElementById('adminCalendar');
    if (!container) return;

    if (shouldShowCalendarDayReservationMarkers() && !adminCalendarBookingsLoaded) {
      try {
        await loadAdminCalendarBookings();
      } catch (error) {
        console.error('loadAdminCalendarBookings for day markers error:', error);
      }
    }

    const year = adminViewDate.getFullYear();
    const month = adminViewDate.getMonth();

    const monthNames = [
      'Styczeń', 'Luty', 'Marzec', 'Kwiecień', 'Maj', 'Czerwiec',
      'Lipiec', 'Sierpień', 'Wrzesień', 'Październik', 'Listopad', 'Grudzień'
    ];

    let firstDay = new Date(year, month, 1).getDay();
    firstDay = firstDay === 0 ? 6 : firstDay - 1;

    const daysInMonth = new Date(year, month + 1, 0).getDate();

    let html = `
      <div class="calendar-header">
        <button id="adminPrev" type="button">&lsaquo;</button>
        <div>${monthNames[month]} ${year}</div>
        <button id="adminNext" type="button">&rsaquo;</button>
      </div>

      <div class="admin-weekdays">
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
      html += '<div class="admin-day empty"></div>';
    }

    for (let day = 1; day <= daysInMonth; day++) {
      const dateStr = formatDate(new Date(year, month, day));
      const isPast = isPastDate(dateStr);
      const isToday = dateStr === todayStr();
      const isSelected = dateStr === selectedAdminDate;
      const dateInfo = getDateBlockInfo(dateStr);
      const isFullBlocked = dateInfo.effectiveBlocked;
      const dateScope = dateInfo.reason;
      const dateLabel = getScopeLabel(dateScope);
      const dayReservations = getCalendarDayReservations(dateStr);
      const hasDayReservations = dayReservations.length > 0;
      const dayReservationBadge = hasDayReservations ? getDayReservationBadgeText(dayReservations) : '';
      const dayReservationTooltip = hasDayReservations
        ? buildGlobalDayReservationsTooltip(dayReservations, dateStr)
        : '';

      let classes = 'admin-day';

      if (isPast) classes += ' disabled';
      if (isFullBlocked) classes += ' blocked';
      if (dateScope === 'staff_manual') classes += ' staff-blocked';
      if (hasDayReservations) classes += ' day-has-reservations tooltip';
      if (isToday) classes += ' today';
      if (isSelected) classes += ' selected';

      html += `
        <div class="${classes}" data-date="${dateStr}" data-tooltip="${escapeHtmlAttr(dayReservationTooltip)}">
          <span>${day}</span>
          ${dayReservationBadge ? `<small class="day-reservation-badge">${escapeHtmlText(dayReservationBadge)}</small>` : ''}
          ${dateLabel ? `<small class="day-label">${dateLabel}</small>` : ''}
        </div>
      `;
    }

    html += '</div>';
    container.innerHTML = html;

    const prevBtn = document.getElementById('adminPrev');
    const nextBtn = document.getElementById('adminNext');

    if (prevBtn) {
      prevBtn.onclick = async () => {
        adminViewDate = new Date(adminViewDate.getFullYear(), adminViewDate.getMonth() - 1, 1);
        await renderAdminCalendar();
      };
    }

    if (nextBtn) {
      nextBtn.onclick = async () => {
        adminViewDate = new Date(adminViewDate.getFullYear(), adminViewDate.getMonth() + 1, 1);
        await renderAdminCalendar();
      };
    }

    container.querySelectorAll('.admin-day[data-date]:not(.disabled)').forEach(el => {
      el.addEventListener('click', async () => {
        selectedAdminDate = el.dataset.date;
        await renderAdminCalendar();
        await renderAdminTimeSlots();
      });
    });
  }

  async function renderAdminTimeSlots() {
    const container = document.getElementById('adminTimeSlots');
    if (!container) return;
    syncReservationLegendVisibility();

    if (!selectedAdminDate) {
      container.innerHTML = '<p>Wybierz dzień w kalendarzu.</p>';
      return;
    }

    if (blockScope !== 'staff' && hasActiveBlockStaff() && !adminServicesLoaded) {
      await loadAdminServicesForBlockMode();
    }

    if (blockScope !== 'staff' && shouldUseStaffServicesBlockMode()) {
      renderAdminGlobalDayOnly(container);
      return;
    }

    if (blockScope === 'staff' && selectedBlockStaffId) {
      await renderAdminStaffServiceSlots(container);
      return;
    }

    if (blockScope === 'staff') {
      await loadStaffWorkingHoursForDate(selectedAdminDate);
    }

    const slots = blockScope === 'staff'
      ? staffWorkingHours
      : (blockedData.workingHours.length ? blockedData.workingHours : generateTimeSlots());
    const dateInfo = getDateBlockInfo(selectedAdminDate);
    const visibleBlockedForDay = getVisibleBlockedTimesForDate(selectedAdminDate);
    const isFullBlocked = dateInfo.effectiveBlocked;
    const dayButtonLabel = getDayToggleLabel(selectedAdminDate);

    let html = `
      <div class="admin-time-header">
        <h3>Godziny dla: ${selectedAdminDate}</h3>
        <button id="blockFullDayBtn" class="btn-block-day" type="button">
          ${dayButtonLabel}
        </button>
      </div>
      <div class="admin-time-grid">
    `;

    slots.forEach(time => {
      const isBlocked = isFullBlocked || visibleBlockedForDay.includes(time);
      const timeScope = getTimeBlockScope(selectedAdminDate, time) || (isFullBlocked ? getDateBlockScope(selectedAdminDate) : '');
      const timeLabel = isBlocked ? getScopeLabel(timeScope) : '';

      const bookings = getBookingsForCurrentBlockScope(selectedAdminDate, time);
      const booking = bookings[0] || null;
      const isBooked = bookings.length > 0;
      const bookingBadge = getBookingBadgeText(bookings);
      const tooltip = buildBookingsTooltip(bookings);

      html += `
        <div class="admin-time ${isBlocked ? 'blocked' : ''} ${isBooked ? 'booked' : ''} ${timeScope === 'staff_manual' ? 'staff-blocked' : ''} ${isBooked ? 'tooltip' : ''}"
             data-time="${escapeHtmlAttr(time)}"
             data-tooltip="${escapeHtmlAttr(tooltip)}">
          ${escapeHtmlText(time)} ${isBooked ? `<span class="badge-r">${escapeHtmlText(bookingBadge)}</span>` : ''}
          ${timeLabel ? `<small class="time-scope-label">${timeLabel}</small>` : ''}
        </div>
      `;
    });

    html += '</div>';
    container.innerHTML = html;

    const fullDayBtn = document.getElementById('blockFullDayBtn');
    if (fullDayBtn) {
      fullDayBtn.addEventListener('click', async () => {
        await handleFullDayToggle(selectedAdminDate);
      });
    }

    container.querySelectorAll('.admin-time[data-time]').forEach(el => {
      el.addEventListener('click', async () => {
        const time = el.dataset.time;
        const isBlocked = isCurrentScopeTimeBlocked(selectedAdminDate, time);

        if (getDateBlockInfo(selectedAdminDate).effectiveBlocked && !isCurrentScopeTimeBlocked(selectedAdminDate, time)) {
          return;
        }

        await handleTimeToggle(selectedAdminDate, time, isBlocked);
      });
    });
  }

  function renderAdminGlobalDayOnly(container) {
    const dayButtonLabel = getDayToggleLabel(selectedAdminDate);

    container.innerHTML = `
      <div class="admin-time-header admin-time-header-day-only">
        <div>
          <h3>Dzień: ${escapeHtmlText(selectedAdminDate)}</h3>
          <p class="admin-time-mode-note">
            Godziny są zarządzane przy konkretnych pracownikach i usługach.
          </p>
        </div>
        <button id="blockFullDayBtn" class="btn-block-day" type="button">
          ${escapeHtmlText(dayButtonLabel)}
        </button>
      </div>
    `;

    const fullDayBtn = document.getElementById('blockFullDayBtn');
    if (fullDayBtn) {
      fullDayBtn.addEventListener('click', async () => {
        await handleFullDayToggle(selectedAdminDate);
      });
    }
  }

  async function renderAdminStaffServiceSlots(container) {
    const dayButtonLabel = getDayToggleLabel(selectedAdminDate);

    container.innerHTML = `
      <div class="admin-time-header">
        <h3>Godziny dla: ${escapeHtmlText(selectedAdminDate)}</h3>
        <button id="blockFullDayBtn" class="btn-block-day" type="button">
          ${escapeHtmlText(dayButtonLabel)}
        </button>
      </div>
      <div class="admin-service-slots">
        <div class="admin-service-empty">Ładowanie usług i godzin...</div>
      </div>
    `;

    const fullDayBtn = document.getElementById('blockFullDayBtn');
    if (fullDayBtn) {
      fullDayBtn.addEventListener('click', async () => {
        await handleFullDayToggle(selectedAdminDate);
      });
    }

    const servicesWrap = container.querySelector('.admin-service-slots');

    try {
      const services = await getAdminStaffServiceCards(selectedAdminDate);

      if (!servicesWrap) {
        return;
      }

      if (!services.length) {
        servicesWrap.innerHTML = '<div class="admin-service-empty">Brak usług przypisanych do wybranego pracownika.</div>';
        return;
      }

      servicesWrap.innerHTML = services.map((service, serviceIndex) => renderAdminServiceCard(service, serviceIndex)).join('');
      bindAdminServiceSlotTiles(container);
    } catch (error) {
      console.error('renderAdminStaffServiceSlots error:', error);
      if (servicesWrap) {
        servicesWrap.innerHTML = `<div class="admin-service-empty">${escapeHtmlText(error.message || 'Nie udało się pobrać usług i godzin.')}</div>`;
      }
    }
  }

  function renderAdminServiceCard(service, serviceIndex) {
    const slots = Array.isArray(service.slots) ? service.slots : [];
    const serviceName = service.name || 'Usługa';

    return `
      <article class="admin-service-card">
        <div class="admin-service-card-header">
          <div>
            <h3>${escapeHtmlText(serviceName)}</h3>
            <p>
              ${escapeHtmlText(String(service.duration || 0))} min
              · przerwa ${escapeHtmlText(String(service.break || 0))} min
              · bufor ${escapeHtmlText(String(service.buffer || 0))} min
            </p>
          </div>
        </div>

        ${slots.length > 0 ? `
          <div class="admin-service-slot-grid" role="list" aria-label="Godziny dla usługi ${escapeHtmlAttr(serviceName)}">
            ${slots.map((slot, slotIndex) => renderAdminServiceSlot(slot, serviceName, serviceIndex, slotIndex)).join('')}
          </div>
        ` : '<div class="admin-service-empty">Brak godzin dla wybranego dnia.</div>'}
      </article>
    `;
  }

  function renderAdminServiceSlot(slot, serviceName, serviceIndex, slotIndex) {
    const status = String(slot?.status || 'available');
    const isReserved = status === 'reserved';
    const detailId = `admin-service-slot-detail-${serviceIndex}-${slotIndex}`;

    return `
      <div class="admin-service-slot-wrap" role="listitem">
        <button
          type="button"
          class="admin-service-slot is-${escapeHtmlAttr(status)}"
          data-time="${escapeHtmlAttr(slot.time)}"
          data-slot-status="${escapeHtmlAttr(status)}"
          data-block-source="${escapeHtmlAttr(slot.blockSource || '')}"
          ${isReserved ? `data-reservation-detail="${escapeHtmlAttr(detailId)}" aria-expanded="false" aria-controls="${escapeHtmlAttr(detailId)}"` : ''}
        >
          <strong>${escapeHtmlText(slot.time)}</strong>
          ${isReserved ? '<span class="badge-r admin-service-slot-r">R</span>' : ''}
          <small>${escapeHtmlText(getAdminServiceSlotStatusLabel(status))}</small>
        </button>
        ${isReserved ? `
          <div class="admin-service-slot-detail" id="${escapeHtmlAttr(detailId)}" hidden>
            ${adminReservationDetailsHtml(slot.reservation, serviceName, slot.time)}
          </div>
        ` : ''}
      </div>
    `;
  }

  function bindAdminServiceSlotTiles(container) {
    container.querySelectorAll('.admin-service-slot[data-time]').forEach(button => {
      button.addEventListener('click', async () => {
        const status = button.getAttribute('data-slot-status') || '';
        const time = button.getAttribute('data-time') || '';
        const detailId = button.getAttribute('data-reservation-detail') || '';
        const blockSource = button.getAttribute('data-block-source') || '';

        if (detailId) {
          const detail = document.getElementById(detailId);
          if (detail) {
            const shouldShow = detail.hidden;
            detail.hidden = !shouldShow;
            button.setAttribute('aria-expanded', shouldShow ? 'true' : 'false');
          }
          return;
        }

        if (status === 'available') {
          await handleTimeToggle(selectedAdminDate, time, false);
          return;
        }

        if (status === 'blocked_staff' && blockSource === 'staff_time') {
          await handleTimeToggle(selectedAdminDate, time, true);
          return;
        }

        if (status === 'blocked_staff') {
          showMessage('Ten dzień jest zablokowany dla wybranego pracownika. Użyj przycisku odblokowania dnia.', 'error');
          return;
        }

        if (status === 'blocked_global') {
          showMessage('Ten termin jest zablokowany przez firmę lub ustawienia globalne.', 'error');
          return;
        }

        if (status === 'staff_busy') {
          showMessage('Ten termin jest zajęty przez rezerwację pracownika w innej usłudze.', 'error');
          return;
        }

        if (status === 'booking_buffer') {
          showMessage('Ten termin jest niedostępny przez bufor wokół rezerwacji.', 'error');
        }
      });
    });
  }

  function getAdminServiceSlotStatusLabel(status) {
    const labels = {
      available: 'Wolny',
      reserved: 'Rezerwacja',
      staff_busy: 'Zajęty',
      booking_buffer: 'Bufor',
      blocked_staff: 'Pracownik',
      blocked_global: 'Firma'
    };

    return labels[status] || 'Niedostępny';
  }

  function adminReservationDetailsHtml(reservation, serviceName, fallbackTime) {
    const safeReservation = reservation || {};
    const rows = [
      ['👤', 'Klient', safeReservation.name || 'Brak nazwy'],
      ['✉️', 'E-mail', safeReservation.email || 'Brak e-maila'],
      ['☎️', 'Telefon', safeReservation.phone || 'Brak telefonu'],
      ['🕒', 'Godzina', safeReservation.time || fallbackTime || 'Brak godziny'],
      ['🧾', 'Usługa', safeReservation.service || serviceName || 'Usługa']
    ];

    if (safeReservation.notes) {
      rows.push(['📝', 'Notatka', safeReservation.notes]);
    }

    return `
      <div class="admin-service-reservation">
        ${rows.map(([icon, label, value]) => `
          <span>
            <i aria-hidden="true">${icon}</i>
            <strong>${escapeHtmlText(label)}:</strong>
            ${escapeHtmlText(value)}
          </span>
        `).join('')}
      </div>
    `;
  }

  async function saveAvailabilityException(date) {
    const res = await fetch('/api/booking/exception.php', {
      method: 'POST',
      headers: {
        'Content-Type': 'application/json'
      },
      credentials: 'include',
      body: JSON.stringify({
        date,
        staff_id: getCurrentBlockStaffId() || null
      })
    });

    const data = await res.json();

    if (!res.ok || !data.success) {
      throw new Error(data.error || 'Nie udało się zapisać wyjątku');
    }
  }

  async function removeAvailabilityException(date) {
    const res = await fetch('/api/booking/exception.php', {
      method: 'DELETE',
      headers: {
        'Content-Type': 'application/json'
      },
      credentials: 'include',
      body: JSON.stringify({
        date,
        staff_id: getCurrentBlockStaffId() || null
      })
    });

    const data = await res.json();

    if (!res.ok || !data.success) {
      throw new Error(data.error || 'Nie udało się usunąć wyjątku');
    }
  }

  async function handleFullDayToggle(date) {
    if (!ensureCanUseBlockScope()) return;

    const info = getDateBlockInfo(date);

    if (blockScope === 'staff' && info.blockedByGlobalManual) {
      showMessage('Ręczna blokada globalna może być zmieniona tylko w trybie Cała firma.', 'error');
      return;
    }

    const hasBooking = hasBookingForCurrentBlockScope(date);
    const globalDayReservations = getGlobalDayReservations(date);
    const warnsAboutGlobalDayReservations = blockScope !== 'staff'
      && globalDayReservations.length > 0
      && !info.effectiveBlocked;

    const actionLabel = getDayToggleLabel(date);
    const warning = warnsAboutGlobalDayReservations
      ? buildGlobalDayBlockWarningHtml(globalDayReservations, date)
      : (hasBooking ? '<div class="warning-box"><strong>UWAGA!</strong><br>Tu są rezerwacje lub rezerwacja!</div>' : '');
    const confirmed = await openAdminConfirm({
      title: actionLabel,
      html: warning + 'Czy na pewno chcesz wykonać operację dla dnia:<br><strong>' + escapeHtmlText(date) + '</strong>',
      confirmText: warnsAboutGlobalDayReservations ? 'Zablokuj mimo to' : 'Potwierdź',
      cancelText: 'Anuluj',
      variant: warnsAboutGlobalDayReservations ? 'danger' : undefined,
      icon: ''
    });

    if (!confirmed) return;

    try {
      if (blockScope === 'staff') {
        if (info.hasStaffException) {
          await removeAvailabilityException(date);
          showMessage('Dzień ponownie podlega ustawieniom globalnym dla tego pracownika.', 'success');
        } else if (info.blockedBySettings && !info.hasGlobalException) {
          await saveAvailabilityException(date);
          showMessage('Odblokowano dzień dla wybranego pracownika.', 'success');
        } else if (info.blockedByStaffManual) {
          await deleteDayBlock(date);
          showMessage(getScopeMessage('unblock', 'day'), 'success');
        } else {
          const result = await saveDayBlock(date);
          showMessage(result.message || getScopeMessage('block', 'day'), 'success');
        }
      } else if (info.hasGlobalException && !info.blockedByGlobalManual) {
        await removeAvailabilityException(date);
        showMessage('Dzień ponownie podlega ustawieniom globalnym.', 'success');
      } else if (info.blockedBySettings && !info.blockedByGlobalManual) {
        await saveAvailabilityException(date);
        showMessage('Odblokowano dzień mimo ustawień globalnych.', 'success');
      } else if (info.blockedByGlobalManual) {
        await deleteDayBlock(date);
        showMessage(getScopeMessage('unblock', 'day'), 'success');
      } else {
        const result = await saveDayBlock(date);
        showMessage(result.message || getScopeMessage('block', 'day'), 'success');
      }

      await refreshAdminBlocksAfterMutation();
    } catch (error) {
      console.error('handleFullDayToggle error:', error);
      showMessage(error.message || 'Błąd operacji na dniu', 'error');
    }
  }

  async function handleTimeToggle(date, time, currentlyBlocked) {
    if (!ensureCanUseBlockScope()) return;

    const booking = getBookingForCurrentBlockScope(date, time);

    const hasBooking = !!booking;

    const confirmed = await openAdminConfirm({
      title: currentlyBlocked ? 'Odblokowanie godziny' : 'Blokada godziny',
      html: `
        ${hasBooking ? '<div class="warning-box"><strong>UWAGA!</strong><br>Na tej godzinie jest rezerwacja!</div>' : ''}
        ${currentlyBlocked
          ? `Czy na pewno chcesz odblokować godzinę:<br><strong>${time}</strong><br>dla dnia <strong>${date}</strong>?`
          : `Czy na pewno chcesz zablokować godzinę:<br><strong>${time}</strong><br>dla dnia <strong>${date}</strong>?`
        }
      `,
      icon: '',
      confirmText: currentlyBlocked ? 'Odblokuj' : 'Zablokuj',
      cancelText: 'Anuluj'
    });

    if (!confirmed) return;

    try {
      if (currentlyBlocked) {
        await deleteTimeBlock(date, time);
        showMessage(getScopeMessage('unblock', 'time'), 'success');
      } else {
        const result = await saveTimeBlock(date, time);
        showMessage(result.message || getScopeMessage('block', 'time'), 'success');
      }

      await refreshAdminBlocksAfterMutation();
    } catch (error) {
      console.error('handleTimeToggle error:', error);
      showMessage(error.message || 'Błąd operacji na godzinie', 'error');
    }
  }

  async function handleBlockRange() {
    if (!ensureCanUseBlockScope()) return;

    const from = document.getElementById('range-from').value || '';
    const to = document.getElementById('range-to').value || '';

    if (!from || !to) {
      showMessage('Wybierz zakres dat do zablokowania', 'error');
      return;
    }

    if (from > to) {
      showMessage('"Od" nie może być większe niż "Do"', 'error');
      return;
    }

    const confirmed = await openAdminConfirm({
      title: 'Zablokować zakres?',
      html: `Czy na pewno chcesz zablokować zakres od <strong>${from}</strong> do <strong>${to}</strong>?`,
      confirmText: 'Zablokuj',
      cancelText: 'Anuluj',
      variant: 'danger',
      icon: '',
    });

    if (!confirmed) return;

    try {
      const dates = getDatesInRange(from, to);

      for (const date of dates) {
        await saveDayBlock(date);
      }

      showMessage(`Zablokowano zakres: ${from} -> ${to} (${dates.length} dni)`, 'success');

      await refreshAdminBlocksAfterMutation();
    } catch (error) {
      console.error('handleBlockRange error:', error);
      showMessage(error.message || 'Błąd przy blokowaniu zakresu', 'error');
    }
  }

  async function handleUnblockRange() {
    try {
      if (!ensureCanUseBlockScope()) return;

      const from = document.getElementById('range-from').value || '';
      const to = document.getElementById('range-to').value || '';

      if (!from || !to) {
        showMessage('Wybierz zakres dat do odblokowania.', 'error');
        return;
      }

      if (from > to) {
        showMessage('Data "od" nie może być późniejsza niż data "do".', 'error');
        return;
      }

      const confirmed = await openAdminConfirm({
        title: 'Odblokować zakres?',
        html: `Czy na pewno chcesz odblokować zakres od <strong>${from}</strong> do <strong>${to}</strong>?`,
        confirmText: 'Odblokuj',
        cancelText: 'Anuluj',
        variant: 'danger',
        icon: '',
      });

      if (!confirmed) return;

      const dates = getDatesInRange(from, to);

      for (const date of dates) {
        await deleteDayBlock(date, true);
      }

      await refreshAdminBlocksAfterMutation();

      showMessage(`Odblokowano zakres: ${from} - ${to}`, 'success');
    } catch (error) {
      console.error('handleUnblockRange error:', error);
      showMessage('Nie udało się odblokować zakresu.', 'error');
    }
  }

  async function saveDayBlock(date) {
    await clearTimesForDate(date);

    const res = await fetch('/api/booking/blocked.php', {
      method: 'POST',
      headers: {
        'Content-Type': 'application/json'
      },
      credentials: 'include',
      body: JSON.stringify({
        date,
        time: '',
        allDay: true,
        staff_id: getCurrentBlockStaffId() || null,
        csrf: window.CSRF_TOKEN
      })
    });

    const text = await res.text();
    const data = parseJsonSafe(text);

    if (!res.ok || !data.success) {
      if (isDuplicateResponse(data)) {
        return data;
      }

      throw new Error(data.error || `Nie udało się zablokować dnia ${date}`);
    }

    return data;
  }

  async function saveTimeBlock(date, time) {
    const res = await fetch('/api/booking/blocked.php', {
      method: 'POST',
      headers: {
        'Content-Type': 'application/json'
      },
      credentials: 'include',
      body: JSON.stringify({
        date,
        time,
        allDay: false,
        staff_id: getCurrentBlockStaffId() || null,
        csrf: window.CSRF_TOKEN
      })
    });

    const text = await res.text();
    const data = parseJsonSafe(text);

    if (!res.ok || !data.success) {
      if (isDuplicateResponse(data)) {
        return data;
      }

      throw new Error(data.error || `Nie udało się zablokować godziny ${date} ${time}`);
    }

    return data;
  }

  async function deleteDayBlock(date, silent = false) {
    const res = await fetch('/api/booking/blocked.php', {
      method: 'DELETE',
      headers: {
        'Content-Type': 'application/json'
      },
      credentials: 'include',
      body: JSON.stringify({
        date,
        time: null,
        staff_id: getCurrentBlockStaffId() || null
      })
    });

    const text = await res.text();
    const data = parseJsonSafe(text);

    if (!res.ok || !data.success) {
      if (silent) return;
      throw new Error(data.error || `Nie udało się odblokować dnia ${date}`);
    }
  }

  async function deleteTimeBlock(date, time) {
    const res = await fetch('/api/booking/blocked.php', {
      method: 'DELETE',
      headers: {
        'Content-Type': 'application/json'
      },
      credentials: 'include',
      body: JSON.stringify({
        date,
        time,
        staff_id: getCurrentBlockStaffId() || null
      })
    });

    const text = await res.text();
    const data = parseJsonSafe(text);

    if (!res.ok || !data.success) {
      throw new Error(data.error || `Nie udało się odblokować godziny ${date} ${time}`);
    }
  }

  async function clearTimesForDate(date) {
    await fetch('/api/booking/blocked.php', {
      method: 'DELETE',
      headers: {
        'Content-Type': 'application/json'
      },
      credentials: 'include',
      body: JSON.stringify({
        date,
        deleteAllTimes: true,
        staff_id: getCurrentBlockStaffId() || null
      })
    });
  }

  function isDateFullyBlocked(dateStr) {
    return getDateBlockInfo(dateStr).effectiveBlocked;
  }

  function getCurrentBlockStaffId() {
    return blockScope === 'staff' ? selectedBlockStaffId : '';
  }

  function ensureCanUseBlockScope() {
    if (blockScope === 'staff' && !selectedBlockStaffId) {
      showMessage('Wybierz pracownika, aby dodać blokadę.', 'error');
      return false;
    }

    return true;
  }

  function getDateBlockInfo(dateStr) {
    const blockedBySettings = isGlobalRuleBlocked(dateStr);
    const hasGlobalException = blockedData.globalAvailabilityExceptions.includes(dateStr);
    const hasStaffException = blockScope === 'staff' && blockedData.staffAvailabilityExceptions.includes(dateStr);
    const blockedByGlobalManual = hasGlobalDateBlock(dateStr);
    const blockedByStaffManual = hasStaffDateBlock(dateStr);
    const settingsBlockedAfterExceptions = blockedBySettings && !hasGlobalException && !(blockScope === 'staff' && hasStaffException);

    let effectiveBlocked = false;
    let editableLayer = blockScope === 'staff' ? 'staff' : 'global';
    let reason = null;

    if (blockedByGlobalManual) {
      effectiveBlocked = true;
      editableLayer = blockScope === 'global' ? 'global' : null;
      reason = 'global_manual';
    } else if (blockScope === 'staff' && blockedByStaffManual) {
      effectiveBlocked = true;
      reason = 'staff_manual';
    } else if (settingsBlockedAfterExceptions) {
      effectiveBlocked = true;
      reason = 'settings';
    }

    return {
      blockedBySettings,
      hasGlobalException,
      hasStaffException,
      blockedByGlobalManual,
      blockedByStaffManual,
      effectiveBlocked,
      editableLayer,
      reason
    };
  }

  function getDayToggleLabel(dateStr) {
    const info = getDateBlockInfo(dateStr);

    if (blockScope === 'staff') {
      if (info.hasStaffException) return 'Przywróć blokadę dla pracownika';
      if (info.blockedByGlobalManual) return 'Zablokowany przez całą firmę';
      if (info.blockedBySettings && !info.hasGlobalException) return 'Odblokuj dzień dla pracownika';
      if (info.blockedByStaffManual) return 'Odblokuj cały dzień';
      return 'Zablokuj cały dzień';
    }

    if (info.hasGlobalException && !info.blockedByGlobalManual) return 'Przywróć ustawienia globalne';
    if (info.blockedBySettings && !info.blockedByGlobalManual) return 'Odblokuj cały dzień';
    if (info.blockedByGlobalManual) return 'Odblokuj cały dzień';
    return 'Zablokuj cały dzień';
  }

  function isGlobalRuleActive(dateStr) {
    const info = getDateBlockInfo(dateStr);
    return info.blockedBySettings && !info.hasGlobalException && !(blockScope === 'staff' && info.hasStaffException);
  }

  function getDateBlockScope(dateStr) {
    return getDateBlockInfo(dateStr).reason || '';
  }

  function isCurrentScopeDateBlocked(dateStr) {
    const info = getDateBlockInfo(dateStr);

    if (blockScope === 'staff') {
      return info.blockedByStaffManual || info.hasStaffException || (info.blockedBySettings && !info.hasGlobalException);
    }

    return info.blockedByGlobalManual || info.hasGlobalException || (info.blockedBySettings && !info.hasGlobalException);
  }

  function getTimeBlockScope(dateStr, time) {
    const hasGlobal = hasGlobalTimeBlock(dateStr, time);
    const hasStaff = hasStaffTimeBlock(dateStr, time);

    if (hasGlobal && hasStaff) {
      return 'both';
    }

    if (hasStaff) {
      return 'staff_manual';
    }

    if (hasGlobal) {
      return 'global_manual';
    }

    return '';
  }

  function isCurrentScopeTimeBlocked(dateStr, time) {
    if (blockScope === 'staff') {
      return hasStaffTimeBlock(dateStr, time);
    }

    return hasGlobalTimeBlock(dateStr, time);
  }

  function getScopeLabel(scope) {
    if (scope === 'staff_manual') return 'Pracownik';
    if (scope === 'settings' || scope === 'global_manual') return 'Firma';
    if (scope === 'both') return 'Pracownik';
    return '';
  }

  function hasGlobalDateBlock(dateStr) {
    return blockedData.globalBlockedDates.includes(dateStr);
  }

  function hasStaffDateBlock(dateStr) {
    return blockScope === 'staff' && blockedData.staffBlockedDates.includes(dateStr);
  }

  function isVisibleManualDateBlocked(dateStr) {
    const info = getDateBlockInfo(dateStr);
    return info.blockedByGlobalManual || info.blockedByStaffManual;
  }

  function hasGlobalTimeBlock(dateStr, time, candidateStart = NaN, candidateEnd = NaN) {
    const times = Array.isArray(blockedData.globalBlockedTimes?.[dateStr])
      ? blockedData.globalBlockedTimes[dateStr]
      : [];

    if (!Number.isFinite(candidateStart) || !Number.isFinite(candidateEnd)) {
      return times.includes(time);
    }

    return times.some(blockedTime => {
      const blockStart = timeToMinutes(blockedTime);

      if (!Number.isFinite(blockStart)) {
        return false;
      }

      return rangesOverlap(candidateStart, candidateEnd, blockStart, blockStart + 60);
    });
  }

  function hasStaffTimeBlock(dateStr, time) {
    return blockScope === 'staff'
      && Array.isArray(blockedData.staffBlockedTimes?.[dateStr])
      && blockedData.staffBlockedTimes[dateStr].includes(time);
  }

  function getVisibleBlockedTimesForDate(dateStr) {
    const times = new Set();

    if (Array.isArray(blockedData.globalBlockedTimes?.[dateStr])) {
      blockedData.globalBlockedTimes[dateStr].forEach(time => times.add(time));
    }

    if (blockScope === 'staff' && Array.isArray(blockedData.staffBlockedTimes?.[dateStr])) {
      blockedData.staffBlockedTimes[dateStr].forEach(time => times.add(time));
    }

    return Array.from(times);
  }

  function isBookingVisibleForCurrentBlockScope(item) {
    if (!item) {
      return false;
    }

    if (!shouldShowBookingsInCurrentBlockScope()) {
      return false;
    }

    if (blockScope === 'staff') {
      if (!selectedBlockStaffId) {
        return false;
      }

      return normalizeBookingStaffId(item.staff_id) === normalizeBookingStaffId(selectedBlockStaffId);
    }

    return true;
  }

  function normalizeBookingStaffId(value) {
    return String(value || '').trim().toLowerCase();
  }

  function normalizeBookingTime(value) {
    return String(value || '').trim().slice(0, 5);
  }

  function normalizeBookingDate(value) {
    return String(value || '').trim();
  }

  function getReservationRescheduleCount(booking) {
    const count = parseInt(booking?.reschedule_count ?? 0, 10);
    return Number.isFinite(count) && count > 0 ? count : 0;
  }

  function isReservationRescheduled(booking) {
    return getReservationRescheduleCount(booking) > 0 || Boolean(booking?.rescheduled_at);
  }

  function hasAnyRescheduledReservation(bookings) {
    return Array.isArray(bookings) && bookings.some(isReservationRescheduled);
  }

  function formatAdminBookingDateTime(value) {
    const date = new Date(value || '');

    if (Number.isNaN(date.getTime())) {
      return '';
    }

    const day = String(date.getDate()).padStart(2, '0');
    const month = String(date.getMonth() + 1).padStart(2, '0');
    const year = date.getFullYear();
    const hours = String(date.getHours()).padStart(2, '0');
    const minutes = String(date.getMinutes()).padStart(2, '0');

    return `${day}.${month}.${year} ${hours}:${minutes}`;
  }

  function getBookingForCurrentBlockScope(date, time) {
    return getBookingsForCurrentBlockScope(date, time)[0] || null;
  }

  function getBookingsForCurrentBlockScope(date, time) {
    if (!Array.isArray(window._bookingsData)) {
      return [];
    }

    const targetDate = normalizeBookingDate(date);
    const targetTime = normalizeBookingTime(time);

    return window._bookingsData.filter(item =>
      isBookingVisibleForCurrentBlockScope(item) &&
      normalizeBookingDate(item.booking_date || item?.date || '') === targetDate &&
      normalizeBookingTime(item.booking_time || item.time || '') === targetTime
    );
  }

  function getBookingBadgeText(bookings) {
    const count = Array.isArray(bookings) ? bookings.length : 0;
    const rescheduled = hasAnyRescheduledReservation(bookings);

    if (count > 1 && blockScope !== 'staff') {
      return rescheduled ? `R×${count}↻` : `R×${count}`;
    }

    return rescheduled ? 'R↻' : 'R';
  }

  function buildBookingsTooltip(bookings) {
    if (!Array.isArray(bookings) || bookings.length === 0) {
      return '';
    }

    if (bookings.length === 1) {
      return formatBookingTooltipItem(bookings[0], false);
    }

    return [
      `Rezerwacje: ${bookings.length}`,
      ...bookings.map((booking, index) => formatBookingTooltipItem(booking, true, index + 1))
    ].join('\n\n');
  }

  function formatBookingTooltipItem(booking, numbered = false, index = 1) {
    const clientName = booking?.name || booking?.client_name || 'Klient';
    const staffName = booking?.staff_display_name || booking?.staff_name || 'Bez przypisanego pracownika';
    const header = numbered
      ? `${index}. ${clientName} — ${staffName}`
      : `${clientName}${staffName ? ` — ${staffName}` : ''}`;
    const note = booking?.notes || booking?.description || '';
    const lines = [
      header,
      `Email: ${booking?.email || '-'}`,
      `Tel: ${booking?.phone || '-'}`
    ];

    if (note) {
      lines.push(`Notatka: ${note}`);
    }

    if (isReservationRescheduled(booking)) {
      const count = getReservationRescheduleCount(booking);
      const changedAt = formatAdminBookingDateTime(booking?.rescheduled_at);
      lines.push(`Przeniesiona${count > 0 ? `: ${count} z 3` : ''}`);
      if (changedAt) {
        lines.push(`Ostatnia zmiana: ${changedAt}`);
      }
    }

    return lines.join('\n');
  }

  function hasBookingForCurrentBlockScope(date) {
    if (!Array.isArray(window._bookingsData)) {
      return false;
    }

    const targetDate = normalizeBookingDate(date);

    return window._bookingsData.some(item =>
      isBookingVisibleForCurrentBlockScope(item) &&
      normalizeBookingDate(item.booking_date || item?.date || '') === targetDate
    );
  }

  function getScopeMessage(action, unit) {
    const global = blockScope !== 'staff';

    if (unit === 'day') {
      if (action === 'block') return global ? 'Zablokowano dzień dla całej firmy.' : 'Zablokowano dzień dla wybranego pracownika.';
      return global ? 'Odblokowano dzień dla całej firmy.' : 'Odblokowano dzień dla wybranego pracownika.';
    }

    if (action === 'block') return global ? 'Zablokowano godzinę dla całej firmy.' : 'Zablokowano godzinę dla wybranego pracownika.';
    return global ? 'Odblokowano godzinę dla całej firmy.' : 'Odblokowano godzinę dla wybranego pracownika.';
  }
  function isGlobalRuleBlocked(dateStr) {
    const date = new Date(`${dateStr}T00:00:00`);
    const dayOfWeek = date.getDay();
    const mmdd = dateStr.slice(5);

    if (blockSettings.block_saturdays && dayOfWeek === 6) return true;
    if (blockSettings.block_sundays && dayOfWeek === 0) return true;
    if (blockSettings.block_holidays && HOLIDAYS.includes(mmdd)) return true;

    return false;
  }

  function getBlockedTimesForDate(dateStr) {
    return getVisibleBlockedTimesForDate(dateStr);
  }

  function getDatesInRange(from, to) {
    const dates = [];
    let current = new Date(`${from}T00:00:00`);
    const end = new Date(`${to}T00:00:00`);

    while (current <= end) {
      dates.push(formatDate(current));
      current.setDate(current.getDate() + 1);
    }

    return dates;
  }

  function getFirstSelectableDateInCurrentMonth() {
    const year = adminViewDate.getFullYear();
    const month = adminViewDate.getMonth();
    const today = todayStr();
    const daysInMonth = new Date(year, month + 1, 0).getDate();

    for (let day = 1; day <= daysInMonth; day++) {
      const dateStr = formatDate(new Date(year, month, day));
      if (dateStr >= today) {
        return dateStr;
      }
    }

    return today;
  }

  function generateTimeSlots() {
    if (!calendarSettings) {
      throw new Error('Nie załadowano ustawień kalendarza');
    }

    const workStart = calendarSettings.work_start;
    const workEnd = calendarSettings.work_end;
    const slotDuration = parseInt(calendarSettings.consultation_duration, 10);
    const breakDuration = parseInt(calendarSettings.consultation_break, 10);

    if (!isValidTimeString(workStart) || !isValidTimeString(workEnd)) {
      throw new Error('Nieprawidłowe godziny pracy w ustawieniach');
    }

    if (!Number.isFinite(slotDuration) || slotDuration <= 0) {
      throw new Error('Nieprawidłowa długość konsultacji');
    }

    if (!Number.isFinite(breakDuration) || breakDuration < 0) {
      throw new Error('Nieprawidłowa długość konsultacji');
    }

    const slots = [];
    const [startH, startM] = workStart.split(':').map(Number);
    const [endH, endM] = workEnd.split(':').map(Number);

    let current = startH * 60 + startM;
    const end = endH * 60 + endM;

    while (current + slotDuration <= end) {
      const hours = Math.floor(current / 60);
      const minutes = current % 60;

      slots.push(`${String(hours).padStart(2, '0')}:${String(minutes).padStart(2, '0')}`);
      current += slotDuration + breakDuration;
    }

    return slots;
  }

  function updateBlockedStats() {
    const blockedDaysEl = document.getElementById('stat-blocked-days');
    const blockedTimesEl = document.getElementById('stat-blocked-times');

    const blockedDaysCount = blockedData.blockedDates.length;
    const blockedTimesCount = Object.values(blockedData.blockedTimes)
      .reduce((sum, times) => sum + (Array.isArray(times) ? times.filter(t => t !== 'all').length : 0), 0);

    if (blockedDaysEl) blockedDaysEl.textContent = String(blockedDaysCount);
    if (blockedTimesEl) blockedTimesEl.textContent = String(blockedTimesCount);
  }

  function setRangeMinDates() {
    const fromEl = document.getElementById('range-from');
    const toEl = document.getElementById('range-to');
    const today = todayStr();

    if (fromEl) fromEl.min = today;
    if (toEl) toEl.min = today;
  }

  function syncRangeLimits() {
    const fromEl = document.getElementById('range-from');
    const toEl = document.getElementById('range-to');
    if (!fromEl || !toEl) return;

    toEl.min = fromEl.value || todayStr();

    if (toEl.value && fromEl.value && toEl.value < fromEl.value) {
      toEl.value = '';
    }
  }

  function formatDate(date) {
    const year = date.getFullYear();
    const month = String(date.getMonth() + 1).padStart(2, '0');
    const day = String(date.getDate()).padStart(2, '0');
    return `${year}-${month}-${day}`;
  }

  function todayStr() {
    return formatDate(new Date());
  }

  function isPastDate(dateStr) {
    return dateStr < todayStr();
  }

  function parseJsonSafe(text) {
    try {
      return JSON.parse(text);
    } catch {
      return null;
    }
  }

  function isDuplicateResponse(data) {
    const debugText = String(data.debug || '');
    return data.httpCode === 409 || debugText.includes('duplicate key value');
  }

  function isValidTimeString(value) {
    return /^\d{2}:\d{2}$/.test(String(value || ''));
  }

  function escapeHtmlText(value) {
    return String(value ?? '')
      .replaceAll('&', '&amp;')
      .replaceAll('<', '&lt;')
      .replaceAll('>', '&gt;')
      .replaceAll('"', '&quot;')
      .replaceAll("'", '&#039;');
  }

  function escapeHtmlAttr(value) {
    return escapeHtmlText(value).replaceAll('\n', '&#10;').replaceAll('\r', '&#13;');
  }

  function showMessage(text, type = 'success') {
    const messageEl = document.getElementById('block-message');
    if (!messageEl) return;

    messageEl.textContent = text;
    messageEl.classList.remove('success', 'error');
    messageEl.classList.add(type);
    messageEl.style.display = 'block';
  }

  function showConfirmModal({ title, text, onConfirm }) {
    const modal = document.getElementById('confirmModal');
    const titleEl = document.getElementById('confirmModalTitle');
    const textEl = document.getElementById('confirmModalText');
    const okBtn = document.getElementById('confirmModalOk');
    const cancelBtn = document.getElementById('confirmModalCancel');

    if (!modal || !titleEl || !textEl || !okBtn || !cancelBtn) {
      if (typeof onConfirm === 'function') {
        onConfirm();
      }
      return;
    }

    titleEl.textContent = title || 'Potwierdzenie';
    textEl.textContent = text || '';

    modal.classList.remove('hidden');

    const close = () => {
      modal.classList.add('hidden');
      okBtn.onclick = null;
      cancelBtn.onclick = null;
    };

    okBtn.onclick = () => {
      close();
      if (typeof onConfirm === 'function') {
        onConfirm();
      }
    };

    cancelBtn.onclick = () => {
      close();
    };
  }

  async function allowDespiteGlobalBlock(date) {
    showConfirmModal({
        title: 'Odblokować dzień?',
      text: `Odblokować ${date} mimo blokad soboty/niedzieli/świąt?`,
      onConfirm: async () => {
        try {
          const res = await fetch('/api/booking/exception.php', {
            method: 'POST',
            headers: {
              'Content-Type': 'application/json'
            },
            credentials: 'include',
            body: JSON.stringify({ date })
          });

          const data = await res.json();

          if (!res.ok || !data.success) {
            throw new Error(data.error || 'Błąd zapisu wyjątku');
          }

          showMessage('Odblokowano ten dzień mimo ustawień globalnych.', 'success');

          await refreshAdminCalendarData();
          await renderAdminCalendar();
          await renderAdminTimeSlots();
        } catch (err) {
          console.error(err);
          showMessage(err.message || 'Błąd odblokowania dnia', 'error');
        }
      }
    });
  }
})();
