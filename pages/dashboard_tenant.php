<?php
include(__DIR__ . '/../config/db_connect.php');
session_start();

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
        <!-- Sidebar (Conversations List) -->
        <div class="chat-sidebar">
            <div class="sidebar-header">
                <span>Chats</span>
                <button class="new-chat-btn" onclick="openNewChatModal()">
                    <i class="fas fa-plus"></i>
                </button>
            </div>
            
            <!-- Search conversations -->
            <div class="chat-search">
                <input type="text" id="conversation-search" placeholder="Search conversations..." onkeyup="filterConversations()">
            </div>
            
            <div class="conversation-list">
                <?php if ($conversations->num_rows > 0): ?>
                    <?php while($conv = $conversations->fetch_assoc()): 
                        // Get last message for preview
                        $last_msg_stmt = $conn->prepare("
                            SELECT message_text, created_at, is_read 
                            FROM messages 
                            WHERE (sender_id = ? AND receiver_id = ?) OR (sender_id = ? AND receiver_id = ?) 
                            ORDER BY created_at DESC LIMIT 1
                        ");
                        $last_msg_stmt->bind_param("iiii", $tenant_id, $conv['other_user_id'], $conv['other_user_id'], $tenant_id);
                        $last_msg_stmt->execute();
                        $last_msg = $last_msg_stmt->get_result()->fetch_assoc();
                    ?>
                        <a href="?chat_with=<?php echo $conv['other_user_id']; ?>" 
                           class="conversation <?php echo $active_chat_user == $conv['other_user_id'] ? 'active' : ''; ?>"
                           data-userid="<?php echo $conv['other_user_id']; ?>"
                           data-username="<?php echo htmlspecialchars($conv['full_name']); ?>">
                            <div class="conversation-avatar">
                                <?php echo strtoupper(substr($conv['full_name'], 0, 1)); ?>
                            </div>
                            <div class="conversation-details">
                                <div class="conversation-name">
                                    <?php echo htmlspecialchars($conv['full_name']); ?>
                                    <span class="conversation-time">
                                        <?php if ($last_msg): ?>
                                            <?php echo date('H:i', strtotime($last_msg['created_at'])); ?>
                                        <?php endif; ?>
                                    </span>
                                </div>
                                <div class="conversation-preview">
                                    <?php if ($last_msg): ?>
                                        <?php 
                                            $preview = htmlspecialchars($last_msg['message_text']);
                                            if (strlen($preview) > 30) {
                                                $preview = substr($preview, 0, 30) . '...';
                                            }
                                            echo $preview;
                                        ?>
                                    <?php else: ?>
                                        Start a conversation...
                                    <?php endif; ?>
                                </div>
                            </div>
                            <?php if ($last_msg && $last_msg['is_read'] == 0 && $last_msg['sender_id'] == $conv['other_user_id']): ?>
                                <span class="unread-badge"></span>
                            <?php endif; ?>
                        </a>
                    <?php endwhile; ?>
                <?php else: ?>
                    <p class="no-conversations">No conversations yet.</p>
                <?php endif; ?>
            </div>
        </div>

        <!-- Chat Window -->
        <div class="chat-window">
            <?php if ($active_chat_user > 0 && $chat_messages): 
                $landlord_info = $conn->query("SELECT full_name FROM users WHERE user_id = $active_chat_user")->fetch_assoc();
            ?>
                <div class="chat-header">
                    <div class="chat-partner-info">
                        <div class="chat-avatar">
                            <?php echo strtoupper(substr($landlord_info['full_name'], 0, 1)); ?>
                        </div>
                        <div class="chat-partner-name">
                            <h3><?php echo htmlspecialchars($landlord_info['full_name']); ?></h3>
                            <span class="online-status" id="online-status-<?php echo $active_chat_user; ?>">
                                <i class="fas fa-circle"></i> Offline
                            </span>
                        </div>
                    </div>
                    <div class="chat-actions">
                        <button class="chat-action-btn" title="Voice call">
                            <i class="fas fa-phone"></i>
                        </button>
                        <button class="chat-action-btn" title="Video call">
                            <i class="fas fa-video"></i>
                        </button>
                        <button class="chat-action-btn" title="More options">
                            <i class="fas fa-ellipsis-v"></i>
                        </button>
                    </div>
                </div>

                <div class="chat-body" id="chat-body">
                    <div class="chat-date-divider">
                        <span>Today</span>
                    </div>
                    
                    <?php 
                    $current_date = null;
                    while($msg = $chat_messages->fetch_assoc()): 
                        $msg_date = date('Y-m-d', strtotime($msg['created_at']));
                        $today = date('Y-m-d');
                        $yesterday = date('Y-m-d', strtotime('-1 day'));
                        
                        if ($current_date !== $msg_date) {
                            $current_date = $msg_date;
                            $display_date = '';
                            
                            if ($msg_date === $today) {
                                $display_date = 'Today';
                            } elseif ($msg_date === $yesterday) {
                                $display_date = 'Yesterday';
                            } else {
                                $display_date = date('M j, Y', strtotime($msg_date));
                            }
                            
                            echo '<div class="chat-date-divider"><span>' . $display_date . '</span></div>';
                        }
                    ?>
                        <div class="chat-bubble <?php echo $msg['sender_id'] == $tenant_id ? 'sent' : 'received'; ?>"
                             data-msgid="<?php echo $msg['message_id']; ?>">
                            <p><?php echo nl2br(htmlspecialchars($msg['message_text'])); ?></p>
                            <div class="chat-meta">
                                <span class="chat-time"><?php echo date('H:i', strtotime($msg['created_at'])); ?></span>
                                <?php if ($msg['sender_id'] == $tenant_id): ?>
                                    <span class="message-status">
                                        <?php if ($msg['is_read']): ?>
                                            <i class="fas fa-check-double read"></i>
                                        <?php else: ?>
                                            <i class="fas fa-check"></i>
                                        <?php endif; ?>
                                    </span>
                                <?php endif; ?>
                            </div>
                        </div>
                    <?php endwhile; ?>
                </div>

                <div class="chat-footer">
                    <div class="chat-input-actions">
                        <button class="chat-action-btn" title="Attach file">
                            <i class="fas fa-paperclip"></i>
                        </button>
                        <button class="chat-action-btn" title="Emoji">
                            <i class="far fa-smile"></i>
                        </button>
                    </div>
                    <form id="chatForm" class="chat-input-form">
                        <input type="hidden" name="receiver_id" id="receiver_id" value="<?php echo $active_chat_user; ?>">
                        <textarea id="message_text" name="message_text" placeholder="Type a message..." rows="1" oninput="autoResize(this)"></textarea>
                        <button type="submit" class="send-btn">
                            <i class="fas fa-paper-plane"></i>
                        </button>
                    </form>
                </div>

            <?php else: ?>
                <div class="no-chat-selected">
                    <div class="no-chat-icon">
                        <i class="fas fa-comments"></i>
                    </div>
                    <h3>Your messages</h3>
                    <p>Select a conversation or start a new one</p>
                    <button class="btn-primary" onclick="openNewChatModal()">Start new conversation</button>
                </div>
            <?php endif; ?>
        </div>
    </div>
</div>

<!-- New Chat Modal -->
<div id="newChatModal" class="modal">
    <div class="modal-content">
        <div class="modal-header">
            <h3>New conversation</h3>
            <span class="close" onclick="closeNewChatModal()">&times;</span>
        </div>
        <div class="modal-body">
            <div class="search-box">
                <input type="text" id="landlordSearch" placeholder="Search landlords..." onkeyup="searchLandlords()">
            </div>
            <div id="landlordList" class="landlord-list">
                <!-- Landlords will be populated here via AJAX -->
            </div>
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

document.addEventListener('DOMContentLoaded', function() {
    // Initialize chat if active
    <?php if ($active_chat_user > 0): ?>
        scrollToBottom();
        markMessagesAsRead(<?php echo $active_chat_user; ?>);
        startPolling(<?php echo $active_chat_user; ?>);
    <?php endif; ?>
    
    // Chat form submission
    const chatForm = document.getElementById('chatForm');
    if (chatForm) {
        chatForm.addEventListener('submit', function(e) {
            e.preventDefault();
            sendMessage();
        });
    }
    
    // Enable Enter key to send message (Shift+Enter for new line)
    const messageText = document.getElementById('message_text');
    if (messageText) {
        messageText.addEventListener('keydown', function(e) {
            if (e.key === 'Enter' && !e.shiftKey) {
                e.preventDefault();
                document.getElementById('chatForm').dispatchEvent(new Event('submit'));
            }
        });
    }
});

// Auto-resize textarea
function autoResize(textarea) {
    textarea.style.height = 'auto';
    textarea.style.height = (textarea.scrollHeight) + 'px';
}

// Scroll to bottom of chat
function scrollToBottom() {
    const chatBody = document.getElementById('chat-body');
    if (chatBody) {
        chatBody.scrollTop = chatBody.scrollHeight;
    }
}

// Send message via AJAX
function sendMessage() {
    const receiverId = document.getElementById('receiver_id').value;
    const messageText = document.getElementById('message_text');
    const message = messageText.value.trim();
    
    if (!message) return;
    
    // Create temporary message bubble (optimistic UI update)
    const tempId = 'temp-' + Date.now();
    const chatBody = document.getElementById('chat-body');
    const now = new Date();
    const timeStr = now.getHours().toString().padStart(2, '0') + ':' + 
                    now.getMinutes().toString().padStart(2, '0');
    
    const tempMsgHtml = `
        <div class="chat-bubble sent" data-msgid="${tempId}">
            <p>${message.replace(/\n/g, '<br>')}</p>
            <div class="chat-meta">
                <span class="chat-time">${timeStr}</span>
                <span class="message-status"><i class="fas fa-check"></i></span>
            </div>
        </div>
    `;
    
    chatBody.insertAdjacentHTML('beforeend', tempMsgHtml);
    scrollToBottom();
    
    // Clear input and reset height
    messageText.value = '';
    messageText.style.height = 'auto';
    
    // Send via AJAX
    const formData = new FormData();
    formData.append('send_message', 'true');
    formData.append('receiver_id', receiverId);
    formData.append('message_text', message);
    
    fetch('', {
        method: 'POST',
        body: formData
    })
    .then(response => response.text())
    .then(data => {
        // Replace temporary message with actual one from server if needed
        // In a real implementation, you might want to update the message ID
        console.log('Message sent successfully');
    })
    .catch(error => {
        console.error('Error sending message:', error);
        // Show error indicator
        const tempMsg = document.querySelector(`[data-msgid="${tempId}"]`);
        if (tempMsg) {
            tempMsg.querySelector('.message-status').innerHTML = '<i class="fas fa-exclamation-circle error"></i>';
        }
    });
}

// Poll for new messages
function startPolling(receiverId) {
    setInterval(() => {
        checkNewMessages(receiverId);
    }, 3000); // Check every 3 seconds
}

// Check for new messages
function checkNewMessages(receiverId) {
    // Get the last message ID in the chat
    const lastMsg = document.querySelector('.chat-bubble:last-child');
    const lastMsgId = lastMsg ? lastMsg.dataset.msgid : 0;
    
    fetch(`check_messages.php?receiver_id=${receiverId}&last_id=${lastMsgId}`)
    .then(response => response.json())
    .then(messages => {
        if (messages.length > 0) {
            appendNewMessages(messages);
            markMessagesAsRead(receiverId);
        }
        
        // Update online status
        checkOnlineStatus(receiverId);
    })
    .catch(error => console.error('Error checking messages:', error));
}

// Append new messages to chat
function appendNewMessages(messages) {
    const chatBody = document.getElementById('chat-body');
    
    messages.forEach(msg => {
        const msgHtml = `
            <div class="chat-bubble received" data-msgid="${msg.id}">
                <p>${msg.text.replace(/\n/g, '<br>')}</p>
                <div class="chat-meta">
                    <span class="chat-time">${msg.time}</span>
                </div>
            </div>
        `;
        
        chatBody.insertAdjacentHTML('beforeend', msgHtml);
    });
    
    scrollToBottom();
}

// Mark messages as read
function markMessagesAsRead(senderId) {
    fetch('mark_as_read.php', {
        method: 'POST',
        headers: {
            'Content-Type': 'application/json',
        },
        body: JSON.stringify({
            sender_id: senderId
        })
    })
    .then(response => response.json())
    .then(data => {
        // Update message status indicators
        document.querySelectorAll('.chat-bubble.received').forEach(bubble => {
            bubble.querySelector('.message-status')?.remove();
        });
    })
    .catch(error => console.error('Error marking messages as read:', error));
}

// Check online status
function checkOnlineStatus(userId) {
    fetch(`check_online.php?user_id=${userId}`)
    .then(response => response.json())
    .then(data => {
        const statusElement = document.getElementById(`online-status-${userId}`);
        if (statusElement) {
            if (data.online) {
                statusElement.innerHTML = '<i class="fas fa-circle online"></i> Online';
            } else {
                const lastSeen = data.last_seen ? `Last seen ${formatLastSeen(data.last_seen)}` : 'Offline';
                statusElement.innerHTML = `<i class="fas fa-circle"></i> ${lastSeen}`;
            }
        }
    })
    .catch(error => console.error('Error checking online status:', error));
}

// Format last seen time
function formatLastSeen(timestamp) {
    const now = new Date();
    const lastSeen = new Date(timestamp);
    const diffMs = now - lastSeen;
    const diffMins = Math.floor(diffMs / 60000);
    const diffHours = Math.floor(diffMs / 3600000);
    
    if (diffMins < 1) return 'just now';
    if (diffMins < 60) return `${diffMins} minute${diffMins !== 1 ? 's' : ''} ago`;
    if (diffHours < 24) return `${diffHours} hour${diffHours !== 1 ? 's' : ''} ago`;
    
    return lastSeen.toLocaleDateString();
}

// New chat modal functions
function openNewChatModal() {
    document.getElementById('newChatModal').style.display = 'block';
    searchLandlords(); // Load all landlords initially
}

function closeNewChatModal() {
    document.getElementById('newChatModal').style.display = 'none';
}

// Search landlords for new chat
function searchLandlords() {
    const query = document.getElementById('landlordSearch').value;
    
    fetch(`search_landlords.php?q=${encodeURIComponent(query)}`)
    .then(response => response.json())
    .then(landlords => {
        const landlordList = document.getElementById('landlordList');
        landlordList.innerHTML = '';
        
        if (landlords.length === 0) {
            landlordList.innerHTML = '<p class="no-results">No landlords found</p>';
            return;
        }
        
        landlords.forEach(landlord => {
            const landlordEl = document.createElement('div');
            landlordEl.className = 'landlord-item';
            landlordEl.innerHTML = `
                <div class="landlord-avatar">${landlord.initials}</div>
                <div class="landlord-name">${landlord.name}</div>
                <button class="btn-primary" onclick="startChatWith(${landlord.id}, '${landlord.name}')">Chat</button>
            `;
            landlordList.appendChild(landlordEl);
        });
    })
    .catch(error => console.error('Error searching landlords:', error));
}

// Start chat with selected landlord
function startChatWith(landlordId, landlordName) {
    closeNewChatModal();
    window.location.href = `?chat_with=${landlordId}`;
}

// Filter conversations
function filterConversations() {
    const searchTerm = document.getElementById('conversation-search').value.toLowerCase();
    const conversations = document.querySelectorAll('.conversation');
    
    conversations.forEach(conv => {
        const userName = conv.dataset.username.toLowerCase();
        if (userName.includes(searchTerm)) {
            conv.style.display = 'flex';
        } else {
            conv.style.display = 'none';
        }
    });
}

// Close modal if clicked outside
window.onclick = function(event) {
    const modal = document.getElementById('newChatModal');
    if (event.target === modal) {
        closeNewChatModal();
    }
}
// Start a new conversation from property listing
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