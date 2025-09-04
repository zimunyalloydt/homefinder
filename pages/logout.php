<?php
include(__DIR__ . '/../config/db_connect.php');

session_start();
$message = "";

if ($_SERVER["REQUEST_METHOD"] == "POST") {
    // Safely get email and password with null coalescing
    $email = trim($_POST['email'] ?? '');
    $password = $_POST['password'] ?? '';

    // Validate inputs
    if (empty($email) || empty($password)) {
        $message = "❌ Please enter both email and password.";
    } elseif (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
        $message = "❌ Please enter a valid email address.";
    } else {
        // Fetch user by email
        $stmt = $conn->prepare("SELECT user_id, full_name, email, password_hash, role FROM users WHERE email = ?");
        $stmt->bind_param("s", $email);
        $stmt->execute();
        $result = $stmt->get_result();

        if ($result->num_rows === 1) {
            $user = $result->fetch_assoc();

            // Verify password
            if (password_verify($password, $user['password_hash'])) {
                // Store session data
                $_SESSION['user_id']   = $user['user_id'];
                $_SESSION['full_name'] = $user['full_name'];
                $_SESSION['email']     = $user['email'];
                $_SESSION['role']     = $user['role'];

                // Redirect based on role
                header("Location: " . ($user['role'] === 'landlord' ? 'dashboard_landlord.php' : 'dashboard_tenant.php'));
                exit();
            } else {
                $message = "❌ Invalid password.";
            }
        } else {
            $message = "❌ No account found with that email address.";
        }
    }
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>HomeFinder - Login</title>
    <link rel="stylesheet" href="/../css/styles.css">

</head>
<body class="login-page">
    <div class="login-wrapper">
        <div class="login-container">
           <div class="login-header">
    <div class="logo">
        <img src="/../assets/logo.png" alt="HomeFinder Logo">
    </div>
    <h1>Welcome to HomeFinder</h1>
    <p>Please login to continue</p>
</div>

            
            <?php if (!empty($message)): ?>
                <div class="alert alert-error"><?php echo htmlspecialchars($message); ?></div>
            <?php endif; ?>

            <form method="POST" class="login-form">
                <div class="form-group">
                    <label for="email">Email Address</label>
                    <input type="email" id="email" name="email" required 
                           value="<?php echo htmlspecialchars($_POST['email'] ?? ''); ?>">
                </div>
                <div class="form-group">
                    <label for="password">Password</label>
                    <input type="password" id="password" name="password" required>
                </div>
                <button type="submit" class="btn btn-primary btn-block">Login</button>
            </form>

            <div class="login-footer">
                <p>Don't have an account? <a href="register.php">Sign up</a></p>
                <p><a href="forgot_password.php">Forgot password?</a></p>
            </div>
        </div>
    </div>
</body>
</html>