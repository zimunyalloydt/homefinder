<?php
include(__DIR__ . '/../config/db_connect.php');
session_start();

if (!isset($_SESSION['user_id'])) {
    exit("Not logged in");
}

$current_user_id = $_SESSION['user_id'];
$other_user_id = intval($_GET['user_id']);

// Fetch messages
$stmt = $conn->prepare("
    SELECT m.*, u.full_name AS sender_name
    FROM messages m
    JOIN users u ON m.sender_id = u.user_id
    WHERE (m.sender_id = ? AND m.receiver_id = ?)
       OR (m.sender_id = ? AND m.receiver_id = ?)
    ORDER BY m.created_at ASC
");
$stmt->bind_param("iiii", $current_user_id, $other_user_id, $other_user_id, $current_user_id);
$stmt->execute();
$result = $stmt->get_result();

// Render chat
echo '<div class="chat-messages">';
if ($result->num_rows > 0) {
    while ($row = $result->fetch_assoc()) {
        $class = $row['sender_id'] == $current_user_id ? "outgoing" : "incoming";
        echo "<div class='message $class'>";
        echo "<strong>" . htmlspecialchars($row['sender_name']) . ":</strong> ";
        echo htmlspecialchars($row['message_text']);
        echo "<br><small>" . date("M j, H:i", strtotime($row['created_at'])) . "</small>";
        echo "</div>";
    }
} else {
    echo "<p>No messages yet. Start the conversation!</p>";
}
echo '</div>';

// Input box
echo "<form onsubmit='sendMessage(event, $other_user_id)'>
        <textarea name='message_text' required></textarea>
        <button type='submit'>Send</button>
      </form>";
error_reporting(E_ALL);
ini_set('display_errors', 1);
