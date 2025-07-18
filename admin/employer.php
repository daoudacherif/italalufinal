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
// 1) CALCULATE CURRENT MONTH SALARY OBLIGATIONS AND PAYMENTS
// ---------------------------------------------------------------------

// Current month and year
$currentMonth = date('Y-m');
$currentMonthName = date('F Y');

// 1.1 Total salary obligations for current month (all active employees)
$sqlSalaryObligations = "
  SELECT COALESCE(SUM(e.BaseSalary), 0) AS totalObligations,
         COUNT(*) AS activeEmployees
  FROM tblemployees e
  WHERE e.Status = 'active'
";
$resSalaryObligations = mysqli_query($con, $sqlSalaryObligations);
$rowSalaryObligations = mysqli_fetch_assoc($resSalaryObligations);
$monthlyObligations = floatval($rowSalaryObligations['totalObligations']);
$activeEmployees = intval($rowSalaryObligations['activeEmployees']);

// 1.2 Total payments made this month
$sqlPaymentsMade = "
  SELECT 
    COALESCE(SUM(CASE WHEN TransactionType='payment' THEN Amount ELSE 0 END), 0) AS salaryPayments,
    COALESCE(SUM(CASE WHEN TransactionType='bonus' THEN Amount ELSE 0 END), 0) AS bonusPayments,
    COALESCE(SUM(CASE WHEN TransactionType='deduction' THEN Amount ELSE 0 END), 0) AS deductions,
    COALESCE(SUM(CASE WHEN TransactionType='adjustment' THEN Amount ELSE 0 END), 0) AS adjustments
  FROM tblsalarytransactions
  WHERE DATE_FORMAT(TransactionDate, '%Y-%m') = '$currentMonth'
    AND Status = 'completed'
";
$resPaymentsMade = mysqli_query($con, $sqlPaymentsMade);
$rowPaymentsMade = mysqli_fetch_assoc($resPaymentsMade);
$salaryPaymentsMade = floatval($rowPaymentsMade['salaryPayments']);
$bonusPaymentsMade = floatval($rowPaymentsMade['bonusPayments']);
$deductionsMade = floatval($rowPaymentsMade['deductions']);
$adjustmentsMade = floatval($rowPaymentsMade['adjustments']);

// 1.3 Outstanding salary balance
$outstandingSalaries = $monthlyObligations - $salaryPaymentsMade;

// 1.4 Total advances outstanding
$sqlAdvancesOutstanding = "
  SELECT COALESCE(SUM(RemainingBalance), 0) AS totalAdvances
  FROM tblsalaryadvances
  WHERE Status = 'active'
";
$resAdvancesOutstanding = mysqli_query($con, $sqlAdvancesOutstanding);
$rowAdvancesOutstanding = mysqli_fetch_assoc($resAdvancesOutstanding);
$outstandingAdvances = floatval($rowAdvancesOutstanding['totalAdvances']);

// 1.5 Get employees who haven't been paid this month
$sqlUnpaidEmployees = "
  SELECT e.ID, e.FullName, e.BaseSalary, e.Position
  FROM tblemployees e
  WHERE e.Status = 'active'
    AND e.ID NOT IN (
      SELECT DISTINCT st.EmployeeID 
      FROM tblsalarytransactions st 
      WHERE st.TransactionType = 'payment'
        AND DATE_FORMAT(st.TransactionDate, '%Y-%m') = '$currentMonth'
        AND st.Status = 'completed'
    )
";
$resUnpaidEmployees = mysqli_query($con, $sqlUnpaidEmployees);

// 1.6 AVANCES SUR SALAIRE - Calculs
$sqlActiveAdvances = "
  SELECT 
    COUNT(*) AS totalAdvances,
    COALESCE(SUM(Amount), 0) AS totalAmount,
    COALESCE(SUM(RemainingBalance), 0) AS totalRemaining
  FROM tblsalaryadvances
  WHERE Status = 'active'
";
$resActiveAdvances = mysqli_query($con, $sqlActiveAdvances);
$rowActiveAdvances = mysqli_fetch_assoc($resActiveAdvances);
$totalActiveAdvances = intval($rowActiveAdvances['totalAdvances']);
$totalAdvanceAmount = floatval($rowActiveAdvances['totalAmount']);
$totalRemainingBalance = floatval($rowActiveAdvances['totalRemaining']);

// ---------------------------------------------------------------------
// 2) HANDLE NEW SALARY TRANSACTION
// ---------------------------------------------------------------------

$transactionError = '';
$transactionSuccess = '';

if (isset($_POST['submit_transaction'])) {
  $employeeId = intval($_POST['employee_id']);
  $transactionType = $_POST['transaction_type'];
  $amount = floatval($_POST['amount']);
  $paymentMethod = $_POST['payment_method'];
  $payrollPeriod = mysqli_real_escape_string($con, $_POST['payroll_period']);
  $description = mysqli_real_escape_string($con, $_POST['description']);
  $processedBy = $_SESSION['imsaid']; // Admin ID

  if ($employeeId <= 0) {
    $transactionError = 'Veuillez sélectionner un employé valide';
  } elseif ($amount <= 0) {
    $transactionError = 'Le montant doit être supérieur à 0';
  } else {
    // Insert the transaction
    $sqlInsertTransaction = "
      INSERT INTO tblsalarytransactions (EmployeeID, TransactionType, Amount, PaymentMethod, PayrollPeriod, Description, ProcessedBy)
      VALUES ('$employeeId', '$transactionType', '$amount', '$paymentMethod', '$payrollPeriod', '$description', '$processedBy')
    ";
    
    if (mysqli_query($con, $sqlInsertTransaction)) {
      $transactionSuccess = 'Transaction enregistrée avec succès!';
      echo "<script>setTimeout(function(){ window.location.href='employee-salary.php'; }, 1500);</script>";
    } else {
      $transactionError = 'Erreur lors de l\'enregistrement de la transaction';
    }
  }
}

// ---------------------------------------------------------------------
// 3) HANDLE NEW EMPLOYEE
// ---------------------------------------------------------------------

if (isset($_POST['submit_employee'])) {
  $employeeCode = mysqli_real_escape_string($con, $_POST['employee_code']);
  $fullName = mysqli_real_escape_string($con, $_POST['full_name']);
  $position = mysqli_real_escape_string($con, $_POST['position']);
  $department = mysqli_real_escape_string($con, $_POST['department']);
  $hireDate = $_POST['hire_date'];
  $baseSalary = floatval($_POST['base_salary']);
  $paymentFrequency = $_POST['payment_frequency'];
  $phone = mysqli_real_escape_string($con, $_POST['phone']);
  $email = mysqli_real_escape_string($con, $_POST['email']);

  // Check if employee code already exists
  $checkCode = mysqli_query($con, "SELECT ID FROM tblemployees WHERE EmployeeCode = '$employeeCode'");
  
  if (mysqli_num_rows($checkCode) > 0) {
    $transactionError = 'Ce code employé existe déjà';
  } elseif (empty($employeeCode) || empty($fullName) || empty($position) || $baseSalary <= 0) {
    $transactionError = 'Veuillez remplir tous les champs obligatoires';
  } else {
    $sqlInsertEmployee = "
      INSERT INTO tblemployees (EmployeeCode, FullName, Position, Department, HireDate, BaseSalary, PaymentFrequency, Phone, Email)
      VALUES ('$employeeCode', '$fullName', '$position', '$department', '$hireDate', '$baseSalary', '$paymentFrequency', '$phone', '$email')
    ";
    
    if (mysqli_query($con, $sqlInsertEmployee)) {
      $transactionSuccess = 'Employé ajouté avec succès!';
      echo "<script>setTimeout(function(){ window.location.href='employee-salary.php'; }, 1500);</script>";
    } else {
      $transactionError = 'Erreur lors de l\'ajout de l\'employé';
    }
  }
}

// ---------------------------------------------------------------------
// 4) HANDLE NEW SALARY ADVANCE
// ---------------------------------------------------------------------

if (isset($_POST['submit_advance'])) {
  $employeeId = intval($_POST['employee_id_advance']);
  $advanceAmount = floatval($_POST['advance_amount']);
  $reason = mysqli_real_escape_string($con, $_POST['reason']);
  $monthlyDeduction = floatval($_POST['monthly_deduction']);
  $approvedBy = $_SESSION['imsaid']; // Admin ID

  // Vérifications
  if ($employeeId <= 0) {
    $transactionError = 'Veuillez sélectionner un employé valide';
  } elseif ($advanceAmount <= 0) {
    $transactionError = 'Le montant de l\'avance doit être supérieur à 0';
  } elseif ($monthlyDeduction <= 0) {
    $transactionError = 'La déduction mensuelle doit être supérieure à 0';
  } elseif ($monthlyDeduction > $advanceAmount) {
    $transactionError = 'La déduction mensuelle ne peut pas être supérieure au montant de l\'avance';
  } else {
    // Vérifier si l'employé a déjà une avance active
    $checkExisting = mysqli_query($con, "
      SELECT ID FROM tblsalaryadvances 
      WHERE EmployeeID = '$employeeId' AND Status = 'active'
    ");
    
    // Récupérer le salaire de l'employé pour validation
    $checkSalary = mysqli_query($con, "
      SELECT BaseSalary FROM tblemployees 
      WHERE ID = '$employeeId' AND Status = 'active'
    ");
    
    if (mysqli_num_rows($checkExisting) > 0) {
      $transactionError = 'Cet employé a déjà une avance active. Terminez la première avant d\'en accorder une nouvelle.';
    } elseif (mysqli_num_rows($checkSalary) == 0) {
      $transactionError = 'Employé non trouvé ou inactif';
    } else {
      $salaryRow = mysqli_fetch_assoc($checkSalary);
      $baseSalary = floatval($salaryRow['BaseSalary']);
      
      // Vérifier que l'avance ne dépasse pas 80% du salaire
      if ($advanceAmount > ($baseSalary * 0.8)) {
        $transactionError = 'L\'avance ne peut pas dépasser 80% du salaire de base (' . number_format($baseSalary * 0.8, 2) . ')';
      } elseif ($monthlyDeduction > ($baseSalary * 0.3)) {
        $transactionError = 'La déduction mensuelle ne peut pas dépasser 30% du salaire (' . number_format($baseSalary * 0.3, 2) . ')';
      } else {
        // Calculer la date de début des déductions (mois prochain)
        $deductionStartDate = date('Y-m-01', strtotime('+1 month'));
        
        // Insérer l'avance
        $sqlInsertAdvance = "
          INSERT INTO tblsalaryadvances (EmployeeID, AdvanceDate, Amount, Reason, DeductionStartDate, MonthlyDeduction, RemainingBalance, ApprovedBy)
          VALUES ('$employeeId', CURDATE(), '$advanceAmount', '$reason', '$deductionStartDate', '$monthlyDeduction', '$advanceAmount', '$approvedBy')
        ";
        
        if (mysqli_query($con, $sqlInsertAdvance)) {
          $transactionSuccess = 'Avance accordée avec succès! Déductions commenceront le ' . date('d/m/Y', strtotime($deductionStartDate));
          echo "<script>setTimeout(function(){ window.location.href='employee-salary.php'; }, 2000);</script>";
        } else {
          $transactionError = 'Erreur lors de l\'enregistrement de l\'avance';
        }
      }
    }
  }
}

// ---------------------------------------------------------------------
// 5) HANDLE ADVANCE PAYMENT/DEDUCTION
// ---------------------------------------------------------------------

if (isset($_POST['submit_advance_payment'])) {
  $advanceId = intval($_POST['advance_id']);
  $paymentAmount = floatval($_POST['payment_amount']);
  $paymentReason = mysqli_real_escape_string($con, $_POST['payment_reason']);

  if ($advanceId <= 0 || $paymentAmount <= 0) {
    $transactionError = 'Données de remboursement invalides';
  } else {
    // Récupérer l'avance actuelle
    $getAdvance = mysqli_query($con, "
      SELECT * FROM tblsalaryadvances 
      WHERE ID = '$advanceId' AND Status = 'active'
    ");
    
    if (mysqli_num_rows($getAdvance) == 0) {
      $transactionError = 'Avance non trouvée ou déjà terminée';
    } else {
      $advance = mysqli_fetch_assoc($getAdvance);
      $currentBalance = floatval($advance['RemainingBalance']);
      
      if ($paymentAmount > $currentBalance) {
        $transactionError = 'Le montant de remboursement ne peut pas dépasser le solde restant (' . number_format($currentBalance, 2) . ')';
      } else {
        $newBalance = $currentBalance - $paymentAmount;
        $newStatus = ($newBalance <= 0) ? 'completed' : 'active';
        
        // Mettre à jour l'avance
        $updateAdvance = "
          UPDATE tblsalaryadvances 
          SET RemainingBalance = '$newBalance', Status = '$newStatus'
          WHERE ID = '$advanceId'
        ";
        
        if (mysqli_query($con, $updateAdvance)) {
          // Enregistrer la transaction de remboursement
          $sqlInsertPayment = "
            INSERT INTO tblsalarytransactions (EmployeeID, TransactionType, Amount, PaymentMethod, Description, ProcessedBy)
            VALUES ('{$advance['EmployeeID']}', 'deduction', '$paymentAmount', 'adjustment', 'Remboursement avance: $paymentReason', '{$_SESSION['imsaid']}')
          ";
          mysqli_query($con, $sqlInsertPayment);
          
          $transactionSuccess = 'Remboursement enregistré avec succès!';
          if ($newStatus == 'completed') {
            $transactionSuccess .= ' Avance totalement remboursée.';
          }
          echo "<script>setTimeout(function(){ window.location.href='employee-salary.php'; }, 2000);</script>";
        } else {
          $transactionError = 'Erreur lors de la mise à jour de l\'avance';
        }
      }
    }
  }
}

?>
<!DOCTYPE html>
<html lang="fr">
<head>
  <title>Gestion d'inventaire | Gestion des salaires</title>
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
    .highlight-monthly {
      background-color: #e6f3ff; 
      font-weight: bold;
    }
    .outstanding-salary {
      background-color: #fff2cc;
      font-weight: bold;
    }
    
    .transaction-type {
      display: inline-block;
      padding: 2px 6px;
      font-size: 12px;
      font-weight: bold;
      border-radius: 3px;
      text-align: center;
    }
    .type-payment { 
      background-color: #d4edda; 
      color: #155724;
    }
    .type-bonus { 
      background-color: #cce7ff; 
      color: #004085;
    }
    .type-deduction { 
      background-color: #f8d7da; 
      color: #721c24;
    }
    .type-adjustment { 
      background-color: #fff3cd; 
      color: #856404;
    }
    
    .alert-info {
      background-color: #d9edf7;
      border-color: #bce8f1;
      color: #3a87ad;
      padding: 8px;
      margin-bottom: 15px;
      border-radius: 4px;
    }
    
    .alert-success {
      background-color: #d4edda;
      border-color: #c3e6cb;
      color: #155724;
      padding: 8px;
      margin-bottom: 15px;
      border-radius: 4px;
    }
    
    .alert-error {
      background-color: #f8d7da;
      border-color: #f5c6cb;
      color: #721c24;
      padding: 8px;
      margin-bottom: 15px;
      border-radius: 4px;
    }
    
    .unpaid-employee {
      background-color: #fff2cc;
    }
    
    .nav-tabs {
      margin-bottom: 20px;
    }
    
    .tab-content {
      border: 1px solid #ddd;
      border-top: none;
      padding: 20px;
      background-color: white;
    }
    
    .advance-status {
      display: inline-block;
      padding: 2px 6px;
      font-size: 11px;
      font-weight: bold;
      border-radius: 3px;
      text-align: center;
    }
    .status-active { 
      background-color: #fff3cd; 
      color: #856404;
    }
    .status-completed { 
      background-color: #d4edda; 
      color: #155724;
    }
    
    .advance-highlight {
      background-color: #fffacd;
      border-left: 4px solid #ffc107;
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
      <a href="employee-salary.php" class="current">Gestion des salaires</a>
    </div>
    <h1>Gestion des employés et salaires (PÉRIODE: <?php echo $currentMonthName; ?>)</h1>
  </div>

  <div class="container-fluid">
    <hr>
    
    <?php if ($transactionSuccess): ?>
    <div class="alert-success">
      <strong>Succès:</strong> <?php echo $transactionSuccess; ?>
    </div>
    <?php endif; ?>
    
    <?php if ($transactionError): ?>
    <div class="alert-error">
      <strong>Erreur:</strong> <?php echo $transactionError; ?>
    </div>
    <?php endif; ?>
    
    <div class="alert-info">
      <strong>Information:</strong> Cette page permet de gérer les employés, leurs salaires et les avances. 
      Le calcul du solde salarial se base sur les obligations mensuelles par rapport aux paiements effectués.
      <br><strong>Employés actifs:</strong> <?php echo $activeEmployees; ?> | 
      <strong>Obligations mensuelles:</strong> <?php echo number_format($monthlyObligations, 2); ?> | 
      <strong>Avances en cours:</strong> <?php echo $totalActiveAdvances; ?> (<?php echo number_format($totalRemainingBalance, 2); ?>)
    </div>

    <!-- Résumé des salaires -->
    <div class="row-fluid">
      <div class="span12">
        <div class="balance-box">
          <div class="row-fluid">
            <div class="span8">
              <h3>Situation salariale (<?php echo $currentMonthName; ?>): 
                <span class="<?php echo ($outstandingSalaries <= 0) ? 'text-success' : 'text-warning'; ?> highlight-monthly">
                  Reste à payer: <?php echo number_format($outstandingSalaries, 2); ?>
                </span>
              </h3>
              
              <h4>Détail du mois:</h4>
              <table class="table table-bordered table-striped" style="width: auto;">
                <tr>
                  <td>Obligations salariales totales (<?php echo $activeEmployees; ?> employés actifs):</td>
                  <td style="text-align: right;">
                    <strong><?php echo number_format($monthlyObligations, 2); ?></strong>
                  </td>
                </tr>
                <tr>
                  <td>Salaires payés ce mois:</td>
                  <td style="text-align: right;">
                    <strong class="text-success">-<?php echo number_format($salaryPaymentsMade, 2); ?></strong>
                  </td>
                </tr>
                <tr class="outstanding-salary">
                  <th>Salaires restant à payer:</th>
                  <th style="text-align: right;" class="<?php echo ($outstandingSalaries <= 0) ? 'text-success' : 'text-warning'; ?>">
                    <?php echo number_format($outstandingSalaries, 2); ?>
                  </th>
                </tr>
                <tr>
                  <td colspan="2"><hr style="margin: 5px 0;"></td>
                </tr>
                <tr>
                  <td>Primes payées ce mois:</td>
                  <td style="text-align: right;">+<?php echo number_format($bonusPaymentsMade, 2); ?></td>
                </tr>
                <tr>
                  <td>Déductions appliquées:</td>
                  <td style="text-align: right;"><?php echo number_format($deductionsMade, 2); ?></td>
                </tr>
                <tr>
                  <td>Ajustements:</td>
                  <td style="text-align: right;"><?php echo number_format($adjustmentsMade, 2); ?></td>
                </tr>
                <tr>
                  <td>Avances en cours (total):</td>
                  <td style="text-align: right;" class="text-warning"><?php echo number_format($totalRemainingBalance, 2); ?></td>
                </tr>
                <tr>
                  <td><small>└ Nombre d'avances actives:</small></td>
                  <td style="text-align: right;"><small><?php echo $totalActiveAdvances; ?> avance(s)</small></td>
                </tr>
              </table>
            </div>
            
            <div class="span4">
              <div style="padding: 20px; background-color: #eee; border-radius: 5px;">
                <h4>Employés non payés ce mois:</h4>
                <?php if (mysqli_num_rows($resUnpaidEmployees) > 0): ?>
                  <ul style="margin: 0; padding-left: 20px;">
                    <?php while ($unpaid = mysqli_fetch_assoc($resUnpaidEmployees)): ?>
                      <li style="margin-bottom: 5px;">
                        <strong><?php echo $unpaid['FullName']; ?></strong><br>
                        <small><?php echo $unpaid['Position']; ?> - <?php echo number_format($unpaid['BaseSalary'], 2); ?></small>
                      </li>
                    <?php endwhile; ?>
                  </ul>
                <?php else: ?>
                  <p class="text-success"><strong>✓ Tous les employés ont été payés ce mois!</strong></p>
                <?php endif; ?>
                
                <hr>
                <h5>Actions rapides:</h5>
                <p><small>
                  • Utilisez l'onglet "Nouvelle transaction" pour enregistrer un paiement<br>
                  • Utilisez l'onglet "Nouvel employé" pour ajouter un employé<br>
                  • Les calculs se mettent à jour automatiquement
                </small></p>
              </div>
            </div>
          </div>
        </div>
      </div>
    </div>

    <!-- Tabs Navigation -->
    <ul class="nav nav-tabs">
      <li class="active"><a href="#transactions" data-toggle="tab">Nouvelle transaction salariale</a></li>
      <li><a href="#new-employee" data-toggle="tab">Nouvel employé</a></li>
      <li><a href="#salary-advances" data-toggle="tab">Avances sur salaire (<?php echo $totalActiveAdvances; ?>)</a></li>
      <li><a href="#current-transactions" data-toggle="tab">Transactions du mois</a></li>
      <li><a href="#employee-list" data-toggle="tab">Liste des employés</a></li>
    </ul>

    <div class="tab-content">
      <!-- Tab 1: New Transaction -->
      <div id="transactions" class="tab-pane active">
        <div class="widget-box">
          <div class="widget-title">
            <span class="icon"><i class="icon-plus"></i></span>
            <h5>Ajouter une nouvelle transaction salariale</h5>
          </div>
          <div class="widget-content nopadding">
            <form method="post" class="form-horizontal">
              <div class="control-group">
                <label class="control-label">Employé :</label>
                <div class="controls">
                  <select name="employee_id" required>
                    <option value="">-- Sélectionner un employé --</option>
                    <?php
                    $sqlEmployees = "SELECT ID, EmployeeCode, FullName, Position, BaseSalary FROM tblemployees WHERE Status='active' ORDER BY FullName";
                    $resEmployees = mysqli_query($con, $sqlEmployees);
                    while ($emp = mysqli_fetch_assoc($resEmployees)) {
                      echo "<option value='{$emp['ID']}'>{$emp['FullName']} - {$emp['Position']} (Salaire: " . number_format($emp['BaseSalary'], 2) . ")</option>";
                    }
                    ?>
                  </select>
                </div>
              </div>

              <div class="control-group">
                <label class="control-label">Type de transaction :</label>
                <div class="controls">
                  <select name="transaction_type" required>
                    <option value="payment">Paiement de salaire</option>
                    <option value="bonus">Prime/Bonus</option>
                    <option value="deduction">Déduction</option>
                    <option value="adjustment">Ajustement</option>
                  </select>
                </div>
              </div>

              <div class="control-group">
                <label class="control-label">Montant :</label>
                <div class="controls">
                  <input type="number" name="amount" step="0.01" min="0.01" required />
                </div>
              </div>

              <div class="control-group">
                <label class="control-label">Méthode de paiement :</label>
                <div class="controls">
                  <select name="payment_method" required>
                    <option value="bank_transfer">Virement bancaire</option>
                    <option value="cash">Espèces</option>
                    <option value="check">Chèque</option>
                    <option value="mobile_money">Mobile Money</option>
                  </select>
                </div>
              </div>

              <div class="control-group">
                <label class="control-label">Période de paie :</label>
                <div class="controls">
                  <input type="text" name="payroll_period" value="<?php echo $currentMonthName; ?>" required />
                </div>
              </div>

              <div class="control-group">
                <label class="control-label">Description :</label>
                <div class="controls">
                  <textarea name="description" placeholder="Description de la transaction" required></textarea>
                </div>
              </div>

              <div class="form-actions">
                <button type="submit" name="submit_transaction" class="btn btn-success">
                  Enregistrer la transaction
                </button>
              </div>
            </form>
          </div>
        </div>
      </div>

      <!-- Tab 2: New Employee -->
      <div id="new-employee" class="tab-pane">
        <div class="widget-box">
          <div class="widget-title">
            <span class="icon"><i class="icon-user"></i></span>
            <h5>Ajouter un nouvel employé</h5>
          </div>
          <div class="widget-content nopadding">
            <form method="post" class="form-horizontal">
              <div class="control-group">
                <label class="control-label">Code employé :</label>
                <div class="controls">
                  <input type="text" name="employee_code" placeholder="Ex: EMP005" required />
                </div>
              </div>

              <div class="control-group">
                <label class="control-label">Nom complet :</label>
                <div class="controls">
                  <input type="text" name="full_name" placeholder="Prénom Nom" required />
                </div>
              </div>

              <div class="control-group">
                <label class="control-label">Poste :</label>
                <div class="controls">
                  <input type="text" name="position" placeholder="Ex: Caissier, Manager" required />
                </div>
              </div>

              <div class="control-group">
                <label class="control-label">Département :</label>
                <div class="controls">
                  <input type="text" name="department" placeholder="Ex: Ventes, Finance" />
                </div>
              </div>

              <div class="control-group">
                <label class="control-label">Date d'embauche :</label>
                <div class="controls">
                  <input type="date" name="hire_date" required />
                </div>
              </div>

              <div class="control-group">
                <label class="control-label">Salaire de base :</label>
                <div class="controls">
                  <input type="number" name="base_salary" step="0.01" min="0.01" placeholder="Ex: 120000.00" required />
                </div>
              </div>

              <div class="control-group">
                <label class="control-label">Fréquence de paie :</label>
                <div class="controls">
                  <select name="payment_frequency" required>
                    <option value="monthly">Mensuel</option>
                    <option value="biweekly">Bimensuel</option>
                    <option value="weekly">Hebdomadaire</option>
                  </select>
                </div>
              </div>

              <div class="control-group">
                <label class="control-label">Téléphone :</label>
                <div class="controls">
                  <input type="text" name="phone" placeholder="Ex: 123456789" />
                </div>
              </div>

              <div class="control-group">
                <label class="control-label">Email :</label>
                <div class="controls">
                  <input type="email" name="email" placeholder="exemple@company.com" />
                </div>
              </div>

              <div class="form-actions">
                <button type="submit" name="submit_employee" class="btn btn-success">
                  Ajouter l'employé
                </button>
              </div>
            </form>
          </div>
        </div>
      </div>

      <!-- Tab 3: Salary Advances -->
      <div id="salary-advances" class="tab-pane">
        <div class="row-fluid">
          <!-- Section: Nouvelle avance -->
          <div class="span6">
            <div class="widget-box">
              <div class="widget-title">
                <span class="icon"><i class="icon-plus"></i></span>
                <h5>Accorder une nouvelle avance</h5>
              </div>
              <div class="widget-content nopadding">
                <form method="post" class="form-horizontal">
                  <div class="control-group">
                    <label class="control-label">Employé :</label>
                    <div class="controls">
                      <select name="employee_id_advance" id="employee_advance_select" required>
                        <option value="">-- Sélectionner un employé --</option>
                        <?php
                        // Seulement les employés sans avance active
                        $sqlEligibleEmployees = "
                          SELECT e.ID, e.EmployeeCode, e.FullName, e.Position, e.BaseSalary 
                          FROM tblemployees e
                          WHERE e.Status='active'
                            AND e.ID NOT IN (
                              SELECT EmployeeID FROM tblsalaryadvances WHERE Status = 'active'
                            )
                          ORDER BY e.FullName
                        ";
                        $resEligibleEmployees = mysqli_query($con, $sqlEligibleEmployees);
                        while ($emp = mysqli_fetch_assoc($resEligibleEmployees)) {
                          $maxAdvance = $emp['BaseSalary'] * 0.8;
                          $maxDeduction = $emp['BaseSalary'] * 0.3;
                          echo "<option value='{$emp['ID']}' data-salary='{$emp['BaseSalary']}' data-max-advance='$maxAdvance' data-max-deduction='$maxDeduction'>
                                  {$emp['FullName']} - {$emp['Position']} (Salaire: " . number_format($emp['BaseSalary'], 2) . ")
                                </option>";
                        }
                        ?>
                      </select>
                    </div>
                  </div>

                  <div class="control-group">
                    <label class="control-label">Montant avance :</label>
                    <div class="controls">
                      <input type="number" name="advance_amount" id="advance_amount" step="0.01" min="0.01" required />
                      <span class="help-inline">
                        <span id="max-advance-info" style="color: #3a87ad;"></span>
                      </span>
                    </div>
                  </div>

                  <div class="control-group">
                    <label class="control-label">Déduction mensuelle :</label>
                    <div class="controls">
                      <input type="number" name="monthly_deduction" id="monthly_deduction" step="0.01" min="0.01" required />
                      <span class="help-inline">
                        <span id="max-deduction-info" style="color: #3a87ad;"></span>
                        <br><span id="months-info" style="color: #856404;"></span>
                      </span>
                    </div>
                  </div>

                  <div class="control-group">
                    <label class="control-label">Motif :</label>
                    <div class="controls">
                      <select id="reason_preset" onchange="setAdvanceReason()">
                        <option value="">-- Motif prédéfini --</option>
                        <option value="Urgence médicale">Urgence médicale</option>
                        <option value="Frais scolaires">Frais scolaires</option>
                        <option value="Urgence familiale">Urgence familiale</option>
                        <option value="Réparation véhicule">Réparation véhicule</option>
                        <option value="Dépenses exceptionnelles">Dépenses exceptionnelles</option>
                      </select>
                    </div>
                  </div>

                  <div class="control-group">
                    <label class="control-label"></label>
                    <div class="controls">
                      <textarea name="reason" id="advance_reason" placeholder="Détaillez le motif de l'avance" required></textarea>
                    </div>
                  </div>

                  <div class="form-actions">
                    <button type="submit" name="submit_advance" class="btn btn-warning">
                      Accorder l'avance
                    </button>
                    <span class="help-inline" style="margin-left: 15px;">
                      <small><strong>Règles:</strong> Max 80% salaire | Déduction max 30%</small>
                    </span>
                  </div>
                </form>
              </div>
            </div>
          </div>

          <!-- Section: Avances actives -->
          <div class="span6">
            <div class="widget-box">
              <div class="widget-title">
                <span class="icon"><i class="icon-time"></i></span>
                <h5>Avances actives (<?php echo $totalActiveAdvances; ?>)</h5>
              </div>
              <div class="widget-content">
                <?php
                // Récupérer les avances actives
                $sqlActiveAdvancesList = "
                  SELECT a.*, e.FullName, e.Position, e.BaseSalary,
                         ROUND(a.RemainingBalance / a.MonthlyDeduction, 0) AS monthsRemaining
                  FROM tblsalaryadvances a
                  JOIN tblemployees e ON e.ID = a.EmployeeID
                  WHERE a.Status = 'active'
                  ORDER BY a.AdvanceDate DESC
                ";
                $resActiveAdvancesList = mysqli_query($con, $sqlActiveAdvancesList);
                
                if (mysqli_num_rows($resActiveAdvancesList) > 0):
                  while ($advance = mysqli_fetch_assoc($resActiveAdvancesList)): 
                    $progressPercent = (($advance['Amount'] - $advance['RemainingBalance']) / $advance['Amount']) * 100;
                ?>
                <div style="border: 1px solid #ddd; padding: 10px; margin-bottom: 15px; border-radius: 4px; background-color: #fffacd;">
                  <div class="row-fluid">
                    <div class="span8">
                      <h5><?php echo $advance['FullName']; ?> 
                        <small>(<?php echo $advance['Position']; ?>)</small>
                      </h5>
                      <p><strong>💰 Avance:</strong> <?php echo number_format($advance['Amount'], 2); ?> | 
                         <strong>Reste:</strong> <span class="text-warning"><?php echo number_format($advance['RemainingBalance'], 2); ?></span><br>
                         <strong>📅 Déduction:</strong> <?php echo number_format($advance['MonthlyDeduction'], 2); ?>/mois | 
                         <strong>Durée:</strong> ~<?php echo $advance['monthsRemaining']; ?> mois</p>
                      
                      <div style="background-color: #f5f5f5; border-radius: 4px; overflow: hidden; height: 15px;">
                        <div style="background-color: #5bc0de; height: 100%; width: <?php echo $progressPercent; ?>%;"></div>
                      </div>
                      <small>Remboursé: <?php echo number_format($progressPercent, 1); ?>%</small>
                    </div>
                    
                    <div class="span4">
                      <form method="post" style="margin: 0;">
                        <input type="hidden" name="advance_id" value="<?php echo $advance['ID']; ?>" />
                        <input type="number" name="payment_amount" step="0.01" min="0.01" 
                               max="<?php echo $advance['RemainingBalance']; ?>" 
                               placeholder="Montant" required style="width: 80px; margin-bottom: 5px;" />
                        <input type="text" name="payment_reason" 
                               placeholder="Motif" required style="width: 80px; margin-bottom: 5px;" />
                        <button type="submit" name="submit_advance_payment" class="btn btn-warning btn-small">
                          Déduire
                        </button>
                      </form>
                    </div>
                  </div>
                </div>
                <?php 
                  endwhile;
                else: 
                ?>
                <div style="text-align: center; padding: 20px;">
                  <p class="text-success"><strong>✅ Aucune avance active!</strong></p>
                  <p>Tous les employés sont à jour.</p>
                </div>
                <?php endif; ?>
              </div>
            </div>
          </div>
        </div>

        <!-- Historique des avances -->
        <div class="row-fluid">
          <div class="span12">
            <div class="widget-box">
              <div class="widget-title">
                <span class="icon"><i class="icon-list"></i></span>
                <h5>Historique des avances (30 dernières)</h5>
              </div>
              <div class="widget-content nopadding">
                <table class="table table-bordered">
                  <thead>
                    <tr>
                      <th width="12%">Date</th>
                      <th width="20%">Employé</th>
                      <th width="12%">Montant</th>
                      <th width="12%">Remboursé</th>
                      <th width="12%">Reste</th>
                      <th width="8%">Statut</th>
                      <th width="24%">Motif</th>
                    </tr>
                  </thead>
                  <tbody>
                    <?php
                    $sqlAdvanceHistory = "
                      SELECT a.*, e.FullName, e.Position
                      FROM tblsalaryadvances a
                      JOIN tblemployees e ON e.ID = a.EmployeeID
                      ORDER BY a.AdvanceDate DESC
                      LIMIT 30
                    ";
                    $resAdvanceHistory = mysqli_query($con, $sqlAdvanceHistory);
                    
                    if (mysqli_num_rows($resAdvanceHistory) > 0) {
                      while ($row = mysqli_fetch_assoc($resAdvanceHistory)) {
                        $repaid = $row['Amount'] - $row['RemainingBalance'];
                        $statusClass = ($row['Status'] == 'active') ? 'text-warning' : 'text-success';
                        $statusText = ($row['Status'] == 'active') ? 'ACTIVE' : 'TERMINÉ';
                      ?>
                      <tr>
                        <td><?php echo date('d/m/Y', strtotime($row['AdvanceDate'])); ?></td>
                        <td>
                          <strong><?php echo $row['FullName']; ?></strong><br>
                          <small><?php echo $row['Position']; ?></small>
                        </td>
                        <td style="text-align: right;"><?php echo number_format($row['Amount'], 2); ?></td>
                        <td style="text-align: right;" class="text-success"><?php echo number_format($repaid, 2); ?></td>
                        <td style="text-align: right;" class="<?php echo ($row['RemainingBalance'] > 0) ? 'text-warning' : 'text-success'; ?>">
                          <?php echo number_format($row['RemainingBalance'], 2); ?>
                        </td>
                        <td class="<?php echo $statusClass; ?>">
                          <small><strong><?php echo $statusText; ?></strong></small>
                        </td>
                        <td><small><?php echo $row['Reason']; ?></small></td>
                      </tr>
                      <?php
                      }
                    } else {
                      echo '<tr><td colspan="7" style="text-align: center;">Aucun historique d\'avance</td></tr>';
                    }
                    ?>
                  </tbody>
                </table>
              </div>
            </div>
          </div>
        </div>
      </div>

      <!-- Tab 4: Current Month Transactions -->
      <div id="current-transactions" class="tab-pane">
        <div class="widget-box">
          <div class="widget-title">
            <span class="icon"><i class="icon-th"></i></span>
            <h5>Transactions de <?php echo $currentMonthName; ?></h5>
          </div>
          <div class="widget-content nopadding">
            <table class="table table-bordered">
              <thead>
                <tr>
                  <th width="5%">#</th>
                  <th width="15%">Date</th>
                  <th width="20%">Employé</th>
                  <th width="12%">Type</th>
                  <th width="12%">Montant</th>
                  <th width="12%">Méthode</th>
                  <th width="24%">Description</th>
                </tr>
              </thead>
              <tbody>
                <?php
                $sqlCurrentTransactions = "
                  SELECT st.*, e.FullName, e.Position 
                  FROM tblsalarytransactions st 
                  JOIN tblemployees e ON e.ID = st.EmployeeID 
                  WHERE DATE_FORMAT(st.TransactionDate, '%Y-%m') = '$currentMonth' 
                  ORDER BY st.TransactionDate DESC
                ";
                $resCurrentTransactions = mysqli_query($con, $sqlCurrentTransactions);
                $cnt = 1;
                
                if (mysqli_num_rows($resCurrentTransactions) > 0) {
                  while ($row = mysqli_fetch_assoc($resCurrentTransactions)) {
                    $typeClass = 'type-' . $row['TransactionType'];
                  ?>
                  <tr>
                    <td><?php echo $cnt; ?></td>
                    <td><?php echo date('d/m/Y H:i', strtotime($row['TransactionDate'])); ?></td>
                    <td>
                      <strong><?php echo $row['FullName']; ?></strong><br>
                      <small><?php echo $row['Position']; ?></small>
                    </td>
                    <td>
                      <span class="transaction-type <?php echo $typeClass; ?>">
                        <?php echo ucfirst($row['TransactionType']); ?>
                      </span>
                    </td>
                    <td style="text-align: right;">
                      <?php echo number_format($row['Amount'], 2); ?>
                    </td>
                    <td><?php echo ucfirst(str_replace('_', ' ', $row['PaymentMethod'])); ?></td>
                    <td><?php echo $row['Description']; ?></td>
                  </tr>
                  <?php
                    $cnt++;
                  }
                } else {
                  echo '<tr><td colspan="7" style="text-align: center;">Aucune transaction ce mois</td></tr>';
                }
                ?>
              </tbody>
            </table>
          </div>
        </div>
      </div>

      <!-- Tab 5: Employee List -->
      <div id="employee-list" class="tab-pane">
        <div class="widget-box">
          <div class="widget-title">
            <span class="icon"><i class="icon-group"></i></span>
            <h5>Liste des employés actifs</h5>
          </div>
          <div class="widget-content nopadding">
            <table class="table table-bordered">
              <thead>
                <tr>
                  <th width="6%">Code</th>
                  <th width="18%">Nom</th>
                  <th width="12%">Poste</th>
                  <th width="10%">Département</th>
                  <th width="8%">Embauche</th>
                  <th width="10%">Salaire</th>
                  <th width="6%">Fréquence</th>
                  <th width="12%">Avance active</th>
                  <th width="18%">Contact</th>
                </tr>
              </thead>
              <tbody>
                <?php
                // Check which employees haven't been paid this month for highlighting
                $unpaidEmployeeIds = [];
                mysqli_data_seek($resUnpaidEmployees, 0); // Reset pointer
                while ($unpaid = mysqli_fetch_assoc($resUnpaidEmployees)) {
                  $unpaidEmployeeIds[] = $unpaid['ID'];
                }
                
                $sqlAllEmployees = "
                  SELECT e.*, 
                         a.Amount as AdvanceAmount, 
                         a.RemainingBalance as AdvanceBalance,
                         a.MonthlyDeduction as AdvanceDeduction
                  FROM tblemployees e
                  LEFT JOIN tblsalaryadvances a ON a.EmployeeID = e.ID AND a.Status = 'active'
                  WHERE e.Status = 'active' 
                  ORDER BY e.FullName
                ";
                $resAllEmployees = mysqli_query($con, $sqlAllEmployees);
                
                while ($emp = mysqli_fetch_assoc($resAllEmployees)) {
                  $isUnpaid = in_array($emp['ID'], $unpaidEmployeeIds);
                  $hasAdvance = !empty($emp['AdvanceAmount']);
                  $rowClass = '';
                  if ($isUnpaid) $rowClass .= 'unpaid-employee ';
                  if ($hasAdvance) $rowClass .= 'highlight-daily ';
                ?>
                <tr class="<?php echo trim($rowClass); ?>">
                  <td><?php echo $emp['EmployeeCode']; ?></td>
                  <td>
                    <strong><?php echo $emp['FullName']; ?></strong>
                    <?php if ($isUnpaid): ?>
                      <small class="text-warning"><br>⚠ Non payé ce mois</small>
                    <?php endif; ?>
                    <?php if ($hasAdvance): ?>
                      <small class="text-info"><br>💰 Avance active</small>
                    <?php endif; ?>
                  </td>
                  <td><?php echo $emp['Position']; ?></td>
                  <td><?php echo $emp['Department']; ?></td>
                  <td><?php echo date('d/m/Y', strtotime($emp['HireDate'])); ?></td>
                  <td style="text-align: right;"><?php echo number_format($emp['BaseSalary'], 2); ?></td>
                  <td><?php echo ucfirst($emp['PaymentFrequency']); ?></td>
                  <td style="text-align: center;">
                    <?php if ($hasAdvance): ?>
                      <small>
                        <strong class="text-warning"><?php echo number_format($emp['AdvanceBalance'], 2); ?></strong><br>
                        <span style="color: #666;">sur <?php echo number_format($emp['AdvanceAmount'], 2); ?></span><br>
                        <span style="color: #666;">-<?php echo number_format($emp['AdvanceDeduction'], 2); ?>/mois</span>
                      </small>
                    <?php else: ?>
                      <span class="text-success">✓ Aucune</span>
                    <?php endif; ?>
                  </td>
                  <td>
                    <small>
                      <?php if ($emp['Phone']): ?>📞 <?php echo $emp['Phone']; ?><br><?php endif; ?>
                      <?php if ($emp['Email']): ?>✉ <?php echo $emp['Email']; ?><?php endif; ?>
                    </small>
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
  // Auto-fill salary amount when employee is selected for payment
  $('select[name="employee_id"]').on('change', function() {
    var selectedOption = $(this).find('option:selected');
    var transactionType = $('select[name="transaction_type"]').val();
    
    if (selectedOption.val() && transactionType === 'payment') {
      // Extract salary from option text (format: Name - Position (Salaire: XX.XX))
      var optionText = selectedOption.text();
      var salaryMatch = optionText.match(/Salaire: ([\d,]+\.?\d*)/);
      if (salaryMatch) {
        var salary = salaryMatch[1].replace(/,/g, '');
        $('input[name="amount"]').val(salary);
      }
    }
  });
  
  // Update amount field when transaction type changes
  $('select[name="transaction_type"]').on('change', function() {
    var transactionType = $(this).val();
    var selectedEmployee = $('select[name="employee_id"]').find('option:selected');
    
    if (transactionType === 'payment' && selectedEmployee.val()) {
      // Auto-fill with employee's base salary
      var optionText = selectedEmployee.text();
      var salaryMatch = optionText.match(/Salaire: ([\d,]+\.?\d*)/);
      if (salaryMatch) {
        var salary = salaryMatch[1].replace(/,/g, '');
        $('input[name="amount"]').val(salary);
      }
    } else {
      $('input[name="amount"]').val('');
    }
  });
  
  // Generate employee code automatically
  $('input[name="full_name"]').on('blur', function() {
    var fullName = $(this).val();
    if (fullName && !$('input[name="employee_code"]').val()) {
      // Generate code from name (first 3 letters + random number)
      var nameCode = fullName.replace(/\s+/g, '').substring(0, 3).toUpperCase();
      var randomNum = Math.floor(Math.random() * 900) + 100;
      $('input[name="employee_code"]').val('EMP' + nameCode + randomNum);
    }
  });
  
  // Auto-set hire date to today if empty
  if (!$('input[name="hire_date"]').val()) {
    var today = new Date().toISOString().split('T')[0];
    $('input[name="hire_date"]').val(today);
  }
  
  // ===== GESTION DES AVANCES =====
  
  // Mettre à jour les informations quand un employé est sélectionné pour avance
  $('#employee_advance_select').on('change', function() {
    var selectedOption = $(this).find('option:selected');
    if (selectedOption.val()) {
      var salary = parseFloat(selectedOption.data('salary'));
      var maxAdvance = parseFloat(selectedOption.data('max-advance'));
      var maxDeduction = parseFloat(selectedOption.data('max-deduction'));
      
      $('#max-advance-info').text('Maximum: ' + maxAdvance.toLocaleString('fr-FR', {minimumFractionDigits: 2}) + ' (80% du salaire)');
      $('#max-deduction-info').text('Maximum: ' + maxDeduction.toLocaleString('fr-FR', {minimumFractionDigits: 2}) + ' (30% du salaire)');
      
      // Mettre à jour les limites des champs
      $('#advance_amount').attr('max', maxAdvance);
      $('#monthly_deduction').attr('max', maxDeduction);
    } else {
      $('#max-advance-info').text('');
      $('#max-deduction-info').text('');
      $('#months-info').text('');
    }
  });
  
  // Calculer automatiquement le nombre de mois
  $('#advance_amount, #monthly_deduction').on('input', function() {
    var advanceAmount = parseFloat($('#advance_amount').val()) || 0;
    var monthlyDeduction = parseFloat($('#monthly_deduction').val()) || 0;
    
    if (advanceAmount > 0 && monthlyDeduction > 0) {
      var months = Math.ceil(advanceAmount / monthlyDeduction);
      $('#months-info').text('Durée estimée: ' + months + ' mois');
    } else {
      $('#months-info').text('');
    }
  });
  
  // Suggestion automatique de déduction (20% de l'avance ou max 30% du salaire)
  $('#advance_amount').on('blur', function() {
    var advanceAmount = parseFloat($(this).val()) || 0;
    if (advanceAmount > 0 && !$('#monthly_deduction').val()) {
      var selectedOption = $('#employee_advance_select').find('option:selected');
      if (selectedOption.val()) {
        var maxDeduction = parseFloat(selectedOption.data('max-deduction'));
        var suggestedDeduction = Math.min(
          Math.round(advanceAmount * 0.25 / 1000) * 1000, // 25% de l'avance, arrondi aux milliers
          maxDeduction
        );
        $('#monthly_deduction').val(suggestedDeduction);
        $('#monthly_deduction').trigger('input'); // Déclencher le calcul des mois
      }
    }
  });
});

// Fonction pour définir le motif d'avance prédéfini
function setAdvanceReason() {
  var preset = document.getElementById('reason_preset').value;
  if (preset !== '') {
    document.getElementById('advance_reason').value = preset;
  }
}
</script>
</body>
</html>