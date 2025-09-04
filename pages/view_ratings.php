<?php
include(__DIR__ . '/../config/db_connect.php');
session_start();

if (!isset($_SESSION['user_id']) || $_SESSION['role'] !== 'landlord') {
    header("Location: login.php");
    exit();
}

$landlord_id = $_SESSION['user_id'];

// Fetch ratings for landlord's properties
$query = "
    SELECT r.rating_id, r.stars, r.review, r.created_at,
           p.title AS property_title,
           u.full_name AS tenant_name
    FROM property_ratings r
    JOIN properties p ON r.property_id = p.property_id
    JOIN users u ON r.tenant_id = u.user_id
    WHERE p.landlord_id = ?
    ORDER BY r.created_at DESC
";



$stmt = $conn->prepare($query);
$stmt->bind_param("i", $landlord_id);
$stmt->execute();
$result = $stmt->get_result();
?>
<!DOCTYPE html>
<html>
<head>
    <title>View Ratings</title>
    
    <link rel="stylesheet" href="/../css/view_ratings.css">
</head>
<body>
    <h2>📊 Property Ratings & Reviews</h2>
    <a href="dashboard_landlord.php"+ user_id class="back-btn">⬅ Back to Dashboard</a>
    <hr>

    <div class="ratings-container">
        <?php if ($result->num_rows > 0): ?>
            <?php while ($row = $result->fetch_assoc()): ?>
                <div class="rating-card">
                    <h3><?php echo htmlspecialchars($row['property_title']); ?></h3>
                  <p><strong>Tenant:</strong> <?php echo htmlspecialchars($row['tenant_name']); ?></p>

                   <p class="stars">⭐ <?php echo str_repeat("⭐", $row['stars']); ?></p>

                    <p class="review">"<?php echo htmlspecialchars($row['review']); ?>"</p>
                    <p class="date">🗓 <?php echo date("F j, Y, g:i a", strtotime($row['created_at'])); ?></p>
                </div>
            <?php endwhile; ?>
        <?php else: ?>
            <p class="no-ratings">No ratings found for your properties yet.</p>
        <?php endif; ?>
    </div>
</body>
</html>
