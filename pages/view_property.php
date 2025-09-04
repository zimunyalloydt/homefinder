<?php
include __DIR__ . '/config/db_connect.php';
session_start();

/*Verify user is logged in
if (!isset($_SESSION['user_id'])) {
    header("Location: login.php");
    exit();
}*/

$user_id = $_SESSION['user_id'];
$role = $_SESSION['role'];
$message = '';

// Fetch ratings based on user role
if ($role === 'tenant') {
    // Get landlord ratings given by this tenant
    $landlord_ratings_stmt = $conn->prepare("
        SELECT lr.*, u.full_name AS landlord_name 
        FROM landlord_ratings lr
        JOIN users u ON lr.landlord_id = u.user_id
        WHERE lr.tenant_id = ?
    ");
    $landlord_ratings_stmt->bind_param("i", $user_id);
    $landlord_ratings_stmt->execute();
    $landlord_ratings = $landlord_ratings_stmt->get_result();

    // Get property ratings given by this tenant
    $property_ratings_stmt = $conn->prepare("
        SELECT pr.*, p.title AS property_title 
        FROM property_ratings pr
        JOIN properties p ON pr.property_id = p.property_id
        WHERE pr.tenant_id = ?
    ");
    $property_ratings_stmt->bind_param("i", $user_id);
    $property_ratings_stmt->execute();
    $property_ratings = $property_ratings_stmt->get_result();

    // Get ratings of this tenant by landlords
    $tenant_ratings_stmt = $conn->prepare("
        SELECT tr.*, u.full_name AS landlord_name 
        FROM tenant_ratings tr
        JOIN users u ON tr.landlord_id = u.user_id
        WHERE tr.tenant_id = ?
    ");
    $tenant_ratings_stmt->bind_param("i", $user_id);
    $tenant_ratings_stmt->execute();
    $tenant_ratings = $tenant_ratings_stmt->get_result();
} else {
    // Get tenant ratings given by this landlord
    $tenant_ratings_stmt = $conn->prepare("
        SELECT tr.*, u.full_name AS tenant_name 
        FROM tenant_ratings tr
        JOIN users u ON tr.tenant_id = u.user_id
        WHERE tr.landlord_id = ?
    ");
    $tenant_ratings_stmt->bind_param("i", $user_id);
    $tenant_ratings_stmt->execute();
    $tenant_ratings = $tenant_ratings_stmt->get_result();

    // Get ratings of this landlord by tenants
    $landlord_ratings_stmt = $conn->prepare("
        SELECT lr.*, u.full_name AS tenant_name 
        FROM landlord_ratings lr
        JOIN users u ON lr.tenant_id = u.user_id
        WHERE lr.landlord_id = ?
    ");
    $landlord_ratings_stmt->bind_param("i", $user_id);
    $landlord_ratings_stmt->execute();
    $landlord_ratings = $landlord_ratings_stmt->get_result();

    // Get ratings of properties owned by this landlord
    $property_ratings_stmt = $conn->prepare("
        SELECT pr.*, p.title AS property_title, u.full_name AS tenant_name 
        FROM property_ratings pr
        JOIN properties p ON pr.property_id = p.property_id
        JOIN users u ON pr.tenant_id = u.user_id
        WHERE p.landlord_id = ?
    ");
    $property_ratings_stmt->bind_param("i", $user_id);
    $property_ratings_stmt->execute();
    $property_ratings = $property_ratings_stmt->get_result();
}
?>

<!DOCTYPE html>
<html>
<head>
    <title>My Ratings - HomeFinder</title>
    <link rel="stylesheet" href="styles.css">
    <style>
        .rating-container {
            margin-bottom: 30px;
        }
        .rating-card {
            background: white;
            padding: 15px;
            border-radius: 5px;
            box-shadow: 0 2px 5px rgba(0,0,0,0.1);
            margin-bottom: 15px;
        }
        .rating-header {
            display: flex;
            justify-content: space-between;
            margin-bottom: 10px;
        }
        .rating-stars {
            color: gold;
            font-size: 1.2rem;
        }
        .rating-review {
            margin-top: 10px;
            padding-top: 10px;
            border-top: 1px solid #eee;
        }
    </style>
</head>
<body>
    <div class="container">
        <h2>My Ratings</h2>
        
        <?php if ($role === 'tenant'): ?>
            <!-- Tenant View -->
            <div class="rating-container">
                <h3>Ratings You've Given</h3>
                
                <h4>Landlord Ratings</h4>
                <?php if ($landlord_ratings->num_rows > 0): ?>
                    <?php while ($rating = $landlord_ratings->fetch_assoc()): ?>
                        <div class="rating-card">
                            <div class="rating-header">
                                <h5>Landlord: <?php echo htmlspecialchars($rating['landlord_name']); ?></h5>
                                <div class="rating-stars">
                                    <?php echo str_repeat('★', $rating['rating']) . str_repeat('☆', 5 - $rating['rating']); ?>
                                </div>
                            </div>
                            <div class="rating-date"><?php echo htmlspecialchars($rating['created_at']); ?></div>
                            <?php if (!empty($rating['review'])): ?>
                                <div class="rating-review">
                                    <?php echo htmlspecialchars($rating['review']); ?>
                                </div>
                            <?php endif; ?>
                        </div>
                    <?php endwhile; ?>
                <?php else: ?>
                    <p>You haven't rated any landlords yet.</p>
                <?php endif; ?>
                
                <h4>Property Ratings</h4>
                <?php if ($property_ratings->num_rows > 0): ?>
                    <?php while ($rating = $property_ratings->fetch_assoc()): ?>
                        <div class="rating-card">
                            <div class="rating-header">
                                <h5>Property: <?php echo htmlspecialchars($rating['property_title']); ?></h5>
                                <div class="rating-stars">
                                    <?php echo str_repeat('★', $rating['rating']) . str_repeat('☆', 5 - $rating['rating']); ?>
                                </div>
                            </div>
                            <div class="rating-date"><?php echo htmlspecialchars($rating['created_at']); ?></div>
                            <?php if (!empty($rating['review'])): ?>
                                <div class="rating-review">
                                    <?php echo htmlspecialchars($rating['review']); ?>
                                </div>
                            <?php endif; ?>
                        </div>
                    <?php endwhile; ?>
                <?php else: ?>
                    <p>You haven't rated any properties yet.</p>
                <?php endif; ?>
            </div>
            
            <div class="rating-container">
                <h3>Ratings About You</h3>
                
                <h4>Landlord Ratings of You</h4>
                <?php if ($tenant_ratings->num_rows > 0): ?>
                    <?php while ($rating = $tenant_ratings->fetch_assoc()): ?>
                        <div class="rating-card">
                            <div class="rating-header">
                                <h5>From: <?php echo htmlspecialchars($rating['landlord_name']); ?></h5>
                                <div class="rating-stars">
                                    <?php echo str_repeat('★', $rating['rating']) . str_repeat('☆', 5 - $rating['rating']); ?>
                                </div>
                            </div>
                            <div class="rating-date"><?php echo htmlspecialchars($rating['created_at']); ?></div>
                            <?php if (!empty($rating['review'])): ?>
                                <div class="rating-review">
                                    <?php echo htmlspecialchars($rating['review']); ?>
                                </div>
                            <?php endif; ?>
                        </div>
                    <?php endwhile; ?>
                <?php else: ?>
                    <p>No landlords have rated you yet.</p>
                <?php endif; ?>
            </div>
            
        <?php else: ?>
            <!-- Landlord View -->
            <div class="rating-container">
                <h3>Ratings You've Given</h3>
                
                <h4>Tenant Ratings</h4>
                <?php if ($tenant_ratings->num_rows > 0): ?>
                    <?php while ($rating = $tenant_ratings->fetch_assoc()): ?>
                        <div class="rating-card">
                            <div class="rating-header">
                                <h5>Tenant: <?php echo htmlspecialchars($rating['tenant_name']); ?></h5>
                                <div class="rating-stars">
                                    <?php echo str_repeat('★', $rating['rating']) . str_repeat('☆', 5 - $rating['rating']); ?>
                                </div>
                            </div>
                            <div class="rating-date"><?php echo htmlspecialchars($rating['created_at']); ?></div>
                            <?php if (!empty($rating['review'])): ?>
                                <div class="rating-review">
                                    <?php echo htmlspecialchars($rating['review']); ?>
                                </div>
                            <?php endif; ?>
                        </div>
                    <?php endwhile; ?>
                <?php else: ?>
                    <p>You haven't rated any tenants yet.</p>
                <?php endif; ?>
            </div>
            
            <div class="rating-container">
                <h3>Ratings About You</h3>
                
                <h4>Tenant Ratings of You</h4>
                <?php if ($landlord_ratings->num_rows > 0): ?>
                    <?php while ($rating = $landlord_ratings->fetch_assoc()): ?>
                        <div class="rating-card">
                            <div class="rating-header">
                                <h5>From: <?php echo htmlspecialchars($rating['tenant_name']); ?></h5>
                                <div class="rating-stars">
                                    <?php echo str_repeat('★', $rating['rating']) . str_repeat('☆', 5 - $rating['rating']); ?>
                                </div>
                            </div>
                            <div class="rating-date"><?php echo htmlspecialchars($rating['created_at']); ?></div>
                            <?php if (!empty($rating['review'])): ?>
                                <div class="rating-review">
                                    <?php echo htmlspecialchars($rating['review']); ?>
                                </div>
                            <?php endif; ?>
                        </div>
                    <?php endwhile; ?>
                <?php else: ?>
                    <p>No tenants have rated you yet.</p>
                <?php endif; ?>
            </div>
            
            <div class="rating-container">
                <h3>Your Property Ratings</h3>
                <?php if ($property_ratings->num_rows > 0): ?>
                    <?php while ($rating = $property_ratings->fetch_assoc()): ?>
                        <div class="rating-card">
                            <div class="rating-header">
                                <h5>Property: <?php echo htmlspecialchars($rating['property_title']); ?></h5>
                                <div class="rating-stars">
                                    <?php echo str_repeat('★', $rating['rating']) . str_repeat('☆', 5 - $rating['rating']); ?>
                                </div>
                            </div>
                            <div class="rating-info">
                                <span>From: <?php echo htmlspecialchars($rating['tenant_name']); ?></span>
                                <span class="rating-date"><?php echo htmlspecialchars($rating['created_at']); ?></span>
                            </div>
                            <?php if (!empty($rating['review'])): ?>
                                <div class="rating-review">
                                    <?php echo htmlspecialchars($rating['review']); ?>
                                </div>
                            <?php endif; ?>
                        </div>
                    <?php endwhile; ?>
                <?php else: ?>
                    <p>No tenants have rated your properties yet.</p>
                <?php endif; ?>
            </div>
        <?php endif; ?>
        
        <a href="<?php echo $role === 'tenant' ? 'tenant_dashboard.php' : 'landlord_dashboard.php'; ?>" class="btn">Back to Dashboard</a>
    </div>
</body>
</html>