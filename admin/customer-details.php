<?php
session_start();
error_reporting(0);
include('includes/dbconnection.php');

// Check admin login
if (strlen($_SESSION['imsaid'] == 0)) {
    header('location:logout.php');
    exit;
}
?>
<!DOCTYPE html>
<html lang="fr">
<head>
    <title>Gestion des Stocks | Détails Client</title>
    <?php include_once('includes/cs.php'); ?>
    <?php include_once('includes/responsive.php'); ?>
    <style>
        .status-paid {
            background-color: #dff0d8;
            color: #3c763d;
            padding: 5px 10px;
            border-radius: 3px;
            font-weight: bold;
        }
        .status-pending {
            background-color: #fcf8e3;
            color: #8a6d3b;
            padding: 5px 10px;
            border-radius: 3px;
            font-weight: bold;
        }
        .btn-action {
            margin-right: 5px;
        }
        .customer-summary {
            background-color: #f9f9f9;
            border-radius: 5px;
            padding: 15px;
            margin-bottom: 20px;
        }
        .summary-box {
            text-align: center;
            padding: 15px;
            border-radius: 5px;
            margin-bottom: 15px;
        }
        .summary-box h4 {
            margin-top: 0;
        }
        .summary-box h3 {
            margin: 5px 0;
            font-size: 24px;
        }
        .total-sales {
            background-color: #d9edf7;
        }
        .total-paid {
            background-color: #dff0d8;
        }
        .total-pending {
            background-color: #fcf8e3;
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
      <a href="customer-details.php" class="current">Détails Client</a>
    </div>
    <h1>Clients / Factures</h1>
  </div>

  <div class="container-fluid">
    <hr>
    
    <!-- Customer Summary -->
    <div class="row-fluid">
        <div class="span12 customer-summary">
            <div class="row-fluid">
                <?php
                // Calculate totals
                $sqlTotals = "SELECT 
                    COUNT(*) as totalCustomers,
                    SUM(FinalAmount) as totalSales,
                    SUM(Paid) as totalPaid,
                    SUM(Dues) as totalDues,
                    COUNT(CASE WHEN Dues = 0 THEN 1 END) as paidCustomers,
                    COUNT(CASE WHEN Dues > 0 THEN 1 END) as pendingCustomers
                FROM tblcustomer";
                $resTotals = mysqli_query($con, $sqlTotals);
                $rowTotals = mysqli_fetch_assoc($resTotals);
                ?>
                <div class="span3">
                    <div class="summary-box total-sales">
                        <h4>Total Factures</h4>
                        <h3><?php echo number_format($rowTotals['totalSales'], 0); ?></h3>
                        <p><?php echo $rowTotals['totalCustomers']; ?> clients</p>
                    </div>
                </div>
                <div class="span3">
                    <div class="summary-box total-paid">
                        <h4>Montants Payés</h4>
                        <h3><?php echo number_format($rowTotals['totalPaid'], 0); ?></h3>
                        <p><?php echo $rowTotals['paidCustomers']; ?> factures soldées</p>
                    </div>
                </div>
                <div class="span3">
                    <div class="summary-box total-pending">
                        <h4>Montants Dus</h4>
                        <h3><?php echo number_format($rowTotals['totalDues'], 0); ?></h3>
                        <p><?php echo $rowTotals['pendingCustomers']; ?> factures en attente</p>
                    </div>
                </div>
                <div class="span3">
                    <div class="summary-box">
                        <h4>Actions Rapides</h4>
                        <a href="payment-history.php" class="btn btn-info btn-block">
                            <i class="icon-time"></i> Historique des Paiements
                        </a>
                        <a href="daily-repport.php" class="btn btn-success btn-block" style="margin-top: 5px;">
                            <i class="icon-signal"></i> Rapport Financier
                        </a>
                    </div>
                </div>
            </div>
        </div>
    </div>
    
    <!-- Customer List -->
    <div class="row-fluid">
      <div class="span12">
        <div class="widget-box">
          <div class="widget-title">
            <span class="icon"><i class="icon-th"></i></span>
            <h5>Liste des Factures</h5>
          </div>
          <div class="widget-content nopadding">
            <table class="table table-bordered data-table">
              <thead>
                <tr>
                  <th width="5%">N°</th>
                  <th width="10%">Facture #</th>
                  <th width="20%">Client</th>
                  <th width="10%">Date</th>
                  <th width="10%">Montant</th>
                  <th width="10%">Payé</th>
                  <th width="10%">Dû</th>
                  <th width="10%">Statut</th>
                  <th width="15%">Actions</th>
                </tr>
              </thead>
              <tbody>
                <?php
                // Initialize totals
                $totalPaid = 0;
                $totalDues = 0;

                // Fetch all rows from tblcustomer
                $ret = mysqli_query($con, "SELECT * FROM tblcustomer ORDER BY ID DESC");
                $cnt = 1;
                while ($row = mysqli_fetch_array($ret)) {
                    // Accumulate for totals
                    $totalPaid += intval($row['Paid']);
                    $totalDues += intval($row['Dues']);
                ?>
                    <tr class="gradeX">
                      <td><?php echo $cnt; ?></td>
                      <td><?php echo $row['BillingNumber']; ?></td>
                      <td>
                        <strong><?php echo $row['CustomerName']; ?></strong>
                        <?php if(!empty($row['MobileNumber'])) { ?>
                            <br><small><?php echo $row['MobileNumber']; ?></small>
                        <?php } ?>
                      </td>
                      <td><?php echo date('d/m/Y', strtotime($row['BillingDate'])); ?></td>
                      <td><?php echo number_format(intval($row['FinalAmount']), 0); ?></td>
                      <td><?php echo number_format(intval($row['Paid']), 0); ?></td>
                      <td><?php echo number_format(intval($row['Dues']), 0); ?></td>
                      <td>
                        <?php if ($row['Dues'] <= 0) { ?>
                            <span class="status-paid">Soldé</span>
                        <?php } else { ?>
                            <span class="status-pending">En attente</span>
                        <?php } ?>
                      </td>
                      <td>
                        <?php if ($row['Dues'] > 0) { ?>
                            <a href="make-payment.php?id=<?php echo $row['ID']; ?>" class="btn btn-info btn-mini btn-action" title="Effectuer un paiement">
                                <i class="icon-money"></i>
                            </a>
                        <?php } ?>
                        <a href="view-customer.php?id=<?php echo $row['ID']; ?>" class="btn btn-success btn-mini btn-action" title="Voir les détails">
                            <i class="icon-eye-open"></i>
                        </a>
                        <a href="payment-history.php?cid=<?php echo $row['ID']; ?>" class="btn btn-primary btn-mini btn-action" title="Historique des paiements">
                            <i class="icon-time"></i>
                        </a>
                      </td>
                    </tr>
                <?php
                    $cnt++;
                } // end while
                ?>
              </tbody>
              <!-- Add a final row for totals -->
              <tfoot>
                <tr>
                  <th colspan="5" style="text-align: right; font-weight: bold;">
                    Totaux:
                  </th>
                  <th style="font-weight: bold;">
                    <?php echo number_format($totalPaid, 0); ?>
                  </th>
                  <th style="font-weight: bold;">
                    <?php echo number_format($totalDues, 0); ?>
                  </th>
                  <th colspan="2"></th>
                </tr>
              </tfoot>
            </table>
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