<?php
ini_set('display_errors', 1);
ini_set('display_startup_errors', 1);
error_reporting(E_ALL);
session_start();
include("../../includes/db.php");
if (!isset($_SESSION['user_id'])) {
    exit("Session Expired");
}

/* Established session CSRF token, same pattern as index.php and
   modules/abdm/settings/abdm_settings.php. Required by save_ticket.php. */
if (empty($_SESSION['csrf'])) {
    $_SESSION['csrf'] = bin2hex(random_bytes(32));
}
/*
|--------------------------------------------------------------------------
| FETCH HOSPITALS
|--------------------------------------------------------------------------
*/
$hospitalQuery = mysqli_query($conn,"
    SELECT id, hospital_name
    FROM hospital_master
    WHERE status = '1'
    ORDER BY hospital_name ASC
");
/*


|--------------------------------------------------------------------------
| FETCH USERS
|--------------------------------------------------------------------------
*/
$userQuery = mysqli_query($conn,"
    SELECT id, full_name
    FROM users
    ORDER BY full_name ASC
");
/*
|--------------------------------------------------------------------------
| MODULE DROPDOWN ARRAY
|--------------------------------------------------------------------------
*/
$modules = [
    "Appointment",
    "OPD",
    "IPD",
    "Pharmacy",
    "Laboratory",
    "Radiology",
    "Billing",
    "Accounts",
    "HRMS",
    "Doctor Master",
    "Patient Registration",
    "Dashboard",
    "Inventory",
    "Reports",
    "Settings",
    "Website",
    "Mobile App",
    "Other"
];
?>
<!DOCTYPE html>
<html>
<head>
    <meta charset="UTF-8">
    <title>Create Ticket</title>
    <link rel="stylesheet"
          href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.5.1/css/all.min.css">
<style>
*{
    margin:0;
    padding:0;
    box-sizing:border-box;
    font-family:Arial, sans-serif;
}
body{
    background:#f5f7fb;
    padding:20px;
}
.ticket-card{
    background:#fff;
    border-radius:16px;
    padding:15px;
    box-shadow:0 4px 20px rgba(0,0,0,0.08);
}
.ticket-title{
    font-size:22px;
    font-weight:700;
    margin-bottom:5px;
    color:#222;
}
.form-grid{
    display:grid;
    grid-template-columns:1fr 1fr;
    gap:5px;
}
.form-group{
    display:flex;
    flex-direction:column;
}
.form-group.full{
    grid-column:1 / -1;
}
label{
    font-size:14px;
    font-weight:600;
    margin-bottom:8px;
    color:#333;
}
input,
select,
textarea{
    width:100%;
    border:1px solid #dcdfe6;
    border-radius:10px;
    padding:12px 14px;
    font-size:14px;
    outline:none;
    transition:0.2s;
    background:#fff;
}
input:focus,
select:focus,
textarea:focus{
    border-color:#4f46e5;
    box-shadow:0 0 0 3px rgba(79,70,229,0.1);
}
textarea{
    resize:vertical;
    min-height:90px;
    max-height:120px;
}
.readonly-field{
    background:#f3f4f6;
}
.submit-btn{
    background:linear-gradient(135deg,#4f46e5,#7c3aed);
    color:#fff;
    border:none;
    border-radius:12px;
    padding:14px 20px;
    font-size:15px;
    font-weight:600;
    cursor:pointer;
    transition:0.2s;
}
.submit-btn:hover{
    transform:translateY(-1px);
    opacity:0.95;
}
.required{
    color:red;
}
@media(max-width:768px){

    .form-grid{
        grid-template-columns:1fr;
    }
}
</style>
</head>
<body>
<div class="ticket-card">
    <div class="ticket-title">
        <i class="fas fa-ticket-alt"
       style="color:#10b981;"></i>
        Create Support Ticket
    </div>
    <form action="save_ticket.php"
          method="POST"
          enctype="multipart/form-data">
        <input type="hidden"
               name="csrf"
               value="<?= htmlspecialchars($_SESSION['csrf'], ENT_QUOTES, 'UTF-8') ?>">
        <div class="form-grid">
            <!-- HOSPITAL -->
            <div class="form-group">
                <label>
                    Hospital
                    <span class="required">*</span>
                </label>
                <select name="hospital_id"
        id="hospitalDropdown"
        required>
                    <option value="">
                        Select Hospital
                    </option>
                    <?php while($hospital = mysqli_fetch_assoc($hospitalQuery)) { ?>
                        <option value="<?= $hospital['id'] ?>">
                            <?= htmlspecialchars($hospital['hospital_name']) ?>
                        </option>
                    <?php } ?>
                </select>
            </div>
            <!-- BRANCH -->
            <div class="form-group">
                <label>
                    Branch
                    <span class="required">*</span>
                </label>
                <select name="branch_id"
        id="branchDropdown"
        required>

    <option value="">
        Select Branch
    </option>

</select>
            </div>
            <!-- MODULE -->
            <div class="form-group">
                <label>
                    Module
                    <span class="required">*</span>
                </label>
                <select name="module_name" required>
                    <option value="">
                        Select Module
                    </option>
                    <?php foreach($modules as $module){ ?>
                        <option value="<?= $module ?>">
                            <?= $module ?>
                        </option>
                    <?php } ?>
                </select>
            </div>
            <!-- TYPE -->
            <div class="form-group">
                <label>
                    Type
                    <span class="required">*</span>
                </label>
                <select name="ticket_type" required>
                    <option value="">
                        Select Type
                    </option>
                    <option value="Issue">
                        Issue
                    </option>
                    <option value="Requirement">
                        Requirement
                    </option>
                </select>
            </div>
            <!-- TITLE -->
            <div class="form-group full">
                <label>
                    Title
                    <span class="required">*</span>
                </label>
                <input type="text"
                       name="title"
                       placeholder="Enter Ticket Title"
                       required>
            </div>
            <!-- DESCRIPTION -->
            <div class="form-group full">
                <label>
                    Description
                    <span class="required">*</span>
                </label>
                <textarea name="description"
                          placeholder="Describe Issue / Requirement"
                          required></textarea>
            </div>
            <!-- ATTACHMENT -->
            <div class="form-group">
                <label>
                    Attachment
                </label>
                <input type="file"
                       name="attachment">
            </div>
            <!-- ISSUE FACED BY -->
            <div class="form-group">
                <label>
                    Issue Faced By
                    <span class="required">*</span>
                </label>
                <select name="issue_faced_by" required>
                    <option value="">
                        Select User
                    </option>
                    <?php while($user = mysqli_fetch_assoc($userQuery)) { ?>
                        <option value="<?= $user['id'] ?>">
                            <?= htmlspecialchars($user['full_name']) ?>
                        </option>
                    <?php } ?>
                </select>
            </div>
            <!-- CREATED BY -->
            <div class="form-group">
                <label>
                    Created By
                </label>
                <input type="text"
                       class="readonly-field"
                       value="<?= htmlspecialchars($_SESSION['name']) ?>"
                       readonly>
                <input type="hidden"
                       name="created_by"
                       value="<?= $_SESSION['user_id'] ?>">
            </div>
            <!-- CREATED DATE -->
            <div class="form-group">
                <label>
                    Created Date
                </label>
                <input type="text"
                       class="readonly-field"
                       value="<?= date('d-m-Y h:i A') ?>"
                       readonly>
            </div>
            <!-- SUBMIT -->
            <div class="form-group full">
                <button type="submit"
                        class="submit-btn">
                    <i class="fas fa-paper-plane"></i>
                    Submit Ticket
                </button>
            </div>
        </div>
    </form>
</div>
<script src="https://code.jquery.com/jquery-3.7.1.min.js"></script>

<script>

$('#hospitalDropdown').on('change', function(){

    var hospital_id = $(this).val();

    if(hospital_id != ''){

        $.ajax({

            url:'get_branches.php',

            type:'POST',

            data:{
                hospital_id:hospital_id
            },

            success:function(response){

                $('#branchDropdown').html(response);

            }

        });

    }
    else{

        $('#branchDropdown').html(
            '<option value="">Select Branch</option>'
        );

    }

});

</script>
</body>
</html>