<?php
session_start();
error_reporting(0);
include('includes/dbconnection.php');

if (strlen($_SESSION['imsaid'] == 0)) {
  header('location:logout.php');
  exit;
}

// =================================
// 1) Calculer le solde de caisse actuel
// =================================
// 1.1 Ventes régulières du jour
$sqlRegularSales = "
  SELECT COALESCE(SUM(c.ProductQty * p.Price), 0) AS totalSales
  FROM tblcart c
  JOIN tblproducts p ON p.ID = c.ProductId
  WHERE c.IsCheckOut = '1'
    AND DATE(c.CartDate) = CURDATE()
";
$resRegularSales = mysqli_query($con, $sqlRegularSales);
$rowRegularSales = mysqli_fetch_assoc($resRegularSales);
$todayRegularSales = floatval($rowRegularSales['totalSales']);

// 1.2 Paiements clients du jour - UPDATED to use tblpayments
// 1.2.1 Tous les paiements clients (pour l'affichage)
$sqlAllCustomerPayments = "
  SELECT COALESCE(SUM(PaymentAmount), 0) AS totalPaid
  FROM tblpayments
  WHERE DATE(PaymentDate) = CURDATE()
";
$resAllCustomerPayments = mysqli_query($con, $sqlAllCustomerPayments);
$rowAllCustomerPayments = mysqli_fetch_assoc($resAllCustomerPayments);
$todayCustomerPaymentsAll = floatval($rowAllCustomerPayments['totalPaid']);

// 1.2.2 Uniquement les paiements en espèces - NOUVEAU
$sqlCashPayments = "
  SELECT COALESCE(SUM(PaymentAmount), 0) AS totalPaid
  FROM tblpayments
  WHERE DATE(PaymentDate) = CURDATE()
    AND PaymentMethod = 'Cash'
";
$resCashPayments = mysqli_query($con, $sqlCashPayments);
$rowCashPayments = mysqli_fetch_assoc($resCashPayments);
$todayCustomerPayments = floatval($rowCashPayments['totalPaid']);

// 1.2.3 Paiements par méthode (pour l'affichage détaillé) - NOUVEAU
$sqlPaymentMethods = "
  SELECT 
    PaymentMethod,
    COUNT(*) as count,
    SUM(PaymentAmount) as total
  FROM tblpayments
  WHERE DATE(PaymentDate) = CURDATE()
  GROUP BY PaymentMethod
";
$resPaymentMethods = mysqli_query($con, $sqlPaymentMethods);
$paymentsByMethod = [];
while ($row = mysqli_fetch_assoc($resPaymentMethods)) {
  $paymentsByMethod[$row['PaymentMethod']] = $row;
}

// 1.3 Dépôts et retraits manuels du jour
$sqlManualTransactions = "
  SELECT
    COALESCE(SUM(CASE WHEN TransType='IN' THEN Amount ELSE 0 END), 0) AS deposits,
    COALESCE(SUM(CASE WHEN TransType='OUT' THEN Amount ELSE 0 END), 0) AS withdrawals
  FROM tblcashtransactions
  WHERE DATE(TransDate) = CURDATE()
";
$resManualTransactions = mysqli_query($con, $sqlManualTransactions);
$rowManualTransactions = mysqli_fetch_assoc($resManualTransactions);
$todayDeposits = floatval($rowManualTransactions['deposits']);
$todayWithdrawals = floatval($rowManualTransactions['withdrawals']);

// 1.4 Retours du jour
$sqlReturns = "
  SELECT COALESCE(SUM(r.Quantity * p.Price), 0) AS totalReturns
  FROM tblreturns r
  JOIN tblproducts p ON p.ID = r.ProductID
  WHERE DATE(r.ReturnDate) = CURDATE()
";
$resReturns = mysqli_query($con, $sqlReturns);
$rowReturns = mysqli_fetch_assoc($resReturns);
$todayReturns = floatval($rowReturns['totalReturns']);

// 1.5 Calcul du solde disponible en caisse
// UPDATED: N'utilise que les paiements en espèces dans le calcul du solde
$availableCash = $todayDeposits + $todayRegularSales + $todayCustomerPayments - ($todayWithdrawals + $todayReturns);

// ==========================
// 2) Insertion d'un paiement fournisseur
// ==========================
$paymentError = '';
$paymentSuccess = '';

if (isset($_POST['submit'])) {
  $supplierID  = intval($_POST['supplierid']);
  $payDate     = $_POST['paydate'];
  $amount      = floatval($_POST['amount']);
  $comments    = mysqli_real_escape_string($con, $_POST['comments']);
  $paymentMode = mysqli_real_escape_string($con, $_POST['paymentmode']);
  $importationID = isset($_POST['importation_id']) ? intval($_POST['importation_id']) : null;

  if ($supplierID <= 0 || $amount <= 0) {
    $paymentError = 'Données invalides';
  } 
  // Vérifier si le solde de caisse est suffisant
  elseif ($amount > $availableCash) {
    $paymentError = 'Solde en caisse insuffisant pour effectuer ce paiement !';
  } 
  else {
    // 1) Débuter une transaction pour assurer la cohérence des données
    mysqli_begin_transaction($con);
    
    try {
      // 2) Enregistrer le paiement fournisseur avec lien optionnel vers importation
      $importClause = $importationID ? ", ImportationID = $importationID" : "";
      $sqlPayment = "
        INSERT INTO tblsupplierpayments(SupplierID, PaymentDate, Amount, Comments, PaymentMode $importClause)
        VALUES('$supplierID', '$payDate', '$amount', '$comments', '$paymentMode'" . 
        ($importationID ? ", $importationID" : "") . ")
      ";
      $resPayment = mysqli_query($con, $sqlPayment);
      
      if (!$resPayment) {
        throw new Exception("Erreur lors de l'insertion du paiement fournisseur");
      }
      
      // 3) Récupérer le nom du fournisseur pour le commentaire de la transaction
      $sqlSupplier = "SELECT SupplierName FROM tblsupplier WHERE ID = '$supplierID' LIMIT 1";
      $resSupplier = mysqli_query($con, $sqlSupplier);
      $rowSupplier = mysqli_fetch_assoc($resSupplier);
      $supplierName = isset($rowSupplier['SupplierName']) ? $rowSupplier['SupplierName'] : 'Fournisseur #'.$supplierID;
      
      // 4) Calculer le nouveau solde de caisse
      $newCashBalance = $availableCash - $amount;
      
      // 5) Enregistrer la transaction de caisse (OUT)
      $cashComment = "Paiement fournisseur: " . $supplierName;
      if ($importationID) {
        // Récupérer la référence d'importation
        $importRefQuery = mysqli_query($con, "SELECT ImportRef FROM tblimportations WHERE ID = $importationID");
        if ($importRefQuery && mysqli_num_rows($importRefQuery) > 0) {
          $importRef = mysqli_fetch_assoc($importRefQuery)['ImportRef'];
          $cashComment .= " (Import: $importRef)";
        }
      }
      if (!empty($comments)) {
        $cashComment .= " - " . $comments;
      }
      
      $sqlCashTrans = "
        INSERT INTO tblcashtransactions(TransDate, TransType, Amount, BalanceAfter, Comments)
        VALUES('$payDate', 'OUT', '$amount', '$newCashBalance', '$cashComment')
      ";
      $resCashTrans = mysqli_query($con, $sqlCashTrans);
      
      if (!$resCashTrans) {
        throw new Exception("Erreur lors de l'enregistrement de la transaction en caisse");
      }
      
      // 6) Tout s'est bien passé, on valide la transaction
      mysqli_commit($con);
      $paymentSuccess = 'Paiement enregistré et déduit de la caisse !';
      
      // Recalculer le solde disponible après le paiement
      $availableCash = $newCashBalance;
      
    } catch (Exception $e) {
      // Annuler toutes les modifications en cas d'erreur
      mysqli_rollback($con);
      $paymentError = $e->getMessage();
    }
  }
  
  if (!empty($paymentError)) {
    echo "<script>alert('$paymentError');</script>";
  } else if (!empty($paymentSuccess)) {
    echo "<script>alert('$paymentSuccess');</script>";
  }
}

// ==========================
// 3) Filtre pour afficher le total pour un fournisseur - AMÉLIORÉ AVEC IMPORTATIONS
// ==========================
$selectedSupplier = 0;
$totalArrivals = 0;
$totalImportations = 0;
$totalPaid = 0;
$totalDue = 0;
$supplierImportations = [];
$supplierArrivals = [];

if (isset($_GET['supplierSearch'])) {
  $selectedSupplier = intval($_GET['supplierSearch']);

  if ($selectedSupplier > 0) {
    // *** NOUVEAU : Calculer le total des importations (coût complet avec frais) ***
    $sqlImportations = "
      SELECT 
        i.ID as ImportID,
        i.ImportRef,
        i.BLNumber,
        i.ImportDate,
        i.TotalValueUSD,
        i.ExchangeRate,
        i.TotalValueGNF,
        i.TotalFees,
        i.TotalCostGNF,
        i.Status,
        i.Description,
        COALESCE(SUM(sp.Amount), 0) as PaidAmount,
        (i.TotalCostGNF - COALESCE(SUM(sp.Amount), 0)) as RemainingAmount
      FROM tblimportations i
      LEFT JOIN tblsupplierpayments sp ON sp.SupplierID = i.SupplierID AND sp.ImportationID = i.ID
      WHERE i.SupplierID = '$selectedSupplier'
      GROUP BY i.ID
      ORDER BY i.ImportDate DESC, i.ID DESC
    ";
    $resImportations = mysqli_query($con, $sqlImportations);
    
    $totalImportationsCost = 0;
    $totalImportationsPaid = 0;
    while ($imp = mysqli_fetch_assoc($resImportations)) {
      $supplierImportations[] = $imp;
      $totalImportationsCost += floatval($imp['TotalCostGNF']);
      $totalImportationsPaid += floatval($imp['PaidAmount']);
    }
    
    // *** Calculer aussi les arrivages anciens (sans importation) ***
    $sqlArrivalsOld = "
      SELECT COALESCE(SUM(Cost), 0) as sumArrivals
      FROM tblproductarrivals
      WHERE SupplierID = '$selectedSupplier' 
        AND (ImportationID IS NULL OR ImportationID = 0)
    ";
    $resArrivalsOld = mysqli_query($con, $sqlArrivalsOld);
    $rowArrivalsOld = mysqli_fetch_assoc($resArrivalsOld);
    $totalArrivalsOld = floatval($rowArrivalsOld['sumArrivals']);

    // *** Calculer la somme des paiements généraux (sans importation spécifique) ***
    $sqlPayGeneral = "
      SELECT COALESCE(SUM(Amount), 0) as sumPaid
      FROM tblsupplierpayments
      WHERE SupplierID = '$selectedSupplier'
        AND (ImportationID IS NULL OR ImportationID = 0)
    ";
    $resPayGeneral = mysqli_query($con, $sqlPayGeneral);
    $rowPayGeneral = mysqli_fetch_assoc($resPayGeneral);
    $totalPaidGeneral = floatval($rowPayGeneral['sumPaid']);

    // *** Totaux finaux ***
    $totalImportations = $totalImportationsCost;
    $totalArrivals = $totalArrivalsOld; // Arrivages anciens seulement
    $totalPaid = $totalImportationsPaid + $totalPaidGeneral;
    $totalDue = ($totalImportations + $totalArrivals) - $totalPaid;
    if ($totalDue < 0) $totalDue = 0;
    
    // *** Récupérer les détails des arrivages anciens ***
    if ($totalArrivalsOld > 0) {
      $sqlArrivalsDetails = "
        SELECT 
          a.ID as arrivalID,
          a.ArrivalDate,
          a.Quantity,
          a.Cost,
          a.Comments,
          p.ProductName,
          p.Price as UnitPrice
        FROM tblproductarrivals a
        LEFT JOIN tblproducts p ON p.ID = a.ProductID
        WHERE a.SupplierID = '$selectedSupplier'
          AND (a.ImportationID IS NULL OR a.ImportationID = 0)
        ORDER BY a.ArrivalDate DESC, a.ID DESC
      ";
      $resArrivalsDetails = mysqli_query($con, $sqlArrivalsDetails);
      while ($arr = mysqli_fetch_assoc($resArrivalsDetails)) {
        $supplierArrivals[] = $arr;
      }
    }
  }
}

// ==========================
// 4) Liste des paiements - AMÉLIORÉE AVEC IMPORTATIONS
// ==========================
$sqlList = "
  SELECT sp.ID as paymentID,
         sp.PaymentDate,
         sp.Amount,
         sp.Comments,
         sp.PaymentMode,
         sp.ImportationID,
         s.SupplierName,
         i.ImportRef,
         i.BLNumber
  FROM tblsupplierpayments sp
  LEFT JOIN tblsupplier s ON s.ID = sp.SupplierID
  LEFT JOIN tblimportations i ON i.ID = sp.ImportationID
  ORDER BY sp.ID DESC
  LIMIT 100
";
$resList = mysqli_query($con, $sqlList);

// Ajouter la colonne ImportationID à tblsupplierpayments si elle n'existe pas
$checkColumn = mysqli_query($con, "SHOW COLUMNS FROM tblsupplierpayments LIKE 'ImportationID'");
if (mysqli_num_rows($checkColumn) == 0) {
  mysqli_query($con, "ALTER TABLE tblsupplierpayments ADD COLUMN ImportationID int(11) DEFAULT NULL AFTER PaymentMode");
  mysqli_query($con, "ALTER TABLE tblsupplierpayments ADD KEY ImportationID (ImportationID)");
}
?>
<!DOCTYPE html>
<html lang="fr">
<head>
  <title>Paiements Fournisseurs - Gestion Importations</title>
  <?php include_once('includes/cs.php'); ?>
  <?php include_once('includes/responsive.php'); ?>
  <style>
    .summary-box {
      border: 1px solid #ccc;
      padding: 20px;
      margin-bottom: 20px;
      border-radius: 4px;
      background-color: #f9f9f9;
      margin-left: 10px;
    }
    .summary-box h4 {
      margin-top: 0;
      border-bottom: 1px solid #eee;
      padding-bottom: 10px;
    }
    .balance-due {
      font-size: 16px;
      font-weight: bold;
      color: #d9534f;
    }
    .importations-table, .arrivals-table {
      margin-top: 15px;
    }
    .supplier-details {
      margin-bottom: 25px;
    }
    .cash-balance {
      background-color: #dff0d8;
      border: 1px solid #d6e9c6;
      color: #3c763d;
      padding: 15px;
      margin-bottom: 20px;
      border-radius: 4px;
    }
    .cash-balance.insufficient {
      background-color: #f2dede;
      border: 1px solid #ebccd1;
      color: #a94442;
    }
    .cash-balance-amount {
      font-size: 18px;
      font-weight: bold;
    }
    .transaction-history {
      margin-top: 10px;
      font-size: 12px;
    }
    .not-in-cash {
      color: #888;
      font-style: italic;
    }
    .importation-card {
      border: 1px solid #ddd;
      border-radius: 5px;
      padding: 15px;
      margin-bottom: 15px;
      background: #f8f9fa;
    }
    .currency-usd {
      color: #2ecc71;
      font-weight: bold;
    }
    .currency-gnf {
      color: #e74c3c;
      font-weight: bold;
    }
    .import-status-en_cours { color: #007bff; }
    .import-status-termine { color: #28a745; }
    .import-status-annule { color: #dc3545; }
    .remaining-amount {
      font-size: 14px;
      font-weight: bold;
    }
    .remaining-amount.zero { color: #28a745; }
    .remaining-amount.partial { color: #ffc107; }
    .remaining-amount.unpaid { color: #dc3545; }
    .tabs-container {
      margin: 20px 0;
    }
    .nav-tabs {
      list-style: none;
      padding: 0;
      margin: 0;
      border-bottom: 2px solid #ddd;
    }
    .nav-tabs li {
      display: inline-block;
      margin-right: 5px;
    }
    .nav-tabs li a {
      display: block;
      padding: 10px 15px;
      background: #f8f9fa;
      color: #333;
      text-decoration: none;
      border: 1px solid #ddd;
      border-bottom: none;
      border-radius: 5px 5px 0 0;
    }
    .nav-tabs li.active a {
      background: #007bff;
      color: white;
    }
    .tab-content {
      display: none;
      padding: 20px 0;
    }
    .tab-content.active {
      display: block;
    }
  </style>
</head>
<body>
<?php include_once('includes/header.php'); ?>
<?php include_once('includes/sidebar.php'); ?>

<div id="content">
  <div id="content-header">
    <div id="breadcrumb">
      <a href="dashboard.php" class="tip-bottom"><i class="icon-home"></i> Accueil</a>
      <a href="supplier-payments.php" class="current">Paiements Fournisseurs</a>
    </div>
    <h1>💰 Paiements aux Fournisseurs - Gestion Importations</h1>
  </div>
  <div class="container-fluid">
    <hr>
    
    <!-- ========== AFFICHAGE DU SOLDE DE CAISSE ========== -->
    <div class="row-fluid">
      <div class="span12">
        <div class="cash-balance <?php echo ($availableCash <= 0) ? 'insufficient' : ''; ?>">
          <div class="row-fluid">
            <div class="span6">
              <h4><i class="icon-money"></i> Solde disponible en caisse:</h4>
              <span class="cash-balance-amount"><?php echo number_format($availableCash, 2); ?> GNF</span>
              <?php if ($availableCash <= 0): ?>
                <p><strong>⚠️ Attention:</strong> Solde insuffisant pour effectuer des paiements.</p>
              <?php endif; ?>
              
              <?php if ($todayCustomerPaymentsAll > $todayCustomerPayments): ?>
                <p class="not-in-cash">
                  <i class="icon-info-sign"></i>
                  <small>Note: Certains paiements clients (<?php echo number_format($todayCustomerPaymentsAll - $todayCustomerPayments, 2); ?>) 
                  ont été effectués par d'autres moyens que les espèces et ne sont pas inclus dans le solde.</small>
                </p>
              <?php endif; ?>
            </div>
            <div class="span6">
              <div class="transaction-history">
                <p><strong>📊 Détail du jour:</strong></p>
                <ul>
                  <li>Ventes régulières: +<?php echo number_format($todayRegularSales, 2); ?></li>
                  <li>Paiements clients en espèces: +<?php echo number_format($todayCustomerPayments, 2); ?></li>
                  <?php if (count($paymentsByMethod) > 0): ?>
                    <li class="not-in-cash">Détail des paiements par méthode:
                      <ul>
                        <?php foreach ($paymentsByMethod as $method => $data): ?>
                          <li><?php echo $method; ?>: <?php echo number_format($data['total'], 2); ?> (<?php echo $data['count']; ?> transaction(s))</li>
                        <?php endforeach; ?>
                      </ul>
                    </li>
                  <?php endif; ?>
                  <li>Dépôts: +<?php echo number_format($todayDeposits, 2); ?></li>
                  <li>Retraits: -<?php echo number_format($todayWithdrawals, 2); ?></li>
                  <li>Retours: -<?php echo number_format($todayReturns, 2); ?></li>
                </ul>
              </div>
            </div>
          </div>
        </div>
      </div>
    </div>

    <!-- ========== FORMULAIRE pour voir combien on doit et a payé ========== -->
    <div class="row-fluid">
      <div class="span12">
        <form method="get" action="supplier-payments.php" class="form-inline">
          <label><i class="icon-search"></i> Choisir un fournisseur :</label>
          <select name="supplierSearch" required>
            <option value="">-- Tous --</option>
            <?php
            // Charger la liste des fournisseurs
            $suppQ = mysqli_query($con, "SELECT ID, SupplierName FROM tblsupplier ORDER BY SupplierName ASC");
            while ($sRow = mysqli_fetch_assoc($suppQ)) {
              $sid   = $sRow['ID'];
              $sname = $sRow['SupplierName'];
              $sel   = ($sid == $selectedSupplier) ? 'selected' : '';
              echo "<option value='$sid' $sel>$sname</option>";
            }
            ?>
          </select>
          <button type="submit" class="btn btn-info">
            <i class="icon-calculator"></i> Voir le total
          </button>
        </form>
        <hr>

        <?php
        // Afficher le total pour le fournisseur sélectionné
        if ($selectedSupplier > 0) {
          // Récupérer son nom
          $qsupp = mysqli_query($con, "SELECT SupplierName, Phone, Email, Address FROM tblsupplier WHERE ID='$selectedSupplier' LIMIT 1");
          $rSupp = mysqli_fetch_assoc($qsupp);
          $supplierName = $rSupp ? $rSupp['SupplierName'] : '???';
          $supplierPhone = $rSupp['Phone'];
          $supplierEmail = $rSupp['Email'];
          $supplierAddress = $rSupp['Address'];
        ?>
          <div class="supplier-details">
            <div class="summary-box">
              <h4><i class="icon-building"></i> Fournisseur : <strong><?php echo $supplierName; ?></strong></h4>
              <?php if(!empty($supplierPhone) || !empty($supplierEmail) || !empty($supplierAddress)): ?>
              <div class="row-fluid">
                <div class="span4">
                  <?php if(!empty($supplierPhone)): ?>
                  <p><i class="icon-phone"></i> <?php echo $supplierPhone; ?></p>
                  <?php endif; ?>
                </div>
                <div class="span4">
                  <?php if(!empty($supplierEmail)): ?>
                  <p><i class="icon-envelope"></i> <?php echo $supplierEmail; ?></p>
                  <?php endif; ?>
                </div>
                <div class="span4">
                  <?php if(!empty($supplierAddress)): ?>
                  <p><i class="icon-home"></i> <?php echo $supplierAddress; ?></p>
                  <?php endif; ?>
                </div>
              </div>
              <hr>
              <?php endif; ?>
              
              <div class="row-fluid">
                <div class="span3">
                  <p><strong>🚢 Importations:</strong> <br><span class="currency-gnf"><?php echo number_format($totalImportations, 0); ?> GNF</span></p>
                </div>
                <div class="span3">
                  <p><strong>📦 Arrivages anciens:</strong> <br><span class="currency-gnf"><?php echo number_format($totalArrivals, 0); ?> GNF</span></p>
                </div>
                <div class="span3">
                  <p><strong>💰 Total payé:</strong> <br><span class="currency-gnf"><?php echo number_format($totalPaid, 0); ?> GNF</span></p>
                </div>
                <div class="span3">
                  <p class="balance-due"><strong>⚠️ Solde dû:</strong> <br><span class="currency-gnf"><?php echo number_format($totalDue, 0); ?> GNF</span></p>
                  
                  <?php if ($totalDue > 0): ?>
                    <?php if ($availableCash <= 0): ?>
                      <div class="alert alert-error">
                        <strong>❌ Impossible de payer !</strong> Solde en caisse insuffisant.
                      </div>
                    <?php elseif ($availableCash < $totalDue): ?>
                      <div class="alert alert-warning">
                        <strong>⚠️ Attention !</strong> Solde en caisse (<?php echo number_format($availableCash, 2); ?>) inférieur au montant dû.
                      </div>
                    <?php else: ?>
                      <div class="alert alert-success">
                        <strong>✅ OK !</strong> Solde en caisse suffisant pour payer ce fournisseur.
                      </div>
                    <?php endif; ?>
                  <?php endif; ?>
                </div>
              </div>
            </div>
            
            <!-- ONGLETS POUR DÉTAILS -->
            <div class="tabs-container">
              <ul class="nav-tabs">
                <li class="active">
                  <a href="#tab-importations" onclick="showTab('importations')">
                    🚢 Importations (<?php echo count($supplierImportations); ?>)
                  </a>
                </li>
                <?php if (count($supplierArrivals) > 0): ?>
                <li>
                  <a href="#tab-arrivals" onclick="showTab('arrivals')">
                    📦 Arrivages Anciens (<?php echo count($supplierArrivals); ?>)
                  </a>
                </li>
                <?php endif; ?>
              </ul>

              <!-- TAB 1: IMPORTATIONS -->
              <div id="tab-importations" class="tab-content active">
                <?php if (count($supplierImportations) > 0): ?>
                  <?php foreach ($supplierImportations as $imp): ?>
                  <div class="importation-card">
                    <div class="row-fluid">
                      <div class="span8">
                        <h5>
                          <i class="icon-folder-open"></i> 
                          <strong><?php echo $imp['ImportRef']; ?></strong>
                          <span class="label import-status-<?php echo $imp['Status']; ?>">
                            <?php echo strtoupper($imp['Status']); ?>
                          </span>
                        </h5>
                        <p><strong>BL:</strong> <?php echo $imp['BLNumber']; ?> | 
                           <strong>Date:</strong> <?php echo date('d/m/Y', strtotime($imp['ImportDate'])); ?></p>
                        <p><small><?php echo $imp['Description']; ?></small></p>
                      </div>
                      <div class="span4" style="text-align: right;">
                        <p><strong>Valeur:</strong> <span class="currency-usd">$<?php echo number_format($imp['TotalValueUSD'], 2); ?></span></p>
                        <p><strong>+ Frais:</strong> <span class="currency-gnf"><?php echo number_format($imp['TotalFees'], 0); ?> GNF</span></p>
                        <p><strong>Total:</strong> <span class="currency-gnf"><?php echo number_format($imp['TotalCostGNF'], 0); ?> GNF</span></p>
                        <p><strong>Payé:</strong> <span class="currency-gnf"><?php echo number_format($imp['PaidAmount'], 0); ?> GNF</span></p>
                        
                        <?php 
                        $remaining = floatval($imp['RemainingAmount']);
                        $remainingClass = 'unpaid';
                        if ($remaining == 0) $remainingClass = 'zero';
                        elseif ($remaining < floatval($imp['TotalCostGNF'])) $remainingClass = 'partial';
                        ?>
                        <p class="remaining-amount <?php echo $remainingClass; ?>">
                          <strong>Reste:</strong> <?php echo number_format($remaining, 0); ?> GNF
                        </p>
                        
                        <?php if ($remaining > 0): ?>
                        <button type="button" class="btn btn-mini btn-primary" 
                                onclick="selectImportationForPayment(<?php echo $imp['ImportID']; ?>, '<?php echo $imp['ImportRef']; ?>', <?php echo $remaining; ?>)">
                          <i class="icon-credit-card"></i> Payer
                        </button>
                        <?php endif; ?>
                      </div>
                    </div>
                  </div>
                  <?php endforeach; ?>
                <?php else: ?>
                  <div class="alert alert-info">
                    <strong>ℹ️ Info!</strong> Aucune importation trouvée pour ce fournisseur.
                  </div>
                <?php endif; ?>
              </div>

              <!-- TAB 2: ARRIVAGES ANCIENS -->
              <div id="tab-arrivals" class="tab-content">
                <?php if (count($supplierArrivals) > 0): ?>
                <div class="widget-box">
                  <div class="widget-title">
                    <span class="icon"><i class="icon-truck"></i></span>
                    <h5>📦 Détails des Arrivages Anciens (Sans Importation)</h5>
                  </div>
                  <div class="widget-content nopadding">
                    <table class="table table-bordered table-striped arrivals-table">
                      <thead>
                        <tr>
                          <th>ID</th>
                          <th>Date d'Arrivage</th>
                          <th>Produit</th>
                          <th>Quantité</th>
                          <th>Prix Unitaire</th>
                          <th>Coût Total</th>
                          <th>Commentaires</th>
                        </tr>
                      </thead>
                      <tbody>
                        <?php foreach ($supplierArrivals as $arrival): ?>
                        <tr>
                          <td><?php echo $arrival['arrivalID']; ?></td>
                          <td><?php echo $arrival['ArrivalDate']; ?></td>
                          <td><?php echo $arrival['ProductName']; ?></td>
                          <td><?php echo $arrival['Quantity']; ?></td>
                          <td><?php echo number_format($arrival['UnitPrice'], 2); ?></td>
                          <td class="currency-gnf"><?php echo number_format($arrival['Cost'], 2); ?></td>
                          <td><?php echo $arrival['Comments']; ?></td>
                        </tr>
                        <?php endforeach; ?>
                      </tbody>
                    </table>
                  </div>
                </div>
                <?php else: ?>
                  <div class="alert alert-info">
                    <strong>ℹ️ Info!</strong> Aucun arrivage ancien trouvé pour ce fournisseur.
                  </div>
                <?php endif; ?>
              </div>
            </div>
          </div>
        <?php
        }
        ?>
      </div>
    </div><!-- row-fluid -->

    <hr>

    <!-- ========== FORMULAIRE d'ajout de paiement - AMÉLIORÉ ========== -->
    <div class="row-fluid">
      <div class="span12">
        <div class="widget-box">
          <div class="widget-title">
            <span class="icon"><i class="icon-money"></i></span>
            <h5>💳 Enregistrer un paiement</h5>
          </div>
          <div class="widget-content nopadding">
            <form method="post" class="form-horizontal" id="paymentForm">
              <div class="control-group">
                <label class="control-label">Fournisseur :</label>
                <div class="controls">
                  <select name="supplierid" id="supplierid" required onchange="loadSupplierImportations()">
                    <option value="">-- Choisir --</option>
                    <?php
                    // Recharger la liste
                    $suppQ2 = mysqli_query($con, "SELECT ID, SupplierName FROM tblsupplier ORDER BY SupplierName ASC");
                    while ($rowS2 = mysqli_fetch_assoc($suppQ2)) {
                      $selected = ($rowS2['ID'] == $selectedSupplier) ? 'selected' : '';
                      echo '<option value="'.$rowS2['ID'].'" '.$selected.'>'.$rowS2['SupplierName'].'</option>';
                    }
                    ?>
                  </select>
                </div>
              </div>
              
              <div class="control-group" id="importation-group" style="display: none;">
                <label class="control-label">Importation (optionnel) :</label>
                <div class="controls">
                  <select name="importation_id" id="importationSelect">
                    <option value="">-- Paiement général --</option>
                  </select>
                  <span class="help-inline">Laisser vide pour un paiement général</span>
                </div>
              </div>
              
              <div class="control-group">
                <label class="control-label">Date de paiement :</label>
                <div class="controls">
                  <input type="date" name="paydate" value="<?php echo date('Y-m-d'); ?>" required />
                </div>
              </div>
              <div class="control-group">
                <label class="control-label">Montant :</label>
                <div class="controls">
                  <input type="number" name="amount" id="amount" step="any" min="0.01" required />
                  <div id="amount-suggestions" style="margin-top: 5px;">
                    <?php if ($selectedSupplier > 0 && $totalDue > 0): ?>
                      <a href="#" onclick="setAmount(<?php echo $totalDue; ?>); return false;" class="btn btn-mini btn-info">
                        Montant total dû (<?php echo number_format($totalDue, 2); ?>)
                      </a>
                      <?php if ($availableCash > 0 && $availableCash < $totalDue): ?>
                        <a href="#" onclick="setAmount(<?php echo $availableCash; ?>); return false;" class="btn btn-mini btn-warning">
                          Solde disponible (<?php echo number_format($availableCash, 2); ?>)
                        </a>
                      <?php endif; ?>
                    <?php endif; ?>
                  </div>
                </div>
              </div>
              <div class="control-group">
                <label class="control-label">Mode de paiement :</label>
                <div class="controls">
                  <label class="radio inline">
                    <input type="radio" name="paymentmode" value="espece" checked /> 💵 Espèce
                  </label>
                  <label class="radio inline">
                    <input type="radio" name="paymentmode" value="carte" /> 💳 Carte
                  </label>
                  <label class="radio inline">
                    <input type="radio" name="paymentmode" value="virement" /> 🏦 Virement
                  </label>
                </div>
              </div>
              <div class="control-group">
                <label class="control-label">Commentaires :</label>
                <div class="controls">
                  <input type="text" name="comments" placeholder="Référence, note..." />
                </div>
              </div>
              <div class="form-actions">
                <button type="submit" name="submit" class="btn btn-success" <?php echo ($availableCash <= 0) ? 'disabled' : ''; ?>>
                  <i class="icon-check"></i> 💳 Enregistrer et déduire de la caisse
                </button>
                <?php if ($availableCash <= 0): ?>
                  <span class="help-inline text-error">Impossible d'effectuer des paiements: solde en caisse insuffisant</span>
                <?php endif; ?>
              </div>
            </form>
          </div><!-- widget-content nopadding -->
        </div><!-- widget-box -->
      </div>
    </div><!-- row-fluid -->

    <hr>

    <!-- ========== LISTE DES PAIEMENTS - AMÉLIORÉE ========== -->
    <div class="row-fluid">
      <div class="span12">
        <div class="widget-box">
          <div class="widget-title">
            <span class="icon"><i class="icon-th"></i></span>
            <h5>📋 Liste des Paiements Récents</h5>
          </div>
          <div class="widget-content nopadding">
            <table class="table table-bordered data-table">
              <thead>
                <tr>
                  <th>#</th>
                  <th>Date</th>
                  <th>Fournisseur</th>
                  <th>Importation</th>
                  <th>Montant</th>
                  <th>Mode</th>
                  <th>Commentaires</th>
                </tr>
              </thead>
              <tbody>
                <?php
                $cnt=1;
                while ($row = mysqli_fetch_assoc($resList)) {
                  ?>
                  <tr>
                    <td><?php echo $cnt; ?></td>
                    <td><?php echo date('d/m/Y', strtotime($row['PaymentDate'])); ?></td>
                    <td><?php echo $row['SupplierName']; ?></td>
                    <td>
                      <?php if ($row['ImportationID']): ?>
                        <span class="label label-info">
                          <i class="icon-folder-open"></i> <?php echo $row['ImportRef']; ?>
                        </span><br>
                        <small><?php echo $row['BLNumber']; ?></small>
                      <?php else: ?>
                        <span class="text-muted">Paiement général</span>
                      <?php endif; ?>
                    </td>
                    <td class="currency-gnf"><?php echo number_format($row['Amount'],2); ?></td>
                    <td><?php 
                      if($row['PaymentMode'] == 'espece') {
                        echo '<span class="label label-success">💵 Espèce</span>';
                      } else if($row['PaymentMode'] == 'carte') {
                        echo '<span class="label label-info">💳 Carte</span>';
                      } else if($row['PaymentMode'] == 'virement') {
                        echo '<span class="label label-warning">🏦 Virement</span>';
                      } else {
                        echo '<span class="label">Non spécifié</span>';
                      }
                    ?></td>
                    <td><?php echo $row['Comments']; ?></td>
                  </tr>
                  <?php
                  $cnt++;
                }
                ?>
              </tbody>
            </table>
          </div><!-- widget-content nopadding -->
        </div><!-- widget-box -->
      </div>
    </div><!-- row-fluid -->

  </div><!-- container-fluid -->
</div><!-- content -->

<?php include_once('includes/footer.php'); ?>
<script src="js/jquery.min.js"></script>
<script src="js/bootstrap.min.js"></script>
<script src="js/jquery.dataTables.min.js"></script>
<script src="js/matrix.tables.js"></script>
<script>
// Fonction pour définir le montant
function setAmount(amount) {
  document.getElementById('amount').value = amount;
}

// Fonction pour sélectionner une importation pour paiement
function selectImportationForPayment(importId, importRef, remainingAmount) {
  // Sélectionner l'importation
  document.getElementById('importationSelect').value = importId;
  
  // Définir le montant restant
  setAmount(remainingAmount);
  
  // Faire défiler vers le formulaire
  document.getElementById('paymentForm').scrollIntoView({behavior: 'smooth'});
  
  // Mettre en évidence le formulaire
  var form = document.getElementById('paymentForm').parentElement;
  form.style.border = '2px solid #007bff';
  setTimeout(function() {
    form.style.border = '';
  }, 3000);
}

// Charger les importations d'un fournisseur
function loadSupplierImportations() {
  var supplierId = document.getElementById('supplierid').value;
  var importationGroup = document.getElementById('importation-group');
  var importationSelect = document.getElementById('importationSelect');
  
  if (supplierId) {
    // Afficher le groupe
    importationGroup.style.display = 'block';
    
    // Charger les importations via AJAX (simulation)
    // En réalité, vous devriez faire un appel AJAX ici
    // Pour l'instant, on va juste réinitialiser
    importationSelect.innerHTML = '<option value="">-- Paiement général --</option>';
    
    // Simulation de chargement des importations du fournisseur sélectionné
    <?php if ($selectedSupplier > 0): ?>
    if (supplierId == <?php echo $selectedSupplier; ?>) {
      <?php foreach ($supplierImportations as $imp): ?>
      <?php if (floatval($imp['RemainingAmount']) > 0): ?>
      var option = document.createElement('option');
      option.value = <?php echo $imp['ImportID']; ?>;
      option.text = '<?php echo $imp['ImportRef']; ?> (Reste: <?php echo number_format($imp['RemainingAmount'], 0); ?>)';
      importationSelect.appendChild(option);
      <?php endif; ?>
      <?php endforeach; ?>
    }
    <?php endif; ?>
  } else {
    importationGroup.style.display = 'none';
  }
}

// Gestion des onglets
function showTab(tabName) {
  // Masquer tous les onglets
  document.querySelectorAll('.tab-content').forEach(function(tab) {
    tab.classList.remove('active');
  });
  
  // Désactiver tous les liens
  document.querySelectorAll('.nav-tabs li').forEach(function(li) {
    li.classList.remove('active');
  });
  
  // Activer l'onglet sélectionné
  document.getElementById('tab-' + tabName).classList.add('active');
  
  // Activer le lien correspondant
  document.querySelector('a[href="#tab-' + tabName + '"]').parentNode.classList.add('active');
}

// Validation du formulaire
$(document).ready(function() {
  $('#paymentForm').on('submit', function(e) {
    var amount = parseFloat($('#amount').val()) || 0;
    var availableCash = <?php echo $availableCash; ?>;
    
    if (amount <= 0) {
      alert('Veuillez saisir un montant valide (supérieur à 0)');
      e.preventDefault();
      return false;
    }
    
    if (amount > availableCash) {
      alert('Le montant saisi (' + amount.toFixed(2) + ') est supérieur au solde disponible en caisse (' + availableCash.toFixed(2) + ')');
      e.preventDefault();
      return false;
    }
    
    return true;
  });
  
  // Auto-sélectionner le fournisseur si passé en paramètre
  <?php if ($selectedSupplier > 0): ?>
  $('#supplierid').val(<?php echo $selectedSupplier; ?>);
  loadSupplierImportations();
  <?php endif; ?>
  
  // Initialiser DataTables
  $('.data-table').dataTable({
    "aaSorting": [[ 0, "desc" ]],
    "iDisplayLength": 25,
    "aLengthMenu": [[10, 25, 50, -1], [10, 25, 50, "Tout"]]
  });
});
</script>
</body>
</html>