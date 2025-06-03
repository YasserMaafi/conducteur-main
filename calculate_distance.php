<?php
require_once 'includes/config.php';

$origin = filter_input(INPUT_GET, 'origin', FILTER_VALIDATE_INT);
$destination = filter_input(INPUT_GET, 'destination', FILTER_VALIDATE_INT);

// In a real app, you would calculate actual distance between stations
// This is just a placeholder that returns a random distance
header('Content-Type: application/json');
echo json_encode(['distance' => rand(50, 500)]);
?>