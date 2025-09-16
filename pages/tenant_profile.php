<?php
include(__DIR__ . '/../config/db_connect.php');

if (!isset($_GET['tenant_id'])) {
    die("Invalid request");
}

$tenant_id = intval($_GET['tenant_id']);

// Handle Approve/Reject POST
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (isset($_POST['approve'])) {
        $status = "approved";
    } elseif (isset($_POST['reject'])) {
        $status = "rejected";
    }

    if (isset($status)) {
        // Update tenant status
        $update_stmt = $conn->prepare("UPDATE tenants SET status = ? WHERE user_id = ?");
        $update_stmt->bind_param("si", $status, $tenant_id);
        $update_stmt->execute();
        
        // Also update all applications for this tenant
        $update_app_stmt = $conn->prepare("UPDATE applications SET status = ? WHERE tenant_id = ?");
        $update_app_stmt->bind_param("si", $status, $tenant_id);
        $update_app_stmt->execute();

        $message = "Tenant has been " . ucfirst($status) . ". All their applications have been updated.";
    }
}

// Fetch tenant details
$tenant_stmt = $conn->prepare("
    SELECT u.full_name, u.email, u.phone,
           t.family_size, t.profession, t.children_count, 
           t.previous_rentals, t.previous_residence, t.additional_info, t.status
    FROM users u
    LEFT JOIN tenants t ON u.user_id = t.user_id
    WHERE u.user_id = ?
");
$tenant_stmt->bind_param("i", $tenant_id);
$tenant_stmt->execute();
$tenant_result = $tenant_stmt->get_result();
$tenant = $tenant_result->fetch_assoc();

if (!$tenant) {
    die("Tenant not found.");
}

// Fetch ratings and comments from previous landlords
$ratings_stmt = $conn->prepare("
    SELECT r.rating, r.review, u.full_name AS landlord_name, r.created_at 
    FROM tenant_ratings r
    JOIN landlords l ON r.landlord_id = l.landlord_id
    JOIN users u ON l.user_id = u.user_id
    WHERE r.tenant_id = ?
    ORDER BY r.created_at DESC
");
$ratings_stmt->bind_param("i", $tenant_id);
$ratings_stmt->execute();
$ratings = $ratings_stmt->get_result();
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <title>Tenant Profile - <?php echo htmlspecialchars($tenant['full_name']); ?></title>
    <link rel="stylesheet" href="../css/tenantprofile.css">
    <style>
        .tenant-profile { max-width: 800px; margin: 2rem auto; background: #fff; padding: 20px; border-radius: 8px; }
        .tenant-profile h2 { margin-bottom: 1rem; }
        .tenant-info p { margin: 0.5rem 0; }
        .ratings { margin-top: 2rem; }
        .rating-card { border: 1px solid #ddd; padding: 10px; margin-bottom: 10px; border-radius: 5px; }
        .rating-card strong { display: inline-block; width: 120px; }
        .decision-btns { margin-top: 20px; display: flex; gap: 10px; }
        .btn { padding: 10px 20px; border: none; border-radius: 5px; cursor: pointer; font-weight: bold; }
        .btn-approve { background: #28a745; color: white; }
        .btn-reject { background: #dc3545; color: white; }
        .btn:hover { opacity: 0.9; }
        .status { margin-top: 15px; font-weight: bold; }
        .status-badge {
            display: inline-block;
            padding: 5px 10px;
            border-radius: 4px;
            color: white;
            font-weight: bold;
        }
        .status-approved { background-color: #28a745; }
        .status-rejected { background-color: #dc3545; }
        .status-pending { background-color: #ffc107; color: #000; }
    </style>
</head>
<body>
    <div class="tenant-profile">
        <h2><?php echo htmlspecialchars($tenant['full_name']); ?> - Tenant Profile</h2>
        
        <?php if (isset($message)): ?>
            <p class="status"><?php echo htmlspecialchars($message); ?></p>
        <?php endif; ?>

        <div class="tenant-info">
            <p><strong>Email:</strong> <a href="mailto:<?php echo htmlspecialchars($tenant['email']); ?>"><?php echo htmlspecialchars($tenant['email']); ?></a></p>
            <p><strong>Phone:</strong> <a href="tel:<?php echo htmlspecialchars($tenant['phone']); ?>"><?php echo htmlspecialchars($tenant['phone']); ?></a></p>
            <p><strong>Profession:</strong> <?php echo htmlspecialchars($tenant['profession'] ?? 'N/A'); ?></p>
            <p><strong>Family Size:</strong> <?php echo htmlspecialchars($tenant['family_size'] ?? 'N/A'); ?></p>
            <p><strong>Number of Children:</strong> <?php echo htmlspecialchars($tenant['children_count'] ?? 'N/A'); ?></p>
            <p><strong>Previous Rentals:</strong> <?php echo htmlspecialchars($tenant['previous_rentals'] ?? 'N/A'); ?></p>
            <p><strong>Former Residence:</strong> <?php echo htmlspecialchars($tenant['previous_residence'] ?? 'N/A'); ?></p>
            <?php if (!empty($tenant['additional_info'])): ?>
                <p><strong>Additional Info:</strong> <?php echo nl2br(htmlspecialchars($tenant['additional_info'])); ?></p>
            <?php endif; ?>
            <p><strong>Current Status:</strong> 
                <span class="status-badge status-<?php echo htmlspecialchars($tenant['status'] ?? 'pending'); ?>">
                    <?php echo htmlspecialchars($tenant['status'] ?? 'Pending'); ?>
                </span>
            </p>
        </div>

        <div class="decision-btns">
            <form method="post" action="">
                <button type="submit" name="approve" class="btn btn-approve">✅ Approve</button>
                <button type="submit" name="reject" class="btn btn-reject">❌ Reject</button>
            </form>
        </div>
        
        <div class="ratings">
            <h3>Ratings & Comments from Previous Landlords</h3>
            <?php if ($ratings->num_rows > 0): ?>
                <?php while ($r = $ratings->fetch_assoc()): ?>
                    <div class="rating-card">
                        <p><strong>Landlord:</strong> <?php echo htmlspecialchars($r['landlord_name']); ?></p>
                        <p><strong>Rating:</strong> <?php echo htmlspecialchars($r['rating']); ?>/5</p>
                        <p><strong>Review:</strong> <?php echo nl2br(htmlspecialchars($r['review'])); ?></p>
                        <p><small>Date: <?php echo date('M j, Y', strtotime($r['created_at'])); ?></small></p>
                    </div>
                <?php endwhile; ?>
            <?php else: ?>
                <p>No ratings or comments available.</p>
            <?php endif; ?>
        </div>
    </div>
</body>
</html>