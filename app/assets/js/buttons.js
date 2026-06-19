function finishButtonState(button, defaultText, startTime, minTime = 3000) {
  const elapsed = Date.now() - startTime;
  const delay = Math.max(0, minTime - elapsed);

  setTimeout(() => {
    button.disabled = false;
    button.textContent = defaultText;
  }, delay);
}

document.addEventListener('DOMContentLoaded', () => {                             
  const accountMessage = document.getElementById('account-message')
    || document.getElementById('settings-message');

  // MOJE KONTO — dane firmy
  const saveAccountDataBtn = document.getElementById('save-company-btn');

  if (saveAccountDataBtn) {
    saveAccountDataBtn.addEventListener('click', async () => {
      const startTime = Date.now();

      try {
        saveAccountDataBtn.disabled = true;
        saveAccountDataBtn.textContent = 'Zapisywanie danych firmy...';

        const client_name = document.getElementById('account-company-name')?.value || '';
        const client_number = document.getElementById('account-client-number')?.value || '';
        const company_id = document.getElementById('account-company-id')?.value || '';

        const res = await apiFetch('/api/system/branding.php', {
          method: 'POST',
          body: JSON.stringify({
            company_id,
            client_name,
            client_number
          })
        });

        if (accountMessage) {
          accountMessage.style.display = 'block';
          accountMessage.textContent =
            res && res.success ? 'Dane firmy zapisane' : (res?.error || 'Błąd zapisu');

          accountMessage.className = `message ${res?.success ? 'success' : 'error'}`;

          setTimeout(() => {
            accountMessage.textContent = '';
            accountMessage.className = 'message';
          }, 3000);
        }
      } catch (err) {
        console.error('save company data error:', err);

        if (accountMessage) {
          accountMessage.style.display = 'block';
          accountMessage.textContent = 'Błąd serwera';
          accountMessage.className = 'message error';

          setTimeout(() => {
            accountMessage.textContent = '';
            accountMessage.className = 'message';
          }, 3000);
        }
      }

      finishButtonState(saveAccountDataBtn, 'Zapisz nazwę firmy', startTime);
    });
  }
  
  function normalizeHexColor(value, fallback = '#ffffff') {
  const color = String(value || '').trim();

  if (/^#[0-9a-f]{6}$/i.test(color)) {
    return color;
  }

  if (/^#[0-9a-f]{3}$/i.test(color)) {
    return `#${color[1]}${color[1]}${color[2]}${color[2]}${color[3]}${color[3]}`;
  }

  return fallback;
}

function getColorLuminance(hexColor) {
  const color = normalizeHexColor(hexColor).replace('#', '');

  const r = parseInt(color.slice(0, 2), 16) / 255;
  const g = parseInt(color.slice(2, 4), 16) / 255;
  const b = parseInt(color.slice(4, 6), 16) / 255;

  const toLinear = value => {
    return value <= 0.03928
      ? value / 12.92
      : Math.pow((value + 0.055) / 1.055, 2.4);
  };

  return (0.2126 * toLinear(r)) + (0.7152 * toLinear(g)) + (0.0722 * toLinear(b));
}

function getReadableReservationsColors(baseColor) {
  const luminance = getColorLuminance(baseColor);
  const isDark = luminance < 0.42;

  return {
    text_color: isDark ? '#f8fafc' : '#0f172a',
    muted_color: isDark ? '#cbd5e1' : '#334155',
    button_text_color: isDark ? '#f8fafc' : '#0f172a',
    button_border_color: isDark ? '#cbd5e1' : '#64748b'
  };
}

  // MOJE KONTO — branding
  const saveBrandingBtn = document.getElementById('save-branding-btn');

  const ALLOWED_BRANDING_MIME_TYPES = ['image/png', 'image/jpeg', 'image/webp'];
  const ALLOWED_BRANDING_EXTENSIONS = ['png', 'jpg', 'jpeg', 'webp'];

  function validateBrandingFile(file, type) {
    const extension = String(file?.name || '').split('.').pop().toLowerCase();

    const hasAllowedMime = !file?.type || ALLOWED_BRANDING_MIME_TYPES.includes(file.type);

    if (!file || !hasAllowedMime || !ALLOWED_BRANDING_EXTENSIONS.includes(extension)) {
      throw new Error('Dozwolone formaty: PNG, JPG, WebP.');
    }

    if (type === 'favicon' && file.size > 512 * 1024) {
      throw new Error('Favicona jest za duża. Maksymalny rozmiar to 512 KB. Zmniejsz obraz i spróbuj ponownie.');
    }

    if (type === 'logo' && file.size > 2 * 1024 * 1024) {
      throw new Error('Logo jest za duże. Maksymalny rozmiar to 2 MB.');
    }
  }

  async function readUploadResponse(response, fallbackMessage) {
    const responseText = await response.text();
    let data = null;

    if (responseText) {
      try {
        data = JSON.parse(responseText);
      } catch (error) {
        data = null;
      }
    }

    if (response.status === 413) {
      throw new Error('Plik jest za duży.');
    }

    if (!response.ok || data?.success !== true) {
      throw new Error(data?.error || data?.message || fallbackMessage);
    }

    return data;
  }

  function setFaviconFieldMessage(message = '') {
    const fieldMessage = document.getElementById('account-favicon-message');
    if (!fieldMessage) return;

    if (message) {
      fieldMessage.textContent = message;
      fieldMessage.classList.add('error');
      fieldMessage.hidden = false;
      fieldMessage.removeAttribute('hidden');
      return;
    }

    fieldMessage.textContent = '';
    fieldMessage.classList.remove('error');
    fieldMessage.hidden = true;
  }

  function validateBrandingInput(input, type, showGlobalMessage = true) {
    if (!input?.files?.length) {
      if (type === 'favicon') setFaviconFieldMessage('');
      return null;
    }

    try {
      validateBrandingFile(input.files[0], type);
      if (type === 'favicon') setFaviconFieldMessage('');
      return null;
    } catch (error) {
      const validationError = error instanceof Error
        ? error
        : new Error('Nie udało się zweryfikować pliku.');

      if (type === 'favicon') {
        setFaviconFieldMessage(validationError.message);
      }
      if (showGlobalMessage) {
        showBrandingActionMessage(validationError.message, 'error');
      }

      return validationError;
    }
  }

  const brandingLogoInput = document.getElementById('account-logo');
  const brandingFaviconInput = document.getElementById('account-favicon');

  if (brandingLogoInput) {
    brandingLogoInput.addEventListener('change', () => {
      validateBrandingInput(brandingLogoInput, 'logo');
    });
  }

  if (brandingFaviconInput) {
    brandingFaviconInput.addEventListener('change', () => {
      validateBrandingInput(brandingFaviconInput, 'favicon');
    });
  }

  if (saveBrandingBtn) {
    saveBrandingBtn.addEventListener('click', async () => {
      const startTime = Date.now();

      try {
        saveBrandingBtn.disabled = true;
        saveBrandingBtn.textContent = 'Zapisywanie brandingu...';

       const admin_theme = document.getElementById('account-theme')?.value || '';
const service_title_front = document.getElementById('service-title-front')?.value || '';
const company_id = document.getElementById('account-company-id')?.value || '';
const logoInput = document.getElementById('account-logo');
const faviconInput = document.getElementById('account-favicon');

if (logoInput?.files?.length) {
  const logoValidationError = validateBrandingInput(logoInput, 'logo', false);
  if (logoValidationError) throw logoValidationError;
}

if (faviconInput?.files?.length) {
  const faviconValidationError = validateBrandingInput(faviconInput, 'favicon', false);
  if (faviconValidationError) throw faviconValidationError;
} else {
  setFaviconFieldMessage('');
}

let logo_url_front = '';

if (logoInput && logoInput.files && logoInput.files.length > 0) {
  const formData = new FormData();
  formData.append('logo', logoInput.files[0]);

  const uploadRes = await fetch('/api/system/upload-logo-front.php', {
    method: 'POST',
    credentials: 'include',
    body: formData
  });

  const uploadData = await readUploadResponse(uploadRes, 'Nie udało się zapisać pliku. Spróbuj ponownie lub wybierz mniejszy obraz.');

  logo_url_front = uploadData.logo_url_front || '';

  if (logo_url_front) {
    await setLogoPreview('/api/system/logo-front.php');
  }
}

let favicon_url_front = '';

if (faviconInput && faviconInput.files && faviconInput.files.length > 0) {
  const formData = new FormData();
  formData.append('favicon', faviconInput.files[0]);

  const uploadRes = await fetch('/api/system/upload-favicon-front.php', {
    method: 'POST',
    credentials: 'include',
    body: formData
  });

  const uploadData = await readUploadResponse(uploadRes, 'Nie udało się zapisać pliku. Spróbuj ponownie lub wybierz mniejszy obraz.');

  favicon_url_front = uploadData.favicon_url_front || '';

  if (favicon_url_front) {
    await setFaviconPreview('/api/system/favicon-front.php');
  }
}

const payload = {
  company_id,
  admin_theme,
  service_title_front
};

if (logo_url_front) {
  payload.logo_url_front = logo_url_front;
}

if (favicon_url_front) {
  payload.favicon_url_front = favicon_url_front;
}

const res = await apiFetch('/api/system/branding.php', {
  method: 'POST',
  body: JSON.stringify(payload)
});

        showBrandingActionMessage(
          res && res.success ? 'Branding zapisany' : (res?.error || 'Błąd zapisu brandingu'),
          res?.success ? 'success' : 'error'
        );
      } catch (err) {
        console.error('save branding error:', err);
        showBrandingActionMessage(err?.message || 'Błąd serwera', 'error');
      }

      finishButtonState(saveBrandingBtn, 'Zapisz branding', startTime);
    });
  }
  
    // MOJE KONTO — usuwanie logo i favicony frontu
  const deleteLogoFrontBtn = document.getElementById('delete-logo-front-btn');
  const deleteFaviconFrontBtn = document.getElementById('delete-favicon-front-btn');
  let brandingMessageTimer = null;

  function showBrandingActionMessage(message, type = 'success') {
    if (!accountMessage) return;

    if (brandingMessageTimer) {
      clearTimeout(brandingMessageTimer);
    }

    accountMessage.style.display = 'block';
    accountMessage.hidden = false;
    accountMessage.removeAttribute('hidden');
    accountMessage.textContent = message;
    accountMessage.className = `message ${type}`;

    brandingMessageTimer = setTimeout(() => {
      accountMessage.textContent = '';
      accountMessage.className = 'message';
      brandingMessageTimer = null;
    }, type === 'error' ? 7000 : 3000);
  }

  function withCacheBuster(url) {
    if (!url) return '';

    const separator = url.includes('?') ? '&' : '?';
    return `${url}${separator}v=${Date.now()}`;
  }

  function setLogoPreview(url) {
    const logoInput = document.getElementById('account-logo');
    const logoPreview = document.getElementById('account-logo-preview');
    const logoEmpty = document.getElementById('account-logo-empty');
    const deleteBtn = document.getElementById('delete-logo-front-btn');

    if (logoInput) {
      logoInput.value = '';
    }

    if (!logoPreview) return Promise.resolve(false);

    logoPreview.style.display = 'none';
    if (logoEmpty) logoEmpty.style.display = 'none';

    return new Promise((resolve, reject) => {
      logoPreview.onload = () => {
        logoPreview.onload = null;
        logoPreview.onerror = null;
        logoPreview.style.display = 'block';
        if (logoEmpty) logoEmpty.style.display = 'none';
        if (deleteBtn) deleteBtn.style.display = 'inline-flex';
        resolve(true);
      };
      logoPreview.onerror = () => {
        logoPreview.onload = null;
        logoPreview.onerror = null;
        logoPreview.removeAttribute('src');
        logoPreview.style.display = 'none';
        if (logoEmpty) logoEmpty.style.display = 'block';
        if (deleteBtn) deleteBtn.style.display = 'inline-flex';
        reject(new Error('Logo zostało zapisane, ale nie udało się załadować podglądu.'));
      };
      logoPreview.src = withCacheBuster(url);
    });
  }

  function setFaviconPreview(url) {
    const faviconInput = document.getElementById('account-favicon');
    const faviconPreview = document.getElementById('account-favicon-preview');
    const faviconEmpty = document.getElementById('account-favicon-empty');
    const deleteBtn = document.getElementById('delete-favicon-front-btn');

    if (faviconInput) {
      faviconInput.value = '';
    }

    if (!faviconPreview) return Promise.resolve(false);

    faviconPreview.style.display = 'none';
    if (faviconEmpty) faviconEmpty.style.display = 'none';

    return new Promise((resolve, reject) => {
      faviconPreview.onload = () => {
        faviconPreview.onload = null;
        faviconPreview.onerror = null;
        faviconPreview.style.display = 'block';
        if (faviconEmpty) faviconEmpty.style.display = 'none';
        if (deleteBtn) deleteBtn.style.display = 'inline-flex';
        resolve(true);
      };
      faviconPreview.onerror = () => {
        faviconPreview.onload = null;
        faviconPreview.onerror = null;
        faviconPreview.removeAttribute('src');
        faviconPreview.style.display = 'none';
        if (faviconEmpty) faviconEmpty.style.display = 'block';
        if (deleteBtn) deleteBtn.style.display = 'inline-flex';
        reject(new Error('Favicona została zapisana, ale nie udało się załadować podglądu.'));
      };
      faviconPreview.src = withCacheBuster(url);
    });
  }

  function clearLogoPreview() {
    const logoInput = document.getElementById('account-logo');
    const logoPreview = document.getElementById('account-logo-preview');
    const logoEmpty = document.getElementById('account-logo-empty');
    const deleteBtn = document.getElementById('delete-logo-front-btn');

    if (logoInput) {
      logoInput.value = '';
    }

    if (logoPreview) {
      logoPreview.removeAttribute('src');
      logoPreview.style.display = 'none';
    }

    if (logoEmpty) {
      logoEmpty.textContent = 'Brak wgranego logo';
      logoEmpty.style.display = 'block';
    }

    if (deleteBtn) {
      deleteBtn.style.display = 'none';
    }
  }

  function clearFaviconPreview() {
    const faviconInput = document.getElementById('account-favicon');
    const faviconPreview = document.getElementById('account-favicon-preview');
    const faviconEmpty = document.getElementById('account-favicon-empty');
    const deleteBtn = document.getElementById('delete-favicon-front-btn');

    if (faviconInput) {
      faviconInput.value = '';
    }

    if (faviconPreview) {
      faviconPreview.removeAttribute('src');
      faviconPreview.style.display = 'none';
    }

    if (faviconEmpty) {
      faviconEmpty.textContent = 'Brak wgranej favicony';
      faviconEmpty.style.display = 'block';
    }

    if (deleteBtn) {
      deleteBtn.style.display = 'none';
    }
  }

  async function confirmBrandingDelete(message) {
    if (typeof openAdminConfirm === 'function') {
      return await openAdminConfirm({
        title: 'Potwierdź usunięcie',
        message,
        confirmText: 'Usuń',
        cancelText: 'Anuluj',
        danger: true
      });
    }

    return window.confirm(message);
  }

  if (deleteLogoFrontBtn) {
    deleteLogoFrontBtn.addEventListener('click', async () => {
      const confirmed = await confirmBrandingDelete('Czy na pewno usunąć logo frontu kalendarza?');

      if (!confirmed) {
        return;
      }

      const defaultText = deleteLogoFrontBtn.textContent;
      const startTime = Date.now();

      try {
        deleteLogoFrontBtn.disabled = true;
        deleteLogoFrontBtn.textContent = 'Usuwanie...';

        const res = await fetch('/api/system/delete-logo-front.php', {
          method: 'POST',
          credentials: 'include'
        });

        const text = await res.text();

        let data;
        try {
          data = JSON.parse(text);
        } catch {
          throw new Error('Serwer zwrócił nieprawidłową odpowiedź przy usuwaniu logo.');
        }

        if (!res.ok || data.success !== true) {
          throw new Error(data.error || 'Nie udało się usunąć logo.');
        }

        clearLogoPreview();
        showBrandingActionMessage('Logo usunięte.', 'success');
      } catch (error) {
        console.error('delete logo error:', error);
        showBrandingActionMessage(error.message || 'Błąd usuwania logo.', 'error');
      }

      finishButtonState(deleteLogoFrontBtn, defaultText, startTime);
    });
  }

  if (deleteFaviconFrontBtn) {
    deleteFaviconFrontBtn.addEventListener('click', async () => {
      const confirmed = await confirmBrandingDelete('Czy na pewno usunąć faviconę frontu kalendarza?');

      if (!confirmed) {
        return;
      }

      const defaultText = deleteFaviconFrontBtn.textContent;
      const startTime = Date.now();

      try {
        deleteFaviconFrontBtn.disabled = true;
        deleteFaviconFrontBtn.textContent = 'Usuwanie...';

        const res = await fetch('/api/system/delete-favicon-front.php', {
          method: 'POST',
          credentials: 'include'
        });

        const text = await res.text();

        let data;
        try {
          data = JSON.parse(text);
        } catch {
          throw new Error('Serwer zwrócił nieprawidłową odpowiedź przy usuwaniu favicony.');
        }

        if (!res.ok || data.success !== true) {
          throw new Error(data.error || 'Nie udało się usunąć favicony.');
        }

        clearFaviconPreview();
        showBrandingActionMessage('Favicon usunięta.', 'success');
      } catch (error) {
        console.error('delete favicon error:', error);
        showBrandingActionMessage(error.message || 'Błąd usuwania favicony.', 'error');
      }

      finishButtonState(deleteFaviconFrontBtn, defaultText, startTime);
    });
  }
  
  const saveReservationsStyleBtn = document.getElementById('save-reservations-style-btn');

if (saveReservationsStyleBtn) {
  saveReservationsStyleBtn.addEventListener('click', async () => {
    const startTime = Date.now();

    try {
      saveReservationsStyleBtn.disabled = true;
      saveReservationsStyleBtn.textContent = 'Zapisywanie wyglądu rezerwacji...';

      const defaultReservationsStyle = {
        bg_color: '#e5ebf2',
        card_color: '#f8fafc',
        table_color: '#eef2f7',
        header_color: '#cbd5e1',
        border_color: '#94a3b8',
        radius: '16'
      };

      const bgColor = normalizeHexColor(
        document.getElementById('reservations-bg-color')?.value,
        defaultReservationsStyle.bg_color
      );

      const cardColor = normalizeHexColor(
        document.getElementById('reservations-card-color')?.value,
        defaultReservationsStyle.card_color
      );

      const tableColor = normalizeHexColor(
        document.getElementById('reservations-table-color')?.value,
        defaultReservationsStyle.table_color
      );

      const headerColor = normalizeHexColor(
        document.getElementById('reservations-header-color')?.value,
        defaultReservationsStyle.header_color
      );

      const borderColor = normalizeHexColor(
        document.getElementById('reservations-border-color')?.value,
        defaultReservationsStyle.border_color
      );

      const readableColors = getReadableReservationsColors(tableColor);
      const readableButtonColors = getReadableReservationsColors(cardColor);

      const reservations_style = {
        bg_color: bgColor,
        card_color: cardColor,
        table_color: tableColor,
        header_color: headerColor,
        border_color: borderColor,
        radius: document.getElementById('reservations-radius')?.value || defaultReservationsStyle.radius,

        text_color: readableColors.text_color,
        muted_color: readableColors.muted_color,
        button_text_color: readableButtonColors.button_text_color,
        button_border_color: readableButtonColors.button_border_color
      };

      const res = await apiFetch('/api/system/branding.php', {
        method: 'POST',
        body: JSON.stringify({
          reservations_style
        })
      });

      if (accountMessage) {
        accountMessage.style.display = 'block';
        accountMessage.textContent =
          res && res.success ? 'Wygląd rezerwacji zapisany' : (res?.error || 'Błąd zapisu wyglądu rezerwacji');

        accountMessage.className = `message ${res?.success ? 'success' : 'error'}`;

        setTimeout(() => {
          accountMessage.textContent = '';
          accountMessage.className = 'message';
        }, 3000);
      }
    } catch (err) {
      console.error('save reservations style error:', err);

      if (accountMessage) {
        accountMessage.style.display = 'block';
        accountMessage.textContent = err.message || 'Błąd serwera';
        accountMessage.className = 'message error';

        setTimeout(() => {
          accountMessage.textContent = '';
          accountMessage.className = 'message';
        }, 3000);
      }
    }

    finishButtonState(saveReservationsStyleBtn, 'Zapisz wygląd rezerwacji', startTime);
  });
}

// MOJE KONTO — wygląd kalendarza frontowego
const saveFrontStyleBtn = document.getElementById('save-front-style-btn');

if (saveFrontStyleBtn) {
  saveFrontStyleBtn.addEventListener('click', async () => {
    const startTime = Date.now();

    try {
      saveFrontStyleBtn.disabled = true;
      saveFrontStyleBtn.textContent = 'Zapisywanie wyglądu kalendarza...';

      const calendar_front_style = {
        bg_color: document.getElementById('front-bg-color')?.value || '#ffffff',
        card_color: document.getElementById('front-card-color')?.value || '#ffffff',
        cell_color: document.getElementById('front-cell-color')?.value || '#ffffff',
        active_color: document.getElementById('front-active-color')?.value || '#2563eb',
        blocked_color: document.getElementById('front-blocked-color')?.value || '#e5e7eb',
        radius: document.getElementById('front-radius')?.value || '16',
        width: document.getElementById('front-width')?.value || '520'
      };

      const res = await apiFetch('/api/system/branding.php', {
        method: 'POST',
        body: JSON.stringify({
          calendar_front_style
        })
      });

      if (accountMessage) {
        accountMessage.style.display = 'block';
        accountMessage.textContent =
          res && res.success ? 'Wygląd kalendarza frontowego zapisany' : (res?.error || 'Błąd zapisu wyglądu kalendarza');

        accountMessage.className = `message ${res?.success ? 'success' : 'error'}`;

        setTimeout(() => {
          accountMessage.textContent = '';
          accountMessage.className = 'message';
        }, 3000);
      }
    } catch (err) {
      console.error('save front calendar style error:', err);

      if (accountMessage) {
        accountMessage.style.display = 'block';
        accountMessage.textContent = err.message || 'Błąd serwera';
        accountMessage.className = 'message error';

        setTimeout(() => {
          accountMessage.textContent = '';
          accountMessage.className = 'message';
        }, 3000);
      }
    }

    finishButtonState(saveFrontStyleBtn, 'Zapisz wygląd kalendarza', startTime);
  });
}

// MOJE KONTO — pola formularza frontowego
const saveFormFieldsBtn = document.getElementById('save-form-fields-btn');

if (saveFormFieldsBtn) {
  saveFormFieldsBtn.addEventListener('click', async () => {
    const startTime = Date.now();

    try {
      saveFormFieldsBtn.disabled = true;
      saveFormFieldsBtn.textContent = 'Zapisywanie pól formularza...';

      const calendar_form_fields = {
        name_label: document.getElementById('label-name')?.value || 'Imię i nazwisko',
        email_label: document.getElementById('label-email')?.value || 'E-mail',
        phone_label: document.getElementById('label-phone')?.value || 'Telefon',
        notes_label: document.getElementById('label-notes')?.value || 'Wiadomość',
        show_email: true,
        show_phone: !!document.getElementById('toggle-phone-field')?.checked,
        show_notes: !!document.getElementById('toggle-notes-field')?.checked
      };

      const res = await apiFetch('/api/system/branding.php', {
        method: 'POST',
        body: JSON.stringify({
          calendar_form_fields
        })
      });

      if (accountMessage) {
        accountMessage.style.display = 'block';
        accountMessage.textContent =
          res && res.success ? 'Pola formularza frontowego zapisane' : (res?.error || 'Błąd zapisu pól formularza');

        accountMessage.className = `message ${res?.success ? 'success' : 'error'}`;

        setTimeout(() => {
          accountMessage.textContent = '';
          accountMessage.className = 'message';
        }, 3000);
      }
    } catch (err) {
      console.error('save front form fields error:', err);

      if (accountMessage) {
        accountMessage.style.display = 'block';
        accountMessage.textContent = err.message || 'Błąd serwera';
        accountMessage.className = 'message error';

        setTimeout(() => {
          accountMessage.textContent = '';
          accountMessage.className = 'message';
        }, 3000);
      }
    }

    finishButtonState(saveFormFieldsBtn, 'Zapisz pola formularza', startTime);
  });
}

  // MOJE KONTO — zmiana e-maila
  const changeEmailBtn = document.getElementById('change-email-btn');

  if (changeEmailBtn) {
    changeEmailBtn.addEventListener('click', async () => {
      const startTime = Date.now();
      const defaultText = 'Zapisz nowy e-mail';
      const newEmailInput = document.getElementById('new-email');
      const newEmail = newEmailInput?.value.trim();

      if (!newEmail) {
        if (accountMessage) {
          accountMessage.textContent = 'Podaj nowy adres e-mail.';
          accountMessage.className = 'message error';
          setTimeout(() => {
            accountMessage.textContent = '';
          }, 3000);
        }
        return;
      }

      changeEmailBtn.disabled = true;
      changeEmailBtn.textContent = 'Zapisywanie zamiany email...';

      try {
        const result = await apiFetch('/api/user/change-email.php', {
          method: 'POST',
          body: JSON.stringify({ email: newEmail })
        });

        if (accountMessage) {
          if (result?.success) {
            accountMessage.textContent = result.message || 'E-mail został zmieniony.';
            accountMessage.className = 'message success';

            const accountEmail = document.getElementById('account-email');
            if (accountEmail) accountEmail.value = newEmail;
            if (newEmailInput) newEmailInput.value = '';
          } else {
            accountMessage.textContent =
              result?.message || result?.error || 'Nie udało się zmienić e-maila.';
            accountMessage.className = 'message error';
          }
        }
      } catch (error) {
        if (accountMessage) {
          accountMessage.textContent = error.message || 'Błąd zmiany e-maila.';
          accountMessage.className = 'message error';
        }
      } finally {
        if (accountMessage) {
          setTimeout(() => {
            accountMessage.textContent = '';
          }, 3000);
        }

        finishButtonState(changeEmailBtn, defaultText, startTime, 3000);
      }
    });
  }
  
  // EMAIL — ustawienia e-mail
 
  // USTAWIENIA — zapis ustawień
  const saveSettingsBtn = document.getElementById('save-settings-btn');

  if (saveSettingsBtn) {
    saveSettingsBtn.addEventListener('click', async () => {
      const startTime = Date.now();
      const defaultText = 'Zapisz ustawienia';
      const settingsMessage = document.getElementById('settings-message');

      try {
        saveSettingsBtn.disabled = true;
        saveSettingsBtn.textContent = 'Zapisywanie ustawień rezerwacji...';

        const workStart = document.getElementById('work-start')?.value || '00:00';
        const workEnd = document.getElementById('work-end')?.value || '23:59';
        const minNoticeValue = Math.max(
          0,
          parseInt(document.getElementById('booking-min-notice-value')?.value || '0', 10) || 0
        );
        const minNoticeUnit = document.getElementById('booking-min-notice-unit')?.value || 'minutes';
        const bookingBufferMinutes = minNoticeUnit === 'days'
          ? minNoticeValue * 1440
          : (minNoticeUnit === 'hours' ? minNoticeValue * 60 : minNoticeValue);

        const payload = {
          work_start: workStart,
          work_end: workEnd,
          consultation_duration: parseInt(
            document.getElementById('consultation-duration')?.value || '60',
            10
          ),
          consultation_break: parseInt(
            document.getElementById('consultation-break')?.value || '0',
            10
          ),
          booking_buffer: bookingBufferMinutes,
          booking_start_month_offset: parseInt(
            document.getElementById('booking-start-month-offset')?.value || '0',
            10
          ),
          booking_month_range: parseInt(
            document.getElementById('booking-month-range')?.value || '1',
            10
          )
        };

        const res = await apiFetch('/api/system/settings.php', {
          method: 'POST',
          body: JSON.stringify(payload)
        });

        if (settingsMessage) {
          settingsMessage.textContent = res?.success
            ? 'Ustawienia zapisane'
            : (res?.error || 'Błąd zapisu ustawień');

          settingsMessage.className = `message ${res?.success ? 'success' : 'error'}`;
          settingsMessage.style.display = 'block';

          setTimeout(() => {
            settingsMessage.textContent = '';
            settingsMessage.className = 'message';
            settingsMessage.style.display = 'none';
          }, 3000);
        }
      } catch (err) {
        console.error('save settings error:', err);

        if (settingsMessage) {
          settingsMessage.textContent = err.message || 'Błąd serwera';
          settingsMessage.className = 'message error';
          settingsMessage.style.display = 'block';

          setTimeout(() => {
            settingsMessage.textContent = '';
            settingsMessage.className = 'message';
            settingsMessage.style.display = 'none';
          }, 3000);
        }
      }

      finishButtonState(saveSettingsBtn, defaultText, startTime);
    });
  }
});

