<?php
header('Content-Type: application/json');
header('Access-Control-Allow-Origin: *');

error_reporting(E_ALL);
ini_set('display_errors', 1);

$positions_file = __DIR__ . '/driver_positions.json';

if (!file_exists($positions_file)) {
    die(json_encode(['error' => 'Fichier de positions introuvable']));
}

$content = file_get_contents($positions_file);
if ($content === false) {
    die(json_encode(['error' => 'Impossible de lire le fichier de positions']));
}

$positions = json_decode($content, true);
if ($positions === null) {
    die(json_encode(['error' => 'Données de positions corrompues']));
}

// Filtrer les positions trop anciennes (plus de 5 minutes)
$current_time = time();
$active_positions = [];
foreach ($positions as $driver_id => $position) {
    if ($current_time - $position['timestamp'] <= 300) { // 5 minutes
        $active_positions[] = [
            'id' => $driver_id,
            'name' => $position['name'] ?? 'Conducteur '.$driver_id,
            'lat' => (float)$position['lat'],
            'lng' => (float)$position['lng'],
            'speed' => (int)$position['speed'],
            'train_type' => $position['train_type'] ?? 'Non spécifié',
            'timestamp' => $position['timestamp']
        ];
    }
}

echo json_encode(array_values($active_positions));
?>