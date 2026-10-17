<?php
if (session_status() === PHP_SESSION_NONE) {
    session_start();
}
if (!isset($_SESSION['user_id'])) {
    header('Location: /index.php');
    exit;
}
?>
<link rel="stylesheet"
      href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.5.1/css/all.min.css">

<style>
    /* HEADER RIGHT */
.header-right{
    display:flex;
    align-items:center;
    gap:15px;
}
/* USER BOX */
.user-box{
    position:relative;
    display:flex;
    align-items:center;
    background:#fff;
    padding:2px 2px;
    border-radius:50px;
    cursor:pointer;
    box-shadow:0 2px 10px rgba(0,0,0,0.08);
    transition:0.2s;
}
.user-box:hover{
    box-shadow:0 4px 15px rgba(0,0,0,0.15);
    transform:translateY(-1px);
}
/* AVATAR */
.header-avatar,
.dropdown-avatar{
    width:30px;
    height:30px;
    border-radius:50%;
    background:linear-gradient(135deg,#4dabf7,#845ef7);
    color:#fff;
    display:flex;
    align-items:center;
    justify-content:center;
    font-weight:700;
    flex-shrink:0;
}
/* USER INFO */
.header-user-info{
    display:flex;
    flex-direction:column;
    line-height:1.2;
}
.user-name{
    font-size:14px;
    font-weight:600;
    color:#222;
}
.user-role{
    font-size:11px;
    color:#777;
}
/* DROPDOWN ICON */
.dropdown-icon{
    color:#666;
    font-size:12px;
    margin-right:2px;
}
/* DROPDOWN */
.user-dropdown{
    position:absolute;
    top:60px;
    right:0;
    width:300px;
    background:#fff;
    border-radius:14px;
    box-shadow:0 12px 35px rgba(0,0,0,0.15);
    overflow:hidden;
    display:none;
    z-index:9999;
    animation:dropdownFade 0.18s ease;
}
.dropdown-user-top{
    display:flex;
    align-items:center;
    gap:12px;
    padding:18px;
    background:#f8fbff;
    border-bottom:1px solid #eee;
}
.dropdown-user-name{
    font-size:15px;
    font-weight:700;
    color:#222;
}
.dropdown-user-email{
    font-size:12px;
    color:#666;
    margin-top:3px;
    word-break:break-all;
}
.dropdown-user-details{
    flex:1;
}
/* DROPDOWN LINKS */
.user-dropdown a{
    display:flex;
    align-items:center;
    gap:10px;
    padding:12px 16px;
    text-decoration:none;
    color:#333;
    font-size:14px;
    transition:0.2s;
}
.user-dropdown a:hover{
    background:#f5f9ff;
    color:#1a73e8;
}
.user-dropdown a i{
    width:18px;
    text-align:center;
}
/* VERSION */

.dropdown-version{
    padding:12px 18px;
    font-size:12px;
    color:#777;
    border-top:1px solid #eee;
    background:#fafafa;
}
/* SUPPORT POPUP */

.support-modal{
    display:none;
    position:fixed;
    z-index:999999;
    left:0;
    top:0;
    width:100%;
    height:100vh;
    overflow:auto;
    background:rgba(0,0,0,0.55);
    backdrop-filter:blur(3px);
}
.support-box{
    width:420px;
    max-width:92%;
    background:#fff;
    border-radius:20px;
    margin:40px auto;
    overflow:hidden;
    position:relative;
    animation:supportPopup 0.22s ease;
    box-shadow:0 20px 60px rgba(0,0,0,0.25);
}

@keyframes supportPopup{

    from{
        opacity:0;
        transform:translateY(-20px) scale(0.96);
    }

    to{
        opacity:1;
        transform:translateY(0) scale(1);
    }
}

.support-close{
    position:absolute;
    right:18px;
    top:12px;
    font-size:26px;
    cursor:pointer;
    color:red;
    z-index:5;
}

.support-top{
    background:linear-gradient(135deg,#4dabf7,#845ef7);
    padding:10px 10px;
    text-align:center;
    color:#fff;
}

.support-icon{
    width:70px;
    height:70px;
    border-radius:50%;
    background:rgba(255,255,255,0.2);
    margin:0 auto 15px;
    display:flex;
    align-items:center;
    justify-content:center;
    font-size:28px;
}

.support-top h2{
    margin:0;
}

.support-top p{
    margin-top:8px;
    opacity:0.9;
}

.support-items{
    padding:18px;
}

.support-item{
    display:flex;
    align-items:center;
    gap:15px;
    padding:14px;
    border-radius:12px;
    text-decoration:none;
    transition:0.2s;
    margin-bottom:12px;
    border:1px solid #625e5e;
}
.support-item:hover{
    background:#f8fbff;
}
.support-item-icon{
    width:50px;
    height:50px;
    border-radius:12px;
    display:flex;
    align-items:center;
    justify-content:center;
    color:#fff;
    font-size:18px;
}
.phone{
    background:#2563eb;
}
.whatsapp{
    background:#22c55e;
}
.email{
    background:#f59e0b;
}
.website{
    background:#8b5cf6;
}
.support-label{
    font-size:12px;
    color:#777;
}
.support-value{
    font-size:15px;
    font-weight:600;
    color:#222;
    margin-top:3px;
}
.support-footer{
    padding:0 18px 20px;
}

/* ANIMATION */
@keyframes dropdownFade{
    from{
        opacity:0;
        transform:translateY(-6px);
    }
    to{
        opacity:1;
        transform:translateY(0);
    }
}
/* MOBILE */
@media(max-width:768px){
    .header-user-info{
        display:none;
    }
    .user-box{
        padding:5px 8px;
    }
    .header-avatar{
        width:38px;
        height:38px;
        font-size:14px;
    }
}
</style>
<div class="hms-header">
    <!-- LEFT : LOGO -->
  <div class="header-left">
    <a href="/dashboard/index.php" style="display:flex; align-items:center; text-decoration:none;">
        <img src="/assets/img/hms_logo.png" class="header-logo logo-outline" alt="WebSplend HMS"> &nbsp;
        <span class="header-title">WebSplend EHR</span>
    </a>
</div>
    <!-- RIGHT -->
<?php
$hms_version = "v1.0.0";
?>
<div class="header-right">
    <!-- USER PROFILE -->
    <div class="user-box" onclick="toggleUserMenu(event)">
        <!-- PROFILE AVATAR -->
        <div class="header-avatar">
            <?= strtoupper(substr($_SESSION['name'] ?? 'U',0,1)) ?>
        </div>
        <!-- USER INFO -->
        <div class="header-user-info">
            <span class="user-name">
                <?= htmlspecialchars($_SESSION['name']); ?>
            </span>
        </div>
        <!-- DROPDOWN ICON -->
        <span class="dropdown-icon">
            <i class="fas fa-chevron-down"></i>
        </span>
        <!-- DROPDOWN -->
        <div class="user-dropdown" id="userDropdown">
            <!-- TOP USER INFO -->
            <div class="dropdown-user-top">
                <div class="dropdown-avatar">
                    <?= strtoupper(substr($_SESSION['name'] ?? 'U',0,1)) ?>
                </div>
                <div class="dropdown-user-details">
                    <div class="dropdown-user-name">
                        <?= htmlspecialchars($_SESSION['name']); ?>
                    </div>
                    <div class="dropdown-user-email">
                        <?= htmlspecialchars($_SESSION['email'] ?? 'user@hms.com'); ?>
                    </div>
                </div>
            </div>
<!-- MENU -->
<a href="/profile/profile.php">
    <i class="fas fa-user-circle"
       style="color:#4f46e5;"></i>
    My Profile
</a>
<a href="/settings/settings.php">
    <i class="fas fa-cog"
       style="color:#0ea5e9;"></i>
    Settings
</a>
<a href="javascript:void(0)"
   onclick="openSupportPopup()">
    <i class="fas fa-headset"
       style="color:#f59e0b;"></i>
    Contact Support
</a>
<a href="javascript:void(0)"
   onclick="openTicketPopup()">
    <i class="fas fa-ticket-alt"
       style="color:#10b981;"></i>
    Add Ticket
</a>

<a href="/modules/tickets/Ticket_list.php">
    <i class="fas fa-list-check"
       style="color:#10b981;"></i>
    Support Tickets List
</a>

<a href="/swbilling/index.php">
    <i class="fas fa-file-invoice-dollar"
       style="color:#ef4444;"></i>
    Billing
</a>
<a href="https://websplend.com"
   target="_blank">
    <i class="fas fa-box-open"
       style="color:#8b5cf6;"></i>
    More Products
</a>
<a href="/equipment/order_equipment.php">
    <i class="fas fa-stethoscope"
       style="color:#06b6d4;"></i>
    Order Equipment
</a>
<a href="/medicine/order_medicine.php">
    <i class="fas fa-capsules"
       style="color:#ec4899;"></i>
    Order Medicine
</a>
<a href="/stationary/order_stationary.php">
    <i class="fas fa-pencil-ruler"
       style="color:#f97316;"></i>
    Order Stationary
</a>
<a href="/logout.php" class="logout-link">
    <i class="fas fa-sign-out-alt"
       style="color:#dc2626;"></i>
    Logout
</a>            <!-- VERSION -->
            <div class="dropdown-version">
                HMS Version :
                <strong><?= $hms_version ?></strong>
            </div>
        </div>
    </div>
</div>
</div>
<!-- SUPPORT POPUP -->
<div class="support-modal" id="supportModal">
    <div class="support-box">
        <!-- CLOSE -->
        <span class="support-close"
              onclick="closeSupportPopup()">
            &times;
        </span>
        <!-- TOP -->
        <div class="support-top">
            <div class="support-icon">
                <i class="fas fa-headset"></i>
            </div>
            <a>
                Contact Support
            </a>
            <p>
                We are available for your support
            </p>
        </div>
        <!-- ITEMS -->
        <div class="support-items">
            <!-- PHONE -->
            <a href="tel:+918580501114"
               class="support-item">
                <div class="support-item-icon phone">
                    <i class="fas fa-phone-alt"></i>
                </div>
                <div>
                    <div class="support-label">
                        Phone Number
                    </div>
                    <div class="support-value">
                        +91 85805 01114
                    </div>
                </div>
            </a>
            <!-- WHATSAPP -->
            <a href="https://wa.me/918580501114"
               target="_blank"
               class="support-item">
                <div class="support-item-icon whatsapp">
                    <i class="fab fa-whatsapp"></i>
                </div>
                <div>
                    <div class="support-label">
                        WhatsApp
                    </div>
                    <div class="support-value">
                        Chat on WhatsApp
                    </div>
                </div>
            </a>
            <!-- EMAIL -->
            <a href="mailto:info@websplend.com"
               class="support-item">
                <div class="support-item-icon email">
                    <i class="fas fa-envelope"></i>
                </div>
                <div>
                    <div class="support-label">
                        Email ID
                    </div>
                    <div class="support-value">
                        info@websplend.com
                    </div>
                </div>
            </a>
            <!-- WEBSITE -->
            <a href="https://websplend.com"
               target="_blank"
               class="support-item">
                <div class="support-item-icon website">
                    <i class="fas fa-globe"></i>
                </div>
                <div>
                    <div class="support-label">
                        Website
                    </div>
                    <div class="support-value">
                        www.websplend.com
                    </div>
                </div>
            </a>
        </div>
    </div>
</div>
<!-- TICKET POPUP -->
<div class="support-modal" id="ticketModal">
    <div class="support-box"
     style="
        width:950px;
        max-width:95%;
        height:92vh;
        margin:20px auto;
     ">
        <!-- CLOSE -->
        <span class="support-close"
              onclick="closeTicketPopup()">
            &times;
        </span>
        <!-- BODY -->
        <div style="height:calc(92vh - 20px); overflow:auto;">
            <iframe
    src="/modules/tickets/add_ticket.php"
    width="100%"
    height="100%"
    frameborder="0"
    style="
        border:none;
        display:block;
        background:#fff;
    ">
</iframe>
        </div>
    </div>
</div>
<!-- DROPDOWN SCRIPT -->
<script>
function toggleUserMenu(event){
    event.stopPropagation();
    const menu =
        document.getElementById('userDropdown');
    menu.style.display =
        (menu.style.display === 'block')
        ? 'none'
        : 'block';
}
/* TICKET POPUP */
function openTicketPopup(){
    document.getElementById(
        'ticketModal'
    ).style.display = 'block';
}
function closeTicketPopup(){
    document.getElementById(
        'ticketModal'
    ).style.display = 'none';
}
/* SUPPORT POPUP */
function openSupportPopup(){
    document.getElementById(
        'supportModal'
    ).style.display = 'block';
}
function closeSupportPopup(){
    document.getElementById(
        'supportModal'
    ).style.display = 'none';
}
/* OUTSIDE CLICK */
document.addEventListener('click', function(e) {
    const userBox =
        document.querySelector('.user-box');
    if (userBox &&
        !userBox.contains(e.target)) {
        document.getElementById(
            'userDropdown'
        ).style.display = 'none';
    }
    const supportModal =
        document.getElementById(
            'supportModal'
        );
    if(e.target === supportModal){
        closeSupportPopup();
    }
    const ticketModal =
    document.getElementById(
        'ticketModal'
    );

if(e.target === ticketModal){
    closeTicketPopup();
}
});
</script>