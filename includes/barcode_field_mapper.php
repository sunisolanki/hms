<?php
function getBarcodeFieldValue($field, $data)
{
    switch($field){
        case 'hospital_name':
            return $data['hospital_name'] ?? '';
        case 'branch_name':
            return $data['branch_name'] ?? '';
        case 'patient_name':
            return trim(
                ($data['first_name'] ?? '') . ' ' .
                ($data['last_name'] ?? '')
            );
        case 'uhid':
            return $data['uhid'] ?? '';
        case 'doctor_name':
            return $data['doctor_name'] ?? '';
        case 'department_name':
            return $data['department_name'] ?? '';
        case 'mobile':
            return $data['mobile'] ?? '';
        case 'date':
            return !empty($data['appointment_date'])
                ? date('d-m-Y', strtotime($data['appointment_date']))
                : '';
        case 'time':
            return $data['appointment_time'] ?? '';
        case 'barcode_no':
            return $data['barcode_no'] ?? '';
        default:
            return '';
    }
}