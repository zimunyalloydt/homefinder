<?php
include(__DIR__ . '/../config/db_connect.php');
session_start();

/* Verify landlord is logged in
if (!isset($_SESSION['user_id']) || $_SESSION['role'] !== 'landlord') {
    header("Location: login.php");
    exit();
}*/

$landlord_id = $_SESSION['user_id'];
$tenant_id = isset($_GET['tenant_id']) ? intval($_GET['tenant_id']) : 0;
$property_id = isset($_GET['property_id']) ? intval($_GET['property_id']) : 0;
$message = '';

// Check if landlord has approved this tenant (optional validation)
$has_approved = false;
$stmt = $conn->prepare("SELECT 1 FROM applications a JOIN properties p ON a.property_id = p.property_id WHERE a.tenant_id = ? AND p.landlord_id = ? AND a.status = 'Approved'");
$stmt->bind_param("ii", $tenant_id, $landlord_id);
$stmt->execute();
$has_approved = $stmt->get_result()->num_rows > 0;

// Handle rating submission
if ($_SERVER['REQUEST_METHOD'] === 'POST' && $has_approved) {
    $rating = intval($_POST['rating']);
    $review = trim($_POST['review'] ?? '');

$stmt = $conn->prepare("
    SELECT 1 
    FROM applications a 
    JOIN properties p ON a.property_id = p.property_id 
    WHERE a.tenant_id = ? 
      AND p.landlord_id = ? 
      AND a.property_id = ? 
      AND UPPER(TRIM(a.status)) = 'APPROVED'
");
$stmt->bind_param("iii", $tenant_id, $landlord_id, $property_id);
$stmt->execute();
$has_approved = $stmt->get_result()->num_rows > 0;


    if ($stmt->execute()) {
        $message = "✅ Thank you for your rating!";
    } else {
        $message = "❌ Error submitting rating: " . $conn->error;
    }
}
?>

<!DOCTYPE html>
<html>
<head>
    <title>Rate Tenant - HomeFinder</title>
    <link rel="stylesheet" href="styles.css">
    <style>
        /* Same styles as rate_property.php */
    </style>
</head>
<body>
    <div class="container">
        <h2>Rate Tenant</h2>
        
        <?php if (!$has_approved): ?>
            <div class="alert error">You can only rate tenants you've approved.</div>
            <a href="landlord_dashboard.php" class="btn">Back to Dashboard</a>
        <?php else: ?>
            <?php if ($message): ?>
                <div class="alert <?php echo strpos($message, '✅') !== false ? 'success' : 'error'; ?>">
                    <?php echo htmlspecialchars($message); ?>
                </div>
            <?php endif; ?>
            
            <form method="POST">
                <div class="rating-stars">
                    <?php for ($i = 1; $i <= 5; $i++): ?>
                        <span class="star" data-rating="<?php echo $i; ?>">★</span>
                    <?php endfor; ?>
                    <input type="hidden" name="rating" id="rating-value" value="0" required>
                </div>
                
                <label for="review">Review (optional):</label>
                <textarea name="review" id="review"></textarea>
                
                <button type="submit" class="btn">Submit Rating</button>
                <a href="landlord_dashboard.php" class="btn">Cancel</a>
            </form>
            <form method="POST" action="rate_tenant.php?tenant_id=<?php echo $row['tenant_id']; ?>&property_id=<?php echo $row['property_id']; ?>">

        <?php endif; ?>
    </div>

    <script>
        // Same JavaScript as rate_property.php
    </script>
</body>
</html>