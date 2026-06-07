(function () {
  'use strict';

  const LOGIN_ENDPOINT = '/api/staff/login.php';
  const ME_ENDPOINT = '/api/staff/me.php';
  const ACCESS_ENDPOINT = '/api/staff/panel-access.php';
  const PANEL_URL = '/panel-pracownika/panel.html?v=2';
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

  async function handleLogin(event) {
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
    }
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

    form.addEventListener('submit', handleLogin);
  });
})();
