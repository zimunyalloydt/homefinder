<?php
include __DIR__ . '/config/db_connect.php';
session_start();

/*Verify landlord is logged in
if (!isset($_SESSION['user_id']) || $_SESSION['role'] !== 'landlord') {
    header("Location: login.php");
    exit();
}*/

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $application_id = intval($_POST['application_id']);
    $status = $_POST['status']; // Should be 'Approved' or 'Rejected'
    
    // Verify the landlord owns this property
    $stmt = $conn->prepare("
        UPDATE applications a
        JOIN properties p ON a.property_id = p.property_id
        SET a.status = ?
        WHERE a.application_id = ? AND p.landlord_id = ?
    ");
    $stmt->bind_param("sii", $status, $application_id, $_SESSION['user_id']);
    
    if ($stmt->execute()) {
        $_SESSION['message'] = "Application updated successfully!";
    } else {
        $_SESSION['message'] = "Error updating application: " . $conn->error;
    }
}

header("Location: landlord_dashboard.php");
exit();
?>