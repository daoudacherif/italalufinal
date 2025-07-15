<?php
session_start();
error_reporting(0);
include('includes/dbconnection.php');

// Check admin login
if (strlen($_SESSION['imsaid'] == 0)) {
  header('location:logout.php');
  exit;
}

// ---------------------------------------------------------------------
// 1) CALCULER LE SOLDE DU JOUR UNIQUEMENT (Période de 24h)
// ---------------------------------------------------------------------

// 1.1 CORRECTION : Ventes régulières du jour - Utilise FinalAmount (AVEC remises)
$sqlRegularSales = "
  SELECT COALESCE(SUM(cust.FinalAmount), 0) AS totalSales
  FROM tblcustomer cust
  WHERE cust.ModeofPayment != 'credit'
    AND EXISTS (
      SELECT 1 FROM tblcart c 
      WHERE c.BillingId = cust.BillingNumber 
        AND DATE(c.CartDate) = CURDATE()
        AND c.IsCheckOut = '1'
    )
";
$resRegularSales = mysqli_query($con, $sqlRegularSales);
$rowRegularSales = mysqli_fetch_assoc($resRegularSales);
$todayRegularSales = floatval($rowRegularSales['totalSales']);

// 1.2 CORRECTION : Ventes à crédit du jour - Utilise FinalAmount (AVEC remises)
$sqlCreditSales = "
  SELECT COALESCE(SUM(cust.FinalAmount), 0) AS totalSales
  FROM tblcustomer cust
  WHERE cust.ModeofPayment = 'credit'
    AND EXISTS (
      SELECT 1 FROM tblcart c 
      WHERE c.BillingId = cust.BillingNumber 
        AND DATE(c.CartDate) = CURDATE()
        AND c.IsCheckOut = '1'
    )
";
$resCreditSales = mysqli_query($con, $sqlCreditSales);
$rowCreditSales = mysqli_fetch_assoc($resCreditSales);
$todayCreditSales = floatval($rowCreditSales['totalSales']);

// 1.3 Paiements clients du jour (from tblpayments) - FIXED - POUR AFFICHAGE UNIQUEMENT
$sqlCustomerPayments = "
  SELECT COALESCE(SUM(PaymentAmount), 0) AS totalPaid
  FROM tblpayments
  WHERE DATE(PaymentDate) = CURDATE()
";
$resCustomerPayments = mysqli_query($con, $sqlCustomerPayments);
$rowCustomerPayments = mysqli_fetch_assoc($resCustomerPayments);
$todayCustomerPayments = floatval($rowCustomerPayments['totalPaid']);

// 1.3.1 Récupérer les paiements par méthode pour analyse (NOUVEAU)
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
$cashPayments = 0;
while ($row = mysqli_fetch_assoc($resPaymentMethods)) {
  $paymentsByMethod[$row['PaymentMethod']] = $row;
  if ($row['PaymentMethod'] == 'Cash') {
    $cashPayments = floatval($row['total']);
  }
}

// 1.4 Dépôts et retraits manuels du jour (from tblcashtransactions)
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

// 1.5 Retours du jour (from tblreturns)
$sqlReturns = "
  SELECT COALESCE(SUM(r.Quantity * p.Price), 0) AS totalReturns
  FROM tblreturns r
  JOIN tblproducts p ON p.ID = r.ProductID
  WHERE DATE(r.ReturnDate) = CURDATE()
";
$resReturns = mysqli_query($con, $sqlReturns);
$rowReturns = mysqli_fetch_assoc($resReturns);
$todayReturns = floatval($rowReturns['totalReturns']);

// 1.6 Calcul du solde du jour incluant les ventes et paiements client
// FIXED: Utiliser seulement les paiements en espèces (Cash) pour le solde
$todayBalance = $todayDeposits + $todayRegularSales + $cashPayments - ($todayWithdrawals + $todayReturns);

// 1.7 Calcul du solde total théorique si toutes les ventes et paiements étaient inclus
$todayTotalTheoretical = $todayDeposits + $todayRegularSales + $todayCustomerPayments - ($todayWithdrawals + $todayReturns);

// 1.8 NOUVEAU : Calcul de la différence due aux remises (pour information)
$sqlRegularSalesBeforeDiscount = "
  SELECT COALESCE(SUM(c.ProductQty * p.Price), 0) AS totalSalesBeforeDiscount
  FROM tblcart c
  JOIN tblproducts p ON p.ID = c.ProductId
  WHERE c.IsCheckOut = '1'
    AND DATE(c.CartDate) = CURDATE()
";
$resRegularSalesBeforeDiscount = mysqli_query($con, $sqlRegularSalesBeforeDiscount);
$rowRegularSalesBeforeDiscount = mysqli_fetch_assoc($resRegularSalesBeforeDiscount);
$todayRegularSalesBeforeDiscount = floatval($rowRegularSalesBeforeDiscount['totalSalesBeforeDiscount']);

// Calcul de la remise totale accordée aujourd'hui
$todayDiscountGiven = $todayRegularSalesBeforeDiscount - $todayRegularSales;

// ---------------------------------------------------------------------
// 2) GÉRER UNE NOUVELLE TRANSACTION MANUELLE
// ---------------------------------------------------------------------

$transactionError = '';

if (isset($_POST['submit'])) {
  $transtype = $_POST['transtype']; // 'IN' ou 'OUT'
  $amount = floatval($_POST['amount']);
  $comments = mysqli_real_escape_string($con, $_POST['comments']);

  if ($amount <= 0) {
    $transactionError = 'Montant invalide. Doit être > 0';
  } 
  // Bloquer le retrait si le solde du jour est insuffisant
  else if ($transtype == 'OUT' && $amount > $todayBalance) {
    $transactionError = 'Impossible d\'effectuer un retrait : montant supérieur au solde du jour';
  }
  else {
    // Calculer le solde actuel dans la table des transactions
    $sqlCurrentBal = "
      SELECT COALESCE(SUM(CASE WHEN TransType='IN' THEN Amount ELSE 0 END), 0) -
             COALESCE(SUM(CASE WHEN TransType='OUT' THEN Amount ELSE 0 END), 0) AS currentBalance
      FROM tblcashtransactions
      WHERE DATE(TransDate) = CURDATE()
    ";
    $resCurrentBal = mysqli_query($con, $sqlCurrentBal);
    $rowCurrentBal = mysqli_fetch_assoc($resCurrentBal);
    $currentBal = floatval($rowCurrentBal['currentBalance']);

    // Calculer le nouveau solde
    $newBal = ($transtype == 'IN') ? $currentBal + $amount : $currentBal - $amount;
    
    // Insérer la transaction
    $sqlInsert = "
      INSERT INTO tblcashtransactions(TransDate, TransType, Amount, BalanceAfter, Comments)
      VALUES (NOW(), '$transtype', '$amount', '$newBal', '$comments')
    ";
    
    if (mysqli_query($con, $sqlInsert)) {
      echo "<script>alert('Transaction enregistrée!');</script>";
      echo "<script>window.location.href='transact.php'</script>";
      exit;
    } else {
      $transactionError = 'Erreur lors de l\'insertion de la transaction';
    }
  }
  
  if ($transactionError) {
    echo "<script>alert('$transactionError');</script>";
  }
}

// Désactiver les retraits si le solde est insuffisant
$outDisabled = ($todayBalance <= 0);

// Montant maximum retirable
$maxWithdrawal = ($todayBalance > 0) ? $todayBalance : 0;

?>
<!DOCTYPE html>
<html lang="fr">
<head>
  <title>Gestion d'inventaire | Transactions en espèces</title>
  <?php include_once('includes/cs.php'); ?>
  <?php include_once('includes/responsive.php'); ?>
  <style>
    .balance-box {
      border: 1px solid #ccc;
      padding: 15px;
      margin-bottom: 20px;
      background-color: #f9f9f9;
    }
    .text-success { color: #468847; }
    .text-error { color: #b94a48; }
    .text-warning { color: #c09853; }
    .text-info { color: #3a87ad; }
    .highlight-daily {
      background-color: #fffacd; 
      font-weight: bold;
    }
    .not-in-cash {
      background-color: #f2f2f2;
      font-style: italic;
    }
    
    .transaction-type {
      display: inline-block;
      padding: 2px 6px;
      font-size: 12px;
      font-weight: bold;
      border-radius: 3px;
      text-align: center;
    }
    .type-in { 
      background-color: #dff0d8; 
      color: #468847;
    }
    .type-out { 
      background-color: #f2dede; 
      color: #b94a48;
    }
    .alert-info {
      background-color: #d9edf7;
      border-color: #bce8f1;
      color: #3a87ad;
      padding: 8px;
      margin-bottom: 15px;
      border-radius: 4px;
    }
    .correction-notice {
      background-color: #d4edda;
      border: 1px solid #c3e6cb;
      border-radius: 4px;
      padding: 10px;
      margin-bottom: 15px;
      color: #155724;
    }
    .discount-info {
      background-color: #fff3cd;
      border: 1px solid #ffeaa7;
      border-radius: 4px;
      padding: 8px;
      margin-top: 10px;
      color: #856404;
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
      <a href="transact.php" class="current">Transactions en espèces</a>
    </div>
    <h1>Transactions en espèces (PÉRIODE: AUJOURD'HUI UNIQUEMENT)</h1>
  </div>

  <div class="container-fluid">
    <hr>
    
   
    
    <div class="alert-info">
      <strong>Information importante:</strong> Les ventes régulières (<?php echo number_format($todayRegularSales, 2); ?>) et paiements clients en espèces (<?php echo number_format($cashPayments, 2); ?>) sont automatiquement inclus dans le calcul du solde de caisse. 
      <br>Si vous devez faire un retrait, le système vérifie que vous ne dépassez pas le solde disponible total.
      <?php if ($todayCustomerPayments > $cashPayments): ?>
      <br><strong>Note:</strong> Certains paiements clients (<?php echo number_format($todayCustomerPayments - $cashPayments, 2); ?>) ont été effectués par d'autres moyens que les espèces et ne sont pas inclus dans le solde de caisse.
      <?php endif; ?>
    </div>

    <!-- Résumé du solde -->
    <div class="row-fluid">
      <div class="span12">
        <div class="balance-box">
          <div class="row-fluid">
            <div class="span7">
              <h3>Solde en caisse (aujourd'hui): <span class="<?php echo ($todayBalance > 0) ? 'text-success' : 'text-error'; ?> highlight-daily">
                <?php echo number_format($todayBalance, 2); ?>
              </span></h3>
              
              <h4>Détail du jour:</h4>
              <table class="table table-bordered table-striped" style="width: auto;">
                <tr>
                  <td>Ventes régulières (avec remises, incluses dans le solde):</td>
                  <td style="text-align: right;">
                    <strong>+<?php echo number_format($todayRegularSales, 2); ?></strong>
                  </td>
                </tr>
                <?php if ($todayDiscountGiven > 0): ?>
                <tr>
                  <td colspan="2" class="discount-info">
                    <small>
                      <strong>Info remises:</strong> 
                      Avant remises: <?php echo number_format($todayRegularSalesBeforeDiscount, 2); ?> | 
                      Remises accordées: -<?php echo number_format($todayDiscountGiven, 2); ?> | 
                      Après remises: <?php echo number_format($todayRegularSales, 2); ?>
                    </small>
                  </td>
                </tr>
                <?php endif; ?>
                <tr>
                  <td>Paiements clients en espèces (inclus dans le solde):</td>
                  <td style="text-align: right;">
                    <strong>+<?php echo number_format($cashPayments, 2); ?></strong>
                  </td>
                </tr>
                <?php if ($todayCustomerPayments > $cashPayments): ?>
                <tr class="not-in-cash">
                  <td>Autres paiements clients (non inclus dans le solde):</td>
                  <td style="text-align: right;">
                    (+<?php echo number_format($todayCustomerPayments - $cashPayments, 2); ?>)
                  </td>
                </tr>
                <?php endif; ?>
                <tr>
                  <td>Dépôts enregistrés:</td>
                  <td style="text-align: right;">+<?php echo number_format($todayDeposits, 2); ?></td>
                </tr>
                <tr>
                  <td>Retraits:</td>
                  <td style="text-align: right;">-<?php echo number_format($todayWithdrawals, 2); ?></td>
                </tr>
                <tr>
                  <td>Retours:</td>
                  <td style="text-align: right;">-<?php echo number_format($todayReturns, 2); ?></td>
                </tr>
                <tr>
                  <th>Solde en caisse:</th>
                  <th style="text-align: right;" class="<?php echo ($todayBalance >= 0) ? 'text-success' : 'text-error'; ?> highlight-daily">
                    <?php echo number_format($todayBalance, 2); ?>
                  </th>
                </tr>
              </table>
            </div>
            
            <div class="span5">
              <div style="padding: 20px; background-color: #eee; border-radius: 5px;">
                <h4>Guide d'utilisation:</h4>
                <p><strong>Calcul du solde de caisse (CORRIGÉ):</strong><br>
                Le solde inclut automatiquement:
                <ul>
                  <li>Les ventes régulières du jour (AVEC remises): <?php echo number_format($todayRegularSales, 2); ?></li>
                  <li>Les paiements clients en espèces: <?php echo number_format($cashPayments, 2); ?></li>
                  <li>Les dépôts manuels: <?php echo number_format($todayDeposits, 2); ?></li>
                </ul>
                Le solde exclut:
                <ul>
                  <li>Les ventes à crédit: <?php echo number_format($todayCreditSales, 2); ?></li>
                  <li>Les paiements par carte/virement/mobile: <?php echo number_format($todayCustomerPayments - $cashPayments, 2); ?></li>
                  <li>Les retraits et retours: <?php echo number_format($todayWithdrawals + $todayReturns, 2); ?></li>
                </ul>
                </p>
                
                <?php if ($todayBalance <= 0): ?>
                  <p class="text-error"><strong>SOLDE INSUFFISANT:</strong><br>
                     Les retraits sont désactivés.</p>
                <?php else: ?>
                  <p><strong>Montant max. retirable aujourd'hui:</strong><br>
                     <?php echo number_format($maxWithdrawal, 2); ?></p>
                <?php endif; ?>
                
                <?php if ($todayDiscountGiven > 0): ?>
                <div class="discount-info">
                  <strong>💡 Info Remises:</strong><br>
                  Remises accordées aujourd'hui: <?php echo number_format($todayDiscountGiven, 2); ?><br>
                  <small>Les calculs utilisent maintenant les montants réellement facturés (après remises)</small>
                </div>
                <?php endif; ?>
              </div>
            </div>
          </div>
        </div>
      </div>
    </div>

    <!-- Formulaire de nouvelle transaction -->
    <div class="row-fluid">
      <div class="span12">
        <div class="widget-box">
          <div class="widget-title">
            <span class="icon"><i class="icon-plus"></i></span>
            <h5>Ajouter une nouvelle transaction</h5>
          </div>
          <div class="widget-content nopadding">
            <form method="post" class="form-horizontal">
              <div class="control-group">
                <label class="control-label">Type de transaction :</label>
                <div class="controls">
                  <select name="transtype" id="transtype" required>
                    <option value="IN">Dépôt (IN)</option>
                    <?php if (!$outDisabled): ?>
                    <option value="OUT">Retrait (OUT)</option>
                    <?php endif; ?>
                  </select>
                  <?php if ($outDisabled): ?>
                    <span class="help-inline text-error">Retraits désactivés (solde insuffisant)</span>
                  <?php endif; ?>
                </div>
              </div>

              <div class="control-group">
                <label class="control-label">Montant :</label>
                <div class="controls">
                  <input type="number" name="amount" id="amount" step="0.01" min="0.01" required />
                  <span id="amount-warning" class="help-inline text-error" style="display: none;">
                    Le montant doit être inférieur au solde du jour (<?php echo number_format($todayBalance, 2); ?>)
                  </span>
                </div>
              </div>

              <div class="control-group">
                <label class="control-label">Commentaires :</label>
                <div class="controls">
                  <select name="comments_preset" id="comments_preset" onchange="setComments()">
                    <option value="">-- Commentaire personnalisé --</option>
                    <option value="Ventes du jour">Ventes du jour</option>
                    <option value="Paiements clients">Paiements clients</option>
                    <option value="Retrait pour fournisseur">Retrait pour fournisseur</option>
                    <option value="Dépôt divers">Dépôt divers</option>
                  </select>
                </div>
              </div>
              
              <div class="control-group">
                <label class="control-label"></label>
                <div class="controls">
                  <input type="text" name="comments" id="comments" placeholder="Commentaire (obligatoire)" required />
                </div>
              </div>

              <div class="form-actions">
                <button type="submit" name="submit" class="btn btn-success">
                  Enregistrer la transaction
                </button>
              </div>
            </form>
          </div>
        </div>
      </div>
    </div>

    <hr>

    <!-- Liste des transactions récentes -->
    <div class="row-fluid">
      <div class="span12">
        <div class="widget-box">
          <div class="widget-title">
            <span class="icon"><i class="icon-th"></i></span>
            <h5>Transactions d'aujourd'hui</h5>
          </div>
          <div class="widget-content nopadding">
            <table class="table table-bordered">
              <thead>
                <tr>
                  <th width="5%">#</th>
                  <th width="15%">Date/Heure</th>
                  <th width="10%">Type</th>
                  <th width="15%">Montant</th>
                  <th width="15%">Solde après</th>
                  <th width="40%">Commentaires</th>
                </tr>
              </thead>
              <tbody>
                <?php
                // Afficher uniquement les transactions d'aujourd'hui
                $sqlList = "SELECT * FROM tblcashtransactions 
                           WHERE DATE(TransDate) = CURDATE() 
                           ORDER BY ID DESC";
                $resList = mysqli_query($con, $sqlList);
                $cnt = 1;
                
                if (mysqli_num_rows($resList) > 0) {
                  while ($row = mysqli_fetch_assoc($resList)) {
                    $id = $row['ID'];
                    $transDate = $row['TransDate'];
                    $transType = $row['TransType'];
                    $amount = floatval($row['Amount']);
                    $balance = floatval($row['BalanceAfter']);
                    $comments = $row['Comments'];
                    
                    // Déterminer la classe CSS pour le type
                    $typeClass = '';
                    if ($transType == 'IN') {
                      $typeClass = 'type-in';
                      $transTypeLabel = 'IN';
                    } elseif ($transType == 'OUT') {
                      $typeClass = 'type-out';
                      $transTypeLabel = 'OUT';
                    }
                  ?>
                  <tr>
                    <td><?php echo $cnt; ?></td>
                    <td><?php echo date('d/m/Y H:i', strtotime($transDate)); ?></td>
                    <td>
                      <span class="transaction-type <?php echo $typeClass; ?>">
                        <?php echo $transTypeLabel; ?>
                      </span>
                    </td>
                    <td style="text-align: right;">
                      <?php echo number_format($amount, 2); ?>
                    </td>
                    <td style="text-align: right;">
                      <?php echo number_format($balance, 2); ?>
                    </td>
                    <td>
                      <?php echo $comments; ?>
                    </td>
                  </tr>
                  <?php
                    $cnt++;
                  }
                } else {
                  echo '<tr><td colspan="6" style="text-align: center;">Aucune transaction aujourd\'hui</td></tr>';
                }
                ?>
              </tbody>
            </table>
          </div>
        </div>
      </div>
    </div>
    
    <!-- Historique des transactions -->
    <div class="row-fluid">
      <div class="span12">
        <div class="widget-box">
          <div class="widget-title">
            <span class="icon"><i class="icon-time"></i></span>
            <h5>Historique des transactions (30 derniers jours)</h5>
          </div>
          <div class="widget-content nopadding">
            <table class="table table-bordered">
              <thead>
                <tr>
                  <th width="5%">#</th>
                  <th width="10%">Date</th>
                  <th width="10%">Type</th>
                  <th width="15%">Montant</th>
                  <th width="15%">Solde après</th>
                  <th width="45%">Commentaires</th>
                </tr>
              </thead>
              <tbody>
                <?php
                // Afficher l'historique des transactions (sauf aujourd'hui)
                $sqlHistory = "SELECT * FROM tblcashtransactions 
                              WHERE DATE(TransDate) < CURDATE() 
                              ORDER BY TransDate DESC, ID DESC 
                              LIMIT 100";
                $resHistory = mysqli_query($con, $sqlHistory);
                $cnt = 1;
                
                if (mysqli_num_rows($resHistory) > 0) {
                  while ($row = mysqli_fetch_assoc($resHistory)) {
                    $id = $row['ID'];
                    $transDate = $row['TransDate'];
                    $transType = $row['TransType'];
                    $amount = floatval($row['Amount']);
                    $balance = floatval($row['BalanceAfter']);
                    $comments = $row['Comments'];
                    
                    // Déterminer la classe CSS pour le type
                    $typeClass = '';
                    if ($transType == 'IN') {
                      $typeClass = 'type-in';
                      $transTypeLabel = 'IN';
                    } elseif ($transType == 'OUT') {
                      $typeClass = 'type-out';
                      $transTypeLabel = 'OUT';
                    }
                  ?>
                  <tr>
                    <td><?php echo $cnt; ?></td>
                    <td><?php echo date('d/m/Y', strtotime($transDate)); ?></td>
                    <td>
                      <span class="transaction-type <?php echo $typeClass; ?>">
                        <?php echo $transTypeLabel; ?>
                      </span>
                    </td>
                    <td style="text-align: right;">
                      <?php echo number_format($amount, 2); ?>
                    </td>
                    <td style="text-align: right;">
                      <?php echo number_format($balance, 2); ?>
                    </td>
                    <td>
                      <?php echo $comments; ?>
                    </td>
                  </tr>
                  <?php
                    $cnt++;
                  }
                } else {
                  echo '<tr><td colspan="6" style="text-align: center;">Pas d\'historique disponible</td></tr>';
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

<!-- Scripts -->
<script src="js/jquery.min.js"></script>
<script src="js/jquery.ui.custom.js"></script>
<script src="js/bootstrap.min.js"></script>
<script src="js/jquery.uniform.js"></script>
<script src="js/select2.min.js"></script>
<script src="js/jquery.dataTables.min.js"></script>
<script src="js/matrix.js"></script>
<script src="js/matrix.tables.js"></script>

<script>
$(document).ready(function() {
  // Validation dynamique du montant pour les retraits
  $('#transtype, #amount').on('change input', function() {
    var transType = $('#transtype').val();
    var amount = parseFloat($('#amount').val()) || 0;
    var todayBalance = <?php echo $todayBalance; ?>;
    
    if (transType === 'OUT') {
      if (amount > todayBalance) {
        $('#amount-warning').show();
        $('#amount').addClass('error');
      } else {
        $('#amount-warning').hide();
        $('#amount').removeClass('error');
      }
    } else {
      $('#amount-warning').hide();
      $('#amount').removeClass('error');
    }
  });
  
  // Validation du formulaire avant soumission
  $('form').on('submit', function(e) {
    var transType = $('#transtype').val();
    var amount = parseFloat($('#amount').val()) || 0;
    var todayBalance = <?php echo $todayBalance; ?>;
    
    if (transType === 'OUT' && amount > todayBalance) {
      e.preventDefault();
      alert('Impossible d\'effectuer un retrait supérieur au solde du jour (' + todayBalance.toFixed(2) + ')');
      return false;
    }
    
    return true;
  });
  
  // Suggérer des montants prédéfinis (CORRIGÉ - utilise les montants avec remises)
  $('#comments_preset').on('change', function() {
    var preset = $(this).val();
    if (preset === 'Ventes du jour') {
      $('#amount').val(<?php echo $todayRegularSales; ?>);  // Maintenant avec remises
      $('#transtype').val('IN');
    } else if (preset === 'Paiements clients') {
      $('#amount').val(<?php echo $cashPayments; ?>);  // Updated to use Cash payments only
      $('#transtype').val('IN');
    }
  });
});

// Fonction pour définir le commentaire
function setComments() {
  var preset = document.getElementById('comments_preset').value;
  if (preset !== '') {
    document.getElementById('comments').value = preset;
  }
}
</script>
</body>
</html>