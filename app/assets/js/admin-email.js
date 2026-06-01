(function () {
  const section = document.querySelector('section[data-section="email"]');
  if (!section) return;

  const els = {
    content: section.querySelector('#booking-email-content'),
    toolbar: section.querySelector('.email-toolbar'),
    emojiPickers: section.querySelectorAll('.emoji-picker'),
    previewContent: section.querySelector('#email-preview-content'),
    previewSubject: section.querySelector('#email-preview-subject'),
    subject: section.querySelector('#booking-email-subject'),
    heading: section.querySelector('#booking-email-service-name'),
    saveGlobalButton: section.querySelector('#save-email-settings-btn'),
    globalMessage: section.querySelector('#global-email-message'),
    saveSmtpButton: section.querySelector('#save-smtp-settings-btn'),
    smtpMessage: section.querySelector('#smtp-settings-message'),
    testSmtpButton: section.querySelector('#test-email-connection'),
    smtpFromName: section.querySelector('#smtp-from-name'),
    smtpFromEmail: section.querySelector('#smtp-from-email'),
    smtpHost: section.querySelector('#smtp-host'),
    smtpPort: section.querySelector('#smtp-port'),
    smtpUsername: section.querySelector('#smtp-username'),
    smtpPassword: section.querySelector('#smtp-password'),
    smtpToggle: section.querySelector('#toggle-smtp-password'),
    staffSelect: section.querySelector('#staff-email-template-select'),
    staffStatus: section.querySelector('#staff-email-template-status'),
    staffSubject: section.querySelector('#staff-email-subject'),
    staffHeading: section.querySelector('#staff-email-heading'),
    staffBody: section.querySelector('#staff-email-body'),
    staffSaveButton: section.querySelector('#save-staff-email-template-btn'),
    staffResetButton: section.querySelector('#reset-staff-email-template-btn'),
    staffMessage: section.querySelector('#staff-email-template-message'),
    staffPreviewStatus: section.querySelector('#staff-email-preview-status'),
    staffPreviewSubject: section.querySelector('#staff-email-preview-subject'),
    staffPreviewHeading: section.querySelector('#staff-email-preview-heading'),
    staffPreviewContent: section.querySelector('#staff-email-preview-content'),
  };

  const state = {
    staff: [],
    selectedStaffId: '',
    currentTarget: 'global-body',
    savingSmtp: false,
    savingGlobal: false,
    savingStaff: false,
  };

  syncEmojiPickers();
  bindEvents();
  loadEmailSettings();
  loadStaffEmailTemplates();
  updateGlobalEmailPreview();
  updateStaffEmailPreview();

  function bindEvents() {
    els.smtpToggle?.addEventListener('click', toggleSmtpPassword);
    els.saveSmtpButton?.addEventListener('click', saveSmtpSettings);
    els.saveGlobalButton?.addEventListener('click', saveGlobalTemplate);
    els.testSmtpButton?.addEventListener('click', testSmtpConnection);
    els.staffSelect?.addEventListener('change', handleStaffSelection);
    els.staffSaveButton?.addEventListener('click', saveStaffTemplate);
    els.staffResetButton?.addEventListener('click', resetStaffTemplate);

    section.querySelectorAll('[data-action="emoji-toggle"]').forEach((button) => {
      button.addEventListener('click', () => {
        state.currentTarget = button.dataset.target || 'content';
        toggleEmojiPicker(button);
      });
    });

    section.querySelectorAll('.email-toolbar').forEach((toolbar) => {
      toolbar.addEventListener('click', handleToolbarClick);
    });
    els.emojiPickers.forEach((picker) => {
      picker.addEventListener('click', handleEmojiClick);
    });
    els.content?.addEventListener('input', updateGlobalEmailPreview);
    els.subject?.addEventListener('input', updateGlobalEmailPreview);
    els.heading?.addEventListener('input', updateStaffEmailPreview);
    els.staffSubject?.addEventListener('input', updateStaffEmailPreview);
    els.staffHeading?.addEventListener('input', updateStaffEmailPreview);
    els.staffBody?.addEventListener('input', updateStaffEmailPreview);

    section.querySelectorAll('[data-insert-target][data-insert-text]').forEach((button) => {
      button.addEventListener('click', () => {
        insertIntoField(button.dataset.insertTarget || '', button.dataset.insertText || '');
      });
    });
  }

  function toggleSmtpPassword(event) {
    event.preventDefault();

    if (!els.smtpPassword || !els.smtpToggle) return;

    const isPassword = els.smtpPassword.type === 'password';
    els.smtpPassword.type = isPassword ? 'text' : 'password';
    els.smtpToggle.textContent = isPassword ? '🙈' : '👁';
    els.smtpToggle.setAttribute('aria-pressed', isPassword ? 'true' : 'false');
  }

  function updateGlobalEmailPreview() {
    if (els.previewContent && els.content) {
      const content = (els.content.value || '').replace(/\n/g, '<br>');
      els.previewContent.innerHTML = content || 'Treść wiadomości pojawi się tutaj';
    }

    if (els.previewSubject && els.subject) {
      els.previewSubject.textContent = els.subject.value || 'Temat wiadomości pojawi się tutaj';
    }

    if (isSelectedStaffUsingGlobalTemplate()) {
      updateStaffEmailPreview();
    }
  }

  function updateStaffEmailPreview() {
    const staff = findSelectedStaff();

    if (!staff) {
      setPreviewText(
        'Wybierz pracownika, aby zobaczyć podgląd jego wiadomości.',
        'Temat wiadomości pracownika pojawi się tutaj',
        'Nagłówek wiadomości pracownika pojawi się tutaj',
        'Treść wiadomości pracownika pojawi się tutaj'
      );
      return;
    }

    const hasOwnValues = hasStaffFormValues();
    const usesGlobal = !staff.has_custom_template && !hasOwnValues;

    if (usesGlobal) {
      setPreviewText(
        'Ten pracownik używa globalnego szablonu e-mail. Podgląd globalnego szablonu używanego przez tego pracownika.',
        els.subject?.value || 'Temat globalnego szablonu pojawi się tutaj',
        els.heading?.value || 'Nagłówek globalnego szablonu pojawi się tutaj',
        els.content?.value || 'Treść globalnego szablonu pojawi się tutaj'
      );
      return;
    }

    setPreviewText(
      'Podgląd własnego szablonu pracownika.',
      els.staffSubject?.value || 'Temat wiadomości pracownika pojawi się tutaj',
      els.staffHeading?.value || 'Nagłówek wiadomości pracownika pojawi się tutaj',
      els.staffBody?.value || 'Treść wiadomości pracownika pojawi się tutaj'
    );
  }

  function setPreviewText(status, subject, heading, body) {
    if (els.staffPreviewStatus) els.staffPreviewStatus.textContent = status;
    if (els.staffPreviewSubject) els.staffPreviewSubject.textContent = subject;
    if (els.staffPreviewHeading) els.staffPreviewHeading.textContent = heading;
    if (els.staffPreviewContent) els.staffPreviewContent.innerHTML = String(body || '').replace(/\n/g, '<br>');
  }

  function hasStaffFormValues() {
    return Boolean(
      (els.staffSubject?.value || '').trim()
      || (els.staffHeading?.value || '').trim()
      || (els.staffBody?.value || '').trim()
    );
  }

  function isSelectedStaffUsingGlobalTemplate() {
    const staff = findSelectedStaff();
    return Boolean(staff && !staff.has_custom_template && !hasStaffFormValues());
  }

  function syncEmojiPickers() {
    const globalPicker = section.querySelector('[data-emoji-picker="global"]');
    const globalEmojiHtml = globalPicker?.innerHTML || '';

    section.querySelectorAll('.emoji-picker').forEach((picker) => {
      if (picker !== globalPicker && picker.innerHTML.trim() === '') {
        picker.innerHTML = globalEmojiHtml;
      }
    });
  }

  function toggleEmojiPicker(trigger) {
    const picker = trigger.closest('.email-settings-card')?.querySelector('.emoji-picker');
    if (!picker) return;

    const shouldOpen = picker.style.display !== 'block';

    els.emojiPickers.forEach((item) => {
      item.style.display = 'none';
    });

    picker.style.display = shouldOpen ? 'block' : 'none';
  }

  async function handleToolbarClick(event) {
    const button = event.target.closest('button');
    if (!button) return;

    const action = button.dataset.action;
    if (!action || action === 'emoji-toggle') return;

    const targetName = button.closest('.email-toolbar')?.dataset.fieldTarget || 'global-body';
    const target = getFieldByTarget(targetName);

    if (!target || target.disabled) return;

    const start = target.selectionStart;
    const end = target.selectionEnd;
    const text = target.value;
    const selected = text.substring(start, end);
    let result = '';

    switch (action) {
      case 'bold':
        result = `<b>${selected || 'pogrubiony tekst'}</b>`;
        break;
      case 'italic':
        result = `<i>${selected || 'kursywa'}</i>`;
        break;
      case 'br':
        result = '<br>';
        break;
      case 'ph-name':
        result = '{name}';
        break;
      case 'ph-date':
        result = '{date}';
        break;
      case 'ph-time':
        result = '{time}';
        break;
      case 'link':
        result = await buildLink(selected);
        if (!result) return;
        break;
      default:
        return;
    }

    target.value = text.substring(0, start) + result + text.substring(end);
    target.focus();
    target.selectionStart = target.selectionEnd = start + result.length;
    updatePreviewForField(target);
  }

  async function buildLink(selected) {
    if (typeof window.openAdminInputModal === 'function') {
      const url = await window.openAdminInputModal({
        title: 'Dodaj link',
        placeholder: 'https://twoja-strona.pl',
        confirmText: 'Wstaw link',
      });

      return url ? `<a href="${escapeHtmlAttr(url)}" target="_blank">${escapeHtml(selected || url)}</a>` : '';
    }

    const url = window.prompt('Dodaj link', 'https://twoja-strona.pl');
    return url ? `<a href="${escapeHtmlAttr(url)}" target="_blank">${escapeHtml(selected || url)}</a>` : '';
  }

  function handleEmojiClick(event) {
    const emojiElement = event.target.closest('span');
    if (!emojiElement) return;

    const emoji = emojiElement.textContent.trim();
    if (!emoji) return;

    insertIntoField(state.currentTarget, emoji);

    const picker = event.target.closest('.emoji-picker');
    if (picker) picker.style.display = 'none';
  }

  function insertIntoInput(input, value) {
    if (!input || !value) return;
    if (input.disabled) return;

    const start = input.selectionStart ?? input.value.length;
    const end = input.selectionEnd ?? input.value.length;
    const text = input.value;

    input.value = text.substring(0, start) + value + text.substring(end);
    input.focus();
    input.selectionStart = input.selectionEnd = start + value.length;
    updatePreviewForField(input);
  }

  function insertIntoField(targetName, value) {
    insertIntoInput(getFieldByTarget(targetName), value);
  }

  function updatePreviewForField(field) {
    if ([els.subject, els.heading, els.content].includes(field)) {
      updateGlobalEmailPreview();
      return;
    }

    if ([els.staffSubject, els.staffHeading, els.staffBody].includes(field)) {
      updateStaffEmailPreview();
    }
  }

  function getFieldByTarget(targetName) {
    switch (targetName) {
      case 'global-subject':
      case 'subject':
        return els.subject;
      case 'global-body':
      case 'content':
        return els.content;
      case 'staff-subject':
        return els.staffSubject;
      case 'staff-body':
        return els.staffBody;
      default:
        return null;
    }
  }

  function buildSmtpPayload() {
    return {
      section: 'smtp',
      smtp_name: els.smtpFromName?.value.trim() || '',
      smtp_email: els.smtpFromEmail?.value.trim() || '',
      smtp_host: els.smtpHost?.value.trim() || '',
      smtp_port: parseInt(els.smtpPort?.value || '587', 10),
      smtp_user: els.smtpUsername?.value.trim() || '',
      ...(els.smtpPassword?.value.trim() ? { smtp_pass: els.smtpPassword.value.trim() } : {}),
    };
  }

  function validateSmtpPayload(payload) {
    if (!payload.smtp_name || !payload.smtp_email || !payload.smtp_host || !payload.smtp_user || !payload.smtp_port) {
      throw new Error('Uzupełnij dane SMTP przed zapisem.');
    }
  }

  function buildGlobalTemplatePayload() {
    return {
      section: 'global_template',
      mail_subject: els.subject?.value.trim() || '',
      service_name: els.heading?.value.trim() || '',
      mail_body: els.content?.value || '',
      admin_mail_subject: '',
      admin_mail_body: '',
    };
  }

  function validateGlobalTemplatePayload(payload) {
    if (!payload.mail_subject || !payload.mail_body.trim()) {
      throw new Error('Uzupełnij temat i treść wiadomości.');
    }
  }

  async function saveSmtpSettings() {
    if (state.savingSmtp) return;

    state.savingSmtp = true;
    const originalText = els.saveSmtpButton.textContent;

    try {
      const payload = buildSmtpPayload();
      validateSmtpPayload(payload);
      setButtonState(els.saveSmtpButton, 'Zapisywanie...', true);
      setMessage(els.smtpMessage, 'Zapisywanie ustawień SMTP...', 'muted');

      await requestJson('/api/email/save-email-settings.php', payload);
      clearSmtpPassword();
      setMessage(els.smtpMessage, 'Ustawienia SMTP zostały zapisane.', 'success');
      setButtonState(els.saveSmtpButton, 'Zapisano', true);
    } catch (error) {
      setMessage(els.smtpMessage, error.message || 'Nie udało się zapisać zmian. Spróbuj ponownie.', 'error');
      setButtonState(els.saveSmtpButton, 'Błąd', true);
    } finally {
      state.savingSmtp = false;
      resetButtonLater(els.saveSmtpButton, originalText);
    }
  }

  async function saveGlobalTemplate() {
    if (state.savingGlobal) return;

    state.savingGlobal = true;
    const originalText = els.saveGlobalButton.textContent;

    try {
      const payload = buildGlobalTemplatePayload();
      validateGlobalTemplatePayload(payload);
      setButtonState(els.saveGlobalButton, 'Zapisywanie...', true);
      setMessage(els.globalMessage, 'Zapisywanie globalnego szablonu...', 'muted');

      await requestJson('/api/email/save-email-settings.php', payload);
      setMessage(els.globalMessage, 'Globalny szablon e-mail został zapisany.', 'success');
      setButtonState(els.saveGlobalButton, 'Zapisano', true);
    } catch (error) {
      setMessage(els.globalMessage, error.message || 'Nie udało się zapisać zmian. Spróbuj ponownie.', 'error');
      setButtonState(els.saveGlobalButton, 'Błąd', true);
    } finally {
      state.savingGlobal = false;
      resetButtonLater(els.saveGlobalButton, originalText);
    }
  }

  async function testSmtpConnection(event) {
    event.preventDefault();

    const originalText = els.testSmtpButton.textContent;
    setButtonState(els.testSmtpButton, 'Sprawdzanie...', true);
    setMessage(els.smtpMessage, 'Sprawdzanie połączenia SMTP...', 'muted');

    try {
      const result = await requestJson('/api/email/test-email-connection.php', {
        smtp_host: els.smtpHost?.value.trim() || '',
        smtp_port: els.smtpPort?.value.trim() || '',
        smtp_username: els.smtpUsername?.value.trim() || '',
        smtp_password: els.smtpPassword?.value || '',
        smtp_email: els.smtpFromEmail?.value.trim() || '',
        smtp_name: els.smtpFromName?.value.trim() || '',
      });

      setMessage(els.smtpMessage, result.message || 'Połączenie SMTP działa poprawnie.', 'success');
    } catch (error) {
      setMessage(els.smtpMessage, error.message || 'Nie udało się połączyć z serwerem SMTP.', 'error');
    } finally {
      resetButtonLater(els.testSmtpButton, originalText);
    }
  }

  async function loadEmailSettings() {
    try {
      const data = await fetchJson('/api/email/get-email-settings.php');
      const smtp = data.data?.smtp || {};
      const clientTemplate = data.data?.client_template || {};

      setFieldValue(els.smtpFromName, smtp.from_name || '');
      setFieldValue(els.smtpFromEmail, smtp.from_email || '');
      setFieldValue(els.smtpHost, smtp.smtp_host || '');
      setFieldValue(els.smtpPort, smtp.smtp_port || '');
      setFieldValue(els.smtpUsername, smtp.smtp_username || '');
      clearSmtpPassword();

      setFieldValue(els.subject, clientTemplate.subject || '');
      setFieldValue(els.heading, clientTemplate.service_name || '');
      setFieldValue(els.content, clientTemplate.body_html || '');
      updateGlobalEmailPreview();
      updateStaffEmailPreview();
    } catch (error) {
      setMessage(els.smtpMessage, 'Nie udało się wczytać ustawień e-mail.', 'error');
    }
  }

  async function loadStaffEmailTemplates(selectedId = state.selectedStaffId) {
    try {
      const data = await fetchJson('/api/email/get-staff-email-template.php');
      state.staff = Array.isArray(data.staff) ? data.staff : [];
      renderStaffOptions(selectedId);
      populateSelectedStaff();
    } catch (error) {
      setMessage(els.staffMessage, 'Nie udało się wczytać listy pracowników.', 'error');
      setStaffFieldsEnabled(false);
    }
  }

  function renderStaffOptions(selectedId) {
    if (!els.staffSelect) return;

    els.staffSelect.innerHTML = '<option value="">Wybierz pracownika</option>'
      + state.staff.map((person) => {
        const status = person.has_custom_template ? 'własny szablon' : 'szablon globalny';
        const selected = person.id === selectedId ? ' selected' : '';
        const name = person.display_name || 'Pracownik bez nazwy';
        return '<option value="' + escapeHtmlAttr(person.id) + '"' + selected + '>' + escapeHtml(name) + ' - ' + status + '</option>';
      }).join('');

    state.selectedStaffId = selectedId && state.staff.some((person) => person.id === selectedId) ? selectedId : '';
    els.staffSelect.value = state.selectedStaffId;
  }

  function handleStaffSelection() {
    state.selectedStaffId = els.staffSelect?.value || '';
    populateSelectedStaff();
    setMessage(els.staffMessage, '', '');
  }

  function populateSelectedStaff() {
    const staff = findSelectedStaff();

    if (!staff) {
      setFieldValue(els.staffSubject, '');
      setFieldValue(els.staffHeading, '');
      setFieldValue(els.staffBody, '');
      setStaffFieldsEnabled(false);
      setStaffStatus('Wybierz pracownika, aby ustawić jego własny szablon e-mail.');
      return;
    }

    setStaffFieldsEnabled(true);
    setFieldValue(els.staffSubject, staff.email_subject || '');
    setFieldValue(els.staffHeading, staff.email_heading || '');
    setFieldValue(els.staffBody, staff.email_body || '');
    setStaffStatus(staff.has_custom_template
      ? 'Ten pracownik ma własny szablon e-mail.'
      : 'Ten pracownik używa szablonu globalnego.');
    updateStaffEmailPreview();
  }

  async function saveStaffTemplate() {
    if (state.savingStaff) return;

    const staff = findSelectedStaff();

    if (!staff) {
      setMessage(els.staffMessage, 'Wybierz pracownika, aby edytować jego szablon e-mail.', 'error');
      return;
    }

    const payload = {
      staff_id: staff.id,
      email_subject: els.staffSubject?.value.trim() || '',
      email_heading: els.staffHeading?.value.trim() || '',
      email_body: els.staffBody?.value || '',
    };

    if (!payload.email_subject || !payload.email_body.trim()) {
      setMessage(els.staffMessage, 'Uzupełnij temat i treść wiadomości.', 'error');
      return;
    }

    state.savingStaff = true;
    const originalText = els.staffSaveButton.textContent;

    try {
      setButtonState(els.staffSaveButton, 'Zapisywanie...', true);
      setMessage(els.staffMessage, 'Zapisywanie szablonu pracownika...', 'muted');
      const data = await requestJson('/api/email/save-staff-email-template.php', payload);
      updateStaffInState(data.staff);
      populateSelectedStaff();
      setMessage(els.staffMessage, 'Szablon e-mail pracownika został zapisany.', 'success');
      setStaffStatus('Ten pracownik ma własny szablon e-mail.');
      setButtonState(els.staffSaveButton, 'Zapisano', true);
      renderStaffOptions(state.selectedStaffId);
    } catch (error) {
      setMessage(els.staffMessage, error.message || 'Nie udało się zapisać zmian. Spróbuj ponownie.', 'error');
      setButtonState(els.staffSaveButton, 'Błąd', true);
    } finally {
      state.savingStaff = false;
      resetButtonLater(els.staffSaveButton, originalText);
    }
  }

  async function resetStaffTemplate() {
    if (state.savingStaff) return;

    const staff = findSelectedStaff();

    if (!staff) {
      setMessage(els.staffMessage, 'Wybierz pracownika, aby edytować jego szablon e-mail.', 'error');
      return;
    }

    state.savingStaff = true;
    const originalText = els.staffResetButton.textContent;

    try {
      setButtonState(els.staffResetButton, 'Przywracanie...', true);
      setMessage(els.staffMessage, 'Przywracanie globalnego szablonu...', 'muted');
      const data = await requestJson('/api/email/reset-staff-email-template.php', { staff_id: staff.id });
      updateStaffInState(data.staff);
      populateSelectedStaff();
      renderStaffOptions(state.selectedStaffId);
      updateStaffEmailPreview();
      setMessage(els.staffMessage, 'Pracownik ponownie używa globalnego szablonu e-mail.', 'success');
    } catch (error) {
      setMessage(els.staffMessage, error.message || 'Nie udało się zapisać zmian. Spróbuj ponownie.', 'error');
      setButtonState(els.staffResetButton, 'Błąd', true);
    } finally {
      state.savingStaff = false;
      resetButtonLater(els.staffResetButton, originalText);
    }
  }

  function updateStaffInState(updatedStaff) {
    if (!updatedStaff?.id) return;

    state.staff = state.staff.map((person) => person.id === updatedStaff.id
      ? { ...person, ...updatedStaff }
      : person);
    state.selectedStaffId = updatedStaff.id;
  }

  function findSelectedStaff() {
    return state.staff.find((person) => person.id === state.selectedStaffId) || null;
  }

  function setStaffFieldsEnabled(enabled) {
    [els.staffSubject, els.staffHeading, els.staffBody, els.staffSaveButton, els.staffResetButton]
      .forEach((element) => {
        if (element) element.disabled = !enabled;
      });
  }

  function setStaffStatus(text) {
    if (els.staffStatus) els.staffStatus.textContent = text;
  }

  async function requestJson(url, payload) {
    const response = await fetch(url, {
      method: 'POST',
      credentials: 'include',
      headers: {
        'Content-Type': 'application/json',
        Accept: 'application/json',
      },
      body: JSON.stringify(payload),
    });

    const data = await response.json().catch(() => null);

    if (!response.ok || !data || data.success !== true) {
      throw new Error(data?.error || 'Nie udało się zapisać zmian. Spróbuj ponownie.');
    }

    return data;
  }

  async function fetchJson(url) {
    const response = await fetch(url, {
      method: 'GET',
      credentials: 'include',
      headers: {
        Accept: 'application/json',
      },
    });

    const data = await response.json().catch(() => null);

    if (!response.ok || !data || data.success !== true) {
      throw new Error(data?.error || 'Nie udało się pobrać danych.');
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

  function setButtonState(button, text, disabled = false) {
    if (!button) return;
    button.textContent = text;
    button.disabled = disabled;
  }

  function resetButtonLater(button, text) {
    window.setTimeout(() => {
      if (!button) return;
      button.textContent = text;
      button.disabled = false;
    }, 900);
  }

  function clearSmtpPassword() {
    if (els.smtpPassword) {
      els.smtpPassword.value = '';
      els.smtpPassword.type = 'password';
    }

    if (els.smtpToggle) {
      els.smtpToggle.textContent = '👁';
      els.smtpToggle.setAttribute('aria-pressed', 'false');
    }
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
