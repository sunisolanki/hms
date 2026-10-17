<?php
session_start();
require $_SERVER['DOCUMENT_ROOT'].'/includes/db.php';
require $_SERVER['DOCUMENT_ROOT'].'/vendor/tcpdf/TCPDF-main/tcpdf.php';
$appointment_id = (int)($_GET['id'] ?? 0);
if ($appointment_id <= 0) {
    die('Invalid appointment ID');
}
/* =========================================================
   FETCH APPOINTMENT
========================================================= */
$stmt = $conn->prepare("
    SELECT
        a.*,
        c.name AS city_name,
        dm.department_name,
        CASE
            WHEN a.type = 'doctor' THEN u.full_name
            WHEN a.type = 'service' THEN sm.service_name
            ELSE '-'
        END AS entity_name
    FROM appointments a
    LEFT JOIN cities c
        ON c.id = a.city_id
    LEFT JOIN department_master dm
        ON dm.id = a.department_id
    LEFT JOIN users u
        ON u.id = a.entity_id
       AND a.type = 'doctor'
    LEFT JOIN service_master sm
        ON sm.id = a.entity_id
       AND a.type = 'service'
    WHERE a.id = ?
    LIMIT 1
");
$stmt->bind_param("i", $appointment_id);
$stmt->execute();
$result = $stmt->get_result();
$r = $result->fetch_assoc();
$stmt->close();
if (!$r) {
    die('Appointment not found');
}
/* =========================================================
   FETCH HOSPITAL
========================================================= */
$hospital = [];
$hospitalResult = $conn->query("
    SELECT *
    FROM hospital_master
    ORDER BY id ASC
    LIMIT 1
");
if ($hospitalResult && $hospitalResult->num_rows > 0) {
    $hospital = $hospitalResult->fetch_assoc();
}
/* =========================================================
   SAFE VALUE HELPER
========================================================= */
function pdfValue($value, $default = '-')
{
    if ($value === null || trim((string)$value) === '') {
        return $default;
    }
    return htmlspecialchars(
        (string)$value,
        ENT_QUOTES,
        'UTF-8'
    );
}
/* =========================================================
   HOSPITAL DETAILS
========================================================= */
$hospitalName =
    $hospital['hospital_name']
    ?? 'Hospital';
$directorName =
    $hospital['director_name']
    ?? '';
$hospitalAddress =
    $hospital['address']
    ?? '';
$landmark =
    $hospital['landmark']
    ?? '';
$country =
    $hospital['country']
    ?? '';
$state =
    $hospital['state']
    ?? '';
$district =
    $hospital['district']
    ?? '';
$hospitalCity =
    $hospital['city']
    ?? '';
$pincode =
    $hospital['pincode']
    ?? '';
$mobile =
    $hospital['mobile']
    ?? '';
$phone =
    $hospital['phone']
    ?? '';
$email =
    $hospital['email']
    ?? '';
$website =
    $hospital['website']
    ?? '';
$pan =
    $hospital['pan_no']
    ?? '';
$tin =
    $hospital['tin_no']
    ?? '';
$hospitalCode =
    $hospital['hospital_code']
    ?? '';
/* =========================================================
   HOSPITAL ADDRESS
========================================================= */
$addressParts = [];
foreach ([
    $hospitalAddress,
    $landmark,
    $hospitalCity,
    $district,
    $state,
    $country,
    $pincode
] as $part) {

    if (
        $part !== null &&
        trim((string)$part) !== ''
    ) {
        $addressParts[] =
            trim((string)$part);
    }
}
$fullHospitalAddress =
    implode(', ', $addressParts);
/* =========================================================
   HOSPITAL CONTACT
========================================================= */
$contactParts = [];
if ($mobile !== '') {
    $contactParts[] =
        'Mobile: '.pdfValue($mobile);
}
if ($phone !== '') {
    $contactParts[] =
        'Phone: '.pdfValue($phone);
}
if ($email !== '') {
    $contactParts[] =
        'Email: '.pdfValue($email);
}
if ($website !== '') {
    $contactParts[] =
        'Website: '.pdfValue($website);
}
$hospitalContact =
    implode(
        ' &nbsp;&nbsp; | &nbsp;&nbsp; ',
        $contactParts
    );
/* =========================================================
   LOGO
========================================================= */
$logoPath =
    $_SERVER['DOCUMENT_ROOT'] .
    '/assets/img/hms_logo.png';
$hasLogo =
    file_exists($logoPath);
/* =========================================================
   APPOINTMENT VALUES
========================================================= */
$appointmentCode =
    pdfValue($r['appointment_code']);
$appointmentDate =
    !empty($r['appointment_date'])
        ? date(
            'd M Y',
            strtotime($r['appointment_date'])
        )
        : '-';

$appointmentTime =
    pdfValue($r['appointment_time']);
$patientName =
    trim(
        ($r['title'] ?? '').' '.
        ($r['first_name'] ?? '').' '.
        ($r['last_name'] ?? '')
    );
$patientName =
    pdfValue($patientName);
$doctorService =
    pdfValue($r['entity_name']);
$department =
    pdfValue($r['department_name']);
$type =
    strtoupper(
        pdfValue($r['type'])
    );
    $consultationMode =
    (($r['consultation_mode'] ?? 'PHYSICAL') === 'TELEMEDICINE')
        ? 'Telemedicine'
        : 'Physical';
$status =
    pdfValue($r['status']);
$gender =
    pdfValue($r['sex']);
$marital =
    pdfValue($r['marital_status']);
$guardian =
    pdfValue($r['guardian_name']);
$mobilePatient =
    pdfValue($r['mobile']);
$phonePatient =
    pdfValue($r['phone']);
$emailPatient =
    pdfValue($r['email']);
$aadhaar =
    pdfValue($r['aadhaar']);
$dob =
    !empty($r['dob'])
        ? date(
            'd M Y',
            strtotime($r['dob'])
        )
        : '-';
$age =
    trim(
        ($r['age_y'] ?? 0).'Y '.
        ($r['age_m'] ?? 0).'M '.
        ($r['age_d'] ?? 0).'D'
    );
$city =
    pdfValue($r['city_name']);
$pincodePatient =
    pdfValue($r['pincode']);
$address =
    pdfValue($r['address']);
$remarks =
    pdfValue($r['remarks']);
$fee =
    number_format(
        (float)($r['consultation_fee'] ?? 0),
        2
    );
$paymentMode =
    pdfValue($r['payment_mode']);
$paid =
    number_format(
        (float)($r['paid_amount'] ?? 0),
        2
    );
$balance =
    number_format(
        (float)($r['balance_amount'] ?? 0),
        2
    );
/* =========================================================
   TCPDF
========================================================= */
$pdf = new TCPDF(
    'P',
    'mm',
    'A4',
    true,
    'UTF-8',
    false
);
$pdf->SetCreator('WebSplend HMS');
$pdf->SetAuthor($hospitalName);
$pdf->SetTitle(
    'Appointment Slip - '.$appointmentCode
);
$pdf->SetSubject(
    'Hospital Appointment'
);
$pdf->setPrintHeader(false);
$pdf->setPrintFooter(false);
$pdf->SetMargins(
    10,
    10,
    10
);

$pdf->SetAutoPageBreak(
    true,
    13
);
$pdf->AddPage();
/* =========================================================
   PDF HTML - MONOCHROME DESIGN
========================================================= */
$html = '
<style>
/* =====================================================
   GLOBAL
===================================================== */
body {
    font-family: dejavusans;
    color: #222222;
    font-size: 9px;
}
table {
    border-collapse: collapse;
}
.hospital-header{
    width:100%;
    padding-bottom:2px;
    border-bottom:1.5px solid #2563eb;
}
.logo-cell{
    width:13%;
    text-align:left;
    vertical-align:middle;
}
.logo{
    width:48px;
    height:auto;
}
.hospital-cell{
    width:87%;
    text-align:center;
    vertical-align:middle;
}
.hospital-name{
    font-size:20px;
    font-weight:bold;
    color:#123b72;
}
.hospital-address{
    color:#123b72;
}
.hospital-contact{
    font-size:8px;
    color:#123b72;
}
/* =====================================================
   DOCUMENT TITLE
===================================================== */
.document-title{
    width:100%;
    padding:0;
    margin:0;
    text-align:center;
    font-size:15px;
    font-weight:bold;
    line-height:15px;
    color:#111111;
}
/* =====================================================
   APPOINTMENT SUMMARY
===================================================== */

.summary-box {
    margin-top: 5px;
    width: 100%;
    border: 1px solid #999999;
}

.summary-label {
    font-size: 10px;
    color: #666666;
    text-transform: uppercase;
}

.summary-value {
    margin-top: 2px;
    font-size: 10px;
    font-weight: bold;
    color: #111111;
}
/* =====================================================
   SECTION TITLE
===================================================== */
.section-title{
    margin:0;
    padding-top:3px;
    padding-right:8px;
    padding-bottom:3px;
    padding-left:12px;
    background-color:#222222;
    color:#ffffff;
    font-size:9px;
    font-weight:bold;
    line-height:10px;
    letter-spacing:.4px;
}
/* =====================================================
   DETAIL TABLE
===================================================== */
.details {
    width: 100%;
    border: 2px solid #aaaaaa;
        }
.details-label {
    width: 27%;
    background-color: #eeeeee;
    color: #444444;
    font-size: 10px;
    font-weight: bold;
    border-bottom: 1px solid #cccccc;
    }
.details-value {
    width: 23%;
    color: #222222;
    font-size: 10px;
    border-bottom: 1px solid #cccccc;
}
.details-value-wide {
    color: #222222;
    font-size: 8.5px;
    border-bottom: 1px solid #cccccc;
}
/* =====================================================
   PAYMENT
===================================================== */
.payment-box {
    width: 100%;
    border: 1px solid #999999;
}
.payment-label {
    background-color: #eeeeee;
    color: #444444;
    font-size: 10px;
    font-weight: bold;
}
.payment-value {
    font-size: 10px;
    font-weight: bold;
    color: #222222;
}
.balance-label {
    background-color: #dddddd;
    color: #111111;
    font-size: 8px;
    font-weight: bold;
}

.balance-value {
    font-size: 11px;
    font-weight: bold;
    color: #111111;
}
/* =====================================================
   REMARKS
===================================================== */
.remarks-box {
    width: 100%;
    border: 1px solid #aaaaaa;
}

.remarks-text {
    font-size: 10px;
    color: #333333;
}

/* =====================================================
   FOOTER
===================================================== */

.footer-note {
    margin-top: 13px;
    text-align: center;
    font-size: 7.5px;
    font-weight: bold;
    color: #444444;
}
.footer-contact {
    margin-top: 3px;
    text-align: center;
    font-size: 7px;
    color: #777777;
    
}
.footer-line {
    margin-top: 8px;
    border-top: 1px solid #cccccc;
}
</style>
<!-- =====================================================
     HOSPITAL HEADER
===================================================== -->
<table
    class="hospital-header"
>
<tr>
<td class="logo-cell">';
if ($hasLogo) {
    $html .= '
        <img
            src="'.$logoPath.'"
            class="logo"
        />';
}
$html .= '
</td>
<td class="hospital-cell">
<div class="hospital-name">
    '.pdfValue($hospitalName).'
</div>
';
if ($fullHospitalAddress !== '') {
    $html .= '
    <div class="hospital-address">
        '.pdfValue($fullHospitalAddress).'
    </div>
    ';
}
if ($hospitalContact !== '') {
    $html .= '
    <div class="hospital-contact">
        '.$hospitalContact.'
    </div>
    ';
}
$html .= '
</td>
</tr>
</table>
<!-- =====================================================
     TITLE
===================================================== -->
<table
    width="100%"
    cellpadding="0"
    cellspacing="0"
    border="0"
>
<tr>
<td
    align="center"
    style="
        padding-top:0;
        padding-bottom:2px;
        font-size:15px;
        font-weight:bold;
        line-height:15px;
        color:#111111;
    "
>
    APPOINTMENT SLIP
</td>
</tr>
</table>

<table
    width="100%"
    cellpadding="0"
    cellspacing="0"
    border="0"
>
<tr>
<td
    style="
        padding-top:3px;
        padding-bottom:3px;
        padding-left:12px;
        background-color:#222222;
        color:#ffffff;
        font-size:9px;
        font-weight:bold;
        line-height:10px;
    "
>
    APPOINTMENT DETAILS
</td>
</tr>
</table>
<table
    class="details"
    cellpadding="6"
    cellspacing="0"
    width="100%"
>
<tr>
<td class="details-label">
Appointment ID
</td>
<td class="details-value">
'.$appointmentCode.'
</td>
<td class="details-label">
Appointment Date
</td>
<td class="details-value">
'.$appointmentDate.'
</td>
</tr>

<tr>
<td class="details-label">
Appointment Time
</td>
<td class="details-value">
'.$appointmentTime.'
</td>
<td class="details-label">
Status
</td>
<td class="details-value">
'.$status.'
</td>
</tr>

<tr>
<td class="details-label">
Appointment Type
</td>
<td class="details-value">
'.$type.'
</td>
<td class="details-label">
Consultation Mode
</td>
<td class="details-value">
'.$consultationMode.'
</td>
</tr>

<tr>
<td class="details-label">
Department
</td>
<td class="details-value">
'.$department.'
</td>
<td class="details-label">
Doctor / Service
</td>
<td class="details-value">
'.$doctorService.'
</td>
</tr>

<tr>
<td class="details-label">
Appointment Code
</td>
<td class="details-value">
'.$appointmentCode.'
</td>
<td class="details-label">
&nbsp;
</td>
<td class="details-value">
&nbsp;
</td>
</tr>

</table>
<!-- =====================================================
     PATIENT DETAILS
===================================================== -->
<div class="section-title">
PATIENT DETAILS
</div>
<table
    class="details"
    cellpadding="6"
    cellspacing="0"
    width="100%"
>
<tr>
<td class="details-label">
Patient Name
</td>

<td class="details-value">
<b>'.$patientName.'</b>
</td>

<td class="details-label">
Gender
</td>

<td class="details-value">
'.$gender.'
</td>

</tr>

<tr>

<td class="details-label">
Date of Birth
</td>

<td class="details-value">
'.$dob.'
</td>

<td class="details-label">
Age
</td>

<td class="details-value">
'.$age.'
</td>

</tr>

<tr>

<td class="details-label">
Marital Status
</td>

<td class="details-value">
'.$marital.'
</td>

<td class="details-label">
Guardian
</td>

<td class="details-value">
'.$guardian.'
</td>

</tr>

<tr>

<td class="details-label">
Mobile
</td>

<td class="details-value">
'.$mobilePatient.'
</td>

<td class="details-label">
Phone
</td>

<td class="details-value">
'.$phonePatient.'
</td>

</tr>

<tr>

<td class="details-label">
Email
</td>

<td class="details-value">
'.$emailPatient.'
</td>

<td class="details-label">
Aadhaar
</td>

<td class="details-value">
'.$aadhaar.'
</td>

</tr>

<tr>

<td class="details-label">
City
</td>

<td class="details-value">
'.$city.'
</td>

<td class="details-label">
Pincode
</td>

<td class="details-value">
'.$pincodePatient.'
</td>

</tr>

<tr>

<td class="details-label">
Address
</td>

<td
    colspan="3"
    class="details-value-wide"
>
'.$address.'
</td>

</tr>

</table>


<!-- =====================================================
     PAYMENT DETAILS
===================================================== -->
<div class="section-title">
PAYMENT DETAILS
</div>
<table
    class="payment-box"
    cellpadding="7"
    cellspacing="0"
    width="100%"
>
<tr>
<td
    width="25%"
    class="payment-label"
>
Consultation Fee
</td>

<td
    width="25%"
    class="payment-value"
>
 '.$fee.'
</td>

<td
    width="25%"
    class="payment-label"
>
Payment Mode
</td>

<td
    width="25%"
    class="payment-value"
>
'.$paymentMode.'
</td>

</tr>

<tr>

<td class="payment-label">
Paid Amount
</td>

<td class="payment-value">
 '.$paid.'
</td>

<td class="balance-label">
Balance Amount
</td>

<td class="balance-value">
 '.$balance.'
</td>

</tr>

</table>
';

if ($remarks !== '-') {

    $html .= '

    <!-- =================================================
         REMARKS
    ================================================= -->

    <div class="section-title">
        REMARKS
    </div>
    <table
        class="remarks-box"
        cellpadding="7"
        cellspacing="0"
        width="100%"
    >
    <tr>
    <td class="remarks-text">
        '.$remarks.'
    </td>
    </tr>
    </table>
    ';
}
$html .= '
<!-- =====================================================
     FOOTER
===================================================== -->
<div class="footer-line">
</div>
<div class="footer-note">
    Please carry this appointment slip when visiting the hospital.
</div>
<div class="footer-contact">
    This is a computer-generated appointment slip.
';
$html .= '
</div>
';
/* =========================================================
   WRITE PDF
========================================================= */
$pdf->writeHTML(
    $html,
    true,
    false,
    true,
    false,
    ''
);
/* =========================================================
   OUTPUT
========================================================= */
$pdf->Output(
    'Appointment_'.$appointmentCode.'.pdf',
    'I'
);