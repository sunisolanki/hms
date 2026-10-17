<?php
return '
<!DOCTYPE html>
<html>
<head>
<meta charset="UTF-8">
<title>Patient Portal Credentials</title>
</head>
<body style="margin:0;padding:0;background:#f5f7f9;font-family:Arial,Helvetica,sans-serif;">
<div style="max-width:650px;margin:30px auto;background:#ffffff;border:1px solid #e5e7eb;border-radius:10px;overflow:hidden;">
<div style="padding:20px 25px;background:#099aa7;color:#ffffff;">
<h2 style="margin:0;font-size:22px;">Patient Portal Login Details</h2>
</div>
<div style="padding:30px 25px;color:#333333;">
<p style="font-size:16px;">Dear {{PATIENT_NAME}},</p>
<p style="font-size:15px;line-height:1.6;">
Your patient portal account has been created successfully.
You can use the following credentials to access your hospital services online.
</p>
<table cellpadding="8" cellspacing="0" style="width:100%;border-collapse:collapse;font-size:15px;">
<tr>
<td style="font-weight:bold;border-bottom:1px solid #eeeeee;width:40%;">Hospital ID</td>
<td style="border-bottom:1px solid #eeeeee;">{{HOSPITAL_ID}}</td>
</tr>
<tr>
<td style="font-weight:bold;border-bottom:1px solid #eeeeee;">Username</td>
<td style="border-bottom:1px solid #eeeeee;">{{USERNAME}}</td>
</tr>
<tr>
<td style="font-weight:bold;border-bottom:1px solid #eeeeee;">Password</td>
<td style="border-bottom:1px solid #eeeeee;">{{PASSWORD}}</td>
</tr>
</table>
<p style="font-size:14px;line-height:1.6;margin-top:25px;">
You can use these credentials to log in to the patient portal and access the services available to you.
</p>
<p style="font-size:14px;color:#777777;">
Please keep your login credentials secure.
</p>
<p style="font-size:15px;">
Regards,<br>
Hospital Administration
</p>
</div>
</div>
</body>
</html>
';