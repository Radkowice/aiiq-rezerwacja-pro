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
  };

  const els = {};

  document.addEventListener('DOMContentLoaded', initServicePaymentsSettings);

  function initServicePaymentsSettings() {
    if (initialized) return;

    const section = document.querySelector('section[data-section="usluga-platnosci"]');
    if (!section) return;

    initialized = true;
    cacheElements(section);
    bindEvents();
    loadServiceCompanyNamePreview();
    loadServicePaymentsData();
  }

  function cacheElements(section) {
    els.section = section;
    els.newButton = section.querySelector('#service-new-btn');
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
    els.bookingBufferMinutes = section.querySelector('#service-booking-buffer-minutes');
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

  function bindEvents() {
    els.newButton?.addEventListener('click', () => {
      populateForm(null);
      setMessage(els.formMessage, '', '');
      els.name?.focus();
    });

    els.form?.addEventListener('submit', saveService);
    els.saveButton?.addEventListener('click', saveService);
    els.globalSaveButton?.addEventListener('click', saveGlobalServiceSettings);

    window.addEventListener('aiiq:staff-updated', refreshStaffAfterUpdate);
    window.addEventListener('aiiq:section-shown', (event) => {
      if (event?.detail?.section === 'usluga-platnosci') {
        loadServicePaymentsData();
      }
    });
  }

  async function loadServicePaymentsData() {
    if (state.loading) return;

    state.loading = true;
    setMessage(els.listMessage, 'Ładowanie usług...', 'muted');

    try {
      const [servicesData, staffData] = await Promise.all([
        requestJson('/api/services/list.php', { method: 'GET' }),
        requestJson('/api/staff/list.php', { method: 'GET' }),
        loadGlobalServiceSettings(),
      ]);

      state.services = Array.isArray(servicesData.services) ? servicesData.services.map(normalizeService) : [];
      state.staff = Array.isArray(staffData.staff) ? staffData.staff.map(normalizeStaff) : [];

      mergeAssignedStaffFromServices();
      renderServiceList();
      populateForm(findSelectedService());
      setMessage(els.staffMessage, '', '');

      if (state.services.length === 0) {
        setMessage(els.listMessage, 'Brak usług. Dodaj pierwszą usługę.', 'muted');
      } else {
        setMessage(els.listMessage, '', '');
      }
    } catch (error) {
      setMessage(els.listMessage, error.message || 'Nie udało się załadować usług.', 'error');
      setMessage(els.staffMessage, 'Nie udało się załadować pracowników do przypisania.', 'error');
      renderServiceList();
      renderStaffCheckboxes([]);
    } finally {
      state.loading = false;
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

  async function loadGlobalServiceSettings() {
    try {
      const data = await requestJson('/api/system/service-settings.php', { method: 'GET' });
      fillGlobalSettings(data.settings || {});
    } catch (error) {
      fillGlobalSettings({});
    }
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
    const paymentTimeLimitValue = readInteger(els.globalPaymentTimeLimitValue, 'Termin płatności', 1, 10080);
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
      id: row?.id ? String(row.id) : '',
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
      is_active: row?.is_active === true,
      visible_on_front: row?.visible_on_front === true,
    };
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

  function renderServiceList() {
    if (!els.list) return;

    if (state.services.length === 0) {
      els.list.innerHTML = '<div class="service-empty">Nie ma jeszcze żadnych usług.</div>';
      return;
    }

    els.list.innerHTML = state.services.map((service) => {
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
  }

  function selectService(serviceId) {
    const service = state.services.find((item) => item.id === serviceId) || null;
    if (!service) return;

    state.selectedId = service.id;
    populateForm(service);
    setMessage(els.formMessage, '', '');
    renderServiceList();
  }

  function populateForm(service) {
    const selected = service || null;
    state.selectedId = selected ? selected.id : null;

    setFieldValue(els.id, selected?.id || '');
    setFieldValue(els.name, selected?.name || '');
    setFieldValue(els.description, selected?.description || '');
    setFieldValue(els.durationMinutes, selected?.duration_minutes || 60);
    setFieldValue(els.breakMinutes, selected?.break_minutes || 0);
    setFieldValue(els.bookingBufferMinutes, selected?.booking_buffer_minutes || 0);
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

    const selectedSet = new Set(selectedStaffIds.map(String));
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
      const email = person.email ? `<small>${escapeHtml(person.email)}</small>` : '';

      return `
        <label class="service-staff-item ${isInactive ? 'is-inactive' : ''}">
          <input type="checkbox" value="${escapeHtmlAttr(person.id)}" ${checked} ${disabled}>
          <span>
            <strong>${escapeHtml(person.display_name || 'Bez nazwy')}</strong>
            ${email}
            <em>${status}</em>
          </span>
        </label>
      `;
    }).join('');
  }

  function getCurrentSelectedStaffIds() {
    const checkedIds = els.staffList
      ? Array.from(els.staffList.querySelectorAll('input[type="checkbox"]:checked'))
        .map((input) => input.value)
        .filter(Boolean)
      : [];

    if (checkedIds.length > 0) {
      return checkedIds;
    }

    const selectedService = findSelectedService();
    return selectedService ? selectedService.staff_ids : [];
  }

  function readServicePayload() {
    const name = els.name.value.trim();
    const description = els.description.value.trim();
    const durationMinutes = readInteger(els.durationMinutes, 'Czas trwania', 1, 1440);
    const breakMinutes = readInteger(els.breakMinutes, 'Przerwa po usłudze', 0, 1440);
    const bookingBufferMinutes = readInteger(els.bookingBufferMinutes, 'Bufor rezerwacji', 0, 10080);
    const sortOrder = readInteger(els.sortOrder, 'Kolejność', -1000000, 1000000);
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

  function readInteger(input, label, min, max) {
    const value = Number.parseInt(String(input.value || ''), 10);

    if (!Number.isFinite(value) || value < min || value > max) {
      throw new Error(`${label} musi mieć wartość od ${min} do ${max}.`);
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
      setMessage(els.formMessage, 'Usługa została zapisana.', 'success');
      setButtonState(els.saveButton, 'Zapisano', true);
      await loadServicePaymentsData();
      selectService(state.selectedId);
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
      await loadServicePaymentsData();
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

  async function requestJson(url, options = {}) {
    const response = await fetch(url, {
      credentials: 'include',
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
      throw new Error(data?.error || 'Nie udało się wykonać operacji.');
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
