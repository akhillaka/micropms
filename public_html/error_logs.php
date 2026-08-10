<?php
require_once __DIR__ . '/../pms_core/AuthHelper.php';
AuthHelper::requireLogin();
$role = AuthHelper::getRole();
if (!in_array($role, ['superadmin', 'owner', 'admin'], true)) {
    header("HTTP/1.1 403 Forbidden");
    echo "Access Denied";
    exit;
}

$pageTitle = "System Error Logs";
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Error Logs - MicroPMS</title>
    <link href="https://cdn.jsdelivr.net/npm/tailwindcss@2.2.19/dist/tailwind.min.css" rel="stylesheet">
    <link href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.0.0/css/all.min.css" rel="stylesheet">
    <link href="/css/style.css" rel="stylesheet">
</head>
<body class="bg-gray-50 text-gray-800">
    <!-- Navbar -->
    <?php include 'navbar.php'; ?>

    <div class="container mx-auto px-4 py-8 max-w-7xl">
        <div class="flex justify-between items-center mb-6">
            <h1 class="text-2xl font-bold text-gray-800">System Error Logs</h1>
            <div class="flex space-x-2">
                <button id="refreshBtn" class="bg-blue-600 hover:bg-blue-700 text-white px-4 py-2 rounded shadow transition flex items-center">
                    <i class="fas fa-sync-alt mr-2"></i> Refresh
                </button>
            </div>
        </div>

        <!-- Filters -->
        <div class="bg-white p-4 rounded-lg shadow mb-6">
            <form id="filterForm" class="flex flex-wrap gap-4 items-end">
                <div class="w-full md:w-auto">
                    <label class="block text-sm font-medium text-gray-700 mb-1">Status</label>
                    <select id="statusFilter" class="border border-gray-300 rounded-md shadow-sm p-2 w-full">
                        <option value="new">New</option>
                        <option value="resolved">Resolved</option>
                        <option value="all">All</option>
                    </select>
                </div>
                <div class="w-full md:w-auto">
                    <label class="block text-sm font-medium text-gray-700 mb-1">Severity</label>
                    <select id="severityFilter" class="border border-gray-300 rounded-md shadow-sm p-2 w-full">
                        <option value="">All</option>
                        <option value="error">Error</option>
                        <option value="critical">Critical</option>
                        <option value="warning">Warning</option>
                    </select>
                </div>
                <div class="w-full md:w-auto mt-4 md:mt-0">
                    <button type="submit" class="bg-gray-800 hover:bg-gray-900 text-white px-4 py-2 rounded shadow transition w-full">
                        Apply Filters
                    </button>
                </div>
            </form>
        </div>

        <!-- Logs Table -->
        <div class="bg-white rounded-lg shadow overflow-hidden">
            <div class="overflow-x-auto">
                <table class="min-w-full divide-y divide-gray-200">
                    <thead class="bg-gray-50">
                        <tr>
                            <th class="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider">Timestamp</th>
                            <th class="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider">Severity</th>
                            <th class="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider">Message</th>
                            <th class="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider">Context</th>
                            <th class="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider">Status</th>
                            <th class="px-6 py-3 text-right text-xs font-medium text-gray-500 uppercase tracking-wider">Actions</th>
                        </tr>
                    </thead>
                    <tbody id="logsTableBody" class="bg-white divide-y divide-gray-200">
                        <!-- Populated by JS -->
                        <tr><td colspan="6" class="px-6 py-4 text-center text-gray-500">Loading logs...</td></tr>
                    </tbody>
                </table>
            </div>
            <!-- Pagination -->
            <div class="bg-white px-4 py-3 border-t border-gray-200 flex items-center justify-between sm:px-6">
                <div class="hidden sm:flex-1 sm:flex sm:items-center sm:justify-between">
                    <div>
                        <p class="text-sm text-gray-700">
                            Showing <span class="font-medium" id="pageStart">1</span> to <span class="font-medium" id="pageEnd">10</span> of <span class="font-medium" id="totalRecords">0</span> results
                        </p>
                    </div>
                    <div>
                        <nav class="relative z-0 inline-flex rounded-md shadow-sm -space-x-px" aria-label="Pagination">
                            <button id="prevPage" class="relative inline-flex items-center px-2 py-2 rounded-l-md border border-gray-300 bg-white text-sm font-medium text-gray-500 hover:bg-gray-50">
                                Previous
                            </button>
                            <button id="nextPage" class="relative inline-flex items-center px-2 py-2 rounded-r-md border border-gray-300 bg-white text-sm font-medium text-gray-500 hover:bg-gray-50">
                                Next
                            </button>
                        </nav>
                    </div>
                </div>
            </div>
        </div>
    </div>

    <!-- Details Modal -->
    <div id="detailsModal" class="fixed inset-0 bg-gray-600 bg-opacity-50 hidden flex items-center justify-center z-50">
        <div class="bg-white rounded-lg shadow-xl w-full max-w-4xl mx-4 max-h-screen overflow-hidden flex flex-col">
            <div class="flex justify-between items-center px-6 py-4 border-b">
                <h3 class="text-lg font-medium text-gray-900" id="modalTitle">Error Details</h3>
                <button class="text-gray-400 hover:text-gray-500 focus:outline-none" onclick="closeModal()">
                    <i class="fas fa-times text-xl"></i>
                </button>
            </div>
            <div class="px-6 py-4 overflow-y-auto flex-1">
                <div class="mb-4">
                    <h4 class="font-bold text-gray-700">Message</h4>
                    <p id="modalMessage" class="text-gray-800 bg-gray-100 p-3 rounded"></p>
                </div>
                <div class="mb-4">
                    <h4 class="font-bold text-gray-700">Stack Trace</h4>
                    <pre id="modalStack" class="text-sm text-red-600 bg-gray-100 p-3 rounded overflow-x-auto whitespace-pre-wrap"></pre>
                </div>
                <div class="mb-4">
                    <h4 class="font-bold text-gray-700">Request Data</h4>
                    <pre id="modalRequest" class="text-sm text-gray-800 bg-gray-100 p-3 rounded overflow-x-auto whitespace-pre-wrap"></pre>
                </div>
            </div>
            <div class="px-6 py-4 border-t bg-gray-50 flex justify-end space-x-3">
                <button onclick="closeModal()" class="px-4 py-2 bg-white border border-gray-300 rounded-md shadow-sm text-sm font-medium text-gray-700 hover:bg-gray-50">Close</button>
                <button id="resolveBtn" class="px-4 py-2 bg-green-600 border border-transparent rounded-md shadow-sm text-sm font-medium text-white hover:bg-green-700 hidden">Mark as Resolved</button>
            </div>
        </div>
    </div>

    <script src="/js/error_logs.js"></script>
</body>
</html>
