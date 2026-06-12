<section class="panel-card hidden" data-section="blokady">
  <div class="panel-header">
    <h2>Blokady terminów</h2>
    <button type="button" id="admin-block-refresh-btn" class="btn btn-secondary block-refresh-btn">
      Odśwież
    </button>
  </div>

  <div class="block-section admin-card">
    <div class="section-header">
      <p>Blokuj całe dni, soboty, niedziele, święta, zakresy dat oraz pojedyncze godziny.</p>
    </div>

    <div class="block-scope-card">
      <div class="block-scope-header">
        <h3>Zakres blokad</h3>
        <p>
          Blokady globalne dotyczą całej firmy i blokują termin dla wszystkich pracowników. Blokady pracownika dotyczą tylko wybranej osoby, np. urlopu, wolnego dnia albo niedostępnej godziny.
          <a class="settings-help-link" href="https://ai-iq.pl/wsparcie/rezerwacja-ai-iq-pro/instrukcja.html" target="_blank" rel="noopener noreferrer">Instrukcja</a>
        </p>
      </div>

      <div class="block-scope-options" role="group" aria-label="Zakres blokad">
        <label class="block-scope-option">
          <input type="radio" name="block-scope" value="global" checked>
          <span>
            <strong>Cała firma</strong>
            <small>Blokuje termin dla całej firmy.</small>
          </span>
        </label>

        <label class="block-scope-option">
          <input type="radio" name="block-scope" value="staff">
          <span>
            <strong>Konkretny pracownik</strong>
            <small>Blokuje termin tylko dla wybranej osoby.</small>
          </span>
        </label>
      </div>

      <div class="block-staff-select" id="block-staff-select-wrap" hidden>
        <label for="block-staff-select">Wybierz pracownika</label>
        <select id="block-staff-select">
          <option value="">Wybierz pracownika</option>
        </select>
      </div>
    </div>

    <div class="admin-calendar-wrap">
      <div id="adminCalendar"></div>
      <div id="adminTimeSlots" class="admin-time-slots"></div>
      <div class="admin-legend" id="adminReservationLegend">
        <div class="admin-legend-reservations">
          <span class="legend-item">
            <span class="badge-r">R</span> - rezerwacja
          </span>
          <span class="legend-item">
            <span class="badge-r">R↻</span> - zmiana rezerwacji
          </span>
          <span class="legend-item">
            <span class="badge-r">Rx</span> - suma rezerwacji
          </span>
        </div>
        <div class="admin-legend-blocks">
          <div><strong>blokady globalne</strong> - blokady nałożone przez administratora</div>
          <div><strong>blokady pracownika</strong> - blokady nałożone przez pracownika</div>
        </div>
      </div>
    </div>

    <div id="block-settings" class="block-settings">
      <label><input type="checkbox" id="block-saturdays"> Zablokuj soboty</label>
      <label><input type="checkbox" id="block-sundays"> Zablokuj niedziele</label>
      <label><input type="checkbox" id="block-holidays"> Zablokuj święta</label>
    </div>
    <p id="staff-block-settings-note" class="staff-block-settings-note" hidden>
      Soboty, niedziele i święta są ustawieniem całej firmy. Możesz jednak odblokować pojedynczy dzień tylko dla wybranego pracownika.
    </p>
    <div class="block-range-box">
      <div class="form-row">
        <div class="form-group">
          <label for="range-from">Od</label>
          <input type="date" id="range-from">
        </div>

        <div class="form-group">
          <label for="range-to">Do</label>
          <input type="date" id="range-to">
        </div>

        <div class="form-group form-group-button">
          <label>&nbsp;</label>
          <button type="button" id="block-range-btn" class="btn btn-danger">
            Zablokuj zakres
          </button>
          <button type="button" id="unblock-range-btn" class="btn btn-success">
  Odblokuj zakres
</button>
        </div>
      </div>
    </div>

    <p id="block-message" class="message" style="display:none;"></p>
  </div>
</section>
