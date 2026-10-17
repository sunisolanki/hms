function calculateGST(amount,gst){

amount=parseFloat(amount)||0;

gst=parseFloat(gst)||0;

return ((amount*gst)/100).toFixed(2);

}

function calculateTotal(amount,gst,discount){

amount=parseFloat(amount)||0;

gst=parseFloat(gst)||0;

discount=parseFloat(discount)||0;

let gstAmount=(amount*gst)/100;

return (amount+gstAmount-discount).toFixed(2);

}

function confirmDelete(){

return confirm("Are you sure you want to delete this record?");

}

function showLoader(){

$("#loader").show();

}

function hideLoader(){

$("#loader").hide();

}