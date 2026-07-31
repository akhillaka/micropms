/**
 * Booking Assistant - Core Controller & SPA Router
 */
class BookingAssistant {
  constructor() {
    this.currentUser = null;
    this.csrfToken = '';
    this.activeScreen = 'login';
    this.screenHistory = [];
    
    // Tutorial state
    this.tutorialStep = 0;
    this.tutorialSeen = localStorage.getItem('pms_tutorial_seen') === 'true';
    
    // Numpad state
    this.activeNumpadTarget = null; // 'search_phone', 'new_phone', 'custom_rate', 'advance_amount', 'upi_ref', 'profile_pin', 'history_search'
    this.numpadValue = '';
    
    // Wizard State
    this.wizardStep = 1;
    this.wizardData = {
      guest_id: null,
      guest_name: '',
      guest_phone: '',
      is_new_guest: false,
      id_proof_front: null,
      id_proof_back: null,
      photo: null,
      id_status: 'Pending',
      room_id: null,
      room_number: '',
      category_id: null,
      category_name: '',
      rate_plan_name: null, // null = Standard
      rate_plans: [],
      price_override: null,
      room_cost: 0,
      extra_bed_cost: 0,
      total_cost: 0,
      check_in: '',
      check_out: '',
      adults: 2,
      children: 0,
      extra_bed: 0,
      payment_collected: 0,
      payment_method: 'Cash',
      payment_ref: '',
      offline_folio_id: null
    };

    // Camera/OCR State
    this.mediaStream = null;
    this.activeScanSide = 'front'; // 'front', 'back', 'photo'
    this.scanType = 'physical'; // 'physical', 'whatsapp', 'other'

    // History filter
    this.historyFilter = 'today';
    this.historySearch = '';
    this.paymentMethods = ['Cash', 'UPI'];

    this.init();
  }

  async init() {
    this.initEventListeners();
    this.initOnlineStatusCheck();
    this.applyPaymentMethodsToUI(); // Ensure pills are generated even before API fetch
    await this.checkAuthStatus();
  }

  // Initialize event listeners
  initEventListeners() {
    // Check if network status shifts
    window.addEventListener('online', () => this.handleNetworkChange(true));
    window.addEventListener('offline', () => this.handleNetworkChange(false));
  }

  // Network offline/online status check
  initOnlineStatusCheck() {
    this.handleNetworkChange(navigator.onLine);
  }

  handleNetworkChange(isOnline) {
    const banner = document.getElementById('offline-banner');
    if (!banner) return;
    if (isOnline) {
      banner.style.display = 'none';
    } else {
      banner.style.display = 'block';
    }
  }

  // Check user auth status on startup
  async checkAuthStatus() {
    this.showLoading('Checking session...');
    try {
      const response = await this.apiCall('api/auth.php?action=status');
      this.hideLoading();
      if (response && response.success && response.logged_in) {
        this.currentUser = response.user;
        this.csrfToken = response.csrf_token;
        document.getElementById('user-display-name').textContent = this.currentUser.username.toUpperCase();
        this.showScreen('dashboard');
        this.startLiveSync();
      } else {
        this.loadStaffListForLogin();
      }
    } catch (e) {
      this.hideLoading();
      this.loadStaffListForLogin();
    }
  }

  // Background Live Sync (Real-time updates without refreshing the page)
  startLiveSync() {
    if (this.syncInterval) clearInterval(this.syncInterval);

    // Auto-poll every 6 seconds
    this.syncInterval = setInterval(() => {
      if (document.visibilityState === 'visible') {
        this.performLiveSync();
      }
    }, 6000);

    if (!this.boundVisibilityHandler) {
      this.boundVisibilityHandler = () => {
        if (document.visibilityState === 'visible') {
          this.performLiveSync();
        }
      };
      document.addEventListener('visibilitychange', this.boundVisibilityHandler);
    }
  }

  async performLiveSync() {
    if (!this.currentUser) return;
    try {
      // 1. Silent dashboard update
      await this.loadDashboardData(true);

      // 2. Silent active screen update if no popup is active
      const isModalActive = document.querySelector('.modal-overlay.active');
      if (!isModalActive) {
        if (this.activeScreen === 'history') {
          await this.loadBookingHistory(true);
        } else if (this.activeScreen === 'housekeeping' || this.activeScreen === 'rooms') {
          await this.loadHousekeepingRooms(true);
        } else if (this.activeScreen === 'payments') {
          await this.showPaymentCollectScreen(null, true);
        }
      }
    } catch (e) {
      // Ignore background sync errors silently
    }
  }

  // Route SPA screens
  showScreen(screenId) {
    const screens = document.querySelectorAll('.screen');
    screens.forEach(s => s.classList.remove('active'));
    
    const activeScr = document.getElementById(`screen-${screenId}`);
    if (activeScr) {
      activeScr.classList.add('active');
      this.activeScreen = screenId;
    }

    // Toggle bottom navigation active state
    const navButtons = document.querySelectorAll('.nav-item');
    navButtons.forEach(btn => btn.classList.remove('active'));

    if (screenId === 'dashboard') {
      document.getElementById('nav-btn-home').classList.add('active');
      this.loadDashboardData();
    } else if (screenId === 'history') {
      document.getElementById('nav-btn-bookings').classList.add('active');
      this.loadBookingHistory();
    } else if (screenId === 'notifications') {
      document.getElementById('nav-btn-notifications').classList.add('active');
      this.loadNotificationsScreen();
    } else if (screenId === 'profile') {
      document.getElementById('nav-btn-profile').classList.add('active');
      this.loadProfileScreen();
    } else if (screenId === 'housekeeping') {
      document.getElementById('nav-btn-housekeeping').classList.add('active');
      this.loadHousekeepingData();
    } else if (screenId === 'rooms') {
      this.loadHousekeepingRooms();
    }
  }

  // --- HOUSEKEEPING ADDON ---
  async loadHousekeepingData() {
    this.showLoading('Loading rooms...');
    try {
      const res = await this.apiCall('/api/admin/housekeeping', { action: 'list' });
      this.hideLoading();
      if (res && res.success) {
        this.housekeepingData = res;
        if (!this.housekeepingFilter) this.housekeepingFilter = 'dirty';
        this.renderHousekeepingList();
      } else {
        this.pmsAlert('Failed to load rooms: ' + (res?.error || 'Unknown error'), 'Error');
      }
    } catch (e) {
      this.hideLoading();
      console.error(e);
      this.pmsAlert('Network error', 'Error');
    }
  }

  setHousekeepingFilter(filter) {
    this.housekeepingFilter = filter;
    document.getElementById('hk-filter-dirty').classList.toggle('active', filter === 'dirty');
    document.getElementById('hk-filter-clean').classList.toggle('active', filter === 'clean');
    this.renderHousekeepingList();
  }

  renderHousekeepingList() {
    const list = document.getElementById('housekeeping-list');
    list.innerHTML = '';
    const items = this.housekeepingData ? this.housekeepingData[this.housekeepingFilter] || [] : [];
    const masterChecklist = (this.housekeepingData && this.housekeepingData.checklist_items) ? this.housekeepingData.checklist_items : [];
    
    if (items.length === 0) {
      list.innerHTML = `<div style="text-align: center; padding: 40px 20px; color: var(--color-text-muted);">
        <i class="ph ph-check-circle" style="font-size: 3rem; color: var(--color-success); margin-bottom: 10px; display: block;"></i>
        No ${this.housekeepingFilter} rooms right now!
      </div>`;
      return;
    }

    if (!this.hkChecklistState) this.hkChecklistState = {};

    items.forEach(r => {
      const isDirty = r.state === 'dirty';
      const bgColor = isDirty ? '#fff5f5' : '#f0fdf4';
      const borderColor = isDirty ? '#fca5a5' : '#86efac';
      const textColor = isDirty ? '#991b1b' : '#166534';
      
      // Get checklist items applicable to room category
      const roomChecklist = masterChecklist.filter(c => !c.category_id || c.category_id == r.category_id);
      
      // Track checked items for this room
      if (!this.hkChecklistState[r.id]) {
        this.hkChecklistState[r.id] = {};
        // Default mandatory items to false, non-mandatory to false
        roomChecklist.forEach(item => {
          this.hkChecklistState[r.id][item.id] = false;
        });
      }

      const totalItems = roomChecklist.length;
      let checkedCount = 0;
      let mandatoryRemaining = 0;

      roomChecklist.forEach(item => {
        if (this.hkChecklistState[r.id][item.id]) {
          checkedCount++;
        } else if (item.is_mandatory == 1) {
          mandatoryRemaining++;
        }
      });

      const pct = totalItems > 0 ? Math.round((checkedCount / totalItems) * 100) : 100;
      const canComplete = isDirty && (mandatoryRemaining === 0);

      let checklistHtml = '';
      if (isDirty && roomChecklist.length > 0) {
        checklistHtml = `
          <div style="margin-top: 14px; background: rgba(255,255,255,0.8); border-radius: var(--border-radius-md); padding: 12px; border: 1px solid ${borderColor};">
            <div style="display: flex; justify-content: space-between; align-items: center; margin-bottom: 8px;">
              <span style="font-size: 0.8rem; font-weight: 800; color: #475569; text-transform: uppercase;">CLEANING CHECKLIST</span>
              <span style="font-size: 0.8rem; font-weight: 800; color: #0284c7;">${pct}% COMPLETED</span>
            </div>
            
            <!-- Progress Bar -->
            <div style="width: 100%; background: #e2e8f0; height: 8px; border-radius: 4px; overflow: hidden; margin-bottom: 12px;">
              <div style="width: ${pct}%; background: linear-gradient(90deg, #3b82f6, #10b981); height: 100%; transition: width 0.3s ease;"></div>
            </div>

            <div style="display: flex; flex-direction: column; gap: 8px;">
              ${roomChecklist.map(item => {
                const isChecked = !!this.hkChecklistState[r.id][item.id];
                return `
                  <label style="display: flex; align-items: center; gap: 10px; font-size: 0.9rem; font-weight: 600; color: #1e293b; cursor: pointer; padding: 4px 0;">
                    <input type="checkbox" ${isChecked ? 'checked' : ''} onchange="app.toggleHkItem(${r.id}, ${item.id}, this.checked)" style="width: 20px; height: 20px; accent-color: #059669;">
                    <span>${item.item_text} ${item.is_mandatory == 1 ? '<span style="color: #dc2626; font-size: 0.75rem; font-weight: 800;">*</span>' : ''}</span>
                  </label>
                `;
              }).join('')}
            </div>
          </div>
        `;
      }

      let actionBtn = '';
      if (isDirty) {
        actionBtn = `
          <button class="btn-large btn-success" style="margin-top: 14px; font-size: 1.1rem; padding: 14px; width: 100%; opacity: ${canComplete ? '1' : '0.6'};" onclick="app.markRoomCleanWithChecklist(${r.id})" ${canComplete ? '' : 'disabled'}>
            <i class="ph ph-sparkles"></i> ${canComplete ? 'MARK CLEAN & READY' : `COMPLETE MANDATORY ITEMS (${mandatoryRemaining} REMAINING)`}
          </button>
        `;
      }

      const html = `
        <div style="background-color: ${bgColor}; border: 2px solid ${borderColor}; border-radius: var(--border-radius-md); padding: 16px; box-shadow: var(--shadow-sm);">
          <div style="display: flex; justify-content: space-between; align-items: center;">
            <div style="font-size: 2.2rem; font-weight: 800; color: ${textColor}; display: flex; align-items: center; gap: 8px;">
              ${isDirty ? '🧹' : '✨'} Room ${r.room_number}
            </div>
            <div style="font-size: 0.9rem; font-weight: 700; background: rgba(255,255,255,0.7); padding: 4px 10px; border-radius: 8px; color: ${textColor};">
              ${r.category_name}
            </div>
          </div>
          ${checklistHtml}
          ${actionBtn}
        </div>
      `;
      list.insertAdjacentHTML('beforeend', html);
    });
  }

  toggleHkItem(roomId, itemId, isChecked) {
    if (!this.hkChecklistState) this.hkChecklistState = {};
    if (!this.hkChecklistState[roomId]) this.hkChecklistState[roomId] = {};
    this.hkChecklistState[roomId][itemId] = isChecked;
    this.renderHousekeepingList();
  }

  async markRoomCleanWithChecklist(roomId) {
    const confirmed = await this.pmsConfirm('Mark this room as clean and ready?', 'Confirm Room Ready');
    if (!confirmed) return;

    const completedItems = [];
    if (this.hkChecklistState && this.hkChecklistState[roomId]) {
      Object.keys(this.hkChecklistState[roomId]).forEach(itemId => {
        if (this.hkChecklistState[roomId][itemId]) {
          completedItems.push(parseInt(itemId, 10));
        }
      });
    }

    this.showLoading('Saving room status...');
    try {
      const res = await this.apiCall('/api/admin/housekeeping', {
        action: 'mark_clean',
        room_id: roomId,
        completed_items: completedItems
      });
      if (res && res.success) {
        this.showToast('Room marked clean & ready!', 'success');
        await this.loadHousekeepingData();
      } else {
        this.hideLoading();
        this.pmsAlert('Failed: ' + (res?.error || 'Unknown error'), 'Error');
      }
    } catch (e) {
      this.hideLoading();
      console.error(e);
      this.pmsAlert('Network error', 'Error');
    }
  }

  async markRoomClean(roomId) {
    return this.markRoomCleanWithChecklist(roomId);
  }

  startHousekeepingVoiceAssist() {
    if (!window.Voice) return this.pmsAlert('Voice not supported', 'Error');
    
    // Check if we have rooms to match against
    if (!this.housekeepingData || !this.housekeepingData.dirty) return;

    window.Voice.speak('Please say the room number to mark as clean.', 'en-IN');
    
    window.Voice.startListening(
      (text, conf) => {
        const lower = text.toLowerCase();
        console.log('Voice recognized:', lower);
        
        // Extract room number
        const match = lower.match(/\b(\d{3,4})\b/);
        if (match) {
          const roomNum = match[1];
          // Find room id in dirty list
          const room = this.housekeepingData.dirty.find(r => r.room_number == roomNum);
          if (room) {
            window.Voice.speak('Marking room ' + roomNum + ' clean.', 'en-IN');
            this.markRoomClean(room.id);
          } else {
            window.Voice.speak('Room ' + roomNum + ' is not dirty or not found.', 'en-IN');
            this.pmsAlert('Room ' + roomNum + ' is not dirty or not found.', 'Room Not Found');
          }
        } else {
          window.Voice.speak('I did not catch the room number.', 'en-IN');
          this.pmsAlert('Could not detect room number. Please try again.', 'Voice Error');
        }
      },
      () => {
        // onStart
        this.showLoading('Listening for room number...');
      },
      (err) => {
        // onError
        this.hideLoading();
        this.pmsAlert('Voice error: ' + err, 'Voice Error');
      },
      () => {
        // onEnd
        this.hideLoading();
      }
    );
  }

  // Fetch staff users list for login grid
  async loadStaffListForLogin() {
    this.showScreen('login');
    document.getElementById('header-user').style.display = 'none';
    document.getElementById('bottom-nav').style.display = 'none';
    
    this.showLoading('Loading staff...');
    try {
      const res = await this.apiCall('api/auth.php?action=list_staff');
      this.hideLoading();
      
      const grid = document.getElementById('login-staff-list');
      grid.innerHTML = '';
      
      if (res && res.success && res.staff) {
        res.staff.forEach(user => {
          const card = document.createElement('div');
          card.className = 'staff-avatar-card';
          card.onclick = (e) => this.selectStaffForLogin(user, e);
          card.innerHTML = `
            <div class="staff-avatar-icon">${user.username.substring(0,2).toUpperCase()}</div>
            <div style="font-weight:800; font-size:0.95rem;">${user.username.toUpperCase()}</div>
            <div style="font-size:0.75rem; color:var(--color-text-secondary); font-weight:700; text-transform:uppercase;">${user.role}</div>
          `;
          grid.appendChild(card);
        });
      }
    } catch (e) {
      this.hideLoading();
      this.showToast(e.message || 'Failed to load staff list', 'danger');
    }
  }

  selectStaffForLogin(user, event) {
    // Highlight card
    const cards = document.querySelectorAll('.staff-avatar-card');
    cards.forEach(c => c.classList.remove('selected'));
    
    if (event && event.currentTarget) {
      event.currentTarget.classList.add('selected');
    }
    
    // Setup login keypad panel
    this.loginTargetUser = user;
    document.getElementById('login-target-username').textContent = user.username.toUpperCase();
    document.getElementById('login-numpad-wrapper').style.display = 'flex';
    
    this.numpadValue = '';
    this.updateLoginPinDisplay();
    
    // Voice prompt
    Voice.speak(`Please enter your PIN code.`);
  }

  pressLoginKey(key) {
    if (key === 'clear') {
      this.numpadValue = '';
    } else if (key === 'back') {
      this.numpadValue = this.numpadValue.slice(0, -1);
    } else {
      if (this.numpadValue.length < 4) {
        this.numpadValue += key;
      }
    }
    this.updateLoginPinDisplay();
  }

  updateLoginPinDisplay() {
    const display = document.getElementById('login-pin-display');
    if (this.numpadValue.length === 0) {
      display.textContent = '● ● ● ●';
      display.classList.add('empty');
    } else {
      let stars = '';
      for (let i = 0; i < this.numpadValue.length; i++) stars += '★ ';
      for (let i = this.numpadValue.length; i < 4; i++) stars += '● ';
      display.textContent = stars.trim();
      display.classList.remove('empty');
    }
  }

  async submitLoginPin() {
    if (this.numpadValue.length !== 4) {
      this.showToast('Please enter complete 4-digit PIN', 'warning');
      return;
    }

    this.showLoading('Verifying PIN...');
    try {
      const res = await this.apiCall('api/auth.php?action=login', {
        user_id: this.loginTargetUser.id,
        pin: this.numpadValue
      });
      this.hideLoading();

      if (res && res.success) {
        this.currentUser = res.user;
        this.csrfToken = res.csrf_token;
        document.getElementById('user-display-name').textContent = this.currentUser.username.toUpperCase();
        document.getElementById('header-user').style.display = 'flex';
        document.getElementById('bottom-nav').style.display = 'flex';
        document.getElementById('login-numpad-wrapper').style.display = 'none';
        
        this.showScreen('dashboard');
        this.startLiveSync();
        Voice.speak(`Welcome back ${this.currentUser.username}.`);
        
        // Show tutorial on first launch
        if (!this.tutorialSeen) {
          setTimeout(() => this.showTutorial(), 1500);
        }
      } else {
        this.showToast(res.message || 'Incorrect PIN', 'danger');
        Voice.speak(`Incorrect PIN. Try again.`);
        this.numpadValue = '';
        this.updateLoginPinDisplay();
      }
    } catch (e) {
      this.hideLoading();
      this.showToast(e.message || 'Login connection error', 'danger');
    }
  }

  // Load Dashboard statistical information
  async loadDashboardData() {
    try {
      const res = await this.apiCall('api/dashboard.php');
      if (res && res.success) {
        document.getElementById('stat-available').textContent = res.summary.available_rooms;
        document.getElementById('stat-occupied').textContent = res.summary.occupied_rooms;
        document.getElementById('stat-cleaning').textContent = res.summary.cleaning_rooms;
        document.getElementById('stat-pending-ids').textContent = res.summary.pending_id_verification;
        document.getElementById('stat-pending-payments').textContent = res.summary.pending_payments;

        // Hidden stats used by voice commands
        const arrEl = document.getElementById('stat-arrivals');
        if (arrEl) arrEl.textContent = res.summary.today_check_in ?? res.summary.arrivals ?? 0;
        const depEl = document.getElementById('stat-departures');
        if (depEl) depEl.textContent = res.summary.today_check_out ?? res.summary.departures ?? 0;

        if (res.payment_methods) {
          this.paymentMethods = res.payment_methods;
          this.applyPaymentMethodsToUI();
        }

        // Alerts badge
        const badgeCount = res.alerts.length;
        const navBadge = document.getElementById('bottom-nav-badge');
        if (badgeCount > 0) {
          navBadge.textContent = badgeCount;
          navBadge.style.display = 'block';
        } else {
          navBadge.style.display = 'none';
        }

        // Render dashboard alerts
        const alertsList = document.getElementById('dashboard-alerts-list');
        alertsList.innerHTML = '';
        
        if (res.alerts.length === 0) {
          alertsList.innerHTML = `
            <div style="text-align:center; padding: 20px; color: var(--color-text-muted); font-weight:700;">
              <span style="font-size: 2.5rem; display:block; margin-bottom:6px; line-height:1;">✅</span>
              Everything is clean & verified!
            </div>
          `;
        } else {
          res.alerts.slice(0, 3).forEach(alert => {
            const alertClass = (alert.severity === 'critical' || alert.severity === 'danger') ? 'danger' : (alert.severity === 'warning' ? 'warning' : 'info');
            const card = document.createElement('div');
            card.className = `alert-box alert-box-${alertClass}`;
            card.onclick = () => this.handleAlertClick(alert);
            
            let emoji = 'ℹ️';
            if (alert.type === 'dirty_room') emoji = '🧹';
            else if (alert.type === 'today_arrival') emoji = '📥';
            else if (alert.type === 'today_departure') emoji = '📤';
            else if (alert.type === 'missing_id') emoji = '🆔';
            else if (alert.type === 'pending_payment') emoji = '💵';
            else if (alert.type === 'overdue_checkout') emoji = '🚨';
            else if (alert.type === 'upcoming_checkout') emoji = '⏰';
            else if (alert.type === 'overdue_checkin') emoji = '⚠️';
            else if (alert.type === 'booking_hold') emoji = '⏳';

            card.innerHTML = `
              <span style="font-size:1.85rem; line-height:1; flex-shrink:0;">${emoji}</span>
              <div style="flex:1; font-weight:700; font-size:0.95rem; margin-left:8px;">${alert.message}</div>
              <span style="font-size:1.2rem; margin-left:auto; font-weight:900;">👉</span>
            `;
            alertsList.appendChild(card);
          });
        }
        
        // Voice summary on dashboard load
        const summary = res.summary;
        const alertCount = res.alerts.length;
        let voiceMsg = '';
        
        if (alertCount > 0) {
          // Prioritize critical alerts
          const criticalAlerts = res.alerts.filter(a => a.severity === 'critical');
          const warningAlerts = res.alerts.filter(a => a.severity === 'warning');
          
          if (criticalAlerts.length > 0) {
            voiceMsg = `Attention! ${criticalAlerts[0].message}. `;
          }
          
          voiceMsg += `${summary.available_rooms} rooms available. ${summary.occupied_rooms} occupied. ${summary.cleaning_rooms} need cleaning. ${alertCount} alerts.`;
        } else {
          voiceMsg = `All good! ${summary.available_rooms} rooms available. ${summary.occupied_rooms} occupied. No alerts.`;
        }
        
        // Delay voice slightly so UI renders first
        setTimeout(() => Voice.speak(voiceMsg), 500);
      }
    } catch (e) {
      console.error('Failed to load dashboard statistics:', e.message || e);
      // Show error state in UI
      document.getElementById('stat-available').textContent = '-';
      document.getElementById('stat-occupied').textContent = '-';
      document.getElementById('stat-cleaning').textContent = '-';
      document.getElementById('stat-pending-ids').textContent = '-';
      document.getElementById('stat-pending-payments').textContent = '-';
      // Show toast with error
      this.showToast('Dashboard load failed: ' + (e.message || 'Unknown error'), 'danger');
    }
  }

  handleAlertClick(alert) {
    if (alert.type === 'dirty_room') {
      this.showScreen('rooms');
    } else if (alert.type === 'today_arrival') {
      this.showCheckInScreen();
    } else if (alert.type === 'today_departure') {
      this.showCheckOutScreen();
    } else if (alert.type === 'missing_id') {
      this.showMissingIdVerify(alert.booking_id, alert.guest_id);
    } else if (alert.type === 'pending_payment') {
      this.showPaymentCollectScreen();
    } else if (alert.type === 'overdue_checkout' || alert.type === 'overdue_checkin' || alert.type === 'booking_hold' || alert.type === 'upcoming_checkout') {
      this.openBookingActionById(alert.booking_id);
    }
  }

  async openBookingActionById(bookingId) {
    // Fetch booking details and open the action sheet
    this.showLoading('Loading booking...');
    try {
      const res = await this.apiCall('api/bookings.php?action=list&filter=today');
      this.hideLoading();
      if (res && res.success && res.bookings) {
        const booking = res.bookings.find(b => b.id === bookingId);
        if (booking) {
          this.openBookingActionsSheet(
            booking.id,
            booking.guest_name,
            booking.room_number,
            booking.booking_status,
            booking.guest_id,
            booking.id_proof_front || '',
            booking.id_proof_back || '',
            booking.check_out
          );
        } else {
          this.showToast('Booking not found', 'danger');
        }
      }
    } catch (e) {
      this.hideLoading();
      this.showToast(e.message || 'Error loading booking', 'danger');
    }
  }

  // --- NEW BOOKING WIZARD ---
  startNewBookingWizard() {
    this.showScreen('wizard');
    this.wizardStep = 1;
    this.wizardData = {
      guest_id: null,
      guest_name: '',
      guest_phone: '',
      is_new_guest: false,
      id_proof_front: null,
      id_proof_back: null,
      photo: null,
      id_status: 'Pending',
      room_id: null,
      room_number: '',
      category_id: null,
      category_name: '',
      rate_plan_name: null,
      rate_plans: [],
      price_override: null,
      room_cost: 0,
      extra_bed_cost: 0,
      total_cost: 0,
      check_in: '',
      check_out: '',
      adults: 2,
      children: 0,
      extra_bed: 0,
      payment_collected: 0,
      payment_method: 'Cash',
      payment_ref: '',
      offline_folio_id: null
    };

    this.showWizardStep(1);
    Voice.speak(`New Booking. Step 1. Please search by mobile number or speak the guest's name.`);
  }

  showWizardStep(stepNum) {
    this.wizardStep = stepNum;
    
    // Hide all step panels
    const steps = document.querySelectorAll('.wizard-step');
    steps.forEach(s => s.style.display = 'none');
    
    // Show active step
    document.getElementById(`wizard-step-${stepNum}`).style.display = 'block';

    // Update Progress Indicator
    document.getElementById('wizard-step-label').textContent = `Step ${stepNum} of 11`;
    const progressPercent = Math.round(((stepNum - 1) / 10) * 100);
    document.getElementById('wizard-progress-bar').style.width = `${progressPercent}%`;
    document.getElementById('wizard-percent-label').textContent = `${progressPercent}%`;

    // Initialize defaults or loaders for specific steps
    if (stepNum === 1) {
      // Restore previous search phone if present, or set placeholder
      const phoneDisplay = document.getElementById('txt-search-phone');
      const phoneContainer = document.getElementById('wizard-search-phone-display');
      if (phoneDisplay) {
        if (this.searchPhoneVal) {
          phoneDisplay.textContent = this.searchPhoneVal;
          if (phoneContainer) phoneContainer.classList.remove('empty');
        } else {
          phoneDisplay.textContent = 'Tap to enter phone number';
          if (phoneContainer) phoneContainer.classList.add('empty');
        }
      }
    } else if (stepNum === 2) {
      // Reset name input field
      const nameInput = document.getElementById('txt-spoken-guest-name-input');
      if (nameInput) nameInput.value = this.wizardData.guest_name || '';
    } else if (stepNum === 4) {
      this.initCheckIn();
    } else if (stepNum === 5) {
      this.initCheckOut();
    } else if (stepNum === 7) {
      this.loadAvailableRoomsForWizard();
    } else if (stepNum === 9) {
      this.initPriceOverride();
    } else if (stepNum === 10) {
      const advInput = document.getElementById('wizard-advance-paid');
      if (advInput) advInput.value = this.wizardData.payment_collected;
      const totalCost = this.wizardData.price_override !== null ? this.wizardData.price_override : this.wizardData.total_cost;
      document.getElementById('payment-total-cost').textContent = totalCost.toFixed(2);
    } else if (stepNum === 11) {
      this.populateBookingSummary();
    }
  }

  nextWizardStep(stepNum) {
    this.showWizardStep(stepNum);
    
    // Voice guidance cues (disabled voice synthesis on step change to avoid speech recognition picking up speaker output)
    /*
    if (stepNum === 1) {
      Voice.speak(`Step 1. Search for a guest by name or mobile number.`);
    } else if (stepNum === 2) {
      Voice.speak(`Step 2. Please enter guest mobile number and name.`);
    } else if (stepNum === 7) {
      Voice.speak(`Step 7. Select an available room.`);
    }
    */
  }

  prevWizardStep(stepNum) {
    this.showWizardStep(stepNum);
  }

  // --- STEP 1: SEARCH GUEST ---

  /**
   * Open numpad for phone number entry
   * Replaces native keyboard input for non-literate users
   */
  openPhoneNumpad(target) {
    this.openNumpadPopup(target, '📱 Enter 10-Digit Phone Number', '', (val) => {
      if (val.length === 10 && /^\d{10}$/.test(val)) {
        if (target === 'search_phone') {
          // Update search display
          const display = document.getElementById('txt-search-phone');
          const container = document.getElementById('wizard-search-phone-display');
          if (display) {
            display.textContent = val;
            container?.classList.remove('empty');
          }
          // Trigger search
          this.searchGuests(val);
        } else if (target === 'new_phone') {
          // Update new guest phone display
          const display = document.getElementById('txt-new-guest-phone');
          const container = document.getElementById('new-guest-phone-display');
          if (display) {
            display.textContent = val;
            container?.classList.remove('empty');
          }
          this.wizardData.guest_phone = val;
        }
        Voice.speak(`Phone number ${val.split('').join(' ')}`);
      } else if (val.length > 0) {
        this.showToast('Phone number must be 10 digits', 'warning');
      }
    });
  }

  async searchGuests(query) {
    if (query.length < 2) return;
    
    this.showLoading('Searching guests...');
    try {
      const res = await this.apiCall(`api/guests.php?action=search&q=${query}`);
      this.hideLoading();
      
      const list = document.getElementById('search-results-list');
      list.innerHTML = '';
      
      if (res && res.success && res.guests && res.guests.length > 0) {
        res.guests.forEach(guest => {
          const card = document.createElement('div');
          card.className = 'selection-card';
          card.onclick = () => this.selectGuestForWizard(guest);
          
          let alertBadge = '';
          if (guest.outstanding_balance > 0) {
            alertBadge = `<span class="badge badge-danger" style="margin-top:4px;">Bal: ₹${guest.outstanding_balance}</span>`;
          }

          let returningBadge = '';
          if (guest.stay_count > 0) {
            returningBadge = `<span class="badge badge-success" style="margin-top:4px;">Returning Guest • ${guest.stay_count} stays</span>`;
          }
          let lastRoomInfo = '';
          if (guest.preferred_room && guest.preferred_room !== 'None') {
            lastRoomInfo = `<span style="font-size:0.7rem; color: var(--color-text-secondary);">Prefers: ${guest.preferred_room}</span>`;
          }

          card.innerHTML = `
            <div class="selection-card-avatar">${escapeHtml(guest.name.substring(0,1).toUpperCase())}</div>
            <div class="selection-card-details">
              <div class="selection-card-title">${escapeHtml(guest.name)}</div>
              <div class="selection-card-subtitle">${escapeHtml(guest.display_phone)} • ${guest.stay_count} Stays</div>
              ${returningBadge}
              ${lastRoomInfo}
              ${alertBadge}
            </div>
            <button class="btn-large btn-brand" style="width:75px; min-height:40px; height:40px; font-size:0.75rem; padding:0 8px; margin-left:auto;">SELECT</button>
          `;
          list.appendChild(card);
        });
        Voice.speak(`Found ${res.guests.length} guest records. Select one to proceed.`);
      } else {
        list.innerHTML = `
          <div style="text-align:center; padding: 20px; color: var(--color-text-secondary); font-weight:700;">
            No guest found. Click "NEW GUEST PROFILE" below to create.
          </div>
        `;
        Voice.speak(`No guest records found. Create a new guest.`);
      }
    } catch (e) {
      this.hideLoading();
      this.showToast(e.message || 'Guest search connection error', 'danger');
    }
  }

  listenGuestNameForSearch() {
    const wave = document.getElementById('voice-wave-search');
    const btn = document.getElementById('btn-search-voice');
    
    Voice.startListening(
      (transcript) => {
        // Update the phone display with spoken text and trigger guest search
        const phoneDisplay = document.getElementById('txt-search-phone');
        if (phoneDisplay) phoneDisplay.textContent = transcript;
        const phoneContainer = document.getElementById('wizard-search-phone-display');
        if (phoneContainer) phoneContainer.classList.remove('empty');
        this.searchGuests(transcript);
      },
      () => {
        wave.classList.add('active');
        btn.classList.add('recording');
      },
      (err) => {
        this.showToast('Voice error: ' + err, 'warning');
      },
      () => {
        wave.classList.remove('active');
        btn.classList.remove('recording');
      }
    );
  }

  selectGuestForWizard(guest) {
    this.wizardData.guest_id = guest.id;
    this.wizardData.guest_name = guest.name;
    this.wizardData.guest_phone = guest.phone;
    this.wizardData.is_new_guest = false;
    this.wizardData.id_proof_front = guest.id_proof_front;
    this.wizardData.id_proof_back = guest.id_proof_back;
    this.wizardData.photo = guest.photo;
    
    if (guest.has_id_proof) {
      this.wizardData.id_status = 'Verified';
      // If guest already has ID proof in database, we can skip directly to Room selection! (Step 4)
      this.nextWizardStep(4);
      this.showToast(`Selected ${guest.name}. ID already verified.`, 'success');
      Voice.speak(`Selected guest ${guest.name}. ID already verified. Select stay dates.`);
    } else {
      this.wizardData.id_status = 'Pending';
      // Go to verification step
      this.nextWizardStep(3);
      this.showToast(`Selected ${guest.name}. ID proof required.`, 'warning');
      Voice.speak(`Selected guest ${guest.name}. ID proof required. Please verify identity.`);
    }
  }

  // --- STEP 2: NEW GUEST PROFILE ---
  promptOrListenGuestName() {
    const input = prompt('Enter Guest Name:', this.wizardData.guest_name || '');
    if (input !== null && input.trim() !== '') {
      const capName = input.trim().split(' ').map(w => w.charAt(0).toUpperCase() + w.slice(1)).join(' ');
      document.getElementById('txt-spoken-guest-name').textContent = capName;
      document.getElementById('new-guest-name-display').classList.remove('empty');
      this.wizardData.guest_name = capName;
    }
  }

  onGuestNameTyped(val) {
    this.wizardData.guest_name = val.trim();
  }

  listenNewGuestName() {
    const wave = document.getElementById('voice-wave-newguest');
    const btn = document.getElementById('btn-new-guest-voice');
    
    // Stop any ongoing speech synthesis to prevent mic feedback loop
    if (window.speechSynthesis) window.speechSynthesis.cancel();
    
    Voice.startListening(
      (transcript) => {
        // Capitalize name
        const capName = transcript.split(' ').map(w => w.charAt(0).toUpperCase() + w.slice(1)).join(' ');
        const nameInput = document.getElementById('txt-spoken-guest-name-input');
        if (nameInput) nameInput.value = capName;
        this.wizardData.guest_name = capName;
        
        this.showToast(`Captured Name: "${capName}"`, 'success');
      },
      () => {
        wave?.classList.add('active');
        btn?.classList.add('recording');
      },
      (err) => {
        this.showToast('Voice error: ' + err + '. Please type name in input box.', 'warning');
      },
      () => {
        wave?.classList.remove('active');
        btn?.classList.remove('recording');
      }
    );
  }

  async saveNewGuestDetails() {
    if (!this.wizardData.guest_phone || this.wizardData.guest_phone.length < 10) {
      this.showToast('Please enter a valid mobile number', 'warning');
      Voice.speak('Please enter a valid mobile number.');
      return;
    }
    if (!this.wizardData.guest_name) {
      this.showToast('Please speak and select guest name', 'warning');
      Voice.speak('Please speak the guest name.');
      return;
    }

    // Call API to create guest profile (or check duplicate mobile number)
    this.showLoading('Saving guest profile...');
    try {
      const res = await this.apiCall('api/guests.php?action=create', {
        phone: this.wizardData.guest_phone,
        name: this.wizardData.guest_name
      });
      this.hideLoading();

      if (res && res.success) {
        this.wizardData.guest_id = res.guest_id;
        this.wizardData.is_new_guest = true;
        this.wizardData.id_status = 'Pending';
        
        this.nextWizardStep(3);
        this.showToast('Guest profile created', 'success');
      } else {
        this.showToast(res.message || 'Failed to save profile', 'danger');
      }
    } catch (e) {
      this.hideLoading();
      if (!navigator.onLine) {
        this.showToast(e.message || 'No internet connection', 'danger');
      } else {
        this.showToast('Guest profile connection error', 'danger');
      }
    }
  }

  // --- STEP 3: IDENTITY SCANNER ---
  openIdScanner(type) {
    this.scanType = type;
    
    if (type === 'physical' || type === 'other') {
      this.activeScanSide = 'front';
      document.getElementById('camera-title').textContent = type === 'physical' ? 'Scan Aadhaar (Front)' : 'Scan ID Card';
      document.getElementById('camera-guide-text').textContent = type === 'physical' ? 'Align Aadhaar FRONT' : 'Align ID Card';
      document.getElementById('camera-popup').classList.add('active');
      this.initCameraStream();
      
      Voice.speak(`Please capture the front of the ID card.`);
    } else if (type === 'whatsapp') {
      // WhatsApp upload uses Gallery direct file picker
      document.getElementById('file-upload-input').click();
    }
  }

  async initCameraStream() {
    try {
      this.mediaStream = await navigator.mediaDevices.getUserMedia({
        video: { facingMode: 'environment', width: 640, height: 480 }
      });
      const video = document.getElementById('video-stream');
      video.srcObject = this.mediaStream;
    } catch (e) {
      console.error('Camera stream access failed:', e);
      this.showToast('Failed to access camera. Uploading from gallery instead.', 'warning');
      this.closeCameraPopup();
      document.getElementById('file-upload-input').click();
    }
  }

  closeCameraPopup(event) {
    if (event && event.target !== event.currentTarget) return;
    
    if (this.mediaStream) {
      this.mediaStream.getTracks().forEach(track => track.stop());
      this.mediaStream = null;
    }
    document.getElementById('camera-popup').classList.remove('active');
  }

  triggerGalleryUpload() {
    this.closeCameraPopup();
    document.getElementById('file-upload-input').click();
  }

  handleGalleryFileSelected(event) {
    const file = event.target.files[0];
    if (!file) return;

    // Check if we're in missing ID mode (just upload, no OCR)
    if (this.missingIdMode) {
      this.missingIdMode = false;
      this.uploadIdProofFile(file, this.activeScanSide, this.wizardData.guest_id);
      return;
    }

    this.showLoading('Reading document...');
    const reader = new FileReader();
    reader.onload = async (e) => {
      const base64Data = e.target.result;
      this.hideLoading();
      this.runOcrProcess(base64Data);
    };
    reader.readAsDataURL(file);
  }

  captureCameraImage() {
    const video = document.getElementById('video-stream');
    
    // Canvas processing
    const canvas = document.createElement('canvas');
    canvas.width = video.videoWidth || 640;
    canvas.height = video.videoHeight || 480;
    const ctx = canvas.getContext('2d');
    ctx.drawImage(video, 0, 0, canvas.width, canvas.height);
    
    const base64Data = canvas.toDataURL('image/jpeg', 0.9);
    
    this.closeCameraPopup();
    
    // Check if we're in missing ID mode (just upload, no OCR)
    if (this.missingIdMode) {
      this.missingIdMode = false;
      this.uploadCapturedImage(base64Data);
    } else {
      this.runOcrProcess(base64Data);
    }
  }

  async uploadCapturedImage(base64Data) {
    this.showLoading('Uploading ID proof...');
    
    try {
      const loc = window.location.pathname;
      const assistantIndex = loc.indexOf('/assistant');
      const pmsApiBase = assistantIndex !== -1 ? loc.substring(0, assistantIndex) + '/api/' : '/api/';
      const uploadUrl = pmsApiBase + 'ocr_upload.php';
      
      const headers = {
        'Content-Type': 'application/json'
      };
      if (this.csrfToken) {
        headers['X-CSRF-TOKEN'] = this.csrfToken;
      }
      
      const response = await fetch(uploadUrl, {
        method: 'POST',
        headers: headers,
        body: JSON.stringify({
          image: base64Data,
          guest_id: this.wizardData.guest_id,
          id_type: this.activeScanSide === 'front' ? 'id_proof_front' : 'id_proof_back'
        }),
        cache: 'no-store'
      });
      
      const res = await response.json();
      this.hideLoading();
      
      if (res && res.success) {
        this.showToast('ID proof uploaded successfully!', 'success');
        Voice.speak('ID proof uploaded successfully.');
        this.loadDashboardData();
      } else {
        this.showToast(res?.error || 'Upload failed', 'danger');
        Voice.speak('Upload failed. Please try again.');
      }
    } catch (e) {
      this.hideLoading();
      this.showToast(e.message || 'Upload error', 'danger');
      Voice.speak('Upload error. Please try again.');
    }
  }

  async runOcrProcess(base64Data) {
    this.showLoading('Running OCR (Text Reader)...');
    
    try {
      // 1. Process via client-side Tesseract OCR (Ocr wrapper)
      const ocrResults = await Ocr.scanId(base64Data);
      this.hideLoading();

      this.currentOcrData = ocrResults;
      this.currentCapturedImageBase64 = base64Data; // save locally for upload

      // 2. Load match Results validation sheet
      const spokenName = this.wizardData.guest_name;
      const ocrName = ocrResults.name || 'UNKNOWN';
      const similarityPct = Ocr.calculateSimilarity(spokenName, ocrName);

      document.getElementById('ocr-match-title').textContent = `Match: ${similarityPct}%`;
      document.getElementById('ocr-val-spoken').textContent = spokenName.toUpperCase();
      document.getElementById('ocr-val-extracted').textContent = ocrName.toUpperCase();
      document.getElementById('ocr-val-aadhaar').textContent = ocrResults.id_number || 'Not detected';
      document.getElementById('ocr-val-meta').textContent = `${ocrResults.gender || 'N/A'} / ${ocrResults.dob || 'N/A'}`;
      
      // New fields
      const addressEl = document.getElementById('ocr-val-address');
      if (addressEl) addressEl.textContent = ocrResults.address || 'Not detected';
      
      const idTypeEl = document.getElementById('ocr-val-idtype');
      if (idTypeEl) {
        const idTypeMap = { 'aadhaar': 'Aadhaar Card', 'pan': 'PAN Card', 'voter_id': 'Voter ID', 'unknown': 'Unknown' };
        idTypeEl.textContent = idTypeMap[ocrResults.id_type] || 'Other ID';
      }

      const mismatchAlert = document.getElementById('ocr-mismatch-alert');
      if (similarityPct < 70) {
        mismatchAlert.style.display = 'flex';
        Voice.speak(`Warning. Spoken name and ID name do not match. Match rate is ${similarityPct} percent.`);
      } else {
        mismatchAlert.style.display = 'none';
        Voice.speak(`Identity verified. Match rate is ${similarityPct} percent.`);
      }

      document.getElementById('ocr-validation-popup').classList.add('active');
    } catch (e) {
      this.hideLoading();
      this.showToast('OCR scanner error. Using manual entry fallback.', 'warning');
      this.skipIdVerification();
    }
  }

  retakeOcrVoice() {
    document.getElementById('ocr-validation-popup').classList.remove('active');
    this.wizardStep = 2;
    this.showWizardStep(2);
    this.listenNewGuestName();
  }

  retakeOcrScan() {
    document.getElementById('ocr-validation-popup').classList.remove('active');
    this.openIdScanner(this.scanType);
  }

  async acceptOcrData(anyway = false) {
    document.getElementById('ocr-validation-popup').classList.remove('active');
    
    // Auto-fill guest data from OCR if available
    if (this.currentOcrData) {
      const ocr = this.currentOcrData;
      
      // Auto-fill phone if OCR found a mobile number and guest doesn't have one
      if (ocr.mobile && !this.wizardData.guest_phone) {
        this.wizardData.guest_phone = ocr.mobile;
        const phoneDisplay = document.getElementById('txt-new-guest-phone');
        const phoneContainer = document.getElementById('new-guest-phone-display');
        if (phoneDisplay) {
          phoneDisplay.textContent = ocr.mobile;
          phoneContainer?.classList.remove('empty');
        }
      }
      
      // Auto-fill name if OCR found one and guest doesn't have one
      if (ocr.name && !this.wizardData.guest_name) {
        this.wizardData.guest_name = ocr.name;
        const nameDisplay = document.getElementById('txt-spoken-guest-name');
        const nameContainer = document.getElementById('new-guest-name-display');
        if (nameDisplay) {
          nameDisplay.textContent = ocr.name;
          nameContainer?.classList.remove('empty');
        }
      }

      // Store extended metadata in wizard state
      if (ocr.address) this.wizardData.guest_address = ocr.address;
      if (ocr.pincode) this.wizardData.guest_pincode = ocr.pincode;

      // Sync updated guest profile details to backend if guest_id exists
      if (this.wizardData.guest_id && (ocr.address || ocr.name || ocr.mobile)) {
        this.apiCall('api/guests.php', {
          action: 'update_profile',
          guest_id: this.wizardData.guest_id,
          name: ocr.name || this.wizardData.guest_name,
          phone: ocr.mobile || this.wizardData.guest_phone,
          address: ocr.address || '',
          pincode: ocr.pincode || ''
        }).catch(err => console.warn('[OCR Sync Warning]', err));
      }
    }
    
    this.showLoading('Uploading ID proof...');
    
    // Save image to server and update guest profile
    try {
      const uploadRes = await this.apiCall('api/ocr_upload.php', {
        guest_id: this.wizardData.guest_id,
        id_type: this.activeScanSide === 'front' ? 'id_proof_front' : 'id_proof_back',
        image: this.currentCapturedImageBase64
      });
      this.hideLoading();

      if (uploadRes && uploadRes.success) {
        if (this.activeScanSide === 'front' && this.scanType === 'physical') {
          // Scanned front of physical Aadhaar, now scan back
          this.activeScanSide = 'back';
          this.wizardData.id_proof_front = uploadRes.filename;
          
          this.showToast('Front ID saved. Now scan back side.', 'info');
          Voice.speak('Front side saved. Now scan the back side of Aadhaar.');
          
          setTimeout(() => this.openIdScanner('physical'), 800);
        } else {
          // Finished ID verification steps
          if (this.activeScanSide === 'back') {
            this.wizardData.id_proof_back = uploadRes.filename;
          } else {
            this.wizardData.id_proof_front = uploadRes.filename; // Other ID/whatsapp
          }
          
          this.wizardData.id_status = 'Verified';
          this.nextWizardStep(4);
          this.showToast('ID Verification completed', 'success');
        }
      }
    } catch (e) {
      this.hideLoading();
      // Offline fallback: save images locally in wizardData
      if (!navigator.onLine) {
        if (this.activeScanSide === 'front' && this.scanType === 'physical') {
          this.activeScanSide = 'back';
          this.wizardData.id_proof_front = 'offline_front_placeholder.jpg';
          this.showToast('Offline Mode: Front ID saved', 'info');
          setTimeout(() => this.openIdScanner('physical'), 800);
        } else {
          this.wizardData.id_proof_back = 'offline_back_placeholder.jpg';
          this.wizardData.id_status = 'Offline Draft';
          this.nextWizardStep(4);
          this.showToast('Offline Mode: ID verified offline', 'info');
        }
      } else {
        this.showToast('Document upload error', 'danger');
      }
    }
  }

  skipIdVerification() {
    this.wizardData.id_status = 'Pending';
    this.nextWizardStep(4);
    this.showToast('ID Verification skipped. Booking marked ID Pending.', 'warning');
    Voice.speak('Verification skipped. Enter stay details.');
  }

  // --- STEP 4: ROOM SELECTION ---
  async loadAvailableRoomsForWizard() {
    this.showLoading('Loading available rooms...');
    
    // Ensure check_in and check_out are valid, default to today and tomorrow if missing
    if (!this.wizardData.check_in) {
      this.wizardData.check_in = dateToMysqlString(new Date());
    }
    if (!this.wizardData.check_out) {
      this.wizardData.check_out = dateToMysqlString(new Date(Date.now() + 86400000));
    }

    try {
      const res = await this.apiCall('api/rooms.php', {
        check_in: this.wizardData.check_in,
        check_out: this.wizardData.check_out
      });
      this.hideLoading();

      if (res && res.success) {
        this.availableRoomsList = res.rooms;
        this.renderRoomsForWizard();
        return true;
      }
      return false;
    } catch (e) {
      this.hideLoading();
      this.showToast(e.message || 'Rooms list connection error', 'danger');
      return false;
    }
  }

  renderRoomsForWizard(filterType = 'all') {
    const list = document.getElementById('wizard-rooms-list');
    list.innerHTML = '';

    if (!this.availableRoomsList || this.availableRoomsList.length === 0) {
      list.innerHTML = `
        <div style="text-align:center; padding:20px; color:var(--color-text-secondary); font-weight:700;">
          No available rooms for selected dates.
        </div>
      `;
      return;
    }

    // Apply Client side filters
    let filtered = this.availableRoomsList;
    if (filterType === 'ground') {
      filtered = this.availableRoomsList.filter(r => r.floor === 'Ground Floor');
    } else if (filterType === 'first') {
      filtered = this.availableRoomsList.filter(r => r.floor === 'First Floor');
    } else if (filterType === 'second') {
      filtered = this.availableRoomsList.filter(r => r.floor === 'Second Floor');
    } else if (filterType === 'ac') {
      filtered = this.availableRoomsList.filter(r => r.is_ac);
    } else if (filterType === 'non-ac') {
      filtered = this.availableRoomsList.filter(r => !r.is_ac);
    } else if (filterType === 'deluxe') {
      filtered = this.availableRoomsList.filter(r => r.room_type === 'Deluxe');
    } else if (filterType === 'suite') {
      filtered = this.availableRoomsList.filter(r => r.room_type === 'Suite');
    }

    filtered.forEach(room => {
      const card = document.createElement('div');
      card.className = `selection-card ${this.wizardData.room_id === room.id ? 'selected' : ''}`;
      card.onclick = () => this.selectRoomForWizard(room);

      // Get standard pricing display
      const stdPlan = room.rate_plans.find(p => p.name === 'Standard' || p.name === 'Base Rate') || room.rate_plans[0];
      const costDisplay = stdPlan ? `₹${stdPlan.total_cost}` : 'TBD';

      card.innerHTML = `
        <div class="selection-card-avatar" style="border-radius:10px;"><i class="lucide-bed"></i></div>
        <div class="selection-card-details">
          <div class="selection-card-title">Room ${room.room_number}</div>
          <div class="selection-card-subtitle">${room.floor} • ${room.category_name}</div>
          <div style="margin-top: 4px; font-weight:800; color:var(--color-brand); font-size:0.9rem;">Rate: ${costDisplay}</div>
        </div>
        <div style="margin-left:auto;">
          <span class="badge ${room.is_ac ? 'badge-success' : 'badge-info'}">${room.is_ac ? 'AC' : 'NON-AC'}</span>
        </div>
      `;
      list.appendChild(card);
    });
  }

  toggleRoomFilter(chip, type) {
    // Toggle active state
    const chips = chip.parentNode.querySelectorAll('.filter-chip');
    chips.forEach(c => c.classList.remove('active'));
    chip.classList.add('active');

    this.renderRoomsForWizard(type);
  }

  selectRoomForWizard(room) {
    this.wizardData.room_id = room.id;
    this.wizardData.room_number = room.room_number;
    this.wizardData.category_id = room.category_id;
    this.wizardData.category_name = room.category_name;
    this.wizardData.rate_plans = room.rate_plans;
    
    // Highlight
    this.renderRoomsForWizard();
    document.getElementById('btn-room-next').disabled = false;
    
    Voice.speak(`Selected Room ${room.room_number}. Tap next to configure rate plans.`);
  }

  submitSelectedRoom() {
    this.nextWizardStep(8);
    this.loadRatePlansForWizard();
  }

  // --- STEP 5: RATE PLANS ---
  loadRatePlansForWizard() {
    const list = document.getElementById('wizard-plans-list');
    list.innerHTML = '';
    
    this.wizardData.rate_plans.forEach(plan => {
      const card = document.createElement('div');
      const isSelected = (this.wizardData.rate_plan_name === plan.rate_plan_key && this.wizardData.price_override === null);
      
      card.className = `selection-card ${isSelected ? 'selected' : ''}`;
      card.onclick = () => this.selectRatePlanForWizard(plan);
      
      card.innerHTML = `
        <div class="selection-card-avatar" style="background-color:#fff3cd; color:#d97706;"><i class="lucide-tags"></i></div>
        <div class="selection-card-details">
          <div class="selection-card-title">${plan.name} Plan</div>
          <div class="selection-card-subtitle">Auto room charge calculator</div>
          <div style="margin-top: 4px; font-weight:800; color:var(--color-brand); font-size:1.1rem;">₹${plan.total_cost} Total</div>
        </div>
      `;
      list.appendChild(card);
    });

    document.getElementById('btn-rate-next').disabled = (this.wizardData.rate_plan_name === null && this.wizardData.price_override === null && !this.wizardData.room_cost);
  }

  selectRatePlanForWizard(plan) {
    this.wizardData.rate_plan_name = plan.rate_plan_key;
    this.wizardData.price_override = null;
    this.wizardData.room_cost = plan.total_cost;
    this.wizardData.total_cost = plan.total_cost;
    
    this.loadRatePlansForWizard();
    
    Voice.speak(`Selected ${plan.name} rate plan. Total ₹${plan.total_cost}.`);
  }



  submitSelectedRate() {
    this.nextWizardStep(9);
  }

  // --- STEPS 4 & 5: DATES AND TIMES (LAYMAN UI) ---
  initCheckIn() {
    // defaults
  }

  initCheckOut() {
    // defaults
  }

  setQuickCheckInNow() {
    const now = new Date();
    this.wizardData.check_in = this.formatDateForMySQL(now);
    
    // Update custom input in case they want to change it
    const dtLocal = new Date(now.getTime() - now.getTimezoneOffset() * 60000).toISOString().slice(0, 16);
    const el = document.getElementById('stay-custom-checkin');
    if (el) el.value = dtLocal;
    
    Voice.speak('Check-in time set to right now.');
    this.submitCheckIn();
  }

  setCustomCheckIn() {
    const val = document.getElementById('stay-custom-checkin').value;
    if (val) {
      this.wizardData.check_in = val.replace('T', ' ') + ':00';
    }
  }

  setQuickCheckout(hours) {
    if (!this.wizardData.check_in) {
      const now = new Date();
      this.wizardData.check_in = this.formatDateForMySQL(now);
    }
    
    const checkIn = new Date(this.wizardData.check_in.replace(' ', 'T'));
    const checkOut = new Date(checkIn.getTime() + hours * 60 * 60 * 1000);
    
    this.wizardData.check_out = this.formatDateForMySQL(checkOut);
    
    const dtLocal = new Date(checkOut.getTime() - checkOut.getTimezoneOffset() * 60000).toISOString().slice(0, 16);
    const el = document.getElementById('stay-custom-checkout');
    if (el) el.value = dtLocal;
    
    const durationText = hours < 24 ? `${hours} hours` : `${hours / 24} day${hours >= 48 ? 's' : ''}`;
    Voice.speak(`Checkout set to ${durationText}.`);
    
    if (navigator.vibrate) navigator.vibrate(50);
  }

  setCustomCheckOut() {
    const val = document.getElementById('stay-custom-checkout').value;
    if (val) {
      this.wizardData.check_out = val.replace('T', ' ') + ':00';
    }
  }

  formatDateForMySQL(date) {
    const y = date.getFullYear();
    const m = (date.getMonth() + 1).toString().padStart(2, '0');
    const d = date.getDate().toString().padStart(2, '0');
    const h = date.getHours().toString().padStart(2, '0');
    const min = date.getMinutes().toString().padStart(2, '0');
    const s = date.getSeconds().toString().padStart(2, '0');
    return `${y}-${m}-${d} ${h}:${min}:${s}`;
  }

  validateDates() {
    if (!this.wizardData.check_in || !this.wizardData.check_out) return;
    
    if (new Date(this.wizardData.check_out.replace(' ', 'T')) <= new Date(this.wizardData.check_in.replace(' ', 'T'))) {
      return; 
    }
    this.recalculatePricing();
  }

  async recalculatePricing() {
    if (this.wizardData.price_override === null) {
      try {
        const res = await this.apiCall('api/bookings.php?action=calculate', {
          category_id: this.wizardData.category_id,
          check_in: this.wizardData.check_in,
          check_out: this.wizardData.check_out,
          rate_plan_name: this.wizardData.rate_plan_name,
          extra_bed: this.wizardData.extra_bed
        });

        if (res && res.success) {
          this.wizardData.room_cost = res.room_cost;
          this.wizardData.extra_bed_cost = res.extra_bed_cost;
          this.wizardData.total_cost = res.total_cost;
        }
      } catch (e) {
        const checkOutDate = new Date(this.wizardData.check_out.replace(' ', 'T'));
        const checkInDate = new Date(this.wizardData.check_in.replace(' ', 'T'));
        const days = Math.max(1, Math.ceil((checkOutDate - checkInDate) / 86400000));
        const baseRate = this.wizardData.rate_plans ? (this.wizardData.rate_plans[0]?.total_cost || 1000) : 1000;
        this.wizardData.room_cost = baseRate * days;
        this.wizardData.extra_bed_cost = this.wizardData.extra_bed ? days * 500 : 0;
        this.wizardData.total_cost = this.wizardData.room_cost + this.wizardData.extra_bed_cost;
      }
    } else {
      const checkOutDate = new Date(this.wizardData.check_out.replace(' ', 'T'));
      const checkInDate = new Date(this.wizardData.check_in.replace(' ', 'T'));
      const days = Math.max(1, Math.ceil((checkOutDate - checkInDate) / 86400000));
      this.wizardData.extra_bed_cost = this.wizardData.extra_bed ? days * 500 : 0;
      this.wizardData.total_cost = this.wizardData.room_cost + this.wizardData.extra_bed_cost;
    }
  }

  submitCheckIn() {
    if (!this.wizardData.check_in) {
      this.showToast('Please select a check-in time', 'warning');
      return;
    }
    this.nextWizardStep(5);
  }

  submitCheckOut() {
    if (!this.wizardData.check_out) {
      this.showToast('Please select a check-out time', 'warning');
      return;
    }
    if (new Date(this.wizardData.check_out.replace(' ', 'T')) <= new Date(this.wizardData.check_in.replace(' ', 'T'))) {
      this.showToast('Checkout must be after Check-in', 'warning');
      Voice.speak('Checkout date must be after check-in date.');
      return;
    }
    this.validateDates();
    this.nextWizardStep(6);
  }

  // --- STEP 6: GUEST COUNTS ---
  adjustGuestCount(type, val) {
    if (type === 'adults') {
      const input = document.getElementById('stay-adults');
      let count = (parseInt(input?.value) || this.wizardData.adults || 1) + val;
      if (count < 1) count = 1;
      this.wizardData.adults = count;
      if (input) input.value = count;
    } else if (type === 'children') {
      const input = document.getElementById('stay-children');
      let count = (parseInt(input?.value) || this.wizardData.children || 0) + val;
      if (count < 0) count = 0;
      this.wizardData.children = count;
      if (input) input.value = count;
    }
  }

  setExtraBed(val) {
    this.wizardData.extra_bed = val;
    // Update the number input directly (HTML uses stay-extra-bed input)
    const input = document.getElementById('stay-extra-bed');
    if (input) input.value = val;
    this.recalculatePricing();
  }

  async submitGuestCounts() {
    this.wizardData.adults = parseInt(document.getElementById('stay-adults').value) || 1;
    this.wizardData.children = parseInt(document.getElementById('stay-children').value) || 0;
    this.wizardData.extra_bed = parseInt(document.getElementById('stay-extra-bed').value) || 0;
    
    const success = await this.loadAvailableRoomsForWizard();
    if (success) {
      this.nextWizardStep(7);
    }
  }

  // --- STEP 9: PRICE OVERRIDE ---
  initPriceOverride() {
    this.recalculatePricing();
    document.getElementById('calculated-price-display').textContent = `₹${Number(this.wizardData.total_cost || 0).toFixed(2)}`;
    const current = this.wizardData.price_override !== null ? this.wizardData.price_override : this.wizardData.total_cost;
    const inputEl = document.getElementById('wizard-final-price-input');
    if (inputEl) {
      inputEl.value = current;
    }
    this.updateDiscountDisplay(current);
  }

  adjustFinalPrice(amount) {
    const inputEl = document.getElementById('wizard-final-price-input');
    if (!inputEl) return;
    let current = parseFloat(inputEl.value) || this.wizardData.total_cost;
    current += amount;
    if (current < 0) current = 0;
    this.wizardData.price_override = current;
    inputEl.value = current;
    this.updateDiscountDisplay(current);
  }

  updatePriceFromInput(val) {
    const parsed = parseFloat(val) || 0;
    this.wizardData.price_override = parsed;
    this.updateDiscountDisplay(parsed);
  }

  updateDiscountDisplay(currentPrice) {
    const diff = this.wizardData.total_cost - currentPrice;
    const discountEl = document.getElementById('wizard-discount-display');
    if (!discountEl) return;
    if (diff > 0) {
      discountEl.textContent = `${diff} discount applied`;
      discountEl.style.color = 'var(--color-success)';
    } else if (diff < 0) {
      discountEl.textContent = `${Math.abs(diff)} surcharge applied`;
      discountEl.style.color = 'var(--color-danger)';
    } else {
      discountEl.textContent = '';
    }
  }

  submitPriceOverride() {
    this.nextWizardStep(10);
  }

  // --- STEP 10: ADVANCE PAYMENT ---
  // Managed by direct native input in index.html

  setPaymentMode(mode) {
    this.wizardData.payment_method = mode;
  }
  
  selectPaymentMode(el, mode) {
    this.setPaymentMode(mode);
    const modes = el.parentElement.querySelectorAll('.payment-mode-card');
    modes.forEach(m => m.classList.remove('active'));
    el.classList.add('active');
  }

  submitPaymentStep() {
    const paymentModeEl = document.getElementById('wizard-payment-mode');
    if (paymentModeEl) {
      this.wizardData.payment_method = paymentModeEl.value;
    }
    this.nextWizardStep(11);
  }

  // --- STEP 11: BOOKING CONFIRMATION SUMMARY ---
  populateBookingSummary() {
    document.getElementById('summary-guest-name').textContent = this.wizardData.guest_name;
    document.getElementById('summary-guest-phone').textContent = this.wizardData.guest_phone;
    
    document.getElementById('summary-checkin').textContent = formatNiceDate(this.wizardData.check_in);
    document.getElementById('summary-checkout').textContent = formatNiceDate(this.wizardData.check_out);
    
    document.getElementById('summary-room').textContent = `Room ${this.wizardData.room_number || ''} (${this.wizardData.category_name || ''})`;
    document.getElementById('summary-guests').textContent = `${this.wizardData.adults} Adults, ${this.wizardData.children} Children`;
    
    document.getElementById('summary-total').textContent = `₹${Number(this.wizardData.total_cost || 0).toFixed(2)}`;
    document.getElementById('summary-advance').textContent = `₹${Number(this.wizardData.payment_collected || 0).toFixed(2)}`;

    // Voice summary for non-literate users
    const guestName = this.wizardData.guest_name;
    const roomNum = this.wizardData.room_number;
    const total = Number(this.wizardData.total_cost || 0).toFixed(0);
    const advance = Number(this.wizardData.payment_collected || 0).toFixed(0);
    const checkIn = formatNiceDate(this.wizardData.check_in);
    const checkOut = formatNiceDate(this.wizardData.check_out);
    
    let voiceMsg = `Booking summary. Guest ${guestName}. Room ${roomNum}. Check-in ${checkIn}. Check-out ${checkOut}. Total ${total} rupees.`;
    if (advance > 0) {
      voiceMsg += ` Advance paid ${advance} rupees.`;
    }
    voiceMsg += ` Tap confirm to complete.`;
    
    setTimeout(() => Voice.speak(voiceMsg), 500);
  }

  async confirmBookingCreation() {
    this.showLoading('Saving booking in PMS...');

    const payload = {
      action: 'create',
      room_id: this.wizardData.room_id,
      guest_id: this.wizardData.guest_id,
      check_in: this.wizardData.check_in,
      check_out: this.wizardData.check_out,
      rate_plan_name: this.wizardData.rate_plan_name,
      price_override: this.wizardData.price_override,
      adults: this.wizardData.adults,
      children: this.wizardData.children,
      extra_bed: this.wizardData.extra_bed,
      payment_collected: this.wizardData.payment_collected,
      payment_method: this.wizardData.payment_method,
      payment_ref: this.wizardData.payment_ref,
      offline_folio_id: this.wizardData.offline_folio_id,
      booking_status: 'booked' // default
    };

    // If check-in date is today or in past, we can directly check in the guest!
    const todayStr = new Date().toISOString().split('T')[0];
    if (this.wizardData.check_in.startsWith(todayStr)) {
      payload.booking_status = 'checked_in';
    }

    try {
      const res = await this.apiCall('api/bookings.php', payload);
      this.hideLoading();

      if (res && res.success) {
        this.completeBookingSuccess(res.booking_id, res.display_id);
      } else {
        this.showToast(res.message || 'Booking failed to create', 'danger');
      }
    } catch (e) {
      this.hideLoading();
      
      if (!navigator.onLine) {
        this.showToast(e.message || 'No internet connection. Booking failed.', 'danger');
      } else {
        this.showToast('Server booking submission error', 'danger');
      }
    }
  }


  completeBookingSuccess(bookingId, displayId) {
    document.getElementById('wizard-step-11').style.display = 'none';
    document.getElementById('wizard-success-screen').style.display = 'flex';
    
    document.getElementById('success-booking-id').textContent = displayId;
    document.getElementById('success-room-number').textContent = `Room ${this.wizardData.room_number}`;
    
    // Keep local reference for printing/sharing
    this.lastCreatedBookingId = bookingId;
    this.lastCreatedBookingDisplayId = displayId;

    Voice.speak(`Booking completed successfully. Displaying Receipt.`);
  }

  shareBookingWhatsApp() {
    const text = `Hello ${this.wizardData.guest_name}, Your booking at MicroPMS Hotel is confirmed! Booking ID: ${this.lastCreatedBookingDisplayId}, Room: ${this.wizardData.room_number}. Check-in: ${formatNiceDate(this.wizardData.check_in)}. Thank you!`;
    const url = `https://wa.me/${PhoneHelperToE164(this.wizardData.guest_phone)}?text=${encodeURIComponent(text)}`;
    window.open(url, '_blank');
  }

  printReceipt() {
    window.print();
  }

  finishBookingWizard() {
    this.showScreen('dashboard');
  }


  // --- CHECK IN SCREEN VIEW ---
  async showCheckInScreen() {
    this.showScreen('checkin');
    this.showLoading('Loading arrivals...');
    try {
      const res = await this.apiCall('api/bookings.php?action=list&filter=today');
      this.hideLoading();

      const list = document.getElementById('checkin-arrivals-list');
      list.innerHTML = '';

      if (res && res.success && res.bookings) {
        const arrivals = res.bookings.filter(b => b.booking_status === 'booked');
        
        if (arrivals.length === 0) {
          list.innerHTML = `
            <div style="text-align:center; padding: 20px; color: var(--color-text-secondary); font-weight:700;">
              No pending check-ins today
            </div>
          `;
          return;
        }

        arrivals.forEach(b => {
          const card = document.createElement('div');
          card.className = 'selection-card';
          const guestName = b.guest_name || 'Walk-in Guest';
          const firstChar = escapeHtml(guestName.charAt(0).toUpperCase());
          const safeGuestName = escapeHtml(guestName);
          const safeRoomNumber = escapeHtml(b.room_number);
          card.innerHTML = `
            <div class="selection-card-avatar">${firstChar}</div>
            <div class="selection-card-details">
              <div class="selection-card-title">${safeGuestName}</div>
              <div class="selection-card-subtitle">Room ${safeRoomNumber} • Arrived at ${escapeHtml(b.display_check_in.split(',')[1])}</div>
              <div style="font-size:0.75rem; font-weight:700; color:var(--color-success); margin-top:2px;">Advance Paid: ₹${b.advance_paid}</div>
            </div>
            <button class="btn-large btn-success" style="width:100px; min-height:45px; height:45px; font-size:0.8rem; padding:0 8px; margin-left:auto;" onclick="app.executeCheckIn(${b.id}, '${safeGuestName.replace(/'/g, "\\'")}', '${safeRoomNumber}')">CHECK IN</button>
          `;
          list.appendChild(card);
        });
      }
    } catch (e) {
      this.hideLoading();
      this.showToast(e.message || 'Check in list error', 'danger');
    }
  }

  async executeCheckIn(bookingId, guestName, roomNumber) {
    this.showLoading('Recording check-in...');
    try {
      const res = await this.apiCall('/api/admin/booking_status', {
        action: 'check_in',
        booking_id: bookingId
      });
      this.hideLoading();

      if (res && res.success) {
        this.showToast(`Checked in ${guestName} to Room ${roomNumber}`, 'success');
        Voice.speak(`${guestName} checked in to Room ${roomNumber} successfully.`);
        if (this.activeScreen === 'history') {
          this.loadBookingHistory();
        } else {
          this.showScreen('dashboard');
        }
      } else {
        this.showToast(res.message || 'Check-in failed', 'danger');
      }
    } catch (e) {
      this.hideLoading();
      this.showToast(e.message || 'Check-in status connection error', 'danger');
    }
  }

  // --- CHECK OUT SCREEN VIEW ---
  async showCheckOutScreen() {
    this.showScreen('checkout');
    this.showLoading('Loading checked-in guests...');
    try {
      const res = await this.apiCall('api/bookings.php?action=list&filter=today');
      this.hideLoading();

      const list = document.getElementById('checkout-list');
      list.innerHTML = '';

      if (res && res.success && res.bookings) {
        const checkedIn = res.bookings.filter(b => b.booking_status === 'checked_in');
        
        if (checkedIn.length === 0) {
          list.innerHTML = `
            <div style="text-align:center; padding: 20px; color: var(--color-text-secondary); font-weight:700;">
              No occupied rooms right now
            </div>
          `;
          return;
        }

        checkedIn.forEach(b => {
          const card = document.createElement('div');
          card.className = 'selection-card';
          const guestName = b.guest_name || 'Walk-in Guest';
          const firstChar = escapeHtml(guestName.charAt(0).toUpperCase());
          card.innerHTML = `
            <div class="selection-card-avatar">${firstChar}</div>
            <div class="selection-card-details">
              <div class="selection-card-title">${escapeHtml(guestName)}</div>
              <div class="selection-card-subtitle">Room ${escapeHtml(b.room_number)} • Checkout due: ${escapeHtml(b.display_check_out)}</div>
              <div style="font-size:0.75rem; font-weight:700; color:var(--color-cta); margin-top:2px;">Pending Dues: ₹${b.balance}</div>
            </div>
            <button class="btn-large btn-danger" style="width:110px; min-height:45px; height:45px; font-size:0.8rem; padding:0 8px; margin-left:auto;" onclick="app.showCheckoutDetailsSheet(${b.id})">BILL SUMMARY</button>
          `;
          list.appendChild(card);
        });
      }
    } catch (e) {
      this.hideLoading();
      this.showToast(e.message || 'Checkout list error', 'danger');
    }
  }

  async showCheckoutDetailsSheet(bookingId) {
    this.showLoading('Fetching bill folio...');
    try {
      const res = await this.apiCall(`api/checkout.php?action=details&booking_id=${bookingId}`);
      this.hideLoading();

      if (res && res.success) {
        this.activeCheckoutData = res;
        
        document.getElementById('checkout-sheet-room').textContent = `Room ${res.booking.room_number} Folio Settle`;
        document.getElementById('bill-room-rent').textContent = `₹${Number(res.bill.room_rent || 0).toFixed(2)}`;
        document.getElementById('bill-restaurant').textContent = `₹${Number(res.bill.restaurant || 0).toFixed(2)}`;
        document.getElementById('bill-laundry').textContent = `₹${Number(res.bill.laundry || 0).toFixed(2)}`;
        document.getElementById('bill-extrabed').textContent = `₹${Number(res.bill.extra_bed || 0).toFixed(2)}`;
        document.getElementById('bill-taxes').textContent = `₹${Number(res.bill.taxes || 0).toFixed(2)}`;
        document.getElementById('bill-advance').textContent = `-₹${Number(res.bill.total_paid || 0).toFixed(2)}`;
        document.getElementById('bill-balance').textContent = `₹${Number(res.bill.balance || 0).toFixed(2)}`;

        // Render Day-Wise Itemized Ledger List
        const ledgerList = document.getElementById('checkout-ledger-entries-list');
        const badgeCount = document.getElementById('ledger-count-badge');
        if (ledgerList && res.ledger) {
          ledgerList.innerHTML = '';
          if (badgeCount) badgeCount.textContent = `${res.ledger.length} entries`;

          if (res.ledger.length === 0) {
            ledgerList.innerHTML = '<div style="text-align:center; color:var(--color-text-secondary); font-size:0.8rem; padding:10px; font-weight:700;">No ledger transactions recorded yet.</div>';
          } else {
            res.ledger.forEach(entry => {
              const amt = Number(entry.amount || 0);
              const isPayment = amt < 0 || entry.transaction_type === 'payment' || (entry.description && entry.description.toLowerCase().includes('payment'));
              const amtFormatted = isPayment ? `-₹${Math.abs(amt).toFixed(2)}` : `+₹${amt.toFixed(2)}`;
              const colorStyle = isPayment ? 'color: var(--color-success);' : 'color: var(--color-text-primary);';
              const recDate = entry.recorded_at ? formatNiceDate(entry.recorded_at) : '';

              const item = document.createElement('div');
              item.style.cssText = 'padding: 8px 10px; background: white; border-radius: 8px; border: 1px solid var(--color-border); display: flex; justify-content: space-between; align-items: center; gap: 8px;';
              item.innerHTML = `
                <div style="min-width:0;">
                  <div style="font-weight: 800; font-size: 0.85rem; color: var(--color-text-primary);">${escapeHtml(entry.description || entry.transaction_type)}</div>
                  <div style="font-size: 0.72rem; color: var(--color-text-secondary); font-weight: 600; margin-top: 2px;">
                    ${entry.display_id ? `<span style="font-weight:700; color:var(--color-brand);">${escapeHtml(entry.display_id)}</span> • ` : ''}${escapeHtml(recDate)}${entry.payment_method ? ` • ${escapeHtml(entry.payment_method)}` : ''}
                  </div>
                </div>
                <div style="text-align: right; shrink: 0;">
                  <span style="font-weight: 900; font-size: 0.92rem; ${colorStyle}">${amtFormatted}</span>
                </div>
              `;
              ledgerList.appendChild(item);
            });
          }
        }

        // Enforce payment collections before checkout if balance > 0
        const collectBtn = document.getElementById('btn-checkout-collect');
        const executeBtn = document.getElementById('btn-checkout-execute');

        if (res.bill.balance > 0) {
          collectBtn.style.display = 'inline-flex';
          collectBtn.innerHTML = '💵 COLLECT PAYMENT';
          executeBtn.disabled = true;
          executeBtn.style.opacity = 0.5;
          Voice.speak(`Room ${res.booking.room_number} has pending dues of ₹${res.bill.balance}. Please collect payment first.`);
        } else if (res.bill.balance < 0) {
          collectBtn.style.display = 'inline-flex';
          collectBtn.innerHTML = '💵 PROCESS REFUND';
          executeBtn.disabled = true;
          executeBtn.style.opacity = 0.5;
          Voice.speak(`Room ${res.booking.room_number} has a negative balance. Please process a refund or adjust charges before checkout.`);
        } else {
          collectBtn.style.display = 'none';
          executeBtn.disabled = false;
          executeBtn.style.opacity = 1;
          Voice.speak(`Dues settled. Ready to checkout Room ${res.booking.room_number}.`);
        }

        document.getElementById('checkout-sheet-popup').classList.add('active');

        // Load quick charges
        this.loadQuickCharges();

        // Show payment mode selector and reset to default
        const modeSelector = document.getElementById('payment-mode-selector');
        if (modeSelector) {
          modeSelector.style.display = 'flex'; // Always show so they can select mode even for zero balance
        }
        
        document.querySelectorAll('.payment-mode-pill').forEach(p => p.classList.remove('active'));
        const defaultMethod = (this.paymentMethods && this.paymentMethods.length > 0) 
                              ? this.paymentMethods[0].toLowerCase().replace(/[^a-z0-9]/g, '') 
                              : 'cash';
        this.selectedPaymentMode = defaultMethod;
        const defaultPill = document.getElementById(`pill-${defaultMethod}`);
        if (defaultPill) defaultPill.classList.add('active');
      }
    } catch (e) {
      this.hideLoading();
      this.showToast(e.message || 'Failed to load checkout details', 'danger');
    }
  }

  closeCheckoutSheet(event) {
    if (event && event.target !== event.currentTarget) return;
    document.getElementById('checkout-sheet-popup').classList.remove('active');
  }

  selectPaymentModePill(el, mode) {
    this.selectedPaymentMode = mode;
    document.querySelectorAll('.payment-mode-pill').forEach(p => p.classList.remove('active'));
    el.classList.add('active');
  }

  checkoutCollectPayment() {
    const mode = this.selectedPaymentMode || 'cash';
    this.closeCheckoutSheet();
    this.activePaymentMode = mode;
    this.activePaymentBookingId = this.activeCheckoutData.booking.id;
    this.numpadValue = '';
    this.openNumpadPopup('payment_settle', `Collect Payment (${mode.toUpperCase()}) ₹`, '', (val) => {
      this.submitPaymentCollection(parseFloat(val) || 0, this.activePaymentMode);
    });
  }

  checkoutGenerateBill() {
    window.open(`/guest_invoice.php?id=${this.activeCheckoutData.booking.id}`, '_blank');
  }

  checkoutSendInvoiceWhatsApp() {
    if (!this.activeCheckoutData || !this.activeCheckoutData.booking) return;
    
    const booking = this.activeCheckoutData.booking;
    const guestPhone = booking.guest_phone || '';
    if (!guestPhone) {
      this.showToast('Guest phone not available', 'danger');
      return;
    }

    // Generate secure invoice link via API
    this.apiCall(`api/bookings.php?action=invoice_link&booking_id=${booking.id}`).then(res => {
      if (res && res.success && res.invoice_link) {
        let cleanPhone = guestPhone.replace(/[^0-9]/g, '');
        if (cleanPhone.length === 10) cleanPhone = '91' + cleanPhone;
        
        const message = encodeURIComponent(`Invoice for your stay at Room ${booking.room_number}\n\n${res.invoice_link}`);
        window.open(`https://wa.me/${cleanPhone}?text=${message}`, '_blank');
      } else {
        this.showToast('Could not generate invoice link', 'danger');
      }
    }).catch(() => {
      this.showToast('Connection error', 'danger');
    });
  }

  async loadQuickCharges() {
    const section = document.getElementById('quick-charges-section');
    const grid = document.getElementById('quick-charges-grid');
    if (!section || !grid) return;

    try {
      const res = await this.apiCall('api/quick_charges.php?action=presets');
      if (res && res.success && res.presets.length > 0) {
        grid.innerHTML = res.presets.map(p => `
          <button class="quick-charge-btn" onclick="app.postQuickCharge('${p.name}', ${p.amount})">
            <i class="ph ${p.icon || 'ph-receipt'}" style="font-size: 1rem;"></i>
            ${p.name} ₹${p.amount}
          </button>
        `).join('');
        section.style.display = 'flex';
      } else {
        section.style.display = 'none';
      }
    } catch (e) {
      section.style.display = 'none';
    }
  }

  async postQuickCharge(name, amount) {
    if (!this.activeCheckoutData || !this.activeCheckoutData.booking) return;
    
    const bookingId = this.activeCheckoutData.booking.id;
    this.showLoading(`Adding ${name}...`);
    
    try {
      const res = await this.apiCall('api/quick_charges.php?action=add', {
        booking_id: bookingId,
        name: name,
        amount: amount
      });
      this.hideLoading();

      if (res && res.success) {
        this.showToast(res.message, 'success');
        Voice.speak(`Collected ₹${amount} successfully.`);
        // Refresh the checkout sheet
        this.showCheckoutDetailsSheet(bookingId);
      } else {
        this.showToast(res.message || 'Failed to add charge', 'danger');
      }
    } catch (e) {
      this.hideLoading();
      this.showToast(e.message || 'Connection error', 'danger');
    }
  }

  async checkoutExecute() {
    this.closeCheckoutSheet();
    this.showLoading('Executing checkout in PMS...');
    try {
      const res = await this.apiCall('api/checkout.php', {
        action: 'checkout',
        booking_id: this.activeCheckoutData.booking.id
      });
      this.hideLoading();

      if (res && res.success) {
        this.showToast(res.message, 'success');
        Voice.speak(`Checkout processed successfully.`);
        if (this.activeScreen === 'history') {
          this.loadBookingHistory();
        } else {
          this.showScreen('dashboard');
        }
      } else {
        this.showToast(res.message || 'Checkout failed', 'danger');
      }
    } catch (e) {
      this.hideLoading();
      this.showToast(e.message || 'Checkout connection error', 'danger');
    }
  }

  checkoutOpenChangeDate() {
    if (!this.activeCheckoutData || !this.activeCheckoutData.booking) return;
    document.getElementById('new-checkout-datetime').value = this.activeCheckoutData.booking.check_out_raw;
    document.getElementById('change-checkout-modal').classList.add('active');
  }

  closeChangeCheckoutModal(event) {
    if (event && event.target !== event.currentTarget) return;
    document.getElementById('change-checkout-modal').classList.remove('active');
  }

  async checkoutSubmitChangeDate() {
    const newDate = document.getElementById('new-checkout-datetime').value;
    if (!newDate) {
      this.showToast('Please select a valid date and time', 'warning');
      return;
    }
    this.closeChangeCheckoutModal();
    this.showLoading('Recalculating folio...');
    try {
      const res = await this.apiCall('/api/admin/update_checkout_date', {
        booking_id: this.activeCheckoutData.booking.id,
        new_checkout_date: newDate.replace('T', ' ')
      });
      this.hideLoading();
      if (res && res.success) {
        this.showToast('Checkout date updated and folio recalculated.', 'success');
        this.showCheckoutDetailsSheet(this.activeCheckoutData.booking.id);
      } else {
        this.showToast(res.message || 'Failed to update checkout date', 'danger');
      }
    } catch (e) {
      this.hideLoading();
      this.showToast(e.message || 'Connection error', 'danger');
    }
  }

  // --- PAYMENT MODE PICKER ---
  openPaymentModePickerThenNumpad() {
    const icons = { 'Cash': 'lucide-banknote', 'UPI': 'lucide-smartphone', 'Card': 'lucide-credit-card', 'BankTransfer': 'lucide-building-2', 'Online': 'lucide-globe' };
    const modes = (this.paymentMethods || ['Cash', 'UPI']).map(m => {
      const key = m.toLowerCase().replace(/[^a-z0-9]/g, '');
      return { key: key, label: m, icon: icons[m] || 'lucide-banknote' };
    });

    const existingPicker = document.getElementById('quick-payment-mode-picker');
    if (existingPicker) existingPicker.remove();

    const overlay = document.createElement('div');
    overlay.id = 'quick-payment-mode-picker';
    overlay.className = 'modal-overlay active';
    overlay.style.zIndex = '500';
    overlay.innerHTML = `
      <div class="modal-content-box" style="margin-top: auto; border-radius: var(--border-radius-lg) var(--border-radius-lg) 0 0; max-width: 400px; margin-left: auto; margin-right: auto;" onclick="event.stopPropagation()">
        <div style="display: flex; justify-content: space-between; align-items: center; margin-bottom: 16px;">
          <strong style="font-size: 1.1rem; font-weight: 800;">Select Payment Mode</strong>
          <button onclick="document.getElementById('quick-payment-mode-picker').remove()" style="background:none;border:none;font-size:1.5rem;color:var(--color-text-muted);cursor:pointer;">&times;</button>
        </div>
        <div style="display: grid; grid-template-columns: 1fr 1fr; gap: 10px; margin-bottom: 16px;">
          ${modes.map(m => `
            <button onclick="app.pickModeAndCollect('${m.key}')" style="display:flex;flex-direction:column;align-items:center;gap:8px;padding:18px 10px;border:2px solid var(--color-border);border-radius:12px;background:white;font-weight:800;font-size:0.95rem;color:var(--color-text-primary);cursor:pointer;">
              <i class="${m.icon}" style="font-size:1.8rem; color:var(--color-brand);"></i>
              ${m.label}
            </button>
          `).join('')}
        </div>
      </div>
    `;
    overlay.onclick = (e) => { if (e.target === overlay) overlay.remove(); };
    document.body.appendChild(overlay);
  }

  pickModeAndCollect(mode) {
    const overlay = document.getElementById('quick-payment-mode-picker');
    if (overlay) overlay.remove();
    this.activePaymentMode = mode;
    this.numpadValue = '';
    this.openNumpadPopup('payment_settle', `Collect Payment (${mode.toUpperCase()}) \u20b9`, '', (val) => {
      this.submitPaymentCollection(parseFloat(val) || 0, this.activePaymentMode);
    });
  }

  // --- COLLECT PAYMENT SCREEN VIEW ---
  async showPaymentCollectScreen(targetBookingId = null, isBackground = false) {
    if (targetBookingId) {
      // Show mode picker first, then numpad
      this.activePaymentBookingId = targetBookingId;
      this.openPaymentModePickerThenNumpad();
      return;
    }

    if (!isBackground) {
      this.showScreen('payments');
      this.showLoading('Loading pending balances...');
    }
    try {
      const res = await this.apiCall('api/bookings.php?action=list&filter=today');
      if (!isBackground) this.hideLoading();

      const list = document.getElementById('payments-pending-list');
      if (!list) return;
      list.innerHTML = '';

      if (res && res.success && res.bookings) {
        const pending = res.bookings.filter(b => b.balance > 0 && b.booking_status !== 'checked_out');
        
        if (pending.length === 0) {
          list.innerHTML = `
            <div style="text-align:center; padding: 20px; color: var(--color-text-secondary); font-weight:700;">
              No pending dues on active bookings
            </div>
          `;
          return;
        }

        pending.forEach(b => {
          const card = document.createElement('div');
          card.className = 'selection-card';
          const guestName = b.guest_name || 'Walk-in Guest';
          const firstChar = escapeHtml(guestName.charAt(0).toUpperCase());
          card.innerHTML = `
            <div class="selection-card-avatar">${firstChar}</div>
            <div class="selection-card-details">
              <div class="selection-card-title">${escapeHtml(guestName)}</div>
              <div class="selection-card-subtitle">Room ${escapeHtml(b.room_number)} • Total Charges: ₹${b.total_amount}</div>
              <div style="font-size:0.9rem; font-weight:800; color:var(--color-cta); margin-top:2px;">Dues: ₹${b.balance}</div>
            </div>
            <button class="btn-large btn-success" style="width:100px; min-height:45px; height:45px; font-size:0.8rem; padding:0 8px; margin-left:auto;" onclick="app.showPaymentCollectScreen(${b.id})">COLLECT</button>
          `;
          list.appendChild(card);
        });
      }
    } catch (e) {
      this.hideLoading();
      this.showToast(e.message || 'Dues collection list load error', 'danger');
    }
  }

  async submitPaymentCollection(amount, method = 'cash') {
    if (amount <= 0) return;
    
    this.showLoading('Processing payment...');
    try {
      const res = await this.apiCall('/api/admin/record_payment', {
        booking_id: this.activePaymentBookingId,
        amount: amount,
        method: method,   // matches PMS: cash | upi | card | online
        ref: ''
      });
      this.hideLoading();

      if (res && res.success) {
        this.showToast(`₹${amount} via ${method.toUpperCase()} recorded!`, 'success');
        Voice.speak(`Collected ₹${amount} successfully.`);
        
        if (this.activeScreen === 'checkout') {
          this.showCheckoutDetailsSheet(this.activePaymentBookingId);
        } else if (this.activeScreen === 'history') {
          this.showScreen('history');
          this.loadBookingHistory();
        } else {
          this.showScreen('dashboard');
        }
      } else {
        this.showToast(res.message || 'Payment failed', 'danger');
      }
    } catch (e) {
      this.hideLoading();
      this.showToast(e.message || 'Payment API connection error', 'danger');
    }
  }

  // --- MISSING ID VERIFY TRIGGER ---
  showMissingIdVerify(bookingId, guestId) {
    this.wizardData.guest_id = guestId;
    this.activeScanSide = 'front';
    
    // Show a bottom sheet with options to upload ID proof
    const sheet = document.createElement('div');
    sheet.className = 'modal-overlay active';
    sheet.id = 'missing-id-sheet';
    sheet.onclick = (e) => { if (e.target === sheet) sheet.remove(); };
    
    sheet.innerHTML = `
      <div class="modal-content-box" style="margin-top: auto; border-radius: var(--border-radius-lg) var(--border-radius-lg) 0 0;">
        <div style="display: flex; justify-content: space-between; align-items: center; margin-bottom: 16px;">
          <strong style="font-size: 1.2rem;">Upload ID Proof</strong>
          <span style="font-size: 1.5rem; cursor: pointer;" onclick="document.getElementById('missing-id-sheet').remove()">❌</span>
        </div>
        <p style="color: var(--color-text-secondary); font-weight: 600; margin-bottom: 16px;">
          Guest needs ID verification. Choose how to upload:
        </p>
        <div style="display: flex; flex-direction: column; gap: 10px;">
          <button class="btn-large btn-brand" onclick="document.getElementById('missing-id-sheet').remove(); app.startIdCameraCapture('front', ${guestId})">
            📷 SCAN WITH CAMERA
          </button>
          <button class="btn-large btn-outline" onclick="document.getElementById('missing-id-sheet').remove(); app.triggerIdFileUpload('front', ${guestId})">
            🖼️ CHOOSE FROM GALLERY
          </button>
          <button class="btn-large btn-outline" onclick="document.getElementById('missing-id-sheet').remove(); app.skipIdAndContinue(${bookingId})" style="border-color: var(--color-warning); color: var(--color-warning);">
            ⏭️ SKIP FOR NOW
          </button>
        </div>
      </div>
    `;
    
    document.body.appendChild(sheet);
    Voice.speak('Please upload the guest ID proof.');
  }

  startIdCameraCapture(side, guestId) {
    this.activeScanSide = side;
    this.wizardData.guest_id = guestId;
    this.missingIdMode = true; // Flag to skip OCR and just upload
    
    // Open camera popup
    const popup = document.getElementById('camera-popup');
    if (popup) {
      popup.classList.add('active');
      this.initCameraStream();
      Voice.speak('Please capture the front of the ID card.');
    } else {
      // Fallback to file upload
      this.triggerIdFileUpload(side, guestId);
    }
  }

  triggerIdFileUpload(side, guestId) {
    this.activeScanSide = side;
    this.wizardData.guest_id = guestId;
    
    // Create a temporary file input
    const input = document.createElement('input');
    input.type = 'file';
    input.accept = 'image/*,application/pdf';
    input.capture = 'environment';
    input.onchange = (e) => {
      const file = e.target.files[0];
      if (file) {
        this.uploadIdProofFile(file, side, guestId);
      }
    };
    input.click();
  }

  async uploadIdProofFile(file, side, guestId) {
    this.showLoading('Uploading ID proof...');
    
    try {
      // Build the URL
      const loc = window.location.pathname;
      const assistantIndex = loc.indexOf('/assistant');
      const pmsApiBase = assistantIndex !== -1 ? loc.substring(0, assistantIndex) + '/api/' : '/api/';
      const uploadUrl = pmsApiBase + 'ocr_upload.php';
      
      const formData = new FormData();
      formData.append('file', file);
      formData.append('guest_id', guestId);
      formData.append('id_type', side === 'front' ? 'id_proof_front' : 'id_proof_back');
      
      const headers = {};
      if (this.csrfToken) {
        headers['X-CSRF-TOKEN'] = this.csrfToken;
      }
      
      const response = await fetch(uploadUrl, {
        method: 'POST',
        headers: headers,
        body: formData,
        cache: 'no-store'
      });
      
      const res = await response.json();
      this.hideLoading();
      
      if (res && res.success) {
        this.showToast('ID proof uploaded successfully!', 'success');
        Voice.speak('ID proof uploaded successfully.');
        // Refresh the notifications to remove the alert
        this.loadDashboardData();
      } else {
        this.showToast(res?.error || 'Upload failed', 'danger');
        Voice.speak('Upload failed. Please try again.');
      }
    } catch (e) {
      this.hideLoading();
      this.showToast(e.message || 'Upload error', 'danger');
      Voice.speak('Upload error. Please try again.');
    }
  }

  skipIdAndContinue(bookingId) {
    this.showToast('ID upload skipped', 'info');
    Voice.speak('ID upload skipped. You can upload later from the booking actions.');
  }

  // --- BOOKING HISTORY PAGE ---
  setHistoryFilter(filter) {
    this.historyFilter = filter;
    
    const chips = ['today', 'week', 'month'];
    chips.forEach(c => document.getElementById(`history-filter-${c}`).classList.remove('active'));
    document.getElementById(`history-filter-${filter}`).classList.add('active');
    
    this.loadBookingHistory();
  }

  handleHistorySearch(query) {
    this.historySearch = query;
    this.loadBookingHistory();
  }

  /**
   * Voice search for booking history
   * Speaks guest name or phone to search
   */
  voiceSearchHistory() {
    const mic = document.createElement('button');
    mic.style.cssText = 'position:fixed;bottom:100px;left:50%;transform:translateX(-50%);z-index:999;';
    
    Voice.speak('Speak guest name or phone number to search.');
    
    setTimeout(() => {
      Voice.startListening(
        (transcript) => {
          const searchInput = document.getElementById('history-search-input');
          if (searchInput) {
            searchInput.value = transcript;
            this.handleHistorySearch(transcript);
          }
          Voice.speak(`Searching for ${transcript}.`);
        },
        () => {
          this.showLoading('Listening...');
        },
        (err) => {
          this.hideLoading();
          this.showToast('Voice search error: ' + err, 'warning');
        },
        () => {
          this.hideLoading();
        }
      );
    }, 1500);
  }

  // --- BOOKING ACTIONS SHEET ---
  openBookingActionsSheet(bookingOrId, guestName, roomNumber, status, guestId, idFront, idBack, checkOutDate) {
    let b = {};
    if (typeof bookingOrId === 'object' && bookingOrId !== null) {
      b = bookingOrId;
    } else {
      b = {
        id: bookingOrId,
        guest_name: guestName,
        room_number: roomNumber,
        booking_status: status,
        guest_id: guestId,
        id_proof_front: idFront,
        id_proof_back: idBack,
        check_out: checkOutDate
      };
    }

    const bookingId = b.id;
    const gName = b.guest_name || 'Guest';
    const gPhone = b.guest_phone || b.phone || '-';
    const rNum = b.room_number || '-';
    const rType = b.category_name ? ` (${b.category_name})` : '';
    const bStatus = b.booking_status || 'booked';
    const ratePlan = b.rate_plan_name || 'Standard Rate';
    const checkInStr = b.display_check_in || (b.check_in ? formatNiceDate(b.check_in) : '-');
    const checkOutStr = b.display_check_out || (b.check_out ? formatNiceDate(b.check_out) : '-');

    const bkgDisplayId = b.display_id || (`BKG-${bookingId}`);
    const folioDisplayId = b.offline_folio_id || (`FOL-${bookingId}`);

    this.activeActionBookingId = bookingId;
    this.activeActionGuestName = gName;
    this.activeActionRoomNumber = rNum;
    this.activeActionGuestId = b.guest_id || 0;
    this.activeActionIdFront = b.id_proof_front || '';
    this.activeActionIdBack = b.id_proof_back || '';
    this.activeActionCheckOut = b.check_out || '';
    
    document.getElementById('action-sheet-name').textContent = gName;
    document.getElementById('action-sheet-phone').textContent = gPhone;
    document.getElementById('action-sheet-room').textContent = `Room ${rNum}${rType}`;
    document.getElementById('action-sheet-rate-plan').textContent = ratePlan;
    document.getElementById('action-sheet-checkin').textContent = checkInStr;
    document.getElementById('action-sheet-checkout').textContent = checkOutStr;
    document.getElementById('action-sheet-avatar').textContent = gName.charAt(0).toUpperCase();

    const bkgEl = document.getElementById('action-sheet-bkg-id');
    const folioEl = document.getElementById('action-sheet-folio-no');
    if (bkgEl) bkgEl.textContent = bkgDisplayId;
    if (folioEl) folioEl.textContent = folioDisplayId;
    
    // Render current ID proof previews and status
    this.refreshActionIdPreviews();
    
    const statusEl = document.getElementById('action-sheet-status');
    const btnCheckIn = document.getElementById('btn-action-checkin');
    const btnCheckOut = document.getElementById('btn-action-checkout');
    const btnCollect = document.getElementById('btn-action-collect');
    const btnBill = document.getElementById('btn-action-bill');
    const btnExtend = document.getElementById('btn-action-extend');
    
    btnCheckIn.style.display = 'none';
    btnCheckOut.style.display = 'none';
    btnCollect.style.display = 'none';
    btnBill.style.display = 'none';
    if (btnExtend) btnExtend.style.display = 'none';
 
    if (bStatus === 'booked') {
      statusEl.innerHTML = '<span class="badge badge-info">Booked</span>';
      btnCheckIn.style.display = 'flex';
      btnCollect.style.display = 'flex';
      if (btnExtend) btnExtend.style.display = 'flex';
    } else if (bStatus === 'checked_in') {
      statusEl.innerHTML = '<span class="badge badge-success">Active</span>';
      btnCheckOut.style.display = 'flex';
      btnBill.style.display = 'flex';
      btnCollect.style.display = 'flex';
      if (btnExtend) btnExtend.style.display = 'flex';
    } else if (bStatus === 'checked_out') {
      statusEl.innerHTML = '<span class="badge badge-danger">Checked Out</span>';
      btnBill.style.display = 'flex';
    } else {
      statusEl.innerHTML = '';
    }
 
    document.getElementById('booking-actions-popup').classList.add('active');
  }

  closeBookingActionsSheet(event) {
    if (event && event.target !== event.currentTarget) return;
    document.getElementById('booking-actions-popup').classList.remove('active');
  }

  actionExtendStay() {
    this.closeBookingActionsSheet();
    
    const friendlyDate = formatNiceDate(this.activeActionCheckOut);
    document.getElementById('extend-current-checkout').textContent = friendlyDate || this.activeActionCheckOut;
    
    // Reset new checkout display
    const newDisplay = document.getElementById('extend-new-checkout-display');
    if (newDisplay) newDisplay.textContent = 'Tap below';
    
    document.getElementById('extend-stay-popup').classList.add('active');
  }

  closeExtendStayPopup(event) {
    if (event && event.target !== event.currentTarget) return;
    document.getElementById('extend-stay-popup').classList.remove('active');
  }

  /**
   * Quick extend stay by hours — selects AND submits immediately in one tap
   */
  async quickExtend(hours) {
    const currentCheckout = this.activeActionCheckOut;
    if (!currentCheckout) {
      this.showToast('Current checkout time not available', 'warning');
      return;
    }
    
    // Parse current checkout and compute new checkout
    const current = new Date(currentCheckout.replace(' ', 'T'));
    const extended = new Date(current.getTime() + hours * 60 * 60 * 1000);
    
    const y = extended.getFullYear();
    const m = (extended.getMonth() + 1).toString().padStart(2, '0');
    const d = extended.getDate().toString().padStart(2, '0');
    const h = extended.getHours().toString().padStart(2, '0');
    const min = extended.getMinutes().toString().padStart(2, '0');
    const newCheckoutStr = `${y}-${m}-${d} ${h}:${min}:00`;

    const durationText = hours < 24 ? `${hours} hour${hours > 1 ? 's' : ''}` : `${hours / 24} day${hours > 24 ? 's' : ''}`;

    // Haptic feedback
    if (navigator.vibrate) navigator.vibrate([50, 30, 50]);

    // Submit directly to API
    this.showLoading(`Extending by ${durationText}...`);
    try {
      const res = await this.apiCall('api/bookings.php?action=extend_stay', {
        booking_id: this.activeActionBookingId,
        check_out: newCheckoutStr
      });
      this.hideLoading();

      if (res && res.success) {
        // Update local reference so popup shows updated checkout
        this.activeActionCheckOut = newCheckoutStr;
        document.getElementById('extend-current-checkout').textContent =
          `${d}/${m}/${y} ${h}:${min}`;
        const newDisplay = document.getElementById('extend-new-checkout-display');
        if (newDisplay) newDisplay.textContent = `✅ ${d}/${m}/${y} ${h}:${min}`;

        this.showToast(`✅ Extended by ${durationText}`, 'success');
        Voice.speak(`Stay extended by ${durationText}. Extra charge is rupees ${res.extra_cost}.`);
        this.loadBookingHistory();
        this.loadDashboardData();
      } else {
        this.showToast(res.message || 'Failed to extend stay', 'danger');
      }
    } catch (e) {
      this.hideLoading();
      this.showToast(e.message || 'Extension failed, check connection', 'danger');
    }
  }

  async submitExtendStay() {
    const inputValue = document.getElementById('extend-new-checkout-date').value;
    if (!inputValue) {
      this.showToast('Please select a new checkout date & time', 'warning');
      return;
    }

    // Convert YYYY-MM-DDTHH:MM to MySQL format YYYY-MM-DD HH:MM:00
    const newCheckoutStr = inputValue.replace('T', ' ') + ':00';
    
    if (new Date(inputValue) <= new Date(this.activeActionCheckOut.replace(' ', 'T'))) {
      this.showToast('New checkout must be after current checkout', 'warning');
      return;
    }

    this.showLoading('Extending Stay...');
    try {
      const res = await this.apiCall('api/bookings.php?action=extend_stay', {
        booking_id: this.activeActionBookingId,
        check_out: newCheckoutStr
      });
      this.hideLoading();

      if (res && res.success) {
        this.closeExtendStayPopup();
        this.showToast('Stay extended successfully', 'success');
        Voice.speak(`Stay extended successfully. Extra charge is ₹${res.extra_cost}.`);
        this.loadBookingHistory(); // Refresh lists
        this.loadDashboardData();  // Refresh stats
      } else {
        this.showToast(res.message || 'Failed to extend stay', 'danger');
      }
    } catch (e) {
      this.hideLoading();
      this.showToast(e.message || 'Extension API connection error', 'danger');
    }
  }

  refreshActionIdPreviews() {
    const frontPreview = document.getElementById('action-id-front-preview');
    const backPreview = document.getElementById('action-id-back-preview');
    const statusText = document.getElementById('action-sheet-id-status');

    if (frontPreview && backPreview && statusText) {
      const frontFilename = this.activeActionIdFront || '';
      const backFilename = this.activeActionIdBack || '';
      
      const hasFront = frontFilename.trim().length > 0;
      const hasBack = backFilename.trim().length > 0;

      if (hasFront && hasBack) {
        statusText.className = 'badge badge-success';
        statusText.textContent = 'Verified';
      } else {
        statusText.className = 'badge badge-danger';
        statusText.textContent = 'Missing ID';
      }

      if (hasFront) {
        frontPreview.innerHTML = `<img src="/api/admin/view_document?file=${frontFilename}" style="width:100%; height:100%; object-fit:cover;">`;
      } else {
        frontPreview.innerHTML = `
          <i class="lucide-image" style="font-size: 1.5rem; color: var(--color-text-muted);"></i>
          <span style="font-size: 0.75rem; color: var(--color-text-secondary); margin-top: 4px; font-weight:700;">Front Image</span>
        `;
      }

      if (hasBack) {
        backPreview.innerHTML = `<img src="/api/admin/view_document?file=${backFilename}" style="width:100%; height:100%; object-fit:cover;">`;
      } else {
        backPreview.innerHTML = `
          <i class="lucide-image" style="font-size: 1.5rem; color: var(--color-text-muted);"></i>
          <span style="font-size: 0.75rem; color: var(--color-text-secondary); margin-top: 4px; font-weight:700;">Back Image</span>
        `;
      }
    }
  }

  showIdProofOptions(idType) {
    this.currentIdProofType = idType;
    const viewBtn = document.getElementById('btn-id-view');
    const hasImage = (idType === 'id_proof_front' && this.activeActionIdFront) || 
                     (idType === 'id_proof_back' && this.activeActionIdBack);
    
    viewBtn.style.display = hasImage ? 'block' : 'none';
    document.getElementById('id-proof-options-popup').classList.add('active');
  }

  closeIdProofOptions(event) {
    if (event && event.target !== event.currentTarget) return;
    document.getElementById('id-proof-options-popup').classList.remove('active');
  }

  viewIdProof() {
    this.closeIdProofOptions();
    const filename = this.currentIdProofType === 'id_proof_front' ? this.activeActionIdFront : this.activeActionIdBack;
    if (filename) {
      window.open(`/api/admin/view_document?file=${filename}`, '_blank');
    }
  }

  triggerIdCamera() {
    this.closeIdProofOptions();
    if (this.currentIdProofType === 'id_proof_front') {
      document.getElementById('action-id-front-camera').click();
    } else {
      document.getElementById('action-id-back-camera').click();
    }
  }

  triggerIdUpload() {
    this.closeIdProofOptions();
    if (this.currentIdProofType === 'id_proof_front') {
      document.getElementById('action-id-front-picker').click();
    } else {
      document.getElementById('action-id-back-picker').click();
    }
  }

  async uploadIdProofImage(idType, file) {
    if (!file) return;
    if (!this.activeActionGuestId) {
      this.showToast('No active guest linked to this booking', 'warning');
      return;
    }

    this.showLoading('Uploading ID Proof...');
    const formData = new FormData();
    formData.append('guest_id', this.activeActionGuestId);
    formData.append('id_type', idType);
    formData.append('file', file);

    try {
      const response = await fetch('api/ocr_upload.php', {
        method: 'POST',
        body: formData
      });
      const res = await response.json();
      this.hideLoading();

      if (res && res.success) {
        this.showToast('ID Proof updated successfully', 'success');
        Voice.speak('ID proof uploaded successfully.');
        
        if (idType === 'id_proof_front') {
          this.activeActionIdFront = res.filename;
        } else if (idType === 'id_proof_back') {
          this.activeActionIdBack = res.filename;
        }
        
        this.refreshActionIdPreviews();
        this.loadBookingHistory(); // Reload history cache
        this.loadDashboardData();  // Update statistics
      } else {
        this.showToast(res.message || 'Upload failed', 'danger');
      }
    } catch (e) {
      this.hideLoading();
      this.showToast(e.message || 'Network error during upload', 'danger');
    }
  }

  actionCheckIn() {
    this.closeBookingActionsSheet();
    this.executeCheckIn(this.activeActionBookingId, this.activeActionGuestName, this.activeActionRoomNumber);
  }

  actionCheckOut() {
    this.closeBookingActionsSheet();
    document.getElementById('checkout-type-modal').classList.add('active');
  }

  closeCheckoutTypeModal(event) {
    if (event && event.target !== event.currentTarget) return;
    document.getElementById('checkout-type-modal').classList.remove('active');
  }

  proceedWithNormalCheckout() {
    this.closeCheckoutTypeModal();
    this.showCheckoutDetailsSheet(this.activeActionBookingId);
  }

  async proceedWithEarlyCheckout() {
    this.closeCheckoutTypeModal();
    this.showLoading('Fetching booking details...');
    try {
      const res = await this.apiCall(`api/checkout.php?action=details&booking_id=${this.activeActionBookingId}`);
      this.hideLoading();
      if (res && res.success) {
        this.activeCheckoutData = res;
        // Format to YYYY-MM-DDTHH:MM for the datetime-local input
        document.getElementById('new-checkout-datetime').value = res.booking.check_out_raw;
        document.getElementById('change-checkout-modal').classList.add('active');
      } else {
        this.showToast('Failed to load booking details', 'danger');
      }
    } catch(e) {
      this.hideLoading();
      this.showToast('Network error', 'danger');
    }
  }

  actionCollectPayment() {
    this.closeBookingActionsSheet();
    this.showPaymentCollectScreen(this.activeActionBookingId);
  }

  actionBillSummary() {
    this.closeBookingActionsSheet();
    this.showCheckoutDetailsSheet(this.activeActionBookingId);
  }

  async loadBookingHistory(isBackground = false) {
    if (!isBackground) this.showLoading('Fetching bookings...');
    try {
      const res = await this.apiCall('api/bookings.php?action=list', {
        filter: this.historyFilter,
        q: this.historySearch
      });
      if (!isBackground) this.hideLoading();

      const list = document.getElementById('history-bookings-list');
      if (!list) return;
      list.innerHTML = '';

      if (res && res.success && res.bookings) {
        if (res.bookings.length === 0) {
          list.innerHTML = `
            <div style="text-align:center; padding: 20px; color: var(--color-text-secondary); font-weight:700;">
              No bookings found in this period
            </div>
          `;
          return;
        }

        res.bookings.forEach(b => {
          const card = document.createElement('div');
          
          let statusBadge = '';
          let cardStyle = 'border: 2px solid var(--color-border);';
          if (b.booking_status === 'booked') {
            statusBadge = '<span class="badge" style="background-color: #d1fae5; color: #065f46; font-size:0.8rem; font-weight:800; padding:4px 8px;">BOOKED</span>';
            cardStyle = 'border: 3px solid var(--color-success); background-color: #f0fdf4;';
          } else if (b.booking_status === 'checked_in') {
            statusBadge = '<span class="badge" style="background-color: #e0f2fe; color: #075985; font-size:0.8rem; font-weight:800; padding:4px 8px;">ACTIVE</span>';
            cardStyle = 'border: 3px solid var(--color-text-secondary); background-color: #eff6ff;';
          } else if (b.booking_status === 'checked_out') {
            statusBadge = '<span class="badge" style="background-color: #fee2e2; color: #991b1b; font-size:0.8rem; font-weight:800; padding:4px 8px;">OUT</span>';
            cardStyle = 'border: 3px solid #94a3b8; background-color: #f8fafc; opacity: 0.85;';
          }

          card.className = 'selection-card';
          card.style = cardStyle + ' display: flex; align-items: center; padding: 16px; margin-bottom: 12px; border-radius: var(--border-radius-md); box-shadow: var(--shadow-sm);';
          
          const guestName = b.guest_name || 'Walk-in Guest';
          const firstChar = escapeHtml(guestName.charAt(0).toUpperCase());
          const safeGuestName = escapeHtml(guestName);
          const safeRoomNumber = escapeHtml(b.room_number);

          let dueText = '';
          if (b.balance > 0) {
            dueText = `<div style="font-size:0.75rem; color:var(--color-danger); font-weight:800; margin-top:2px;">Due: ₹${Number(b.balance || 0).toFixed(2)}</div>`;
          } else {
            dueText = `<div style="font-size:0.75rem; color:var(--color-success); font-weight:800; margin-top:2px;">Paid in Full</div>`;
          }

          card.innerHTML = `
            <div class="selection-card-avatar">${firstChar}</div>
            <div class="selection-card-details" style="margin-left: 14px; flex: 1;">
              <div class="selection-card-title">${safeGuestName}</div>
              <div class="selection-card-subtitle">Room ${safeRoomNumber} • Folio: BKG-${b.id}</div>
              <div style="font-size:0.75rem; color:var(--color-text-secondary); margin-top:2px;">Stay: ${escapeHtml(b.display_check_in.split(',')[0])} - ${escapeHtml(b.display_check_out.split(',')[0])}</div>
              ${dueText}
            </div>
            <div style="margin-left:auto; display:flex; flex-direction:column; align-items:flex-end; gap:4px;">
              <strong style="font-size:1.1rem; color:var(--color-brand);">₹${b.total_amount}</strong>
              ${statusBadge}
            </div>
          `;
          
          card.onclick = () => {
            app.openBookingActionsSheet(b);
          };
          
          list.appendChild(card);
        });
      }
    } catch (e) {
      this.hideLoading();
      this.showToast(e.message || 'History list error', 'danger');
    }
  }

  // --- HOUSEKEEPING ROOM STATUS PAGE ---
  async loadHousekeepingRooms(isBackground = false) {
    if (!isBackground) this.showLoading('Fetching room statuses...');
    try {
      const res = await this.apiCall('api/rooms.php?action=all');
      if (!isBackground) this.hideLoading();

      const list = document.getElementById('rooms-status-list');
      if (!list) return;
      list.innerHTML = '';

      if (res && res.success && res.rooms) {
        // Sort rooms numerically
        const rooms = res.rooms.sort((a,b) => a.room_number.localeCompare(b.room_number, undefined, {numeric: true}));
        
        rooms.forEach(r => {
          const card = document.createElement('div');
          
          let statusLabel = 'Available';
          let badgeClass = 'badge-success';
          let showCleanButton = false;
          let cardStyle = 'border: 3px solid var(--color-success); background-color: #f0fdf4;';

          if (r.is_occupied) {
            statusLabel = 'Occupied';
            badgeClass = 'badge-danger';
            cardStyle = 'border: 3px solid var(--color-danger); background-color: #fef2f2;';
          }

          // Check if dirty
          if (r.room_state === 'dirty') {
            statusLabel = 'Dirty / Cleaning';
            badgeClass = 'badge-warning';
            showCleanButton = true;
            cardStyle = 'border: 3px solid var(--color-warning); background-color: #fffbeb;';
          } else if (r.room_state === 'out_of_order') {
            statusLabel = 'Out of Order';
            badgeClass = 'badge-danger';
            cardStyle = 'border: 3px solid #7f1d1d; background-color: #f3f4f6; opacity: 0.8;';
          }
          
          card.className = 'selection-card';
          card.style = cardStyle + ' display: flex; align-items: center; padding: 16px; margin-bottom: 12px; border-radius: var(--border-radius-md); box-shadow: var(--shadow-sm);';
          
          const safeRoomNumber = escapeHtml(r.room_number);
          let cleanBtnHtml = '';
          if (showCleanButton) {
            cleanBtnHtml = `<button class="btn-large btn-success" style="width:105px; min-height:45px; height:45px; font-size:0.8rem; padding:0 8px; margin-left:auto; z-index: 10;" onclick="app.markRoomClean(${r.id}, '${safeRoomNumber}')">MARK CLEAN</button>`;
          }

          card.innerHTML = `
            <div class="selection-card-avatar" style="border-radius:10px;"><i class="lucide-bed"></i></div>
            <div class="selection-card-details" style="margin-left: 14px; flex: 1;">
              <div class="selection-card-title">Room ${safeRoomNumber}</div>
              <div class="selection-card-subtitle">${escapeHtml(r.category_name)} • ${escapeHtml(r.floor)}</div>
              <div style="margin-top: 4px;"><span class="badge ${badgeClass}" style="font-size:0.8rem; font-weight:800; padding:4px 8px;">${escapeHtml(statusLabel.toUpperCase())}</span></div>
            </div>
            ${cleanBtnHtml}
          `;
          list.appendChild(card);
        });
      }
    } catch (e) {
      this.hideLoading();
      this.showToast(e.message || 'Rooms list error', 'danger');
    }
  }

  async markRoomClean(roomId, roomNumber) {
    this.showLoading('Marking room clean...');
    try {
      const res = await this.apiCall('/api/admin/room_action', {
        action: 'mark_clean',
        room_id: roomId
      });
      this.hideLoading();

      if (res && res.success) {
        this.showToast(`Room ${roomNumber} is clean & available!`, 'success');
        Voice.speak(`Room ${roomNumber} is clean.`);
        this.loadHousekeepingRooms();
        this.loadDashboardData();
      } else {
        this.showToast(res.message || 'Action failed', 'danger');
      }
    } catch (e) {
      this.hideLoading();
      this.showToast(e.message || 'Room clean status error', 'danger');
    }
  }

  // --- NOTIFICATIONS PAGE ---
  async loadNotificationsScreen() {
    this.showLoading('Loading alerts...');
    try {
      const res = await this.apiCall('api/dashboard.php');
      this.hideLoading();

      const list = document.getElementById('notifications-list');
      list.innerHTML = '';

      if (res && res.success && res.alerts) {
        if (res.alerts.length === 0) {
          list.innerHTML = `
            <div style="text-align:center; padding: 30px; color: var(--color-text-secondary); font-weight:700;">
              <i class="lucide-smile-plus" style="font-size:2.5rem; color:var(--color-brand); margin-bottom:8px; display:block;"></i>
              No pending task alerts. Nice job!
            </div>
          `;
          return;
        }

        res.alerts.forEach(alert => {
          const card = document.createElement('div');
          card.className = `alert-box alert-box-${alert.severity === 'danger' ? 'danger' : (alert.severity === 'warning' ? 'warning' : 'info')}`;
          card.onclick = () => this.handleAlertClick(alert);
          
          let icon = 'info';
          if (alert.type === 'dirty_room') icon = 'brush';
          else if (alert.type === 'today_arrival') icon = 'user-check';
          else if (alert.type === 'today_departure') icon = 'log-out';
          else if (alert.type === 'missing_id') icon = 'contact-2';
          else if (alert.type === 'pending_payment') icon = 'wallet-cards';
          else if (alert.type === 'overdue_checkout') icon = 'alarm-clock';
          else if (alert.type === 'upcoming_checkout') icon = 'clock';
          else if (alert.type === 'overdue_checkin') icon = 'alert-triangle';
          else if (alert.type === 'booking_hold') icon = 'hourglass';

          card.innerHTML = `
            <i class="lucide-${icon}" style="font-size:1.5rem;"></i>
            <div style="flex:1;">
              <strong style="display:block; font-size:0.95rem; margin-bottom:2px;">${alert.title}</strong>
              <span style="font-size:0.8rem; font-weight:600;">${alert.message}</span>
            </div>
            <i class="lucide-arrow-right-circle" style="font-size:1.4rem; margin-left:auto;"></i>
          `;
          list.appendChild(card);
        });
      }
    } catch (e) {
      this.hideLoading();
      this.showToast(e.message || 'Alerts error', 'danger');
    }
  }

  // --- PROFILE & PIN SETUP ---
  loadProfileScreen() {
    document.getElementById('profile-username').textContent = this.currentUser.username.toUpperCase();
    document.getElementById('profile-avatar').textContent = this.currentUser.username.substring(0,2).toUpperCase();
    document.getElementById('profile-role').textContent = this.currentUser.role;
    
    // Clear displays
    document.getElementById('profile-pin-display').textContent = 'Enter New PIN';
    document.getElementById('profile-pin-display').classList.add('empty');
    this.profileNewPin = '';
  }

  activateNumpadForProfilePin() {
    this.openNumpadPopup('profile_pin', 'Enter New 4-Digit Login PIN', '', (val) => {
      if (val.length !== 4) return;
      this.profileNewPin = val;
      document.getElementById('profile-pin-display').textContent = '● ● ● ●';
      document.getElementById('profile-pin-display').classList.remove('empty');
    });
  }

  async submitNewProfilePin() {
    if (!this.profileNewPin || this.profileNewPin.length !== 4) {
      this.showToast('PIN must be exactly 4 digits', 'warning');
      return;
    }

    this.showLoading('Saving PIN...');
    try {
      const res = await this.apiCall('api/auth.php?action=update_pin', {
        pin: this.profileNewPin
      });
      this.hideLoading();

      if (res && res.success) {
        this.showToast('Login PIN updated successfully!', 'success');
        Voice.speak('Login PIN updated successfully.');
        this.loadProfileScreen();
      } else {
        this.showToast(res.message || 'Fail to update PIN', 'danger');
      }
    } catch (e) {
      this.hideLoading();
      this.showToast(e.message || 'PIN update connection error', 'danger');
    }
  }

  async logout() {
    this.showLoading('Logging out...');
    try {
      await this.apiCall('api/auth.php?action=logout');
    } catch (e) {}
    
    this.hideLoading();
    this.currentUser = null;
    this.csrfToken = '';
    this.loadStaffListForLogin();
  }

  // --- REUSABLE NUMPAD SYSTEM POPUP ---
  openNumpadPopup(target, title, startVal = '', onConfirm) {
    this.activeNumpadTarget = target;
    this.numpadValue = startVal.toString();
    this.onNumpadConfirmCallback = onConfirm;

    document.getElementById('numpad-title').textContent = title;
    
    // Toggle presets for payment inputs to help low-literacy users
    const presetsEl = document.getElementById('numpad-quick-presets');
    if (presetsEl) {
      if (target === 'payment_settle') {
        presetsEl.style.display = 'grid';
      } else {
        presetsEl.style.display = 'none';
      }
    }

    this.updateNumpadDisplay();
    document.getElementById('numpad-popup').classList.add('active');
  }

  addNumpadPreset(val) {
    const current = parseFloat(this.numpadValue) || 0;
    this.numpadValue = String(current + val);
    this.updateNumpadDisplay();
  }

  closeNumpadPopup(event) {
    if (event && event.target !== event.currentTarget) return;
    document.getElementById('numpad-popup').classList.remove('active');
  }

  pressNumpadKey(key) {
    if (key === 'clear') {
      this.numpadValue = '';
    } else if (key === 'back') {
      this.numpadValue = this.numpadValue.slice(0, -1);
    } else {
      // Restrict lengths depending on targets
      const maxLen = (this.activeNumpadTarget === 'search_phone' || this.activeNumpadTarget === 'new_phone') ? 10 : (this.activeNumpadTarget === 'profile_pin' ? 4 : 12);
      if (this.numpadValue.length < maxLen) {
        this.numpadValue += key;
      }
    }
    this.updateNumpadDisplay();
  }

  updateNumpadDisplay() {
    const display = document.getElementById('numpad-native-input');
    if (!display) return;
    
    if (this.numpadValue.length === 0) {
      display.value = 'Enter Value';
      display.classList.add('empty');
    } else {
      display.value = this.numpadValue;
      display.classList.remove('empty');
    }
  }

  submitNumpadVal() {
    document.getElementById('numpad-popup').classList.remove('active');
    if (this.onNumpadConfirmCallback) {
      this.onNumpadConfirmCallback(this.numpadValue);
    }
  }

  // --- UTILS & SHELL WRAPPERS ---
  async apiCall(url, data = null) {
    // Resolve dynamic path relative to the assistant subfolder
    const loc = window.location.pathname;
    const assistantIndex = loc.indexOf('/assistant');
    const basePath = assistantIndex !== -1 ? loc.substring(0, assistantIndex) + '/assistant/' : '/assistant/';
    
    let cleanUrl = url;
    if (url.startsWith('./')) cleanUrl = url.substring(2);
    if (url.startsWith('/')) cleanUrl = url.substring(1);
    
    let finalUrl = cleanUrl;
    if (!url.startsWith('http://') && !url.startsWith('https://')) {
      if (url.startsWith('../api/')) {
        const pmsApiBase = assistantIndex !== -1 ? loc.substring(0, assistantIndex) + '/api/' : '/api/';
        finalUrl = pmsApiBase + url.substring(7);
      } else {
        let relUrl = cleanUrl;
        if (relUrl.startsWith('assistant/')) {
          relUrl = relUrl.substring(10);
        }
        finalUrl = basePath + relUrl;
      }
    }

    if (!data) {
      finalUrl += (finalUrl.includes('?') ? '&' : '?') + '_t=' + Date.now();
    }

    const options = {
      method: data ? 'POST' : 'GET',
      cache: 'no-store',
      credentials: 'same-origin',
      headers: {
        'Accept': 'application/json',
        'Cache-Control': 'no-cache',
        'Pragma': 'no-cache'
      }
    };

    if (data) {
      options.headers['Content-Type'] = 'application/json';
      if (this.csrfToken) {
        options.headers['X-CSRF-TOKEN'] = this.csrfToken;
      }
      options.body = JSON.stringify(data);
    }

    const response = await fetch(finalUrl, options);
    if (!response.ok) {
      if (response.status === 419 || response.status === 403) {
        this.logout();
        throw new Error('CSRF Session Expired');
      }
      let errorMsg = 'API server status failure';
      try {
        const errorData = await response.json();
        if (errorData && errorData.message) {
          errorMsg = errorData.message;
        }
      } catch (e) {}
      
      throw new Error(errorMsg);
    }
    return response.json();
  }

  showToast(message, type = 'info') {
    // Create quick notification alert
    const toast = document.createElement('div');
    toast.className = `alert-box alert-box-${type}`;
    toast.style.position = 'fixed';
    toast.style.top = '15px';
    toast.style.left = '15px';
    toast.style.right = '15px';
    toast.style.zIndex = '300';
    toast.style.boxShadow = '0 10px 20px rgba(0,0,0,0.15)';
    
    const iconMap = { success: '✅', danger: '❌', warning: '⚠️', info: 'ℹ️' };
    const icon = iconMap[type] || 'ℹ️';
    
    toast.innerHTML = `
      <span style="font-size:1.5rem;">${icon}</span>
      <div style="font-weight:800; font-size:0.85rem;">${message}</div>
    `;
    
    document.body.appendChild(toast);
    
    // Voice readout for all toasts
    Voice.speak(message);
    
    // Haptic feedback
    if (navigator.vibrate) navigator.vibrate(type === 'danger' ? [100, 50, 100] : 50);
    
    setTimeout(() => {
      toast.style.opacity = '0';
      toast.style.transition = 'opacity 300ms ease';
      setTimeout(() => toast.remove(), 300);
    }, 3000);
  }

  /**
   * Custom confirm dialog (replaces native confirm())
   * Returns a Promise that resolves to true/false
   */
  async pmsConfirm(message, title = 'Confirm') {
    return new Promise((resolve) => {
      const overlay = document.createElement('div');
      overlay.className = 'modal-overlay active';
      overlay.style.zIndex = '500';
      
      overlay.innerHTML = `
        <div style="margin: auto; max-width: 320px; width: 90%;">
          <div class="modal-content-box" style="border-radius: var(--border-radius-lg); text-align: center; padding: 28px;">
            <div style="font-size: 3rem; margin-bottom: 12px;">❓</div>
            <h3 style="font-weight: 800; font-size: 1.1rem; margin-bottom: 8px;">${title}</h3>
            <p style="color: var(--color-text-secondary); font-weight: 600; margin-bottom: 24px; font-size: 0.95rem;">${message}</p>
            <div style="display: flex; gap: 12px;">
              <button id="pms-confirm-no" class="btn-large btn-outline" style="flex: 1; min-height: 52px;">
                ❌ NO
              </button>
              <button id="pms-confirm-yes" class="btn-large btn-success" style="flex: 1; min-height: 52px;">
                ✅ YES
              </button>
            </div>
          </div>
        </div>
      `;
      
      document.body.appendChild(overlay);
      Voice.speak(message);
      if (navigator.vibrate) navigator.vibrate(50);
      
      overlay.querySelector('#pms-confirm-yes').onclick = () => {
        overlay.remove();
        resolve(true);
      };
      overlay.querySelector('#pms-confirm-no').onclick = () => {
        overlay.remove();
        resolve(false);
      };
      overlay.onclick = (e) => {
        if (e.target === overlay) {
          overlay.remove();
          resolve(false);
        }
      };
    });
  }

  /**
   * Custom alert dialog (replaces native alert())
   */
  async pmsAlert(message, title = 'Notice') {
    return new Promise((resolve) => {
      const overlay = document.createElement('div');
      overlay.className = 'modal-overlay active';
      overlay.style.zIndex = '500';
      
      overlay.innerHTML = `
        <div style="margin: auto; max-width: 320px; width: 90%;">
          <div class="modal-content-box" style="border-radius: var(--border-radius-lg); text-align: center; padding: 28px;">
            <div style="font-size: 3rem; margin-bottom: 12px;">ℹ️</div>
            <h3 style="font-weight: 800; font-size: 1.1rem; margin-bottom: 8px;">${title}</h3>
            <p style="color: var(--color-text-secondary); font-weight: 600; margin-bottom: 24px; font-size: 0.95rem;">${message}</p>
            <button id="pms-alert-ok" class="btn-large btn-brand" style="width: 100%; min-height: 52px;">
              ✅ OK
            </button>
          </div>
        </div>
      `;
      
      document.body.appendChild(overlay);
      Voice.speak(message);
      if (navigator.vibrate) navigator.vibrate(50);
      
      overlay.querySelector('#pms-alert-ok').onclick = () => {
        overlay.remove();
        resolve();
      };
      overlay.onclick = (e) => {
        if (e.target === overlay) {
          overlay.remove();
          resolve();
        }
      };
    });
  }

  showLoading(text = 'Processing...') {
    document.getElementById('loading-text').textContent = text;
    document.getElementById('loading-overlay').classList.add('active');
  }

  hideLoading() {
    document.getElementById('loading-overlay').classList.remove('active');
  }

  /**
   * Select payment mode in wizard step 10 (icon buttons)
   */
  selectWizardPaymentMode(el, mode) {
    // Update hidden input
    document.getElementById('wizard-payment-mode').value = mode;
    this.wizardData.payment_method = mode;
    
    // Update visual state
    document.querySelectorAll('#wizard-payment-modes .payment-mode-card').forEach(btn => {
      btn.classList.remove('active');
      btn.style.borderColor = 'var(--color-border)';
      btn.style.background = 'var(--color-glass)';
    });
    el.classList.add('active');
    el.style.borderColor = 'var(--color-brand)';
    el.style.background = 'var(--color-brand-light)';
    
    // Voice feedback
    const modeNames = { 'Cash': 'Cash', 'UPI': 'UPI', 'Card': 'Card', 'BankTransfer': 'Bank Transfer' };
    Voice.speak(`Payment mode: ${modeNames[mode] || mode}`);
    
    // Haptic feedback
    if (navigator.vibrate) navigator.vibrate(30);
  }

  applyPaymentMethodsToUI() {
    // 1. Update Step 10 payment mode icon buttons
    const container = document.getElementById('wizard-payment-modes');
    if (container && this.paymentMethods) {
      const icons = { 'Cash': '💵', 'UPI': '📱', 'Card': '💳', 'BankTransfer': '🏦', 'Online': '🌐' };
      const labels = { 'Cash': 'Cash', 'UPI': 'UPI / QR', 'Card': 'Card', 'BankTransfer': 'Bank', 'Online': 'Online' };
      
      container.innerHTML = '';
      this.paymentMethods.forEach((method, i) => {
        const icon = icons[method] || '💰';
        const label = labels[method] || method;
        const isActive = i === 0;
        
        const btn = document.createElement('button');
        btn.className = 'payment-mode-card' + (isActive ? ' active' : '');
        btn.setAttribute('data-mode', method);
        btn.onclick = () => this.selectWizardPaymentMode(btn, method);
        btn.style.cssText = `display:flex;align-items:center;gap:10px;padding:14px;border-radius:var(--border-radius-md);border:2px solid ${isActive ? 'var(--color-brand)' : 'var(--color-border)'};background:${isActive ? 'var(--color-brand-light)' : 'var(--color-glass)'};cursor:pointer;transition:all 150ms ease;`;
        btn.innerHTML = `<span style="font-size:1.8rem;">${icon}</span><span style="font-weight:700;font-size:0.95rem;">${label}</span>`;
        container.appendChild(btn);
      });
      
      // Set default value
      if (this.paymentMethods.length > 0) {
        document.getElementById('wizard-payment-mode').value = this.paymentMethods[0];
        this.wizardData.payment_method = this.paymentMethods[0];
      }
    }

    // 2. Dynamically generate payment pills in checkout details
    const pillContainer = document.querySelector('#payment-mode-selector > div');
    if (pillContainer && this.paymentMethods) {
      const icons = { 'Cash': '💵', 'UPI': '📱', 'Card': '💳', 'BankTransfer': '🏦', 'Online': '🌐' };
      pillContainer.innerHTML = '';
      this.paymentMethods.forEach((method, i) => {
        const icon = icons[method] || '💰';
        const methodKey = method.toLowerCase().replace(/[^a-z0-9]/g, '');
        
        const btn = document.createElement('button');
        btn.className = 'payment-mode-pill';
        btn.id = `pill-${methodKey}`;
        btn.setAttribute('data-method', methodKey);
        btn.onclick = () => this.selectPaymentModePill(btn, methodKey);
        btn.innerHTML = `${icon} ${method}`;
        pillContainer.appendChild(btn);
      });
    }

    const methodsLower = this.paymentMethods.map(m => m.toLowerCase().replace(/[^a-z0-9]/g, ''));

    // Reset default active pill
    document.querySelectorAll('.payment-mode-pill').forEach(p => p.classList.remove('active'));
    const defaultPillName = methodsLower[0] || 'cash';
    const defaultPill = document.getElementById(`pill-${defaultPillName}`);
    if (defaultPill) {
      this.selectPaymentModePill(defaultPill, defaultPillName);
    }
  }

  // ═══════════════════════════════════════════════════════
  // TUTORIAL SYSTEM
  // ═══════════════════════════════════════════════════════
  
  showTutorial() {
    this.tutorialStep = 0;
    const overlay = document.getElementById('tutorial-overlay');
    if (!overlay) return;
    
    overlay.style.display = 'block';
    this._renderTutorialStep();
    Voice.speak(TUTORIAL_STEPS[0].voice);
  }

  _renderTutorialStep() {
    const step = TUTORIAL_STEPS[this.tutorialStep];
    if (!step) return;
    
    document.getElementById('tutorial-icon').textContent = step.icon;
    document.getElementById('tutorial-title').textContent = step.title;
    document.getElementById('tutorial-desc').textContent = step.desc;
    
    // Update dots
    const dots = document.querySelectorAll('.tutorial-dot');
    dots.forEach((dot, i) => {
      dot.style.background = i === this.tutorialStep ? 'white' : 'rgba(255,255,255,0.3)';
    });
    
    // Update button text
    const nextBtn = document.getElementById('tutorial-next');
    if (this.tutorialStep === TUTORIAL_STEPS.length - 1) {
      nextBtn.textContent = 'START! 🚀';
    } else {
      nextBtn.textContent = 'NEXT ➡️';
    }
  }

  nextTutorialStep() {
    this.tutorialStep++;
    
    if (this.tutorialStep >= TUTORIAL_STEPS.length) {
      this.closeTutorial(true);
      return;
    }
    
    this._renderTutorialStep();
    Voice.speak(TUTORIAL_STEPS[this.tutorialStep].voice);
    
    // Haptic feedback
    if (navigator.vibrate) navigator.vibrate(30);
  }

  closeTutorial(markSeen = false) {
    const overlay = document.getElementById('tutorial-overlay');
    if (overlay) overlay.style.display = 'none';
    
    if (markSeen) {
      this.tutorialSeen = true;
      localStorage.setItem('pms_tutorial_seen', 'true');
    }
    
    Voice.speak('You are ready! Tap the bell icon to start.');
  }
}

// Helper date parsing formats
function dateToMysqlString(date) {
  const y = date.getFullYear();
  const m = String(date.getMonth() + 1).padStart(2, '0');
  const d = String(date.getDate()).padStart(2, '0');
  const h = String(date.getHours()).padStart(2, '0');
  return `${y}-${m}-${d} ${h}:00:00`;
}

function dateToDateInputString(date) {
  const y = date.getFullYear();
  const m = String(date.getMonth() + 1).padStart(2, '0');
  const d = String(date.getDate()).padStart(2, '0');
  return `${y}-${m}-${d}`;
}

function formatNiceDate(mysqlStr) {
  if (!mysqlStr) return '';
  const date = new Date(mysqlStr.replace(' ', 'T'));
  const options = { day: 'numeric', month: 'short', hour: '2-digit', minute: '2-digit', hour12: true };
  return date.toLocaleDateString('en-IN', options);
}

// HTML escape helper to prevent XSS
function escapeHtml(str) {
  if (!str) return '';
  const div = document.createElement('div');
  div.textContent = str;
  return div.innerHTML;
}

// Global functions linked to standard scripts
function PhoneHelperFormat(phone) {
  if (!phone) return '';
  const clean = phone.replace(/[^0-9]/g, '');
  if (clean.length === 10) {
    return `+91 ${clean.substring(0, 5)} ${clean.substring(5)}`;
  }
  return phone;
}

function PhoneHelperToE164(phone) {
  const clean = phone.replace(/[^0-9]/g, '');
  if (clean.length === 10) {
    return '91' + clean;
  }
  return clean;
}

// Boot instance
window.addEventListener('DOMContentLoaded', () => {
  window.app = new BookingAssistant();
  
  // Custom screen route binds for nav status
  document.getElementById('nav-btn-home').onclick = () => app.showScreen('dashboard');
  document.getElementById('nav-btn-bookings').onclick = () => {
    app.historySearch = '';
    const searchInput = document.getElementById('history-search-input');
    if (searchInput) searchInput.value = '';
    app.showScreen('history');
  };
  document.getElementById('nav-btn-notifications').onclick = () => app.showScreen('notifications');
  document.getElementById('nav-btn-profile').onclick = () => app.showScreen('profile');

  // Initialize Voice Commands (always-on)
  if (window.VoiceCommands) {
    VoiceCommands.onCommand = (action, captured, transcript) => {
      console.log('[App] Voice command:', action, captured);
      
      switch (action) {
        case 'new_booking':
          app.startNewBookingWizard();
          Voice.speak('Starting new booking.');
          break;
          
        case 'show_bookings':
          app.showScreen('history');
          Voice.speak('Showing booking history.');
          break;
          
        case 'check_in':
          app.showCheckInScreen();
          Voice.speak('Showing arrivals.');
          break;
          
        case 'check_out':
          app.showCheckOutScreen();
          Voice.speak('Showing departures.');
          break;
          
        case 'housekeeping':
          app.showScreen('housekeeping');
          Voice.speak('Showing room status.');
          break;
          
        case 'alerts':
          app.showScreen('notifications');
          Voice.speak('Showing alerts.');
          break;
          
        case 'home':
        case 'dashboard':
          app.showScreen('dashboard');
          Voice.speak('Going to dashboard.');
          break;
          
        case 'profile':
          app.showScreen('profile');
          Voice.speak('Opening profile.');
          break;
          
        case 'mark_clean':
          if (captured) {
            // Find room by number and mark clean
            const roomNum = captured;
            if (app.housekeepingData && app.housekeepingData.dirty) {
              const room = app.housekeepingData.dirty.find(r => r.room_number == roomNum);
              if (room) {
                app.markRoomClean(room.id);
                Voice.speak(`Marking room ${roomNum} as clean.`);
              } else {
                Voice.speak(`Room ${roomNum} is not dirty.`);
              }
            }
          }
          break;
          
        case 'available_rooms':
          const avail = document.getElementById('stat-available')?.textContent || '0';
          Voice.speak(`There are ${avail} rooms available.`);
          break;
          
        case 'occupied_rooms':
          const occ = document.getElementById('stat-occupied')?.textContent || '0';
          Voice.speak(`${occ} rooms are occupied.`);
          break;
          
        case 'pending_payments':
          const pay = document.getElementById('stat-pending-payments')?.textContent || '0';
          Voice.speak(`${pay} pending payments.`);
          break;
          
        case 'today_arrivals':
          const arr = document.getElementById('stat-arrivals')?.textContent || '0';
          Voice.speak(`${arr} arrivals today.`);
          break;
          
        case 'today_departures':
          const dep = document.getElementById('stat-departures')?.textContent || '0';
          Voice.speak(`${dep} departures today.`);
          break;
          
        case 'room_number':
          if (captured) {
            Voice.speak(`Room ${captured}.`);
          }
          break;
          
        case 'help':
          Voice.speak('You can say: new booking, check in, check out, dirty rooms, available rooms, pending payments, alerts, or room number followed by clean.');
          break;
      }
    };
    
    // Start listening after login
    const originalShowScreen = app.showScreen.bind(app);
    app.showScreen = function(screenId) {
      originalShowScreen(screenId);
      
      // Voice commands can be started on-demand via mic buttons rather than continuous background capture
      /*
      if (screenId === 'dashboard' && !VoiceCommands.isListening) {
        setTimeout(() => {
          VoiceCommands.start();
          console.log('[App] Voice commands started');
        }, 1000);
      }
      */
    };
  }
});
