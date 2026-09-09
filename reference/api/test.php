<?php
header('Content-Type: application/json');

// قراءة كل شيء يوصله
$headers = getallheaders();
$body = file_get_contents("php://input");

// عرض البيانات
echo json_encode([
    "status" => "OK",
    "headers" => $headers,
    "body_raw" => $body,
    "post" => $_POST,
    "get" => $_GET
], JSON_PRETTY_PRINT);