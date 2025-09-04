<?php
include(__DIR__ . '/../config/db_connect.php');
session_start();

// Redirect if not landlord
if (!isset($_SESSION['user_id']) || $_SESSION['role'] !== 'landlord') {
    header("Location: login.php");
    exit();
}

$landlord_id = $_SESSION['user_id'];
$message = "";

// Handle property submission
if ($_SERVER["REQUEST_METHOD"] == "POST") {
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
    } catch (Exception $e) {
        // Rollback on error
        $conn->rollback();
        $message = "❌ Error: " . $e->getMessage();
    }
}
?>

<!DOCTYPE html>
<html>
<head>
    <title>Add Property - HomeFinder</title>
   
    <link rel="stylesheet" href="/../css/addproperty.css">
</head>
<body>
    <div class="container">
        <h2>Add New Property</h2>
        <p><a href="dashboard_landlord.php"+ user_id>⬅ Back to Dashboard</a></p>

        <?php if ($message): ?>
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
</body>
</html>