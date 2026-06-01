<section class="panel-card hidden" data-section="personel">
  <div class="panel-header">
    <div>
      <h2>Personel</h2>
      <p>Zarządzaj osobami widocznymi w kalendarzu oraz ich dostępnością.</p>
    </div>
  </div>

  <div class="personel-layout">
    <div class="personel-card" id="personel-list-panel">
      <div class="personel-card-header">
        <div>
          <h3>Lista personelu</h3>
          <p class="personel-muted">Wybierz osobę, aby edytować dane, grafik i usługę.</p>
        </div>
        <button type="button" class="btn" id="personel-add-btn">Dodaj osobę</button>
      </div>

      <div class="personel-message" id="personel-list-message" role="status" aria-live="polite"></div>
      <div class="form-group personel-search">
        <label for="personel-search-input">Szukaj</label>
        <input type="search" id="personel-search-input" placeholder="Szukaj osoby..." autocomplete="off">
      </div>
      <div class="personel-list" id="personel-list"></div>
    </div>

    <div class="personel-bottom-grid">
      <div class="personel-card personel-availability-card" id="personel-availability-panel">
        <div class="personel-card-header">
          <div>
            <h3>Grafik tygodniowy</h3>
            <p class="personel-muted">Zaznacz dni pracy i ustaw jeden przedział godzinowy na dzień.</p>
          </div>
        </div>

        <form class="personel-availability" id="personel-availability-form">
          <div class="personel-days" id="personel-days"></div>

          <div class="personel-message personel-availability-action-message" id="personel-availability-message" role="status" aria-live="polite">
            Wybierz osobę, aby edytować grafik.
          </div>

          <div class="personel-actions">
            <button type="submit" class="btn btn-primary" id="personel-save-availability-btn" disabled>
              Zapisz grafik
            </button>
          </div>
        </form>
      </div>

      <div class="personel-card" id="personel-form-panel">
        <div class="personel-card-header">
          <div>
            <h3>Dane osoby z personelu</h3>
            <p class="personel-muted">Uzupełnij dane osoby widocznej w kalendarzu.</p>
          </div>
        </div>

        <form class="personel-form" id="personel-profile-form">
          <input type="hidden" id="personel-id">

          <div class="personel-form-section">
            <h4>Dane osoby</h4>

            <div class="form-group">
              <label for="personel-display-name">Imię i nazwisko</label>
              <input type="text" id="personel-display-name" autocomplete="name" required>
            </div>

            <div class="personel-form-grid">
              <div class="form-group">
                <label for="personel-email">Email</label>
                <input type="email" id="personel-email" autocomplete="email">
              </div>

              <div class="form-group">
                <label for="personel-phone">Telefon</label>
                <input type="tel" id="personel-phone" autocomplete="tel">
              </div>

              <div class="form-group">
                <label for="personel-color">Kolor</label>
                <input type="color" id="personel-color" value="#2563eb">
              </div>

              <div class="form-group">
                <label for="personel-sort-order">Kolejność</label>
                <input type="number" id="personel-sort-order" step="1" inputmode="numeric">
              </div>
            </div>

            <div class="personel-invite-box">
              <p>
                Zaproszenie pozwoli osobie z personelu ustawić hasło i zalogować się do własnego panelu, gdzie zobaczy swoje rezerwacje i blokady.
              </p>
              <label class="personel-check personel-invite-check">
                <input type="checkbox" id="personel-send-invite">
                <span>Wyślij zaproszenie do panelu personelu</span>
              </label>
            </div>

            <div class="form-group">
              <label for="personel-description">Opis osoby</label>
              <textarea id="personel-description" rows="3"></textarea>
            </div>

            <div class="personel-checkboxes">
              <label class="personel-check">
                <input type="checkbox" id="personel-is-active" checked>
                <span>Aktywny</span>
              </label>
            </div>
          </div>

          <div class="personel-message personel-form-action-message" id="personel-form-message" role="status" aria-live="polite"></div>

          <div class="personel-actions">
            <button type="button" class="btn btn-danger" id="personel-delete-btn" hidden>Usuń pracownika</button>
            <button type="submit" class="btn btn-primary">Zapisz osobę</button>
          </div>
        </form>
      </div>
    </div>
  </div>
</section>
