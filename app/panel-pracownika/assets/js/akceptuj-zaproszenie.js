(function () {
  const endpoint = '/api/staff/accept-invite.php';

  function getTokenFromUrl() {
    const params = new URLSearchParams(window.location.search);
    return String(params.get('token') || '').trim();
  }

  function getMessageElement() {
    return document.getElementById('employeeAcceptMessage');
  }

  function setMessage(message, type) {
    const element = getMessageElement();

    if (!element) {
      return;
    }

    element.textContent = message || '';
    element.className = 'employee-auth-message';

    if (message) {
      element.classList.add(type === 'success' ? 'is-success' : 'is-error');
    }
  }

  function setFormDisabled(disabled) {
    const form = document.getElementById('employeeAcceptInviteForm');

    if (!form) {
      return;
    }

    form.querySelectorAll('input, button').forEach((element) => {
      element.disabled = disabled;
    });
  }

  function validatePassword(password, passwordConfirm) {
    if (password.length < 8) {
      return 'Hasło musi mieć minimum 8 znaków.';
    }

    if (!/[a-z]/.test(password)) {
      return 'Hasło musi zawierać małą literę.';
    }

    if (!/[A-Z]/.test(password)) {
      return 'Hasło musi zawierać wielką literę.';
    }

    if (!/[0-9]/.test(password)) {
      return 'Hasło musi zawierać cyfrę.';
    }

    if (!/[^A-Za-z0-9]/.test(password)) {
      return 'Hasło musi zawierać znak specjalny.';
    }

    if (password !== passwordConfirm) {
      return 'Hasła muszą być takie same.';
    }

    return '';
  }

  function clearPasswordFields() {
    const passwordInput = document.getElementById('employeePassword');
    const passwordConfirmInput = document.getElementById('employeePasswordConfirm');

    if (passwordInput) {
      passwordInput.value = '';
    }

    if (passwordConfirmInput) {
      passwordConfirmInput.value = '';
    }
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

  async function handleSubmit(event, token) {
    event.preventDefault();
    setMessage('', '');

    const form = event.currentTarget;
    const submitButton = document.getElementById('employeeAcceptSubmit');
    const passwordInput = document.getElementById('employeePassword');
    const passwordConfirmInput = document.getElementById('employeePasswordConfirm');
    const password = passwordInput ? passwordInput.value : '';
    const passwordConfirm = passwordConfirmInput ? passwordConfirmInput.value : '';
    const validationError = validatePassword(password, passwordConfirm);

    if (validationError) {
      setMessage(validationError, 'error');
      return;
    }

    if (submitButton) {
      submitButton.disabled = true;
      submitButton.textContent = 'Zapisywanie...';
    }

    try {
      const response = await fetch(endpoint, {
        method: 'POST',
        headers: {
          'Content-Type': 'application/json'
        },
        credentials: 'include',
        body: JSON.stringify({
          token,
          password,
          password_confirm: passwordConfirm
        })
      });

      const data = await response.json().catch(() => null);

      if (response.ok && data && data.success === true) {
        clearPasswordFields();
        setMessage('Hasło zostało ustawione. Możesz przejść do logowania pracownika.', 'success');
        form.classList.add('is-complete');
        return;
      }

      setMessage(data?.error || 'Nie udało się ustawić hasła.', 'error');
    } catch (error) {
      setMessage('Nie udało się połączyć z serwerem. Spróbuj ponownie za chwilę.', 'error');
    } finally {
      if (submitButton && !form.classList.contains('is-complete')) {
        submitButton.disabled = false;
        submitButton.textContent = 'Ustaw hasło';
      }
    }
  }

  document.addEventListener('DOMContentLoaded', () => {
    const token = getTokenFromUrl();
    const form = document.getElementById('employeeAcceptInviteForm');

    bindPasswordToggles();

    if (!form) {
      return;
    }

    if (!token) {
      setMessage('Brak tokenu zaproszenia. Link jest nieprawidłowy.', 'error');
      setFormDisabled(true);
      return;
    }

    form.addEventListener('submit', (event) => handleSubmit(event, token));
  });
}());
