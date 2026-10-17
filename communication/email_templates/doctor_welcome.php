<?php
/**
 * Variables available:
 * $doctor_name
 * $hospital_name (optional)
 */

$subject = 'Welcome to WebSplend HMS';

$message = '
<p>Dear Dr. '.htmlspecialchars($doctor_name).',</p>

<p>
Welcome to <strong>WebSplend HMS</strong>!<br>
Your profile has been successfully created in the system.
</p>

<p>
You can now start consulting patients and managing your schedule through the platform.
</p>

<p>
Regards,<br>
Team WebSplend
</p>';

return [
    'subject' => $subject,
    'message' => $message
];
