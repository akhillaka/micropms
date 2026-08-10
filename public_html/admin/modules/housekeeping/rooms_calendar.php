<?php
require_once __DIR__ . '/../../../../pms_core/CsrfToken.php';
require_once __DIR__ . '/../../../../pms_core/AuthHelper.php';
require_once __DIR__ . '/../../../../pms_core/services/SaaSEntitlementsService.php';
require_once __DIR__ . '/../../../../pms_core/Database.php';

AuthHelper::requireLoginOrRedirect();
CsrfToken::checkTimeout();

$db = Database::getInstance()->getConnection();
$propertyId = AuthHelper::getPropertyId();
$hkEnabled = SaaSEntitlementsService::isFeatureEnabled($db, $propertyId, 'housekeeping_module');
if (!$hkEnabled) {
    ?>
    <!DOCTYPE html>
    <html lang="en">
    <head>
        <meta charset="UTF-8">
        <meta name="viewport" content="width=device-width, initial-scale=1.0">
        <title>Housekeeping Upgrade Required | StayFlexi</title>
        <?php include __DIR__ . '/../../components/ui_head.php'; ?>
        <style>
            @import url('https://fonts.googleapis.com/css2?family=Karla:wght@400;700&display=swap');
            body { font-family: 'Karla', sans-serif; background-color: #f8fafc; color: #1e3a8a; }
        </style>
    </head>
    <body class="flex flex-col min-h-screen items-center justify-center p-6 text-center">
        <div class="max-w-md w-full bg-white border border-slate-200 p-8 rounded-2xl shadow-md space-y-5">
            <div class="w-16 h-16 bg-amber-50 rounded-full flex items-center justify-center mx-auto border border-amber-200 text-amber-600">
                <i class="ph ph-lock text-3xl"></i>
            </div>
            <h2 class="text-xl font-bold tracking-tight text-slate-800">Housekeeping Module Upgrade Needed</h2>
            <p class="text-xs text-slate-500 font-semibold leading-relaxed">
                Your current subscription tier does not have the **Housekeeping & Rooms Management** module enabled. 
                Upgrade your subscription plan to gain access to dynamic rooms calendars, calendar status tracking, and housekeeping logs.
            </p>
            <div class="pt-2 flex flex-col gap-2">
                <a href="../../settings.php?tab=subscription" class="px-5 py-3 bg-amber-600 hover:bg-amber-700 text-white rounded-xl text-xs font-bold transition shadow cursor-pointer">Upgrade Subscription Plan</a>
                <a href="../../index.php" class="px-5 py-3 bg-slate-100 hover:bg-slate-200 text-slate-700 rounded-xl text-xs font-bold transition cursor-pointer">Back to Dashboard</a>
            </div>
        </div>
    </body>
    </html>
    <?php
    exit;
}

$view = $_GET['view'] ?? 'hourly';
$date = $_GET['date'] ?? date('Y-m-d');

$days = [];
if ($view === 'weekly') {
    $ws = date('Y-m-d', strtotime('monday this week', strtotime($date)));
    for ($i = 0; $i < 7; $i++) {
        $d = date('Y-m-d', strtotime("$ws +$i days"));
        $days[] = [
            'date' => $d,
            'dn'   => date('D', strtotime($d)),
            'dd'   => date('j', strtotime($d)),
            'dm'   => date('M', strtotime($d)),
        ];
    }
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <?= CsrfToken::meta() ?>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Reservation Calendar | MicroPMS</title>
    
    <?php include __DIR__ . '/../../components/mobile_nav.php'; ?>
    <?php include __DIR__ . '/../../components/ui_head.php'; ?>
    <link rel="stylesheet" href="../../css/style.css">
    <style> 
        /* Calendar Grid Styles - StayFlexi / Minimalist inspired */
        .bk {
            position: absolute;
            top: 6px;
            bottom: 6px;
            border-radius: 8px;
            font-size: 11px;
            font-weight: 600;
            padding: 6px 10px;
            overflow: hidden;
            white-space: nowrap;
            text-overflow: ellipsis;
            display: flex;
            align-items: center;
            border: 1px solid rgba(0, 0, 0, 0.05);
            text-decoration: none;
            z-index: 5;
            transition: all 0.2s cubic-bezier(0.16, 1, 0.3, 1);
        }
        .bk:hover {
            transform: translateY(-1px);
            box-shadow: 0 4px 12px rgba(0, 0, 0, 0.08);
            z-index: 10;
        }
        .bk-booked {
            background-color: #EFF6FF;
            color: #1D4ED8;
            border-color: #DBEAFE;
        }
        .bk-checked_in {
            background-color: #F5F3FF;
            color: #6D28D9;
            border-color: #EDE9FE;
        }
        .bk-checked_out {
            background-color: #F1F5F9;
            color: #475569;
            border-color: #E2E8F0;
        }
        .slot {
            border-right: 1px dashed rgba(226, 232, 240, 0.6);
            box-sizing: border-box;
        }
        .slot-hl {
            background: rgba(79, 70, 229, 0.02);
        }
        .nl {
            position: absolute;
            top: 0;
            bottom: 0;
            width: 1px;
            background: #4F46E5;
            z-index: 20;
            pointer-events: none;
        }
        .nll {
            position: absolute;
            top: -20px;
            left: -15px;
            background: #4F46E5;
            color: white;
            font-size: 9px;
            padding: 2px 6px;
            border-radius: 6px;
            font-weight: 600;
            box-shadow: 0 2px 4px rgba(79, 70, 229, 0.15);
        }
        .room-label {
            display: flex;
            flex-direction: column;
            justify-content: center;
            align-items: center;
            border-right: 1px solid rgba(226, 232, 240, 0.8);
            background: rgba(255, 255, 255, 0.95);
            backdrop-filter: blur(8px);
            position: sticky;
            left: 0;
            z-index: 15;
        }
        .scroll-x {
            overflow-x: auto;
            overflow-y: hidden;
            scroll-behavior: smooth;
        }
        
        /* Category styling */
        .category-row-label {
            display: flex;
            align-items: center;
            justify-content: space-between;
            padding: 0 10px;
            border-right: 1px solid rgba(226, 232, 240, 0.8);
            background: #F8FAFC;
            font-weight: 700;
            font-size: 10px;
            text-transform: uppercase;
            letter-spacing: 0.05em;
            color: #64748B;
            position: sticky;
            left: 0;
            z-index: 16;
            cursor: pointer;
        }
        .category-row-label:hover {
            color: #4F46E5;
            background: #F1F5F9;
        }
    </style>
</head>
<body class="bg-brand-50" style="display:flex;flex-direction:column;height:100vh;">

<header class="bg-white px-6 py-4 flex items-center justify-between border-b border-slate-200/80 sticky top-0 z-50 shadow-sm" style="flex-shrink:0;">
    <div class="flex items-center gap-3">
        <a href="../../index.php" class="p-2 -ml-2 rounded-full hover:bg-slate-100 transition-colors"><i class="ph ph-caret-left text-2xl text-slate-700"></i></a>
        <div>
            <h1 class="text-xl font-bold text-slate-900 leading-none">Reservation Calendar</h1>
            <p class="text-[11px] font-semibold text-slate-500 uppercase tracking-wider mt-1" id="subtitle"></p>
        </div>
    </div>
    <div class="flex items-center gap-3">
        <div class="flex bg-slate-100 rounded-xl p-1">
            <a href="?view=hourly&date=<?= htmlspecialchars((string)($date), ENT_QUOTES, 'UTF-8') ?>" class="px-4 py-2 text-xs font-bold rounded-lg transition-all <?= htmlspecialchars((string)($view==='hourly'?'bg-white text-indigo-600 shadow-sm':'text-slate-600 hover:text-slate-900'), ENT_QUOTES, 'UTF-8') ?>"><i class="ph ph-clock mr-1"></i>Hourly</a>
            <a href="?view=weekly&date=<?= htmlspecialchars((string)($date), ENT_QUOTES, 'UTF-8') ?>" class="px-4 py-2 text-xs font-bold rounded-lg transition-all <?= htmlspecialchars((string)($view==='weekly'?'bg-white text-indigo-600 shadow-sm':'text-slate-600 hover:text-slate-900'), ENT_QUOTES, 'UTF-8') ?>"><i class="ph ph-calendar-blank mr-1"></i>Weekly</a>
        </div>
        <div class="flex items-center gap-1">
            <button onclick="nav(-1)" class="p-2 rounded-full hover:bg-slate-100 text-slate-600 transition-colors"><i class="ph ph-caret-left text-lg"></i></button>
            <button onclick="goToday()" class="px-3.5 py-2 text-xs font-bold text-indigo-600 bg-indigo-50 hover:bg-indigo-100 rounded-xl transition-all">Today</button>
            <button onclick="nav(1)" class="p-2 rounded-full hover:bg-slate-100 text-slate-600 transition-colors"><i class="ph ph-caret-right text-lg"></i></button>
        </div>
        <?php include __DIR__ . '/../../components/desktop_nav.php'; ?>
    </div>
</header>

<div class="bg-white border-b border-slate-100 px-6 py-2.5 flex items-center gap-5 text-[10px] font-bold uppercase tracking-wider flex-wrap text-slate-500" style="flex-shrink:0;">
    <span class="flex items-center gap-1.5"><span class="w-3.5 h-3.5 rounded-md border border-emerald-200" style="background:#ECFDF5;"></span>Clean</span>
    <span class="flex items-center gap-1.5"><span class="w-3.5 h-3.5 rounded-md border border-amber-200" style="background:#FFFBEB;"></span>Dirty</span>
    <span class="flex items-center gap-1.5"><span class="w-3.5 h-3.5 rounded-md border border-blue-200" style="background:#EFF6FF;"></span>Booked</span>
    <span class="flex items-center gap-1.5"><span class="w-3.5 h-3.5 rounded-md border border-purple-200" style="background:#F5F3FF;"></span>Checked In</span>
    <span class="flex items-center gap-1.5"><span class="w-3.5 h-3.5 rounded-md border border-slate-200" style="background:#F1F5F9;"></span>Checked Out</span>
    <span class="flex items-center gap-1.5"><span class="w-1.5 h-3.5 rounded-full bg-indigo-600"></span>Now</span>
</div>

<div id="cal" style="flex:1;" class="bg-white">
    <div class="flex items-center justify-center h-full text-slate-400"><i class="ph ph-spinner animate-spin mr-2 text-xl"></i>Loading...</div>
</div>

<script>
(function(){
    var VIEW=<?=json_encode($view)?>;
    var CAL_DATE=<?=json_encode($date)?>;
    var WEEK=<?=json_encode($days)?>;
    var CELL_W=VIEW==='hourly'?80:140;
    var ROW_H=60;
    var ROOM_W=80;
    var rooms=[],bookings=[],timer=null;
    var collapsedCategories = {}; // Tracks collapsed room category rows

    /* ═══ IST TIMEZONE ═══ */
    function nowIST(){
        var d=new Date();
        var ist=new Date(d.getTime()+5.5*3600000);
        return{
            y:ist.getUTCFullYear(),mo:ist.getUTCMonth(),day:ist.getUTCDate(),
            h:ist.getUTCHours(),mi:ist.getUTCMinutes(),
            dec:ist.getUTCHours()+ist.getUTCMinutes()/60,
            str:ist.getUTCFullYear()+'-'+String(ist.getUTCMonth()+1).padStart(2,'0')+'-'+String(ist.getUTCDate()).padStart(2,'0')
        };
    }
    function fmtTime(h,m){
        var p=h<12?'AM':'PM';
        var dh=h===0?12:h<=12?h:h-12;
        return dh+':'+String(m).padStart(2,'0')+' '+p;
    }

    /* ═══ NAVIGATION ═══ */
    function addDays(ds,n){
        var d=new Date(ds+'T12:00:00Z');
        d.setUTCDate(d.getUTCDate()+n);
        return d.getUTCFullYear()+'-'+String(d.getUTCMonth()+1).padStart(2,'0')+'-'+String(d.getUTCDate()).padStart(2,'0');
    }
    window.nav=function(dir){location.href='?view='+VIEW+'&date='+addDays(CAL_DATE,dir*(VIEW==='weekly'?7:1))};
    window.goToday=function(){location.href='?view='+VIEW+'&date='+nowIST().str};

    /* ═══ DATA ═══ */
    async function loadData(){
        var s,e;
        if(VIEW==='hourly'){s=e=CAL_DATE}else{s=WEEK[0].date;e=WEEK[6].date}
        try{var r=await fetch('/api/admin/calendar_data?start='+s+'&end='+e);var j=await r.json();if(j.success){rooms=j.rooms;bookings=j.bookings}}catch(x){}
    }

    /* ═══ ROOM STATE ═══ */
    function roomIcon(st){
        if(st==='dirty')return{c:'bg-warning-500',i:'ph-broom'};
        return{c:'bg-success-500',i:'ph-check-circle'};
    }
    function roomLabel(rm){
        var bg = 'rgba(255, 255, 255, 0.95)';
        var act = '';
        if(rm.state === 'dirty') {
            bg = '#FEF3C7'; // Amber-100
            act = '<button onclick="mc('+rm.id+',this)" class="mt-1 w-5 h-5 border border-amber-300 rounded-full bg-amber-500 text-white flex items-center justify-center hover:scale-105 cursor-pointer shadow-sm transition-all"><i class="ph ph-broom text-[8px] font-bold"></i></button>';
        } else if (rm.state === 'out_of_order') {
            bg = '#FEE2E2'; // Rose-100
            act = '<span class="mt-1 w-5 h-5 border border-rose-300 rounded-full bg-rose-500 text-white flex items-center justify-center shadow-sm"><i class="ph ph-warning-octagon text-[8px] font-bold"></i></span>';
        } else {
            act = '<span class="mt-1 w-5 h-5 border border-emerald-200 rounded-full bg-emerald-500 text-white flex items-center justify-center shadow-sm"><i class="ph ph-check text-[8px] font-bold"></i></span>';
        }
        return '<div class="room-label" style="width:'+ROOM_W+'px;height:'+ROW_H+'px;background:'+bg+';">'
            +'<span style="font-size:12px;font-weight:700;color:#1E293B;">'+rm.room_number+'</span>'
            +act+'</div>';
    }

    function esc(s){var d=document.createElement('div');d.innerText=s;return d.innerHTML;}
    
    /* ═══ TOOLTIP HELPERS ═══ */
    window.showTooltip = function(e, bJsonStr) {
        var b = JSON.parse(decodeURIComponent(bJsonStr));
        var rm = rooms.find(function(r){return r.id===b.room_id});
        var rn = rm ? rm.room_number : '—';
        var statusLabel = {
            'booked': 'Booked (Reserved)',
            'checked_in': 'Checked In (In-house)',
            'checked_out': 'Checked Out',
            'cancelled': 'Cancelled'
        }[b.booking_status] || b.booking_status;

        var tooltip = document.getElementById('pms-calendar-tooltip');
        if (!tooltip) {
            tooltip = document.createElement('div');
            tooltip.id = 'pms-calendar-tooltip';
            tooltip.className = 'fixed hidden bg-white border border-slate-200/80 shadow-2xl rounded-2xl p-4 z-50 pointer-events-none space-y-2';
            tooltip.style.width = '240px';
            tooltip.style.fontFamily = 'Inter, sans-serif';
            document.body.appendChild(tooltip);
        }

        var amountHtml = b.total_amount ? '<div class="text-xs font-bold text-slate-800">Total: <span class="text-emerald-600 font-extrabold">₹' + parseFloat(b.total_amount).toLocaleString('en-IN') + '</span></div>' : '';

        tooltip.innerHTML = 
             '<div class="flex items-center gap-2 border-b border-slate-100 pb-2">'
            +'  <div class="w-8 h-8 rounded-full bg-indigo-50 flex items-center justify-center text-indigo-600"><i class="ph ph-user text-base"></i></div>'
            +'  <div>'
            +'    <h4 class="text-xs font-extrabold text-slate-900 leading-tight">' + esc(b.guest_name || 'Guest') + '</h4>'
            +'    <p class="text-[9px] font-semibold text-slate-400 uppercase tracking-wider mt-0.5">Room ' + esc(rn) + '</p>'
            +'  </div>'
            +'</div>'
            +'<div class="space-y-1 pt-1">'
            +'  <div class="text-[10px] text-slate-500 font-medium">➔ Check-in: <span class="font-bold text-slate-700">' + esc(b.check_in) + '</span></div>'
            +'  <div class="text-[10px] text-slate-500 font-medium">➔ Check-out: <span class="font-bold text-slate-700">' + esc(b.check_out) + '</span></div>'
            +'  <div class="text-[10px] text-slate-500 font-medium">Status: <span class="font-bold text-indigo-600">' + esc(statusLabel) + '</span></div>'
            +amountHtml
            +'</div>';

        tooltip.classList.remove('hidden');
        moveTooltip(e);
    };

    window.moveTooltip = function(e) {
        var tooltip = document.getElementById('pms-calendar-tooltip');
        if (!tooltip) return;
        var x = e.clientX + 15;
        var y = e.clientY + 15;
        if (x + 240 > window.innerWidth) x = e.clientX - 255;
        if (y + 160 > window.innerHeight) y = e.clientY - 175;
        tooltip.style.left = x + 'px';
        tooltip.style.top = y + 'px';
    };

    window.hideTooltip = function() {
        var tooltip = document.getElementById('pms-calendar-tooltip');
        if (tooltip) tooltip.classList.add('hidden');
    };

    window.quickBook = function(roomId, date) {
        window.location.href = '/booking_wizard.php?prefill_room=' + roomId + '&prefill_date=' + encodeURIComponent(date);
    };

    /* ═══ BOOKING BLOCK ═══ */
    function bkBlock(b,leftPct,widthPct){
        var stClass = 'bk-' + (b.booking_status || 'booked');
        var rm=rooms.find(function(r){return r.id===b.room_id});
        var rn=rm?rm.room_number:'';
        var gn=b.guest_name||'Guest';
        var bJson = encodeURIComponent(JSON.stringify(b));
        return '<a href="../../folio.php?id='+b.id+'" class="bk ' + stClass + '" style="left:'+leftPct+'%;width:'+widthPct+'%;min-width:18px;" '
            +'onmouseenter="showTooltip(event, \''+bJson+'\')" onmousemove="moveTooltip(event)" onmouseleave="hideTooltip()">'+esc(gn)+'</a>';
    }

    /* ═══ NOW LINE ═══ */
    function nowLine(pct){
        var n=nowIST();
        return '<div class="nl" id="nl" style="left:'+pct+'%"><span class="nll">'+fmtTime(n.h,n.mi)+'</span></div>';
    }

    /* Group Rooms by Category */
    function groupRoomsByCategory() {
        var grouped = {};
        rooms.forEach(function(rm){
            if(!grouped[rm.category_name]) {
                grouped[rm.category_name] = [];
            }
            grouped[rm.category_name].push(rm);
        });
        return grouped;
    }

    window.toggleCategory = function(catName) {
        collapsedCategories[catName] = !collapsedCategories[catName];
        if (VIEW === 'hourly') renderHourly(); else renderWeekly();
    };

    /* ═══ HOURLY: 24 cols x rooms ═══ */
    function renderHourly(){
        var now=nowIST();
        var isToday=(CAL_DATE===now.str);
        var DAY_NAMES=['Sunday','Monday','Tuesday','Wednesday','Thursday','Friday','Saturday'];
        var MONTH_NAMES=['January','February','March','April','May','June','July','August','September','October','November','December'];
        var pd=new Date(CAL_DATE+'T12:00:00Z');
        document.getElementById('subtitle').textContent=DAY_NAMES[pd.getUTCDay()]+', '+MONTH_NAMES[pd.getUTCMonth()]+' '+pd.getUTCDate()+', '+pd.getUTCFullYear();

        /* header row: 24 hour columns */
        var hdrCells='';
        for(var h=0;h<24;h++){
            var lbl=h===0?'12A':h<12?h+'A':h===12?'12P':(h-12)+'P';
            var cur=(isToday&&h===now.h);
            hdrCells+='<div class="slot'+(cur?' slot-hl':'')+'" style="width:'+CELL_W+'px;flex-shrink:0;padding:4px 0;text-align:center;">'
                +'<span style="font-size:11px;font-weight:700;'+(cur?'color:#4F46E5;text-decoration:underline;':'color:#64748B;')+'">'+lbl+'</span></div>';
        }
        var hdrNow=isToday?nowLine((now.dec/24)*100):'';

        var gridW=24*CELL_W;
        var header='<div style="display:flex;border-bottom:1px solid #e2e8f0;background:#fff;position:sticky;top:0;z-index:10;flex-shrink:0;min-width:max-content;">'
            +'<div class="room-label" style="width:'+ROOM_W+'px;height:32px;border-right:1px solid #e2e8f0;"></div>'
            +'<div style="width:'+gridW+'px;position:relative;height:32px;">'
            +'<div style="display:flex;height:32px;">'+hdrCells+'</div>'
            +hdrNow+'</div></div>';

        /* room rows grouped by categories */
        var rows='';
        var grouped = groupRoomsByCategory();

        Object.keys(grouped).forEach(function(catName){
            var isCollapsed = !!collapsedCategories[catName];
            var count = grouped[catName].length;
            var arrow = isCollapsed ? 'ph-caret-right' : 'ph-caret-down';

            // Category Header Row
            rows+='<div style="display:flex;border-bottom:1px solid #e2e8f0;background:#f8fafc;min-width:max-content;">'
                +'<div class="category-row-label" onclick="toggleCategory(\''+esc(catName)+'\')" style="width:'+ROOM_W+'px;height:32px;">'
                +'<span style="overflow:hidden;text-overflow:ellipsis;white-space:nowrap;max-width:60px;">'+catName+'</span>'
                +'<i class="ph '+arrow+' text-xs"></i>'
                +'</div>'
                +'<div style="width:'+gridW+'px;height:32px;display:flex;align-items:center;padding-left:12px;background:#f8fafc;font-size:10px;font-weight:700;color:#64748B;">'
                +'<span>'+count+' Rooms Available</span>'
                +'</div></div>';

            if (!isCollapsed) {
                grouped[catName].forEach(function(rm){
                    var cells='';
                    for(var h=0;h<24;h++){
                        var cur=(isToday&&h===now.h);
                        var hrStr = String(h).padStart(2, '0') + ':00:00';
                        cells+='<div class="slot'+(cur?' slot-hl':'')+' hover:bg-slate-50 cursor-pointer" onclick="quickBook('+rm.id+',\''+CAL_DATE+' '+hrStr+'\')" style="width:'+CELL_W+'px;flex-shrink:0;"></div>';
                    }
                    var rbs=bookings.filter(function(b){
                        if(b.room_id!=rm.id)return false;
                        var biD=b.check_in.substring(0,10);
                        var boD=b.check_out.substring(0,10);
                        return biD<=CAL_DATE&&boD>=CAL_DATE;
                    });
                    var bks='';
                    rbs.forEach(function(b){
                        var biD=b.check_in.substring(0,10), biH=parseInt(b.check_in.substring(11,13)), biM=parseInt(b.check_in.substring(14,16));
                        var boD=b.check_out.substring(0,10), boH=parseInt(b.check_out.substring(11,13)), boM=parseInt(b.check_out.substring(14,16));
                        var startH=(biD<CAL_DATE)?0:biH+biM/60;
                        var endH=(boD>CAL_DATE)?24:boH+boM/60;
                        var dur=endH-startH;
                        if(dur>0)bks+=bkBlock(b,(startH/24)*100,(dur/24)*100);
                    });
                    rows+='<div style="display:flex;border-bottom:1px solid #f1f5f9;min-width:max-content;">'
                        +roomLabel(rm)
                        +'<div style="width:'+gridW+'px;position:relative;height:'+ROW_H+'px;">'
                        +'<div style="display:flex;height:'+ROW_H+'px;">'+cells+'</div>'
                        +bks+'</div></div>';
                });
            }
        });

        document.getElementById('cal').innerHTML='<div class="scroll-x" style="height:100%;">'+header+rows+'</div>';
        /* auto-scroll to current time */
        requestAnimationFrame(function(){
            var scroller=document.querySelector('.scroll-x');
            if(!scroller)return;
            if(isToday){
                var nowPx=(now.dec/24)*24*CELL_W;
                scroller.scrollLeft=Math.max(0,nowPx-scroller.clientWidth/2);
            }
        });
        if(isToday){clearInterval(timer);timer=setInterval(updateNL,30000);}
    }

    /* ═══ WEEKLY: 7 day cols x rooms ═══ */
    function renderWeekly(){
        var now=nowIST();
        var ws=WEEK[0].date,we=WEEK[6].date;
        var totalMs=7*24*3600000;
        document.getElementById('subtitle').textContent=
            WEEK[0].dn+' '+WEEK[0].dd+' '+WEEK[0].dm+' – '+WEEK[6].dn+' '+WEEK[6].dd+' '+WEEK[6].dm;

        /* header row: 7 day columns */
        var hdrCells='';
        WEEK.forEach(function(day){
            var isTD=(day.date===now.str);
            hdrCells+='<div class="slot'+(isTD?' slot-hl':'')+'" style="width:'+CELL_W+'px;flex-shrink:0;padding:8px 0;text-align:center;">'
                +'<div style="font-size:11px;font-weight:700;text-transform:uppercase;'+(isTD?'color:#4F46E5;':'color:#64748B;')+'">'+day.dn+'</div>'
                +'<div style="font-size:20px;font-weight:700;'+(isTD?'color:#4F46E5;text-decoration:underline;':'color:#1E293B;')+'">'+day.dd+'</div>'
                +'<div style="font-size:11px;font-weight:700;'+(isTD?'color:#4F46E5;':'color:#64748B;')+'">'+day.dm+'</div></div>';
        });
        var tIdx=WEEK.findIndex(function(d){return d.date===now.str});
        var hdrNow=tIdx!==-1?nowLine(((tIdx*24+now.dec)/(7*24))*100):'';

        var gridW=7*CELL_W;
        var header='<div style="display:flex;border-bottom:1px solid #e2e8f0;background:#fff;position:sticky;top:0;z-index:10;flex-shrink:0;min-width:max-content;">'
            +'<div class="room-label" style="width:'+ROOM_W+'px;height:56px;border-right:1px solid #e2e8f0;"></div>'
            +'<div style="width:'+gridW+'px;position:relative;height:56px;">'
            +'<div style="display:flex;height:56px;">'+hdrCells+'</div>'
            +hdrNow+'</div></div>';

        /* room rows grouped by categories */
        var rows='';
        var grouped = groupRoomsByCategory();

        Object.keys(grouped).forEach(function(catName){
            var isCollapsed = !!collapsedCategories[catName];
            var count = grouped[catName].length;
            var arrow = isCollapsed ? 'ph-caret-right' : 'ph-caret-down';

            // Category Header Row
            rows+='<div style="display:flex;border-bottom:1px solid #e2e8f0;background:#f8fafc;min-width:max-content;">'
                +'<div class="category-row-label" onclick="toggleCategory(\''+esc(catName)+'\')" style="width:'+ROOM_W+'px;height:40px;">'
                +'<span style="overflow:hidden;text-overflow:ellipsis;white-space:nowrap;max-width:60px;">'+catName+'</span>'
                +'<i class="ph '+arrow+' text-xs"></i>'
                +'</div>'
                +'<div style="width:'+gridW+'px;height:40px;display:flex;align-items:center;padding-left:12px;background:#f8fafc;font-size:10px;font-weight:700;color:#64748B;">'
                +'<span>'+count+' Rooms Available</span>'
                +'</div></div>';

            if (!isCollapsed) {
                grouped[catName].forEach(function(rm){
                    var cells='';
                    WEEK.forEach(function(day){
                        var isTD=(day.date===now.str);
                        cells+='<div class="slot'+(isTD?' slot-hl':'')+' hover:bg-slate-50 cursor-pointer" onclick="quickBook('+rm.id+',\''+day.date+' 12:00:00\')" style="width:'+CELL_W+'px;flex-shrink:0;"></div>';
                    });
                    var rbs=bookings.filter(function(b){
                        if(b.room_id!=rm.id)return false;
                        var biD=b.check_in.substring(0,10);
                        var boD=b.check_out.substring(0,10);
                        return biD<=we&&boD>=ws;
                    });
                    var bks='';
                    rbs.forEach(function(b){
                        var biD=b.check_in.substring(0,10),biH=parseInt(b.check_in.substring(11,13)),biM=parseInt(b.check_in.substring(14,16));
                        var boD=b.check_out.substring(0,10),boH=parseInt(b.check_out.substring(11,13)),boM=parseInt(b.check_out.substring(14,16));
                        var biMs,boMs;
                        if(biD<ws){biMs=0}else{var di=WEEK.findIndex(function(d){return d.date===biD});biMs=(di*24+biH+biM/60)*3600000;}
                        if(boD>we){boMs=totalMs}else{var di=WEEK.findIndex(function(d){return d.date===boD});boMs=(di*24+boH+boM/60)*3600000;}
                        var wMs=boMs-biMs;
                        if(wMs>0)bks+=bkBlock(b,(biMs/totalMs)*100,(wMs/totalMs)*100);
                    });
                    rows+='<div style="display:flex;border-bottom:1px solid #f1f5f9;min-width:max-content;">'
                        +roomLabel(rm)
                        +'<div style="width:'+gridW+'px;position:relative;height:'+ROW_H+'px;">'
                        +'<div style="display:flex;height:'+ROW_H+'px;">'+cells+'</div>'
                        +bks+'</div></div>';
                });
            }
        });

        document.getElementById('cal').innerHTML='<div class="scroll-x" style="height:100%;">'+header+rows+'</div>';
        /* add now line spanning across room rows */
        if(tIdx!==-1){
            var nlPct=((tIdx*24+now.dec)/(7*24))*100;
            var nlHtml='<div class="nl" id="nl" style="left:'+nlPct+'%"><span class="nll">'+fmtTime(now.h,now.mi)+'</span></div>';
            document.querySelectorAll('#cal .scroll-x > div').forEach(function(row,i){
                if(i===0 || row.querySelector('.category-row-label')) return;
                var gridDiv=row.children[1];
                if(gridDiv){
                    var tmp=document.createElement('div');
                    tmp.innerHTML=nlHtml;
                    tmp.firstChild.style.height=ROW_H+'px';
                    tmp.firstChild.style.top='0';
                    tmp.firstChild.style.bottom='auto';
                    gridDiv.appendChild(tmp.firstChild);
                }
            });
        }
        /* auto-scroll to today */
        requestAnimationFrame(function(){
            var scroller=document.querySelector('.scroll-x');
            if(!scroller)return;
            var tIdx=WEEK.findIndex(function(d){return d.date===now.str});
            if(tIdx>=0){
                var todayPx=tIdx*CELL_W;
                scroller.scrollLeft=Math.max(0,todayPx-scroller.clientWidth/3);
            }
        });
        var tIdx2=WEEK.findIndex(function(d){return d.date===now.str});
        if(tIdx2!==-1){clearInterval(timer);timer=setInterval(updateNL,30000);}
    }

    /* ═══ NOW LINE UPDATE ═══ */
    function updateNL(){
        var els=document.querySelectorAll('#nl');
        if(!els.length)return;
        var n=nowIST();
        var pct;
        if(VIEW==='hourly'){
            pct=(n.dec/24*100)+'%';
        }else{
            var idx=WEEK.findIndex(function(d){return d.date===n.str});
            if(idx>=0)pct=(((idx*24+n.dec)/(7*24))*100)+'%';
        }
        if(!pct)return;
        els.forEach(function(el){el.style.left=pct;});
        var l=document.querySelector('.nll');
        if(l)l.textContent=fmtTime(n.h,n.mi);
    }

    /* ═══ MARK CLEAN ═══ */
    window.mc=async function(id,btn){
        event.preventDefault();event.stopPropagation();
        var orig=btn.innerHTML;
        btn.innerHTML='<i class="ph ph-spinner animate-spin text-xs"></i>';btn.disabled=true;
        try{
            var r=await fetch('/api/admin/room_action',{method:'POST',headers:{'Content-Type':'application/json'},body:JSON.stringify({room_id:id,action:'mark_clean'})});
            var d=await r.json();
            if(d.success){var rm=rooms.find(function(r){return r.id===id});if(rm)rm.state='clean';render()}
            else{showToast(d.message);btn.innerHTML=orig;btn.disabled=false}
        }catch(e){showToast('Failed');btn.innerHTML=orig;btn.disabled=false}
    };

    async function render(){
        await loadData();
        if(VIEW==='hourly')renderHourly();else renderWeekly();
    }
    render();
})();
</script>
</body>
</html>
