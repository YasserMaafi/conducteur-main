<?php
// includes/config.php
session_start();

// Configuration de la base de données
define('DB_HOST', 'localhost');
define('DB_USER', 'postgres');
define('DB_PASS', '123');
define('DB_NAME', 'fretdb');
 
// Connexion à la base de données avec PDO pour PostgreSQL
try {
    $pdo = new PDO("pgsql:host=" . DB_HOST . ";dbname=" . DB_NAME, DB_USER, DB_PASS);
    $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
    $pdo->setAttribute(PDO::ATTR_DEFAULT_FETCH_MODE, PDO::FETCH_ASSOC);
} catch (PDOException $e) {
    die("Erreur de connexion : " . $e->getMessage());
}

// Fonction de redirection
function redirect($url) {
    header("Location: $url");
    exit();
}
?> 