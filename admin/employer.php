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
      <strong>Information:</strong> Cette page permet de gérer les employés et leurs salaires. 
      Le calcul du solde salarial se base sur les obligations mensuelles par rapport aux paiements effectués.
      Les employés actifs: <?php echo $activeEmployees; ?> | 
      Obligations mensuelles: <?php echo number_format($monthlyObligations, 2); ?> | 
      Avances en cours: <?php echo number_format($outstandingAdvances, 2); ?>
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
                  <td style="text-align: right;" class="text-warning"><?php echo number_format($outstandingAdvances, 2); ?></td>
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

      <!-- Tab 3: Current Month Transactions -->
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

      <!-- Tab 4: Employee List -->
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
                  <th width="8%">Code</th>
                  <th width="20%">Nom</th>
                  <th width="15%">Poste</th>
                  <th width="12%">Département</th>
                  <th width="10%">Embauche</th>
                  <th width="12%">Salaire</th>
                  <th width="8%">Fréquence</th>
                  <th width="15%">Contact</th>
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
                  SELECT * FROM tblemployees 
                  WHERE Status = 'active' 
                  ORDER BY FullName
                ";
                $resAllEmployees = mysqli_query($con, $sqlAllEmployees);
                
                while ($emp = mysqli_fetch_assoc($resAllEmployees)) {
                  $isUnpaid = in_array($emp['ID'], $unpaidEmployeeIds);
                  $rowClass = $isUnpaid ? 'unpaid-employee' : '';
                ?>
                <tr class="<?php echo $rowClass; ?>">
                  <td><?php echo $emp['EmployeeCode']; ?></td>
                  <td>
                    <strong><?php echo $emp['FullName']; ?></strong>
                    <?php if ($isUnpaid): ?>
                      <small class="text-warning"><br>⚠ Non payé ce mois</small>
                    <?php endif; ?>
                  </td>
                  <td><?php echo $emp['Position']; ?></td>
                  <td><?php echo $emp['Department']; ?></td>
                  <td><?php echo date('d/m/Y', strtotime($emp['HireDate'])); ?></td>
                  <td style="text-align: right;"><?php echo number_format($emp['BaseSalary'], 2); ?></td>
                  <td><?php echo ucfirst($emp['PaymentFrequency']); ?></td>
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
});
</script>
</body>
</html>