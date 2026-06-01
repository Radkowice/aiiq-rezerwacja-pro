(function () {
  'use strict';

  const FORGOT_PASSWORD_ENDPOINT = '/api/staff/forgot-password.php';

  function getElement(id) {
    return document.getElementById(id);
  }

  function setMessage(message, type) {
    const messageEl = getElement('employeeForgotMessage');

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

  function normalizeEmail(value) {
    return String(value || '').trim().toLowerCase();
  }

  function isValidEmail(email) {
    return /^[^\s@]+@[^\s@]+\.[^\s@]+$/.test(email);
  }

  function setFormDisabled(disabled) {
    const form = getElement('employeeForgotPasswordForm');

    if (!form) {
      return;
    }

    form.querySelectorAll('input, button').forEach((element) => {
      element.disabled = disabled;
    });
  }

  async function handleForgotPassword(event) {
    event.preventDefault();

    const emailInput = getElement('employeeForgotEmail');
    const submitBtn = getElement('employeeForgotSubmit');
    const email = normalizeEmail(emailInput ? emailInput.value : '');

    setMessage('', '');

    if (!email) {
      setMessage('Podaj adres e-mail.', 'error');
      return;
    }

    if (!isValidEmail(email)) {
      setMessage('Podaj poprawny adres e-mail.', 'error');
      return;
    }

    setFormDisabled(true);

    if (submitBtn) {
      submitBtn.textContent = 'Wysyłanie...';
    }

    try {
      const response = await fetch(FORGOT_PASSWORD_ENDPOINT, {
        method: 'POST',
        credentials: 'include',
        headers: {
          'Content-Type': 'application/json',
          'Accept': 'application/json'
        },
        body: JSON.stringify({ email })
      });

      const data = await response.json().catch(() => null);

      if (!response.ok || !data || data.success !== true) {
        throw new Error(data && data.error ? data.error : 'Nie udało się wysłać instrukcji resetu hasła.');
      }

      setMessage(data.message || 'Jeśli konto personelu istnieje, wysłaliśmy wiadomość z instrukcją resetu hasła.', 'success');
    } catch (error) {
      setMessage(error.message || 'Nie udało się wysłać instrukcji resetu hasła.', 'error');
    } finally {
      setFormDisabled(false);

      if (submitBtn) {
        submitBtn.textContent = 'Wyślij link resetujący';
      }
    }
  }

  document.addEventListener('DOMContentLoaded', () => {
    const form = getElement('employeeForgotPasswordForm');

    if (!form) {
      return;
    }

    form.addEventListener('submit', handleForgotPassword);
  });
})();
