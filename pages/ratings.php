<?php
include __DIR__ . '/../config/db_connect.php';
session_start();

/* Redirect if not logged in
if (!isset($_SESSION['user_id'])) {
    header("Location: login.php");
    exit();
}*/

$user_id = $_SESSION['user_id'];
$role = $_SESSION['role']; // landlord or tenant
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <title>Ratings</title>
    <style>
        body { font-family: Arial, sans-serif; padding:20px; }
        h2 { margin-top:30px; }
        table { width:100%; border-collapse: collapse; margin-bottom:20px; }
        th, td { padding:10px; border:1px solid #ccc; text-align:left; }
        th { background:#f4f4f4; }
    </style>
</head>
<body>
    <h1>⭐ Ratings</h1>

    <!-- My Own Rating -->
    <h2>My Rating</h2>
    <?php
    if ($role === 'tenant') {
        $stmt = $conn->prepare("SELECT r.rating, r.comment, r.created_at, u.full_name AS reviewer
                                FROM ratings r
                                JOIN users u ON r.reviewer_id=u.user_id
                                WHERE target_type='tenant' AND target_id=?");
    } else {
        $stmt = $conn->prepare("SELECT r.rating, r.comment, r.created_at, u.full_name AS reviewer
                                FROM ratings r
                                JOIN users u ON r.reviewer_id=u.user_id
                                WHERE target_type='landlord' AND target_id=?");
    }
    $stmt->bind_param("i", $user_id);
    $stmt->execute();
    $myRatings = $stmt->get_result();

    if ($myRatings->num_rows > 0) {
        echo "<table><tr><th>Rating</th><th>Reviewer</th><th>Comment</th><th>Date</th></tr>";
        while($row = $myRatings->fetch_assoc()) {
            echo "<tr><td>{$row['rating']}★</td><td>{$row['reviewer']}</td><td>{$row['comment']}</td><td>{$row['created_at']}</td></tr>";
        }
        echo "</table>";
    } else {
        echo "<p>You have not been rated yet.</p>";
    }
    ?>

    <!-- Landlord Ratings -->
    <h2>Landlord Ratings</h2>
    <?php
    $landlordRatings = $conn->query("SELECT r.rating, r.comment, r.created_at, u.full_name AS reviewer, t.full_name AS landlord
                                     FROM ratings r
                                     JOIN users u ON r.reviewer_id=u.user_id
                                     JOIN users t ON r.target_id=t.user_id
                                     WHERE target_type='landlord'");
    if ($landlordRatings->num_rows > 0) {
        echo "<table><tr><th>Landlord</th><th>Rating</th><th>Reviewer</th><th>Comment</th><th>Date</th></tr>";
        while($row = $landlordRatings->fetch_assoc()) {
            echo "<tr><td>{$row['landlord']}</td><td>{$row['rating']}★</td><td>{$row['reviewer']}</td><td>{$row['comment']}</td><td>{$row['created_at']}</td></tr>";
        }
        echo "</table>";
    } else {
        echo "<p>No landlord ratings yet.</p>";
    }
    ?>

    <!-- Property Ratings -->
    <h2>Property Ratings</h2>
    <?php
    $propertyRatings = $conn->query("SELECT r.rating, r.comment, r.created_at, u.full_name AS reviewer, p.title AS property_title
                                     FROM ratings r
                                     JOIN users u ON r.reviewer_id=u.user_id
                                     JOIN properties p ON r.target_id=p.property_id
                                     WHERE target_type='property'");
    if ($propertyRatings->num_rows > 0) {
        echo "<table><tr><th>Property</th><th>Rating</th><th>Reviewer</th><th>Comment</th><th>Date</th></tr>";
        while($row = $propertyRatings->fetch_assoc()) {
            echo "<tr><td>{$row['property_title']}</td><td>{$row['rating']}★</td><td>{$row['reviewer']}</td><td>{$row['comment']}</td><td>{$row['created_at']}</td></tr>";
        }
        echo "</table>";
    } else {
        echo "<p>No property ratings yet.</p>";
    }
    ?>

    <!-- Tenant Ratings -->
    <h2>Tenant Ratings</h2>
    <?php
    $tenantRatings = $conn->query("SELECT r.rating, r.comment, r.created_at, u.full_name AS reviewer, t.full_name AS tenant
                                   FROM ratings r
                                   JOIN users u ON r.reviewer_id=u.user_id
                                   JOIN users t ON r.target_id=t.user_id
                                   WHERE target_type='tenant'");
    if ($tenantRatings->num_rows > 0) {
        echo "<table><tr><th>Tenant</th><th>Rating</th><th>Reviewer</th><th>Comment</th><th>Date</th></tr>";
        while($row = $tenantRatings->fetch_assoc()) {
            echo "<tr><td>{$row['tenant']}</td><td>{$row['rating']}★</td><td>{$row['reviewer']}</td><td>{$row['comment']}</td><td>{$row['created_at']}</td></tr>";
        }
        echo "</table>";
    } else {
        echo "<p>No tenant ratings yet.</p>";
    }
    ?>

</body>
</html>
