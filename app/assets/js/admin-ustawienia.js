(() => {
  let adminSettingsInitialized = false;

  window.initAdminSettingsModule = async function initAdminSettingsModule() {
    if (adminSettingsInitialized) return;

    adminSettingsInitialized = true;
    await loadSettingsForm();
  };

  function splitBookingBufferMinutes(totalMinutes) {
    const minutes = Math.max(0, parseInt(totalMinutes || 0, 10) || 0);

    if (minutes >= 1440 && minutes % 1440 === 0) {
      return {
        value: minutes / 1440,
        unit: 'days'
      };
    }

    if (minutes >= 60 && minutes % 60 === 0) {
      return {
        value: minutes / 60,
        unit: 'hours'
      };
    }

    return {
      value: minutes,
      unit: 'minutes'
    };
  }
  
  async function loadSettingsForm() {
  try {
    const data = await requestSettingsJson('/api/system/settings.php', {
      cache: 'no-store'
    });

    if (!data?.success || !data?.settings) {
      throw new Error(data?.error || 'Nie udało się pobrać ustawień');
    }

    const minNotice = splitBookingBufferMinutes(data.settings.booking_buffer);
    const minNoticeValue = document.getElementById('booking-min-notice-value');
    const minNoticeUnit = document.getElementById('booking-min-notice-unit');

    if (minNoticeValue) {
      minNoticeValue.value = minNotice.value;
    }

    if (minNoticeUnit) {
      minNoticeUnit.value = minNotice.unit;
    }

    document.getElementById('work-start').value = data.settings.work_start || '00:00';
    document.getElementById('work-end').value = data.settings.work_end || '23:59';
    document.getElementById('consultation-duration').value = data.settings.consultation_duration || 60;
    document.getElementById('consultation-break').value = data.settings.consultation_break || 0;
    document.getElementById('booking-start-month-offset').value = data.settings.booking_start_month_offset || 0;
    document.getElementById('booking-month-range').value = data.settings.booking_month_range || 1;
    
  } catch (error) {
    console.error('loadSettingsForm error:', error);
    showSettingsMessage('Nie udało się wczytać ustawień', 'error');
  }
}

  async function requestSettingsJson(url, options = {}) {
    if (typeof window.apiFetch === 'function') {
      return await window.apiFetch(url, options);
    }

    if (typeof window.adminRequest !== 'function') {
      throw new Error('Brak helpera requestów administracyjnych.');
    }

    const response = await window.adminRequest(url, options);
    return await response.json();
  }

  function isValidTimeRange(start, end) {
    return timeToMinutes(end) > timeToMinutes(start);
  }

  function timeToMinutes(time) {
    const [hours, minutes] = String(time).split(':').map(Number);
    return (hours * 60) + minutes;
  }

  function showSettingsMessage(text, type = 'success') {
    const messageEl = document.getElementById('settings-message');
    if (!messageEl) return;

    messageEl.textContent = text;
    messageEl.classList.remove('success', 'error');
    messageEl.classList.add(type);
    messageEl.style.display = 'block';
  }
})();

