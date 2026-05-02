<section class="panel-card hidden" data-section="ustawienia">
  <div class="panel-header">
    <h2>Ustawienia</h2>
  </div>

  <div class="settings-grid">
    <div class="settings-card">
      <h3>Godziny pracy</h3>
      <p class="settings-desc">
        Ustaw przedział godzin, w których klient może wybrać termin rezerwacji.
      </p>

      <div class="settings-row">
        <div class="form-group">
          <label for="work-start">Godzina rozpoczęcia</label>
          <input type="time" id="work-start" value="00:00">
        </div>

        <div class="form-group">
          <label for="work-end">Godzina zakończenia</label>
          <input type="time" id="work-end" value="23:59">
        </div>
      </div>
    </div>

    <div class="settings-card">
      <h3>Czas rezerwacji</h3>
      <p class="settings-desc">
        Ustaw długość usługi, przerwę między terminami oraz bufor przed rezerwacją.
      </p>

      <div class="settings-row">
        <div class="form-group">
          <label for="consultation-duration">Długość konsultacji</label>
          <select id="consultation-duration">
            <option value="15">15 minut</option>
            <option value="30">30 minut</option>
            <option value="45">45 minut</option>
            <option value="60" selected>60 minut</option>
            <option value="90">90 minut</option>
            <option value="120">120 minut</option>
          </select>
        </div>

        <div class="form-group">
          <label for="consultation-break">Przerwa między konsultacjami</label>
          <input type="number" id="consultation-break" min="0" step="5" value="0">
          <div class="settings-help">Ile minut przerwy ma być między jedną konsultacją a następną.</div>
        </div>

        <div class="form-group">
          <label for="booking-buffer">Bufor rezerwacji</label>
          <input type="number" id="booking-buffer" min="0" step="15" value="0">
          <div class="settings-help">Ile minut przed wizytą klient nie może już wybrać terminu.</div>
        </div>
      </div>
    </div>

    <div class="settings-card">
      <h3>Zakres kalendarza</h3>
      <p class="settings-desc">
        Określ od którego miesiąca i na ile miesięcy do przodu klient widzi dostępne terminy.
      </p>

      <div class="settings-row">
        <div class="form-group">
          <label for="booking-start-month-offset">Start od miesiąca</label>
          <input type="number" id="booking-start-month-offset" min="0" max="11" step="1" value="0">
          <div class="settings-help">0 = bieżący miesiąc, 1 = następny, 2 = za dwa miesiące itd.</div>
        </div>

        <div class="form-group">
          <label for="booking-month-range">Zakres miesięcy</label>
          <input type="number" id="booking-month-range" min="1" max="12" step="1" value="1">
          <div class="settings-help">Ile kolejnych miesięcy ma być dostępnych dla klienta.</div>
        </div>
      </div>
    </div>

    <div class="settings-card settings-actions-card">
      <h3>Zapis ustawień</h3>
      <p class="settings-desc">
        Te ustawienia sterują widocznością terminów w kalendarzu klienta.
      </p>

      <div class="settings-actions">
        <button type="button" id="save-settings-btn" class="btn">
          Zapisz ustawienia
        </button>
      </div>
    </div>
  </div>
</section>