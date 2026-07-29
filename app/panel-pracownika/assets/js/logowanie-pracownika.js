(function () {
  'use strict';

  const LOGIN_ENDPOINT = '/api/staff/login.php';
  const LOGIN_CODE_ENDPOINT = '/api/staff/login-code.php';
  const ME_ENDPOINT = '/api/staff/me.php';
  const ACCESS_ENDPOINT = '/api/staff/panel-access.php';
  const PANEL_URL = '/panel-pracownika/panel.html?v=2';
  let selectedLoginMethod = 'code';
  let loginCodeRequested = false;
  let loginRequestInProgress = false;
  const LOCKED_TITLE = 'Panel pracownika dostępny w planie Pro';
  const LOCKED_MESSAGE = 'Panel pracownika jest dostępny dla kont z aktywnym planem Pro. To konto działa obecnie w planie Free albo abonament Pro wygasł. Opłać abonament Pro, aby odzyskać dostęp do panelu pracownika.';

  function getElement(id) {
    return document.getElementById(id);
  }

  function setMessage(message, type) {
    const messageEl = getElement('employeeLoginMessage');

    if (!messageEl) {
      return;
    }

    messageEl.textContent = message || '';
    messageEl.classList.remove('is-success', 'is-error');

    if (type === 'success') {
      messageEl.classList.add('is-success');
    }

    if (type === 'error') {
      messageEl.classList.add('is-error');
    }
  }

  function setFormDisabled(disabled) {
    const form = getElement('employeeLoginForm');

    if (!form) {
      return;
    }

    form.querySelectorAll('input, button').forEach((element) => {
      element.disabled = disabled;
    });
  }

  function setSubmitLoading(isLoading) {
    const submit = getElement('employeeLoginSubmit');

    if (!submit) {
      return;
    }

    submit.disabled = isLoading;
    submit.textContent = isLoading ? 'Logowanie…' : 'Zaloguj się';
  }

  function updateLoginSubmitLabel() {
    const submit = getElement('employeeLoginSubmit');

    if (!submit || loginRequestInProgress) {
      return;
    }

    if (selectedLoginMethod === 'password') {
      submit.textContent = 'Zaloguj się hasłem';
      return;
    }

    submit.textContent = loginCodeRequested ? 'Zaloguj się kodem' : 'Wyślij kod';
  }

  function selectLoginMethod(method, message = '') {
    if (!['code', 'password'].includes(method)) {
      return;
    }

    selectedLoginMethod = method;

    document.querySelectorAll('[data-login-method]').forEach((button) => {
      const isActive = button.dataset.loginMethod === method;
      button.classList.toggle('is-active', isActive);
      button.setAttribute('aria-pressed', isActive ? 'true' : 'false');
    });

    document.querySelectorAll('[data-login-panel]').forEach((panel) => {
      panel.hidden = panel.dataset.loginPanel !== method;
    });

    setMessage(message, message ? 'error' : '');
    updateLoginSubmitLabel();
  }

  function showLoginContent() {
    const content = getElement('employeeLoginContent');
    const locked = getElement('employeeLoginLocked');

    if (content) {
      content.hidden = false;
    }

    if (locked) {
      locked.hidden = true;
    }

    setFormDisabled(false);
  }

  function showPlanLock(message) {
    const content = getElement('employeeLoginContent');
    const locked = getElement('employeeLoginLocked');

    if (content) {
      content.hidden = true;
    }

    if (locked) {
      const title = locked.querySelector('h2');
      const description = locked.querySelector('p');

      if (title) {
        title.textContent = LOCKED_TITLE;
      }

      if (description) {
        description.textContent = message || LOCKED_MESSAGE;
      }

      locked.hidden = false;
    }

    setFormDisabled(true);
  }

  function bindPasswordToggles() {
    document.querySelectorAll('[data-password-toggle]').forEach((button) => {
      button.addEventListener('click', () => {
        const targetId = button.getAttribute('data-target') || '';
        const input = document.getElementById(targetId);

        if (!input) {
          return;
        }

        const isVisible = input.type === 'text';
        input.type = isVisible ? 'password' : 'text';
        button.classList.toggle('is-visible', !isVisible);
        button.setAttribute('aria-label', isVisible ? 'Pokaż hasło' : 'Ukryj hasło');
      });
    });
  }

  function normalizeEmail(value) {
    return String(value || '').trim().toLowerCase();
  }

  function isValidEmail(email) {
    return /^[^\s@]+@[^\s@]+\.[^\s@]+$/.test(email);
  }

  async function checkExistingSession() {
    try {
      const response = await fetch(ME_ENDPOINT, {
        method: 'GET',
        credentials: 'include',
        headers: {
          'Accept': 'application/json'
        }
      });

      const data = await response.json().catch(() => null);

      if (response.ok && data && data.success === true) {
        window.location.href = PANEL_URL;
      }
    } catch (error) {
      // Brak aktywnej sesji nie blokuje formularza logowania.
    }
  }

  async function checkPanelAccess() {
    try {
      const response = await fetch(ACCESS_ENDPOINT, {
        method: 'GET',
        credentials: 'include',
        headers: {
          'Accept': 'application/json'
        }
      });

      const data = await response.json().catch(() => null);

      if (response.status === 403 && data?.upgrade_required === true) {
        showPlanLock(data.error || LOCKED_MESSAGE);
        return false;
      }

      if (!response.ok || !data || data.staff_panel_available !== true) {
        showPlanLock('Nie udało się potwierdzić dostępu do panelu pracownika. Spróbuj ponownie za chwilę albo skontaktuj się z administratorem.');
        return false;
      }

      showLoginContent();
      return true;
    } catch (error) {
      showPlanLock('Nie udało się potwierdzić dostępu do panelu pracownika. Spróbuj ponownie za chwilę albo skontaktuj się z administratorem.');
      return false;
    }
  }

  function setLoginRequestLoading(isLoading) {
    loginRequestInProgress = isLoading;
    setSubmitLoading(isLoading);
    setFormDisabled(isLoading);

    document.querySelectorAll('.employee-login-method-btn').forEach((button) => {
      button.disabled = isLoading;
    });

    if (!isLoading) {
      updateLoginSubmitLabel();
    }
  }

  async function requestLoginCode() {
    if (loginRequestInProgress) {
      return;
    }

    const emailInput = getElement('employeeLoginEmail');
    const email = normalizeEmail(emailInput ? emailInput.value : '');

    setMessage('', '');

    if (!email || !isValidEmail(email)) {
      setMessage('Podaj poprawny adres e-mail.', 'error');
      emailInput?.focus();
      return;
    }

    setLoginRequestLoading(true);

    try {
      const response = await fetch(LOGIN_CODE_ENDPOINT, {
        method: 'POST',
        credentials: 'include',
        headers: {
          'Content-Type': 'application/json',
          'Accept': 'application/json'
        },
        body: JSON.stringify({ action: 'request', email })
      });
      const data = await response.json().catch(() => null);

      if (response.status === 403 && data?.upgrade_required === true) {
        showPlanLock(data.error || LOCKED_MESSAGE);
        return;
      }

      if (!response.ok || data?.success !== true) {
        setMessage(data?.error || 'Nie udało się wysłać kodu. Spróbuj ponownie za chwilę.', 'error');
        return;
      }

      loginCodeRequested = true;
      const codeStep = getElement('employeeLoginCodeStep');

      if (codeStep) {
        codeStep.hidden = false;
      }

      const codeInput = getElement('employeeLoginCode');
      if (codeInput) codeInput.value = '';

      setMessage(data.message || 'Jeśli aktywne konto istnieje, kod logowania został wysłany.', 'success');
      getElement('employeeLoginCode')?.focus();
    } catch (error) {
      setMessage('Nie udało się wysłać kodu. Spróbuj ponownie za chwilę.', 'error');
    } finally {
      setLoginRequestLoading(false);
    }
  }

  async function verifyLoginCode() {
    if (loginRequestInProgress) {
      return;
    }

    const emailInput = getElement('employeeLoginEmail');
    const codeInput = getElement('employeeLoginCode');
    const trustDeviceInput = getElement('employeeTrustDevice');
    const email = normalizeEmail(emailInput ? emailInput.value : '');
    const code = codeInput ? codeInput.value.trim() : '';

    setMessage('', '');

    if (!email || !isValidEmail(email)) {
      setMessage('Podaj poprawny adres e-mail.', 'error');
      emailInput?.focus();
      return;
    }

    if (!/^[0-9]{6}$/.test(code)) {
      setMessage('Wpisz sześciocyfrowy kod logowania.', 'error');
      codeInput?.focus();
      return;
    }

    setLoginRequestLoading(true);

    try {
      const response = await fetch(LOGIN_CODE_ENDPOINT, {
        method: 'POST',
        credentials: 'include',
        headers: {
          'Content-Type': 'application/json',
          'Accept': 'application/json'
        },
        body: JSON.stringify({
          action: 'verify',
          email,
          code,
          trust_device: trustDeviceInput?.checked === true
        })
      });
      const data = await response.json().catch(() => null);

      if (response.status === 403 && data?.upgrade_required === true) {
        showPlanLock(data.error || LOCKED_MESSAGE);
        return;
      }

      if (!response.ok || data?.success !== true) {
        setMessage(data?.error || 'Kod jest nieprawidłowy albo wygasł.', 'error');
        return;
      }

      if (codeInput) {
        codeInput.value = '';
      }

      setMessage('Zalogowano. Przekierowuję do panelu…', 'success');
      window.location.href = PANEL_URL;
    } catch (error) {
      setMessage('Wystąpił błąd połączenia. Spróbuj ponownie za chwilę.', 'error');
    } finally {
      setLoginRequestLoading(false);
    }
  }

  async function handlePasswordLogin(event) {
    event.preventDefault();

    const emailInput = getElement('employeeLoginEmail');
    const passwordInput = getElement('employeeLoginPassword');

    const email = normalizeEmail(emailInput ? emailInput.value : '');
    const password = passwordInput ? passwordInput.value : '';

    setMessage('', '');

    if (!email || !password) {
      setMessage('Podaj e-mail i hasło.', 'error');
      return;
    }

    if (!isValidEmail(email)) {
      setMessage('Podaj poprawny adres e-mail.', 'error');
      return;
    }

    setSubmitLoading(true);
    setFormDisabled(true);

    try {
      const response = await fetch(LOGIN_ENDPOINT, {
        method: 'POST',
        credentials: 'include',
        headers: {
          'Content-Type': 'application/json',
          'Accept': 'application/json'
        },
        body: JSON.stringify({
          email,
          password
        })
      });

      const data = await response.json().catch(() => null);

      if (response.status === 403 && data?.upgrade_required === true) {
        showPlanLock(data.error || LOCKED_MESSAGE);
        return;
      }

      if (data?.code === 'trusted_device_required') {
        selectLoginMethod('code', data.error || 'Zaloguj się kodem, aby zaufać tej przeglądarce.');
        return;
      }

      if (!response.ok || !data || data.success !== true) {
        const errorMessage = data && data.error
          ? data.error
          : 'Nie udało się zalogować. Sprawdź dane i spróbuj ponownie.';

        setMessage(errorMessage, 'error');
        return;
      }

      if (passwordInput) {
        passwordInput.value = '';
      }

      setMessage('Zalogowano. Przekierowuję do panelu…', 'success');
      window.location.href = PANEL_URL;
    } catch (error) {
      setMessage('Wystąpił błąd połączenia. Spróbuj ponownie za chwilę.', 'error');
    } finally {
      setSubmitLoading(false);
      setFormDisabled(false);
      updateLoginSubmitLabel();
    }
  }

  async function handleSelectedLogin(event) {
    event.preventDefault();

    if (selectedLoginMethod === 'password') {
      await handlePasswordLogin(event);
      return;
    }

    if (loginCodeRequested) {
      await verifyLoginCode();
      return;
    }

    await requestLoginCode();
  }

  document.addEventListener('DOMContentLoaded', async () => {
    const form = getElement('employeeLoginForm');

    bindPasswordToggles();

    const hasPanelAccess = await checkPanelAccess();

    if (!hasPanelAccess) {
      return;
    }

    checkExistingSession();

    if (!form) {
      return;
    }

    document.querySelectorAll('[data-login-method]').forEach((button) => {
      button.addEventListener('click', () => {
        selectLoginMethod(button.dataset.loginMethod || 'code');
      });
    });

    const emailInput = getElement('employeeLoginEmail');

    if (emailInput) {
      emailInput.addEventListener('input', () => {
        if (!loginCodeRequested) {
          return;
        }

        loginCodeRequested = false;
        const codeStep = getElement('employeeLoginCodeStep');
        const codeInput = getElement('employeeLoginCode');

        if (codeStep) codeStep.hidden = true;
        if (codeInput) codeInput.value = '';
        updateLoginSubmitLabel();
      });
    }

    getElement('employeeRequestNewCode')?.addEventListener('click', requestLoginCode);
    selectLoginMethod('code');
    form.addEventListener('submit', handleSelectedLogin);
  });
})();
