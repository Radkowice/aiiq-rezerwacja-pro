document.addEventListener('DOMContentLoaded', () => {
  loadClientLegalFavicon();
  loadPublicLegalDocument();
});

function getCurrentLegalDocumentType() {
  const path = window.location.pathname;

  if (path.includes('polityka-prywatnosci')) {
    return 'privacy';
  }

  return 'terms';
}

function setPageFavicon(faviconUrl) {
  const cleanUrl = String(faviconUrl || '').trim();

  if (!cleanUrl) return;

  let faviconEl = document.querySelector('link[rel="icon"]');

  if (!faviconEl) {
    faviconEl = document.createElement('link');
    faviconEl.rel = 'icon';
    document.head.appendChild(faviconEl);
  }

  faviconEl.href = cleanUrl;
}

async function loadClientLegalFavicon() {
  try {
    const response = await fetch('/api/system/branding-public.php', {
      method: 'GET',
      cache: 'no-store'
    });

    const data = await response.json();

    if (!response.ok || data.success !== true || !data.branding) {
      return;
    }

    const faviconUrl = data.branding.favicon_url_front || '';

    setPageFavicon(faviconUrl);
  } catch (error) {
    console.error('loadClientLegalFavicon error:', error);
  }
}

function formatLegalDate(value) {
  if (!value) return '';

  const date = new Date(value);

  if (Number.isNaN(date.getTime())) return '';

  return date.toLocaleDateString('pl-PL', {
    year: 'numeric',
    month: '2-digit',
    day: '2-digit'
  });
}

function renderLegalText(text) {
  const content = document.getElementById('legalDocumentContent');
  if (!content) return;

  const cleanText = String(text || '')
    .replace(/\r\n/g, '\n')
    .replace(/\r/g, '\n')
    .trim();

  if (!cleanText) {
    content.textContent = 'Dokument nie został jeszcze uzupełniony.';
    return;
  }

  content.innerHTML = '';

  const lines = cleanText
    .split('\n')
    .map(line => line.trim())
    .filter(Boolean);

  lines.forEach((line, index) => {
    let element;

    if (/^(I|II|III|IV|V|VI|VII|VIII|IX|X|XI|XII)\.\s+/.test(line)) {
      element = document.createElement('h2');
      element.textContent = line;
    } else if (/^\d+\.\s+/.test(line)) {
      element = document.createElement('h3');
      element.textContent = line;
    } else if (index === 0 && line.length < 80) {
      element = document.createElement('p');
      element.className = 'legal-client-intro';
      element.textContent = line;
    } else {
      element = document.createElement('p');
      element.textContent = line;
    }

    content.appendChild(element);
  });
}

async function loadPublicLegalDocument() {
  const titleEl = document.getElementById('legalDocumentTitle');
  const updatedEl = document.getElementById('legalDocumentUpdated');
  const contentEl = document.getElementById('legalDocumentContent');
  const eyebrowEl = document.querySelector('.legal-client-eyebrow');

  if (eyebrowEl) {
    eyebrowEl.textContent = '';
  }

  const renderMissingDocumentsMessage = providerCompanyName => {
    if (!contentEl) return;

    const cleanProviderCompanyName = String(providerCompanyName || '').trim();
    contentEl.textContent = cleanProviderCompanyName
      ? `Usługodawca nie przygotował regulaminu oraz polityki prywatności. Skontaktuj się z ${cleanProviderCompanyName}.`
      : 'Usługodawca nie przygotował regulaminu oraz polityki prywatności.';
  };

  try {
    const response = await fetch('/api/system/legal-documents-public.php', {
      method: 'GET',
      cache: 'no-store'
    });

    const data = await response.json();
    const providerCompanyName = String(data?.provider?.company_full_name || '').trim();

    if (!response.ok || !data.success || !data.enabled || !data.documents) {
      renderMissingDocumentsMessage(providerCompanyName);

      if (updatedEl) {
        updatedEl.textContent = '';
      }

      return;
    }

    const type = getCurrentLegalDocumentType();
    const docs = data.documents;
    const formatDocumentTitle = title => (
      providerCompanyName ? `${title} ${providerCompanyName}` : title
    );

    if (type === 'privacy') {
      const pageTitle = formatDocumentTitle(docs.privacy_title || 'Polityka prywatności');
      if (titleEl) titleEl.textContent = pageTitle;
      document.title = pageTitle;
      renderLegalText(docs.privacy_content || '');
    } else {
      const pageTitle = formatDocumentTitle(docs.terms_title || 'Regulamin rezerwacji');
      if (titleEl) titleEl.textContent = pageTitle;
      document.title = pageTitle;
      renderLegalText(docs.terms_content || '');
    }

    const date = formatLegalDate(docs.updated_at);
    if (updatedEl) {
      updatedEl.textContent = date ? `Ostatnia aktualizacja: ${date}` : '';
    }
  } catch (error) {
    console.error('loadPublicLegalDocument error:', error);

    if (contentEl) {
      contentEl.textContent = error.message || 'Nie udało się wczytać dokumentu.';
    }

    if (updatedEl) {
      updatedEl.textContent = '';
    }
  }
}
