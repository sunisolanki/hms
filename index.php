<?php
ini_set('display_errors', 1);
ini_set('display_startup_errors', 1);
error_reporting(E_ALL);
/* Secure session settings */
ini_set('session.cookie_httponly', 1);
ini_set('session.cookie_secure', 1);   // works only on HTTPS
ini_set('session.use_strict_mode', 1);
if (empty($_SERVER['HTTPS'])) {
    header("Location: https://" . $_SERVER['HTTP_HOST'] . $_SERVER['REQUEST_URI']);
    exit();
}
session_set_cookie_params([
    'lifetime' => 0,
    'path' => '/',
    'secure' => true,
    'httponly' => true,
    'samesite' => 'Strict'
]);
session_start();
require_once __DIR__ . '/includes/db.php';
require_once __DIR__ . '/includes/tenant.php';
require_once __DIR__ . '/includes/tenant_db.php';
if (!isset($_SESSION['user_id']) && !empty($_COOKIE['remember_token']) && !empty($_COOKIE['remember_tenant'])) {

    $rememberToken = $_COOKIE['remember_token'];
    $rememberTenantId = $_COOKIE['remember_tenant'];

    $rememberTenant = getTenant($rememberTenantId);

   if (
    $rememberTenant &&
    $rememberTenant['access_status'] === 'Allowed'
) {

        try {

            $rememberConn = connectTenantDatabase($rememberTenant);

            $stmt = $rememberConn->prepare("
                SELECT user_id
                FROM remember_tokens
                WHERE token = ?
                AND expires_at > NOW()
                LIMIT 1
            ");

            $stmt->bind_param("s", $rememberToken);
            $stmt->execute();

            $result = $stmt->get_result();

            if ($row = $result->fetch_assoc()) {

                $stmt = $rememberConn->prepare("
                    SELECT
                        u.id,
                        u.employee_id,
                        u.full_name,
                        u.username,
                        u.email,
                        u.rights_type_id,
                        rt.name AS role_name
                    FROM users u
                    LEFT JOIN rights_types rt
                        ON rt.id = u.rights_type_id
                    WHERE u.id = ?
                    LIMIT 1
                ");

                $stmt->bind_param("i", $row['user_id']);
                $stmt->execute();

                $user = $stmt->get_result()->fetch_assoc();

                if ($user) {

                    $_SESSION['user_id'] = $user['id'];
                    $_SESSION['employee_id'] = $user['employee_id'];
                    $_SESSION['name'] = $user['full_name'];
                    $_SESSION['username'] = $user['username'];
                    $_SESSION['email'] = $user['email'];
                    $_SESSION['rights_type_id'] = $user['rights_type_id'];
                    $_SESSION['role_name'] = $user['role_name'];
                    $_SESSION['tenant_id'] = $rememberTenant['tenant_id'];
                    $_SESSION['hospital_id'] = $rememberTenant['hospital_id'];

                    $branchStmt = $rememberConn->prepare("
                        SELECT branch_id
                        FROM user_branches
                        WHERE user_id = ?
                        LIMIT 1
                    ");

                    $branchStmt->bind_param("i", $user['id']);
                    $branchStmt->execute();

                    $branchResult = $branchStmt->get_result();

                    if ($branchRow = $branchResult->fetch_assoc()) {
                        $_SESSION['branch_id'] = $branchRow['branch_id'];
                    }

                    require_once __DIR__ . '/includes/audit_log.php';

                    addAuditLog(
                        'Authentication',
                        'LOGIN',
                        'Remember Me Login Success | ' . $user['full_name'] . ' | ' . $user['email']
                    );

                    $rememberConn->close();

                    header("Location: /dashboard/index.php");
                    exit;
                }
            }

            $rememberConn->close();

        } catch (Throwable $e) {

            // Invalid/expired tenant remember session.
        }
    }

    setcookie(
        "remember_token",
        "",
        [
            'expires' => time() - 3600,
            'path' => '/',
            'secure' => true,
            'httponly' => true,
            'samesite' => 'Strict'
        ]
    );

    setcookie(
        "remember_tenant",
        "",
        [
            'expires' => time() - 3600,
            'path' => '/',
            'secure' => true,
            'httponly' => false,
            'samesite' => 'Strict'
        ]
    );
}
header("X-Frame-Options: SAMEORIGIN");
header("X-Content-Type-Options: nosniff");
header("X-XSS-Protection: 1; mode=block");
if (empty($_SESSION['csrf'])) {
    $_SESSION['csrf'] = bin2hex(random_bytes(32));
}
// ✅ DEFINE THIS (MISSING LINE)
$alreadyLoggedIn = isset($_SESSION['user_id']);

/* LOGIN PROCESS */
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!isset($_POST['csrf']) || $_POST['csrf'] !== $_SESSION['csrf']) {
    die("Invalid request");
}
$tenantId = trim($_POST['tenant_id'] ?? '');
$login    = trim($_POST['username'] ?? '');
$password = $_POST['password'] ?? '';
    

$tenant = null;

if ($tenantId === '') {
    $_SESSION['error'] = "Tenant ID is required.";
    $tenant = null;
} else {
    try {
        $tenant = getTenant($tenantId);

        if (!$tenant) {
    $_SESSION['error'] = "Invalid Tenant ID.";
    $tenant = null;
} elseif ($tenant['access_status'] !== 'Allowed') {
    header(
        'Location: /login/tenant_blocked.php?tenant_id=' .
        urlencode($tenant['tenant_id'])
    );
    exit;
} else {
    $conn = connectTenantDatabase($tenant);
}
    } catch (Throwable $e) {
        $tenant = null;
        $_SESSION['error'] = "Unable to connect to the selected Tenant database.";
    }
}
    if (!isset($_SESSION['login_attempts'])) {
    $_SESSION['login_attempts'] = 0;
    
   
}

if ($_SESSION['login_attempts'] >= 5) {
    die("Too many login attempts. Try again later.");
}

   if (!$tenant) {
    if (empty($_SESSION['error'])) {
        $_SESSION['error'] = "Invalid Tenant.";
    }
} elseif ($login === '' || $password === '') {
    $_SESSION['error'] = "Username / Email and Password are required.";
} else {





        $stmt = $conn->prepare("
            SELECT 
    u.id,
    u.employee_id,
    u.full_name,
    u.username,
    u.email,
    u.password,
    u.status,
    u.rights_type_id,
    rt.name AS role_name
FROM users u
LEFT JOIN rights_types rt ON rt.id = u.rights_type_id
            WHERE username = ? OR email = ?
            LIMIT 1
        ");
        $stmt->bind_param("ss", $login, $login);
        $stmt->execute();
        $result = $stmt->get_result();

        if ($user = $result->fetch_assoc()) {

            if ($user['status'] !== 'Active') {
                $_SESSION['login_attempts']++;
                $_SESSION['error'] = "Your account is inactive.";
            } elseif (!password_verify($password, $user['password'])) {
                require_once __DIR__ . '/includes/audit_log.php';

 addAuditLog(
    'Authentication',
    'FAILED_LOGIN',
    'Invalid password for username: '.$login,
    null,
    null,
    null,
    'FAILED'
);
                $_SESSION['login_attempts']++;
                $_SESSION['error'] = "Invalid Password.";
            } else {
    // LOGIN SUCCESS
session_regenerate_id(true);
$_SESSION['login_attempts'] = 0;
unset($_SESSION['error']);

if (!empty($_POST['remember'])) {

    $token = bin2hex(random_bytes(32));
    $expires = date('Y-m-d H:i:s', time() + (86400 * 7));

    // Delete old remember token
$deleteStmt = $conn->prepare("
    DELETE FROM remember_tokens
    WHERE user_id = ?
");
$deleteStmt->bind_param("i", $user['id']);
$deleteStmt->execute();

// Insert new remember token
$insertStmt = $conn->prepare("
    INSERT INTO remember_tokens (user_id, token, expires_at)
    VALUES (?, ?, ?)
");
$insertStmt->bind_param(
    "iss",
    $user['id'],
    $token,
    $expires
);
$insertStmt->execute();

    setcookie(
        "remember_token",
        $token,
        [
            'expires' => time() + (86400 * 7),
            'path' => '/',
            'secure' => true,
            'httponly' => true,
            'samesite' => 'Strict'
        ]
    );
    setcookie(
    "remember_tenant",
    $tenant['tenant_id'],
    [
        'expires' => time() + (86400 * 7),
        'path' => '/',
        'secure' => true,
        'httponly' => true,
        'samesite' => 'Strict'
    ]
);
}

/* SESSION LOGIN (must always run) */
$_SESSION['user_id']        = $user['id'];
$_SESSION['employee_id']    = $user['employee_id'];
$_SESSION['name']           = $user['full_name'];
$_SESSION['username']       = $user['username'];
$_SESSION['email']          = $user['email'];
$_SESSION['rights_type_id'] = $user['rights_type_id'];
$_SESSION['role_name'] = $user['role_name'];
$_SESSION['tenant_id'] = $tenant['tenant_id'];
$_SESSION['hospital_id'] = $tenant['hospital_id'];
$branchStmt = $conn->prepare("
    SELECT branch_id
    FROM user_branches
    WHERE user_id = ?
    LIMIT 1
");

$branchStmt->bind_param("i", $user['id']);
$branchStmt->execute();

$branchResult = $branchStmt->get_result();

if ($branchRow = $branchResult->fetch_assoc()) {
    $_SESSION['branch_id'] = $branchRow['branch_id'];
}
require_once __DIR__ . '/includes/audit_log.php';

addAuditLog(
    'Authentication',
    'LOGIN',
    'Login Success | '.$user['full_name'].' | '.$user['email']
);

header('Location: /dashboard/index.php');
exit;
     
            }

        } else {

    require_once __DIR__ . '/includes/audit_log.php';

    addAuditLog(
    'Authentication',
    'FAILED_LOGIN',
    'Invalid username/email: '.$login,
    null,
    null,
    null,
    'FAILED'
);

    $_SESSION['login_attempts']++;
    $_SESSION['error'] = "Invalid Username / Email.";
}
    }
}
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <title>EHR Login</title>
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <link rel="stylesheet" href="assets/css/style.css">
    <link rel="icon" href="assets/img/hms_logo.png" sizes="32x32" type="image/png">
    <link rel="icon" href="assets/img/hms_logo.png" sizes="192x192" type="image/png">
    <link rel="apple-touch-icon" href="assets/img/hms_logo.png">
</head>
<body>
<div class="login-wrapper">
    <!-- LEFT PANEL -->
    <div class="left-panel">
        <img src="assets/img/hospital_cartoon.png" alt="Hospital Cartoon" class="hero-img">
        <h1>WebSplend EHR</h1>
        <p class="tagline">Electronic Health Record</p>
    </div>
    <!-- RIGHT PANEL -->
    <div class="right-panel">
        <div class="login-card">
            <img src="assets/img/hms_logo.png" class="logo">
          <?php if ($alreadyLoggedIn): ?>
    <h2>Welcome <br>

        <?= htmlspecialchars($_SESSION['name']); ?>
    </h2>
    <a href="/dashboard/index.php" style="
        display:inline-block;
        background:#1976d2;
        color:#fff;
        padding:12px 20px;
        border-radius:6px;
        text-decoration:none;
        font-size:16px;
    ">
        Go to Dashboard
    </a>
    <br>  <br>  
     <a href="/logout.php" style="
        display:inline-block;
        background:#d32f2f;
        color:#fff;
        padding:12px 20px;
        border-radius:6px;
        text-decoration:none;
        font-size:16px;
    ">
        Logout
    </a>
<?php else: ?>
    <h3>Login to WebSplend EHR</h3><br>
    <?php if (!empty($_SESSION['error'])): ?>
        <div class="login-error">
            <?= htmlspecialchars($_SESSION['error']) ?>
        </div>
    <?php unset($_SESSION['error']); endif; ?>
    <form method="post">
    <input type="hidden" name="csrf" value="<?= $_SESSION['csrf'] ?>">

    <div class="form-group">
        <input
            type="text"
            name="tenant_id"
            placeholder="Tenant ID"
            required
            autocomplete="off"
        >
    </div>

    <div class="form-group">
        <input
            type="text"
            name="username"
            placeholder="Username / Email"
            required
            autocomplete="username"
        >
    </div>
        <div class="form-group password-group">
            <input type="password" name="password" id="password" placeholder="Password" required>
            <span class="toggle-password" onclick="togglePassword()">👁</span>
        </div>
        <button type="submit">Login</button>
        <div class="extra">
            <label class="remember-box">
    <input type="checkbox" name="remember">
    <span>Remember me</span>
</label>
            <a href="javascript:void(0)" onclick="openForgot()">Forgot password?</a>
        </div>
    </form>
<?php endif; ?>
         <div class="android-box">
    <a href="assets/apk/hms.apk" target="_blank" rel="noopener noreferrer">
        <img src="assets/img/android_logo.png" alt="Android App">
    </a>
</div>
</div>
    </div>
</div>
<!-- Forgot Password Popup Start-->
<div class="fp-overlay" id="fpOverlay">
    <div class="fp-modal">
        <h3>Forgot Password</h3>
        <p id="fpText">Enter your registered email or username</p>

        <input type="text" id="fpInput" placeholder="Email / Username">

        <div class="fp-actions" id="fpActions">
            <button class="fp-btn" onclick="submitForgot()">Submit</button>
            <button class="fp-btn cancel" onclick="closeForgot()">Cancel</button>
        </div>
    </div>
</div>
<!-- Forgot Password Popup End-->

<!-- Starts Eye Button for View Password -->
<script>
function togglePassword() {
    const pwd = document.getElementById('password');
    pwd.type = pwd.type === 'password' ? 'text' : 'password';
}
</script>
<!-- End Script Eye Button for View Password -->

<!-- Starts Forgot Password popup Script-->
<script>
function openForgot() {
    document.getElementById('fpOverlay').style.display = 'flex';
    resetForgotPopup();
}

function closeForgot() {
    document.getElementById('fpOverlay').style.display = 'none';
}

function submitForgot() {
    const val = document.getElementById('fpInput').value.trim();
    const text = document.getElementById('fpText');
    const actions = document.getElementById('fpActions');

    if (!val) {
        text.innerHTML = "Please enter email or username";
        text.style.color = "red";
        return;
    }

    /* TEMP – backend will be called later */

    // Success UI
    text.innerHTML = "If the account exists, a password reset link has been sent to your email.";
    text.style.color = "green";

    document.getElementById('fpInput').style.display = "none";
    actions.innerHTML = '<button class="fp-btn" onclick="closeForgot()">Close</button>';
}
function resetForgotPopup() {
    const text = document.getElementById('fpText');
    const input = document.getElementById('fpInput');
    const actions = document.getElementById('fpActions');

    text.innerHTML = "Enter your registered email or username";
    text.style.color = "#555";

    input.value = "";
    input.style.display = "block";

    actions.innerHTML = `
        <button class="fp-btn" onclick="submitForgot()">Submit</button>
        <button class="fp-btn cancel" onclick="closeForgot()">Cancel</button>
    `;
}
</script>
<!-- End Forgot Password popup Script-->
</body>
</html>