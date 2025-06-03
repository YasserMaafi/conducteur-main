<?php
// includes/auth_functions.php
require_once 'config.php';

// Vérifier si l'utilisateur est connecté
function isLoggedIn() {
    return !empty($_SESSION['user']);
}

// Fonction d'inscription avec rôle
function registerUserWithRole($username, $password, $email, $role, $roleData = []) {
    global $pdo;

    try {
        $pdo->beginTransaction();
        
        // Vérifier si le nom d'utilisateur ou l'email existe déjà
        $stmt = $pdo->prepare("SELECT user_id FROM users WHERE username = ? OR email = ?");
        $stmt->execute([$username, $email]);
        
        if ($stmt->rowCount() > 0) {
            throw new Exception("Username or email already exists");
        }
        
        // Insérer dans la table users
        $stmt = $pdo->prepare("INSERT INTO users (username, password_hash, email, role) VALUES (?, ?, ?, ?)");
        $stmt->execute([
            $username,
            password_hash($password, PASSWORD_BCRYPT),
            $email,
            $role
        ]);
        $userId = $pdo->lastInsertId();
        
        // Insérer dans la table spécifique au rôle
        switch ($role) {
            case 'admin':
                $stmt = $pdo->prepare("INSERT INTO admins (user_id, department, access_level) VALUES (?, ?, ?)");
                $stmt->execute([
                    $userId,
                    $roleData['department'], 
                    $roleData['access_level'] ?? 1
                ]);
                break;

            case 'agent':
                // Utilise id_gare (correspond à la colonne dans la table agents)
                $stmt = $pdo->prepare("INSERT INTO agents (user_id, id_gare, badge_number) VALUES (?, ?, ?)");
                $stmt->execute([
                    $userId,
                    $roleData['station_id'],
                    $roleData['badge_number']
                ]);
                break;
                
            case 'client':
                $stmt = $pdo->prepare("INSERT INTO clients (user_id, company_name, phone_number, account_code, tax_id) VALUES (?, ?, ?, ?, ?)");
                $stmt->execute([
                    $userId,
                    $roleData['company_name'],
                    $roleData['phone_number'],
                    $roleData['account_code'],
                    $roleData['tax_id'] ?? ''
                ]);
                break;
                
            case 'driver':
                $stmt = $pdo->prepare("INSERT INTO drivers (user_id, license_number, train_types) VALUES (?, ?, ?)");
                $stmt->execute([
                    $userId,
                    $roleData['license_number'],
                    $roleData['train_types']
                ]);
                break;
                
            default:
                throw new Exception("Invalid role specified");
        }
        
        $pdo->commit();
        return $userId;
    } catch (Exception $e) {
        $pdo->rollBack();
        // En mode développement, vous pouvez afficher l'erreur exacte :
        error_log("Registration failed for role {$role}: " . $e->getMessage());
        return false;
    }
}

function loginUser($username, $password) {
    global $pdo;
    
    $stmt = $pdo->prepare(
        "SELECT u.user_id, u.username, u.password_hash, u.role, u.is_active,
               c.company_name AS client_company,
               d.license_number AS driver_license
        FROM users u
        LEFT JOIN clients c ON u.user_id = c.user_id AND u.role = 'client'
        LEFT JOIN drivers d ON u.user_id = d.user_id AND u.role = 'driver'
        WHERE u.username = ?"
    );
    
    $stmt->execute([$username]);
    $user = $stmt->fetch();
    
    if ($user && password_verify($password, $user['password_hash'])) {
        if (!$user['is_active']) {
            throw new Exception("Account is inactive");
        }
        
        session_regenerate_id(true);  // Prévenir fixation de session
        
        // Mettre à jour last_login
        $stmt = $pdo->prepare("UPDATE users SET last_login = CURRENT_TIMESTAMP WHERE user_id = ?");
        $stmt->execute([$user['user_id']]);
        
        // Stocker les infos utilisateur en session
        $_SESSION['user'] = [
            'id' => $user['user_id'],
            'username' => $user['username'],
            'role' => $user['role'],
            'company_name' => $user['client_company'] ?? null,
            'license_number' => $user['driver_license'] ?? null
        ];
        
        return true;
    }
    
    return false;
}

function logoutUser() {
    $_SESSION = [];
    session_destroy();
}

function requireRole($role) {
    if (!isLoggedIn() || $_SESSION['user']['role'] !== $role) {
        redirect('unauthorized.php');
    }
}

function getClientIdByUserId(PDO $pdo, int $userId): ?int {
    $stmt = $pdo->prepare("SELECT client_id FROM clients WHERE user_id = ?");
    $stmt->execute([$userId]);
    $result = $stmt->fetch(PDO::FETCH_ASSOC);
    return $result ? (int)$result['client_id'] : null;
}

