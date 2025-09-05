<?php
include(__DIR__ . '/../config/db_connect.php');
session_start();

if (!isset($_SESSION['user_id'])) {
    exit("Not logged in");
}

$current_user = $_SESSION['user_id'];
$chat_user = isset($_GET['user_id']) ? intval($_GET['user_id']) : 0;

if ($chat_user <= 0) {
    exit("Invalid chat user");
}

// Fetch messages
$stmt = $conn->prepare("
    SELECT m.id, m.sender_id, m.receiver_id, m.message_text, m.created_at,
           u.full_name AS sender_name
    FROM messages m
    JOIN users u ON m.sender_id = u.user_id
    WHERE (m.sender_id = ? AND m.receiver_id = ?)
       OR (m.sender_id = ? AND m.receiver_id = ?)
    ORDER BY m.created_at ASC
");
$stmt->bind_param("iiii", $current_user, $chat_user, $chat_user, $current_user);
$stmt->execute();
$result = $stmt->get_result();

// Output chat header
?>
<div class="chat-header">
    <h3>
        <?php
        $userStmt = $conn->prepare("SELECT full_name FROM users WHERE user_id = ?");
        $userStmt->bind_param("i", $chat_user);
        $userStmt->execute();
        $userStmt->bind_result($chat_name);
        $userStmt->fetch();
        $userStmt->close();
        echo htmlspecialchars($chat_name);
        ?>
    </h3>
    <span id="online-status-<?php echo $chat_user; ?>"></span>
</div>

<div class="chat-body" id="chat-body">
    <?php while ($msg = $result->fetch_assoc()): ?>
        <?php 
            $isSent = $msg['sender_id'] == $current_user;
            $bubbleClass = $isSent ? 'sent' : 'received';
            $time = date("H:i", strtotime($msg['created_at']));
        ?>
        <div class="chat-bubble <?php echo $bubbleClass; ?>" data-msgid="<?php echo $msg['id']; ?>">
            <p><?php echo nl2br(htmlspecialchars($msg['message_text'])); ?></p>
            <div class="chat-meta">
                <span class="chat-time"><?php echo $time; ?></span>
                <?php if ($isSent): ?>
                    <span class="message-status"><i class="fas fa-check"></i></span>
                <?php endif; ?>
            </div>
        </div>
    <?php endwhile; ?>
</div>

<!-- Chat Input -->
<form id="chatForm" class="chat-input-form">
    <input type="hidden" name="receiver_id" id="receiver_id" value="<?php echo $chat_user; ?>">
    <textarea name="message_text" id="message_text" placeholder="Type a message..."
              oninput="autoResize(this)"></textarea>
    <button type="submit"><i class="fas fa-paper-plane"></i></button>
</form>
