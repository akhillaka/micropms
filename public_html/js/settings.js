// CSS.escape polyfill for older browsers
if (!CSS.escape) {
    CSS.escape = function(value) {
        return value.replace(/([^\w-])/g, '\\$1');
    };
}

let currentTab = 'categories';
const tabs = ['categories', 'rooms', 'rates', 'integrations', 'payments', 'staff', 'roles', 'property', 'folio-items', 'sequences', 'night-audit', 'subscription', 'guest-portal', 'housekeeping'];

function getHeaders() {
    return {
        'Content-Type': 'application/json',
        'X-CSRF-TOKEN': document.querySelector('meta[name="csrf-token"]')?.getAttribute('content') || ''
    };
}

function switchTab(tabId) {
    currentTab = tabId;
    tabs.forEach(t => {
        const content = document.getElementById('content-' + t);
        const tab = document.getElementById('tab-' + t);
        if (content) {
            content.style.display = 'none';
            content.classList.add('hidden');
        }
        if (tab) tab.className = 'settings-tab-btn tab-inactive';
    });
    const content = document.getElementById('content-' + tabId);
    const tab = document.getElementById('tab-' + tabId);
    if (content) {
        content.style.display = '';
        content.classList.remove('hidden');
    }
    if (tab) tab.className = 'settings-tab-btn tab-active';

    const fabBtn = document.getElementById('fabBtn');
    if (fabBtn) {
        if (tabId === 'integrations' || tabId === 'staff' || tabId === 'property' || tabId === 'night-audit') {
            fabBtn.style.display = 'none';
        } else {
            fabBtn.style.display = '';
        }
    }
}

function handleFabClick() {
    if (currentTab === 'categories') {
        openModal('catModal', null);
    } else if (currentTab === 'rooms') {
        openModal('roomModal', null);
    } else if (currentTab === 'rates') {
        // Open bulk rate modal for first category
        if (typeof openBulkRateModal === 'function' && typeof SETTINGS_DATA !== 'undefined') {
            openBulkRateModal({
                cat_id: SETTINGS_DATA.firstCategoryId,
                cat_name: SETTINGS_DATA.firstCategoryName,
                rates: { 'Base Rate': {} }
            });
        }
    } else if (currentTab === 'integrations') {
        document.getElementById('integrationsForm').scrollIntoView();
    }
}

function openModal(modalId, data = null) {
    const modal = document.getElementById(modalId);
    const overlay = document.getElementById('modalOverlay');

    modal.querySelector('form').reset();

    // Check if data has an actual ID (edit mode) vs null/empty (create mode)
    if(data && data.id) {
        if(modalId === 'catModal') {
            document.getElementById('catModalTitle').innerText = 'Edit Category';
            document.getElementById('cat_id').value = data.id;
            document.getElementById('cat_name').value = data.name || '';
        } else if(modalId === 'roomModal') {
            document.getElementById('roomModalTitle').innerText = 'Edit Room';
            document.getElementById('room_id').value = data.id;
            document.getElementById('room_number').value = data.num || '';
            document.getElementById('room_category_id').value = data.cat || '';
        } else if(modalId === 'rateModal') {
            document.getElementById('rateModalTitle').innerText = 'Edit Hourly Rate';
            document.getElementById('rate_category_id').value = data.cat || '';
            document.getElementById('rate_hours').value = data.hours || '';
            document.getElementById('rate_price').value = data.price || '';
            document.getElementById('rate_plan_name').value = data.name || '';
        }
    } else {
        if(modalId === 'catModal') {
            document.getElementById('catModalTitle').innerText = 'Add Category';
            document.getElementById('cat_id').value = '';
        } else if(modalId === 'roomModal') {
            document.getElementById('roomModalTitle').innerText = 'Add Room';
            document.getElementById('room_id').value = '';
        } else if(modalId === 'rateModal') {
            document.getElementById('rateModalTitle').innerText = 'Add Hourly Rate';
        }
    }

    overlay.classList.remove('hidden');
    void overlay.offsetWidth;
    overlay.classList.remove('opacity-0');
    modal.classList.remove('translate-y-full');
}

let currentBulkData = null;

function openBulkRateModal(data) {
    currentBulkData = data;
    document.getElementById('rate_category_id').value = data.cat_id;
    document.getElementById('rateModalSubtitle').innerText = data.cat_name;
    
    renderBulkRateTable();
    
    const modal = document.getElementById('rateModal');
    const overlay = document.getElementById('modalOverlay');
    overlay.classList.remove('hidden');
    void overlay.offsetWidth;
    overlay.classList.remove('opacity-0');
    modal.classList.remove('translate-y-full');
}

function renderBulkRateTable() {
    const container = document.getElementById('bulk_rate_table_container');
    const rates = currentBulkData.rates;
    const plans = Object.keys(rates);
    
    let tableHtml = `
        <div class="overflow-x-auto w-full border border-slate-200/60 rounded-xl">
            <table class="w-full text-xs font-semibold text-slate-800" style="min-width: 400px;">
                <thead class="bg-slate-50 border-b border-slate-200 text-[10px] font-bold text-slate-400 uppercase tracking-wider text-center">
                    <tr>
                        <th class="p-2 border-r border-slate-200 text-center w-16">Hour</th>
                        ${plans.map(p => {
                            const escapedP = p.replace(/'/g, "\\'");
                            return `
                            <th class="p-2 border-r border-slate-200 text-center relative group min-w-[120px]">
                                <div class="flex items-center justify-center gap-1">
                                    <span class="text-slate-700 plan-name-display">${p}</span>
                                    <button type="button" onclick="renameBulkPlanColumn('${escapedP}')" class="opacity-0 group-hover:opacity-100 transition-opacity p-0.5 rounded hover:bg-slate-200" title="Rename">
                                        <i class="ph ph-pencil-simple text-[10px] text-slate-500"></i>
                                    </button>
                                </div>
                                <button type="button" onclick="deleteBulkPlanColumn('${escapedP}')" class="absolute -top-1 -right-1 hidden group-hover:flex w-4 h-4 rounded-full bg-rose-500 text-white items-center justify-center cursor-pointer shadow"><i class="ph ph-x text-[8px]"></i></button>
                            </th>
                        `}).join('')}
                    </tr>
                </thead>
                <tbody>
    `;
    
    for (let h = 1; h <= 24; h++) {
        tableHtml += `
            <tr class="border-b border-slate-100 hover:bg-slate-50/50">
                <td class="p-2 border-r border-slate-200 text-center font-bold bg-slate-50/50">${h}h</td>
                ${plans.map(p => {
                    const price = rates[p][h] !== undefined ? rates[p][h] : '';
                    // Use data attribute instead of CSS.escape for input names
                    return `
                        <td class="p-1.5 border-r border-slate-200">
                            <input type="number" data-plan="${p}" data-hour="${h}" value="${price}" step="0.01" min="0" placeholder="—" class="w-full bg-transparent text-center font-extrabold focus:outline-none focus:bg-white text-slate-800 p-1 rate-input">
                        </td>
                    `;
                }).join('')}
            </tr>
        `;
    }
    
    tableHtml += `
                </tbody>
            </table>
        </div>
    `;
    
    container.innerHTML = tableHtml;
}

function addBulkPlanColumn() {
    const input = document.getElementById('new_plan_name');
    const planName = input.value.trim();
    if (!planName) {
        showToast('Please enter a rate plan name', 'error');
        return;
    }
    
    if (currentBulkData.rates[planName]) {
        showToast('Plan name already exists', 'error');
        return;
    }
    
    currentBulkData.rates[planName] = Array(25).fill('');
    input.value = '';
    renderBulkRateTable();
}

function renameBulkPlanColumn(oldName) {
    const newName = prompt(`Rename rate plan "${oldName}" to:`, oldName);
    if (!newName || newName.trim() === '' || newName.trim() === oldName) return;
    
    const trimmedName = newName.trim();
    
    // Check if new name already exists
    if (currentBulkData.rates[trimmedName]) {
        showToast('A plan with that name already exists', 'error');
        return;
    }
    
    // Rename: copy data to new key, delete old key
    currentBulkData.rates[trimmedName] = currentBulkData.rates[oldName];
    delete currentBulkData.rates[oldName];
    
    renderBulkRateTable();
    showToast(`Renamed to "${trimmedName}"`, 'success');
}

async function deleteBulkPlanColumn(planName) {
    // Prevent deleting if it's the only plan
    if (Object.keys(currentBulkData.rates).length <= 1) {
        showToast('Cannot delete the last rate plan', 'error');
        return;
    }
    if (!await pmsConfirm(`Delete rate plan "${planName}"? This will clear its prices.`)) return;
    delete currentBulkData.rates[planName];
    renderBulkRateTable();
}

async function submitBulkRates(e, form) {
    e.preventDefault();
    const btn = form.querySelector('button[type="submit"]');
    const originalText = btn.innerText;
    btn.innerText = 'Saving...';
    btn.classList.add('opacity-75');
    btn.disabled = true;
    
    const categoryId = document.getElementById('rate_category_id').value;
    const ratesPayload = {};
    
    const plans = Object.keys(currentBulkData.rates);
    plans.forEach(p => {
        ratesPayload[p] = {};
        for(let h=1; h<=24; h++) {
            // Use data attributes instead of CSS.escape for reliable matching
            const el = form.querySelector(`input.rate-input[data-plan="${p}"][data-hour="${h}"]`);
            ratesPayload[p][h] = el ? el.value.trim() : '';
        }
    });
    
    const csrfToken = document.querySelector('meta[name="csrf-token"]')?.getAttribute('content') || '';
    
    try {
        const res = await fetch('/api/admin/settings', {
            method: 'POST',
            credentials: 'same-origin',
            headers: {
                'Content-Type': 'application/json',
                'X-CSRF-Token': csrfToken
            },
            body: JSON.stringify({
                action: 'save_bulk_rates',
                category_id: categoryId,
                rates: ratesPayload,
                _csrf_token: csrfToken
            })
        });
        const result = await res.json();
        if (result.success) {
            closeModals();
            showToast('All rates saved successfully!', 'success');
            setTimeout(() => location.reload(), 300);
        } else {
            showToast(result.message || 'Failed to save rates', 'error');
            btn.innerText = originalText;
            btn.classList.remove('opacity-75');
            btn.disabled = false;
        }
    } catch (err) {
        showToast('Connection error', 'error');
        btn.innerText = originalText;
        btn.classList.remove('opacity-75');
        btn.disabled = false;
    }
}

function closeModals() {
    const overlay = document.getElementById('modalOverlay');
    overlay.classList.add('opacity-0');

    ['catModal', 'roomModal', 'rateModal', 'staffModal', 'roleModal'].forEach(id => {
        document.getElementById(id).classList.add('translate-y-full');
    });

    setTimeout(() => {
        overlay.classList.add('hidden');
    }, 300);
}

async function deleteItem(type, id, name, rateName = null) {
    let message = '';
    let payload = { 
        action: '',
        _csrf_token: document.querySelector('meta[name="csrf-token"]')?.getAttribute('content') || ''
    };

    if (type === 'category') {
        message = `Delete category "${name}"? This cannot be undone.`;
        payload.action = 'delete_category';
        payload.cat_id = id;
    } else if (type === 'room') {
        message = `Delete room "${name}"? This cannot be undone.`;
        payload.action = 'delete_room';
        payload.room_id = id;
    } else if (type === 'rate') {
        message = `Delete rate plan "${name}"? This cannot be undone.`;
        payload.action = 'delete_rate';
        payload.category_id = id;
        payload.rate_plan_name = rateName;
    }

    if (!await pmsConfirm(message)) return;

    try {
        const res = await fetch('/api/admin/settings', {
            method: 'POST',
            credentials: 'same-origin',
            headers: getHeaders(),
            body: JSON.stringify(payload)
        });
        const result = await res.json();

        if (result.success) {
            location.reload();
        } else {
            showToast(result.message || 'Delete failed');
        }
    } catch(err) {
        showToast('Connection error');
    }
}

async function submitForm(e, form, modalId) {
    e.preventDefault();
    const btn = form.querySelector('button[type="submit"]');
    const originalText = btn.innerText;
    btn.innerText = 'Saving...';
    btn.classList.add('opacity-75');
    btn.disabled = true;

    const formData = new FormData(form);
    const data = {};
    for (let [key, val] of formData.entries()) {
        if (key.startsWith('prices[')) {
            if (!data.prices) data.prices = {};
            let match = key.match(/\[(\d+)\]/);
            if (match) {
                data.prices[match[1]] = val;
            }
        } else {
            data[key] = val;
        }
    }
    data['_csrf_token'] = document.querySelector('meta[name="csrf-token"]')?.getAttribute('content') || '';

    try {
        const res = await fetch('/api/admin/settings', {
            method: 'POST',
            credentials: 'same-origin',
            headers: getHeaders(),
            body: JSON.stringify(data)
        });
        const result = await res.json();

        if (result.success) {
            closeModals();
            setTimeout(() => location.reload(), 300);
        } else {
            showToast(result.message);
            btn.innerText = originalText;
            btn.classList.remove('opacity-75');
            btn.disabled = false;
        }
    } catch(err) {
        showToast("Request failed");
        btn.innerText = originalText;
        btn.classList.remove('opacity-75');
        btn.disabled = false;
    }
}

async function submitIntegrations(e) {
    e.preventDefault();
    const btn = document.getElementById('saveIntegrationBtn');
    const originalText = btn.innerHTML;
    btn.innerHTML = 'Saving...';
    btn.classList.add('opacity-75');
    btn.disabled = true;

    const form = document.getElementById('integrationsForm');
    const data = {};
    
    // Collect all form fields via FormData (hidden input NOTIFY_EVENTS is included)
    new FormData(form).forEach((val, key) => {
        data[key] = val;
    });
    data['_csrf_token'] = document.querySelector('meta[name="csrf-token"]')?.getAttribute('content') || '';

    try {
        const res = await fetch('/api/admin/save_settings', {
            method: 'POST',
            credentials: 'same-origin',
            headers: getHeaders(),
            body: JSON.stringify(data)
        });
        const result = await res.json();

        if (result.success) {
            showToast('Integrations saved successfully!');
            location.reload();
        } else {
            showToast(result.message || 'Error saving settings');
        }
    } catch (err) {
        showToast('Connection error');
    } finally {
        btn.innerHTML = originalText;
        btn.classList.remove('opacity-75');
        btn.disabled = false;
    }
}

function syncNotifyEvents() {
    const events = {};
    document.querySelectorAll('#notify-events-wrap input[type="checkbox"][data-event]').forEach(cb => {
        events[cb.dataset.event] = cb.checked;
    });
    document.getElementById('notify_events_json').value = JSON.stringify(events);
}

async function testTelegram() {
    const btn = document.getElementById('tg-test-btn');
    const orig = btn.innerHTML;
    btn.innerHTML = '<i class="ph ph-spinner animate-spin"></i> Sending...';
    btn.disabled = true;

    try {
        const res = await fetch('/api/admin/test_telegram', {
            method: 'POST',
            credentials: 'same-origin',
            headers: getHeaders(),
            body: JSON.stringify({
                _csrf_token: document.querySelector('meta[name="csrf-token"]')?.getAttribute('content') || ''
            })
        });
        const data = await res.json();
        if (data.ok) {
            btn.innerHTML = '<i class="ph ph-check-circle"></i> Sent!';
            btn.classList.remove('bg-sky-50', 'text-sky-600');
            btn.classList.add('bg-green-50', 'text-green-600');
        } else {
            btn.innerHTML = '<i class="ph ph-x-circle"></i> Failed';
            btn.classList.remove('bg-sky-50', 'text-sky-600');
            btn.classList.add('bg-red-50', 'text-red-600');
            showToast('Test failed: ' + (data.error || 'Unknown error'));
        }
    } catch(e) {
        btn.innerHTML = '<i class="ph ph-x-circle"></i> Error';
        showToast('Connection error');
    }

    setTimeout(() => {
        btn.innerHTML = orig;
        btn.disabled = false;
        btn.classList.remove('bg-green-50', 'text-green-600', 'bg-red-50', 'text-red-600');
        btn.classList.add('bg-sky-50', 'text-sky-600');
    }, 3000);
}

function addPaymentMethodRow() {
    const list = document.getElementById('pmList');
    const div = document.createElement('div');
    div.className = 'flex items-center gap-2';
    div.innerHTML = `
        <input type="text" name="payment_methods[]" required class="flex-1 bg-gray-50 border border-gray-200 p-3 rounded-xl text-sm outline-none focus:border-blue-500 font-bold text-gray-900">
        <button type="button" onclick="this.parentElement.remove()" class="w-11 h-11 flex-shrink-0 bg-rose-50 text-rose-600 rounded-xl flex items-center justify-center hover:bg-rose-100"><i class="ph ph-trash text-lg"></i></button>
    `;
    list.appendChild(div);
}

async function submitPaymentMethods(e) {
    e.preventDefault();
    const btn = document.getElementById('savePmBtn');
    const originalText = btn.innerHTML;
    btn.innerHTML = 'Saving...';
    btn.disabled = true;
    btn.classList.add('opacity-75');

    const form = document.getElementById('paymentMethodsForm');
    const formData = new FormData(form);
    const methods = formData.getAll('payment_methods[]');

    if(methods.length === 0) {
        showToast('You must have at least one payment method.');
        btn.innerHTML = originalText;
        btn.disabled = false;
        btn.classList.remove('opacity-75');
        return;
    }

    try {
        const payloadData = {
            payment_methods: JSON.stringify(methods),
            _csrf_token: document.querySelector('meta[name="csrf-token"]')?.getAttribute('content') || ''
        };
        const res = await fetch('/api/admin/save_settings', {
            method: 'POST',
            credentials: 'same-origin',
            headers: getHeaders(),
            body: JSON.stringify(payloadData)
        });
        const result = await res.json();
        if(result.success) {
            showToast('Payment methods saved!');
            location.reload();
        } else showToast(result.message);
    } catch(e) {
        showToast('Connection error');
    }
    btn.innerHTML = originalText;
    btn.disabled = false;
    btn.classList.remove('opacity-75');
}

function openStaffModal() {
    document.getElementById('staffModalTitle').textContent = 'Add Staff User';
    document.getElementById('staffSubmitBtn').textContent = 'Add User';
    document.getElementById('staff_user_id').value = '';
    document.getElementById('staff_username').value = '';
    document.getElementById('staff_password').value = '';
    document.getElementById('staff_password').required = true;
    document.getElementById('staff_password').classList.remove('hidden');
    document.getElementById('staff_password_label').textContent = 'Password';
    document.getElementById('staff_password_hint').classList.add('hidden');
    
    document.getElementById('staff_pin').value = '';
    document.getElementById('staff_pin_label').textContent = '4-Digit PIN (Assistant)';
    document.getElementById('staff_pin_hint').classList.add('hidden');

    document.getElementById('staff_access_level').value = 'manager';
    const statusSelect = document.getElementById('staff_is_active');
    if (statusSelect) statusSelect.value = '1';
    openModal('staffModal');
}

function editStaff(id, username, accessLevel, isActive = 1) {
    document.getElementById('staffModalTitle').textContent = 'Edit Staff User';
    document.getElementById('staffSubmitBtn').textContent = 'Save Changes';
    document.getElementById('staff_user_id').value = id;
    document.getElementById('staff_username').value = username;
    document.getElementById('staff_password').value = '';
    document.getElementById('staff_password').required = false;
    document.getElementById('staff_password_label').textContent = 'New Password (optional)';
    document.getElementById('staff_password_hint').classList.remove('hidden');

    document.getElementById('staff_pin').value = '';
    document.getElementById('staff_pin_label').textContent = 'New 4-Digit PIN (optional)';
    document.getElementById('staff_pin_hint').classList.remove('hidden');

    document.getElementById('staff_access_level').value = accessLevel;
    const statusSelect = document.getElementById('staff_is_active');
    if (statusSelect) statusSelect.value = isActive;
    const modal = document.getElementById('staffModal');
    const overlay = document.getElementById('modalOverlay');
    overlay.classList.remove('hidden');
    void overlay.offsetWidth;
    overlay.classList.remove('opacity-0');
    modal.classList.remove('translate-y-full');
}

async function submitStaff(e) {
    e.preventDefault();
    const userId = document.getElementById('staff_user_id').value;
    const isEdit = userId !== '';
    const payload = {
        action: isEdit ? 'edit' : 'add',
        user_id: isEdit ? userId : undefined,
        username: document.getElementById('staff_username').value.trim(),
        password: document.getElementById('staff_password').value,
        pin: document.getElementById('staff_pin').value,
        access_level: document.getElementById('staff_access_level').value,
        role: document.getElementById('staff_access_level').value,
        is_active: document.getElementById('staff_is_active') ? parseInt(document.getElementById('staff_is_active').value) : 1,
        _csrf_token: document.querySelector('meta[name="csrf-token"]')?.getAttribute('content') || ''
    };
    if (!payload.username) return showToast('Username is required');
    if (!isEdit && !payload.password) return showToast('Password is required');
    if (payload.password && payload.password.length < 6) return showToast('Password must be at least 6 characters');
    try {
        const res = await fetch('/api/admin/manage_staff', {
            method: 'POST',
            credentials: 'same-origin',
            headers: getHeaders(),
            body: JSON.stringify(payload)
        });
        const data = await res.json();
        if (data.success) location.reload();
        else showToast(data.message);
    } catch(e) { showToast('Request failed'); }
}

async function deleteStaff(id, username) {
    if (!await pmsConfirm(`Delete user "${username}"? This cannot be undone.`)) return;
    try {
        const res = await fetch('/api/admin/manage_staff', {
            method: 'POST',
            credentials: 'same-origin',
            headers: getHeaders(),
            body: JSON.stringify({ 
                action: 'delete', 
                user_id: id,
                _csrf_token: document.querySelector('meta[name="csrf-token"]')?.getAttribute('content') || ''
            })
        });
        const data = await res.json();
        if (data.success) location.reload();
        else showToast(data.message);
    } catch(e) { showToast('Request failed'); }
}

async function sendDailySummary() {
    const btn = document.getElementById('daily-summary-btn');
    const orig = btn.innerHTML;
    btn.innerHTML = '<i class="ph ph-spinner animate-spin"></i> Sending...';
    btn.disabled = true;

    try {
        const res = await fetch('/api/admin/daily_summary', {
            method: 'POST',
            credentials: 'same-origin',
            headers: getHeaders(),
            body: JSON.stringify({
                _csrf_token: document.querySelector('meta[name="csrf-token"]')?.getAttribute('content') || ''
            })
        });
        const data = await res.json();
        if (data.success) {
            btn.innerHTML = '<i class="ph ph-check-circle"></i> Sent!';
            btn.classList.remove('bg-sky-600');
            btn.classList.add('bg-green-600');
        } else {
            btn.innerHTML = '<i class="ph ph-x-circle"></i> Failed';
            btn.classList.remove('bg-sky-600');
            btn.classList.add('bg-red-600');
        }
    } catch(e) {
        btn.innerHTML = '<i class="ph ph-x-circle"></i> Error';
        btn.classList.remove('bg-sky-600');
        btn.classList.add('bg-red-600');
    }

    setTimeout(() => {
        btn.innerHTML = orig;
        btn.disabled = false;
        btn.classList.remove('bg-green-600', 'bg-red-600');
        btn.classList.add('bg-sky-600');
    }, 3000);
}

async function submitPropertySettings(e) {
    e.preventDefault();
    const btn = document.getElementById('savePropertyBtn');
    const originalText = btn.innerHTML;
    btn.innerHTML = 'Saving...';
    btn.classList.add('opacity-75');
    btn.disabled = true;

    const form = document.getElementById('propertyForm');
    const data = {};
    
    new FormData(form).forEach((val, key) => {
        data[key] = val;
    });
    data['_csrf_token'] = document.querySelector('meta[name="csrf-token"]')?.getAttribute('content') || '';

    try {
        const res = await fetch('/api/admin/save_settings', {
            method: 'POST',
            credentials: 'same-origin',
            headers: getHeaders(),
            body: JSON.stringify(data)
        });
        const result = await res.json();

        if (result.success) {
            showToast('Property settings saved successfully!');
            location.reload();
        } else {
            showToast(result.message || 'Error saving settings');
        }
    } catch (err) {
        showToast('Connection error');
    } finally {
        btn.innerHTML = originalText;
        btn.classList.remove('opacity-75');
        btn.disabled = false;
    }
}

let cropperInstance = null;

function convertLogoToBase64() {
    const file = document.getElementById('property_logo_file').files[0];
    if (!file) return;
    
    // Validate size (max 1.5MB)
    if (file.size > 1.5 * 1024 * 1024) {
        showToast('Logo image file is too large. Please select an image under 1.5MB.', 'error');
        document.getElementById('property_logo_file').value = '';
        return;
    }
    
    const reader = new FileReader();
    reader.onloadend = function() {
        const dataUrl = reader.result;
        
        // Show Crop Modal
        const cropImage = document.getElementById('cropImage');
        cropImage.src = dataUrl;
        
        const cropModal = document.getElementById('cropModal');
        cropModal.classList.remove('hidden');
        // trigger reflow
        void cropModal.offsetWidth;
        cropModal.classList.remove('opacity-0');
        cropModal.querySelector('.bg-white').classList.remove('scale-95');
        
        // Initialize Cropper after modal is visible
        if (cropperInstance) {
            cropperInstance.destroy();
        }
        
        setTimeout(() => {
            cropperInstance = new Cropper(cropImage, {
                viewMode: 1,
                dragMode: 'crop',
                autoCropArea: 0.8,
                aspectRatio: NaN,
                restore: false,
                modal: true,
                guides: true,
                highlight: false,
                cropBoxMovable: true,
                cropBoxResizable: true,
                toggleDragModeOnDblclick: false,
            });
        }, 100);
    };
    reader.readAsDataURL(file);
}

function closeCropModal() {
    const cropModal = document.getElementById('cropModal');
    cropModal.classList.add('opacity-0');
    cropModal.querySelector('.bg-white').classList.add('scale-95');
    setTimeout(() => {
        cropModal.classList.add('hidden');
        if (cropperInstance) {
            cropperInstance.destroy();
            cropperInstance = null;
        }
        document.getElementById('property_logo_file').value = '';
    }, 300);
}

function applyCrop() {
    if (!cropperInstance) return;
    
    // Get cropped canvas
    const canvas = cropperInstance.getCroppedCanvas({
        maxWidth: 1024,
        maxHeight: 1024,
        fillColor: 'transparent',
    });
    
    const dataUrl = canvas.toDataURL('image/png');
    const b64 = dataUrl.split(',')[1] || dataUrl;
    
    document.getElementById('property_logo_base64').value = b64;

    // Update logo preview in upload zone
    let logoPreview = document.getElementById('logo-preview');
    if (!logoPreview) {
        logoPreview = document.createElement('img');
        logoPreview.id = 'logo-preview';
        logoPreview.className = 'w-20 h-20 rounded-xl mx-auto object-contain mb-2 bg-slate-50 border border-slate-200';
        logoPreview.alt = 'Current Logo';
        const uploadZone = document.getElementById('property_logo_file').parentElement;
        uploadZone.insertBefore(logoPreview, uploadZone.firstChild);
    }
    logoPreview.src = dataUrl;
    
    // Update the live branding preview at top
    const previewLogoWrap = document.getElementById('preview-logo-wrap');
    if (previewLogoWrap) {
        previewLogoWrap.innerHTML = `<img id="preview-logo" src="${dataUrl}" class="w-14 h-14 rounded-xl object-contain border border-slate-200 bg-slate-50" alt="Hotel Logo">`;
    }

    closeCropModal();
    showToast('Logo cropped! Save settings to apply.', 'success');
}

async function toggleRoomOOO(roomId, isOOO) {
    try {
        const csrfToken = document.querySelector('meta[name="csrf-token"]')?.getAttribute('content');
        const res = await fetch('/api/admin/room_action', {
            method: 'POST',
            headers: { 
                'Content-Type': 'application/json',
                'X-CSRF-Token': csrfToken
            },
            body: JSON.stringify({
                room_id: roomId,
                action: isOOO ? 'mark_ooo' : 'mark_clean'
            })
        });
        const data = await res.json();
        if (data.success) {
            showToast(isOOO ? 'Room marked Out of Order' : 'Room marked operational (clean)', 'success');
        } else {
            showToast(data.message || 'Error updating room state', 'error');
        }
    } catch (err) {
        showToast('Connection error', 'error');
    }
}

document.addEventListener('DOMContentLoaded', () => {
    if (document.getElementById('wa_quick_replies_json')) {
        renderQuickReplies();
    }
});

function toggleTemplateEdit(key) {
    const el = document.getElementById(`tg-edit-container-${key}`);
    if (el) {
        el.classList.toggle('hidden');
    }
}

function renderQuickReplies() {
    const list = document.getElementById('quick-replies-list');
    if (!list) return;
    
    const hiddenInput = document.getElementById('wa_quick_replies_json');
    let qrData = [];
    try {
        qrData = JSON.parse(hiddenInput.value || '[]');
    } catch(e) {
        qrData = [];
    }
    
    if (qrData.length === 0) {
        list.innerHTML = `<p class="text-xs text-brand-900/50 font-medium py-2 text-center">No custom quick replies. Add one below!</p>`;
        return;
    }
    
    list.innerHTML = qrData.map((qr, index) => `
        <div class="bg-white border border-brand-200 p-3 rounded-xl space-y-2 relative group">
            <button type="button" onclick="deleteQuickReplyItem(${index})" class="absolute top-2 right-2 text-brand-400 hover:text-rose-500 transition-colors">
                <i class="ph ph-trash text-base"></i>
            </button>
            <div>
                <label class="block text-[9px] font-bold text-brand-400 uppercase tracking-wider mb-1">Shortcut / Title</label>
                <input type="text" value="${esc(qr.title || '')}" oninput="syncQuickReplies()" placeholder="e.g. WiFi Details" class="w-full bg-brand-50 border border-brand-200 p-2 rounded-lg text-xs font-semibold outline-none focus:bg-white focus:border-brand-900 transition-all qr-title-input">
            </div>
            <div>
                <label class="block text-[9px] font-bold text-brand-400 uppercase tracking-wider mb-1">Message Text</label>
                <textarea rows="2" oninput="syncQuickReplies()" placeholder="Type template message text..." class="w-full bg-brand-50 border border-brand-200 p-2 rounded-lg text-xs font-semibold outline-none focus:bg-white focus:border-brand-900 transition-all qr-text-input">${esc(qr.text || '')}</textarea>
            </div>
        </div>
    `).join('');
}

function addQuickReplyItem() {
    const hiddenInput = document.getElementById('wa_quick_replies_json');
    let qrData = [];
    try {
        qrData = JSON.parse(hiddenInput.value || '[]');
    } catch(e) {
        qrData = [];
    }
    qrData.push({ title: '', text: '' });
    hiddenInput.value = JSON.stringify(qrData);
    renderQuickReplies();
}

async function deleteQuickReplyItem(index) {
    if (!await pmsConfirm('Are you sure you want to delete this quick reply?')) return;
    const hiddenInput = document.getElementById('wa_quick_replies_json');
    let qrData = [];
    try {
        qrData = JSON.parse(hiddenInput.value || '[]');
    } catch(e) {
        qrData = [];
    }
    qrData.splice(index, 1);
    hiddenInput.value = JSON.stringify(qrData);
    renderQuickReplies();
}

function syncQuickReplies() {
    const list = document.getElementById('quick-replies-list');
    if (!list) return;
    
    const titles = Array.from(list.querySelectorAll('.qr-title-input')).map(el => el.value);
    const texts = Array.from(list.querySelectorAll('.qr-text-input')).map(el => el.value);
    
    const qrData = [];
    for (let i = 0; i < titles.length; i++) {
        qrData.push({
            title: titles[i],
            text: texts[i]
        });
    }
    
    document.getElementById('wa_quick_replies_json').value = JSON.stringify(qrData);
}

function esc(s) {
    if (s === null || s === undefined || s === '') return '';
    return String(s).replace(/&/g, "&amp;").replace(/</g, "&gt;").replace(/>/g, "&gt;").replace(/"/g, "&quot;").replace(/'/g, "&#039;");
}

document.addEventListener('DOMContentLoaded', () => {
    if (document.getElementById('folio_quick_charges_json')) {
        renderQuickCharges();
    }
});

function renderQuickCharges() {
    const list = document.getElementById('quick-charges-list');
    if (!list) return;
    
    const hiddenInput = document.getElementById('folio_quick_charges_json');
    let qcData = [];
    try {
        qcData = JSON.parse(hiddenInput.value || '[]');
    } catch(e) {
        qcData = [];
    }
    
    if (qcData.length === 0) {
        list.innerHTML = `<p class="text-xs text-brand-900/50 font-medium py-2 text-center">No quick charges configured. Add one below!</p>`;
        return;
    }
    
    const iconOptions = [
        { val: 'ph-receipt', label: 'Receipt / General' },
        { val: 'ph-coffee', label: 'Coffee / Breakfast' },
        { val: 'ph-fork-knife', label: 'Food / Dining' },
        { val: 'ph-wine', label: 'Drinks / Mini Bar' },
        { val: 'ph-washing-machine', label: 'Laundry' },
        { val: 'ph-car', label: 'Parking / Transport' },
        { val: 'ph-user-plus', label: 'Extra Person' },
        { val: 'ph-bed', label: 'Bed / Room' },
        { val: 'ph-wifi-high', label: 'Internet / WiFi' },
        { val: 'ph-drop', label: 'Water / Spa' },
        { val: 'ph-ticket', label: 'Tickets / Events' },
        { val: 'ph-shopping-cart', label: 'Shop / Retail' },
        { val: 'ph-scissors', label: 'Salon / Grooming' },
        { val: 'ph-swimming-pool', label: 'Pool / Leisure' },
        { val: 'ph-television', label: 'Entertainment / TV' },
        { val: 'ph-first-aid', label: 'Medical / First Aid' }
    ];

    list.innerHTML = qcData.map((qc, index) => {
        let optionsHtml = '';
        let found = false;
        iconOptions.forEach(opt => {
            const isSelected = opt.val === qc.icon;
            if (isSelected) found = true;
            optionsHtml += `<option value="${opt.val}" ${isSelected ? 'selected' : ''}>${opt.label}</option>`;
        });
        if (qc.icon && !found) {
            optionsHtml += `<option value="${esc(qc.icon)}" selected>Custom (${esc(qc.icon)})</option>`;
        }
        
        return `
            <div class="bg-white border border-brand-200 p-4 rounded-xl space-y-3 relative group">
                <button type="button" onclick="deleteQuickChargeItem(${index})" class="absolute top-3 right-3 text-brand-400 hover:text-rose-500 transition-colors">
                    <i class="ph ph-trash text-base"></i>
                </button>
                <div class="grid grid-cols-2 gap-3 pr-6">
                    <div>
                        <label class="block text-[9px] font-bold text-brand-400 uppercase tracking-wider mb-1">Item Name</label>
                        <input type="text" value="${esc(qc.name || '')}" oninput="syncQuickCharges()" placeholder="e.g. Water Bottle" class="w-full bg-brand-50 border border-brand-200 p-2 rounded-lg text-xs font-semibold outline-none focus:bg-white focus:border-brand-900 transition-all qc-name-input">
                    </div>
                    <div>
                        <label class="block text-[9px] font-bold text-brand-400 uppercase tracking-wider mb-1">Amount (₹)</label>
                        <input type="number" step="0.01" value="${esc(qc.amount || '')}" oninput="syncQuickCharges()" placeholder="e.g. 50" class="w-full bg-brand-50 border border-brand-200 p-2 rounded-lg text-xs font-semibold outline-none focus:bg-white focus:border-brand-900 transition-all qc-amount-input">
                    </div>
                    <div>
                        <label class="block text-[9px] font-bold text-brand-400 uppercase tracking-wider mb-1">Icon</label>
                        <select onchange="syncQuickCharges()" class="w-full bg-brand-50 border border-brand-200 p-2 rounded-lg text-xs font-semibold outline-none focus:bg-white focus:border-brand-900 transition-all qc-icon-input appearance-none">
                            ${optionsHtml}
                        </select>
                    </div>
                    <div>
                        <label class="block text-[9px] font-bold text-brand-400 uppercase tracking-wider mb-1">Description (Optional)</label>
                        <input type="text" value="${esc(qc.desc || '')}" oninput="syncQuickCharges()" placeholder="e.g. 1L Mineral Water" class="w-full bg-brand-50 border border-brand-200 p-2 rounded-lg text-xs font-semibold outline-none focus:bg-white focus:border-brand-900 transition-all qc-desc-input">
                    </div>
                </div>
            </div>
        `;
    }).join('');
}

function addQuickChargeItem() {
    const hiddenInput = document.getElementById('folio_quick_charges_json');
    let qcData = [];
    try {
        qcData = JSON.parse(hiddenInput.value || '[]');
    } catch(e) {
        qcData = [];
    }
    qcData.push({ icon: 'ph-receipt', name: '', amount: '', desc: '' });
    hiddenInput.value = JSON.stringify(qcData);
    renderQuickCharges();
}

async function deleteQuickChargeItem(index) {
    if (!await pmsConfirm('Are you sure you want to delete this quick charge?')) return;
    const hiddenInput = document.getElementById('folio_quick_charges_json');
    let qcData = [];
    try {
        qcData = JSON.parse(hiddenInput.value || '[]');
    } catch(e) {
        qcData = [];
    }
    qcData.splice(index, 1);
    hiddenInput.value = JSON.stringify(qcData);
    renderQuickCharges();
}

function syncQuickCharges() {
    const list = document.getElementById('quick-charges-list');
    if (!list) return;
    
    const names = Array.from(list.querySelectorAll('.qc-name-input')).map(el => el.value);
    const amounts = Array.from(list.querySelectorAll('.qc-amount-input')).map(el => el.value);
    const icons = Array.from(list.querySelectorAll('.qc-icon-input')).map(el => el.value);
    const descs = Array.from(list.querySelectorAll('.qc-desc-input')).map(el => el.value);
    
    const qcData = [];
    for (let i = 0; i < names.length; i++) {
        qcData.push({
            name: names[i],
            amount: amounts[i],
            icon: icons[i] || 'ph-receipt',
            desc: descs[i]
        });
    }
    
    document.getElementById('folio_quick_charges_json').value = JSON.stringify(qcData);
}

async function submitFolioItems(e) {
    e.preventDefault();
    const btn = document.getElementById('saveFolioItemsBtn');
    const originalText = btn.innerHTML;
    btn.innerHTML = 'Saving...';
    btn.classList.add('opacity-75');
    btn.disabled = true;

    const form = document.getElementById('folioItemsForm');
    const data = {};
    new FormData(form).forEach((val, key) => { data[key] = val; });
    data['_csrf_token'] = document.querySelector('meta[name="csrf-token"]')?.getAttribute('content') || '';

    try {
        const res = await fetch('/api/admin/save_settings', {
            method: 'POST',
            credentials: 'same-origin',
            headers: getHeaders(),
            body: JSON.stringify(data)
        });
        const result = await res.json();
        if (result.success) {
            showToast('Folio settings saved successfully!');
            location.reload();
        } else {
            showToast(result.message || 'Error saving settings');
        }
    } catch (err) {
        showToast('Connection error');
    } finally {
        btn.innerHTML = originalText;
        btn.classList.remove('opacity-75');
        btn.disabled = false;
    }
}

async function submitSequences(e) {
    e.preventDefault();
    const btn = document.getElementById('saveSequencesBtn');
    const originalText = btn.innerHTML;
    btn.innerHTML = 'Saving...';
    btn.classList.add('opacity-75');
    btn.disabled = true;

    const form = document.getElementById('sequencesForm');
    const data = {};
    
    new FormData(form).forEach((val, key) => {
        data[key] = val;
    });
    data['_csrf_token'] = document.querySelector('meta[name="csrf-token"]')?.getAttribute('content') || '';

    try {
        const res = await fetch('/api/admin/save_settings', {
            method: 'POST',
            credentials: 'same-origin',
            headers: getHeaders(),
            body: JSON.stringify(data)
        });
        const result = await res.json();

        if (result.success) {
            showToast('Sequence settings saved successfully!');
            location.reload();
        } else {
            showToast(result.message || 'Error saving settings');
        }
    } catch (err) {
        showToast('Connection error');
    } finally {
        btn.innerHTML = originalText;
        btn.classList.remove('opacity-75');
        btn.disabled = false;
    }
}

// ═══════════════════════════════════════════════════════════════
// NIGHT AUDIT FUNCTIONS
// ═══════════════════════════════════════════════════════════════

function loadNightAuditSettings() {
    fetch('/api/admin/night_audit?action=settings', {
        credentials: 'same-origin',
        headers: getHeaders()
    })
    .then(res => res.json())
    .then(data => {
        if (data.success && data.settings) {
            const s = data.settings;
            document.getElementById('night_audit_enabled').checked = s.night_audit_enabled === 'true';
            document.getElementById('night_audit_time').value = s.night_audit_time || '02:00';
            document.getElementById('night_audit_auto_checkout').checked = s.night_audit_auto_checkout === 'true';
            document.getElementById('night_audit_auto_checkout_hours').value = s.night_audit_auto_checkout_hours || '2';
            document.getElementById('night_audit_mark_dirty').checked = s.night_audit_mark_dirty !== 'false';
            document.getElementById('night_audit_notify_telegram').checked = s.night_audit_notify_telegram !== 'false';
            document.getElementById('night_audit_notify_email').value = s.night_audit_notify_email || '';
            document.getElementById('night_audit_report_revenue').checked = s.night_audit_report_revenue !== 'false';
            document.getElementById('night_audit_report_occupancy').checked = s.night_audit_report_occupancy !== 'false';
            document.getElementById('night_audit_report_room_status').checked = s.night_audit_report_room_status !== 'false';
            document.getElementById('night_audit_report_bookings').checked = s.night_audit_report_bookings !== 'false';
        }
    })
    .catch(() => showToast('Failed to load night audit settings', 'error'));
}

function loadAuditExceptions() {
    fetch('/api/admin/night_audit?action=exceptions', {
        credentials: 'same-origin',
        headers: getHeaders()
    })
    .then(res => res.json())
    .then(data => {
        const container = document.getElementById('audit-exceptions-list');
        if (data.success && data.exceptions && data.exceptions.length > 0) {
            container.innerHTML = data.exceptions.map(e => `
                <div class="flex items-center gap-3 p-3 border border-slate-100 rounded-xl hover:bg-slate-50 transition-colors">
                    <input type="checkbox" value="${e.id}" class="audit-exception-cb w-4 h-4 rounded border-brand-300 text-amber-600 focus:ring-amber-500">
                    <div class="flex-1">
                        <div class="flex justify-between items-center">
                            <span class="font-bold text-sm text-brand-900">${e.guest_name}</span>
                            <span class="text-xs font-bold px-2 py-0.5 rounded-md bg-amber-50 text-amber-700">Room ${e.room_number}</span>
                        </div>
                        <div class="text-[10px] text-brand-500 mt-1">
                            In: ${e.check_in} &bull; Out: <span class="text-red-500 font-bold">${e.check_out}</span>
                        </div>
                    </div>
                </div>
            `).join('');
        } else {
            container.innerHTML = '<div class="text-center py-4 text-slate-400 text-sm">No overdue checkouts found</div>';
        }
    })
    .catch(err => {
        console.error('Audit exceptions fetch error:', err);
        document.getElementById('audit-exceptions-list').innerHTML = '<div class="text-center py-4 text-red-400 text-sm">Failed to load exceptions</div>';
    });
}

function toggleAllExceptions(source) {
    const checkboxes = document.querySelectorAll('.audit-exception-cb');
    checkboxes.forEach(cb => cb.checked = source.checked);
}

function bulkResolveExceptions() {
    const checkboxes = document.querySelectorAll('.audit-exception-cb:checked');
    if (checkboxes.length === 0) {
        showToast('Please select at least one booking', 'info');
        return;
    }
    
    if (!confirm(`Are you sure you want to auto-checkout ${checkboxes.length} booking(s) and mark their rooms dirty?`)) return;
    
    const ids = Array.from(checkboxes).map(cb => cb.value);
    
    showLoading('Resolving exceptions...');
    fetch('/api/admin/night_audit?action=bulk_resolve', {
        method: 'POST',
        credentials: 'same-origin',
        headers: getHeaders(),
        body: JSON.stringify({ booking_ids: ids })
    })
    .then(res => res.json())
    .then(data => {
        hideLoading();
        if (data.success) {
            showToast(data.message, 'success');
            loadAuditExceptions();
        } else {
            showToast('Error: ' + data.error, 'error');
        }
    })
    .catch(err => {
        hideLoading();
        console.error('Bulk resolve error:', err);
        showToast('Connection error', 'error');
    });
}

function loadAuditHistory() {
    fetch('/api/admin/night_audit?action=history', {
        credentials: 'same-origin',
        headers: getHeaders()
    })
    .then(res => res.json())
    .then(data => {
        const container = document.getElementById('audit-history-list');
        if (data.success && data.history && data.history.length > 0) {
            container.innerHTML = data.history.map(a => {
                const statusColor = a.status === 'success' ? 'text-emerald-600 bg-emerald-50' : (a.status === 'partial' ? 'text-amber-600 bg-amber-50' : 'text-red-600 bg-red-50');
                const statusIcon = a.status === 'success' ? 'ph-check-circle' : (a.status === 'partial' ? 'ph-warning' : 'ph-x-circle');
                return `
                    <div class="border border-slate-100 rounded-xl p-4 hover:bg-slate-50/50 transition-colors">
                        <div class="flex items-center justify-between mb-2">
                            <div class="flex items-center gap-2">
                                <span class="text-sm font-bold text-slate-900">${a.audit_date}</span>
                                <span class="text-xs font-bold px-2 py-0.5 rounded-full ${statusColor}"><i class="ph ${statusIcon}"></i> ${a.status}</span>
                            </div>
                            <span class="text-[10px] text-slate-500 font-semibold">${a.run_by || 'system'}</span>
                        </div>
                        <div class="grid grid-cols-2 md:grid-cols-4 gap-2 text-xs">
                            <div><span class="text-slate-500">Occupied:</span> <span class="font-bold text-slate-900">${a.occupied_rooms}/${a.total_rooms}</span></div>
                            <div><span class="text-slate-500">Arrivals:</span> <span class="font-bold text-slate-900">${a.arrivals_today}</span></div>
                            <div><span class="text-slate-500">Departures:</span> <span class="font-bold text-slate-900">${a.departures_today}</span></div>
                            <div><span class="text-slate-500">Overdue:</span> <span class="font-bold ${a.overdue_checkouts > 0 ? 'text-red-600' : 'text-slate-900'}">${a.overdue_checkouts}</span></div>
                        </div>
                        ${a.auto_checkout_count > 0 ? `<div class="text-[10px] text-amber-600 font-semibold mt-1">Auto-checked out: ${a.auto_checkout_count} guest(s)</div>` : ''}
                    </div>
                `;
            }).join('');
        } else {
            container.innerHTML = '<div class="text-center py-4 text-slate-400 text-sm">No audit history yet</div>';
        }
    })
    .catch(() => {
        document.getElementById('audit-history-list').innerHTML = '<div class="text-center py-4 text-red-400 text-sm">Failed to load history</div>';
    });
}

async function saveNightAuditSettings() {
    const settings = {
        night_audit_enabled: document.getElementById('night_audit_enabled').checked ? 'true' : 'false',
        night_audit_time: document.getElementById('night_audit_time').value,
        night_audit_auto_checkout: document.getElementById('night_audit_auto_checkout').checked ? 'true' : 'false',
        night_audit_auto_checkout_hours: document.getElementById('night_audit_auto_checkout_hours').value,
        night_audit_mark_dirty: document.getElementById('night_audit_mark_dirty').checked ? 'true' : 'false',
        night_audit_notify_telegram: document.getElementById('night_audit_notify_telegram').checked ? 'true' : 'false',
        night_audit_notify_email: document.getElementById('night_audit_notify_email').value,
        night_audit_report_revenue: document.getElementById('night_audit_report_revenue').checked ? 'true' : 'false',
        night_audit_report_occupancy: document.getElementById('night_audit_report_occupancy').checked ? 'true' : 'false',
        night_audit_report_room_status: document.getElementById('night_audit_report_room_status').checked ? 'true' : 'false',
        night_audit_report_bookings: document.getElementById('night_audit_report_bookings').checked ? 'true' : 'false',
    };

    try {
        const res = await fetch('/api/admin/night_audit?action=save_settings', {
            method: 'POST',
            credentials: 'same-origin',
            headers: getHeaders(),
            body: JSON.stringify({ settings: settings })
        });
        const data = await res.json();
        if (data.success) {
            showToast('Night audit settings saved!', 'success');
        } else {
            showToast(data.message || 'Failed to save settings', 'error');
        }
    } catch (e) {
        showToast('Connection error', 'error');
    }
}

async function runNightAuditNow() {
    const statusDiv = document.getElementById('night-audit-status');
    statusDiv.innerHTML = '<div class="bg-blue-50 border border-blue-200 text-blue-800 p-3 rounded-xl text-sm font-semibold"><i class="ph ph-spinner animate-spin"></i> Running night audit...</div>';

    try {
        const res = await fetch('/api/admin/night_audit?action=run', {
            method: 'POST',
            credentials: 'same-origin',
            headers: getHeaders(),
            body: JSON.stringify({})
        });
        const data = await res.json();

        if (data.success && data.result) {
            const r = data.result;
            if (r.status === 'skipped') {
                statusDiv.innerHTML = `<div class="bg-amber-50 border border-amber-200 text-amber-800 p-3 rounded-xl text-sm font-semibold"><i class="ph ph-info"></i> ${r.message}</div>`;
            } else {
                statusDiv.innerHTML = `
                    <div class="bg-emerald-50 border border-emerald-200 text-emerald-800 p-4 rounded-xl text-sm space-y-1">
                        <p class="font-bold"><i class="ph ph-check-circle"></i> Night audit completed</p>
                        <div class="grid grid-cols-2 gap-2 text-xs mt-2">
                            <div>Occupied: <strong>${r.occupied_rooms}/${r.total_rooms}</strong></div>
                            <div>Arrivals: <strong>${r.arrivals_today}</strong></div>
                            <div>Departures: <strong>${r.departures_today}</strong></div>
                            <div>Overdue: <strong>${r.overdue_checkouts}</strong></div>
                            <div>Auto checkout: <strong>${r.auto_checkout_count}</strong></div>
                            <div>Dirty rooms: <strong>${r.rooms_marked_dirty}</strong></div>
                            <div>Collected: <strong>₹${parseFloat(r.revenue_collected).toFixed(2)}</strong></div>
                            <div>Pending: <strong>₹${parseFloat(r.revenue_pending).toFixed(2)}</strong></div>
                        </div>
                    </div>
                `;
            }
            loadAuditHistory();
        } else {
            statusDiv.innerHTML = `<div class="bg-red-50 border border-red-200 text-red-800 p-3 rounded-xl text-sm font-semibold"><i class="ph ph-x-circle"></i> ${data.message || 'Audit failed'}</div>`;
        }
    } catch (e) {
        statusDiv.innerHTML = '<div class="bg-red-50 border border-red-200 text-red-800 p-3 rounded-xl text-sm font-semibold"><i class="ph ph-x-circle"></i> Connection error</div>';
    }
}

const originalSwitchTabFn = switchTab;
switchTab = function(tabId) {
    originalSwitchTabFn(tabId);
    if (tabId === 'night-audit') {
        loadNightAuditSettings();
        loadAuditHistory();
        loadAuditExceptions();
    }
};

// Initialize the tab on page load (supports ?tab= parameter from menu links)
document.addEventListener('DOMContentLoaded', function() {
    const urlParams = new URLSearchParams(window.location.search);
    const tabParam = urlParams.get('tab');
    const validTabs = ['categories', 'rooms', 'rates', 'integrations', 'payments', 'staff', 'roles', 'property', 'folio-items', 'sequences', 'night-audit', 'subscription', 'guest-portal', 'housekeeping'];
    
    if (tabParam && validTabs.includes(tabParam)) {
        switchTab(tabParam);
    } else {
        switchTab('categories');
    }
});

// Roles & Permissions Management
function openRoleModal() {
    openModal('roleModal');
    document.getElementById('role_id').value = '';
    document.getElementById('role_name').value = '';
    document.querySelectorAll('#roleForm input[type="checkbox"]').forEach(cb => cb.checked = false);
    document.getElementById('roleModalTitle').innerText = 'Create Custom Role';
}

function editRoleFromBtn(btn) {
    const id = btn.getAttribute('data-role-id');
    const name = btn.getAttribute('data-role-name');
    let permissions = [];
    try {
        permissions = JSON.parse(btn.getAttribute('data-role-perms') || '[]');
    } catch(e) {
        console.error('Failed to parse permissions:', e);
    }
    editRole(id, name, permissions);
}

function editRole(id, name, permissions) {
    openModal('roleModal');
    document.getElementById('role_id').value = id;
    document.getElementById('role_name').value = name;
    
    document.querySelectorAll('#roleForm input[type="checkbox"]').forEach(cb => {
        cb.checked = permissions.includes(cb.value);
    });
    
    document.getElementById('roleModalTitle').innerText = 'Edit Role';
}

async function deleteRole(id, name) {
    if (!await pmsConfirm(`Delete role "${name}"? Staff users with this role will lose their custom permissions.`)) return;
    
    try {
        const formData = new FormData();
        formData.append('action', 'delete');
        formData.append('role_id', id);
        
        const res = await fetch('/api/admin/manage_roles', { method: 'POST', body: formData });
        const data = await res.json();
        
        if (data.success) {
            showToast(data.message, 'success');
            setTimeout(() => window.location.reload(), 1000);
        } else {
            showToast(data.message, 'error');
        }
    } catch (e) {
        showToast('Connection error', 'error');
    }
}

async function submitRole(e) {
    e.preventDefault();
    const form = e.target;
    const btn = form.querySelector('button[type="submit"]');
    btn.disabled = true;
    btn.innerText = 'Saving...';
    
    try {
        const formData = new FormData(form);
        formData.append('action', formData.get('role_id') ? 'update' : 'create');
        
        const res = await fetch('/api/admin/manage_roles', { method: 'POST', body: formData });
        const data = await res.json();
        
        if (data.success) {
            showToast(data.message, 'success');
            setTimeout(() => window.location.reload(), 1000);
        } else {
            showToast(data.message, 'error');
            btn.disabled = false;
            btn.innerText = 'Save Role';
        }
    } catch (e) {
        showToast('Connection error', 'error');
        btn.disabled = false;
        btn.innerText = 'Save Role';
    }
}
