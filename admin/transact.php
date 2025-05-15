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
// A) Calculate today's sale from tblcart
// ---------------------------------------------------------------------
$todysale = 0;

// Query: sum of ProductQty * Price for today's checked-out carts
$query6 = mysqli_query($con, "
  SELECT tblcart.ProductQty, tblproducts.Price
  FROM tblcart
  JOIN tblproducts ON tblproducts.ID = tblcart.ProductId
  WHERE DATE(CartDate) = CURDATE()
    AND IsCheckOut = '1'
");

while ($row = mysqli_fetch_array($query6)) {
  $todays_sale = $row['ProductQty'] * $row['Price'];
  $todysale += $todays_sale;
}

// Optional: check if we already inserted a "Daily Sale" transaction for today
$alreadyInserted = false;
if ($todysale > 0) {
  $checkToday = mysqli_query($con, "
    SELECT ID 
    FROM tblcashtransactions
    WHERE TransType='IN'
      AND DATE(TransDate)=CURDATE()
      AND Comments='Daily Sale'
    LIMIT 1
  ");
  if (mysqli_num_rows($checkToday) > 0) {
    $alreadyInserted = true;
  }
}

// If we have a positive sale and not inserted yet, insert a new "IN" transaction
if ($todysale > 0 && !$alreadyInserted) {
  // 1) Get the last BalanceAfter
  $sqlLast = "SELECT BalanceAfter FROM tblcashtransactions ORDER BY ID DESC LIMIT 1";
  $resLast = mysqli_query($con, $sqlLast);
  if (mysqli_num_rows($resLast) > 0) {
    $rowLast = mysqli_fetch_assoc($resLast);
    $oldBal  = floatval($rowLast['BalanceAfter']);
  } else {
    $oldBal = 0;
  }

  // 2) newBal = oldBal + $todysale
  $newBal = $oldBal + $todysale;

  // 3) Insert row in tblcashtransactions
  $sqlInsertSale = "
    INSERT INTO tblcashtransactions(TransDate, TransType, Amount, BalanceAfter, Comments)
    VALUES(NOW(), 'IN', '$todysale', '$newBal', 'Daily Sale')
  ";
  mysqli_query($con, $sqlInsertSale);
}

// ---------------------------------------------------------------------
// B) Calculate the daily balance BEFORE processing new transactions
// ---------------------------------------------------------------------

// 1. Today's transaction totals
$sqlToday = "
  SELECT
  COALESCE(SUM(CASE WHEN TransType='IN'  THEN Amount ELSE 0 END),0) as sumIn,
  COALESCE(SUM(CASE WHEN TransType='OUT' THEN Amount ELSE 0 END),0) as sumOut
  FROM tblcashtransactions
  WHERE DATE(TransDate) = CURDATE()
";
$resToday = mysqli_query($con, $sqlToday);
$rowToday = mysqli_fetch_assoc($resToday);
$todayIn  = floatval($rowToday['sumIn']);
$todayOut = floatval($rowToday['sumOut']);
$todayNet = $todayIn - $todayOut;

// 2. Today's returns
$sqlTodayReturns = "
  SELECT COALESCE(SUM(ReturnPrice), 0) AS todayReturns
  FROM tblreturns
  WHERE DATE(ReturnDate) = CURDATE()
";
$resTodayReturns = mysqli_query($con, $sqlTodayReturns);
$rowTodayReturns = mysqli_fetch_assoc($resTodayReturns);
$todayReturns = floatval($rowTodayReturns['todayReturns']);

// 3. Calculate daily balance
$dailyBalance = $todayNet - $todayReturns;

// 4. Get previous day's balance
$sqlPrevious = "
  SELECT BalanceAfter 
  FROM tblcashtransactions 
  WHERE DATE(TransDate) < CURDATE() 
  ORDER BY ID DESC 
  LIMIT 1
";
$resPrevious = mysqli_query($con, $sqlPrevious);
if (mysqli_num_rows($resPrevious) > 0) {
  $rowPrevious = mysqli_fetch_assoc($resPrevious);
  $previousBalance = floatval($rowPrevious['BalanceAfter']);
} else {
  $previousBalance = 0;
}

// 5. Calculate current balance
$currentBalance = $previousBalance + $dailyBalance;

// ---------------------------------------------------------------------
// C) Handle manual transaction (Deposit/Withdrawal) from your form
// ---------------------------------------------------------------------
$transactionError = ''; // Track any errors for display

if (isset($_POST['submit'])) {
  $transtype = $_POST['transtype']; // 'IN' or 'OUT'
  $amount    = floatval($_POST['amount']);
  $comments  = mysqli_real_escape_string($con, $_POST['comments']);

  if ($amount <= 0) {
    $transactionError = 'Montant invalide. Doit être > 0';
  } 
  // Block OUT when current balance is zero or negative
  else if ($transtype == 'OUT' && $currentBalance <= 0) {
    $transactionError = 'Impossible d\'effectuer un retrait : le solde actuel est nul ou négatif';
  }
  // Block if amount would exceed the balance
  else if ($transtype == 'OUT' && $amount > $currentBalance) {
    $transactionError = 'Impossible d\'effectuer un retrait : montant supérieur au solde actuel';
  }
  else {
    // Find last transaction's balance
    $sqlLast = "SELECT BalanceAfter FROM tblcashtransactions ORDER BY ID DESC LIMIT 1";
    $resLast = mysqli_query($con, $sqlLast);
    if (mysqli_num_rows($resLast) > 0) {
      $rowLast  = mysqli_fetch_assoc($resLast);
      $oldBal   = floatval($rowLast['BalanceAfter']);
    } else {
      $oldBal = 0;
    }

    // Compute new balance
    if ($transtype == 'IN') {
      $newBal = $oldBal + $amount;
      
      // Insert new deposit transaction
      $sqlInsert = "
        INSERT INTO tblcashtransactions(TransDate, TransType, Amount, BalanceAfter, Comments)
        VALUES(NOW(), '$transtype', '$amount', '$newBal', '$comments')
      ";
      if (mysqli_query($con, $sqlInsert)) {
        echo "<script>alert('Transaction enregistrée!');</script>";
        // Refresh
        echo "<script>window.location.href='transact.php'</script>";
        exit;
      } else {
        $transactionError = 'Erreur lors de l\'insertion de la transaction';
      }
    } else {
      // 'OUT' transaction
      $newBal = $oldBal - $amount;
      
      // Double-check to never allow balance to go negative (zero is fine)
      if ($newBal < 0) {
        $transactionError = 'Impossible d\'effectuer un retrait : fonds insuffisants';
      } else {
        // Insert new withdrawal transaction
        $sqlInsert = "
          INSERT INTO tblcashtransactions(TransDate, TransType, Amount, BalanceAfter, Comments)
          VALUES(NOW(), '$transtype', '$amount', '$newBal', '$comments')
        ";
        if (mysqli_query($con, $sqlInsert)) {
          echo "<script>alert('Transaction enregistrée!');</script>";
          // Refresh
          echo "<script>window.location.href='transact.php'</script>";
          exit;
        } else {
          $transactionError = 'Erreur lors de l\'insertion de la transaction';
        }
      }
    }
  }
  
  if ($transactionError) {
    echo "<script>alert('$transactionError');</script>";
  }
}

// ---------------------------------------------------------------------
// D) Recalculate today's transaction totals for display (in case we added a new one)
// ---------------------------------------------------------------------
$sqlToday = "
  SELECT
  COALESCE(SUM(CASE WHEN TransType='IN'  THEN Amount ELSE 0 END),0) as sumIn,
  COALESCE(SUM(CASE WHEN TransType='OUT' THEN Amount ELSE 0 END),0) as sumOut
  FROM tblcashtransactions
  WHERE DATE(TransDate) = CURDATE()
";
$resToday = mysqli_query($con, $sqlToday);
$rowToday = mysqli_fetch_assoc($resToday);
$todayIn  = floatval($rowToday['sumIn']);
$todayOut = floatval($rowToday['sumOut']);
$todayNet = $todayIn - $todayOut;

// Calculate daily balance again for display
$dailyBalance = $todayNet - $todayReturns;

// Calculate current balance again for display
$currentBalance = $previousBalance + $dailyBalance;

// Keep the old calculation for reference
$sqlBal = "SELECT BalanceAfter FROM tblcashtransactions ORDER BY ID DESC LIMIT 1";
$resBal = mysqli_query($con, $sqlBal);
if (mysqli_num_rows($resBal) > 0) {
  $rowBal = mysqli_fetch_assoc($resBal);
  $oldBalance = floatval($rowBal['BalanceAfter']);
} else {
  $oldBalance = 0;
}

// Determine the maximum amount that can be withdrawn (allow down to zero)
$maxWithdrawal = $currentBalance > 0 ? $currentBalance : 0;

// Determine if OUT transactions should be completely disabled
$outDisabled = ($currentBalance <= 0);
?>
<!DOCTYPE html>
<html lang="fr">
<head>
  <title>Gestion d'inventaire | Transactions en espèces</title>
  <?php include_once('includes/cs.php'); ?>
  <?php include_once('includes/responsive.php'); ?>
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
  <h1>Transactions en espèces (Vente quotidienne + Dépôt/Retrait manuel)</h1>
  </div>

  <div class="container-fluid">
  <hr>

  <!-- Display current balance & today's net -->
  <div class="row-fluid">
    <div class="span12">
    <div style="border: 1px solid #ccc; padding: 15px; margin-bottom: 20px;">
      <h4>Solde actuel: <?php echo number_format($currentBalance, 2); ?></h4>
      <?php if ($oldBalance != $currentBalance): ?>
      <p><small>(Ancien calcul: <?php echo number_format($oldBalance, 2); ?> - La différence inclut maintenant les retours)</small></p>
      <?php endif; ?>
      <p>Aujourd'hui IN: <?php echo number_format($todayIn, 2); ?>,
       Aujourd'hui OUT: <?php echo number_format($todayOut, 2); ?>,
       Net: <?php echo number_format($todayNet, 2); ?></p>
      <p>Vente du jour: <?php echo number_format($todysale, 2); ?><?php
       if ($alreadyInserted) {
         echo " (déjà ajouté à la caisse)";
       }
      ?></p>
      <p>Retours du jour: <?php echo number_format($todayReturns, 2); ?></p>
      <p>Solde journalier: <strong><?php echo number_format($dailyBalance, 2); ?></strong> 
      <?php if ($dailyBalance <= 0): ?>
        <span style="color: red;">(Attention: Retraits bloqués car solde journalier ≤ 0)</span>
      <?php endif; ?>
      </p>
      <?php if ($currentBalance <= 0): ?>
        <p style="color: red; font-weight: bold;">SOLDE NUL OU NÉGATIF: RETRAITS DÉSACTIVÉS</p>
      <?php else: ?>
        <p>Montant max. retirable: <strong><?php echo number_format($maxWithdrawal, 2); ?></strong></p>
      <?php endif; ?>
    </div>
    </div>
  </div>

  <!-- ========== NEW TRANSACTION FORM ========== -->
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
            <span class="help-inline" style="color: red;">Retraits désactivés (solde actuel nul ou négatif)</span>
          <?php endif; ?>
        </div>
        </div>

        <div class="control-group">
        <label class="control-label">Montant :</label>
        <div class="controls">
          <input type="number" name="amount" id="amount" step="0.01" min="0.01" 
                 <?php if ($transtype == 'OUT'): ?>max="<?php echo $maxWithdrawal; ?>"<?php endif; ?> required />
          <span id="amount-warning" class="help-inline" style="color: red; display: none;">
            Le montant doit être inférieur à <?php echo number_format($maxWithdrawal, 2); ?> pour garder un solde positif
          </span>
        </div>
        </div>

        <div class="control-group">
        <label class="control-label">Commentaires :</label>
        <div class="controls">
          <input type="text" name="comments" placeholder="Note optionnelle" />
        </div>
        </div>

        <div class="form-actions">
        <button type="submit" name="submit" class="btn btn-success">
          Enregistrer la transaction
        </button>
        </div>
      </form>
      </div><!-- widget-content nopadding -->
    </div><!-- widget-box -->
    </div>
  </div><!-- row-fluid -->

  <hr>

  <!-- ========== RECENT TRANSACTIONS LIST ========== -->
  <div class="row-fluid">
    <div class="span12">
    <div class="widget-box">
      <div class="widget-title">
      <span class="icon"><i class="icon-th"></i></span>
      <h5>Transactions récentes</h5>
      </div>
      <div class="widget-content nopadding">
      <table class="table table-bordered data-table">
        <thead>
        <tr>
          <th>#</th>
          <th>Date/Heure</th>
          <th>Type</th>
          <th>Montant</th>
          <th>Solde après</th>
          <th>Commentaires</th>
        </tr>
        </thead>
        <tbody>
        <?php
        $sqlList = "SELECT * FROM tblcashtransactions ORDER BY ID DESC LIMIT 50";
        $resList = mysqli_query($con, $sqlList);
        $cnt = 1;
        while ($row = mysqli_fetch_assoc($resList)) {
          $id          = $row['ID'];
          $transDate   = $row['TransDate'];
          $transType   = $row['TransType'];
          $amount      = floatval($row['Amount']);
          $balance     = floatval($row['BalanceAfter']);
          $comments    = $row['Comments'];
          ?>
          <tr>
            <td><?php echo $cnt; ?></td>
            <td><?php echo $transDate; ?></td>
            <td><?php echo $transType; ?></td>
            <td><?php echo number_format($amount,2); ?></td>
            <td><?php echo number_format($balance,2); ?></td>
            <td><?php echo $comments; ?></td>
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

<!-- Scripts -->
<script src="js/jquery.min.js"></script>
<script src="js/jquery.ui.custom.js"></script>
<script src="js/bootstrap.min.js"></script>
<script src="js/jquery.uniform.js"></script>
<script src="js/select2.min.js"></script>
<script src="js/jquery.dataTables.min.js"></script>
<script src="js/matrix.js"></script>
<script src="js/matrix.tables.js"></script>

<!-- Custom script for amount validation -->
<script>
$(document).ready(function() {
  // Show/hide max amount warning based on transaction type
  $('#transtype, #amount').on('change input', function() {
    var transType = $('#transtype').val();
    var amount = parseFloat($('#amount').val()) || 0;
    var maxWithdrawal = <?php echo $maxWithdrawal; ?>;
    var currentBalance = <?php echo $currentBalance; ?>;
    
    // Display warning when withdrawal amount exceeds current balance
    if (transType === 'OUT') {
      if (currentBalance <= 0) {
        // Should never happen due to form restrictions, but just in case
        $('#amount-warning').text('Retraits désactivés (solde nul ou négatif)').show();
        $('#amount').val('');  // Clear the amount
      } else if (amount > maxWithdrawal) {
        $('#amount-warning').text('Le montant doit être inférieur à ' + maxWithdrawal.toFixed(2) + ' pour garder un solde positif').show();
      } else {
        $('#amount-warning').hide();
      }
    } else {
      $('#amount-warning').hide();
    }
  });
  
  // Extra validation before form submission
  $('form').on('submit', function(e) {
    var transType = $('#transtype').val();
    var amount = parseFloat($('#amount').val()) || 0;
    var currentBalance = <?php echo $currentBalance; ?>;
    
    if (transType === 'OUT' && currentBalance <= 0) {
      e.preventDefault();
      alert('Impossible d\'effectuer un retrait : le solde actuel est nul ou négatif');
      return false;
    }
    
    if (transType === 'OUT' && amount > currentBalance) {
      e.preventDefault();
      alert('Impossible d\'effectuer un retrait : montant supérieur au solde actuel');
      return false;
    }
    
    return true;
  });
});
</script>
</body>
</html>