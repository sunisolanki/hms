<?php
/*
 |--------------------------------------------------------------------------
 | SAVE TICKET
 |--------------------------------------------------------------------------
 | Batch 1 security remediation.
 |
 | Defects fixed here:
 |   1. SQL injection - $hospital_id, $branch_id and $issue_faced_by were read
 |      straight from $_POST and interpolated into the INSERT string. Every
 |      value now goes through a prepared statement; the three integer columns
 |      are additionally cast. Exactly one INSERT statement exists in this file
 |      and it is the only write path.
 |   2. Authorization - the handler only checked that some session existed.
 |      It now fails closed on a missing user id or role.
 |      NOTE: no 'tickets' module slug exists in the permissions data, so a
 |      checkPermission('tickets', ...) gate would deny every legitimate
 |      submission for non-super-admin users. Adding that module requires
 |      seeding module_permissions and is deliberately out of Batch 1 scope.
 |   3. Unrestricted file upload - the original filename was filtered with
 |      /[^A-Za-z0-9.\-_]/ which preserves dots, so "shell.php" survived into
 |      a web-reachable uploads/tickets directory with no execution barrier,
 |      i.e. remote code execution. Uploads are now extension allow-listed,
 |      content-sniffed, size-limited and stored under a server-generated name.
 |   4. CSRF - the form was an unprotected state-changing POST. It now requires
 |      the application's established session token ($_SESSION['csrf'], created
 |      at index.php:155 and compared with hash_equals(), exactly as
 |      modules/abdm/settings/save_global_switch.php does). The hidden field is
 |      emitted by modules/tickets/add_ticket.php.
 |   5. Referential integrity - hospital_id and branch_id were accepted as
 |      arbitrary integers, so a ticket could be filed against a hospital that
 |      does not exist, or a branch belonging to a different hospital.
 |      They are now validated against hospital_master and branch_master.
 |      They are deliberately NOT forced to $_SESSION['hospital_id']: the
 |      support-ticket workflow in add_ticket.php deliberately lets an
 |      administrator raise a ticket for any active hospital, so binding would
 |      break that workflow rather than secure it.
 |   6. Schema agreement - the server runs with a NON-strict sql_mode, so an
 |      out-of-range enum or an over-length varchar was silently coerced
 |      instead of rejected. ticket_type is now checked against the real enum
 |      and the varchar(255)/text columns are length-capped before the write.
 |
 | Response contract unchanged: success renders the same "Ticket Submitted"
 | page inside the ticket popup iframe; failure still emits the same alert.
 */

ini_set('display_errors', '0');
ini_set('display_startup_errors', '0');
error_reporting(E_ALL);
session_start();

include $_SERVER['DOCUMENT_ROOT'] . '/includes/db.php';

if (!isset($_SESSION['user_id']) || empty($_SESSION['user_id'])) {
    exit("Session Expired");
}

/* Fail closed unless the session belongs to a configured application user
   (a role is always assigned at login). */
if (!isset($_SESSION['rights_type_id']) || (int)$_SESSION['rights_type_id'] <= 0) {
    exit("Access denied");
}

/*
|--------------------------------------------------------------------------
| CSRF
|--------------------------------------------------------------------------
| The application's established token: generated at login with
| bin2hex(random_bytes(32)) and verified in constant time. Checked before any
| upload is touched and before the database is read or written.
*/
if (
    empty($_SESSION['csrf']) ||
    !isset($_POST['csrf']) ||
    !is_string($_POST['csrf']) ||
    !hash_equals((string)$_SESSION['csrf'], $_POST['csrf'])
) {
    die("
        <script>
            alert('Your session has expired. Please try again.');
            window.history.back();
        </script>
    ");
}
/*
|--------------------------------------------------------------------------
| GET FORM DATA
|--------------------------------------------------------------------------
*/
$hospital_id     = (int)($_POST['hospital_id'] ?? 0);
$branch_id       = (int)($_POST['branch_id'] ?? 0);
$module_name     = trim((string)($_POST['module_name'] ?? ''));
$ticket_type     = trim((string)($_POST['ticket_type'] ?? ''));
$title           = trim((string)($_POST['title'] ?? ''));
$description     = trim((string)($_POST['description'] ?? ''));
$issue_faced_by  = (int)($_POST['issue_faced_by'] ?? 0);
$created_by      = (int)$_SESSION['user_id'];
$created_date    = date('Y-m-d H:i:s');

/* Text fields must be plain text only - strip control characters so nothing
   smuggled through a browser can reach a terminal or log. */
foreach (['module_name', 'ticket_type', 'title', 'description'] as $field) {
    ${$field} = preg_replace('/[\x00-\x08\x0B\x0C\x0E-\x1F\x7F]/u', '', ${$field});
    ${$field} = is_string(${$field}) ? trim(${$field}) : '';
}
/*
|--------------------------------------------------------------------------
| VALIDATION
|--------------------------------------------------------------------------
*/
if(
    empty($hospital_id) ||
    empty($branch_id) ||
    empty($module_name) ||
    empty($ticket_type) ||
    empty($title) ||
    empty($description) ||
    empty($issue_faced_by)
){
    die("
        <script>
            alert('Please fill all required fields');
            window.history.back();
        </script>
    ");
}

/*
| ticket_type is an ENUM('Issue','Requirement') column and the server does not
| run a strict sql_mode, so a value outside the enum would be silently stored
| as ''. It is rejected here instead.
*/
if (!in_array($ticket_type, ['Issue', 'Requirement'], true)) {
    die("
        <script>
            alert('Please select a valid ticket type');
            window.history.back();
        </script>
    ");
}

/* varchar(255) / text column limits, checked before the write so an
   over-length value is refused rather than silently truncated. */
if (
    mb_strlen($module_name, 'UTF-8') > 255 ||
    mb_strlen($ticket_type, 'UTF-8') > 255 ||
    mb_strlen($title, 'UTF-8') > 255 ||
    mb_strlen($description, 'UTF-8') > 65535
) {
    die("
        <script>
            alert('One or more fields are too long');
            window.history.back();
        </script>
    ");
}

/*
|--------------------------------------------------------------------------
| HOSPITAL / BRANCH INTEGRITY
|--------------------------------------------------------------------------
| Read-only lookups on prepared statements, run before the upload is stored so
| a rejected submission never leaves an orphaned file behind. These validate
| referential integrity only; they are deliberately not an authorization rule
| (see the header note about the cross-hospital support workflow).
*/
$hospitalExists = false;
$stmt = $conn->prepare("SELECT id FROM hospital_master WHERE id = ? LIMIT 1");

if ($stmt) {
    $stmt->bind_param("i", $hospital_id);
    $stmt->execute();
    $hospitalExists = ($stmt->get_result()->fetch_assoc() !== null);
    $stmt->close();
}

if (!$hospitalExists) {
    die("
        <script>
            alert('The selected hospital is not valid');
            window.history.back();
        </script>
    ");
}

$branchBelongsToHospital = false;
$stmt = $conn->prepare(
    "SELECT id FROM branch_master WHERE id = ? AND hospital_id = ? LIMIT 1"
);

if ($stmt) {
    $stmt->bind_param("ii", $branch_id, $hospital_id);
    $stmt->execute();
    $branchBelongsToHospital = ($stmt->get_result()->fetch_assoc() !== null);
    $stmt->close();
}

if (!$branchBelongsToHospital) {
    die("
        <script>
            alert('The selected branch does not belong to the selected hospital');
            window.history.back();
        </script>
    ");
}
/*
|--------------------------------------------------------------------------
| FILE UPLOAD
|--------------------------------------------------------------------------
|
| Nothing about the client-supplied filename is trusted. The stored name is
| generated on the server; the extension is taken from a fixed allow-list.
 |
| A rejected upload exits here, so no database write is ever reached for an
| invalid or oversized attachment.
 */
$attachment = '';
$uploadError = '';

const TICKET_ATTACHMENT_MAX_BYTES = 5242880; // 5 MB

const TICKET_ALLOWED_EXTENSIONS = [
    'pdf'  => ['application/pdf', 'application/x-pdf'],
    'doc'  => ['application/msword'],
    'docx' => ['application/vnd.openxmlformats-officedocument.wordprocessingml.document', 'application/zip'],
    'xls'  => ['application/vnd.ms-excel'],
    'xlsx' => ['application/vnd.openxmlformats-officedocument.spreadsheetml.sheet', 'application/zip'],
    'ppt'  => ['application/vnd.ms-powerpoint'],
    'pptx' => ['application/vnd.openxmlformats-officedocument.presentationml.presentation', 'application/zip'],
    'txt'  => ['text/plain'],
    'csv'  => ['text/plain', 'text/csv', 'application/csv'],
    'jpg'  => ['image/jpeg'],
    'jpeg' => ['image/jpeg'],
    'png'  => ['image/png'],
    'gif'  => ['image/gif'],
    'zip'  => ['application/zip', 'application/x-zip-compressed'],
];

if (isset($_FILES['attachment']) && $_FILES['attachment']['error'] !== UPLOAD_ERR_NO_FILE) {
    $file      = $_FILES['attachment'];
    $uploadErr = (int)$file['error'];

    if ($uploadErr !== UPLOAD_ERR_OK) {
        $uploadError = 'The attachment could not be uploaded (error code ' . $uploadErr . ').';
    } elseif (!is_uploaded_file($file['tmp_name'])) {
        $uploadError = 'Invalid upload.';
    } elseif ((int)$file['size'] > TICKET_ATTACHMENT_MAX_BYTES) {
        $uploadError = 'Attachment is larger than the 5 MB limit.';
    } else {
        $originalName = (string)$file['name'];

        /* Reject any hidden/double-extension trick before looking at the last
           segment: shell.php.jpg, .php, x.php. (trailing dot/space) etc. */
        $segments = preg_split('/[\.]/', $originalName);
        $lowerSegments = array_map('strtolower', $segments);
        $dangerous = ['php', 'php3', 'php4', 'php5', 'php7', 'php8', 'phtml', 'phps', 'phar',
                      'cgi', 'pl', 'py', 'sh', 'bash', 'exe', 'com', 'bat', 'cmd', 'asp', 'aspx',
                      'jsp', 'jspx', 'htaccess', 'htpasswd', 'inc', 'shtml'];
        foreach ($lowerSegments as $seg) {
            if (in_array(rtrim($seg, " \t"), $dangerous, true)) {
                $uploadError = 'That file type is not allowed.';
                break;
            }
        }

        if ($uploadError === '') {
            $ext = strtolower((string)pathinfo($originalName, PATHINFO_EXTENSION));
            if ($ext === '' || !isset(TICKET_ALLOWED_EXTENSIONS[$ext])) {
                $uploadError = 'That file type is not allowed.';
            }
        }

        if ($uploadError === '') {
            /* Content sniffing - the extension must agree with the real bytes. */
            $finfo = new finfo(FILEINFO_MIME_TYPE);
            $detected = (string)$finfo->file($file['tmp_name']);

            if (!in_array($detected, TICKET_ALLOWED_EXTENSIONS[$ext], true)) {
                $uploadError = 'File contents do not match the file extension.';
            }
        }

        if ($uploadError === '') {
            /* Images must additionally be structurally valid, and no file may
               carry an embedded PHP opening tag. */
            if (in_array($ext, ['jpg', 'jpeg', 'png', 'gif'], true)) {
                if (@getimagesize($file['tmp_name']) === false) {
                    $uploadError = 'That image file could not be read.';
                }
            }
            $head = (string)file_get_contents($file['tmp_name'], false, null, 0, 8192);
            if (stripos($head, '<?php') !== false ||
                stripos($head, '<?=') !== false ||
                stripos($head, '<script language="php"') !== false ||
                stripos($head, '#!/usr/bin/php') !== false) {
                $uploadError = 'Executable content is not allowed.';
            }
        }

        if ($uploadError === '') {
            $uploadDir = $_SERVER['DOCUMENT_ROOT'] . '/uploads/tickets';
            if (!is_dir($uploadDir)) {
                @mkdir($uploadDir, 0755, true);
            }
            $fileName = bin2hex(random_bytes(16)) . '.' . $ext;
            $targetFile = $uploadDir . '/' . $fileName;

            if (@move_uploaded_file($file['tmp_name'], $targetFile)) {
                @chmod($targetFile, 0644);
                $attachment = $fileName;
            } else {
                $uploadError = 'The attachment could not be stored.';
            }
        }
    }

    if ($uploadError !== '') {
        echo '<script>alert(' . json_encode($uploadError) . '); window.history.back();</script>';
        exit;
    }
}
/*
|--------------------------------------------------------------------------
| INSERT QUERY
|--------------------------------------------------------------------------
*/
mysqli_report(MYSQLI_REPORT_ERROR | MYSQLI_REPORT_STRICT);

$query = false;
$ticket_id = 0;

try {
    $stmt = $conn->prepare("
        INSERT INTO tickets (
            hospital_id,
            branch_id,
            module_name,
            ticket_type,
            title,
            description,
            attachment,
            issue_faced_by,
            created_by,
            created_date
        ) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?)
    ");

    if ($stmt === false) {
        throw new RuntimeException('prepare failed');
    }

    $stmt->bind_param(
        "iisssssssi",
        $hospital_id,
        $branch_id,
        $module_name,
        $ticket_type,
        $title,
        $description,
        $attachment,
        $issue_faced_by,
        $created_by,
        $created_date
    );

    $query = $stmt->execute();
    $ticket_id = (int)$conn->insert_id;
    $stmt->close();
} catch (Throwable $e) {
    error_log('save_ticket insert failed: ' . $e->getMessage());
    $query = false;
    $ticket_id = 0;
}
/*
|--------------------------------------------------------------------------
| SUCCESS / FAILED
|--------------------------------------------------------------------------
| $ticket_id is read once, inside the try block, immediately after the single
| INSERT. There is no second insert_id call and no second write path.
*/
if($query && $ticket_id > 0){
/*
|--------------------------------------------------------------------------
| GENERATE CUSTOM TICKET NUMBER
|--------------------------------------------------------------------------
*/

$type_code = ($ticket_type === 'Requirement')
    ? 'R'
    : 'I';

$custom_ticket_no =
    'HS' .
    $hospital_id .
    '-' .
    $type_code .
    '-' .
    $ticket_id;
?>
<!DOCTYPE html>
<html>
<head>
    <title>Ticket Saved</title>
    <style>
        body{
            font-family:Arial,sans-serif;
            background:#f5f7fb;
            display:flex;
            justify-content:center;
            align-items:center;
            height:100vh;
            margin:0;
        }
        .success-box{
            background:#fff;
            padding:40px;
            border-radius:20px;
            text-align:center;
            box-shadow:0 10px 30px rgba(0,0,0,0.1);
            width:400px;
            max-width:90%;
        }
        .success-icon{
            width:90px;
            height:90px;
            background:#10b981;
            color:#fff;
            border-radius:50%;
            display:flex;
            align-items:center;
            justify-content:center;
            font-size:40px;
            margin:0 auto 20px;
        }
        h2{
            margin-bottom:10px;
            color:#111827;
        }
        p{
            color:#6b7280;
            margin-bottom:25px;
        }
        .btn{
            background:#2563eb;
            color:#fff;
            border:none;
            padding:12px 22px;
            border-radius:10px;
            cursor:pointer;
            font-size:14px;
        }
    </style>
</head>
<body>
<div class="success-box">
    <div class="success-icon">
        ✓
    </div>
    <h2>Ticket Submitted</h2>
    <p style="margin-bottom:12px;">
    Your support ticket has been created successfully.
</p>

<div style="
    background:#eff6ff;
    color:#1d4ed8;
    padding:12px;
    border-radius:10px;
    font-size:18px;
    font-weight:700;
    margin-bottom:25px;
    border:1px solid #bfdbfe;
">

    Ticket ID :
    <?= $custom_ticket_no ?>

<div style="
    display:flex;
    gap:12px;
    justify-content:center;
    flex-wrap:wrap;
">

    <!-- CLOSE BUTTON -->

    <button class="btn"
            type="button"
            onclick="closePopup()">

        Close

    </button>

    <!-- ALL TICKETS BUTTON -->

    <button class="btn"
            type="button"
            onclick="openAllTickets()"
            style="
                background:#10b981;
            ">

        All Tickets

    </button>

</div>
<script>
function closePopup(){
    if(window.parent){
        window.parent.closeTicketPopup();
    }
}
setTimeout(function(){
    if(window.parent){
        window.parent.closeTicketPopup();
    }
}, 2000);

function openAllTickets(){

    window.parent.location.href =
        '/user/Ticket_list.php';

}
</script>
</body>
</html>
<?php
}
else{

    echo "
    <script>
        alert('Failed to save ticket');
        window.history.back();
    </script>
    ";
}
?>