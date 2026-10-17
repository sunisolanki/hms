function openServicePopup(){
    window.open('services_popup.php','services','width=800,height=500');
}

function addServicesToTable(services){
    let table=document.getElementById('serviceTable');

    services.forEach(s=>{
        if(document.getElementById('svc_'+s.id)) return;

        let row=table.insertRow();
        row.id='svc_'+s.id;

        row.innerHTML=`
            <td>${s.code}<input type="hidden" name="service_id[]" value="${s.id}"></td>
            <td>${s.name}</td>
            <td>${s.amount}<input type="hidden" name="service_amount[]" value="${s.amount}"></td>
            <td><input type="number" name="emp_amount[]" step="0.01" required max="${s.amount}"></td>
            <td><button type="button" onclick="this.closest('tr').remove()">X</button></td>
        `;
    });
}

function validateForm(){
    if(document.querySelectorAll('#serviceTable tr').length<=1){
        alert('Select at least one service');
        return false;
    }
    return true;
}
