<?php
include(__DIR__ . '/../config/db_connect.php');
session_start();

if (!isset($_SESSION['user_id'])) {
    header("HTTP/1.1 403 Forbidden");
    exit();
}

$user_id = intval($_GET['user_id']);

// Check if user is online (last activity within 2 minutes)
$stmt = $conn->prepare("
    SELECT last_activity FROM user_online_status 
    WHERE user_id = ? AND last_activity > DATE_SUB(NOW(), INTERVAL 2 MINUTE)
");
$stmt->bind_param("i", $user_id);
$stmt->execute();
$result = $stmt->get_result();

$response = ['online' => $result->num_rows > 0];

if (!$response['online']) {
    // Get last seen time
    $stmt2 = $conn->prepare("SELECT last_activity FROM user_online_status WHERE user_id = ?");
    $stmt2->bind_param("i", $user_id);
    $stmt2->execute();
    $result2 = $stmt2->get_result();
    
    if ($row = $result2->fetch_assoc()) {
        $response['last_seen'] = $row['last_activity'];
    }
}

header('Content-Type: application/json');
echo json_encode($response);
?>