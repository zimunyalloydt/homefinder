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

    // Extra fields only for tenants
    $profession = isset($_POST['profession']) ? trim($_POST['profession']) : null;
    $family_size = isset($_POST['family_size']) ? intval($_POST['family_size']) : null;

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

                // If tenant, insert extra info into tenants table
                if ($role === "tenant") {
                    $tenant_stmt = $conn->prepare("INSERT INTO tenants (user_id, profession, family_size) VALUES (?, ?, ?)");
                    $tenant_stmt->bind_param("isi", $user_id, $profession, $family_size);
                    $tenant_stmt->execute();
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
    <script>
        function toggleTenantFields() {
            const role = document.getElementById("role").value;
            const tenantFields = document.getElementById("tenantFields");
            if (role === "tenant") {
                tenantFields.style.display = "block";
            } else {
                tenantFields.style.display = "none";
            }
        }
    </script>
</head>
<body>
<div class="container" style="max-width:500px; margin-top:50px;">
    <h2>Register for HomeFinder</h2>
    <?php if(isset($message)) echo "<p class='message error'>{$message}</p>"; ?>
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
        <select name="role" id="role" onchange="toggleTenantFields()" required>
            <option value="tenant">Tenant</option>
            <option value="landlord">Landlord</option>
        </select>

        <!-- Tenant specific fields -->
        <div id="tenantFields" style="display:none; margin-top:10px;">
            <label>Profession:</label>
            <input type="text" name="profession">

            <label>Family Size:</label>
            <input type="number" name="family_size" min="1">
        </div>

        <button type="submit">Register</button>
    </form>
    <p style="margin-top:10px;">Already have an account? <a href="login.php">Login here</a></p>
</div>

<script>
    // Run on page load in case tenant is already selected
    toggleTenantFields();
</script>
</body>
</html>
