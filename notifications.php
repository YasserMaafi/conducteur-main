<?php
require_once 'includes/config.php';
require_once 'includes/auth_functions.php';

// Set page title
$page_title = 'Mes Notifications | SNCFT';

// Start session if not already started
if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

// Verify client role and session
if (!isLoggedIn() || $_SESSION['user']['role'] !== 'client') {
    header('Location: ../index.php');
    exit();
}

// Ensure client_id in session
if (!isset($_SESSION['user']['client_id'])) {
    $stmt = $pdo->prepare("SELECT client_id FROM clients WHERE user_id = ?");
    $stmt->execute([$_SESSION['user']['id']]);
    $c = $stmt->fetch();
    if (!$c) {
        die("Error: Client account not properly configured");
    }
    $_SESSION['user']['client_id'] = $c['client_id'];
}
$client_id = $_SESSION['user']['client_id'];

// Fetch client info
$stmt = $pdo->prepare("
    SELECT c.*, u.email 
      FROM clients c 
      JOIN users u ON c.user_id = u.user_id 
     WHERE c.client_id = ?
");
$stmt->execute([$client_id]);
$client = $stmt->fetch();

// Fetch all notifications for this user
$notifStmt = $pdo->prepare("
    SELECT * 
      FROM notifications 
     WHERE user_id = ? 
  ORDER BY created_at DESC
");
$notifStmt->execute([$_SESSION['user']['id']]);
$notifications = $notifStmt->fetchAll();

// Count unread notifications
$notifCountStmt = $pdo->prepare("
    SELECT COUNT(*) AS count
      FROM notifications
     WHERE user_id = ? 
       AND is_read = FALSE
");
$notifCountStmt->execute([$_SESSION['user']['id']]);
$notifCount = $notifCountStmt->fetch()['count'];

// Include header
require_once 'includes/client_header.php';
?>

<!-- Notifications Header -->
<div class="card shadow-sm mb-4">
    <div class="card-body">
        <div class="d-flex justify-content-between align-items-center">
            <h4 class="mb-0">
                <i class="fas fa-bell me-2"></i>Mes Notifications
                <?php if ($notifCount > 0): ?>
                    <span class="badge bg-danger ms-2"><?= $notifCount ?> non lues</span>
                <?php endif; ?>
            </h4>
            <button class="btn btn-outline-primary" onclick="markAllAsRead()">
                <i class="fas fa-check-double me-2"></i>Tout marquer comme lu
            </button>
        </div>
    </div>
</div>

<!-- Notifications List -->
<div class="card shadow-sm">
    <div class="card-body p-0">
        <?php if (empty($notifications)): ?>
            <div class="text-center py-5">
                <i class="fas fa-bell-slash fa-3x text-muted mb-3"></i>
                <h5>Aucune notification</h5>
                <p class="text-muted">Vous n'avez pas encore reçu de notifications</p>
            </div>
        <?php else: ?>
            <div class="list-group list-group-flush">
                <?php foreach ($notifications as $n): ?>
                    <?php 
                        $md = json_decode($n['metadata'] ?? '{}', true);
                        $link = $md['link'] ?? '#';
                        $cls = $n['is_read'] ? '' : 'unread';
                    ?>
                    <div class="list-group-item notification-item <?= $cls ?>" data-id="<?= $n['id'] ?>">
                        <div class="d-flex justify-content-between align-items-center">
                            <div>
                                <h6 class="mb-1"><?= htmlspecialchars($n['title']) ?></h6>
                                <p class="mb-1"><?= htmlspecialchars($n['message']) ?></p>
                                <small class="text-muted">
                                    <?= time_elapsed_string($n['created_at']) ?>
                                </small>
                            </div>
                            <div>
                                <?php if (!$n['is_read']): ?>
                                    <span class="badge bg-primary">Nouveau</span>
                                <?php endif; ?>
                            </div>
                        </div>
                    </div>
                <?php endforeach; ?>
            </div>
        <?php endif; ?>
    </div>
</div>

<script>
// Mark notification as read on click
document.querySelectorAll('.notification-item').forEach(item => {
    item.addEventListener('click', function() {
        if (!this.classList.contains('unread')) return;
        this.classList.remove('unread');
        fetch('mark_notification_read.php?id=' + this.dataset.id, { method: 'POST' });
    });
});

// Mark all notifications as read
function markAllAsRead() {
    fetch('mark_all_notifications_read.php', { method: 'POST' })
        .then(response => response.json())
        .then(data => {
            if (data.success) {
                document.querySelectorAll('.notification-item.unread').forEach(item => {
                    item.classList.remove('unread');
                });
                document.querySelector('.badge.bg-danger').remove();
            }
        });
}
</script>

<?php
// Include footer
require_once 'includes/client_footer.php';

// Helper function to display relative time
function time_elapsed_string($datetime, $full = false) {
    $now = new DateTime;
    $ago = new DateTime($datetime);
    $diff = $now->diff($ago);

    $diff->w = floor($diff->d / 7);
    $diff->d -= $diff->w * 7;

    $string = array(
        'y' => 'an',
        'm' => 'mois',
        'w' => 'semaine',
        'd' => 'jour',
        'h' => 'heure',
        'i' => 'minute',
        's' => 'seconde',
    );
    
    foreach ($string as $k => &$v) {
        if ($diff->$k) {
            $v = $diff->$k . ' ' . $v . ($diff->$k > 1 && $k != 'm' ? 's' : '');
        } else {
            unset($string[$k]);
        }
    }

    if (!$full) $string = array_slice($string, 0, 1);
    return $string ? 'Il y a ' . implode(', ', $string) : 'À l\'instant';
}
?>
