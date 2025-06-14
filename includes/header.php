<?php
// Verify admin role
if (!isLoggedIn() || $_SESSION['user']['role'] !== 'admin') {
    header('Location: ../../index.php');
    exit();
}

// Get unread notifications count
$notif_stmt = $pdo->prepare("SELECT COUNT(*) FROM notifications WHERE user_id = ? AND is_read = FALSE");
$notif_stmt->execute([$_SESSION['user']['id']]);
$unread_notif_count = $notif_stmt->fetchColumn();
?>
<!-- Top Navigation Bar -->
<nav class="navbar navbar-expand-lg navbar-dark bg-primary shadow-sm">
    <div class="container-fluid">
        <a class="navbar-brand" href="dashboard.php">
            <i class="fas fa-user-shield me-2"></i>SNCFT Admin
        </a>
        
        <button class="navbar-toggler" type="button" data-bs-toggle="collapse" data-bs-target="#adminNavbar">
            <span class="navbar-toggler-icon"></span>
        </button>
        
        <div class="collapse navbar-collapse" id="adminNavbar">
            <ul class="navbar-nav ms-auto">
                <li class="nav-item dropdown">
                    <a class="nav-link dropdown-toggle" href="#" id="notifDropdown" role="button" data-bs-toggle="dropdown">
                        <i class="fas fa-bell"></i>
                        <?php if ($unread_notif_count > 0): ?>
                            <span class="badge bg-danger notification-badge"><?= $unread_notif_count ?></span>
                        <?php endif; ?>
                    </a>
                    <ul class="dropdown-menu dropdown-menu-end notification-dropdown">
                        <?php
                        $notifications = $pdo->prepare("
                            SELECT * FROM notifications 
                            WHERE user_id = ? 
                            ORDER BY created_at DESC 
                            LIMIT 5
                        ");
                        $notifications->execute([$_SESSION['user']['id']]);
                        $notifications = $notifications->fetchAll();
                        ?>
                        
                        <?php if (empty($notifications)): ?>
                            <li><span class="dropdown-item text-muted">Aucune notification</span></li>
                        <?php else: ?>
                            <?php foreach ($notifications as $notif): ?>
                                <li>
                                    <a class="dropdown-item notification-item <?= $notif['is_read'] ? '' : 'unread' ?>" 
                                       href="#" data-id="<?= $notif['id'] ?>">
                                        <div class="d-flex justify-content-between">
                                            <strong><?= htmlspecialchars($notif['title']) ?></strong>
                                            <small class="text-muted"><?= time_elapsed_string($notif['created_at']) ?></small>
                                        </div>
                                        <p class="mb-0 text-truncate"><?= htmlspecialchars($notif['message']) ?></p>
                                    </a>
                                </li>
                            <?php endforeach; ?>
                            <li><hr class="dropdown-divider"></li>
                            <li><a class="dropdown-item text-center" href="notifications.php">Voir toutes</a></li>
                        <?php endif; ?>
                    </ul>
                </li>
                <li class="nav-item dropdown">
                    <a class="nav-link dropdown-toggle" href="#" id="profileDropdown" role="button" data-bs-toggle="dropdown">
                        <i class="fas fa-user-circle me-1"></i>
                        <?= htmlspecialchars($_SESSION['user']['username']) ?>
                    </a>
                    <ul class="dropdown-menu dropdown-menu-end">
                        <li><a class="dropdown-item" href="profile.php"><i class="fas fa-user me-2"></i>Profil</a></li>
                        <li><a class="dropdown-item" href="settings.php"><i class="fas fa-cog me-2"></i>Paramètres</a></li>
                        <li><hr class="dropdown-divider"></li>
                        <li><a class="dropdown-item" href="../../logout.php"><i class="fas fa-sign-out-alt me-2"></i>Déconnexion</a></li>
                    </ul>
                </li>
            </ul>
        </div>
    </div>
</nav>