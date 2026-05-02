/* =========================
   Integracje — Google Calendar / PayU
========================= */

(function () {
  const API_URL = '/api/system/integrations.php';

  async function integrationApi(url, options = {}) {
    if (typeof window.apiFetch === 'function') {
      return await window.apiFetch(url, options);
    }

    const response = await fetch(url, {
      credentials: 'include',
      headers: {
        'Content-Type': 'application/json',
        ...(options.headers || {})
      },
      ...options
    });

    const data = await response.json().catch(() => ({}));

    if (!response.ok) {
      throw new Error(data.error || 'Błąd połączenia z serwerem');
    }

    return data;
  }

  function getEl(id) {
    return document.getElementById(id);
  }

  function setChecked(id, value) {
    const el = getEl(id);
    if (el) {
      el.checked = Boolean(value);
    }
  }

  function setValue(id, value) {
    const el = getEl(id);
    if (el) {
      el.value = value ?? '';
    }
  }

  function setGoogleStatus(text) {
    const status = getEl('google-calendar-status');
    if (status) {
      status.textContent = text;
    }
  }

  function setButtonLoading(button, text) {
    if (!button) return;

    button.dataset.defaultText = button.dataset.defaultText || button.textContent;
    button.disabled = true;
    button.textContent = text;
  }

  function resetButton(button) {
    if (!button) return;

    button.disabled = false;
    button.textContent = button.dataset.defaultText || button.textContent;
  }

  function showSimpleMessage(text, type = 'info') {
    const existing = document.getElementById('integrations-message');

    if (existing) {
      existing.remove();
    }

    const section = document.querySelector('[data-section="integracje"]');
    const header = section?.querySelector('.panel-header');

    if (!section || !header) {
      return;
    }

    const msg = document.createElement('div');
    msg.id = 'integrations-message';
    msg.className = `message ${type}`;
    msg.textContent = text;
    msg.style.marginTop = '12px';

    header.insertAdjacentElement('afterend', msg);

    setTimeout(() => {
      msg.remove();
    }, 3500);
  }

  function applyIntegrationsData(integrations) {
    const google = integrations?.google_calendar || {};
    const payu = integrations?.payu || {};

    setChecked('google-calendar-enabled', google.enabled);
    setChecked('payu-enabled', payu.enabled);

    setValue('payu-mode', payu.mode || 'sandbox');
    setValue('payu-pos-id', payu.settings?.pos_id || '');
    setValue('payu-client-id', payu.settings?.client_id || '');

   const googleConnected =
  google.secrets_status?.access_token_saved ||
  google.secrets_status?.refresh_token_saved;

if (googleConnected) {
  setGoogleStatus('Status: połączono z Google Calendar');
} else if (google.enabled) {
  setGoogleStatus('Status: integracja włączona, konto Google niepołączone');
} else {
  setGoogleStatus('Status: niepołączono');
}

    const payuClientSecret = getEl('payu-client-secret');
    const payuSecondKey = getEl('payu-second-key');

    if (payuClientSecret && payu.secrets_status?.client_secret_saved) {
      payuClientSecret.placeholder = 'Zapisano — wpisz nowe tylko jeśli chcesz zmienić';
    }

    if (payuSecondKey && payu.secrets_status?.second_key_saved) {
      payuSecondKey.placeholder = 'Zapisano — wpisz nowe tylko jeśli chcesz zmienić';
    }
  }

  async function loadIntegrations() {
    try {
      const data = await integrationApi(API_URL, {
        method: 'GET'
      });

      if (!data.success) {
        throw new Error(data.error || 'Nie udało się odczytać integracji');
      }

      applyIntegrationsData(data.integrations || {});
    } catch (error) {
      console.error('load integrations error:', error);
      setGoogleStatus('Status: błąd odczytu integracji');
      showSimpleMessage(error.message || 'Błąd odczytu integracji', 'error');
    }
  }

  async function savePayuSettings() {
    const button = getEl('save-payu-settings-btn');

    try {
      setButtonLoading(button, 'Zapisywanie...');

      const payload = {
        provider: 'payu',
        enabled: getEl('payu-enabled')?.checked || false,
        mode: getEl('payu-mode')?.value || 'sandbox',
        settings: {
          pos_id: getEl('payu-pos-id')?.value || '',
          client_id: getEl('payu-client-id')?.value || ''
        },
        secrets: {
          client_secret: getEl('payu-client-secret')?.value || '',
          second_key: getEl('payu-second-key')?.value || ''
        }
      };

      const data = await integrationApi(API_URL, {
        method: 'POST',
        body: JSON.stringify(payload)
      });

      if (!data.success) {
        throw new Error(data.error || 'Nie udało się zapisać PayU');
      }

      const clientSecret = getEl('payu-client-secret');
      const secondKey = getEl('payu-second-key');

      if (clientSecret) {
        clientSecret.value = '';
        clientSecret.placeholder = 'Zapisano — wpisz nowe tylko jeśli chcesz zmienić';
      }

      if (secondKey) {
        secondKey.value = '';
        secondKey.placeholder = 'Zapisano — wpisz nowe tylko jeśli chcesz zmienić';
      }

      showSimpleMessage('Ustawienia PayU zapisane', 'success');
    } catch (error) {
      console.error('save PayU error:', error);
      showSimpleMessage(error.message || 'Błąd zapisu PayU', 'error');
    } finally {
      resetButton(button);
    }
  }

  async function saveGoogleEnabled(enabled) {
    try {
      const data = await integrationApi(API_URL, {
        method: 'POST',
        body: JSON.stringify({
          provider: 'google_calendar',
          enabled,
          mode: 'sandbox',
          settings: {
            sync_mode: 'create_event_only'
          },
          secrets: {}
        })
      });

      if (!data.success) {
        throw new Error(data.error || 'Nie udało się zapisać Google Calendar');
      }

      showSimpleMessage(
        enabled ? 'Google Calendar włączony' : 'Google Calendar wyłączony',
        'success'
      );
    } catch (error) {
      console.error('save Google Calendar error:', error);
      showSimpleMessage(error.message || 'Błąd zapisu Google Calendar', 'error');

      const checkbox = getEl('google-calendar-enabled');
      if (checkbox) {
        checkbox.checked = !enabled;
      }
    }
  }

  function bindEvents() {
    const savePayuBtn = getEl('save-payu-settings-btn');
    const testPayuBtn = getEl('test-payu-connection-btn');
    const googleEnabled = getEl('google-calendar-enabled');
    const payuEnabled = getEl('payu-enabled');
    const connectGoogleBtn = getEl('connect-google-calendar-btn');
    const disconnectGoogleBtn = getEl('disconnect-google-calendar-btn');

    if (savePayuBtn) {
      savePayuBtn.addEventListener('click', savePayuSettings);
    }

       if (testPayuBtn) {
      testPayuBtn.addEventListener('click', async () => {
        try {
          setButtonLoading(testPayuBtn, 'Sprawdzanie...');

          const data = await integrationApi('/api/payments/payu-test-connection.php', {
            method: 'POST'
          });

          if (!data.success) {
            throw new Error(data.error || 'Nie udało się połączyć z PayU');
          }

          showSimpleMessage(data.message || 'Połączenie z PayU poprawne.', 'success');
        } catch (error) {
          console.error('test PayU connection error:', error);
          showSimpleMessage(error.message || 'Błąd testu połączenia PayU', 'error');
        } finally {
          resetButton(testPayuBtn);
        }
      });
    }

    if (googleEnabled) {
      googleEnabled.addEventListener('change', () => {
        saveGoogleEnabled(googleEnabled.checked);
      });
    }

    if (payuEnabled) {
      payuEnabled.addEventListener('change', () => {
        showSimpleMessage('Zapisz PayU, aby utrwalić zmianę przełącznika.', 'info');
      });
    }

  if (connectGoogleBtn) {
  connectGoogleBtn.addEventListener('click', async () => {
    const googleEnabledCheckbox = getEl('google-calendar-enabled');

    if (!googleEnabledCheckbox?.checked) {
      showSimpleMessage('Najpierw włącz integrację Google Calendar.', 'info');
      return;
    }

    try {
      setButtonLoading(connectGoogleBtn, 'Łączenie...');

      const data = await integrationApi('/api/integrations/google/connect.php', {
        method: 'POST'
      });

      if (!data.success || !data.auth_url) {
        throw new Error(data.error || 'Nie udało się rozpocząć połączenia z Google');
      }

      window.location.href = data.auth_url;
    } catch (error) {
      console.error('connect Google Calendar error:', error);
      showSimpleMessage(error.message || 'Błąd połączenia z Google', 'error');
      resetButton(connectGoogleBtn);
    }
  });
}

    if (disconnectGoogleBtn) {
      disconnectGoogleBtn.addEventListener('click', async () => {
        const checkbox = getEl('google-calendar-enabled');

        if (checkbox) {
          checkbox.checked = false;
        }

        await saveGoogleEnabled(false);
        setGoogleStatus('Status: niepołączono');
      });
    }
  }

  document.addEventListener('DOMContentLoaded', () => {
    const section = document.querySelector('[data-section="integracje"]');

    if (!section) {
      return;
    }

    bindEvents();
    loadIntegrations();
  });
})();