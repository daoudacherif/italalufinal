<?php 
session_start();
ini_set('display_errors', 1);
ini_set('display_startup_errors', 1);
error_reporting(E_ALL);
include('includes/dbconnection.php');

// Vérifie que l'admin est connecté
if (empty($_SESSION['imsaid'])) {
    header('Location: logout.php');
    exit;
}

// Fonction pour mettre à jour rapidement un plafond de crédit
if (isset($_POST['quick_update_credit'])) {
    $customerId = intval($_POST['customer_id']);
    $newCreditLimit = max(0, floatval($_POST['new_credit_limit']));
    
    $updateQuery = "UPDATE tblcustomer_master SET CreditLimit = '$newCreditLimit' WHERE id = '$customerId'";
    if (mysqli_query($con, $updateQuery)) {
        $message = "Plafond mis à jour avec succès";
        $messageType = "success";
    } else {
        $message = "Erreur lors de la mise à jour : " . mysqli_error($con);
        $messageType = "error";
    }
}

// Gestion de la suppression
if (isset($_GET['delete_id'])) {
    $deleteId = intval($_GET['delete_id']);
    
    // Vérifier s'il y a des factures liées
    $checkInvoices = mysqli_query($con, "SELECT COUNT(*) as count FROM tblcustomer WHERE customer_master_id = '$deleteId'");
    $invoiceCount = mysqli_fetch_assoc($checkInvoices)['count'];
    
    if ($invoiceCount == 0) {
        $deleteQuery = "DELETE FROM tblcustomer_master WHERE id = '$deleteId'";
        if (mysqli_query($con, $deleteQuery)) {
            $message = "Client supprimé avec succès";
            $messageType = "success";
        } else {
            $message = "Erreur lors de la suppression : " . mysqli_error($con);
            $messageType = "error";
        }
    } else {
        $message = "Impossible de supprimer ce client car il a des factures associées";
        $messageType = "error";
    }
}

// Recherche et filtres
$searchTerm = '';
$creditFilter = isset($_GET['credit_filter']) ? $_GET['credit_filter'] : '';
$whereClause = 'WHERE 1=1';

if (!empty($_GET['search'])) {
    $searchTerm = mysqli_real_escape_string($con, $_GET['search']);
    $whereClause .= " AND (CustomerName LIKE '%$searchTerm%' OR CustomerContact LIKE '%$searchTerm%' OR CustomerEmail LIKE '%$searchTerm%')";
}

// Filtre par statut de crédit
if (!empty($creditFilter)) {
    switch ($creditFilter) {
        case 'no_limit':
            $whereClause .= " AND CreditLimit = 0";
            break;
        case 'within_limit':
            $whereClause .= " AND CreditLimit > 0 AND TotalDues <= CreditLimit";
            break;
        case 'near_limit':
            $whereClause .= " AND CreditLimit > 0 AND TotalDues > (CreditLimit * 0.8) AND TotalDues <= CreditLimit";
            break;
        case 'over_limit':
            $whereClause .= " AND CreditLimit > 0 AND TotalDues > CreditLimit";
            break;
    }
}

// Pagination
$page = isset($_GET['page']) ? max(1, intval($_GET['page'])) : 1;
$recordsPerPage = 20;
$offset = ($page - 1) * $recordsPerPage;

// Requête principale pour les clients avec informations de crédit
$baseQuery = "SELECT 
    cm.id,
    cm.CustomerName,
    cm.CustomerContact,
    cm.CustomerEmail,
    cm.CustomerAddress,
    cm.CustomerRegdate,
    cm.Status,
    cm.TotalPurchases,
    cm.TotalDues,
    cm.CreditLimit,
    cm.LastPurchaseDate,
    COUNT(c.ID) as TotalInvoices
FROM tblcustomer_master cm
LEFT JOIN tblcustomer c ON c.customer_master_id = cm.id
$whereClause
GROUP BY cm.id";

// Compter le total
$countQuery = "SELECT COUNT(*) as total FROM ($baseQuery) as customer_count";
$countResult = mysqli_query($con, $countQuery);
$totalRecords = mysqli_fetch_assoc($countResult)['total'];
$totalPages = ceil($totalRecords / $recordsPerPage);

// Récupérer les clients avec pagination
$customersQuery = "$baseQuery ORDER BY cm.LastPurchaseDate DESC LIMIT $offset, $recordsPerPage";
$customersResult = mysqli_query($con, $customersQuery);

// Statistiques générales avec plafonds
$stats = mysqli_fetch_assoc(mysqli_query($con, "
    SELECT 
        COUNT(*) as total_customers,
        SUM(TotalPurchases) as total_revenue,
        SUM(TotalDues) as total_dues,
        SUM(CreditLimit) as total_credit_limits,
        COUNT(CASE WHEN TotalDues > 0 THEN 1 END) as customers_with_dues,
        COUNT(CASE WHEN CreditLimit > 0 THEN 1 END) as customers_with_limits,
        COUNT(CASE WHEN CreditLimit > 0 AND TotalDues > CreditLimit THEN 1 END) as customers_over_limit,
        COUNT(CASE WHEN CreditLimit > 0 AND TotalDues > (CreditLimit * 0.8) AND TotalDues <= CreditLimit THEN 1 END) as customers_near_limit
    FROM tblcustomer_master
    WHERE Status = 'active'
"));
?>

<!DOCTYPE html>
<html lang="fr">
<head>
    <title>Répertoire Client avec Plafonds | Système de Gestion d'Inventaire</title>
    <?php include_once('includes/cs.php'); ?>
    <?php include_once('includes/responsive.php'); ?>
    <style>
        /* Tableau de bord moderne */
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
            padding: 20px;
            box-shadow: 0 2px 8px rgba(0,0,0,0.1);
            border-left: 4px solid;
            transition: transform 0.2s, box-shadow 0.2s;
        }
        
        .stat-card:hover {
            transform: translateY(-2px);
            box-shadow: 0 4px 16px rgba(0,0,0,0.15);
        }
        
        .stat-card.primary {
            border-left-color: #007bff;
        }
        
        .stat-card.success {
            border-left-color: #28a745;
        }
        
        .stat-card.warning {
            border-left-color: #ffc107;
        }
        
        .stat-card.danger {
            border-left-color: #dc3545;
        }
        
        .stat-card.info {
            border-left-color: #17a2b8;
        }
        
        .stat-value {
            font-size: 28px;
            font-weight: bold;
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
            float: right;
            font-size: 24px;
            opacity: 0.3;
            margin-top: -10px;
        }
        
        /* Section de filtres moderne */
        .filters-section {
            background: linear-gradient(135deg, #f8f9fa 0%, #e9ecef 100%);
            padding: 20px;
            border-radius: 10px;
            margin-bottom: 25px;
            box-shadow: 0 2px 10px rgba(0,0,0,0.05);
        }
        
        .filter-title {
            color: #495057;
            font-weight: 600;
            margin-bottom: 15px;
            font-size: 16px;
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
            font-weight: 500;
            transition: all 0.2s;
        }
        
        .filter-btn:hover {
            border-color: #007bff;
            color: #007bff;
            text-decoration: none;
        }
        
        .filter-btn.active {
            background: #007bff;
            border-color: #007bff;
            color: white;
        }
        
        .filter-btn.success {
            border-color: #28a745;
            color: #28a745;
        }
        
        .filter-btn.success.active {
            background: #28a745;
            border-color: #28a745;
            color: white;
        }
        
        .filter-btn.warning {
            border-color: #ffc107;
            color: #856404;
        }
        
        .filter-btn.warning.active {
            background: #ffc107;
            border-color: #ffc107;
            color: #212529;
        }
        
        .filter-btn.danger {
            border-color: #dc3545;
            color: #dc3545;
        }
        
        .filter-btn.danger.active {
            background: #dc3545;
            border-color: #dc3545;
            color: white;
        }
        
        /* Badges de statut modernes */
        .credit-status {
            padding: 6px 12px;
            border-radius: 20px;
            font-size: 11px;
            font-weight: 600;
            text-transform: uppercase;
            letter-spacing: 0.5px;
            display: inline-block;
        }
        
        .credit-ok {
            background: linear-gradient(135deg, #d4edda, #c3e6cb);
            color: #155724;
            border: 1px solid #c3e6cb;
        }
        
        .credit-warning {
            background: linear-gradient(135deg, #fff3cd, #ffeaa7);
            color: #856404;
            border: 1px solid #ffeaa7;
        }
        
        .credit-danger {
            background: linear-gradient(135deg, #f8d7da, #f5c6cb);
            color: #721c24;
            border: 1px solid #f5c6cb;
        }
        
        .credit-none {
            background: linear-gradient(135deg, #e2e3e5, #d6d8db);
            color: #495057;
            border: 1px solid #d6d8db;
        }
        
        /* Barres d'utilisation animées */
        .usage-container {
            display: flex;
            align-items: center;
            gap: 8px;
        }
        
        .usage-bar {
            width: 80px;
            height: 12px;
            background: #e9ecef;
            border-radius: 6px;
            overflow: hidden;
            position: relative;
        }
        
        .usage-fill {
            height: 100%;
            border-radius: 6px;
            transition: width 0.8s ease-in-out;
            position: relative;
        }
        
        .usage-fill.low {
            background: linear-gradient(90deg, #28a745, #34ce57);
        }
        
        .usage-fill.medium {
            background: linear-gradient(90deg, #ffc107, #ffcd39);
        }
        
        .usage-fill.high {
            background: linear-gradient(90deg, #dc3545, #e55370);
        }
        
        .usage-fill::after {
            content: '';
            position: absolute;
            top: 0;
            left: 0;
            right: 0;
            bottom: 0;
            background: linear-gradient(90deg, transparent, rgba(255,255,255,0.3), transparent);
            animation: shimmer 2s infinite;
        }
        
        @keyframes shimmer {
            0% { transform: translateX(-100%); }
            100% { transform: translateX(100%); }
        }
        
        .usage-percent {
            font-size: 12px;
            font-weight: 600;
            color: #495057;
        }
        
        /* Tableaux modernes */
        .modern-table {
            background: white;
            border-radius: 10px;
            overflow: hidden;
            box-shadow: 0 4px 20px rgba(0,0,0,0.1);
        }
        
        .modern-table .widget-title {
            background: linear-gradient(135deg, #ffffffff, );
            color: white;
            padding: 20px;
            margin: 0;
        }
        
        .modern-table .widget-title h5 {
            margin: 0;
            font-size: 18px;
            font-weight: 600;
        }
        
        .modern-table table {
            margin: 0;
        }
        
        .modern-table thead th {
            background: #f8f9fa;
            color: #495057;
            font-weight: 600;
            padding: 15px 12px;
            border-bottom: 2px solid #dee2e6;
            font-size: 13px;
            text-transform: uppercase;
            letter-spacing: 0.5px;
        }
        
        .modern-table tbody tr {
            transition: background-color 0.2s;
        }
        
        .modern-table tbody tr:hover {
            background-color: #f8f9fa;
        }
        
        .modern-table tbody td {
            padding: 15px 12px;
            vertical-align: middle;
            border-bottom: 1px solid #f1f3f4;
        }
        
        /* Boutons d'action modernes */
        .action-buttons {
            display: flex;
            gap: 6px;
            flex-wrap: wrap;
        }
        
        .btn-modern {
            padding: 6px 10px;
            border-radius: 6px;
            font-size: 12px;
            font-weight: 500;
            border: none;
            cursor: pointer;
            transition: all 0.2s;
            display: inline-flex;
            align-items: center;
            gap: 4px;
        }
        
        .btn-modern:hover {
            transform: translateY(-1px);
            box-shadow: 0 4px 12px rgba(0,0,0,0.15);
        }
        
        .btn-modern.btn-info {
            background: linear-gradient(135deg, #17a2b8, #20c997);
            color: white;
        }
        
        .btn-modern.btn-warning {
            background: linear-gradient(135deg, #ffc107, #fd7e14);
            color: #212529;
        }
        
        .btn-modern.btn-danger {
            background: linear-gradient(135deg, #dc3545, #e74c3c);
            color: white;
        }
        
        .btn-modern.btn-success {
            background: linear-gradient(135deg, #28a745, #20c997);
            color: white;
        }
        
        /* Formulaire de mise à jour rapide */
        .quick-update-form {
            display: inline-flex;
            align-items: center;
            gap: 6px;
            margin-top: 5px;
        }
        
        .quick-update-input {
            width: 90px;
            padding: 4px 8px;
            border: 1px solid #dee2e6;
            border-radius: 4px;
            font-size: 12px;
        }
        
        .quick-update-btn {
            padding: 4px 8px;
            background: #17a2b8;
            color: white;
            border: none;
            border-radius: 4px;
            cursor: pointer;
            font-size: 11px;
        }
        
        .quick-update-btn:hover {
            background: #138496;
        }
        
        /* Messages d'alerte */
        .alert-modern {
            padding: 15px 20px;
            border-radius: 8px;
            margin-bottom: 20px;
            display: flex;
            align-items: center;
            gap: 10px;
            font-weight: 500;
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
        
        /* Pagination moderne */
        .modern-pagination {
            display: flex;
            justify-content: center;
            align-items: center;
            gap: 8px;
            margin-top: 30px;
        }
        
        .modern-pagination a {
            padding: 10px 15px;
            background: white;
            color: #6c757d;
            text-decoration: none;
            border-radius: 8px;
            border: 1px solid #dee2e6;
            font-weight: 500;
            transition: all 0.2s;
        }
        
        .modern-pagination a:hover {
            background: #007bff;
            color: white;
            border-color: #007bff;
            transform: translateY(-1px);
        }
        
        .modern-pagination a.active {
            background: #007bff;
            color: white;
            border-color: #007bff;
        }
        
        /* Responsive */
        @media (max-width: 768px) {
            .stats-dashboard {
                flex-direction: column;
            }
            
            .filter-buttons {
                flex-direction: column;
            }
            
            .action-buttons {
                flex-direction: column;
            }
            
            .usage-container {
                flex-direction: column;
                align-items: flex-start;
            }
        }
        
        /* Animations */
        .fade-in {
            animation: fadeIn 0.5s ease-in;
        }
        
        @keyframes fadeIn {
            from { opacity: 0; transform: translateY(20px); }
            to { opacity: 1; transform: translateY(0); }
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
                <a href="manage_customer_master.php" class="current">💳 Répertoire Client & Plafonds</a>
            </div>
            <h1>💳 Répertoire Client avec Gestion des Plafonds de Crédit</h1>
        </div>
        
        <div class="container-fluid">
            <!-- Messages de confirmation/erreur -->
            <?php if (isset($message)): ?>
            <div class="alert-modern <?php echo $messageType; ?> fade-in">
                <i class="icon-<?php echo $messageType == 'success' ? 'ok' : 'warning-sign'; ?>"></i>
                <?php echo $message; ?>
            </div>
            <?php endif; ?>

            <!-- Tableau de bord des statistiques -->
            <div class="stats-dashboard fade-in">
                <div class="stat-card primary">
                    <div class="stat-value"><?php echo number_format($stats['total_customers']); ?></div>
                    <div class="stat-label">Total Clients</div>
                    <i class="icon-user stat-icon"></i>
                </div>
                <div class="stat-card success">
                    <div class="stat-value"><?php echo number_format($stats['total_revenue'] / 1000000, 1); ?></div>
                    <div class="stat-label">Chiffre d'Affaires (GNF)</div>
                    <i class="icon-money stat-icon"></i>
                </div>
                <div class="stat-card warning">
                    <div class="stat-value"><?php echo number_format($stats['total_dues'] / 1000000, 1); ?></div>
                    <div class="stat-label">Créances Totales (GNF)</div>
                    <i class="icon-time stat-icon"></i>
                </div>
                <div class="stat-card info">
                    <div class="stat-value"><?php echo number_format($stats['total_credit_limits'] / 1000000, 1); ?></div>
                    <div class="stat-label">Plafonds Totaux (GNF)</div>
                    <i class="icon-credit-card stat-icon"></i>
                </div>
                <div class="stat-card danger">
                    <div class="stat-value"><?php echo number_format($stats['customers_over_limit']); ?></div>
                    <div class="stat-label">⚠️ Plafonds Dépassés</div>
                    <i class="icon-warning-sign stat-icon"></i>
                </div>
            </div>

            <!-- Actions rapides -->
            <div class="row-fluid">
                <div class="span12">
                    <a href="add_customer_master.php" class="btn btn-primary">
                        <i class="icon-plus"></i> Ajouter un Client
                    </a>
                    <a href="dettecart.php" class="btn btn-success">
                        <i class="icon-shopping-cart"></i> Nouvelle Vente
                    </a>
                    <a href="manage_customers.php" class="btn btn-info">
                        <i class="icon-file-text"></i> Historique Factures
                    </a>
                    <a href="recouvrement.php" class="btn btn-warning">
                        <i class="icon-time"></i> Gestion Échéances
                    </a>
                </div>
            </div>
            <hr>

            <!-- Section de filtres moderne -->
            <div class="filters-section fade-in">
                <div class="filter-title">
                    <i class="icon-filter"></i> Filtrer par statut de crédit
                </div>
                
                <div class="filter-buttons">
                    <a href="?<?php echo http_build_query(array_merge($_GET, ['credit_filter' => ''])); ?>" 
                       class="filter-btn <?php echo empty($creditFilter) ? 'active' : ''; ?>">
                        <i class="icon-th-list"></i> Tous (<?php echo number_format($stats['total_customers']); ?>)
                    </a>
                    <a href="?<?php echo http_build_query(array_merge($_GET, ['credit_filter' => 'no_limit'])); ?>" 
                       class="filter-btn <?php echo $creditFilter == 'no_limit' ? 'active' : ''; ?>">
                        <i class="icon-unlock"></i> Sans Limite (<?php echo number_format($stats['total_customers'] - $stats['customers_with_limits']); ?>)
                    </a>
                    <a href="?<?php echo http_build_query(array_merge($_GET, ['credit_filter' => 'within_limit'])); ?>" 
                       class="filter-btn success <?php echo $creditFilter == 'within_limit' ? 'active' : ''; ?>">
                        <i class="icon-ok"></i> Dans les Limites
                    </a>
                    <a href="?<?php echo http_build_query(array_merge($_GET, ['credit_filter' => 'near_limit'])); ?>" 
                       class="filter-btn warning <?php echo $creditFilter == 'near_limit' ? 'active' : ''; ?>">
                        <i class="icon-warning-sign"></i> Près du Plafond (<?php echo $stats['customers_near_limit']; ?>)
                    </a>
                    <a href="?<?php echo http_build_query(array_merge($_GET, ['credit_filter' => 'over_limit'])); ?>" 
                       class="filter-btn danger <?php echo $creditFilter == 'over_limit' ? 'active' : ''; ?>">
                        <i class="icon-remove"></i> Plafond Dépassé (<?php echo $stats['customers_over_limit']; ?>)
                    </a>
                </div>

                <!-- Barre de recherche -->
                <form method="get" class="form-inline">
                    <div class="row-fluid">
                        <div class="span8">
                            <div class="input-append">
                                <input type="text" name="search" value="<?php echo htmlspecialchars($searchTerm); ?>" 
                                       placeholder="🔍 Rechercher par nom, téléphone ou email..." class="span11">
                                <button type="submit" class="btn btn-primary">
                                    <i class="icon-search"></i>
                                </button>
                            </div>
                        </div>
                        <div class="span4">
                            <?php if (!empty($creditFilter)): ?>
                                <input type="hidden" name="credit_filter" value="<?php echo htmlspecialchars($creditFilter); ?>">
                            <?php endif; ?>
                            <?php if (!empty($searchTerm) || !empty($creditFilter)): ?>
                            <a href="manage_customer_master.php" class="btn btn-warning">
                                <i class="icon-remove"></i> Effacer Filtres
                            </a>
                            <?php endif; ?>
                        </div>
                    </div>
                </form>
            </div>

            <!-- Liste des clients moderne -->
            <div class="modern-table fade-in">
                <div class="widget-title">
                    <h5><i class="icon-credit-card"></i> Clients avec Plafonds de Crédit (<?php echo $totalRecords; ?>)</h5>
                </div>
                <div class="widget-content nopadding">
                    <table class="table table-striped">
                        <thead>
                            <tr>
                                <th>#</th>
                                <th>Client</th>
                                <th>Contact</th>
                                <th>Plafond & Modification</th>
                                <th>Créances Actuelles</th>
                                <th>Utilisation du Crédit</th>
                                <th>Statut</th>
                                <th>Activité</th>
                                <th>Actions</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php 
                            if (mysqli_num_rows($customersResult) > 0) {
                                $i = $offset + 1;
                                while ($customer = mysqli_fetch_assoc($customersResult)) {
                                    $duesClass = $customer['TotalDues'] > 0 ? 'montant-echu' : 'montant-normal';
                                    $lastPurchase = $customer['LastPurchaseDate'] ? 
                                        date('d/m/Y', strtotime($customer['LastPurchaseDate'])) : 
                                        'Jamais';
                                    
                                    $creditLimit = floatval($customer['CreditLimit']);
                                    $totalDues = floatval($customer['TotalDues']);
                                    
                                    // Calculer le statut du crédit
                                    $creditStatus = '';
                                    $creditClass = '';
                                    $usagePercent = 0;
                                    
                                    if ($creditLimit == 0) {
                                        $creditStatus = 'Aucune limite';
                                        $creditClass = 'credit-none';
                                    } else {
                                        $usagePercent = min(100, round(($totalDues / $creditLimit) * 100, 1));
                                        
                                        if ($totalDues > $creditLimit) {
                                            $creditStatus = 'DÉPASSÉ';
                                            $creditClass = 'credit-danger';
                                        } elseif ($totalDues > ($creditLimit * 0.8)) {
                                            $creditStatus = 'Près limite';
                                            $creditClass = 'credit-warning';
                                        } else {
                                            $creditStatus = 'OK';
                                            $creditClass = 'credit-ok';
                                        }
                                    }
                                    
                                    // Classe pour la barre d'utilisation
                                    $usageClass = $usagePercent < 50 ? 'low' : ($usagePercent < 80 ? 'medium' : 'high');
                                    ?>
                                    <tr>
                                        <td><strong><?php echo $i++; ?></strong></td>
                                        <td>
                                            <div>
                                                <strong><?php echo htmlspecialchars($customer['CustomerName']); ?></strong>
                                                <br>
                                                <small class="text-muted">
                                                    <?php echo htmlspecialchars($customer['CustomerEmail'] ?: 'Email non renseigné'); ?>
                                                </small>
                                            </div>
                                        </td>
                                        <td>
                                            <a href="tel:<?php echo $customer['CustomerContact']; ?>" style="text-decoration: none;">
                                                <i class="icon-phone"></i> <?php echo htmlspecialchars($customer['CustomerContact']); ?>
                                            </a>
                                        </td>
                                        <td>
                                            <div style="color: #495057; font-weight: 600;">
                                                <?php echo number_format($creditLimit, 0, ',', ' '); ?> GNF
                                            </div>
                                            <form method="post" class="quick-update-form">
                                                <input type="hidden" name="customer_id" value="<?php echo $customer['id']; ?>">
                                                <input type="number" name="new_credit_limit" value="<?php echo $creditLimit; ?>" 
                                                       min="0" step="1000" class="quick-update-input">
                                                <button type="submit" name="quick_update_credit" class="quick-update-btn" title="Mettre à jour">
                                                    <i class="icon-refresh"></i>
                                                </button>
                                            </form>
                                        </td>
                                        <td>
                                            <span style="color: <?php echo $totalDues > 0 ? '#dc3545' : '#28a745'; ?>; font-weight: 600;">
                                                <?php echo number_format($totalDues, 0, ',', ' '); ?> GNF
                                            </span>
                                        </td>
                                        <td>
                                            <?php if ($creditLimit > 0): ?>
                                                <div class="usage-container">
                                                    <div class="usage-bar">
                                                        <div class="usage-fill <?php echo $usageClass; ?>" 
                                                             style="width: <?php echo min(100, $usagePercent); ?>%" 
                                                             data-percent="<?php echo $usagePercent; ?>"></div>
                                                    </div>
                                                    <span class="usage-percent"><?php echo $usagePercent; ?>%</span>
                                                </div>
                                            <?php else: ?>
                                                <span class="text-muted">-</span>
                                            <?php endif; ?>
                                        </td>
                                        <td>
                                            <span class="credit-status <?php echo $creditClass; ?>"><?php echo $creditStatus; ?></span>
                                        </td>
                                        <td>
                                            <div style="font-size: 12px;">
                                                <strong><?php echo $customer['TotalInvoices']; ?></strong> facture(s)
                                                <br>
                                                <span class="text-muted"><?php echo $lastPurchase; ?></span>
                                            </div>
                                        </td>
                                        <td>
                                            <div class="action-buttons">
                                                <a href="customer_details.php?id=<?php echo $customer['id']; ?>" 
                                                   class="btn-modern btn-info" title="Voir détails">
                                                    <i class="icon-eye-open"></i>
                                                </a>
                                                <a href="edit_customer_master.php?id=<?php echo $customer['id']; ?>" 
                                                   class="btn-modern btn-warning" title="Modifier">
                                                    <i class="icon-edit"></i>
                                                </a>
                                                <?php if ($customer['TotalInvoices'] == 0): ?>
                                                <a href="manage_customer_master.php?delete_id=<?php echo $customer['id']; ?>" 
                                                   onclick="return confirm('Êtes-vous sûr de vouloir supprimer ce client ?')" 
                                                   class="btn-modern btn-danger" title="Supprimer">
                                                    <i class="icon-trash"></i>
                                                </a>
                                                <?php endif; ?>
                                            </div>
                                        </td>
                                    </tr>
                                    <?php
                                }
                            } else {
                                ?>
                                <tr>
                                    <td colspan="9" class="text-center" style="color: #6c757d; padding: 40px;">
                                        <i class="icon-info-sign" style="font-size: 24px; margin-bottom: 10px;"></i>
                                        <br>
                                        <?php echo !empty($searchTerm) ? 'Aucun client trouvé pour cette recherche' : 'Aucun client enregistré'; ?>
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

           

    <?php include_once('includes/footer.php'); ?>
    
    <script src="js/jquery.min.js"></script>
    <script src="js/bootstrap.min.js"></script>
    <script src="js/matrix.js"></script>
    
    <script>
    $(document).ready(function() {
        // Animation des barres d'utilisation au chargement
        setTimeout(function() {
            $('.usage-fill').each(function() {
                const targetWidth = $(this).data('percent') + '%';
                $(this).css('width', '0%').animate({
                    width: targetWidth
                }, 1000, 'easeOutCubic');
            });
        }, 500);
        
        // Confirmation pour la mise à jour rapide des plafonds
        $('form.quick-update-form').on('submit', function(e) {
            const newLimit = $(this).find('input[name="new_credit_limit"]').val();
            const customerName = $(this).closest('tr').find('td:nth-child(2) strong').text().trim();
            
            const confirmed = confirm(
                '💳 Confirmer la modification du plafond de crédit ?\n\n' +
                '👤 Client: ' + customerName + '\n' +
                '💰 Nouveau plafond: ' + formatNumber(newLimit) + ' GNF'
            );
            
            if (!confirmed) {
                e.preventDefault();
                return false;
            }
        });
        
        // Effet hover sur les cartes statistiques
        $('.stat-card').hover(
            function() {
                $(this).css('transform', 'translateY(-5px) scale(1.02)');
            },
            function() {
                $(this).css('transform', 'translateY(0) scale(1)');
            }
        );
        
        // Fonction de formatage des nombres
        function formatNumber(num) {
            return parseInt(num).toLocaleString('fr-FR');
        }
        
        // Auto-refresh des données toutes les 5 minutes
        setInterval(function() {
            if (!document.hidden) {
                // Refresh silencieux des statistiques via AJAX
                location.reload();
            }
        }, 300000); // 5 minutes
        
        // Effet de pulse sur les éléments dangereux
        $('.credit-danger').each(function() {
            setInterval(() => {
                $(this).animate({opacity: 0.7}, 500).animate({opacity: 1}, 500);
            }, 3000);
        });
    });
    </script>
</body>
</html>