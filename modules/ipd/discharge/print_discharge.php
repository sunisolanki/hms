<?php
ini_set('display_errors',1);
ini_set('display_startup_errors',1);
error_reporting(E_ALL);

session_start();
require $_SERVER['DOCUMENT_ROOT'].'/includes/db.php';

if(session_status()===PHP_SESSION_NONE){
    session_start();
}

$discharge_id=(int)($_GET['discharge_id'] ?? 0);

if($discharge_id<=0){
    die('Invalid discharge ID.');
}

/* =========================================================
   DISCHARGE SUMMARY
   ========================================================= */

$stmt=$conn->prepare("
    SELECT
        ds.*,
        ia.payment_type,
        ia.admission_date,
        ia.admission_time,
        ia.doctor_id AS admission_doctor_id,
        ia.department_id,
        ia.hospital_id,
        ia.branch_id,
        pm.uhid,
        pm.title,
        pm.first_name,
        pm.last_name,
        pm.sex,
        pm.dob,
        pm.age_y,
        pm.age_m,
        pm.age_d,
        pm.mobile,
        pm.email,
        pm.address,
        pm.pincode,
        pm.aadhaar,
        pm.abha_id,
        hm.hospital_code,
        hm.hospital_name,
        hm.director_name,
        hm.country,
        hm.state,
        hm.district,
        hm.city,
        hm.landmark,
        hm.address AS hospital_address,
        hm.pincode AS hospital_pincode,
        hm.mobile AS hospital_mobile,
        hm.phone AS hospital_phone,
        hm.email AS hospital_email,
        hm.website AS hospital_website,
        hm.gstin,
        hm.logo,
        hm.secondary_logo
    FROM ipd_discharge_summaries ds
    INNER JOIN ipd_admissions ia
        ON ia.id=ds.admission_id
    LEFT JOIN patient_master pm
        ON pm.id=ds.patient_id
    LEFT JOIN hospital_master hm
        ON hm.id=ia.hospital_id
    WHERE ds.id=?
    LIMIT 1
");

if(!$stmt){
    die('Database prepare error: '.$conn->error);
}

$stmt->bind_param("i",$discharge_id);

if(!$stmt->execute()){
    die('Database execute error: '.$stmt->error);
}

$result=$stmt->get_result();
$discharge=$result->fetch_assoc();
$stmt->close();

if(!$discharge){
    die('Discharge summary not found.');
}
/* =========================================================
   PATIENT NAME
   ========================================================= */

$patient_name=trim(
    ($discharge['title'] ?? '').' '.
    ($discharge['first_name'] ?? '').' '.
    ($discharge['last_name'] ?? '')
);

$patient_name=preg_replace('/\s+/',' ',$patient_name);

/* =========================================================
   AGE
   ========================================================= */

$age='';

if((int)$discharge['age_y']>0){
    $age.=(int)$discharge['age_y'].' Y ';
}

if((int)$discharge['age_m']>0){
    $age.=(int)$discharge['age_m'].' M ';
}

if((int)$discharge['age_d']>0){
    $age.=(int)$discharge['age_d'].' D';
}

$age=trim($age);

if($age==='' && !empty($discharge['dob'])){
    $dob=new DateTime($discharge['dob']);
    $today=new DateTime();
    $age=$dob->diff($today)->y.' Y';
}

/* =========================================================
   HOSPITAL ADDRESS
   ========================================================= */

$hospital_address_parts=[];

if(!empty($discharge['hospital_address'])){
    $hospital_address_parts[]=$discharge['hospital_address'];
}

if(!empty($discharge['landmark'])){
    $hospital_address_parts[]=$discharge['landmark'];
}

if(!empty($discharge['city'])){
    $hospital_address_parts[]=$discharge['city'];
}

if(!empty($discharge['district'])){
    $hospital_address_parts[]=$discharge['district'];
}

if(!empty($discharge['state'])){
    $hospital_address_parts[]=$discharge['state'];
}

if(!empty($discharge['hospital_pincode'])){
    $hospital_address_parts[]='- '.$discharge['hospital_pincode'];
}

$hospital_address=implode(', ',$hospital_address_parts);

/* =========================================================
   DATE/TIME
   ========================================================= */

$admission_datetime='';

if(!empty($discharge['admission_date'])){
    $admission_datetime=date(
        'd-m-Y',
        strtotime($discharge['admission_date'])
    );

    if(!empty($discharge['admission_time'])){
        $admission_datetime.=' '.date(
            'h:i A',
            strtotime($discharge['admission_time'])
        );
    }
}

$discharge_datetime='';

if(!empty($discharge['discharge_date'])){
    $discharge_datetime=date(
        'd-m-Y',
        strtotime($discharge['discharge_date'])
    );

    if(!empty($discharge['discharge_time'])){
        $discharge_datetime.=' '.date(
            'h:i A',
            strtotime($discharge['discharge_time'])
        );
    }
}

/* =========================================================
   LENGTH OF STAY
   ========================================================= */

$length_of_stay='';

if(!empty($discharge['admission_date']) && !empty($discharge['discharge_date'])){
    $admission_date_obj=new DateTime($discharge['admission_date']);
    $discharge_date_obj=new DateTime($discharge['discharge_date']);

    $days=$admission_date_obj->diff($discharge_date_obj)->days;

    if($days<1){
        $days=1;
    }

    $length_of_stay=$days.' Day'.($days===1?'':'s');
}

/* =========================================================
   LOGO
   ========================================================= */

$logo_path='';

if(!empty($discharge['logo'])){
    $logo_path='/'.ltrim($discharge['logo'],'/');
}

/* =========================================================
   DISEASES
   ========================================================= */

$diseases=[];

$stmt=$conn->prepare("
    SELECT
        dd.id,
        dd.disease_id,
        dm.disease_name,
        dm.icd_code
    FROM ipd_discharge_diseases dd
    LEFT JOIN disease_master dm
        ON dm.id=dd.disease_id
    WHERE dd.discharge_id=?
    ORDER BY dd.id ASC
");

if($stmt){
    $stmt->bind_param("i",$discharge_id);
    $stmt->execute();
    $result=$stmt->get_result();

    while($row=$result->fetch_assoc()){
        $diseases[]=$row;
    }

    $stmt->close();
}

/* =========================================================
   DOCTORS
   ========================================================= */

$doctors=[];

$stmt=$conn->prepare("
    SELECT
        dd.id,
        dd.doctor_id,
        dm.license_no,
        u.full_name,
        u.employee_id,
        u.email,
        u.mobile_no
    FROM ipd_discharge_doctors dd
    LEFT JOIN doctor_master dm
        ON dm.id=dd.doctor_id
    LEFT JOIN users u
        ON u.id=dm.user_id
    WHERE dd.discharge_id=?
    ORDER BY dd.id ASC
");

if($stmt){
    $stmt->bind_param("i",$discharge_id);
    $stmt->execute();
    $result=$stmt->get_result();

    while($row=$result->fetch_assoc()){
        $doctors[]=$row;
    }

    $stmt->close();
}

/* =========================================================
   SERVICES
   ========================================================= */

$services=[];

$stmt=$conn->prepare("
    SELECT
        ds.id,
        ds.service_id,
        ds.billing_status,
        ds.billing_id,
        sm.category_type,
        sm.service_type,
        sm.service_code,
        sm.service_name,
        sm.amount,
        sm.service_mode
    FROM ipd_discharge_services ds
    LEFT JOIN service_master sm
        ON sm.id=ds.service_id
    WHERE ds.discharge_id=?
    ORDER BY ds.id ASC
");

if($stmt){
    $stmt->bind_param("i",$discharge_id);
    $stmt->execute();
    $result=$stmt->get_result();

    while($row=$result->fetch_assoc()){
        $services[]=$row;
    }

    $stmt->close();
}

/* =========================================================
   MEDICINES
   ========================================================= */

$medicines=[];

$stmt=$conn->prepare("
    SELECT
        dm.id,
        dm.item_id,
        dm.medicine,
        im.item_name,
        dm.frequency,
        dm.meal,
        dm.qty,
        dm.morning,
        dm.noon,
        dm.evening,
        dm.doctor_id,
        dm.stop_medication,
        dm.stop_date,
        dm.comments,
        dm.pharmacy_status,
        dm.dispensed_qty,
        dm.billing_status,
        u.full_name AS doctor_name
    FROM ipd_discharge_medicines dm
    LEFT JOIN item_master im
        ON im.id=dm.item_id
    LEFT JOIN doctor_master dmaster
        ON dmaster.id=dm.doctor_id
    LEFT JOIN users u
        ON u.id=dmaster.user_id
    WHERE dm.discharge_id=?
    ORDER BY dm.id ASC
");

if($stmt){
    $stmt->bind_param("i",$discharge_id);
    $stmt->execute();
    $result=$stmt->get_result();

    while($row=$result->fetch_assoc()){
        $medicines[]=$row;
    }

    $stmt->close();
}

/* =========================================================
   PARAMETER READINGS
   ========================================================= */

$parameter_readings=[];

if(!empty($discharge['parameter_readings'])){

    $decoded_parameters=json_decode(
        $discharge['parameter_readings'],
        true
    );

    if(
        json_last_error()===JSON_ERROR_NONE &&
        is_array($decoded_parameters)
    ){
        $parameter_readings=$decoded_parameters;
    }
}

/* =========================================================
   HELPERS
   ========================================================= */

function h($value){
    return htmlspecialchars(
        (string)$value,
        ENT_QUOTES,
        'UTF-8'
    );
}

function show_text($value){
    if($value===null || trim((string)$value)===''){
        return '';
    }

    return nl2br(
        htmlspecialchars(
            (string)$value,
            ENT_QUOTES,
            'UTF-8'
        )
    );
}

function medicine_dose_text($medicine){

    $parts=[];

    if((float)$medicine['morning']>0){
        $parts[]='Morning: '.rtrim(
            rtrim((string)$medicine['morning'],'0'),
            '.'
        );
    }

    if((float)$medicine['noon']>0){
        $parts[]='Noon: '.rtrim(
            rtrim((string)$medicine['noon'],'0'),
            '.'
        );
    }

    if((float)$medicine['evening']>0){
        $parts[]='Evening: '.rtrim(
            rtrim((string)$medicine['evening'],'0'),
            '.'
        );
    }

    return implode(' | ',$parts);
}

?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<title>Discharge Summary - <?=h($discharge['ipd_no'])?></title>

<style>
*{
    box-sizing:border-box;
}

body{
    margin:0;
    padding:20px;
    font-family:Arial,Helvetica,sans-serif;
    color:#222;
    background:#f5f5f5;
    font-size:13px;
}

.print-container{
    max-width:1000px;
    margin:0 auto;
    background:#fff;
    padding:25px;
}

.no-print{
    text-align:center;
    margin-bottom:15px;
}

.print-button{
    border:none;
    background:#2563eb;
    color:#fff;
    padding:10px 20px;
    border-radius:5px;
    cursor:pointer;
    font-size:14px;
}

.hospital-header{
    display:flex;
    align-items:center;
    gap:20px;
    border-bottom:2px solid #222;
    padding-bottom:15px;
    margin-bottom:15px;
}

.hospital-logo{
    width:90px;
    height:90px;
    object-fit:contain;
    flex:0 0 90px;
}

.hospital-info{
    flex:1;
    text-align:center;
}

.hospital-name{
    font-size:24px;
    font-weight:700;
    margin-bottom:5px;
}

.hospital-code{
    font-size:12px;
    color:#555;
    margin-bottom:5px;
}

.hospital-address{
    font-size:12px;
    line-height:1.5;
}

.document-title{
    text-align:center;
    font-size:20px;
    font-weight:700;
    margin:18px 0;
    text-transform:uppercase;
}

.info-table{
    width:100%;
    border-collapse:collapse;
    margin-bottom:18px;
}

.info-table th,
.info-table td{
    border:1px solid #999;
    padding:7px 9px;
    vertical-align:top;
}

.info-table th{
    background:#e9ecef;
    font-weight:700;
}

.label{
    width:18%;
    font-weight:700;
    background:#f2f2f2;
}

.value{
    width:32%;
}

.section-title{
    font-size:15px;
    font-weight:700;
    background:#e9ecef;
    border:1px solid #999;
    padding:8px 10px;
    margin-top:15px;
}

.section-content{
    border:1px solid #999;
    border-top:none;
    padding:10px;
    min-height:45px;
    line-height:1.6;
}

.empty-message{
    color:#777;
    font-style:italic;
}

.service-table,
.medicine-table,
.disease-table,
.doctor-table,
.parameter-table{
    width:100%;
    border-collapse:collapse;
}

.service-table th,
.service-table td,
.medicine-table th,
.medicine-table td,
.disease-table th,
.disease-table td,
.doctor-table th,
.doctor-table td,
.parameter-table th,
.parameter-table td{
    border:1px solid #999;
    padding:7px 8px;
    vertical-align:top;
}

.service-table th,
.medicine-table th,
.disease-table th,
.doctor-table th,
.parameter-table th{
    background:#f2f2f2;
    font-weight:700;
}

.text-center{
    text-align:center;
}

.text-right{
    text-align:right;
}

.signature-area{
    display:flex;
    justify-content:space-between;
    margin-top:70px;
    page-break-inside:avoid;
}

.signature-box{
    width:30%;
    text-align:center;
}

.signature-line{
    border-top:1px solid #222;
    padding-top:6px;
}

.small-text{
    font-size:11px;
    color:#555;
}

.status-pending{
    color:#b45309;
    font-weight:600;
}

@media print{

    @page{
        size:A4;
        margin:12mm;
    }

    body{
        background:#fff;
        padding:0;
    }

    .print-container{
        max-width:none;
        padding:0;
    }

    .no-print{
        display:none !important;
    }

    .section-title,
    .info-table th,
    .service-table th,
    .medicine-table th,
    .disease-table th,
    .doctor-table th,
    .parameter-table th{
        -webkit-print-color-adjust:exact;
        print-color-adjust:exact;
    }

    table{
        page-break-inside:auto;
    }

    tr{
        page-break-inside:avoid;
        page-break-after:auto;
    }

    .section-title{
        page-break-after:avoid;
    }
}
</style>
</head>

<body>

<div class="no-print">
    <button
        type="button"
        class="print-button"
        onclick="window.print()"
    >
        Print Discharge Summary
    </button>
</div>

<div class="print-container">

    <!-- =====================================================
         HOSPITAL HEADER
         ===================================================== -->

    <div class="hospital-header">

        <?php if($logo_path!==''): ?>

            <img
                src="<?=h($logo_path)?>"
                class="hospital-logo"
                alt="Hospital Logo"
            >

        <?php endif; ?>

        <div class="hospital-info">

            <div class="hospital-name">
                <?=h($discharge['hospital_name'] ?? '')?>
            </div>

            <?php if(!empty($discharge['hospital_code'])): ?>

                <div class="hospital-code">
                    Hospital Code:
                    <?=h($discharge['hospital_code'])?>
                </div>

            <?php endif; ?>

            <?php if($hospital_address!==''): ?>

                <div class="hospital-address">
                    <?=h($hospital_address)?>
                </div>

            <?php endif; ?>

            <?php if(!empty($discharge['hospital_mobile'])): ?>

                <div class="hospital-address">
                    Mobile:
                    <?=h($discharge['hospital_mobile'])?>
                </div>

            <?php endif; ?>

            <?php if(!empty($discharge['hospital_email'])): ?>

                <div class="hospital-address">
                    Email:
                    <?=h($discharge['hospital_email'])?>
                </div>

            <?php endif; ?>

        </div>

    </div>

    <div class="document-title">
        <?=strcasecmp(trim((string)($discharge['discharge_type'] ?? '')),'Death')===0 ? 'Death Summary' : 'Discharge Summary'?>
    </div>

    <!-- =====================================================
         PATIENT / ADMISSION DETAILS
         ===================================================== -->

    <table class="info-table">

        <tr>
            <td class="label">IPD No.</td>
            <td class="value"><?=h($discharge['ipd_no'])?></td>

            <td class="label">UHID</td>
            <td class="value"><?=h($discharge['uhid'] ?? '')?></td>
        </tr>

        <tr>
            <td class="label">Patient Name</td>
            <td class="value"><?=h($patient_name)?></td>

            <td class="label">Gender</td>
            <td class="value"><?=h($discharge['sex'] ?? '')?></td>
        </tr>

        <tr>
            <td class="label">Age</td>
            <td class="value"><?=h($age)?></td>

            <td class="label">Date of Birth</td>
            <td class="value">
                <?php if(!empty($discharge['dob'])): ?>
                    <?=h(date('d-m-Y',strtotime($discharge['dob'])))?>
                <?php endif; ?>
            </td>
        </tr>

        <tr>
            <td class="label">Mobile</td>
            <td class="value"><?=h($discharge['mobile'] ?? '')?></td>

            <td class="label">Email</td>
            <td class="value"><?=h($discharge['email'] ?? '')?></td>
        </tr>

        <tr>
            <td class="label">Address</td>
            <td class="value">
                <?=show_text($discharge['address'] ?? '')?>
                <?php if(!empty($discharge['pincode'])): ?>
                    <br>
                    PIN:
                    <?=h($discharge['pincode'])?>
                <?php endif; ?>
            </td>

            <td class="label">ABHA ID</td>
            <td class="value"><?=h($discharge['abha_id'] ?? '')?></td>
        </tr>

        <tr>
            <td class="label">Admission Date & Time</td>
            <td class="value"><?=h($admission_datetime)?></td>

            <td class="label">
                <?=strcasecmp(trim((string)($discharge['discharge_type'] ?? '')),'Death')===0 ? 'Death Date & Time' : 'Discharge Date & Time'?>
            </td>
            <td class="value"><?=h($discharge_datetime)?></td>
        </tr>

        <tr>
            <td class="label">Length of Stay</td>
            <td class="value"><?=h($length_of_stay)?></td>

            <td class="label">Discharge Type</td>
            <td class="value"><?=h($discharge['discharge_type'] ?? '')?></td>
        </tr>

        <tr>
            <td class="label">Payment Type</td>
            <td class="value"><?=h($discharge['payment_type'] ?? '')?></td>

            <td class="label">IPD Status</td>
            <td class="value">Discharged</td>
        </tr>

    </table>

   <!-- =====================================================
     CLINICAL HISTORY
     ===================================================== -->
<?php if(
    !empty($discharge['co']) ||
    !empty($discharge['ho'])
): ?>

    <div class="section-title">
        Clinical History
    </div>

    <div class="section-content">
        <?php if(!empty($discharge['co'])): ?>
            <strong>C/O (Complaints Of):</strong><br>
            <?=show_text($discharge['co'])?>
        <?php endif; ?>

        <?php if(!empty($discharge['ho'])): ?>
            <?php if(!empty($discharge['co'])): ?><br><br><?php endif; ?>
            <strong>H/O (History Of):</strong><br>
            <?=show_text($discharge['ho'])?>
        <?php endif; ?>
    </div>

<?php endif; ?>
    <!-- =====================================================
         DIAGNOSIS
         ===================================================== -->

    <?php if(
        !empty($discharge['final_diagnosis']) ||
        !empty($discharge['secondary_diagnosis'])
    ): ?>

        <div class="section-title">
            Diagnosis
        </div>

        <div class="section-content">

            <?php if(!empty($discharge['final_diagnosis'])): ?>

                <strong>Final Diagnosis:</strong><br>
                <?=show_text($discharge['final_diagnosis'])?>

            <?php endif; ?>

            <?php if(!empty($discharge['secondary_diagnosis'])): ?>

                <?php if(!empty($discharge['final_diagnosis'])): ?>
                    <br><br>
                <?php endif; ?>

                <strong>Secondary Diagnosis:</strong><br>
                <?=show_text($discharge['secondary_diagnosis'])?>

            <?php endif; ?>

        </div>

    <?php endif; ?>

    <!-- =====================================================
         DISEASES
         ===================================================== -->

    <?php if(!empty($diseases)): ?>

        <div class="section-title">
            Diagnosed Diseases
        </div>

        <div class="section-content">

            <table class="disease-table">

                <thead>
                    <tr>
                        <th width="8%">#</th>
                        <th>Disease</th>
                        <th width="25%">ICD Code</th>
                    </tr>
                </thead>

                <tbody>

                <?php foreach($diseases as $index=>$disease): ?>

                    <tr>
                        <td class="text-center">
                            <?=($index+1)?>
                        </td>

                        <td>
                            <?=h($disease['disease_name'] ?? ('Disease #'.$disease['disease_id']))?>
                        </td>

                        <td>
                            <?=h($disease['icd_code'] ?? '')?>
                        </td>
                    </tr>

                <?php endforeach; ?>

                </tbody>

            </table>

        </div>

    <?php endif; ?>

    <!-- =====================================================
         PROGNOSIS
         ===================================================== -->

    <?php if(!empty($discharge['prognosis'])): ?>

        <div class="section-title">
            Prognosis
        </div>

        <div class="section-content">
            <?=show_text($discharge['prognosis'])?>
        </div>

    <?php endif; ?>

    <!-- =====================================================
         COMORBIDITY
         ===================================================== -->

    <?php if(!empty($discharge['comorbidity'])): ?>

        <div class="section-title">
            Comorbidity
        </div>

        <div class="section-content">
            <?=show_text($discharge['comorbidity'])?>
        </div>

    <?php endif; ?>

    <!-- =====================================================
         CONDITION AT DISCHARGE
         ===================================================== -->

    <?php if(!empty($discharge['condition_at_discharge'])): ?>

        <div class="section-title">
            Condition at Discharge
        </div>

        <div class="section-content">
            <?=show_text($discharge['condition_at_discharge'])?>
        </div>

    <?php endif; ?>

    <!-- =====================================================
         DOCTORS
         ===================================================== -->

    <?php if(!empty($doctors)): ?>

        <div class="section-title">
            Treating / Discharge Doctors
        </div>

        <div class="section-content">

            <table class="doctor-table">

                <thead>
                    <tr>
                        <th width="8%">#</th>
                        <th>Doctor Name</th>
                        <th>Employee ID</th>
                        <th>License No.</th>
                    </tr>
                </thead>

                <tbody>

                <?php foreach($doctors as $index=>$doctor): ?>

                    <tr>

                        <td class="text-center">
                            <?=($index+1)?>
                        </td>

                        <td>
                            <?=h($doctor['full_name'] ?? '')?>
                        </td>

                        <td>
                            <?=h($doctor['employee_id'] ?? '')?>
                        </td>

                        <td>
                            <?=h($doctor['license_no'] ?? '')?>
                        </td>

                    </tr>

                <?php endforeach; ?>

                </tbody>

            </table>

        </div>

    <?php endif; ?>

    <!-- =====================================================
         SERVICES
         ===================================================== -->

    <?php if(!empty($services)): ?>

        <div class="section-title">
            Services / Investigations Advised at Discharge
        </div>

        <div class="section-content">

            <table class="service-table">

                <thead>
                    <tr>
                        <th width="6%">#</th>
                        <th>Service</th>
                        <th width="18%">Code</th>
                        <th width="18%">Category</th>
                        <th width="18%">Service Type</th>
                        <th width="12%">Mode</th>
                        <th width="12%">Amount</th>
                        <th width="12%">Billing</th>
                    </tr>
                </thead>

                <tbody>

                <?php
                $service_total=0;
                ?>

                <?php foreach($services as $index=>$service): ?>

                    <?php
                    $service_amount=(float)($service['amount'] ?? 0);
                    $service_total+=$service_amount;
                    ?>

                    <tr>

                        <td class="text-center">
                            <?=($index+1)?>
                        </td>

                        <td>
                            <?=h($service['service_name'] ?? ('Service #'.$service['service_id']))?>
                        </td>

                        <td>
                            <?=h($service['service_code'] ?? '')?>
                        </td>

                        <td>
                            <?=h($service['category_type'] ?? '')?>
                        </td>

                        <td>
                            <?=h($service['service_type'] ?? '')?>
                        </td>

                        <td>
                            <?=h($service['service_mode'] ?? '')?>
                        </td>

                        <td class="text-right">
                            ₹<?=number_format($service_amount,2)?>
                        </td>

                        <td>
                            <?php if(!empty($service['billing_status'])): ?>
                                <?=h($service['billing_status'])?>
                            <?php endif; ?>
                        </td>

                    </tr>

                <?php endforeach; ?>

                <tr>
                    <th colspan="6" class="text-right">
                        Total
                    </th>

                    <th class="text-right">
                        ₹<?=number_format($service_total,2)?>
                    </th>

                    <th></th>
                </tr>

                </tbody>

            </table>

        </div>

    <?php endif; ?>

    <!-- =====================================================
         MEDICINES
         ===================================================== -->

    <?php if(!empty($medicines)): ?>

        <div class="section-title">
            Discharge Medicines
        </div>

        <div class="section-content">

            <table class="medicine-table">

                <thead>
                    <tr>
                        <th width="5%">#</th>
                        <th>Medicine</th>
                        <th width="12%">Frequency</th>
                        <th width="15%">Dose</th>
                        <th width="13%">Meal</th>
                        <th width="9%">Qty</th>
                        <th width="16%">Doctor</th>
                        <th width="12%">Pharmacy</th>
                        <th width="12%">Billing</th>
                        <th>Instructions</th>
                    </tr>
                </thead>

                <tbody>

                <?php foreach($medicines as $index=>$medicine): ?>

                    <tr>

                        <td class="text-center">
                            <?=($index+1)?>
                        </td>

                        <td>
                            <?=h(
                                !empty($medicine['medicine'])
                                    ? $medicine['medicine']
                                    : ($medicine['item_name'] ?? '')
                            )?>
                        </td>

                        <td>
                            <?=h($medicine['frequency'] ?? '')?>
                        </td>

                        <td>
                            <?=h(medicine_dose_text($medicine))?>
                        </td>

                        <td>
                            <?=h($medicine['meal'] ?? '')?>
                        </td>

                        <td class="text-center">
                            <?=h($medicine['qty'] ?? '')?>
                        </td>

                        <td>
                            <?=h($medicine['doctor_name'] ?? '')?>
                        </td>

                        <td>
                            <?=h($medicine['pharmacy_status'] ?? '')?>
                        </td>

                        <td>
                            <?=h($medicine['billing_status'] ?? '')?>
                        </td>

                        <td>

                            <?php if(!empty($medicine['comments'])): ?>

                                <?=show_text($medicine['comments'])?>

                            <?php endif; ?>

                            <?php if(!empty($medicine['stop_medication'])): ?>

                                <?php if(!empty($medicine['comments'])): ?>
                                    <br>
                                <?php endif; ?>

                                <strong>
                                    Stop:
                                </strong>

                                <?=h($medicine['stop_medication'])?>

                            <?php endif; ?>

                            <?php if(!empty($medicine['stop_date'])): ?>

                                <br>

                                <strong>
                                    Stop Date:
                                </strong>

                                <?=h(
                                    date(
                                        'd-m-Y',
                                        strtotime($medicine['stop_date'])
                                    )
                                )?>

                            <?php endif; ?>

                        </td>

                    </tr>

                <?php endforeach; ?>

                </tbody>

            </table>

        </div>

    <?php endif; ?>

    <!-- =====================================================
         VITALS / FOLLOW-UP
         ===================================================== -->
    <?php if(
        $discharge['weight']!==null ||
        $discharge['height']!==null ||
        $discharge['bmi']!==null ||
        $discharge['pain_scale']!==null ||
        $discharge['follow_up_days']!==null ||
        !empty($discharge['follow_up_date']) ||
        !empty($discharge['follow_up_details']) ||
        !empty($discharge['advice_at_discharge'])
    ): ?>

        <div class="section-title">
            Vitals / Discharge Follow-up
        </div>

        <div class="section-content">
            <table class="info-table">
                <tr>
                    <?php if($discharge['weight']!==null): ?>
                        <td class="label">Weight</td>
                        <td class="value"><?=h($discharge['weight'])?> kg</td>
                    <?php else: ?>
                        <td class="label"></td><td class="value"></td>
                    <?php endif; ?>
                    <?php if($discharge['height']!==null): ?>
                        <td class="label">Height</td>
                        <td class="value"><?=h($discharge['height'])?> cm</td>
                    <?php else: ?>
                        <td class="label"></td><td class="value"></td>
                    <?php endif; ?>
                </tr>
                <tr>
                    <?php if($discharge['bmi']!==null): ?>
                        <td class="label">BMI</td>
                        <td class="value"><?=h($discharge['bmi'])?></td>
                    <?php else: ?>
                        <td class="label"></td><td class="value"></td>
                    <?php endif; ?>
                    <?php if($discharge['pain_scale']!==null): ?>
                        <td class="label">Pain Scale</td>
                        <td class="value"><?=h($discharge['pain_scale'])?> / 10</td>
                    <?php else: ?>
                        <td class="label"></td><td class="value"></td>
                    <?php endif; ?>
                </tr>
                <tr>
                    <?php if($discharge['follow_up_days']!==null): ?>
                        <td class="label">Follow-up After</td>
                        <td class="value"><?=h($discharge['follow_up_days'])?> day(s)</td>
                    <?php else: ?>
                        <td class="label"></td><td class="value"></td>
                    <?php endif; ?>
                    <?php if(!empty($discharge['follow_up_date'])): ?>
                        <td class="label">Follow-up Date</td>
                        <td class="value"><?=h(date('d-m-Y',strtotime($discharge['follow_up_date'])))?></td>
                    <?php else: ?>
                        <td class="label"></td><td class="value"></td>
                    <?php endif; ?>
                </tr>
            </table>

            <?php if(!empty($discharge['follow_up_details'])): ?>
                <p><strong>Follow-up Details:</strong><br><?=show_text($discharge['follow_up_details'])?></p>
            <?php endif; ?>

            <?php if(!empty($discharge['advice_at_discharge'])): ?>
                <p><strong>Advice at Discharge:</strong><br><?=show_text($discharge['advice_at_discharge'])?></p>
            <?php endif; ?>
        </div>

    <?php endif; ?>

    <!-- =====================================================
         PARAMETER READINGS
         ===================================================== -->

    <?php if(!empty($parameter_readings)): ?>

        <div class="section-title">
            Parameter Readings
        </div>

        <div class="section-content">

            <?php
            $parameter_is_list=true;

            foreach($parameter_readings as $key=>$value){

                if(!is_array($value)){
                    $parameter_is_list=false;
                    break;
                }

            }
            ?>

            <?php if($parameter_is_list): ?>

                <table class="parameter-table">

                    <thead>
                        <tr>
                            <th width="8%">#</th>
                            <th>Parameter</th>
                            <th>Reading</th>
                        </tr>
                    </thead>

                    <tbody>

                    <?php $parameter_index=1; ?>

                    <?php foreach($parameter_readings as $key=>$value): ?>

                        <?php if(is_array($value)): ?>

                            <tr>

                                <td class="text-center">
                                    <?=$parameter_index++?>
                                </td>

                                <td>
                                    <?=h(
                                        $value['parameter_name']
                                        ?? $value['name']
                                        ?? $key
                                    )?>
                                </td>

                                <td>
                                    <?=h(
                                        $value['reading']
                                        ?? $value['value']
                                        ?? $value['result']
                                        ?? ''
                                    )?>

                                    <?php if(!empty($value['unit'])): ?>
                                        <?=h($value['unit'])?>
                                    <?php endif; ?>

                                </td>

                            </tr>

                        <?php endif; ?>

                    <?php endforeach; ?>

                    </tbody>

                </table>

            <?php else: ?>

                <table class="parameter-table">

                    <thead>
                        <tr>
                            <th width="40%">Parameter</th>
                            <th>Reading</th>
                        </tr>
                    </thead>

                    <tbody>

                    <?php foreach($parameter_readings as $key=>$value): ?>

                        <tr>

                            <td>
                                <?=h($key)?>
                            </td>

                            <td>
                                <?=h(
                                    is_array($value)
                                        ? json_encode(
                                            $value,
                                            JSON_UNESCAPED_UNICODE
                                        )
                                        : $value
                                )?>
                            </td>

                        </tr>

                    <?php endforeach; ?>

                    </tbody>

                </table>

            <?php endif; ?>

        </div>

    <?php endif; ?>

    <!-- =====================================================
         PROGNOSIS / COMORBIDITY / CONDITION ETC.
         ===================================================== -->

    <?php if(!empty($discharge['comments'])): ?>

        <div class="section-title">
            Discharge Summary Comments
        </div>

        <div class="section-content">
            <?=show_text($discharge['comments'])?>
        </div>

    <?php endif; ?>

    <?php if(!empty($discharge['diet_comments'])): ?>

        <div class="section-title">
            Diet Instructions
        </div>

        <div class="section-content">
            <?=show_text($discharge['diet_comments'])?>
        </div>

    <?php endif; ?>

    <?php if(!empty($discharge['guidelines_comments'])): ?>

        <div class="section-title">
            Guidelines / Follow-up Instructions
        </div>

        <div class="section-content">
            <?=show_text($discharge['guidelines_comments'])?>
        </div>

    <?php endif; ?>

    <!-- =====================================================
         SIGNATURES
         ===================================================== -->

    <div class="signature-area">

        <div class="signature-box">

            <div style="height:45px;"></div>

            <div class="signature-line">
                Patient / Attendant
            </div>

        </div>

        <div class="signature-box">

            <div style="height:45px;"></div>

            <div class="signature-line">
                Treating Doctor
            </div>

        </div>

        <div class="signature-box">

            <div style="height:45px;"></div>

            <div class="signature-line">
                Authorized Signatory
            </div>

        </div>

    </div>

</div>

</body>
</html>