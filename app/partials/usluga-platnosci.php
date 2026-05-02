<section class="panel-card hidden service-payments-section" data-section="usluga-platnosci">
  <div class="service-payments-header">
    <div>
      <h2>Usługa i płatności</h2>
      <p>Ustaw główną usługę oraz zasady płatności za rezerwację.</p>
    </div>
  </div>

  <div class="service-payments-grid">
    <div class="settings-card service-payments-card">
      <h3>Dane usługi</h3>
      <p class="service-payments-desc">
        Wpisz nazwę, opis i cenę głównej usługi. Czas konsultacji ustawiasz osobno w zakładce Ustawienia.
      </p>

      <div class="service-payments-form-grid">
        <label class="full">
          <span>Nazwa usługi</span>
          <input type="text" id="service-name" placeholder="np. Konsultacja, Wizyta, Rezerwacja terminu">
        </label>

        <label class="full">
          <span>Opis usługi</span>
          <textarea id="service-description" rows="4" placeholder="Krótki opis usługi widoczny dla klienta."></textarea>
        </label>
      </div>
    </div>

    <div class="settings-card service-payments-card">
      <h3>Płatność za rezerwację</h3>
      <p class="service-payments-desc">
        Ustaw, czy klient musi opłacić rezerwację oraz ile ma czasu na płatność.
      </p>

      <div class="service-payments-form-grid">
      <label class="service-payments-switch full">
  <input type="checkbox" id="service-payment-required">

  <span>
    <strong>Włącz płatność online</strong>
    <small>Po rezerwacji klient zostanie przekierowany do płatności PayU.</small>
  </span>
</label>

        <label>
          <span>Kwota usługi</span>
          <input type="number" id="service-price-amount" min="0" step="0.01" placeholder="np. 150.00">
        </label>

        <label>
          <span>Waluta</span>
          <select id="service-price-currency">
            <option value="PLN">PLN</option>
          </select>
        </label>

        <label>
          <span>Czas na płatność</span>
          <input type="number" id="service-payment-time-limit-hours" min="1" step="1" value="48">
        </label>

        <label>
          <span>Jednostka</span>
          <select id="service-payment-time-unit">
            <option value="hours">godziny</option>
            <option value="days">dni</option>
          </select>
        </label>

        <label class="full">
          <span>Komunikat dla klienta</span>
          <textarea id="service-payment-message" rows="4" placeholder="np. Rezerwacja zostanie potwierdzona po zaksięgowaniu płatności."></textarea>
        </label>
      </div>
    </div>
  </div>

  <div class="service-payments-actions">
    <button type="button" class="btn btn-primary" id="save-service-payments-btn">
      Zapisz ustawienia usługi
    </button>
  </div>
</section>