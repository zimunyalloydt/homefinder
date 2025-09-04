<?php
include __DIR__ . '/config/db_connect.php';
session_start();

if (!isset($_SESSION['user_id']) || $_SESSION['role'] !== 'tenant') {
    header("Location: login.php");
    exit();
}

$tenant_id = $_SESSION['user_id'];

// Fetch property info
$property_id = intval($_GET['property_id']);
$stmt = $conn->prepare("SELECT p.*, u.full_name AS landlord_name, u.user_id AS landlord_id
                        FROM properties p
                        JOIN users u ON p.landlord_id = u.user_id
                        WHERE p.property_id = ?");
$stmt->bind_param("i", $property_id);
$stmt->execute();
$property = $stmt->get_result()->fetch_assoc();

// Fetch average ratings
$stmt = $conn->prepare("SELECT AVG(stars) AS avg_property_rating FROM ratings WHERE rating_type='property' AND target_id=?");
$stmt->bind_param("i", $property_id);
$stmt->execute();
$avg_property_rating = $stmt->get_result()->fetch_assoc()['avg_property_rating'];

$landlord_id = $property['landlord_id'];
$stmt = $conn->prepare("SELECT AVG(stars) AS avg_landlord_rating FROM ratings WHERE rating_type='landlord' AND target_id=?");
$stmt->bind_param("i", $landlord_id);
$stmt->execute();
$avg_landlord_rating = $stmt->get_result()->fetch_assoc()['avg_landlord_rating'];
?>

<!DOCTYPE html>
<html>
<head>
    <title>Property Details - HomeFinder</title>
</head>
<body>
<h2><?php echo htmlspecialchars($property['title']); ?></h2>
<p><?php echo nl2br(htmlspecialchars($property['description'])); ?></p>
<p><strong>Location:</strong> <?php echo htmlspecialchars($property['location']); ?></p>
<p><strong>Rent:</strong> $<?php echo number_format($property['rent'], 2); ?></p>
<p><strong>Landlord:</strong> <?php echo htmlspecialchars($property['landlord_name']); ?></p>
<?php if ($property['image_path']): ?>
    <img src="<?php echo $property['image_path']; ?>" width="300">
<?php endif; ?>

<h3>Ratings</h3>
<p>Property Rating: <?php echo round($avg_property_rating,1) ?: "No ratings yet"; ?></p>
<p>Landlord Rating: <?php echo round($avg_landlord_rating,1) ?: "No ratings yet"; ?></p>

<h4>Rate Property</h4>
<form method="POST" action="rate_property.php">
    <label>Stars (1-5):</label>
    <input type="number" name="stars" min="1" max="5" required><br>
    <label>Review:</label><br>
    <textarea name="review"></textarea><br>
    <input type="hidden" name="property_id" value="<?php echo $property_id; ?>">
    <button type="submit">Rate Property</button>
</form>

<h4>Rate Landlord</h4>
<form method="POST" action="rate_landlord.php">
    <label>Stars (1-5):</label>
    <input type="number" name="stars" min="1" max="5" required><br>
    <label>Review:</label><br>
    <textarea name="review"></textarea><br>
    <input type="hidden" name="landlord_id" value="<?php echo $landlord_id; ?>">
    <input type="hidden" name="property_id" value="<?php echo $property_id; ?>">
    <button type="submit">Rate Landlord</button>
</form>

<p><a href="tenant_dashboard.php">⬅ Back to Dashboard</a></p>
</body>
</html>
