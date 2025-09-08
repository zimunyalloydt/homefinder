<?php
include(__DIR__ . '/../config/db_connect.php');
session_start();


$currentUserId = $_SESSION['user_id'];

// find a tenant who applied
$sql = "SELECT a.tenant_id 
        FROM applications a
        JOIN properties p ON a.property_id = p.property_id
        WHERE p.landlord_id=? 
        ORDER BY a.date_applied DESC 
        LIMIT 1";

$stmt = $conn->prepare($sql);
$stmt->bind_param("i", $currentUserId);
$stmt->execute();
$result = $stmt->get_result();
$row = $result->fetch_assoc();

$otherUserId = $row['tenant_id'] ?? 0;


// Redirect if not logged in or not landlord
if (!isset($_SESSION['user_id']) || $_SESSION['role'] !== 'landlord') {
    header("Location: login.php");
    exit();
}

$landlord_id = $_SESSION['user_id'];
$full_name   = $_SESSION['full_name'];
$message = "";

// Handle property submission (from the new Add Property tab)
if ($_SERVER["REQUEST_METHOD"] == "POST" && isset($_POST['title'])) {
    // Validate and sanitize inputs
    $title = trim($_POST['title'] ?? '');
    $description = trim($_POST['description'] ?? '');
    $location = trim($_POST['location'] ?? '');
    $price = floatval($_POST['rent'] ?? 0);
    $type = $_POST['type'] ?? 'House';
    $bedrooms = intval($_POST['bedrooms'] ?? 0);
    $bathrooms = intval($_POST['bathrooms'] ?? 0);
    $status = $_POST['status'] ?? 'Available';

    // Begin transaction
    $conn->begin_transaction();

    try {
        // Insert property into DB
        $stmt = $conn->prepare("INSERT INTO properties 
            (landlord_id, title, description, location, price, status, type, bedrooms, bathrooms) 
            VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?)");
        $stmt->bind_param("isssdssii", 
            $landlord_id, 
            $title, 
            $description, 
            $location, 
            $price, 
            $status, 
            $type, 
            $bedrooms, 
            $bathrooms
        );

        if (!$stmt->execute()) {
            throw new Exception("Error saving property: " . $conn->error);
        }

        $property_id = $conn->insert_id;

        // Handle multiple image uploads
        if (!empty($_FILES["images"]["name"][0])) {
            $targetDir = "../uploads/";
            
            // Create uploads directory if it doesn't exist
            if (!file_exists($targetDir)) {
                mkdir($targetDir, 0755, true);
            }

            // Process each uploaded file
            foreach ($_FILES["images"]["tmp_name"] as $key => $tmp_name) {
                $fileName = $_FILES["images"]["name"][$key];
                $fileTmp = $_FILES["images"]["tmp_name"][$key];
                $fileSize = $_FILES["images"]["size"][$key];
                $fileError = $_FILES["images"]["error"][$key];
                
                // Skip if error
                if ($fileError !== UPLOAD_ERR_OK) {
                    continue;
                }

                // Check if file is an image
                $check = getimagesize($fileTmp);
                if ($check === false) {
                    continue;
                }

                // Generate unique filename
                $fileExtension = strtolower(pathinfo($fileName, PATHINFO_EXTENSION));
                $newFilename = uniqid() . '.' . $fileExtension;
                $targetFile = $targetDir . $newFilename;

                // Move uploaded file
                if (move_uploaded_file($fileTmp, $targetFile)) {
                    // Insert image record into database
                    $imageStmt = $conn->prepare("INSERT INTO property_images 
                        (property_id, image_path, is_primary) 
                        VALUES (?, ?, ?)");
                    $isPrimary = ($key === 0) ? 1 : 0; // First image is primary
                    $imageStmt->bind_param("isi", $property_id, $targetFile, $isPrimary);
                    
                    if (!$imageStmt->execute()) {
                        throw new Exception("Error saving image: " . $conn->error);
                    }
                }
            }
        }

        // Commit transaction if all successful
        $conn->commit();
        $message = "✅ Property added successfully!";
        
        // Refresh properties list
        $stmt = $conn->prepare("
            SELECT p.*, 
                   (SELECT GROUP_CONCAT(pi.image_path) FROM property_images pi WHERE pi.property_id = p.property_id) AS all_images,
                   (SELECT COUNT(*) FROM applications a WHERE a.property_id = p.property_id AND a.status = 'Pending') AS pending_applications
            FROM properties p 
            WHERE p.landlord_id = ?
            ORDER BY p.date_posted DESC
        ");
        $stmt->bind_param("i", $landlord_id);
        $stmt->execute();
        $properties = $stmt->get_result();
        
    } catch (Exception $e) {
        // Rollback on error
        $conn->rollback();
        $message = "❌ Error: " . $e->getMessage();
    }
}

// Handle application approval/rejection
if ($_SERVER['REQUEST_METHOD'] == 'POST' && isset($_POST['application_id']) && isset($_POST['status'])) {
    $application_id = intval($_POST['application_id']);
    $status = $_POST['status'];
    
    // Verify that this application belongs to one of the landlord's properties
    $verify_stmt = $conn->prepare("
        SELECT a.*, p.title as property_title FROM applications a 
        JOIN properties p ON a.property_id = p.property_id 
        WHERE a.application_id = ? AND p.landlord_id = ?
    ");
    $verify_stmt->bind_param("ii", $application_id, $landlord_id);
    $verify_stmt->execute();
    $verified_application = $verify_stmt->get_result();
    
    if (isset($_POST['send_message'])) {
    $receiver_id = intval($_POST['receiver_id']);
    $msg_text = trim($_POST['message_text']);
    if (!empty($msg_text)) {
        $stmt = $conn->prepare("INSERT INTO messages (sender_id, receiver_id, message_text, created_at) VALUES (?, ?, ?, NOW())");
        $stmt->bind_param("iis", $landlord_id, $receiver_id, $msg_text);
        $stmt->execute();
    }
}

    if ($verified_application->num_rows > 0) {
        $app_data = $verified_application->fetch_assoc();
        
        // Update application status
        $update_stmt = $conn->prepare("UPDATE applications SET status = ? WHERE application_id = ?");
        $update_stmt->bind_param("si", $status, $application_id);
        
        if ($update_stmt->execute()) {
            $message = "✅ Application " . strtolower($status) . " successfully for '{$app_data['property_title']}'!";
            
            // If approved, automatically reject all other pending applications for the same property
            if ($status === 'Approved') {
                $reject_others_stmt = $conn->prepare("
                    UPDATE applications 
                    SET status = 'Rejected' 
                    WHERE property_id = ? 
                    AND application_id != ? 
                    AND status = 'Pending'
                ");
                $reject_others_stmt->bind_param("ii", $app_data['property_id'], $application_id);
                $reject_others_stmt->execute();
            }
        } else {
            $message = "❌ Error updating application: " . $conn->error;
        }
    } else {
        $message = "❌ Unauthorized action.";
    }
}

// Fetch landlord properties with all details and images
$stmt = $conn->prepare("
    SELECT p.*, 
           (SELECT GROUP_CONCAT(pi.image_path) FROM property_images pi WHERE pi.property_id = p.property_id) AS all_images,
           (SELECT COUNT(*) FROM applications a WHERE a.property_id = p.property_id AND a.status = 'Pending') AS pending_applications
    FROM properties p 
    WHERE p.landlord_id = ?
    ORDER BY p.date_posted DESC
");
$stmt->bind_param("i", $landlord_id);
$stmt->execute();
$properties = $stmt->get_result();

$allRatingsStmt = $conn->prepare("
    SELECT lr.rating, lr.review, lr.created_at, u.full_name AS reviewer_name,
           'landlord' AS target_type, NULL AS property_title
    FROM landlord_ratings lr
    JOIN users u ON lr.tenant_id = u.user_id
    WHERE lr.landlord_id = ?

    UNION ALL

    SELECT pr.stars AS rating, pr.review, pr.created_at, u.full_name AS reviewer_name,
           'property' AS target_type, p.title AS property_title
    FROM property_ratings pr
    JOIN users u ON pr.tenant_id = u.user_id
    JOIN properties p ON pr.property_id = p.property_id
    WHERE p.landlord_id = ?

    ORDER BY created_at DESC
");
$allRatingsStmt->bind_param("ii", $landlord_id, $landlord_id);
$allRatingsStmt->execute();
$resultRatings = $allRatingsStmt->get_result();
$ratings = $resultRatings->fetch_all(MYSQLI_ASSOC);

// Calculate average rating for landlord
$avg_landlord_rating_stmt = $conn->prepare("
    SELECT AVG(rating) as avg_rating, COUNT(*) as total_reviews
    FROM landlord_ratings 
    WHERE landlord_id = ?
");
$avg_landlord_rating_stmt->bind_param("i", $landlord_id);
$avg_landlord_rating_stmt->execute();
$avg_landlord_rating = $avg_landlord_rating_stmt->get_result()->fetch_assoc();

// Calculate average rating for properties
$avg_properties_rating_stmt = $conn->prepare("
    SELECT AVG(pr.stars) as avg_rating, COUNT(*) as total_reviews
    FROM property_ratings pr
    JOIN properties p ON pr.property_id = p.property_id
    WHERE p.landlord_id = ?
");
$avg_properties_rating_stmt->bind_param("i", $landlord_id);
$avg_properties_rating_stmt->execute();
$avg_properties_rating = $avg_properties_rating_stmt->get_result()->fetch_assoc();

// Fetch all tenants the landlord has messages with
$conversations_stmt = $conn->prepare("
    SELECT DISTINCT u.user_id AS other_user_id, u.full_name
    FROM messages m
    JOIN users u ON (CASE 
                        WHEN m.sender_id = ? THEN m.receiver_id
                        ELSE m.sender_id
                     END) = u.user_id
    WHERE m.sender_id = ? OR m.receiver_id = ?
");
$conversations_stmt->bind_param("iii", $landlord_id, $landlord_id, $landlord_id);
$conversations_stmt->execute();
$conversations = $conversations_stmt->get_result();

$active_chat_user = isset($_GET['chat_with']) ? intval($_GET['chat_with']) : 0;

$chat_messages = null;
if ($active_chat_user > 0) {
    $chat_stmt = $conn->prepare("
        SELECT m.*, u.full_name AS sender_name
        FROM messages m
        JOIN users u ON m.sender_id = u.user_id
        WHERE (m.sender_id = ? AND m.receiver_id = ?)
           OR (m.sender_id = ? AND m.receiver_id = ?)
        ORDER BY m.created_at ASC
    ");
    $chat_stmt->bind_param("iiii", $landlord_id, $active_chat_user, $active_chat_user, $landlord_id);
    $chat_stmt->execute();
    $chat_messages = $chat_stmt->get_result();
}


?>

<!DOCTYPE html>
<html>
<head>
    <title>Landlord Dashboard - HomeFinder</title>
    <link rel="stylesheet" href="/../css/landlorddashboard.css">
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/swiper@8/swiper-bundle.min.css">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.0.0/css/all.min.css">
    <style>
       
    </style>
</head>
<body>
    <div class="container">
        <header>
            <h2>Welcome, <?php echo htmlspecialchars($full_name); ?> (Landlord)</h2>
            <div class="actions">
                <a href="logout.php" class="btn btn-danger">🚪 Logout</a>
            </div>
        </header>

        <?php if (!empty($message)): ?>
            <div class="alert <?php echo strpos($message, '❌') !== false ? 'error' : ''; ?>">
                <?php echo htmlspecialchars($message); ?>
            </div>
        <?php endif; ?>

        <div class="tab-container">
            <div class="tab-buttons">
                <button class="tab-button active" onclick="openTab('properties')">My Properties</button>
                <button class="tab-button" onclick="openTab('add-property')">Add Property</button>
                <button class="tab-button" onclick="openTab('applications')">Applications</button>
                <button class="tab-button" onclick="openTab('ratings')">Ratings</button>
            <?php if ($otherUserId > 0): ?>
    <a href="messages.php?chat_with=<?php echo $otherUserId; ?>" class="btn btn-primary">💬 Messages</a>
<?php endif; ?>

            </div>

            <div id="properties" class="tab-content active">
                <h3>Your Properties</h3>
                
                <?php if ($properties->num_rows > 0): ?>
                    <div class="property-grid">
                        <?php while ($property = $properties->fetch_assoc()): 
                            $images = !empty($property['all_images']) ? explode(',', $property['all_images']) : [];
                        ?>
                            <div class="property-card">
                                <!-- Property Images Slider -->
                                <div class="property-images">
                                    <?php if (!empty($images)): ?>
                                        <div class="swiper">
                                            <div class="swiper-wrapper">
                                                <?php foreach ($images as $image): ?>
                                                    <div class="swiper-slide">
                                                        <img src="<?php echo htmlspecialchars($image); ?>" alt="<?php echo htmlspecialchars($property['title']); ?>">
                                                    </div>
                                                <?php endforeach; ?>
                                            </div>
                                            <div class="swiper-pagination"></div>
                                            <div class="swiper-button-prev"></div>
                                            <div class="swiper-button-next"></div>
                                        </div>
                                    <?php else: ?>
                                        <div class="no-image">No Images Available</div>
                                    <?php endif; ?>
                                </div>
                                
                                <!-- Property Details -->
                                <div class="property-details">
                                    <h4><?php echo htmlspecialchars($property['title']); ?></h4>
                                    <div style="display:contents; grid-template-columns: repeat(auto-fit, minmax(150px, 1fr)); gap: 10px; margin: 10px 0;">
                                        <p><strong>📍 Location:</strong> <?php echo htmlspecialchars($property['location']); ?></p>
                                        <p><strong>🏠 Type:</strong> <?php echo htmlspecialchars($property['type']); ?></p>
                                        <p><strong>💰 Rent:</strong> $<?php echo number_format($property['price'], 2); ?></p>
                                        <p><strong>🛏️ Bedrooms:</strong> <?php echo htmlspecialchars($property['bedrooms']); ?></p>
                                        <p><strong>🚿 Bathrooms:</strong> <?php echo htmlspecialchars($property['bathrooms']); ?></p>
                                        <p><strong>📊 Status:</strong> 
                                            <span class="status-<?php echo strtolower($property['status']); ?>">
                                                <?php echo htmlspecialchars($property['status']); ?>
                                            </span>
                                        </p>
                                    </div>
                                    
                                    <!-- Property Description -->
                                    <div class="property-description">
                                        <p><?php echo htmlspecialchars($property['description']); ?></p>
                                    </div>
                                    
                                    <!-- Action Buttons -->
                                    <div class="property-actions" style="margin-top: 15px;">
                                        <a href="edit_property.php?id=<?php echo $property['property_id']; ?>" class="btn btn-info">
                                            <i class="fas fa-edit"></i> Edit
                                        </a>
                                        <a href="delete_property.php?id=<?php echo $property['property_id']; ?>" class="btn btn-danger" onclick="return confirm('Are you sure you want to delete this property?');">
                                            <i class="fas fa-trash"></i> Delete
                                        </a>
                                        <a href="manage_images.php?id=<?php echo $property['property_id']; ?>" class="btn btn-info">
                                            <i class="fas fa-images"></i> Manage Images
                                        </a>
                                    </div>
                                </div>
                            </div>
                        <?php endwhile; ?>
                    </div>
                <?php else: ?>
                    <div class="no-properties">
                        <p>No properties added yet. Use the "Add Property" tab to create your first listing!</p>
                    </div>
                <?php endif; ?>
            </div>

            <div id="add-property" class="tab-content">
                <h3>Add New Property</h3>
                
                <?php if (!empty($message) && isset($_POST['title'])): ?>
                    <div class="message <?php echo strpos($message, '✅') !== false ? 'success' : 'error'; ?>">
                        <?php echo htmlspecialchars($message); ?>
                    </div>
                <?php endif; ?>

                <form method="POST" enctype="multipart/form-data" class="property-form">
                    <div class="form-group">
                        <label>Title:</label>
                        <input type="text" name="title" required value="<?php echo htmlspecialchars($_POST['title'] ?? ''); ?>">
                    </div>

                    <div class="form-group">
                        <label>Description / House rules and expectations:</label>
                        <textarea name="description" required><?php echo htmlspecialchars($_POST['description'] ?? ''); ?></textarea>
                    </div>

                    <div class="form-group">
                        <label>Location:</label>
                        <input type="text" name="location" required value="<?php echo htmlspecialchars($_POST['location'] ?? ''); ?>">
                    </div>

                    <div class="form-group">
                        <label>Rent (USD):</label>
                        <input type="number" step="0.01" name="rent" required value="<?php echo htmlspecialchars($_POST['rent'] ?? ''); ?>">
                    </div>

                    <div class="form-group">
                        <label>Status:</label>
                        <select name="status" required>
                            <option value="Available" <?php echo ($_POST['status'] ?? '') === 'Available' ? 'selected' : ''; ?>>Available</option>
                            <option value="Occupied" <?php echo ($_POST['status'] ?? '') === 'Occupied' ? 'selected' : ''; ?>>Occupied</option>
                        </select>
                    </div>

                    <div class="form-group">
                        <label>Type:</label>
                        <select name="type" required>
                            <option value="House" <?php echo ($_POST['type'] ?? '') === 'House' ? 'selected' : ''; ?>>House</option>
                            <option value="Apartment" <?php echo ($_POST['type'] ?? '') === 'Apartment' ? 'selected' : ''; ?>>Apartment</option>
                            <option value="Room" <?php echo ($_POST['type'] ?? '') === 'Room' ? 'selected' : ''; ?>>Room</option>
                            <option value="Cottage" <?php echo ($_POST['type'] ?? '') === 'Cottage' ? 'selected' : ''; ?>>Cottage</option>
                            <option value="Other" <?php echo ($_POST['type'] ?? '') === 'Other' ? 'selected' : ''; ?>>Other</option>
                        </select>
                    </div>

                    <div class="form-group">
                        <label>Bedrooms:</label>
                        <input type="number" name="bedrooms" min="0" required value="<?php echo htmlspecialchars($_POST['bedrooms'] ?? ''); ?>">
                    </div>

                    <div class="form-group">
                        <label>Bathrooms:</label>
                        <input type="number" name="bathrooms" min="0" step="0.5" required value="<?php echo htmlspecialchars($_POST['bathrooms'] ?? ''); ?>">
                    </div>

                    <div class="form-group">
                        <label>Property Images:</label>
                        <input type="file" name="images[]" multiple accept="image/*">
                        <small>Select multiple images (Max 10MB each, JPG/PNG/GIF)</small>
                    </div>

                    <div class="form-group">
                        <button type="submit">➕ Add Property</button>
                    </div>
                </form>
            </div>

            <div id="applications" class="tab-content">
                <h3>Property Applications</h3>
                
                <?php 
                // Fetch all applications for landlord's properties
                $apps_stmt = $conn->prepare("
                    SELECT a.*, p.title as property_title, p.location, p.price, 
                           u.full_name, u.email, u.phone, u.user_id as tenant_id,
                           t.family_size, t.profession, t.additional_info,
                           DATEDIFF(NOW(), a.date_applied) as days_ago
                    FROM applications a 
                    JOIN properties p ON a.property_id = p.property_id
                    JOIN users u ON a.tenant_id = u.user_id 
                    LEFT JOIN tenants t ON u.user_id = t.user_id
                    WHERE p.landlord_id = ?
                    ORDER BY 
                        CASE WHEN a.status = 'Pending' THEN 1 ELSE 2 END,
                        a.date_applied DESC
                ");
                $apps_stmt->bind_param("i", $landlord_id);
                $apps_stmt->execute();
                $all_applications = $apps_stmt->get_result();
                ?>
                
                <?php if ($all_applications->num_rows > 0): ?>
                    <div class="applications-list">
                        <?php while ($app = $all_applications->fetch_assoc()): ?>
                            <div class="application <?php echo strtolower($app['status']); ?>">
                                <?php if ($app['status'] === 'Pending' && $app['days_ago'] <= 2): ?>
                                    <span class="application-badge badge-new">NEW</span>
                                <?php endif; ?>
                                
                                <div class="application-header">
                                    <h4><?php echo htmlspecialchars($app['full_name']); ?> - <?php echo htmlspecialchars($app['property_title']); ?></h4>
                                    <span class="status-<?php echo strtolower($app['status']); ?>">
                                        <?php echo htmlspecialchars($app['status']); ?>
                                        <?php if ($app['status'] === 'Pending'): ?>
                                            <br><small>(<?php echo $app['days_ago']; ?> days ago)</small>
                                        <?php endif; ?>
                                    </span>
                                </div>
                                
                                <div class="application-info">
                                    <p><strong>📍 Property:</strong> <?php echo htmlspecialchars($app['property_title']); ?> - <?php echo htmlspecialchars($app['location']); ?></p>
                                    <p><strong>💰 Rent:</strong> $<?php echo number_format($app['price'], 2); ?></p>
                                    <p><strong>📅 Applied on:</strong> <?php echo date('M j, Y', strtotime($app['date_applied'])); ?></p>
                                    <p><strong>📧 Email:</strong> <a href="mailto:<?php echo htmlspecialchars($app['email']); ?>"><?php echo htmlspecialchars($app['email']); ?></a></p>
                                    <p><strong>📞 Phone:</strong> <a href="tel:<?php echo htmlspecialchars($app['phone']); ?>"><?php echo htmlspecialchars($app['phone']); ?></a></p>
                                    <p><strong>👨‍👩‍👧‍👦 Family Size:</strong> <?php echo htmlspecialchars($app['family_size'] ?? 'N/A'); ?></p>
                                    <p><strong>💼 Profession:</strong> <?php echo htmlspecialchars($app['profession'] ?? 'N/A'); ?></p>
                                </div>
                                
                                <?php if (!empty($app['additional_info'])): ?>
                                    <p><strong>📝 Additional Info:</strong><br><?php echo nl2br(htmlspecialchars($app['additional_info'] ?? 'N/A')); ?></p>
                                <?php endif; ?>
                                
                                <div class="application-actions">
                                    <?php if ($app['status'] === 'Pending'): ?>
                                        <form method="POST" style="display: inline;">
                                            <input type="hidden" name="application_id" value="<?php echo $app['application_id']; ?>">
                                            <input type="hidden" name="status" value="Approved">
                                            <button type="submit" class="btn btn-success" onclick="return confirm('Approve <?php echo htmlspecialchars($app['full_name']); ?>\\'s application? This will automatically reject all other pending applications for this property.')">
                                                <i class="fas fa-check"></i> Approve
                                            </button>
                                        </form>
                                        <form method="POST" style="display: inline;">
                                            <input type="hidden" name="application_id" value="<?php echo $app['application_id']; ?>">
                                            <input type="hidden" name="status" value="Rejected">
                                            <button type="submit" class="btn btn-danger" onclick="return confirm('Reject <?php echo htmlspecialchars($app['full_name']); ?>\\'s application?')">
                                                <i class="fas fa-times"></i> Reject
                                            </button>
                                        </form>
                                        <a href="tel:<?php echo htmlspecialchars($app['phone']); ?>" class="btn btn-info">
                                            <i class="fas fa-phone"></i> Call
                                        </a>
                                        <a href="mailto:<?php echo htmlspecialchars($app['email']); ?>" class="btn btn-info">
                                            <i class="fas fa-envelope"></i> Email
                                        </a>
                                    <?php elseif ($app['status'] === 'Approved'): ?>
                                        <a href="rate_tenant.php?tenant_id=<?php echo $app['tenant_id']; ?>&property_id=<?php echo $app['property_id']; ?>" 
                                           class="btn btn-info" title="Rate this tenant">
                                           <i class="fas fa-star"></i> Rate Tenant
                                        </a>
                                        <a href="tel:<?php echo htmlspecialchars($app['phone']); ?>" class="btn btn-info">
                                            <i class="fas fa-phone"></i> Call
                                        </a>
                                        <a href="mailto:<?php echo htmlspecialchars($app['email']); ?>" class="btn btn-info">
                                            <i class="fas fa-envelope"></i> Email
                                        </a>
                                    <?php endif; ?>
                                </div>
                            </div>
                        <?php endwhile; ?>
                    </div>
                <?php else: ?>
                    <p>No applications yet for your properties.</p>
                <?php endif; ?>
            </div>

           <div id="ratings" class="tab-content">
                <h3>My Ratings & Reviews</h3>
                
                <div class="rating-summary">
                    <div class="rating-card">
                        <h4>Landlord Rating</h4>
                        <div class="rating-value"><?php echo number_format($avg_landlord_rating['avg_rating'] ?? 0, 1); ?></div>
                        <div class="rating-stars">
                            <?php
                            $avg_rating = $avg_landlord_rating['avg_rating'] ?? 0;
                            for ($i = 1; $i <= 5; $i++): 
                                if ($i <= floor($avg_rating)): ?>
                                    <i class="fas fa-star"></i>
                                <?php elseif ($i == ceil($avg_rating) && fmod($avg_rating, 1) > 0): ?>
                                    <i class="fas fa-star-half-alt"></i>
                                <?php else: ?>
                                    <i class="far fa-star"></i>
                                <?php endif;
                            endfor; ?>
                        </div>
                        <div class="rating-count">Based on <?php echo $avg_landlord_rating['total_reviews'] ?? 0; ?> reviews</div>
                    </div>
                    
                    <div class="rating-card">
                        <h4>Properties Rating</h4>
                        <div class="rating-value"><?php echo number_format($avg_properties_rating['avg_rating'] ?? 0, 1); ?></div>
                        <div class="rating-stars">
                            <?php
                            $avg_prop_rating = $avg_properties_rating['avg_rating'] ?? 0;
                            for ($i = 1; $i <= 5; $i++): 
                                if ($i <= floor($avg_prop_rating)): ?>
                                    <i class="fas fa-star"></i>
                                <?php elseif ($i == ceil($avg_prop_rating) && fmod($avg_prop_rating, 1) > 0): ?>
                                    <i class="fas fa-star-half-alt"></i>
                                <?php else: ?>
                                    <i class="far fa-star"></i>
                                <?php endif;
                            endfor; ?>
                        </div>
                        <div class="rating-count">Based on <?php echo $avg_properties_rating['total_reviews'] ?? 0; ?> reviews</div>
                    </div>
                </div>
                
                <div class="ratings-list">
                    <h4>All Reviews</h4>
                    
                    <?php if (count($ratings) > 0): ?>
                        <?php foreach ($ratings as $rating): ?>
                            <div class="rating-item">
                                <div class="rating-item-header">
                                    <div>
                                        <strong><?php echo htmlspecialchars($rating['reviewer_name']); ?></strong>
                                        <span class="rating-stars">
                                            <?php for ($i = 1; $i <= 5; $i++): ?>
                                                <i class="<?php echo $i <= $rating['rating'] ? 'fas' : 'far'; ?> fa-star"></i>
                                            <?php endfor; ?>
                                        </span>
                                    </div>
                                    <span class="rating-item-type">
                                        <?php echo $rating['target_type'] === 'landlord' ? 'Landlord Review' : 'Property Review'; ?>
                                    </span>
                                </div>
                                
                                <?php if ($rating['target_type'] === 'property' && !empty($rating['property_title'])): ?>
                                    <div class="rating-item-property">
                                        For property: <?php echo htmlspecialchars($rating['property_title']); ?>
                                    </div>
                                <?php endif; ?>
                                
                                <div class="rating-item-date">
                                    <?php echo date('F j, Y', strtotime($rating['created_at'])); ?>
                                </div>
                                
                                <?php if (!empty($rating['review'])): ?>
                                    <div class="rating-item-comment">
                                        <?php echo nl2br(htmlspecialchars($rating['review'])); ?>
                                    </div>
                                <?php endif; ?>
                            </div>
                        <?php endforeach; ?>
                    <?php else: ?>
                        <p>No ratings yet. You'll see reviews from tenants here once they rate you or your properties.</p>
                    <?php endif; ?>
                </div>
            </div>

            

    <script src="https://cdn.jsdelivr.net/npm/swiper@8/swiper-bundle.min.js"></script>
    <script>
    // Initialize all Swiper sliders
   document.addEventListener("DOMContentLoaded", function () {
    // ---- Swiper init ----
    const swipers = document.querySelectorAll(".swiper");
    swipers.forEach(swiperEl => {
        new Swiper(swiperEl, {
            loop: true,
            autoplay: {
                delay: 3000,
                disableOnInteraction: false
            },
            pagination: {
                el: swiperEl.querySelector(".swiper-pagination"),
                clickable: true
            },
            navigation: {
                nextEl: swiperEl.querySelector(".swiper-button-next"),
                prevEl: swiperEl.querySelector(".swiper-button-prev")
            }
        });
    });


        function openTab(tabName) {
            // Hide all tab content
            const tabContents = document.getElementsByClassName('tab-content');
            for (let i = 0; i < tabContents.length; i++) {
                tabContents[i].classList.remove('active');
            }
            
            // Remove active class from all buttons
            const tabButtons = document.getElementsByClassName('tab-button');
            for (let i = 0; i < tabButtons.length; i++) {
                tabButtons[i].classList.remove('active');
            }
            
            // Show the specific tab content
            document.getElementById(tabName).classList.add('active');
            
            // Add active class to the button that opened the tab
            event.currentTarget.classList.add('active');
        }

        document.addEventListener("DOMContentLoaded", function () {
    const chatBody = document.getElementById("chat-body");
    if (chatBody) {
        chatBody.scrollTop = chatBody.scrollHeight;
    }
});

    </script>
    <script>
document.addEventListener("DOMContentLoaded", function () {
    const chatForm = document.getElementById("chatForm");
    const chatBody = document.getElementById("chat-body");

    if (chatForm) {
        chatForm.addEventListener("submit", function (e) {
            e.preventDefault();

            const receiver_id = document.getElementById("receiver_id").value;
            const message_text = document.getElementById("message_text").value.trim();

            if (message_text === "") return;

            fetch("send_message.php", {
                method: "POST",
                headers: { "Content-Type": "application/json" },
                body: JSON.stringify({ receiver_id, message_text })
            })
            .then(res => res.json())
            .then(data => {
                if (data.status === "success") {
                    // Add message bubble instantly
                    const bubble = document.createElement("div");
                    bubble.classList.add("chat-bubble", "sent");
                    bubble.innerHTML = `<p>${message_text}</p><span class="chat-time">now</span>`;
                    chatBody.appendChild(bubble);

                    // Scroll to bottom
                    chatBody.scrollTop = chatBody.scrollHeight;

                    // Clear input
                    document.getElementById("message_text").value = "";
                } else {
                    alert("Message failed: " + data.message);
                }
            })
            .catch(err => console.error("Error sending:", err));
        });
    }
});

document.addEventListener("DOMContentLoaded", function () {
    const chatBody = document.getElementById("chat-body");
    const chatForm = document.getElementById("chatForm");
    const activeChatUser = <?php echo $active_chat_user; ?>;

    function fetchMessages() {
        if (!activeChatUser) return;
        fetch("fetch_messages.php?chat_with=" + activeChatUser)
            .then(res => res.json())
            .then(data => {
                if (!chatBody) return;
                chatBody.innerHTML = "";
                data.forEach(msg => {
                    const bubble = document.createElement("div");
                    bubble.classList.add("chat-bubble");
                    bubble.classList.add(msg.sender_id == <?php echo $landlord_id; ?> ? "sent" : "received");
                    bubble.innerHTML = `<p>${msg.message_text}</p>
                                        <span class="chat-time">${new Date(msg.created_at).toLocaleTimeString()}</span>`;
                    chatBody.appendChild(bubble);
                });
                chatBody.scrollTop = chatBody.scrollHeight;
            })
            .catch(err => console.error(err));
    }

    fetchMessages();
    setInterval(fetchMessages, 2000);

    if (chatForm) {
        chatForm.addEventListener("submit", function (e) {
            e.preventDefault();
            const receiver_id = document.getElementById("receiver_id").value;
            const message_text = document.getElementById("message_text").value.trim();
            if (message_text === "") return;

            fetch("send_message.php", {
                method: "POST",
                headers: { "Content-Type": "application/json" },
                body: JSON.stringify({ receiver_id, message_text })
            })
            .then(res => res.json())
            .then(data => {
                if (data.status === "success") {
                    document.getElementById("message_text").value = "";
                    fetchMessages(); // refresh immediately
                } else {
                    alert("Message failed: " + data.message);
                }
            });
        });
    }
});
</script>

</body>
</html>


