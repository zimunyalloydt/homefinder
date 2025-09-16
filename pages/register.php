<?php
include(__DIR__ . '/../config/db_connect.php');
$message = "";

if ($_SERVER["REQUEST_METHOD"] == "POST") {
    $full_name = trim($_POST['full_name']);
    $email = trim($_POST['email']);
    $phone = trim($_POST['phone']);
    $password = $_POST['password'];
    $confirm_password = $_POST['confirm_password'];
    $role = $_POST['role'];

    // Tenant-specific fields
    $profession = isset($_POST['profession']) ? trim($_POST['profession']) : null;
    $family_size = isset($_POST['family_size']) ? intval($_POST['family_size']) : null;
    $children_count = isset($_POST['children_count']) ? intval($_POST['children_count']) : null;
    $previous_residence = isset($_POST['previous_residence']) ? trim($_POST['previous_residence']) : null;

    // Landlord-specific fields
    $house_capacity = isset($_POST['house_capacity']) ? intval($_POST['house_capacity']) : 0;
    $tenants_changed_year = isset($_POST['tenants_changed_year']) ? intval($_POST['tenants_changed_year']) : 0;

    if ($password !== $confirm_password) {
        $message = "❌ Passwords do not match.";
    } else {
        $password_hash = password_hash($password, PASSWORD_DEFAULT);

        // Check if email already exists
        $check = $conn->prepare("SELECT * FROM users WHERE email = ?");
        $check->bind_param("s", $email);
        $check->execute();
        $result = $check->get_result();

        if ($result->num_rows > 0) {
            $message = "❌ Email is already registered.";
        } else {
            // Insert into users table
            $stmt = $conn->prepare("INSERT INTO users (full_name,email,phone,password_hash,role) VALUES (?,?,?,?,?)");
            $stmt->bind_param("sssss",$full_name,$email,$phone,$password_hash,$role);

            if ($stmt->execute()) {
                $user_id = $stmt->insert_id;

                if ($role === "tenant") {
                    // Insert into tenants
                    $tenant_stmt = $conn->prepare("INSERT INTO tenants (user_id, profession, family_size, children_count, previous_residence) VALUES (?, ?, ?, ?, ?)");
                    $tenant_stmt->bind_param("isiss", $user_id, $profession, $family_size, $children_count, $previous_residence);
                    $tenant_stmt->execute();

                } elseif ($role === "landlord") {
                    // Insert into landlords
                    $landlord_stmt = $conn->prepare("INSERT INTO landlords (user_id, house_capacity, tenants_changed_year) VALUES (?, ?, ?)");
                    $landlord_stmt->bind_param("iii", $user_id, $house_capacity, $tenants_changed_year);
                    $landlord_stmt->execute();
                }

                $message = "✅ Registration successful. You can now log in.";
            } else {
                $message = "❌ Error: ".$conn->error;
            }
        }
    }
}
?>

<!DOCTYPE html>
<html>
<head>
    <title>Register - HomeFinder</title>
    <link rel="stylesheet" href="/../css/register.css">
</head>
<body>
<div class="container" style="max-width:500px; margin-top:50px;">
    <h2>Register for HomeFinder</h2>
    <?php if(!empty($message)) echo "<p class='message error'>{$message}</p>"; ?>
    <form method="POST">
        <label>Full Name:</label>
        <input type="text" name="full_name" required>

        <label>Email:</label>
        <input type="email" name="email" required>

        <label>Phone:</label>
        <input type="text" name="phone">

        <label>Password:</label>
        <input type="password" name="password" required>

        <label>Confirm Password:</label>
        <input type="password" name="confirm_password" required>

        <label>Role:</label>
        <select name="role" id="role" onchange="toggleFields()" required>
            <option value="">-- Select --</option>
            <option value="tenant">Tenant</option>
            <option value="landlord">Landlord</option>
        </select>

        <!-- Tenant specific fields -->
        <div id="tenantFields" style="display:none;">
            <label>Profession:</label>
            <input type="text" name="profession">

            <label>Family Size:</label>
            <input type="number" name="family_size" min="1">

            <label>Number of Children:</label>
            <input type="number" name="children_count" min="0">

            <label>Former Residence:</label>
            <input type="text" name="previous_residence" placeholder="Previous place of residence">
        </div>

        <!-- Landlord specific fields -->
        <div id="landlordFields" style="display:none;">
            <label>House Capacity:</label>
            <input type="number" name="house_capacity" min="1">

            <label>Tenants Changed (This Year):</label>
            <input type="number" name="tenants_changed_year" min="0">
        </div>

        <button type="submit">Register</button>
    </form>
    <p style="margin-top:10px;">Already have an account? <a href="login.php">Login here</a></p>
</div>

<script>
function toggleFields() {
    const role = document.getElementById("role").value;
    document.getElementById("tenantFields").style.display = (role === "tenant") ? "block" : "none";
    document.getElementById("landlordFields").style.display = (role === "landlord") ? "block" : "none";
}
</script>
</body>
</html>
