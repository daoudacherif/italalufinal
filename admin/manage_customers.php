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

// Recherche
$searchTerm = '';
$whereClause = '';
if (!empty($_GET['search'])) {
    $searchTerm = mysqli_real_escape_string($con, $_GET['search']);
    $whereClause = "WHERE CustomerName LIKE '%$searchTerm%' OR CustomerContact LIKE '%$searchTerm%' OR CustomerEmail LIKE '%$searchTerm%'";
}

// Pagination
$page = isset($_GET['page']) ? max(1, intval($_GET['page'])) : 1;
$recordsPerPage = 20;
$offset = ($page - 1) * $recordsPerPage;

// Compter le total
$countQuery = "SELECT COUNT(*) as total FROM v_customer_overview $whereClause";
$countResult = mysqli_query($con, $countQuery);
$totalRecords = mysqli_fetch_assoc($countResult)['total'];
$totalPages = ceil($totalRecords / $recordsPerPage);

// Récupérer les clients avec statistiques
$customersQuery = "SELECT * FROM v_customer_overview $whereClause 
                   ORDER BY LastPurchaseDate DESC 
                   LIMIT $offset, $recordsPerPage";
$customersResult = mysqli_query($con, $customersQuery);

// Statistiques générales
$stats = mysqli_fetch_assoc(mysqli_query($con, "
    SELECT 
        COUNT(*) as total_customers,
        SUM(TotalPurchases) as total_revenue,
        SUM(TotalDues) as total_dues,
        COUNT(CASE WHEN TotalDues > 0 THEN 1 END) as customers_with_dues
    FROM v_customer_overview
"));
?>

<!DOCTYPE html>
<html lang="fr">
<head>
    <title>Répertoire Client | Système de Gestion d'Inventaire</title>
    <?php include_once('includes/cs.php'); ?>
    <?php include_once('includes/responsive.php'); ?>
    <style>
        .customer-stats {
            background-color: #e7f3ff;
            border-left: 4px solid #007bff;
            padding: 15px;
            margin-bottom: 20px;
        }
        .stat-box {
            display: inline-block;
            margin-right: 30px;
        }
        .stat-value {
            font-size: 20px;
            font-weight: bold;
            color: #333;
        }
        .stat-label {
            font-size: 12px;
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
                <a href="manage_customer_master.php" class="current">Répertoire Client</a>
            </div>
            <h1>Répertoire Client</h1>
        </div>
        
        <div class="container-fluid">
            <!-- Statistiques générales -->
            <div class="customer-stats">
                <div class="stat-box">
                    <div class="stat-value"><?php echo number_format($stats['total_customers']); ?></div>
                    <div class="stat-label">Total Clients</div>
                </div>
                <div class="stat-box">
                    <div class="stat-value"><?php echo number_format($stats['total_revenue']); ?> GNF</div>
                    <div class="stat-label">Chiffre d'Affaires Total</div>
                </div>
                <div class="stat-box">
                    <div class="stat-value"><?php echo number_format($stats['total_dues']); ?> GNF</div>
                    <div class="stat-label">Créances Totales</div>
                </div>
                <div class="stat-box">
                    <div class="stat-value"><?php echo number_format($stats['customers_with_dues']); ?></div>
                    <div class="stat-label">Clients avec Créances</div>
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
                        <?php if (!empty($searchTerm)): ?>
                        <a href="manage_customer_master.php" class="btn">
                            <i class="icon-remove"></i> Effacer
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
                    <h5>Clients (<?php echo $totalRecords; ?>)</h5>
                </div>
                <div class="widget-content nopadding">
                    <table class="table table-bordered table-striped">
                        <thead>
                            <tr>
                                <th>#</th>
                                <th>Nom</th>
                                <th>Téléphone</th>
                                <th>Email</th>
                                <th>Inscription</th>
                                <th>Factures</th>
                                <th>Achats Totaux</th>
                                <th>Créances</th>
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
                                    ?>
                                    <tr>
                                        <td><?php echo $i++; ?></td>
                                        <td><strong><?php echo htmlspecialchars($customer['CustomerName']); ?></strong></td>
                                        <td><?php echo htmlspecialchars($customer['CustomerContact']); ?></td>
                                        <td><?php echo htmlspecialchars($customer['CustomerEmail'] ?: '-'); ?></td>
                                        <td><?php echo date('d/m/Y', strtotime($customer['CustomerRegdate'])); ?></td>
                                        <td>
                                            <span class="badge badge-info"><?php echo $customer['TotalInvoices']; ?></span>
                                        </td>
                                        <td><?php echo number_format($customer['TotalPurchases']); ?> GNF</td>
                                        <td class="<?php echo $duesClass; ?>">
                                            <?php echo number_format($customer['TotalDues']); ?> GNF
                                        </td>
                                        <td><?php echo $lastPurchase; ?></td>
                                        <td class="customer-actions">
                                            <a href="customer_details.php?id=<?php echo $customer['id']; ?>" 
                                               class="btn btn-info btn-small" title="Voir détails">
                                                <i class="icon-eye-open"></i>
                                            </a>
                                            <a href="edit_customer_master.php?id=<?php echo $customer['id']; ?>" 
                                               class="btn btn-warning btn-small" title="Modifier">
                                                <i class="icon-edit"></i>
                                            </a>
                                            <?php if ($customer['TotalInvoices'] == 0): ?>
                                            <a href="manage_customer_master.php?delete_id=<?php echo $customer['id']; ?>" 
                                               onclick="return confirm('Êtes-vous sûr de vouloir supprimer ce client ?')" 
                                               class="btn btn-danger btn-small" title="Supprimer">
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
                                    <td colspan="10" class="text-center" style="color: red;">
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
                    <a href="?page=<?php echo $page-1; ?><?php echo !empty($searchTerm) ? '&search='.urlencode($searchTerm) : ''; ?>">&laquo; Précédent</a>
                <?php endif; ?>
                
                <?php
                $start = max(1, $page - 2);
                $end = min($totalPages, $page + 2);
                
                for ($i = $start; $i <= $end; $i++): ?>
                    <a href="?page=<?php echo $i; ?><?php echo !empty($searchTerm) ? '&search='.urlencode($searchTerm) : ''; ?>" 
                       class="<?php echo $i == $page ? 'active' : ''; ?>">
                        <?php echo $i; ?>
                    </a>
                <?php endfor; ?>
                
                <?php if ($page < $totalPages): ?>
                    <a href="?page=<?php echo $page+1; ?><?php echo !empty($searchTerm) ? '&search='.urlencode($searchTerm) : ''; ?>">Suivant &raquo;</a>
                <?php endif; ?>
            </div>
            <?php endif; ?>
        </div>
    </div>

    <?php include_once('includes/footer.php'); ?>
    
    <script src="js/jquery.min.js"></script>
    <script src="js/bootstrap.min.js"></script>
    <script src="js/matrix.js"></script>
</body>
</html>