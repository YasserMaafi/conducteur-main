<?php
// Start session if not already started
if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

require_once 'includes/config.php';
require_once 'includes/auth_functions.php';

if (!isLoggedIn() || $_SESSION['user']['role'] !== 'admin') {
    header('Location: login.php');
    exit();
}

$admin_id = $_SESSION['user']['id'];

// Get unread notifications for this admin (both personal and system-wide)
$stmt = $pdo->prepare("
    SELECT n.*, fr.id AS request_id 
    FROM notifications n
    LEFT JOIN freight_requests fr ON n.related_request_id = fr.id
    WHERE (n.user_id = ? OR (n.user_id IS NULL AND metadata->>'target_audience' = 'admins'))
    AND n.is_read = FALSE
    ORDER BY n.created_at DESC
    LIMIT 5
");
$stmt->execute([$admin_id]);
$unread_notifications = $stmt->fetchAll(PDO::FETCH_ASSOC);
?>

<!-- Admin Navigation Bar -->
<nav class="navbar navbar-expand-lg navbar-dark bg-primary fixed-top">
    <div class="container-fluid px-3">
        <a class="navbar-brand" href="admin-dashboard.php">
            <i class="fas fa-train me-2"></i>SNCFT Admin
        </a>
        
        <div class="d-flex align-items-center">
            <!-- Notification Dropdown -->
            <div class="dropdown me-3">
                <a class="nav-link dropdown-toggle position-relative" href="#" id="notificationDropdown" role="button" data-bs-toggle="dropdown">
                    <i class="fas fa-bell"></i>
                    <?php if (count($unread_notifications) > 0): ?>
                        <span class="position-absolute top-0 start-100 translate-middle badge rounded-pill bg-danger">
                            <?= count($unread_notifications) ?>
                        </span>
                    <?php endif; ?>
                </a>
                <ul class="dropdown-menu dropdown-menu-end" aria-labelledby="notificationDropdown">
                    <li class="dropdown-header">
                        <strong>Notifications</strong>
                        <a href="admin-notifications.php" class="btn btn-sm btn-outline-primary float-end">Voir tout</a>
                    </li>
                    
                    <?php if (empty($unread_notifications)): ?>
                        <li class="px-3 py-2 text-muted">Aucune nouvelle notification</li>
                    <?php else: ?>
                        <?php foreach ($unread_notifications as $notif): ?>
                            <?php
                            $link = '#';
                            if ($notif['related_request_id']) {
                                switch ($notif['type']) {
                                    case 'client_confirmed':
                                        $link = "create_contract.php?request_id=".$notif['related_request_id'];
                                        break;
                                    case 'nouvelle_demande':
                                        $link = "request_details.php?id=".$notif['related_request_id'];
                                        break;
                                }
                            }
                            ?>
                            <li>
                                <a class="dropdown-item notification-item <?= $notif['is_read'] ? '' : 'unread' ?>" 
                                   href="<?= $link ?>" data-id="<?= $notif['id'] ?>">
                                    <div class="d-flex">
                                        <div class="flex-shrink-0 me-2">
                                            <?php switch($notif['type']) {
                                                case 'client_confirmed': ?>
                                                    <i class="fas fa-check-circle text-success"></i>
                                                    <?php break;
                                                case 'nouvelle_demande': ?>
                                                    <i class="fas fa-file-alt text-primary"></i>
                                                    <?php break;
                                                default: ?>
                                                    <i class="fas fa-bell text-warning"></i>
                                            <?php } ?>
                                        </div>
                                        <div class="flex-grow-1">
                                            <div class="d-flex justify-content-between">
                                                <strong><?= htmlspecialchars($notif['title']) ?></strong>
                                                <small><?= time_elapsed_string($notif['created_at']) ?></small>
                                            </div>
                                            <small><?= htmlspecialchars($notif['message']) ?></small>
                                        </div>
                                    </div>
                                </a>
                            </li>
                        <?php endforeach; ?>
                    <?php endif; ?>
                    
                    <li class="dropdown-footer text-center py-2">
                        <small class="text-muted"><?= count($unread_notifications) ?> non lue(s)</small>
                    </li>
                </ul>
            </div>
            
            <!-- User Dropdown -->
            <div class="dropdown">
                <a class="nav-link dropdown-toggle" href="#" id="userDropdown" role="button" data-bs-toggle="dropdown">
                    <i class="fas fa-user-shield"></i>
                </a>
                <ul class="dropdown-menu dropdown-menu-end">
                    <li><h6 class="dropdown-header">Compte Admin</h6></li>
                    <li><a class="dropdown-item" href="admin-profile.php"><i class="fas fa-user-cog me-2"></i>Profil</a></li>
                    <li><a class="dropdown-item" href="logout.php"><i class="fas fa-sign-out-alt me-2"></i>Déconnexion</a></li>
                </ul>
            </div>
        </div>
    </div>
</nav>

<script>
// Mark notification as read when clicked
document.querySelectorAll('.notification-item').forEach(item => {
    item.addEventListener('click', function() {
        if (this.classList.contains('unread')) {
            const notificationId = this.dataset.id;
            fetch('mark_notification_read.php', {
                method: 'POST',
                headers: {
                    'Content-Type': 'application/x-www-form-urlencoded',
                },
                body: 'id=' + notificationId
            });
            this.classList.remove('unread');
        }
    });
});
</script>