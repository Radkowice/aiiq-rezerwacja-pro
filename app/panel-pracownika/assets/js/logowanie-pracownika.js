(function () {
  'use strict';

  const LOGIN_ENDPOINT = '/api/staff/login.php';
  const ME_ENDPOINT = '/api/staff/me.php';
  const PANEL_URL = '/panel-pracownika/panel.html?v=2';

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

  document.addEventListener('DOMContentLoaded', () => {
    const form = getElement('employeeLoginForm');

    bindPasswordToggles();
    checkExistingSession();

    if (!form) {
      return;
    }

    form.addEventListener('submit', handleLogin);
  });
})();
