<?php
// Get unread notifications for this admin
$notifications = $pdo->prepare("
    SELECT n.*, fr.id AS request_id 
    FROM notifications n
    LEFT JOIN freight_requests fr ON n.related_request_id = fr.id
    WHERE (n.user_id = ? OR (n.user_id IS NULL AND metadata->>'target_audience' = 'admins'))
    AND n.is_read = FALSE
    ORDER BY n.created_at DESC
    LIMIT 5
");
$notifications->execute([$admin_id]);
$notifications = $notifications->fetchAll();

// Helper function for time display
function time_elapsed_string($datetime, $full = false) {
    $now = new DateTime;
    $ago = new DateTime($datetime);
    $diff = $now->diff($ago);

    $string = array(
        'y' => 'an',
        'm' => 'mois',
        'd' => 'jour',
        'h' => 'heure',
        'i' => 'minute',
        's' => 'seconde',
    );
    
    foreach ($string as $k => $v) {
        if ($diff->$k) {
            $parts[] = $diff->$k . ' ' . $v . ($diff->$k > 1 ? 's' : '');
        }
    }

    if (!$full) $parts = array_slice($parts, 0, 1);
    return $parts ? implode(', ', $parts) . ' il y a' : 'à l\'instant';
}
?>

<!-- Notification dropdown -->
<div class="dropdown me-3">
    <a class="nav-link dropdown-toggle position-relative p-2" href="#" id="notifDropdown" 
       role="button" data-bs-toggle="dropdown" aria-expanded="false">
        <i class="fas fa-bell fa-lg"></i>
        <?php if (count($notifications) > 0): ?>
            <span class="position-absolute top-0 start-100 translate-middle badge rounded-pill bg-danger">
                <?= count($notifications) ?>
                <span class="visually-hidden">unread notifications</span>
            </span>
        <?php endif; ?>
    </a>
    <ul class="dropdown-menu dropdown-menu-end border-0 shadow" aria-labelledby="notifDropdown" style="width: 350px;">
        <li class="dropdown-header bg-light py-2 px-3 d-flex justify-content-between align-items-center border-bottom">
            <strong class="text-primary">Notifications</strong>
            <a href="admin-notifications.php" class="btn btn-sm btn-outline-primary">Voir tout</a>
        </li>
        <?php if (empty($notifications)): ?>
            <li class="py-4 px-3 text-center text-muted">
                <i class="fas fa-bell-slash fa-2x mb-2 opacity-50"></i>
                <div>Aucune nouvelle notification</div>
            </li>
        <?php else: ?>
            <div style="max-height: 400px; overflow-y: auto;">
                <?php foreach ($notifications as $notif): ?>
                    <?php
                        // Default link for notifications with no specific action
                        $link = 'javascript:void(0);';
                        $request_id = $notif['related_request_id'] ?? null;
                        
                        // Set links based on notification type
                        switch ($notif['type']) {
                            case 'client_confirmed':
                            case 'client_rejected':
                            case 'request_rejected':
                            case 'request_approved':
                            case 'request_accepted':
                            case 'nouvelle_demande':
                                $link = "admin-request-details.php?id=" . (int)$request_id;
                                break;
                            case 'contract_draft':
                            case 'new_contract_draft':
                                $link = "create_contract.php?request_id=" . (int)$request_id;
                                break;
                            case 'contract_completed':
                                $link = "admin-contract-details.php?id=" . (int)$request_id;
                                break;
                        }
                        
                        $timeAgo = time_elapsed_string($notif['created_at']);
                        $metadata = isset($notif['metadata']) ? json_decode($notif['metadata'], true) : [];
                    ?>
                    <li>
                        <a class="dropdown-item p-3 border-bottom <?= $notif['is_read'] ? '' : 'bg-light' ?>" 
                           href="<?= htmlspecialchars($link) ?>"
                           <?= ($request_id && $request_id > 0) ? '' : 'onclick="return false;"' ?>>
                            <div class="d-flex">
                                <div class="flex-shrink-0 me-3">
                                    <?php switch($notif['type']) {
                                        case 'nouvelle_demande': ?>
                                            <i class="fas fa-file-alt text-primary"></i>
                                            <?php break;
                                        case 'client_confirmed':
                                        case 'request_approved':
                                        case 'request_accepted': ?>
                                            <i class="fas fa-check-circle text-success"></i>
                                            <?php break;
                                        case 'client_rejected':
                                        case 'request_rejected': ?>
                                            <i class="fas fa-times-circle text-danger"></i>
                                            <?php break;
                                        case 'contract_draft':
                                        case 'new_contract_draft': ?>
                                            <i class="fas fa-file-contract text-info"></i>
                                            <?php break;
                                        case 'contract_completed': ?>
                                            <i class="fas fa-file-signature text-success"></i>
                                            <?php break;
                                        default: ?>
                                            <i class="fas fa-bell text-warning"></i>
                                    <?php } ?>
                                </div>
                                <div class="flex-grow-1">
                                    <div class="d-flex justify-content-between">
                                        <strong><?= htmlspecialchars($notif['title']) ?></strong>
                                        <small class="text-muted"><?= htmlspecialchars($timeAgo) ?></small>
                                    </div>
                                    <div class="text-muted small"><?= htmlspecialchars($notif['message']) ?></div>
                                    <?php if (!empty($metadata)): ?>
                                        <div class="mt-1">
                                            <?php if (isset($metadata['price'])): ?>
                                                <small class="text-success"><?= htmlspecialchars($metadata['price']) ?> €</small>
                                            <?php endif; ?>
                                            <?php if (isset($metadata['wagon_count'])): ?>
                                                <small class="text-primary ms-2"><?= htmlspecialchars($metadata['wagon_count']) ?> wagons</small>
                                            <?php endif; ?>
                                        </div>
                                    <?php endif; ?>
                                </div>
                            </div>
                        </a>
                    </li>
                <?php endforeach; ?>
            </div>
        <?php endif; ?>
        <li class="dropdown-footer bg-light py-2 px-3 text-center border-top">
            <small class="text-muted"><?= count($notifications) ?> notification(s) non lue(s)</small>
        </li>
    </ul>
</div> 