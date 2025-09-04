<?php
include(__DIR__ . '/../config/db_connect.php');
session_start();

/* Redirect if not tenant
if (!isset($_SESSION['user_id']) || $_SESSION['role'] !== 'tenant') {
    header("Location: login.php");
    exit();
}*/

$tenant_id = $_SESSION['user_id'];
$message = "";

// Get property_id from query string
$property_id = intval($_GET['id'] ?? 0);

// Fetch property details
$stmt = $conn->prepare("SELECT * FROM properties WHERE property_id = ?");
$stmt->bind_param("i", $property_id);
$stmt->execute();
$property = $stmt->get_result()->fetch_assoc();

if (!isset($_GET['property_id'])) {
    echo "❌ Property not found.";
    exit();
}
$property_id = intval($_GET['property_id']);


// Handle rating submission
if ($_SERVER["REQUEST_METHOD"] == "POST") {
    $stars = intval($_POST['stars'] ?? 0);
    $review = trim($_POST['review'] ?? '');

    if ($stars < 1 || $stars > 5) {
        $message = "❌ Please select a rating between 1 and 5.";
    } else {
        $stmt = $conn->prepare("INSERT INTO property_ratings 
            (property_id, tenant_id, stars, review, created_at) 
            VALUES (?, ?, ?, ?, NOW())");
        $stmt->bind_param("iiis", $property_id, $tenant_id, $stars, $review);

        if ($stmt->execute()) {
            $message = "✅ Thank you for rating this property!";
        } else {
            $message = "❌ Error: " . $conn->error;
        }
    }
}
?>
<!DOCTYPE html>
<html>
<head>
    <title>Rate Property - HomeFinder</title>
    <link rel="stylesheet" href="/../css/rateproperty.css">
</head>
<body>
    <div class="container">
        <h2>Rate Property</h2>
        <p><a href="tenant_dashboard.php">⬅ Back to Dashboard</a></p>

        <?php if ($property): ?>
            <div class="property-card">
                <h3><?php echo htmlspecialchars($property['title']); ?></h3>
                <p><b>Location:</b> <?php echo htmlspecialchars($property['location']); ?></p>
                <p><b>Rent:</b> $<?php echo htmlspecialchars($property['price']); ?></p>
                <p><b>Type:</b> <?php echo htmlspecialchars($property['type']); ?></p>
            </div>
        <?php endif; ?>

        <?php if ($message): ?>
            <div class="message <?php echo strpos($message, '✅') !== false ? 'success' : 'error'; ?>">
                <?php echo htmlspecialchars($message); ?>
            </div>
        <?php endif; ?>

        <form method="POST" class="rate-form">
            <div class="form-group">
                <label>Rating (1 to 5 ⭐):</label>
                <select name="stars" required>
                    <option value="">-- Select --</option>
                    <option value="1">⭐</option>
                    <option value="2">⭐⭐</option>
                    <option value="3">⭐⭐⭐</option>
                    <option value="4">⭐⭐⭐⭐</option>
                    <option value="5">⭐⭐⭐⭐⭐</option>
                </select>
            </div>

            <div class="form-group">
                <label>Review:</label>
                <textarea name="review" placeholder="Write your experience..."></textarea>
            </div>

            <div class="form-group">
                <button type="submit">✅ Submit Rating</button>
            </div>
        </form>
    </div>
</body>
</html>
