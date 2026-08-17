// JS Logic for SPA
        let selectedRoomIds = []; // Array for multi-room selection
        window.holdToken = null;
        window.bookingIdempotencyKey = (window.crypto && crypto.randomUUID) ? crypto.randomUUID() : String(Date.now()) + '-' + Math.random().toString(16).slice(2);
        let holdSyncTimer = null;
        let selectedRoomsInfo = []; // Track room details for display

        function updateStepBar(activeStep) {
            try {
                for (let i = 1; i <= 3; i++) {
                    const el = document.getElementById('step-bar-' + i);
                    if (!el) continue;
                    const numSpan = el.querySelector('span');
                    if (!numSpan) continue;
                    if (i === activeStep) {
                        el.className = 'flex items-center gap-1.5 font-extrabold text-indigo-700';
                        numSpan.className = 'w-5 h-5 rounded-full bg-indigo-600 text-white flex items-center justify-center text-[10px] shadow-sm';
                        numSpan.textContent = i;
                    } else if (i < activeStep) {
                        el.className = 'flex items-center gap-1.5 text-emerald-600';
                        numSpan.className = 'w-5 h-5 rounded-full bg-emerald-100 text-emerald-600 flex items-center justify-center text-[10px]';
                        numSpan.innerHTML = '<i class="ph ph-check"></i>';
                    } else {
                        el.className = 'flex items-center gap-1.5 text-slate-400';
                        numSpan.className = 'w-5 h-5 rounded-full bg-slate-100 text-slate-400 flex items-center justify-center text-[10px]';
                        numSpan.textContent = i;
                    }
                }
            } catch (err) { /* step bar update failed silently */ }
        }

        // Generate time options
        function generateTimeOptions() {
            let options = '';
            for(let h=0; h<24; h++) {
                for(let m=0; m<60; m+=30) {
                    let hourStr = h < 10 ? '0' + h : h;
                    let minStr = m === 0 ? '00' : '30';
                    let timeVal = `${hourStr}:${minStr}`;
                    let period = h >= 12 ? 'PM' : 'AM';
                    let displayHour = h % 12 || 12;
                    let displayTime = `${displayHour}:${minStr} ${period}`;
                    options += `<option value="${timeVal}">${displayTime}</option>`;
                }
            }
            return options;
        }

        // Format helpers to get local ISO string formats without timezone/shift bugs
        function formatDateToYYYYMMDD(date) {
            let y = date.getFullYear();
            let m = date.getMonth() + 1;
            let d = date.getDate();
            return `${y}-${m < 10 ? '0' + m : m}-${d < 10 ? '0' + d : d}`;
        }

        function formatTimeToHHMM(date) {
            let h = date.getHours();
            let m = date.getMinutes();
            return `${h < 10 ? '0' + h : h}:${m < 10 ? '0' + m : m}`;
        }

        function parseISOString(str) {
            if (!str) return new Date();
            let clean = str.replace('T', ' ');
            if (clean.length === 16) clean += ':00';
            let parts = clean.split(' ');
            let dateParts = (parts[0] || '').split('-');
            let timeParts = (parts[1] || '00:00:00').split(':');
            let y = parseInt(dateParts[0], 10) || 2026;
            let m = (parseInt(dateParts[1], 10) || 1) - 1;
            let d = parseInt(dateParts[2], 10) || 1;
            let hr = parseInt(timeParts[0], 10) || 0;
            let min = parseInt(timeParts[1], 10) || 0;
            let sec = parseInt(timeParts[2] || '0', 10) || 0;
            return new Date(y, m, d, hr, min, sec);
        }

        function getCheckIn() {
            const dateEl = document.getElementById('check_in_date');
            const timeEl = document.getElementById('check_in_time');
            const now = new Date();
            let dVal = (dateEl && dateEl.value) ? dateEl.value : formatDateToYYYYMMDD(now);
            let tVal = (timeEl && timeEl.value) ? timeEl.value : formatTimeToHHMM(now);
            if (dateEl && !dateEl.value) dateEl.value = dVal;
            if (timeEl && !timeEl.value) timeEl.value = tVal;
            return `${dVal}T${tVal}`;
        }

        function getCheckOut() {
            const dateEl = document.getElementById('check_out_date');
            const timeEl = document.getElementById('check_out_time');
            const now = new Date();
            let later = new Date(now.getTime() + 3 * 3600 * 1000);
            let dVal = (dateEl && dateEl.value) ? dateEl.value : formatDateToYYYYMMDD(later);
            let tVal = (timeEl && timeEl.value) ? timeEl.value : formatTimeToHHMM(later);
            if (dateEl && !dateEl.value) dateEl.value = dVal;
            if (timeEl && !timeEl.value) timeEl.value = tVal;
            return `${dVal}T${tVal}`;
        }

        function initStep1DatesAndTime() {
            const checkInDateEl = document.getElementById('check_in_date');
            const checkInTimeEl = document.getElementById('check_in_time');
            const checkOutDateEl = document.getElementById('check_out_date');
            const checkOutTimeEl = document.getElementById('check_out_time');

            if (!checkInDateEl || !checkInTimeEl || !checkOutDateEl || !checkOutTimeEl) return;

            // 1. Populate time option tags if not already rendered
            if (!checkInTimeEl.children.length) {
                checkInTimeEl.innerHTML = generateTimeOptions();
            }
            if (!checkOutTimeEl.children.length) {
                checkOutTimeEl.innerHTML = generateTimeOptions();
            }

            // 2. Compute rounded local times
            const now = new Date();
            let roundedNow = new Date(now);
            let minutes = now.getMinutes();
            let roundedMinutes = Math.ceil(minutes / 30) * 30;
            if (roundedMinutes === 60) {
                roundedNow.setHours(roundedNow.getHours() + 1, 0, 0, 0);
            } else {
                roundedNow.setMinutes(roundedMinutes, 0, 0);
            }

            // 3. Assign values to inputs automatically
            if (!checkInDateEl.value) {
                checkInDateEl.value = formatDateToYYYYMMDD(roundedNow);
            }
            if (!checkInTimeEl.value) {
                checkInTimeEl.value = formatTimeToHHMM(roundedNow);
            }

            let roundedLater = new Date(roundedNow.getTime() + 3 * 60 * 60 * 1000);
            if (!checkOutDateEl.value) {
                checkOutDateEl.value = formatDateToYYYYMMDD(roundedLater);
            }
            if (!checkOutTimeEl.value) {
                checkOutTimeEl.value = formatTimeToHHMM(roundedLater);
            }

            checkOutDateEl.min = checkInDateEl.value;

            checkInDateEl.onchange = (e) => {
                checkOutDateEl.min = e.target.value;
                if (checkOutDateEl.value < e.target.value) {
                    checkOutDateEl.value = e.target.value;
                }
            };
        }

        // Run date/time initialization immediately & on DOMContentLoaded
        initStep1DatesAndTime();
        if (document.readyState === 'loading') {
            document.addEventListener('DOMContentLoaded', initStep1DatesAndTime);
        }

        // Step transition helper — called immediately on button click
        function goToStep2(checkIn, checkOut) {
            const inDate = parseISOString(checkIn);
            const outDate = parseISOString(checkOut);
            const hours = Math.max(1, Math.round((outDate - inDate) / 3600000));
            const nights = Math.max(1, Math.round(hours / 24));

            try {
                const summaryDisplay = document.getElementById('summary-dates-display');
                if (summaryDisplay) {
                    const opts = { month: 'short', day: 'numeric', hour: '2-digit', minute: '2-digit' };
                    const inFmt = inDate.toLocaleDateString('en-IN', opts);
                    const outFmt = outDate.toLocaleDateString('en-IN', opts);
                    summaryDisplay.innerHTML =
                        '<span>' + inFmt + '</span>' +
                        '<i class="ph ph-arrow-right text-[10px]"></i>' +
                        '<span>' + outFmt + '</span>' +
                        '<span class="ml-2 bg-indigo-500/30 text-indigo-200 px-2 py-0.5 rounded text-[10px] font-bold">' + nights + (nights === 1 ? ' Night' : ' Nights') + '</span>';
                }
            } catch (e2) {}

            const stepDates = document.getElementById('step-dates');
            const stepRooms = document.getElementById('step-rooms');
            if (stepDates) stepDates.style.display = 'none';
            if (stepRooms) { stepRooms.style.display = ''; stepRooms.classList.remove('hidden'); }
            updateStepBar(2);
            window.scrollTo({ top: 0, behavior: 'smooth' });
        }

        // Global function attached directly to btn-check and window
        window.checkAvailability = function() {
            const btn = document.getElementById('btn-check');
            const checkIn = getCheckIn();
            const checkOut = getCheckOut();

            // Immediately show loading state
            let origHtml = 'Find Available Rooms <i class="ph ph-arrow-right text-sm"></i>';
            if (btn) { origHtml = btn.innerHTML; btn.innerHTML = '<i class="ph ph-spinner animate-spin mr-2"></i> Checking...'; btn.disabled = true; }

            const container = document.getElementById('rooms-container');
            if (container && window.ApiClient) {
                ApiClient.showSkeleton(container, { type: 'cards', rows: 3 });
            } else if (container) {
                container.innerHTML = '<div class="card-glass p-8 text-center text-slate-400"><i class="ph ph-spinner animate-spin text-3xl mb-3 text-indigo-400"></i><p class="font-semibold mt-2">Finding available rooms...</p></div>';
            }

            goToStep2(checkIn, checkOut);

            const run = window.ApiClient
                ? ApiClient.apiFetch('/api/system/check_availability', {
                    method: 'POST',
                    body: JSON.stringify({ check_in: checkIn, check_out: checkOut }),
                    toast: false
                })
                : fetch('/api/system/check_availability', {
                    method: 'POST',
                    headers: {
                        'Content-Type': 'application/json',
                        'X-CSRF-Token': document.querySelector('meta[name="csrf-token"]')?.content || ''
                    },
                    body: JSON.stringify({ check_in: checkIn, check_out: checkOut })
                }).then(function(res) { return res.json(); });

            run.then(function(data) {
                const categories = (data && Array.isArray(data.categories)) ? data.categories : [];
                window.lastAvailableCategories = categories;
                renderRooms(categories);
                if (data && !data.success && data.message) showNotification(data.message, 'error');
            }).catch(function(err) {
                if (container && window.ApiClient) {
                    ApiClient.showEmptyState(container, {
                        message: 'Could not load rooms: ' + (err.message || 'network error'),
                        retryFn: function () { document.getElementById('btn-check')?.click(); },
                        icon: 'ph-wifi-slash'
                    });
                } else {
                    renderRooms([]);
                    showNotification('Could not load rooms: ' + err.message, 'error');
                }
            }).finally(function() {
                if (btn) { btn.innerHTML = origHtml; btn.disabled = false; }
            });
        };

        function renderRooms(categories) {
            const container = document.getElementById('rooms-container');
            container.innerHTML = '';
            
            if (categories.length === 0) {
                container.innerHTML = '<div class="card-glass p-8 text-center text-slate-400 font-semibold"><i class="ph ph-smiley-sad text-4xl mb-3 text-slate-300"></i><p>No rooms available for these dates.</p></div>';
                return;
            }

            categories.forEach(cat => {
                const div = document.createElement('div');
                div.className = 'card-glass p-5';
                
                // Amenity heuristics based on category name
                const lowerName = cat.name.toLowerCase();
                const amenities = [
                    { name: 'Free WiFi', icon: 'ph-wifi' },
                    { name: 'Mineral Water', icon: 'ph-drop' }
                ];
                if (lowerName.includes('ac') && !lowerName.includes('non-ac')) {
                    amenities.push({ name: 'Air Conditioning', icon: 'ph-snowflake' });
                }
                if (lowerName.includes('deluxe') || lowerName.includes('suite') || lowerName.includes('premium')) {
                    amenities.push({ name: 'Room Service', icon: 'ph-bell' });
                    amenities.push({ name: 'Premium Toiletries', icon: 'ph-sparkles' });
                    amenities.push({ name: 'King Bed', icon: 'ph-bed' });
                    if (!lowerName.includes('non-ac')) {
                        amenities.push({ name: 'Air Conditioning', icon: 'ph-snowflake' });
                    }
                } else {
                    amenities.push({ name: 'Double Bed', icon: 'ph-bed' });
                }
                if (lowerName.includes('tv') || lowerName.includes('deluxe') || lowerName.includes('suite')) {
                    amenities.push({ name: 'Flat TV', icon: 'ph-television' });
                }

                const amenitiesHtml = `
                    <div class="flex flex-wrap gap-x-3 gap-y-1.5 mb-4 border-t border-slate-100 pt-3">
                        ${amenities.map(a => `
                            <span class="flex items-center gap-1 text-[10px] font-bold text-slate-400">
                                <i class="ph ${a.icon} text-xs text-slate-400"></i> ${a.name}
                            </span>
                        `).join('')}
                    </div>
                `;
                
                div.innerHTML = `
                    <div class="mb-3 pb-2 border-b border-slate-100">
                        <h3 class="font-bold text-lg text-slate-800 font-display">${cat.name}</h3>
                    </div>
                    ${amenitiesHtml}
                    <div>
                        <p class="text-[10px] font-bold text-slate-400 uppercase tracking-wider mb-3">Select Rate Plan</p>
                        <div class="flex flex-col gap-3 mb-5">
                            ${cat.rate_plans.map((rp, i) => `
                                <label class="flex items-center gap-3 p-3.5 rounded-xl border border-slate-100 bg-white cursor-pointer hover:bg-slate-50/50 transition-colors shadow-sm">
                                    <input type="radio" name="rate_plan_${cat.category_id}" value="${rp.name}" class="w-4 h-4 accent-slate-900" ${i === 0 ? 'checked' : ''}>
                                    <div class="flex-1">
                                        <span class="block font-semibold text-sm text-slate-700">${rp.name}</span>
                                    </div>
                                    <span class="font-bold text-slate-900 text-base">₹${rp.total_cost}</span>
                                </label>
                            `).join('')}
                        </div>

                        <p class="text-[10px] font-bold text-slate-400 uppercase tracking-wider mb-3">Select Room(s) — Tap to toggle</p>
                        <div class="grid grid-cols-3 gap-2.5">
                            ${cat.rooms.map(room => `
                                <button onclick="toggleRoom(${room.id}, '${room.room_number}', ${cat.category_id}, this)" class="room-btn flex flex-col items-center justify-center p-3.5 rounded-2xl bg-white text-slate-800 font-bold border border-slate-150 hover:border-indigo-500 hover:bg-indigo-50/20 active:scale-[0.95] transition-all shadow-sm group" data-room-id="${room.id}">
                                    <i class="ph ph-key text-xl text-slate-350 group-hover:text-indigo-600 transition-colors mb-1.5"></i>
                                    <span class="text-[9px] text-slate-400 font-bold uppercase tracking-wider leading-none">Room</span>
                                    <span class="text-sm font-extrabold text-slate-900 mt-1">${room.room_number}</span>
                                </button>
                            `).join('')}
                        </div>
                    </div>
                `;
                container.appendChild(div);
            });

            // If PREFILL_ROOM_ID is set, select it automatically after render
            if (window.PREFILL_ROOM_ID) {
                const roomBtn = document.querySelector(`[data-room-id="${window.PREFILL_ROOM_ID}"]`);
                if (roomBtn) {
                    roomBtn.click();
                }
                window.PREFILL_ROOM_ID = null; // Clear it so it doesn't auto-click on date changes
            }
        }
        window.toggleRoom = (roomId, roomNumber, catId, btnEl) => {
            const selectedPlanInput = document.querySelector(`input[name="rate_plan_${catId}"]:checked`);
            const ratePlanName = selectedPlanInput ? selectedPlanInput.value : 'Base Rate';
            
            let totalCost = 0;
            for(let cat of window.lastAvailableCategories) {
                if(cat.category_id == catId) {
                    for(let rp of cat.rate_plans) {
                        if(rp.name === ratePlanName) {
                            totalCost = rp.total_cost;
                        }
                    }
                }
            }

            // Toggle room selection
            const existingIndex = selectedRoomIds.findIndex(r => r.id === roomId);
            if (existingIndex >= 0) {
                // Deselect
                selectedRoomIds.splice(existingIndex, 1);
                selectedRoomsInfo.splice(existingIndex, 1);
                btnEl.classList.remove('border-indigo-600', 'bg-indigo-50');
                btnEl.classList.add('border-slate-150', 'bg-white');
            } else {
                // Select
                selectedRoomIds.push({ id: roomId, catId: catId });
                selectedRoomsInfo.push({ id: roomId, roomNumber, catId, ratePlanName, totalCost });
                btnEl.classList.remove('border-slate-150', 'bg-white');
                btnEl.classList.add('border-indigo-600', 'bg-indigo-50');
            }

            // Update selected count display
            updateSelectedRoomsBar();
            syncRoomHold();
        };

        function syncRoomHold() {
            clearTimeout(holdSyncTimer);
            holdSyncTimer = setTimeout(async () => {
                const csrf = document.querySelector('meta[name="csrf-token"]')?.content || '';
                const roomIds = selectedRoomIds.map(r => r.id);
                try {
                    const data = window.ApiClient
                        ? await ApiClient.apiFetch('/api/system/place_room_hold', {
                            method: 'POST',
                            body: JSON.stringify({
                                room_ids: roomIds,
                                check_in: getCheckIn(),
                                check_out: getCheckOut(),
                                hold_token: window.holdToken || ''
                            }),
                            toast: false
                        })
                        : await (await fetch('/api/system/place_room_hold', {
                            method: 'POST',
                            headers: {
                                'Content-Type': 'application/json',
                                'X-CSRF-Token': csrf
                            },
                            body: JSON.stringify({
                                room_ids: roomIds,
                                check_in: getCheckIn(),
                                check_out: getCheckOut(),
                                hold_token: window.holdToken || ''
                            })
                        })).json();
                    if (data.success && data.hold_token) {
                        window.holdToken = data.hold_token;
                    } else if (!data.success && data.message) {
                        showNotification(data.message, 'error');
                    }
                } catch (e) {
                    // Hold is best-effort; confirm still re-checks availability
                }
            }, 400);
        }

        function updateSelectedRoomsBar() {
            let bar = document.getElementById('selected-rooms-bar');
            if (!bar) {
                // Create the bar if it doesn't exist
                bar = document.createElement('div');
                bar.id = 'selected-rooms-bar';
                bar.className = 'fixed bottom-20 left-4 right-4 z-50 bg-indigo-900 text-white p-3 rounded-2xl shadow-lg flex items-center justify-between';
                document.body.appendChild(bar);
            }

            if (selectedRoomIds.length === 0) {
                bar.style.display = 'none';
                return;
            }

            bar.style.display = 'flex';
            const totalAmount = selectedRoomsInfo.reduce((sum, r) => sum + r.totalCost, 0);
            const roomNumbers = selectedRoomsInfo.map(r => r.roomNumber).join(', ');
            
            bar.innerHTML = `
                <div>
                    <div class="text-xs font-bold text-indigo-200">${selectedRoomIds.length} Room${selectedRoomIds.length > 1 ? 's' : ''} Selected</div>
                    <div class="text-sm font-bold">${roomNumbers}</div>
                </div>
                <div class="text-right">
                    <div class="text-xs text-indigo-200">Total</div>
                    <div class="text-lg font-extrabold">₹${totalAmount.toFixed(2)}</div>
                </div>
            `;
        }

        window.selectRoom = (roomId, roomNumber, catId) => {
            // Legacy single-select for backward compatibility
            toggleRoom(roomId, roomNumber, catId, document.querySelector(`[data-room-id="${roomId}"]`));
        };

        // Continue to guest details when rooms are selected
        window.proceedToGuestDetails = () => {
            if (selectedRoomIds.length === 0) {
                showNotification('Please select at least one room', 'error');
                return;
            }

            // Use first room's rate plan for pricing (all rooms same category/rate)
            const firstRoom = selectedRoomsInfo[0];
            window.selectedRatePlanName = firstRoom.ratePlanName;
            document.getElementById('modalRatePlan').value = firstRoom.ratePlanName;

            // Calculate total across all selected rooms
            const totalAmount = selectedRoomsInfo.reduce((sum, r) => sum + r.totalCost, 0);
            window.baseTotalCost = totalAmount;

            // Reset price override
            document.getElementById('price_override').value = '';

            document.getElementById('step-rooms').classList.add('hidden');
            const stepGuest = document.getElementById('step-guest');
            stepGuest.classList.remove('hidden');
            updateStepBar(3);

            // Hide the floating bar when moving to next step
            const bar = document.getElementById('selected-rooms-bar');
            if (bar) bar.style.display = 'none';

            updatePricingBreakdown();
        };

        window.updatePricingBreakdown = () => {
            if (selectedRoomIds.length === 0) return;
            
            const overrideVal = document.getElementById('price_override').value.trim();
            const customPrice = overrideVal !== '' ? parseFloat(overrideVal) : null;
            
            const ratePlanName = window.selectedRatePlanName;
            const taxEnabled = window.TAX_ENABLED;
            const taxRate = window.TAX_RATE || 0;
            const taxLabel = window.TAX_LABEL || 'Tax';
            
            // Track individual overrides
            if (!window.roomOverrides) {
                window.roomOverrides = {};
            }

            // Build per-room breakdown with individual edit options
            let roomBreakdownHtml = '';
            let totalBaseOverride = 0;
            let hasOverrides = false;

            selectedRoomsInfo.forEach(r => {
                const currentCost = window.roomOverrides[r.id] !== undefined ? window.roomOverrides[r.id] : r.totalCost;
                if (window.roomOverrides[r.id] !== undefined) {
                    hasOverrides = true;
                }
                totalBaseOverride += currentCost;

                const inlineEditHtml = `
                    <div class="flex items-center gap-1.5" id="room-price-wrapper-${r.id}">
                        <span class="text-slate-800 font-bold">₹${currentCost.toFixed(2)}</span>
                        <button onclick="enableRoomPriceEdit(${r.id}, ${currentCost})" class="text-[10px] text-indigo-600 hover:text-indigo-800 font-extrabold transition-colors cursor-pointer bg-indigo-50 hover:bg-indigo-100 px-1.5 py-0.5 rounded border border-indigo-100" title="Edit Room Price"><i class="ph ph-pencil-simple text-xs mr-0.5"></i>Edit</button>
                    </div>
                `;

                roomBreakdownHtml += `
                    <div class="flex justify-between items-center py-1 border-b border-slate-50 last:border-0">
                        <span>Room ${r.roomNumber} (${r.ratePlanName})</span>
                        ${inlineEditHtml}
                    </div>
                `;
            });

            const currentBaseCost = customPrice !== null ? customPrice : totalBaseOverride;
            let taxAmount = 0;
            let finalTotal = currentBaseCost;
            
            if (taxEnabled) {
                taxAmount = currentBaseCost * (taxRate / 100);
                finalTotal = currentBaseCost + taxAmount;
            }
            
            document.getElementById('modalTotalCost').value = finalTotal.toFixed(2);
            document.getElementById('price_override').value = hasOverrides ? currentBaseCost.toFixed(2) : '';
            
            // Build room list display
            const roomNumbers = selectedRoomsInfo.map(r => r.roomNumber).join(', ');
            const roomCount = selectedRoomIds.length;
            
            breakdownHtml = `
                <div class="mt-4 pt-3 border-t border-slate-100 space-y-1.5 text-xs font-semibold text-slate-600">
                    ${roomBreakdownHtml}
                    ${taxEnabled ? `
                    <div class="flex justify-between">
                        <span>${taxLabel} (${taxRate}%)</span>
                        <span class="text-slate-800">₹${taxAmount.toFixed(2)}</span>
                    </div>
                    ` : ''}
                    <div class="flex justify-between border-t border-slate-100 pt-2 text-sm font-extrabold text-slate-900 font-display items-center">
                        <span>Net Total</span>
                        <span class="text-indigo-600 text-base font-black">₹${finalTotal.toFixed(2)}</span>
                    </div>
                </div>
            `;

            document.getElementById('selected-room-info').innerHTML = `
                <div class="flex justify-between items-center mb-2.5">
                    <div class="font-bold text-base text-slate-800 font-display">Room${roomCount > 1 ? 's' : ''} ${roomNumbers}</div>
                    <div class="text-[10px] font-bold bg-indigo-50 text-indigo-600 px-2.5 py-1 rounded-lg border border-indigo-100">${ratePlanName}</div>
                </div>
                <div class="text-[11px] font-semibold text-slate-500 flex items-center gap-1.5"><i class="ph ph-clock text-sm text-slate-400"></i> ${getCheckIn().replace('T', ' ')} <i class="ph ph-arrow-right text-xs"></i> ${getCheckOut().replace('T', ' ')}</div>
                ${breakdownHtml}
            `;
        };

        window.enableRoomPriceEdit = (roomId, currentPrice) => {
            const wrapper = document.getElementById(`room-price-wrapper-${roomId}`);
            if (!wrapper) return;
            wrapper.innerHTML = `
                <div class="flex items-center gap-1 bg-white border border-slate-200 rounded-lg px-2 py-0.5 shadow-sm">
                    <span class="text-[10px] text-slate-400">₹</span>
                    <input type="number" id="override_room_${roomId}" value="${currentPrice}" class="w-12 text-right outline-none text-[11px] font-bold text-slate-800" min="0" step="0.01">
                    <button onclick="saveRoomPriceOverride(${roomId})" class="text-emerald-600 hover:bg-emerald-50 rounded" title="Save"><i class="ph ph-check text-[10px] font-bold"></i></button>
                    <button onclick="cancelRoomPriceOverride()" class="text-rose-600 hover:bg-rose-50 rounded" title="Cancel"><i class="ph ph-x text-[10px] font-bold"></i></button>
                </div>
            `;
            setTimeout(() => document.getElementById(`override_room_${roomId}`)?.focus(), 50);
        };

        window.saveRoomPriceOverride = (roomId) => {
            const val = document.getElementById(`override_room_${roomId}`).value.trim();
            if (val !== '') {
                window.roomOverrides[roomId] = parseFloat(val);
            }
            updatePricingBreakdown();
        };

        window.cancelRoomPriceOverride = () => {
            updatePricingBreakdown();
        };

        window.enablePriceEdit = () => {
            // Deprecated - replaced by room-level editing
        };
        
        window.saveInlinePriceOverride = () => {
            const val = document.getElementById('inline_price_override').value.trim();
            document.getElementById('price_override').value = val;
            updatePricingBreakdown();
        };
        
        window.cancelInlinePriceOverride = () => {
            updatePricingBreakdown();
        };

        document.getElementById('btn-back-dates').addEventListener('click', () => {
            const stepRooms = document.getElementById('step-rooms');
            const stepDates = document.getElementById('step-dates');
            if (stepRooms) {
                stepRooms.classList.add('hidden');
                stepRooms.style.display = 'none';
            }
            if (stepDates) {
                stepDates.classList.remove('hidden');
                stepDates.style.display = 'block';
            }
            updateStepBar(1);
            window.scrollTo({ top: 0, behavior: 'smooth' });
        });
        
        document.getElementById('btn-back-rooms').addEventListener('click', () => {
            document.getElementById('step-guest').classList.add('hidden');
            const stepRooms = document.getElementById('step-rooms');
            stepRooms.classList.remove('hidden');
            updateStepBar(2);
        });

        document.getElementById('btn-book').addEventListener('click', async () => {
            const checkIn = getCheckIn();
            const checkOut = getCheckOut();
            const guestName = document.getElementById('guest_name').value.trim();
            const phoneRaw = document.getElementById('guest_phone').value.trim();
            const countryCode = document.getElementById('country_code').value;
            const guestPhone = countryCode + phoneRaw;
            const ratePlan = document.getElementById('modalRatePlan').value;
            const bookingSource = document.getElementById('booking_source').value;
            
            let hasError = false;
            if(!guestName) {
                document.getElementById('guest-name-wrap').classList.add('animate-shake');
                setTimeout(() => document.getElementById('guest-name-wrap').classList.remove('animate-shake'), 400);
                hasError = true;
            }
            if(!/^\d{7,15}$/.test(phoneRaw.replace(/\D/g, ''))) {
                showNotification('Phone number must be valid (7-15 digits)', 'error');
                document.getElementById('guest-phone-wrap').classList.add('animate-shake');
                setTimeout(() => document.getElementById('guest-phone-wrap').classList.remove('animate-shake'), 400);
                hasError = true;
            }
            if(hasError) {
                showNotification('Please fill in required guest details', 'error');
                return;
            }
            
            const btn = document.getElementById('btn-book');
            const origHtml = btn.innerHTML;
            btn.innerHTML = '<i class="ph ph-spinner animate-spin mr-2 text-sm"></i> Confirming...';

            // Submit via FormData to support file uploads
            const formData = new FormData();
            // Send room IDs as JSON array for multi-room support
            const roomIds = selectedRoomIds.map(r => r.id);
            formData.append('room_ids', JSON.stringify(roomIds));
            formData.append('check_in', checkIn);
            formData.append('check_out', checkOut);
            formData.append('guest_name', guestName);
            formData.append('guest_phone', guestPhone);
            formData.append('rate_plan_name', ratePlan);
            formData.append('booking_source', bookingSource);
            
            const priceOverride = document.getElementById('price_override').value.trim();
            if(priceOverride) {
                formData.append('price_override', priceOverride);
            }

            if(window.roomOverrides && Object.keys(window.roomOverrides).length > 0) {
                formData.append('room_overrides', JSON.stringify(window.roomOverrides));
            }

            const payAmt = document.getElementById('payment_collected').value.trim();
            if(payAmt) {
                formData.append('payment_collected', payAmt);
                formData.append('payment_method', document.getElementById('payment_method').value);
            }

            const idFront = document.getElementById('id_proof_front').files[0];
            if(idFront) formData.append('id_proof_front', idFront);

            const idBack = document.getElementById('id_proof_back').files[0];
            if(idBack) formData.append('id_proof_back', idBack);

            const photo = document.getElementById('guest_photo').files[0];
            if(photo) formData.append('guest_photo', photo);

            const csrf = document.querySelector('meta[name="csrf-token"]')?.content || '';
            if (csrf) formData.append('_csrf_token', csrf);
            if (window.holdToken) formData.append('hold_token', window.holdToken);
            if (!window.bookingIdempotencyKey) {
                window.bookingIdempotencyKey = (crypto.randomUUID ? crypto.randomUUID() : String(Date.now()) + '-' + Math.random().toString(16).slice(2));
            }
            formData.append('idempotency_key', window.bookingIdempotencyKey);

            try {
                const data = window.ApiClient
                    ? await ApiClient.apiFetch('/api/system/create_hold', {
                        method: 'POST',
                        headers: { 'X-CSRF-Token': csrf },
                        body: formData
                    })
                    : await (await fetch('/api/system/create_hold', {
                        method: 'POST',
                        headers: { 'X-CSRF-Token': csrf },
                        body: formData
                    })).json();
                
                if(data.success) {
                    const count = data.booking_ids ? data.booking_ids.length : 1;
                    showNotification(`${count} Booking${count > 1 ? 's' : ''} confirmed!`, 'success');
                    setTimeout(() => {
                        // Redirect to first booking's folio
                        const firstId = (data.display_ids && data.display_ids[0]) || data.display_id || (data.booking_ids ? data.booking_ids[0] : data.booking_id);
                        window.location.href = `admin/folio.php?id=${encodeURIComponent(firstId)}`;
                    }, 1000);
                } else {
                    showNotification(data.message, 'error');
                    btn.innerHTML = origHtml;
                }
            } catch(e) {
                showNotification('Error creating booking', 'error');
                btn.innerHTML = origHtml;
            }
        });
        
        // Guest Autocomplete Logic
        let suggestionTimeout;

        function handleGuestSearch(e) {
            clearTimeout(suggestionTimeout);
            const q = e.target.value.trim();
            if(q.length < 2) {
                document.getElementById('guest-suggestions').classList.add('hidden');
                return;
            }
            
            suggestionTimeout = setTimeout(async () => {
                try {
                    const res = await fetch(`/api/system/search_guests?q=${encodeURIComponent(q)}`);
                    const data = await res.json();
                    
                    const container = document.getElementById('guest-suggestions');
                    if(data.success && data.guests.length > 0) {
                        container.innerHTML = '';
                        data.guests.forEach(g => {
                            const item = document.createElement('div');
                            item.className = 'p-3 hover:bg-slate-50 cursor-pointer border-b border-slate-100 last:border-0 transition-colors';
                            
                            const nameP = document.createElement('p');
                            nameP.className = 'font-bold text-xs text-slate-800';
                            nameP.textContent = g.guest_name;
                            
                            const phoneP = document.createElement('p');
                            phoneP.className = 'text-[10px] font-semibold text-slate-400 mt-0.5';
                            phoneP.textContent = g.guest_phone;
                            
                            item.appendChild(nameP);
                            item.appendChild(phoneP);
                            
                            item.addEventListener('click', () => {
                                selectGuest(g.guest_name, g.guest_phone);
                            });
                            container.appendChild(item);
                        });
                        container.classList.remove('hidden');
                    } else {
                        container.classList.add('hidden');
                    }
                } catch(err) {
                    console.error('Search failed', err);
                }
            }, 300);
        }

        document.getElementById('guest_phone').addEventListener('input', handleGuestSearch);
        document.getElementById('guest_name').addEventListener('input', handleGuestSearch);

        window.selectGuest = (name, phone) => {
            document.getElementById('guest_name').value = name;
            document.getElementById('guest_phone').value = phone;
            document.getElementById('guest-suggestions').classList.add('hidden');
            showNotification('Guest details prefilled!', 'success');
        };

        document.addEventListener('click', (e) => {
            if(!e.target.closest('#guest-suggestions') && e.target.id !== 'guest_phone' && e.target.id !== 'guest_name') {
                document.getElementById('guest-suggestions').classList.add('hidden');
            }
        });

        // Trigger availability check automatically if prefilled parameters are in the URL on load
        if (window.PREFILL_ROOM_ID) {
            document.addEventListener('DOMContentLoaded', () => {
                setTimeout(() => {
                    checkAvailability();
                }, 100);
            });
        }