const bookingId = FOLIO_DATA.bookingId;
const currentBalance = FOLIO_DATA.balance;

const catRatePlans = FOLIO_DATA.catRatePlans;
const currentRatePlan = FOLIO_DATA.ratePlanName;

let pendingStatusAction = null;

function showStatusModal(action) {
    pendingStatusAction = action;
    const titles = {
        'check_in': 'Check In Guest',
        'check_out': 'Check Out Guest',
        'cancel': 'Cancel Booking',
        'rollback_to_booked': 'Rollback to Booked',
        'rollback_to_checked_in': 'Rollback to Checked In'
    };
    const descs = {
        'check_in': 'Confirm that the guest has arrived and is checking in now.',
        'check_out': 'Confirm that the guest is leaving and checking out now.',
        'cancel': 'This will cancel the booking and mark it as cancelled. The room will become available.',
        'rollback_to_booked': 'This will undo the check-in. Check-in date will become editable again.',
        'rollback_to_checked_in': 'This will undo the check-out. Check-out date will become editable again.'
    };
    const reasonRequired = action.startsWith('rollback') || action === 'cancel';

    document.getElementById('status_modal_title').innerText = titles[action];
    document.getElementById('status_modal_desc').innerText = descs[action];
    document.getElementById('reason_required').style.display = reasonRequired ? 'inline' : 'none';
    document.getElementById('status_reason').value = '';

    UI.showModal('status-change-modal');
}

async function submitStatusChange(btn) {
    const reason = document.getElementById('status_reason').value.trim();
    const reasonRequired = pendingStatusAction.startsWith('rollback') || pendingStatusAction === 'cancel';

    if (reasonRequired && !reason) {
        showToast('Reason is required for this action.');
        return;
    }

    const originalText = btn.innerText;
    btn.innerText = 'Processing...';
    btn.disabled = true;

    try {
        const res = await fetch('../api/admin_booking_status.php', {
            method: 'POST',
            headers: { 'Content-Type': 'application/json' },
            body: JSON.stringify({
                action: pendingStatusAction,
                booking_id: bookingId,
                reason: reason
            })
        });
        const data = await res.json();

        if (data.success) {
            UI.hideModal('status-change-modal');
            location.reload();
        } else {
            showToast(data.message || 'Action failed');
            btn.innerText = originalText;
            btn.disabled = false;
        }
    } catch(e) {
        showToast('Connection error');
        btn.innerText = originalText;
        btn.disabled = false;
    }
}

function updateRatePlanDropdown() {
    const roomSelect = document.getElementById('edit_room_id');
    const selectedOption = roomSelect.options[roomSelect.selectedIndex];
    const catId = selectedOption.getAttribute('data-cat');
    const rateSelect = document.getElementById('edit_rate_plan');

    rateSelect.innerHTML = '';
    const plans = catRatePlans[catId] || ['Base Rate'];
    plans.forEach(plan => {
        const opt = document.createElement('option');
        opt.value = plan === 'Base Rate' ? '' : plan;
        opt.text = plan;
        if (plan === currentRatePlan) opt.selected = true;
        rateSelect.appendChild(opt);
    });
}

updateRatePlanDropdown();

async function editBooking(btn) {
    const roomId = document.getElementById('edit_room_id').value;
    const ratePlan = document.getElementById('edit_rate_plan').value;
    const inDate = document.getElementById('edit_check_in').value;
    const outDate = document.getElementById('edit_check_out').value;
    const taxPrefEl = document.getElementById('edit_tax_pref');
    const taxPref = taxPrefEl ? taxPrefEl.value : 'exclusive';

    if (new Date(outDate) <= new Date(inDate)) {
        showToast("Check-out date and time must be strictly after the check-in date and time.");
        return;
    }

    const originalText = btn.innerText;

    btn.innerText = 'Updating...';
    btn.disabled = true;

    try {
        const res = await fetch('../api/admin_edit_booking.php', {
            method: 'POST',
            headers: { 'Content-Type': 'application/json' },
            body: JSON.stringify({
                booking_id: bookingId,
                room_id: roomId,
                rate_plan_name: ratePlan,
                check_in: inDate,
                check_out: outDate,
                tax_preference: taxPref
            })
        });
        const data = await res.json();
        if(data.success) {
            showToast('Booking updated. New total: ₹' + data.new_total);
            location.reload();
        } else {
            showToast(data.message);
            btn.innerText = originalText;
            btn.disabled = false;
        }
    } catch(e) {
        showToast("Request failed");
        btn.innerText = originalText;
        btn.disabled = false;
    }
}

async function extendStay(btn, hours) {
    if(!confirm(`Extend stay by ${hours} hours?`)) return;
    const originalText = btn.innerText;
    btn.innerText = 'Wait...';
    btn.disabled = true;
    try {
        const res = await fetch('../api/admin_extend_stay.php', {
            method: 'POST',
            headers: { 'Content-Type': 'application/json' },
            body: JSON.stringify({ booking_id: bookingId, hours: hours })
        });
        const data = await res.json();
        if(data.success) {
            showToast(`Extended by ${hours} hours! Added ₹${data.added_cost} to ledger.`);
            location.reload();
        } else {
            showToast(data.message);
            btn.innerText = originalText;
            btn.disabled = false;
        }
    } catch(e) {
        showToast("Request failed");
        btn.innerText = originalText;
        btn.disabled = false;
    }
}

/**
 * Prefill the incidental charge fields from a quick-charge preset.
 */
function prefillCharge(name, amount) {
    const nameEl = document.getElementById('incidental_name');
    const amountEl = document.getElementById('incidental_amount');
    if (nameEl) nameEl.value = name;
    if (amountEl) amountEl.value = amount;
    nameEl?.focus();
    showToast(`Quick charge prefilled: ${name} (₹${amount})`, 'info');
}

async function postCharge(btn) {
    const itemName = document.getElementById('incidental_name').value;
    const amount = parseFloat(document.getElementById('incidental_amount').value);

    if(!itemName || !amount || amount <= 0) {
        showToast('Enter valid item and amount');
        return;
    }

    const originalHTML = btn.innerHTML;
    btn.innerHTML = 'Wait...';
    btn.disabled = true;

    try {
        const res = await fetch('../api/admin_post_charge.php', {
            method: 'POST',
            headers: { 'Content-Type': 'application/json' },
            body: JSON.stringify({ booking_id: bookingId, item_name: itemName, amount: amount })
        });
        const data = await res.json();
        if(data.success) {
            location.reload();
        } else {
            showToast(data.message);
            btn.innerHTML = originalHTML;
            btn.disabled = false;
        }
    } catch(e) {
        showToast("Request failed");
        btn.innerHTML = originalHTML;
        btn.disabled = false;
    }
}

async function checkout(btn) {
    let proceed = false;
    if(currentBalance > 0) {
        showToast(`Cannot check-out: Guest owes ₹${currentBalance}. Please collect payment first.`, 'error');
        return;
    } else if (currentBalance < 0) {
        proceed = await pmsConfirm(`You owe guest ₹${Math.abs(currentBalance)}. Process checkout?`, 'Confirm Check-out', 'warning');
    } else {
        proceed = await pmsConfirm('Process checkout for this room?', 'Confirm Check-out', 'warning');
    }
    if (!proceed) return;

    const originalHTML = btn.innerHTML;
    btn.innerHTML = 'Processing...';
    btn.disabled = true;

    try {
        const res = await fetch('../api/admin_booking_status.php', {
            method: 'POST',
            headers: { 'Content-Type': 'application/json' },
            body: JSON.stringify({ action: 'check_out', booking_id: bookingId, reason: 'Manual checkout from Folio' })
        });
        const data = await res.json();
        if(data.success) {
            location.href = 'index.php';
        } else {
            showToast(data.message);
            btn.innerHTML = originalHTML;
            btn.disabled = false;
        }
    } catch (e) {
        showToast("Checkout request failed");
        btn.innerHTML = originalHTML;
        btn.disabled = false;
    }
}

async function saveGuestEdit(btn) {
    const n = document.getElementById('edit_g_name').value;
    const p = document.getElementById('edit_g_phone').value;
    const age = document.getElementById('edit_g_age').value;
    const city = document.getElementById('edit_g_city').value;
    const state = document.getElementById('edit_g_state').value;
    const country = document.getElementById('edit_g_country').value;
    const pincode = document.getElementById('edit_g_pincode').value;

    UI.setLoading(btn, true);
    const res = await fetch('../api/admin_edit_guest.php', {
        method: 'POST',
        body: JSON.stringify({
            booking_id: bookingId,
            guest_name: n,
            guest_phone: p,
            age: age,
            city: city,
            state: state,
            country: country,
            pincode: pincode
        })
    });
    const data = await res.json();
    if(data.success) location.reload(); else { showToast(data.message); UI.setLoading(btn, false); }
}

function openEditLedger(id, desc, amt, method = '') {
    document.getElementById('edit_l_id').value = id;
    document.getElementById('edit_l_desc').value = desc;
    document.getElementById('edit_l_amount').value = amt;
    document.getElementById('edit_l_method').value = method;
    UI.showModal('edit-ledger-modal');
}

async function saveLedgerEdit(btn) {
    UI.setLoading(btn, true);
    const id = document.getElementById('edit_l_id').value;
    const desc = document.getElementById('edit_l_desc').value;
    const amt = document.getElementById('edit_l_amount').value;
    const method = document.getElementById('edit_l_method').value;

    try {
        const res = await fetch('../api/admin_edit_ledger.php', {
            method: 'POST',
            headers: {'Content-Type': 'application/json'},
            body: JSON.stringify({ ledger_id: id, description: desc, amount: amt, payment_method: method })
        });
        const data = await res.json();
        if(data.success) location.reload(); else { showToast(data.message); UI.setLoading(btn, false); }
    } catch(e) {
        showToast("Error");
        UI.setLoading(btn, false);
    }
}

function refundRazorpay(ledgerId) {
    if (!confirm('Are you sure you want to issue a refund for this online payment? This action cannot be undone.')) return;
    
    fetch('../api/admin_refund_razorpay.php', {
        method: 'POST',
        headers: {'Content-Type': 'application/json'},
        body: JSON.stringify({ ledger_id: ledgerId })
    }).then(r => r.json())
    .then(data => {
        if (data.success) {
            showToast('Refund successful!');
            location.reload();
        } else {
            showToast('Refund failed: ' + (data.message || 'Unknown error') + (data.details ? ' ' + JSON.stringify(data.details) : ''));
        }
    }).catch(err => {
        console.error(err);
        showToast('An error occurred while processing the refund.');
    });
}

async function deleteLedger(id) {
    if(!confirm('Are you sure you want to delete this ledger entry?')) return;
    const res = await fetch('../api/admin_delete_ledger.php', {
        method: 'POST', body: JSON.stringify({ledger_id: id})
    });
    const data = await res.json();
    if(data.success) location.reload(); else showToast(data.message);
}

async function uploadDoc(type, input) {
    if(!input.files || input.files.length === 0) return;

    const file = input.files[0];
    const ext = file.name.split('.').pop().toLowerCase();

    if (['jpg', 'jpeg', 'png'].includes(ext)) {
        try {
            const result = await UI.compressImage(file, 1000, 0.7, 500 * 1024);
            const compressedFile = new File([result.blob], file.name.replace(/\.[^.]+$/, '.jpg'), { type: 'image/jpeg' });

            const formData = new FormData();
            formData.append('file', compressedFile);
            formData.append('doc_type', type);
            formData.append('booking_id', bookingId);

            const res = await fetch('../api/admin_upload_document.php', {
                method: 'POST', body: formData
            });
            const text = await res.text();
            try {
                const data = JSON.parse(text);
                if(data.success) location.reload(); else showToast(data.message);
            } catch(e) {
                showToast('Server error: ' + text.substring(0, 200));
            }
        } catch(e) {
            showToast('Compression failed: ' + e.message);
        }
    } else {
        const formData = new FormData();
        formData.append('file', file);
        formData.append('doc_type', type);
        formData.append('booking_id', bookingId);

        const res = await fetch('../api/admin_upload_document.php', {
            method: 'POST', body: formData
        });
        const text = await res.text();
        try {
            const data = JSON.parse(text);
            if(data.success) location.reload(); else showToast(data.message);
        } catch(e) {
            showToast('Server error: ' + text.substring(0, 200));
        }
    }
}

async function recordManualPayment(btn, method) {
    const amt = parseFloat(document.getElementById('cp_amount').value);
    if(amt <= 0) return showToast('Invalid amount');
    if(!confirm(`Record ₹${amt} payment via ${method.toUpperCase()}?`)) return;

    const originalHTML = btn.innerHTML;
    btn.innerHTML = 'Wait...';
    btn.disabled = true;

    try {
        const res = await fetch('../api/admin_record_payment.php', {
            method: 'POST', body: JSON.stringify({
                booking_id: bookingId, amount: amt, method: method, ref: 'MANUAL_' + Date.now()
            })
        });
        const data = await res.json();
        if(data.success) location.reload(); else { showToast(data.message); btn.innerHTML = originalHTML; btn.disabled = false; }
    } catch(e) {
        showToast('Request failed');
        btn.innerHTML = originalHTML;
        btn.disabled = false;
    }
}

async function sendPaymentLink(btn) {
    showToast('Payment link sent via WhatsApp successfully!');
}

async function payViaGateway(btn) {
    const amt = parseFloat(document.getElementById('cp_amount').value);
    if(amt <= 0 || isNaN(amt)) return showToast('Invalid amount');

    const originalHTML = btn.innerHTML;
    btn.innerHTML = 'Wait...';
    btn.disabled = true;

    let orderId = '';
    try {
        const orderRes = await fetch('../api/admin_create_razorpay_order.php', {
            method: 'POST',
            headers: { 'Content-Type': 'application/json' },
            body: JSON.stringify({ booking_id: bookingId, amount: amt })
        });
        const orderData = await orderRes.json();
        if(orderData.success) {
            orderId = orderData.order_id;
        } else {
            showToast('Failed to generate Order ID: ' + (orderData.message || 'Check Razorpay keys.'));
            btn.innerHTML = originalHTML;
            btn.disabled = false;
            return;
        }
    } catch (e) {
        showToast('Network error while generating Order ID.');
        btn.innerHTML = originalHTML;
        btn.disabled = false;
        return;
    }

    const options = {
        key: FOLIO_DATA.razorpayKeyId,
        amount: Math.round(amt * 100),
        currency: 'INR',
        name: 'MicroPMS',
        description: 'Folio Payment Collection',
        order_id: orderId,
        handler: async function (response) {
            const res = await fetch('../api/admin_record_payment.php', {
                method: 'POST',
                headers: { 'Content-Type': 'application/json' },
                body: JSON.stringify({
                    booking_id: bookingId, amount: amt, method: 'Razorpay', ref: response.razorpay_payment_id
                })
            });
            const data = await res.json();
            if(data.success) location.reload(); else showToast(data.message);
        },
        prefill: { name: FOLIO_DATA.guestName, contact: FOLIO_DATA.guestPhone },
        theme: { color: '#0ea5e9' }
    };

    if(typeof Razorpay !== 'undefined') {
        const rzp1 = new Razorpay(options);
        rzp1.on('payment.failed', function (response){
            showToast("Payment Failed. Reason: " + response.error.description);
        });
        rzp1.open();
        btn.innerHTML = originalHTML;
        btn.disabled = false;
    } else {
        showToast('Razorpay script not loaded. Check internet connection.');
        btn.innerHTML = originalHTML;
        btn.disabled = false;
    }
}

async function triggerWhatsAppAutomation(eventKey, btn) {
    const originalHTML = btn.innerHTML;
    btn.disabled = true;
    btn.innerHTML = `<span class="flex items-center gap-2"><i class="ph ph-spinner animate-spin"></i> Sending...</span>`;
    
    try {
        const res = await fetch('../api/trigger_automation.php', {
            method: 'POST',
            headers: { 'Content-Type': 'application/json' },
            body: JSON.stringify({ event: eventKey, booking_id: bookingId })
        });
        const data = await res.json();
        if (data.success) {
            showToast("WhatsApp message triggered successfully!", "success");
            UI.hideModal('whatsapp-triggers-modal');
        } else {
            showToast(data.error || "Failed to trigger message.");
        }
    } catch (e) {
        showToast("Network error. Please try again.");
    } finally {
        btn.disabled = false;
        btn.innerHTML = originalHTML;
    }
}
