<?php
session_start();
error_reporting(E_ALL);
include('includes/dbconnection.php');

// Affiche toutes les erreurs (à désactiver en production)
ini_set('display_errors', 1);
ini_set('display_startup_errors', 1);
error_reporting(E_ALL);

// Verify admin is logged in
if (empty($_SESSION['imsaid'])) {
    header('Location: logout.php');
    exit;
}

// Get current admin details
$currentAdminID = $_SESSION['imsaid'];
$adminQuery = mysqli_query($con, "SELECT AdminName FROM tbladmin WHERE ID = '$currentAdminID'");
$adminData = mysqli_fetch_assoc($adminQuery);
$currentAdminName = $adminData['AdminName'];

// Get all admin names for the filter dropdown
$adminsQuery = mysqli_query($con, "SELECT ID, AdminName FROM tbladmin ORDER BY AdminName ASC");
$adminsList = array();
while ($admin = mysqli_fetch_assoc($adminsQuery)) {
    $adminsList[$admin['ID']] = $admin['AdminName'];
}

// Initialize filter variables
$selectedAdminID = isset($_GET['admin_id']) ? intval($_GET['admin_id']) : 0;
$selectedInvoiceType = isset($_GET['invoice_type']) ? $_GET['invoice_type'] : 'all';
$startDate = isset($_GET['start_date']) ? $_GET['start_date'] : '';
$endDate = isset($_GET['end_date']) ? $_GET['end_date'] : '';
$searchTerm = isset($_GET['search']) ? mysqli_real_escape_string($con, $_GET['search']) : '';

// Pagination settings
$page = isset($_GET['page']) ? intval($_GET['page']) : 1;
$recordsPerPage = 20;
$offset = ($page - 1) * $recordsPerPage;

// Build the SQL query - both for counting and for the actual data
$countSql = "SELECT COUNT(*) as total FROM (";
$dataSql = "";

// Base queries for regular invoices (tblcart) - Added Paid & Dues columns to match credit query
$regularInvoicesQuery = "
    SELECT DISTINCT 
        cust.BillingNumber, 
        cust.CustomerName, 
        cust.MobileNumber, 
        cust.ModeofPayment,
        cust.BillingDate, 
        cust.FinalAmount,
        cust.FinalAmount as Paid,   /* Standard invoices are fully paid */
        0 as Dues,                  /* Standard invoices have no dues */
        a.AdminName,
        a.ID as AdminID,
        'standard' as InvoiceType
    FROM tblcustomer cust
    JOIN tblcart c ON cust.BillingNumber = c.BillingId
    JOIN tbladmin a ON c.AdminID = a.ID
    WHERE c.IsCheckOut = 1
";

// Base queries for credit invoices (tblcreditcart)
$creditInvoicesQuery = "
    SELECT DISTINCT 
        cust.BillingNumber, 
        cust.CustomerName, 
        cust.MobileNumber, 
        cust.ModeofPayment,         /* Changed to match exactly the column name in tblcustomer */
        cust.BillingDate, 
        cust.FinalAmount,
        cust.Paid,
        cust.Dues,
        a.AdminName,
        a.ID as AdminID,
        'credit' as InvoiceType
    FROM tblcustomer cust
    JOIN tblcreditcart c ON cust.BillingNumber = c.BillingId
    JOIN tbladmin a ON c.AdminID = a.ID
    WHERE c.IsCheckOut = 1
";

// Apply filters
if ($selectedAdminID > 0) {
    $adminFilter = " AND a.ID = $selectedAdminID";
    $regularInvoicesQuery .= $adminFilter;
    $creditInvoicesQuery .= $adminFilter;
}

if (!empty($searchTerm)) {
    $searchFilter = " AND (cust.BillingNumber LIKE '%$searchTerm%' OR cust.CustomerName LIKE '%$searchTerm%' OR cust.MobileNumber LIKE '%$searchTerm%')";
    $regularInvoicesQuery .= $searchFilter;
    $creditInvoicesQuery .= $searchFilter;
}

if (!empty($startDate)) {
    $dateFilter = " AND cust.BillingDate >= '$startDate'";
    $regularInvoicesQuery .= $dateFilter;
    $creditInvoicesQuery .= $dateFilter;
}

if (!empty($endDate)) {
    $dateFilter = " AND DATE(cust.BillingDate) <= '$endDate'";
    $regularInvoicesQuery .= $dateFilter;
    $creditInvoicesQuery .= $dateFilter;
}

// Complete the SQL based on the invoice type filter
if ($selectedInvoiceType == 'standard' || $selectedInvoiceType == 'all') {
    $dataSql .= $regularInvoicesQuery;
}

if ($selectedInvoiceType == 'credit' || $selectedInvoiceType == 'all') {
    if (!empty($dataSql)) {
        $dataSql .= " UNION ";
    }
    $dataSql .= $creditInvoicesQuery;
}

// Complete the count query
$countSql .= $dataSql . ") as combined_results";

// Add ordering and pagination to the data query
$dataSql .= " ORDER BY BillingDate DESC LIMIT $offset, $recordsPerPage";

// Execute count query
$totalRecords = 0;
$countResult = mysqli_query($con, $countSql);
if ($countResult) {
    $row = mysqli_fetch_assoc($countResult);
    $totalRecords = $row['total'];
}

// Calculate total pages
$totalPages = ceil($totalRecords / $recordsPerPage);

// Execute data query
$result = mysqli_query($con, $dataSql);
$invoices = array();
if ($result) {
    while ($row = mysqli_fetch_assoc($result)) {
        $invoices[] = $row;
    }
}

// Check if the current database has AdminID columns in cart tables
$hasAdminID = false;
$cartColumnsQuery = mysqli_query($con, "SHOW COLUMNS FROM tblcart");
if ($cartColumnsQuery) {
    while ($col = mysqli_fetch_assoc($cartColumnsQuery)) {
        if ($col['Field'] == 'AdminID') {
            $hasAdminID = true;
            break;
        }
    }
}

// Special note if AdminID is missing
$adminIdMissingNote = "";
if (!$hasAdminID) {
    $adminIdMissingNote = "<div class='alert alert-warning'>
        <strong>Note:</strong> Pour activer le filtre par administrateur, il faut ajouter la colonne AdminID aux tables tblcart et tblcreditcart. 
        Sans cette colonne, les factures ne peuvent pas être reliées aux administrateurs qui les ont traitées.
    </div>";
}
?>

<!DOCTYPE html>
<html lang="fr">
<head>
    <title>Système de gestion des stocks | Factures par Administrateur</title>
    <?php include_once('includes/cs.php'); ?>
    <?php include_once('includes/responsive.php'); ?>
    <style>
        .filter-section {
            background-color: #f9f9f9;
            padding: 15px;
            border-radius: 5px;
            margin-bottom: 20px;
            border: 1px solid #e9e9e9;
        }
        
        .invoice-type-badge {
            display: inline-block;
            padding: 2px 8px;
            border-radius: 3px;
            font-size: 11px;
            font-weight: bold;
            margin-left: 5px;
        }
        
        .standard-invoice {
            background-color: #dff0d8;
            color: #3c763d;
        }
        
        .credit-invoice {
            background-color: #fcf8e3;
            color: #8a6d3b;
        }
        
        .pagination {
            text-align: center;
            margin: 20px 0;
        }
        
        .dues-badge {
            background-color: #f2dede;
            color: #a94442;
            padding: 2px 5px;
            border-radius: 3px;
            font-size: 11px;
        }
        
        .fully-paid {
            background-color: #dff0d8;
            color: #3c763d;
        }
    </style>
</head>
<body>
    <!-- Header + Sidebar -->
    <?php include_once('includes/header.php'); ?>
    <?php include_once('includes/sidebar.php'); ?>

    <div id="content">
        <div id="content-header">
            <div id="breadcrumb">
                <a href="dashboard.php" title="Aller à l'accueil" class="tip-bottom">
                    <i class="icon-home"></i> Accueil
                </a>
                <a href="admin_invoices.php" class="current">Factures par Administrateur</a>
            </div>
            <h1>Suivi des Factures par Administrateur</h1>
        </div>

        <div class="container-fluid">
            <?php echo $adminIdMissingNote; ?>
            
            <!-- Filtres -->
            <div class="filter-section">
                <form method="get" action="admin_invoices.php" class="form-inline">
                    <div class="row-fluid">
                        <div class="span3">
                            <label>Administrateur :</label>
                            <select name="admin_id" class="span12">
                                <option value="0">Tous les administrateurs</option>
                                <?php foreach ($adminsList as $id => $name): ?>
                                <option value="<?php echo $id; ?>" <?php echo ($selectedAdminID == $id) ? 'selected' : ''; ?>>
                                    <?php echo htmlspecialchars($name); ?>
                                </option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                        
                        <div class="span3">
                            <label>Type de facture :</label>
                            <select name="invoice_type" class="span12">
                                <option value="all" <?php echo ($selectedInvoiceType == 'all') ? 'selected' : ''; ?>>Tous les types</option>
                                <option value="standard" <?php echo ($selectedInvoiceType == 'standard') ? 'selected' : ''; ?>>Standard</option>
                                <option value="credit" <?php echo ($selectedInvoiceType == 'credit') ? 'selected' : ''; ?>>Crédit</option>
                            </select>
                        </div>
                        
                        <div class="span3">
                            <label>Date début :</label>
                            <input type="date" name="start_date" class="span12" value="<?php echo $startDate; ?>">
                        </div>
                        
                        <div class="span3">
                            <label>Date fin :</label>
                            <input type="date" name="end_date" class="span12" value="<?php echo $endDate; ?>">
                        </div>
                    </div>
                    
                    <div class="row-fluid" style="margin-top: 10px;">
                        <div class="span8">
                            <label>Recherche (N° facture, client, téléphone) :</label>
                            <input type="text" name="search" class="span12" value="<?php echo htmlspecialchars($searchTerm); ?>" placeholder="Rechercher...">
                        </div>
                        
                        <div class="span4" style="text-align: right; margin-top: 25px;">
                            <button type="submit" class="btn btn-primary">
                                <i class="icon-search"></i> Filtrer
                            </button>
                            <a href="admin_invoices.php" class="btn">Réinitialiser</a>
                        </div>
                    </div>
                </form>
            </div>
            
            <!-- Tableau des résultats -->
            <div class="widget-box">
                <div class="widget-title">
                    <span class="icon"><i class="icon-th"></i></span>
                    <h5>Factures (<?php echo $totalRecords; ?> résultats)</h5>
                </div>
                <div class="widget-content nopadding">
                    <table class="table table-bordered table-striped">
                        <thead>
                            <tr>
                                <th>N° Facture</th>
                                <th>Date</th>
                                <th>Client</th>
                                <th>Téléphone</th>
                                <th>Mode de Paiement</th>
                                <th>Montant</th>
                                <th>État</th>
                                <th>Traité par</th>
                                <th>Actions</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php if (empty($invoices)): ?>
                            <tr>
                                <td colspan="9" style="text-align: center;">Aucune facture trouvée</td>
                            </tr>
                            <?php else: ?>
                                <?php foreach ($invoices as $invoice): ?>
                                <tr>
                                    <td>
                                        <?php echo $invoice['BillingNumber']; ?>
                                        <span class="invoice-type-badge <?php echo $invoice['InvoiceType']; ?>-invoice">
                                            <?php echo ($invoice['InvoiceType'] == 'standard') ? 'Standard' : 'Crédit'; ?>
                                        </span>
                                    </td>
                                    <td><?php echo date('d/m/Y H:i', strtotime($invoice['BillingDate'])); ?></td>
                                    <td><?php echo htmlspecialchars($invoice['CustomerName']); ?></td>
                                    <td><?php echo $invoice['MobileNumber']; ?></td>
                                    <td><?php echo $invoice['ModeofPayment']; ?></td>
                                    <td><?php echo number_format($invoice['FinalAmount'], 2); ?> GNF</td>
                                    <td>
                                        <?php if ($invoice['InvoiceType'] == 'credit'): ?>
                                            <?php if ($invoice['Dues'] > 0): ?>
                                                <span class="dues-badge">
                                                    Reste dû: <?php echo number_format($invoice['Dues'], 2); ?> GNF
                                                </span>
                                            <?php else: ?>
                                                <span class="dues-badge fully-paid">Payé</span>
                                            <?php endif; ?>
                                        <?php else: ?>
                                            <span class="dues-badge fully-paid">Payé</span>
                                        <?php endif; ?>
                                    </td>
                                    <td><?php echo htmlspecialchars($invoice['AdminName']); ?></td>
                                    <td>
                                        <?php if ($invoice['InvoiceType'] == 'standard'): ?>
                                            <a href="invoice.php?invoiceid=<?php echo $invoice['BillingNumber']; ?>" class="btn btn-primary btn-mini">
                                                <i class="icon-eye-open"></i> Voir
                                            </a>
                                        <?php else: ?>
                                            <a href="invoice_dettecard.php?invoiceid=<?php echo $invoice['BillingNumber']; ?>" class="btn btn-primary btn-mini">
                                                <i class="icon-eye-open"></i> Voir
                                            </a>
                                        <?php endif; ?>
                                    </td>
                                </tr>
                                <?php endforeach; ?>
                            <?php endif; ?>
                        </tbody>
                    </table>
                </div>
            </div>
            
            <!-- Pagination -->
            <?php if ($totalPages > 1): ?>
            <div class="pagination">
                <ul>
                    <?php if ($page > 1): ?>
                    <li>
                        <a href="admin_invoices.php?page=<?php echo ($page - 1); ?>&admin_id=<?php echo $selectedAdminID; ?>&invoice_type=<?php echo $selectedInvoiceType; ?>&start_date=<?php echo $startDate; ?>&end_date=<?php echo $endDate; ?>&search=<?php echo urlencode($searchTerm); ?>">Précédent</a>
                    </li>
                    <?php endif; ?>
                    
                    <?php 
                        $startPage = max(1, $page - 2);
                        $endPage = min($totalPages, $startPage + 4);
                        for ($i = $startPage; $i <= $endPage; $i++): 
                    ?>
                    <li <?php echo ($i == $page) ? 'class="active"' : ''; ?>>
                        <a href="admin_invoices.php?page=<?php echo $i; ?>&admin_id=<?php echo $selectedAdminID; ?>&invoice_type=<?php echo $selectedInvoiceType; ?>&start_date=<?php echo $startDate; ?>&end_date=<?php echo $endDate; ?>&search=<?php echo urlencode($searchTerm); ?>"><?php echo $i; ?></a>
                    </li>
                    <?php endfor; ?>
                    
                    <?php if ($page < $totalPages): ?>
                    <li>
                        <a href="admin_invoices.php?page=<?php echo ($page + 1); ?>&admin_id=<?php echo $selectedAdminID; ?>&invoice_type=<?php echo $selectedInvoiceType; ?>&start_date=<?php echo $startDate; ?>&end_date=<?php echo $endDate; ?>&search=<?php echo urlencode($searchTerm); ?>">Suivant</a>
                    </li>
                    <?php endif; ?>
                </ul>
            </div>
            <?php endif; ?>
            
            <!-- Statistiques -->
            <div class="row-fluid">
                <div class="span12">
                    <div class="widget-box">
                        <div class="widget-title">
                            <span class="icon"><i class="icon-signal"></i></span>
                            <h5>Statistiques des factures</h5>
                        </div>
                        <div class="widget-content">
                            <div class="row-fluid">
                                <div class="span6">
                                    <h4>Total des factures : <?php echo $totalRecords; ?></h4>
                                    <?php
                                        // Get statistics on invoice counts by admin
                                        $adminStatsSql = "
                                            SELECT AdminName, COUNT(*) as invoice_count
                                            FROM (
                                                SELECT AdminName FROM ($regularInvoicesQuery) AS reg
                                                UNION ALL
                                                SELECT AdminName FROM ($creditInvoicesQuery) AS cred
                                            ) as invoices
                                            GROUP BY AdminName
                                            ORDER BY invoice_count DESC
                                            LIMIT 5
                                        ";
                                        
                                        $statsResult = mysqli_query($con, $adminStatsSql);
                                        if ($statsResult && mysqli_num_rows($statsResult) > 0) {
                                            echo '<table class="table table-striped">';
                                            echo '<thead><tr><th>Administrateur</th><th>Nombre de factures</th></tr></thead>';
                                            echo '<tbody>';
                                            while ($stat = mysqli_fetch_assoc($statsResult)) {
                                                echo '<tr>';
                                                echo '<td>' . htmlspecialchars($stat['AdminName']) . '</td>';
                                                echo '<td>' . $stat['invoice_count'] . '</td>';
                                                echo '</tr>';
                                            }
                                            echo '</tbody></table>';
                                        }
                                    ?>
                                </div>
                                <div class="span6">
                                    <h4>Montants par type de facture</h4>
                                    <?php
                                        // Get statistics on amounts by invoice type
                                        $typeStatsSql = "
                                            SELECT 
                                                type_label,
                                                COUNT(*) as count,
                                                SUM(amount) as total_amount,
                                                AVG(amount) as avg_amount
                                            FROM (
                                                SELECT 
                                                    CASE 
                                                        WHEN InvoiceType = 'standard' THEN 'Standard'
                                                        ELSE 'Crédit'
                                                    END as type_label,
                                                    FinalAmount as amount
                                                FROM (
                                                    $regularInvoicesQuery
                                                    UNION
                                                    $creditInvoicesQuery
                                                ) as all_invoices
                                            ) as summary
                                            GROUP BY type_label
                                        ";
                                        
                                        $typeStatsResult = mysqli_query($con, $typeStatsSql);
                                        if ($typeStatsResult && mysqli_num_rows($typeStatsResult) > 0) {
                                            echo '<table class="table table-striped">';
                                            echo '<thead><tr><th>Type</th><th>Nombre</th><th>Montant total</th><th>Montant moyen</th></tr></thead>';
                                            echo '<tbody>';
                                            while ($stat = mysqli_fetch_assoc($typeStatsResult)) {
                                                echo '<tr>';
                                                echo '<td>' . $stat['type_label'] . '</td>';
                                                echo '<td>' . $stat['count'] . '</td>';
                                                echo '<td>' . number_format($stat['total_amount'], 2) . ' GNF</td>';
                                                echo '<td>' . number_format($stat['avg_amount'], 2) . ' GNF</td>';
                                                echo '</tr>';
                                            }
                                            echo '</tbody></table>';
                                        }
                                    ?>
                                </div>
                            </div>
                        </div>
                    </div>
                </div>
            </div>
        </div>
    </div>

    <!-- Footer -->
    <?php include_once('includes/footer.php'); ?>

    <!-- Scripts -->
    <script src="js/jquery.min.js"></script>
    <script src="js/jquery.ui.custom.js"></script>
    <script src="js/bootstrap.min.js"></script>
    <script src="js/jquery.uniform.js"></script>
    <script src="js/select2.min.js"></script>
    <script src="js/jquery.dataTables.min.js"></script>
    <script src="js/matrix.js"></script>
    <script src="js/matrix.tables.js"></script>
</body>
</html>