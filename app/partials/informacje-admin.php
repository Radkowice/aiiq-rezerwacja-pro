<section class="panel-card hidden" data-section="informacje">
  <div class="panel-header">
    <h2>Informacje</h2>
  </div>

  <div class="admin-info-grid">
    <div class="admin-info-card admin-info-subscription-card">
      <h3>Abonament</h3>

      <div class="admin-info-list">
        <div class="admin-info-row">
          <span>Plan</span>
          <strong id="info-plan-name">—</strong>
        </div>

        <div class="admin-info-row">
          <span>Okres rozliczeniowy</span>
          <strong id="info-billing-period">—</strong>
        </div>

        <div class="admin-info-row">
          <span>Najbliższa płatność</span>
          <strong id="info-next-payment">—</strong>
        </div>

        <div class="admin-info-row">
          <span>Kwota abonamentu</span>
          <strong id="info-amount">—</strong>
        </div>

        <div class="admin-info-row">
          <span>Status</span>
          <strong id="info-status">—</strong>
        </div>

        <div class="admin-info-row">
          <span>Okres abonamentu</span>
          <strong id="info-current-period">—</strong>
        </div>

        <div class="admin-info-row">
          <span>Okres ochronny</span>
          <strong id="info-grace-period">—</strong>
        </div>
      </div>
    </div>

    <div class="admin-info-card admin-info-pro-upgrade-card" id="pro-upgrade-card" hidden>
      <h3>Przejdź na plan Pro</h3>

      <p class="admin-info-desc">
        Odblokuj pełne możliwości systemu rezerwacji i rozwijaj obsługę klientów bez ręcznego pilnowania wszystkiego.
      </p>

      <ul class="pro-upgrade-benefits">
        <li>obsługa personelu,</li>
        <li>wiele usług,</li>
        <li>płatności online,</li>
        <li>integracja z Google Calendar,</li>
        <li>własne logo i wygląd kalendarza,</li>
        <li>zaawansowane ustawienia rezerwacji,</li>
        <li>automatyczne przypomnienia i większa kontrola nad rezerwacjami.</li>
      </ul>

      <div class="pro-upgrade-options" id="pro-upgrade-options" role="radiogroup" aria-label="Okres rozliczeniowy planu Pro">
        <label class="pro-upgrade-option">
          <input type="radio" name="pro-upgrade-period" value="monthly">
          <span>
            <strong>Miesięczny</strong>
            <small id="pro-price-monthly">—</small>
          </span>
        </label>

        <label class="pro-upgrade-option">
          <input type="radio" name="pro-upgrade-period" value="yearly">
          <span>
            <strong>Roczny</strong>
            <small id="pro-price-yearly">—</small>
          </span>
        </label>
      </div>

      <div class="admin-info-actions pro-upgrade-actions">
        <button type="button" class="btn btn-primary" id="pro-upgrade-btn" disabled>
          Przejdź na plan Pro
        </button>
      </div>

      <p class="pro-upgrade-note" id="pro-upgrade-note">
        W kolejnym kroku zostaniesz przekierowany do płatności. Funkcje Pro zostaną aktywowane po zaksięgowaniu płatności.
      </p>

      <p class="pro-upgrade-message" id="pro-upgrade-message" hidden></p>
    </div>

    <div class="admin-info-card admin-info-company-card">
      <h3>Dane firmy</h3>

     <div class="admin-info-list">
  <div class="admin-info-row">
    <span>Nazwa publiczna / marka</span>
    <strong id="info-company-name">—</strong>
  </div>

  <div class="admin-info-row">
    <span>Pełna nazwa firmy</span>
    <strong id="info-company-full-name">—</strong>
  </div>

  <div class="admin-info-row">
    <span>Właściciel / osoba kontaktowa</span>
    <strong id="info-company-owner-name">—</strong>
  </div>

  <div class="admin-info-row">
    <span>NIP</span>
    <strong id="info-company-tax-id">—</strong>
  </div>

  <div class="admin-info-row">
    <span>Adres firmy</span>
    <input type="text" id="info-company-address" class="admin-info-input" autocomplete="street-address">
  </div>

  <div class="admin-info-row">
    <span>Email firmowy</span>
    <input type="email" id="info-company-email" class="admin-info-input" autocomplete="email">
  </div>

  <div class="admin-info-row">
    <span>Telefon firmowy</span>
    <input type="tel" id="info-company-phone" class="admin-info-input" autocomplete="tel">
  </div>

  <div class="admin-info-row">
    <span>Numer klienta</span>
    <strong id="info-client-number">—</strong>
  </div>

  <div class="admin-info-row">
    <span>Identyfikator firmy</span>
    <strong id="info-company-id">—</strong>
  </div>

  <div class="admin-info-row">
    <span>Tenant ID</span>
    <strong id="info-tenant-id">—</strong>
  </div>

  <div class="admin-info-row">
    <span>Email administratora</span>
    <strong id="info-user-email">—</strong>
  </div>

  <div class="admin-info-row">
    <span>Rola użytkownika</span>
    <strong id="info-user-role">—</strong>
  </div>
</div>

      <div class="admin-info-actions">
        <button type="button" class="btn btn-primary" id="save-company-contact-btn">
          Zapisz dane firmy
        </button>
      </div>
      
    </div>

    <div class="admin-info-card admin-info-version-card">
      <h3>Wersja aplikacji</h3>

      <div class="admin-info-list">
        <div class="admin-info-row">
          <span>System</span>
          <strong>AI-IQ Rezerwacja Pro</strong>
        </div>

        <div class="admin-info-row">
          <span>Wersja</span>
          <strong>1.0</strong>
        </div>

        <div class="admin-info-row">
          <span>Środowisko</span>
          <strong>Produkcja</strong>
        </div>
      </div>
    </div>

    <div class="admin-info-card admin-info-support-card">
      <h3>Support</h3>

      <p class="admin-info-desc">
        W razie problemów technicznych, pytań dotyczących abonamentu albo potrzeby zmiany danych,
        skontaktuj się z obsługą AI-IQ.
      </p>

      <div class="admin-info-list">
        <div class="admin-info-row">
          <span>Email</span>
          <strong>biuro@ai-iq.pl</strong>
        </div>
      </div>
    </div>
  </div>
</section>
