<?php
session_start();
if(!isset($_SESSION['user_id'])){
    header('Location: /index.php');
    exit;
}
require $_SERVER['DOCUMENT_ROOT'].'/includes/db.php';
$topServices=[];
$mostSellingMedicines=[];
$lowStockMedicines=[];
/* =========================================================
   TOP SERVICES
   ========================================================= */
$serviceStmt=$conn->prepare("
    SELECT
        obd.service_id,
        sm.service_name,
        SUM(obd.qty) AS quantity_sold,
        COALESCE(SUM(obd.net_amount),0) AS revenue
    FROM opd_bill_details obd
    INNER JOIN service_master sm
        ON sm.id=obd.service_id
    WHERE obd.status='Active'
    GROUP BY obd.service_id,sm.service_name
    ORDER BY quantity_sold DESC,revenue DESC
    LIMIT 5
");
if($serviceStmt){
    $serviceStmt->execute();
    $serviceResult=$serviceStmt->get_result();
    while($row=$serviceResult->fetch_assoc()){
        $topServices[]=$row;
    }
    $serviceStmt->close();
}
/* =========================================================
   MOST SELLING MEDICINES
   ========================================================= */
$medicineStmt=$conn->prepare("
    SELECT
        psi.item_id,
        im.item_name,
        COALESCE(SUM(psi.qty),0) AS quantity_sold,
        COALESCE(SUM(psi.net_amount),0) AS revenue
    FROM pharmacy_sale_items psi
    INNER JOIN pharmacy_sales ps
        ON ps.id=psi.sale_id
    INNER JOIN item_master im
        ON im.id=psi.item_id
    WHERE ps.status='Active'
    GROUP BY psi.item_id,im.item_name
    ORDER BY quantity_sold DESC,revenue DESC
    LIMIT 5
");
if($medicineStmt){
    $medicineStmt->execute();
    $medicineResult=$medicineStmt->get_result();
    while($row=$medicineResult->fetch_assoc()){
        $mostSellingMedicines[]=$row;
    }
    $medicineStmt->close();
}
/* =========================================================
   LOW STOCK MEDICINES
   ========================================================= */
$stockStmt=$conn->prepare("
    SELECT
        im.id,
        im.item_name,
        COALESCE(SUM(ib.qty_available),0) AS current_stock,
        COALESCE(NULLIF(im.reorder_level,0),NULLIF(im.min_level,0),0) AS reorder_level
    FROM item_master im
    LEFT JOIN inventory_batches ib
        ON ib.item_id=im.id
        AND ib.status='Active'
    WHERE im.item_group='Medicine'
    AND im.status='Active'
    GROUP BY im.id,im.item_name,im.reorder_level,im.min_level
    HAVING current_stock<=reorder_level
    ORDER BY current_stock ASC,im.item_name ASC
    LIMIT 5
");
if($stockStmt){
    $stockStmt->execute();
    $stockResult=$stockStmt->get_result();
    while($row=$stockResult->fetch_assoc()){
        $lowStockMedicines[]=$row;
    }
    $stockStmt->close();
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<title>Dashboard - WebSplend HMS</title>
<link rel="stylesheet" href="/assets/css/style.css">
<link rel="icon" type="image/png" href="/assets/img/hms_logo.png">
<script src="https://cdn.jsdelivr.net/npm/chart.js"></script>

<style>
.ws-hms-dashboard{
    width:100%;
    padding:10px 12px 28px;
    background:white;
    min-height:calc(100vh - 60px);
    color:#253638;
}
.ws-hms-dashboard *{
    box-sizing:border-box;
}
.ws-hms-dashboard-top{
    display:flex;
    align-items:flex-start;
    justify-content:space-between;
    gap:20px;
    margin-bottom:5px;
}
.ws-hms-dashboard-title-wrap{
    min-width:0;
}
.ws-hms-dashboard-title-row{
    display:flex;
    align-items:center;
    gap:9px;
}
.ws-hms-dashboard-title-icon{
    width:30px;
    height:30px;
    flex:0 0 30px;
    display:flex;
    align-items:center;
    justify-content:center;
    border-radius:9px;
    background:#e8f7f8;
    color:#099aa7;
}
.ws-hms-dashboard-title-icon svg{
    width:16px;
    height:16px;
}
.ws-hms-dashboard-title{
    margin:0;
    font-size:24px;
    line-height:1.15;
    font-weight:750;
    letter-spacing:-.4px;
    color:#1f2f31;
}
.ws-hms-dashboard-subtitle{
    margin:4px 0 0 39px;
    font-size:11px;
    line-height:1.35;
    color:#7a898c;
}
.ws-hms-dashboard-date{
    display:flex;
    align-items:center;
    gap:7px;
    background:#fff;
    border:1px solid #e1e9eb;
    border-radius:9px;
    padding:8px 11px;
    margin-top:1px;
    font-size:10px;
    font-weight:650;
    color:#607073;
    white-space:nowrap;
    box-shadow:0 2px 7px rgba(31,47,49,.035);
}
.ws-hms-dashboard-date svg{
    width:14px;
    height:14px;
    color:#099aa7;
}
.ws-hms-dashboard-announcements{
    margin-bottom:10px;
}
.ws-hms-dashboard-announcement{
    display:flex;
    align-items:flex-start;
    gap:9px;
    background:#fff9e6;
    border:1px solid #f0e1ad;
    border-left:4px solid #e5b51b;
    border-radius:8px;
    padding:8px 10px;
    margin-bottom:6px;
}
.ws-hms-dashboard-announcement-icon{
    width:25px;
    height:25px;
    display:flex;
    align-items:center;
    justify-content:center;
    border-radius:7px;
    background:#fff0bd;
    color:#b98500;
    font-size:14px;
}
.ws-hms-dashboard-announcement-body{
    flex:1;
    min-width:0;
}
.ws-hms-dashboard-announcement-title{
    font-size:11px;
    font-weight:700;
    color:#4d4933;
}
.ws-hms-dashboard-announcement-text{
    margin-top:2px;
    font-size:11px;
    color:#756e52;
}
.ws-hms-dashboard-announcement-links{
    margin-top:3px;
}
.ws-hms-dashboard-announcement-links a{
    font-size:10px;
    color:#078b99;
    margin-right:12px;
    text-decoration:none;
    font-weight:600;
}
.ws-hms-dashboard-announcement-close{
    cursor:pointer;
    font-size:17px;
    color:#777;
    line-height:18px;
}
/* FILTER BAR */
.ws-hms-dashboard-filter{
    position:relative;
    background:#fff;
    border:1px solid #e3eaed;
    border-radius:12px;
    padding:10px 11px;
    margin-bottom:12px;
    box-shadow:0 3px 12px rgba(31,47,49,.045);
}
.ws-hms-dashboard-filter-grid{
    display:grid;
    grid-template-columns:
        minmax(105px,.75fr)
        minmax(125px,.9fr)
        minmax(145px,1fr)
        minmax(155px,1.1fr)
        minmax(145px,1fr)
        minmax(135px,.95fr)
        minmax(135px,.95fr);
    gap:8px;
    align-items:center;
}
.ws-hms-dashboard-filter-item{
    position:relative;
    min-width:0;
}
.ws-hms-dashboard-filter-item select{
    width:100%;
    height:30px;
    padding:0 30px 0 11px;
    border:1px solid #dce5e8;
    border-radius:8px;
    background:#fbfcfd;
    outline:none;
    cursor:pointer;
    appearance:auto;
    transition:
        border-color .16s ease,
        box-shadow .16s ease,
        background .16s ease;
}
.ws-hms-dashboard-filter-item select:hover{
    background:#fff;
    border-color:#cbd9dc;
}
.ws-hms-dashboard-filter-item select:focus{
    background:#fff;
    border-color:#099aa7;
    box-shadow:0 0 0 3px rgba(9,154,167,.08);
}
/* OCCUPANCY */
.ws-hms-dashboard-occupancy{
    min-height:225px;
    display:grid;
    grid-template-columns:210px 1fr;
    align-items:center;
    gap:26px;
    padding:14px 12px;
}
.ws-hms-dashboard-occupancy-circle-wrap{
    width:190px;
    height:190px;
    display:flex;
    align-items:center;
    justify-content:center;
}
.ws-hms-dashboard-occupancy-circle{
    width:166px;
    height:166px;
    flex:0 0 166px;
    border-radius:50%;
    display:flex;
    align-items:center;
    justify-content:center;
    background:conic-gradient(
        #099aa7 0deg,
        #099aa7 0deg,
        #edf2f3 0deg,
        #edf2f3 360deg
    );
    box-shadow:inset 0 0 0 1px rgba(9,154,167,.04);
}
.ws-hms-dashboard-occupancy-inner{
    width:122px;
    height:122px;
    flex:0 0 122px;
    border-radius:50%;
    background:#fff;
    display:flex;
    flex-direction:column;
    align-items:center;
    justify-content:center;
    box-shadow:0 4px 14px rgba(31,47,49,.07);
}
.ws-hms-dashboard-occupancy-value{
    margin:0;
    font-size:30px;
    line-height:1;
    font-weight:800;
    letter-spacing:-.7px;
    color:#20383d;
}
.ws-hms-dashboard-occupancy-label{
    margin-top:7px;
    font-size:10px;
    line-height:1;
    font-weight:700;
    color:#8a999b;
    text-transform:uppercase;
    letter-spacing:.5px;
}
.ws-hms-dashboard-stat-list{
    width:100%;
    display:flex;
    flex-direction:column;
}
.ws-hms-dashboard-stat{
    min-height:47px;
    display:flex;
    align-items:center;
    justify-content:space-between;
    gap:14px;
    padding:0 8px 0 4px;
    border-bottom:1px solid #e9eff0;
}
.ws-hms-dashboard-stat:last-child{
    border-bottom:0;
}
.ws-hms-dashboard-stat-left{
    display:flex;
    align-items:center;
    gap:10px;
    min-width:0;
    font-size:11px;
    line-height:1.2;
    color:#596a6d;
    font-weight:600;
}
.ws-hms-dashboard-stat-dot{
    width:10px;
    height:10px;
    flex:0 0 10px;
    border-radius:50%;
}
.ws-hms-dashboard-stat-value{
    min-width:30px;
    text-align:right;
    font-size:13px;
    line-height:1;
    font-weight:800;
    color:#263b40;
}
@media(max-width:600px){
    .ws-hms-dashboard-occupancy{
        grid-template-columns:1fr;
        gap:12px;
        padding:10px 6px;
    }
    .ws-hms-dashboard-occupancy-circle-wrap{
        width:100%;
        height:auto;
    }
    .ws-hms-dashboard-stat{
        min-height:42px;
    }
}
/* KPI CARDS */
.ws-hms-dashboard-kpis{
    display:grid;
    grid-template-columns:repeat(6,minmax(0,1fr));
    gap:12px;
    margin-bottom:12px;
}
.ws-hms-dashboard-kpi{
    position:relative;
    min-width:0;
    min-height:112px;
    padding:14px 15px 13px;
    border:1px solid rgba(31,47,49,18%);
    border-radius:14px;
    overflow:hidden;
    box-shadow:0 4px 14px rgba(31,47,49,.055);
    transition:
        transform .18s ease,
        box-shadow .18s ease;
}
.ws-hms-dashboard-kpi:hover{
    transform:translateY(-2px);
    box-shadow:0 7px 20px rgba(31,47,49,.085);
}
.ws-hms-dashboard-kpi::after{
    content:"";
    position:absolute;
    right:-18px;
    bottom:-32px;
    width:125px;
    height:72px;
    border-radius:60% 0 0 0;
    opacity:.75;
    pointer-events:none;
}
.ws-hms-dashboard-kpi-blue{
    background:linear-gradient(135deg,#edf6ff 0%,#e4f1ff 100%);
}
.ws-hms-dashboard-kpi-blue::after{
    background:#cfe5ff;
}
.ws-hms-dashboard-kpi-green{
    background:linear-gradient(135deg,#effbf4 0%,#e4f8ed 100%);
}
.ws-hms-dashboard-kpi-green::after{
    background:#c9f0d9;
}
.ws-hms-dashboard-kpi-pink{
    background:linear-gradient(135deg,#fff3f5 0%,#ffebef 100%);
}
.ws-hms-dashboard-kpi-pink::after{
    background:#ffd0d9;
}
.ws-hms-dashboard-kpi-yellow{
    background:linear-gradient(135deg,#fffaf0 0%,#fff5dc 100%);
}
.ws-hms-dashboard-kpi-yellow::after{
    background:#ffe6a6;
}
.ws-hms-dashboard-kpi-purple{
    background:linear-gradient(135deg,#f8f3ff 0%,#f1eaff 100%);
}
.ws-hms-dashboard-kpi-purple::after{
    background:#e2d0ff;
}
.ws-hms-dashboard-kpi-cyan{
    background:linear-gradient(135deg,#effbfc 0%,#e4f8fa 100%);
}
.ws-hms-dashboard-kpi-cyan::after{
    background:#c7eef2;
}
.ws-hms-dashboard-kpi-icon{
    position:relative;
    z-index:2;
    width:38px;
    height:38px;
    border-radius:11px;
    display:flex;
    align-items:center;
    justify-content:center;
    margin-bottom:9px;
}
.ws-hms-dashboard-kpi-blue .ws-hms-dashboard-kpi-icon{
    background:#cfe6ff;
    color:#0877df;
}
.ws-hms-dashboard-kpi-green .ws-hms-dashboard-kpi-icon{
    background:#cdf2dc;
    color:#0ba34f;
}
.ws-hms-dashboard-kpi-pink .ws-hms-dashboard-kpi-icon{
    background:#ffd5de;
    color:#e84e68;
}
.ws-hms-dashboard-kpi-yellow .ws-hms-dashboard-kpi-icon{
    background:#ffe7aa;
    color:#d99a00;
}
.ws-hms-dashboard-kpi-purple .ws-hms-dashboard-kpi-icon{
    background:#e4d4ff;
    color:#7441d2;
}
.ws-hms-dashboard-kpi-cyan .ws-hms-dashboard-kpi-icon{
    background:#c9eef2;
    color:#0797a5;
}
.ws-hms-dashboard-kpi-icon svg{
    width:19px;
    height:19px;
    stroke-width:2;
}
.ws-hms-dashboard-kpi-label{
    position:relative;
    z-index:2;
    margin:0;
    font-size:11px;
    line-height:1.25;
    font-weight:650;
}
.ws-hms-dashboard-kpi-value{
    position:relative;
    z-index:2;
    margin-top:4px;
    font-size:25px;
    line-height:1.15;
    letter-spacing:-.4px;
    font-weight:800;
    color:#172f3a;
    white-space:nowrap;
}
.ws-hms-dashboard-kpi-meta{
    position:relative;
    z-index:2;
    display:flex;
    align-items:center;
    justify-content:space-between;
    gap:6px;
    margin-top:8px;
    font-size:9px;
    line-height:1.2;
    color:#718083;
}
.ws-hms-dashboard-kpi-meta > span:first-child{
    min-width:0;
    overflow:hidden;
    text-overflow:ellipsis;
    white-space:nowrap;
}
.ws-hms-dashboard-change{
    display:inline-flex;
    align-items:center;
    justify-content:center;
    min-height:21px;
    padding:3px 7px;
    border-radius:999px;
    font-size:9px;
    line-height:1;
    font-weight:750;
    white-space:nowrap;
}
.ws-hms-dashboard-up{
    color:#079447;
    background:#d9f7e5;
}
.ws-hms-dashboard-down{
    color:#dc4055;
    background:#ffe0e5;
}
.ws-hms-dashboard-neutral{
    color:#5f6f73;
    background:#e8eef0;
}
.ws-hms-dashboard-kpi-arrow{
    position:absolute;
    z-index:3;
    top:14px;
    right:14px;
    width:28px;
    height:28px;
    display:flex;
    align-items:center;
    justify-content:center;
    border-radius:50%;
    font-size:17px;
    font-weight:500;
    line-height:1;
    color:#294047;
    background:rgba(255,255,255,.48);
    border:1px solid rgba(255,255,255,.6);
}
.ws-hms-dashboard-kpi-bars{
    position:absolute;
    z-index:2;
    right:14px;
    bottom:37px;
    height:36px;
    display:flex;
    align-items:flex-end;
    gap:4px;
    opacity:.9;
}
.ws-hms-dashboard-kpi-bars span{
    display:block;
    width:7px;
    border-radius:5px 5px 2px 2px;
}
.ws-hms-dashboard-kpi-bars span:nth-child(1){
    height:14px;
}
.ws-hms-dashboard-kpi-bars span:nth-child(2){
    height:24px;
}
.ws-hms-dashboard-kpi-bars span:nth-child(3){
    height:32px;
}
.ws-hms-dashboard-kpi-blue .ws-hms-dashboard-kpi-bars span{
    background:#79baff;
}
.ws-hms-dashboard-kpi-green .ws-hms-dashboard-kpi-bars span{
    background:#62d99b;
}
.ws-hms-dashboard-kpi-pink .ws-hms-dashboard-kpi-bars span{
    background:#ff9eae;
}
.ws-hms-dashboard-kpi-yellow .ws-hms-dashboard-kpi-bars span{
    background:#f7c44f;
}
.ws-hms-dashboard-kpi-purple .ws-hms-dashboard-kpi-bars span{
    background:#b991f4;
}
.ws-hms-dashboard-kpi-cyan .ws-hms-dashboard-kpi-bars span{
    background:#63d2dc;
}
@media(max-width:1400px){
    .ws-hms-dashboard-kpis{
        grid-template-columns:repeat(3,1fr);
    }
}
@media(max-width:900px){
    .ws-hms-dashboard-kpis{
        grid-template-columns:repeat(2,1fr);
    }
}
@media(max-width:600px){
    .ws-hms-dashboard-kpis{
        grid-template-columns:1fr;
    }
}

/* PANELS */
.ws-hms-dashboard-grid{
    display:grid;
    gap:9px;
    margin-bottom:9px;
}
.ws-hms-dashboard-grid-3{
    grid-template-columns:1.55fr 1fr 1fr;
}
.ws-hms-dashboard-ipd-panel ~ .ws-hms-dashboard-panel{
    align-self:start;
}
.ws-hms-dashboard-grid-2{
    grid-template-columns:1fr 1.55fr;
}
.ws-hms-dashboard-grid-4{
    grid-template-columns:1.1fr 1.25fr 1.25fr;
}
.ws-hms-dashboard-panel{
    background:#fff;
    border:1px solid #e4ebed;
    border-radius:9px;
    overflow:hidden;
    box-shadow:0 2px 8px rgba(31,47,49,.035);
}
.ws-hms-dashboard-panel-head{
    display:flex;
    align-items:center;
    justify-content:space-between;
    gap:10px;
    padding:8px 11px;
    border-bottom:1px solid #edf1f2;
}
.ws-hms-dashboard-panel-title{
    margin:0;
    font-size:11px;
    font-weight:750;
    color:#293a3d;
}
.ws-hms-dashboard-panel-subtitle{
    margin-top:2px;
    font-size:9px;
    color:#899698;
}
.ws-hms-dashboard-panel-action{
    font-size:9px;
    color:#099aa7;
    font-weight:700;
    text-decoration:none;
}
.ws-hms-dashboard-chart{
    height:240px;
    width:100%;
    padding:8px 10px 10px;
}
.ws-hms-dashboard-small-chart{
    height:240px;
    width:100%;
    padding:8px 10px 10px;
}
.ws-hms-dashboard-medium-chart{
    height:240px;
    width:100%;
    padding:8px 10px 10px;
}
.ws-hms-dashboard-chart canvas,
.ws-hms-dashboard-small-chart canvas,
.ws-hms-dashboard-medium-chart canvas{
    width:100% !important;
    height:100% !important;
}
/* STATUS */
.ws-hms-dashboard-status{
    display:inline-flex;
    align-items:center;
    padding:3px 7px;
    border-radius:12px;
    font-size:8px;
    font-weight:750;
}
.ws-hms-dashboard-status-admitted{
    background:#e5f7ec;
    color:#158548;
}
.ws-hms-dashboard-status-emergency{
    background:#fff0f1;
    color:#d94d5f;
}
.ws-hms-dashboard-status-normal{
    background:#eef3f4;
    color:#667679;
}

/* ALERTS */
.ws-hms-dashboard-alerts{
    display:flex;
    flex-direction:column;
    gap:6px;
}
.ws-hms-dashboard-alert{
    display:flex;
    align-items:center;
    justify-content:space-between;
    gap:8px;
    padding:7px 8px;
    border-radius:7px;
    background:#f8fafb;
}
.ws-hms-dashboard-alert-left{
    display:flex;
    align-items:center;
    gap:7px;
    min-width:0;
}
.ws-hms-dashboard-alert-icon{
    width:23px;
    height:23px;
    flex:0 0 23px;
    display:flex;
    align-items:center;
    justify-content:center;
    border-radius:7px;
    background:#fff0f1;
    color:#dc5262;
}
.ws-hms-dashboard-alert-icon svg{
    width:13px;
    height:13px;
}
.ws-hms-dashboard-alert-title{
    font-size:9px;
    font-weight:700;
    color:#455659;
}
.ws-hms-dashboard-alert-desc{
    margin-top:1px;
    font-size:8px;
    color:#899698;
}
.ws-hms-dashboard-alert-count{
    min-width:23px;
    text-align:center;
    font-size:10px;
    font-weight:800;
    color:#d94d5f;
}
.ws-hms-dashboard-empty{
    padding:20px 10px;
    text-align:center;
    color:#98a4a6;
    font-size:10px;
}
.ws-hms-dashboard-loading{
    opacity:.55;
    pointer-events:none;
}
.ws-hms-dashboard-filter-heading{
    display:flex;
    align-items:center;
    gap:6px;
    margin:0 0 7px 2px;
    color:#617174;
    font-size:10px;
    font-weight:700;
    text-transform:uppercase;
    letter-spacing:.35px;
}
.ws-hms-dashboard-filter-heading svg{
    width:13px;
    height:13px;
    color:#099aa7;
}
/* IPD BED OCCUPANCY */
.ws-hms-dashboard-ipd-panel{
    grid-column:auto;
    overflow:hidden;
}
.ws-hms-dashboard-ipd-header{
    min-height:45px;
    padding:3px 14px;
    display:flex;
    align-items:center;
    justify-content:space-between;
    gap:8px;
    border-bottom:1px solid #edf1f2;
}
.ws-hms-dashboard-ipd-title-wrap{
    display:flex;
    align-items:center;
    gap:8px;
    min-width:0;
}
.ws-hms-dashboard-ipd-icon{
    width:36px;
    height:36px;
    flex:0 0 36px;
    display:flex;
    align-items:center;
    justify-content:center;
    border-radius:10px;
    background:#e5f7fa;
    color:#099aa7;
}
.ws-hms-dashboard-ipd-icon svg{
    width:17px;
    height:17px;
}
.ws-hms-dashboard-ipd-title{
    margin:0;
    color:#293a3d;
    font-size:11px;
    line-height:1.15;
    font-weight:750;
}
.ws-hms-dashboard-ipd-subtitle{
    margin-top:2px;
    color:#899698;
    font-size:9px;
    line-height:1.2;
    font-weight:500;
}
.ws-hms-dashboard-ipd-details{
    height:28px;
    padding:0 8px;
    display:flex;
    align-items:center;
    gap:5px;
    border:1px solid #dfe9ee;
    border-radius:8px;
    color:#099aa7;
    background:#fff;
    text-decoration:none;
    font-size:8px;
    font-weight:700;
    white-space:nowrap;
}
.ws-hms-dashboard-ipd-details svg{
    width:13px;
    height:13px;
}
.ws-hms-dashboard-ipd-details .ws-hms-dashboard-ipd-arrow{
    width:11px;
    height:11px;
}
.ws-hms-dashboard-ipd-content{
    min-height:230px;
    padding:5px 5px 5px;
    display:grid;
    grid-template-columns:180px 1fr;
    gap:12px;
    align-items:center;
}
.ws-hms-dashboard-ipd-left{
    display:flex;
    flex-direction:column;
    align-items:center;
    justify-content:center;
}
.ws-hms-dashboard-ipd-donut{
    width:140px;
    height:140px;
    border-radius:50%;
    display:flex;
    align-items:center;
    justify-content:center;
    background:conic-gradient(
        #13a6b5 0deg,
        #13a6b5 0deg,
        #e7edf2 0deg,
        #e7edf2 360deg
    );
}
.ws-hms-dashboard-ipd-donut-inner{
    width:80px;
    height:80px;
    border-radius:50%;
    background:#fff;
    display:flex;
    flex-direction:column;
    align-items:center;
    justify-content:center;
    box-shadow:0 3px 10px rgba(31,47,49,.06);
}
.ws-hms-dashboard-ipd-percent{
    margin:0;
    color:#142638;
    font-size:24px;
    line-height:1;
    font-weight:850;
    letter-spacing:-.6px;
}
.ws-hms-dashboard-ipd-caption{
    margin-top:5px;
    color:#71818e;
    font-size:7px;
    line-height:1;
    font-weight:700;
}
.ws-hms-dashboard-ipd-availability{
    width:100%;
    min-height:38px;
    margin-top:9px;
    padding:6px 7px;
    display:flex;
    align-items:center;
    gap:6px;
    border-radius:8px;
    background:#f4f8fa;
}
.ws-hms-dashboard-ipd-availability-icon{
    width:25px;
    height:25px;
    flex:0 0 25px;
    display:flex;
    align-items:center;
    justify-content:center;
    color:#10a6b5;
}
.ws-hms-dashboard-ipd-availability-icon svg{
    width:20px;
    height:20px;
}
.ws-hms-dashboard-ipd-availability-value{
    color:#147f91;
    font-size:12px;
    font-family: Arial, sans-serif;
    line-height:1.2;
    font-weight:700;
}
.ws-hms-dashboard-ipd-availability-text{
    margin-top:2px;
    color:#7b8a91;
    font-size:7px;
    line-height:1.2;
    font-weight:500;
}
.ws-hms-dashboard-ipd-cards{
    display:grid;
    grid-template-columns:1fr 1fr;
    gap:8px;
}
.ws-hms-dashboard-ipd-card{
    min-height:100px;
    position:relative;
    display:flex;
    align-items:center;
    gap:8px;
    padding:7px 8px;
    border:1px solid;
    border-radius:8px;
}
.ws-hms-dashboard-ipd-card-occupied{
    background:#f5fbfe;
    border-color:#d7edf7;
}
.ws-hms-dashboard-ipd-card-available{
    background:#f3fcf8;
    border-color:#d4f1e3;
}
.ws-hms-dashboard-ipd-card-total{
    background:#fffaf0;
    border-color:#f8e9c6;
}
.ws-hms-dashboard-ipd-card-maintenance{
    background:#fff5f6;
    border-color:#f8dfe3;
}
.ws-hms-dashboard-ipd-card-icon{
    width:34px;
    height:34px;
    flex:0 0 28px;
    display:flex;
    align-items:center;
    justify-content:center;
    border-radius:50%;
}
.ws-hms-dashboard-ipd-card-icon svg{
    width:19px;
    height:19px;
}
.ws-hms-dashboard-ipd-card-occupied .ws-hms-dashboard-ipd-card-icon{
    background:#e2f5fa;
    color:#0c9db0;
}
.ws-hms-dashboard-ipd-card-available .ws-hms-dashboard-ipd-card-icon{
    background:#ddf7eb;
    color:#18ae79;
}
.ws-hms-dashboard-ipd-card-total .ws-hms-dashboard-ipd-card-icon{
    background:#fff0ce;
    color:#e69b00;
}
.ws-hms-dashboard-ipd-card-maintenance .ws-hms-dashboard-ipd-card-icon{
    background:#fbe0e4;
    color:#d8324a;
}
.ws-hms-dashboard-ipd-card-main{
    min-width:0;
    flex:1;
}
.ws-hms-dashboard-ipd-card-title{
    color:#556b83;
    font-size:12px;
    line-height:1.15;
    font-weight:750;
}
.ws-hms-dashboard-ipd-card-value{
    margin-top:2px;
    font-size:25px;
    line-height:1;
    font-weight:850;
}
.ws-hms-dashboard-ipd-card-occupied .ws-hms-dashboard-ipd-card-value{
    color:#078ca0;
}
.ws-hms-dashboard-ipd-card-available .ws-hms-dashboard-ipd-card-value{
    color:#10a56e;
}
.ws-hms-dashboard-ipd-card-total .ws-hms-dashboard-ipd-card-value{
    color:#d99000;
}
.ws-hms-dashboard-ipd-card-maintenance .ws-hms-dashboard-ipd-card-value{
    color:#c9253c;
}
.ws-hms-dashboard-ipd-card-description{
    display:none;
}
.ws-hms-dashboard-ipd-card-percent{
    position:absolute;
    top:8px;
    right:8px;
    padding:4px 7px;
    border-radius:20px;
    font-size:10px;
    line-height:1;
    font-weight:800;
}
.ws-hms-dashboard-ipd-card-occupied .ws-hms-dashboard-ipd-card-percent{
    background:#e2f3fd;
    color:#0878c9;
}
.ws-hms-dashboard-ipd-card-available .ws-hms-dashboard-ipd-card-percent{
    background:#ddf7eb;
    color:#0a9e6c;
}
.ws-hms-dashboard-ipd-card-total .ws-hms-dashboard-ipd-card-percent{
    background:#fff0d0;
    color:#c98800;
}
.ws-hms-dashboard-ipd-card-maintenance .ws-hms-dashboard-ipd-card-percent{
    background:#fbe0e4;
    color:#c5263e;
}
.ws-hms-dashboard-ipd-progress{
    height:4px;
    margin-top:4px;
    overflow:hidden;
    border-radius:10px;
    background:#e2e8ed;
}
.ws-hms-dashboard-ipd-progress span{
    display:block;
    width:0;
    height:100%;
    border-radius:10px;
}
.ws-hms-dashboard-ipd-card-occupied .ws-hms-dashboard-ipd-progress span{
    background:#13a6b5;
}
.ws-hms-dashboard-ipd-card-available .ws-hms-dashboard-ipd-progress span{
    background:#3bc58d;
}
.ws-hms-dashboard-ipd-card-total .ws-hms-dashboard-ipd-progress span{
    background:#f2ae19;
}
.ws-hms-dashboard-ipd-card-maintenance .ws-hms-dashboard-ipd-progress span{
    background:#df5368;
}
.ws-hms-dashboard-ipd-footer{
    min-height:35px;
    margin:0 10px;
    padding:5px 0;
    display:flex;
    align-items:center;
    justify-content:space-between;
    gap:8px;
    border-top:1px dashed #e4ebef;
}
.ws-hms-dashboard-ipd-footer-info{
    display:flex;
    align-items:center;
    gap:5px;
    color:#71818e;
    font-size:7px;
    line-height:1.2;
    font-weight:500;
}
.ws-hms-dashboard-ipd-info-icon{
    width:17px;
    height:17px;
    flex:0 0 17px;
    display:flex;
    align-items:center;
    justify-content:center;
    border-radius:50%;
    background:#12a6b5;
    color:#fff;
    font-size:10px;
    font-weight:800;
}
.ws-hms-dashboard-ipd-updated{
    display:flex;
    align-items:center;
    gap:4px;
    color:#71818e;
    font-size:7px;
    white-space:nowrap;
}
.ws-hms-dashboard-ipd-updated svg{
    width:13px;
    height:13px;
    color:#10a5b4;
}
/* RESPONSIVE */
@media(max-width:1400px){
    .ws-hms-dashboard-kpis{
        grid-template-columns:repeat(3,1fr);
    }
    .ws-hms-dashboard-filter-grid{
        grid-template-columns:repeat(4,1fr);
    }
}
@media(max-width:1100px){
    .ws-hms-dashboard-grid-3,
    .ws-hms-dashboard-grid-2,
    .ws-hms-dashboard-grid-4{
        grid-template-columns:1fr 1fr;
    }
}
@media(max-width:900px){
    .ws-hms-dashboard-grid-3,
    .ws-hms-dashboard-grid-2,
    .ws-hms-dashboard-grid-4{
        grid-template-columns:1fr;
    }
    .ws-hms-dashboard-kpis{
        grid-template-columns:repeat(2,1fr);
    }
    .ws-hms-dashboard-filter-grid{
        grid-template-columns:repeat(2,1fr);
    }
}
@media(max-width:600px){
    .ws-hms-dashboard{
        padding:7px;
    }
    .ws-hms-dashboard-top{
        align-items:flex-start;
        flex-direction:column;
    }
    .ws-hms-dashboard-kpis{
        grid-template-columns:1fr;
    }
    .ws-hms-dashboard-filter-grid{
        grid-template-columns:1fr;
    }
    .ws-hms-dashboard-occupancy{
        grid-template-columns:1fr;
    }
}
/* =========================================================
   PROFESSIONAL DASHBOARD TABLE UI
   SAME TABLE UI FOR TOP SERVICES / RECENT IPD / LOW STOCK
   IPD BED OCCUPANCY IS NOT AFFECTED
   ========================================================= */
.ws-hms-dashboard-panel-head{
    padding:9px 13px;
    background:#fff;
    border-bottom:1px solid #edf2f3;
}
.ws-hms-dashboard-panel-title{
    margin:0;
    font-size:12px;
    line-height:1.25;
    font-weight:750;
    color:#253638;
    letter-spacing:-.1px;
}
.ws-hms-dashboard-panel-subtitle{
    margin-top:3px;
    font-size:9px;
    color:#8a989b;
    line-height:1.25;
}
.ws-hms-dashboard-panel-action{
    display:inline-flex;
    align-items:center;
    gap:4px;
    padding:5px 7px;
    border-radius:6px;
    color:#099aa7;
    font-size:9px;
    font-weight:750;
    text-decoration:none;
    transition:all .15s ease;
}
.ws-hms-dashboard-panel-action:hover{
    background:#eef9fa;
    color:#078491;
}
.ws-hms-dashboard-panel-body{
    padding:2px 2px 2px;
}
.ws-hms-dashboard-table-wrap{
    width:100%;
    margin:0;
    padding:0;
    overflow:hidden;
    border:1px solid #e8eef0;
    border-radius:8px;
}
.ws-hms-dashboard-table{
    width:100%;
    border-collapse:separate;
    border-spacing:0;
    table-layout:auto;
    font-size:10px;
    background:#fff;
}
.ws-hms-dashboard-table thead th{
    height:35px;
    padding:7px 8px;
    background:#f6f9fa !important;
    color:#647477 !important;
    border-bottom:1px solid #e3eaec !important;
    font-size:9px;
    font-weight:750;
    text-transform:none;
    letter-spacing:.05px;
    vertical-align:middle;
}
.ws-hms-dashboard-table tbody td{
    height:43px;
    padding:7px 8px;
    background:#fff;
    color:#405154;
    border-bottom:1px solid #edf2f3;
    font-size:10px;
    vertical-align:middle;
    transition:background .15s ease;
}
.ws-hms-dashboard-table tbody tr:last-child td{
    border-bottom:0;
}
.ws-hms-dashboard-table tbody tr:hover td{
    background:#f9fcfc;
}
/* COMMON TABLE TEXT */
.ws-hms-dashboard-table tbody td:first-child{
    color:#405154;
    font-weight:600;
}
/* MONEY */
.ws-hms-dashboard-money{
    color:#128c94 !important;
    font-size:10px !important;
    font-weight:800 !important;
    white-space:nowrap;
}
/* EMPTY */
.ws-hms-dashboard-empty{
    padding:25px 10px;
    color:#9aa7a9;
    font-size:10px;
    text-align:center;
}
/* LOW STOCK */
.ws-hms-dashboard-stock-low{
    display:table-cell;
    color:#d84758 !important;
    font-weight:850 !important;
}
.ws-hms-dashboard-stock-ok{
    color:#168c50 !important;
    font-weight:750 !important;
}
/* LOW STOCK - KEEP SAME TABLE ALIGNMENT AS TOP SERVICES */
.ws-hms-dashboard-panel:has(.ws-hms-dashboard-stock-low) .ws-hms-dashboard-table th,
.ws-hms-dashboard-panel:has(.ws-hms-dashboard-stock-low) .ws-hms-dashboard-table td{
    text-align:left;
}
/* RECENT IPD STATUS */
.ws-hms-dashboard-status{
    min-height:22px;
    padding:4px 8px;
    display:inline-flex;
    align-items:center;
    justify-content:center;
    border-radius:999px;
    font-size:8px;
    line-height:1;
    font-weight:750;
    white-space:nowrap;
}
.ws-hms-dashboard-status-admitted{
    background:#e7f8ee;
    color:#16864b;
    border:1px solid #d2f0df;
}
.ws-hms-dashboard-status-emergency{
    background:#fff0f2;
    color:#d5485b;
    border:1px solid #f9dce1;
}
.ws-hms-dashboard-status-normal{
    background:#eef3f5;
    color:#617174;
    border:1px solid #e1e9eb;
}
/* SYSTEM ALERTS */
.ws-hms-dashboard-alerts{
    display:flex;
    flex-direction:column;
    gap:7px;
}
.ws-hms-dashboard-alert{
    min-height:52px;
    padding:8px 10px;
    border:1px solid #edf1f3;
    border-radius:9px;
    background:#f9fbfc;
    transition:
        transform .15s ease,
        border-color .15s ease,
        box-shadow .15s ease;
}
.ws-hms-dashboard-alert:hover{
    transform:translateY(-1px);
    border-color:#dce7e9;
    box-shadow:0 3px 9px rgba(31,47,49,.045);
}
.ws-hms-dashboard-alert-left{
    gap:9px;
}
.ws-hms-dashboard-alert-icon{
    width:28px;
    height:28px;
    flex:0 0 28px;
    border-radius:8px;
    background:#fff0f2;
    color:#d94e60;
    border:1px solid #f8dde2;
}
.ws-hms-dashboard-alert-icon svg{
    width:14px;
    height:14px;
}
.ws-hms-dashboard-alert-title{
    font-size:9px;
    line-height:1.2;
    font-weight:750;
    color:#3e5154;
}
.ws-hms-dashboard-alert-desc{
    margin-top:3px;
    font-size:8px;
    line-height:1.2;
    color:#8a989b;
}
.ws-hms-dashboard-alert-count{
    min-width:28px;
    height:24px;
    padding:0 6px;
    display:flex;
    align-items:center;
    justify-content:center;
    border-radius:7px;
    background:#fff0f2;
    color:#d94d5f;
    border:1px solid #f8dde2;
    font-size:10px;
    font-weight:850;
}
/* 3-COLUMN TABLES
   TOP SERVICES / MOST SELLING MEDICINES */
.ws-hms-dashboard-table-3col{
    width:100% !important;
    max-width:100% !important;
    margin:0 !important;
    padding:0 !important;
    border-spacing:0 !important;
    table-layout:fixed;
}
.ws-hms-dashboard-table-3col th,
.ws-hms-dashboard-table-3col td{
    padding-left:10px;
    padding-right:10px;
    text-align:left;
}
.ws-hms-dashboard-table-3col th:first-child,
.ws-hms-dashboard-table-3col td:first-child{
    width:auto;
    text-align:left;
}
.ws-hms-dashboard-table-3col th:nth-child(2),
.ws-hms-dashboard-table-3col td:nth-child(2){
    width:55px;
    text-align:left;
}
.ws-hms-dashboard-table-3col th:nth-child(3),
.ws-hms-dashboard-table-3col td:nth-child(3){
    width:95px;
    text-align:left;
}
/* PANEL WITH SHORT DATA SHOULD STAY COMPACT */
.ws-hms-dashboard-ipd-panel ~ .ws-hms-dashboard-panel .ws-hms-dashboard-panel-body{
    min-height:240px;
}
/* MOBILE */
@media(max-width:900px){
    .ws-hms-dashboard-table{
        font-size:9px;
    }
    .ws-hms-dashboard-table thead th{
        font-size:8px;
    }
    .ws-hms-dashboard-table tbody td{
        font-size:9px;
        padding:7px 6px;
    }
    .ws-hms-dashboard-table-3col th,
    .ws-hms-dashboard-table-3col td{
        padding-left:7px;
        padding-right:7px;
    }
}
/* ALIGN ALL DASHBOARD TABLES LIKE TOP SERVICES */
.ws-hms-dashboard-table{
    width:100% !important;
    max-width:100% !important;
    margin:0 !important;
    padding:0 !important;
    border-spacing:0 !important;
    table-layout:fixed;
}
.ws-hms-dashboard-table-wrap{
    width:100% !important;
    margin:0 !important;
    padding:0 !important;
    overflow:hidden;
}
</style>
</head>

<body>

<?php require $_SERVER['DOCUMENT_ROOT'].'/includes/header.php'; ?>

<div class="layout">

<?php require $_SERVER['DOCUMENT_ROOT'].'/includes/sidebar.php'; ?>

<div class="main-content" id="mainContent">

<div class="ws-hms-dashboard">

<?php
$user_id=(int)$_SESSION['user_id'];

$sql="
SELECT a.*
FROM announcements a
LEFT JOIN announcement_users au
    ON a.id=au.announcement_id
WHERE a.is_active=1
AND NOW() BETWEEN a.start_date AND a.end_date
AND (a.is_global=1 OR au.user_id=?)
ORDER BY a.id DESC
";

$stmtA=$conn->prepare($sql);

if($stmtA){
    $stmtA->bind_param("i",$user_id);
    $stmtA->execute();
    $resA=$stmtA->get_result();

    if($resA->num_rows>0):
?>

<div class="ws-hms-dashboard-announcements">

<?php while($a=$resA->fetch_assoc()): ?>

<div class="ws-hms-dashboard-announcement">

<div class="ws-hms-dashboard-announcement-icon">
<svg viewBox="0 0 24 24" width="15" height="15" fill="none" stroke="currentColor" stroke-width="2">
<path d="M18 8a6 6 0 0 0-12 0c0 7-3 7-3 9h18c0-2-3-2-3-9"/>
<path d="M10 21h4"/>
</svg>
</div>

<div class="ws-hms-dashboard-announcement-body">

<div class="ws-hms-dashboard-announcement-title">
<?=htmlspecialchars($a['title'])?>
</div>

<div class="ws-hms-dashboard-announcement-text">
<?=htmlspecialchars($a['description'])?>
</div>

<div class="ws-hms-dashboard-announcement-links">

<?php
if(!empty($a['url'])):
$url=$a['url'];
if(!preg_match("~^(?:f|ht)tps?://~i",$url)){
    $url="https://".$url;
}
?>

<a href="<?=htmlspecialchars($url)?>" target="_blank" rel="noopener noreferrer">
View
</a>

<?php endif; ?>

<?php if(!empty($a['file_path'])): ?>

<a href="<?=htmlspecialchars($a['file_path'])?>" target="_blank">
Download
</a>

<?php endif; ?>

</div>

</div>

<div class="ws-hms-dashboard-announcement-close" onclick="this.parentElement.remove()">
×
</div>
</div>
<?php endwhile; ?>
</div>
<?php
    endif;
    $stmtA->close();
}
?>

<!-- FILTERS -->
<div class="ws-hms-dashboard-filter" id="wsDashboardFilter">
<div class="ws-hms-dashboard-filter-heading">
<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
<path d="M4 5h16M7 12h10M10 19h4"/>
</svg>
<span>Dashboard Filters</span>
</div>
<div class="ws-hms-dashboard-filter-grid">
    
<div class="ws-hms-dashboard-filter-item">
<select id="source">
<option value="All">All Sources</option>
<option value="Website">Website</option>
<option value="Direct">Direct</option>
</select>
</div>
<div class="ws-hms-dashboard-filter-item">
<select id="date_filter">
<option value="today" >Today</option>
<option value="yesterday">Yesterday</option>
<option value="current_month">Current Month</option>
<option value="current_week">Current Week</option>
<option value="last_month">Last Month</option>
<option value="last_3_months">Last 3 Months</option>
<option value="last_6_months">Last 6 Months</option>
<option value="current_year">Current Year</option>
<option value="last_year">Last Year</option>
<option value="all" selected >All Appointment Dates</option>
</select>
</div>

<div class="ws-hms-dashboard-filter-item">
<select id="created_date_filter">
<option value="all" selected>All Created Dates</option>
<option value="today">Created Today</option>
<option value="yesterday">Created Yesterday</option>
<option value="current_week">Created This Week</option>
<option value="current_month">Created This Month</option>
<option value="last_month">Created Last Month</option>
<option value="last_3_months">Created Last 3 Months</option>
<option value="last_6_months">Created Last 6 Months</option>
<option value="current_year">Created This Year</option>
<option value="last_year">Created Last Year</option>
</select>
</div>

<div class="ws-hms-dashboard-filter-item">
<select id="doctor">
<option value="">All Doctors</option>
</select>
</div>

<div class="ws-hms-dashboard-filter-item">
<select id="department">
<option value="">All Departments</option>
</select>
</div>

<div class="ws-hms-dashboard-filter-item">
<select id="branch">
<option value="">All Branches</option>
</select>
</div>

<div class="ws-hms-dashboard-filter-item">
<select id="hospital">
<option value="">All Hospitals</option>
</select>
</div>

</div>
</div>
<!-- KPI CARDS -->
<div class="ws-hms-dashboard-kpis">

<div class="ws-hms-dashboard-kpi ws-hms-dashboard-kpi-blue">

<div class="ws-hms-dashboard-kpi-arrow">→</div>

<div class="ws-hms-dashboard-kpi-icon">
<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
<rect x="3" y="4" width="18" height="17" rx="2"/>
<path d="M16 2v4M8 2v4M3 10h18"/>
<path d="M8 14h2M14 14h2M8 18h2"/>
</svg>
</div>

<div class="ws-hms-dashboard-kpi-label">Today's Appointments</div>

<div class="ws-hms-dashboard-kpi-value" id="wsKpiAppointments">0</div>

<div class="ws-hms-dashboard-kpi-meta">
<span>vs Yesterday</span>
<span class="ws-hms-dashboard-change ws-hms-dashboard-neutral" id="wsKpiAppointmentsChange">—</span>
</div>

<div class="ws-hms-dashboard-kpi-bars">
<span></span>
<span></span>
<span></span>
</div>

</div>

<div class="ws-hms-dashboard-kpi ws-hms-dashboard-kpi-green">

<div class="ws-hms-dashboard-kpi-arrow">→</div>

<div class="ws-hms-dashboard-kpi-icon">
<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
<circle cx="12" cy="8" r="4"/>
<path d="M4 21c0-4 3.5-7 8-7s8 3 8 7"/>
<path d="M19 8v6M16 11h6"/>
</svg>
</div>

<div class="ws-hms-dashboard-kpi-label">OPD Patients</div>

<div class="ws-hms-dashboard-kpi-value" id="wsKpiOPD">0</div>

<div class="ws-hms-dashboard-kpi-meta">
<span>Today's activity</span>
<span id="wsKpiOPDMeta">OPD</span>
</div>

<div class="ws-hms-dashboard-kpi-bars">
<span></span>
<span></span>
<span></span>
</div>

</div>

<div class="ws-hms-dashboard-kpi ws-hms-dashboard-kpi-pink">

<div class="ws-hms-dashboard-kpi-arrow">→</div>

<div class="ws-hms-dashboard-kpi-icon">
<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
<path d="M4 18h16V8H4z"/>
<path d="M4 11h16M8 8V5h8v3"/>
<path d="M7 15h3M14 15h3"/>
</svg>
</div>

<div class="ws-hms-dashboard-kpi-label">IPD Patients</div>

<div class="ws-hms-dashboard-kpi-value" id="wsKpiIPD">0</div>

<div class="ws-hms-dashboard-kpi-meta">
<span>Currently admitted</span>
<span>IPD</span>
</div>

<div class="ws-hms-dashboard-kpi-bars">
<span></span>
<span></span>
<span></span>
</div>

</div>

<div class="ws-hms-dashboard-kpi ws-hms-dashboard-kpi-yellow">

<div class="ws-hms-dashboard-kpi-arrow">→</div>

<div class="ws-hms-dashboard-kpi-icon">
<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
<rect x="5" y="7" width="14" height="10" rx="5" transform="rotate(-45 12 12)"/>
<path d="M9 9l6 6"/>
</svg>
</div>

<div class="ws-hms-dashboard-kpi-label">Pharmacy Sales</div>

<div class="ws-hms-dashboard-kpi-value" id="wsKpiPharmacy">₹0.00</div>

<div class="ws-hms-dashboard-kpi-meta">
<span>Today's sales</span>
<span>Pharmacy</span>
</div>

<div class="ws-hms-dashboard-kpi-bars">
<span></span>
<span></span>
<span></span>
</div>

</div>

<div class="ws-hms-dashboard-kpi ws-hms-dashboard-kpi-purple">

<div class="ws-hms-dashboard-kpi-arrow">→</div>

<div class="ws-hms-dashboard-kpi-icon">
<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
<ellipse cx="12" cy="6" rx="7" ry="3"/>
<path d="M5 6v6c0 1.7 3.1 3 7 3s7-1.3 7-3V6"/>
<path d="M5 12v6c0 1.7 3.1 3 7 3s7-1.3 7-3v-6"/>
</svg>
</div>

<div class="ws-hms-dashboard-kpi-label">Total Revenue</div>

<div class="ws-hms-dashboard-kpi-value" id="wsKpiRevenue">₹0.00</div>

<div class="ws-hms-dashboard-kpi-meta">
<span>Collected today</span>
<span class="ws-hms-dashboard-change ws-hms-dashboard-neutral" id="wsKpiRevenueChange">—</span>
</div>

<div class="ws-hms-dashboard-kpi-bars">
<span></span>
<span></span>
<span></span>
</div>

</div>

<div class="ws-hms-dashboard-kpi ws-hms-dashboard-kpi-cyan">

<div class="ws-hms-dashboard-kpi-arrow">→</div>

<div class="ws-hms-dashboard-kpi-icon">
<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
<circle cx="12" cy="8" r="4"/>
<path d="M4 21c0-4 3.5-7 8-7s8 3 8 7"/>
<path d="M5 5l2 2M19 5l-2 2"/>
</svg>
</div>

<div class="ws-hms-dashboard-kpi-label">Today's Registrations</div>

<div class="ws-hms-dashboard-kpi-value" id="wsKpiRegistrations">0</div>

<div class="ws-hms-dashboard-kpi-meta">
<span>New patients</span>
<span>Today</span>
</div>

<div class="ws-hms-dashboard-kpi-bars">
<span></span>
<span></span>
<span></span>
</div>

</div>

</div>
<!-- CHART ROW -->

<div class="ws-hms-dashboard-grid ws-hms-dashboard-grid-3">
<div class="ws-hms-dashboard-panel ws-hms-dashboard-ipd-panel">
<div class="ws-hms-dashboard-ipd-header">
<div class="ws-hms-dashboard-ipd-title-wrap">
<div class="ws-hms-dashboard-ipd-icon">
<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round">
<path d="M3 20V9"/>
<path d="M3 20h18"/>
<path d="M7 20V7"/>
<path d="M7 7h8a4 4 0 0 1 4 4v9"/>
<path d="M11 11h4"/>
<path d="M13 9v4"/>
<circle cx="16" cy="15" r="2"/>
</svg>
</div>
<div>
<h3 class="ws-hms-dashboard-ipd-title">IPD Bed Occupancy</h3>
</div>
</div>
<a href="/masters/rooms/bed_dashboard.php" class="ws-hms-dashboard-ipd-details">
<span>View Details</span>
<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.4" stroke-linecap="round" stroke-linejoin="round">
<path d="M14 5h5v5"/>
<path d="M10 14L19 5"/>
<path d="M19 13v5a1 1 0 0 1-1 1H6a1 1 0 0 1-1-1V6a1 1 0 0 1 1-1h5"/>
</svg>
<svg class="ws-hms-dashboard-ipd-arrow" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.4" stroke-linecap="round" stroke-linejoin="round">
<path d="m9 18 6-6-6-6"/>
</svg>
</a>
</div>
<div class="ws-hms-dashboard-ipd-content">
<div class="ws-hms-dashboard-ipd-left">
<div class="ws-hms-dashboard-ipd-donut" id="wsOccupancyCircle">
<div class="ws-hms-dashboard-ipd-donut-inner">
<div class="ws-hms-dashboard-ipd-percent" id="wsOccupancyPercent">0%</div>
<div class="ws-hms-dashboard-ipd-caption">OCCUPIED</div>
</div>
</div>
<div class="ws-hms-dashboard-ipd-availability">
<div class="ws-hms-dashboard-ipd-availability-icon">
<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.4" stroke-linecap="round" stroke-linejoin="round">
<path d="M4 19v-5"/>
<path d="M9 19V9"/>
<path d="M14 19V5"/>
<path d="M19 19V12"/>
</svg>
</div>
<div>
<div class="ws-hms-dashboard-ipd-availability-value"><span id="wsIpdAvailableSummary">0</span> beds available</div>
<div class="ws-hms-dashboard-ipd-availability-text">Out of <span id="wsIpdTotalSummary">0</span> total beds</div>
</div>
</div>
</div>
<div class="ws-hms-dashboard-ipd-cards">
<div class="ws-hms-dashboard-ipd-card ws-hms-dashboard-ipd-card-occupied">
<div class="ws-hms-dashboard-ipd-card-icon">
<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.1" stroke-linecap="round" stroke-linejoin="round">
<path d="M3 19V9"/>
<path d="M3 19h18"/>
<path d="M6 19V8"/>
<path d="M6 8h8a4 4 0 0 1 4 4v7"/>
<circle cx="15" cy="12" r="1.7"/>
</svg>
</div>
<div class="ws-hms-dashboard-ipd-card-main">
<div class="ws-hms-dashboard-ipd-card-title">Occupied Beds</div>
<div class="ws-hms-dashboard-ipd-card-value" id="wsOccupiedBeds">0</div>
<div class="ws-hms-dashboard-ipd-card-description">Currently in use</div>
<div class="ws-hms-dashboard-ipd-progress">
<span id="wsOccupiedProgress"></span>
</div>
</div>
<div class="ws-hms-dashboard-ipd-card-percent" id="wsOccupiedPercent">0%</div>
</div>
<div class="ws-hms-dashboard-ipd-card ws-hms-dashboard-ipd-card-available">
<div class="ws-hms-dashboard-ipd-card-icon">
<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.1" stroke-linecap="round" stroke-linejoin="round">
<path d="M3 19V9"/>
<path d="M3 19h18"/>
<path d="M6 19V8"/>
<path d="M6 8h8a4 4 0 0 1 4 4v7"/>
<circle cx="15" cy="12" r="1.7"/>
</svg>
</div>
<div class="ws-hms-dashboard-ipd-card-main">
<div class="ws-hms-dashboard-ipd-card-title">Available Beds</div>
<div class="ws-hms-dashboard-ipd-card-value" id="wsVacantBeds">0</div>
<div class="ws-hms-dashboard-ipd-card-description">Ready for admission</div>
<div class="ws-hms-dashboard-ipd-progress">
<span id="wsAvailableProgress"></span>
</div>
</div>
<div class="ws-hms-dashboard-ipd-card-percent" id="wsAvailablePercent">0%</div>
</div>
<div class="ws-hms-dashboard-ipd-card ws-hms-dashboard-ipd-card-total">
<div class="ws-hms-dashboard-ipd-card-icon">
<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.1" stroke-linecap="round" stroke-linejoin="round">
<ellipse cx="12" cy="6" rx="7" ry="3"/>
<path d="M5 6v5c0 1.7 3.1 3 7 3s7-1.3 7-3V6"/>
<path d="M5 11v5c0 1.7 3.1 3 7 3s7-1.3 7-3v-5"/>
</svg>
</div>
<div class="ws-hms-dashboard-ipd-card-main">
<div class="ws-hms-dashboard-ipd-card-title">Total Beds</div>
<div class="ws-hms-dashboard-ipd-card-value" id="wsTotalBeds">0</div>
<div class="ws-hms-dashboard-ipd-card-description">Total configured beds</div>
<div class="ws-hms-dashboard-ipd-progress">
<span id="wsTotalProgress"></span>
</div>
</div>
<div class="ws-hms-dashboard-ipd-card-percent">100%</div>
</div>
<div class="ws-hms-dashboard-ipd-card ws-hms-dashboard-ipd-card-maintenance">
<div class="ws-hms-dashboard-ipd-card-icon">
<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.1" stroke-linecap="round" stroke-linejoin="round">
<path d="M12 3v18"/>
<path d="M3 12h18"/>
<path d="M5.5 5.5l13 13"/>
<path d="M18.5 5.5l-13 13"/>
</svg>
</div>
<div class="ws-hms-dashboard-ipd-card-main">
<div class="ws-hms-dashboard-ipd-card-title">Maintenance</div>
<div class="ws-hms-dashboard-ipd-card-value" id="wsMaintenanceBeds">0</div>
<div class="ws-hms-dashboard-ipd-card-description">Beds under maintenance</div>
<div class="ws-hms-dashboard-ipd-progress">
<span id="wsMaintenanceProgress"></span>
</div>
</div>
<div class="ws-hms-dashboard-ipd-card-percent" id="wsMaintenancePercent">0%</div>
</div>
</div>
</div>
</div>
<div class="ws-hms-dashboard-panel">

<div class="ws-hms-dashboard-panel-head">
<div>
<h3 class="ws-hms-dashboard-panel-title">OPD Trend</h3>
</div>
</div>

<div class="ws-hms-dashboard-panel-body">
<div class="ws-hms-dashboard-small-chart">
<canvas id="wsOpdIpdChart"></canvas>
</div>
</div>

</div>

<div class="ws-hms-dashboard-panel">

<div class="ws-hms-dashboard-panel-head">
<div>
<h3 class="ws-hms-dashboard-panel-title">Revenue Trend</h3>
</div>
</div>

<div class="ws-hms-dashboard-panel-body">
<div class="ws-hms-dashboard-medium-chart">
<canvas id="wsRevenueChart"></canvas>
</div>
</div>

</div>

</div>

<!-- OCCUPANCY + SERVICES + MEDICINES -->

<div class="ws-hms-dashboard-grid ws-hms-dashboard-grid-3">

<div class="ws-hms-dashboard-panel">

<div class="ws-hms-dashboard-panel-head">
<div>
<h3 class="ws-hms-dashboard-panel-title">Today's Appointments - Status Wise</h3>
</div>
<a href="/modules/appointments/appointment_list.php" class="ws-hms-dashboard-panel-action">View All</a>
</div>

<div class="ws-hms-dashboard-panel-body">
<div class="ws-hms-dashboard-chart">
<canvas id="wsAppointmentsChart"></canvas>
</div>
</div>

</div>


<div class="ws-hms-dashboard-panel">

<div class="ws-hms-dashboard-panel-head">
<div>
<h3 class="ws-hms-dashboard-panel-title">Top Services</h3>
</div>
<a href="#" class="ws-hms-dashboard-panel-action">View All</a>
</div>

<div class="ws-hms-dashboard-panel-body">

<div class="ws-hms-dashboard-table-wrap">

<table class="ws-hms-dashboard-table ws-hms-dashboard-table-3col">

<thead>
<tr>
<th>Service</th>
<th style="width:55px">Qty</th>
<th style="width:75px">Revenue</th>
</tr>
</thead>

<tbody>

<?php if(!empty($topServices)): ?>

<?php foreach($topServices as $index=>$service): ?>

<tr>
<td title="<?=htmlspecialchars($service['service_name'])?>">
<?=htmlspecialchars($service['service_name'])?>
</td>
<td>
<?=number_format((float)$service['quantity_sold'],0)?>
</td>
<td class="ws-hms-dashboard-money">
₹<?=number_format((float)$service['revenue'],2)?>
</td>
</tr>

<?php endforeach; ?>

<?php else: ?>

<tr>
<td colspan="4">
<div class="ws-hms-dashboard-empty">No billed services found.</div>
</td>
</tr>

<?php endif; ?>

</tbody>

</table>

</div>

</div>

</div>

<div class="ws-hms-dashboard-panel">

<div class="ws-hms-dashboard-panel-head">
<div>
<h3 class="ws-hms-dashboard-panel-title">Most Selling Medicines</h3>
</div>
<a href="#" class="ws-hms-dashboard-panel-action">View All</a>
</div>

<div class="ws-hms-dashboard-panel-body">

<div class="ws-hms-dashboard-table-wrap">

<table class="ws-hms-dashboard-table ws-hms-dashboard-table-3col">

<thead>
<tr>
<th>Medicine</th>
<th style="width:55px">Qty</th>
<th style="width:75px">Revenue</th>
</tr>
</thead>

<tbody>

<?php if(!empty($mostSellingMedicines)): ?>

<?php foreach($mostSellingMedicines as $index=>$medicine): ?>

<tr>
<td title="<?=htmlspecialchars($medicine['item_name'])?>">
<?=htmlspecialchars($medicine['item_name'])?>
</td>
<td>
<?=number_format((float)$medicine['quantity_sold'],0)?>
</td>
<td class="ws-hms-dashboard-money">
₹<?=number_format((float)$medicine['revenue'],2)?>
</td>
</tr>

<?php endforeach; ?>

<?php else: ?>

<tr>
<td colspan="3">
<div class="ws-hms-dashboard-empty">No medicine sales found.</div>
</td>
</tr>

<?php endif; ?>

</tbody>

</table>

</div>

</div>

</div>

</div>

<!-- RECENT IPD + LOW STOCK + ALERTS -->

<div class="ws-hms-dashboard-grid ws-hms-dashboard-grid-3">

<div class="ws-hms-dashboard-panel">

<div class="ws-hms-dashboard-panel-head">
<div>
<h3 class="ws-hms-dashboard-panel-title">Recent IPD Admissions</h3>
</div>
<a href="#" class="ws-hms-dashboard-panel-action">View All</a>
</div>

<div class="ws-hms-dashboard-panel-body">

<div class="ws-hms-dashboard-table-wrap">

<table class="ws-hms-dashboard-table">

<thead>
<tr>
<th>IPD No.</th>
<th>Patient</th>
<th>Type</th>
<th>Doctor</th>
<th>Date</th>
<th>Status</th>
</tr>
</thead>

<tbody id="wsRecentAdmissions">

<tr>
<td colspan="6">
<div class="ws-hms-dashboard-empty">Loading admissions...</div>
</td>
</tr>

</tbody>

</table>

</div>

</div>

</div>

<div class="ws-hms-dashboard-panel">

<div class="ws-hms-dashboard-panel-head">
<div>
<h3 class="ws-hms-dashboard-panel-title">Low Stock Medicines</h3>
</div>
<a href="#" class="ws-hms-dashboard-panel-action">View All</a>
</div>

<div class="ws-hms-dashboard-panel-body">

<div class="ws-hms-dashboard-table-wrap">

<table class="ws-hms-dashboard-table">

<thead>
<tr>
<th>Medicine</th>
<th style="width:55px">Stock</th>
<th style="width:65px">Reorder</th>
</tr>
</thead>

<tbody>

<?php if(!empty($lowStockMedicines)): ?>

<?php foreach($lowStockMedicines as $medicine): ?>

<?php
$currentStock=(float)$medicine['current_stock'];
$reorderLevel=(float)$medicine['reorder_level'];
?>

<tr>

<td title="<?=htmlspecialchars($medicine['item_name'])?>">
<?=htmlspecialchars($medicine['item_name'])?>
</td>

<td class="<?=$currentStock<=$reorderLevel?'ws-hms-dashboard-stock-low':'ws-hms-dashboard-stock-ok'?>">
<?=number_format($currentStock,0)?>
</td>

<td>
<?=number_format($reorderLevel,0)?>
</td>

</tr>

<?php endforeach; ?>

<?php else: ?>

<tr>
<td colspan="3">
<div class="ws-hms-dashboard-empty">No low-stock medicines.</div>
</td>
</tr>

<?php endif; ?>

</tbody>

</table>

</div>

</div>

</div>

<div class="ws-hms-dashboard-panel">

<div class="ws-hms-dashboard-panel-head">
<div>
<h3 class="ws-hms-dashboard-panel-title">System Alerts</h3>
</div>
<a href="#" class="ws-hms-dashboard-panel-action">View All</a>
</div>

<div class="ws-hms-dashboard-panel-body">

<div class="ws-hms-dashboard-alerts">

<div class="ws-hms-dashboard-alert">

<div class="ws-hms-dashboard-alert-left">

<div class="ws-hms-dashboard-alert-icon">
<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
<path d="M12 3L2 21h20L12 3z"/>
<path d="M12 9v5M12 18h.01"/>
</svg>
</div>

<div>
<div class="ws-hms-dashboard-alert-title">Pending Payments</div>
<div class="ws-hms-dashboard-alert-desc">Appointments awaiting payment</div>
</div>

</div>

<div class="ws-hms-dashboard-alert-count" id="wsAlertPending">0</div>

</div>

<div class="ws-hms-dashboard-alert">

<div class="ws-hms-dashboard-alert-left">

<div class="ws-hms-dashboard-alert-icon">
<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
<path d="M5 12h14M12 5l7 7-7 7"/>
</svg>
</div>

<div>
<div class="ws-hms-dashboard-alert-title">Emergency Patients</div>
<div class="ws-hms-dashboard-alert-desc">Today's emergency appointments</div>
</div>

</div>

<div class="ws-hms-dashboard-alert-count" id="wsAlertEmergency">0</div>

</div>

<div class="ws-hms-dashboard-alert">

<div class="ws-hms-dashboard-alert-left">

<div class="ws-hms-dashboard-alert-icon">
<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
<path d="M4 19h16M6 17V9h12v8M9 6h6"/>
</svg>
</div>

<div>
<div class="ws-hms-dashboard-alert-title">Vacant Beds</div>
<div class="ws-hms-dashboard-alert-desc">Beds currently available</div>
</div>

</div>

<div class="ws-hms-dashboard-alert-count" id="wsAlertVacantBeds">0</div>

</div>

</div>

</div>

</div>

</div>

</div>

</div>
<script>
const wsSource=document.getElementById('source');
const wsDateFilter=document.getElementById('date_filter');
const wsCreatedDateFilter=document.getElementById('created_date_filter');
const wsDoctor=document.getElementById('doctor');
const wsDepartment=document.getElementById('department');
const wsBranch=document.getElementById('branch');
const wsHospital=document.getElementById('hospital');

let wsAppointmentsChart=null;
let wsOpdIpdChart=null;
let wsRevenueChart=null;

function wsSet(id,value){
    const el=document.getElementById(id);
    if(el) el.textContent=value;
}

function wsMoney(value){
    const number=parseFloat(value||0);
    return '₹'+number.toLocaleString('en-IN',{
        minimumFractionDigits:2,
        maximumFractionDigits:2
    });
}

function wsNumber(value){
    return parseInt(value||0,10).toLocaleString('en-IN');
}

function wsChange(today,yesterday){
    const t=parseFloat(today||0);
    const y=parseFloat(yesterday||0);
    if(y===0&&t===0){
        return{
            text:'0%',
            className:'ws-hms-dashboard-neutral'
        };
    }
    if(y===0){
        return{
            text:'+100%',
            className:'ws-hms-dashboard-up'
        };
    }
    const change=((t-y)/y)*100;
    return{
        text:(change>=0?'+':'')+change.toFixed(1)+'%',
        className:
            change>0
                ?'ws-hms-dashboard-up'
                :change<0
                    ?'ws-hms-dashboard-down'
                    :'ws-hms-dashboard-neutral'
    };
}

function wsRenderChange(id,today,yesterday){
    const el=document.getElementById(id);
    if(!el) return;
    const result=wsChange(today,yesterday);
    el.textContent=result.text;
    el.className='ws-hms-dashboard-change '+result.className;
}

function wsLoadFilters(){
    return fetch(
        '/dashboard/ajax/dashboard_filters.php',
        {
            cache:'no-store'
        }
    )
    .then(response=>{
        if(!response.ok){
            throw new Error(
                'Filter request failed: '+response.status
            );
        }
        return response.json();
    })
    .then(data=>{
        wsDoctor.innerHTML='<option value="">All Doctors</option>';
        (data.doctors||[]).forEach(row=>{
            const option=document.createElement('option');
            option.value=row.doctor_id;
            option.textContent=row.doctor_name;
            wsDoctor.appendChild(option);
        });

        wsDepartment.innerHTML='<option value="">All Departments</option>';
        (data.departments||[]).forEach(row=>{
            const option=document.createElement('option');
            option.value=row.department_id;
            option.textContent=row.department_name;
            wsDepartment.appendChild(option);
        });

        wsBranch.innerHTML='<option value="">All Branches</option>';
        (data.branches||[]).forEach(row=>{
            const option=document.createElement('option');
            option.value=row.branch_id;
            option.textContent=row.branch_name;
            wsBranch.appendChild(option);
        });

        wsHospital.innerHTML='<option value="">All Hospitals</option>';
        (data.hospitals||[]).forEach(row=>{
            const option=document.createElement('option');
            option.value=row.hospital_id;
            option.textContent=row.hospital_name;
            wsHospital.appendChild(option);
        });
    });
}

function wsLoadCoreStats(){
    const formData=new FormData();

    formData.append('source',wsSource.value);
    formData.append('date_filter',wsDateFilter.value);
    formData.append('created_date_filter',wsCreatedDateFilter.value);
    formData.append('doctor',wsDoctor.value);
    formData.append('department',wsDepartment.value);
    formData.append('branch',wsBranch.value);
    formData.append('hospital',wsHospital.value);

    const dashboard=document.querySelector(
        '.ws-hms-dashboard'
    );

    dashboard.classList.add(
        'ws-hms-dashboard-loading'
    );

    return fetch(
        '/dashboard/ajax/dashboard_stats.php',
        {
            method:'POST',
            body:formData,
            cache:'no-store'
        }
    )
    .then(response=>{
        if(!response.ok){
            throw new Error(
                'Dashboard request failed: '+
                response.status
            );
        }
        return response.json();
    })
    .then(data=>{
        console.log(
            'Dashboard data:',
            data
        );

        const today=data.today||{};
        const yesterday=data.yesterday||{};
        const pharmacy=data.pharmacy||{};
        const beds=data.beds||{};

        wsSet(
            'wsKpiAppointments',
            wsNumber(today.appointments)
        );

        wsSet(
            'wsKpiOPD',
            wsNumber(today.opd)
        );

        wsSet(
            'wsKpiPharmacy',
            wsMoney(pharmacy.sales)
        );

        wsSet(
            'wsKpiRevenue',
            wsMoney(today.revenue)
        );

        wsSet(
            'wsKpiRegistrations',
            wsNumber(data.registrations)
        );

        wsSet(
            'wsAlertPending',
            wsNumber(data.pending_payment_count)
        );

        wsSet(
            'wsAlertEmergency',
            wsNumber(today.emergency)
        );

        wsSet(
            'wsAlertVacantBeds',
            wsNumber(beds.vacant)
        );

        wsRenderChange(
            'wsKpiAppointmentsChange',
            today.appointments,
            yesterday.appointments
        );

        wsRenderChange(
            'wsKpiRevenueChange',
            today.revenue,
            yesterday.revenue
        );

        /*
         * IPD / BED DATA
         *
         * Keep this here as a display fallback.
         * wsLoadIPDStats() below remains the final IPD source.
         */

        wsSet(
            'wsOccupiedBeds',
            wsNumber(beds.occupied)
        );

        wsSet(
            'wsVacantBeds',
            wsNumber(beds.vacant)
        );

        wsSet(
            'wsTotalBeds',
            wsNumber(beds.total)
        );

        wsSet(
    'wsMaintenanceBeds',
    wsNumber(beds.maintenance)
);

        wsSet(
            'wsOccupancyPercent',
            (beds.occupancy_percent||0)+'%'
        );

        wsUpdateOccupancy(
            parseFloat(
                beds.occupancy_percent||0
            )
        );

        /*
         * IMPORTANT:
         * Today's Appointments chart uses TOP-LEVEL
         * dashboard_stats values, not data.today.
         *
         * This was missing in the previous version.
         */

        wsRenderAppointmentsChart(
            data
        );

        /*
         * OPD vs IPD - 7 DAYS
         */

        const opdIpd=
            Array.isArray(data.opd_ipd_7_days)
                ?data.opd_ipd_7_days
                :[];

        wsRenderOpdIpdChart(
            opdIpd
        );

        /*
         * REVENUE TREND - 7 DAYS
         */

        const revenue7=
            Array.isArray(data.revenue_7_days)
                ?data.revenue_7_days
                :[];

        wsRenderRevenueChart(
            revenue7
        );

        /*
         * RECENT IPD ADMISSIONS
         */

        const admissionBody=
            document.getElementById(
                'wsRecentAdmissions'
            );

        if(admissionBody){

            if(
                Array.isArray(data.recent_admissions)&&
                data.recent_admissions.length
            ){

                admissionBody.innerHTML=
                    data.recent_admissions.map(
                        row=>{

                            const badge=
                                row.is_emergency
                                    ?
                                    '<span class="ws-hms-dashboard-status ws-hms-dashboard-status-emergency">Emergency</span>'
                                    :
                                    '<span class="ws-hms-dashboard-status ws-hms-dashboard-status-normal">'+
                                    (
                                        row.admission_type||
                                        'Routine'
                                    )+
                                    '</span>';

                            return `
                            <tr>
                                <td>${wsSafe(row.ipd_no)}</td>
                                <td>${wsSafe(row.patient_name)}</td>
                                <td>${badge}</td>
                                <td>${wsSafe(row.doctor_name)}</td>
                                <td>${wsSafe(row.admission_date)}</td>
                                <td>
                                    <span class="ws-hms-dashboard-status ws-hms-dashboard-status-admitted">
                                        ${wsSafe(row.status||'Admitted')}
                                    </span>
                                </td>
                            </tr>
                            `;
                        }
                    ).join('');

            }else{

                admissionBody.innerHTML=
                    '<tr><td colspan="6">'+
                    '<div class="ws-hms-dashboard-empty">'+
                    'No current IPD admissions found.'+
                    '</div>'+
                    '</td></tr>';

            }
        }

    })
    .catch(error=>{
        console.error(
            'Dashboard statistics error:',
            error
        );
    })
    .finally(()=>{
        dashboard.classList.remove(
            'ws-hms-dashboard-loading'
        );
    });
}

/*
 * SAFE DISPLAY
 */

function wsSafe(value){
    if(
        value===null||
        value===undefined||
        value===''
    ){
        return '—';
    }

    const div=document.createElement('div');
    div.textContent=value;
    return div.innerHTML;
}

/*
 * OCCUPANCY
 */

function wsUpdateOccupancy(percent){

    percent=parseFloat(percent||0);

    if(percent<0){
        percent=0;
    }

    if(percent>100){
        percent=100;
    }

    wsSet(
        'wsOccupancyPercent',
        Math.round(percent)+'%'
    );

    const degrees=percent*3.6;

    const circle=
        document.getElementById(
            'wsOccupancyCircle'
        );

    if(circle){

        circle.style.background=
            `conic-gradient(
                #099aa7 0deg,
                #099aa7 ${degrees}deg,
                #edf2f3 ${degrees}deg,
                #edf2f3 360deg
            )`;

    }
}

/*
 * IPD STATS
 *
 * KEEP THIS SEPARATE.
 * This is the call that your testing confirmed
 * is required for IPD Patients.
 */

function wsLoadIPDStats(){

    return fetch(
        '/dashboard/ajax/ipd_stats.php',
        {
            cache:'no-store'
        }
    )
    .then(response=>{

        if(!response.ok){

            throw new Error(
                'IPD request failed: '+
                response.status
            );

        }

        return response.json();

    })
    .then(data=>{

        console.log(
            'IPD data:',
            data
        );

        const admitted=
            parseInt(
                data.admitted_patients||0,
                10
            );

        const vacant=
            parseInt(
                data.vacant_beds||0,
                10
            );

const maintenance=
    parseInt(
        data.maintenance_beds||0,
        10
    );
    
        const total=
    admitted+vacant+maintenance;
const percent=
    total>0
        ?(admitted/total)*100
        :0;

        wsSet(
            'wsKpiIPD',
            wsNumber(admitted)
        );

        

      wsSet(
    'wsOccupiedBeds',
    wsNumber(admitted)
);

wsSet(
    'wsVacantBeds',
    wsNumber(vacant)
);

wsSet(
    'wsTotalBeds',
    wsNumber(total)
);

wsSet(
    'wsMaintenanceBeds',
    wsNumber(maintenance)
);

wsSet(
    'wsIpdAvailableSummary',
    wsNumber(vacant)
);

wsSet(
    'wsIpdTotalSummary',
    wsNumber(total)
);

wsSet(
    'wsAlertVacantBeds',
    wsNumber(vacant)
);

const occupiedPercent=
    total>0
        ?(admitted/total)*100
        :0;

const availablePercent=
    total>0
        ?(vacant/total)*100
        :0;

const maintenancePercent=
    total>0
        ?(maintenance/total)*100
        :0;

wsSet(
    'wsOccupiedPercent',
    Math.round(occupiedPercent)+'%'
);

wsSet(
    'wsAvailablePercent',
    Math.round(availablePercent)+'%'
);

wsSet(
    'wsMaintenancePercent',
    Math.round(maintenancePercent)+'%'
);

const occupiedProgress=
    document.getElementById('wsOccupiedProgress');

if(occupiedProgress){
    occupiedProgress.style.width=
        Math.min(100,occupiedPercent)+'%';
}

const availableProgress=
    document.getElementById('wsAvailableProgress');

if(availableProgress){
    availableProgress.style.width=
        Math.min(100,availablePercent)+'%';
}

const totalProgress=
    document.getElementById('wsTotalProgress');

if(totalProgress){
    totalProgress.style.width='100%';
}

const maintenanceProgress=
    document.getElementById('wsMaintenanceProgress');

if(maintenanceProgress){
    maintenanceProgress.style.width=
        Math.min(100,maintenancePercent)+'%';
}

wsUpdateOccupancy(
    percent
);
    })
    .catch(error=>{

        console.error(
            'IPD statistics error:',
            error
        );

    });
}

/*
 * TODAY'S APPOINTMENTS
 */

function wsRenderAppointmentsChart(data){

    const canvas=
        document.getElementById(
            'wsAppointmentsChart'
        );

    if(!canvas){
        return;
    }

    if(wsAppointmentsChart){
        wsAppointmentsChart.destroy();
    }

    const values=[
        parseInt(
            data.appointments||0,
            10
        ),
        parseInt(
            data.registered_appointments||0,
            10
        ),
        parseInt(
            data.done_count||0,
            10
        ),
        parseInt(
            data.prescription_count||0,
            10
        ),
        parseInt(
            data.admit_advice_count||0,
            10
        ),
        parseInt(
            data.cancel_count||0,
            10
        ),
        parseInt(
            data.refund_count||0,
            10
        )
    ];

    wsAppointmentsChart=
        new Chart(
            canvas,
            {
                type:'bar',

                data:{
                    labels:[
                        'Confirmed',
                        'Registered',
                        'Done',
                        'Prescription',
                        'Admit Advice',
                        'Cancelled',
                        'Refund'
                    ],

                    datasets:[
                        {
                            data:values,

                            backgroundColor:[
                                '#3b82f6',
                                '#14b8a6',
                                '#22c55e',
                                '#8b5cf6',
                                '#f59e0b',
                                '#ef6675',
                                '#ec4899'
                            ],

                            borderWidth:0,
                            borderRadius:5,
                            barThickness:22
                        }
                    ]
                },

                options:{
                    responsive:true,
                    maintainAspectRatio:false,

                    animation:{
                        duration:500
                    },

                    plugins:{
                        legend:{
                            display:false
                        },

                        tooltip:{
                            backgroundColor:'#263638',
                            titleFont:{
                                size:10
                            },
                            bodyFont:{
                                size:10
                            },
                            padding:8
                        }
                    },

                    scales:{
                        x:{
                            grid:{
                                display:false
                            },

                            ticks:{
                                color:'#657477',
                                font:{
                                    size:8
                                },

                                maxRotation:0,
                                minRotation:0
                            }
                        },

                        y:{
                            beginAtZero:true,

                            suggestedMax:
                                Math.max(
                                    1,
                                    ...values
                                ),

                            ticks:{
                                precision:0,
                                color:'#7d898b',
                                font:{
                                    size:8
                                }
                            },

                            grid:{
                                color:'#edf1f2'
                            }
                        }
                    }
                }
            }
        );
}

/*
 * OPD TREND - LAST 7 DAYS
 * Multi-color gradient filled area chart
 */

function wsRenderOpdIpdChart(rows){
    const canvas=document.getElementById('wsOpdIpdChart');
    if(!canvas){
        return;
    }
    if(wsOpdIpdChart){
        wsOpdIpdChart.destroy();
    }
    rows=Array.isArray(rows)?rows:[];
    const labels=rows.map(row=>row.label||'');
    const opdValues=rows.map(row=>parseInt(row.opd||0,10));
    const ctx=canvas.getContext('2d');
    const chartHeight=canvas.clientHeight||220;
    const gradient=ctx.createLinearGradient(0,0,0,chartHeight);
    gradient.addColorStop(0,'rgba(20,184,166,.42)');
    gradient.addColorStop(.45,'rgba(99,102,241,.20)');
    gradient.addColorStop(1,'rgba(139,92,246,.03)');
    wsOpdIpdChart=new Chart(canvas,{
        type:'line',
        data:{
            labels:labels,
            datasets:[
                {
                    label:'OPD',
                    data:opdValues,
                    borderColor:'#14b8a6',
                    backgroundColor:gradient,
                    borderWidth:2.5,
                    pointBackgroundColor:'#14b8a6',
                    pointBorderColor:'#fff',
                    pointBorderWidth:2,
                    pointRadius:4,
                    pointHoverRadius:6,
                    fill:true,
                    tension:.4
                }
            ]
        },
        options:{
            responsive:true,
            maintainAspectRatio:false,
            animation:{
                duration:500
            },
            interaction:{
                intersect:false,
                mode:'index'
            },
            plugins:{
                legend:{
                    position:'bottom',
                    labels:{
                        color:'#657477',
                        font:{
                            size:8,
                            weight:'600'
                        },
                        usePointStyle:true,
                        pointStyle:'circle',
                        boxWidth:8,
                        boxHeight:8,
                        padding:12
                    }
                },
                tooltip:{
                    backgroundColor:'#263638',
                    titleColor:'#fff',
                    bodyColor:'#fff',
                    titleFont:{
                        size:10,
                        weight:'700'
                    },
                    bodyFont:{
                        size:10
                    },
                    padding:9,
                    displayColors:false,
                    callbacks:{
                        label:function(context){
                            return ' OPD: '+context.parsed.y+' patients';
                        }
                    }
                }
            },
            scales:{
                x:{
                    grid:{
                        display:false
                    },
                    border:{
                        display:false
                    },
                    ticks:{
                        color:'#7d898b',
                        font:{
                            size:8,
                            weight:'500'
                        },
                        maxRotation:0,
                        minRotation:0
                    }
                },
                y:{
                    beginAtZero:true,
                    suggestedMax:Math.max(2,...opdValues)+1,
                    border:{
                        display:false
                    },
                    ticks:{
                        precision:0,
                        color:'#7d898b',
                        font:{
                            size:8,
                            weight:'500'
                        },
                        padding:5
                    },
                    grid:{
                        color:'rgba(225,232,235,.65)',
                        borderDash:[3,3]
                    }
                }
            }
        }
    });
}
/*
 * REVENUE TREND - LAST 7 DAYS
 */

function wsRenderRevenueChart(rows){

    const canvas=
        document.getElementById(
            'wsRevenueChart'
        );

    if(!canvas){
        return;
    }

    if(wsRevenueChart){
        wsRevenueChart.destroy();
    }

    rows=Array.isArray(rows)
        ?rows
        :[];

    const labels=
        rows.map(
            row=>row.label||''
        );

    const values=
        rows.map(
            row=>parseFloat(
                row.revenue||0
            )
        );

    wsRevenueChart=
        new Chart(
            canvas,
            {
                type:'line',

                data:{
                    labels:labels,

                    datasets:[
                        {
                            label:'Revenue',
                            data:values,

                            borderColor:'#12a8ad',
                            backgroundColor:'rgba(18,168,173,.10)',

                            borderWidth:2,

                            tension:.35,

                            fill:true,

                            pointRadius:3,
                            pointHoverRadius:5,

                            pointBackgroundColor:'#12a8ad',
                            pointBorderColor:'#fff',
                            pointBorderWidth:2
                        }
                    ]
                },

                options:{
                    responsive:true,
                    maintainAspectRatio:false,

                    animation:{
                        duration:500
                    },

                    plugins:{
                        legend:{
                            display:false
                        },

                        tooltip:{
                            backgroundColor:'#263638',
                            titleFont:{
                                size:10
                            },
                            bodyFont:{
                                size:10
                            },

                            callbacks:{
                                label:function(context){
                                    return ' Revenue: '+
                                        wsMoney(
                                            context.parsed.y
                                        );
                                }
                            }
                        }
                    },

                    scales:{
                        x:{
                            grid:{
                                display:false
                            },

                            ticks:{
                                color:'#7d898b',
                                font:{
                                    size:8
                                }
                            }
                        },

                        y:{
                            beginAtZero:true,

                            ticks:{
                                color:'#7d898b',
                                font:{
                                    size:8
                                },

                                callback:function(value){
                                    return '₹'+
                                        Number(value)
                                        .toLocaleString(
                                            'en-IN'
                                        );
                                }
                            },

                            grid:{
                                color:'#edf1f2'
                            }
                        }
                    }
                }
            }
        );
}

/*
 * DATE LABEL
 */
function wsSetTodayLabel(){
    const now=new Date();
    const element=document.getElementById('wsDashboardTodayLabel');
    if(element){
        const dateText=now.toLocaleDateString('en-IN',{
            weekday:'short',
            day:'2-digit',
            month:'short',
            year:'numeric'
        });
        const span=element.querySelector('span');
        if(span){
            span.textContent=dateText;
        }
    }
}
/*
 * FILTER EVENTS
 */

[
    wsSource,
    wsDateFilter,
    wsCreatedDateFilter,
    wsDoctor,
    wsDepartment,
    wsBranch,
    wsHospital
].forEach(element=>{

    if(element){

        element.addEventListener(
            'change',
            ()=>{
                wsLoadCoreStats();
            }
        );

    }

});

/*
 * INITIALIZE
 */

wsSetTodayLabel();

Promise.all([
    wsLoadFilters(),
    wsLoadCoreStats()
])
.then(()=>{
    return wsLoadIPDStats();
})
.catch(error=>{
    console.error(
        'Dashboard initialization error:',
        error
    );
});
</script>
</body>
</html>