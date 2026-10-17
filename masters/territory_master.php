<?php
ini_set('display_errors', 1);
ini_set('display_startup_errors', 1);
error_reporting(E_ALL);
session_start();
require $_SERVER['DOCUMENT_ROOT'].'/includes/db.php';
require $_SERVER['DOCUMENT_ROOT'].'/includes/permissions.php';
require_once $_SERVER['DOCUMENT_ROOT'].'/includes/audit_log.php';
if (!isset($_SESSION['user_id'])) { header('Location:/index.php'); exit; }
if (!checkPermission('territory_master','view')) { header('Location:/access_denied.php'); exit; }
/* ---------- SAVE ---------- */
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    function clean($v){ return array_filter(array_map('trim', explode(',', $v))); }
    $mode = $_POST['mode'];
    if ($mode === 'country' && !empty($_POST['country'])) {
        $stmt = $conn->prepare("INSERT IGNORE INTO countries (name) VALUES (?)");
foreach (clean($_POST['country']) as $c) {
$stmt->bind_param("s",$c);
$stmt->execute();
}
    }

    if ($mode === 'state' && !empty($_POST['state'])) {
        $country = (int)$_POST['country_id'];
        $stmt = $conn->prepare("INSERT IGNORE INTO states (country_id,name) VALUES (?,?)");
foreach (clean($_POST['state']) as $s) {
    $stmt->bind_param("is",$country,$s);
    $stmt->execute();
}
    }

    if ($mode === 'district' && !empty($_POST['district'])) {
        $state = (int)$_POST['state_id'];
        $stmt = $conn->prepare("INSERT IGNORE INTO districts (state_id,name) VALUES (?,?)");
foreach (clean($_POST['district']) as $d) {
    $stmt->bind_param("is",$state,$d);
    $stmt->execute();
}
    }
if ($mode === 'city' && !empty($_POST['city'])) {
    $district = (int)$_POST['district_id'];
    $userId = $_SESSION['user_id'];
    $editId = $_POST['edit_id'] ?? '';
    if(!empty($editId)){
        $old = $conn->query("
    SELECT name
    FROM cities
    WHERE id = $editId
")->fetch_assoc();

$stmt = $conn->prepare("
    UPDATE cities
    SET name=?, district_id=?
    WHERE id=?
");

$stmt->bind_param("sii", $_POST['city'], $district, $editId);
$stmt->execute();

addAuditLog(
    'City Master',
    'UPDATE',
    'City Updated',
    $editId,
    $old['name'],
    $_POST['city']
);
    }else{
        $stmt = $conn->prepare("INSERT IGNORE INTO cities (district_id,name,created_by) VALUES (?,?,?)");
        foreach (clean($_POST['city']) as $c) {
    $stmt->bind_param("isi", $district, $c, $userId);
    $stmt->execute();
    $cityId = $conn->insert_id;

addAuditLog(
    'City Master',
    'CREATE',
    'City Created : '.$c,
    $cityId,
    '',
    $c
);
}
    }
}
    header("Location: territory_master.php");
    exit;
}
/* ---------- DELETE ---------- */
if (isset($_GET['delete_id']) && is_numeric($_GET['delete_id'])) {
    if (!checkPermission('territory_master','delete')) {
        header('Location:/access_denied.php');
        exit;
    }
    $id = (int)$_GET['delete_id'];

$old = $conn->query("
    SELECT name
    FROM cities
    WHERE id = $id
")->fetch_assoc();

$stmt = $conn->prepare("
    DELETE FROM cities
    WHERE id=?
");

$stmt->bind_param("i",$id);
$stmt->execute();

addAuditLog(
    'City Master',
    'DELETE',
    'City Deleted : '.$old['name'],
    $id
);
    header("Location: territory_master.php");
    exit;
}
/* ---------- DATA ---------- */
$countries = $conn->query("SELECT * FROM countries ORDER BY name");
// Pagination
$page = max(1, (int)($_GET['page'] ?? 1));
$limit = 50;
$offset = ($page - 1) * $limit;
$country_id = (int)($_GET['country_id'] ?? 0);
$state_id   = (int)($_GET['state_id'] ?? 0);
$district_id = (int)($_GET['district_id'] ?? 0);
$city_id = (int)($_GET['city_id'] ?? 0);
$search = trim($_GET['search'] ?? '');

// Total records
$where = [];

if($country_id > 0){
    $where[] = "c.id = $country_id";
}

if($state_id > 0){
    $where[] = "s.id = $state_id";
}
if($district_id > 0){
    $where[] = "d.id = $district_id";
}
if($city_id > 0){
    $where[] = "ci.id = $city_id";
}
if($search != ''){
    $search = $conn->real_escape_string($search);

    $where[] = "(
        c.name LIKE '%$search%' OR
        s.name LIKE '%$search%' OR
        d.name LIKE '%$search%' OR
        ci.name LIKE '%$search%'
    )";
}
$whereSql = count($where) ? "WHERE ".implode(" AND ", $where) : "";

$totalRecords = $conn->query("
    SELECT COUNT(*) AS total
    FROM cities ci
    JOIN districts d ON d.id = ci.district_id
    JOIN states s ON s.id = d.state_id
    JOIN countries c ON c.id = s.country_id
    $whereSql
")->fetch_assoc()['total'];

$totalPages = ceil($totalRecords / $limit);

// Current page records only
$list = $conn->query("
SELECT
c.name AS country,
s.name AS state,
d.name AS district,
ci.id AS city_id,
IFNULL(ci.name,'-') AS city,
IFNULL(u.full_name,'-') AS created_by,
IFNULL(ci.created_date,'-') AS created_date
FROM cities ci
JOIN districts d ON d.id = ci.district_id
JOIN states s ON s.id = d.state_id
JOIN countries c ON c.id = s.country_id
LEFT JOIN users u ON u.id = ci.created_by
$whereSql
ORDER BY c.name,s.name,d.name,ci.name
LIMIT $offset, $limit
");

$filterCounts = [
    'country'  => $conn->query("SELECT COUNT(*) c FROM countries")->fetch_assoc()['c'],
    'state'    => $conn->query("SELECT COUNT(*) c FROM states")->fetch_assoc()['c'],
    'district' => $conn->query("SELECT COUNT(*) c FROM districts")->fetch_assoc()['c'],
    'city'     => $conn->query("SELECT COUNT(*) c FROM cities")->fetch_assoc()['c'],
];
?>
<!DOCTYPE html>
<html>
<head>
<title>City Master</title>
<link rel="stylesheet" href="/assets/css/style.css">
<link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.5.0/css/all.min.css">
<link rel="icon" type="image/png" href="/assets/img/hms_logo.png">
<style>
    #pagination{
    display:flex;
    justify-content:center;
    align-items:center;
    gap:8px;
    padding:10px 0;
}

#pagination button{
    min-width:36px;
    height:36px;
    border:1px solid #ddd;
    background:#1976d2;
    cursor:pointer;
    border-radius:4px;
}

#pagination button:disabled{
    opacity:.5;
    cursor:not-allowed;
}

.page-info{
    font-weight:600;
    min-width:70px;
    text-align:center;
}
.table-wrapper{
    height: calc(100vh - 160px); /* adjust to your header/footer */
    overflow-y: auto;
    overflow-x: auto;
}
</style>
</head>
<?php require $_SERVER['DOCUMENT_ROOT'].'/includes/header.php'; ?>
<body>
<div class="top-bar">  
    <div class="breadcrumb"><a href="/dashboard/index.php">Home</a><span>&gt;</span>
    <a href="/settings/settings.php">Settings</a><span>&gt;</span>
    <a href="/masters/masters.php">Masters</a><span>&gt;</span>
    <a>City Masters</a>
</div>
    <h2 style="margin:0 auto;">City Master</h2>
    <form method="GET" style="margin:0 15px;">
    <input type="hidden" name="country_id" value="<?= $country_id ?>">
    <input type="hidden" name="state_id" value="<?= $state_id ?>">
    <input type="hidden" name="district_id" value="<?= $district_id ?>">
    <input type="hidden" name="city_id" value="<?= $city_id ?>">

    <input type="text"
           name="search"
           value="<?= htmlspecialchars($search) ?>"
           placeholder="Search Country, State, District, City"
           style="width:250px;">

    <button type="submit" class="btn">
        Search
    </button>
</form>



<div style="display:flex; gap:10px; align-items:center;">
       <select id="filterCountry" onchange="reloadFilters()">
    <option value="">Country (<?= $filterCounts['country'] ?>)</option>

    <?php
    $countryFilter = $conn->query("SELECT id,name FROM countries ORDER BY name");
    while($c = $countryFilter->fetch_assoc()):
    ?>
        <option value="<?= $c['id'] ?>"
<?= $country_id == $c['id'] ? 'selected' : '' ?>>
            <?= htmlspecialchars($c['name']) ?>
        </option>
    <?php endwhile; ?>
</select>
<select id="filterState" onchange="reloadFilters()">
    <option value="">
        State (
        <?= $country_id > 0
            ? $conn->query("SELECT COUNT(*) c FROM states WHERE country_id = $country_id")->fetch_assoc()['c']
            : 0 ?>
        )
    </option>
    <?php
    if($country_id > 0){

        $stateFilter = $conn->query("
            SELECT id,name,country_id
            FROM states
            WHERE country_id = $country_id
            ORDER BY name
        ");

        while($s = $stateFilter->fetch_assoc()):
    ?>
        <option
            value="<?= $s['id'] ?>"
            <?= $state_id == $s['id'] ? 'selected' : '' ?>
        >
            <?= htmlspecialchars($s['name']) ?>
        </option>
    <?php
        endwhile;
    }
    ?>
</select>
<select id="filterDistrict" onchange="reloadFilters()">

<option value="">
District (
<?= $state_id > 0
? $conn->query("SELECT COUNT(*) c FROM districts WHERE state_id=$state_id")->fetch_assoc()['c']
: 0 ?>
)
</option>

<?php
if($state_id > 0){

    $districtFilter = $conn->query("
        SELECT id,name
        FROM districts
        WHERE state_id = $state_id
        ORDER BY name
    ");

    while($d = $districtFilter->fetch_assoc()):
?>

<option
    value="<?= $d['id'] ?>"
    <?= $district_id == $d['id'] ? 'selected' : '' ?>
>
    <?= htmlspecialchars($d['name']) ?>
</option>

<?php
    endwhile;
}
?>

</select>
<select id="filterCity" onchange="reloadFilters()">

<option value="">
City (
<?= $district_id > 0
? $conn->query("SELECT COUNT(*) c FROM cities WHERE district_id=$district_id")->fetch_assoc()['c']
: 0 ?>
)
</option>
<?php
if($district_id > 0){
    $cityFilter = $conn->query("
        SELECT id,name
        FROM cities
        WHERE district_id = $district_id
        ORDER BY name
    ");
    while($city = $cityFilter->fetch_assoc()):
?>
<option
    value="<?= $city['id'] ?>"
    <?= $city_id == $city['id'] ? 'selected' : '' ?>
>
    <?= htmlspecialchars($city['name']) ?>
</option>
<?php
    endwhile;
}
?>
</select>
<button class="btn" type="button" onclick="resetFilters()">Reset</button>
        <?php if (checkPermission('territory_master','create')): ?>
            <button class="btn" onclick="openModal()">+ Add</button>
        <?php endif; ?>
    </div>
</div>
<div class="table-wrapper">
<table class="table">
<tr>
    <th style="width:20px;">Sr. No.</th>
<th onclick="sortTable(1,this)">Country</th>
<th onclick="sortTable(2,this)">State</th>
<th onclick="sortTable(3,this)">District</th>
<th onclick="sortTable(4,this)">City</th>
    <th>Created By</th>
    <th>Created Date</th>
    <th style="width:20px;">Action</th>
</tr>
<?php $sr = $offset + 1; ?>
<?php while($r=$list->fetch_assoc()): ?>
<tr
    data-country="<?= htmlspecialchars($r['country']) ?>"
    data-state="<?= htmlspecialchars($r['state']) ?>"
    data-district="<?= htmlspecialchars($r['district']) ?>"
    data-city="<?= htmlspecialchars($r['city']) ?>"
>
<td><?= $sr++ ?></td>
<td><?= htmlspecialchars($r['country'] ?? '') ?></td>
<td><?= htmlspecialchars($r['state'] ?? '') ?></td>
<td><?= htmlspecialchars($r['district'] ?? '') ?></td>
<td><?= htmlspecialchars($r['city'] ?? '-') ?></td>
<td><?= htmlspecialchars($r['created_by'] ?? '-') ?></td>
<td><?= !empty($r['created_date'])     ? date('d-M-Y h:iA', strtotime($r['created_date']))     : '-' ?></td>
<td class="action-buttons">
<?php if (checkPermission('territory_master','edit') && $r['city']!='-'): ?>
<button class="btn-icon btn-edit" title="Edit"
onclick="editCity(
<?= $r['city_id'] ?? 0 ?>,
'<?= htmlspecialchars($r['country']) ?>',
'<?= htmlspecialchars($r['state']) ?>',
'<?= htmlspecialchars($r['district']) ?>',
'<?= htmlspecialchars($r['city']) ?>'
)">
<i class="fa-solid fa-pen-to-square"></i>
</button>
<?php endif; ?>
<?php if (checkPermission('territory_master','delete')): ?>
<button class="btn-icon btn-delete" title="Delete"
onclick="deleteCity(<?= $r['city_id'] ?? 0 ?>)">
<i class="fa-solid fa-trash"></i>
</button>
<?php endif; ?>
</td>
</tr>
<?php endwhile; ?>
</table>
</div>
<div style="margin:8px 0; text-align:center;">

<?php if($page > 1): ?>
<a class="btn" href="?page=1&country_id=<?= $country_id ?>&state_id=<?= $state_id ?>&district_id=<?= $district_id ?>&city_id=<?= $city_id ?>">First</a>

<a class="btn" href="?page=<?= $page - 1 ?>&country_id=<?= $country_id ?>&state_id=<?= $state_id ?>&district_id=<?= $district_id ?>&city_id=<?= $city_id ?>">Previous</a>
<?php endif; ?>

<span style="padding:0 15px;font-weight:bold;">
Page <?= $page ?> of <?= $totalPages ?>
</span>

<?php if($page < $totalPages): ?>
<a class="btn" href="?page=<?= $page + 1 ?>&country_id=<?= $country_id ?>&state_id=<?= $state_id ?>&district_id=<?= $district_id ?>&city_id=<?= $city_id ?>&search=<?= urlencode($search) ?>">Next</a>

<a class="btn" href="?page=<?= $totalPages ?>&country_id=<?= $country_id ?>&state_id=<?= $state_id ?>&district_id=<?= $district_id ?>&city_id=<?= $city_id ?>">Last</a>
<?php endif; ?>

</div>



<!-- 
<div id="pagination" style="margin-top:15px; display:flex; gap:6px; align-items:center;"></div>
</div>
 -->
<!-- MODAL -->
<div class="modal" id="modal">
<div class="modal-box">
   <!-- Modal Header -->
    <div class="modal-header">
      <h2 id="modalTitle">Add / Edit</h2>
      <button class="close-btn" onclick="closeModal()">×</button>
    </div> 
<form method="post" onsubmit="closeModal()">
<input type="hidden" name="edit_id" id="edit_id">
<select name="mode" onchange="toggle(this.value)" required>
<option value="">-- What to Add --</option>
<option value="country">Country</option>
<option value="state">State</option>
<option value="district">District</option>
<option value="city">City</option>
</select>
<!-- COUNTRY -->
<div id="countryBox">
<input type="text" name="country" placeholder="India, USA" required>
</div>
<!-- STATE -->
<div id="stateBox" style="display:none">
<select name="country_id" onchange="filterStates(this.value)">
<option value="">Select Country</option>
<?php
$countryList = $conn->query("SELECT * FROM countries ORDER BY name");
while($c=$countryList->fetch_assoc()):
?>
<option value="<?=$c['id']?>"><?=$c['name']?></option>
<?php endwhile; ?>
</select>
<input type="text" name="state" placeholder="Karnataka, Maharashtra" required>
</div>
<!-- DISTRICT -->
<div id="districtBox" style="display:none">
<select id="d_country" onchange="filterStates(this.value)">
<option value="">Select Country</option>
<?php
$countryList->data_seek(0);
while($c=$countryList->fetch_assoc()):
?>
<option value="<?=$c['id']?>"><?=$c['name']?></option>
<?php endwhile; ?>
</select>
<select name="state_id" id="stateSelect">
<option value="">Select State</option>
<?php
$stateList = $conn->query("SELECT s.id,s.name,s.country_id,c.name country FROM states s JOIN countries c ON c.id=s.country_id");
while($s=$stateList->fetch_assoc()):
?>
<option value="<?=$s['id']?>" data-country="<?=$s['country_id']?>">
<?=$s['name']?> (<?=$s['country']?>)
</option>
<?php endwhile; ?>
</select>
<input type="text" name="district" placeholder="Bangalore Urban, Pune" required>
</div>
<!-- CITY -->
<div id="cityBox" style="display:none">
<select id="c_country" onchange="filterStates(this.value)">
<option value="">Select Country</option>
<?php
$countryList->data_seek(0);
while($c=$countryList->fetch_assoc()):
?>
<option value="<?=$c['id']?>"><?=$c['name']?></option>
<?php endwhile; ?>
</select>
<select id="c_state" onchange="filterDistricts(this.value)">
<option value="">Select State</option>
<?php
$stateList->data_seek(0);
while($s=$stateList->fetch_assoc()):
?>
<option value="<?=$s['id']?>" data-country="<?=$s['country_id']?>">
<?=$s['name']?>
</option>
<?php endwhile; ?>
</select>
<select name="district_id" id="districtSelect">
<option value="">Select District</option>
<?php
$districtList = $conn->query("SELECT d.id,d.name,d.state_id,s.name state FROM districts d JOIN states s ON s.id=d.state_id");
while($d=$districtList->fetch_assoc()):
?>
<option value="<?=$d['id']?>" data-state="<?=$d['state_id']?>">
<?=$d['name']?> (<?=$d['state']?>)
</option>
<?php endwhile; ?>
</select>
<input type="text" name="city" placeholder="Whitefield, Andheri" required>
</div>
<div style="display:flex; gap:10px; margin-top:10px;">
    <button class="btn" type="submit">Save</button>
    <button type="button" class="btn btn-secondary" onclick="closeModal()">Cancel</button>
    </div>
</form>
</div>
</div>
<script>
function openModal(){
document.getElementById('modal').style.display='block';
}
function closeModal(){
document.getElementById('modal').style.display='none';
}
</script>
<script>
function toggle(v){
    const boxes = ['countryBox','stateBox','districtBox','cityBox'];

    // Reset all
    boxes.forEach(id=>{
        const box = document.getElementById(id);
        box.style.display = 'none';

        box.querySelectorAll('input,select').forEach(el=>{
            el.required = false;
            el.disabled = true;
            el.value = "";
        });
    });

    // COUNTRY
    if(v==='country'){
        const box = document.getElementById('countryBox');
        box.style.display='block';

        const input = box.querySelector('input');
        input.required = true;
        input.disabled = false;
    }

    // STATE
    if(v==='state'){
        const box = document.getElementById('stateBox');
        box.style.display='block';

        const country = box.querySelector('select');
        const state   = box.querySelector('input');

        country.required = true;
        state.required   = true;

        country.disabled = false;
        state.disabled   = false;
    }

    // DISTRICT
    if(v==='district'){
        const box = document.getElementById('districtBox');
        box.style.display='block';

        const country = document.getElementById('d_country');
        const state   = document.getElementById('stateSelect');
        const district= box.querySelector('input');

        country.required  = true;
        state.required    = true;
        district.required = true;

        country.disabled  = false;
        state.disabled    = false;
        district.disabled = false;
    }

    // CITY
    if(v==='city'){
        const box = document.getElementById('cityBox');
        box.style.display='block';

        const country = document.getElementById('c_country');
        const state   = document.getElementById('c_state');
        const district= document.getElementById('districtSelect');
        const city    = box.querySelector('input');

        country.required  = true;
        state.required    = true;
        district.required = true;
        city.required     = true;

        country.disabled  = false;
        state.disabled    = false;
        district.disabled = false;
        city.disabled     = false;
    }
}
function filterStates(countryId){
// district section
const stateDropdown = document.getElementById('stateSelect');
if(stateDropdown){
Array.from(stateDropdown.options).forEach(o=>{
if(!o.value) return;
o.style.display = (!countryId || o.dataset.country===countryId) ? 'block' : 'none';
});
stateDropdown.value="";
}
// city section
const cityStateDropdown = document.getElementById('c_state');
if(cityStateDropdown){
Array.from(cityStateDropdown.options).forEach(o=>{
if(!o.value) return;
o.style.display = (!countryId || o.dataset.country===countryId) ? 'block' : 'none';
});
cityStateDropdown.value="";
}
}
function filterDistricts(stateId){
const districtDropdown = document.getElementById('districtSelect');
document.querySelectorAll('#districtSelect option').forEach(o=>{
o.style.display = (!stateId || o.dataset.state===stateId) ? 'block' : 'none';
});
districtDropdown.value = "";
}
</script>
<script>
function applyTableFilters() {

    const fc  = document.getElementById('filterCountry').value;
    const fs  = document.getElementById('filterState').value;
    const fd  = document.getElementById('filterDistrict').value;
    const fci = document.getElementById('filterCity').value;

    const rows = document.querySelectorAll('table.table tr');

    rows.forEach((row,index)=>{

        if(index===0) return;

        const stateText =
document.getElementById('filterState')
.options[document.getElementById('filterState').selectedIndex]
.text.trim();

const districtText =
document.getElementById('filterDistrict')
.options[document.getElementById('filterDistrict').selectedIndex]
?.text.trim();

const cityText =
document.getElementById('filterCity')
.options[document.getElementById('filterCity').selectedIndex]
?.text.trim();

const matchCountry =
!fc ||
row.dataset.country ===
document.getElementById('filterCountry')
.options[document.getElementById('filterCountry').selectedIndex]
.text.trim();

const matchState =
!fs || row.dataset.state === stateText;

const matchDistrict =
!fd || row.dataset.district === districtText;

const matchCity =
!fci || row.dataset.city === cityText;

        row.dataset.filtered =
            (matchCountry && matchState && matchDistrict && matchCity)
            ? "1"
            : "0";
    });

    // currentPage = 1;
// applyPagination();
}
function updateStateFilter() {
const country = document.getElementById('filterCountry').value;
const stateDropdown = document.getElementById('filterState');
stateDropdown.value = "";
Array.from(stateDropdown.options).forEach(o=>{
if(!o.value) return;
const rows = document.querySelectorAll('table.table tr');
let show = false;
rows.forEach(r=>{
if(r.dataset.country === country && r.dataset.state === o.value){
show = true;
}
});
o.style.display = (!country || show) ? 'block' : 'none';
});
}
function updateStateCount(){
const stateDropdown = document.getElementById('filterState');
let count = 0;
Array.from(stateDropdown.options).forEach(o=>{
if(o.style.display !== 'none' && o.value){
count++;
}
});
stateDropdown.options[0].text = "State (" + count + ")";
}
function updateDistrictFilter(){
const country = document.getElementById('filterCountry').value;
const state   = document.getElementById('filterState').value;
const districtDropdown = document.getElementById('filterDistrict');
districtDropdown.value = "";
Array.from(districtDropdown.options).forEach(o=>{
if(!o.value) return;
const rows = document.querySelectorAll('table.table tr');
let show = false;
rows.forEach(r=>{
const matchCountry = !country || r.dataset.country === country;
const matchState   = !state || r.dataset.state === state;
if(matchCountry && matchState && r.dataset.district === o.value){
show = true;
}
});
o.style.display = show ? 'block' : 'none';
});
updateDistrictCount();
}
function updateDistrictCount(){
const districtDropdown = document.getElementById('filterDistrict');
let count = 0;
Array.from(districtDropdown.options).forEach(o=>{
if(o.style.display !== 'none' && o.value){
count++;
}
});
districtDropdown.options[0].text = "District (" + count + ")";
}
function updateCityFilter(){
const country  = document.getElementById('filterCountry').value;
const state    = document.getElementById('filterState').value;
const district = document.getElementById('filterDistrict').value;
const cityDropdown = document.getElementById('filterCity');
cityDropdown.value = "";
Array.from(cityDropdown.options).forEach(o=>{
if(!o.value) return;
const rows = document.querySelectorAll('table.table tr');
let show = false;
rows.forEach(r=>{
const matchCountry  = !country  || r.dataset.country  === country;
const matchState    = !state    || r.dataset.state    === state;
const matchDistrict = !district || r.dataset.district === district;
if(matchCountry && matchState && matchDistrict && r.dataset.city === o.value){
show = true;
}
});
o.style.display = show ? 'block' : 'none';
});
updateCityCount();
}
function updateCityCount(){
const cityDropdown = document.getElementById('filterCity');
let count = 0;
Array.from(cityDropdown.options).forEach(o=>{
if(o.style.display !== 'none' && o.value){
count++;
}
});
cityDropdown.options[0].text = "City (" + count + ")";
}
</script>
<script>
function sortTable(colIndex, th) {
    const table = document.querySelector('table.table');
    const rows = Array.from(table.querySelectorAll('tr')).slice(1); // skip header
    const isAsc = !th.classList.contains('sort-asc');
    // clear all arrows
    table.querySelectorAll('th').forEach(h => {
        h.classList.remove('sort-asc', 'sort-desc');
    });
    rows.sort((a, b) => {
        const x = a.cells[colIndex].innerText.toLowerCase();
        const y = b.cells[colIndex].innerText.toLowerCase();
        if (x < y) return isAsc ? -1 : 1;
        if (x > y) return isAsc ? 1 : -1;
        return 0;
    });
    // append sorted rows
  rows.forEach(row => table.appendChild(row));

// reset to page 1 after sorting
applyTableFilters();

// set arrow
th.classList.add(isAsc ? 'sort-asc' : 'sort-desc');
}
</script>
<script>
function resetFilters(){
    window.location='territory_master.php';
}
</script>
<script>
/*
let currentPage = 1;
const rowsPerPage = 12;

function applyPagination() {

    const rows = Array.from(
        document.querySelectorAll('table.table tr')
    ).slice(1).filter(row =>
        row.dataset.filtered !== "0"
    );

    const totalPages = Math.max(1, Math.ceil(rows.length / rowsPerPage));

    if (currentPage > totalPages) {
        currentPage = totalPages;
    }

    // Hide all rows first
    document.querySelectorAll('table.table tr').forEach((row, index) => {
        if (index === 0) return;
        row.style.display = "none";
    });

    // Show current page rows
    rows.forEach((row, index) => {
        if (
            index >= (currentPage - 1) * rowsPerPage &&
            index < currentPage * rowsPerPage
        ) {
            row.style.display = "";
        }
    });

    const pagination = document.getElementById("pagination");

    pagination.innerHTML = `
        <button ${currentPage === 1 ? 'disabled' : ''}
            onclick="currentPage=1;applyPagination()">
            &laquo;
        </button>

        <button ${currentPage === 1 ? 'disabled' : ''}
            onclick="currentPage--;applyPagination()">
            &lsaquo;
        </button>

        <span class="page-info">
            ${currentPage} / ${totalPages}
        </span>

        <button ${currentPage === totalPages ? 'disabled' : ''}
            onclick="currentPage++;applyPagination()">
            &rsaquo;
        </button>

        <button ${currentPage === totalPages ? 'disabled' : ''}
            onclick="currentPage=${totalPages};applyPagination()">
            &raquo;
        </button>
    `;
}
*/
document.addEventListener('keydown', function(e) {
    if (e.key === "Escape") {
        const modal = document.getElementById('modal');
        if (modal.style.display === 'block') {
            closeModal();
        }
    }
});
window.onclick = function(e) {
    const modal = document.getElementById('modal');
    if (e.target === modal) {
        closeModal();
    }
}
let deleteId = null;
function deleteCity(id){
deleteId = id;
document.getElementById("deleteConfirm").style.display = "flex";
}
function closeDelete(){
document.getElementById("deleteConfirm").style.display = "none";
deleteId = null;
}
document.addEventListener("DOMContentLoaded", function(){
document.getElementById("confirmDeleteBtn").onclick = function(){
if(!deleteId) return;
window.location.href = "territory_master.php?delete_id=" + deleteId;
};
});
function editCity(id,country,state,district,city){
// set edit id
document.getElementById("edit_id").value = id;
// set mode
document.querySelector('select[name="mode"]').value = "city";
toggle("city");
// set city name
document.querySelector('input[name="city"]').value = city;
// open modal first
openModal();
// ----- COUNTRY -----
const countrySelect = document.getElementById("c_country");
for (let opt of countrySelect.options){
    if(opt.text === country){
        countrySelect.value = opt.value;
        break;
    }
}
// filter states for this country
filterStates(countrySelect.value);
// ----- STATE -----
const stateSelect = document.getElementById("c_state");
for (let opt of stateSelect.options){
    if(opt.text === state){
        stateSelect.value = opt.value;
        break;
    }
}
// filter districts
filterDistricts(stateSelect.value);
// ----- DISTRICT -----
const districtSelect = document.getElementById("districtSelect");
for (let opt of districtSelect.options){
    if(opt.text.includes(district)){
        districtSelect.value = opt.value;
        break;
    }
}
}
</script>
<script src="https://cdn.jsdelivr.net/npm/sweetalert2@11"></script>
<div id="deleteConfirm" class="confirm-modal">
  <div class="confirm-box">
    <p>Are you sure you want to delete this city?</p>
<small>This action cannot be undone.</small>
    <div class="confirm-actions">
      <button class="btn-cancel" onclick="closeDelete()">Cancel</button>
      <button class="btn-confirm" id="confirmDeleteBtn">Delete</button>
    </div>
  </div>
</div>
<script>
document.getElementById('filterCountry').addEventListener('change', function(){

    let countryId = this.value;
    let stateDD = document.getElementById('filterState');

    stateDD.value = '';

    let count = 0;

    Array.from(stateDD.options).forEach(function(opt){

        if(opt.value === '') return;

        if(opt.dataset.country === countryId){
            opt.style.display = '';
            count++;
        }else{
            opt.style.display = 'none';
        }

    });

    stateDD.options[0].text = 'State (' + count + ')';

});
</script>
<script>
function filterByState(){

    let selectedState = document.getElementById('filterState');
    let selectedText = selectedState.options[selectedState.selectedIndex].text.trim();

    let rows = document.querySelectorAll('table.table tr');
    console.log(selectedText);

    rows.forEach((row,index)=>{

        if(index === 0) return; // header

        let stateCell = row.cells[2].textContent.trim();

        if(selectedState.value === ''){
            row.style.display = '';
        }
        else if(stateCell === selectedText){
            row.style.display = '';
        }
        else{
            row.style.display = 'none';
        }

    });

}
</script>
<script>
function reloadFilters(){

    let country = document.getElementById('filterCountry').value;
    let state = document.getElementById('filterState').value;
    let district = document.getElementById('filterDistrict').value;
    let city = document.getElementById('filterCity').value;

    let search = document.querySelector('input[name="search"]').value;

    window.location =
        'territory_master.php?country_id=' + country +
        '&state_id=' + state +
        '&district_id=' + district +
        '&city_id=' + city +
        '&search=' + encodeURIComponent(search);
}
</script>
</body>
</html>