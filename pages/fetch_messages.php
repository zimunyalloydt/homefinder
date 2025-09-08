<?php
include(__DIR__ . '/../config/db_connect.php');
session_start();

$landlord_id = $_SESSION['user_id'] ?? 0;
$active_chat_user = intval($_GET['chat_with'] ?? 0);

if (!$landlord_id || !$active_chat_user) {
    echo json_encode([]);
    exit;
}

$chat_stmt = $conn->prepare("
    SELECT m.*, u.full_name AS sender_name
    FROM messages m
    JOIN users u ON m.sender_id = u.user_id
    WHERE (m.sender_id = ? AND m.receiver_id = ?)
       OR (m.sender_id = ? AND m.receiver_id = ?)
    ORDER BY m.created_at ASC
");
$chat_stmt->bind_param("iiii", $landlord_id, $active_chat_user, $active_chat_user, $landlord_id);
$chat_stmt->execute();
$messages = $chat_stmt->get_result()->fetch_all(MYSQLI_ASSOC);

echo json_encode($messages);
