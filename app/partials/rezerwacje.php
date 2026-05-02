        <section class="panel-card" data-section="rezerwacje">
        
        <div class="admin-calendar-status-card" id="calendarStatusCard">
  <div class="calendar-status-content">
    <div class="calendar-status-text">
      <h3>Status kalendarza rezerwacji</h3>
      <p>
        Przed włączeniem kalendarza ustaw dostępność w zakładce „Ustawienia” oraz skonfiguruj e-mail z powiadomieniem dla klienta.
        W przeciwnym razie klient może złożyć rezerwację bez poprawnego potwierdzenia mailowego.
      </p>
    </div>

    <div class="calendar-status-control">
      <label class="calendar-status-toggle">
        <input type="checkbox" id="calendar-enabled-toggle">
        <span id="calendar-enabled-label">Kalendarz wyłączony</span>
      </label>

      <button class="btn" type="button" id="save-calendar-enabled-btn">
        Zapisz status kalendarza
      </button>

      <span class="admin-inline-message" id="calendar-enabled-message"></span>
    </div>
  </div>
</div>

      <div class="dashboard-cards">

  <div class="dash-card">
    <span class="dash-title">Dzisiejsze rezerwacje</span>
    <strong class="dash-value" id="stat-today">0</strong>
  </div>

  <div class="dash-card">
    <span class="dash-title">Zablokowane dni</span>
    <strong class="dash-value" id="stat-blocked-days">0</strong>
  </div>

  <div class="dash-card">
    <span class="dash-title">Zablokowane godziny</span>
    <strong class="dash-value" id="stat-blocked-times">0</strong>
  </div>

  <div class="dash-card">
    <span class="dash-title">Status systemu</span>
    <strong class="dash-value" id="stat-system">...</strong>
  </div>

</div>
        
        <div class="panel-header">
                
          <h2>Rezerwacje</h2>
        
        <button class="refresh-btn" id="refreshBtn" type="button">Odśwież</button>
        </div>

        <div class="table-wrap">
          <table class="rezerwacje-table">
       
          <thead>
  <tr>
    <th class="col-name">Klient</th>
    <th class="col-contact">Kontakt</th>
    <th class="col-date">Data</th>
    <th class="col-time">Godzina</th>
    <th class="col-desc">Opis</th>
    <th class="col-payment">Płatność</th>
    <th class="col-actions">Akcja</th>
  </tr>
</thead>
          
<tbody id="bookingList">
  <tr>
    <td colspan="7" class="empty">Ładowanie danych...</td>
  </tr>
</tbody>
          </table>
        </div>
      
<div class="form-group">
  
</div>
</section>