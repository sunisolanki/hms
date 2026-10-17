<?php

require $_SERVER['DOCUMENT_ROOT'].'/includes/db.php';

$data = json_decode(file_get_contents("php://input"), true);

$image = $data['image'];

$image = str_replace('data:image/png;base64,', '', $image);
$image = str_replace(' ', '+', $image);
$imageData = base64_decode($image);

$file = 'dashboard.png';
file_put_contents($file, $imageData);

/* SEND MAIL */
$to = "your_email@gmail.com";
$subject = "Bed Dashboard";

$boundary = md5(time());

$headers = "From: HMS\r\n";
$headers .= "MIME-Version: 1.0\r\n";
$headers .= "Content-Type: multipart/mixed; boundary=\"$boundary\"\r\n";

$message = "--$boundary\r\n";
$message .= "Content-Type: text/plain; charset=UTF-8\r\n\r\n";
$message .= "Bed Dashboard Attached\r\n";

$message .= "--$boundary\r\n";
$message .= "Content-Type: image/png; name=\"$file\"\r\n";
$message .= "Content-Transfer-Encoding: base64\r\n";
$message .= "Content-Disposition: attachment\r\n\r\n";
$message .= chunk_split(base64_encode(file_get_contents($file)));

$message .= "--$boundary--";

if(mail($to, $subject, $message, $headers)){
    echo "Mail Sent Successfully";
}else{
    echo "Mail Failed";
}

unlink($file);