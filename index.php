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
    <style>
        :root {
            --primary-color: #0056b3;
            --secondary-color: #e63946;
            --light-color: #f8f9fa;
            --dark-color: #343a40;
        }
        
        body {
            font-family: 'Segoe UI', Tahoma, Geneva, Verdana, sans-serif;
            margin: 0;
            padding: 0;
            display: flex;
            justify-content: center;
            align-items: center;
            min-height: 100vh;
            background: url('assets/images/image.png') no-repeat center center fixed;
            background-size: cover;
            position: relative;
        }
        
        body::before {
            content: '';
            position: absolute;
            top: 0;
            left: 0;
            right: 0;
            bottom: 0;
            background: inherit;
            filter: blur(8px) brightness(0.7);
            z-index: -1;
        }
        
        .auth-container {
            width: 90%;
            max-width: 1200px;
            display: flex;
            box-shadow: 0 10px 30px rgba(0, 0, 0, 0.3);
            border-radius: 10px;
            overflow: hidden;
            background-color: rgba(140, 201, 238, 0.42);
            backdrop-filter: blur(5px);
        }
        
        .company-branding {
            flex: 1;
            background-color: rgba(0, 86, 179, 0.9);
            color: white;
            display: flex;
            flex-direction: column;
            justify-content: center;
            align-items: center;
            padding: 2rem;
            text-align: center;
        }
        
        .company-branding img {
            max-width: 80%;
            margin-bottom: 2rem;
            filter: drop-shadow(0 2px 4px rgba(0, 0, 0, 0.3));
        }
        
        .company-branding h2 {
            font-size: 1.8rem;
            margin-bottom: 1rem;
            font-weight: 600;
            text-shadow: 0 2px 4px rgba(0, 0, 0, 0.3);
        }
        
        .company-branding p {
            font-size: 1.1rem;
            opacity: 0.9;
        }
        
        .auth-box {
            flex: 1;
            padding: 3rem;
            display: flex;
            flex-direction: column;
            justify-content: center;
        }
        
        .auth-box h1 {
            color: var(--primary-color);
            text-align: center;
            margin-bottom: 2rem;
            font-size: 2rem;
            display: flex;
            align-items: center;
            justify-content: center;
            gap: 0.5rem;
        }
        
        .form-group {
            margin-bottom: 1.5rem;
        }
        
        .form-group label {
            display: block;
            margin-bottom: 0.5rem;
            font-weight: 500;
            color: var(--dark-color);
        }
        
        .form-group input {
            width: 100%;
            padding: 0.8rem 1rem;
            border: 1px solid #ddd;
            border-radius: 5px;
            font-size: 1rem;
            transition: border-color 0.3s;
            background-color: rgba(255, 255, 255, 0.8);
        }
        
        .form-group input:focus {
            border-color: var(--primary-color);
            outline: none;
            box-shadow: 0 0 0 3px rgba(0, 86, 179, 0.1);
            background-color: white;
        }
        
        .btn {
            display: inline-block;
            padding: 0.8rem 1.5rem;
            background-color: var(--primary-color);
            color: white;
            border: none;
            border-radius: 5px;
            font-size: 1rem;
            font-weight: 500;
            cursor: pointer;
            transition: background-color 0.3s;
            text-align: center;
            width: 100%;
        }
        
        .btn:hover {
            background-color: #004494;
        }
        
        .btn i {
            margin-right: 0.5rem;
        }
        
        .alert {
            padding: 1rem;
            margin-bottom: 1.5rem;
            border-radius: 5px;
            font-size: 0.9rem;
        }
        
        .alert-danger {
            background-color: #f8d7da;
            color: #721c24;
            border: 1px solid #f5c6cb;
        }
        
        .alert-success {
            background-color: #d4edda;
            color: #155724;
            border: 1px solid #c3e6cb;
        }
        
        .auth-footer {
            margin-top: 1.5rem;
            text-align: center;
            font-size: 0.9rem;
        }
        
        .auth-footer a {
            color: var(--primary-color);
            text-decoration: none;
            transition: color 0.3s;
        }
        
        .auth-footer a:hover {
            color: #004494;
            text-decoration: underline;
        }
        
        @media (max-width: 768px) {
            .auth-container {
                flex-direction: column;
            }
            
            .company-branding {
                padding: 1.5rem;
            }
            
            .auth-box {
                padding: 2rem;
            }
        }
    </style>
</head>
<body>
    <div class="auth-container">
        <div class="company-branding">
            <img src="assets/images/sncft-logo.png" alt="Société Nationale des Chemins de Fer Tunisiens">
            <h2>Système Fret Ferroviaire</h2>
            <p>Plateforme de gestion du fret ferroviaire tunisien</p>
        </div>
        
        <div class="auth-box">
            <h1><i class="fas fa-sign-in-alt"></i> Connexion</h1>
            
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
                
                <button type="submit" class="btn">
                    <i class="fas fa-sign-in-alt"></i> Se connecter
                </button>
            </form>
            
            <div class="auth-footer">
                <p><a href="forgot_password.php">Mot de passe oublié?</a></p>
            </div>
        </div>
    </div>
</body>
</html>
