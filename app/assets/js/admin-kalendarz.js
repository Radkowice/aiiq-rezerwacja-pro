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

  let blockSettings = {
    block_saturdays: false,
    block_sundays: false,
    block_holidays: false
  };

  let blockedData = {
    blockedDates: [],
    blockedTimes: {},
    workingHours: [],
    availabilityExceptions: []
  };

  document.addEventListener('DOMContentLoaded', async () => {
    if (!document.getElementById('adminCalendar')) return;

    initCalendarControls();
    setRangeMinDates();

    try {
      await initAdminCalendar();
    } catch (error) {
      console.error('initAdminCalendar error:', error);
      showMessage(error.message || 'Nie udało się uruchomić kalendarza admina', 'error');
    }
  });

 async function initAdminCalendar() {
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
    throw new Error('Nie udało się pobrać ustawień kalendarza');
  }

  const data = await res.json();

  if (!data?.success || !data?.settings) {
    return false;
  }

  const settings = data.settings;

  const workStart = String(settings.work_start || '').trim();
  const workEnd = String(settings.work_end || '').trim();
  const duration = parseInt(settings.consultation_duration, 10);
  const breakTime = parseInt(settings.consultation_break ?? 0, 10);

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
    consultation_break: breakTime
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

          await refreshAdminCalendarData();
          await renderAdminCalendar();
          await renderAdminTimeSlots();

          showMessage('Ustawienia blokad zapisane', 'success');
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

  window.refreshAdminCalendarData = refreshAdminCalendarData;
  
  function renderCalendarNotConfiguredState() {
  const calendarContainer = document.getElementById('adminCalendar');
  const timeSlotsContainer = document.getElementById('adminTimeSlots');

  const html = `
    <div class="calendar-empty-state">
      <div class="calendar-empty-icon">📅</div>
      <h3>Najpierw skonfiguruj kalendarz</h3>
      <p>
        Aby zarządzać blokadami terminów, ustaw dni pracy, godziny dostępności
        oraz długość wizyty w zakładce <strong>Ustawienia</strong>.
      </p>
      <p class="calendar-empty-note">
        Po zapisaniu ustawień kalendarza wróć do zakładki Blokady i odśwież — wtedy pojawią się dni i godziny do blokowania.
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
    const res = await fetch('/api/booking/blocked.php', {
      cache: 'no-store',
      credentials: 'include'
    });

    if (!res.ok) {
      throw new Error('Nie udało się pobrać blokad');
    }

    const data = await res.json();

    blockedData = {
      blockedDates: Array.isArray(data.blockedDates) ? data.blockedDates : [],
      blockedTimes: data.blockedTimes && typeof data.blockedTimes === 'object' ? data.blockedTimes : {},
      workingHours: Array.isArray(data.workingHours) && data.workingHours.length
        ? data.workingHours
        : generateTimeSlots(),
      availabilityExceptions: Array.isArray(data.availabilityExceptions)
        ? data.availabilityExceptions
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

    if (!res.ok || !data?.success) {
      throw new Error(data?.error || 'Nie udało się zapisać ustawień blokad');
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

  async function renderAdminCalendar() {
    const container = document.getElementById('adminCalendar');
    if (!container) return;

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
        <button id="adminPrev" type="button">‹</button>
        <div>${monthNames[month]} ${year}</div>
        <button id="adminNext" type="button">›</button>
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
      const isFullBlocked = isDateFullyBlocked(dateStr);

      let classes = 'admin-day';

      if (isPast) classes += ' disabled';
      if (isFullBlocked) classes += ' blocked';
      if (isToday) classes += ' today';
      if (isSelected) classes += ' selected';

      html += `<div class="${classes}" data-date="${dateStr}">${day}</div>`;
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

    if (!selectedAdminDate) {
      container.innerHTML = '<p>Wybierz dzień w kalendarzu.</p>';
      return;
    }

    const slots = blockedData.workingHours.length ? blockedData.workingHours : generateTimeSlots();
    const blockedForDay = getBlockedTimesForDate(selectedAdminDate);
    const isFullBlocked = isDateFullyBlocked(selectedAdminDate);

    let html = `
      <div class="admin-time-header">
        <h3>Godziny dla: ${selectedAdminDate}</h3>
        <button id="blockFullDayBtn" class="btn-block-day" type="button">
          ${isFullBlocked ? 'Odblokuj cały dzień' : 'Zablokuj cały dzień'}
        </button>
      </div>
      <div class="admin-time-grid">
    `;

    slots.forEach(time => {
      const isBlocked = isFullBlocked || blockedForDay.includes(time);

      const booking = Array.isArray(window._bookingsData)
        ? window._bookingsData.find(item =>
            (item.booking_date || item.date || '') === selectedAdminDate &&
            (item.booking_time || item.time || '') === time
          )
        : null;

      const isBooked = !!booking;

      const tooltip = booking
        ? `${booking.name || booking.client_name || 'Klient'}
📧 ${booking.email || '-'}
📞 ${booking.phone || '-'}
💬 ${booking.notes || booking.description || '-'}`.trim()
        : '';

      html += `
        <div class="admin-time ${isBlocked ? 'blocked' : ''} ${isBooked ? 'tooltip' : ''}"
             data-time="${escapeHtmlAttr(time)}"
             data-tooltip="${escapeHtmlAttr(tooltip)}">
          ${escapeHtmlText(time)} ${isBooked ? '<span class="badge-r">R</span>' : ''}
        </div>
      `;
    });

    html += '</div>';
    container.innerHTML = html;

    const fullDayBtn = document.getElementById('blockFullDayBtn');
    if (fullDayBtn) {
      fullDayBtn.addEventListener('click', async () => {
        await handleFullDayToggle(selectedAdminDate, isFullBlocked);
      });
    }

    container.querySelectorAll('.admin-time[data-time]').forEach(el => {
      el.addEventListener('click', async () => {
        const time = el.dataset.time;
        const isBlocked = el.classList.contains('blocked');

        if (isDateFullyBlocked(selectedAdminDate)) {
          return;
        }

        await handleTimeToggle(selectedAdminDate, time, isBlocked);
      });
    });
  }

  async function removeGlobalBlockException(date) {
    const res = await fetch('/api/booking/exception.php', {
      method: 'DELETE',
      headers: {
        'Content-Type': 'application/json'
      },
      credentials: 'include',
      body: JSON.stringify({ date })
    });

    const data = await res.json();

    if (!res.ok || !data.success) {
      throw new Error(data.error || 'Nie udało się usunąć wyjątku');
    }
  }

  async function handleFullDayToggle(date, currentlyBlocked) {
    const hasBooking = Array.isArray(window._bookingsData)
      ? window._bookingsData.some(item => (item.booking_date || item.date || '') === date)
      : false;

    const confirmed = await openAdminConfirm({
      title: currentlyBlocked ? 'Odblokowanie dnia' : 'Blokada dnia',
      html: currentlyBlocked
        ? `
          ${hasBooking ? '<div class="warning-box"><strong>UWAGA!</strong><br>Tu są rezerwacje lub rezerwacja!</div>' : ''}
          Czy na pewno chcesz odblokować dzień:<br><strong>${date}</strong>?
        `
        : `
          ${hasBooking ? '<div class="warning-box"><strong>UWAGA!</strong><br>Tu są rezerwacje lub rezerwacja!</div>' : ''}
          Czy na pewno chcesz zablokować dzień:<br><strong>${date}</strong>?
        `
    });

    if (!confirmed) return;

    try {
      if (currentlyBlocked) {
        if (isGlobalRuleBlocked(date) && !blockedData.availabilityExceptions.includes(date)) {
          await allowDespiteGlobalBlock(date);
          return;
        }

        if (blockedData.availabilityExceptions.includes(date)) {
          await removeGlobalBlockException(date);
          showMessage(`Usunięto wyjątek dla dnia: ${date}`, 'success');
        } else {
          await deleteDayBlock(date);
          showMessage(`Odblokowano dzień: ${date}`, 'success');
        }
      } else {
        await saveDayBlock(date);
        showMessage(`Zablokowano dzień: ${date}`, 'success');
      }

      await refreshAdminCalendarData();
      await renderAdminCalendar();
      await renderAdminTimeSlots();
    } catch (error) {
      console.error('handleFullDayToggle error:', error);
      showMessage(error.message || 'Błąd operacji na dniu', 'error');
    }
  }

  async function handleTimeToggle(date, time, currentlyBlocked) {
    const booking = Array.isArray(window._bookingsData)
      ? window._bookingsData.find(item =>
          (item.booking_date || item.date || '') === date &&
          (item.booking_time || item.time || '') === time
        )
      : null;

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
      icon: hasBooking ? '⚠️' : (currentlyBlocked ? '✅' : '⛔'),
      confirmText: currentlyBlocked ? 'Odblokuj' : 'Zablokuj',
      cancelText: 'Anuluj'
    });

    if (!confirmed) return;

    try {
      if (currentlyBlocked) {
        await deleteTimeBlock(date, time);
        showMessage(`Odblokowano godzinę ${time} w dniu ${date}`, 'success');
      } else {
        await saveTimeBlock(date, time);
        showMessage(`Zablokowano godzinę ${time} w dniu ${date}`, 'success');
      }

      await refreshAdminCalendarData();
      await renderAdminCalendar();
      await renderAdminTimeSlots();
    } catch (error) {
      console.error('handleTimeToggle error:', error);
      showMessage(error.message || 'Błąd operacji na godzinie', 'error');
    }
  }

  async function handleBlockRange() {
    const from = document.getElementById('range-from')?.value || '';
    const to = document.getElementById('range-to')?.value || '';

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
      icon: '🔴'
    });

    if (!confirmed) return;

    try {
      const dates = getDatesInRange(from, to);

      for (const date of dates) {
        await saveDayBlock(date);
      }

      showMessage(`Zablokowano zakres: ${from} → ${to} (${dates.length} dni)`, 'success');

      await refreshAdminCalendarData();
      await renderAdminCalendar();

      if (selectedAdminDate && selectedAdminDate >= from && selectedAdminDate <= to) {
        await renderAdminTimeSlots();
      }
    } catch (error) {
      console.error('handleBlockRange error:', error);
      showMessage(error.message || 'Błąd przy blokowaniu zakresu', 'error');
    }
  }

  async function handleUnblockRange() {
    try {
      const from = document.getElementById('range-from')?.value || '';
      const to = document.getElementById('range-to')?.value || '';

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
        icon: '🟢'
      });

      if (!confirmed) return;

      const dates = getDatesInRange(from, to);

      for (const date of dates) {
        await deleteDayBlock(date, true);
      }

      await loadBlockedData();
      await renderAdminCalendar();
      await renderAdminTimeSlots();

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
        csrf: window.CSRF_TOKEN
      })
    });

    const text = await res.text();
    const data = parseJsonSafe(text);

    if (!res.ok || !data?.success) {
      if (isDuplicateResponse(data)) {
        return;
      }

      throw new Error(data?.error || `Nie udało się zablokować dnia ${date}`);
    }
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
        csrf: window.CSRF_TOKEN
      })
    });

    const text = await res.text();
    const data = parseJsonSafe(text);

    if (!res.ok || !data?.success) {
      if (isDuplicateResponse(data)) {
        return;
      }

      throw new Error(data?.error || `Nie udało się zablokować godziny ${date} ${time}`);
    }
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
        time: null
      })
    });

    const text = await res.text();
    const data = parseJsonSafe(text);

    if (!res.ok || !data?.success) {
      if (silent) return;
      throw new Error(data?.error || `Nie udało się odblokować dnia ${date}`);
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
        time
      })
    });

    const text = await res.text();
    const data = parseJsonSafe(text);

    if (!res.ok || !data?.success) {
      throw new Error(data?.error || `Nie udało się odblokować godziny ${date} ${time}`);
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
        deleteAllTimes: true
      })
    });
  }

  function isDateFullyBlocked(dateStr) {
    const isException = blockedData.availabilityExceptions.includes(dateStr);

    if (isException) {
      return blockedData.blockedDates.includes(dateStr);
    }

    return isGlobalRuleBlocked(dateStr) || blockedData.blockedDates.includes(dateStr);
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
    return Array.isArray(blockedData.blockedTimes[dateStr])
      ? blockedData.blockedTimes[dateStr]
      : [];
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
      throw new Error('Nieprawidłowa przerwa konsultacji');
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
    const debugText = String(data?.debug || '');
    return data?.httpCode === 409 || debugText.includes('duplicate key value');
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
      title: 'Odblokowanie mimo globalnych blokad',
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

          showMessage(`Dzień ${date} odblokowany mimo globalnych blokad`, 'success');

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