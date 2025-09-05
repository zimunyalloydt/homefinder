<?php
include(__DIR__ . '/../config/db_connect.php');
session_start();

if (!isset($_SESSION['user_id']) || $_SESSION['role'] !== 'tenant') {
    header("HTTP/1.1 403 Forbidden");
    exit();
}

$search = isset($_GET['q']) ? trim($_GET['q']) : '';

$stmt = $conn->prepare("
    SELECT user_id as id, full_name as name 
    FROM users 
    WHERE role = 'landlord' AND full_name LIKE CONCAT('%', ?, '%')
    ORDER BY full_name
");
$stmt->bind_param("s", $search);
$stmt->execute();
$result = $stmt->get_result();

$landlords = [];
while ($row = $result->fetch_assoc()) {
    $landlords[] = [
        'id' => $row['id'],
        'name' => htmlspecialchars($row['name']),
        'initials' => strtoupper(substr($row['name'], 0, 1))
    ];
}

header('Content-Type: application/json');
echo json_encode($landlords);
?>