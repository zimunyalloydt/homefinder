<?php
include(__DIR__ . '/../config/db_connect.php');

if (!isset($_GET['landlord_id'])) {
    die("Invalid request - landlord_id parameter is required");
}

$landlord_id = intval($_GET['landlord_id']);

// Fetch landlord details (from users + landlords)
$stmt = $conn->prepare("
    SELECT u.full_name, u.email, u.phone, l.house_capacity, l.tenants_changed_year
    FROM landlords l
    JOIN users u ON l.user_id = u.user_id
    WHERE l.landlord_id = ?
");

if (!$stmt) {
    die("Database error: " . $conn->error);
}

$stmt->bind_param("i", $landlord_id);
$stmt->execute();
$result = $stmt->get_result();
$landlord = $result->fetch_assoc();

if (!$landlord) {
    die("Landlord not found. ID " . $landlord_id);
}

// Fetch reviews for this landlord only
// Fetch reviews for this landlord only
$reviews_stmt = $conn->prepare("
    SELECT r.rating, r.review, u.full_name AS tenant_name, r.created_at
    FROM landlord_ratings r
    JOIN users u ON r.tenant_id = u.user_id
    WHERE r.landlord_id = ?
    ORDER BY r.created_at DESC
");

if (!$reviews_stmt) {
    die("Error preparing reviews query: " . $conn->error);
}

$reviews_stmt->bind_param("i", $landlord_id);
$reviews_stmt->execute();
$reviews = $reviews_stmt->get_result();

// Calculate average rating
$avg_rating_stmt = $conn->prepare("
    SELECT AVG(rating) as avg_rating, COUNT(*) as total_reviews 
    FROM landlord_ratings 
    WHERE landlord_id = ?
");
$avg_rating_stmt->bind_param("i", $landlord_id);
$avg_rating_stmt->execute();
$avg_result = $avg_rating_stmt->get_result();
$avg_data = $avg_result->fetch_assoc();
$average_rating = $avg_data['avg_rating'] !== null ? round($avg_data['avg_rating'], 1) : 0;
$total_reviews  = $avg_data['total_reviews'] ?? 0;

?>


<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<title>Landlord Profile - <?php echo htmlspecialchars($landlord['full_name']); ?></title>
<link rel="stylesheet" href="/../css/landlordprofile.css">
<style>
    body { font-family: Arial, sans-serif; background:#f5f5f5; margin:0; padding:0; }
    .landlord-profile { max-width: 800px; margin: 2rem auto; background: #fff; padding: 20px; border-radius: 8px; }
    .landlord-profile h2 { margin-bottom: 1rem; }
    .landlord-info p { margin: 0.5rem 0; }
    .reviews { margin-top: 2rem; }
    .review-card { border: 1px solid #ddd; padding: 10px; margin-bottom: 10px; border-radius: 5px; }
    .review-card strong { display: inline-block; width: 100px; }
    .rating-stars { color: #ffc107; font-size: 18px; }
</style>
</head>
<body>
<div class="landlord-profile">
    <h2><?php echo htmlspecialchars($landlord['full_name']); ?> - Landlord Profile</h2>

    <div class="landlord-info">
        <p><strong>Email:</strong> <a href="mailto:<?php echo htmlspecialchars($landlord['email']); ?>"><?php echo htmlspecialchars($landlord['email']); ?></a></p>
        <p><strong>Phone:</strong> <a href="tel:<?php echo htmlspecialchars($landlord['phone']); ?>"><?php echo htmlspecialchars($landlord['phone']); ?></a></p>
        <p><strong>House Capacity:</strong> <?php echo htmlspecialchars($landlord['house_capacity']); ?> people</p>
        <p><strong>Tenants Changed This Year:</strong> <?php echo htmlspecialchars($landlord['tenants_changed_year']); ?></p>
    </div>

    <!-- Show overall rating -->
    <?php if ($total_reviews > 0): ?>
        <div class="average-rating">
            <h3>Overall Rating</h3>
            <p><strong>Average Rating:</strong> 
                <span class="rating-stars">
                    <?php 
                    for ($i = 1; $i <= 5; $i++) {
                        echo $i <= round($average_rating) ? '★' : '☆';
                    }
                    ?>
                </span>
                (<?php echo $average_rating; ?>/5 from <?php echo $total_reviews; ?> reviews)
            </p>
        </div>
    <?php else: ?>
        <p>No reviews yet.</p>
    <?php endif; ?>

    <!-- Reviews list -->
    <div class="reviews">
        <h3>Reviews from Tenants</h3>
        <?php if ($reviews->num_rows > 0): ?>
            <?php while ($r = $reviews->fetch_assoc()): ?>
                <div class="review-card">
                    <p><strong>Tenant:</strong> <?php echo htmlspecialchars($r['tenant_name']); ?></p>
                    <p><strong>Rating:</strong> 
                        <span class="rating-stars">
                            <?php 
                            $rating = $r['rating'];
                            for ($i = 1; $i <= 5; $i++) {
                                echo $i <= $rating ? '★' : '☆';
                            }
                            ?>
                        </span>
                        (<?php echo $rating; ?>/5)
                    </p>
                    <p><strong>Comment:</strong> <?php echo nl2br(htmlspecialchars($r['review'])); ?></p>
                    <p><small>Date: <?php echo date('M j, Y', strtotime($r['created_at'])); ?></small></p>
                </div>
            <?php endwhile; ?>
        <?php else: ?>
            <p>No tenant reviews available.</p>
        <?php endif; ?>
    </div>
</div>
</body>
</html>
