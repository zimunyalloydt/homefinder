<?php
include(__DIR__ . '/../config/db_connect.php');
session_start();

if (!isset($_SESSION['user_id']) || $_SESSION['role'] !== 'tenant') {
    header("HTTP/1.1 403 Forbidden");
    exit();
}

$input = json_decode(file_get_contents('php://input'), true);
$sender_id = intval($input['sender_id']);
$tenant_id = $_SESSION['user_id'];

$stmt = $conn->prepare("
    UPDATE messages 
    SET is_read = 1 
    WHERE sender_id = ? AND receiver_id = ? AND is_read = 0
");
$stmt->bind_param("ii", $sender_id, $tenant_id);
$stmt->execute();

header('Content-Type: application/json');
echo json_encode(['success' => true]);
?>