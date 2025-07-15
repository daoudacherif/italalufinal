<?php
session_start();
error_reporting(0);
include('includes/dbconnection.php');

// Check admin login
if (strlen($_SESSION['imsaid'] == 0)) {
    header('location:logout.php');
    exit;
}

// Get customer ID if provided
$customerID = isset($_GET['cid']) ? intval($_GET['cid']) : 0;

// Determine if filtering by date
$filterByDate = isset($_GET['start']) && isset($_GET['end']);
$startDate = $filterByDate ? $_GET['start'] : date('Y-m-d', strtotime('-30 days'));
$endDate = $filterByDate ? $_GET['end'] : date('Y-m-d');

// Format for SQL
$startDateTime = $startDate . " 00:00:00";
$endDateTime = $endDate . " 23:59:59";

// Get customer info if viewing single customer
$customerName = '';
if ($customerID > 0) {
    $sqlCustomer = "SELECT CustomerName FROM tblcustomer WHERE ID = $customerID LIMIT 1";
    $resultCustomer = mysqli_query($con, $sqlCustomer);
    if ($rowCustomer = mysqli_fetch_assoc($resultCustomer)) {
        $customerName = $rowCustomer['CustomerName'];
    }
}

?>
<!DOCTYPE html>
<html lang="fr">
<head>
    <title>Gestion des Stocks | Historique des Paiements</title>
    <?php include_once('includes/cs.php'); ?>
    <?php include_once('includes/responsive.php'); ?>
    <style>
        .export-buttons {
            margin-bottom: 15px;
        }
        .payment-method-cash {
            background-color: #dff0d8;
        }
        .payment-method-card {
            background-color: #d9edf7;
        }
        .payment-method-transfer {
            background-color: #fcf8e3;
        }
        .payment-method-mobile {
            background-color: #f2dede;
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
      <a href="customer-details.php">Détails Client</a>
      <a href="payment-history.php" class="current">Historique Paiements</a>
    </div>
    <h1>
        Historique des Paiements
        <?php if (!empty($customerName)) echo ' - ' . htmlspecialchars($customerName); ?>
    </h1>
  </div>

  <div class="container-fluid">
    <hr>
    <!-- Date filter form -->
    <div class="row-fluid">
      <div class="span12">
        <div class="widget-box">
          <div class="widget-title">
            <span class="icon"><i class="icon-filter"></i></span>
            <h5>Filtrer les paiements</h5>
          </div>
          <div class="widget-content nopadding">
            <form method="get" class="form-horizontal">
              <?php if ($customerID > 0) { ?>
                <input type="hidden" name="cid" value="<?php echo $customerID; ?>" />
              <?php } ?>
              <div class="control-group">
                <label class="control-label">Date début :</label>
                <div class="controls">
                  <input type="date" name="start" value="<?php echo $startDate; ?>" class="span3" required />
                </div>
              </div>
              <div class="control-group">
                <label class="control-label">Date fin :</label>
                <div class="controls">
                  <input type="date" name="end" value="<?php echo $endDate; ?>" class="span3" required />
                </div>
              </div>
              <div class="form-actions">
                <button type="submit" class="btn btn-success"><i class="icon-search"></i> Filtrer</button>
                <a href="payment-history.php<?php echo $customerID > 0 ? '?cid='.$customerID : ''; ?>" class="btn">Réinitialiser</a>
                <?php if ($customerID > 0) { ?>
                    <a href="payment-history.php" class="btn btn-info">Voir tous les clients</a>
                <?php } ?>
              </div>
            </form>
          </div>
        </div>
      </div>
    </div>
    
    <!-- Export buttons -->
    <div class="row-fluid export-buttons">
      <div class="span12">
        <a href="#" onclick="window.print()" class="btn btn-primary"><i class="icon-print"></i> Imprimer</a>
        <!-- You can add PDF/Excel export functionality in the future if needed -->
      </div>
    </div>
    
    <!-- Payments summary -->
    <div class="row-fluid">
      <div class="span12">
        <div class="widget-box">
          <div class="widget-title">
            <span class="icon"><i class="icon-signal"></i></span>
            <h5>Résumé des Paiements</h5>
          </div>
          <div class="widget-content">
            <?php
            // Build the SQL query for totals
            $sqlTotals = "SELECT 
                            COUNT(*) as totalPayments,
                            SUM(PaymentAmount) as totalAmount,
                            SUM(CASE WHEN PaymentMethod = 'Cash' THEN PaymentAmount ELSE 0 END) as cashTotal,
                            SUM(CASE WHEN PaymentMethod = 'Card' THEN PaymentAmount ELSE 0 END) as cardTotal,
                            SUM(CASE WHEN PaymentMethod = 'Transfer' THEN PaymentAmount ELSE 0 END) as transferTotal,
                            SUM(CASE WHEN PaymentMethod = 'Mobile' THEN PaymentAmount ELSE 0 END) as mobileTotal
                          FROM tblpayments
                          WHERE 1=1 ";
            
            // Add date filter if set
            if ($filterByDate) {
                $sqlTotals .= "AND PaymentDate BETWEEN '$startDateTime' AND '$endDateTime' ";
            }
            
            // Add customer filter if set
            if ($customerID > 0) {
                $sqlTotals .= "AND CustomerID = $customerID ";
            }
            
            $resultTotals = mysqli_query($con, $sqlTotals);
            $rowTotals = mysqli_fetch_assoc($resultTotals);
            ?>
            
            <div class="row-fluid">
              <div class="span3">
                <div class="stat-box">
                  <div class="stat-label">Total Paiements</div>
                  <div class="stat-value"><?php echo number_format($rowTotals['totalAmount'], 0); ?></div>
                  <div class="stat-desc"><?php echo $rowTotals['totalPayments']; ?> transaction(s)</div>
                </div>
              </div>
              <div class="span2">
                <div class="stat-box payment-method-cash">
                  <div class="stat-label">Espèces</div>
                  <div class="stat-value"><?php echo number_format($rowTotals['cashTotal'], 0); ?></div>
                </div>
              </div>
              <div class="span2">
                <div class="stat-box payment-method-card">
                  <div class="stat-label">Carte</div>
                  <div class="stat-value"><?php echo number_format($rowTotals['cardTotal'], 0); ?></div>
                </div>
              </div>
              <div class="span2">
                <div class="stat-box payment-method-transfer">
                  <div class="stat-label">Virement</div>
                  <div class="stat-value"><?php echo number_format($rowTotals['transferTotal'], 0); ?></div>
                </div>
              </div>
              <div class="span2">
                <div class="stat-box payment-method-mobile">
                  <div class="stat-label">Mobile</div>
                  <div class="stat-value"><?php echo number_format($rowTotals['mobileTotal'], 0); ?></div>
                </div>
              </div>
            </div>
          </div>
        </div>
      </div>
    </div>
    
    <!-- Payments list -->
    <div class="row-fluid">
      <div class="span12">
        <div class="widget-box">
          <div class="widget-title">
            <span class="icon"><i class="icon-money"></i></span>
            <h5>Historique des Paiements</h5>
          </div>
          <div class="widget-content nopadding">
            <table class="table table-bordered data-table">
              <thead>
                <tr>
                  <th width="5%">N°</th>
                  <th width="10%">Facture #</th>
                  <?php if ($customerID == 0) { ?>
                  <th width="15%">Client</th>
                  <?php } ?>
                  <th width="10%">Montant</th>
                  <th width="10%">Mode</th>
                  <th width="15%">Date Paiement</th>
                  <th width="15%">Référence</th>
                  <th>Commentaires</th>
                </tr>
              </thead>
              <tbody>
                <?php
                // Build the SQL query based on filters
                $sql = "SELECT p.*, c.CustomerName, c.BillingNumber, c.MobileNumber 
                        FROM tblpayments p 
                        JOIN tblcustomer c ON p.CustomerID = c.ID 
                        WHERE 1=1 ";
                
                // Add date filter if set
                if ($filterByDate) {
                    $sql .= "AND p.PaymentDate BETWEEN '$startDateTime' AND '$endDateTime' ";
                }
                
                // Add customer filter if set
                if ($customerID > 0) {
                    $sql .= "AND p.CustomerID = $customerID ";
                }
                
                $sql .= "ORDER BY p.PaymentDate DESC";
                
                $result = mysqli_query($con, $sql);
                $cnt = 1;
                
                if (mysqli_num_rows($result) > 0) {
                    while ($row = mysqli_fetch_assoc($result)) {
                        $methodClass = '';
                        switch($row['PaymentMethod']) {
                            case 'Cash': $methodClass = 'payment-method-cash'; break;
                            case 'Card': $methodClass = 'payment-method-card'; break;
                            case 'Transfer': $methodClass = 'payment-method-transfer'; break;
                            case 'Mobile': $methodClass = 'payment-method-mobile'; break;
                        }
                ?>
                <tr class="<?php echo $methodClass; ?>">
                  <td><?php echo $cnt++; ?></td>
                  <td><?php echo $row['BillingNumber']; ?></td>
                  <?php if ($customerID == 0) { ?>
                  <td>
                    <a href="payment-history.php?cid=<?php echo $row['CustomerID']; ?>">
                      <?php echo $row['CustomerName']; ?>
                    </a>
                  </td>
                  <?php } ?>
                  <td><?php echo number_format($row['PaymentAmount'], 0); ?></td>
                  <td><?php echo $row['PaymentMethod']; ?></td>
                  <td><?php echo date('d/m/Y H:i', strtotime($row['PaymentDate'])); ?></td>
                  <td><?php echo $row['ReferenceNumber'] ? $row['ReferenceNumber'] : '-'; ?></td>
                  <td><?php echo $row['Comments'] ? $row['Comments'] : '-'; ?></td>
                </tr>
                <?php
                    }
                } else {
                ?>
                <tr>
                  <td colspan="<?php echo $customerID == 0 ? '8' : '7'; ?>" style="text-align:center;">
                    Aucun paiement trouvé pour cette période
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
<script src="js/jquery.ui.custom.js"></script>
<script src="js/bootstrap.min.js"></script>
<script src="js/jquery.uniform.js"></script>
<script src="js/select2.min.js"></script>
<script src="js/jquery.dataTables.min.js"></script>
<script src="js/matrix.js"></script>
<script src="js/matrix.tables.js"></script>
</body>
</html>