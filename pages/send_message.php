<?php
include(__DIR__ . '/../config/db_connect.php');
session_start();




if (!isset($_SESSION['user_id'])) {
    http_response_code(403);
    echo json_encode(["status" => "error", "message" => "Not logged in"]);
    exit();
}

$sender_id = $_SESSION['user_id'];

$data = json_decode(file_get_contents("php://input"), true);

if (!isset($data['receiver_id']) || !isset($data['message_text'])) {
    http_response_code(400);
    echo json_encode(["status" => "error", "message" => "Missing fields"]);
    exit();
}


$receiver_id = intval($data['receiver_id']);
$message_text = trim($data['message_text']);

if (!isset($_SESSION['user_id'])) exit;

$sender_id = $_SESSION['user_id'];
$receiver_id = intval($_GET['receiver_id']);
$message_text = trim($_POST['message_text']);

if (!empty($message_text)) {
    $stmt = $conn->prepare("INSERT INTO messages (sender_id, receiver_id, message_text, created_at) VALUES (?, ?, ?, NOW())");
    $stmt->bind_param("iis", $sender_id, $receiver_id, $message_text);
    $stmt->execute();
    $stmt->close();
}

if ($receiver_id > 0 && $message_text !== "") {
    $stmt = $conn->prepare("INSERT INTO messages (sender_id, receiver_id, message_text, created_at, is_read)
                            VALUES (?, ?, ?, NOW(), 0)");
    $stmt->bind_param("iis", $sender_id, $receiver_id, $message_text);
    $stmt->execute();
    $stmt->close();

    echo json_encode(["status" => "success", "message" => "Message sent"]);
} else {
    http_response_code(400);
    echo json_encode(["status" => "error", "message" => "Invalid input"]);
}

if (!isset($_SESSION['user_id']) || $_SESSION['role'] !== 'landlord') {
    header("Location: login.php");
    exit();
}
$landlord_id = $_SESSION['user_id'];

// ✅ Handle sending a message
if (isset($_POST['send_message'])) {
    $receiver_id = intval($_POST['receiver_id']);
    $message_text = trim($_POST['message_text']);

    if (!empty($receiver_id) && !empty($message_text)) {
        $stmt = $conn->prepare("INSERT INTO messages (sender_id, receiver_id, message_text, created_at, is_read) 
                                VALUES (?, ?, ?, NOW(), 0)");
        $stmt->bind_param("iis", $landlord_id, $receiver_id, $message_text);
        $stmt->execute();
        $stmt->close();

        // Refresh chat so message shows immediately
        header("Location: messages.php?chat_with=" . $receiver_id);
        exit();
    }

}

if ($_SERVER["REQUEST_METHOD"] === "POST") {
    if (!isset($_SESSION['user_id'])) {
        header("Location: login.php");
        exit();
    }

   
    }

header("Location: chat.php?user=" . $receiver_id);
exit();


?>
