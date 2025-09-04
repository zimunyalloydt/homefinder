<?php
include(__DIR__ . '/../config/db_connect.php');
session_start();

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
     <h2>HomeFinder - <?php echo htmlspecialchars($_SESSION['full_name']); ?></h2>
    <div><a href="logout.php">Logout</a></div>
</nav>
<div class="container">
    <?php if (!empty($message)): ?>
        <div class="alert"><?php echo htmlspecialchars($message); ?></div>
    <?php endif; ?>
    
    <div class="tab-container">
       <div class="tab" data-tab="available" onclick="switchTab('available')">Available Properties</div>
<div class="tab" data-tab="favorites" onclick="switchTab('favorites')">❤️ Favorites</div>
<div class="tab" data-tab="applications" onclick="switchTab('applications')">📄 My Applications</div>
<div class="tab" data-tab="ratings" onclick="switchTab('ratings')">⭐ My Ratings</div>
<div class="tab" data-tab="messages" onclick="switchTab('messages')">💬 Messages</div>
<div class="tab" data-tab="profile" onclick="switchTab('profile')">⚙ Edit Profile</div>

      
    </div>

    <div class="sidebar">
   
   
</div>

    
    <!-- Available Properties Tab -->
    <div id="available" class="tab-content active">
    <h2>Available Properties</h2>
    
    <!-- Search and Filter Section -->
    <div class="search-filter-container">
        <form method="GET" action="">
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
                        <option value="Townhouse" <?php echo $type_filter === 'Townhouse' ? : ''; ?>>Townhouse</option>
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
                    <div class="swiper">
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

<script>
function startChatWithLandlord(landlordId, landlordName, propertyId, propertyTitle) {
    // First, switch to the messages tab
    const messagesTab = document.querySelector('.tab[data-tab="messages"]');
    if (messagesTab) {
        messagesTab.click();
        
        // Use a small delay to ensure the tab content is loaded
        setTimeout(() => {
            // Trigger the conversation start in the messages tab
            if (typeof window.startNewConversation === 'function') {
                window.startNewConversation(landlordId, landlordName, propertyId, propertyTitle);
            } else {
                // Fallback: show an alert if the function isn't available
                alert(`Ready to chat with ${landlordName} about "${propertyTitle}". Switching to Messages tab.`);
            }
        }, 300);
    } else {
        alert('Messages tab not found. Please navigate to the Messages tab to start a conversation.');
    }
}

// This function should be available in your messages tab
function startNewConversation(landlordId, landlordName, propertyId, propertyTitle) {
    // Check if conversation already exists
    const existingConversation = document.querySelector(`.conversation[data-landlord-id="${landlordId}"]`);
    
    if (existingConversation) {
        // If conversation exists, just activate it
        existingConversation.click();
    } else {
        // Create a new conversation
        const conversationList = document.querySelector('.chat-sidebar');
        const newConversation = document.createElement('div');
        newConversation.className = 'conversation active';
        newConversation.dataset.landlordId = landlordId;
        newConversation.innerHTML = `
            <strong>${landlordName}</strong>
            <p>Regarding: ${propertyTitle}</p>
        `;
        
        // Add click handler
        newConversation.addEventListener('click', function() {
            // Your existing conversation click handler logic
            loadConversation(landlordId, landlordName);
        });
        
        conversationList.appendChild(newConversation);
        
        // Create initial message
        const initialMessage = `Hello, I'm interested in your property "${propertyTitle}". Could you tell me more about it?`;
        
        // Load this new conversation
        loadConversation(landlordId, landlordName);
        
        // Optionally auto-send the initial message
        setTimeout(() => {
            document.querySelector('.chat-input-form textarea').value = initialMessage;
            // You might want to automatically send this message or let the user edit it first
        }, 500);
    }
}

// This function should already exist in your messages tab
function loadConversation(landlordId, landlordName) {
    // Your existing implementation to load a conversation
    console.log(`Loading conversation with ${landlordName} (ID: ${landlordId})`);
    
    // Update UI to show this conversation is active
    document.querySelectorAll('.conversation').forEach(conv => {
        conv.classList.remove('active');
    });
    document.querySelector(`.conversation[data-landlord-id="${landlordId}"]`).classList.add('active');
    
    // Focus the message input
    document.querySelector('.chat-input-form textarea').focus();
}
</script>
    
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
                        <div class="swiper">
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

    <div id="messages" class="tab-content">
    <h2>Messages</h2>
    <div class="chat-layout">
        <!-- Sidebar with conversations -->
        <div class="chat-sidebar">
            <h3>Conversations</h3>
            <?php if ($conversations->num_rows > 0): ?>
                <?php while($conv = $conversations->fetch_assoc()): ?>
                    <div class="conversation" onclick="openChat(<?php echo $conv['other_user_id']; ?>)">
                        <?php echo htmlspecialchars($conv['full_name']); ?>
                    </div>
                <?php endwhile; ?>
            <?php else: ?>
                <p>No conversations yet.</p>
            <?php endif; ?>
        </div>

        <!-- Chat window -->
        <div class="chat-window" id="chat-window">
            <p>Select a conversation to start chatting.</p>
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
</div>

<script src="https://cdn.jsdelivr.net/npm/swiper@8/swiper-bundle.min.js"></script>
<script>
    // Swiper init
    document.addEventListener('DOMContentLoaded', function() {
        const swipers = document.querySelectorAll('.swiper');
        swipers.forEach(swiperEl => {
            new Swiper(swiperEl, {
                loop: true,
                autoplay: { delay: 3000, disableOnInteraction: false },
                pagination: { el: swiperEl.querySelector('.swiper-pagination'), clickable: true },
                navigation: {
                    nextEl: swiperEl.querySelector('.swiper-button-next'),
                    prevEl: swiperEl.querySelector('.swiper-button-prev'),
                },
            });
        });
    });

    function switchTab(tabId) {
        document.querySelectorAll('.tab-content').forEach(content => content.classList.remove('active'));
        document.getElementById(tabId).classList.add('active');
        document.querySelectorAll('.tab').forEach(tab => tab.classList.remove('active'));
        event.currentTarget.classList.add('active');
    }
    
    function resetFilters() {
        window.location.href = window.location.pathname;
    }
    function openChat(userId) {
    fetch("fetch_chat.php?user_id=" + userId)
        .then(res => res.text())
        .then(html => {
            document.getElementById("chat-window").innerHTML = html;
        });
}

function sendMessage(event, userId) {
    event.preventDefault();
    const formData = new FormData(event.target);

    fetch("send_message.php?receiver_id=" + userId, {
        method: "POST",
        body: formData
    }).then(res => res.text())
      .then(() => openChat(userId)); // reload chat
}

function loadConversation(landlordId, landlordName, callback) {
    fetch("fetch_chat.php?user_id=" + landlordId)
        .then(res => res.text())
        .then(html => {
            document.getElementById("chat-window").innerHTML = html;

            // Run callback after content is loaded
            if (callback) callback();

            // Focus textarea if exists
            const textarea = document.querySelector(".chat-input-form textarea");
            if (textarea) textarea.focus();
        });

    // Mark active conversation in sidebar
    document.querySelectorAll('.conversation').forEach(conv => conv.classList.remove('active'));
    const activeConv = document.querySelector(`.conversation[data-landlord-id="${landlordId}"]`);
    if (activeConv) activeConv.classList.add('active');
}

function startNewConversation(landlordId, landlordName, propertyId, propertyTitle) {
    // Check if conversation already exists
    const existingConversation = document.querySelector(`.conversation[data-landlord-id="${landlordId}"]`);
    
    if (existingConversation) {
        loadConversation(landlordId, landlordName);
    } else {
        // Create new conversation in sidebar
        const conversationList = document.querySelector('.chat-sidebar');
        const newConversation = document.createElement('div');
        newConversation.className = 'conversation active';
        newConversation.dataset.landlordId = landlordId;
        newConversation.innerHTML = `
            <strong>${landlordName}</strong>
            <p>Regarding: ${propertyTitle}</p>
        `;
        newConversation.addEventListener('click', function() {
            loadConversation(landlordId, landlordName);
        });
        conversationList.appendChild(newConversation);

        // Load conversation first
        loadConversation(landlordId, landlordName, () => {
            // Callback: after chat is loaded, set initial message
            const textarea = document.querySelector('.chat-input-form textarea');
            if (textarea) {
                textarea.value = `Hello, I'm interested in your property "${propertyTitle}". Could you tell me more about it?`;
                textarea.focus();
            }
        });
    }
}


</script>
</body>
</html>