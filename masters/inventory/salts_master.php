<?php
ini_set('display_errors',1);
error_reporting(E_ALL);

session_start();

require $_SERVER['DOCUMENT_ROOT'].'/includes/db.php';
require $_SERVER['DOCUMENT_ROOT'].'/includes/permissions.php';

if (!isset($_SESSION['user_id'])) {
    header('Location:/index.php');
    exit;
}

if (!checkPermission('masters','view')) {
    header('Location:/access_denied.php');
    exit;
}

/* ADD SALT */
if(isset($_POST['add_salt'])){

    $salt_name = trim($_POST['salt_name']);

    if($salt_name != ''){

        $check = $conn->prepare("SELECT id FROM salts_master WHERE salt_name=?");
        $check->bind_param("s",$salt_name);
        $check->execute();
        $check->store_result();

        if($check->num_rows == 0){

            $stmt = $conn->prepare("INSERT INTO salts_master (salt_name) VALUES (?)");
            $stmt->bind_param("s",$salt_name);
            $stmt->execute();
        }
    }

    header("Location: salts_master.php");
    exit;
}

/* UPDATE */
if(isset($_POST['update_salt'])){

    $id = (int)$_POST['id'];
    $salt_name = trim($_POST['salt_name']);

    if($salt_name != ''){

        $stmt = $conn->prepare("UPDATE salts_master SET salt_name=? WHERE id=?");
        $stmt->bind_param("si",$salt_name,$id);
        $stmt->execute();
    }

    echo "success";
    exit;
}

/* DELETE */
if(isset($_GET['delete_id'])){

    if (!checkPermission('masters','delete')) {
        header('Location:/access_denied.php');
        exit;
    }

    $id = (int)$_GET['delete_id'];

    $stmt = $conn->prepare("DELETE FROM salts_master WHERE id=?");
    $stmt->bind_param("i",$id);
    $stmt->execute();

    header("Location: salts_master.php");
    exit;
}

/* FETCH */
$salts = $conn->query("
SELECT *
FROM salts_master
ORDER BY salt_name ASC
");
?>

<?php require $_SERVER['DOCUMENT_ROOT'].'/includes/header.php'; ?>

<!DOCTYPE html>
<html>
<head>

<title>Salts Master</title>

<link rel="icon" type="image/png" href="/assets/img/hms_logo.png">

<link rel="stylesheet" href="/assets/css/style.css">

<link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.5.0/css/all.min.css">

<style>

.quick-add-box{
    background:#fff;
    padding:15px;
    border-radius:10px;
    margin-bottom:20px;
    box-shadow:0 2px 8px rgba(0,0,0,0.08);
}

.quick-add-input{
    width:100%;
    padding:12px;
    border:1px solid #ccc;
    border-radius:6px;
    font-size:15px;
}

.inline-input{
    width:100%;
    border:1px solid #ddd;
    padding:6px 8px;
    border-radius:5px;
}

.editing{
    background:#fff3cd;
}

.salt-count{
    font-size:14px;
    color:#666;
    margin-top:5px;
}

</style>

</head>

<body>

<div class="top-bar">

    <div class="breadcrumb">
        <a href="/dashboard/index.php">Home</a>
        <span>&gt;</span>

        <a href="/settings/settings.php">Settings</a>
        <span>&gt;</span>

        <a href="/masters/masters.php">Masters</a>
        <span>&gt;</span>

        <a href="/masters/inventory/inventory_master.php">Inventory</a>
        <span>&gt;</span>

        <a>Salts Master</a>
    </div>

    <h2>Salts Master</h2>

</div>

<div class="quick-add-box">

    <form method="POST" id="quickAddForm">

        <input type="hidden" name="add_salt" value="1">

        <input type="text"
               name="salt_name"
               id="saltInput"
               class="quick-add-input"
               placeholder="Type salt name and press ENTER..."
               autocomplete="off"
               required>

    </form>
<div style="margin-top:15px; position:relative;">

    <i class="fa-solid fa-search"
       style="position:absolute;
              left:12px;
              top:14px;
              color:#999;">
    </i>

    <input type="text"
           id="searchSalt"
           class="quick-add-input"
           placeholder="Search Salt..."
           style="padding-left:38px;">

</div>
    <div class="salt-count">
        Total Salts :
        <?= $salts->num_rows ?>
    </div>

</div>
<div class="table-responsive">

<table class="table">

    <thead>
    <tr>
        <th width="8%">#</th>
        <th>Salt Name</th>
        <th width="15%">Created</th>
        <th width="12%">Action</th>
    </tr>
    </thead>

    <tbody>

    <?php
    $i=1;
    while($row = $salts->fetch_assoc()){
    ?>

    <tr id="row<?= $row['id'] ?>" class="salt-row">

        <td><?= $i++ ?></td>

        <td>

            <span id="text<?= $row['id'] ?>">
                <?= htmlspecialchars($row['salt_name']) ?>
            </span>

            <input type="text"
                   id="input<?= $row['id'] ?>"
                   class="inline-input"
                   value="<?= htmlspecialchars($row['salt_name']) ?>"
                   style="display:none;">

        </td>

        <td>
            <?= date('d-M-Y',strtotime($row['created_at'])) ?>
        </td>

        <td class="action-buttons">

            <button type="button" class="btn-icon btn-edit"
                    title="Edit"
                    onclick="editSalt(<?= $row['id'] ?>)">
                <i class="fa-solid fa-pen-to-square"></i>
            </button>

            <button type="button" class="btn-icon btn-delete"
                    title="Delete"
                    onclick="deleteSalt(<?= $row['id'] ?>)">
                <i class="fa-solid fa-trash"></i>
            </button>

        </td>

    </tr>

    <?php } ?>

    </tbody>

</table>

</div>

<!-- DELETE POPUP -->

<div id="deleteConfirm" class="confirm-modal">

    <div class="confirm-box">

        <p>Are you sure you want to delete this Salt?</p>

        <small>This action cannot be undone.</small>

        <div class="confirm-actions">

            <button class="btn-cancel"
                    onclick="closeDelete()">
                Cancel
            </button>

            <button class="btn-confirm"
                    id="confirmDeleteBtn">
                Delete
            </button>

        </div>

    </div>

</div>

<script>

/* AUTO FOCUS */
document.getElementById('saltInput').focus();

/* ENTER ADD */
document.getElementById('saltInput').addEventListener('keypress', function(e){

    if(e.key === 'Enter'){

        e.preventDefault();

        if(this.value.trim() !== ''){
            document.getElementById('quickAddForm').submit();
        }
    }
});

/* INLINE EDIT */

function editSalt(id){

    let text = document.getElementById('text'+id);
    let input = document.getElementById('input'+id);
    let row = document.getElementById('row'+id);

    text.style.display='none';
    input.style.display='block';

    row.classList.add('editing');

    input.focus();

    input.onkeypress = function(e){

        if(e.key === 'Enter'){
            saveSalt(id);
        }
    };

    input.onblur = function(){
        saveSalt(id);
    };
}

/* SAVE */

function saveSalt(id){

    let input = document.getElementById('input'+id);
    let value = input.value.trim();

    if(value == ''){
        return;
    }

    let formData = new FormData();

    formData.append('update_salt',1);
    formData.append('id',id);
    formData.append('salt_name',value);

    fetch('salts_master.php', {
        method:'POST',
        body:formData
    })
    .then(res=>res.text())
    .then(data=>{

        document.getElementById('text'+id).innerText = value;

        document.getElementById('text'+id).style.display='block';

        document.getElementById('input'+id).style.display='none';

        document.getElementById('row'+id).classList.remove('editing');
    });
}

/* DELETE */

let deleteId = null;

function deleteSalt(id){

    deleteId = id;

    document.getElementById("deleteConfirm").style.display = "flex";
}

function closeDelete(){

    deleteId = null;

    document.getElementById("deleteConfirm").style.display = "none";
}

document.getElementById("confirmDeleteBtn").onclick = function(){

    if(deleteId){
        window.location.href = "salts_master.php?delete_id=" + deleteId;
    }
};

/* ESC CLOSE */

document.addEventListener("keydown", function(e){

    if(e.key === "Escape"){
        closeDelete();
    }
});

/* OUTSIDE CLICK */
/* LIVE SEARCH */

document.getElementById('searchSalt').addEventListener('keyup', function(){

    let value = this.value.toLowerCase();

    document.querySelectorAll('.salt-row').forEach(row => {

        let text = row.querySelector('span').innerText.toLowerCase();

        if(text.includes(value)){
            row.style.display = '';
        }else{
            row.style.display = 'none';
        }
    });
});
window.onclick = function(event){

    let modal = document.getElementById('deleteConfirm');

    if(event.target === modal){
        closeDelete();
    }
};

</script>

</body>
</html>