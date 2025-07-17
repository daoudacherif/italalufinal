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
        .customer-stats {
            background-color: #e7f3ff;
            border-left: 4px solid #007bff;
            padding: 15px;
            margin-bottom: 20px;
        }
        .credit-stats {
            background-color: #fff3cd;
            border-left: 4px solid #ffc107;
            padding: 15px;
            margin-bottom: 20px;
        }
        .stat-box {
            display: inline-block;
            margin-right: 30px;
            text-align: center;
            min-width: 120px;
        }
        .stat-value {
            font-size: 18px;
            font-weight: bold;
            color: #333;
        }
        .stat-label {
            font-size: 11px;
            color: #666;
        }
        .customer-actions {
            white-space: nowrap;
        }
        .dues-high {
            color: #d9534f;
            font-weight: bold;
        }
        .dues-zero {
            color: #5cb85c;
        }
        .credit-status {
            padding: 2px 6px;
            border-radius: 3px;
            font-size: 11px;
            font-weight: bold;
        }
        .credit-ok {
            background-color: #d4edda;
            color: #155724;
        }
        .credit-warning {
            background-color: #fff3cd;
            color: #856404;
        }
        .credit-danger {
            background-color: #f8d7da;
            color: #721c24;
        }
        .credit-none {
            background-color: #e2e3e5;
            color: #6c757d;
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
        }
        .pagination a.active {
            background-color: #007bff;
            color: white;
            border: 1px solid #007bff;
        }
        .pagination a:hover:not(.active) {
            background-color: #ddd;
        }
        .quick-update-form {
            display: inline-block;
            margin-left: 5px;
        }
        .quick-update-form input {
            width: 80px;
            font-size: 11px;
            padding: 2px 5px;
            margin: 0 2px;
        }
        .quick-update-form button {
            padding: 2px 6px;
            font-size: 11px;
        }
        .credit-filter-bar {
            background-color: #f8f9fa;
            padding: 10px;
            border-radius: 4px;
            margin-bottom: 15px;
        }
        .filter-button {
            margin-right: 5px;
            margin-bottom: 5px;
        }
        .usage-bar {
            width: 60px;
            height: 10px;
            background-color: #e9ecef;
            border-radius: 5px;
            overflow: hidden;
            display: inline-block;
            vertical-align: middle;
        }
        .usage-fill {
            height: 100%;
            transition: width 0.3s;
        }
        .usage-fill.low {
            background-color: #28a745;
        }
        .usage-fill.medium {
            background-color: #ffc107;
        }
        .usage-fill.high {
            background-color: #dc3545;
        }
        .message {
            padding: 10px;
            margin-bottom: 15px;
            border-radius: 4px;
        }
        .message.success {
            background-color: #d4edda;
            border-color: #c3e6cb;
            color: #155724;
        }
        .message.error {
            background-color: #f8d7da;
            border-color: #f5c6cb;
            color: #721c24;
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
            <div class="message <?php echo $messageType; ?>">
                <i class="icon-<?php echo $messageType == 'success' ? 'ok' : 'warning-sign'; ?>"></i>
                <?php echo $message; ?>
            </div>
            <?php endif; ?>

            <!-- Statistiques générales -->
            <div class="customer-stats">
                <div class="stat-box">
                    <div class="stat-value"><?php echo number_format($stats['total_customers']); ?></div>
                    <div class="stat-label">Total Clients</div>
                </div>
                <div class="stat-box">
                    <div class="stat-value"><?php echo number_format($stats['total_revenue']); ?></div>
                    <div class="stat-label">Chiffre d'Affaires (GNF)</div>
                </div>
                <div class="stat-box">
                    <div class="stat-value"><?php echo number_format($stats['total_dues']); ?></div>
                    <div class="stat-label">Créances Totales (GNF)</div>
                </div>
                <div class="stat-box">
                    <div class="stat-value"><?php echo number_format($stats['customers_with_dues']); ?></div>
                    <div class="stat-label">Clients avec Créances</div>
                </div>
            </div>

            <!-- Statistiques des plafonds -->
            <div class="credit-stats">
                <div class="stat-box">
                    <div class="stat-value"><?php echo number_format($stats['total_credit_limits']); ?></div>
                    <div class="stat-label">Plafonds Totaux (GNF)</div>
                </div>
                <div class="stat-box">
                    <div class="stat-value"><?php echo number_format($stats['customers_with_limits']); ?></div>
                    <div class="stat-label">Clients avec Plafonds</div>
                </div>
                <div class="stat-box">
                    <div class="stat-value"><?php echo number_format($stats['customers_over_limit']); ?></div>
                    <div class="stat-label">⚠️ Plafonds Dépassés</div>
                </div>
                <div class="stat-box">
                    <div class="stat-value"><?php echo number_format($stats['customers_near_limit']); ?></div>
                    <div class="stat-label">⚡ Près du Plafond</div>
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
                </div>
            </div>
            <hr>

            <!-- Filtres par statut de crédit -->
            <div class="credit-filter-bar">
                <strong><i class="icon-filter"></i> Filtrer par statut de crédit :</strong>
                <a href="?<?php echo http_build_query(array_merge($_GET, ['credit_filter' => ''])); ?>" 
                   class="btn btn-small filter-button <?php echo empty($creditFilter) ? 'btn-primary' : ''; ?>">
                    Tous
                </a>
                <a href="?<?php echo http_build_query(array_merge($_GET, ['credit_filter' => 'no_limit'])); ?>" 
                   class="btn btn-small filter-button <?php echo $creditFilter == 'no_limit' ? 'btn-primary' : ''; ?>">
                    Sans Limite (<?php echo number_format($stats['total_customers'] - $stats['customers_with_limits']); ?>)
                </a>
                <a href="?<?php echo http_build_query(array_merge($_GET, ['credit_filter' => 'within_limit'])); ?>" 
                   class="btn btn-small btn-success filter-button <?php echo $creditFilter == 'within_limit' ? 'btn-primary' : ''; ?>">
                    Dans les Limites
                </a>
                <a href="?<?php echo http_build_query(array_merge($_GET, ['credit_filter' => 'near_limit'])); ?>" 
                   class="btn btn-small btn-warning filter-button <?php echo $creditFilter == 'near_limit' ? 'btn-primary' : ''; ?>">
                    Près du Plafond (<?php echo $stats['customers_near_limit']; ?>)
                </a>
                <a href="?<?php echo http_build_query(array_merge($_GET, ['credit_filter' => 'over_limit'])); ?>" 
                   class="btn btn-small btn-danger filter-button <?php echo $creditFilter == 'over_limit' ? 'btn-primary' : ''; ?>">
                    Plafond Dépassé (<?php echo $stats['customers_over_limit']; ?>)
                </a>
            </div>

            <!-- Barre de recherche -->
            <div class="row-fluid">
                <div class="span12">
                    <form method="get" class="form-inline">
                        <div class="input-append">
                            <input type="text" name="search" value="<?php echo htmlspecialchars($searchTerm); ?>" 
                                   placeholder="Rechercher par nom, téléphone ou email..." class="span4">
                            <button type="submit" class="btn btn-primary">
                                <i class="icon-search"></i> Rechercher
                            </button>
                        </div>
                        <?php if (!empty($creditFilter)): ?>
                            <input type="hidden" name="credit_filter" value="<?php echo htmlspecialchars($creditFilter); ?>">
                        <?php endif; ?>
                        <?php if (!empty($searchTerm) || !empty($creditFilter)): ?>
                        <a href="manage_customer_master.php" class="btn">
                            <i class="icon-remove"></i> Effacer Filtres
                        </a>
                        <?php endif; ?>
                    </form>
                </div>
            </div>
            <hr>

            <!-- Liste des clients -->
            <div class="widget-box">
                <div class="widget-title">
                    <span class="icon"><i class="icon-th"></i></span>
                    <h5>💳 Clients avec Plafonds de Crédit (<?php echo $totalRecords; ?>)</h5>
                </div>
                <div class="widget-content nopadding">
                    <table class="table table-bordered table-striped" style="font-size: 13px;">
                        <thead>
                            <tr>
                                <th>#</th>
                                <th>Nom</th>
                                <th>Téléphone</th>
                                <th>Email</th>
                                <th>Plafond Crédit</th>
                                <th>Créances</th>
                                <th>Utilisation</th>
                                <th>Statut</th>
                                <th>Factures</th>
                                <th>Achats Totaux</th>
                                <th>Dernier Achat</th>
                                <th>Actions</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php 
                            if (mysqli_num_rows($customersResult) > 0) {
                                $i = $offset + 1;
                                while ($customer = mysqli_fetch_assoc($customersResult)) {
                                    $duesClass = $customer['TotalDues'] > 0 ? 'dues-high' : 'dues-zero';
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
                                        <td><?php echo $i++; ?></td>
                                        <td><strong><?php echo htmlspecialchars($customer['CustomerName']); ?></strong></td>
                                        <td><?php echo htmlspecialchars($customer['CustomerContact']); ?></td>
                                        <td><?php echo htmlspecialchars($customer['CustomerEmail'] ?: '-'); ?></td>
                                        <td>
                                            <?php echo number_format($creditLimit, 0, ',', ' '); ?> GNF
                                            <form method="post" class="quick-update-form">
                                                <input type="hidden" name="customer_id" value="<?php echo $customer['id']; ?>">
                                                <input type="number" name="new_credit_limit" value="<?php echo $creditLimit; ?>" min="0" step="1000">
                                                <button type="submit" name="quick_update_credit" class="btn btn-mini btn-info" title="Mettre à jour">
                                                    <i class="icon-refresh"></i>
                                                </button>
                                            </form>
                                        </td>
                                        <td class="<?php echo $duesClass; ?>">
                                            <?php echo number_format($totalDues, 0, ',', ' '); ?> GNF
                                        </td>
                                        <td>
                                            <?php if ($creditLimit > 0): ?>
                                                <div class="usage-bar">
                                                    <div class="usage-fill <?php echo $usageClass; ?>" 
                                                         style="width: <?php echo min(100, $usagePercent); ?>%"></div>
                                                </div>
                                                <small><?php echo $usagePercent; ?>%</small>
                                            <?php else: ?>
                                                -
                                            <?php endif; ?>
                                        </td>
                                        <td>
                                            <span class="credit-status <?php echo $creditClass; ?>"><?php echo $creditStatus; ?></span>
                                        </td>
                                        <td>
                                            <span class="badge badge-info"><?php echo $customer['TotalInvoices']; ?></span>
                                        </td>
                                        <td><?php echo number_format($customer['TotalPurchases'], 0, ',', ' '); ?> GNF</td>
                                        <td><?php echo $lastPurchase; ?></td>
                                        <td class="customer-actions">
                                            <a href="customer_details.php?id=<?php echo $customer['id']; ?>" 
                                               class="btn btn-info btn-mini" title="Voir détails">
                                                <i class="icon-eye-open"></i>
                                            </a>
                                            <a href="edit_customer_master.php?id=<?php echo $customer['id']; ?>" 
                                               class="btn btn-warning btn-mini" title="Modifier">
                                                <i class="icon-edit"></i>
                                            </a>
                                            <?php if ($customer['TotalInvoices'] == 0): ?>
                                            <a href="manage_customer_master.php?delete_id=<?php echo $customer['id']; ?>" 
                                               onclick="return confirm('Êtes-vous sûr de vouloir supprimer ce client ?')" 
                                               class="btn btn-danger btn-mini" title="Supprimer">
                                                <i class="icon-trash"></i>
                                            </a>
                                            <?php endif; ?>
                                        </td>
                                    </tr>
                                    <?php
                                }
                            } else {
                                ?>
                                <tr>
                                    <td colspan="12" class="text-center" style="color: red;">
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

            <!-- Pagination -->
            <?php if ($totalPages > 1): ?>
            <div class="pagination">
                <?php if ($page > 1): ?>
                    <a href="?<?php echo http_build_query(array_merge($_GET, ['page' => $page-1])); ?>">&laquo; Précédent</a>
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
                    <a href="?<?php echo http_build_query(array_merge($_GET, ['page' => $page+1])); ?>">Suivant &raquo;</a>
                <?php endif; ?>
            </div>
            <?php endif; ?>

            <!-- Légende -->
            <div style="margin-top: 20px; padding: 15px; background-color: #f8f9fa; border-radius: 4px;">
                <h6><i class="icon-info-sign"></i> Légende des Statuts de Crédit :</h6>
                <div style="margin-top: 10px;">
                    <span class="credit-status credit-none">Aucune limite</span> - Client sans plafond de crédit défini<br>
                    <span class="credit-status credit-ok">OK</span> - Crédit dans les limites autorisées<br>
                    <span class="credit-status credit-warning">Près limite</span> - Utilisation >80% du plafond<br>
                    <span class="credit-status credit-danger">DÉPASSÉ</span> - Plafond de crédit dépassé ⚠️
                </div>
                <p style="margin-top: 10px; font-size: 12px; color: #666;">
                    <strong>Modification rapide :</strong> Utilisez les champs de saisie à côté des plafonds pour modifier rapidement les limites de crédit.
                </p>
            </div>
        </div>
    </div>

    <?php include_once('includes/footer.php'); ?>
    
    <script src="js/jquery.min.js"></script>
    <script src="js/bootstrap.min.js"></script>
    <script src="js/matrix.js"></script>
    
    <script>
    $(document).ready(function() {
        // Confirmation pour la mise à jour rapide des plafonds
        $('form.quick-update-form').on('submit', function(e) {
            const newLimit = $(this).find('input[name="new_credit_limit"]').val();
            const customerName = $(this).closest('tr').find('td:nth-child(2)').text().trim();
            
            const confirmed = confirm(
                'Confirmer la modification du plafond de crédit ?\n\n' +
                'Client: ' + customerName + '\n' +
                'Nouveau plafond: ' + formatNumber(newLimit) + ' GNF'
            );
            
            if (!confirmed) {
                e.preventDefault();
                return false;
            }
        });
        
        // Animation des barres d'utilisation
        $('.usage-fill').each(function() {
            const width = $(this).css('width');
            $(this).css('width', '0').animate({width: width}, 1000);
        });
        
        function formatNumber(num) {
            return num.toString().replace(/\B(?=(\d{3})+(?!\d))/g, " ");
        }
    });
    </script>
</body>
</html>