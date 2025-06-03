<?php
require_once 'config.php';

function calculateFreightPrice($client_id, $origin_gare_id, $destination_gare_id, $wagon_count) {
    global $pdo;
    
    try {
        // Validate inputs
        if ($wagon_count < 1) {
            throw new Exception("Wagon count must be at least 1");
        }
        
        // 1. Get client's tariff rate with better error handling
        $stmt = $pdo->prepare("SELECT base_rate_per_km FROM tariffs WHERE client_id = ?");
        $stmt->execute([$client_id]);
        $tariff = $stmt->fetch();
        
        if (!$tariff) {
            // Try to get default tariff if client-specific not found
            $stmt = $pdo->prepare("SELECT base_rate_per_km FROM tariffs WHERE client_id IS NULL ORDER BY created_at DESC LIMIT 1");
            $stmt->execute();
            $tariff = $stmt->fetch();
            
            if (!$tariff) {
                throw new Exception("No tariff found for client and no default tariff available");
            }
        }
        $rate_per_km = (float)$tariff['base_rate_per_km'];
        
        // 2. Get regions for both gares with station names for better errors
        $stmt = $pdo->prepare("SELECT region, libelle FROM gares WHERE id_gare = ?");
        $stmt->execute([$origin_gare_id]);
        $origin_data = $stmt->fetch();
        
        if (!$origin_data) {
            throw new Exception("Origin station not found");
        }
        $origin_region = $origin_data['region'];
        $origin_name = $origin_data['libelle'];
        
        $stmt->execute([$destination_gare_id]);
        $destination_data = $stmt->fetch();
        
        if (!$destination_data) {
            throw new Exception("Destination station not found");
        }
        $destination_region = $destination_data['region'];
        $destination_name = $destination_data['libelle'];
        
        // 3. Get distance between regions (try both directions)
        $stmt = $pdo->prepare("
            SELECT estimated_distance_km 
            FROM region_distances 
            WHERE (from_region = ? AND to_region = ?)
            OR (from_region = ? AND to_region = ?)
            LIMIT 1
        ");
        $stmt->execute([$origin_region, $destination_region, $destination_region, $origin_region]);
        $distance = $stmt->fetchColumn();
        
        if (!$distance) {
            throw new Exception("Distance not available between $origin_name ($origin_region) and $destination_name ($destination_region)");
        }
        $distance = (float)$distance;
        
        // 4. Calculate base price
        $base_price = $distance * $rate_per_km;
        
        // 5. Apply wagon multiplier (10% increase per additional wagon)
        $wagon_multiplier = 1 + (($wagon_count - 1) * 0.10);
        $final_price = $base_price * $wagon_multiplier;
        
        return [
            'success' => true,
            'price' => round($final_price, 2),
            'base_price' => round($base_price, 2),
            'distance_km' => $distance,
            'rate_per_km' => $rate_per_km,
            'wagon_count' => (int)$wagon_count,
            'wagon_multiplier' => $wagon_multiplier,
            'origin_name' => $origin_name,
            'destination_name' => $destination_name
        ];
        
    } catch (Exception $e) {
        error_log("Price calculation error: " . $e->getMessage());
        return [
            'success' => false,
            'error' => $e->getMessage(),
            'default_price' => 500.00 // Fallback price
        ];
    }
}