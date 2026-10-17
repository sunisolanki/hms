<?php
return [
    'subject' => 'Your Login Credentials',
    'message' => "
        <p>Hello {$full_name},</p>

        <p>Your account has been created.</p>

        <p>
            <b>Username:</b> {$username}<br>
            <b>Password:</b> {$emailPassword}
        </p>

        <p>Please change your password after login.</p>

        <p>Regards,<br>Team WebSplend</p>
    "
];
