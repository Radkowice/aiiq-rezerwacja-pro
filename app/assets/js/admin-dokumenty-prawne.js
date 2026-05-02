document.addEventListener('DOMContentLoaded', () => {
  initLegalDocuments();
});

async function initLegalDocuments() {
  const saveBtn = document.getElementById('save-legal-documents-btn');

  if (!saveBtn) return;

  await loadLegalDocuments();

  saveBtn.addEventListener('click', async () => {
    await saveLegalDocuments(saveBtn);
  });
}

function getLegalDocumentsFormData() {
  return {
    terms_title: document.getElementById('legal-terms-title')?.value?.trim() || 'Regulamin rezerwacji',
    terms_content: document.getElementById('legal-terms-content')?.value || '',
    privacy_title: document.getElementById('legal-privacy-title')?.value?.trim() || 'Polityka prywatności',
    privacy_content: document.getElementById('legal-privacy-content')?.value || '',
    is_enabled: !!document.getElementById('legal-is-enabled')?.checked
  };
}

function setLegalDocumentsMessage(text, type = '') {
  const message = document.getElementById('legal-documents-message');
  if (!message) return;

  message.textContent = text || '';
  message.classList.remove('success', 'error');

  if (type) {
    message.classList.add(type);
  }
}

function fillLegalDocumentsForm(documents = {}) {
  const termsTitle = document.getElementById('legal-terms-title');
  const termsContent = document.getElementById('legal-terms-content');
  const privacyTitle = document.getElementById('legal-privacy-title');
  const privacyContent = document.getElementById('legal-privacy-content');
  const isEnabled = document.getElementById('legal-is-enabled');

  if (termsTitle) termsTitle.value = documents.terms_title || 'Regulamin rezerwacji';
  if (termsContent) termsContent.value = documents.terms_content || '';
  if (privacyTitle) privacyTitle.value = documents.privacy_title || 'Polityka prywatności';
  if (privacyContent) privacyContent.value = documents.privacy_content || '';
  if (isEnabled) isEnabled.checked = !!documents.is_enabled;
}

async function loadLegalDocuments() {
  try {
    setLegalDocumentsMessage('Wczytywanie dokumentów...', '');

    const response = await fetch('/api/system/legal-documents.php', {
      method: 'GET',
      credentials: 'include',
      cache: 'no-store'
    });

    const data = await response.json();

    if (!response.ok || !data.success) {
      throw new Error(data.error || 'Nie udało się wczytać dokumentów prawnych');
    }

    fillLegalDocumentsForm(data.documents || {});
    setLegalDocumentsMessage('', '');
  } catch (error) {
    console.error('loadLegalDocuments error:', error);
    setLegalDocumentsMessage(error.message || 'Błąd wczytywania dokumentów prawnych', 'error');
  }
}

async function saveLegalDocuments(button) {
  const originalText = button.textContent;

  try {
    button.disabled = true;
    button.textContent = 'Zapisywanie...';
    setLegalDocumentsMessage('', '');

    const payload = getLegalDocumentsFormData();

    const response = await fetch('/api/system/legal-documents.php', {
      method: 'POST',
      credentials: 'include',
      cache: 'no-store',
      headers: {
        'Content-Type': 'application/json'
      },
      body: JSON.stringify(payload)
    });

    const data = await response.json();

    if (!response.ok || !data.success) {
      throw new Error(data.error || 'Nie udało się zapisać dokumentów prawnych');
    }

    setLegalDocumentsMessage('Dokumenty prawne zapisane', 'success');
  } catch (error) {
    console.error('saveLegalDocuments error:', error);
    setLegalDocumentsMessage(error.message || 'Błąd zapisu dokumentów prawnych', 'error');
  } finally {
    button.disabled = false;
    button.textContent = originalText;
  }
}