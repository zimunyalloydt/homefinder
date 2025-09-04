<?php
include(__DIR__ . '/config/db_connect.php');

session_start();

// Fetch all available properties
$stmt = $conn->prepare("
    SELECT p.*, 
           (SELECT pi.image_path FROM property_images pi WHERE pi.property_id = p.property_id LIMIT 1) AS main_image,
           u.full_name AS landlord_name,
           u.user_id AS landlord_id,
           -- Property average rating
           (SELECT ROUND(AVG(stars),1) FROM property_ratings pr WHERE pr.property_id = p.property_id) AS avg_property_rating,
           -- Landlord average rating
           (SELECT ROUND(AVG(rating),1) FROM landlord_ratings lr WHERE lr.landlord_id = p.landlord_id) AS avg_landlord_rating
    FROM properties p
    JOIN users u ON p.landlord_id = u.user_id
    WHERE p.status = 'Available'
    ORDER BY p.date_posted DESC
");
$stmt->execute();
$properties = $stmt->get_result();

// Function: get latest reviews
function getLandlordReviews($conn, $landlord_id) {
    $sql = "SELECT r.review, r.rating, t.full_name 
            FROM landlord_ratings r
            JOIN users t ON r.tenant_id = t.user_id 
            WHERE r.landlord_id = ? AND r.review <> '' 
            ORDER BY r.created_at DESC 
            LIMIT 2";
    $stmt = $conn->prepare($sql);
    $stmt->bind_param("i", $landlord_id);
    $stmt->execute();
    return $stmt->get_result();
}

function getPropertyReviews($conn, $property_id) {
    $sql = "SELECT r.review, r.stars, t.full_name 
            FROM property_ratings r
            JOIN users t ON r.tenant_id = t.user_id 
            WHERE r.property_id = ? AND r.review <> '' 
            ORDER BY r.created_at DESC 
            LIMIT 2";
    $stmt = $conn->prepare($sql);
    $stmt->bind_param("i", $property_id);
    $stmt->execute();
    return $stmt->get_result();
}
?>

<!DOCTYPE html>
<html>
<head>
    <title>HomeFinder - Find Your Next Home</title>
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
    <link rel="stylesheet" href="css/landing.css">
</head>
<body>
    <header>
        <h1>🏡 HomeFinder</h1>
        
        <nav>
            <?php if (isset($_SESSION['user_id'])): ?>
                <a href="pages/dashboard_tenant.php">Dashboard</a>
                <a href="pages/logout.php">Login</a>
            <?php else: ?>
                <a href='/pages/login.php'>Login</a>
                <a href="/pages/register.php">Register</a>
            <?php endif; ?>
        </nav>
    </header>

    <div class="container">
        <h2>Available Properties</h2>
        <?php if ($properties->num_rows > 0): ?>
            <div class="property-grid">
                <?php while ($property = $properties->fetch_assoc()): ?>
                    <div class="property-card">
                        <!-- Property Image -->
                        <?php if ($property['main_image']): ?>
                            <img src="<?php echo htmlspecialchars($property['main_image']); ?>" alt="Property Image">
                        <?php else: ?>
                            <img src="/images/no-image.png" alt="No Image">
                        <?php endif; ?>
                        
                        <!-- Property Details -->
                        <div class="property-details">
                            <h3><?php echo htmlspecialchars($property['title']); ?></h3>
                            <p><strong>📍 Location:</strong> <?php echo htmlspecialchars($property['location']); ?></p>
                            <p><strong>💰 Rent:</strong> $<?php echo number_format($property['price'], 2); ?></p>
                            <p><strong>🏠 Type:</strong> <?php echo htmlspecialchars($property['type']); ?></p>
                            <p><strong>👤 Landlord:</strong> <?php echo htmlspecialchars($property['landlord_name']); ?></p>

                            <!-- Ratings -->
                            <p class="rating">⭐ Property Rating: 
                                <?php echo $property['avg_property_rating'] ? $property['avg_property_rating']." / 5" : "No ratings yet"; ?>
                            </p>
                            <p class="rating">⭐ Landlord Rating: 
                                <?php echo $property['avg_landlord_rating'] ? $property['avg_landlord_rating']." / 5" : "No ratings yet"; ?>
                            </p>

                            <!-- Property Reviews -->
                            
<!-- Property Reviews -->
<?php 
$propReviews = getPropertyReviews($conn, $property['property_id']); 
if ($propReviews->num_rows > 0): ?>
    <div class="reviews">
        <strong>Recent Property Reviews:</strong>
        <?php while ($r = $propReviews->fetch_assoc()): ?>
            <div class="review">
                <span class="rating">⭐ <?php echo $r['stars']; ?>/5</span> 
                - <?php echo htmlspecialchars($r['review']); ?> 
                <em>(by <?php echo htmlspecialchars($r['full_name']); ?>)</em>
            </div>
        <?php endwhile; ?>
    </div>
<?php endif; ?>

<!-- Landlord Reviews -->
<?php 
$landlordReviews = getLandlordReviews($conn, $property['landlord_id']); 
if ($landlordReviews->num_rows > 0): ?>
    <div class="reviews">
        <strong>Recent Landlord Reviews:</strong>
        <?php while ($r = $landlordReviews->fetch_assoc()): ?>
            <div class="review">
                <span class="rating">⭐ <?php echo $r['rating']; ?>/5</span> 
                - <?php echo htmlspecialchars($r['review']); ?> 
                <em>(by <?php echo htmlspecialchars($r['full_name']); ?>)</em>
            </div>
        <?php endwhile; ?>
    </div>
<?php endif; ?>

                            <!-- Landlord Reviews -->
                            
                            
                        </div>

                        <!-- Favourite Button -->
                        <div style="padding:15px;">
                            <a href="pages/login.php" class="btn btn-fav"><i class="fas fa-heart"></i> Add to Favourites</a>
                        </div>
                    </div>
                <?php endwhile; ?>
            </div>
        <?php else: ?>
            <p>No available properties right now. Check back later!</p>
        <?php endif; ?>
    </div>
</body>
</html>
