<?php
require_once 'includes/config.php';
require_once 'includes/auth_functions.php';

if (isLoggedIn()) {
    redirect('dashboard.php');
}

$errors = [];

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $username         = trim($_POST['username']);
    $email            = trim($_POST['email']);
    $password         = trim($_POST['password']);
    $confirm_password = trim($_POST['confirm_password']);
    $role             = trim($_POST['role']);
    $badge_number     = trim($_POST['badge_number'] ?? '');
    $company_name     = trim($_POST['company_name'] ?? '');
    $phone_number     = trim($_POST['phone_number'] ?? '');
    $account_code     = trim($_POST['account_code'] ?? '');
    $department       = trim($_POST['department'] ?? '');
    $license_number   = trim($_POST['license_number'] ?? '');
    $station_id       = trim($_POST['station_id'] ?? 1); // Default to station_id 1 if not selected

    // General validation
    if (empty($username)) $errors[] = "Le nom d'utilisateur est requis";
    if (empty($email))    $errors[] = "L'email est requis";
    if (empty($password)) $errors[] = "Le mot de passe est requis";
    if ($password !== $confirm_password) $errors[] = "Les mots de passe ne correspondent pas";
    if (!filter_var($email, FILTER_VALIDATE_EMAIL)) $errors[] = "Format d'email invalide";

    // Role-specific validation
    switch ($role) {
        case 'agent':
            if (empty($badge_number)) $errors[] = "Le numéro de badge est requis pour les agents";
            if (!is_numeric($station_id)) $errors[] = "La gare sélectionnée est invalide";
            break;
        case 'client':
            if (empty($company_name)) $errors[] = "Le nom de l'entreprise est requis pour les clients";
            if (empty($phone_number)) $errors[] = "Le numéro de téléphone est requis pour les clients";
            if (empty($account_code)) $errors[] = "Le code compte est requis pour les clients";
            break;
        case 'admin':
            if (empty($department)) $errors[] = "Le département est requis pour les administrateurs";
            break;
        case 'driver':
            if (empty($license_number)) $errors[] = "Le numéro de licence est requis pour les conducteurs";
            break;
        default:
            $errors[] = "Rôle invalide";
    }

    if (empty($errors)) {
        // Prepare role data
        $roleData = [];
        switch ($role) {
            case 'agent':
                $roleData = [
                    'badge_number' => $badge_number,
                    'station_id'   => (int)$station_id  // Ensure it's an integer
                ];
                break;
            case 'client':
                $roleData = [
                    'company_name' => $company_name,
                    'phone_number' => $phone_number,
                    'account_code' => $account_code,
                    'tax_id'       => ''
                ];
                break;
            case 'admin':
                $roleData = [
                    'department'   => $department,
                    'access_level' => 1
                ];
                break;
            case 'driver':
                $roleData = [
                    'license_number' => $license_number,
                    'train_types'    => 'GT26-101,GT26-102,GT26-103,GT26-104,GT26-105,GT26-106,GT26-107,GT26-108,CC21000-201,CC21000-202,CC21000-203,BB1200-301,BB1200-302,BB1200-303,BB1200-304'  // Default train types
                ];
                break;
        }

        try {
            $userId = registerUserWithRole($username, $password, $email, $role, $roleData);
            if ($userId) {
                $_SESSION['success_message'] = "Inscription réussie! Vous pouvez maintenant vous connecter.";
                redirect('index.php');
            } else {
                $errors[] = "Erreur lors de l'inscription. Veuillez vérifier vos données et réessayer.";
            }
        } catch (Exception $e) {
            $errors[] = "Erreur: " . $e->getMessage();
        }
    }
}

// Fetch stations for agent registration
$stations = [];
try {
    $stmt = $pdo->query("SELECT id_gare, libelle FROM gares ORDER BY libelle");
    $stations = $stmt->fetchAll(PDO::FETCH_ASSOC);
} catch (PDOException $e) {
    error_log("Error fetching stations: " . $e->getMessage());
}
?>
<!DOCTYPE html>
<html lang="fr">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Inscription - Système Fret Ferroviaire</title>
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.0.0-beta3/css/all.min.css" />
    <link rel="stylesheet" href="assets/css/style.css">
    <style>
        .role-fields { display: none; }
        .role-fields.active { display: block; }
    </style>
</head>
<body>
    <div class="auth-container">
        <div class="auth-box">
            <h1><i class="fas fa-train"></i> Inscription</h1>
            
            <?php if (!empty($errors)): ?>
                <div class="alert alert-danger">
                    <?php foreach ($errors as $error): ?>
                        <p><?php echo htmlspecialchars($error); ?></p>
                    <?php endforeach; ?>
                </div>
            <?php endif; ?>
            
            <form method="POST" action="">
                <div class="form-group">
                    <label for="username">Nom d'utilisateur</label>
                    <input type="text" id="username" name="username" value="<?= htmlspecialchars($_POST['username'] ?? '') ?>" required>
                </div>
                
                <div class="form-group">
                    <label for="email">Email</label>
                    <input type="email" id="email" name="email" value="<?= htmlspecialchars($_POST['email'] ?? '') ?>" required>
                </div>
                
                <div class="form-group">
                    <label for="password">Mot de passe</label>
                    <input type="password" id="password" name="password" required>
                </div>
                
                <div class="form-group">
                    <label for="confirm_password">Confirmer le mot de passe</label>
                    <input type="password" id="confirm_password" name="confirm_password" required>
                </div>
                
                <div class="form-group">
                    <label for="role">Rôle</label>
                    <select id="role" name="role" required onchange="showRoleFields()">
                        <option value="">-- Sélectionnez un rôle --</option>
                        <option value="admin" <?= ($_POST['role'] ?? '') === 'admin' ? 'selected' : '' ?>>Administrateur</option>
                        <option value="agent" <?= ($_POST['role'] ?? '') === 'agent' ? 'selected' : '' ?>>Agent</option>
                        <option value="client" <?= ($_POST['role'] ?? '') === 'client' ? 'selected' : '' ?>>Client</option>
                        <option value="driver" <?= ($_POST['role'] ?? '') === 'driver' ? 'selected' : '' ?>>Conducteur</option>
                    </select>
                </div>
                
                <!-- Admin Fields -->
                <div id="admin-fields" class="role-fields">
                    <div class="form-group">
                        <label for="department">Département</label>
                        <input type="text" id="department" name="department" value="<?= htmlspecialchars($_POST['department'] ?? '') ?>">
                    </div>
                </div>
                
                <!-- Agent Fields -->
                <div id="agent-fields" class="role-fields">
                    <div class="form-group">
                        <label for="badge_number">Numéro de badge</label>
                        <input type="text" id="badge_number" name="badge_number" value="<?= htmlspecialchars($_POST['badge_number'] ?? '') ?>">
                    </div>
                    <div class="form-group">
                        <label for="station_id">Gare d'affectation</label>
                        <select id="station_id" name="station_id" class="form-control">
                            <?php foreach ($stations as $station): ?>
                                <option value="<?= $station['id_gare'] ?>" <?= ($_POST['station_id'] ?? 1) == $station['id_gare'] ? 'selected' : '' ?>>
                                    <?= htmlspecialchars($station['libelle']) ?>
                                </option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                </div>
                
                <!-- Client Fields -->
                <div id="client-fields" class="role-fields">
                    <div class="form-group">
                        <label for="company_name">Nom de l'entreprise</label>
                        <input type="text" id="company_name" name="company_name" value="<?= htmlspecialchars($_POST['company_name'] ?? '') ?>">
                    </div>
                    <div class="form-group">
                        <label for="phone_number">Numéro de téléphone</label>
                        <input type="text" id="phone_number" name="phone_number" value="<?= htmlspecialchars($_POST['phone_number'] ?? '') ?>">
                    </div>
                    <div class="form-group">
                        <label for="account_code">Code compte</label>
                        <input type="text" id="account_code" name="account_code" value="<?= htmlspecialchars($_POST['account_code'] ?? '') ?>">
                    </div>
                </div>
                
                <!-- Driver Fields -->
                <div id="driver-fields" class="role-fields">
                    <div class="form-group">
                        <label for="license_number">Numéro de licence</label>
                        <input type="text" id="license_number" name="license_number" value="<?= htmlspecialchars($_POST['license_number'] ?? '') ?>">
                    </div>
                </div>
                
                <button type="submit" class="btn btn-primary">
                    <i class="fas fa-user-plus"></i> S'inscrire
                </button>
            </form>
            
            <div class="auth-footer">
                <p>Déjà inscrit? <a href="index.php">Se connecter</a></p>
            </div>
        </div>
    </div>

    <script>
        function showRoleFields() {
            // Hide all role fields
            document.querySelectorAll('.role-fields').forEach(field => {
                field.classList.remove('active');
            });
            
            // Show selected role fields
            const role = document.getElementById('role').value;
            if (role) {
                document.getElementById(`${role}-fields`).classList.add('active');
            }
        }
        
        // Show relevant fields on page load if role is already selected
        document.addEventListener('DOMContentLoaded', function() {
            const role = document.getElementById('role').value;
            if (role) {
                showRoleFields();
            }
        });
    </script>
</body>
</html>