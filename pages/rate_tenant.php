<?php
include(__DIR__ . '/../config/db_connect.php');
session_start();

if (!isset($_SESSION['user_id']) || $_SESSION['role'] !== 'landlord') {
    die("Access denied.");
}

$landlord_id = $_SESSION['user_id'];

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $tenant_id = intval($_POST['tenant_id']);
    $rating = intval($_POST['rating']);
    $review = trim($_POST['review']);

    // Check if landlord already rated this tenant
    $stmt_check = $conn->prepare("SELECT * FROM tenant_ratings WHERE landlord_id=? AND tenant_id=?");
    $stmt_check->bind_param("ii", $landlord_id, $tenant_id);
    $stmt_check->execute();
    $res_check = $stmt_check->get_result();

    if ($res_check->num_rows > 0) {
        // Update existing rating
        $stmt = $conn->prepare("UPDATE tenant_ratings SET rating=?, review=?, created_at=NOW() WHERE landlord_id=? AND tenant_id=?");
        $stmt->bind_param("isii", $rating, $review, $landlord_id, $tenant_id);
    } else {
        // Insert new rating
        $stmt = $conn->prepare("INSERT INTO tenant_ratings (landlord_id, tenant_id, rating, review, created_at) VALUES (?, ?, ?, ?, NOW())");
        $stmt->bind_param("iiis", $landlord_id, $tenant_id, $rating, $review);
    }

    if ($stmt->execute()) {
        header("Location: dashboard_landlord.php?message=Tenant+rated+successfully");
        exit;
    } else {
        die("Error saving rating: " . $conn->error);
    }
}
