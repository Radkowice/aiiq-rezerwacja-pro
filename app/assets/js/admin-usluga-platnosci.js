(function () {
  let initialized = false;

  const state = {
    services: [],
    staff: [],
    globalSettings: {},
    selectedId: null,
    loading: false,
    saving: false,
    savingGlobal: false,
    deactivatingId: null,
    deletingId: null,
    loadSequence: 0,
    searchQuery: '',
  };

  const els = {};

  window.initAdminServicePaymentsModule = async function initAdminServicePaymentsModule() {
    if (initialized) return;

    const section = document.querySelector('section[data-section="usluga-platnosci"]');
    if (!section) return;

    initialized = true;
    cacheElements(section);
    normalizeRefreshButtonLayout();
    bindEvents();
    bindSmartNumberInputs(section);
    await loadServiceCompanyNamePreview();
    await loadServicePaymentsData();
  };

  window.refreshAdminServicePaymentsData = function refreshAdminServicePaymentsData() {
    return loadServicePaymentsData({ forceRefresh: true });
  };

  function cacheElements(section) {
    els.section = section;
    els.refreshButton = section.querySelector('#service-payments-refresh-btn');
    els.newButton = section.querySelector('#service-new-btn');
    els.searchInput = section.querySelector('#service-search-input');
    els.list = section.querySelector('#service-list');
    els.listMessage = section.querySelector('#service-list-message');
    els.form = section.querySelector('#service-form');
    els.formTitle = section.querySelector('#service-form-title');
    els.formMessage = section.querySelector('#service-form-message');
    els.saveButton = section.querySelector('#service-save-btn');
    els.staffMessage = section.querySelector('#service-staff-message');
    els.staffList = section.querySelector('#service-staff-list');
    els.id = section.querySelector('#service-id');
    els.name = section.querySelector('#service-name');
    els.description = section.querySelector('#service-description');
    els.durationMinutes = section.querySelector('#service-duration-minutes');
    els.breakMinutes = section.querySelector('#service-break-minutes');
    els.minNoticeValue = section.querySelector('#service-min-notice-value');
    els.minNoticeUnit = section.querySelector('#service-min-notice-unit');
    els.priceAmount = section.querySelector('#service-price-amount');
    els.priceCurrency = section.querySelector('#service-price-currency');
    els.paymentsEnabled = section.querySelector('#service-payments-enabled');
    els.paymentMessage = section.querySelector('#service-payment-message');
    els.isActive = section.querySelector('#service-is-active');
    els.visibleOnFront = section.querySelector('#service-visible-on-front');
    els.sortOrder = section.querySelector('#service-sort-order');
    els.globalName = section.querySelector('#global-service-name');
    els.globalDescription = section.querySelector('#global-service-description');
    els.globalPrice = section.querySelector('#global-service-price');
    els.globalCurrency = section.querySelector('#global-service-currency');
    els.globalPaymentRequired = section.querySelector('#global-service-payment-required');
    els.globalPaymentTimeLimitValue = section.querySelector('#global-payment-time-limit-value');
    els.globalPaymentTimeLimitUnit = section.querySelector('#global-payment-time-limit-unit');
    els.globalPaymentMessage = section.querySelector('#global-service-payment-message');
    els.globalMessage = section.querySelector('#global-service-message');
    els.globalSaveButton = section.querySelector('#global-service-save-btn');
  }

  function normalizeRefreshButtonLayout() {
    if (!els.refreshButton) return;

    els.refreshButton.type = 'button';
    els.refreshButton.classList.add('btn', 'btn-secondary', 'service-payments-refresh-btn');
    els.refreshButton.style.width = 'auto';
    els.refreshButton.style.minWidth = '112px';
    els.refreshButton.style.maxWidth = '180px';
    els.refreshButton.style.flex = '0 0 auto';
    els.refreshButton.style.marginLeft = 'auto';
    els.refreshButton.style.display = 'inline-flex';
    els.refreshButton.style.alignItems = 'center';
    els.refreshButton.style.justifyContent = 'center';
    els.refreshButton.style.whiteSpace = 'nowrap';
  }

  function bindEvents() {
    els.newButton?.addEventListener('click', () => {
      populateForm(null);
      setMessage(els.formMessage, '', '');
      els.name?.focus();
    });

    els.form?.addEventListener('submit', saveService);
    els.saveButton?.addEventListener('click', saveService);
    els.refreshButton?.addEventListener('click', refreshServicePaymentsFromButton);
    els.globalSaveButton?.addEventListener('click', saveGlobalServiceSettings);
    els.searchInput?.addEventListener('input', updateServiceSearch);
    els.searchInput?.addEventListener('change', updateServiceSearch);

    window.addEventListener('aiiq:staff-updated', refreshStaffAfterUpdate);
    window.addEventListener('aiiq:section-shown', (event) => {
      if (event?.detail?.section === 'usluga-platnosci') {
        loadServicePaymentsData({ forceRefresh: true });
      }
    });
  }

  async function loadServicePaymentsData(options = {}) {
    if (state.loading && !options.forceRefresh) return;

    const loadId = state.loadSequence + 1;
    state.loadSequence = loadId;

    state.loading = true;
    setMessage(els.listMessage, 'Ładowanie usług...', 'muted');

    try {
      const [servicesData, staffData] = await Promise.all([
        requestJson(cacheBustUrl('/api/services/list.php'), { method: 'GET', cache: 'no-store' }),
        requestJson(cacheBustUrl('/api/staff/list.php'), { method: 'GET', cache: 'no-store' }),
        loadGlobalServiceSettings({ forceRefresh: Boolean(options.forceRefresh) }),
      ]);

      if (loadId !== state.loadSequence) {
        return;
      }

      state.services = Array.isArray(servicesData.services) ? servicesData.services.map(normalizeService) : [];
      state.staff = Array.isArray(staffData.staff) ? staffData.staff.map(normalizeStaff) : [];

      mergeAssignedStaffFromServices();
      renderServiceList();
      populateForm(findSelectedService());
      setMessage(els.staffMessage, '', '');

      if (state.services.length === 0) {
        setMessage(els.listMessage, 'Brak usług. Dodaj pierwszą usługę.', 'muted');
      } else if (state.searchQuery.trim() !== '' && getFilteredServices().length === 0) {
        setMessage(els.listMessage, 'Brak usług pasujących do wyszukiwania.', 'muted');
      } else {
        setMessage(els.listMessage, '', '');
      }
    } catch (error) {
      if (loadId !== state.loadSequence) {
        return;
      }

      setMessage(els.listMessage, error.message || 'Nie udało się załadować usług.', 'error');
      setMessage(els.staffMessage, 'Nie udało się załadować pracowników do przypisania.', 'error');
      renderServiceList();
      renderStaffCheckboxes([]);
    } finally {
      if (loadId === state.loadSequence) {
        state.loading = false;
      }
    }
  }

  async function refreshStaffAfterUpdate() {
    const selectedStaffIds = getCurrentSelectedStaffIds();
    setMessage(els.staffMessage, 'Odświeżanie listy pracowników...', 'muted');

    try {
      const data = await requestJson('/api/staff/list.php', { method: 'GET' });
      state.staff = Array.isArray(data.staff) ? data.staff.map(normalizeStaff) : [];

      mergeAssignedStaffFromServices();
      renderStaffCheckboxes(selectedStaffIds);
      setMessage(els.staffMessage, '', '');
    } catch (error) {
      setMessage(els.staffMessage, error.message || 'Nie udało się odświeżyć pracowników do przypisania.', 'error');
    }
  }

  async function refreshServicePaymentsFromButton() {
    if (!els.refreshButton) return;

    const originalText = els.refreshButton.textContent;
    setButtonState(els.refreshButton, 'Odświeżanie...', true);

    try {
      await loadServicePaymentsData({ forceRefresh: true });
      setButtonState(els.refreshButton, 'Odświeżono', true);
    } catch (error) {
      setMessage(els.listMessage, error.message || 'Nie udało się odświeżyć danych usług.', 'error');
      setButtonState(els.refreshButton, 'Błąd', true);
    } finally {
      window.setTimeout(() => {
        if (!els.refreshButton) return;
        els.refreshButton.textContent = originalText || 'Odśwież';
        els.refreshButton.disabled = false;
      }, 900);
    }
  }

  async function loadGlobalServiceSettings(options = {}) {
    try {
      const url = options.forceRefresh ? cacheBustUrl('/api/system/service-settings.php') : '/api/system/service-settings.php';
      const data = await requestJson(url, {
        method: 'GET',
        cache: options.forceRefresh ? 'no-store' : 'default',
      });
      fillGlobalSettings(data.settings || {});
    } catch (error) {
      fillGlobalSettings({});
    }
  }

  function cacheBustUrl(url) {
    const separator = url.includes('?') ? '&' : '?';
    return `${url}${separator}_=${Date.now()}`;
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

  function fillGlobalSettings(settings) {
    state.globalSettings = { ...settings };

    setFieldValue(els.globalName, settings.service_name || '');
    setFieldValue(els.globalDescription, settings.service_description || '');
    setFieldValue(els.globalPrice, settings.price_amount ?? '');
    setFieldValue(els.globalCurrency, settings.price_currency || 'PLN');
    setCheckboxValue(els.globalPaymentRequired, Boolean(settings.payment_required));
    setFieldValue(els.globalPaymentTimeLimitValue, normalizePaymentTimeLimitValue(settings.payment_time_limit_value));
    setFieldValue(els.globalPaymentTimeLimitUnit, normalizePaymentTimeLimitUnit(settings.payment_time_limit_unit));
    setFieldValue(els.globalPaymentMessage, settings.payment_message || '');
  }

  function readGlobalSettingsPayload() {
    const priceCurrency = (els.globalCurrency.value.trim() || 'PLN').toUpperCase();
    const priceAmount = readMoneyValue(els.globalPrice, 'Globalna cena');
    const paymentRequired = Boolean(els.globalPaymentRequired.checked);
    const paymentTimeLimitValue = readInteger(els.globalPaymentTimeLimitValue, 'Termin płatności', 1);
    const paymentTimeLimitUnit = els.globalPaymentTimeLimitUnit.value;

    if (!/^[A-Z]{3}$/.test(priceCurrency)) {
      throw new Error('Waluta musi mieć 3 znaki.');
    }

    if (!['hours', 'days'].includes(paymentTimeLimitUnit)) {
      throw new Error('Wybierz poprawną jednostkę terminu płatności.');
    }

    if (paymentRequired && (priceAmount === null || Number(priceAmount) <= 0)) {
      throw new Error('Podaj cenę usługi, jeśli płatność online jest włączona.');
    }

    return {
      service_name: els.globalName.value.trim(),
      service_description: els.globalDescription.value.trim(),
      price_amount: priceAmount || 0,
      price_currency: priceCurrency,
      payment_required: paymentRequired,
      payment_time_limit_value: paymentTimeLimitValue,
      payment_time_limit_unit: paymentTimeLimitUnit,
      payment_message: els.globalPaymentMessage.value.trim(),
    };
  }

  async function saveGlobalServiceSettings() {
    if (state.savingGlobal) return;

    let payload;

    try {
      payload = readGlobalSettingsPayload();
    } catch (error) {
      setMessage(els.globalMessage, error.message, 'error');
      setButtonState(els.globalSaveButton, 'Błąd');
      resetButtonLater(els.globalSaveButton, 'Zapisz ustawienia globalne');
      return;
    }

    state.savingGlobal = true;
    const originalText = els.globalSaveButton.textContent;
    setButtonState(els.globalSaveButton, 'Zapisywanie...', true);
    setMessage(els.globalMessage, 'Zapisywanie ustawień globalnych...', 'muted');

    try {
      const data = await requestJson('/api/system/service-settings.php', {
        method: 'POST',
        body: JSON.stringify(payload),
      });

      fillGlobalSettings(data.settings || payload);
      setMessage(els.globalMessage, 'Ustawienia globalne zostały zapisane.', 'success');
      setButtonState(els.globalSaveButton, 'Zapisano', true);
    } catch (error) {
      setMessage(els.globalMessage, error.message || 'Nie udało się zapisać ustawień globalnych.', 'error');
      setButtonState(els.globalSaveButton, 'Błąd', true);
    } finally {
      state.savingGlobal = false;
      resetButtonLater(els.globalSaveButton, originalText);
    }
  }

  async function loadServiceCompanyNamePreview() {
    const previewEl = document.getElementById('service-company-name-preview');

    if (!previewEl) return;

    try {
      const data = await requestJson('/api/auth/me.php', { method: 'GET' });
      const companyName =
        data?.branding?.client_name ||
        data?.user?.client_name ||
        data?.user?.company_name ||
        data?.client_name ||
        '';

      previewEl.textContent = companyName || 'Nazwę firmy ustawisz w zakładce Konto';
    } catch (error) {
      previewEl.textContent = 'Nazwę firmy ustawisz w zakładce Konto';
    }
  }

  function mergeAssignedStaffFromServices() {
    const staffById = new Map(state.staff.map((person) => [person.id, person]));

    state.services.forEach((service) => {
      service.staff.forEach((person) => {
        if (person.id && !staffById.has(person.id)) {
          staffById.set(person.id, normalizeStaff(person));
        }
      });
    });

    state.staff = Array.from(staffById.values()).sort((a, b) => {
      const activeDiff = Number(b.is_active) - Number(a.is_active);
      if (activeDiff !== 0) return activeDiff;
      return a.display_name.localeCompare(b.display_name, 'pl');
    });
  }

  function normalizeService(row) {
    return {
      id: row?.id ? String(row.id) : (row?.service_id ? String(row.service_id) : ''),
      tenant_id: row?.tenant_id ? String(row.tenant_id) : '',
      name: row?.name ? String(row.name) : '',
      description: row?.description ? String(row.description) : '',
      duration_minutes: normalizeInteger(row?.duration_minutes, 60),
      break_minutes: normalizeInteger(row?.break_minutes, 0),
      booking_buffer_minutes: normalizeInteger(row?.booking_buffer_minutes, 0),
      price_amount: row?.price_amount !== null && row?.price_amount !== undefined ? String(row.price_amount) : '',
      price_currency: row?.price_currency ? String(row.price_currency).toUpperCase() : 'PLN',
      payments_enabled: row?.payments_enabled === true,
      payment_message: row?.payment_message ? String(row.payment_message) : '',
      is_active: row?.is_active === true,
      visible_on_front: row?.visible_on_front === true,
      sort_order: normalizeInteger(row?.sort_order, 0),
      staff_ids: Array.isArray(row?.staff_ids) ? row.staff_ids.map(String) : [],
      staff: Array.isArray(row?.staff) ? row.staff.map(normalizeStaff) : [],
    };
  }

  function normalizeStaff(row) {
    return {
      id: row?.id ? String(row.id) : '',
      display_name: row?.display_name ? String(row.display_name) : '',
      email: row?.email ? String(row.email) : '',
      phone: row?.phone ? String(row.phone) : '',
      description: row?.description ? String(row.description) : '',
      is_active: row?.is_active === true,
      visible_on_front: row?.visible_on_front === true,
    };
  }

  function getStaffDescriptionLabel(person) {
    const description = String(person?.description || '').trim();

    if (!description) {
      return 'Brak opisu osoby';
    }

    return description.length > 90
      ? `${description.slice(0, 87).trim()}...`
      : description;
  }

  function normalizeInteger(value, defaultValue) {
    const number = Number.parseInt(String(value ?? ''), 10);
    return Number.isFinite(number) ? number : defaultValue;
  }

  function normalizePaymentTimeLimitValue(value) {
    const number = normalizeInteger(value, 48);
    return number > 0 ? number : 48;
  }

  function normalizePaymentTimeLimitUnit(value) {
    return ['hours', 'days'].includes(String(value || '')) ? String(value) : 'hours';
  }

  function findSelectedService() {
    return state.services.find((service) => service.id === state.selectedId) || null;
  }

  function updateServiceSearch() {
    state.searchQuery = els.searchInput?.value || '';
    renderServiceList();
  }

  function getServiceSearchText(service) {
    return [
      service.name,
      service.description,
      service.is_active ? 'aktywna' : 'nieaktywna wyłączona',
    ].join(' ').toLowerCase();
  }

  function getFilteredServices() {
    const query = state.searchQuery.trim().toLowerCase();

    if (!query) {
      return state.services;
    }

    return state.services.filter((service) => getServiceSearchText(service).includes(query));
  }

  function applySavedServiceResponse(data) {
    const freshStaffIds = Array.isArray(data?.staff_ids)
      ? data.staff_ids.map(String)
      : (Array.isArray(data?.service?.staff_ids) ? data.service.staff_ids.map(String) : null);
    const responseService = data?.service && typeof data.service === 'object' ? data.service : null;
    const savedServiceId = String(
      data?.service_id ||
      responseService?.id ||
      responseService?.service_id ||
      state.selectedId ||
      ''
    ).trim();

    if (!savedServiceId || (!responseService && freshStaffIds === null)) {
      return null;
    }

    const existingIndex = state.services.findIndex((service) => service.id === savedServiceId);
    const existingService = existingIndex >= 0 ? state.services[existingIndex] : null;
    const mergedRaw = {
      ...(existingService || {}),
      ...(responseService || {}),
      id: savedServiceId,
    };

    if (freshStaffIds !== null) {
      mergedRaw.staff_ids = freshStaffIds;

      if (!Array.isArray(responseService?.staff)) {
        const freshStaffSet = new Set(freshStaffIds);
        mergedRaw.staff = Array.isArray(existingService?.staff)
          ? existingService.staff.filter((person) => freshStaffSet.has(person.id))
          : [];
      }
    }

    const updatedService = normalizeService(mergedRaw);

    if (existingIndex >= 0) {
      state.services[existingIndex] = updatedService;
    } else {
      state.services.unshift(updatedService);
    }

    state.selectedId = updatedService.id;
    mergeAssignedStaffFromServices();
    renderServiceList();

    console.debug('[services] active service before render', {
      service_id: updatedService.id,
      staff_ids: updatedService.staff_ids,
    });

    populateForm(updatedService);

    return updatedService;
  }

  function dispatchServicesUpdatedEvent(service, data) {
    if (typeof window.dispatchEvent !== 'function') {
      return;
    }

    const detail = {
      service_id: service?.id || data?.service_id || '',
      staff_ids: Array.isArray(service?.staff_ids)
        ? service.staff_ids
        : (Array.isArray(data?.staff_ids) ? data.staff_ids.map(String) : []),
    };

    console.debug('[services] services-updated event', detail);
    window.dispatchEvent(new CustomEvent('aiiq:services-updated', { detail }));
  }

  function renderServiceList() {
    if (!els.list) return;

    if (state.services.length === 0) {
      els.list.innerHTML = '<div class="service-empty">Nie ma jeszcze żadnych usług.</div>';
      return;
    }

    const visibleServices = getFilteredServices();

    if (visibleServices.length === 0) {
      els.list.innerHTML = '';
      setMessage(els.listMessage, 'Brak usług pasujących do wyszukiwania.', 'muted');
      return;
    }

    if (
      state.searchQuery.trim() !== ''
      || els.listMessage?.textContent === 'Brak usług pasujących do wyszukiwania.'
    ) {
      setMessage(els.listMessage, '', '');
    }

    els.list.innerHTML = visibleServices.map((service) => {
      const selected = service.id === state.selectedId ? ' is-selected' : '';
      const inactive = service.is_active ? '' : ' is-inactive';
      const paymentLabel = service.payments_enabled && Number(service.price_amount) > 0
        ? `${escapeHtml(formatPrice(service.price_amount))} ${escapeHtml(service.price_currency)}`
        : 'bez płatności';
      const visibilityLabel = service.visible_on_front ? 'widoczna' : 'ukryta';
      const activeLabel = service.is_active ? 'aktywna' : 'wyłączona';
      const staffCount = service.staff_ids.length;
      const statusAction = service.is_active
        ? `<button type="button" class="btn btn-secondary service-deactivate-btn" data-action="deactivate" data-service-id="${escapeHtmlAttr(service.id)}">Wyłącz</button>`
        : '<button type="button" class="btn btn-secondary service-disabled-btn" disabled>Wyłączona</button>';
      const deleteAction = `<button type="button" class="btn btn-danger service-delete-btn" data-action="delete" data-service-id="${escapeHtmlAttr(service.id)}">Usuń usługę</button>`;

      return `
        <div class="service-list-item${selected}${inactive}" data-service-id="${escapeHtmlAttr(service.id)}">
          <div class="service-list-main service-list-content">
            <strong class="service-list-title">${escapeHtml(service.name || 'Bez nazwy')}</strong>
            <div class="service-list-meta">
              <span class="service-badge ${service.payments_enabled ? 'is-payment' : 'is-neutral'}">${paymentLabel}</span>
              <span class="service-badge is-neutral">${service.duration_minutes} min</span>
              <span class="service-badge is-neutral">${staffCount} ${formatStaffCount(staffCount)}</span>
              <span class="service-badge ${service.is_active ? 'is-active' : 'is-inactive'}">${activeLabel}</span>
              <span class="service-badge ${service.visible_on_front ? 'is-visible' : 'is-hidden'}">${visibilityLabel}</span>
            </div>
          </div>
          <div class="service-list-actions">
            <button type="button" class="btn btn-secondary service-edit-btn" data-action="edit" data-service-id="${escapeHtmlAttr(service.id)}">Edytuj</button>
            ${statusAction}
            ${deleteAction}
          </div>
        </div>
      `;
    }).join('');

    els.list.querySelectorAll('[data-action="edit"]').forEach((button) => {
      button.addEventListener('click', () => selectService(button.dataset.serviceId || ''));
    });

    els.list.querySelectorAll('[data-action="deactivate"]').forEach((button) => {
      button.addEventListener('click', () => deactivateService(button.dataset.serviceId || '', button));
    });

    els.list.querySelectorAll('[data-action="delete"]').forEach((button) => {
      button.addEventListener('click', () => deleteService(button.dataset.serviceId || '', button));
    });
  }

  function selectService(serviceId) {
    const service = state.services.find((item) => item.id === serviceId) || null;
    if (!service) return;

    state.selectedId = service.id;
    populateForm(service);
    setMessage(els.formMessage, '', '');
    renderServiceList();
  }

  function splitNoticeMinutes(totalMinutes) {
    const minutes = Math.max(0, parseInt(totalMinutes || 0, 10) || 0);

    if (minutes >= 1440 && minutes % 1440 === 0) {
      return {
        value: minutes / 1440,
        unit: 'days',
      };
    }

    if (minutes >= 60 && minutes % 60 === 0) {
      return {
        value: minutes / 60,
        unit: 'hours',
      };
    }

    return {
      value: minutes,
      unit: 'minutes',
    };
  }

  function readMinNoticeMinutes() {
    const value = Math.max(0, parseInt(els.minNoticeValue?.value || '0', 10) || 0);
    const unit = els.minNoticeUnit?.value || 'minutes';

    if (unit === 'days') {
      return value * 1440;
    }

    if (unit === 'hours') {
      return value * 60;
    }

    return value;
  }

  function populateForm(service) {
    const selected = service || null;
    state.selectedId = selected ? selected.id : null;

    setFieldValue(els.id, selected?.id || '');
    setFieldValue(els.name, selected?.name || '');
    setFieldValue(els.description, selected?.description || '');
    setFieldValue(els.durationMinutes, selected?.duration_minutes || 60);
    setFieldValue(els.breakMinutes, selected?.break_minutes || 0);

    const minNotice = splitNoticeMinutes(selected?.booking_buffer_minutes || 0);
    setFieldValue(els.minNoticeValue, minNotice.value);
    setFieldValue(els.minNoticeUnit, minNotice.unit);

    setFieldValue(els.priceAmount, selected?.price_amount || '');
    setFieldValue(els.priceCurrency, selected?.price_currency || 'PLN');
    setFieldValue(els.paymentMessage, selected?.payment_message || '');
    setFieldValue(els.sortOrder, selected?.sort_order || 0);
    setCheckboxValue(els.paymentsEnabled, selected ? selected.payments_enabled : false);
    setCheckboxValue(els.isActive, selected ? selected.is_active : true);
    setCheckboxValue(els.visibleOnFront, selected ? selected.visible_on_front : true);

    if (els.formTitle) {
      els.formTitle.textContent = selected ? 'Edycja usługi' : 'Nowa usługa';
    }

    renderStaffCheckboxes(selected ? selected.staff_ids : []);
  }

  function renderStaffCheckboxes(selectedStaffIds) {
    if (!els.staffList) return;

    const selectedSet = new Set((Array.isArray(selectedStaffIds) ? selectedStaffIds : []).map(String));
    console.debug('[services] rendered staff_ids', Array.from(selectedSet));
    const availableStaff = state.staff.filter((person) => person.is_active || selectedSet.has(person.id));

    if (availableStaff.length === 0) {
      els.staffList.classList.remove('is-scrollable');
      els.staffList.innerHTML = '<div class="service-empty">Brak aktywnych pracowników do przypisania.</div>';
      return;
    }

    els.staffList.classList.toggle('is-scrollable', availableStaff.length > 6);

    els.staffList.innerHTML = availableStaff.map((person) => {
      const isSelected = selectedSet.has(person.id);
      const isInactive = !person.is_active;
      const disabled = isInactive ? 'disabled' : '';
      const checked = isSelected ? 'checked' : '';
      const status = person.is_active ? 'aktywny' : 'nieaktywny, zostanie pominięty przy zapisie';
      const description = getStaffDescriptionLabel(person);

      return `
        <label class="service-staff-item ${isInactive ? 'is-inactive' : ''}">
          <input type="checkbox" value="${escapeHtmlAttr(person.id)}" ${checked} ${disabled}>
          <span>
            <strong>${escapeHtml(person.display_name || 'Bez nazwy')}</strong>
            <small>${escapeHtml(description)}</small>
            <em>${status}</em>
          </span>
        </label>
      `;
    }).join('');
  }

  function getCurrentSelectedStaffIds() {
    if (els.staffList) {
      return Array.from(els.staffList.querySelectorAll('input[type="checkbox"]:checked'))
        .map((input) => input.value)
        .filter(Boolean);
    }

    const selectedService = findSelectedService();
    return selectedService ? selectedService.staff_ids : [];
  }

  function readServicePayload() {
    const name = els.name.value.trim();
    const description = els.description.value.trim();
    const durationMinutes = readInteger(els.durationMinutes, 'Czas trwania', 1);
    const breakMinutes = readInteger(els.breakMinutes, 'Przerwa po usłudze', 0, 1440);
    const bookingBufferMinutes = readMinNoticeMinutes();
    const sortOrderValue = String(els.sortOrder.value || '').trim();
    const rawSortOrder = Number.parseInt(sortOrderValue, 10);

    if (sortOrderValue !== '' && !/^-?\d+$/.test(sortOrderValue)) {
      throw new Error('Kolejność musi być liczbą całkowitą.');
    }

    if (Number.isFinite(rawSortOrder) && rawSortOrder < 0) {
      throw new Error('Kolejność nie może być mniejsza niż 0.');
    }

    const sortOrder = readInteger(els.sortOrder, 'Kolejność', 0, 1000000);
    const priceAmount = readPrice();
    const priceCurrency = (els.priceCurrency.value.trim() || 'PLN').toUpperCase();
    const paymentsEnabled = Boolean(els.paymentsEnabled.checked);

    if (name === '') {
      throw new Error('Wpisz nazwę usługi.');
    }

    if (name.length > 160) {
      throw new Error('Nazwa usługi może mieć maksymalnie 160 znaków.');
    }

    if (description.length > 2000) {
      throw new Error('Opis usługi może mieć maksymalnie 2000 znaków.');
    }

    if (!/^[A-Z]{3}$/.test(priceCurrency)) {
      throw new Error('Waluta musi mieć 3 znaki.');
    }

    if (paymentsEnabled && (priceAmount === null || Number(priceAmount) <= 0)) {
      throw new Error('Podaj cenę usługi, jeśli płatność online jest włączona.');
    }

    const staffIds = Array.from(els.staffList.querySelectorAll('input[type="checkbox"]:checked:not(:disabled)'))
      .map((input) => input.value)
      .filter(Boolean);

    return {
      id: state.selectedId || null,
      name,
      description,
      duration_minutes: durationMinutes,
      break_minutes: breakMinutes,
      booking_buffer_minutes: bookingBufferMinutes,
      price_amount: priceAmount,
      price_currency: priceCurrency,
      payments_enabled: paymentsEnabled,
      payment_message: els.paymentMessage.value.trim(),
      is_active: Boolean(els.isActive.checked),
      visible_on_front: Boolean(els.visibleOnFront.checked),
      sort_order: sortOrder,
      staff_ids: staffIds,
    };
  }

  function readInteger(input, label, min, max = null) {
    const value = Number.parseInt(String(input.value || ''), 10);
    const hasMax = max !== null && max !== undefined;

    if (!Number.isFinite(value) || value < min || (hasMax && value > max)) {
      if (hasMax) {
        throw new Error(`${label} musi mieć wartość od ${min} do ${max}.`);
      }

      throw new Error(`${label} musi mieć wartość od ${min}.`);
    }

    return value;
  }

  function readPrice() {
    return readMoneyValue(els.priceAmount, 'Cena');
  }

  function readMoneyValue(input, label) {
    const raw = input.value.trim().replace(',', '.');

    if (raw === '') {
      return null;
    }

    if (!/^\d+(?:\.\d{1,2})?$/.test(raw)) {
      throw new Error(`${label} może mieć maksymalnie 2 miejsca po przecinku.`);
    }

    return Number(raw).toFixed(2);
  }

  async function saveService(event) {
    event?.preventDefault();
    event?.stopPropagation();

    if (state.saving) return;

    let payload;

    try {
      payload = readServicePayload();
    } catch (error) {
      setMessage(els.formMessage, error.message, 'error');
      setButtonState(els.saveButton, 'Błąd');
      resetButtonLater(els.saveButton, 'Zapisz usługę');
      return;
    }

    state.saving = true;
    const originalText = els.saveButton.textContent;
    setButtonState(els.saveButton, 'Zapisywanie...', true);
    setMessage(els.formMessage, 'Zapisywanie usługi...', 'muted');

    try {
      const data = await requestJson('/api/services/save.php?debug=1', {
        method: 'POST',
        body: JSON.stringify(payload),
      });

      state.selectedId = data.service_id || state.selectedId;
      console.debug('[services] save response staff_ids', data.staff_ids || data.service?.staff_ids || []);
      const updatedService = applySavedServiceResponse(data);
      dispatchServicesUpdatedEvent(updatedService, data);
      setMessage(els.formMessage, 'Usługa została zapisana.', 'success');
      setButtonState(els.saveButton, 'Zapisano', true);
    } catch (error) {
      setMessage(els.formMessage, error.message || 'Nie udało się zapisać usługi.', 'error');
      setButtonState(els.saveButton, 'Błąd', true);
    } finally {
      state.saving = false;
      resetButtonLater(els.saveButton, originalText);
    }
  }

  async function deactivateService(serviceId, button) {
    const service = state.services.find((item) => item.id === serviceId) || null;

    if (!service || state.deactivatingId) return;

    if (typeof window.openAdminConfirm !== 'function') {
      setMessage(els.listMessage, 'Nie można pokazać okna potwierdzenia.', 'error');
      return;
    }

    const confirmed = await window.openAdminConfirm({
      title: 'Wyłącz usługę',
      message: `Czy na pewno chcesz wyłączyć usługę "${service.name}"? Usługa zostanie ukryta na froncie, ale pozostanie w historii.`,
      confirmText: 'Wyłącz usługę',
      cancelText: 'Anuluj',
      variant: 'danger',
      icon: '!',
    });

    if (!confirmed) return;

    state.deactivatingId = serviceId;
    const originalText = button ? button.textContent : '';

    if (button) {
      button.disabled = true;
      button.textContent = 'Wyłączanie...';
    }

    setMessage(els.listMessage, 'Wyłączanie usługi...', 'muted');

    try {
      await requestJson('/api/services/deactivate.php', {
        method: 'POST',
        body: JSON.stringify({ id: serviceId }),
      });

      setMessage(els.listMessage, 'Usługa została wyłączona.', 'success');
      await loadServicePaymentsData({ forceRefresh: true });
    } catch (error) {
      setMessage(els.listMessage, error.message || 'Nie udało się wyłączyć usługi.', 'error');

      if (button) {
        button.disabled = false;
        button.textContent = originalText || 'Wyłącz';
      }
    } finally {
      state.deactivatingId = null;
    }
  }

  function serviceDeleteBlockMessage() {
    return 'Nie można usunąć tej usługi.\n'
      + 'Usługę możesz usunąć dopiero wtedy, gdy nie będzie miała przypisanych rezerwacji ani pracowników.\n'
      + 'Na ten moment dezaktywuj usługę, aby klienci nie mogli tworzyć nowych rezerwacji dla tej usługi.\n'
      + 'Przed usunięciem:\n\n'
      + '1. odłącz pracowników od tej usługi,\n'
      + '2. upewnij się, że nie ma aktywnych/przyszłych rezerwacji przypisanych do tej usługi.';
  }

  async function showServiceDeleteBlockInfo() {
    const message = serviceDeleteBlockMessage();

    if (typeof window.openAdminConfirm === 'function') {
      await window.openAdminConfirm({
        title: 'Nie można usunąć usługi',
        message,
        confirmText: 'Zamknij',
        cancelText: 'Zamknij',
        variant: 'danger',
        icon: '!',
        showCancel: false,
      });
      return;
    }

    setMessage(els.listMessage, message, 'error');
  }

  async function showServiceDeleteConfirm() {
    if (typeof window.openAdminConfirm !== 'function') {
      setMessage(els.listMessage, 'Nie można pokazać okna potwierdzenia.', 'error');
      return false;
    }

    return window.openAdminConfirm({
      title: 'Usuń usługę',
      message: 'Czy na pewno chcesz usunąć usługę?\nTa operacja jest nieodwracalna.',
      confirmText: 'Usuń usługę',
      cancelText: 'Anuluj',
      variant: 'danger',
      icon: '!',
    });
  }

  function isServiceDeleteBlockedError(error) {
    const data = error && error.data ? error.data : null;
    const reason = String(data?.reason || data?.code || '').toLowerCase();

    return reason === 'service_has_staff'
      || reason === 'service_has_active_bookings'
      || Boolean(data?.has_staff)
      || Boolean(data?.has_active_bookings);
  }

  async function deleteService(serviceId, button) {
    const service = state.services.find((item) => item.id === serviceId) || null;

    if (!service || state.deletingId) return;

    state.deletingId = serviceId;
    const originalText = button ? button.textContent : '';

    if (button) {
      button.disabled = true;
      button.textContent = 'Sprawdzanie...';
    }

    setMessage(els.listMessage, 'Sprawdzanie, czy usługę można usunąć...', 'muted');

    try {
      await requestJson('/api/services/delete.php', {
        method: 'POST',
        body: JSON.stringify({ id: serviceId, check_only: true }),
      });

      const confirmed = await showServiceDeleteConfirm();

      if (!confirmed) {
        if (button) {
          button.disabled = false;
          button.textContent = originalText || 'Usuń usługę';
        }

        setMessage(els.listMessage, '', '');
        return;
      }

      if (button) {
        button.textContent = 'Usuwanie...';
      }

      setMessage(els.listMessage, 'Usuwanie usługi...', 'muted');

      const data = await requestJson('/api/services/delete.php', {
        method: 'POST',
        body: JSON.stringify({ id: serviceId }),
      });

      if (state.selectedId === serviceId) {
        state.selectedId = null;
      }

      dispatchServicesUpdatedEvent(null, { service_id: serviceId, deleted: true });
      setMessage(els.listMessage, data.message || 'Usługa została usunięta.', 'success');
      await loadServicePaymentsData({ forceRefresh: true });
    } catch (error) {
      if (isServiceDeleteBlockedError(error)) {
        setMessage(els.listMessage, serviceDeleteBlockMessage(), 'error');

        await showServiceDeleteBlockInfo();
      } else {
        setMessage(els.listMessage, error.message || 'Nie udało się usunąć usługi.', 'error');
      }

      if (button) {
        button.disabled = false;
        button.textContent = originalText || 'Usuń usługę';
      }
    } finally {
      state.deletingId = null;
    }
  }

  async function requestJson(url, options = {}) {
    if (typeof window.apiFetch === 'function') {
      const data = await window.apiFetch(url, {
        headers: {
          Accept: 'application/json',
          'Content-Type': 'application/json',
          ...(options.headers || {}),
        },
        ...options,
      });

      if (!data || data.success !== true) {
        const error = new Error(data?.error || 'Nie udało się wykonać operacji.');
        error.data = data;
        throw error;
      }

      return data;
    }

    if (typeof window.adminRequest !== 'function') {
      throw new Error('Brak helpera requestów administracyjnych.');
    }

    const response = await window.adminRequest(url, {
      headers: {
        Accept: 'application/json',
        'Content-Type': 'application/json',
        ...(options.headers || {}),
      },
      ...options,
    });

    const text = await response.text();
    let data = null;

    if (text) {
      try {
        data = JSON.parse(text);
      } catch (error) {
        throw new Error('Nieprawidłowa odpowiedź serwera.');
      }
    }

    if (!response.ok || !data || data.success !== true) {
      const error = new Error(data?.error || 'Nie udało się wykonać operacji.');
      error.data = data;
      throw error;
    }

    return data;
  }

  function setMessage(element, text, type) {
    if (!element) return;

    element.textContent = text || '';
    element.dataset.type = type || '';
    element.hidden = !text;
  }

  function setFieldValue(element, value) {
    if (!element) return;
    element.value = value ?? '';
  }

  function setCheckboxValue(element, value) {
    if (!element) return;
    element.checked = Boolean(value);
  }

  function setButtonState(button, text, disabled = false) {
    if (!button) return;
    button.textContent = text;
    button.disabled = disabled;
  }

  function resetButtonLater(button, text) {
    window.setTimeout(() => {
      if (!button || state.saving) return;
      button.textContent = text;
      button.disabled = false;
    }, 900);
  }

  function formatPrice(value) {
    const number = Number(value);
    return Number.isFinite(number) ? number.toFixed(2) : String(value || '0.00');
  }

  function formatStaffCount(count) {
    if (count === 1) return 'pracownik';
    if (count > 1 && count < 5) return 'pracowników';
    return 'pracowników';
  }

  function escapeHtml(value) {
    return String(value ?? '')
      .replace(/&/g, '&amp;')
      .replace(/</g, '&lt;')
      .replace(/>/g, '&gt;')
      .replace(/"/g, '&quot;')
      .replace(/'/g, '&#039;');
  }

  function escapeHtmlAttr(value) {
    return escapeHtml(value);
  }
})();
