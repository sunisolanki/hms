<?php

function formatCurrency($amount){
    return number_format((float)$amount,2,'.',',');
}

function formatDate($date){
    if(empty($date)){
        return '';
    }
    return date('d-m-Y',strtotime($date));
}

function paymentStatusBadge($status){

    switch($status){

        case 'Paid':
            return '<span class="badge bg-success">Paid</span>';

        case 'Partial':
            return '<span class="badge bg-warning text-dark">Partial</span>';

        case 'Pending':
            return '<span class="badge bg-danger">Pending</span>';

        case 'Cancelled':
            return '<span class="badge bg-secondary">Cancelled</span>';

        default:
            return '<span class="badge bg-light text-dark">'.$status.'</span>';
    }

}

function subscriptionStatusBadge($status){

    switch($status){

        case 'Active':
            return '<span class="badge bg-success">Active</span>';

        case 'Trial':
            return '<span class="badge bg-info">Trial</span>';

        case 'Pending':
            return '<span class="badge bg-warning text-dark">Pending</span>';

        case 'Expired':
            return '<span class="badge bg-danger">Expired</span>';

        case 'Suspended':
            return '<span class="badge bg-dark">Suspended</span>';

        case 'Cancelled':
            return '<span class="badge bg-secondary">Cancelled</span>';

        default:
            return '<span class="badge bg-light text-dark">'.$status.'</span>';
    }

}

function generateInvoiceNumber(mysqli $conn){

    $result=$conn->query("SELECT MAX(id) last_id FROM software_invoices");

    $row=$result->fetch_assoc();

    $next=((int)$row['last_id'])+1;

    return 'INV'.str_pad($next,6,'0',STR_PAD_LEFT);

}

function jsonResponse($status,$message,$data=[]){

    header('Content-Type: application/json');

    echo json_encode([
        'status'=>$status,
        'message'=>$message,
        'data'=>$data
    ]);

    exit;

}