const fs = require('fs');
const file = '/Users/lakaakhilyadav/Documents/s/public_html/admin/modules/pos/pos.php';
let content = fs.readFileSync(file, 'utf8');

// Remove custom CSS block completely, keeping ui_head
content = content.replace(/<style>[\s\S]*?<\/style>/, `
    <style>
        .stayflexi-badge {
            display: inline-flex;
            align-items: center;
            padding: 0.125rem 0.625rem;
            border-radius: 9999px;
            font-size: 0.65rem;
            font-weight: 700;
            text-transform: uppercase;
            letter-spacing: 0.05em;
        }
        .folio-grid-col {
            padding-bottom: 12px;
        }
    </style>`);

// Replace body class
content = content.replace(/<body class="flex flex-col min-h-screen">/, '<body class="bg-slate-50/50 flex flex-col min-h-screen">');

// Header
content = content.replace(
    /<header class="bg-white border-b border-slate-200 px-5 py-4 flex items-center justify-between sticky top-0 z-30 shadow-sm">/,
    '<header class="bg-white px-6 py-4 flex items-center justify-between border-b border-slate-100 sticky top-0 z-50 shadow-sm mb-6">'
);

content = content.replace(
    /text-xl font-bold tracking-tight flex items-center gap-2 text-slate-800 font-display/,
    'text-base font-bold text-slate-900 leading-none font-display'
);

content = content.replace(
    /<i class="ph ph-storefront text-amber-600"><\/i> POS & Central Stock/,
    'POS & Central Stock\n                <span class="text-[10px] font-semibold text-slate-400 uppercase tracking-wider mt-1 inline-block font-sans">Point of Sale</span>'
);

// Main Grid Container
content = content.replace(
    /<div class="flex-1 max-w-7xl w-full mx-auto p-4 md:p-6 grid grid-cols-1 lg:grid-cols-12 gap-6 pb-24">/,
    '<div class="flex-1 max-w-7xl w-full mx-auto px-6 grid grid-cols-1 lg:grid-cols-12 gap-6 pb-24">'
);


// Tabs
content = content.replace(
    /<div class="flex bg-white p-1 rounded-xl border border-slate-200 max-w-2xl overflow-x-auto no-scrollbar shadow-sm">/,
    '<div class="flex bg-white p-1.5 rounded-2xl border border-slate-100 max-w-2xl overflow-x-auto no-scrollbar shadow-sm gap-1">'
);

content = content.replace(/btn-tab flex-1 py-2 rounded-lg text-xs font-bold active text-white bg-amber-600 shrink-0 px-3 cursor-pointer/g, 'flex-1 py-2 rounded-xl text-xs font-bold bg-indigo-50 text-indigo-700 shrink-0 px-3 cursor-pointer transition');
content = content.replace(/btn-tab flex-1 py-2 rounded-lg text-xs font-bold hover:text-slate-800 relative shrink-0 px-3 cursor-pointer/g, 'flex-1 py-2 rounded-xl text-xs font-bold text-slate-500 hover:text-slate-700 hover:bg-slate-50 relative shrink-0 px-3 cursor-pointer transition');
content = content.replace(/btn-tab flex-1 py-2 rounded-lg text-xs font-bold hover:text-slate-800 shrink-0 px-3 cursor-pointer/g, 'flex-1 py-2 rounded-xl text-xs font-bold text-slate-500 hover:text-slate-700 hover:bg-slate-50 shrink-0 px-3 cursor-pointer transition');

content = content.replace(
    /document\.getElementById\('tabBtn-' \+ tabId\)\.className = 'btn-tab flex-1 py-2 rounded-lg text-xs font-bold text-white bg-amber-600 shrink-0 px-3 cursor-pointer';/,
    "document.getElementById('tabBtn-' + tabId).className = 'flex-1 py-2 rounded-xl text-xs font-bold bg-indigo-50 text-indigo-700 shrink-0 px-3 cursor-pointer transition';"
);

content = content.replace(
    /document\.getElementById\('tabBtn-register'\)\.className = 'btn-tab flex-1 py-2 rounded-lg text-xs font-bold text-slate-500 hover:text-slate-800 shrink-0 px-3 cursor-pointer';/g,
    "document.getElementById('tabBtn-register').className = 'flex-1 py-2 rounded-xl text-xs font-bold text-slate-500 hover:text-slate-700 hover:bg-slate-50 shrink-0 px-3 cursor-pointer transition';"
);
content = content.replace(
    /document\.getElementById\('tabBtn-inventory'\)\.className = 'btn-tab flex-1 py-2 rounded-lg text-xs font-bold text-slate-500 hover:text-slate-800 shrink-0 px-3 cursor-pointer';/g,
    "document.getElementById('tabBtn-inventory').className = 'flex-1 py-2 rounded-xl text-xs font-bold text-slate-500 hover:text-slate-700 hover:bg-slate-50 shrink-0 px-3 cursor-pointer transition';"
);
content = content.replace(
    /document\.getElementById\('tabBtn-orders'\)\.className = 'btn-tab flex-1 py-2 rounded-lg text-xs font-bold text-slate-500 hover:text-slate-800 relative shrink-0 px-3 cursor-pointer';/g,
    "document.getElementById('tabBtn-orders').className = 'flex-1 py-2 rounded-xl text-xs font-bold text-slate-500 hover:text-slate-700 hover:bg-slate-50 relative shrink-0 px-3 cursor-pointer transition';"
);
content = content.replace(
    /document\.getElementById\('tabBtn-history'\)\.className = 'btn-tab flex-1 py-2 rounded-lg text-xs font-bold text-slate-500 hover:text-slate-800 shrink-0 px-3 cursor-pointer';/g,
    "document.getElementById('tabBtn-history').className = 'flex-1 py-2 rounded-xl text-xs font-bold text-slate-500 hover:text-slate-700 hover:bg-slate-50 shrink-0 px-3 cursor-pointer transition';"
);

// Replace card-premium with new card style
content = content.replace(/card-premium/g, 'bg-white border border-slate-100 rounded-2xl shadow-sm');

// Replace input-premium
content = content.replace(/input-premium/g, 'focus:ring-2 focus:ring-indigo-100 focus:border-indigo-500');

// Replace amber colors to indigo
content = content.replace(/amber-600/g, 'indigo-600');
content = content.replace(/amber-700/g, 'indigo-700');
content = content.replace(/bg-amber-50/g, 'bg-indigo-50');
content = content.replace(/text-amber-500/g, 'text-indigo-500');
content = content.replace(/text-amber-600/g, 'text-indigo-600');
content = content.replace(/border-amber-200/g, 'border-indigo-200');
content = content.replace(/border-amber-600/g, 'border-indigo-600');
content = content.replace(/focus:border-amber-600/g, 'focus:border-indigo-600');

// Custom badge replacements
content = content.replace(/px-1\.5 py-0\.5 rounded text-\[8px\] font-bold bg-rose-50 text-rose-600 border border-rose-200 uppercase/g, 'stayflexi-badge bg-rose-50 text-rose-600 border-rose-200 border');
content = content.replace(/px-1\.5 py-0\.5 rounded text-\[8px\] font-bold bg-indigo-50 text-indigo-600 border border-indigo-200 uppercase/g, 'stayflexi-badge bg-indigo-50 text-indigo-600 border-indigo-200 border');

// Table styling
content = content.replace(/<table class="w-full text-xs text-left text-slate-800">/g, '<table class="table-brutal">');
content = content.replace(/<table class="w-full text-xs text-left">/g, '<table class="table-brutal">');
// Remove custom table head styles because table-brutal handles it
content = content.replace(/<thead class="bg-slate-50 text-\[10px\] font-bold text-slate-500 uppercase tracking-wider border-b border-slate-200">/g, '<thead>');
content = content.replace(/<tbody class="divide-y divide-slate-150">/g, '<tbody>');
content = content.replace(/<tbody class="divide-y divide-slate-150 text-slate-700">/g, '<tbody>');


fs.writeFileSync(file, content);
console.log('Replacements complete');
