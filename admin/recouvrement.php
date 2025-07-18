<?php 
session_start();
error_reporting(E_ALL);
include('includes/dbconnection.php');

// Vérifie que l'admin est connecté
if (empty($_SESSION['imsaid'])) {
    header('Location: logout.php');
    exit;
}

// Fonction SMS pour relances
function sendSmsNotification($to, $message) {
    if (empty($to) || empty($message)) {
        error_log("Nimba SMS Error: Numéro ou message vide");
        return false;
    }
    
    $to = trim($to);
    $to = preg_replace('/[^0-9+]/', '', $to);
    
    if (!preg_match('/^(\+?224)?6[0-9]{8}$/', $to)) {
        error_log("Nimba SMS Error: Format de numéro invalide: $to");
        return false;
    }
    
    if (strlen($message) > 665) {
        error_log("Nimba SMS Error: Message trop long (" . strlen($message) . " caractères)");
        return false;
    }
    
    $url = "https://api.nimbasms.com/v1/messages";
    $service_id = "0b0aa04ddcf33f25a796fc8aac76b66e";
    $secret_token = "Lt-PsM_2LdTPZPtkCmL5DXHiRJVcJRlj8p5nTxQap9iPJoknVoyXGR8uv-wT6aVEErBgJBRoqPbp8cHyKGzqgSw3CkC_ypLH4u8SAV3NjH8";
    $sender_name = "SMS 9080";
    
    $authString = base64_encode($service_id . ":" . $secret_token);
    
    $postData = json_encode([
        "sender_name" => $sender_name,
        "to" => [$to],
        "message" => $message
    ]);
    
    $headers = [
        "Authorization: Basic " . $authString,
        "Content-Type: application/json",
        "Content-Length: " . strlen($postData)
    ];
    
    $ch = curl_init();
    curl_setopt_array($ch, [
        CURLOPT_URL => $url,
        CURLOPT_POST => true,
        CURLOPT_HTTPHEADER => $headers,
        CURLOPT_POSTFIELDS => $postData,
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_SSL_VERIFYPEER => false,
        CURLOPT_TIMEOUT => 30,
        CURLOPT_CONNECTTIMEOUT => 10
    ]);
    
    $response = curl_exec($ch);
    $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    $curlError = curl_error($ch);
    curl_close($ch);
    
    if ($curlError) {
        error_log("Nimba SMS - Curl Error: $curlError");
        return false;
    }
    
    return ($httpCode == 201);
}

// Actions sur les factures
if (isset($_POST['action'])) {
    $action = $_POST['action'];
    
    if ($action === 'marquer_paye') {
        $billingId = mysqli_real_escape_string($con, $_POST['billing_id']);
        $montantPaye = floatval($_POST['montant_paye']);
        
        // Mettre à jour le statut de la facture
        $updateQuery = "UPDATE tblcustomer SET Paid = Paid + $montantPaye, Dues = Dues - $montantPaye WHERE BillingNumber = '$billingId'";
        if (mysqli_query($con, $updateQuery)) {
            // Mettre à jour le statut d'échéance si entièrement payé
            $checkDues = mysqli_query($con, "SELECT Dues FROM tblcustomer WHERE BillingNumber = '$billingId'");
            $duesData = mysqli_fetch_assoc($checkDues);
            
            if ($duesData['Dues'] <= 0) {
                mysqli_query($con, "UPDATE tblcreditcart SET StatutEcheance = 'regle' WHERE BillingId = '$billingId'");
            }
            
            echo "<script>alert('Paiement enregistré avec succès'); window.location='factures_echeance.php';</script>";
        }
        exit;
    }
    
    if ($action === 'envoyer_relance') {
        $billingId = mysqli_real_escape_string($con, $_POST['billing_id']);
        
        // Récupérer les informations de la facture
        $factureQuery = "
            SELECT 
                tc.CustomerName, 
                tc.MobileNumber, 
                tc.BillingNumber, 
                tc.Dues, 
                tcc.DateEcheance
            FROM tblcustomer tc
            LEFT JOIN tblcreditcart tcc ON tcc.BillingId = tc.BillingNumber
            WHERE tc.BillingNumber = '$billingId'
            LIMIT 1
        ";
        
        $factureResult = mysqli_query($con, $factureQuery);
        if ($factureData = mysqli_fetch_assoc($factureResult)) {
            $customerName = $factureData['CustomerName'];
            $mobile = $factureData['MobileNumber'];
            $dues = $factureData['Dues'];
            $dateEcheance = date('d/m/Y', strtotime($factureData['DateEcheance']));
            
            $message = "RELANCE - Bonjour $customerName, votre facture No: $billingId (échéance: $dateEcheance) présente un solde impayé de " . number_format($dues, 0, ',', ' ') . " GNF. Merci de régulariser rapidement.";
            
            $smsResult = sendSmsNotification($mobile, $message);
            
            // Enregistrer la relance
            $tableExists = mysqli_query($con, "SHOW TABLES LIKE 'tbl_sms_logs'");
            if (mysqli_num_rows($tableExists) > 0) {
                $smsLogQuery = "INSERT INTO tbl_sms_logs (recipient, message, status, send_date) VALUES ('$mobile', '" . mysqli_real_escape_string($con, $message) . "', " . ($smsResult ? '1' : '0') . ", NOW())";
                mysqli_query($con, $smsLogQuery);
            }
            
            $statusMessage = $smsResult ? "Relance envoyée avec succès" : "Échec de l'envoi de la relance";
            echo "<script>alert('$statusMessage'); window.location='factures_echeance.php';</script>";
        }
        exit;
    }
}

// Filtres
$statusFilter = $_GET['status'] ?? 'all';
$dateFilter = $_GET['date_filter'] ?? 'all';
$searchTerm = $_GET['search'] ?? '';

// Construction de la requête WHERE - LOGIQUE IDENTIQUE AU TABLEAU HISTORIQUE
$whereConditions = ["tcc.IsCheckOut = 1"];

if ($statusFilter !== 'all') {
    switch ($statusFilter) {
        case 'echu':
            $whereConditions[] = "tcc.DateEcheance < CURDATE() AND tc.Dues > 0";
            break;
        case 'aujourd_hui':
            $whereConditions[] = "tcc.DateEcheance = CURDATE() AND tc.Dues > 0";
            break;
        case 'bientot_echu':
            $whereConditions[] = "tcc.DateEcheance BETWEEN DATE_ADD(CURDATE(), INTERVAL 1 DAY) AND DATE_ADD(CURDATE(), INTERVAL 7 DAY) AND tc.Dues > 0";
            break;
        case 'en_cours':
            $whereConditions[] = "tcc.DateEcheance > DATE_ADD(CURDATE(), INTERVAL 7 DAY) AND tc.Dues > 0";
            break;
        case 'regle':
            $whereConditions[] = "tc.Dues <= 0";
            break;
        case 'sans_echeance':
            $whereConditions[] = "tcc.DateEcheance IS NULL AND tc.Dues > 0";
            break;
    }
}

if ($dateFilter !== 'all') {
    switch ($dateFilter) {
        case 'today':
            $whereConditions[] = "tcc.DateEcheance = CURDATE()";
            break;
        case 'week':
            $whereConditions[] = "tcc.DateEcheance BETWEEN CURDATE() AND DATE_ADD(CURDATE(), INTERVAL 7 DAY)";
            break;
        case 'month':
            $whereConditions[] = "tcc.DateEcheance BETWEEN CURDATE() AND DATE_ADD(CURDATE(), INTERVAL 30 DAY)";
            break;
        case 'overdue':
            $whereConditions[] = "tcc.DateEcheance < CURDATE()";
            break;
    }
}

if (!empty($searchTerm)) {
    $searchTerm = mysqli_real_escape_string($con, $searchTerm);
    $whereConditions[] = "(tc.CustomerName LIKE '%$searchTerm%' OR tc.MobileNumber LIKE '%$searchTerm%' OR tc.BillingNumber LIKE '%$searchTerm%')";
}

$whereClause = "WHERE " . implode(" AND ", $whereConditions);

// Pagination
$page = isset($_GET['page']) ? max(1, intval($_GET['page'])) : 1;
$recordsPerPage = 20;
$offset = ($page - 1) * $recordsPerPage;

// Requête principale avec LOGIQUE IDENTIQUE AU TABLEAU HISTORIQUE
$mainQuery = "
    SELECT 
        tc.ID,
        tc.BillingNumber,
        tc.CustomerName,
        tc.MobileNumber,
        tc.FinalAmount,
        tc.Paid,
        tc.Dues,
        tc.BillingDate,
        tc.ModeOfPayment,
        tcc.DateEcheance,
        tcc.TypeEcheance,
        tcc.StatutEcheance,
        tcm.CustomerEmail,
        tcm.CustomerAddress,
        DATEDIFF(CURDATE(), tcc.DateEcheance) as JoursRetard,
        -- CALCUL IDENTIQUE AU SYSTÈME D'ORIGINE
        CASE 
            WHEN tc.Dues <= 0 THEN 'Réglé'
            WHEN tcc.DateEcheance IS NULL THEN 'Sans échéance'
            WHEN tcc.DateEcheance < CURDATE() THEN 'Échu'
            WHEN tcc.DateEcheance = CURDATE() THEN 'Échéance aujourd\'hui'
            WHEN DATEDIFF(tcc.DateEcheance, CURDATE()) <= 7 THEN 'Bientôt échu'
            ELSE 'En cours'
        END as StatutFacture
    FROM tblcustomer tc
    LEFT JOIN tblcreditcart tcc ON tcc.BillingId = tc.BillingNumber
    LEFT JOIN tblcustomer_master tcm ON tcm.id = tc.customer_master_id
    $whereClause
    -- TRI IDENTIQUE AU SYSTÈME D'ORIGINE
    ORDER BY 
        CASE 
            WHEN tc.Dues > 0 AND tcc.DateEcheance < CURDATE() THEN 1
            WHEN tc.Dues > 0 AND tcc.DateEcheance = CURDATE() THEN 2
            WHEN tc.Dues > 0 AND DATEDIFF(tcc.DateEcheance, CURDATE()) <= 7 THEN 3
            ELSE 4
        END,
        tcc.DateEcheance ASC
    LIMIT $offset, $recordsPerPage
";

$result = mysqli_query($con, $mainQuery);

// Compter le total pour la pagination
$countQuery = "
    SELECT COUNT(DISTINCT tc.BillingNumber) as total
    FROM tblcustomer tc
    LEFT JOIN tblcreditcart tcc ON tcc.BillingId = tc.BillingNumber
    LEFT JOIN tblcustomer_master tcm ON tcm.id = tc.customer_master_id
    $whereClause
";
$countResult = mysqli_query($con, $countQuery);
$totalRecords = mysqli_fetch_assoc($countResult)['total'];
$totalPages = ceil($totalRecords / $recordsPerPage);

// Statistiques générales avec MÊME LOGIQUE
$statsQuery = "
    SELECT 
        COUNT(DISTINCT CASE WHEN tc.Dues > 0 AND tcc.DateEcheance < CURDATE() THEN tc.BillingNumber END) as factures_echues,
        COUNT(DISTINCT CASE WHEN tc.Dues > 0 AND tcc.DateEcheance = CURDATE() THEN tc.BillingNumber END) as factures_aujourd_hui,
        COUNT(DISTINCT CASE WHEN tc.Dues > 0 AND DATEDIFF(tcc.DateEcheance, CURDATE()) BETWEEN 1 AND 7 THEN tc.BillingNumber END) as factures_bientot,
        COUNT(DISTINCT CASE WHEN tc.Dues > 0 AND tcc.DateEcheance IS NULL THEN tc.BillingNumber END) as factures_sans_echeance,
        COUNT(DISTINCT CASE WHEN tc.Dues <= 0 THEN tc.BillingNumber END) as factures_reglees,
        SUM(CASE WHEN tc.Dues > 0 AND tcc.DateEcheance < CURDATE() THEN tc.Dues ELSE 0 END) as montant_echu,
        SUM(CASE WHEN tc.Dues > 0 THEN tc.Dues ELSE 0 END) as total_creances
    FROM tblcustomer tc
    LEFT JOIN tblcreditcart tcc ON tcc.BillingId = tc.BillingNumber
    WHERE tcc.IsCheckOut = 1
";
$statsResult = mysqli_query($con, $statsQuery);
$stats = mysqli_fetch_assoc($statsResult);
?>

<!DOCTYPE html>
<html lang="fr">
<head>
    <title>Gestion des Échéances | Système de Gestion d'Inventaire</title>
    <?php include_once('includes/cs.php'); ?>
    <?php include_once('includes/responsive.php'); ?>
    <style>
        /* ========== STYLES IDENTIQUES AU SYSTÈME D'ORIGINE ========== */
        .stats-dashboard {
            display: flex;
            gap: 15px;
            margin-bottom: 20px;
            flex-wrap: wrap;
        }
        
        .stat-card {
            flex: 1;
            min-width: 180px;
            background: #fff;
            border-radius: 8px;
            padding: 15px;
            box-shadow: 0 2px 4px rgba(0,0,0,0.1);
            border-left: 4px solid;
            text-align: center;
        }
        
        .stat-card.danger { border-left-color: #dc3545; }
        .stat-card.warning { border-left-color: #ffc107; }
        .stat-card.info { border-left-color: #17a2b8; }
        .stat-card.success { border-left-color: #28a745; }
        .stat-card.secondary { border-left-color: #6c757d; }
        
        .stat-value {
            font-size: 20px;
            font-weight: bold;
            margin-bottom: 5px;
        }
        
        .stat-label {
            font-size: 11px;
            color: #666;
            text-transform: uppercase;
        }
        
        .filters-section {
            background: #f8f9fa;
            padding: 15px;
            border-radius: 5px;
            margin-bottom: 20px;
        }
        
        /* BADGES DE STATUT - IDENTIQUES AU SYSTÈME D'ORIGINE */
        .status-badge {
            padding: 3px 8px;
            border-radius: 12px;
            font-size: 10px;
            font-weight: bold;
            text-transform: uppercase;
        }
        
        .status-regle { background: #28a745; color: white; }
        .status-echu { background: #dc3545; color: white; }
        .status-aujourd-hui { background: #ffc107; color: #212529; }
        .status-bientot { background: #17a2b8; color: white; }
        .status-en-cours { background: #6c757d; color: white; }
        .status-sans { background: #e9ecef; color: #495057; }
        
        /* CLASSES DE LIGNES - IDENTIQUES AU SYSTÈME D'ORIGINE */
        .facture-echue {
            background-color: #fff5f5 !important;
            border-left: 3px solid #dc3545;
        }
        
        .facture-attention {
            background-color: #fffbf0 !important;
            border-left: 3px solid #ffc107;
        }
        
        .montant-du {
            color: #dc3545;
            font-weight: bold;
        }
        
        .montant-ok {
            color: #28a745;
            font-weight: bold;
        }
        
        .retard-info {
            color: #dc3545;
            font-size: 11px;
            font-weight: bold;
        }
        
        .echeance-info {
            font-size: 12px;
            color: #666;
        }
        
        .action-buttons {
            display: flex;
            gap: 5px;
            flex-wrap: wrap;
        }
        
        .btn-mini {
            padding: 2px 6px;
            font-size: 11px;
        }
        
        .modal {
            display: none;
            position: fixed;
            z-index: 1000;
            left: 0;
            top: 0;
            width: 100%;
            height: 100%;
            background-color: rgba(0,0,0,0.5);
        }
        
        .modal-content {
            background-color: #fefefe;
            margin: 10% auto;
            padding: 20px;
            border: 1px solid #888;
            width: 50%;
            border-radius: 5px;
        }
        
        .close {
            color: #aaa;
            float: right;
            font-size: 28px;
            font-weight: bold;
            cursor: pointer;
        }
        
        .close:hover {
            color: black;
        }
        
        .pagination {
            text-align: center;
            margin-top: 20px;
        }
        
        .pagination a {
            display: inline-block;
            padding: 8px 16px;
            margin: 0 4px;
            text-decoration: none;
            border: 1px solid #ddd;
            color: #007bff;
            border-radius: 4px;
        }
        
        .pagination a.active {
            background-color: #007bff;
            color: white;
            border: 1px solid #007bff;
        }
        
        .pagination a:hover:not(.active) {
            background-color: #ddd;
        }
        
        @media (max-width: 768px) {
            .stats-dashboard {
                flex-direction: column;
            }
            
            .action-buttons {
                flex-direction: column;
            }
            
            .modal-content {
                width: 90%;
                margin: 5% auto;
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
                <a href="dashboard.php" class="tip-bottom">
                    <i class="icon-home"></i> Accueil
                </a>
                <a href="factures_echeance.php" class="current">Gestion des Échéances</a>
            </div>
            <h1>Gestion des Factures à Échéance</h1>
        </div>
        
        <div class="container-fluid">
            <!-- Tableau de bord des statistiques - AMÉLIORÉ -->
            <div class="stats-dashboard">
                <div class="stat-card danger">
                    <div class="stat-value"><?php echo $stats['factures_echues']; ?></div>
                    <div class="stat-label">Factures Échues</div>
                </div>
                <div class="stat-card warning">
                    <div class="stat-value"><?php echo $stats['factures_aujourd_hui']; ?></div>
                    <div class="stat-label">Échéance Aujourd'hui</div>
                </div>
                <div class="stat-card info">
                    <div class="stat-value"><?php echo $stats['factures_bientot']; ?></div>
                    <div class="stat-label">Bientôt Échues (7j)</div>
                </div>
                <div class="stat-card secondary">
                    <div class="stat-value"><?php echo $stats['factures_sans_echeance']; ?></div>
                    <div class="stat-label">Sans Échéance</div>
                </div>
                <div class="stat-card success">
                    <div class="stat-value"><?php echo $stats['factures_reglees']; ?></div>
                    <div class="stat-label">Factures Réglées</div>
                </div>
                <div class="stat-card danger">
                    <div class="stat-value"><?php echo number_format($stats['montant_echu']); ?> GNF</div>
                    <div class="stat-label">Montant Échu</div>
                </div>
            </div>

            <!-- Section de filtres - AMÉLIORÉE -->
            <div class="filters-section">
                <form method="get" class="form-inline">
                    <div class="row-fluid">
                        <div class="span3">
                            <label>Statut :</label>
                            <select name="status" class="span12">
                                <option value="all" <?php echo $statusFilter == 'all' ? 'selected' : ''; ?>>Tous</option>
                                <option value="echu" <?php echo $statusFilter == 'echu' ? 'selected' : ''; ?>>Échues</option>
                                <option value="aujourd_hui" <?php echo $statusFilter == 'aujourd_hui' ? 'selected' : ''; ?>>Échéance Aujourd'hui</option>
                                <option value="bientot_echu" <?php echo $statusFilter == 'bientot_echu' ? 'selected' : ''; ?>>Bientôt Échues</option>
                                <option value="en_cours" <?php echo $statusFilter == 'en_cours' ? 'selected' : ''; ?>>En Cours</option>
                                <option value="sans_echeance" <?php echo $statusFilter == 'sans_echeance' ? 'selected' : ''; ?>>Sans Échéance</option>
                                <option value="regle" <?php echo $statusFilter == 'regle' ? 'selected' : ''; ?>>Réglées</option>
                            </select>
                        </div>
                        <div class="span3">
                            <label>Période :</label>
                            <select name="date_filter" class="span12">
                                <option value="all" <?php echo $dateFilter == 'all' ? 'selected' : ''; ?>>Toutes</option>
                                <option value="today" <?php echo $dateFilter == 'today' ? 'selected' : ''; ?>>Aujourd'hui</option>
                                <option value="week" <?php echo $dateFilter == 'week' ? 'selected' : ''; ?>>Cette semaine</option>
                                <option value="month" <?php echo $dateFilter == 'month' ? 'selected' : ''; ?>>Ce mois</option>
                                <option value="overdue" <?php echo $dateFilter == 'overdue' ? 'selected' : ''; ?>>En retard</option>
                            </select>
                        </div>
                        <div class="span4">
                            <label>Recherche :</label>
                            <input type="text" name="search" value="<?php echo htmlspecialchars($searchTerm); ?>" 
                                   placeholder="Client, téléphone, n° facture..." class="span12">
                        </div>
                        <div class="span2">
                            <label>&nbsp;</label>
                            <button type="submit" class="btn btn-primary span12">
                                <i class="icon-search"></i> Filtrer
                            </button>
                        </div>
                    </div>
                </form>
            </div>

            <!-- Actions rapides -->
            <div class="row-fluid">
                <div class="span12">
                    <a href="dettecart.php" class="btn btn-success">
                        <i class="icon-plus"></i> Nouvelle Vente
                    </a>
                    <a href="add_customer_master.php" class="btn btn-info">
                        <i class="icon-user"></i> Ajouter Client
                    </a>
                    <button onclick="exportToCSV()" class="btn btn-warning">
                        <i class="icon-download"></i> Exporter CSV
                    </button>
                </div>
            </div>
            <hr>

            <!-- Tableau des factures - LOGIQUE IDENTIQUE -->
            <div class="widget-box">
                <div class="widget-title">
                    <span class="icon"><i class="icon-time"></i></span>
                    <h5>Factures à Échéance (<?php echo $totalRecords; ?>)</h5>
                </div>
                <div class="widget-content nopadding">
                    <table class="table table-bordered table-striped" id="facturesTable">
                        <thead>
                            <tr>
                                <th>N° Facture</th>
                                <th>Client</th>
                                <th>Téléphone</th>
                                <th>Date Facture</th>
                                <th>Échéance</th>
                                <th>Montant Final</th>
                                <th>Payé</th>
                                <th>Reste</th>
                                <th>Statut</th>
                                <th>Actions</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php 
                            if (mysqli_num_rows($result) > 0) {
                                while ($facture = mysqli_fetch_assoc($result)) {
                                    $finalAmt = floatval($facture['FinalAmount']);
                                    $paidAmt = floatval($facture['Paid']);
                                    $dueAmt = floatval($facture['Dues']);
                                    $dateEcheance = $facture['DateEcheance'];
                                    $statutFacture = $facture['StatutFacture'];
                                    $joursRetard = $facture['JoursRetard'];

                                    // LOGIQUE IDENTIQUE : Déterminer la classe CSS de la ligne
                                    $rowClass = '';
                                    if ($statutFacture === 'Échu') {
                                        $rowClass = 'facture-echue';
                                    } elseif ($statutFacture === 'Échéance aujourd\'hui' || $statutFacture === 'Bientôt échu') {
                                        $rowClass = 'facture-attention';
                                    }

                                    // LOGIQUE IDENTIQUE : Badge de statut
                                    $statusClass = '';
                                    switch ($statutFacture) {
                                        case 'Réglé': $statusClass = 'status-regle'; break;
                                        case 'Échu': $statusClass = 'status-echu'; break;
                                        case 'Échéance aujourd\'hui': $statusClass = 'status-aujourd-hui'; break;
                                        case 'Bientôt échu': $statusClass = 'status-bientot'; break;
                                        case 'En cours': $statusClass = 'status-en-cours'; break;
                                        default: $statusClass = 'status-sans'; break;
                                    }

                                    $montantClass = $dueAmt > 0 ? 'montant-du' : 'montant-ok';
                                    ?>
                                    <tr class="<?php echo $rowClass; ?>">
                                        <td>
                                            <strong><?php echo $facture['BillingNumber']; ?></strong>
                                            <br><small class="text-muted"><?php echo ucfirst($facture['ModeOfPayment']); ?></small>
                                        </td>
                                        <td>
                                            <?php echo htmlspecialchars($facture['CustomerName']); ?>
                                        </td>
                                        <td>
                                            <a href="tel:<?php echo $facture['MobileNumber']; ?>">
                                                <?php echo $facture['MobileNumber']; ?>
                                            </a>
                                        </td>
                                        <td>
                                            <?php echo date('d/m/Y H:i', strtotime($facture['BillingDate'])); ?>
                                        </td>
                                        <td>
                                            <?php if ($dateEcheance): ?>
                                                <?php echo date('d/m/Y', strtotime($dateEcheance)); ?>
                                                <?php if ($joursRetard > 0): ?>
                                                    <br><span class="retard-info">+<?php echo $joursRetard; ?> jour(s) de retard</span>
                                                <?php elseif ($joursRetard <= 0 && $dueAmt > 0): ?>
                                                    <?php 
                                                    $joursRestants = abs($joursRetard);
                                                    if ($joursRestants <= 7): ?>
                                                        <br><span class="echeance-info">Dans <?php echo $joursRestants; ?> jour(s)</span>
                                                    <?php endif; ?>
                                                <?php endif; ?>
                                            <?php else: ?>
                                                <span class="text-muted">Non définie</span>
                                            <?php endif; ?>
                                        </td>
                                        <td><?php echo number_format($finalAmt, 0); ?> GNF</td>
                                        <td><?php echo number_format($paidAmt, 0); ?> GNF</td>
                                        <td class="<?php echo $montantClass; ?>">
                                            <?php echo number_format($dueAmt, 0); ?> GNF
                                        </td>
                                        <td>
                                            <span class="status-badge <?php echo $statusClass; ?>">
                                                <?php echo $statutFacture; ?>
                                            </span>
                                        </td>
                                        <td>
                                            <div class="action-buttons">
                                                <?php if ($dueAmt > 0): ?>
                                                <button onclick="openPaymentModal('<?php echo $facture['BillingNumber']; ?>', <?php echo $dueAmt; ?>)" 
                                                        class="btn btn-success btn-mini" title="Enregistrer paiement">
                                                    <i class="icon-money"></i>
                                                </button>
                                                <button onclick="sendRelance('<?php echo $facture['BillingNumber']; ?>')" 
                                                        class="btn btn-warning btn-mini" title="Envoyer relance SMS">
                                                    <i class="icon-comment"></i>
                                                </button>
                                                <?php endif; ?>
                                                <a href="invoice_details.php?billingnum=<?php echo $facture['BillingNumber']; ?>" 
                                                   class="btn btn-info btn-mini" title="Voir détails">
                                                    <i class="icon-eye-open"></i>
                                                </a>
                                                <a href="client-details.php?name=<?php echo urlencode($facture['CustomerName']); ?>&mobile=<?php echo $facture['MobileNumber']; ?>" 
                                                   class="btn btn-primary btn-mini" title="Voir compte client">
                                                    <i class="icon-user"></i>
                                                </a>
                                            </div>
                                        </td>
                                    </tr>
                                    <?php
                                }
                            } else {
                                ?>
                                <tr>
                                    <td colspan="10" class="text-center" style="color: #666;">
                                        Aucune facture trouvée pour les critères sélectionnés
                                    </td>
                                </tr>
                                <?php
                            }
                            ?>
                        </tbody>
                    </table>
                </div>
            </div>

            <!-- Pagination -->
            <?php if ($totalPages > 1): ?>
            <div class="pagination">
                <?php if ($page > 1): ?>
                    <a href="?page=<?php echo $page-1; ?>&status=<?php echo $statusFilter; ?>&date_filter=<?php echo $dateFilter; ?>&search=<?php echo urlencode($searchTerm); ?>">&laquo; Précédent</a>
                <?php endif; ?>
                
                <?php
                $start = max(1, $page - 2);
                $end = min($totalPages, $page + 2);
                
                for ($i = $start; $i <= $end; $i++): ?>
                    <a href="?page=<?php echo $i; ?>&status=<?php echo $statusFilter; ?>&date_filter=<?php echo $dateFilter; ?>&search=<?php echo urlencode($searchTerm); ?>" 
                       class="<?php echo $i == $page ? 'active' : ''; ?>">
                        <?php echo $i; ?>
                    </a>
                <?php endfor; ?>
                
                <?php if ($page < $totalPages): ?>
                    <a href="?page=<?php echo $page+1; ?>&status=<?php echo $statusFilter; ?>&date_filter=<?php echo $dateFilter; ?>&search=<?php echo urlencode($searchTerm); ?>">Suivant &raquo;</a>
                <?php endif; ?>
            </div>
            <?php endif; ?>
        </div>
    </div>

    <!-- Modal de paiement -->
    <div id="paymentModal" class="modal">
        <div class="modal-content">
            <span class="close" onclick="closePaymentModal()">&times;</span>
            <h3>Enregistrer un Paiement</h3>
            <form method="post" id="paymentForm">
                <input type="hidden" name="action" value="marquer_paye">
                <input type="hidden" name="billing_id" id="payment_billing_id">
                
                <div class="control-group">
                    <label>Facture N° :</label>
                    <span id="payment_billing_display"></span>
                </div>
                
                <div class="control-group">
                    <label>Montant dû :</label>
                    <span id="payment_due_display"></span> GNF
                </div>
                
                <div class="control-group">
                    <label>Montant payé :</label>
                    <input type="number" name="montant_paye" id="montant_paye" step="any" min="0" required class="span11">
                </div>
                
                <div class="form-actions">
                    <button type="submit" class="btn btn-primary">
                        <i class="icon-ok"></i> Enregistrer le Paiement
                    </button>
                    <button type="button" onclick="closePaymentModal()" class="btn">Annuler</button>
                </div>
            </form>
        </div>
    </div>

    <?php include_once('includes/footer.php'); ?>
    
    <script src="js/jquery.min.js"></script>
    <script src="js/bootstrap.min.js"></script>
    <script src="js/matrix.js"></script>
    
    <script>
        // Gestion du modal de paiement
        function openPaymentModal(billingId, dueAmount) {
            document.getElementById('payment_billing_id').value = billingId;
            document.getElementById('payment_billing_display').textContent = billingId;
            document.getElementById('payment_due_display').textContent = new Intl.NumberFormat().format(dueAmount);
            document.getElementById('montant_paye').value = dueAmount;
            document.getElementById('montant_paye').max = dueAmount;
            document.getElementById('paymentModal').style.display = 'block';
        }
        
        function closePaymentModal() {
            document.getElementById('paymentModal').style.display = 'none';
        }
        
        // Envoyer une relance SMS
        function sendRelance(billingId) {
            if (confirm('Êtes-vous sûr de vouloir envoyer une relance SMS pour la facture ' + billingId + ' ?')) {
                const form = document.createElement('form');
                form.method = 'post';
                form.style.display = 'none';
                
                const actionInput = document.createElement('input');
                actionInput.name = 'action';
                actionInput.value = 'envoyer_relance';
                form.appendChild(actionInput);
                
                const billingInput = document.createElement('input');
                billingInput.name = 'billing_id';
                billingInput.value = billingId;
                form.appendChild(billingInput);
                
                document.body.appendChild(form);
                form.submit();
            }
        }
        
        // Export CSV
        function exportToCSV() {
            const table = document.getElementById('facturesTable');
            let csv = [];
            
            // Headers
            const headers = [];
            table.querySelectorAll('thead th').forEach(th => {
                headers.push('"' + th.textContent.trim() + '"');
            });
            csv.push(headers.join(','));
            
            // Rows
            table.querySelectorAll('tbody tr').forEach(tr => {
                const row = [];
                tr.querySelectorAll('td').forEach((td, index) => {
                    if (index < 9) { // Exclure la colonne Actions
                        row.push('"' + td.textContent.trim().replace(/"/g, '""') + '"');
                    }
                });
                if (row.length > 0) {
                    csv.push(row.join(','));
                }
            });
            
            // Download
            const csvContent = csv.join('\n');
            const blob = new Blob([csvContent], { type: 'text/csv;charset=utf-8;' });
            const link = document.createElement('a');
            
            if (link.download !== undefined) {
                const url = URL.createObjectURL(blob);
                link.setAttribute('href', url);
                link.setAttribute('download', 'factures_echeance_' + new Date().toISOString().split('T')[0] + '.csv');
                link.style.visibility = 'hidden';
                document.body.appendChild(link);
                link.click();
                document.body.removeChild(link);
            }
        }
        
        // Fermer le modal en cliquant à l'extérieur
        window.onclick = function(event) {
            const modal = document.getElementById('paymentModal');
            if (event.target == modal) {
                closePaymentModal();
            }
        }
        
        // Auto-refresh every 5 minutes for real-time updates
        setInterval(function() {
            // Only refresh if user is not interacting
            if (document.hidden === false) {
                location.reload();
            }
        }, 300000); // 5 minutes
    </script>
</body>
</html>