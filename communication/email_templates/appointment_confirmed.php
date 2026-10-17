<?php
return "
Hello {{PATIENT_NAME}},

✅ **Your appointment has been successfully confirmed!**

We’re looking forward to seeing you. Below are your appointment details:

────────────────────────────
🗓 **Appointment Summary**
────────────────────────────

**Appointment ID:** {{APPOINTMENT_CODE}}
**Date:** {{DATE}}
**Time:** {{TIME}}
**Doctor / Service:** {{ENTITY}}
**Consultation Fee:** ₹{{FEE}}


────────────────────────────

📍 **Please arrive 10 minutes early** and bring any relevant medical documents or reports.

If you need to reschedule or have any questions, feel free to contact us.

Warm regards,

";