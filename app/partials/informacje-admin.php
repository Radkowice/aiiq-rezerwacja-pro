<section class="panel-section admin-info-section hidden" data-section="informacje">
  <div class="admin-info-header">
    <div>
      <p class="admin-info-eyebrow">Konto i aplikacja</p>
      <h1>Informacje</h1>
      <p>
        Informacje o wersji programu, abonamencie, danych firmy oraz kontakcie do supportu.
      </p>
    </div>

    <div class="admin-info-version-pill">
      <span>Wersja aplikacji</span>
      <strong id="info-app-version">AI-IQ Rezerwacja Pro 1.0</strong>
    </div>
  </div>

  <div class="admin-info-grid">
    <div class="admin-info-card admin-info-main-card">
      <div class="admin-info-card-head">
        <div>
          <h2>Abonament</h2>
          <p>Aktualne informacje o planie, statusie i terminie kolejnej płatności.</p>
        </div>
        <span id="info-subscription-status-badge" class="admin-info-badge active">Aktywny</span>
      </div>

      <div class="admin-info-list">
        <div class="admin-info-row">
          <span>Plan</span>
          <strong id="info-plan-name">Start</strong>
        </div>

        <div class="admin-info-row">
          <span>Okres rozliczeniowy</span>
          <strong id="info-billing-period">Miesięczny</strong>
        </div>

        <div class="admin-info-row">
          <span>Następna płatność</span>
          <strong id="info-next-payment">28.05.2026</strong>
        </div>

        <div class="admin-info-row">
          <span>Kwota abonamentu</span>
          <strong id="info-payment-amount">50 zł</strong>
        </div>
      </div>
    </div>

    <div class="admin-info-card admin-info-company-card">
      <div class="admin-info-card-head">
        <div>
          <h2>Dane firmy / dane do FV</h2>
          <p>Dane formalne klienta używane do faktur, płatności i kontaktu administracyjnego.</p>
        </div>
      </div>

      <div class="admin-info-list admin-info-account-list">
        <div class="admin-info-row">
          <span>Nazwa publiczna / marka</span>
          <strong id="info-company-name">—</strong>
        </div>

        <div class="admin-info-row">
          <span>Numer klienta</span>
          <strong id="info-client-number">—</strong>
        </div>

        <div class="admin-info-row">
          <span>ID firmy</span>
          <strong id="info-company-id">—</strong>
        </div>
      </div>

      <div class="admin-info-form-grid">
        <label class="full">
          <span>Pełna nazwa firmy</span>
          <input type="text" id="info-company-full-name" placeholder="">
        </label>

        <label>
          <span>Imię i nazwisko</span>
          <input type="text" id="info-company-owner-name" placeholder="">
        </label>

        <label>
          <span>NIP</span>
          <input type="text" id="info-company-tax-id" placeholder="">
        </label>

        <label class="full">
          <span>Adres firmy</span>
          <input type="text" id="info-company-address" placeholder="ulica, numer, kod pocztowy, miasto">
        </label>

        <label>
          <span>E-mail</span>
          <input type="email" id="info-company-email" placeholder="kontakt@firma.pl" autocomplete="email">
        </label>

        <label>
          <span>Telefon służbowy</span>
          <input type="text" id="info-company-phone" placeholder="+48 000 000 000" autocomplete="tel">
        </label>
      </div>

      <div class="admin-info-actions">
        <button type="button" class="btn btn-primary" id="save-company-info-btn">
          Zapisz dane firmy
        </button>
      </div>

      <p id="company-info-message" class="admin-info-message" aria-live="polite"></p>
    </div>

    <div class="admin-info-card admin-info-support-card">
      <h2>Support</h2>
      <p>
        Kontakt w sprawach technicznych, płatności za aplikację, zmiany planu abonamentowego oraz pomocy przy konfiguracji.
      </p>

      <div class="admin-info-support-box">
        <span>Email supportu</span>
        <strong id="info-support-email">biuro@ai-iq.pl</strong>
      </div>

      <div class="admin-info-support-box">
        <span>Strona</span>
        <strong id="info-support-url">www.ai-iq.pl</strong>
      </div>
    </div>
  </div>
</section>