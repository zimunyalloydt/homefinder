<?php
include(__DIR__ . '/../config/db_connect.php');
session_start();

// Ensure landlord is logged in
if (!isset($_SESSION['user_id']) || $_SESSION['role'] !== 'landlord') {
    header("Location: login.php");
    exit;
}

$landlord_id = $_SESSION['user_id'];
$property_id = isset($_GET['id']) ? intval($_GET['id']) : 0;

if ($property_id > 0) {
    // Verify the property belongs to this landlord
    $stmt_check = $conn->prepare("SELECT * FROM properties WHERE property_id=? AND landlord_id=?");
    $stmt_check->bind_param("ii", $property_id, $landlord_id);
    $stmt_check->execute();
    $prop_result = $stmt_check->get_result();

    if ($prop_result->num_rows > 0) {
        // Delete all property images from server
        $stmt_imgs = $conn->prepare("SELECT image_path FROM property_images WHERE property_id=?");
        $stmt_imgs->bind_param("i", $property_id);
        $stmt_imgs->execute();
        $imgs_result = $stmt_imgs->get_result();

        while ($img = $imgs_result->fetch_assoc()) {
            $file_path = __DIR__ . "/../uploads/" . $img['image_path'];
            if (file_exists($file_path)) {
                unlink($file_path);
            }
        }

        // Delete images from DB
        $stmt_delete_imgs = $conn->prepare("DELETE FROM property_images WHERE property_id=?");
        $stmt_delete_imgs->bind_param("i", $property_id);
        $stmt_delete_imgs->execute();

        // Delete property itself
        $stmt_delete_property = $conn->prepare("DELETE FROM properties WHERE property_id=? AND landlord_id=?");
        $stmt_delete_property->bind_param("ii", $property_id, $landlord_id);
        $stmt_delete_property->execute();

        // Redirect with success
        header("Location: dashboard_landlord.php?message=Property+deleted+successfully");
        exit;
    } else {
        $message = "❌ Property not found or you don't have permission to delete it.";
    }
} else {
    $message = "❌ Invalid property ID.";
}

// If deletion failed, go back to dashboard with error
header("Location: dashboard_landlord.php?message=" . urlencode($message));
exit;
