<?php
require_once 'includes/config.php';

if (!isLoggedIn()) {
    redirect('index.php');
}

// Vérifier le rôle si nécessaire
if ($_SESSION['role'] !== 'driver') {
    // Rediriger ou afficher une erreur
    die("Accès réservé aux conducteurs");
}
?>

<!DOCTYPE html>
<html lang="fr">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Tableau de bord - Interface Conducteur</title>
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.0.0-beta3/css/all.min.css" />
    <link rel="stylesheet" href="assets/css/style.css">
</head>
<body>
    <div class="dashboard-container">
        <header class="dashboard-header">
            <h1><i class="fas fa-train"></i> Tableau de bord Conducteur</h1>
            <div class="user-info">
                <span><?php echo htmlspecialchars($_SESSION['username']); ?></span>
                <a href="logout.php" class="btn btn-logout"><i class="fas fa-sign-out-alt"></i> Déconnexion</a>
            </div>
        </header>
        
        <div class="dashboard-content">
            <div class="card">
                <h2>Messages</h2>
                <div id="messages-container">
                    <!-- Messages will appear here -->
                </div>
                
                <div class="message-form">
                    <textarea id="message-input" placeholder="Tapez votre message ici..."></textarea>
                    <button onclick="sendMessage()" class="btn btn-send">
                        <i class="fas fa-paper-plane"></i> Envoyer
                    </button>
                </div>
            </div>
            
            <div class="card">
                <h2>Statut du train</h2>
                <div class="train-status">
                    <div class="status-indicator active"></div>
                    <span>En service</span>
                </div>
                <button class="btn btn-status">
                    <i class="fas fa-power-off"></i> Changer statut
                </button>
            </div>
        </div>
    </div>

    <script src="assets/js/script.js"></script>
</body>
</html>