<?php
session_start();
require $_SERVER['DOCUMENT_ROOT'].'/includes/db.php';
require $_SERVER['DOCUMENT_ROOT'].'/includes/permissions.php';

/* SAVE ANNOUNCEMENT */
if ($_SERVER['REQUEST_METHOD'] == 'POST') {

    $title = $_POST['title'];
    $desc  = $_POST['description'];
    $url   = $_POST['url'];
    $start = !empty($_POST['start_date']) ? $_POST['start_date'] : date('Y-m-d H:i:s');
    $end   = !empty($_POST['end_date']) ? $_POST['end_date'] : '2099-12-31 23:59:59';
    $is_global = $_POST['is_global'];
    $active    = $_POST['is_active'];

    $file_path = '';

    if (!empty($_FILES['file']['name'])) {
        $dir = $_SERVER['DOCUMENT_ROOT'].'/uploads/announcements/';
        if (!is_dir($dir)) mkdir($dir,0777,true);

        $allowed = ['jpg','jpeg','png','pdf','doc','docx'];
        $ext = strtolower(pathinfo($_FILES['file']['name'], PATHINFO_EXTENSION));
        
        if (in_array($ext, $allowed) && $_FILES['file']['size'] < 5000000) {
            $file_name = time().'_'.basename($_FILES['file']['name']);
            $target = $dir.$file_name;

            if (move_uploaded_file($_FILES['file']['tmp_name'], $target)) {
                $file_path = '/uploads/announcements/'.$file_name;
            }
        }
    }

    $stmt = $conn->prepare("
        INSERT INTO announcements 
        (title,description,url,file_path,start_date,end_date,is_active,is_global,created_by)
        VALUES (?,?,?,?,?,?,?,?,?)
    ");

    $stmt->bind_param("sssssssii",
        $title,$desc,$url,$file_path,$start,$end,$active,$is_global,$_SESSION['user_id']
    );
    $stmt->execute();

    $announcement_id = $stmt->insert_id;

    if (!$is_global && !empty($_POST['user_ids'])) {
        $stmt2 = $conn->prepare("INSERT INTO announcement_users (announcement_id,user_id) VALUES (?,?)");

        foreach($_POST['user_ids'] as $uid){
            $stmt2->bind_param("ii",$announcement_id,$uid);
            $stmt2->execute();
        }
    }

    header("Location: announcement_list.php");
    exit;
}

/* FETCH LIST */
$res = $conn->query("SELECT * FROM announcements ORDER BY id DESC");

// Store all users for JavaScript
$allUsers = [];
$allUsersQuery = $conn->query("SELECT id, full_name FROM users WHERE status='Active' ORDER BY full_name");
while($user = $allUsersQuery->fetch_assoc()) {
    $allUsers[] = $user;
}
?>

<!DOCTYPE html>
<html>
<head>
<meta charset="UTF-8">
<title>Announcements</title>
<link rel="icon" type="image/png" href="/assets/img/hms_logo.png">
<link rel="apple-touch-icon" href="/assets/img/hms_logo.png">
<link rel="stylesheet" href="/assets/css/style.css">
<link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.5.0/css/all.min.css">
<style>
* {
    margin: 0;
    padding: 0;
    box-sizing: border-box;
}

body {
    background: #f4f6f9;
    font-family: Arial, sans-serif;
}

/* Header Bar */
.table-responsive {
    overflow-x: auto;
}

table {
    width: 100%;
    border-collapse: collapse;
    background: #fff;
}

th, td {
    border: 1px solid #e5e7eb;
    padding: 8px 10px;
    font-size: 13px;
    vertical-align: top;
}

th {
    background: #f8fafc;
    font-weight: 600;
    text-align: left;
}
tr:nth-child(even) {
    background: #f9fbfd;
}
.btn-add {
    background: #28a745;
    color: white;
    border: none;
    padding: 10px 20px;
    border-radius: 5px;
    cursor: pointer;
    font-size: 14px;
}

.btn-add:hover {
    background: #218838;
}
/* Badge */
.badge {
    padding: 4px 8px;
    font-size: 12px;
    border-radius: 4px;
    color: #fff;
}

.badge-success { background: #28a745; }
.badge-danger { background: #dc3545; }

/* Action Buttons */
.action-buttons {
    display: flex;
    gap: 6px;
    justify-content: center;
}
/* Action Buttons */
.action-btn {
    padding: 5px 10px;
    margin: 0 3px;
    border: none;
    border-radius: 4px;
    cursor: pointer;
}
.btn-edit {
    background: #007bff;
    color: white;
}

.btn-icon {
    border: none;
    width: 32px;
    height: 32px;
    border-radius: 6px;
    display: flex;
    align-items: center;
    justify-content: center;
    cursor: pointer;
    font-size: 14px;
    transition: 0.2s;
}

.btn-delete {
    background: #fdecea;
    color: #c62828;
}
.btn-delete:hover {
    background: #c62828;
    color: #fff;
}

/* Modal */
.modal {
    display: none;
    position: fixed;
    z-index: 9999;
    left: 0;
    top: 0;
    width: 100%;
    height: 100%;
    background-color: rgba(0,0,0,0.5);
}

.modal-content {
    background-color: white;
    margin: 5% auto;
    width: 90%;
    max-width: 600px;
    border-radius: 8px;
    animation: slideDown 0.3s;
}

@keyframes slideDown {
    from {
        transform: translateY(-50px);
        opacity: 0;
    }
    to {
        transform: translateY(0);
        opacity: 1;
    }
}

.modal-header {
    padding: 15px 20px;
    border-bottom: 1px solid #ddd;
    display: flex;
    justify-content: space-between;
    align-items: center;
}

.modal-header h3 {
    font-size: 18px;
}

.close {
    cursor: pointer;
    font-size: 24px;
    color: #999;
}

.close:hover {
    color: #333;
}

.modal-body {
    padding: 20px;
    max-height: 500px;
    overflow-y: auto;
}

.modal-footer {
    padding: 15px 20px;
    border-top: 1px solid #ddd;
    display: flex;
    justify-content: flex-end;
    gap: 10px;
}

/* Form */
.form-group {
    margin-bottom: 15px;
}

.form-group label {
    display: block;
    margin-bottom: 5px;
    font-weight: bold;
    font-size: 13px;
}

.form-group input,
.form-group textarea,
.form-group select {
    width: 100%;
    padding: 8px;
    border: 1px solid #ddd;
    border-radius: 4px;
    font-size: 14px;
}

.form-row {
    display: grid;
    grid-template-columns: 1fr 1fr;
    gap: 15px;
}

.btn-primary {
    background: #007bff;
    color: white;
}

.btn-secondary {
    background: #6c757d;
    color: white;
}

/* User Selection */
#userSelectionBlock {
    margin-top: 15px;
    padding-top: 15px;
    border-top: 1px solid #ddd;
}

#userSelect {
    height: 120px;
}

small {
    display: block;
    margin-top: 5px;
    font-size: 11px;
    color: #666;
}
/* Description clamp */
.desc-cell {
    max-width: 280px;
    line-height: 1.4;
    color: #444;
position: relative;
    
    -webkit-line-clamp: 2;   /* show 2 lines */
    -webkit-box-orient: vertical;
    overflow: hidden;

    word-break: break-word;
}
.desc-cell::after {
    content: "";
    position: absolute;
    bottom: 0;
    right: 0;
    width: 40%;
    height: 100%;
    background: linear-gradient(to right, transparent, #fff 80%);
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
    <a>Announcements</a>
    </div>
        <h2 style="margin:0 auto;">Announcements</h2>
    <button class="btn btn-add" id="openModalBtn">
        <i class="fas fa-plus"></i> Add Announcement
    </button>
</div>

<div class="container">
    <div class="table-responsive">
        <table>
        <thead>
            <tr>
                <th>ID</th>
                <th>Title</th>
                <th>Description</th>
                <th>URL</th>
                <th>Attachment</th>
                <th>Schedule From</th>
                <th>Schedule To</th>
                <th>Status</th>
                <th>Actions</th>
            </tr>
        </thead>
        <tbody>
            <?php if($res->num_rows == 0): ?>
            <tr>
                <td colspan="8" style="text-align:center; padding:40px;">
                    <i class="fas fa-bullhorn" style="font-size:48px; color:#ccc;"></i>
                    <p style="margin-top:10px;">No announcements found</p>
                </td>
            </tr>
            <?php else: 
                $i = 1;
                while($row = $res->fetch_assoc()): 
            ?>
            <tr>
                <td><?= $i++ ?></td>
                <td><strong><?= htmlspecialchars($row['title']) ?></strong></td>
                <td class="desc-cell" title="<?= htmlspecialchars($row['description']) ?>">
    <?= nl2br(htmlspecialchars($row['description'])) ?>
</td>
                <td>
        <?= $row['url'] ? '<a href="'.$row['url'].'" target="_blank">View</a>' : '-' ?>
    </td>
                <td>
        <?= $row['file_path'] ? '<a href="'.$row['file_path'].'" target="_blank">Download</a>' : '-' ?>
    </td>

    <td>
    <?= date('d M Y h:i A', strtotime($row['start_date'])) ?>
</td>

<td>
    <?= date('d M Y h:i A', strtotime($row['end_date'])) ?>
</td>

    <td>
        <span class="badge <?= $row['is_active'] ? 'badge-success' : 'badge-danger' ?>">
            <?= $row['is_active'] ? 'Active' : 'Inactive' ?>
        </span>
    </td>

    <td class="action-buttons">
        <button class="btn-icon btn-delete"
            onclick="deleteAnnouncement(<?= $row['id'] ?>)">
            <i class="fa-solid fa-trash"></i>
        </button>
    </td>
</tr>
            <?php endwhile; endif; ?>
        </tbody>
    </table>
</div>

<!-- Modal -->
<div id="announcementModal" class="modal">
    <div class="modal-content">
        <div class="modal-header">
            <h3>Add Announcement</h3>
            <span class="close">&times;</span>
        </div>
        <form method="POST" enctype="multipart/form-data">
            <div class="modal-body">
                <div class="form-group">
                    <label>Title *</label>
                    <input type="text" name="title" required>
                </div>
                
                <div class="form-group">
                    <label>Description</label>
                    <textarea name="description" rows="3"></textarea>
                </div>
                
                <div class="form-row">
                    <div class="form-group">
                        <label>URL</label>
                        <input type="url" name="url">
                    </div>
                    <div class="form-group">
                        <label>Attachment</label>
                        <input type="file" name="file">
                    </div>
                </div>
                
                <div class="form-row">
                    <div class="form-group">
                        <label>Start Date</label>
                        <input type="datetime-local" name="start_date">
                    </div>
                    <div class="form-group">
                        <label>End Date</label>
                        <input type="datetime-local" name="end_date">
                    </div>
                </div>
                
                <div class="form-row">
                    <div class="form-group">
                        <label>Status</label>
                        <select name="is_active">
                            <option value="1">Active</option>
                            <option value="0">Inactive</option>
                        </select>
                    </div>
                    <div class="form-group">
                        <label>User Scope</label>
                        <select name="is_global" id="globalDropdown">
                            <option value="1">All Users</option>
                            <option value="0">Selected Users</option>
                        </select>
                    </div>
                </div>
                
                <div id="userSelectionBlock" style="display:none;">
                    <div class="form-group">
                        <label>Department</label>
                        <select id="deptFilter">
                            <option value="">All Departments</option>
                            <?php
                            $depts = $conn->query("SELECT id, department_name FROM department_master WHERE status=1");
                            while($dept = $depts->fetch_assoc()):
                            ?>
                            <option value="<?= $dept['id'] ?>"><?= htmlspecialchars($dept['department_name']) ?></option>
                            <?php endwhile; ?>
                        </select>
                    </div>
                    
                    <div class="form-group">
                        <label>Select Users</label>
                        <select name="user_ids[]" id="userSelect" multiple size="5">
                            <?php foreach($allUsers as $user): ?>
                            <option value="<?= $user['id'] ?>"><?= htmlspecialchars($user['full_name']) ?></option>
                            <?php endforeach; ?>
                        </select>
                        <small>Hold Ctrl to select multiple users</small>
                    </div>
                </div>
            </div>
            <div class="modal-footer">
                <button type="button" class="btn btn-secondary" id="cancelBtn">Cancel</button>
                <button type="submit" class="btn btn-primary">Save</button>
            </div>
        </form>
    </div>
</div>
<div id="deleteConfirm" class="confirm-modal">
  <div class="confirm-box">
    <p>Are you sure you want to delete this announcement?</p>
    <small>This action cannot be undone.</small>

    <div class="confirm-actions">
      <button class="btn-cancel" onclick="closeDelete()">Cancel</button>
      <button class="btn-confirm" id="confirmDeleteBtn">Delete</button>
    </div>
  </div>
</div>
<script>
// Store all users from PHP
const allUsers = <?php echo json_encode($allUsers); ?>;
console.log('All users loaded:', allUsers.length);

// Get elements
const modal = document.getElementById('announcementModal');
const openBtn = document.getElementById('openModalBtn');
const closeBtn = document.querySelector('.close');
const cancelBtn = document.getElementById('cancelBtn');
const globalDropdown = document.getElementById('globalDropdown');
const userBlock = document.getElementById('userSelectionBlock');
const deptFilter = document.getElementById('deptFilter');
const userSelect = document.getElementById('userSelect');

// Function to load users based on department
function loadUsersByDepartment(deptId) {
    if (!deptId) {
        // Show all users
        if (userSelect) {
            userSelect.innerHTML = '';
            allUsers.forEach(user => {
                const option = document.createElement('option');
                option.value = user.id;
                option.text = user.full_name;
                userSelect.appendChild(option);
            });
        }
        return;
    }
    
    // Fetch users by department
    fetch('load_users.php?dept=' + encodeURIComponent(deptId))
        .then(response => response.json())
        .then(data => {
            if (userSelect) {
                userSelect.innerHTML = '';
                if (data.length === 0) {
                    const option = document.createElement('option');
                    option.text = 'No users found';
                    option.disabled = true;
                    userSelect.appendChild(option);
                } else {
                    data.forEach(user => {
                        const option = document.createElement('option');
                        option.value = user.id;
                        option.text = user.full_name;
                        userSelect.appendChild(option);
                    });
                }
            }
        })
        .catch(error => console.error('Error loading users:', error));
}

// Open modal function
function openModal() {
    console.log('Opening modal');
    if (modal) {
        modal.style.display = 'block';
        document.body.style.overflow = 'hidden';
        // Reset department filter to "All" and load all users
        if (deptFilter) {
            deptFilter.value = '';
            loadUsersByDepartment('');
        }
    }
}

// Close modal function
function closeModal() {
    console.log('Closing modal');
    if (modal) {
        modal.style.display = 'none';
        document.body.style.overflow = 'auto';
    }
}

// Delete function
let deleteId = null;

function deleteAnnouncement(id) {
    deleteId = id;
    document.getElementById("deleteConfirm").style.display = "flex";
}

function closeDelete() {
    document.getElementById("deleteConfirm").style.display = "none";
    deleteId = null;
}

document.addEventListener("DOMContentLoaded", function () {
    document.getElementById("confirmDeleteBtn").onclick = function () {
        if (!deleteId) return;

        window.location.href = '/settings/announcements/delete_announcement.php?id=' + deleteId;
    };
});

// Edit function
function editAnnouncement(id) {
    alert('Edit feature coming soon for ID: ' + id);
}

// Toggle user selection
if (globalDropdown) {
    globalDropdown.addEventListener('change', function() {
        if (userBlock) {
            userBlock.style.display = this.value === '0' ? 'block' : 'none';
            // When showing user block, load all users initially
            if (this.value === '0' && deptFilter && userSelect) {
                deptFilter.value = '';
                loadUsersByDepartment('');
            }
        }
    });
}

// Department filter change
if (deptFilter) {
    deptFilter.addEventListener('change', function() {
        const deptId = this.value;
        console.log('Department changed to:', deptId || 'All');
        loadUsersByDepartment(deptId);
    });
}

// Open button click
if (openBtn) {
    openBtn.addEventListener('click', openModal);
    console.log('Open button attached');
} else {
    console.error('Open button not found!');
}

// Close buttons
if (closeBtn) {
    closeBtn.addEventListener('click', closeModal);
}
if (cancelBtn) {
    cancelBtn.addEventListener('click', closeModal);
}

// Click outside to close
window.addEventListener('click', function(event) {
    if (event.target === modal) {
        closeModal();
    }
});

console.log('All event handlers initialized');
</script>

</body>
</html>