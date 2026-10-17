<?php
/**
 * Hospital Welcome Email Template
 * Variables expected:
 * - $hospital_name
 * - $login_link
 * - $help_center_link
 * - $support_email
 * - $support_phone
 * - $website_url
 */

$subject = 'Welcome to WebSplend HMS - Your Healthcare Management Journey Begins Now 🏥';

$message = '
<!DOCTYPE html>
<html>
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Welcome to WebSplend HMS</title>
</head>
<body style="font-family: Arial, sans-serif; line-height: 1.6; color: #333; max-width: 600px; margin: 0 auto;">
    <div style="background: linear-gradient(135deg, #2563eb, #1e40af); padding: 30px; text-align: center; color: white;">
        <h1 style="margin: 0; font-size: 28px;">Welcome to HMS WebSplend!</h1>
    </div>
    
    <div style="padding: 30px; background-color: #f9fafb;">
        <p>Dear '.htmlspecialchars($hospital_name).',</p>
        
        <p>A warm and hearty welcome to <strong>HMS WebSplend</strong>!</p>
        
        <p>We\'re excited to have you on board and grateful you chose us to simplify and elevate your healthcare management. Your registration is complete, and your account is now fully active — designed to help you deliver outstanding patient care with greater ease, accuracy, and efficiency.</p>
        
        <div style="background-color: white; border-left: 4px solid #2563eb; padding: 20px; margin: 25px 0; border-radius: 4px;">
            <h3 style="color: #2563eb; margin-top: 0;">🚀 You\'re Ready to Go!</h3>
            <p><strong>Access Your Dashboard:</strong> <a href="'.$login_link.'" style="color: #2563eb;">Click here to log in</a></p>
            <p><strong>Get Started:</strong> Complete your profile, set up departments, and explore our comprehensive features.</p>
        </div>
        
        <h3 style="color: #1e40af;">📋 Core Features You Can Explore:</h3>
        <ul style="padding-left: 20px;">
            <li><strong>Appointment Scheduling</strong> – Book and manage patient visits seamlessly</li>
            <li><strong>Electronic Health Records (EHR)</strong> – Secure, organized patient histories</li>
            <li><strong>Billing & Invoicing</strong> – Generate bills and track payments</li>
            <li><strong>Inventory & Pharmacy Management</strong></li>
            <li><strong>Reports & Analytics</strong> – Real-time insights for informed decisions</li>
        </ul>
        
        <div style="background-color: #eff6ff; padding: 20px; border-radius: 8px; margin: 25px 0;">
            <h3 style="color: #1e40af; margin-top: 0;">💡 Need Help?</h3>
            <p>We\'re here to support you every step of the way:</p>
            <ul style="padding-left: 20px;">
                <li>📖 <strong>Help Center:</strong> <a href="'.$help_center_link.'" style="color: #2563eb;">Browse guides & tutorials</a></li>
                <li>📧 <strong>Email Support:</strong> '.htmlspecialchars($support_email).'</li>
                <li>📞 <strong>Phone Support:</strong> '.htmlspecialchars($support_phone).'</li>
            </ul>
        </div>
        
        <h3 style="color: #1e40af;">✨ Why Choose WebSplend HMS?</h3>
        <div style="display: grid; grid-template-columns: repeat(auto-fit, minmax(200px, 1fr)); gap: 15px; margin: 20px 0;">
            <div style="background: white; padding: 15px; border-radius: 6px; box-shadow: 0 2px 4px rgba(0,0,0,0.1);">
                <strong style="color: #2563eb;">Easy to Use</strong>
                <p style="margin: 5px 0 0; font-size: 14px;">Intuitive interface, no technical expertise needed</p>
            </div>
            <div style="background: white; padding: 15px; border-radius: 6px; box-shadow: 0 2px 4px rgba(0,0,0,0.1);">
                <strong style="color: #2563eb;">All-in-One</strong>
                <p style="margin: 5px 0 0; font-size: 14px;">Complete solution from front desk to discharge</p>
            </div>
            <div style="background: white; padding: 15px; border-radius: 6px; box-shadow: 0 2px 4px rgba(0,0,0,0.1);">
                <strong style="color: #2563eb;">Secure & Cloud-Based</strong>
                <p style="margin: 5px 0 0; font-size: 14px;">Access anytime, anywhere with top security</p>
            </div>
        </div>
        
        <div style="text-align: center; margin: 30px 0; padding: 20px; background-color: #dcfce7; border-radius: 8px;">
            <p style="margin: 0;"><strong>Ready to begin?</strong></p>
            <a href="'.$login_link.'" style="display: inline-block; background-color: #2563eb; color: white; padding: 12px 30px; text-decoration: none; border-radius: 6px; margin-top: 10px; font-weight: bold;">Launch Your Dashboard →</a>
        </div>
        
        <p>Thank you for trusting us with your journey to better healthcare administration.</p>
        
        <p>Here\'s to a seamless, productive, and successful partnership!</p>
        
        <p>
            Best regards,<br>
            <strong>The WebSplend Team</strong><br>
            <a href="'.$website_url.'" style="color: #2563eb;">'.$website_url.'</a>
        </p>
        
        <hr style="border: none; border-top: 1px solid #e5e7eb; margin: 30px 0;">
        
        <p style="font-size: 12px; color: #6b7280;">
            You\'re receiving this email because you recently registered on HMS WebSplend.<br>
            Stay tuned for helpful tips and updates to maximize your experience!
        </p>
    </div>
</body>
</html>';

return [
    'subject' => $subject,
    'message' => $message
];