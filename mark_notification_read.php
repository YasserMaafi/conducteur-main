<?php
require_once 'includes/config.php';
require_once 'includes/auth_functions.php';


if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['id'])) {
    $notification_id = (int)$_POST['id'];
    $user_id = $_SESSION['user']['id'];
    
    try {
        // Mark notification as read if:
        // 1. It belongs to this user, OR
        // 2. It's a system notification and user is admin
        $stmt = $pdo->prepare("
            UPDATE notifications 
            SET is_read = TRUE 
            WHERE id = ? AND (
                user_id = ? OR 
                (user_id IS NULL AND ? IN (SELECT user_id FROM admins))
            )
        ");
        $stmt->execute([$notification_id, $user_id, $user_id]);
        
        if ($stmt->rowCount() > 0) {
            echo json_encode(['success' => true]);
        } else {
            echo json_encode(['success' => false, 'error' => 'Notification not found or access denied']);
        }
    } catch (PDOException $e) {
        header('HTTP/1.1 500 Internal Server Error');
        echo json_encode(['success' => false, 'error' => $e->getMessage()]);
    }
} else {
    header('HTTP/1.1 400 Bad Request');
    echo json_encode(['success' => false, 'error' => 'Invalid request']);
}