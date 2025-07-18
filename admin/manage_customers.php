<?php
session_start();
error_reporting(0);
include('includes/dbconnection.php');

// Check admin login
if (!isset($_SESSION['imsaid']) || strlen($_SESSION['imsaid']) == 0) {
    header('location:logout.php');
    exit;
}

// 🔥 NOUVELLE FONCTIONNALITÉ : Traitement des paiements (adapté à votre structure)
if (isset($_POST['add_payment'])) {
    $invoiceId = intval($_POST['invoice_id']);
    $paymentAmount = intval($_POST['payment_amount']); // int selon votre structure
    $paymentMethod = mysqli_real_escape_string($con, $_POST['payment_method']);
    $referenceNumber = mysqli_real_escape_string($con, $_POST['reference_number']);
    $comments = mysqli_real_escape_string($con, $_POST['comments']);
    
    // Récupérer les infos de la facture
    $invoiceQuery = "SELECT * FROM tblcustomer WHERE ID = '$invoiceId'";
    $invoiceResult = mysqli_query($con, $invoiceQuery);
    $invoice = mysqli_fetch_assoc($invoiceResult);
    
    if ($invoice && $paymentAmount > 0 && $paymentAmount <= $invoice['Dues']) {
        // Enregistrer le paiement avec votre structure existante
        $insertPayment = "INSERT INTO tblpayments (CustomerID, BillingNumber, PaymentAmount, PaymentMethod, ReferenceNumber, Comments, PaymentDate) 
                         VALUES ('$invoiceId', '{$invoice['BillingNumber']}', '$paymentAmount', '$paymentMethod', '$referenceNumber', '$comments', NOW())";
        
        if (mysqli_query($con, $insertPayment)) {
            // Mettre à jour la facture
            $newPaid = $invoice['Paid'] + $paymentAmount;
            $newDues = $invoice['Dues'] - $paymentAmount;
            
            $updateInvoice = "UPDATE tblcustomer SET Paid = '$newPaid', Dues = '$newDues' WHERE ID = '$invoiceId'";
            
            if (mysqli_query($con, $updateInvoice)) {
                $successMessage = "💰 Paiement de " . number_format($paymentAmount, 0, ',', ' ') . " GNF enregistré avec succès !";
                
                // Mettre à jour le master client si il existe
                if (!empty($invoice['customer_master_id'])) {
                    $updateMaster = "UPDATE tblcustomer_master SET 
                                    TotalDues = TotalDues - '$paymentAmount',
                                    LastPaymentDate = NOW()
                                    WHERE id = '{$invoice['customer_master_id']}'";
                    mysqli_query($con, $updateMaster);
                }
            } else {
                $errorMessage = "❌ Erreur lors de la mise à jour de la facture: " . mysqli_error($con);
            }
        } else {
            $errorMessage = "❌ Erreur lors de l'enregistrement du paiement: " . mysqli_error($con);
        }
    } else {
        $errorMessage = "❌ Montant de paiement invalide (doit être entre 1 et " . number_format($invoice['Dues'], 0, ',', ' ') . " GNF)";
    }
}

// 🔥 NOUVELLE FONCTIONNALITÉ : Filtres avancés
$whereClause = "WHERE 1=1";
$searchTerm = '';
$statusFilter = '';
$dateFrom = '';
$dateTo = '';

if (!empty($_GET['search'])) {
    $searchTerm = mysqli_real_escape_string($con, $_GET['search']);
    $whereClause .= " AND (CustomerName LIKE '%$searchTerm%' OR BillingNumber LIKE '%$searchTerm%' OR MobileNumber LIKE '%$searchTerm%')";
}

if (!empty($_GET['status'])) {
    $statusFilter = $_GET['status'];
    if ($statusFilter === 'paid') {
        $whereClause .= " AND Dues <= 0";
    } elseif ($statusFilter === 'pending') {
        $whereClause .= " AND Dues > 0";
    } elseif ($statusFilter === 'partial') {
        $whereClause .= " AND Paid > 0 AND Dues > 0";
    }
}

if (!empty($_GET['date_from'])) {
    $dateFrom = $_GET['date_from'];
    $whereClause .= " AND DATE(BillingDate) >= '$dateFrom'";
}

if (!empty($_GET['date_to'])) {
    $dateTo = $_GET['date_to'];
    $whereClause .= " AND DATE(BillingDate) <= '$dateTo'";
}

// Pagination
$page = isset($_GET['page']) ? max(1, intval($_GET['page'])) : 1;
$recordsPerPage = 15;
$offset = ($page - 1) * $recordsPerPage;

// Compter le total
$countQuery = "SELECT COUNT(*) as total FROM tblcustomer $whereClause";
$countResult = mysqli_query($con, $countQuery);
$totalRecords = mysqli_fetch_assoc($countResult)['total'];
$totalPages = ceil($totalRecords / $recordsPerPage);

// Récupérer les factures
$mainQuery = "SELECT * FROM tblcustomer $whereClause ORDER BY BillingDate DESC LIMIT $offset, $recordsPerPage";
$ret = mysqli_query($con, $mainQuery);
?>
<!DOCTYPE html>
<html lang="fr">
<head>
    <title>Gestion des Factures et Paiements | Système de Gestion</title>
    <?php include_once('includes/cs.php'); ?>
    <?php include_once('includes/responsive.php'); ?>
    <style>
        /* Styles modernes */
        .payment-modal {
            position: fixed;
            top: 0;
            left: 0;
            width: 100%;
            height: 100%;
            background: rgba(0,0,0,0.5);
            display: none;
            justify-content: center;
            align-items: center;
            z-index: 1000;
        }
        
        .payment-modal-content {
            background: white;
            padding: 30px;
            border-radius: 15px;
            max-width: 500px;
            width: 90%;
            max-height: 90vh;
            overflow-y: auto;
            box-shadow: 0 10px 30px rgba(0,0,0,0.3);
        }
        
        .payment-form {
            display: flex;
            flex-direction: column;
            gap: 15px;
        }
        
        .payment-form label {
            font-weight: 600;
            color: #2c3e50;
            margin-bottom: 5px;
        }
        
        .payment-form input,
        .payment-form select,
        .payment-form textarea {
            padding: 12px;
            border: 2px solid #e9ecef;
            border-radius: 8px;
            font-size: 14px;
            transition: border-color 0.2s;
        }
        
        .payment-form input:focus,
        .payment-form select:focus,
        .payment-form textarea:focus {
            border-color: #007bff;
            outline: none;
            box-shadow: 0 0 0 3px rgba(0,123,255,0.1);
        }
        
        .invoice-info {
            background: #f8f9fa;
            padding: 15px;
            border-radius: 8px;
            margin-bottom: 20px;
            border-left: 4px solid #007bff;
        }
        
        .status-badge {
            padding: 6px 12px;
            border-radius: 20px;
            font-size: 11px;
            font-weight: 600;
            text-transform: uppercase;
            letter-spacing: 0.5px;
        }
        
        .status-paid {
            background: linear-gradient(135deg, #d4edda, #c3e6cb);
            color: #155724;
        }
        
        .status-pending {
            background: linear-gradient(135deg, #fff3cd, #ffeaa7);
            color: #856404;
        }
        
        .status-partial {
            background: linear-gradient(135deg, #d1ecf1, #bee5eb);
            color: #0c5460;
        }
        
        .progress-bar-container {
            width: 100px;
            height: 8px;
            background: #e9ecef;
            border-radius: 4px;
            overflow: hidden;
            margin: 5px 0;
        }
        
        .progress-bar {
            height: 100%;
            border-radius: 4px;
            transition: width 0.8s ease;
        }
        
        .progress-bar.low {
            background: linear-gradient(90deg, #dc3545, #e55370);
        }
        
        .progress-bar.medium {
            background: linear-gradient(90deg, #ffc107, #ffcd39);
        }
        
        .progress-bar.high {
            background: linear-gradient(90deg, #28a745, #34ce57);
        }
        
        .filters-section {
            background: linear-gradient(135deg, #f8f9fa 0%, #e9ecef 100%);
            padding: 20px;
            border-radius: 10px;
            margin-bottom: 25px;
            box-shadow: 0 2px 10px rgba(0,0,0,0.05);
        }
        
        .btn-payment {
            background: linear-gradient(135deg, #28a745, #20c997);
            color: white;
            border: none;
            padding: 8px 15px;
            border-radius: 6px;
            font-size: 12px;
            font-weight: 500;
            cursor: pointer;
            transition: all 0.2s;
        }
        
        .btn-payment:hover {
            transform: translateY(-1px);
            box-shadow: 0 4px 12px rgba(40,167,69,0.3);
        }
        
        .btn-view {
            background: linear-gradient(135deg, #007bff, #0056b3);
            color: white;
            border: none;
            padding: 8px 15px;
            border-radius: 6px;
            font-size: 12px;
            text-decoration: none;
            display: inline-block;
            transition: all 0.2s;
        }
        
        .btn-view:hover {
            transform: translateY(-1px);
            box-shadow: 0 4px 12px rgba(0,123,255,0.3);
            color: white;
            text-decoration: none;
        }
        
        .btn-history {
            background: linear-gradient(135deg, #6f42c1, #563d7c);
            color: white;
            border: none;
            padding: 8px 15px;
            border-radius: 6px;
            font-size: 12px;
            text-decoration: none;
            display: inline-block;
            transition: all 0.2s;
        }
        
        .btn-history:hover {
            transform: translateY(-1px);
            box-shadow: 0 4px 12px rgba(111,66,193,0.3);
            color: white;
            text-decoration: none;
        }
        
        .alert-modern {
            padding: 15px 20px;
            border-radius: 8px;
            margin-bottom: 20px;
            display: flex;
            align-items: center;
            gap: 10px;
            font-weight: 500;
        }
        
        .alert-success {
            background: linear-gradient(135deg, #d4edda, #c3e6cb);
            color: #155724;
            border: 1px solid #c3e6cb;
        }
        
        .alert-error {
            background: linear-gradient(135deg, #f8d7da, #f5c6cb);
            color: #721c24;
            border: 1px solid #f5c6cb;
        }
        
        .customer-summary {
            background: linear-gradient(135deg, #fff 0%, #f8f9fa 100%);
            border-radius: 15px;
            padding: 20px;
            margin-bottom: 25px;
            box-shadow: 0 4px 20px rgba(0,0,0,0.1);
        }
        
        .summary-grid {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(200px, 1fr));
            gap: 20px;
        }
        
        .summary-card {
            text-align: center;
            padding: 20px;
            border-radius: 10px;
            position: relative;
            overflow: hidden;
        }
        
        .summary-card::before {
            content: '';
            position: absolute;
            top: 0;
            left: 0;
            right: 0;
            height: 4px;
        }
        
        .summary-card.total::before {
            background: linear-gradient(90deg, #007bff, #0056b3);
        }
        
        .summary-card.paid::before {
            background: linear-gradient(90deg, #28a745, #20c997);
        }
        
        .summary-card.pending::before {
            background: linear-gradient(90deg, #ffc107, #fd7e14);
        }
        
        .summary-card.partial::before {
            background: linear-gradient(90deg, #17a2b8, #138496);
        }
        
        .summary-value {
            font-size: 24px;
            font-weight: bold;
            margin-bottom: 8px;
            color: #2c3e50;
        }
        
        .summary-label {
            font-size: 13px;
            color: #6c757d;
            text-transform: uppercase;
            font-weight: 600;
            letter-spacing: 0.5px;
        }
        
        .summary-count {
            font-size: 14px;
            color: #495057;
            margin-top: 5px;
        }
        
        @media (max-width: 768px) {
            .summary-grid {
                grid-template-columns: 1fr 1fr;
            }
            
            .payment-modal-content {
                width: 95%;
                padding: 20px;
            }
        }
    </style>
</head>
<body>
<?php include_once('includes/header.php'); ?>
<?php include_once('includes/sidebar.php'); ?>

<div id="content">
    <div id="content-header">
        <div id="breadcrumb">
            <a href="dashboard.php" title="Aller à l'accueil" class="tip-bottom">
                <i class="icon-home"></i> Accueil
            </a>
            <a href="customer-details.php" class="current">💰 Factures & Paiements</a>
        </div>
        <h1>💰 Gestion des Factures et Paiements Clients</h1>
    </div>

    <div class="container-fluid">
        <!-- Messages -->
        <?php if (isset($successMessage)): ?>
        <div class="alert-modern alert-success">
            <i class="icon-ok"></i>
            <?php echo $successMessage; ?>
        </div>
        <?php endif; ?>
        
        <?php if (isset($errorMessage)): ?>
        <div class="alert-modern alert-error">
            <i class="icon-warning-sign"></i>
            <?php echo $errorMessage; ?>
        </div>
        <?php endif; ?>

        <!-- Résumé financier avec nouveaux calculs -->
        <div class="customer-summary">
            <?php
            // Calculs avec les nouveaux filtres
            $sqlTotals = "SELECT 
                COUNT(*) as totalInvoices,
                SUM(FinalAmount) as totalSales,
                SUM(Paid) as totalPaid,
                SUM(Dues) as totalDues,
                COUNT(CASE WHEN Dues = 0 THEN 1 END) as paidInvoices,
                COUNT(CASE WHEN Dues > 0 THEN 1 END) as pendingInvoices,
                COUNT(CASE WHEN Paid > 0 AND Dues > 0 THEN 1 END) as partialInvoices,
                AVG(CASE WHEN FinalAmount > 0 THEN (Paid / FinalAmount) * 100 END) as avgPaymentRate
            FROM tblcustomer $whereClause";
            $resTotals = mysqli_query($con, $sqlTotals);
            $rowTotals = mysqli_fetch_assoc($resTotals);
            ?>
            
            <div class="summary-grid">
                <div class="summary-card total">
                    <div class="summary-value"><?php echo number_format($rowTotals['totalSales'], 0, ',', ' '); ?></div>
                    <div class="summary-label">Chiffre d'Affaires Total</div>
                    <div class="summary-count"><?php echo $rowTotals['totalInvoices']; ?> factures</div>
                </div>
                <div class="summary-card paid">
                    <div class="summary-value"><?php echo number_format($rowTotals['totalPaid'], 0, ',', ' '); ?></div>
                    <div class="summary-label">Montants Encaissés</div>
                    <div class="summary-count"><?php echo $rowTotals['paidInvoices']; ?> factures soldées</div>
                </div>
                <div class="summary-card pending">
                    <div class="summary-value"><?php echo number_format($rowTotals['totalDues'], 0, ',', ' '); ?></div>
                    <div class="summary-label">Créances en Attente</div>
                    <div class="summary-count"><?php echo $rowTotals['pendingInvoices']; ?> factures impayées</div>
                </div>
                <div class="summary-card partial">
                    <div class="summary-value"><?php echo round($rowTotals['avgPaymentRate'], 1); ?>%</div>
                    <div class="summary-label">Taux de Recouvrement</div>
                    <div class="summary-count"><?php echo $rowTotals['partialInvoices']; ?> paiements partiels</div>
                </div>
            </div>
        </div>

        <!-- Filtres avancés -->
        <div class="filters-section">
            <form method="GET" class="form-horizontal">
                <div class="row-fluid">
                    <div class="span3">
                        <label>🔍 Rechercher:</label>
                        <input type="text" name="search" placeholder="Client, facture, téléphone..." 
                               value="<?php echo htmlspecialchars($searchTerm); ?>" class="span12">
                    </div>
                    <div class="span2">
                        <label>📊 Statut:</label>
                        <select name="status" class="span12">
                            <option value="">Tous</option>
                            <option value="paid" <?php echo $statusFilter == 'paid' ? 'selected' : ''; ?>>Soldé</option>
                            <option value="partial" <?php echo $statusFilter == 'partial' ? 'selected' : ''; ?>>Partiel</option>
                            <option value="pending" <?php echo $statusFilter == 'pending' ? 'selected' : ''; ?>>En attente</option>
                        </select>
                    </div>
                    <div class="span2">
                        <label>📅 Du:</label>
                        <input type="date" name="date_from" value="<?php echo $dateFrom; ?>" class="span12">
                    </div>
                    <div class="span2">
                        <label>📅 Au:</label>
                        <input type="date" name="date_to" value="<?php echo $dateTo; ?>" class="span12">
                    </div>
                    <div class="span3">
                        <label>&nbsp;</label>
                        <div>
                            <button type="submit" class="btn btn-primary">
                                <i class="icon-search"></i> Filtrer
                            </button>
                            <a href="customer-details.php" class="btn">
                                <i class="icon-refresh"></i> Reset
                            </a>
                        </div>
                    </div>
                </div>
            </form>
        </div>

        <!-- Liste des factures avec paiements -->
        <div class="row-fluid">
            <div class="span12">
                <div class="widget-box">
                    <div class="widget-title">
                        <span class="icon"><i class="icon-credit-card"></i></span>
                        <h5>Factures et Paiements (<?php echo $totalRecords; ?> résultats)</h5>
                    </div>
                    <div class="widget-content nopadding">
                        <table class="table table-bordered table-striped">
                            <thead>
                                <tr>
                                    <th width="5%">N°</th>
                                    <th width="12%">Facture #</th>
                                    <th width="18%">Client</th>
                                    <th width="10%">Date</th>
                                    <th width="12%">Montant Total</th>
                                    <th width="12%">Payé</th>
                                    <th width="12%">Reste à Payer</th>
                                    <th width="10%">Statut</th>
                                    <th width="9%">Actions</th>
                                </tr>
                            </thead>
                            <tbody>
                                <?php
                                if (mysqli_num_rows($ret) > 0) {
                                    $cnt = $offset + 1;
                                    while ($row = mysqli_fetch_array($ret)) {
                                        $finalAmount = floatval($row['FinalAmount']);
                                        $paid = floatval($row['Paid']);
                                        $dues = floatval($row['Dues']);
                                        
                                        // Calculer le pourcentage de paiement
                                        $paymentPercent = $finalAmount > 0 ? ($paid / $finalAmount) * 100 : 0;
                                        
                                        // Déterminer le statut
                                        if ($dues <= 0) {
                                            $status = 'paid';
                                            $statusLabel = 'Soldé';
                                            $statusClass = 'status-paid';
                                        } elseif ($paid > 0) {
                                            $status = 'partial';
                                            $statusLabel = 'Partiel';
                                            $statusClass = 'status-partial';
                                        } else {
                                            $status = 'pending';
                                            $statusLabel = 'En attente';
                                            $statusClass = 'status-pending';
                                        }
                                        
                                        // Classe pour la barre de progression
                                        $progressClass = $paymentPercent < 30 ? 'low' : ($paymentPercent < 80 ? 'medium' : 'high');
                                ?>
                                        <tr>
                                            <td><?php echo $cnt; ?></td>
                                            <td>
                                                <strong><?php echo $row['BillingNumber']; ?></strong>
                                                <?php if ($status == 'partial'): ?>
                                                <div class="progress-bar-container">
                                                    <div class="progress-bar <?php echo $progressClass; ?>" 
                                                         style="width: <?php echo $paymentPercent; ?>%"></div>
                                                </div>
                                                <small><?php echo round($paymentPercent, 1); ?>% payé</small>
                                                <?php endif; ?>
                                            </td>
                                            <td>
                                                <strong><?php echo htmlspecialchars($row['CustomerName']); ?></strong>
                                                <?php if(!empty($row['MobileNumber'])): ?>
                                                    <br><small><i class="icon-phone"></i> <?php echo $row['MobileNumber']; ?></small>
                                                <?php endif; ?>
                                            </td>
                                            <td>
                                                <?php echo date('d/m/Y', strtotime($row['BillingDate'])); ?>
                                                <br><small><?php echo date('H:i', strtotime($row['BillingDate'])); ?></small>
                                            </td>
                                            <td class="text-right">
                                                <strong><?php echo number_format($finalAmount, 0, ',', ' '); ?> GNF</strong>
                                            </td>
                                            <td class="text-right" style="color: #28a745;">
                                                <strong><?php echo number_format($paid, 0, ',', ' '); ?> GNF</strong>
                                            </td>
                                            <td class="text-right" style="color: <?php echo $dues > 0 ? '#dc3545' : '#28a745'; ?>;">
                                                <strong><?php echo number_format($dues, 0, ',', ' '); ?> GNF</strong>
                                            </td>
                                            <td>
                                                <span class="status-badge <?php echo $statusClass; ?>"><?php echo $statusLabel; ?></span>
                                            </td>
                                            <td>
                                                <div style="display: flex; gap: 5px; flex-wrap: wrap;">
                                                    <?php if ($dues > 0): ?>
                                                    <button onclick="openPaymentModal(<?php echo $row['ID']; ?>, '<?php echo addslashes($row['CustomerName']); ?>', '<?php echo $row['BillingNumber']; ?>', <?php echo $dues; ?>)" 
                                                            class="btn-payment" title="Ajouter un paiement">
                                                        <i class="icon-money"></i>
                                                    </button>
                                                    <?php endif; ?>
                                                    <a href="view-customer.php?id=<?php echo $row['ID']; ?>" 
                                                       class="btn-view" title="Voir détails">
                                                        <i class="icon-eye-open"></i>
                                                    </a>
                                                    <a href="payment-history.php?cid=<?php echo $row['ID']; ?>" 
                                                       class="btn-history" title="Historique">
                                                        <i class="icon-time"></i>
                                                    </a>
                                                </div>
                                            </td>
                                        </tr>
                                <?php
                                        $cnt++;
                                    }
                                } else {
                                ?>
                                    <tr>
                                        <td colspan="9" class="text-center" style="padding: 40px; color: #6c757d;">
                                            <i class="icon-info-sign" style="font-size: 24px; margin-bottom: 10px;"></i>
                                            <br>Aucune facture trouvée pour ces critères
                                        </td>
                                    </tr>
                                <?php } ?>
                            </tbody>
                        </table>
                    </div>
                </div>
            </div>
        </div>

        <!-- Pagination -->
        <?php if ($totalPages > 1): ?>
        <div class="pagination pagination-centered">
            <ul>
                <?php if ($page > 1): ?>
                    <li><a href="?<?php echo http_build_query(array_merge($_GET, ['page' => $page-1])); ?>">&laquo;</a></li>
                <?php endif; ?>
                
                <?php for ($i = max(1, $page - 2); $i <= min($totalPages, $page + 2); $i++): ?>
                    <li class="<?php echo $i == $page ? 'active' : ''; ?>">
                        <a href="?<?php echo http_build_query(array_merge($_GET, ['page' => $i])); ?>"><?php echo $i; ?></a>
                    </li>
                <?php endfor; ?>
                
                <?php if ($page < $totalPages): ?>
                    <li><a href="?<?php echo http_build_query(array_merge($_GET, ['page' => $page+1])); ?>">&raquo;</a></li>
                <?php endif; ?>
            </ul>
        </div>
        <?php endif; ?>
    </div>
</div>

<!-- Modal de paiement -->
<div id="paymentModal" class="payment-modal">
    <div class="payment-modal-content">
        <div style="display: flex; justify-content: space-between; align-items: center; margin-bottom: 20px;">
            <h3 style="margin: 0; color: #2c3e50;">💰 Enregistrer un Paiement</h3>
            <button onclick="closePaymentModal()" style="background: none; border: none; font-size: 24px; cursor: pointer;">&times;</button>
        </div>
        
        <div id="invoiceInfo" class="invoice-info"></div>
        
        <form method="POST" class="payment-form">
            <input type="hidden" id="invoiceId" name="invoice_id">
            
            <div>
                <label for="paymentAmount">💵 Montant du Paiement (GNF):</label>
                <input type="number" id="paymentAmount" name="payment_amount" 
                       min="1" step="1" required class="span12">
            </div>
            
            <div>
                <label for="paymentMethod">💳 Méthode de Paiement:</label>
                <select id="paymentMethod" name="payment_method" required class="span12">
                    <option value="">Sélectionner...</option>
                    <option value="Espèces">💵 Espèces</option>
                    <option value="Chèque">🏦 Chèque</option>
                    <option value="Virement">📱 Virement/Mobile Money</option>
                    <option value="Carte">💳 Carte Bancaire</option>
                    <option value="Autre">❓ Autre</option>
                </select>
            </div>
            
            <div>
                <label for="referenceNumber">🔗 Numéro de Référence:</label>
                <input type="text" id="referenceNumber" name="reference_number" 
                       placeholder="Numéro de chèque, référence transaction..." class="span12">
            </div>
            
            <div>
                <label for="comments">📝 Commentaires (optionnel):</label>
                <textarea id="comments" name="comments" rows="3" 
                          placeholder="Détails sur le paiement, observations..." class="span12"></textarea>
            </div>
            
            <div style="display: flex; gap: 10px; justify-content: flex-end; margin-top: 20px;">
                <button type="button" onclick="closePaymentModal()" class="btn">Annuler</button>
                <button type="submit" name="add_payment" class="btn btn-primary">
                    <i class="icon-ok"></i> Enregistrer le Paiement
                </button>
            </div>
        </form>
    </div>
</div>

<?php include_once('includes/footer.php'); ?>

<script src="js/jquery.min.js"></script>
<script src="js/bootstrap.min.js"></script>
<script src="js/matrix.js"></script>

<script>
function openPaymentModal(invoiceId, customerName, billingNumber, duesAmount) {
    // Remplir les informations de la facture
    document.getElementById('invoiceInfo').innerHTML = `
        <h4 style="margin: 0 0 10px 0; color: #007bff;">📄 Facture: ${billingNumber}</h4>
        <p style="margin: 5px 0;"><strong>👤 Client:</strong> ${customerName}</p>
        <p style="margin: 5px 0;"><strong>💰 Montant dû:</strong> ${new Intl.NumberFormat('fr-FR').format(duesAmount)} GNF</p>
    `;
    
    // Remplir les champs du formulaire
    document.getElementById('invoiceId').value = invoiceId;
    document.getElementById('paymentAmount').max = duesAmount;
    document.getElementById('paymentAmount').value = duesAmount; // Pré-remplir avec le montant total dû
    
    // Afficher le modal
    document.getElementById('paymentModal').style.display = 'flex';
    document.getElementById('paymentAmount').focus();
}

function closePaymentModal() {
    document.getElementById('paymentModal').style.display = 'none';
    // Réinitialiser le formulaire avec les bons IDs
    document.querySelector('.payment-form').reset();
    document.getElementById('paymentAmount').style.borderColor = '#e9ecef';
    // Supprimer les messages d'erreur s'ils existent
    const errorMsg = document.querySelector('.payment-form small');
    if (errorMsg) errorMsg.remove();
}

// Fermer le modal en cliquant à l'extérieur
document.getElementById('paymentModal').addEventListener('click', function(e) {
    if (e.target === this) {
        closePaymentModal();
    }
});

// Validation en temps réel du montant
document.getElementById('paymentAmount').addEventListener('input', function() {
    const maxAmount = parseFloat(this.max);
    const currentAmount = parseFloat(this.value);
    
    if (currentAmount > maxAmount) {
        this.style.borderColor = '#dc3545';
        this.nextElementSibling?.remove();
        this.insertAdjacentHTML('afterend', '<small style="color: #dc3545;">Le montant ne peut pas dépasser le montant dû</small>');
    } else {
        this.style.borderColor = '#28a745';
        this.nextElementSibling?.remove();
    }
});

// Animation des barres de progression au chargement
$(document).ready(function() {
    setTimeout(function() {
        $('.progress-bar').each(function() {
            const targetWidth = $(this).css('width');
            $(this).css('width', '0%').animate({
                width: targetWidth
            }, 1000);
        });
    }, 300);
});
</script>
</body>
</html>