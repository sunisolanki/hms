<?php
session_start();
require $_SERVER['DOCUMENT_ROOT'].'/includes/db.php';
if (!isset($_SESSION['user_id'])) {
    header('Location: /index.php');
    exit;
}
/* SUPPLIERS */
$suppliers = $conn->query("
    SELECT id, party_name
    FROM party_master
    WHERE status='Active'
    ORDER BY party_name ASC
");
/* MANUFACTURERS */
$manufacturers = $conn->query("
    SELECT id, party_name
    FROM party_master
    WHERE status='Active'
    ORDER BY party_name ASC
");
/* HOSPITALS */
$hospitals = $conn->query("
    SELECT id, hospital_name
    FROM hospital_master
    WHERE status='1'
    ORDER BY hospital_name ASC
");
/* BRANCHES */
/* LOAD USING AJAX */

/* STORES */
/* LOAD USING AJAX */

/* ITEMS */
$items = $conn->query("
    SELECT
    id,
    item_name,
    packing
    FROM item_master
    WHERE status='Active'
    ORDER BY item_name ASC
");
?>
<!DOCTYPE html>
<html>
<head>
    <meta charset="UTF-8">
    <title>Material Receipt</title>
    <meta name="viewport" content="width=device-width, initial-scale=1">
<link href="https://cdn.jsdelivr.net/npm/select2@4.1.0-rc.0/dist/css/select2.min.css" rel="stylesheet" />
<link rel="icon" type="image/png" href="/assets/img/hms_logo.png">
<link rel="apple-touch-icon" href="/assets/img/hms_logo.png">
<link rel="stylesheet" href="/assets/css/style.css">
    <style>
    .card{
        background:#fff;
        padding:0px 20px 5px 20px;
        border-radius:10px;
    }
    .form-grid{
        display:grid;
        grid-template-columns:repeat(6,1fr);
        gap:15px;
    }
    .form-group{
        display:flex;
        flex-direction:column;
    }
    .table-responsive{
    height:calc(100vh - 440px);
    overflow-y:auto;
    overflow-x:auto;
    border:1px solid #ddd;
    background:#fff;
}
    .sticky-footer{
    position:fixed;
    bottom:0;
    left:260px;
    right:0;
    background:#fff;
    border-top:2px solid #1976d2;
    padding:10px 15px;
    display:flex;
    gap:15px;
    justify-content:flex-end;
    align-items:center;
    z-index:9999;
    box-shadow:0 -2px 10px rgba(0,0,0,.15);
}
.footer-box{
    display:flex;
    flex-direction:column;
    min-width:150px;
}
.footer-box label{
    font-size:12px;
    font-weight:bold;
    margin-bottom:5px;
}
.footer-box input{
    padding:8px;
    border:1px solid #ccc;
    border-radius:5px;
    background:#f9fafb;
}
    </style>
</head>
<body>
    <?php require $_SERVER['DOCUMENT_ROOT'].'/includes/header.php'; ?>
    <?php require $_SERVER['DOCUMENT_ROOT'].'/includes/sidebar.php'; ?>
<div class="main-content" id="mainContent" style="width:auto;">
<div class="page-container">
<div class="page-wrapper">
<form method="POST" action="save_material_receipt.php">
    <!-- HEADER -->
    <div class="card">
        <h2>Material Receipt</h2>
        <div class="form-grid">
            <div class="form-group">
                <label>MRN Type *</label>
                <select name="mrn_type" required>
                    <option value="">Select</option>
                    <option value="Capex">Capex</option>
                    <option value="Non-Capex">Non-Capex</option>
                </select>
            </div>
            <div class="form-group">
                <label>Supplier Name *</label>
                <select name="supplier_id" required>
                    <option value="">Select Supplier</option>
                    <?php while($sup = $suppliers->fetch_assoc()) { ?>
                    <option value="<?= $sup['id'] ?>">
                        <?= htmlspecialchars($sup['party_name']) ?>
                    </option>
                    <?php } ?>
                </select>
            </div>
            <div class="form-group">
                <label>MRN Against *</label>
                <select name="mrn_against" required>
                    <option value="Direct">Direct</option>
                    <option value="Purchase Order">
                        Purchase Order
                    </option>
                    <option value="Batch Expiry Challan">
                        Batch Expiry Challan
                    </option>
                    <option value="Challan">
                        Challan
                    </option>
                </select>
            </div>
            <div class="form-group">
                <label>Bill No</label>
                <input type="text"
                name="bill_no">
            </div>
            <div class="form-group">
    <label>Bill Date *</label>
    <input type="date"
    name="bill_date"
    value="<?= date('Y-m-d') ?>"
    required>
</div>
          <!--  <div class="form-group">
                <label>Tax Type</label>
                <select name="tax_type">
                    <option value="Item Wise">
                        Item Wise
                    </option>
                </select>
            </div> -->
<div class="form-group">
    <label>Mode of Entry</label>
    <select name="mode_of_entry">
        <option value="Manual">
            Manual
        </option>
        <option value="Barcode Scan">
            Barcode Scan
        </option>
        <option value="Import">
            Import
        </option>
    </select>
</div>
            <div class="form-group">
                <label>Hospital</label>
                <select name="hospital_id" id="hospital_id">
                    <option value="">Select</option>
                    <?php while($hos = $hospitals->fetch_assoc()) { ?>
                    <option value="<?= $hos['id'] ?>">
                        <?= htmlspecialchars($hos['hospital_name']) ?>
                    </option>
                    <?php } ?>
                </select>
            </div>
            <div class="form-group">
    <label>Branch *</label>
    <select name="branch_id" id="branch_id" required>
        <option value="">
            Select Branch
        </option>
    </select>
</div>
            <div class="form-group">
    <label>Store Name *</label>
    <select name="store_id" id="store_id" required>
        <option value="">
            Select Store
        </option>
    </select>
</div>
<div class="form-group">
    <label>MRN Status</label>
    <select name="mrn_status">
        <option value="MRN Submitted" selected>
            MRN Submitted
        </option>
  <!--      <option value="Inspection Pending">
            Inspection Pending
        </option>
        <option value="Inspection Done">
            Inspection Done
        </option>
        <option value="Inspection Approved">
            Inspection Approved
        </option>  --->
    </select>
</div>
<div class="form-group">
    <label>GST Type</label>
    <select name="gst_type">
        <option value="Intra State">
            Intra State
        </option>

        <option value="Inter State">
            Inter State
        </option>

    </select>

</div>
            <div class="form-group" style="display:flex; flex-direction:row; align-items:center; gap:8px;">
<label style="margin:0;font-weight:600;">Exempted</label>
<input type="checkbox" name="exempted" value="1" style=" width:18px; height:18px; cursor:pointer; "></div>
            <div class="form-group" style="grid-column: span 1;">
                <label>Remarks</label>
                <textarea name="remarks"rows="1"style="resize:none;height:38px;min-height:38px;"></textarea>
            </div>
        </div>
    </div>
    <button type="button" class="btn btn-primary" id="addRowBtn"> + Add Row </button>
    <!-- ITEM GRID -->
            <div class="table-responsive">
            <table id="itemTable">
                <thead>
                <tr>
                    <th>Action</th>
                    <th>Item *</th>
                    <th>Qty Rcvd *</th>
                    <th>Free Qty</th>
                    <th>Packing</th>
                    <th>Batch No</th>
                    <th>MRP Rate *</th>
                    <th>Trade Rate *</th>
                    <th>Scheme</th>
                    <th>Bill Rate *</th>
                    <th>Mfg Date</th>
                    <th>Exp Date *</th>
                    <th>Disc (%)</th>
                    <th>GST (%)</th>
                    <th>Net Amount</th>
                    <th>Barcode Number</th>
                    <th>Mfg Company</th>
                </tr>
                </thead>
                <tbody>
                <tr>
                    <td><button type="button" class="btn btn-danger removeRow"> X </button></td>
                    <td><select name="item_id[]" class="item_select" required>
                            <option value="">Select Item</option>
                            <?php
                            mysqli_data_seek($items,0);while($it = $items->fetch_assoc()) {?><option
value="<?= $it['id'] ?>"
data-pack="<?= htmlspecialchars($it['packing']) ?>"
>
<?= htmlspecialchars($it['item_name']) ?>
</option><?php } ?></select></td>
                    <td><input type="number" step="0.01" name="qty_received[]" class="qty_received"required></td>
                    <td><input type="number" step="0.01" name="free_qty[]"></td>
                    <td>
<input
type="text"
name="pack[]"
class="pack"
readonly>
</td>
                    <td><input type="text" name="batch_no[]"></td>
                    <td><input type="number" step="0.01" name="mrp[]" class="mrp" required></td>
                   <td><input type="number" step="0.01" name="trade_rate[]" class="trade_rate" required></td>
                    <td> <input type="text" name="scheme[]"></td>
                    <td><input type="number" step="0.01" name="bill_rate[]" class="bill_rate" readonly required></td>
                    <td><input type="date" name="mfg_date[]"> </td>
                    <td><input type="date" name="exp_date[]" min="<?= date('Y-m-d') ?>" required> </td>
                    <td><input type="number" step="0.01" name="discount_percent[]" class="discount_percent"></td>
                    <td><input type="number" step="0.01"    name="gst_percent[]"    class="gst_percent"    readonly></td>
                    <td><input type="number" step="0.01" name="net_amount[]" class="net_amount" readonly></td>
                    <td><input type="text" name="barcode_no[]"></td>
                    <td><select name="manufacturer_name[]"><option value="">Select Company</option><?php mysqli_data_seek($manufacturers,0); while($man = $manufacturers->fetch_assoc()) { ?><option value="<?= htmlspecialchars($man['party_name']) ?>"><?= htmlspecialchars($man['party_name']) ?></option><?php } ?></select></td>
                </tr>
                </tbody>
            </table>
       <!-- STICKY TOTAL FOOTER -->
<div class="sticky-footer">
     <div class="actions">
        <button type="submit"
        class="btn btn-primary">
            Save Material Receipt
        </button>
    </div>
    <div class="footer-box">
        <label>Sub Total</label>
        <input type="text" id="sub_total" readonly>
    </div>
    <div class="footer-box">
        <label>Total Discount</label>
        <input type="text" id="total_discount" readonly>
    </div>
    <div class="footer-box">
        <label>Total Tax</label>
        <input type="text" id="total_tax" readonly>
    </div>
    <div class="footer-box">
        <label>Round Off</label>
        <input type="text" id="round_off" readonly>
    </div>
    <div class="footer-box">
        <label>Grand Total</label>
        <input type="text" id="grand_total"readonly 
        style="
        background:#1976d2;
        color:#fff;
        font-weight:bold;
        ">
    </div>
</div>
</form>
</div>
<script>
document.getElementById('addRowBtn')
.addEventListener('click', function(){
    let tbody =
    document.querySelector('#itemTable tbody');
    let firstRow =
    tbody.querySelector('tr');
    let clone =
    firstRow.cloneNode(true);
    clone.querySelectorAll('input').forEach(input=>{
        input.value='';
    });
    tbody.appendChild(clone);
});
document.addEventListener('click',function(e){
    if(e.target.classList.contains('removeRow')){
        let rows =
        document.querySelectorAll('#itemTable tbody tr');
        if(rows.length > 1){
            e.target.closest('tr').remove();
        }
    }
});
</script>
<script>
document.getElementById('hospital_id')
.addEventListener('change', function(){
    let hospital_id = this.value;
    let branchDropdown =
    document.getElementById('branch_id');
    branchDropdown.innerHTML =
    '<option>Loading...</option>';
    fetch(
        'ajax/get_branches.php?hospital_id='
        + hospital_id
    )
    .then(response => response.text())
    .then(data => {
        branchDropdown.innerHTML = data;
    });
});
</script>
<script>
document.getElementById('branch_id')
.addEventListener('change', function(){
    let branch_id = this.value;
    let storeDropdown =
    document.getElementById('store_id');
    storeDropdown.innerHTML =
    '<option>Loading...</option>';
    fetch(
        'ajax/get_stores.php?branch_id='
        + branch_id
    )
    .then(response => response.text())
    .then(data => {
        storeDropdown.innerHTML = data;
    });
});
</script>
<script>
document.addEventListener('input', function(e){
    if(e.target.classList.contains('trade_rate')){
        let row =
        e.target.closest('tr');
        let mrp =
        parseFloat(
            row.querySelector('.mrp').value
        ) || 0;
        let tradeRate =
        parseFloat(e.target.value) || 0;
        if(tradeRate > mrp){
            alert(
                'Trade Rate cannot be greater than MRP'
            );
            e.target.value = '';
            e.target.focus();
        }
    }
});
</script>
<script>
document.addEventListener('input', function(e){
    if(e.target.classList.contains('trade_rate')){
        let row =
        e.target.closest('tr');
        let tradeRate =
        parseFloat(e.target.value) || 0;
        let billRateField =
        row.querySelector('.bill_rate');
        billRateField.value = tradeRate;
    }
});
</script>
<script>
document.addEventListener('change', function(e){
    if(e.target.classList.contains('item_select')){
        let item_id = e.target.value;
        let row = e.target.closest('tr');
        fetch('ajax/get_item_details.php?item_id=' + item_id)
            .then(response => response.json())
            .then(data => {
                let selectedOption = e.target.options[e.target.selectedIndex];
                row.querySelector('.pack').value = selectedOption.dataset.pack || '';
                row.querySelector('.gst_percent').value = data.gst_percent || 0;
                row.querySelector('.discount_percent').value = data.discount_percent || 0;
                calculateNetAmount(row);
            });
    }
});
</script>
<script>
function calculateNetAmount(row){
    let qty =
    parseFloat(
        row.querySelector('.qty_received').value
    ) || 0;

    let billRate =
    parseFloat(
        row.querySelector('.bill_rate').value
    ) || 0;

    let discount =
    parseFloat(
        row.querySelector('.discount_percent').value
    ) || 0;

    let gst =
    parseFloat(
        row.querySelector('.gst_percent').value
    ) || 0;
    let gross =
    qty * billRate;
    let discountAmount =
    (gross * discount) / 100;
    let taxable =
    gross - discountAmount;
    let gstAmount =
    (taxable * gst) / 100;
    let netAmount =
    taxable + gstAmount;
    row.querySelector('.net_amount').value =
    netAmount.toFixed(2);
    calculateFooterTotals();
}
document.addEventListener('input', function(e){
    let row = e.target.closest('tr');
    if(!row){
        return;
    }
    /* TRADE RATE */
    if(e.target.classList.contains('trade_rate')){
        let tradeRate =
        parseFloat(e.target.value) || 0;
        row.querySelector('.bill_rate').value =
        tradeRate;
    }
    /* CALCULATE */
    if(
        e.target.classList.contains('qty_received')
        ||
        e.target.classList.contains('trade_rate')
        ||
        e.target.classList.contains('discount_percent')
    ){
        calculateNetAmount(row);
    }
});
</script>
<script>

function calculateFooterTotals(){

    let subTotal = 0;

    let totalDiscount = 0;

    let totalTax = 0;

    let grandTotal = 0;

    document.querySelectorAll('#itemTable tbody tr')
    .forEach(row => {
        let qty =
        parseFloat(
            row.querySelector('.qty_received')?.value
        ) || 0;
        let billRate =
        parseFloat(
            row.querySelector('.bill_rate')?.value
        ) || 0;
        let discount =
        parseFloat(
            row.querySelector('.discount_percent')?.value
        ) || 0;
        let gst =
        parseFloat(
            row.querySelector('.gst_percent')?.value
        ) || 0;
        let gross = qty * billRate;
        let discountAmount =
        (gross * discount) / 100;
        let taxable =
        gross - discountAmount;
        let gstAmount =
        (taxable * gst) / 100;
        let netAmount =
        taxable + gstAmount;
        subTotal += gross;
        totalDiscount += discountAmount;
        totalTax += gstAmount;
        grandTotal += netAmount;
    });
    let roundedTotal =
    Math.round(grandTotal);
    let roundOff =
    roundedTotal - grandTotal;
    document.getElementById('sub_total').value =
    subTotal.toFixed(2);
    document.getElementById('total_discount').value =
    totalDiscount.toFixed(2);
    document.getElementById('total_tax').value =
    totalTax.toFixed(2);
    document.getElementById('round_off').value =
    roundOff.toFixed(2);
    document.getElementById('grand_total').value =
    roundedTotal.toFixed(2);
}
</script>
</body>
</html>