<?php
$appointment_id = (int)($_GET['appointment_id'] ?? 0);
?>
<!DOCTYPE html>
<html>
<head>
<title>Registration Successful</title>
</head>
<body>
<script>
if(<?= $appointment_id ?> > 0){
    window.open(
        '/modules/appointments/print_registration_slip.php?id=<?= $appointment_id ?>',
        '_blank'
    );
}
if(window.opener && !window.opener.closed){
    window.opener.location.href='/modules/appointments/appointment_list.php';
    window.close();
}else if(window.parent && window.parent!==window){
    if(window.parent.document.getElementById('registrationModal')){
        window.parent.document.getElementById('registrationModal').style.display='none';
    }
    window.parent.location.href='/modules/appointments/appointment_list.php';
}else{
    window.location.href='/modules/appointments/appointment_list.php';
}
</script>
</body>
</html>