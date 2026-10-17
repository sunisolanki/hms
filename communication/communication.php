<?php
session_start();

?>

<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
    <title>Notification Configuration</title>

   <meta name="viewport" content="width=device-width, initial-scale=1">
 <!-- MAIN CSS -->
    <link rel="stylesheet" href="/assets/css/style.css">

    <!-- FAVICON -->
    <link rel="icon" type="image/png" href="/assets/img/hms_logo.png">
    <link rel="apple-touch-icon" href="/assets/img/hms_logo.png">

</head>

<?php require $_SERVER['DOCUMENT_ROOT'] . '/includes/header.php'; ?>
<body>

<!-- MAIN CONTENT -->
<div>
    <div class="page-wrapper">
        <div class="top-bar">
            <div>
                <h2 class="settings-title">Notification Configuration</h2>
                <div class="breadcrumb">
                    <span>Home</span> › <span>Settings</span> › Notification
                </div>
            </div>
        </div>

        <div class="settings-wrapper">
            <div class="settings-grid">

                <!-- WhatsApp -->
                <a href="/communication/whatsapp/whatsapp_settings.php" class="settings-card">
                    <svg class="settings-icon" viewBox="0 0 24 24">
                        <path d="M12 2a10 10 0 0 0-8.9 14.5L2 22l5.6-1.1A10 10 0 1 0 12 2z"/>
                    </svg>
                    <h4>WhatsApp</h4>
                    <p>
                        Configure WhatsApp API, templates,
                        delivery rules, and message formats.
                    </p>
                </a>

                <!-- SMS -->
                <a href="/communication/sms/sms_settings.php" class="settings-card">
                    <svg class="settings-icon" viewBox="0 0 24 24">
                        <path d="M20 2H4a2 2 0 0 0-2 2v16l4-4h14a2 2 0 0 0 2-2V4a2 2 0 0 0-2-2z"/>
                    </svg>
                    <h4>SMS</h4>
                    <p>
                        Manage SMS gateways, sender IDs,
                        templates, and usage limits.
                    </p>
                </a>

                <!-- Email -->
                <a href="/communication/email_settings.php" class="settings-card">
                    <svg class="settings-icon" viewBox="0 0 24 24">
                        <path d="M20 4H4a2 2 0 0 0-2 2v12a2 2 0 0 0 2 2h16a2 2 0 0 0 2-2V6a2 2 0 0 0-2-2z"/>
                    </svg>
                    <h4>Email</h4>
                    <p>
                        Configure SMTP, email templates,
                        branding, and delivery options.
                    </p>
                </a>

            </div>
        </div>

    </div>
</div>

</body>
</html>
