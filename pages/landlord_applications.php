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

// Handle approve/reject actions
if (isset($_POST['action']) && isset($_POST['applications_id'])) {
    $application_id = intval($_POST['applications_id']);
    if ($_POST['action'] === 'approve' || $_POST['action'] === 'reject') {
        $status = ($_POST['action'] === 'approve') ? 'Approved' : 'Rejected';
        $stmt = $conn->prepare("UPDATE applications SET status = ? WHERE applications_id = ?");
        $stmt->bind_param("si", $status, $application_id);
        if ($stmt->execute()) {
            $message = "✅ Application $status successfully!";
        } else {
            $message = "❌ Error: " . $conn->error;
        }
    }
}

/*Fetch applications for landlord’s properties
$query = "SELECT a.applications_id, a.status, a.date_applied,
                 p.title AS property_title,
                 u.user_id AS tenant_id, u.full_name AS tenant_name
          FROM applications a
          JOIN properties p ON a.property_id = p.property_id
          JOIN users u ON a.tenant_id = u.user_id
          WHERE p.landlord_id = ?
          ORDER BY a.date_applied DESC";*/

         $query = "SELECT a.applications_id, a.status, a.date_applied,
                 p.property_id,
                 p.title AS property_title,
                 u.user_id AS tenant_id, u.full_name AS tenant_name,
                 (SELECT ROUND(AVG(rating),1) FROM tenant_ratings tr WHERE tr.tenant_id = u.user_id) AS avg_rating,
                 (SELECT COUNT(*) FROM tenant_ratings tr WHERE tr.tenant_id = u.user_id) AS rating_count
          FROM applications a
          JOIN properties p ON a.property_id = p.property_id
          JOIN users u ON a.tenant_id = u.user_id
          WHERE p.landlord_id = ?
          ORDER BY a.date_applied DESC";


$stmt = $conn->prepare($query);
$stmt->bind_param("i", $landlord_id);
$stmt->execute();
$applications = $stmt->get_result();
?>

<!DOCTYPE html>
<html>
<head>
    <title>Landlord Applications - HomeFinder</title>
    <link rel="stylesheet" href="../css/applications.css">
    <style>
        .badge-pending { background: orange; color: white; padding: 5px; border-radius: 5px; }
        .badge-approved { background: green; color: white; padding: 5px; border-radius: 5px; }
        .badge-rejected { background: red; color: white; padding: 5px; border-radius: 5px; }
    </style>
</head>
<body>
<h2>Tenant Applications</h2>
<div class="nav-links">
    <a href="dashboard_landlord.php">⬅ Back to Dashboard</a>
    <a href="logout.php">🚪 Logout</a>
</div>

<?php if ($message) echo "<p>$message</p>"; ?>

<?php if ($applications->num_rows > 0): ?>
    <table border="1" cellpadding="10">
        <tr>
            <th>Property</th>
            <th>Tenant</th>
            <th>Status</th>
            <th>Applied On</th>
            <th>Action / Rate</th>
        </tr>
        <?php while ($row = $applications->fetch_assoc()): ?>
            
                  
                   

            <tr>
    <td><?php echo htmlspecialchars($row['property_title']); ?></td>
    <td>
        <?php echo htmlspecialchars($row['tenant_name']); ?><br>
        <?php if ($row['avg_rating']): ?>
            ⭐ <?php echo $row['avg_rating']; ?> / 5 (<?php echo $row['rating_count']; ?> reviews)
        <?php else: ?>
            <em>No ratings yet</em>
        <?php endif; ?>
    </td>
    <td>
        <?php 
            $status = strtolower(trim($row['status']));
            if ($status === 'approved') {
                echo '<span class="badge-approved">Approved</span>';
            } elseif ($status === 'rejected') {
                echo '<span class="badge-rejected">Rejected</span>';
            } else {
                echo '<span class="badge-pending">Pending</span>';
            }
        ?>
    </td>
    <td><?php echo $row['date_applied']; ?></td>
    <td>
        <?php if (empty($row['status']) || strtolower($row['status']) === 'pending'): ?>
            <form method="POST" style="display:inline;">
                <input type="hidden" name="applications_id" value="<?php echo $row['applications_id']; ?>">
                <button type="submit" name="action" value="approve">✅ Approve</button>
                <button type="submit" name="action" value="reject">❌ Reject</button>
            </form>
        <?php else: ?>
            <!-- Show rating form -->
            <form method="POST" action="rate_tenant.php">
                <input type="hidden" name="tenant_id" value="<?php echo $row['tenant_id']; ?>">
                <label>Stars (1-5):</label>
                <input type="number" name="stars" min="1" max="5" required>
                <label>Review:</label>
                <textarea name="review"></textarea>
                <button type="submit">Rate Tenant</button>
            </form>
        <?php endif; ?>
    </td>
</tr>

        <?php endwhile; ?>
    </table>
<?php else: ?>
    <p>No applications found for your properties.</p>
<?php endif; ?>
</body>
</html>
