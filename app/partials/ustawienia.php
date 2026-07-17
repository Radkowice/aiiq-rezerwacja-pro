<section class="panel-card hidden" data-section="ustawienia">
  <div class="panel-header">
    <h2>Ustawienia globalne</h2>
    <p class="settings-desc">
      Te ustawienia są domyślne dla kalendarza bez aktywnego personelu. Gdy korzystasz z pracowników,
      dostępność terminów wynika głównie z usług, przypisań personelu, grafików i blokad.
    </p>
  </div>

  <div class="settings-grid">
    <div class="settings-card">
      <h3>Globalne godziny pracy</h3>
      <p class="settings-desc">
        Używane jako domyślne godziny dostępności, gdy nie ma aktywnych pracowników.
        Przy aktywnym personelu pierwszeństwo mają grafiki i blokady pracowników.
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
      <h3>Domyślne ustawienia rezerwacji</h3>
      <p class="settings-desc">
        Używany głównie jako ustawienie domyślne, gdy rezerwacje nie są obsługiwane przez aktywny personel.
        Przy usługach i pracownikach pierwszeństwo mają ustawienia z zakładki „Usługa i płatności”.
      </p>

      <div class="settings-row">
        <div class="form-group">
          <label for="consultation-duration">Domyślny czas trwania rezerwacji</label>
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
          <label for="consultation-break">Przerwa między rezerwacjami</label>
          <input type="number" id="consultation-break" min="0" step="5" value="0">
          <div class="settings-help">
            Globalna przerwa między rezerwacjami bez aktywnego personelu. Przy personelu używaj ustawień usług i grafików.
          </div>
        </div>

        <div class="form-group">
          <label for="booking-min-notice-value">Minimalne wyprzedzenie rezerwacji</label>
          <div class="settings-row">
            <input type="number" id="booking-min-notice-value" min="0" step="1" value="0">
            <select id="booking-min-notice-unit">
              <option value="minutes">minuty</option>
              <option value="hours">godziny</option>
              <option value="days">dni</option>
            </select>
          </div>
          <div class="settings-help">
            Globalne minimalne wyprzedzenie rezerwacji. To nie jest przerwa między wizytami.
            Przykład: ustaw 2 godziny, jeśli klient ma móc zarezerwować wizytę najwcześniej za 2 godziny.
          </div>
        </div>
      </div>
    </div>

    <div class="settings-card">
      <h3>Zakres widoczności kalendarza</h3>
      <p class="settings-desc">
        To ustawienie działa zawsze na stronie rezerwacji, niezależnie od personelu. Określa, od którego miesiąca
        i na ile miesięcy do przodu klient widzi kalendarz.
      </p>

      <div class="settings-row">
        <div class="form-group">
          <label for="booking-start-month-offset">Pierwszy widoczny miesiąc</label>
          <input type="number" id="booking-start-month-offset" min="0" max="11" step="1" value="0">
          <div class="settings-help">
            0 = bieżący miesiąc, 1 = następny, 2 = za dwa miesiące itd. Działa niezależnie od personelu.
          </div>
        </div>

        <div class="form-group">
          <label for="booking-month-range">Zakres miesięcy</label>
          <input type="number" id="booking-month-range" min="1" max="12" step="1" value="1">
          <div class="settings-help">Ile kolejnych miesięcy ma być dostępnych dla klienta na stronie rezerwacji.</div>
        </div>
      </div>
    </div>

    <div class="settings-card settings-actions-card">
      <h3>Zapis ustawień globalnych</h3>
      <p class="settings-desc">
        Zakres widoczności kalendarza działa zawsze. Pozostałe ustawienia są domyślne dla trybu bez aktywnego personelu
        albo jako baza, jeśli dana konfiguracja nie ma własnych ustawień.
      </p>

      <div class="settings-actions">
        <button type="button" id="save-settings-btn" class="btn">
          Zapisz ustawienia
        </button>
      </div>
    </div>
  </div>
</section>
