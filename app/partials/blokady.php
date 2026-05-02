<section class="panel-card hidden" data-section="blokady">
  <div class="panel-header">
    <h2>Blokady terminów</h2>
  </div>

  <div class="block-section admin-card">
    <div class="section-header">
      <p>Blokuj całe dni, soboty, niedziele, święta, zakresy dat oraz pojedyncze godziny.</p>
    </div>

    <div class="admin-calendar-wrap">
      <div id="adminCalendar"></div>
      <div id="adminTimeSlots" class="admin-time-slots"></div>
      <div class="admin-legend">
  <span class="legend-item">
    <span class="badge-r">R</span> – rezerwacja
  </span>
</div>
    </div>

    <div id="block-settings" class="block-settings">
      <label><input type="checkbox" id="block-saturdays"> Zablokuj soboty</label>
      <label><input type="checkbox" id="block-sundays"> Zablokuj niedziele</label>
      <label><input type="checkbox" id="block-holidays"> Zablokuj święta</label>
    </div>

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