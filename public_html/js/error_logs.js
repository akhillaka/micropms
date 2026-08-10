document.addEventListener('DOMContentLoaded', () => {
    let currentPage = 1;
    let currentLogs = [];

    const tableBody = document.getElementById('logsTableBody');
    const filterForm = document.getElementById('filterForm');
    const refreshBtn = document.getElementById('refreshBtn');
    
    // Pagination elements
    const prevBtn = document.getElementById('prevPage');
    const nextBtn = document.getElementById('nextPage');

    const loadLogs = async () => {
        const status = document.getElementById('statusFilter').value;
        const severity = document.getElementById('severityFilter').value;
        
        tableBody.innerHTML = '<tr><td colspan="6" class="px-6 py-4 text-center text-gray-500">Loading logs...</td></tr>';
        
        try {
            const res = await fetch(`/api/admin_error_logs?page=${currentPage}&status=${status}&severity=${severity}`);
            const data = await res.json();
            
            if (data.status === 'success') {
                currentLogs = data.data.logs;
                renderLogs(data.data.logs);
                updatePagination(data.data.pagination);
            } else {
                tableBody.innerHTML = `<tr><td colspan="6" class="px-6 py-4 text-center text-red-500">Error: ${data.message}</td></tr>`;
            }
        } catch (e) {
            tableBody.innerHTML = `<tr><td colspan="6" class="px-6 py-4 text-center text-red-500">Failed to load logs.</td></tr>`;
        }
    };

    const renderLogs = (logs) => {
        tableBody.innerHTML = '';
        if (logs.length === 0) {
            tableBody.innerHTML = '<tr><td colspan="6" class="px-6 py-4 text-center text-gray-500">No logs found.</td></tr>';
            return;
        }

        logs.forEach(log => {
            const severityClass = log.severity === 'error' || log.severity === 'critical' ? 'text-red-600 bg-red-100' : 'text-yellow-600 bg-yellow-100';
            const statusClass = log.status === 'resolved' ? 'text-green-600 bg-green-100' : 'text-gray-600 bg-gray-100';
            
            const tr = document.createElement('tr');
            tr.innerHTML = `
                <td class="px-6 py-4 whitespace-nowrap text-sm text-gray-500">${new Date(log.created_at).toLocaleString()}</td>
                <td class="px-6 py-4 whitespace-nowrap"><span class="px-2 inline-flex text-xs leading-5 font-semibold rounded-full ${severityClass}">${log.severity}</span></td>
                <td class="px-6 py-4 text-sm text-gray-900 break-words max-w-xs truncate" title="${log.error_message}">${log.error_message}</td>
                <td class="px-6 py-4 text-sm text-gray-500">${log.context}</td>
                <td class="px-6 py-4 whitespace-nowrap"><span class="px-2 inline-flex text-xs leading-5 font-semibold rounded-full ${statusClass}">${log.status}</span></td>
                <td class="px-6 py-4 whitespace-nowrap text-right text-sm font-medium">
                    <button class="text-indigo-600 hover:text-indigo-900 view-btn" data-id="${log.id}">View</button>
                </td>
            `;
            tableBody.appendChild(tr);
        });

        document.querySelectorAll('.view-btn').forEach(btn => {
            btn.addEventListener('click', (e) => {
                const id = parseInt(e.target.getAttribute('data-id'));
                openModal(id);
            });
        });
    };

    const updatePagination = (p) => {
        document.getElementById('totalRecords').innerText = p.total;
        document.getElementById('pageStart').innerText = p.total === 0 ? 0 : (p.page - 1) * p.limit + 1;
        document.getElementById('pageEnd').innerText = Math.min(p.page * p.limit, p.total);
        
        prevBtn.disabled = p.page === 1;
        nextBtn.disabled = p.page === p.pages;
    };

    filterForm.addEventListener('submit', (e) => {
        e.preventDefault();
        currentPage = 1;
        loadLogs();
    });

    refreshBtn.addEventListener('click', () => loadLogs());
    
    prevBtn.addEventListener('click', () => {
        if (!prevBtn.disabled) {
            currentPage--;
            loadLogs();
        }
    });

    nextBtn.addEventListener('click', () => {
        if (!nextBtn.disabled) {
            currentPage++;
            loadLogs();
        }
    });

    // Modal Handling
    window.openModal = (id) => {
        const log = currentLogs.find(l => l.id === id);
        if (!log) return;

        document.getElementById('modalTitle').innerText = `Error #${log.id}`;
        document.getElementById('modalMessage').innerText = log.error_message;
        document.getElementById('modalStack').innerText = log.stack_trace || 'No stack trace available';
        document.getElementById('modalRequest').innerText = log.request_data || 'No request data available';
        
        const resolveBtn = document.getElementById('resolveBtn');
        if (log.status !== 'resolved') {
            resolveBtn.classList.remove('hidden');
            resolveBtn.onclick = () => resolveLog(log.id);
        } else {
            resolveBtn.classList.add('hidden');
        }

        document.getElementById('detailsModal').classList.remove('hidden');
    };

    window.closeModal = () => {
        document.getElementById('detailsModal').classList.add('hidden');
    };

    window.resolveLog = async (id) => {
        if (!confirm('Mark this error as resolved?')) return;
        
        try {
            const res = await fetch('/api/admin_error_logs', {
                method: 'POST',
                headers: { 'Content-Type': 'application/json' },
                body: JSON.stringify({ action: 'resolve', log_ids: [id] })
            });
            const data = await res.json();
            if (data.status === 'success') {
                closeModal();
                loadLogs();
            } else {
                alert('Failed to resolve log: ' + data.message);
            }
        } catch (e) {
            alert('Error resolving log');
        }
    };

    // Init
    loadLogs();
});
