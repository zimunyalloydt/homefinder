<?php
include(__DIR__ . '/../config/db_connect.php');
session_start();

$currentUserId = $_SESSION['user_id'];

// find the landlord for the tenant’s last application
$sql = "SELECT p.landlord_id 
        FROM applications a
        JOIN properties p ON a.property_id = p.property_id
        WHERE a.tenant_id=? 
        ORDER BY a.date_applied DESC 
        LIMIT 1";

$stmt = $conn->prepare($sql);
$stmt->bind_param("i", $currentUserId);
$stmt->execute();
$result = $stmt->get_result();
$row = $result->fetch_assoc();

$otherUserId = $row['landlord_id'] ?? 0;




$active_chat_user = isset($_GET['chat_with']) ? intval($_GET['chat_with']) : 0;

// then fetch messages if a chat is open
$chat_messages = null;
if ($active_chat_user > 0) {
    $stmt = $conn->prepare("
        SELECT * FROM messages 
        WHERE (sender_id = ? AND receiver_id = ?) 
           OR (sender_id = ? AND receiver_id = ?)
        ORDER BY created_at ASC
    ");
    $stmt->bind_param("iiii", $tenant_id, $active_chat_user, $active_chat_user, $tenant_id);
    $stmt->execute();
    $chat_messages = $stmt->get_result();
}
// Redirect if not tenant
if (!isset($_SESSION['user_id']) || $_SESSION['role'] !== 'tenant') {
    header("Location: login.php");
    exit();
}

$tenant_id = $_SESSION['user_id'];
$message = "";

// Fetch tenant's current information
$tenant_info_stmt = $conn->prepare("
    SELECT u.*, t.profession, t.family_size, t.additional_info 
    FROM users u 
    LEFT JOIN tenants t ON u.user_id = t.user_id 
    WHERE u.user_id = ?
");
$tenant_info_stmt->bind_param("i", $tenant_id);
$tenant_info_stmt->execute();
$tenant_info = $tenant_info_stmt->get_result()->fetch_assoc();

// Fetch tenant's full name
$stmt = $conn->prepare("SELECT full_name FROM users WHERE user_id = ?");
$stmt->bind_param("i", $tenant_id);
$stmt->execute();
$result = $stmt->get_result();

if ($row = $result->fetch_assoc()) {
    $full_name = $row['full_name'];
} else {
    $full_name = "Tenant"; // fallback
}

// Handle profile update
if (isset($_POST['update_profile'])) {
    $full_name = trim($_POST['full_name']);
    $email = trim($_POST['email']);
    $phone = trim($_POST['phone']);
    $profession = trim($_POST['profession']);
    $family_size = intval($_POST['family_size']);
    $additional_info = trim($_POST['additional_info']);
    
    // Update users table
    $update_user_stmt = $conn->prepare("UPDATE users SET full_name=?, email=?, phone=? WHERE user_id=?");
    $update_user_stmt->bind_param("sssi", $full_name, $email, $phone, $tenant_id);
    
    // Update tenants table
    $update_tenant_stmt = $conn->prepare("UPDATE tenants SET profession=?, family_size=?, additional_info=? WHERE user_id=?");
    $update_tenant_stmt->bind_param("sisi", $profession, $family_size, $additional_info, $tenant_id);
    
    if ($update_user_stmt->execute() && $update_tenant_stmt->execute()) {
        $message = "✅ Profile updated successfully!";
        // Refresh tenant info
        $tenant_info_stmt->execute();
        $tenant_info = $tenant_info_stmt->get_result()->fetch_assoc();
        $_SESSION['full_name'] = $full_name; // Update session name
    } else {
        $message = "❌ Error updating profile: " . $conn->error;
    }
}

// Handle property application
if (isset($_POST['apply_property_id'])) {
    $property_id = intval($_POST['apply_property_id']);

    // Check if already applied
    $check = $conn->prepare("SELECT * FROM applications WHERE tenant_id=? AND property_id=?");
    $check->bind_param("ii", $tenant_id, $property_id);
    $check->execute();
    $result = $check->get_result();

    if ($result->num_rows > 0) {
        $message = "❌ You have already applied for this property.";
    } else {
        $stmt = $conn->prepare("INSERT INTO applications (tenant_id, property_id, status, date_applied) VALUES (?, ?, 'Pending', NOW())");
        $stmt->bind_param("ii", $tenant_id, $property_id);
        if ($stmt->execute()) {
            $message = "✅ Application submitted successfully!";
        } else {
        }
    }
}

// Handle favorite/unfavorite
if (isset($_POST['favorite_action'])) {
    $property_id = intval($_POST['property_id']);
    
    if ($_POST['favorite_action'] === 'add') {
        $stmt = $conn->prepare("INSERT INTO favorites (tenant_id, property_id) VALUES (?, ?)");
        $stmt->bind_param("ii", $tenant_id, $property_id);
        $stmt->execute();
    } elseif ($_POST['favorite_action'] === 'remove') {
        $stmt = $conn->prepare("DELETE FROM favorites WHERE tenant_id = ? AND property_id = ?");
        $stmt->bind_param("ii", $tenant_id, $property_id);
        $stmt->execute();
    }
}

// Check for notifications
$notif_stmt = $conn->prepare("SELECT property_id, status FROM applications WHERE tenant_id = ? AND status != 'Pending' AND notified = 0");
$notif_stmt->bind_param("i", $tenant_id);
$notif_stmt->execute();
$notifications = $notif_stmt->get_result();

// Mark them as notified
$update_notif = $conn->prepare("UPDATE applications SET notified = 1 WHERE tenant_id = ? AND status != 'Pending' AND notified = 0");
$update_notif->bind_param("i", $tenant_id);
$update_notif->execute();
if ($notifications->num_rows > 0) {
    while ($notif = $notifications->fetch_assoc()) {
        $message .= "✅ Your application for property ID " . htmlspecialchars($notif['property_id']) . " has been " . htmlspecialchars($notif['status']) . ".<br>";
    }
}

// Handle search and filters
$search = isset($_GET['search']) ? trim($_GET['search']) : '';
$type_filter = isset($_GET['type']) ? $_GET['type'] : '';
$min_price = isset($_GET['min_price']) ? intval($_GET['min_price']) : 0;
$max_price = isset($_GET['max_price']) ? intval($_GET['max_price']) : 1000000;
$bedrooms = isset($_GET['bedrooms']) ? intval($_GET['bedrooms']) : 0;

// Build the properties query with filters
$properties_query = "
    SELECT p.*, 
           u.full_name AS landlord_name, 
           u.user_id AS landlord_id,
           (SELECT COUNT(*) FROM favorites f WHERE f.tenant_id = ? AND f.property_id = p.property_id) AS is_favorite,
           (SELECT GROUP_CONCAT(pi.image_path) FROM property_images pi WHERE pi.property_id = p.property_id) AS all_images
    FROM properties p
    JOIN users u ON p.landlord_id = u.user_id
    WHERE p.status = 'Available'
";

$params = array($tenant_id);
$param_types = "i";


// Add search condition
if (!empty($search)) {
    $properties_query .= " AND (p.title LIKE ? OR p.location LIKE ? OR p.description LIKE ?)";
    $search_term = "%$search%";
    array_push($params, $search_term, $search_term, $search_term);
    $param_types .= "sss";
}

// Add type filter
if (!empty($type_filter) && $type_filter !== 'all') {
    $properties_query .= " AND p.type = ?";
    array_push($params, $type_filter);
    $param_types .= "s";
}

// Add price filter
$properties_query .= " AND p.price BETWEEN ? AND ?";
array_push($params, $min_price, $max_price);
$param_types .= "ii";

// Add bedrooms filter
if ($bedrooms > 0) {
    $properties_query .= " AND p.bedrooms >= ?";
    array_push($params, $bedrooms);
    $param_types .= "i";
}

// Prepare and execute the properties query
$properties_stmt = $conn->prepare($properties_query);
$properties_stmt->bind_param($param_types, ...$params);
$properties_stmt->execute();
$properties_result = $properties_stmt->get_result();

// Fetch tenant applications
$apps_stmt = $conn->prepare("
    SELECT a.*, 
           p.title AS property_title, 
           u.full_name AS landlord_name, 
           u.email AS landlord_email, 
           u.phone AS landlord_phone
    FROM applications a
    JOIN properties p ON a.property_id = p.property_id
    JOIN users u ON p.landlord_id = u.user_id
    WHERE a.tenant_id=? 
    ORDER BY a.date_applied DESC
");
$apps_stmt->bind_param("i", $tenant_id);
$apps_stmt->execute();
$applications = $apps_stmt->get_result();

// Fetch favorite properties
$favorites_stmt = $conn->prepare("
    SELECT p.*, u.full_name AS landlord_name, u.user_id AS landlord_id,
           (SELECT GROUP_CONCAT(pi.image_path) FROM property_images pi WHERE pi.property_id = p.property_id) AS all_images
    FROM favorites f 
    JOIN properties p ON f.property_id = p.property_id
    JOIN users u ON p.landlord_id = u.user_id
    WHERE f.tenant_id = ?
");
$favorites_stmt->bind_param("i", $tenant_id);
$favorites_stmt->execute();
$favorites = $favorites_stmt->get_result();

// Fetch property ratings
$property_ratings_stmt = $conn->prepare("
    SELECT pr.*, p.title AS property_title 
    FROM property_ratings pr 
    JOIN properties p ON pr.property_id = p.property_id 
    WHERE pr.tenant_id = ?
    ORDER BY pr.created_at DESC
");
$property_ratings_stmt->bind_param("i", $tenant_id);
$property_ratings_stmt->execute();
$property_ratings = $property_ratings_stmt->get_result();

// Fetch landlord ratings
$landlord_ratings_stmt = $conn->prepare("
    SELECT lr.*, u.full_name AS landlord_name 
    FROM landlord_ratings lr 
    JOIN users u ON lr.landlord_id = u.user_id 
    WHERE lr.tenant_id = ?
    ORDER BY lr.created_at DESC
");
$landlord_ratings_stmt->bind_param("i", $tenant_id);
$landlord_ratings_stmt->execute();
$landlord_ratings = $landlord_ratings_stmt->get_result();


// Handle send message
if (isset($_POST['send_message'])) {
    $receiver_id = intval($_POST['receiver_id']);
    $msg_text = trim($_POST['message_text']);
    if (!empty($msg_text)) {
        $stmt = $conn->prepare("INSERT INTO messages (sender_id, receiver_id, message_text, created_at) VALUES (?, ?, ?, NOW())");
        $stmt->bind_param("iis", $tenant_id, $receiver_id, $msg_text);
        $stmt->execute();
    }
}
// Fetch messages between tenant and landlords they applied to
$messages_stmt = $conn->prepare("
    SELECT m.*, u.full_name AS sender_name 
    FROM messages m
    JOIN users u ON m.sender_id = u.user_id
    WHERE (m.sender_id = ? OR m.receiver_id = ?)
    ORDER BY m.created_at DESC
");
$messages_stmt->bind_param("ii", $tenant_id, $tenant_id);
$messages_stmt->execute();
$messages = $messages_stmt->get_result();

// Fetch all landlords tenant has conversations with
$conv_stmt = $conn->prepare("
    SELECT DISTINCT 
        CASE 
            WHEN m.sender_id = ? THEN m.receiver_id
            ELSE m.sender_id
        END AS other_user_id,
        u.full_name
    FROM messages m
    JOIN users u ON u.user_id = 
        CASE 
            WHEN m.sender_id = ? THEN m.receiver_id
            ELSE m.sender_id
        END
    WHERE m.sender_id = ? OR m.receiver_id = ?
");
$conv_stmt->bind_param("iiii", $tenant_id, $tenant_id, $tenant_id, $tenant_id);
$conv_stmt->execute();
$conversations = $conv_stmt->get_result();


// Messages tab functionality
$active_chat_user = isset($_GET['chat_with']) ? intval($_GET['chat_with']) : 0;
$chat_messages = null;
$chatPartnerName = "";

if ($active_chat_user > 0) {
    // Fetch messages if a chat is open
    $stmt = $conn->prepare("
        SELECT m.*, u.full_name AS sender_name 
        FROM messages m
        JOIN users u ON m.sender_id = u.user_id
        WHERE (m.sender_id = ? AND m.receiver_id = ?) 
           OR (m.sender_id = ? AND m.receiver_id = ?)
        ORDER BY m.created_at ASC
    ");
    $stmt->bind_param("iiii", $tenant_id, $active_chat_user, $active_chat_user, $tenant_id);
    $stmt->execute();
    $chat_messages = $stmt->get_result();
    
    // Get chat partner name
    $stmt = $conn->prepare("SELECT full_name FROM users WHERE user_id = ?");
    $stmt->bind_param("i", $active_chat_user);
    $stmt->execute();
    $result = $stmt->get_result();
    if ($row = $result->fetch_assoc()) {
        $chatPartnerName = $row['full_name'];
    }
    
    // Mark messages as read
    $updateStmt = $conn->prepare("UPDATE messages SET is_read=1 WHERE sender_id=? AND receiver_id=? AND is_read=0");
    $updateStmt->bind_param("ii", $active_chat_user, $tenant_id);
    $updateStmt->execute();
}

// Handle send message
if (isset($_POST['send_message']) && $active_chat_user > 0) {
    $msg_text = trim($_POST['message_text']);
    if (!empty($msg_text)) {
        $stmt = $conn->prepare("INSERT INTO messages (sender_id, receiver_id, message_text, created_at) VALUES (?, ?, ?, NOW())");
        $stmt->bind_param("iis", $tenant_id, $active_chat_user, $msg_text);
        $stmt->execute();
        
        // Refresh page to show new message
        header("Location: tenant_dashboard.php?tab=messages&chat_with=" . $active_chat_user);
        exit();
    }
}

// Fetch all landlords tenant has conversations with
$conv_stmt = $conn->prepare("
    SELECT DISTINCT 
        CASE 
            WHEN m.sender_id = ? THEN m.receiver_id
            ELSE m.sender_id
        END AS other_user_id,
        u.full_name,
        (SELECT message_text FROM messages 
         WHERE (sender_id = u.user_id AND receiver_id = ?) OR (sender_id = ? AND receiver_id = u.user_id) 
         ORDER BY created_at DESC LIMIT 1) AS last_message,
        (SELECT created_at FROM messages 
         WHERE (sender_id = u.user_id AND receiver_id = ?) OR (sender_id = ? AND receiver_id = u.user_id) 
         ORDER BY created_at DESC LIMIT 1) AS last_time,
        (SELECT COUNT(*) FROM messages 
         WHERE sender_id = u.user_id AND receiver_id = ? AND is_read = 0) AS unread_count
    FROM messages m
    JOIN users u ON u.user_id = 
        CASE 
            WHEN m.sender_id = ? THEN m.receiver_id
            ELSE m.sender_id
        END
    WHERE m.sender_id = ? OR m.receiver_id = ?
    ORDER BY last_time DESC
");
$conv_stmt->bind_param("iiiiiiiii", $tenant_id, $tenant_id, $tenant_id, $tenant_id, $tenant_id, $tenant_id, $tenant_id, $tenant_id, $tenant_id);
$conv_stmt->execute();
$conversations = $conv_stmt->get_result();

?>
<!DOCTYPE html>
<html>
<head>
    <title>Tenant Dashboard - HomeFinder</title>
    <link rel="stylesheet" href="/../css/tenantdashboard.css">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.0.0/css/all.min.css">
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/swiper@8/swiper-bundle.min.css">
    
</head>
<body>
<nav>
     
 <h2>Welcome, <?php echo htmlspecialchars($full_name ?? "Tenant"); ?></h2>

    <div><a href="logout.php">Logout</a></div>
</nav>
 <div class="container">
    <?php if (!empty($message)): ?>
        <div class="alert <?php echo strpos($message, '✅') !== false ? 'success' : 'error'; ?>"><?php echo $message; ?></div>
    <?php endif; ?>
    
    <div class="tab-container">
        <div class="tab-buttons">
            <button class="tab-button active" data-tab="available">Available Properties</button>
            <button class="tab-button" data-tab="favorites">❤️ Favorites</button>
            <button class="tab-button" data-tab="applications">📄 My Applications</button>
            <button class="tab-button" data-tab="ratings">⭐ My Ratings</button>
            <button class="tab-button" data-tab="messages">💬 Messages</button>
            <button class="tab-button" data-tab="profile">⚙ Edit Profile</button>
            <?php if ($otherUserId > 0): ?>
                <a href="messages.php?chat_with=<?php echo $otherUserId; ?>" class="btn-primary">💬 Messages</a>
            <?php endif; ?>
        </div>
        
        <!-- Available Properties Tab -->
        <div id="available" class="tab-content active">
            <h2>Available Properties</h2>
            
            <!-- Search and Filter Section -->
            <div class="search-filter-container">
                <form method="GET" action="">
                    <input type="hidden" name="tab" value="available">
                    <div class="search-box">
                        <input type="text" name="search" placeholder="Search by title, location, or description..." value="<?php echo htmlspecialchars($search); ?>">
                        <button type="submit">Search</button>
                    </div>
                    
                    <div class="filter-options">
                        <div class="filter-group">
                            <label for="type">Property Type</label>
                            <select name="type" id="type">
                                <option value="all" <?php echo $type_filter === 'all' ? 'selected' : ''; ?>>All Types</option>
                                <option value="House" <?php echo $type_filter === 'House' ? 'selected' : ''; ?>>House</option>
                                <option value="Apartment" <?php echo $type_filter === 'Apartment' ? 'selected' : ''; ?>>Apartment</option>
                                <option value="Condo" <?php echo $type_filter === 'Condo' ? 'selected' : ''; ?>>Condo</option>
                                <option value="Townhouse" <?php echo $type_filter === 'Townhouse' ? 'selected' : ''; ?>>Townhouse</option>
                            </select>
                        </div>
                        
                        <div class="filter-group">
                            <label for="min_price">Min Price ($)</label>
                            <input type="number" name="min_price" id="min_price" min="0" value="<?php echo $min_price; ?>">
                        </div>
                        
                        <div class="filter-group">
                            <label for="max_price">Max Price ($)</label>
                            <input type="number" name="max_price" id="max_price" min="0" value="<?php echo $max_price; ?>">
                        </div>
                        
                        <div class="filter-group">
                            <label for="bedrooms">Min Bedrooms</label>
                            <select name="bedrooms" id="bedrooms">
                                <option value="0" <?php echo $bedrooms == 0 ? 'selected' : ''; ?>>Any</option>
                                <option value="1" <?php echo $bedrooms == 1 ? 'selected' : ''; ?>>1+</option>
                                <option value="2" <?php echo $bedrooms == 2 ? 'selected' : ''; ?>>2+</option>
                                <option value="3" <?php echo $bedrooms == 3 ? 'selected' : ''; ?>>3+</option>
                                <option value="4" <?php echo $bedrooms == 4 ? 'selected' : ''; ?>>4+</option>
                            </select>
                        </div>
                    </div>
                    
                    <div class="filter-buttons">
                        <button type="submit" class="btn-apply">Apply Filters</button>
                        <button type="button" class="btn-reset" onclick="resetFilters()">Reset</button>
                    </div>
                </form>
            </div>
            
            <div class="grid-3">
                <?php if ($properties_result->num_rows > 0): ?>
                    <?php while($prop = $properties_result->fetch_assoc()): 
                        $images = !empty($prop['all_images']) ? explode(',', $prop['all_images']) : [];
                    ?>
                    <div class="property-card">
                        <form method="POST" class="favorite-form">
                            <input type="hidden" name="property_id" value="<?php echo $prop['property_id']; ?>">
                            <input type="hidden" name="favorite_action" value="<?php echo $prop['is_favorite'] ? 'remove' : 'add'; ?>">
                            <button type="submit" class="favorite-btn" title="<?php echo $prop['is_favorite'] ? 'Remove from favorites' : 'Add to favorites'; ?>">
                                <i class="<?php echo $prop['is_favorite'] ? 'fas' : 'far'; ?> fa-star"></i>
                            </button>
                        </form>
                        
                        <!-- Image Slider -->
                        <?php if (!empty($images)): ?>
                            <div class="swiper swiper-<?php echo $prop['property_id']; ?>">
                                <div class="swiper-wrapper">
                                    <?php foreach ($images as $image): ?>
                                        <div class="swiper-slide">
                                            <img src="<?php echo htmlspecialchars($image); ?>" alt="Property Image">
                                        </div>
                                    <?php endforeach; ?>
                                </div>
                                <div class="swiper-pagination"></div>
                                <div class="swiper-button-prev"></div>
                                <div class="swiper-button-next"></div>
                            </div>
                        <?php else: ?>
                            <div class="property-image-placeholder">No Image Available</div>
                        <?php endif; ?>
                        
                        <h3><?php echo htmlspecialchars($prop['title'] ?? 'Untitled Property'); ?></h3>
                        <p><strong>Type:</strong> <?php echo htmlspecialchars($prop['type'] ?? 'N/A'); ?></p>
                        <p><strong>Location:</strong> <?php echo htmlspecialchars($prop['location'] ?? 'Location not specified'); ?></p>
                        <p><strong>Rent:</strong> $<?php echo number_format((float)($prop['price'] ?? 0), 2); ?></p>
                        <p><strong>Bedrooms:</strong> <?php echo htmlspecialchars($prop['bedrooms'] ?? 'N/A'); ?></p>
                        <p><strong>Bathrooms:</strong> <?php echo htmlspecialchars($prop['bathrooms'] ?? 'N/A'); ?></p>
                        <p><strong>Landlord:</strong> <?php echo htmlspecialchars($prop['landlord_name'] ?? 'Unknown'); ?></p>
                        
                        <form method="POST" style="margin-bottom:5px;">
                            <input type="hidden" name="apply_property_id" value="<?php echo htmlspecialchars($prop['property_id']); ?>">
                            <button type="submit" class="btn-primary">Apply</button>
                        </form>
                        
                        <!-- Chat button to start conversation with landlord -->
                        <button class="btn-primary" onclick="startChatWithLandlord(
                            '<?php echo htmlspecialchars($prop['landlord_id']); ?>', 
                            '<?php echo htmlspecialchars($prop['landlord_name']); ?>',
                            '<?php echo htmlspecialchars($prop['property_id']); ?>',
                            '<?php echo htmlspecialchars($prop['title']); ?>'
                        )" style="margin: 0 1rem 1rem;">
                            Chat with Landlord
                        </button>
                        
                        <div style="display: flex; gap: 10px;">
                            <a href="rate_property.php?property_id=<?php echo htmlspecialchars($prop['property_id']); ?>" style="font-size: 14px;">Rate Property</a>
                            <a href="rate_landlord.php?landlord_id=<?php echo htmlspecialchars($prop['landlord_id']); ?>&property_id=<?php echo htmlspecialchars($prop['property_id']); ?>" style="font-size: 14px;">Rate Landlord</a>
                        </div>
                    </div>
                    <?php endwhile; ?>
                <?php else: ?>
                    <div class="no-results">
                        <h3>No properties found</h3>
                        <p>Try adjusting your search criteria or filters.</p>
                    </div>
                <?php endif; ?>
            </div>
        </div>
        
        <!-- Favorites Tab -->
        <div id="favorites" class="tab-content">
            <h2>Favorite Properties</h2>
            <?php if ($favorites->num_rows > 0): ?>
                <div class="grid-3">
                    <?php while($fav = $favorites->fetch_assoc()): 
                        $images = !empty($fav['all_images']) ? explode(',', $fav['all_images']) : [];
                    ?>
                    <div class="property-card">
                        <form method="POST" class="favorite-form">
                            <input type="hidden" name="property_id" value="<?php echo $fav['property_id']; ?>">
                            <input type="hidden" name="favorite_action" value="remove">
                            <button type="submit" class="favorite-btn" title="Remove from favorites">
                                <i class="fas fa-star"></i>
                            </button>
                        </form>
                        
                        <!-- Image Slider -->
                        <?php if (!empty($images)): ?>
                            <div class="swiper swiper-<?php echo $fav['property_id']; ?>">
                                <div class="swiper-wrapper">
                                    <?php foreach ($images as $image): ?>
                                        <div class="swiper-slide">
                                            <img src="<?php echo htmlspecialchars($image); ?>" alt="Property Image">
                                        </div>
                                    <?php endforeach; ?>
                                </div>
                                <div class="swiper-pagination"></div>
                                <div class="swiper-button-prev"></div>
                                <div class="swiper-button-next"></div>
                            </div>
                        <?php else: ?>
                            <div class="property-image-placeholder">No Image Available</div>
                        <?php endif; ?>
                        
                        <h3><?php echo htmlspecialchars($fav['title'] ?? 'Untitled Property'); ?></h3>
                        <p>Location: <?php echo htmlspecialchars($fav['location'] ?? 'Location not specified'); ?></p>
                        <p>Rent: $<?php echo number_format((float)($fav['price'] ?? 0), 2); ?></p>
                        <p>Landlord: <?php echo htmlspecialchars($fav['landlord_name'] ?? 'Unknown'); ?></p>
                        
                        <form method="POST" style="margin-bottom:5px;">
                            <input type="hidden" name="apply_property_id" value="<?php echo htmlspecialchars($fav['property_id']); ?>">
                            <button type="submit" class="btn-primary">Apply</button>
                        </form>
                        
                        <div style="display: flex; gap: 10px;">
                            <a href="rate_property.php?property_id=<?php echo htmlspecialchars($fav['property_id']); ?>" style="font-size: 14px;">Rate Property</a>
                            <a href="rate_landlord.php?landlord_id=<?php echo htmlspecialchars($fav['landlord_id']); ?>&property_id=<?php echo htmlspecialchars($fav['property_id']); ?>" style="font-size: 14px;">Rate Landlord</a>
                        </div>
                    </div>
                    <?php endwhile; ?>
                </div>
            <?php else: ?>
                <p class="no-favorites">You haven't favorited any properties yet.</p>
            <?php endif; ?>
        </div>
        
        <!-- Applications Tab -->
        <div id="applications" class="tab-content">
            <h2>Your Applications</h2>
            <?php if ($applications->num_rows > 0): ?>
            <table>
                <tr>
                    <th>Property</th>
                    <th>Status</th>
                    <th>Applied On</th>
                    <th>Landlord Contact</th>
                </tr>
                <?php while($app = $applications->fetch_assoc()): ?>
                <tr>
                    <td><?php echo htmlspecialchars($app['property_title'] ?? 'Unknown Property'); ?></td>
                    <td><?php echo htmlspecialchars($app['status'] ?? 'pending'); ?></td>
                    <td><?php echo htmlspecialchars($app['date_applied'] ?? 'Date not available'); ?></td>
                    <td>
                        <?php if ($app['status'] === 'approved'): ?>
                            <strong><?php echo htmlspecialchars($app['landlord_name']); ?></strong><br>
                            📧 <?php echo htmlspecialchars($app['landlord_email']); ?><br>
                            📞 <?php echo htmlspecialchars($app['landlord_phone']); ?>
                        <?php else: ?>
                            <em>Visible after approval</em>
                        <?php endif; ?>
                    </td>
                </tr>
                <?php endwhile; ?>
            </table>
            <?php else: ?>
                <p class="no-applications">You haven't applied to any properties yet.</p>
            <?php endif; ?>
        </div>
        
        <!-- Ratings Tab -->
        <div id="ratings" class="tab-content">
            <h2>My Ratings</h2>
            
            <div class="ratings-container">
                <div class="ratings-section">
                    <h3>Property Ratings</h3>
                    <?php if ($property_ratings->num_rows > 0): ?>
                        <div class="ratings-grid">
                            <?php while($rating = $property_ratings->fetch_assoc()): ?>
                                <div class="rating-card">
                                    <h4><?php echo htmlspecialchars($rating['property_title']); ?></h4>
                                    <div class="rating-stars">
                                        <?php for ($i = 1; $i <= 5; $i++): ?>
                                            <i class="fas fa-star <?php echo $i <= $rating['stars'] ? 'active' : ''; ?>"></i>
                                        <?php endfor; ?>
                                    </div>
                                    <?php if (!empty($rating['review'])): ?>
                                        <p class="rating-comment"><?php echo htmlspecialchars($rating['review']); ?></p>
                                    <?php endif; ?>
                                    <p class="rating-date">Rated on: <?php echo date('M j, Y', strtotime($rating['created_at'])); ?></p>
                                </div>
                            <?php endwhile; ?>
                        </div>
                    <?php else: ?>
                        <p class="no-ratings">You haven't rated any properties yet.</p>
                    <?php endif; ?>
                </div>
                
                <div class="ratings-section">
                    <h3>Landlord Ratings</h3>
                    <?php if ($landlord_ratings->num_rows > 0): ?>
                        <div class="ratings-grid">
                            <?php while($rating = $landlord_ratings->fetch_assoc()): ?>
                                <div class="rating-card">
                                    <h4>Landlord: <?php echo htmlspecialchars($rating['landlord_name']); ?></h4>
                                    <div class="rating-stars">
                                        <?php for ($i = 1; $i <= 5; $i++): ?>
                                            <i class="fas fa-star <?php echo $i <= $rating['rating'] ? 'active' : ''; ?>"></i>
                                        <?php endfor; ?>
                                    </div>
                                    <?php if (!empty($rating['review'])): ?>
                                        <p class="rating-comment"><?php echo htmlspecialchars($rating['review']); ?></p>
                                    <?php endif; ?>
                                    <p class="rating-date">Rated on: <?php echo date('M j, Y', strtotime($rating['created_at'])); ?></p>
                                </div>
                            <?php endwhile; ?>
                        </div>
                    <?php else: ?>
                        <p class="no-ratings">You haven't rated any landlords yet.</p>
                    <?php endif; ?>
                </div>
            </div>
        </div>
        
        <!-- Profile Edit Tab -->
        <div id="profile" class="tab-content">
            <h2>Edit Your Profile</h2>
            <form method="POST" class="profile-form">
                <input type="hidden" name="update_profile" value="1">
                
                <div class="form-group">
                    <label for="full_name">Full Name:</label>
                    <input type="text" id="full_name" name="full_name" value="<?php echo htmlspecialchars($tenant_info['full_name'] ?? ''); ?>" required>
                </div>
                
                <div class="form-group">
                    <label for="email">Email:</label>
                    <input type="email" id="email" name="email" value="<?php echo htmlspecialchars($tenant_info['email'] ?? ''); ?>" required>
                </div>
                
                <div class="form-group">
                    <label for="phone">Phone:</label>
                    <input type="text" id="phone" name="phone" value="<?php echo htmlspecialchars($tenant_info['phone'] ?? ''); ?>">
                </div>
                
                <div class="form-group">
                    <label for="profession">Profession:</label>
                    <input type="text" id="profession" name="profession" value="<?php echo htmlspecialchars($tenant_info['profession'] ?? ''); ?>">
                </div>
                
                <div class="form-group">
                    <label for="family_size">Family Size:</label>
                    <input type="number" id="family_size" name="family_size" min="1" value="<?php echo htmlspecialchars($tenant_info['family_size'] ?? 1); ?>">
                </div>
                
                <div class="form-group">
                    <label for="additional_info">Additional Information:</label>
                    <textarea id="additional_info" name="additional_info" placeholder="Tell landlords more about yourself, your rental preferences, etc."><?php echo htmlspecialchars($tenant_info['additional_info'] ?? ''); ?></textarea>
                </div>
                
                <button type="submit" class="btn-primary">Update Profile</button>
            </form>
        </div>
 <div id="messages" class="tab-content">
            <h2>Messages</h2>
            
            <div class="messages-container">
                <div class="messages-sidebar">
                    <div class="messages-sidebar-header">
                        <h3>Conversations</h3>
                    </div>
                    
                    <div class="messages-search">
                        <input type="text" placeholder="Search conversations..." onkeyup="filterConversations()">
                    </div>
                    
                    <div class="conversations-list">
                        <?php if ($conversations->num_rows > 0): ?>
                            <?php while($conv = $conversations->fetch_assoc()): 
                                $initials = strtoupper(substr($conv['full_name'], 0, 2));
                                $lastTime = !empty($conv['last_time']) ? date("M j, g:i A", strtotime($conv['last_time'])) : 'No messages';
                            ?>
                            <div class="conversation-item <?php echo $active_chat_user == $conv['other_user_id'] ? 'active' : ''; ?>" 
                                 onclick="window.location.href='tenant_dashboard.php?tab=messages&chat_with=<?php echo $conv['other_user_id']; ?>'">
                                <div class="conversation-avatar"><?php echo $initials; ?></div>
                                <div class="conversation-info">
                                    <div class="conversation-name"><?php echo htmlspecialchars($conv['full_name']); ?></div>
                                    <div class="conversation-preview"><?php echo htmlspecialchars($conv['last_message'] ?? 'No messages yet'); ?></div>
                                </div>
                                <div class="conversation-meta">
                                    <div class="conversation-time"><?php echo $lastTime; ?></div>
                                    <?php if ($conv['unread_count'] > 0): ?>
                                        <div class="unread-count"><?php echo $conv['unread_count']; ?></div>
                                    <?php endif; ?>
                                </div>
                            </div>
                            <?php endwhile; ?>
                        <?php else: ?>
                            <div class="empty-chat">
                                <p>No conversations yet</p>
                            </div>
                        <?php endif; ?>
                    </div>
                </div>
                
                <div class="messages-content">
                    <?php if ($active_chat_user > 0 && !empty($chatPartnerName)): ?>
                        <div class="messages-header">
                            <div class="conversation-avatar"><?php echo strtoupper(substr($chatPartnerName, 0, 2)); ?></div>
                            <div class="conversation-name"><?php echo htmlspecialchars($chatPartnerName); ?></div>
                        </div>
                        
                        <div class="messages-body" id="messages-body">
                            <?php if ($chat_messages && $chat_messages->num_rows > 0): ?>
                                <?php while($msg = $chat_messages->fetch_assoc()): 
                                    $isSent = $msg['sender_id'] == $tenant_id;
                                    $time = date("g:i A", strtotime($msg['created_at']));
                                ?>
                                <div class="message <?php echo $isSent ? 'sent' : 'received'; ?>">
                                    <div class="message-content">
                                        <p><?php echo htmlspecialchars($msg['message_text']); ?></p>
                                        <div class="message-time"><?php echo $time; ?></div>
                                    </div>
                                </div>
                                <?php endwhile; ?>
                            <?php else: ?>
                                <div class="empty-chat">
                                    <p>No messages yet. Start the conversation!</p>
                                </div>
                            <?php endif; ?>
                        </div>
                        
                        <form method="POST" class="messages-input">
                            <input type="text" name="message_text" placeholder="Type your message..." required>
                            <button type="submit" name="send_message"><i class="fas fa-paper-plane"></i></button>
                        </form>
                    <?php else: ?>
                        <div class="empty-chat">
                            <p>Select a conversation to start chatting</p>
                        </div>
                    <?php endif; ?>
                </div>
            </div>
        </div>



    </div>
</div>

<script src="https://cdn.jsdelivr.net/npm/swiper@8/swiper-bundle.min.js"></script>
<script>
// Tab functionality
document.addEventListener('DOMContentLoaded', function() {
    // Set up tab switching
    const tabButtons = document.querySelectorAll('.tab-button');
    const tabContents = document.querySelectorAll('.tab-content');
    
    // Function to switch tabs
    function switchTab(tabName) {
        // Hide all tab contents
        tabContents.forEach(content => {
            content.classList.remove('active');
        });

        
        
       


        // Show the selected tab content
        document.getElementById(tabName).classList.add('active');
        
        // Update active tab button
        tabButtons.forEach(button => {
            button.classList.remove('active');
            if (button.dataset.tab === tabName) {
                button.classList.add('active');
            }
        });
        
        // Initialize swipers for the active tab
        if (tabName === 'available' || tabName === 'favorites') {
            setTimeout(initializeSwipers, 100);
        }
    }
    
    // Add click event to tab buttons
    tabButtons.forEach(button => {
        button.addEventListener('click', function() {
            const tabName = this.dataset.tab;
            switchTab(tabName);
            
            // Update URL with tab parameter
            const url = new URL(window.location);
            url.searchParams.set('tab', tabName);
            window.history.replaceState({}, '', url);
        });
    });
    
    // Check if there's a tab parameter in the URL
    const urlParams = new URLSearchParams(window.location.search);
    const requestedTab = urlParams.get('tab');
    if (requestedTab && document.getElementById(requestedTab)) {
        switchTab(requestedTab);
    } else {
        // Default to first tab
        switchTab('available');
    }
    
    // Initialize swipers on page load for the active tab
    if (document.querySelector('.tab-content.active').id === 'available' || 
        document.querySelector('.tab-content.active').id === 'favorites') {
        initializeSwipers();
    }
});

// Initialize all Swiper sliders
function initializeSwipers() {
    document.querySelectorAll('.swiper').forEach(swiperEl => {
        // Check if this swiper is already initialized
        if (swiperEl.swiper) {
            swiperEl.swiper.destroy(true, true);
        }
        
        new Swiper(swiperEl, {
            loop: true,
            pagination: {
                el: '.swiper-pagination',
                clickable: true,
            },
            navigation: {
                nextEl: '.swiper-button-next',
                prevEl: '.swiper-button-prev',
            },
        });
    });
}

// Reset filters function
function resetFilters() {
    window.location.href = window.location.pathname + '?tab=available';
}

// Start chat with landlord function
function startChatWithLandlord(landlordId, landlordName, propertyId, propertyTitle) {
    alert(`Ready to chat with ${landlordName} about "${propertyTitle}". In a real implementation, this would open the chat interface.`);
    // In a real implementation, you would switch to the messages tab and start a conversation
}
</script>
</body>
</html>