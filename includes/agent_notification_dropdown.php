<?php
// This file contains the notification dropdown for agent pages
// It expects $draftNotifications and $draftNotificationCount to be defined
?>
<!-- Notification Dropdown -->
<div class="dropdown me-3">
    <button class="btn btn-outline-light position-relative" 
            type="button" 
            id="notificationDropdown" 
            data-bs-toggle="dropdown" 
            aria-expanded="false">
        <i class="fas fa-bell"></i>
        <?php if ($draftNotificationCount > 0): ?>
            <span class="position-absolute top-0 start-100 translate-middle badge rounded-pill bg-danger">
                <?= $draftNotificationCount ?>
            </span>
        <?php endif; ?>
    </button>
    <ul class="dropdown-menu dropdown-menu-end shadow-sm notification-dropdown" aria-labelledby="notificationDropdown" style="width: 400px; max-height: 400px; overflow-y: auto;">
        <li>
            <div class="dropdown-header bg-light d-flex justify-content-between align-items-center py-3">
                <h6 class="m-0">Notifications</h6>
                <?php if ($draftNotificationCount > 0): ?>
                    <span class="badge bg-primary rounded-pill"><?= $draftNotificationCount ?> nouvelle<?= $draftNotificationCount > 1 ? 's' : '' ?></span>
                <?php endif; ?>
            </div>
        </li>
        
        <?php if ($draftNotificationCount > 0): ?>
            <?php foreach ($draftNotifications as $notification): 
                $metadata = json_decode($notification['metadata'], true);
                $contract_id = $metadata['contract_id'] ?? 0;
            ?>
                <li>
                    <a class="dropdown-item notification-item py-2 px-3 border-bottom" href="complete_contract.php?id=<?= $contract_id ?>">
                        <div class="d-flex">
                            <div class="flex-shrink-0">
                                <div class="notification-icon bg-primary text-white rounded-circle">
                                    <i class="fas fa-file-alt"></i>
                                </div>
                            </div>
                            <div class="flex-grow-1 ms-3">
                                <h6 class="mb-1 fw-bold"><?= htmlspecialchars($notification['title']) ?></h6>
                                <p class="mb-1 small text-muted"><?= htmlspecialchars($notification['message']) ?></p>
                                <div class="small text-muted">
                                    <i class="fas fa-clock me-1"></i><?= date('d/m/Y H:i', strtotime($notification['created_at'])) ?>
                                </div>
                            </div>
                        </div>
                    </a>
                </li>
            <?php endforeach; ?>
        <?php else: ?>
            <li>
                <div class="text-center py-4">
                    <i class="fas fa-check-circle text-success fa-2x mb-2"></i>
                    <p class="mb-0 text-muted">Aucune nouvelle notification</p>
                </div>
            </li>
        <?php endif; ?>
        
        <li>
            <div class="dropdown-divider m-0"></div>
            <a class="dropdown-item text-center py-2 bg-light" href="agent-contracts.php?status=draft">
                <i class="fas fa-list-ul me-1"></i> Voir tous les brouillons
            </a>
        </li>
    </ul>
</div>