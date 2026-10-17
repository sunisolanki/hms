<?php
ini_set('display_errors',1);
ini_set('display_startup_errors',1);
error_reporting(E_ALL);

session_start();
require $_SERVER['DOCUMENT_ROOT'].'/includes/db.php';
require $_SERVER['DOCUMENT_ROOT'].'/includes/permissions.php';
require_once $_SERVER['DOCUMENT_ROOT'].'/includes/audit_log.php';

/* LOGIN CHECK */
if (!isset($_SESSION['user_id'])) {
    header('Location: /index.php');
    exit;
}

/* VIEW PERMISSION */
if (!checkPermission('charges_type_master','view')) {
    header('Location: /access_denied.php');
    exit;
}

$user_id = $_SESSION['user_id'];

/* DELETE */
if (isset($_GET['delete']) && checkPermission('charges_type_master','delete')) {

    $id = (int)$_GET['delete'];

    $oldStmt = mysqli_prepare($conn,"
        SELECT *
        FROM charges_master
        WHERE id=?
    ");

    mysqli_stmt_bind_param($oldStmt,"i",$id);
    mysqli_stmt_execute($oldStmt);

    $oldResult = mysqli_stmt_get_result($oldStmt);
    $oldData = mysqli_fetch_assoc($oldResult);

    mysqli_stmt_close($oldStmt);


    if($oldData){

        $delete = mysqli_prepare($conn,"
            DELETE FROM charges_master
            WHERE id=?
        ");

        mysqli_stmt_bind_param($delete,"i",$id);
        mysqli_stmt_execute($delete);
        mysqli_stmt_close($delete);


        addAuditLog(
            'Charges Type Master',
            'DELETE',
            'Charge Deleted : '.$oldData['charges_name'],
            $id,
            '',
            ''
        );


        $_SESSION['success']="Charge deleted successfully.";
    }

    header("Location: charges_type_master.php");
    exit;
}


/* SAVE ADD / EDIT */

if($_SERVER['REQUEST_METHOD']=="POST"){

    $id = $_POST['id'] ?? 0;

    $charges_name = trim($_POST['charges_name']);
    $frequency = $_POST['frequency'];
    $charges = $_POST['charges'];
    $status = $_POST['status'];


    $types = $_POST['charge_type'] ?? [];


    if(
        ($id>0 && !checkPermission('charges_type_master','update'))
        ||
        ($id==0 && !checkPermission('charges_type_master','create'))
    ){

        header('Location:/access_denied.php');
        exit;
    }

if($charges_name==""){

    $_SESSION['error']="Charges Name is required.";
    header("Location:charges_type_master.php");
    exit;
}


if(empty($types)){

    $_SESSION['error']="Please select at least one Charges Type.";
    header("Location:charges_type_master.php");
    exit;
}


if($frequency==""){

    $_SESSION['error']="Frequency is required.";
    header("Location:charges_type_master.php");
    exit;
}


if($charges=="" || $charges<=0){

    $_SESSION['error']="Charges amount is required.";
    header("Location:charges_type_master.php");
    exit;
}


if($status==""){

    $_SESSION['error']="Status is required.";
    header("Location:charges_type_master.php");
    exit;
}


    mysqli_begin_transaction($conn);


    try{


        if($id>0){


            $oldQ=mysqli_prepare($conn,"
                SELECT *
                FROM charges_master
                WHERE id=?
            ");

            mysqli_stmt_bind_param($oldQ,"i",$id);
            mysqli_stmt_execute($oldQ);

            $old=mysqli_fetch_assoc(
                mysqli_stmt_get_result($oldQ)
            );

            mysqli_stmt_close($oldQ);



            $update=mysqli_prepare($conn,"
                UPDATE charges_master SET
                charges_name=?,
                frequency=?,
                charges=?,
                status=?,
                updated_at=NOW()
                WHERE id=?
            ");


            mysqli_stmt_bind_param(
                $update,
                "ssdii",
                $charges_name,
                $frequency,
                $charges,
                $status,
                $id
            );


            mysqli_stmt_execute($update);

            mysqli_stmt_close($update);



            /* REMOVE OLD TYPES */

            mysqli_query($conn,"
                DELETE FROM charges_type_mapping
                WHERE charge_id=$id
            ");



            $charge_id=$id;


        }else{


            $insert=mysqli_prepare($conn,"
                INSERT INTO charges_master
                (
                    charges_name,
                    frequency,
                    charges,
                    status,
                    created_by
                )
                VALUES(?,?,?,?,?)
            ");


            mysqli_stmt_bind_param(
                $insert,
                "ssdii",
                $charges_name,
                $frequency,
                $charges,
                $status,
                $user_id
            );


            mysqli_stmt_execute($insert);


            $charge_id=mysqli_insert_id($conn);


            mysqli_stmt_close($insert);

        }



        foreach($types as $type){

            $map=mysqli_prepare($conn,"
                INSERT INTO charges_type_mapping
                (
                    charge_id,
                    charge_type
                )
                VALUES(?,?)
            ");

            mysqli_stmt_bind_param(
                $map,
                "is",
                $charge_id,
                $type
            );

            mysqli_stmt_execute($map);

            mysqli_stmt_close($map);

        }



        addAuditLog(
            'Charges Type Master',
            $id>0?'UPDATE':'CREATE',
            ($id>0?'Charge Updated : ':'Charge Created : ').$charges_name,
            $charge_id,
            '',
            ''
        );


        mysqli_commit($conn);


        $_SESSION['success']="Charge saved successfully.";


    }catch(Exception $e){

        mysqli_rollback($conn);

        $_SESSION['error']=$e->getMessage();

    }


    header("Location:charges_type_master.php");
    exit;

}

/* FETCH CHARGES */

$chargesQuery=mysqli_query($conn,"
SELECT
cm.*,
u.full_name AS created_by_name,
GROUP_CONCAT(ctm.charge_type SEPARATOR ', ') AS charge_types,
GROUP_CONCAT(
    CONCAT('\"',ctm.charge_type,'\"')
    SEPARATOR ','
) AS charge_type_json
FROM charges_master cm
LEFT JOIN users u
ON u.id=cm.created_by
LEFT JOIN charges_type_mapping ctm
ON ctm.charge_id=cm.id
GROUP BY cm.id
ORDER BY cm.id DESC
");


/* SUCCESS MESSAGE */

$success=$_SESSION['success'] ?? '';
$error=$_SESSION['error'] ?? '';

unset($_SESSION['success']);
unset($_SESSION['error']);


?>
<!DOCTYPE html>
<html>
<head>

<title>Charges Type Master</title>

<link rel="stylesheet" href="/assets/css/style.css">
<link rel="icon" type="image/png" href="/assets/img/hms_logo.png">
<link rel="apple-touch-icon" href="/assets/img/hms_logo.png">

<link rel="stylesheet"
href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.5.0/css/all.min.css">


<style>

.container{
padding:20px;
}


table{
width:100%;
border-collapse:collapse;
background:white;
}


th{
background:#f1f5f9;
}


th,td{
padding:8px;
border:1px solid #ddd;
font-size:13px;
}



input,
select{
width:100%;
padding:7px;
border:1px solid #ccc;
border-radius:4px;
}



.inline-form{
background:#eef7ff;
}


.btn{
padding:7px 12px;
border:none;
border-radius:5px;
cursor:pointer;
}



.btn-save{
background:#198754;
color:white;
}


.btn-cancel{
background:#dc3545;
color:white;
}


.btn-edit{
background:#0d6efd;
color:white;
}


.btn-delete{
background:#dc3545;
color:white;
}


.badge{
padding:4px 8px;
border-radius:4px;
font-size:12px;
}



.active{
background:#198754;
color:white;
}


.inactive{
background:#6c757d;
color:white;
}

.checkbox-box{
display:flex;
gap:8px;
flex-wrap:wrap;
min-width:500px;
}

.checkbox-item{
display:flex;
align-items:center;
gap:5px;
background:#fff;
border:1px solid #ddd;
padding:6px 10px;
border-radius:5px;
white-space:nowrap;
}
.action{
display:flex;
gap:5px;

}


</style>


</head>


<body>


<?php require $_SERVER['DOCUMENT_ROOT'].'/includes/header.php'; ?>


<div class="top-bar">

<div class="breadcrumb">

<a href="/dashboard/index.php">Home</a>
>
<a href="/masters/masters.php">Masters</a>
>
Charges Type Master

</div>


<h2>Charges Type Master</h2>


</div>



<div class="container">



<?php if($success){ ?>

<div style="background:#d1e7dd;padding:10px;">
<?=htmlspecialchars($success)?>
</div>

<?php } ?>


<?php if($error){ ?>

<div style="background:#f8d7da;padding:10px;">
<?=htmlspecialchars($error)?>
</div>

<?php } ?>



<table>


<thead>

<tr>

<th colspan="8">
</th>


</tr>


<tr>

<th>Charges Name</th>

<th>Charges Type</th>

<th>Frequency</th>

<th>Charges</th>

<th>Status</th>

<th>Created By</th>

<th>Modified</th>

<th>Action</th>


</tr>


</thead>


<tbody>



<!-- ADD / EDIT ROW -->


<tr class="inline-form"
id="formRow">


<form method="post">


<input type="hidden"
name="id"
id="charge_id">



<td>

<input type="text"
name="charges_name"
id="charges_name"
placeholder="Charges Name"
required>

</td>



<td>


<div class="checkbox-box">


<?php

$chargeTypes=[
"Doctor Charges",
"Room Charges",
"Ward Charges",
"Surgery Charges",
"Additional Charges"
];


foreach($chargeTypes as $type){

?>

<label class="checkbox-item">

<input type="checkbox"
name="charge_type[]"
value="<?=$type?>">

<?=$type?>

</label>


<?php } ?>


</div>


</td>



<td>

<select name="frequency"
id="frequency"
required>


<option value="Day Wise">
Day Wise
</option>


<option value="One Time">
One Time
</option>


</select>


</td>



<td>

<input type="number"
step="0.01"
min="1"
name="charges"
id="charges"
value=""
required>

</td>



<td>


<select name="status"
id="status"
required>


<option value="1">
Active
</option>


<option value="0">
Inactive
</option>


</select>


</td>



<td>
-
</td>



<td>
-
</td>



<td>


<button class="btn btn-save">
Save
</button>
<button type="button"
class="btn btn-cancel"
onclick="resetForm()">
Reset
</button>


</td>



</form>


</tr>
<?php while($row=mysqli_fetch_assoc($chargesQuery)){ ?>

<tr>

<td>
<?=htmlspecialchars($row['charges_name'])?>
</td>


<td>
<?=htmlspecialchars($row['charge_types'] ?? '')?>
</td>


<td>
<?=htmlspecialchars($row['frequency'])?>
</td>


<td>
₹ <?=number_format($row['charges'],2)?>
</td>


<td>

<?php if($row['status']==1){ ?>

<span class="badge active">
Active
</span>

<?php }else{ ?>

<span class="badge inactive">
Inactive
</span>

<?php } ?>

</td>


<td>
<?=htmlspecialchars($row['created_by_name'] ?? '-')?>
</td>


<td>

<?=!empty($row['updated_at'])
?
date('d-M-Y',strtotime($row['updated_at']))
:
date('d-M-Y',strtotime($row['created_at']))
?>

</td>


<td>


<div class="action">


<?php if(checkPermission('charges_type_master','update')){ ?>

<button class="btn btn-edit"
onclick='editCharge(
<?=htmlspecialchars(json_encode($row),ENT_QUOTES,"UTF-8")?>
)'
>
<i class="fa fa-edit"></i>
</button>

<?php } ?>


<?php if(checkPermission('charges_type_master','delete')){ ?>

<a class="btn btn-delete"
href="charges_type_master.php?delete=<?=$row['id']?>"
onclick="return confirm('Delete this charge?')">

<i class="fa fa-trash"></i>

</a>

<?php } ?>


</div>


</td>


</tr>


<?php } ?>


</tbody>

</table>


</div>


<script>
    function resetForm(){

document.getElementById('charge_id').value='';

document.getElementById('charges_name').value='';

document.getElementById('charges').value='';

document.getElementById('frequency').value='Day Wise';

document.getElementById('status').value='1';


document.querySelectorAll(
'input[name="charge_type[]"]'
).forEach(function(e){
    e.checked=false;
});

}
function editCharge(data){
document.getElementById('charge_id').value=data.id;

document.getElementById('charges_name').value=data.charges_name;

document.getElementById('frequency').value=data.frequency;

document.getElementById('charges').value=data.charges;

document.getElementById('status').value=data.status;


/* uncheck all first */

document.querySelectorAll(
'input[name="charge_type[]"]'
).forEach(function(e){
    e.checked=false;
});


/* select saved charge types */

if(data.charge_type_json){

    let selectedTypes=JSON.parse(
        "["+data.charge_type_json+"]"
    );


    selectedTypes.forEach(function(type){

        let checkbox=document.querySelector(
            'input[name="charge_type[]"][value="'+type+'"]'
        );


        if(checkbox){
            checkbox.checked=true;
        }

    });

}


window.scrollTo({
top:0,
behavior:'smooth'
});

}
</script>
</body>
</html>