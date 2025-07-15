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
      // 2) Enregistrer le paiement fournisseur
      $sqlPayment = "
        INSERT INTO tblsupplierpayments(SupplierID, PaymentDate, Amount, Comments, PaymentMode)
        VALUES('$supplierID', '$payDate', '$amount', '$comments', '$paymentMode')
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
// 3) Filtre pour afficher le total pour un fournisseur
// ==========================
$selectedSupplier = 0;
$totalArrivals = 0;
$totalPaid     = 0;
$totalDue      = 0;

if (isset($_GET['supplierSearch'])) {
  $selectedSupplier = intval($_GET['supplierSearch']);

  if ($selectedSupplier > 0) {
    // Calculer la somme des arrivages
    $sqlArr = "
      SELECT IFNULL(SUM(Cost),0) as sumArrivals
      FROM tblproductarrivals
      WHERE SupplierID='$selectedSupplier'
    ";
    $resArr = mysqli_query($con, $sqlArr);
    $rowArr = mysqli_fetch_assoc($resArr);
    $totalArrivals = floatval($rowArr['sumArrivals']);

    // Calculer la somme des paiements
    $sqlPay = "
      SELECT IFNULL(SUM(Amount),0) as sumPaid
      FROM tblsupplierpayments
      WHERE SupplierID='$selectedSupplier'
    ";
    $resPay = mysqli_query($con, $sqlPay);
    $rowPay = mysqli_fetch_assoc($resPay);
    $totalPaid = floatval($rowPay['sumPaid']);

    // Solde
    $totalDue = $totalArrivals - $totalPaid;
    if ($totalDue < 0) $totalDue = 0;
    
    // Récupérer les détails des arrivages pour ce fournisseur
    $sqlArrivals = "
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
      ORDER BY a.ArrivalDate DESC, a.ID DESC
    ";
    $resArrivals = mysqli_query($con, $sqlArrivals);
  }
}

// ==========================
// 4) Liste des paiements
// ==========================
$sqlList = "
  SELECT sp.ID as paymentID,
         sp.PaymentDate,
         sp.Amount,
         sp.Comments,
         sp.PaymentMode,
         s.SupplierName
  FROM tblsupplierpayments sp
  LEFT JOIN tblsupplier s ON s.ID = sp.SupplierID
  ORDER BY sp.ID DESC
  LIMIT 100
";
$resList = mysqli_query($con, $sqlList);
?>
<!DOCTYPE html>
<html lang="fr">
<head>
  <title>Paiements Fournisseurs</title>
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
    .arrivals-table {
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
  </style>
</head>
<body>
<?php include_once('includes/header.php'); ?>
<?php include_once('includes/sidebar.php'); ?>

<div id="content">
  <div id="content-header">
    <h1>Paiements aux Fournisseurs</h1>
  </div>
  <div class="container-fluid">
    <hr>
    
    <!-- ========== AFFICHAGE DU SOLDE DE CAISSE ========== -->
    <div class="row-fluid">
      <div class="span12">
        <div class="cash-balance <?php echo ($availableCash <= 0) ? 'insufficient' : ''; ?>">
          <div class="row-fluid">
            <div class="span6">
              <h4>Solde disponible en caisse:</h4>
              <span class="cash-balance-amount"><?php echo number_format($availableCash, 2); ?></span>
              <?php if ($availableCash <= 0): ?>
                <p><strong>Attention:</strong> Solde insuffisant pour effectuer des paiements.</p>
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
                <p><strong>Détail du jour:</strong></p>
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
          <label>Choisir un fournisseur :</label>
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
          <button type="submit" class="btn btn-info">Voir le total</button>
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
              <h4>Fournisseur : <strong><?php echo $supplierName; ?></strong></h4>
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
                <div class="span4">
                  <p>Total des arrivages : <strong><?php echo number_format($totalArrivals, 2); ?></strong></p>
                </div>
                <div class="span4">
                  <p>Total payé : <strong><?php echo number_format($totalPaid, 2); ?></strong></p>
                </div>
                <div class="span4">
                  <p class="balance-due">Solde dû : <strong><?php echo number_format($totalDue, 2); ?></strong></p>
                  
                  <?php if ($totalDue > 0): ?>
                    <?php if ($availableCash <= 0): ?>
                      <div class="alert alert-error">
                        <strong>Impossible de payer !</strong> Solde en caisse insuffisant.
                      </div>
                    <?php elseif ($availableCash < $totalDue): ?>
                      <div class="alert alert-warning">
                        <strong>Attention !</strong> Solde en caisse (<?php echo number_format($availableCash, 2); ?>) inférieur au montant dû.
                      </div>
                    <?php else: ?>
                      <div class="alert alert-success">
                        <strong>OK !</strong> Solde en caisse suffisant pour payer ce fournisseur.
                      </div>
                    <?php endif; ?>
                  <?php endif; ?>
                </div>
              </div>
            </div>
            
            <!-- Affichage des détails d'arrivages pour ce fournisseur -->
            <?php if (isset($resArrivals) && mysqli_num_rows($resArrivals) > 0): ?>
            <div class="widget-box">
              <div class="widget-title">
                <span class="icon"><i class="icon-truck"></i></span>
                <h5>Détails des Arrivages</h5>
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
                    <?php
                    while ($arrival = mysqli_fetch_assoc($resArrivals)) {
                      ?>
                      <tr>
                        <td><?php echo $arrival['arrivalID']; ?></td>
                        <td><?php echo $arrival['ArrivalDate']; ?></td>
                        <td><?php echo $arrival['ProductName']; ?></td>
                        <td><?php echo $arrival['Quantity']; ?></td>
                        <td><?php echo number_format($arrival['UnitPrice'], 2); ?></td>
                        <td><?php echo number_format($arrival['Cost'], 2); ?></td>
                        <td><?php echo $arrival['Comments']; ?></td>
                      </tr>
                    <?php
                    }
                    ?>
                  </tbody>
                </table>
              </div>
            </div>
            <?php else: ?>
              <?php if ($selectedSupplier > 0): ?>
                <div class="alert alert-info">
                  <button class="close" data-dismiss="alert">×</button>
                  <strong>Info!</strong> Aucun arrivage trouvé pour ce fournisseur.
                </div>
              <?php endif; ?>
            <?php endif; ?>
          </div>
        <?php
        }
        ?>
      </div>
    </div><!-- row-fluid -->

    <hr>

    <!-- ========== FORMULAIRE d'ajout de paiement ========== -->
    <div class="row-fluid">
      <div class="span12">
        <div class="widget-box">
          <div class="widget-title">
            <span class="icon"><i class="icon-money"></i></span>
            <h5>Enregistrer un paiement</h5>
          </div>
          <div class="widget-content nopadding">
            <form method="post" class="form-horizontal">
              <div class="control-group">
                <label class="control-label">Fournisseur :</label>
                <div class="controls">
                  <select name="supplierid" id="supplierid" required>
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
                  <?php if ($selectedSupplier > 0 && $totalDue > 0): ?>
                    <span class="help-inline">
                      <a href="#" onclick="setAmount(<?php echo $totalDue; ?>); return false;" class="btn btn-mini btn-info">Montant dû (<?php echo number_format($totalDue, 2); ?>)</a>
                      <?php if ($availableCash > 0 && $availableCash < $totalDue): ?>
                        <a href="#" onclick="setAmount(<?php echo $availableCash; ?>); return false;" class="btn btn-mini btn-warning">Solde disponible (<?php echo number_format($availableCash, 2); ?>)</a>
                      <?php endif; ?>
                    </span>
                  <?php endif; ?>
                </div>
              </div>
              <div class="control-group">
                <label class="control-label">Mode de paiement :</label>
                <div class="controls">
                  <label class="radio inline">
                    <input type="radio" name="paymentmode" value="espece" checked /> Espèce
                  </label>
                  <label class="radio inline">
                    <input type="radio" name="paymentmode" value="carte" /> Carte
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
                  <i class="icon-check"></i> Enregistrer et déduire de la caisse
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

    <!-- ========== LISTE DES PAIEMENTS ========== -->
    <div class="row-fluid">
      <div class="span12">
        <div class="widget-box">
          <div class="widget-title">
            <span class="icon"><i class="icon-th"></i></span>
            <h5>Liste des Paiements</h5>
          </div>
          <div class="widget-content nopadding">
            <table class="table table-bordered">
              <thead>
                <tr>
                  <th>#</th>
                  <th>Date</th>
                  <th>Fournisseur</th>
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
                    <td><?php echo $row['PaymentDate']; ?></td>
                    <td><?php echo $row['SupplierName']; ?></td>
                    <td><?php echo number_format($row['Amount'],2); ?></td>
                    <td><?php 
                      if($row['PaymentMode'] == 'espece') {
                        echo '<span class="label label-success">Espèce</span>';
                      } else if($row['PaymentMode'] == 'carte') {
                        echo '<span class="label label-info">Carte</span>';
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

// Validation du formulaire
$(document).ready(function() {
  $('form').on('submit', function(e) {
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
  <?php endif; ?>
});
</script>
</body>
</html>