<section class="panel-card hidden service-payments-section" data-section="usluga-platnosci">
  <div class="panel-header">
    <div>
      <h2>Usługi i płatności</h2>
      <p>Zarządzaj usługami, płatnościami i przypisaniem pracowników.</p>
    </div>
    <button type="button" class="btn btn-secondary" id="service-payments-refresh-btn">Odśwież</button>
  </div>

  <div class="service-payments-grid">
    <div class="settings-card service-payments-card service-payments-info-card">
      <h3>Jak działają ustawienia</h3>
      <p class="service-payments-desc">
        Ustawienia globalne są używane wtedy, gdy nie utworzysz osobnych usług.
      </p>
     
    </div>

    <div class="settings-card service-payments-card service-payments-global-card">
      <h3>Ustawienia globalne usługi i płatności</h3>
      <p class="service-payments-desc">
        Ustawienia globalne są używane jako domyślna usługa wtedy, gdy nie tworzysz osobnych usług albo nie korzystasz z personelu. Jeżeli tworzysz usługi poniżej, każda usługa ma własną nazwę, cenę i płatność.
      </p>

      <div class="service-payments-form-grid service-payments-global-grid">
        <label class="full">
          <span>Globalna nazwa usługi</span>
          <input type="text" id="global-service-name" placeholder="np. Konsultacja, Wizyta, Rezerwacja terminu">
        </label>

        <div class="plan-payments-pro-group full">
          <label class="full">
            <span>Globalny opis usługi</span>
            <textarea id="global-service-description" rows="3" placeholder="Krótki opis usługi widoczny dla klienta."></textarea>
          </label>

          <label>
            <span>Globalna cena</span>
            <input type="number" id="global-service-price" min="0" step="0.01" placeholder="np. 150.00">
          </label>

          <label>
            <span>Waluta</span>
            <input type="text" id="global-service-currency" maxlength="3" value="PLN">
          </label>

          <label class="service-payments-switch full">
            <input type="checkbox" id="global-service-payment-required">
            <span>
              <strong>Globalna płatność online włączona</strong>
              <small>Ta płatność dotyczy domyślnej usługi w kalendarzu.</small>
            </span>
          </label>

          <label class="full">
            <span>Globalny komunikat płatności</span>
            <textarea id="global-service-payment-message" rows="3" placeholder="np. Rezerwacja zostanie potwierdzona po zaksięgowaniu płatności."></textarea>
          </label>
        </div>
      </div>

    </div>
  </div>

  <div class="settings-card service-payments-card service-payment-deadline-card">
    <h3>Termin płatności</h3>
    <p class="service-payments-desc">Termin płatności dotyczy wszystkich usług oraz ustawień globalnych.</p>
    <div class="service-payment-deadline">
      <div class="service-payment-deadline-controls">
        <input type="number" id="global-payment-time-limit-value" min="1" max="10080" step="1" value="24" aria-label="Wartość terminu płatności">
        <select id="global-payment-time-limit-unit" aria-label="Jednostka terminu płatności">
          <option value="hours">godziny</option>
          <option value="days">dni</option>
        </select>
      </div>
      <small>Po tym czasie system może wysłać przypomnienie o nieopłaconej rezerwacji.</small>
    </div>
    <div class="service-global-actions">
      <div class="service-action-message" id="global-service-message" hidden></div>
      <button type="button" class="btn btn-primary" id="global-service-save-btn">Zapisz ustawienia globalne</button>
    </div>
  </div>

  <div class="service-manager-layout">
    <div class="settings-card service-payments-card service-list-card">
      <div class="service-card-header">
        <div>
          <h3>Lista usług</h3>
          <p class="service-payments-desc">Edytuj istniejące usługi albo wyłącz te, których nie chcesz pokazywać klientom.</p>
        </div>
        <button type="button" class="btn btn-secondary" id="service-new-btn">Nowa usługa</button>
      </div>

      <div class="service-search">
        <label for="service-search-input">Szukaj</label>
        <input type="search" id="service-search-input" placeholder="Szukaj usługi..." autocomplete="off">
      </div>
      <div class="service-action-message" id="service-list-message" hidden></div>
      <div class="service-list" id="service-list"></div>
    </div>

    <div class="settings-card service-payments-card service-form-card">
      <div class="service-card-header">
        <div>
          <h3 id="service-form-title">Nowa usługa</h3>
          <p class="service-payments-desc">Ustaw dane usługi, płatność oraz pracowników dostępnych dla tej usługi.</p>
        </div>
      </div>

      <form class="service-form" id="service-form" novalidate>
        <input type="hidden" id="service-id">

        <div class="service-payments-form-grid">
          <label class="full">
            <span>Nazwa usługi</span>
            <input type="text" id="service-name" maxlength="160" placeholder="np. Konsultacja, Wizyta, Rezerwacja terminu" required>
          </label>

          <label class="full">
            <span>Opis usługi</span>
            <textarea id="service-description" rows="4" maxlength="2000" placeholder="Krótki opis usługi widoczny dla klienta."></textarea>
          </label>

          <label>
            <span>Czas trwania w minutach</span>
            <input type="number" id="service-duration-minutes" min="1" max="1440" step="1" value="60" required>
          </label>

          <label>
            <span>Przerwa po usłudze w minutach</span>
            <input type="number" id="service-break-minutes" min="0" max="1440" step="1" value="0">
          </label>

          <label class="full">
            <span>Minimalne wyprzedzenie rezerwacji dla tej usługi</span>
            <div class="settings-row">
              <input type="number" id="service-min-notice-value" min="0" step="1" value="0">
              <select id="service-min-notice-unit">
                <option value="minutes">minuty</option>
                <option value="hours">godziny</option>
                <option value="days">dni</option>
              </select>
            </div>
            <small>
              Określa, z jakim wyprzedzeniem klient może najwcześniej zarezerwować tę usługę.
              Jeśli ustawisz 2 godziny, klient będzie mógł wybrać termin najwcześniej za 2 godziny.
              Jeśli pole zostanie ustawione na 0, użyte zostanie ustawienie globalne.
            </small>
          </label>

          <label>
            <span>Kolejność</span>
            <input type="number" id="service-sort-order" min="0" step="1" value="0" placeholder="10">
            <small class="service-field-hint">Niższa liczba oznacza wyższą pozycję w kalendarzu rezerwacji.</small>
          </label>

          <label>
            <span>Cena</span>
            <input type="number" id="service-price-amount" min="0" step="0.01" placeholder="np. 150.00">
          </label>

          <label>
            <span>Waluta</span>
            <input type="text" id="service-price-currency" maxlength="3" value="PLN">
          </label>

          <label class="service-payments-switch full">
            <input type="checkbox" id="service-payments-enabled">
            <span>
              <strong>Płatność online włączona</strong>
              <small>Po rezerwacji klient będzie mógł przejść do płatności online dla tej usługi.</small>
            </span>
          </label>

          <label class="full">
            <span>Komunikat płatności</span>
            <textarea id="service-payment-message" rows="3" maxlength="2000" placeholder="np. Rezerwacja zostanie potwierdzona po zaksięgowaniu płatności."></textarea>
          </label>

          <div class="service-payments-checkboxes full">
            <label class="service-payments-check">
              <input type="checkbox" id="service-is-active" checked>
              <span>
                <strong>Usługa aktywna</strong>
                <small>Usługę można rezerwować w systemie.</small>
              </span>
            </label>

            <label class="service-payments-check">
              <input type="checkbox" id="service-visible-on-front" checked>
              <span>
                <strong>Widoczna dla klientów</strong>
                <small>Klient zobaczy tę usługę w kalendarzu.</small>
              </span>
            </label>
          </div>
        </div>

        <div class="service-staff-section">
          <h4>Przypisani pracownicy</h4>
          <p class="service-payments-desc">Wybierz osoby, które wykonują tę usługę.</p>
          <div class="service-action-message" id="service-staff-message" hidden></div>
          <div class="service-staff-list" id="service-staff-list"></div>
        </div>

        <div class="service-form-actions">
          <div class="service-action-message" id="service-form-message" hidden></div>
          <button type="button" class="btn btn-primary" id="service-save-btn">Zapisz usługę</button>
        </div>
      </form>
    </div>
  </div>
</section>
