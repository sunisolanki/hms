<?php
session_start();
require $_SERVER['DOCUMENT_ROOT'].'/includes/db.php';
require $_SERVER['DOCUMENT_ROOT'].'/includes/permissions.php';
if (!checkPermission('rights', 'view')) {
    header('Location: /access_denied.php');
    exit;
}
/* FETCH RIGHTS TYPES */
$roles = $conn->query("
    SELECT
        rt.id,
        rt.name,
        rt.created_at,
        rt.updated_at,
        u.full_name AS created_by_name
    FROM rights_types rt
    LEFT JOIN users u ON u.id = rt.created_by
    ORDER BY rt.id DESC
");
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<title>Rights Management</title>
<link rel="stylesheet" href="/assets/css/style.css">
<link rel="icon" href="/assets/img/hms_logo.png" sizes="32x32" type="image/png">
<link rel="icon" href="/assets/img/hms_logo.png" sizes="192x192" type="image/png">
<link rel="apple-touch-icon" href="/assets/img/hms_logo.png">
<link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.5.0/css/all.min.css">
<style>
.rights-list-card{
    width:100%;
    background:#fff;
    border-radius:12px;
    overflow:auto;
    box-shadow:0 4px 20px rgba(0,0,0,.05);
}
.rights-list-card table{
    width:100%;
    border-collapse:collapse;
}
.rights-list-card th,
.rights-list-card td{
    padding:10px 12px;
    border-bottom:1px solid #e5e7eb;
}
.rights-list-card th{
    background:#f4f7fb;
    color:#334155;
    font-weight:700;
    text-align:left;
    white-space:nowrap;
}
.rights-list-card td{
    text-align:left;
}
.right-name-link{
    color:#1976d2;
    font-weight:700;
    text-decoration:none;
}
.right-name-link:hover{
    text-decoration:underline;
}
.rights-actions{
    display:flex;
    align-items:center;
    justify-content:center;
    gap:8px;
}
.rights-actions .btn{
    display:inline-flex;
    align-items:center;
    justify-content:center;
    gap:6px;
}
.modal{
    display:none;
    position:fixed;
    z-index:9999;
    left:0;
    top:0;
    width:100%;
    height:100%;
    background:rgba(0,0,0,.5);
    justify-content:center;
    align-items:center;
}
.modal-content{
    background:#fff;
    width:100%;
    max-width:450px;
    border-radius:16px;
    padding:25px;
    animation:fadeIn .25s ease;
    box-shadow:0 10px 40px rgba(0,0,0,.2);
}
.modal-header{
    display:flex;
    justify-content:space-between;
    align-items:center;
    margin-bottom:20px;
}
.modal-header h3{
    margin:0;
    color:#1976d2;
}
.form-group{
    margin-bottom:20px;
}
.form-group label{
    display:block;
    margin-bottom:8px;
    font-weight:600;
}
.form-group input{
    width:100%;
    padding:12px;
    border:1px solid #ddd;
    border-radius:10px;
    font-size:14px;
    box-sizing:border-box;
}
.close-btn{
    cursor:pointer;
    font-size:24px;
    color:#64748b;
}
.close-btn:hover{
    color:#dc2626;
}
@keyframes fadeIn{
    from{
        opacity:0;
        transform:translateY(-10px);
    }
    to{
        opacity:1;
        transform:translateY(0);
    }
}
@media(max-width:900px){
    .rights-list-card{
        overflow:auto;
    }
    .rights-list-card table{
        min-width:800px;
    }
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
        <a href="/settings/users/users.php">User Management</a>
        <span>&gt;</span>
        <a>Rights Management</a>
    </div>
    <h2>Rights Management</h2>
    <div>
        <?php if (checkPermission('rights', 'create')): ?>
            <button class="btn" onclick="openModal()">
                <i class="fas fa-plus"></i>
                Add Rights
            </button>
        <?php endif; ?>
    </div>
</div>
<div class="rights-list-card">
    <table id="rightsListTable">
        <thead>
            <tr>
                <th>Right Name</th>
                <th>Created Date</th>
                <th>Created By</th>
                <th>Updated At</th>
                <th>Action</th>
            </tr>
        </thead>
        <tbody>
        <?php while($right = $roles->fetch_assoc()): ?>
            <tr>
                <td>
                    <a href="show_rights.php?role=<?= (int)$right['id'] ?>"
                       class="right-name-link">
                        <?= htmlspecialchars($right['name']) ?>
                    </a>
                </td>
                <td>
                    <?= !empty($right['created_at'])
                        ? date('d M Y H:i', strtotime($right['created_at']))
                        : '-' ?>
                </td>
                <td>
                    <?= htmlspecialchars($right['created_by_name'] ?? '-') ?>
                </td>
                <td>
                    <?= !empty($right['updated_at'])
                        ? date('d M Y H:i', strtotime($right['updated_at']))
                        : '-' ?>
                </td>
                <td>
                    <div class="rights-actions">
                        <?php if (checkPermission('rights', 'update')): ?>
                            <button type="button"
                                    class="btn btn-sm"
                                    onclick="openEditModal(
                                        '<?= (int)$right['id'] ?>',
                                        '<?= htmlspecialchars($right['name'], ENT_QUOTES) ?>'
                                    )">
                                <i class="fas fa-edit"></i>
                                Rename
                            </button>
                        <?php endif; ?>
                        <?php if (checkPermission('rights', 'delete')): ?>
                            <a href="delete_rights.php?id=<?= (int)$right['id'] ?>"
                               class="btn btn-sm btn-danger"
                               onclick="return confirm('Delete this rights type?')">
                                <i class="fas fa-trash"></i>
                                Delete
                            </a>
                        <?php endif; ?>
                    </div>
                </td>
            </tr>
        <?php endwhile; ?>
        </tbody>
    </table>
</div>
<!-- CREATE RIGHTS TYPE MODAL -->
<div class="modal" id="rightsModal">
    <div class="modal-content">
        <div class="modal-header">
            <h3>Add Rights</h3>
            <span class="close-btn" onclick="closeModal()">&times;</span>
        </div>
        <form method="post" action="/settings/rights/store_rights_type.php">
            <div class="form-group">
                <label>Rights Type Name</label>
                <input type="text"
                       name="name"
                       placeholder="e.g. Admin"
                       required>
            </div>
            <button type="submit" class="btn">
                <i class="fas fa-plus"></i>
                Create Rights
            </button>
        </form>
    </div>
</div>
<!-- EDIT RIGHTS MODAL -->
<div class="modal" id="editRightsModal">
    <div class="modal-content">
        <div class="modal-header">
            <h3>Rename Rights</h3>
            <span class="close-btn" onclick="closeEditModal()">&times;</span>
        </div>
        <form method="post" action="update_rights.php">
            <input type="hidden"
                   name="id"
                   id="edit_right_id">
            <div class="form-group">
                <label>Rights Type Name</label>
                <input type="text"
                       name="name"
                       id="edit_right_name"
                       required>
            </div>
            <button type="submit" class="btn">
                <i class="fas fa-save"></i>
                Update Rights
            </button>
        </form>
    </div>
</div>
<script>
function openModal(){
    document.getElementById('rightsModal').style.display='flex';
}
function closeModal(){
    document.getElementById('rightsModal').style.display='none';
}
function openEditModal(id,name){
    document.getElementById('edit_right_id').value=id;
    document.getElementById('edit_right_name').value=name;
    document.getElementById('editRightsModal').style.display='flex';
}
function closeEditModal(){
    document.getElementById('editRightsModal').style.display='none';
}
window.addEventListener('click',function(e){
    const rightsModal=document.getElementById('rightsModal');
    const editRightsModal=document.getElementById('editRightsModal');
    if(e.target===rightsModal){
        rightsModal.style.display='none';
    }
    if(e.target===editRightsModal){
        editRightsModal.style.display='none';
    }
});
</script>
</body>
</html>