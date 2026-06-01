(function () {
  'use strict';

  const RESET_PASSWORD_ENDPOINT = '/api/staff/reset-password.php';

  function getElement(id) {
    return document.getElementById(id);
  }

  function getTokenFromUrl() {
    const params = new URLSearchParams(window.location.search);
    return String(params.get('token') || '').trim();
  }

  function setMessage(message, type) {
    const messageEl = getElement('employeeResetMessage');

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
    const form = getElement('employeeResetPasswordForm');

    if (!form) {
      return;
    }

    form.querySelectorAll('input, button').forEach((element) => {
      element.disabled = disabled;
    });
  }

  function bindPasswordToggles() {
    document.querySelectorAll('[data-password-toggle]').forEach((button) => {
      button.addEventListener('click', () => {
        const targetId = button.getAttribute('data-target') || '';
        const input = getElement(targetId);

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

  function getPasswordValidationError(password) {
    if (password.length < 8) {
      return 'Nowe hasło musi mieć minimum 8 znaków.';
    }

    if (!/[a-z]/.test(password)) {
      return 'Nowe hasło musi zawierać małą literę.';
    }

    if (!/[A-Z]/.test(password)) {
      return 'Nowe hasło musi zawierać dużą literę.';
    }

    if (!/[0-9]/.test(password)) {
      return 'Nowe hasło musi zawierać cyfrę.';
    }

    if (!/[^A-Za-z0-9]/.test(password)) {
      return 'Nowe hasło musi zawierać znak specjalny.';
    }

    return '';
  }

  function clearPasswordFields() {
    ['employeeResetPassword', 'employeeResetPasswordConfirm'].forEach((id) => {
      const input = getElement(id);

      if (input) {
        input.value = '';
      }
    });
  }

  async function handleResetPassword(event, token) {
    event.preventDefault();

    const passwordInput = getElement('employeeResetPassword');
    const passwordConfirmInput = getElement('employeeResetPasswordConfirm');
    const submitBtn = getElement('employeeResetSubmit');
    const password = passwordInput ? passwordInput.value : '';
    const passwordConfirm = passwordConfirmInput ? passwordConfirmInput.value : '';

    setMessage('', '');

    if (!token) {
      setMessage('Brak tokenu resetu hasła. Link jest nieprawidłowy.', 'error');
      setFormDisabled(true);
      return;
    }

    if (!password || !passwordConfirm) {
      setMessage('Wypełnij wszystkie pola.', 'error');
      return;
    }

    if (password !== passwordConfirm) {
      setMessage('Hasła nie są takie same.', 'error');
      return;
    }

    const passwordError = getPasswordValidationError(password);

    if (passwordError) {
      setMessage(passwordError, 'error');
      return;
    }

    setFormDisabled(true);

    if (submitBtn) {
      submitBtn.textContent = 'Zapisywanie...';
    }

    try {
      const response = await fetch(RESET_PASSWORD_ENDPOINT, {
        method: 'POST',
        credentials: 'include',
        headers: {
          'Content-Type': 'application/json',
          'Accept': 'application/json'
        },
        body: JSON.stringify({
          token,
          password,
          password_confirm: passwordConfirm
        })
      });

      const data = await response.json().catch(() => null);

      if (!response.ok || !data || data.success !== true) {
        throw new Error(data && data.error ? data.error : 'Nie udało się zmienić hasła.');
      }

      clearPasswordFields();
      setMessage(data.message || 'Hasło zostało zmienione. Możesz się zalogować.', 'success');
    } catch (error) {
      setMessage(error.message || 'Nie udało się zmienić hasła.', 'error');
    } finally {
      setFormDisabled(false);

      if (submitBtn) {
        submitBtn.textContent = 'Zapisz nowe hasło';
      }
    }
  }

  document.addEventListener('DOMContentLoaded', () => {
    const token = getTokenFromUrl();
    const form = getElement('employeeResetPasswordForm');

    bindPasswordToggles();

    if (!form) {
      return;
    }

    if (!token) {
      setMessage('Brak tokenu resetu hasła. Link jest nieprawidłowy.', 'error');
      setFormDisabled(true);
      return;
    }

    form.addEventListener('submit', (event) => handleResetPassword(event, token));
  });
})();
