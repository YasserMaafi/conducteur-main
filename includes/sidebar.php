            <div class="col-lg-3 mb-4">
                <div class="card shadow-sm">
                    <div class="card-body">
                        <div class="text-center mb-4">
                            <div class="avatar bg-primary text-white rounded-circle p-3 mx-auto" style="width: 80px; height: 80px;">
                                <i class="fas fa-user-shield fa-2x"></i>
                            </div>
                            <h5 class="mt-3 mb-0"><?= htmlspecialchars($admin['department'] ?? 'Administrateur') ?></h5>
                            <small class="text-muted">Niveau d'accès: <?= $admin['access_level'] ?? 1 ?></small>
                        </div>
                        
                        <hr>
                        
                        <ul class="nav flex-column">
                            <li class="nav-item">
                                <a class="nav-link active" href="admin-dashboard.php">
                                    <i class="fas fa-tachometer-alt me-2"></i> Tableau de bord
                                </a>
                            </li>
                            <li class="nav-item">
                                <a class="nav-link" href="manage-clients.php">
                                    <i class="fas fa-users me-2"></i> Gestion Clients
                                </a>
                            </li>
                            <li class="nav-item">
                                <a class="nav-link" href="manage-stations.php">
                                    <i class="fas fa-train me-2"></i> Gestion Gares
                                </a>
                            </li>
                            <li class="nav-item">
                                <a class="nav-link" href="manage-tariffs.php">
                                    <i class="fas fa-money-bill-wave me-2"></i> Tarifs
                                </a>
                            </li>
                            <li class="nav-item">
                                <a class="nav-link" href="admin-settings.php">
                                    <i class="fas fa-cog me-2"></i> Paramètres
                                </a>
                            </li>
                        </ul>
                    </div>
                </div>
            </div>