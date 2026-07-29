const loginUrlParams = new URLSearchParams(window.location.search);
const loginActivatedState = loginUrlParams.get('activated');
const skipSetupRedirect = loginActivatedState === 'already';
const loginCodeEndpoint = '/api/auth/login-code.php';
const activationReissueMessage = 'Jeśli konto wymaga aktywacji, wyślemy nowy link aktywacyjny.';
const activationReissueErrorMessage = 'Nie udało się obsłużyć prośby. Spróbuj ponownie później.';
let activationReissueEmail = '';
let selectedLoginMethod = 'code';
let loginCodeRequested = false;
let loginRequestInProgress = false;

async function checkSetupBeforeLogin() {
  if (skipSetupRedirect) {
    return;
  }

  try {
    const res = await fetch('/api/auth/register.php', {
      cache: 'no-store'
    });

    const data = await res.json().catch(() => null);

    if (!skipSetupRedirect && data?.registration_allowed === true) {
      window.location.href = '/rejestracja.html';
    }
  } catch (e) {
    clearLoginError();
  }
}

function getLoginErrorEl() {
  return document.getElementById('err');
}

function setLoginError(message) {
  const err = getLoginErrorEl();
  if (!err) return;

  err.innerText = message || '';
  err.classList.toggle('visible', Boolean(message));
}

function clearLoginError() {
  setLoginError('');
}

function getActivationReissueWrap() {
  return document.getElementById('activationReissueWrap');
}

function getActivationReissueButton() {
  return document.getElementById('activationReissueBtn');
}

function showActivationReissueAction() {
  const wrap = getActivationReissueWrap();
  if (!wrap) return;

  wrap.hidden = false;
}

function hideActivationReissueAction() {
  const wrap = getActivationReissueWrap();
  const button = getActivationReissueButton();
  activationReissueEmail = '';

  if (wrap) {
    wrap.hidden = true;
  }

  if (button) {
    button.disabled = false;
  }
}

function handleLoginEmailChange() {
  if (activationReissueEmail === '') {
    return;
  }

  const emailInput = document.getElementById('email');
  const currentEmail = emailInput ? emailInput.value.trim() : '';

  if (currentEmail !== activationReissueEmail) {
    hideActivationReissueAction();
  }
}

function showActivationMessage() {
  const params = new URLSearchParams(window.location.search);
  const activated = params.get('activated');
  const activationError = params.get('activation');

  if (activated === 'already') {
    setLoginError('Konto zostało aktywowane.');
  } else if (activated === '1') {
    setLoginError('Konto zostało aktywowane. Możesz się teraz zalogować.');
  } else if (activationError === 'domain_unavailable') {
    setLoginError('Konto zostało aktywowane, ale adres panelu nie jest jeszcze dostępny.');
  } else if (activationError) {
    setLoginError('Link aktywacyjny jest nieprawidłowy, wygasł albo został już użyty.');
  } else {
    return;
  }

  window.history.replaceState({}, document.title, window.location.pathname + window.location.hash);
}

function setLoginLoading(isLoading) {
  loginRequestInProgress = isLoading;

  document.querySelectorAll('#loginForm input, #loginForm button, .login-method-btn').forEach((element) => {
    element.disabled = isLoading;
  });
}

function updateLoginSubmitLabel() {
  const submit = document.getElementById('loginSubmitBtn');

  if (!submit) return;

  if (selectedLoginMethod === 'password') {
    submit.textContent = 'Zaloguj się hasłem';
    return;
  }

  submit.textContent = loginCodeRequested ? 'Zaloguj się kodem' : 'Wyślij kod';
}

function selectLoginMethod(method, message = '') {
  if (!['code', 'password'].includes(method)) return;

  selectedLoginMethod = method;

  document.querySelectorAll('[data-login-method]').forEach((button) => {
    const isActive = button.dataset.loginMethod === method;
    button.classList.toggle('is-active', isActive);
    button.setAttribute('aria-pressed', isActive ? 'true' : 'false');
  });

  document.querySelectorAll('[data-login-panel]').forEach((panel) => {
    panel.hidden = panel.dataset.loginPanel !== method;
  });

  hideActivationReissueAction();
  setLoginError(message);
  updateLoginSubmitLabel();
}

async function requestLoginCode() {
  if (loginRequestInProgress) return;

  const emailInput = document.getElementById('email');
  const email = emailInput ? emailInput.value.trim() : '';

  if (!email) {
    setLoginError('Podaj adres e-mail');
    emailInput?.focus();
    return;
  }

  setLoginLoading(true);

  try {
    const response = await fetch(loginCodeEndpoint, {
      method: 'POST',
      headers: {
        'Content-Type': 'application/json',
        'Accept': 'application/json'
      },
      credentials: 'include',
      body: JSON.stringify({ action: 'request', email })
    });
    const data = await response.json().catch(() => null);

    if (!response.ok || data?.success !== true) {
      setLoginError(data?.error || 'Nie udało się wysłać kodu. Spróbuj ponownie za chwilę.');
      return;
    }

    loginCodeRequested = true;
    const codeStep = document.getElementById('loginCodeStep');

    if (codeStep) {
      codeStep.hidden = false;
    }

    const codeInput = document.getElementById('loginCode');
    if (codeInput) codeInput.value = '';

    updateLoginSubmitLabel();
    setLoginError(data.message || 'Jeśli aktywne konto istnieje, kod logowania został wysłany.');
    document.getElementById('loginCode')?.focus();
  } catch (error) {
    setLoginError('Nie udało się wysłać kodu. Spróbuj ponownie za chwilę.');
  } finally {
    setLoginLoading(false);
  }
}

async function verifyLoginCode() {
  if (loginRequestInProgress) return;

  const emailInput = document.getElementById('email');
  const codeInput = document.getElementById('loginCode');
  const trustDeviceInput = document.getElementById('trustDevice');
  const email = emailInput ? emailInput.value.trim() : '';
  const code = codeInput ? codeInput.value.trim() : '';

  if (!email) {
    setLoginError('Podaj adres e-mail');
    emailInput?.focus();
    return;
  }

  if (!/^[0-9]{6}$/.test(code)) {
    setLoginError('Wpisz sześciocyfrowy kod logowania.');
    codeInput?.focus();
    return;
  }

  setLoginLoading(true);

  try {
    const response = await fetch(loginCodeEndpoint, {
      method: 'POST',
      headers: {
        'Content-Type': 'application/json',
        'Accept': 'application/json'
      },
      credentials: 'include',
      body: JSON.stringify({
        action: 'verify',
        email,
        code,
        trust_device: trustDeviceInput?.checked === true
      })
    });
    const data = await response.json().catch(() => null);

    if (response.ok && data?.success === true) {
      if (codeInput) {
        codeInput.value = '';
      }

      window.location.href = '/panel-admina.php';
      return;
    }

    setLoginError(data?.error || 'Kod jest nieprawidłowy albo wygasł.');
  } catch (error) {
    setLoginError('Nie udało się zalogować. Spróbuj ponownie za chwilę.');
  } finally {
    setLoginLoading(false);
  }
}

async function loginWithPassword() {
  if (loginRequestInProgress) return;

  clearLoginError();
  hideActivationReissueAction();

  const emailInput = document.getElementById('email');
  const passwordInput = document.getElementById('password');

  const email = emailInput ? emailInput.value.trim() : '';
  const password = passwordInput ? passwordInput.value : '';

  if (!email) {
    setLoginError('Podaj adres e-mail');
    emailInput?.focus();
    return;
  }

  if (!password) {
    setLoginError('Podaj hasło');
    passwordInput?.focus();
    return;
  }

  setLoginLoading(true);

  try {
    const res = await fetch('/api/auth/login.php', {
      method: 'POST',
      headers: { 'Content-Type': 'application/json' },
      credentials: 'include',
      body: JSON.stringify({ email, password })
    });

    const data = await res.json().catch(() => null);

    if (res.ok && data?.success) {
      window.location.href = '/panel-admina.php';
      return;
    }

    if (data?.code === 'trusted_device_required') {
      selectLoginMethod('code', data.error || 'Zaloguj się kodem, aby zaufać tej przeglądarce.');
      return;
    }

    if (data?.activation_required === true) {
      activationReissueEmail = email;
      showActivationReissueAction();
    } else {
      hideActivationReissueAction();
    }

    setLoginError(data?.error || 'Nieprawidłowy e-mail lub hasło');
  } catch (error) {
    hideActivationReissueAction();
    setLoginError('Nie udało się zalogować. Spróbuj ponownie za chwilę');
  } finally {
    setLoginLoading(false);
    updateLoginSubmitLabel();
  }
}

async function submitSelectedLoginMethod() {
  if (selectedLoginMethod === 'password') {
    await loginWithPassword();
    return;
  }

  if (loginCodeRequested) {
    await verifyLoginCode();
    return;
  }

  await requestLoginCode();
}

async function requestActivationReissue() {
  const button = getActivationReissueButton();
  const email = activationReissueEmail;

  if (!email) {
    hideActivationReissueAction();
    setLoginError(activationReissueErrorMessage);
    return;
  }

  if (button) {
    button.disabled = true;
  }

  try {
    const res = await fetch('/api/auth/activation-link-reissue.php', {
      method: 'POST',
      headers: { 'Content-Type': 'application/json' },
      credentials: 'include',
      body: JSON.stringify({ email })
    });

    const data = await res.json().catch(() => null);

    if (res.ok && data?.success === true) {
      hideActivationReissueAction();
      setLoginError(activationReissueMessage);
      return;
    }

    setLoginError(activationReissueErrorMessage);
  } catch (error) {
    setLoginError(activationReissueErrorMessage);
  } finally {
    if (button) {
      button.disabled = false;
    }
  }
}

function togglePassword() {
  const input = document.getElementById('password');
  const button = document.querySelector('.login-toggle-password');

  if (!input || !button) return;

  const isVisible = input.type === 'text';

  input.type = isVisible ? 'password' : 'text';
  button.classList.toggle('is-visible', !isVisible);
}

document.addEventListener('DOMContentLoaded', async () => {
  clearLoginError();

  const form = document.querySelector('form');
  const emailInput = document.getElementById('email');
  const togglePasswordButton = document.querySelector('.login-toggle-password');
  const activationReissueButton = getActivationReissueButton();
  const requestNewCodeButton = document.getElementById('requestNewCodeBtn');

  if (form) {
    form.addEventListener('submit', async function (event) {
      event.preventDefault();
      await submitSelectedLoginMethod();
    });
  }

  document.querySelectorAll('[data-login-method]').forEach((button) => {
    button.addEventListener('click', () => {
      selectLoginMethod(button.dataset.loginMethod || 'code');
    });
  });

  if (togglePasswordButton) {
    togglePasswordButton.addEventListener('click', togglePassword);
  }

  if (emailInput) {
    emailInput.addEventListener('input', () => {
      handleLoginEmailChange();

      if (loginCodeRequested) {
        loginCodeRequested = false;
        const codeStep = document.getElementById('loginCodeStep');
        const codeInput = document.getElementById('loginCode');

        if (codeStep) codeStep.hidden = true;
        if (codeInput) codeInput.value = '';
        updateLoginSubmitLabel();
      }
    });
  }

  if (activationReissueButton) {
    activationReissueButton.addEventListener('click', requestActivationReissue);
  }

  if (requestNewCodeButton) {
    requestNewCodeButton.addEventListener('click', requestLoginCode);
  }

  selectLoginMethod('code');

  if (skipSetupRedirect) {
    showActivationMessage();
    return;
  }

  await checkSetupBeforeLogin();
  showActivationMessage();
});
