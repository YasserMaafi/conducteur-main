<?php
require_once 'includes/config.php';
require_once 'includes/functions.php';

header('Content-Type: application/json');

// Vérifier que la requête est POST
if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    echo json_encode(['error' => 'Méthode non autorisée']);
    exit;
}

// Récupérer les données JSON
$data = json_decode(file_get_contents('php://input'), true);

// Validation
if (!isset($data['driver_id']) || !isset($data['status'])) {
    echo json_encode(['error' => 'Données manquantes']);
    exit;
}

// Mettre à jour le statut dans la base de données
try {
    $stmt = $pdo->prepare("UPDATE drivers SET status = :status WHERE id = :driver_id");
    $stmt->execute([
        ':status' => $data['status'],
        ':driver_id' => $data['driver_id']
    ]);
    
    echo json_encode(['success' => true, 'message' => 'Statut mis à jour']);
} catch (PDOException $e) {
    echo json_encode(['error' => 'Erreur de base de données: ' . $e->getMessage()]);
}