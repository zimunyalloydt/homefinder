<?php
include(__DIR__ . '/../config/db_connect.php');
session_start();

if (!isset($_SESSION['user_id'])) {
    header("Location: login.php");
    exit();
}

$current_user = $_SESSION['user_id'];

// Handle reply submission
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['reply_message'], $_POST['receiver_id'])) {
    $receiver_id = intval($_POST['receiver_id']);
    $reply_message = trim($_POST['reply_message']);

    if (!empty($reply_message)) {
        $insert_stmt = $conn->prepare("
            INSERT INTO messages (sender_id, receiver_id, message_text, created_at, is_read) 
            VALUES (?, ?, ?, NOW(), 0)
        ");
        $insert_stmt->bind_param("iis", $current_user, $receiver_id, $reply_message);
        $insert_stmt->execute();
    }

    // Refresh to show updated conversations
    header("Location: messages.php");
    exit();
}

// First, let's get all users that the current user has conversed with
$stmt = $conn->prepare("
    SELECT DISTINCT 
        CASE 
            WHEN sender_id = ? THEN receiver_id 
            ELSE sender_id 
        END as other_user_id
    FROM messages 
    WHERE sender_id = ? OR receiver_id = ?
");
$stmt->bind_param("iii", $current_user, $current_user, $current_user);
$stmt->execute();
$conversation_partners = $stmt->get_result();

$conversations = [];
while ($partner = $conversation_partners->fetch_assoc()) {
    $other_user_id = $partner['other_user_id'];
    
    // Get the other user's name
    $user_stmt = $conn->prepare("SELECT full_name FROM users WHERE user_id = ?");
    $user_stmt->bind_param("i", $other_user_id);
    $user_stmt->execute();
    $user_result = $user_stmt->get_result();
    $other_user_name = $user_result->fetch_assoc()['full_name'];
    
    // Get the last message
    $msg_stmt = $conn->prepare("
        SELECT message_text, created_at 
        FROM messages 
        WHERE (sender_id = ? AND receiver_id = ?) 
           OR (sender_id = ? AND receiver_id = ?)
        ORDER BY created_at DESC 
        LIMIT 1
    ");
    $msg_stmt->bind_param("iiii", $current_user, $other_user_id, $other_user_id, $current_user);
    $msg_stmt->execute();
    $msg_result = $msg_stmt->get_result();
    $last_message = $msg_result->fetch_assoc();
    
    // Get unread count
    $unread_stmt = $conn->prepare("
        SELECT COUNT(*) as unread_count 
        FROM messages 
        WHERE sender_id = ? 
          AND receiver_id = ? 
          AND is_read = 0
    ");
    $unread_stmt->bind_param("ii", $other_user_id, $current_user);
    $unread_stmt->execute();
    $unread_result = $unread_stmt->get_result();
    $unread_count = $unread_result->fetch_assoc()['unread_count'];
    
    $conversations[] = [
        'other_user_id' => $other_user_id,
        'other_user_name' => $other_user_name,
        'last_message' => $last_message['message_text'] ?? '',
        'last_time' => $last_message['created_at'] ?? '',
        'unread_count' => $unread_count
    ];
}

// Sort conversations by last message time
usort($conversations, function($a, $b) {
    return strtotime($b['last_time']) - strtotime($a['last_time']);
});
?>
<!DOCTYPE html>
<html>
<head>
    <title>Messages</title>
    <style>
        body { font-family: Arial, sans-serif; background:#f8f9fa; margin:0; padding:20px; }
        .container { max-width:600px; margin:auto; background:#fff; padding:20px; border-radius:10px; box-shadow:0 0 10px rgba(0,0,0,0.1); }
        h2 { text-align:center; margin-bottom:20px; }
        .conversation { padding:10px; border-bottom:1px solid #ddd; }
        .conversation-header { display:flex; justify-content:space-between; align-items:center; cursor:pointer; }
        .conversation-header:hover { background:#f1f1f1; }
        .user { font-weight:bold; }
        .last-message { color:#666; font-size:14px; }
        .time { font-size:12px; color:#aaa; }
        .badge { background:red; color:white; padding:2px 6px; border-radius:50%; font-size:12px; }
        .reply-box { margin-top:10px; display:flex; gap:5px; }
        .reply-box input { flex:1; padding:5px; border:1px solid #ccc; border-radius:5px; }
        .reply-box button { padding:5px 10px; background:#007bff; color:white; border:none; border-radius:5px; cursor:pointer; }
        .reply-box button:hover { background:#0056b3; }
    </style>
</head>
<body>
    <div class="container">
        <h2>Your Conversations</h2>
        <?php if (count($conversations) > 0): ?>
            <?php foreach ($conversations as $conv): ?>
                <div class="conversation">
                    <div class="conversation-header" onclick="window.location.href='chat.php?user=<?php echo $conv['other_user_id']; ?>'">
                        <div>
                            <div class="user"><?php echo htmlspecialchars($conv['other_user_name']); ?></div>
                            <div class="last-message"><?php echo htmlspecialchars($conv['last_message']); ?></div>
                        </div>
                        <div>
                            <div class="time"><?php echo !empty($conv['last_time']) ? date('M j, g:i a', strtotime($conv['last_time'])) : ''; ?></div>
                            <?php if ($conv['unread_count'] > 0): ?>
                                <span class="badge"><?php echo $conv['unread_count']; ?></span>
                            <?php endif; ?>
                        </div>
                    </div>
                    <!-- Reply Form -->
                    <form method="POST" class="reply-box">
                        <input type="hidden" name="receiver_id" value="<?php echo $conv['other_user_id']; ?>">
                        <input type="text" name="reply_message" placeholder="Type a reply..." required>
                        <button type="submit">Send</button>
                    </form>
                </div>
            <?php endforeach; ?>
        <?php else: ?>
            <p>No conversations yet.</p>
        <?php endif; ?>
    </div>
</body>
</html>
