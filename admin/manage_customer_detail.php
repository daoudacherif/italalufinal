<?php 
session_start();
error_reporting(E_ALL);
include('includes/dbconnection.php');

// Vérifie que l'admin est connecté
if (empty($_SESSION['imsaid'])) {
    header('Location: logout.php');
    exit;
}

$customerId = isset($_GET['id']) ? intval($_GET['id']) : 0;

if (!$customerId) {
    header('Location: manage_customers.php');
    exit;
}

// Récupérer les informations du client
$customerQuery = "SELECT * FROM v_customer_overview WHERE id = '$customerId' LIMIT 1";
$customerResult = mysqli_query($con, $customerQuery);

if (mysqli_num_rows($customerResult) == 0) {
    header('Location: manage_customer_master.php');
    exit;
}

$customer = mysqli_fetch_assoc($customerResult);

// Récupérer l'historique des factures
$invoicesQuery = "
    SELECT 
        BillingNumber,
        BillingDate,
        ModeOfPayment,
        FinalAmount,
        Paid,
        Dues
    FROM tblcustomer 
    WHERE customer_master_id = '$customerId' 
    ORDER BY BillingDate DESC
";
$invoicesResult = mysqli_query($con, $invoicesQuery);

// Récupérer l'historique des paiements
$paymentsQuery = "
    SELECT 
        p.PaymentAmount,
        p.PaymentDate,
        p.PaymentMethod,
        p.ReferenceNumber,
        p.Comments,
        tc.BillingNumber
    FROM tblpayments p
    JOIN tblcustomer tc ON tc.ID = p.CustomerID
    WHERE tc.customer_master_id = '$customerId'
    ORDER BY p.PaymentDate DESC
";
$paymentsResult = mysqli_query($con, $paymentsQuery);
?>

<!DOCTYPE html>
<html lang="fr">
<head>
    <title>Détails Client | Système de Gestion d'Inventaire</title>
    <?php include_once('includes/cs.php'); ?>
    <?php include_once('includes/responsive.php'); ?>
    <style>
        .customer-info {
            background-color: #f8f9fa;
            border: 1px solid #dee2e6;
            border-radius: 5px;
            padding: 20px;
            margin-bottom: 20px;
        }
        .info-item {
            margin-bottom: 10px;
        }
        .info-label {
            font-weight: bold;
            display: inline-block;
            width: 150px;
        }
        .stats-box {
            background-color: #e7f3ff;
            border-left: 4px solid #007bff;
            padding: 15px;
            margin-bottom: 20px;
        }
        .stat-item {
            display: inline-block;
            margin-right: 30px;
        }
        .stat-value {
            font-size: 18px;
            font-weight: bold;
            color: #333;
        }
        .stat-label {
            font-size: 12px;
            color: #666;
        }
        .dues-high {
            color: #d9534f;
            font-weight: bold;
        }
        .dues-zero {
            color: #5cb85c;
        }
        .amount-paid {
            color: #5cb85c;
        }
        .amount-due {
            color: #d9534f;
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
                <a href="manage_customer_master.php" class="tip-bottom">
                    <i class="icon-user"></i> Répertoire Client
                </a>
                <a href="#" class="current">Détails Client</a>
            </div>
            <h1>Détails du Client</h1>
        </div>
        
        <div class="container-fluid">
            <!-- Informations du client -->
            <div class="customer-info">
                <h4><i class="icon-user"></i> Informations Personnelles</h4>
                <div class="info-item">
                    <span class="info-label">Nom :</span>
                    <?php echo htmlspecialchars($customer['CustomerName']); ?>
                </div>
                <div class="info-item">
                    <span class="info-label">Téléphone :</span>
                    <?php echo htmlspecialchars($customer['CustomerContact']); ?>
                </div>
                <div class="info-item">
                    <span class="info-label">Email :</span>
                    <?php echo htmlspecialchars($customer['CustomerEmail'] ?: 'Non renseigné'); ?>
                </div>
                <div class="info-item">
                    <span class="info-label">Adresse :</span>
                    <?php echo htmlspecialchars($customer['CustomerAddress'] ?: 'Non renseignée'); ?>
                </div>
                <div class="info-item">
                    <span class="info-label">Inscription :</span>
                    <?php echo date('d/m/Y H:i', strtotime($customer['CustomerRegdate'])); ?>
                </div>
                <div class="info-item">
                    <span class="info-label">Statut :</span>
                    <span class="badge badge-success"><?php echo ucfirst($customer['Status']); ?></span>
                </div>
            </div>

            <!-- Statistiques -->
            <div class="stats-box">
                <div class="stat-item">
                    <div class="stat-value"><?php echo number_format($customer['TotalInvoices']); ?></div>
                    <div class="stat-label">Factures</div>
                </div>
                <div class="stat-item">
                    <div class="stat-value"><?php echo number_format($customer['TotalPurchases']); ?> GNF</div>
                    <div class="stat-label">Achats Totaux</div>
                </div>
                <div class="stat-item">
                    <div class="stat-value"><?php echo number_format($customer['TotalPaid']); ?> GNF</div>
                    <div class="stat-label">Payé</div>
                </div>
                <div class="stat-item">
                    <div class="stat-value <?php echo $customer['TotalDues'] > 0 ? 'dues-high' : 'dues-zero'; ?>">
                        <?php echo number_format($customer['TotalDues']); ?> GNF
                    </div>
                    <div class="stat-label">Créances</div>
                </div>
                <div class="stat-item">
                    <div class="stat-value">
                        <?php echo $customer['LastPurchaseDate'] ? date('d/m/Y', strtotime($customer['LastPurchaseDate'])) : 'Jamais'; ?>
                    </div>
                    <div class="stat-label">Dernier Achat</div>
                </div>
            </div>

            <!-- Actions -->
            <div class="row-fluid">
                <div class="span12">
                    <a href="edit_customer_master.php?id=<?php echo $customer['id']; ?>" class="btn btn-warning">
                        <i class="icon-edit"></i> Modifier les Informations
                    </a>
                    <a href="dettecart.php" class="btn btn-success">
                        <i class="icon-shopping-cart"></i> Nouvelle Vente
                    </a>
                    <a href="manage_customer_master.php" class="btn btn-primary">
                        <i class="icon-arrow-left"></i> Retour au Répertoire
                    </a>
                </div>
            </div>
            <hr>

            <div class="row-fluid">
                <div class="span6">
                    <!-- Historique des factures -->
                    <div class="widget-box">
                        <div class="widget-title">
                            <span class="icon"><i class="icon-file-text"></i></span>
                            <h5>Historique des Factures</h5>
                        </div>
                        <div class="widget-content nopadding">
                            <table class="table table-bordered table-striped">
                                <thead>
                                    <tr>
                                        <th>N° Facture</th>
                                        <th>Date</th>
                                        <th>Montant</th>
                                        <th>Payé</th>
                                        <th>Dû</th>
                                    </tr>
                                </thead>
                                <tbody>
                                    <?php 
                                    if (mysqli_num_rows($invoicesResult) > 0) {
                                        while ($invoice = mysqli_fetch_assoc($invoicesResult)) {
                                            ?>
                                            <tr>
                                                <td>
                                                    <a href="invoice_details.php?billingnum=<?php echo $invoice['BillingNumber']; ?>">
                                                        <?php echo $invoice['BillingNumber']; ?>
                                                    </a>
                                                </td>
                                                <td><?php echo date('d/m/Y', strtotime($invoice['BillingDate'])); ?></td>
                                                <td><?php echo number_format($invoice['FinalAmount']); ?> GNF</td>
                                                <td class="amount-paid"><?php echo number_format($invoice['Paid']); ?> GNF</td>
                                                <td class="<?php echo $invoice['Dues'] > 0 ? 'amount-due' : 'dues-zero'; ?>">
                                                    <?php echo number_format($invoice['Dues']); ?> GNF
                                                </td>
                                            </tr>
                                            <?php
                                        }
                                    } else {
                                        ?>
                                        <tr>
                                            <td colspan="5" class="text-center" style="color: #666;">
                                                Aucune facture trouvée
                                            </td>
                                        </tr>
                                        <?php
                                    }
                                    ?>
                                </tbody>
                            </table>
                        </div>
                    </div>
                </div>

                <div class="span6">
                    <!-- Historique des paiements -->
                    <div class="widget-box">
                        <div class="widget-title">
                            <span class="icon"><i class="icon-money"></i></span>
                            <h5>Historique des Paiements</h5>
                        </div>
                        <div class="widget-content nopadding">
                            <table class="table table-bordered table-striped">
                                <thead>
                                    <tr>
                                        <th>Date</th>
                                        <th>Montant</th>
                                        <th>Méthode</th>
                                        <th>Facture</th>
                                    </tr>
                                </thead>
                                <tbody>
                                    <?php 
                                    if (mysqli_num_rows($paymentsResult) > 0) {
                                        while ($payment = mysqli_fetch_assoc($paymentsResult)) {
                                            ?>
                                            <tr>
                                                <td><?php echo date('d/m/Y', strtotime($payment['PaymentDate'])); ?></td>
                                                <td class="amount-paid"><?php echo number_format($payment['PaymentAmount']); ?> GNF</td>
                                                <td><?php echo ucfirst($payment['PaymentMethod']); ?></td>
                                                <td>
                                                    <a href="invoice_details.php?billingnum=<?php echo $payment['BillingNumber']; ?>">
                                                        <?php echo $payment['BillingNumber']; ?>
                                                    </a>
                                                </td>
                                            </tr>
                                            <?php
                                        }
                                    } else {
                                        ?>
                                        <tr>
                                            <td colspan="4" class="text-center" style="color: #666;">
                                                Aucun paiement trouvé
                                            </td>
                                        </tr>
                                        <?php
                                    }
                                    ?>
                                </tbody>
                            </table>
                        </div>
                    </div>
                </div>
            </div>
        </div>
    </div>

    <?php include_once('includes/footer.php'); ?>
    
    <script src="js/jquery.min.js"></script>
    <script src="js/bootstrap.min.js"></script>
    <script src="js/matrix.js"></script>
</body>
</html>