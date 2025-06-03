<?php
require_once 'includes/config.php';
require_once 'includes/auth_functions.php';

if (isLoggedIn()) {
    // Redirect based on role
    switch ($_SESSION['user']['role']) {
        case 'admin':
            redirect('admin-dashboard.php');
            break;
        case 'agent':
            redirect('agent-dashboard.php');
            break;
        case 'client':
            redirect('client-dashboard.php');
            break;
        case 'driver':
            redirect('dashboard.php');
            break;
        default:
            redirect('dashboard.php');
    }
}

$error = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    try {
        if (loginUser($_POST['username'], $_POST['password'])) {
            // Redirect based on role
            switch ($_SESSION['user']['role']) {
                case 'admin':
                    redirect('admin-dashboard.php');
                    break;
                case 'agent':
                    redirect('agent-dashboard.php');
                    break;
                case 'client':
                    redirect('client-dashboard.php');
                    break;
                case 'driver':
                    redirect('dashboard.php');
                    break;
                default:
                    redirect('dashboard.php');
            }
        } else {
            $error = "Identifiants incorrects";
        }
    } catch (Exception $e) {
        $error = $e->getMessage();
    }
}
?>

<!DOCTYPE html>
<html lang="fr">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Connexion - Système Fret Ferroviaire</title>
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.0.0-beta3/css/all.min.css" />
    <link rel="stylesheet" href="assets/css/style.css">
</head>
<body>
    <div class="auth-container">
        <div class="auth-box">
            <h1><i class="fas fa-train"></i> Connexion</h1>
            
            <?php if (isset($_SESSION['success_message'])): ?>
                <div class="alert alert-success">
                    <?php echo htmlspecialchars($_SESSION['success_message']); ?>
                    <?php unset($_SESSION['success_message']); ?>
                </div>
            <?php endif; ?>
            
            <?php if ($error): ?>
                <div class="alert alert-danger"><?php echo htmlspecialchars($error); ?></div>
            <?php endif; ?>
            
            <form method="POST" action="">
                <div class="form-group">
                    <label for="username">Nom d'utilisateur</label>
                    <input type="text" id="username" name="username" required>
                </div>
                
                <div class="form-group">
                    <label for="password">Mot de passe</label>
                    <input type="password" id="password" name="password" required>
                </div>
                
                <button type="submit" class="btn btn-primary">
                    <i class="fas fa-sign-in-alt"></i> Se connecter
                </button>
            </form>
            
            <div class="auth-footer">
                <p>Pas encore de compte? <a href="signup.php">S'inscrire</a></p>
                <p><a href="forgot_password.php">Mot de passe oublié?</a></p>
            </div>
        </div>
    </div>
</body>
</html>