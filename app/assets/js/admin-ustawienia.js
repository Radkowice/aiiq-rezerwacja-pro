(() => {
  let adminSettingsInitialized = false;

  window.initAdminSettingsModule = async function initAdminSettingsModule() {
    if (adminSettingsInitialized) return;

    const section = document.querySelector('section[data-section="ustawienia"]') || document;

    adminSettingsInitialized = true;
    bindSmartNumberInputs(section);
    await loadSettingsForm();
    bindSmartNumberInputs(section);
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


  function bindSmartNumberInputs(root = document) {
    root.querySelectorAll('input[type="number"]').forEach((input) => {
      if (input.dataset.smartNumberBound === '1') {
        return;
      }

      input.dataset.smartNumberBound = '1';

      input.addEventListener('focus', () => {
        if (input.value === '0') {
          input.select();
        }
      });

      input.addEventListener('input', () => {
        const normalized = normalizeSmartNumberInputValue(input.value, isDecimalNumberInput(input));

        if (normalized !== input.value) {
          input.value = normalized;
        }
      });

      input.addEventListener('blur', () => {
        if (input.value.trim() !== '' || !input.required) {
          return;
        }

        const min = input.getAttribute('min');
        input.value = min !== null && min !== '' ? min : '0';
      });
    });
  }

  function isDecimalNumberInput(input) {
    const step = String(input.getAttribute('step') || '');
    return step === 'any' || step.includes('.') || step.includes(',');
  }

  function normalizeSmartNumberInputValue(value, allowDecimal) {
    let text = String(value || '');

    if (text === '' || text === '-') {
      return text;
    }

    const sign = text.startsWith('-') ? '-' : '';

    if (sign) {
      text = text.slice(1);
    }

    if (allowDecimal) {
      const separatorMatch = text.match(/[.,]/);

      if (separatorMatch) {
        const separator = separatorMatch[0];
        const separatorIndex = text.indexOf(separator);
        const integerPart = text.slice(0, separatorIndex);
        const decimalPart = text.slice(separatorIndex + 1);
        const normalizedInteger = normalizeIntegerLeadingZeros(integerPart);

        return `${sign}${normalizedInteger}${separator}${decimalPart}`;
      }
    }

    return `${sign}${normalizeIntegerLeadingZeros(text)}`;
  }

  function normalizeIntegerLeadingZeros(value) {
    const digits = String(value || '');

    if (!/^\d+$/.test(digits)) {
      return digits;
    }

    const normalized = digits.replace(/^0+(?=\d)/, '');

    return normalized === '' ? '0' : normalized;
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

