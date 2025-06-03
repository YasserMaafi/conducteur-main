<?php
require_once 'includes/config.php';
require_once 'includes/auth_functions.php';

function createNotification($user_id, $type, $title, $message, $related_request_id = null, $metadata = []) {
    global $pdo;
    
    // Add target_audience to metadata if user_id is null
    if ($user_id === null && !isset($metadata['target_audience'])) {
        $metadata['target_audience'] = 'admins';
    }

    $stmt = $pdo->prepare("
        INSERT INTO notifications 
        (user_id, type, title, message, metadata, related_request_id)
        VALUES (?, ?, ?, ?, ?::jsonb, ?)
    ");
    
    $stmt->execute([
        $user_id,
        $type,
        $title,
        $message,
        json_encode($metadata),
        $related_request_id
    ]);
    
    return $pdo->lastInsertId();
}

function getUnreadNotifications($user_id, $limit = 5) {
    global $pdo;
    
    $stmt = $pdo->prepare("
        SELECT id, type, title, message, metadata, 
               related_request_id, created_at
        FROM notifications 
        WHERE user_id = ? AND is_read = FALSE
        ORDER BY created_at DESC
        LIMIT ?
    ");
    $stmt->execute([$user_id, $limit]);
    
    return $stmt->fetchAll();
}

function markAsRead($notification_id) {
    global $pdo;
    
    $stmt = $pdo->prepare("
        UPDATE notifications SET is_read = TRUE 
        WHERE id = ?
    ");
    return $stmt->execute([$notification_id]);
}

function getUnreadNotificationsAgent($pdo, $user_id) {
    $stmt = $pdo->prepare("
        SELECT n.*, 
               CASE 
                   WHEN n.type = 'contract_draft' THEN 'Nouveau contrat à compléter'
                   WHEN n.type = 'arrival' THEN 'Arrivée d''expédition'
                   WHEN n.type = 'payment_confirmation' THEN 'Confirmation de paiement'
                   ELSE n.title 
               END as display_title,
               CASE 
                   WHEN n.type = 'contract_draft' THEN 'fas fa-file-contract'
                   WHEN n.type = 'arrival' THEN 'fas fa-truck'
                   WHEN n.type = 'payment_confirmation' THEN 'fas fa-money-bill-wave'
                   ELSE 'fas fa-bell'
               END as icon_class
        FROM notifications n
        WHERE n.user_id = ? AND n.is_read = false
        ORDER BY n.created_at DESC
        LIMIT 5
    ");
    $stmt->execute([$user_id]);
    return $stmt->fetchAll();
}
function markNotificationAsRead($pdo, $notification_id) {
    $stmt = $pdo->prepare("
        UPDATE notifications 
        SET is_read = true 
        WHERE id = ?
    ");
    return $stmt->execute([$notification_id]);
}