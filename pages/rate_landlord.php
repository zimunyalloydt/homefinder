<?php 
include(__DIR__ . '/../config/db_connect.php');
session_start();

// Verify tenant is logged in
/*if (!isset($_SESSION['user_id']) || $_SESSION['role'] !== 'tenant') {
    header(header: "Location: login.php");
    exit();
}*/

$tenant_id   = $_SESSION['user_id'];
$landlord_id = isset($_GET['landlord_id']) ? intval($_GET['landlord_id']) : 0;
$property_id = isset($_GET['property_id']) ? intval($_GET['property_id']) : 0;
$message     = '';

// Check if tenant has rented from this landlord
$stmt = $conn->prepare("
    SELECT 1 
    FROM applications a 
    JOIN properties p ON a.property_id = p.property_id 
    WHERE a.tenant_id = ? AND p.landlord_id = ? AND a.status = 'Approved'
");
$stmt->bind_param("ii", $tenant_id, $landlord_id);
$stmt->execute();
$has_rented = $stmt->get_result()->num_rows > 0;

// Handle rating submission
if ($_SERVER['REQUEST_METHOD'] === 'POST' && $has_rented) {
    $rating = intval($_POST['rating']);
    $review = trim($_POST['review'] ?? '');

    // Validate rating (1–5)
    if ($rating < 1 || $rating > 5) {
        $message = "❌ Please select a rating between 1 and 5 stars.";
    } else {
        $stmt = $conn->prepare("
            INSERT INTO landlord_ratings (landlord_id, tenant_id, rating, review) 
            VALUES (?, ?, ?, ?)
        ");
        $stmt->bind_param("iiis", $landlord_id, $tenant_id, $rating, $review);
        
        if ($stmt->execute()) {
            $message = "✅ Thank you for your rating!";
        } else {
            $message = "❌ Error submitting rating: " . $conn->error;
        }
    }
}
?>

<!DOCTYPE html>
<html>
<head>
    <title>Rate Landlord - HomeFinder</title>
     <link rel="stylesheet" href="/../css/landlordratings.css">
    <style>
        body { font-family: Arial, sans-serif; background: #f4f4f9; }
        .container { width: 500px; margin: 40px auto; background: #fff; padding: 20px; border-radius: 8px; }
        h2 { margin-bottom: 20px; }
        .alert { padding: 10px; border-radius: 5px; margin-bottom: 15px; }
        .alert.success { background: #d4edda; color: #155724; }
        .alert.error { background: #f8d7da; color: #721c24; }
        .rating-stars { margin-bottom: 15px; }
        .star { font-size: 2rem; cursor: pointer; color: gray; }
        .star.selected { color: gold; }
        textarea { width: 100%; height: 100px; padding: 8px; margin-top: 10px; }
        .btn { padding: 10px 15px; border: none; border-radius: 5px; cursor: pointer; margin-right: 10px; }
        .btn-primary { background: #3498db; color: #fff; }
        .btn-secondary { background: #95a5a6; color: #fff; }
    </style>
</head>
<body>
    <div class="container">
        <h2>Rate Landlord</h2>
        
        <?php if (!$has_rented): ?>
            <div class="alert error">You can only rate landlords you've rented from.</div>
            <a href="tenant_dashboard.php" class="btn btn-secondary">Back to Dashboard</a>
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
                <textarea name="review" id="review" placeholder="Write your review..."></textarea>
                
                <button type="submit" class="btn btn-primary">Submit Rating</button>
                <a href="tenant_dashboard.php" class="btn btn-secondary">Cancel</a>
            </form>
        <?php endif; ?>
    </div>

    <script>
        // Handle star click
        document.querySelectorAll('.star').forEach(star => {
            star.addEventListener('click', function() {
                const rating = this.dataset.rating;
                document.getElementById('rating-value').value = rating;

                // Highlight stars
                document.querySelectorAll('.star').forEach(s => {
                    s.classList.toggle('selected', s.dataset.rating <= rating);
                });
            });
        });
    </script>
</body>
</html>
