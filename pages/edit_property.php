<?php
include(__DIR__ . '/../config/db_connect.php');
session_start();

// Ensure landlord is logged in
if (!isset($_SESSION['user_id']) || $_SESSION['role'] !== 'landlord') {
    header("Location: login.php");
    exit;
}

$landlord_id = $_SESSION['user_id'];
$property_id = isset($_GET['id']) ? intval($_GET['id']) : 0;
$message = "";

// --- Fetch property details ---
$stmt = $conn->prepare("SELECT * FROM properties WHERE property_id = ? AND landlord_id = ?");
$stmt->bind_param("ii", $property_id, $landlord_id);
$stmt->execute();
$property = $stmt->get_result()->fetch_assoc();

if (!$property) {
    die("❌ Property not found or you do not have permission to edit it.");
}

// --- Update property details ---
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['update_property'])) {
    $title = trim($_POST['title']);
    $description = trim($_POST['description']);
    $price = floatval($_POST['price']);
    $location = trim($_POST['location']);

    $update = $conn->prepare("UPDATE properties SET title=?, description=?, price=?, location=? WHERE property_id=? AND landlord_id=?");
    $update->bind_param("ssdsii", $title, $description, $price, $location, $property_id, $landlord_id);

    if ($update->execute()) {
        $message = "✅ Property updated successfully.";
        // Refresh property data
        $stmt->execute();
        $property = $stmt->get_result()->fetch_assoc();
    } else {
        $message = "❌ Failed to update property.";
    }
}

// --- Upload new image ---
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['upload_image']) && isset($_FILES['new_image'])) {
    // Check if file was actually uploaded
    if ($_FILES['new_image']['error'] === UPLOAD_ERR_OK) {
        $target_dir = __DIR__ . "/../uploads/";
        if (!is_dir($target_dir)) {
            mkdir($target_dir, 0777, true);
        }

        // Validate file type
        $allowed_types = ['image/jpeg', 'image/png', 'image/gif', 'image/webp'];
        $file_type = $_FILES['new_image']['type'];
        
        if (!in_array($file_type, $allowed_types)) {
            $message = "❌ Invalid file type. Only JPG, PNG, GIF, and WEBP are allowed.";
        } else {
            // Generate safe filename
            $filename = time() . "_" . uniqid() . "_" . preg_replace('/[^a-zA-Z0-9\._-]/', '_', $_FILES["new_image"]["name"]);
            $target_file = $target_dir . $filename;

            if (move_uploaded_file($_FILES["new_image"]["tmp_name"], $target_file)) {
                $db_path = $filename;
                $insert = $conn->prepare("INSERT INTO property_images (property_id, image_path) VALUES (?, ?)");
                $insert->bind_param("is", $property_id, $db_path);
                
                if ($insert->execute()) {
                    $message = "✅ New image uploaded.";
                } else {
                    $message = "❌ Failed to save image to database.";
                    // Remove the uploaded file if database insert failed
                    if (file_exists($target_file)) {
                        unlink($target_file);
                    }
                }
            } else {
                $message = "❌ Failed to upload image. Please try again.";
            }
        }
    } else {
        // Handle different upload errors
        $upload_errors = [
            UPLOAD_ERR_INI_SIZE => "File too large (server limit).",
            UPLOAD_ERR_FORM_SIZE => "File too large (form limit).",
            UPLOAD_ERR_PARTIAL => "File upload was incomplete.",
            UPLOAD_ERR_NO_FILE => "No file was selected.",
            UPLOAD_ERR_NO_TMP_DIR => "Missing temporary folder.",
            UPLOAD_ERR_CANT_WRITE => "Failed to write file to disk.",
            UPLOAD_ERR_EXTENSION => "File upload stopped by extension."
        ];
        
        $error_code = $_FILES['new_image']['error'];
        $message = "❌ Upload error: " . ($upload_errors[$error_code] ?? "Unknown error (code: $error_code)");
    }
}

// --- Delete image ---
if (isset($_GET['delete_image'])) {
    $img_id = intval($_GET['delete_image']);

    // Verify the image belongs to this property and landlord
    $stmt_img = $conn->prepare("SELECT pi.image_path 
                               FROM property_images pi 
                               JOIN properties p ON pi.property_id = p.property_id 
                               WHERE pi.id = ? AND pi.property_id = ? AND p.landlord_id = ?");
    $stmt_img->bind_param("iii", $img_id, $property_id, $landlord_id);
    $stmt_img->execute();
    $res_img = $stmt_img->get_result();

    if ($img = $res_img->fetch_assoc()) {
        $file_path = __DIR__ . "/../uploads/" . $img['image_path'];
        
        // Delete file from server
        if (file_exists($file_path)) {
            unlink($file_path);
        }

        // Delete from database
        $delete_stmt = $conn->prepare("DELETE FROM property_images WHERE id = ? AND property_id = ?");
        $delete_stmt->bind_param("ii", $img_id, $property_id);
        
        if ($delete_stmt->execute()) {
            $message = "✅ Image deleted.";
        } else {
            $message = "❌ Failed to delete image from database.";
        }
    } else {
        $message = "❌ Image not found or you don't have permission to delete it.";
    }

    header("Location: edit_property.php?id=" . $property_id);
    exit;
}

// --- Fetch property images ---
$imgs = $conn->prepare("SELECT * FROM property_images WHERE property_id=? ORDER BY date_posted DESC");
$imgs->bind_param("i", $property_id);
$imgs->execute();
$images = $imgs->get_result();

?>
<!DOCTYPE html>
<html>
<head>
    <title>Edit Property - HomeFinder</title>
    <style>
        body { font-family: Arial, sans-serif; background: #f7f7f7; margin: 20px; }
        .container { max-width: 900px; margin: auto; background: #fff; padding: 20px; border-radius: 8px; }
        h2 { color: #333; }
        .message { 
            background: #eef; 
            padding: 10px; 
            border-radius: 5px; 
            margin-bottom: 15px; 
            border-left: 4px solid #007bff;
        }
        .message.success { background: #d4edda; border-left-color: #28a745; }
        .message.error { background: #f8d7da; border-left-color: #dc3545; }
        form { margin-bottom: 30px; }
        label { display: block; margin-top: 10px; font-weight: bold; }
        input, textarea { 
            width: 100%; 
            padding: 8px; 
            margin-top: 5px; 
            border-radius: 5px; 
            border: 1px solid #ccc; 
            box-sizing: border-box;
        }
        button { 
            margin-top: 15px; 
            padding: 10px 15px; 
            border: none; 
            border-radius: 5px; 
            cursor: pointer; 
            font-size: 14px;
        }
        .save-btn { background: #28a745; color: white; }
        .save-btn:hover { background: #218838; }
        .upload-btn { background: #007bff; color: white; }
        .upload-btn:hover { background: #0056b3; }
        .images { 
            display: flex; 
            flex-wrap: wrap; 
            gap: 15px; 
            margin: 20px 0;
        }
        .image-item { 
            position: relative; 
            border: 1px solid #ddd;
            border-radius: 8px;
            padding: 10px;
        }
        .images img { 
            width: 180px; 
            height: 120px; 
            object-fit: cover; 
            border-radius: 6px; 
        }
        .delete-btn {
            position: absolute; 
            top: 5px; 
            right: 5px;
            background: red; 
            color: white; 
            padding: 5px 8px;
            border-radius: 50%; 
            text-decoration: none;
            width: 25px;
            height: 25px;
            text-align: center;
            line-height: 15px;
            font-weight: bold;
        }
        .delete-btn:hover {
            background: #c82333;
        }
        .section {
            margin-bottom: 30px;
            padding-bottom: 20px;
            border-bottom: 1px solid #eee;
        }
    </style>
</head>
<body>
<div class="container">
    <h2>Edit Property: <?php echo htmlspecialchars($property['title']); ?></h2>
    
    <?php if (!empty($message)): ?>
        <div class="message <?php echo strpos($message, '✅') !== false ? 'success' : 'error'; ?>">
            <?php echo htmlspecialchars($message); ?>
        </div>
    <?php endif; ?>

    <!-- Property Details Form -->
    <div class="section">
        <h3>Property Details</h3>
        <form method="POST">
            <label>Title:</label>
            <input type="text" name="title" value="<?php echo htmlspecialchars($property['title']); ?>" required>

            <label>Description:</label>
            <textarea name="description" rows="4" required><?php echo htmlspecialchars($property['description']); ?></textarea>

            <label>Price (USD):</label>
            <input type="number" name="price" step="0.01" min="0" value="<?php echo htmlspecialchars($property['price']); ?>" required>

            <label>Location:</label>
            <input type="text" name="location" value="<?php echo htmlspecialchars($property['location']); ?>" required>

            <button type="submit" name="update_property" class="save-btn">Save Changes</button>
        </form>
    </div>

    <!-- Image Management -->
    <!-- Image Management -->
<div class="section">
    <h3>Property Images</h3>

    <div class="images">
        <?php if ($images && $images->num_rows > 0): ?>
            <?php while ($img = $images->fetch_assoc()): ?>
                <div class="image-item">
                    <img src="../uploads/<?php echo htmlspecialchars($img['image_path']); ?>" 
                         alt="Property Image"
                         onerror="this.src='data:image/svg+xml;base64,PHN2ZyB3aWR0aD0iMTgwIiBoZWlnaHQ9IjEyMCIgeG1sbnM9Imh0dHA6Ly93d3cudzMub3JnLzIwMDAvc3ZnIj48cmVjdCB3aWR0aD0iMTAwJSIgaGVpZ2h0PSIxMDAlIiBmaWxsPSIjZGRkIi8+PHRleHQgeD0iNTAlIiB5PSI1MCUiIGZvbnQtZmFtaWx5PSJBcmlhbCwgc2Fucy1zZXJpZiIgZm9udC1zaXplPSIxNCIgZmlsbD0iIzY2NiIgdGV4dC1hbmNob3I9Im1pZGRsZSIgZHk9Ii4zZW0iPkltYWdlIE5vdCBGb3VuZDwvdGV4dD48L3N2Zz4='">
                    
                    <!-- Delete Button -->
                    <a href="edit_property.php?delete_image=<?php echo $img['id']; ?>&id=<?php echo $property_id; ?>" 
                       class="delete-btn" 
                       onclick="return confirm('Are you sure you want to delete this image?');">×</a>
                </div>
            <?php endwhile; ?>
        <?php else: ?>
            <p>No images uploaded yet.</p>
        <?php endif; ?>
    </div>

    <!-- Upload new image form -->
    <form method="POST" enctype="multipart/form-data">
        <label>Upload New Image (JPG, PNG, GIF, WEBP):</label>
        <input type="file" name="new_image" accept="image/*" required>
        <button type="submit" name="upload_image" class="upload-btn">Add Image</button>
    </form>
</div>


    <div>
        <a href="landlord_dashboard.php" style="color: #007bff; text-decoration: none;">← Back to Dashboard</a>
    </div>
</div>

<script>
    // Clear message after 5 seconds
    setTimeout(function() {
        const message = document.querySelector('.message');
        if (message) {
            message.style.opacity = '0';
            message.style.transition = 'opacity 0.5s ease';
            setTimeout(() => message.remove(), 500);
        }
    }, 5000);
</script>
</body>
</html>