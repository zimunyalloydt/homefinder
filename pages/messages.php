<?php
session_start();
include(__DIR__ . '/../config/db_connect.php');

// Redirect if not logged in
if (!isset($_SESSION['user_id'])) {
    header("Location: login.php");
    exit();
}

$currentUserId = $_SESSION['user_id'];
$currentUserRole = $_SESSION['role']; // 'tenant' or 'landlord'
$chatWith = isset($_GET['chat_with']) ? intval($_GET['chat_with']) : 0;

// --- Send message ---
if ($_SERVER['REQUEST_METHOD'] === 'POST' && !empty($_POST['message_text']) && $chatWith > 0) {
    $msg = trim($_POST['message_text']);
    $stmt = $conn->prepare("INSERT INTO messages (sender_id, receiver_id, message_text) VALUES (?, ?, ?)");
    $stmt->bind_param("iis", $currentUserId, $chatWith, $msg);
    $stmt->execute();
    header("Location: messages.php?chat_with=" . $chatWith);
    exit();
}

// --- Load conversations ---
if ($currentUserRole === 'tenant') {
    // Tenants see landlords
    $sql = "
        SELECT u.user_id, u.full_name, 
               (SELECT message_text FROM messages 
                WHERE (sender_id=u.user_id AND receiver_id=?) OR (receiver_id=u.user_id AND sender_id=?) 
                ORDER BY created_at DESC LIMIT 1) AS last_message,
               (SELECT created_at FROM messages 
                WHERE (sender_id=u.user_id AND receiver_id=?) OR (receiver_id=u.user_id AND sender_id=?) 
                ORDER BY created_at DESC LIMIT 1) AS last_time,
               (SELECT COUNT(*) FROM messages 
                WHERE receiver_id=? AND sender_id=u.user_id AND is_read=0) AS unread_count
        FROM users u
        WHERE u.role='landlord' AND u.user_id != ?
        ORDER BY last_time DESC
    ";
} else {
    // Landlords see tenants
    $sql = "
        SELECT u.user_id, u.full_name, 
               (SELECT message_text FROM messages 
                WHERE (sender_id=u.user_id AND receiver_id=?) OR (receiver_id=u.user_id AND sender_id=?) 
                ORDER BY created_at DESC LIMIT 1) AS last_message,
               (SELECT created_at FROM messages 
                WHERE (sender_id=u.user_id AND receiver_id=?) OR (receiver_id=u.user_id AND sender_id=?) 
                ORDER BY created_at DESC LIMIT 1) AS last_time,
               (SELECT COUNT(*) FROM messages 
                WHERE receiver_id=? AND sender_id=u.user_id AND is_read=0) AS unread_count
        FROM users u
        WHERE u.role='tenant' AND u.user_id != ?
        ORDER BY last_time DESC
    ";
}

$stmt = $conn->prepare($sql);
$stmt->bind_param("iiiiii", $currentUserId, $currentUserId, $currentUserId, $currentUserId, $currentUserId, $currentUserId);
$stmt->execute();
$convos = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);

// --- Load messages with selected user ---
$messages = [];
$chatPartnerName = "";
if ($chatWith > 0) {
    $stmt = $conn->prepare("SELECT full_name FROM users WHERE user_id = ?");
    $stmt->bind_param("i", $chatWith);
    $stmt->execute();
    $result = $stmt->get_result();
    $chatPartner = $result->fetch_assoc();
    $chatPartnerName = $chatPartner['full_name'] ?? "";

    $sql = "SELECT * FROM messages 
            WHERE (sender_id=? AND receiver_id=?) OR (sender_id=? AND receiver_id=?)
            ORDER BY created_at ASC";
    $stmt = $conn->prepare($sql);
    $stmt->bind_param("iiii", $currentUserId, $chatWith, $chatWith, $currentUserId);
    $stmt->execute();
    $messages = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);

    // Mark as read
    $updateStmt = $conn->prepare("UPDATE messages SET is_read=1 WHERE sender_id=? AND receiver_id=? AND is_read=0");
    $updateStmt->bind_param("ii", $chatWith, $currentUserId);
    $updateStmt->execute();
}

// --- Get available users to chat with ---
if ($currentUserRole === 'tenant') {
    $availableUsersSql = "SELECT user_id, full_name FROM users WHERE role='landlord' ORDER BY full_name";
} else {
    $availableUsersSql = "SELECT user_id, full_name FROM users WHERE role='tenant' ORDER BY full_name";
}
$availableUsersStmt = $conn->prepare($availableUsersSql);
$availableUsersStmt->execute();
$availableUsers = $availableUsersStmt->get_result()->fetch_all(MYSQLI_ASSOC);
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<title>HomeFinder Messaging</title>
<link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
<link rel="stylesheet" href="/../css/messages.css">
</head>
<body>
<div class="header">
    <div class="logo"><i class="fas fa-home"></i> <span>HomeFinder</span></div>
    <div class="user-info">
        <div class="user-avatar"><?php echo strtoupper(substr($_SESSION['full_name'],0,2)); ?></div>
        <div class="user-name"><?php echo htmlspecialchars($_SESSION['full_name'] . " (" . ucfirst($_SESSION['role']) . ")"); ?></div>
    </div>
</div>

<div class="container">
    <div class="sidebar">
        <div class="sidebar-header">
            <div class="sidebar-title">Messages</div>
            <button class="new-chat-btn" onclick="openNewChatModal()"><i class="fas fa-edit"></i></button>
        </div>
        <div class="search-container">
            <div class="search-box">
                <i class="fas fa-search"></i>
                <input type="text" placeholder="Search messages..." onkeyup="filterConversations()">
            </div>
        </div>
        <div class="conversation-list">
            <?php foreach ($convos as $c): 
                $initials = strtoupper(substr($c['full_name'],0,2));
                $lastTime = !empty($c['last_time']) ? date("g:i A", strtotime($c['last_time'])) : '';
            ?>
            <div class="conversation <?php echo $chatWith==$c['user_id']?'active':''; ?>" 
                 data-user-id="<?php echo $c['user_id']; ?>" 
                 onclick="location.href='messages.php?chat_with=<?php echo $c['user_id']; ?>'">
                <div class="conversation-avatar"><?php echo $initials; ?></div>
                <div class="conversation-info">
                    <div class="conversation-name"><?php echo htmlspecialchars($c['full_name']); ?></div>
                    <div class="conversation-preview"><?php echo htmlspecialchars($c['last_message'] ?? 'No messages yet'); ?></div>
                </div>
                <div class="conversation-meta">
                    <div class="conversation-time"><?php echo $lastTime; ?></div>
                    <?php if($c['unread_count']>0): ?>
                    <div class="unread-count"><?php echo $c['unread_count']; ?></div>
                    <?php endif; ?>
                </div>
            </div>
            <?php endforeach; ?>
        </div>
    </div>

    <div class="chat-container">
        <?php if($chatWith>0): ?>
        <div class="chat-header">
            <div class="conversation-avatar"><?php echo strtoupper(substr($chatPartnerName,0,2)); ?></div>
            <div class="chat-recipient"><?php echo htmlspecialchars($chatPartnerName); ?></div>
        </div>
        <div class="chat-messages" id="chat-messages">
            <?php foreach($messages as $m):
                $isSent = $m['sender_id']==$currentUserId;
                $time = date("g:i A", strtotime($m['created_at']));
            ?>
            <div class="message <?php echo $isSent?'sent':'received'; ?>">
                <div class="message-content">
                    <p><?php echo htmlspecialchars($m['message_text']); ?></p>
                    <div class="message-time"><?php echo $time; ?></div>
                </div>
            </div>
            <?php endforeach; ?>
        </div>
        <form method="POST" class="chat-input">
            <div class="message-input">
                <input type="text" name="message_text" placeholder="Type a message..." required>
            </div>
            <button type="submit" class="send-btn"><i class="fas fa-paper-plane"></i></button>
        </form>
        <?php else: ?>
        <div class="empty-chat"><p>Select a conversation to start chatting</p></div>
        <?php endif; ?>
    </div>
</div>

<!-- New Chat Modal -->
<div id="newChatModal" class="modal">
    <div class="modal-content">
        <div class="modal-header">
            <h3>Start New Conversation</h3>
            <span class="close" onclick="closeNewChatModal()">&times;</span>
        </div>
        <div class
