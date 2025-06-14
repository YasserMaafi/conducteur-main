<?php
require_once 'includes/config.php';
require_once 'includes/auth_functions.php';

// Verify agent role
if (!isLoggedIn() || $_SESSION['user']['role'] !== 'agent') {
    redirect('index.php');
}

// Get agent info
$agent_id = $_SESSION['user']['id'];
$stmt = $pdo->prepare("SELECT * FROM agents WHERE user_id = ?");
$stmt->execute([$agent_id]);
$agent = $stmt->fetch();

// Get dropdown options from database - CHANGED TO USE gares TABLE
$gares = $pdo->query("SELECT id_gare, libelle FROM gares ORDER BY libelle")->fetchAll(); // Changed from stations to gares
$clients = $pdo->query("SELECT client_id, company_name FROM clients ORDER BY company_name")->fetchAll();
$transaction_types = ['tm01s' => 'TM01S Standard', 'tm02e' => 'TM02E Express'];
$transport_modes = ['wagon_complet' => 'Wagon complet', 'groupage' => 'Groupage'];
$payment_methods = ['port_paye' => 'Port payé', 'port_due' => 'Port dû'];
$weighing_options = ['sans_pesage' => 'Sans pesage', 'avec_pesage' => 'Avec pesage'];
$surcharge_options = ['non_majorable' => 'Non majorable', 'majorable' => 'Majorable'];
$counting_options = ['oui' => 'Oui', 'non' => 'Non'];

// Process form submission
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    try {
        $pdo->beginTransaction();
        
        // Validate and sanitize inputs
        $contract_data = [
            'transaction_type' => $_POST['transaction_type'],
            'shipment_location' => $_POST['shipment_location'],
            'delivery_location' => $_POST['delivery_location'],
            'transport_mode' => $_POST['transport_mode'],
            'payment_method' => $_POST['payment_method'],
            'shipment_number' => generateShipmentNumber(),
            'departure_station' => $_POST['departure_station'], // Now references id_gare
            'destination_station' => $_POST['destination_station'], // Now references id_gare
            'source_connection' => $_POST['source_connection'],
            'destination_connection' => $_POST['destination_connection'],
            'goods_description' => htmlspecialchars($_POST['goods_description']),
            'shipper_client' => $_POST['shipper_client'],
            'consignee_client' => $_POST['consignee_client'],
            'shipment_weight' => (float)$_POST['shipment_weight'],
            'transport_allocation' => htmlspecialchars($_POST['transport_allocation']),
            'shipment_date' => $_POST['shipment_date'],
            'weighing' => $_POST['weighing'],
            'surcharge' => $_POST['surcharge'],
            'weight_to_surcharge' => (float)$_POST['weight_to_surcharge'],
            'total_surcharged_weight' => (float)$_POST['total_surcharged_weight'],
            'counting' => $_POST['counting'],
            'wagon_count' => (int)$_POST['wagon_count'],
            'tarpaulin_count' => (int)$_POST['tarpaulin_count'],
            'total_units' => (int)$_POST['total_units'],
            'accessories' => htmlspecialchars($_POST['accessories']),
            'expenses' => (float)$_POST['expenses'],
            'reimbursement' => (float)$_POST['reimbursement'],
            'paid_freight' => (float)$_POST['paid_freight'],
            'total_freight_due' => calculateTotalFreight($_POST),
            'analytical_allocation' => htmlspecialchars($_POST['analytical_allocation']),
            'sncf_share' => (float)$_POST['sncf_share'],
            'oncf_share' => (float)$_POST['oncf_share'],
            'agent_id' => $agent_id
        ];

        // Insert contract - UPDATED TO USE id_gare REFERENCES
        $stmt = $pdo->prepare("
            INSERT INTO contracts (
                transaction_type, shipment_location, delivery_location, transport_mode, 
                payment_method, shipment_number, gare_expéditrice, gare_destinataire, /* Changed field names */
                source_connection, destination_connection, goods_description, sender_client, /* Changed field name */
                recipient_client, shipment_weight, transport_allocation, shipment_date, /* Changed field name */
                weighing, surcharge, weight_to_surcharge, total_surcharged_weight,
                counting, wagon_count, tarp_count, total_units, accessories, /* Changed field name */
                expenses, reimbursement, paid_port, total_port_due, /* Changed field names */
                analytical_allocation, part_sncf, part_oncf, agent_id, created_at /* Changed field names */
            ) VALUES (
                :transaction_type, :shipment_location, :delivery_location, :transport_mode,
                :payment_method, :shipment_number, :departure_station, :destination_station,
                :source_connection, :destination_connection, :goods_description, :shipper_client,
                :consignee_client, :shipment_weight, :transport_allocation, :shipment_date,
                :weighing, :surcharge, :weight_to_surcharge, :total_surcharged_weight,
                :counting, :wagon_count, :tarpaulin_count, :total_units, :accessories,
                :expenses, :reimbursement, :paid_freight, :total_freight_due,
                :analytical_allocation, :sncf_share, :oncf_share, :agent_id, NOW()
            )
        ");
        $stmt->execute($contract_data);
        
        $pdo->commit();
        $_SESSION['success'] = "Contrat créé avec succès (N° " . $contract_data['shipment_number'] . ")";
        redirect('agent-dashboard.php');
        
    } catch (Exception $e) {
        $pdo->rollBack();
        $error = "Erreur: " . $e->getMessage();
    }
}

// Helper functions (unchanged)
function generateShipmentNumber() {
    return 'SNCFT-' . date('Ymd') . '-' . strtoupper(substr(uniqid(), -6));
}

function calculateTotalFreight($data) {
    $base = (float)$data['shipment_weight'] * 0.25; // Example rate
    if ($data['surcharge'] === 'majorable') {
        $base += (float)$data['weight_to_surcharge'] * 0.1;
    }
    return $base + ((float)$data['expenses'] ?? 0);
}
?>

<!DOCTYPE html>
<html lang="fr">
[... rest of the HTML remains the same until the station dropdowns ...]
                                    
                                    <div class="col-md-6">
                                        <label class="form-label required-field">Gare Expéditrice</label>
                                        <select name="departure_station" class="form-select" required>
                                            <option value="">-- Sélectionnez --</option>
                                            <?php foreach ($gares as $gare): ?>
                                                <option value="<?= $gare['id_gare'] ?>"><?= htmlspecialchars($gare['libelle']) ?></option>
                                            <?php endforeach; ?>
                                        </select>
                                    </div>
                                    
                                    <div class="col-md-6">
                                        <label class="form-label required-field">Gare Destinataire</label>
                                        <select name="destination_station" class="form-select" required>
                                            <option value="">-- Sélectionnez --</option>
                                            <?php foreach ($gares as $gare): ?>
                                                <option value="<?= $gare['id_gare'] ?>"><?= htmlspecialchars($gare['libelle']) ?></option>
                                            <?php endforeach; ?>
                                        </select>
                                    </div>

                                    
                                    <div class="col-md-6">
                                        <label class="form-label">Embranchement Source</label>
                                        <input type="text" name="source_connection" class="form-control">
                                    </div>
                                    
                                    <div class="col-md-6">
                                        <label class="form-label">Embranchement Destination</label>
                                        <input type="text" name="destination_connection" class="form-control">
                                    </div>
                                </div>
                            </div>
                            
                            <!-- Section 3: Goods & Clients -->
                            <div class="form-section">
                                <h5><i class="fas fa-boxes me-2"></i>Marchandise & Clients</h5>
                                <div class="row g-3">
                                    <div class="col-12">
                                        <label class="form-label required-field">Marchandise</label>
                                        <textarea name="goods_description" class="form-control" rows="2" required></textarea>
                                    </div>
                                    
                                    <div class="col-md-6">
                                        <label class="form-label required-field">Client Expéditeur</label>
                                        <div class="input-group">
                                            <select name="shipper_client" class="form-select" required>
                                                <option value="">-- Sélectionnez --</option>
                                                <?php foreach ($clients as $client): ?>
                                                    <option value="<?= $client['client_id'] ?>"><?= htmlspecialchars($client['company_name']) ?></option>
                                                <?php endforeach; ?>
                                            </select>
                                            <button type="button" class="btn btn-outline-secondary" data-bs-toggle="modal" data-bs-target="#newClientModal">
                                                <i class="fas fa-plus"></i>
                                            </button>
                                        </div>
                                    </div>
                                    
                                    <div class="col-md-6">
                                        <label class="form-label required-field">Client Destinataire</label>
                                        <div class="input-group">
                                            <select name="consignee_client" class="form-select" required>
                                                <option value="">-- Sélectionnez --</option>
                                                <?php foreach ($clients as $client): ?>
                                                    <option value="<?= $client['client_id'] ?>"><?= htmlspecialchars($client['company_name']) ?></option>
                                                <?php endforeach; ?>
                                            </select>
                                            <button type="button" class="btn btn-outline-secondary" data-bs-toggle="modal" data-bs-target="#newClientModal">
                                                <i class="fas fa-plus"></i>
                                            </button>
                                        </div>
                                    </div>
                                </div>
                            </div>
                            
                            <!-- Section 4: Weight & Dates -->
                            <div class="form-section">
                                <h5><i class="fas fa-weight me-2"></i>Poids & Dates</h5>
                                <div class="row g-3">
                                    <div class="col-md-3">
                                        <label class="form-label required-field">Poids Expédition (KG)</label>
                                        <input type="number" name="shipment_weight" class="form-control" step="0.01" min="0" required>
                                    </div>
                                    
                                    <div class="col-md-3">
                                        <label class="form-label">Imputation Transport</label>
                                        <input type="text" name="transport_allocation" class="form-control">
                                    </div>
                                    
                                    <div class="col-md-3">
                                        <label class="form-label required-field">Date d'expédition</label>
                                        <input type="date" name="shipment_date" class="form-control" required>
                                    </div>
                                    
                                    <div class="col-md-3">
                                        <label class="form-label required-field">Pesage</label>
                                        <select name="weighing" class="form-select" required>
                                            <option value="">-- Sélectionnez --</option>
                                            <?php foreach ($weighing_options as $value => $label): ?>
                                                <option value="<?= $value ?>"><?= $label ?></option>
                                            <?php endforeach; ?>
                                        </select>
                                    </div>
                                </div>
                            </div>
                            
                            <!-- Section 5: Surcharge -->
                            <div class="form-section">
                                <h5><i class="fas fa-percentage me-2"></i>Majoration</h5>
                                <div class="row g-3">
                                    <div class="col-md-3">
                                        <label class="form-label required-field">Majoration</label>
                                        <select name="surcharge" class="form-select" required>
                                            <option value="">-- Sélectionnez --</option>
                                            <?php foreach ($surcharge_options as $value => $label): ?>
                                                <option value="<?= $value ?>"><?= $label ?></option>
                                            <?php endforeach; ?>
                                        </select>
                                    </div>
                                    
                                    <div class="col-md-3">
                                        <label class="form-label">Poids à Majorer (KG)</label>
                                        <input type="number" name="weight_to_surcharge" class="form-control" step="0.01" min="0">
                                    </div>
                                    
                                    <div class="col-md-3">
                                        <label class="form-label">Poids Total Majoré (KG)</label>
                                        <input type="number" name="total_surcharged_weight" class="form-control" step="0.01" min="0" readonly>
                                    </div>
                                    
                                    <div class="col-md-3">
                                        <label class="form-label required-field">Comptage</label>
                                        <select name="counting" class="form-select" required>
                                            <option value="">-- Sélectionnez --</option>
                                            <?php foreach ($counting_options as $value => $label): ?>
                                                <option value="<?= $value ?>"><?= $label ?></option>
                                            <?php endforeach; ?>
                                        </select>
                                    </div>
                                </div>
                            </div>
                            
                            <!-- Section 6: Wagon Details -->
                            <div class="form-section">
                                <h5><i class="fas fa-train me-2"></i>Détails Wagon</h5>
                                <div class="row g-3">
                                    <div class="col-md-3">
                                        <label class="form-label">Nombre Wagon</label>
                                        <input type="number" name="wagon_count" class="form-control" min="0">
                                    </div>
                                    
                                    <div class="col-md-3">
                                        <label class="form-label">Nombre Baches</label>
                                        <input type="number" name="tarpaulin_count" class="form-control" min="0">
                                    </div>
                                    
                                    <div class="col-md-3">
                                        <label class="form-label">Total Unités</label>
                                        <input type="number" name="total_units" class="form-control" min="0">
                                    </div>
                                    
                                    <div class="col-md-3">
                                        <label class="form-label">Accessoires</label>
                                        <input type="text" name="accessories" class="form-control">
                                    </div>
                                </div>
                            </div>
                            
                            <!-- Section 7: Financial -->
                            <div class="form-section">
                                <h5><i class="fas fa-money-bill-wave me-2"></i>Détails Financiers</h5>
                                <div class="row g-3">
                                    <div class="col-md-3">
                                        <label class="form-label">Débours (€)</label>
                                        <input type="number" name="expenses" class="form-control" step="0.01" min="0">
                                    </div>
                                    
                                    <div class="col-md-3">
                                        <label class="form-label">Remboursement (€)</label>
                                        <input type="number" name="reimbursement" class="form-control" step="0.01" min="0">
                                    </div>
                                    
                                    <div class="col-md-3">
                                        <label class="form-label">Port Payé (€)</label>
                                        <input type="number" name="paid_freight" class="form-control" step="0.01" min="0">
                                    </div>
                                    
                                    <div class="col-md-3">
                                        <label class="form-label">Total Port Du (€)</label>
                                        <input type="number" name="total_freight_due" class="form-control" step="0.01" min="0" readonly>
                                    </div>
                                    
                                    <div class="col-md-4">
                                        <label class="form-label">Imputation Analytique</label>
                                        <input type="text" name="analytical_allocation" class="form-control">
                                    </div>
                                    
                                    <div class="col-md-4">
                                        <label class="form-label">Part SNCF (€)</label>
                                        <input type="number" name="sncf_share" class="form-control" step="0.01" min="0">
                                    </div>
                                    
                                    <div class="col-md-4">
                                        <label class="form-label">Part ONCF (€)</label>
                                        <input type="number" name="oncf_share" class="form-control" step="0.01" min="0">
                                    </div>
                                </div>
                            </div>
                            
                            <!-- Form Actions -->
                            <div class="d-flex justify-content-end gap-3 mt-4">
                                <button type="reset" class="btn btn-outline-secondary px-4">
                                    <i class="fas fa-eraser me-1"></i> Annuler
                                </button>
                                <button type="submit" class="btn btn-success px-4">
                                    <i class="fas fa-check-circle me-1"></i> Valider
                                </button>
                            </div>
                        </form>
                    </div>
                </div>
            </div>
        </div>
    </div>

    <!-- New Client Modal -->
    <div class="modal fade" id="newClientModal" tabindex="-1" aria-hidden="true">
        <div class="modal-dialog">
            <div class="modal-content">
                <div class="modal-header">
                    <h5 class="modal-title">Nouveau Client</h5>
                    <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
                </div>
                <div class="modal-body">
                    <form id="newClientForm">
                        <div class="mb-3">
                            <label class="form-label">Nom de l'entreprise</label>
                            <input type="text" class="form-control" required>
                        </div>
                        <div class="mb-3">
                            <label class="form-label">Contact</label>
                            <input type="text" class="form-control" required>
                        </div>
                        <div class="mb-3">
                            <label class="form-label">Email</label>
                            <input type="email" class="form-control" required>
                        </div>
                    </form>
                </div>
                <div class="modal-footer">
                    <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Fermer</button>
                    <button type="button" class="btn btn-primary">Enregistrer</button>
                </div>
            </div>
        </div>
    </div>

    <!-- Bootstrap & JS -->
    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/js/bootstrap.bundle.min.js"></script>
    <script>
        // Form validation
        (function () {
            'use strict'
            const forms = document.querySelectorAll('.needs-validation')
            Array.from(forms).forEach(form => {
                form.addEventListener('submit', event => {
                    if (!form.checkValidity()) {
                        event.preventDefault()
                        event.stopPropagation()
                    }
                    form.classList.add('was-validated')
                }, false)
            })
        })()
        
        // Dynamic calculations
        document.addEventListener('DOMContentLoaded', function() {
            // Surcharge calculation
            const surchargeSelect = document.querySelector('select[name="surcharge"]');
            const weightToSurcharge = document.querySelector('input[name="weight_to_surcharge"]');
            const totalSurcharged = document.querySelector('input[name="total_surcharged_weight"]');
            const shipmentWeight = document.querySelector('input[name="shipment_weight"]');
            
            surchargeSelect.addEventListener('change', updateSurcharge);
            weightToSurcharge.addEventListener('input', updateSurcharge);
            shipmentWeight.addEventListener('input', updateSurcharge);
            
            function updateSurcharge() {
                if (surchargeSelect.value === 'majorable' && weightToSurcharge.value && shipmentWeight.value) {
                    const total = parseFloat(shipmentWeight.value) + parseFloat(weightToSurcharge.value);
                    totalSurcharged.value = total.toFixed(2);
                } else {
                    totalSurcharged.value = shipmentWeight.value || '';
                }
            }
            
            // Total freight calculation
            const expensesInput = document.querySelector('input[name="expenses"]');
            const paidFreightInput = document.querySelector('input[name="paid_freight"]');
            const totalFreightInput = document.querySelector('input[name="total_freight_due"]');
            
            [shipmentWeight, weightToSurcharge, surchargeSelect, expensesInput, paidFreightInput].forEach(el => {
                el.addEventListener('change', updateTotalFreight);
                el.addEventListener('input', updateTotalFreight);
            });
            
            function updateTotalFreight() {
                const weight = parseFloat(shipmentWeight.value) || 0;
                const surchargeWeight = parseFloat(weightToSurcharge.value) || 0;
                const expenses = parseFloat(expensesInput.value) || 0;
                const paidFreight = parseFloat(paidFreightInput.value) || 0;
                
                let total = weight * 0.25; // Base rate
                if (surchargeSelect.value === 'majorable') {
                    total += surchargeWeight * 0.1;
                }
                total += expenses;
                
                totalFreightInput.value = total.toFixed(2);
            }
        });
    </script>
</body>
</html>