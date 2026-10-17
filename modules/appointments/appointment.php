<?php
error_reporting(E_ALL);
ini_set('display_errors', 1);
session_start();
require $_SERVER['DOCUMENT_ROOT'].'/includes/db.php';
require $_SERVER['DOCUMENT_ROOT'].'/includes/permissions.php';
/*

if (!isset($_SESSION['user_id'])) {
    header('Location: /index.php');
    exit;
}
if (!checkPermission('appointments','create')) {
    die('Access Denied');
}
*/
$today = new DateTime();
$dates = [];
for ($i = 0; $i < 7; $i++) {
    $d = clone $today;
    $d->modify("+$i day");
    $dates[] = $d;
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<title>Appointments</title>
<meta name="viewport" content="width=device-width, initial-scale=1">
<link rel="stylesheet" href="/assets/css/style.css">
<link rel="icon" type="image/png" href="/assets/img/hms_logo.png">
<link rel="apple-touch-icon" href="/assets/img/hms_logo.png">
<style>
.slot.booked {
    background: #fb923c;   /* orange */
    color: #fff;
    cursor: not-allowed;
}
.slot.checked {
    background: #94a3b8;   /* grey-blue */
    color: #fff;
    cursor: not-allowed;
}
.legend-item {
    display:flex;
    align-items:center;
    gap:6px;
}
.legend-box {
    width:14px;
    height:14px;
    border-radius:4px;
    display:inline-block;
}
/* match slot colors */
.legend-box.available {
    background:#22c55e;
}
.legend-box.booked {
    background:#fb923c;
}
.legend-box.break {
    background:#ef4444;
}
.legend-box.past {
    background:#cbd5e1;
}
.legend-box.checked {
    background:#94a3b8;
}
#viewBar > div:first-child {
    margin-right:20px;
}
/* ===== ACCESS RESTRICTED POPUP ===== */
.permission-modal{
    display:none;
    position:fixed;
    inset:0;
    z-index:999999;
    background:rgba(15,23,42,.55);
    align-items:center;
    justify-content:center;
}
.permission-modal-box{
    width:100%;
    max-width:400px;
    background:#fff;
    border-radius:16px;
    padding:28px;
    text-align:center;
    box-shadow:0 20px 50px rgba(0,0,0,.25);
    animation:permissionPopup .2s ease;
}
.permission-modal-icon{
    width:58px;
    height:58px;
    margin:0 auto 15px;
    border-radius:50%;
    background:#fee2e2;
    color:#dc2626;
    display:flex;
    align-items:center;
    justify-content:center;
    font-size:25px;
}
.permission-modal-box h3{
    margin:0 0 10px;
    color:#1e293b;
    font-size:20px;
}
.permission-modal-box p{
    margin:0;
    color:#64748b;
    font-size:14px;
    line-height:1.6;
}
.permission-modal-footer{
    margin-top:22px;
}
.permission-modal-footer button{
    min-width:90px;
    padding:9px 22px;
    border:none;
    border-radius:8px;
    background:#1976d2;
    color:#fff;
    font-size:14px;
    font-weight:600;
    cursor:pointer;
}
.permission-modal-footer button:hover{
    background:#1565c0;
}
@keyframes permissionPopup{
    from{
        opacity:0;
        transform:scale(.95);
    }
    to{
        opacity:1;
        transform:scale(1);
    }
}
</style>
</head>
<body>
<?php require $_SERVER['DOCUMENT_ROOT'].'/includes/header.php'; ?>
<div class="layout">
    <?php require $_SERVER['DOCUMENT_ROOT'].'/includes/sidebar.php'; ?>
    <div class="main-content" id="mainContent">
        <div class="page-container">
<h2>Appointments</h2>
<!-- FILTERS -->
<div class="filters">
    <select id="type_dd">
        <option value="">Doctor / Service</option>
        <option value="doctor">Doctor</option>
        <option value="service">Service</option>
    </select>
    <label id="second_dd_label">Department</label>
    <select id="department_dd">
        <option value="">Select</option>
    </select>
    <select id="entity_dd">
        <option value="">Doctor / Service</option>
    </select>
    <?php if (checkPermission('appointments','create')): ?>
<button type="button" id="addBlankAppointment">+ Add Appointment</button>
<?php endif; ?>
</div>
<!-- VIEW BAR (ONE LINE) -->
<div id="viewBar" style="
    display:flex;
    align-items:center;
    gap:20px;
    margin-bottom:10px;
    flex-wrap:nowrap;
">
    <!-- VIEW BUTTONS -->
    <div style="display:flex; gap:8px; margin-right:auto;">
        <button type="button" id="dayViewBtn" class="view-btn active">Day View</button>
        <button type="button" id="weekViewBtn" class="view-btn">Week View</button>
    </div>
    <!-- DAY SUMMARY -->
    <div id="daySlotSummary" style="font-weight:bold; display:none; white-space:nowrap;">
        Total Slots: <span id="dayTotalSlots">0</span> |
        Available Slots: <span id="dayAvailableSlots">0</span>
    </div>
    <!-- WEEK SUMMARY -->
    <div id="weekSlotSummary" style="font-weight:bold; display:none; white-space:nowrap;">
        Total Slots: <span id="totalSlots">0</span> |
        Available Slots: <span id="availableSlots">0</span>
    </div>
    <!-- WEEK LEGENDS -->
    <div id="weekLegends" style="
        display:none;
        display:flex;
        gap:12px;
        align-items:center;
        white-space:nowrap;
        font-size:13px;
    ">
        <div class="legend-item">
            <span class="legend-box available"></span> Available
        </div>
        <div class="legend-item">
            <span class="legend-box booked"></span> Booked
        </div>
        <div class="legend-item">
            <span class="legend-box break"></span> Break
        </div>
        <div class="legend-item">
            <span class="legend-box past"></span> Past
        </div>
        <div class="legend-item">
            <span class="legend-box checked"></span> Checked
        </div>
    </div>
</div>
<div id="dayViewLayout" style="display:none; display:flex; gap:15px; flex-direction: row-reverse;">
    <!-- LEFT: SLOTS -->
    <div id="daySlots" style="flex:3;">
        <h4 id="selectedDateTitle"></h4>
        <div id="daySlotsGrid" class="day-slots-grid"></div>
    </div>
    <!-- RIGHT: CALENDAR -->
    <div id="dayCalendar" style="flex:1; padding:5px; background :#cbf1ca; margin-top:60px;">
        <div class="calendar-header">
            <button onclick="prevMonth()">‹</button>
            <span id="calendarMonth"></span>
            <button onclick="nextMonth()">›</button>
        </div>
        <div id="calendarGrid" class="calendar-grid"></div>
    </div>
</div>
<!-- SLOTS -->
<div class="slots-wrapper">
<?php foreach ($dates as $d): ?>
    <div class="day-column" data-date="<?= $d->format('Y-m-d') ?>">
        <div class="day-header">
            <?= $d->format('D') ?><br><?= $d->format('d M') ?>
        </div>
        <div class="slots-container"></div>
    </div>
<?php endforeach; ?>
</div> <!-- page-container -->
    </div> <!-- main-content -->
</div> <!-- layout -->
<!-- MODAL -->
<div class="modal" id="appointmentModal">
<div class="modal-box">
<div class="modal-header">
    <h3>Add Appointment</h3>
    <span class="close-modal" onclick="closeModal()">&times;</span>
</div>
<form onsubmit="return false;">
    <div class="form-group">
    <label>Type</label>
    <select id="f_type" class="form-control">
        <option value="doctor">Doctor</option>
        <option value="service">Service</option>
    </select>
</div>
<div class="form-group">
    <label>Department / Category</label>
    <select id="f_department" class="form-control"></select>
</div>
<div class="form-group">
    <label>Doctor / Service</label>
    <select id="f_entity" class="form-control"></select>
</div>
    <div class="form-group">
    <label>Date</label>
    <input type="date" id="f_date" class="form-control" min="">
</div>
<div class="form-group">
    <label>Slot</label>
    <select id="f_slot" class="form-control">
        <option value="">Select Slot</option>
    </select>
</div>
</form>
</div>
</div>
<script>
async function fetchBookedSlots(entityId, date) {
    const res = await fetch(
        `/modules/appointments/ajax/get_booked_slots.php?` +
        `type=doctor&entity_id=${entityId}&date=${date}`
    );
    return await res.json();
}
</script>
<script>
const typeDD = document.getElementById('type_dd');
const secondLabel = document.getElementById('second_dd_label');
const secondDD = document.getElementById('department_dd');
const entityDD = document.getElementById('entity_dd');
/* TYPE CHANGE */
typeDD.addEventListener('change', function () {
    const type = this.value;
    secondDD.innerHTML = '<option value="">Loading...</option>';
    entityDD.innerHTML = '<option value="">Select</option>';
    if (!type) {
        secondLabel.innerText = 'Department';
        secondDD.innerHTML = '<option value="">Select</option>';
        return;
    }
    /* DOCTOR FLOW */
    if (type === 'doctor') {
        secondLabel.innerText = 'Department';
        fetch('/modules/appointments/ajax/get_departments.php?type=doctor')
    .then(r => r.json())
    .then(data => {
        secondDD.innerHTML = '<option value="">Select Department</option>';
        data.forEach(d => {
            secondDD.innerHTML += `<option value="${d.id}">${d.department_name}</option>`;
        });
        // ✅ AUTO-SELECT IF ONLY ONE DEPARTMENT
        if (data.length === 1) {
            secondDD.value = data[0].id;
            // 🔥 trigger change so doctors load automatically
            secondDD.dispatchEvent(new Event('change'));
        }
    })
    .catch(err => console.error('Department load error', err));
    }
    /* SERVICE FLOW */
    if (type === 'service') {
        secondLabel.innerText = 'Service Category';
        fetch('/modules/appointments/ajax/get_service_categories.php')
    .then(r => r.json())
    .then(data => {
        secondDD.innerHTML = '<option value="">Select Category</option>';
        data.forEach(c => {
            secondDD.innerHTML += `<option value="${c.id}">${c.category_name}</option>`;
        });
        // ✅ AUTO-SELECT IF ONLY ONE CATEGORY
        if (data.length === 1) {
            secondDD.value = data[0].id;
            // trigger next dropdown (services)
            secondDD.dispatchEvent(new Event('change'));
        }
    })
    .catch(err => console.error('Service category load error', err));

    }
});
/* SECOND DROPDOWN CHANGE */
secondDD.addEventListener('change', function () {
    const selectedId = this.value;
    const type = typeDD.value;
    entityDD.innerHTML = '<option value="">Loading...</option>';
    if (!selectedId || !type) {
        entityDD.innerHTML = '<option value="">Select</option>';
        return;
    }
    if (type === 'doctor') {
        fetch(`/modules/appointments/ajax/get_doctors.php?department_id=${selectedId}`)
    .then(r => r.json())
    .then(data => {
        entityDD.innerHTML = '<option value="">Select Doctor</option>';
        data.forEach(d => {
            entityDD.innerHTML += `
<option 
    value="${d.id}" 
    data-entity="${d.entity_id}">
    ${d.name}
</option>`;
        });
        // ✅ AUTO-SELECT IF ONLY ONE DOCTOR
        if (data.length === 1) {
            entityDD.value = data[0].id;
            // 🔥 trigger change so slots load automatically
            entityDD.dispatchEvent(new Event('change'));
        }
    });
    }
    if (type === 'service') {
    fetch(`/modules/appointments/ajax/get_services.php?category_id=${selectedId}`)
        .then(r => r.json())
        .then(data => {
            entityDD.innerHTML = '<option value="">Select Service</option>';
            data.forEach(s => {
                entityDD.innerHTML += `<option value="${s.id}">${s.name}</option>`;
            });
            // ✅ AUTO-SELECT IF ONLY ONE SERVICE
            if (data.length === 1) {
                entityDD.value = data[0].id;

                // trigger change (if you load slots / prices etc.)
                entityDD.dispatchEvent(new Event('change'));
            }
        })
        .catch(err => console.error('Service load error', err));
}
});
function enableModalFields() {
    document.getElementById('f_type').disabled = false;
    document.getElementById('f_department').disabled = false;
    document.getElementById('f_entity').disabled = false;
}
function disableModalFields() {
    document.getElementById('f_type').disabled = true;
    document.getElementById('f_department').disabled = true;
    document.getElementById('f_entity').disabled = true;
}
</script>
<script>
/* CLEAR ALL SLOTS */
function clearAllSlots() {
    document.querySelectorAll('.slots-container').forEach(c => {
        c.innerHTML = '';
    });
}
/* GENERATE SERVICE SLOTS (sample_collection_time) */
function generateServiceSlots(slotMinutes, startTime, closeTime) {
    // RESET WEEK COUNTS
    let total = 0;
    let available = 0;
    clearAllSlots();
    const todayReal = new Date().toISOString().split('T')[0];
    const now = new Date();
    const [sh, sm] = startTime.split(':').slice(0,2).map(Number);
    const [eh, em] = closeTime.split(':').slice(0,2).map(Number);
    document.querySelectorAll('.day-column').forEach(dayCol => {
        const date = dayCol.dataset.date;
        const container = dayCol.querySelector('.slots-container');
        // ❌ past date
        if (date < todayReal) {
            container.innerHTML = '<div style="font-size:12px;color:#999">No slots</div>';
            return;
        }
        let current = sh * 60 + sm;
        const end = eh * 60 + em;
        while (current + slotMinutes <= end) {
            let h = Math.floor(current / 60);
            let m = current % 60;
            // ❌ past time today
            if (date === todayReal) {
                const slotTime = new Date();
                slotTime.setHours(h, m, 0, 0);
                if (slotTime <= now) {
                    current += slotMinutes;
                    continue;
                }
            }
            const start = `${String(h).padStart(2,'0')}:${String(m).padStart(2,'0')}`;
            const next = current + slotMinutes;
            const endStr = `${String(Math.floor(next/60)).padStart(2,'0')}:${String(next%60).padStart(2,'0')}`;
            const span = document.createElement('span');
            span.className = 'slot available';
            span.innerText = `${start} - ${endStr}`;
            span.onclick = () => openAppointmentForm(date, span.innerText);
            container.appendChild(span);
            total++;
            available++;
            current = next;
        }
    });
    // ✅ UPDATE WEEK SUMMARY ONCE (HERE ONLY)
    document.getElementById('totalSlots').innerText = total;
    document.getElementById('availableSlots').innerText = available;
}
/* ENTITY CHANGE — DOCTOR + SERVICE */
entityDD.addEventListener('change', function () {
    const type = typeDD.value;
    const entityId = this.value;
    if (!type || !entityId) return;
    if (currentView === 'week') {
        loadWeekSlots();
    }
    if (currentView === 'day') {
        loadDaySlots();
    }
});
</script>
<script>
function toggleSidebar() {
    const sidebar = document.getElementById('sidebar');
    sidebar.classList.toggle('collapsed');
}
</script>
<script>
const addBtn = document.getElementById('addBlankAppointment');
const modal = document.getElementById('appointmentModal');
addBtn.addEventListener('click', function () {
    const type = typeDD.value || '';
    const departmentId = secondDD.value || '';
    const entityId = entityDD.value || '';
    let url = '/modules/appointments/appointment_form_internal.php';
    if (type && departmentId && entityId) {
        url += '?type=' + encodeURIComponent(type)
            + '&department_id=' + encodeURIComponent(departmentId)
            + '&entity_id=' + encodeURIComponent(entityId);
    }
    document.getElementById('appointmentFormFrame').src = url;
    document.getElementById('appointmentFormOverlay').style.display = 'block';
});
</script>
<script>
function closeModal() {
    document.getElementById('appointmentModal').style.display = 'none';
}
</script>
<script>
function openAppointmentModal(date, slotTime) {
    // ⭐ Set today's date if no date provided
    const today = new Date().toISOString().split('T')[0];
    // Date & Slot
    document.getElementById('f_date').value = date || today;
    const slotDD = document.getElementById('f_slot');
    slotDD.innerHTML = '<option value="">Loading slots...</option>';
    disableModalFields(); // 🔒 LOCK fields on slot click
    /* ---------- TYPE ---------- */
    const typeSelect = document.getElementById('f_type');
    typeSelect.value = typeDD.value;
    /* ---------- DEPARTMENT / CATEGORY ---------- */
    const deptSelect = document.getElementById('f_department');
    deptSelect.innerHTML = '';
    if (secondDD.selectedIndex > 0) {
        const opt = document.createElement('option');
        opt.value = secondDD.value;
        opt.text = secondDD.options[secondDD.selectedIndex].text;
        opt.selected = true;
        deptSelect.appendChild(opt);
    }
    /* ---------- DOCTOR / SERVICE ---------- */
    const entitySelect = document.getElementById('f_entity');
    entitySelect.innerHTML = '';
    if (entityDD.selectedIndex > 0) {
        const opt = document.createElement('option');
        opt.value = entityDD.value;
        opt.text = entityDD.options[entityDD.selectedIndex].text;
        opt.selected = true;
        entitySelect.appendChild(opt);
    }
    // ⭐ Load slots automatically if we have all info
    const type = typeSelect.value;
    const entityId = entityDD.value;
    const currentDate = document.getElementById('f_date').value;
    
    if (type && entityId && currentDate) {
        setTimeout(() => {
            loadSlotsForModal(currentDate, type, entityId);
        }, 100);
    }
    // If slotTime was provided, select it after loading
    if (slotTime) {
        setTimeout(() => {
            const options = Array.from(slotDD.options);
            const matchingOption = options.find(opt => opt.value === slotTime);
            if (matchingOption) {
                slotDD.value = slotTime;
            }
        }, 500); // Wait for slots to load
    }
    document.getElementById('appointmentModal').style.display = 'block';
}
</script>
<script>
let currentView = 'day';
/* BUTTON CLICK */
document.getElementById('weekViewBtn').addEventListener('click', showWeekView);
document.getElementById('dayViewBtn').addEventListener('click', showDayView);
/* WEEK VIEW */
function showWeekView() {
    currentView = 'week';
    document.querySelector('.slots-wrapper').style.display = 'flex';
    document.getElementById('dayViewLayout').style.display = 'none';
    document.getElementById('daySlotSummary').style.display = 'none';
    // show summary
    document.getElementById('weekSlotSummary').style.display = 'block';
    // reset counts
    document.getElementById('totalSlots').innerText = 0;
    document.getElementById('availableSlots').innerText = 0;
}
function showDayView() {
    currentView = 'day';
    document.querySelector('.slots-wrapper').style.display = 'none';
    document.getElementById('dayViewLayout').style.display = 'flex';
    document.getElementById('weekSlotSummary').style.display = 'none';
    document.getElementById('daySlotSummary').style.display = 'block';
    if (!selectedDay) {
        selectedDay = new Date();
    }
    renderCalendar();
    loadDaySlots();
}
function loadDaySlots() {
    document.getElementById('selectedDateTitle').innerText =
        selectedDay.toDateString();
    const container = document.getElementById('daySlotsGrid');
    container.innerHTML = '';
    const type = typeDD.value;
    const entityId = entityDD.value;
    if (!type || !entityId) return;
    const selectedDateStr = formatDate(selectedDay);
    /* =======================
       SERVICE DAY VIEW
    ======================= */
    if (type === 'service') {
    fetch(`/modules/appointments/ajax/get_service_time.php?service_id=${entityId}`)
        .then(r => r.json())
        .then(data => {
            let total = 0;
            let available = 0;
            const todayReal = new Date().toISOString().split('T')[0];
            const now = new Date();
            // ❌ BLOCK PAST DATE
            if (selectedDateStr < todayReal) {
                container.innerHTML = '<p>No slots available</p>';
                document.getElementById('dayTotalSlots').innerText = 0;
                document.getElementById('dayAvailableSlots').innerText = 0;
                return;
            }
            let minutes = data.slot_minutes;
            if (!minutes || minutes <= 0) {
    container.innerHTML = '<p>No slots available</p>';
    document.getElementById('dayTotalSlots').innerText = 0;
    document.getElementById('dayAvailableSlots').innerText = 0;
    return;
}
            let [sh, sm] = data.start_time.split(':').slice(0,2).map(Number);
            let [eh, em] = data.close_time.split(':').slice(0,2).map(Number);
            let current = sh * 60 + sm;
            let end = eh * 60 + em;
            while (current + minutes <= end) {
                let h = Math.floor(current / 60);
                let m = current % 60;
                // ❌ BLOCK PAST TIME (TODAY)
                if (selectedDateStr === todayReal) {
                    const slotTime = new Date();
                    slotTime.setHours(h, m, 0, 0);
                    if (slotTime <= now) {
                        current += minutes;
                        continue;
                    }
                }
                const start = `${String(h).padStart(2,'0')}:${String(m).padStart(2,'0')}`;
                const next = current + minutes;
                const endStr = `${String(Math.floor(next/60)).padStart(2,'0')}:${String(next%60).padStart(2,'0')}`;
                const div = document.createElement('div');
                div.className = 'slot available';
                div.innerText = `${start} - ${endStr}`;
                div.onclick = () => openAppointmentForm(selectedDateStr, div.innerText);
                container.appendChild(div);
                // ✅ COUNT
                total++;
                available++;
                current = next;
            }
            // ✅ UPDATE DAY SUMMARY
            document.getElementById('dayTotalSlots').innerText = total;
            document.getElementById('dayAvailableSlots').innerText = available;
        });
}
    /* =======================
       DOCTOR DAY VIEW ✅
    ======================= */
    if (type === 'doctor') {
        fetch(`/modules/appointments/ajax/get_doctor_slots.php?doctor_id=${entityId}`)
            .then(r => r.json())
            .then(async data => {
    const bookedSlots = await fetchBookedSlots(entityId, selectedDateStr);
                if (!data[selectedDateStr]) {
                    container.innerHTML = '<p>No slots available</p>';
                    return;
                }
let total = 0;
let available = 0;
data[selectedDateStr].forEach(slot => {
    const todayStr = new Date().toISOString().split('T')[0];
    const now = new Date();
    let isPast = false;
    if (selectedDateStr < todayStr) isPast = true;
    if (selectedDateStr === todayStr) {
        const [h, m] = slot.start.split(':').map(Number);
        const slotTime = new Date();
        slotTime.setHours(h, m, 0, 0);
        if (slotTime <= now) isPast = true;
    }
    total++;
    if (slot.status === 'available' && !isPast) {
        available++;
    }
const slotKey = `${slot.start} - ${slot.end}`;
if (bookedSlots[slotKey]) {
    slot.status = 'booked';
}
    const div = document.createElement('div');
    div.innerText = `${slot.start} - ${slot.end}`;
    if (slot.status === 'break') {
        div.className = 'slot break';
    }
    else if (slot.status === 'available') {
        if (isPast) {
            div.className = 'slot past';
        } else {
            div.className = 'slot available';
            div.onclick = () =>
                openAppointmentForm(selectedDateStr, div.innerText);
        }
    }
    else if (slot.status === 'booked') {
    div.className = 'slot booked';
    div.title = 'Already Booked';
}
else if (slot.status === 'checked' || slot.status === 'completed') {
    div.className = 'slot checked';
    div.title = 'Visit Completed';
}
    container.appendChild(div);
});
document.getElementById('dayTotalSlots').innerText = total;
document.getElementById('dayAvailableSlots').innerText = available;
            });
    }
}
</script>
<script>
function loadWeekSlots() {
    clearAllSlots();
    const type = typeDD.value;
    const entityId = entityDD.value;
    if (!type || !entityId) return;
    let total = 0;
    let available = 0;
    fetch(`/modules/appointments/ajax/get_doctor_slots.php?doctor_id=${entityId}`)
        .then(r => r.json())
        .then(async data => {
            for (const date in data) {
                const bookedSlots = await fetchBookedSlots(entityId, date);
                const dayCol = document.querySelector(
                    `.day-column[data-date="${date}"]`
                );
                if (!dayCol) continue;
                const container = dayCol.querySelector('.slots-container');
                container.innerHTML = '';

                const todayStr = new Date().toISOString().split('T')[0];
                const now = new Date();

                data[date].forEach(slot => {

                    const slotKey = `${slot.start} - ${slot.end}`;
                    if (bookedSlots[slotKey]) {
                        slot.status = bookedSlots[slotKey];
                    }

                    let isPast = false;
                    if (date < todayStr) isPast = true;
                    if (date === todayStr) {
                        const [h, m] = slot.start.split(':').map(Number);
                        const slotTime = new Date();
                        slotTime.setHours(h, m, 0, 0);
                        if (slotTime <= now) isPast = true;
                    }

                    total++;
                    if (slot.status === 'available' && !isPast) {
                        available++;
                    }

                    const span = document.createElement('span');
                    span.innerText = `${slot.start} - ${slot.end}`;

                    if (slot.status === 'break') {
                        span.className = 'slot break';
                    }
                    else if (slot.status === 'available') {
                        span.className = isPast ? 'slot past' : 'slot available';
                        if (!isPast) {
                            span.onclick = () =>
                                openAppointmentForm(date, span.innerText);
                        }
                    }
                    else if (slot.status === 'booked') {
                        span.className = 'slot booked';
                    }
                    else {
                        span.className = 'slot checked';
                    }

                    container.appendChild(span);
                });
            }

            document.getElementById('totalSlots').innerText = total;
            document.getElementById('availableSlots').innerText = available;
        });
}
</script>

<script>
let currentMonth = new Date();
let selectedDay = new Date();


function renderCalendar() {
    const year = currentMonth.getFullYear();
    const month = currentMonth.getMonth();

    document.getElementById('calendarMonth').innerText =
        currentMonth.toLocaleString('default', { month: 'long', year: 'numeric' });

    const firstDay = new Date(year, month, 1).getDay();
    const daysInMonth = new Date(year, month + 1, 0).getDate();

    const grid = document.getElementById('calendarGrid');
    grid.innerHTML = '';

    for (let i = 0; i < firstDay; i++) {
        grid.innerHTML += `<div></div>`;
    }

    for (let d = 1; d <= daysInMonth; d++) {
        const dateStr = `${year}-${String(month+1).padStart(2,'0')}-${String(d).padStart(2,'0')}`;
        const div = document.createElement('div');
        div.innerText = d;

        if (dateStr === formatDate(selectedDay)) {
            div.classList.add('active');
        }

        div.onclick = () => {
            selectedDay = new Date(year, month, d);
            loadDaySlots();
            renderCalendar();
        };

        grid.appendChild(div);
    }
}
function prevMonth() {
    currentMonth.setMonth(currentMonth.getMonth() - 1);
    renderCalendar();
}
function nextMonth() {
    currentMonth.setMonth(currentMonth.getMonth() + 1);
    renderCalendar();
}
function formatDate(d) {
    const year = d.getFullYear();
    const month = String(d.getMonth() + 1).padStart(2, '0');
    const day = String(d.getDate()).padStart(2, '0');
    return `${year}-${month}-${day}`;
}
</script>
<script>
document.addEventListener('DOMContentLoaded', function () {
    showDayView();
});
</script>
<script>
const fType = document.getElementById('f_type');
const fDept = document.getElementById('f_department');
const fEntity = document.getElementById('f_entity');
/* MODAL TYPE CHANGE */
fType.addEventListener('change', function () {
    fDept.innerHTML = '<option value="">Loading...</option>';
    fEntity.innerHTML = '<option value="">Select</option>';
    if (this.value === 'doctor') {
        fetch('/modules/appointments/ajax/get_departments.php?type=doctor')
            .then(r => r.json())
            .then(data => {
                fDept.innerHTML = '<option value="">Select Department</option>';
                data.forEach(d => {
                    fDept.innerHTML += `<option value="${d.id}">${d.department_name}</option>`;
                });
            });
    }
    if (this.value === 'service') {
        fetch('/modules/appointments/ajax/get_service_categories.php')
            .then(r => r.json())
            .then(data => {
                fDept.innerHTML = '<option value="">Select Category</option>';
                data.forEach(c => {
                    fDept.innerHTML += `<option value="${c.id}">${c.category_name}</option>`;
                });
            });
    }
});
/* MODAL DEPARTMENT CHANGE */
fDept.addEventListener('change', function () {
    fEntity.innerHTML = '<option value="">Loading...</option>';
    if (fType.value === 'doctor') {
        fetch(`/modules/appointments/ajax/get_doctors.php?department_id=${this.value}`)
            .then(r => r.json())
            .then(data => {
                fEntity.innerHTML = '<option value="">Select Doctor</option>';
                data.forEach(d => {
                    fEntity.innerHTML += `<option value="${d.id}">${d.name}</option>`;
                });
            });
    }
    if (fType.value === 'service') {
        fetch(`/modules/appointments/ajax/get_services.php?category_id=${this.value}`)
            .then(r => r.json())
            .then(data => {
                fEntity.innerHTML = '<option value="">Select Service</option>';
                data.forEach(s => {
                    fEntity.innerHTML += `<option value="${s.id}">${s.name}</option>`;
                });
            });
    }
});
</script>
<script>
const fDate = document.getElementById('f_date');
// Set today's date by default in the modal
fDate.value = new Date().toISOString().split('T')[0];
const fSlot = document.getElementById('f_slot');
/* LOAD AVAILABLE SLOTS ON DATE CHANGE */
fDate.addEventListener('change', function () {
    fSlot.innerHTML = '<option value="">Loading...</option>';
    const date = this.value;
    const type = document.getElementById('f_type').value;
    const entityId = document.getElementById('f_entity').value;
    if (!date || !type || !entityId) {
        fSlot.innerHTML = '<option value="">Select Slot</option>';
        return;
    }
    /* ---------- DOCTOR SLOTS ---------- */
    if (type === 'doctor') {
        fetch(`/modules/appointments/ajax/get_doctor_slots.php?doctor_id=${entityId}`)
            .then(r => r.json())
            .then(data => {
                fSlot.innerHTML = '<option value="">Select Slot</option>';
                if (!data[date]) {
                    fSlot.innerHTML = '<option value="">No slots available</option>';
                    return;
                }
              const now = new Date();
const todayReal = new Date().toISOString().split('T')[0];
data[date].forEach(slot => {
    // skip break slots
if (slot.status === 'break') return;
if (date < todayReal) return;
if (date === todayReal) {
    const [h, m] = slot.start.split(':').map(Number);
    const slotTime = new Date();
    slotTime.setHours(h, m, 0, 0);
    if (slotTime <= now) return;
}
    const opt = document.createElement('option');
    opt.value = `${slot.start} - ${slot.end}`;
    opt.text = `${slot.start} - ${slot.end}`;
    fSlot.appendChild(opt);
});
            });
    }
    /* ---------- SERVICE SLOTS ---------- */
    if (type === 'service') {
        fetch(`/modules/appointments/ajax/get_service_time.php?service_id=${entityId}`)
            .then(r => r.json())
            .then(data => {
                fSlot.innerHTML = '<option value="">Select Slot</option>';
                let minutes = data.slot_minutes;
                if (!minutes || minutes <= 0) {
    fSlot.innerHTML = '<option value="">No slots available</option>';
    return;
}
                let [sh, sm] = data.start_time.split(':').slice(0,2).map(Number);
                let [eh, em] = data.close_time.split(':').slice(0,2).map(Number);
                let current = sh * 60 + sm;
                let end = eh * 60 + em;
                const now = new Date();
const todayStr = fDate.value;
const todayReal = new Date().toISOString().split('T')[0];
// ❌ block past dates completely (SERVICE)
if (todayStr < todayReal) {
    fSlot.innerHTML = '<option value="">No slots available</option>';
    return;
}
while (current + minutes <= end) {
    let h = Math.floor(current / 60);
    let m = current % 60;
    // skip past time slots for today
    const todayReal = new Date().toISOString().split('T')[0];
if (todayStr === todayReal) {
        const slotTime = new Date();
        slotTime.setHours(h, m, 0, 0);
        if (slotTime <= now) {
            current += minutes;
            continue;
        }
    }
    const start = `${String(h).padStart(2,'0')}:${String(m).padStart(2,'0')}`;
    const next = current + minutes;
    const endStr = `${String(Math.floor(next/60)).padStart(2,'0')}:${String(next%60).padStart(2,'0')}`;
    const opt = document.createElement('option');
    opt.value = `${start} - ${endStr}`;
    opt.text = `${start} - ${endStr}`;
    fSlot.appendChild(opt);
    current = next;
}
            });
    }
});
</script>
<script>
fEntity.addEventListener('change', function () {
    // reset slot dropdown
    fSlot.innerHTML = '<option value="">Select Slot</option>';
    
    // ⭐ NEW: Load slots automatically if date is already selected
    const date = fDate.value;
    const type = document.getElementById('f_type').value;
    const entityId = this.value;
    
    if (date && type && entityId) {
        loadSlotsForModal(date, type, entityId);
    }
});

// ⭐ ADD THIS NEW FUNCTION
function loadSlotsForModal(date, type, entityId) {
    fSlot.innerHTML = '<option value="">Loading slots...</option>';
    
    if (type === 'doctor') {
        fetch(`/modules/appointments/ajax/get_doctor_slots.php?doctor_id=${entityId}`)
            .then(r => r.json())
            .then(data => {
                fSlot.innerHTML = '<option value="">Select Slot</option>';
                if (!data[date]) {
                    fSlot.innerHTML = '<option value="">No slots available</option>';
                    return;
                }
                const now = new Date();
                const todayReal = new Date().toISOString().split('T')[0];
                data[date].forEach(slot => {
                    // skip break slots
                    if (slot.status === 'break') return;
                    if (date < todayReal) return;
                    if (date === todayReal) {
                        const [h, m] = slot.start.split(':').map(Number);
                        const slotTime = new Date();
                        slotTime.setHours(h, m, 0, 0);
                        if (slotTime <= now) return;
                    }
                    const opt = document.createElement('option');
                    opt.value = `${slot.start} - ${slot.end}`;
                    opt.text = `${slot.start} - ${slot.end}`;
                    fSlot.appendChild(opt);
                });
            });
    }
    
    if (type === 'service') {
        fetch(`/modules/appointments/ajax/get_service_time.php?service_id=${entityId}`)
            .then(r => r.json())
            .then(data => {
                fSlot.innerHTML = '<option value="">Select Slot</option>';
                let minutes = data.slot_minutes;
                if (!minutes || minutes <= 0) {
    fSlot.innerHTML = '<option value="">No slots available</option>';
    return;
}
                let [sh, sm] = data.start_time.split(':').slice(0,2).map(Number);
                let [eh, em] = data.close_time.split(':').slice(0,2).map(Number);
                let current = sh * 60 + sm;
                let end = eh * 60 + em;
                const now = new Date();
                const todayReal = new Date().toISOString().split('T')[0];
                // ❌ block past dates completely (SERVICE)
                if (date < todayReal) {
                    fSlot.innerHTML = '<option value="">No slots available</option>';
                    return;
                }
                while (current + minutes <= end) {
                    let h = Math.floor(current / 60);
                    let m = current % 60;
                    // skip past time slots for today
                    if (date === todayReal) {
                        const slotTime = new Date();
                        slotTime.setHours(h, m, 0, 0);
                        if (slotTime <= now) {
                            current += minutes;
                            continue;
                        }
                    }
                    const start = `${String(h).padStart(2,'0')}:${String(m).padStart(2,'0')}`;
                    const next = current + minutes;
                    const endStr = `${String(Math.floor(next/60)).padStart(2,'0')}:${String(next%60).padStart(2,'0')}`;
                    const opt = document.createElement('option');
                    opt.value = `${start} - ${endStr}`;
                    opt.text = `${start} - ${endStr}`;
                    fSlot.appendChild(opt);
                    current = next;
                }
            });
    }
}
</script>
<script>
const fDateMin = document.getElementById('f_date');
// today in YYYY-MM-DD
const todayMin = new Date().toISOString().split('T')[0];
// block past dates
fDateMin.min = todayMin;
function openAppointmentForm(date, slot) {
    const canCreateAppointment = <?= checkPermission('appointments','create') ? 'true' : 'false' ?>;
    if (!canCreateAppointment) {
    showPermissionModal();
    return;
}
    const type = typeDD.value;
    const entityId = entityDD.value;
    if (!type || !entityId) {
        alert('Please select Doctor / Service first');
        return;
    }
    const url =
        '/modules/appointments/appointment_form_internal.php' +
        '?date=' + date +
        '&slot=' + encodeURIComponent(slot) +
        '&type=' + type +
        '&entity_id=' + entityId;
    document.getElementById('appointmentFormFrame').src = url;
    document.getElementById('appointmentFormOverlay').style.display = 'block';
}
function closeAppointmentForm() {
    document.getElementById('appointmentFormFrame').src = '';
    document.getElementById('appointmentFormOverlay').style.display = 'none';
}
</script>
<!-- FULL PAGE APPOINTMENT FORM MODAL -->
<div id="appointmentFormOverlay" style="
    display:none;
    position:fixed;
    top:0; left:0;
    width:100%;
    height:100%;
    background:rgba(0,0,0,0.6);
    z-index:99999;
">
    <div style="
        width:100%;
        height:100%;
        background:#fff;
        margin:auto;
        position:relative;
        overflow:hidden;
    ">
        <!-- CLOSE BUTTON -->
<button type="button"
    onclick="closeAppointmentForm()"
    aria-label="Close"
    title="Close"
    style="
        position:absolute;
        top:5.5px;
        right:30px;
        z-index:10;
        width:36px;
        height:36px;
        display:flex;
        align-items:center;
        justify-content:center;
        padding:0;
        border:1px solid rgba(255,255,255,.35);
        border-radius:50%;
        background:rgba(255,255,255,.14);
        color:#fff;
        font-size:20px;
        font-weight:400;
        line-height:1;
        cursor:pointer;
        box-shadow:0 2px 8px rgba(0,0,0,.12);
        transition:all .18s ease;
    "
    onmouseover="this.style.background='rgba(220,38,38,.95)';this.style.borderColor='rgba(255,255,255,.6)';this.style.transform='scale(1.06)'"
    onmouseout="this.style.background='rgba(255,255,255,.14)';this.style.borderColor='rgba(255,255,255,.35)';this.style.transform='scale(1)'">
    ✕
</button>
        <!-- IFRAME -->
        <iframe id="appointmentFormFrame"
            src=""
            style="width:100%; height:100%; border:none;">
        </iframe>
    </div>
</div>
<script>
document.addEventListener('DOMContentLoaded', function () {
    const dayBtn  = document.getElementById('dayViewBtn');
    const weekBtn = document.getElementById('weekViewBtn');
    if (!dayBtn || !weekBtn) return;
    dayBtn.addEventListener('click', function (e) {
        e.preventDefault();
        setActiveView(this);

        // ⚠️ CALL YOUR EXISTING DAY SLOT FUNCTION
        if (typeof loadDaySlots === 'function') {
            loadDaySlots();
        }
    });
    weekBtn.addEventListener('click', function (e) {
        e.preventDefault();
        setActiveView(this);

        // ⚠️ CALL YOUR EXISTING WEEK SLOT FUNCTION
        if (typeof loadWeekSlots === 'function') {
            loadWeekSlots();
        }
    });
    function setActiveView(activeBtn) {
        document.querySelectorAll('.view-btn').forEach(btn => {
            btn.classList.remove('active');
        });
        activeBtn.classList.add('active');
    }
});
function showPermissionModal(){
    document.getElementById('permissionModal').style.display='flex';
}
function closePermissionModal(){
    document.getElementById('permissionModal').style.display='none';
}
document.getElementById('permissionModal').addEventListener('click',function(e){
    if(e.target===this){
        closePermissionModal();
    }
});
</script>
<!-- ACCESS RESTRICTED POPUP -->
<div id="permissionModal" class="permission-modal">
    <div class="permission-modal-box">
        <div class="permission-modal-icon">
            <i class="fas fa-lock"></i>
        </div>
        <h3>Access Restricted</h3>
        <p>You do not have permission to create an appointment.</p>
        <div class="permission-modal-footer">
            <button type="button" onclick="closePermissionModal()">OK</button>
        </div>
    </div>
</div>
</body>
</html>