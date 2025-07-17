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
                
                // Mettre à jour les statistiques du customer_master
                $updateMasterQuery = "UPDATE tblcustomer_master cm 
                    SET cm.TotalDues = COALESCE((SELECT SUM(Dues) FROM tblcustomer WHERE customer_master_id = cm.id), 0)
                    WHERE cm.id = (SELECT customer_master_id FROM tblcustomer WHERE BillingNumber = '$billingId' LIMIT 1)";
                mysqli_query($con, $updateMasterQuery);
            }
            
            $message = 'Paiement enregistré avec succès';
            $messageType = 'success';
        } else {
            $message = 'Erreur lors de l\'enregistrement du paiement';
            $messageType = 'error';
        }
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
            
            $message_sms = "RELANCE - Bonjour $customerName, votre facture No: $billingId (échéance: $dateEcheance) présente un solde impayé de " . number_format($dues, 0, ',', ' ') . " GNF. Merci de régulariser rapidement.";
            
            $smsResult = sendSmsNotification($mobile, $message_sms);
            
            // Enregistrer la relance
            $tableExists = mysqli_query($con, "SHOW TABLES LIKE 'tbl_sms_logs'");
            if (mysqli_num_rows($tableExists) > 0) {
                $smsLogQuery = "INSERT INTO tbl_sms_logs (recipient, message, status, send_date) VALUES ('$mobile', '" . mysqli_real_escape_string($con, $message_sms) . "', " . ($smsResult ? '1' : '0') . ", NOW())";
                mysqli_query($con, $smsLogQuery);
            }
            
            $message = $smsResult ? "Relance envoyée avec succès" : "Échec de l'envoi de la relance";
            $messageType = $smsResult ? 'success' : 'error';
        }
    }
}

// Filtres
$statusFilter = $_GET['status'] ?? 'all';
$dateFilter = $_GET['date_filter'] ?? 'all';
$typeFilter = $_GET['type_filter'] ?? 'all';
$searchTerm = $_GET['search'] ?? '';
$dateFrom = $_GET['date_from'] ?? '';
$dateTo = $_GET['date_to'] ?? '';

// Construction de la requête WHERE
$whereConditions = ["tcc.IsCheckOut = 1"];

// Filtre par statut d'échéance
if ($statusFilter !== 'all') {
    switch ($statusFilter) {
        case 'en_cours':
            $whereConditions[] = "tcc.StatutEcheance = 'en_cours' AND tc.Dues > 0 AND tcc.DateEcheance >= CURDATE()";
            break;
        case 'echu':
            $whereConditions[] = "tcc.DateEcheance < CURDATE() AND tc.Dues > 0";
            break;
        case 'bientot_echu':
            $whereConditions[] = "tcc.DateEcheance BETWEEN CURDATE() AND DATE_ADD(CURDATE(), INTERVAL 7 DAY) AND tc.Dues > 0";
            break;
        case 'en_retard':
            $whereConditions[] = "tcc.StatutEcheance = 'en_retard' OR (tcc.DateEcheance < CURDATE() AND tc.Dues > 0)";
            break;
        case 'regle':
            $whereConditions[] = "tc.Dues <= 0 OR tcc.StatutEcheance = 'regle'";
            break;
    }
}

// Filtre par période prédéfinie
if ($dateFilter !== 'all') {
    switch ($dateFilter) {
        case 'today':
            $whereConditions[] = "tcc.DateEcheance = CURDATE()";
            break;
        case 'tomorrow':
            $whereConditions[] = "tcc.DateEcheance = DATE_ADD(CURDATE(), INTERVAL 1 DAY)";
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
        case 'past_week':
            $whereConditions[] = "tcc.DateEcheance BETWEEN DATE_SUB(CURDATE(), INTERVAL 7 DAY) AND CURDATE()";
            break;
    }
}

// Filtre par type d'échéance
if ($typeFilter !== 'all') {
    $whereConditions[] = "tcc.TypeEcheance = '$typeFilter'";
}

// Filtre par plage de dates personnalisée
if (!empty($dateFrom)) {
    $whereConditions[] = "tcc.DateEcheance >= '" . mysqli_real_escape_string($con, $dateFrom) . "'";
}
if (!empty($dateTo)) {
    $whereConditions[] = "tcc.DateEcheance <= '" . mysqli_real_escape_string($con, $dateTo) . "'";
}

// Filtre de recherche
if (!empty($searchTerm)) {
    $searchTerm = mysqli_real_escape_string($con, $searchTerm);
    $whereConditions[] = "(tc.CustomerName LIKE '%$searchTerm%' OR tc.MobileNumber LIKE '%$searchTerm%' OR tc.BillingNumber LIKE '%$searchTerm%')";
}

$whereClause = "WHERE " . implode(" AND ", $whereConditions);

// Pagination
$page = isset($_GET['page']) ? max(1, intval($_GET['page'])) : 1;
$recordsPerPage = 25;
$offset = ($page - 1) * $recordsPerPage;

// Requête principale avec informations complètes
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
        tcc.NombreJours,
        tcm.CustomerEmail,
        tcm.CustomerAddress,
        tcm.CreditLimit,
        tcm.TotalDues as CustomerTotalDues,
        DATEDIFF(CURDATE(), tcc.DateEcheance) as JoursRetard,
        CASE 
            WHEN tc.Dues <= 0 THEN 'Réglé'
            WHEN tcc.DateEcheance < CURDATE() THEN 'En retard'
            WHEN tcc.DateEcheance = CURDATE() THEN 'Échéance aujourd\'hui'
            WHEN DATEDIFF(tcc.DateEcheance, CURDATE()) <= 7 THEN 'Bientôt échu'
            ELSE 'En cours'
        END as StatutFacture
    FROM tblcustomer tc
    LEFT JOIN tblcreditcart tcc ON tcc.BillingId = tc.BillingNumber
    LEFT JOIN tblcustomer_master tcm ON tcm.id = tc.customer_master_id
    $whereClause
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

// Statistiques générales et détaillées
$statsQuery = "
    SELECT 
        COUNT(DISTINCT tc.BillingNumber) as total_factures,
        COUNT(DISTINCT CASE WHEN tc.Dues > 0 AND tcc.DateEcheance < CURDATE() THEN tc.BillingNumber END) as factures_echues,
        COUNT(DISTINCT CASE WHEN tc.Dues > 0 AND tcc.DateEcheance = CURDATE() THEN tc.BillingNumber END) as factures_aujourd_hui,
        COUNT(DISTINCT CASE WHEN tc.Dues > 0 AND DATEDIFF(tcc.DateEcheance, CURDATE()) BETWEEN 1 AND 7 THEN tc.BillingNumber END) as factures_bientot,
        COUNT(DISTINCT CASE WHEN tc.Dues <= 0 THEN tc.BillingNumber END) as factures_reglees,
        COUNT(DISTINCT CASE WHEN tcc.TypeEcheance = 'immediat' THEN tc.BillingNumber END) as factures_immediat,
        COUNT(DISTINCT CASE WHEN tcc.TypeEcheance = '30_jours' THEN tc.BillingNumber END) as factures_30j,
        COUNT(DISTINCT CASE WHEN tcc.TypeEcheance = '60_jours' THEN tc.BillingNumber END) as factures_60j,
        SUM(CASE WHEN tc.Dues > 0 AND tcc.DateEcheance < CURDATE() THEN tc.Dues ELSE 0 END) as montant_echu,
        SUM(CASE WHEN tc.Dues > 0 THEN tc.Dues ELSE 0 END) as total_creances,
        SUM(tc.FinalAmount) as montant_total_factures,
        SUM(tc.Paid) as montant_total_paye
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
    <title>📅 Gestion Complète des Échéances | Système de Gestion d'Inventaire</title>
    <?php include_once('includes/cs.php'); ?>
    <?php include_once('includes/responsive.php'); ?>
    <style>
        /* Tableau de bord moderne */
        .stats-dashboard {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(220px, 1fr));
            gap: 20px;
            margin-bottom: 25px;
        }
        
        .stat-card {
            background: linear-gradient(135deg, #fff 0%, #f8f9fa 100%);
            border-radius: 12px;
            padding: 20px;
            box-shadow: 0 4px 15px rgba(0,0,0,0.1);
            border-left: 5px solid;
            transition: all 0.3s ease;
            position: relative;
            overflow: hidden;
        }
        
        .stat-card::before {
            content: '';
            position: absolute;
            top: 0;
            right: 0;
            width: 50px;
            height: 50px;
            opacity: 0.1;
            border-radius: 50%;
            transform: translate(20px, -20px);
        }
        
        .stat-card:hover {
            transform: translateY(-5px);
            box-shadow: 0 8px 25px rgba(0,0,0,0.15);
        }
        
        .stat-card.danger {
            border-left-color: #dc3545;
        }
        .stat-card.danger::before {
            background: #dc3545;
        }
        
        .stat-card.warning {
            border-left-color: #ffc107;
        }
        .stat-card.warning::before {
            background: #ffc107;
        }
        
        .stat-card.info {
            border-left-color: #17a2b8;
        }
        .stat-card.info::before {
            background: #17a2b8;
        }
        
        .stat-card.success {
            border-left-color: #28a745;
        }
        .stat-card.success::before {
            background: #28a745;
        }
        
        .stat-card.primary {
            border-left-color: #007bff;
        }
        .stat-card.primary::before {
            background: #007bff;
        }
        
        .stat-value {
            font-size: 28px;
            font-weight: 700;
            margin-bottom: 8px;
            color: #2c3e50;
        }
        
        .stat-label {
            font-size: 13px;
            color: #7f8c8d;
            text-transform: uppercase;
            font-weight: 600;
            letter-spacing: 0.5px;
        }
        
        .stat-icon {
            position: absolute;
            top: 15px;
            right: 15px;
            font-size: 24px;
            opacity: 0.3;
            color: #495057;
        }
        
        /* Section de filtres avancés */
        .advanced-filters {
            background: linear-gradient(135deg, #f8f9fa 0%, #e9ecef 100%);
            padding: 25px;
            border-radius: 15px;
            margin-bottom: 25px;
            box-shadow: 0 2px 10px rgba(0,0,0,0.05);
        }
        
        .filter-title {
            color: #495057;
            font-weight: 700;
            margin-bottom: 20px;
            font-size: 18px;
            display: flex;
            align-items: center;
            gap: 10px;
        }
        
        .filter-section {
            margin-bottom: 20px;
        }
        
        .filter-section h6 {
            color: #6c757d;
            font-weight: 600;
            margin-bottom: 10px;
            font-size: 14px;
            text-transform: uppercase;
            letter-spacing: 0.5px;
        }
        
        .filter-buttons {
            display: flex;
            gap: 8px;
            flex-wrap: wrap;
            margin-bottom: 15px;
        }
        
        .filter-btn {
            padding: 8px 16px;
            border: 2px solid #dee2e6;
            background: white;
            color: #6c757d;
            border-radius: 25px;
            text-decoration: none;
            font-size: 13px;
            font-weight: 600;
            transition: all 0.3s ease;
            display: inline-flex;
            align-items: center;
            gap: 6px;
        }
        
        .filter-btn:hover {
            border-color: #007bff;
            color: #007bff;
            text-decoration: none;
            transform: translateY(-1px);
        }
        
        .filter-btn.active {
            background: #007bff;
            border-color: #007bff;
            color: white;
            box-shadow: 0 4px 15px rgba(0,123,255,0.3);
        }
        
        .filter-btn.danger { border-color: #dc3545; color: #dc3545; }
        .filter-btn.danger.active { background: #dc3545; border-color: #dc3545; color: white; }
        .filter-btn.warning { border-color: #ffc107; color: #856404; }
        .filter-btn.warning.active { background: #ffc107; border-color: #ffc107; color: #212529; }
        .filter-btn.success { border-color: #28a745; color: #28a745; }
        .filter-btn.success.active { background: #28a745; border-color: #28a745; color: white; }
        .filter-btn.info { border-color: #17a2b8; color: #17a2b8; }
        .filter-btn.info.active { background: #17a2b8; border-color: #17a2b8; color: white; }
        
        /* Date range picker */
        .date-range-container {
            display: flex;
            gap: 10px;
            align-items: center;
            flex-wrap: wrap;
        }
        
        .date-input {
            padding: 8px 12px;
            border: 1px solid #dee2e6;
            border-radius: 6px;
            font-size: 13px;
        }
        
        /* Badges de statut modernes */
        .status-badge {
            padding: 6px 12px;
            border-radius: 20px;
            font-size: 11px;
            font-weight: 700;
            text-transform: uppercase;
            letter-spacing: 0.5px;
            display: inline-block;
        }
        
        .status-echu {
            background: linear-gradient(135deg, #dc3545, #c82333);
            color: white;
            box-shadow: 0 2px 8px rgba(220,53,69,0.3);
        }
        
        .status-aujourd-hui {
            background: linear-gradient(135deg, #ffc107, #e0a800);
            color: #212529;
            box-shadow: 0 2px 8px rgba(255,193,7,0.3);
        }
        
        .status-bientot {
            background: linear-gradient(135deg, #17a2b8, #138496);
            color: white;
            box-shadow: 0 2px 8px rgba(23,162,184,0.3);
        }
        
        .status-en-cours {
            background: linear-gradient(135deg, #28a745, #218838);
            color: white;
            box-shadow: 0 2px 8px rgba(40,167,69,0.3);
        }
        
        .status-regle {
            background: linear-gradient(135deg, #6c757d, #5a6268);
            color: white;
            box-shadow: 0 2px 8px rgba(108,117,125,0.3);
        }
        
        /* Type d'échéance badges */
        .type-badge {
            padding: 4px 8px;
            border-radius: 12px;
            font-size: 10px;
            font-weight: 600;
            text-transform: uppercase;
            background: #e9ecef;
            color: #495057;
        }
        
        .type-badge.immediat {
            background: #e3f2fd;
            color: #1976d2;
        }
        
        .type-badge.jours {
            background: #f3e5f5;
            color: #7b1fa2;
        }
        
        .type-badge.personnalise {
            background: #fff3e0;
            color: #f57c00;
        }
        
        /* Tableau moderne */
        .modern-table {
            background: white;
            border-radius: 15px;
            overflow: hidden;
            box-shadow: 0 6px 30px rgba(0,0,0,0.1);
        }
        
        .modern-table .widget-title {
            background: linear-gradient(135deg, #495057, #6c757d);
            color: white;
            padding: 25px;
            margin: 0;
        }
        
        .modern-table .widget-title h5 {
            margin: 0;
            font-size: 20px;
            font-weight: 700;
            display: flex;
            align-items: center;
            gap: 10px;
        }
        
        .modern-table table {
            margin: 0;
        }
        
        .modern-table thead th {
            background: #f8f9fa;
            color: #495057;
            font-weight: 700;
            padding: 18px 15px;
            border-bottom: 2px solid #dee2e6;
            font-size: 13px;
            text-transform: uppercase;
            letter-spacing: 0.5px;
        }
        
        .modern-table tbody tr {
            transition: all 0.2s ease;
            border-bottom: 1px solid #f1f3f4;
        }
        
        .modern-table tbody tr:hover {
            background-color: #f8f9fa;
            transform: scale(1.01);
        }
        
        .modern-table tbody td {
            padding: 15px;
            vertical-align: middle;
        }
        
        /* Boutons d'action modernes */
        .action-buttons {
            display: flex;
            gap: 6px;
            flex-wrap: wrap;
        }
        
        .btn-modern {
            padding: 6px 12px;
            border-radius: 8px;
            font-size: 12px;
            font-weight: 600;
            border: none;
            cursor: pointer;
            transition: all 0.2s ease;
            display: inline-flex;
            align-items: center;
            gap: 4px;
            text-decoration: none;
        }
        
        .btn-modern:hover {
            transform: translateY(-2px);
            box-shadow: 0 6px 20px rgba(0,0,0,0.15);
            text-decoration: none;
        }
        
        .btn-modern.btn-success {
            background: linear-gradient(135deg, #28a745, #20c997);
            color: white;
        }
        
        .btn-modern.btn-warning {
            background: linear-gradient(135deg, #ffc107, #fd7e14);
            color: #212529;
        }
        
        .btn-modern.btn-info {
            background: linear-gradient(135deg, #17a2b8, #20c997);
            color: white;
        }
        
        .btn-modern.btn-primary {
            background: linear-gradient(135deg, #007bff, #6610f2);
            color: white;
        }
        
        /* Modal moderne */
        .modern-modal {
            display: none;
            position: fixed;
            z-index: 1000;
            left: 0;
            top: 0;
            width: 100%;
            height: 100%;
            background-color: rgba(0,0,0,0.6);
            backdrop-filter: blur(5px);
        }
        
        .modern-modal-content {
            background: white;
            margin: 5% auto;
            padding: 0;
            border-radius: 15px;
            width: 90%;
            max-width: 500px;
            box-shadow: 0 20px 60px rgba(0,0,0,0.3);
            animation: modalSlideIn 0.3s ease;
        }
        
        @keyframes modalSlideIn {
            from { transform: translateY(-50px); opacity: 0; }
            to { transform: translateY(0); opacity: 1; }
        }
        
        .modal-header {
            background: linear-gradient(135deg, #007bff, #6610f2);
            color: white;
            padding: 20px 25px;
            border-radius: 15px 15px 0 0;
            display: flex;
            justify-content: between;
            align-items: center;
        }
        
        .modal-body {
            padding: 25px;
        }
        
        .modal-close {
            color: white;
            float: right;
            font-size: 24px;
            font-weight: bold;
            cursor: pointer;
            opacity: 0.8;
            transition: opacity 0.2s;
        }
        
        .modal-close:hover {
            opacity: 1;
        }
        
        /* Pagination moderne */
        .modern-pagination {
            display: flex;
            justify-content: center;
            align-items: center;
            gap: 8px;
            margin-top: 30px;
        }
        
        .modern-pagination a {
            padding: 12px 16px;
            background: white;
            color: #6c757d;
            text-decoration: none;
            border-radius: 10px;
            border: 1px solid #dee2e6;
            font-weight: 600;
            transition: all 0.2s ease;
        }
        
        .modern-pagination a:hover {
            background: #007bff;
            color: white;
            border-color: #007bff;
            transform: translateY(-2px);
            text-decoration: none;
        }
        
        .modern-pagination a.active {
            background: #007bff;
            color: white;
            border-color: #007bff;
        }
        
        /* Messages d'alerte */
        .alert-modern {
            padding: 15px 20px;
            border-radius: 10px;
            margin-bottom: 20px;
            display: flex;
            align-items: center;
            gap: 10px;
            font-weight: 600;
        }
        
        .alert-modern.success {
            background: linear-gradient(135deg, #d4edda, #c3e6cb);
            color: #155724;
            border: 1px solid #c3e6cb;
        }
        
        .alert-modern.error {
            background: linear-gradient(135deg, #f8d7da, #f5c6cb);
            color: #721c24;
            border: 1px solid #f5c6cb;
        }
        
        /* Responsive */
        @media (max-width: 768px) {
            .stats-dashboard {
                grid-template-columns: 1fr;
            }
            
            .filter-buttons {
                flex-direction: column;
            }
            
            .action-buttons {
                flex-direction: column;
            }
            
            .date-range-container {
                flex-direction: column;
                align-items: stretch;
            }
            
            .modern-modal-content {
                width: 95%;
                margin: 10% auto;
            }
        }
        
        /* Animations */
        .fade-in {
            animation: fadeIn 0.6s ease-in;
        }
        
        @keyframes fadeIn {
            from { opacity: 0; transform: translateY(20px); }
            to { opacity: 1; transform: translateY(0); }
        }
        
        .retard-days {
            color: #dc3545;
            font-weight: bold;
            font-size: 11px;
            background: #f8d7da;
            padding: 2px 6px;
            border-radius: 10px;
        }
        
        .montant-echu {
            color: #dc3545;
            font-weight: bold;
        }
        
        .montant-normal {
            color: #28a745;
            font-weight: bold;
        }
        
        .client-info {
            display: flex;
            flex-direction: column;
            gap: 2px;
        }
        
        .client-name {
            font-weight: 600;
            color: #2c3e50;
        }
        
        .client-phone {
            font-size: 12px;
            color: #6c757d;
        }
        
        .credit-info {
            font-size: 11px;
            color: #17a2b8;
            font-weight: 600;
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
                <a href="factures_echeance.php" class="current">📅 Gestion des Échéances</a>
            </div>
            <h1>📅 Gestion Complète des Factures à Échéance</h1>
        </div>
        
        <div class="container-fluid">
            <!-- Messages d'alerte -->
            <?php if (isset($message)): ?>
            <div class="alert-modern <?php echo $messageType; ?> fade-in">
                <i class="icon-<?php echo $messageType == 'success' ? 'ok' : 'warning-sign'; ?>"></i>
                <?php echo $message; ?>
            </div>
            <?php endif; ?>

            <!-- Tableau de bord des statistiques -->
            <div class="stats-dashboard fade-in">
                <div class="stat-card primary">
                    <div class="stat-value"><?php echo number_format($stats['total_factures']); ?></div>
                    <div class="stat-label">Total Factures à Terme</div>
                    <i class="icon-file-text stat-icon"></i>
                </div>
                <div class="stat-card danger">
                    <div class="stat-value"><?php echo number_format($stats['factures_echues']); ?></div>
                    <div class="stat-label">Factures Échues</div>
                    <i class="icon-warning-sign stat-icon"></i>
                </div>
                <div class="stat-card warning">
                    <div class="stat-value"><?php echo number_format($stats['factures_aujourd_hui']); ?></div>
                    <div class="stat-label">Échéance Aujourd'hui</div>
                    <i class="icon-time stat-icon"></i>
                </div>
                <div class="stat-card info">
                    <div class="stat-value"><?php echo number_format($stats['factures_bientot']); ?></div>
                    <div class="stat-label">Bientôt Échues (7j)</div>
                    <i class="icon-bell stat-icon"></i>
                </div>
                <div class="stat-card success">
                    <div class="stat-value"><?php echo number_format($stats['factures_reglees']); ?></div>
                    <div class="stat-label">Factures Réglées</div>
                    <i class="icon-ok stat-icon"></i>
                </div>
                <div class="stat-card danger">
                    <div class="stat-value"><?php echo number_format($stats['montant_echu'] / 1000000, 1); ?>M</div>
                    <div class="stat-label">Montant Échu (GNF)</div>
                    <i class="icon-money stat-icon"></i>
                </div>
            </div>

            <!-- Section de filtres avancés -->
            <div class="advanced-filters fade-in">
                <div class="filter-title">
                    <i class="icon-filter"></i>
                    Filtres Avancés pour Factures à Échéance
                </div>
                
                <!-- Filtres par statut -->
                <div class="filter-section">
                    <h6>🎯 Filtrer par Statut d'Échéance</h6>
                    <div class="filter-buttons">
                        <a href="?<?php echo http_build_query(array_merge($_GET, ['status' => 'all'])); ?>" 
                           class="filter-btn <?php echo $statusFilter == 'all' ? 'active' : ''; ?>">
                            <i class="icon-th-list"></i> Tous (<?php echo number_format($stats['total_factures']); ?>)
                        </a>
                        <a href="?<?php echo http_build_query(array_merge($_GET, ['status' => 'en_cours'])); ?>" 
                           class="filter-btn success <?php echo $statusFilter == 'en_cours' ? 'active' : ''; ?>">
                            <i class="icon-play"></i> En Cours
                        </a>
                        <a href="?<?php echo http_build_query(array_merge($_GET, ['status' => 'bientot_echu'])); ?>" 
                           class="filter-btn info <?php echo $statusFilter == 'bientot_echu' ? 'active' : ''; ?>">
                            <i class="icon-bell"></i> Bientôt Échues (<?php echo $stats['factures_bientot']; ?>)
                        </a>
                        <a href="?<?php echo http_build_query(array_merge($_GET, ['status' => 'echu'])); ?>" 
                           class="filter-btn danger <?php echo $statusFilter == 'echu' ? 'active' : ''; ?>">
                            <i class="icon-warning-sign"></i> Échues (<?php echo $stats['factures_echues']; ?>)
                        </a>
                        <a href="?<?php echo http_build_query(array_merge($_GET, ['status' => 'en_retard'])); ?>" 
                           class="filter-btn danger <?php echo $statusFilter == 'en_retard' ? 'active' : ''; ?>">
                            <i class="icon-exclamation-sign"></i> En Retard
                        </a>
                        <a href="?<?php echo http_build_query(array_merge($_GET, ['status' => 'regle'])); ?>" 
                           class="filter-btn success <?php echo $statusFilter == 'regle' ? 'active' : ''; ?>">
                            <i class="icon-ok"></i> Réglées (<?php echo $stats['factures_reglees']; ?>)
                        </a>
                    </div>
                </div>

                <!-- Filtres par période -->
                <div class="filter-section">
                    <h6>📅 Filtrer par Période</h6>
                    <div class="filter-buttons">
                        <a href="?<?php echo http_build_query(array_merge($_GET, ['date_filter' => 'all'])); ?>" 
                           class="filter-btn <?php echo $dateFilter == 'all' ? 'active' : ''; ?>">
                            <i class="icon-calendar"></i> Toutes Périodes
                        </a>
                        <a href="?<?php echo http_build_query(array_merge($_GET, ['date_filter' => 'today'])); ?>" 
                           class="filter-btn warning <?php echo $dateFilter == 'today' ? 'active' : ''; ?>">
                            <i class="icon-time"></i> Aujourd'hui
                        </a>
                        <a href="?<?php echo http_build_query(array_merge($_GET, ['date_filter' => 'tomorrow'])); ?>" 
                           class="filter-btn info <?php echo $dateFilter == 'tomorrow' ? 'active' : ''; ?>">
                            <i class="icon-forward"></i> Demain
                        </a>
                        <a href="?<?php echo http_build_query(array_merge($_GET, ['date_filter' => 'week'])); ?>" 
                           class="filter-btn info <?php echo $dateFilter == 'week' ? 'active' : ''; ?>">
                            <i class="icon-calendar"></i> Cette Semaine
                        </a>
                        <a href="?<?php echo http_build_query(array_merge($_GET, ['date_filter' => 'month'])); ?>" 
                           class="filter-btn <?php echo $dateFilter == 'month' ? 'active' : ''; ?>">
                            <i class="icon-calendar"></i> Ce Mois
                        </a>
                        <a href="?<?php echo http_build_query(array_merge($_GET, ['date_filter' => 'overdue'])); ?>" 
                           class="filter-btn danger <?php echo $dateFilter == 'overdue' ? 'active' : ''; ?>">
                            <i class="icon-exclamation-sign"></i> En Retard
                        </a>
                        <a href="?<?php echo http_build_query(array_merge($_GET, ['date_filter' => 'past_week'])); ?>" 
                           class="filter-btn <?php echo $dateFilter == 'past_week' ? 'active' : ''; ?>">
                            <i class="icon-backward"></i> Semaine Passée
                        </a>
                    </div>
                </div>

                <!-- Filtres par type d'échéance -->
                <div class="filter-section">
                    <h6>⏰ Filtrer par Type d'Échéance</h6>
                    <div class="filter-buttons">
                        <a href="?<?php echo http_build_query(array_merge($_GET, ['type_filter' => 'all'])); ?>" 
                           class="filter-btn <?php echo $typeFilter == 'all' ? 'active' : ''; ?>">
                            <i class="icon-th-list"></i> Tous Types
                        </a>
                        <a href="?<?php echo http_build_query(array_merge($_GET, ['type_filter' => 'immediat'])); ?>" 
                           class="filter-btn <?php echo $typeFilter == 'immediat' ? 'active' : ''; ?>">
                            <i class="icon-flash"></i> Immédiat (<?php echo $stats['factures_immediat']; ?>)
                        </a>
                        <a href="?<?php echo http_build_query(array_merge($_GET, ['type_filter' => '7_jours'])); ?>" 
                           class="filter-btn <?php echo $typeFilter == '7_jours' ? 'active' : ''; ?>">
                            <i class="icon-time"></i> 7 Jours
                        </a>
                        <a href="?<?php echo http_build_query(array_merge($_GET, ['type_filter' => '30_jours'])); ?>" 
                           class="filter-btn <?php echo $typeFilter == '30_jours' ? 'active' : ''; ?>">
                            <i class="icon-calendar"></i> 30 Jours (<?php echo $stats['factures_30j']; ?>)
                        </a>
                        <a href="?<?php echo http_build_query(array_merge($_GET, ['type_filter' => '60_jours'])); ?>" 
                           class="filter-btn <?php echo $typeFilter == '60_jours' ? 'active' : ''; ?>">
                            <i class="icon-calendar"></i> 60 Jours (<?php echo $stats['factures_60j']; ?>)
                        </a>
                        <a href="?<?php echo http_build_query(array_merge($_GET, ['type_filter' => 'personnalise'])); ?>" 
                           class="filter-btn <?php echo $typeFilter == 'personnalise' ? 'active' : ''; ?>">
                            <i class="icon-cog"></i> Personnalisé
                        </a>
                    </div>
                </div>

                <!-- Recherche et plage de dates -->
                <form method="get" class="form-inline">
                    <div class="row-fluid">
                        <div class="span4">
                            <label><strong>🔍 Recherche :</strong></label>
                            <input type="text" name="search" value="<?php echo htmlspecialchars($searchTerm); ?>" 
                                   placeholder="Client, téléphone, n° facture..." class="span12">
                        </div>
                        <div class="span3">
                            <label><strong>📅 Date de début :</strong></label>
                            <input type="date" name="date_from" value="<?php echo $dateFrom; ?>" class="span12">
                        </div>
                        <div class="span3">
                            <label><strong>📅 Date de fin :</strong></label>
                            <input type="date" name="date_to" value="<?php echo $dateTo; ?>" class="span12">
                        </div>
                        <div class="span2">
                            <label>&nbsp;</label>
                            <button type="submit" class="btn btn-primary span12">
                                <i class="icon-search"></i> Filtrer
                            </button>
                        </div>
                    </div>
                    
                    <!-- Maintenir les autres filtres -->
                    <?php if ($statusFilter != 'all'): ?>
                        <input type="hidden" name="status" value="<?php echo $statusFilter; ?>">
                    <?php endif; ?>
                    <?php if ($dateFilter != 'all'): ?>
                        <input type="hidden" name="date_filter" value="<?php echo $dateFilter; ?>">
                    <?php endif; ?>
                    <?php if ($typeFilter != 'all'): ?>
                        <input type="hidden" name="type_filter" value="<?php echo $typeFilter; ?>">
                    <?php endif; ?>
                </form>
                
                <!-- Bouton pour effacer tous les filtres -->
                <?php if ($statusFilter != 'all' || $dateFilter != 'all' || $typeFilter != 'all' || !empty($searchTerm) || !empty($dateFrom) || !empty($dateTo)): ?>
                <div style="margin-top: 15px; text-align: center;">
                    <a href="factures_echeance.php" class="btn btn-warning">
                        <i class="icon-refresh"></i> Effacer Tous les Filtres
                    </a>
                </div>
                <?php endif; ?>
            </div>

            <!-- Actions rapides -->
            <div class="row-fluid">
                <div class="span12">
                    <a href="dettecart.php" class="btn btn-success">
                        <i class="icon-plus"></i> Nouvelle Vente à Terme
                    </a>
                    <a href="add_customer_master.php" class="btn btn-info">
                        <i class="icon-user"></i> Ajouter Client
                    </a>
                    <a href="manage_customer_master.php" class="btn btn-primary">
                        <i class="icon-credit-card"></i> Gestion Plafonds
                    </a>
                    <button onclick="exportToCSV()" class="btn btn-warning">
                        <i class="icon-download"></i> Exporter CSV
                    </button>
                </div>
            </div>
            <hr>

            <!-- Tableau des factures moderne -->
            <div class="modern-table fade-in">
                <div class="widget-title">
                    <h5><i class="icon-calendar"></i> Factures à Échéance (<?php echo $totalRecords; ?>)</h5>
                </div>
                <div class="widget-content nopadding">
                    <table class="table table-striped" id="facturesTable">
                        <thead>
                            <tr>
                                <th>N° Facture</th>
                                <th>Client & Contact</th>
                                <th>Date Facture</th>
                                <th>Type & Échéance</th>
                                <th>Montants</th>
                                <th>Statut</th>
                                <th>Crédit Client</th>
                                <th>Actions</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php 
                            if (mysqli_num_rows($result) > 0) {
                                while ($facture = mysqli_fetch_assoc($result)) {
                                    $statusClass = '';
                                    $statusText = $facture['StatutFacture'];
                                    
                                    switch ($facture['StatutFacture']) {
                                        case 'En retard':
                                            $statusClass = 'status-echu';
                                            break;
                                        case 'Échéance aujourd\'hui':
                                            $statusClass = 'status-aujourd-hui';
                                            break;
                                        case 'Bientôt échu':
                                            $statusClass = 'status-bientot';
                                            break;
                                        case 'En cours':
                                            $statusClass = 'status-en-cours';
                                            break;
                                        case 'Réglé':
                                            $statusClass = 'status-regle';
                                            break;
                                    }
                                    
                                    $montantClass = $facture['Dues'] > 0 ? 'montant-echu' : 'montant-normal';
                                    $dateEcheance = $facture['DateEcheance'] ? date('d/m/Y', strtotime($facture['DateEcheance'])) : 'Non définie';
                                    
                                    // Type d'échéance
                                    $typeLabels = [
                                        'immediat' => 'Immédiat',
                                        '7_jours' => '7 jours',
                                        '15_jours' => '15 jours',
                                        '30_jours' => '30 jours',
                                        '60_jours' => '60 jours',
                                        '90_jours' => '90 jours',
                                        'personnalise' => 'Personnalisé'
                                    ];
                                    $typeLabel = $typeLabels[$facture['TypeEcheance']] ?? $facture['TypeEcheance'];
                                    $typeClass = $facture['TypeEcheance'] == 'immediat' ? 'immediat' : ($facture['TypeEcheance'] == 'personnalise' ? 'personnalise' : 'jours');
                                    
                                    if ($facture['TypeEcheance'] == 'personnalise') {
                                        $typeLabel .= ' (' . $facture['NombreJours'] . ' jours)';
                                    }
                                    ?>
                                    <tr>
                                        <td>
                                            <strong style="color: #007bff;"><?php echo $facture['BillingNumber']; ?></strong>
                                            <br>
                                            <small class="text-muted"><?php echo date('d/m/Y', strtotime($facture['BillingDate'])); ?></small>
                                        </td>
                                        <td>
                                            <div class="client-info">
                                                <div class="client-name"><?php echo htmlspecialchars($facture['CustomerName']); ?></div>
                                                <div class="client-phone">
                                                    <a href="tel:<?php echo $facture['MobileNumber']; ?>" style="text-decoration: none;">
                                                        <i class="icon-phone"></i> <?php echo $facture['MobileNumber']; ?>
                                                    </a>
                                                </div>
                                            </div>
                                        </td>
                                        <td>
                                            <?php echo date('d/m/Y', strtotime($facture['BillingDate'])); ?>
                                            <br>
                                            <small class="text-muted"><?php echo $facture['ModeOfPayment']; ?></small>
                                        </td>
                                        <td>
                                            <span class="type-badge <?php echo $typeClass; ?>"><?php echo $typeLabel; ?></span>
                                            <br>
                                            <strong style="color: #495057;"><?php echo $dateEcheance; ?></strong>
                                            <?php if ($facture['JoursRetard'] > 0): ?>
                                                <br><span class="retard-days">+<?php echo $facture['JoursRetard']; ?> jours</span>
                                            <?php endif; ?>
                                        </td>
                                        <td>
                                            <div style="font-size: 12px;">
                                                <strong>Total :</strong> <?php echo number_format($facture['FinalAmount'], 0, ',', ' '); ?> GNF<br>
                                                <strong>Payé :</strong> <?php echo number_format($facture['Paid'], 0, ',', ' '); ?> GNF<br>
                                                <strong class="<?php echo $montantClass; ?>">Dû :</strong> 
                                                <span class="<?php echo $montantClass; ?>"><?php echo number_format($facture['Dues'], 0, ',', ' '); ?> GNF</span>
                                            </div>
                                        </td>
                                        <td>
                                            <span class="status-badge <?php echo $statusClass; ?>">
                                                <?php echo $statusText; ?>
                                            </span>
                                        </td>
                                        <td>
                                            <?php if ($facture['CreditLimit'] > 0): ?>
                                                <div class="credit-info">
                                                    Plafond: <?php echo number_format($facture['CreditLimit'], 0, ',', ' '); ?> GNF<br>
                                                    Dette totale: <?php echo number_format($facture['CustomerTotalDues'], 0, ',', ' '); ?> GNF<br>
                                                    <?php 
                                                    $usagePercent = round(($facture['CustomerTotalDues'] / $facture['CreditLimit']) * 100, 1);
                                                    $usageColor = $usagePercent > 80 ? '#dc3545' : ($usagePercent > 60 ? '#ffc107' : '#28a745');
                                                    ?>
                                                    <span style="color: <?php echo $usageColor; ?>;">Utilisation: <?php echo $usagePercent; ?>%</span>
                                                </div>
                                            <?php else: ?>
                                                <span class="text-muted">Aucune limite</span>
                                            <?php endif; ?>
                                        </td>
                                        <td>
                                            <div class="action-buttons">
                                                <?php if ($facture['Dues'] > 0): ?>
                                                <button onclick="openPaymentModal('<?php echo $facture['BillingNumber']; ?>', <?php echo $facture['Dues']; ?>)" 
                                                        class="btn-modern btn-success" title="Enregistrer paiement">
                                                    <i class="icon-money"></i> Payer
                                                </button>
                                                <button onclick="sendRelance('<?php echo $facture['BillingNumber']; ?>')" 
                                                        class="btn-modern btn-warning" title="Envoyer relance SMS">
                                                    <i class="icon-comment"></i> SMS
                                                </button>
                                                <?php endif; ?>
                                                <a href="invoice_details.php?billingnum=<?php echo $facture['BillingNumber']; ?>" 
                                                   class="btn-modern btn-info" title="Voir détails">
                                                    <i class="icon-eye-open"></i> Détails
                                                </a>
                                            </div>
                                        </td>
                                    </tr>
                                    <?php
                                }
                            } else {
                                ?>
                                <tr>
                                    <td colspan="8" class="text-center" style="color: #6c757d; padding: 40px;">
                                        <i class="icon-info-sign" style="font-size: 24px; margin-bottom: 10px;"></i>
                                        <br>
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

            <!-- Pagination moderne -->
            <?php if ($totalPages > 1): ?>
            <div class="modern-pagination fade-in">
                <?php if ($page > 1): ?>
                    <a href="?<?php echo http_build_query(array_merge($_GET, ['page' => $page-1])); ?>">
                        <i class="icon-chevron-left"></i> Précédent
                    </a>
                <?php endif; ?>
                
                <?php
                $start = max(1, $page - 2);
                $end = min($totalPages, $page + 2);
                
                for ($i = $start; $i <= $end; $i++): ?>
                    <a href="?<?php echo http_build_query(array_merge($_GET, ['page' => $i])); ?>" 
                       class="<?php echo $i == $page ? 'active' : ''; ?>">
                        <?php echo $i; ?>
                    </a>
                <?php endfor; ?>
                
                <?php if ($page < $totalPages): ?>
                    <a href="?<?php echo http_build_query(array_merge($_GET, ['page' => $page+1])); ?>">
                        Suivant <i class="icon-chevron-right"></i>
                    </a>
                <?php endif; ?>
            </div>
            <?php endif; ?>
        </div>
    </div>

    <!-- Modal de paiement moderne -->
    <div id="paymentModal" class="modern-modal">
        <div class="modern-modal-content">
            <div class="modal-header">
                <h3 style="margin: 0;">💰 Enregistrer un Paiement</h3>
                <span class="modal-close" onclick="closePaymentModal()">&times;</span>
            </div>
            <div class="modal-body">
                <form method="post" id="paymentForm">
                    <input type="hidden" name="action" value="marquer_paye">
                    <input type="hidden" name="billing_id" id="payment_billing_id">
                    
                    <div class="control-group">
                        <label><strong>Facture N° :</strong></label>
                        <div style="font-size: 18px; color: #007bff; font-weight: bold;">
                            <span id="payment_billing_display"></span>
                        </div>
                    </div>
                    
                    <div class="control-group">
                        <label><strong>Montant dû :</strong></label>
                        <div style="font-size: 16px; color: #dc3545; font-weight: bold;">
                            <span id="payment_due_display"></span> GNF
                        </div>
                    </div>
                    
                    <div class="control-group">
                        <label><strong>Montant payé :</strong></label>
                        <input type="number" name="montant_paye" id="montant_paye" step="any" min="0" required 
                               class="span11" style="font-size: 16px; padding: 10px;">
                    </div>
                    
                    <div class="form-actions" style="text-align: center; margin-top: 20px;">
                        <button type="submit" class="btn btn-success btn-large">
                            <i class="icon-ok"></i> Enregistrer le Paiement
                        </button>
                        <button type="button" onclick="closePaymentModal()" class="btn btn-large">Annuler</button>
                    </div>
                </form>
            </div>
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
            document.getElementById('payment_due_display').textContent = new Intl.NumberFormat('fr-FR').format(dueAmount);
            document.getElementById('montant_paye').value = dueAmount;
            document.getElementById('montant_paye').max = dueAmount;
            document.getElementById('paymentModal').style.display = 'block';
        }
        
        function closePaymentModal() {
            document.getElementById('paymentModal').style.display = 'none';
        }
        
        // Envoyer une relance SMS
        function sendRelance(billingId) {
            if (confirm('📱 Êtes-vous sûr de vouloir envoyer une relance SMS pour la facture ' + billingId + ' ?')) {
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
                    if (index < 7) { // Exclure la colonne Actions
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
        
        // Animation des cartes statistiques
        $(document).ready(function() {
            // Animation des valeurs des statistiques
            $('.stat-value').each(function() {
                const finalValue = $(this).text();
                const isNumber = !isNaN(parseFloat(finalValue.replace(/[^0-9.-]/g, '')));
                
                if (isNumber) {
                    const numValue = parseFloat(finalValue.replace(/[^0-9.-]/g, ''));
                    $(this).prop('Counter', 0).animate({
                        Counter: numValue
                    }, {
                        duration: 1500,
                        easing: 'swing',
                        step: function(now) {
                            if (finalValue.includes('M')) {
                                $(this).text(Math.ceil(now * 10) / 10 + 'M');
                            } else {
                                $(this).text(Math.ceil(now).toLocaleString('fr-FR'));
                            }
                        }
                    });
                }
            });
            
            // Effet hover sur les cartes
            $('.stat-card').hover(
                function() {
                    $(this).css('transform', 'translateY(-8px) scale(1.02)');
                },
                function() {
                    $(this).css('transform', 'translateY(0) scale(1)');
                }
            );
        });
        
        // Auto-refresh every 5 minutes for real-time updates
        setInterval(function() {
            if (!document.hidden) {
                const modal = document.getElementById('paymentModal');
                if (modal.style.display !== 'block') {
                    location.reload();
                }
            }
        }, 300000); // 5 minutes
    </script>
</body>
</html>