<?php
session_start();
require $_SERVER['DOCUMENT_ROOT'].'/includes/db.php';
require $_SERVER['DOCUMENT_ROOT'].'/includes/permissions.php';

if (!isset($_SESSION['user_id'])) {
    header('Location: /index.php');
    exit;
}

if (!checkPermission('doctor_master','add')) {
    header('Location: /access_denied.php'); exit;
}
?>
<!DOCTYPE html>
<html>
<head>
<meta charset="UTF-8">
<title>Doctor Master</title>
<meta name="viewport" content="width=device-width, initial-scale=1">
<!-- FAVICON -->
    <link rel="icon" type="image/png" href="/assets/img/hms_logo.png">
    <link rel="apple-touch-icon" href="/assets/img/hms_logo.png">

<link rel="stylesheet" href="/assets/css/style.css">

<style>
fieldset { border:1px solid #ccc; padding:15px; margin-bottom:20px }
legend { font-weight:bold }
.row { display:flex; gap:15px; margin-bottom:12px }
.col { flex:1 }

input[readonly],
select[disabled] {
    background:#f4f6f8;
    cursor:not-allowed;
}

.multi-select.disabled .select-box {
    background:#f4f6f8;
    cursor:not-allowed;
}
/* =========================================================
   COMPACT DOCTOR WEEKLY SCHEDULE
   ========================================================= */

#doctorScheduleContainer {
    display: grid;
    grid-template-columns: repeat(2, minmax(0, 1fr));
    gap: 7px;
}
#doctorScheduleContainer .day-box {
    border: 1px solid #dfe5ec !important;
    border-radius: 7px;
    padding: 6px 8px !important;
    margin: 0 !important;
}

/* Monday — Light Blue */
#doctorScheduleContainer .day-box:nth-child(1) {
    background: #afc7e2 !important;
    border-color: #cfe3f8 !important;
}

/* Tuesday — Light Green */
#doctorScheduleContainer .day-box:nth-child(2) {
    background: #a9d0b6 !important;
    border-color: #cfe9d8 !important;
}

/* Wednesday — Light Yellow */
#doctorScheduleContainer .day-box:nth-child(3) {
    background: #dfd2b8 !important;
    border-color: #f1dfb8 !important;
}

/* Thursday — Light Purple */
#doctorScheduleContainer .day-box:nth-child(4) {
    background: #b2a7c7 !important;
    border-color: #dfd3f5 !important;
}

/* Friday — Light Pink */
#doctorScheduleContainer .day-box:nth-child(5) {
    background: #ccb2b7 !important;
    border-color: #f1d2d9 !important;
}

/* Saturday — Light Cyan */
#doctorScheduleContainer .day-box:nth-child(6) {
    background: #b5e0e4 !important;
    border-color: #cce9ec !important;
}

/* Sunday — Light Gray */
#doctorScheduleContainer .day-box:nth-child(7) {
    background: #c2cee7 !important;
    border-color: #dfe2e7 !important;
}
/* Day header */
#doctorScheduleContainer .day-box > div:first-child {
    min-height: 27px;
    font-size: 12px;
}

/* Checkbox */
#doctorScheduleContainer .day-box > div:first-child input[type="checkbox"] {
    width: 15px;
    min-height: 15px;
    margin: 0;
}

/* Schedule container */
#doctorScheduleContainer .schedules {
    margin-top: 6px !important;
}

/* Individual schedule */
#doctorScheduleContainer .schedule {
    border: 1px dashed #cbd5e1 !important;
    border-radius: 6px;
    padding: 6px !important;
    margin-bottom: 5px !important;
    background: #f8fafc;
}

/* Schedule row */
#doctorScheduleContainer .schedule > div:first-child {
    display: flex;
    align-items: center;
    gap: 5px !important;
    margin-bottom: 5px !important;
}

/* Time inputs */
#doctorScheduleContainer input[type="time"] {
    width: 105px;
    min-height: 29px;
    padding: 4px 6px;
}

/* Schedule labels */
#doctorScheduleContainer .schedule label {
    margin: 0;
    font-size: 11px;
    white-space: nowrap;
}

/* Add schedule / break buttons */
#doctorScheduleContainer button {
    min-height: 28px;
    padding: 4px 8px;
    font-size: 11px;
}

/* Break */
#doctorScheduleContainer .break {
    margin: 4px 0 4px 10px !important;
    padding: 5px !important;
    border: 1px solid #e2e8f0 !important;
    border-radius: 5px;
    background: #fff !important;
}

/* Break row */
#doctorScheduleContainer .break > div {
    display: flex;
    align-items: center;
    gap: 5px !important;
}

/* Help text below schedule */
fieldset:has(#doctorScheduleContainer) small {
    margin-top: 5px;
}

/* Tablet */
@media (max-width: 750px) {
    #doctorScheduleContainer {
        grid-template-columns: 1fr;
    }
}

/* Mobile */
@media (max-width: 500px) {
    #doctorScheduleContainer .schedule > div:first-child,
    #doctorScheduleContainer .break > div {
        flex-wrap: wrap;
    }

    #doctorScheduleContainer input[type="time"] {
        width: 95px;
    }

    #doctorScheduleContainer .break {
        margin-left: 0 !important;
    }
}
.doctor-media-row {
    display: flex !important;
    flex-wrap: nowrap !important;
    align-items: center !important;
    gap: 25px !important;
}

.doctor-media-item {
    display: flex !important;
    align-items: center !important;
    gap: 10px !important;
    flex: 1 !important;
    min-width: 0 !important;
}

.doctor-media-item label {
    white-space: nowrap !important;
    margin: 0 !important;
}

.doctor-media-item input[type="file"] {
    width: auto !important;
    flex: 1 !important;
}

.doctor-media-item img {
    flex-shrink: 0 !important;
    margin: 0 !important;
}
</style>
</head>

<?php require $_SERVER['DOCUMENT_ROOT'].'/includes/header.php'; ?>
<body>

<div class="user-wrapper">
<h2>Doctor Master</h2>

<form method="post"
      action="save_doctor.php"
      id="doctorForm"
      enctype="multipart/form-data">

<!-- ================= SELECT DOCTOR ================= -->
<fieldset>
<legend>Select Doctor</legend>

<div class="row">
<div class="col">
<label>Select Doctor *</label>
<select name="user_id" id="user_id" required>
<option value="">-- Select Doctor --</option>
<?php
$q = $conn->query("
    SELECT DISTINCT u.id, u.employee_id, u.full_name
    FROM users u
    JOIN user_employee_sub_types ust ON ust.user_id = u.id
    JOIN employee_type_master etm ON etm.id = ust.sub_type_id AND etm.parent_id = 2
    WHERE u.status='Active'
    ORDER BY u.full_name
");
while($r=$q->fetch_assoc()){
    echo '<option value="'.$r['id'].'">'.htmlspecialchars($r['full_name'].' ('.$r['employee_id'].')').'</option>';
}
?>
</select>
</div>
<div class="col"><label>Employee Type</label><input id="employee_type" readonly></div>
<div class="col">
<label>Doctor Type</label>
<select id="employee_sub_type" disabled>
<option value="">Auto</option>
<?php
$dt = $conn->query("SELECT type_name FROM employee_type_master WHERE parent_id=2 AND status=1");
while($d=$dt->fetch_assoc()){
    echo '<option value="'.$d['type_name'].'">'.htmlspecialchars($d['type_name']).'</option>';
}
?>
</select>
<input type="hidden" name="employee_sub_type">
</div>
<div class="col"><label>Status</label><input id="status" readonly></div>
<div class="col"><label>Surgeon</label><select name="is_surgeon"><option value="0">No</option><option value="1">Yes</option></select></div>
<div class="col"><label>License No</label><input name="license_no"></div>
<div class="col"><label>Time Slot (Minutes)</label><input type="number" name="time_slot_minutes" required></div>
</div>
</fieldset>

<!-- ================= USER DETAILS (LOCKED) ================= -->
<fieldset>
<legend>User Details (Read Only)</legend>

<div class="row">
<div class="col"><label>Employee ID</label><input id="employee_id" readonly><input type="hidden" name="employee_id" id="employee_id_hidden"></div>
<div class="col"><label>Full Name</label><input id="full_name" readonly><input type="hidden" name="full_name" id="full_name_hidden"></div>
<div class="col"><label>Username</label><input id="username" readonly></div>
<div class="col"><label>Email</label><input id="email" readonly></div>
<div class="col"><label>Mobile</label><input id="mobile_no" readonly></div>
</div>

<div class="row">
<div class="col"><label>Gender</label><select id="gender" disabled><option>Male</option><option>Female</option></select></div>
<div class="col"><label>Marital Status</label><select id="marital_status" disabled><option>Married</option><option>Unmarried</option></select></div>
<div class="col"><label>DOB</label><input type="date" id="dob" readonly></div>
<div class="col"><label>Qualification</label><input id="qualification" readonly></div>
<div class="col"><label>Aadhaar</label><input id="aadhaar" readonly></div>
</div>
<!-- MULTI SELECT (LOCKED) -->
<?php
function renderLockedMulti($id,$label,$items){
    echo "
    <div class='col'>
    <label>$label</label>
    <div class='multi-select disabled'>
        <div class='select-box'><span id='{$id}Count'>0 selected</span></div>
        <div class='dropdown' id='{$id}Dropdown'>";
    foreach($items as $v=>$t){
        echo "<label><input type='checkbox' value='$v' disabled>$t</label>";
    }
    echo "</div></div></div>";
}
?>

<div class="row">
<?php
$h=[]; $hq=$conn->query("SELECT id,hospital_name FROM hospital_master WHERE status=1");
while($r=$hq->fetch_assoc()) $h[$r['id']]=$r['hospital_name'];
renderLockedMulti('hospital','Hospitals',$h);

$b=[]; $bq=$conn->query("SELECT id,branch_name FROM branch_master WHERE status=1");
while($r=$bq->fetch_assoc()) $b[$r['id']]=$r['branch_name'];
renderLockedMulti('branch','Branches',$b);
?>

<?php
$d=[]; $dq=$conn->query("SELECT id,department_name FROM department_master WHERE status=1");
while($r=$dq->fetch_assoc()) $d[$r['id']]=$r['department_name'];
renderLockedMulti('department','Departments',$d);

$ds=[]; $dq=$conn->query("SELECT designation_code,designation_name FROM designation_master");
while($r=$dq->fetch_assoc()) $ds[$r['designation_code']]=$r['designation_name'];
renderLockedMulti('designation','Designations',$ds);
?>

<div class="col">
<label>Specialization</label>

<div class="multi-select">
    <div class="select-box" onclick="toggleDropdown('specializationDropdown', event)">
        <span id="specializationCount">Select Specialization</span>
    </div>

    <div class="dropdown" id="specializationDropdown">
        <?php
        $sq = mysqli_query(
            $conn,
            "SELECT specialization_code, specialization_name
             FROM specialization_master
             WHERE status = 1
             ORDER BY specialization_name"
        );
        while ($s = mysqli_fetch_assoc($sq)) {
        ?>
        <label>
            <input type="checkbox"
                   name="specialization_codes[]"
                   value="<?= htmlspecialchars($s['specialization_code']) ?>">
            <?= htmlspecialchars($s['specialization_name']) ?>
        </label>
        <?php } ?>
    </div>
</div>
</div>
</div>

</fieldset>

<!-- ================= DOCTOR CONFIG (EDITABLE) ================= -->
<fieldset>
  <legend>Doctor Charges</legend>

  <table id="chargesTable" style="width:100%; border-collapse:collapse;">
    <thead>
      <tr>
        <th style="border:1px solid #ddd; padding:8px;">Visit Type</th>
        <th style="border:1px solid #ddd; padding:8px;">Charge Amount</th>
        <th style="border:1px solid #ddd; padding:8px;">Valid From (Day)</th>
        <th style="border:1px solid #ddd; padding:8px;">Valid To (Day)</th>
        <th style="border:1px solid #ddd; padding:8px;">Action</th>
      </tr>
    </thead>
    <tbody id="chargesBody">
      <!-- Rows added dynamically -->
    </tbody>
  </table>

  <div style="margin-top:10px;">
    <button type="button" onclick="addChargeRow()">+ Add Charge</button>
  </div>

  <small style="display:block; margin-top:8px; color:#555;">
    • • <strong>NORMAL:</strong> one row only (valid days = 0–0) | • <strong>REVISIT:</strong> multiple slabs allowed (ranges must not overlap) | • <strong>EMERGENCY:</strong> independent (days ignored; keep 0–0) | • <strong>ROOM VISIT OWN:</strong> Room Visit Charges (Own Patient) | • <strong>ROOM VISIT OTHER:</strong> Room Visit Charges (Other Patient)
  </small>
</fieldset>
<fieldset>
    <legend>Doctor Weekly Schedule</legend>

    <div id="doctorScheduleContainer"></div>

    <small style="color:#555;">
        • Enable days when doctor is available |   • Each day can have multiple schedules | • Each schedule can have breaks
    </small>
</fieldset>

<fieldset>
    <legend>Doctor Media</legend>

    <div class="row">
       <div class="row doctor-media-row">

    <!-- Profile Photo -->
    <div class="col doctor-media-item">
        <label>Doctor Profile Photo</label>

        <input type="file"
               name="doctor_profile_photo"
               accept="image/*"
               onchange="DoctorMedia.preview(this, 'profilePreview')">

        <img id="profilePreview"
             src=""
             style="display:none;width:120px;height:120px;
                    object-fit:cover;border:1px solid #ccc;">
    </div>

    <!-- Signature -->
    <div class="col doctor-media-item">
        <label>Doctor Signature</label>

        <input type="file"
               name="doctor_signature"
               accept="image/*"
               onchange="DoctorMedia.preview(this, 'signaturePreview')">

        <img id="signaturePreview"
             src=""
             style="display:none;width:180px;height:80px;
                    object-fit:contain;border:1px solid #ccc;">
    </div>

</div>

    <small style="color:#555">
        • Images only (JPG, PNG) |  • Profile photo is used in Doctor listing | • Signature is used in prescriptions & reports
    </small>
</fieldset>

<fieldset>
    <legend>Doctor Share</legend>

    <!-- ================= CONSULTATION SHARE ================= -->
    <h4>Consultation Share</h4>
    <div class="row">
        <div class="col">
            <label>Percentage (%)</label>
            <input type="number" step="0.01" min="0"
                   name="doctor_share[consultation][percentage]">
        </div>
        <div class="col">
            <label>Flat Amount</label>
            <input type="number" step="0.01" min="0"
                   name="doctor_share[consultation][flat_amount]">
        </div>
        <div class="col">
            <label>W.e.f Date</label>
            <input type="date"
                   name="doctor_share[consultation][wef_date]">
        </div>
    </div>

    <hr>

    <!-- ================= INVOICE SHARE ================= -->
    <h4>Invoice Share</h4>
    <div class="row">
        <div class="col">
            <label>Percentage (%)</label>
            <input type="number" step="0.01" min="0"
                   name="doctor_share[invoice][percentage]">
        </div>
        <div class="col">
            <label>Flat Amount</label>
            <input type="number" step="0.01" min="0"
                   name="doctor_share[invoice][flat_amount]">
        </div>
        <div class="col">
            <label>W.e.f Date</label>
            <input type="date"
                   name="doctor_share[invoice][wef_date]">
        </div>
    </div>

    <hr>

    <!-- ================= SERVICES SHARE ================= -->
    <h4>Services Share</h4>
    <div class="row">
        <div class="col">
            <label>Percentage (%)</label>
            <input type="number" step="0.01" min="0"
                   name="doctor_share[service][percentage]">
        </div>
        <div class="col">
            <label>Flat Amount</label>
            <input type="number" step="0.01" min="0"
                   name="doctor_share[service][flat_amount]">
        </div>
        <div class="col">
            <label>W.e.f Date</label>
            <input type="date"
                   name="doctor_share[service][wef_date]">
        </div>
    </div>

    <small style="color:#555">
        • Only one share rule per category   |        • Either Percentage or Flat Amount can be used |         • W.e.f date is for reference and future audit
    </small>
</fieldset>
<style>
.share-table {
    width:100%;
    border-collapse:collapse;
    margin-bottom:8px;
}
.share-table th, .share-table td {
    border:1px solid #ddd;
    padding:6px;
    font-size:13px;
}
.share-table th {
    background:#f4f6f8;
}
</style>

<button type="submit">Save Doctor</button>
</form>
</div>
<script>
let chargeIndex = 0;

function addChargeRow(data = {}) {
  const tbody = document.getElementById('chargesBody');
  const tr = document.createElement('tr');

  const visitType = data.visit_type || '';
  const amount    = data.charge_amount || '';
  const fromDay   = (data.valid_from_day !== undefined) ? data.valid_from_day : 0;
  const toDay     = (data.valid_to_day !== undefined) ? data.valid_to_day : 0;

  tr.innerHTML = `
    <td style="border:1px solid #ddd; padding:6px;">
      <select name="charges[${chargeIndex}][visit_type]" required onchange="onVisitTypeChange(this)">
        <option value="">Select</option>
        <option value="NORMAL" ${visitType==='NORMAL'?'selected':''}>Normal</option>
        <option value="REVISIT" ${visitType==='REVISIT'?'selected':''}>Revisit</option>
        <option value="EMERGENCY" ${visitType==='EMERGENCY'?'selected':''}>Emergency</option>
        <option value="ROOM_VISIT_OWN" ${visitType==='ROOM_VISIT_OWN'?'selected':''}>Room Visit Charges (Own Patient)</option>
        <option value="ROOM_VISIT_OTHER" ${visitType==='ROOM_VISIT_OTHER'?'selected':''}>Room Visit Charges (Other Patient)</option>
      </select>
    </td>

    <td style="border:1px solid #ddd; padding:6px;">
      <input type="number" step="0.01" min="0" name="charges[${chargeIndex}][charge_amount]"
             value="${amount}" required>
    </td>

    <td style="border:1px solid #ddd; padding:6px;">
      <input type="number" min="0" name="charges[${chargeIndex}][valid_from_day]"
             value="${fromDay}" required>
    </td>

    <td style="border:1px solid #ddd; padding:6px;">
      <input type="number" min="0" name="charges[${chargeIndex}][valid_to_day]"
             value="${toDay}" required>
    </td>

    <td style="border:1px solid #ddd; padding:6px; text-align:center;">
      <button type="button" onclick="removeChargeRow(this)">Remove</button>
    </td>
  `;

  tbody.appendChild(tr);
  chargeIndex++;
}

function removeChargeRow(btn) {
  btn.closest('tr').remove();
}

function onVisitTypeChange(selectEl) {
  const tr = selectEl.closest('tr');
  const fromInput = tr.querySelector('input[name*="[valid_from_day]"]');
  const toInput   = tr.querySelector('input[name*="[valid_to_day]"]');

  if (
    selectEl.value === 'NORMAL' ||
    selectEl.value === 'EMERGENCY' ||
    selectEl.value === 'ROOM_VISIT_OWN' ||
    selectEl.value === 'ROOM_VISIT_OTHER'
  ) {
    fromInput.value = 0;
    toInput.value   = 0;
    fromInput.readOnly = true;
    toInput.readOnly   = true;
  } else {
    fromInput.readOnly = false;
    toInput.readOnly   = false;
  }

  enforceSingleNormal();
}

function enforceSingleNormal() {
  const selects = document.querySelectorAll('#chargesBody select');
  const restrictedTypes = [
  'NORMAL',
  'EMERGENCY',
  'ROOM_VISIT_OWN',
  'ROOM_VISIT_OTHER'
];

  restrictedTypes.forEach(type => {
    const matches = Array.from(selects).filter(sel => sel.value === type);

    if (matches.length > 1) {
      alert(`Only ONE ${type.replaceAll('_', ' ')} charge is allowed per doctor.`);
      matches[matches.length - 1].value = '';
    }
  });
}

/* Optional basic overlap check before submit */
document.getElementById('doctorForm').addEventListener('submit', function(e){
  const rows = document.querySelectorAll('#chargesBody tr');
  let revisitRanges = [];

  rows.forEach(r => {
    const vt = r.querySelector('select').value;
    const from = parseInt(r.querySelector('input[name*="[valid_from_day]"]').value,10);
    const to   = parseInt(r.querySelector('input[name*="[valid_to_day]"]').value,10);

    if (vt === 'REVISIT') {
      revisitRanges.push({from, to});
    }
  });

  revisitRanges.sort((a,b)=>a.from-b.from);

  for (let i=1;i<revisitRanges.length;i++){
    if (revisitRanges[i].from <= revisitRanges[i-1].to) {
      alert('Revisit day ranges must not overlap.');
      e.preventDefault();
      return false;
    }
  }
});
</script>
<script>
(function () {

const days = [
    'Monday','Tuesday','Wednesday',
    'Thursday','Friday','Saturday','Sunday'
];

const container = document.getElementById('doctorScheduleContainer');

/* Render days */
days.forEach(day => {
    const div = document.createElement('div');
    div.className = 'day-box';
    div.style.border = '1px solid #ccc';
    div.style.padding = '10px';
    div.style.marginBottom = '10px';
    div.style.background = '#fff';

    div.innerHTML = `
        <div style="display:flex;align-items:center;gap:10px;font-weight:600;">
            <input type="checkbox" onchange="DoctorSchedule.toggleDay(this)">
            <span>${day}</span>
        </div>

        <div class="schedules" style="display:none;margin-top:10px;"></div>

        <button type="button"
                style="display:none;margin-top:8px;"
                onclick="DoctorSchedule.addSchedule(this)">
            + Add Schedule
        </button>
    `;

    container.appendChild(div);
});

window.DoctorSchedule = {

    toggleDay(cb) {
        const box = cb.closest('.day-box');
        box.querySelector('.schedules').style.display = cb.checked ? 'block' : 'none';
        box.querySelector('button').style.display = cb.checked ? 'inline-block' : 'none';
        if (!cb.checked) {
    box.querySelector('.schedules').innerHTML = '';
}

    },

    addSchedule(btn) {
    const dayBox = btn.closest('.day-box');
    const dayName = dayBox.querySelector('span').innerText;

    const schedulesDiv = btn.previousElementSibling;
    const index = schedulesDiv.children.length;

    const schedule = document.createElement('div');
    schedule.classList.add('schedule');
    schedule.style.border = '1px dashed #999';
    schedule.style.padding = '8px';
    schedule.style.marginBottom = '8px';

    schedule.innerHTML = `
        <div style="display:flex;gap:10px;align-items:center;margin-bottom:6px;">
            <label>From</label>
            <input type="time"
                   name="schedule[${dayName}][${index}][from]"
                   required>

            <label>To</label>
            <input type="time"
                   name="schedule[${dayName}][${index}][to]"
                   required>

            <button type="button"
                    onclick="this.closest('.schedule').remove()">
                Remove Schedule
            </button>
        </div>

        <div class="breaks"></div>

        <button type="button"
                onclick="DoctorSchedule.addBreak(this, '${dayName}', ${index})">
            + Add Break
        </button>
    `;

    schedulesDiv.appendChild(schedule);
},


    addBreak(btn, dayName, scheduleIndex) {
    const breaksDiv = btn.previousElementSibling;
    const breakIndex = breaksDiv.children.length;

    const br = document.createElement('div');
    br.classList.add('break');
    br.style.marginLeft = '20px';
    br.style.border = '1px solid #ddd';
    br.style.padding = '6px';
    br.style.marginBottom = '6px';
    br.style.background = '#fafafa';

    br.innerHTML = `
        <div style="display:flex;gap:10px;align-items:center;">
            <label>Break From</label>
            <input type="time"
                   name="schedule[${dayName}][${scheduleIndex}][breaks][${breakIndex}][from]">

            <label>To</label>
            <input type="time"
                   name="schedule[${dayName}][${scheduleIndex}][breaks][${breakIndex}][to]">

            <button type="button"
                    onclick="this.closest('.break').remove()">
                Remove Break
            </button>
        </div>
    `;

    breaksDiv.appendChild(br);
}

};

})();
</script>

<script>
/* ================= DROPDOWN TOGGLE (SAME AS EDIT USER) ================= */
function toggleDropdown(id, event){
    event.stopPropagation();
    const d = document.getElementById(id);

    document.querySelectorAll('.dropdown').forEach(x => {
        if (x.id !== id) x.style.display = 'none';
    });

    d.style.display = (d.style.display === 'block') ? 'none' : 'block';
}

document.addEventListener('click', function () {
    document.querySelectorAll('.dropdown').forEach(d => d.style.display = 'none');
});

document.addEventListener('DOMContentLoaded', function () {
    document.querySelectorAll('.dropdown').forEach(d => {
        d.addEventListener('click', e => e.stopPropagation());
    });
});


/* ================= LOCKED MULTI-SELECT FILL (SHOW VALUES) ================= */
function fillLockedMulti(field, values) {
    values = (Array.isArray(values) ? values : []).map(String);

    const dropdown = document.getElementById(field + 'Dropdown');
    if (!dropdown) return;

    const checkboxes = dropdown.querySelectorAll('input[type="checkbox"]');
    let selectedLabels = [];

    checkboxes.forEach(cb => {
        if (values.includes(cb.value)) {
            cb.checked = true;

            // Get visible label text
            const labelText = cb.parentElement.textContent.trim();
            selectedLabels.push(labelText);
        } else {
            cb.checked = false;
        }
    });

    const display = document.getElementById(field + 'Count');
    if (display) {
        display.innerText = selectedLabels.length
            ? selectedLabels.join(', ')
            : 'None';
    }
}


/* ================= DOCTOR SELECTION → AJAX AUTO-FILL ================= */
document.getElementById('user_id').addEventListener('change', function () {
    const uid = this.value;
    if (!uid) return;

    fetch('fetch_doctor_user.php?user_id=' + encodeURIComponent(uid))
        .then(res => res.json())
        .then(data => {
            
            if (!data.success) {
                alert(data.message || 'Unable to fetch doctor data');
                return;
            }

            const d = data.data || {};
            
            
           
/* ================= LOAD DOCTOR SCHEDULE ================= */
if (data.schedule) {

    document.querySelectorAll('.day-box').forEach(box => {

        const dayName = box.querySelector('span').innerText.trim().toUpperCase();


        if (!data.schedule[dayName]) return;

        const checkbox = box.querySelector('input[type="checkbox"]');
checkbox.checked = true;
DoctorSchedule.toggleDay(checkbox);


        box.querySelector('.schedules').style.display = 'block';
        box.querySelector('button').style.display = 'inline-block';

        data.schedule[dayName].forEach(slot => {
            
            
            

            const addBtn = box.querySelector('button');
            DoctorSchedule.addSchedule(addBtn);

            const schedules = box.querySelectorAll('.schedule');
            const last = schedules[schedules.length - 1];

            last.querySelector('input[name*="[from]"]').value = slot.from;
            last.querySelector('input[name*="[to]"]').value   = slot.to;
            
            if (slot.breaks && Array.isArray(slot.breaks)) {

    slot.breaks.forEach(br => {

        const breakBtn = last.querySelector('button[onclick^="DoctorSchedule.addBreak"]');
        DoctorSchedule.addBreak(breakBtn, dayName, schedules.length - 1);

        const breaks = last.querySelectorAll('.break');
        const lastBreak = breaks[breaks.length - 1];

        lastBreak.querySelector('input[name*="[from]"]').value = br.from;
        lastBreak.querySelector('input[name*="[to]"]').value   = br.to;
    });
}

        });
    });
}



            
           /* ================= DOCTOR SHARE ================= */
if (data.doctor_share) {

    const s = data.doctor_share;

    // Consultation
    if (s.consultation) {
        document.querySelector('[name="doctor_share[consultation][percentage]"]').value =
            s.consultation.percentage ?? '';

        document.querySelector('[name="doctor_share[consultation][flat_amount]"]').value =
            s.consultation.flat_amount ?? '';

        document.querySelector('[name="doctor_share[consultation][wef_date]"]').value =
            s.consultation.wef_date ?? '';
    }

    // Invoice
    if (s.invoice) {
        document.querySelector('[name="doctor_share[invoice][percentage]"]').value =
            s.invoice.percentage ?? '';

        document.querySelector('[name="doctor_share[invoice][flat_amount]"]').value =
            s.invoice.flat_amount ?? '';

        document.querySelector('[name="doctor_share[invoice][wef_date]"]').value =
            s.invoice.wef_date ?? '';
    }

    // Services
    if (s.service) {
        document.querySelector('[name="doctor_share[service][percentage]"]').value =
            s.service.percentage ?? '';

        document.querySelector('[name="doctor_share[service][flat_amount]"]').value =
            s.service.flat_amount ?? '';

        document.querySelector('[name="doctor_share[service][wef_date]"]').value =
            s.service.wef_date ?? '';
    }

} else {
    // clear fields if no share exists
    document
        .querySelectorAll('[name^="doctor_share"]')
        .forEach(el => el.value = '');
}
 
            /* ================= DOCTOR MEDIA ================= */
if (data.media) {

    if (data.media.profile_photo) {
        const img = document.getElementById('profilePreview');
        img.src = '/' + data.media.profile_photo;
        img.style.display = 'block';
    } else {
        document.getElementById('profilePreview').style.display = 'none';
    }

    if (data.media.signature_image) {
        const img = document.getElementById('signaturePreview');
        img.src = '/' + data.media.signature_image;
        img.style.display = 'block';
    } else {
        document.getElementById('signaturePreview').style.display = 'none';
    }

} else {
    document.getElementById('profilePreview').style.display = 'none';
    document.getElementById('signaturePreview').style.display = 'none';
}

            
            /* ================= DOCTOR CHARGES ================= */
const tbody = document.getElementById('chargesBody');
tbody.innerHTML = '';
chargeIndex = 0;

if (data.charges && Array.isArray(data.charges)) {

    data.charges.forEach(row => {
        addChargeRow({
            visit_type: row.visit_type,
            charge_amount: row.charge_amount,
            valid_from_day: row.valid_from_day,
            valid_to_day: row.valid_to_day
        });
    });

}

            
            /* ================= DOCTOR MASTER CONFIG ================= */
if (data.doctor_config) {

    const cfg = data.doctor_config;

    // License No
    const licenseInput = document.querySelector('input[name="license_no"]');
    if (licenseInput) {
        licenseInput.value = cfg.license_no || '';
    }

    // Surgeon
    const surgeonSelect = document.querySelector('select[name="is_surgeon"]');
    if (surgeonSelect) {
        surgeonSelect.value = cfg.is_surgeon ?? '0';
    }

    // Time Slot Minutes
    const slotInput = document.querySelector('input[name="time_slot_minutes"]');
    if (slotInput) {
        slotInput.value = cfg.time_slot_minutes || '';
    }
}


            /* ---- SIMPLE READ-ONLY FIELDS ---- */
            [
                'employee_id','full_name','username','email','mobile_no',
                'gender','dob','marital_status','aadhaar','qualification',
                'employee_type','status'
            ].forEach(f => {
                const el = document.getElementById(f);
    const hid = document.getElementById(f + '_hidden');

    if (el) el.value = d[f] ?? '';
    if (hid) hid.value = d[f] ?? '';
});

            /* ---- DOCTOR TYPE ---- */
            if (d.employee_sub_type) {
                const val = d.employee_sub_type.split(',')[0].trim();
                const sel = document.getElementById('employee_sub_type');
                const hid = document.querySelector('[name="employee_sub_type"]');
                if (sel) sel.value = val;
                if (hid) hid.value = val;
            }

            /* ---- LOCKED MULTI-SELECTS (SHOW TEXT) ---- */
           fillLockedMulti('hospital',   d.hospital_ids || []);
fillLockedMulti('branch',    d.branch_ids || []);
fillLockedMulti('department',d.department_ids || []);
fillLockedMulti('designation',d.designation_ids || []);
/* ===== SPECIALIZATION AUTO FILL ===== */
if (Array.isArray(data.specialization_codes)) {

    document
        .querySelectorAll('#specializationDropdown input[type="checkbox"]')
        .forEach(cb => {
            cb.checked = data.specialization_codes.includes(cb.value);
        });

    updateSpecializationDisplay();
}


        })
        .catch(err => {
            console.error(err);
            alert('Network error while fetching doctor data');
        });
});
</script>


<script>
function updateSpecializationDisplay() {
    const dropdown = document.getElementById('specializationDropdown');
    const display  = document.getElementById('specializationCount');
    if (!dropdown || !display) return;

    const checkboxes = dropdown.querySelectorAll('input[type="checkbox"]');
    let selectedLabels = [];

    checkboxes.forEach(cb => {
        if (cb.checked) {
            selectedLabels.push(cb.parentElement.textContent.trim());
        }
    });

    display.innerText = selectedLabels.length
        ? selectedLabels.join(', ')
        : 'Select Specialization';
}

/* listen for specialization checkbox change */
document.addEventListener('change', function(e){
    if (e.target.matches('#specializationDropdown input[type="checkbox"]')) {
        updateSpecializationDisplay();
    }
});
</script>
<script>
window.DoctorMedia = {
    preview(input, previewId) {
        const file = input.files[0];
        const img = document.getElementById(previewId);

        if (!file || !file.type.startsWith('image/')) {
            img.style.display = 'none';
            img.src = '';
            return;
        }

        const reader = new FileReader();
        reader.onload = function (e) {
            img.src = e.target.result;
            img.style.display = 'block';
        };
        reader.readAsDataURL(file);
    }
};
</script>
<script>
function syncLockedMulti(field, values) {
    values = Array.isArray(values) ? values : [];

    // remove old hidden inputs
    document.querySelectorAll(`input[name="${field}_ids[]"]`)
        .forEach(e => e.remove());

    values.forEach(v => {
        const h = document.createElement('input');
        h.type = 'hidden';
        h.name = field + '_ids[]';
        h.value = v;
        document.getElementById('doctorForm').appendChild(h);
    });
}
</script>

<script>
window.DoctorShare = {

    addRow(type) {
        const table = document.querySelector(
            `.share-table[data-type="${type}"] tbody`
        );

        const idx = table.children.length;

        const tr = document.createElement('tr');
        tr.innerHTML = `
            <td>
                <input type="number" step="0.01" min="0"
                       name="doctor_share[${type}][${idx}][percentage]"
                       placeholder="%" >
            </td>

            <td>
                <input type="number" step="0.01" min="0"
                       name="doctor_share[${type}][${idx}][flat_amount]"
                       placeholder="Amount">
            </td>

            <td>
                <input type="date"
                       name="doctor_share[${type}][${idx}][wef_date]"
                       required>
            </td>

            <td>
                <button type="button"
                        onclick="this.closest('tr').remove()">
                    Remove
                </button>
            </td>
        `;

        table.appendChild(tr);
    }

};
</script>
<script>
document.addEventListener('DOMContentLoaded', function () {

    const params = new URLSearchParams(window.location.search);
    const userId = params.get('user_id');

    if (!userId) return;

    const select = document.getElementById('user_id');
    if (!select) return;

    // wait until options are ready
    let found = false;

    for (let i = 0; i < select.options.length; i++) {
        if (select.options[i].value === userId) {
            select.selectedIndex = i;
            found = true;
            break;
        }
    }

    if (found) {
        // 🔥 trigger AJAX auto-load
        select.dispatchEvent(new Event('change', { bubbles: true }));
    } else {
        console.warn('Doctor user_id not found in dropdown:', userId);
    }
});
</script>

</body>
</html>
