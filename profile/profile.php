<?php
error_reporting(E_ALL);
ini_set('display_errors',1);

session_start();
require $_SERVER['DOCUMENT_ROOT'].'/includes/db.php';
require $_SERVER['DOCUMENT_ROOT'].'/includes/permissions.php';

if (!isset($_SESSION['user_id'])) {
    header('Location: /index.php');
    exit;
}
$user_id = $_SESSION['user_id'];
function e($v){ return htmlspecialchars($v ?? '', ENT_QUOTES, 'UTF-8'); }
/* USER */
$stmt = $conn->prepare("SELECT * FROM users WHERE id=?");
$stmt->bind_param("i",$user_id);
$stmt->execute();
$user = $stmt->get_result()->fetch_assoc();
/* ROLE */
$roleMap = [1=>'Super Admin',2=>'Admin',3=>'MIS',4=>'Field User'];
$role_name = $roleMap[$user['rights_type_id']] ?? 'User';
/* FETCH */
function getList($conn,$q,$id){
    $stmt=$conn->prepare($q);
    $stmt->bind_param("i",$id);
    $stmt->execute();
    $res=$stmt->get_result();
    $arr=[];
    while($r=$res->fetch_assoc()) $arr[]=array_values($r)[0];
    return $arr;
}
$hospitals = getList($conn,"SELECT h.hospital_name FROM user_hospitals uh JOIN hospital_master h ON h.id=uh.hospital_id WHERE uh.user_id=?",$user_id);
$branches  = getList($conn,"SELECT b.branch_name FROM user_branches ub JOIN branch_master b ON b.id=ub.branch_id WHERE ub.user_id=?",$user_id);
$departments = getList($conn,"SELECT d.department_name FROM user_departments ud JOIN department_master d ON d.id=ud.department_id WHERE ud.user_id=?",$user_id);
$designations = getList($conn,"SELECT dm.designation_name FROM user_designations ud JOIN designation_master dm ON dm.designation_code = ud.designation_code WHERE ud.user_id=?",$user_id);
$qualifications = getList($conn," SELECT q.qualification_name FROM user_qualifications uq JOIN qualification_master q ON q.id = uq.qualification_id WHERE uq.user_id=? ",$user_id);
$employee_type = ''; if(!empty($user['employee_type_id'])){ $stmt = $conn->prepare("SELECT type_name FROM employee_type_master WHERE id=?"); $stmt->bind_param("i",$user['employee_type_id']);     $stmt->execute();     $res = $stmt->get_result();    if($row = $res->fetch_assoc()){         $employee_type = $row['type_name'];     } }
$sub_types = getList($conn," SELECT et.type_name FROM user_employee_sub_types ust JOIN employee_type_master et ON et.id = ust.sub_type_id WHERE ust.user_id=? ",$user_id);
$reporting_officer = ''; 
if(!empty($user['reporting_officer_id'])){
    $stmt = $conn->prepare("SELECT full_name FROM users WHERE id=?");
    $stmt->bind_param("i",$user['reporting_officer_id']);
    $stmt->execute();
    $res = $stmt->get_result();
    if($row = $res->fetch_assoc()){
        $reporting_officer = $row['full_name'];
    }
}
$dob = !empty($user['dob']) ? date('d M Y', strtotime($user['dob'])) : '-';
?>
<!DOCTYPE html>
<html>
<head>
<meta charset="UTF-8">
<link rel="icon" type="image/png" href="/assets/img/hms_logo.png">
<link rel="apple-touch-icon" href="/assets/img/hms_logo.png">
<title>Profile</title>
<link rel="stylesheet" href="/assets/css/style.css">
<link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.5.0/css/all.min.css">
<style>
/* SAME AS SETTINGS GRID */
/* PROFILE TOP CARD */
.profile-top{
    background:#fff;
    border-radius:12px;
    padding:8px 20px;
    display:flex;
    justify-content:space-between;
    align-items:center;
    gap:15px;
    box-shadow:0 4px 12px rgba(0,0,0,0.48);
    margin-bottom:20px;
}
.profile-left{
    display:flex;
    align-items:center;
    gap:15px;
}
.avatar{
    width:55px;
    height:55px;
    border-radius:50%;
    background:linear-gradient(135deg,#4dabf7,#845ef7);
    color:#fff;
    display:flex;
    align-items:center;
    justify-content:center;
    font-size:22px;
    font-weight:bold;
}
.name{font-size:18px;font-weight:600}
.role{font-size:13px;color:#666}
/* GRID SAME AS SETTINGS */
.profile-grid{
    display:grid;
    grid-template-columns:repeat(auto-fit,minmax(220px,1fr));
    gap:20px;
}
/* CARD SAME STYLE */
.profile-card{
    background:linear-gradient(145deg,#fff,#f5f9ff);
    border-radius:12px;
    padding:20px;
    text-align:center;
    box-shadow:0 4px 12px rgb(26 142 210 / 70%);
    transition:0.25s;
}
.profile-card:hover{
    transform:translateY(-5px);
    box-shadow:0 10px 25px rgba(0,0,0,0.15);
}
.icon{
    font-size:28px;
    margin-bottom:10px;
}
.title{
    font-size:14px;
    color:#666;
}
.value{
    font-size:16px;
    font-weight:600;
    margin-top:5px;
}

/* TAGS */
.tags{
    margin-top:10px;
}
.tag{
    display:inline-block;
    background:#eef2ff;
    padding:4px 8px;
    border-radius:20px;
    font-size:15px;
    margin:3px;
}
.top-bar {
    position: relative;
}

.top-bar h2 {
    position: absolute;
    left: 50%;
    transform: translateX(-50%);
    margin: 0;
    font-size: 20px;
}
.value {
    font-size: 16px;
    font-weight: 600;
    margin-top: 5px;

    /* FIX OVERFLOW */
    word-break: break-word;
    overflow-wrap: anywhere;
}
/* RIGHT */
.profile-actions{
    display:flex;
    align-items:center;
}

/* LOGOUT BUTTON */
.logout-btn{
    background:#ef4444;
    color:#fff;
    padding:8px 14px;
    border-radius:6px;
    text-decoration:none;
    font-size:13px;
    display:flex;
    align-items:center;
    gap:6px;
    transition:0.2s;
}
.logout-btn:hover{
    background:#dc2626;
}
/* CHANGE PASSWORD BUTTON */

.change-pass-btn{
    background:#2563eb;
    color:#fff;
    border:none;
    padding:8px 14px;
    border-radius:6px;
    font-size:13px;
    display:flex;
    align-items:center;
    gap:6px;
    cursor:pointer;
    transition:0.2s;
}

.change-pass-btn:hover{
    background:#1d4ed8;
}
</style>
</head>

<body>

<?php require $_SERVER['DOCUMENT_ROOT'].'/includes/header.php'; ?>
<div class="top-bar">
    <div class="breadcrumb">
        <a href="/dashboard/index.php">Home</a>
        <span>&gt;</span>
        <a href="/settings/settings.php">Settings</a>
        <span>&gt;</span>
        <a>Profile</a>
    </div>
    <h2>My Profile</h2>
</div>
<div class="container">

<div class="profile-top">

    <!-- LEFT SIDE -->
    <div class="profile-left">
        <div class="avatar">
            <?= strtoupper(substr($user['full_name'] ?? 'U',0,1)) ?>
        </div>

        <div class="profile-info">
            <div class="name"><?= e($user['full_name']) ?></div>

            <div class="role">
                <i class="fas fa-user-shield" style="margin-right:5px;"></i>
                <?= e($role_name) ?>

                <span style="margin:0 6px;">•</span>

                <i class="fas fa-circle" 
                   style="font-size:8px;color:<?= ($user['status'] ?? 'Active')=='Active' ? '#22c55e' : '#ef4444' ?>;margin-right:4px;">
                </i>
                <?= e($user['status']) ?>
            </div>
        </div>
    </div>

    <!-- RIGHT SIDE -->
    <div class="profile-actions" style="display:flex;gap:10px;">
    <!-- CHANGE PASSWORD -->
    <button class="change-pass-btn"
            onclick="openPasswordModal()">
        <i class="fas fa-key"></i>
        Change Password
    </button>
    <!-- LOGOUT -->
    <a href="/logout.php" class="logout-btn">
        <i class="fas fa-sign-out-alt"></i>
        Logout
    </a>
</div>
</div>

<!-- GRID -->
<div class="profile-grid">

<div class="profile-card">
    <div class="icon" style="color:#4dabf7"><i class="fas fa-user"></i></div>
    <div class="title">Username</div>
    <div class="value"><?= e($user['username']) ?></div>
</div>
<!-- Email  -->
<div class="profile-card">
    <div class="icon" style="color:#20c997">
        <i class="fas fa-envelope"></i>
    </div>
    <div class="title">Email</div>

    <div class="value">
        <?php if(!empty($user['email'])): ?>
            <a href="mailto:<?= e($user['email']) ?>" style="color:inherit;text-decoration:none;">
                <?= e($user['email']) ?>
            </a>
        <?php else: ?>
            -
        <?php endif; ?>
    </div>
</div>
<!-- Mobile  -->
<div class="profile-card">
    <div class="icon" style="color:#845ef7">
        <i class="fas fa-phone"></i>
    </div>
    <div class="title">Mobile</div>

    <div class="value">
        <?php if(!empty($user['mobile_no'])): 
            $mobile = preg_replace('/[^0-9]/','',$user['mobile_no']);
        ?>
            <a href="tel:<?= e($mobile) ?>" style="color:inherit;text-decoration:none;">
                <?= e($user['mobile_no']) ?>
            </a>
        <?php else: ?>
            -
        <?php endif; ?>
    </div>
</div>
<!-- WhatsApp  -->
<div class="profile-card">
    <div class="icon" style="color:#25D366">
        <i class="fab fa-whatsapp"></i>
    </div>
    <div class="title">WhatsApp</div>

    <div class="value">
        <?php if(!empty($user['mobile_no'])): 
            $mobile = preg_replace('/[^0-9]/','',$user['mobile_no']);
            $mobile = '91' . ltrim($mobile, '0'); // India format
        ?>
            <a href="https://wa.me/<?= e($mobile) ?>" target="_blank"
               style="color:inherit;text-decoration:none;">
               <?= e($user['mobile_no']) ?>
            </a>
        <?php else: ?>
            -
        <?php endif; ?>
    </div>
</div>
<!-- Gender  -->
<div class="profile-card">
    <div class="icon" style="color:#f59f00"><i class="fas fa-venus-mars"></i></div>
    <div class="title">Gender</div>
    <div class="value"><?= e($user['gender']) ?></div>
</div>
<!-- DOB -->
<div class="profile-card">
    <div class="icon" style="color:#e64980">
        <i class="fas fa-calendar"></i>
    </div>
    <div class="title">DOB</div>
    <div class="value">
        <?php 
        if(!empty($user['dob'])):
            $dob_date = new DateTime($user['dob']);
            $today = new DateTime();
            // Age
            $age = $today->diff($dob_date)->y;
            // Next Birthday
            $next_birthday = new DateTime($today->format('Y') . '-' . $dob_date->format('m-d'));
            if($next_birthday < $today){
                $next_birthday->modify('+1 year');
            }
            $days_left = $today->diff($next_birthday)->days;
        ?>
            <div><?= $dob ?></div>
            <div style="font-size:12px;color:#666;">
                <?= $age ?> Years Old
            </div>
            <div style="font-size:12px;color:#28a745;">
                🎂 <?= $days_left ?> days left
            </div>
        <?php else: ?>
            -
        <?php endif; ?>
    </div>
</div>
<!-- Marital Status -->
<div class="profile-card">
    <div class="icon" style="color:#ff922b"><i class="fas fa-heart"></i></div>
    <div class="title">Marital Status</div>
    <div class="value"><?= !empty($user['marital_status']) ? e($user['marital_status']) : '-' ?></div>
</div>

<!-- Aadhar Card -->
<div class="profile-card">
    <div class="icon" style="color:#0ca678">
        <i class="fas fa-id-card"></i>
    </div>
    <div class="title">Aadhar Card</div>

    <?php 
    $aadhar = $user['aadhar_no'] ?? '';
    $masked = $aadhar ? 'XXXX-XXXX-'.substr($aadhar,-4) : '-';
    ?>

    <div class="value" style="display:flex;align-items:center;justify-content:center;gap:8px;">
        <span id="aadharText"><?= $masked ?></span>

        <?php if($aadhar): ?>
        <i class="fas fa-eye" 
           id="toggleEye" 
           style="cursor:pointer;color:#555;"
           onclick="toggleAadhar('<?= e($aadhar) ?>')">
        </i>
        <?php endif; ?>
    </div>
</div>


<!-- HOSPITAL -->

<div class="profile-card">
    <div class="icon" style="color:#ff6b6b"><i class="fas fa-hospital"></i></div>
    <div class="title">Hospitals</div>
    <div class="tags">
        <?php foreach($hospitals as $v) echo "<span class='tag'>".e($v)."</span>"; ?>
    </div>
</div>

<!-- BRANCH -->
<div class="profile-card">
    <div class="icon" style="color:#20c997"><i class="fas fa-code-branch"></i></div>
    <div class="title">Branches</div>
    <div class="tags">
        <?php foreach($branches as $v) echo "<span class='tag'>".e($v)."</span>"; ?>
    </div>
</div>


<!-- DEPT -->

<div class="profile-card">
    <div class="icon" style="color:#845ef7"><i class="fas fa-sitemap"></i></div>
    <div class="title">Departments</div>
    <div class="tags">
        <?php foreach($departments as $v) echo "<span class='tag'>".e($v)."</span>"; ?>
    </div>
</div>
<!-- DESIGNATION -->

<div class="profile-card">
    <div class="icon" style="color:#f59f00">
        <i class="fas fa-id-badge"></i>
    </div>
    <div class="title">Designations</div>

    <div class="tags">
        <?php foreach($designations as $v): ?>
            <span class="tag"><?= e($v) ?></span>
        <?php endforeach; ?>
    </div>
</div>
<!-- Qualifications -->

<div class="profile-card">
    <div class="icon" style="color:#228be6">
        <i class="fas fa-graduation-cap"></i>
    </div>
    <div class="title">Qualifications</div>

    <div class="tags">
        <?php foreach($qualifications as $v): ?>
            <span class="tag"><?= e($v) ?></span>
        <?php endforeach; ?>
    </div>
</div>

<!-- Employee Type -->

<div class="profile-card">
    <div class="icon" style="color:#40c057">
        <i class="fas fa-users"></i>
    </div>
    <div class="title">Employee Type</div>

    <div class="value"><?= e($employee_type) ?></div>
</div>


<!-- Employee Sub Type -->

<div class="profile-card">
    <div class="icon" style="color:#12b886">
        <i class="fas fa-layer-group"></i>
    </div>
    <div class="title">Employee Sub Types</div>

    <div class="tags">
        <?php foreach($sub_types as $v): ?>
            <span class="tag"><?= e($v) ?></span>
        <?php endforeach; ?>
    </div>
</div>

<!-- REPORTING OFFICER -->
<div class="profile-card">
    <div class="icon" style="color:#fa5252">
        <i class="fas fa-user-tie"></i>
    </div>
    <div class="title">Reporting Officer</div>

    <div class="value"><?= e($reporting_officer) ?></div>
</div>
</div>
</div>
<script>
let isVisible = false;

function toggleAadhar(fullValue) {
    const text = document.getElementById('aadharText');
    const icon = document.getElementById('toggleEye');

    if (!isVisible) {
        text.innerText = fullValue;
        icon.classList.remove('fa-eye');
        icon.classList.add('fa-eye-slash');
    } else {
        text.innerText = 'XXXX-XXXX-' + fullValue.slice(-4);
        icon.classList.remove('fa-eye-slash');
        icon.classList.add('fa-eye');
    }

    isVisible = !isVisible;
}
</script>
<!-- CHANGE PASSWORD MODAL -->
<div class="modal" id="passwordModal">
    <div class="modal-box">
        <h3>
            Change Password
        </h3>
        <form method="POST" action="change_password.php">
            <label>Current Password *</label>
            <input type="password"
                   name="current_password"
                   required>
            <label>New Password *</label>
            <input type="password"
                   name="new_password"
                   required>
            <label>Confirm Password *</label>
            <input type="password"
                   name="confirm_password"
                   required>
            <div class="actions">
                <button class="btn btn-success">
                    Update Password
                </button>
                <button type="button"
                        class="btn btn-danger"
                        onclick="closePasswordModal()">
                    Cancel
                </button>
            </div>
        </form>
    </div>
</div>
<script>
function openPasswordModal(){
    document.getElementById('passwordModal').style.display='block';
}
function closePasswordModal(){
    document.getElementById('passwordModal').style.display='none';
}
window.onclick = function(event){
    let modal = document.getElementById('passwordModal');
    if(event.target == modal){
        closePasswordModal();
    }
};
</script>
</body>
</html>