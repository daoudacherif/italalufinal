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
// 1) GESTION DES FILTRES DE DATE
// ---------------------------------------------------------------------

// Dates par défaut (mois actuel)
$currentMonth = date('Y-m');
$currentYear = date('Y');

// Récupérer les filtres depuis GET/POST
$filterMonth = isset($_GET['month']) ? $_GET['month'] : $currentMonth;
$filterYear = isset($_GET['year']) ? $_GET['year'] : $currentYear;
$filterType = isset($_GET['filter_type']) ? $_GET['filter_type'] : 'month'; // 'month', 'year', 'all'

// Construire la condition WHERE selon le filtre
$whereCondition = "WHERE TransType = 'OUT'";
$periodTitle = "";

switch ($filterType) {
    case 'month':
        $whereCondition .= " AND DATE_FORMAT(TransDate, '%Y-%m') = '$filterMonth'";
        $periodTitle = date('F Y', strtotime($filterMonth . '-01'));
        break;
    case 'year':
        $whereCondition .= " AND YEAR(TransDate) = '$filterYear'";
        $periodTitle = "Année $filterYear";
        break;
    case 'all':
        $periodTitle = "Toutes les périodes";
        break;
}

// ---------------------------------------------------------------------
// 2) CALCULS DES TOTAUX ET STATISTIQUES
// ---------------------------------------------------------------------

// 2.1 Total des dépenses pour la période sélectionnée
$sqlTotalExpenses = "
    SELECT 
        COUNT(*) AS totalTransactions,
        COALESCE(SUM(Amount), 0) AS totalAmount,
        MIN(TransDate) AS firstTransaction,
        MAX(TransDate) AS lastTransaction
    FROM tblcashtransactions 
    $whereCondition
";
$resTotalExpenses = mysqli_query($con, $sqlTotalExpenses);
$rowTotalExpenses = mysqli_fetch_assoc($resTotalExpenses);
$totalExpenses = floatval($rowTotalExpenses['totalAmount']);
$totalTransactions = intval($rowTotalExpenses['totalTransactions']);
$firstTransaction = $rowTotalExpenses['firstTransaction'];
$lastTransaction = $rowTotalExpenses['lastTransaction'];

// 2.2 Dépenses par catégorie (analyse des commentaires)
$sqlExpensesByCategory = "
    SELECT 
        CASE 
            WHEN Comments LIKE '%fournisseur%' OR Comments LIKE '%achat%' OR Comments LIKE '%stock%' THEN 'Achats/Fournisseurs'
            WHEN Comments LIKE '%salaire%' OR Comments LIKE '%employé%' OR Comments LIKE '%paie%' THEN 'Salaires'
            WHEN Comments LIKE '%frais%' OR Comments LIKE '%charge%' THEN 'Frais généraux'
            WHEN Comments LIKE '%réparation%' OR Comments LIKE '%maintenance%' THEN 'Maintenance'
            WHEN Comments LIKE '%transport%' OR Comments LIKE '%carburant%' THEN 'Transport'
            WHEN Comments LIKE '%électricité%' OR Comments LIKE '%eau%' OR Comments LIKE '%loyer%' THEN 'Charges locatives'
            ELSE 'Autres dépenses'
        END AS category,
        COUNT(*) AS transactionCount,
        SUM(Amount) AS categoryTotal
    FROM tblcashtransactions 
    $whereCondition
    GROUP BY category
    ORDER BY categoryTotal DESC
";
$resExpensesByCategory = mysqli_query($con, $sqlExpensesByCategory);

// 2.3 Comparaison avec le mois précédent (si on filtre par mois)
$previousPeriodTotal = 0;
$comparisonText = "";
if ($filterType == 'month') {
    $previousMonth = date('Y-m', strtotime($filterMonth . '-01 -1 month'));
    $sqlPreviousMonth = "
        SELECT COALESCE(SUM(Amount), 0) AS previousTotal
        FROM tblcashtransactions 
        WHERE TransType = 'OUT' AND DATE_FORMAT(TransDate, '%Y-%m') = '$previousMonth'
    ";
    $resPreviousMonth = mysqli_query($con, $sqlPreviousMonth);
    $rowPreviousMonth = mysqli_fetch_assoc($resPreviousMonth);
    $previousPeriodTotal = floatval($rowPreviousMonth['previousTotal']);
    
    if ($previousPeriodTotal > 0) {
        $percentageChange = (($totalExpenses - $previousPeriodTotal) / $previousPeriodTotal) * 100;
        $changeDirection = $percentageChange >= 0 ? 'augmentation' : 'diminution';
        $changeClass = $percentageChange >= 0 ? 'text-error' : 'text-success';
        $comparisonText = sprintf(
            "<span class='%s'>%s de %.1f%% par rapport à %s</span>",
            $changeClass,
            ucfirst($changeDirection),
            abs($percentageChange),
            date('F Y', strtotime($previousMonth . '-01'))
        );
    }
}

// 2.4 Top 5 des plus grosses dépenses de la période
$sqlTopExpenses = "
    SELECT TransDate, Amount, Comments
    FROM tblcashtransactions 
    $whereCondition
    ORDER BY Amount DESC
    LIMIT 5
";
$resTopExpenses = mysqli_query($con, $sqlTopExpenses);

// 2.5 Évolution mensuelle (pour graphique simple)
$sqlMonthlyTrend = "
    SELECT 
        DATE_FORMAT(TransDate, '%Y-%m') AS month,
        SUM(Amount) AS monthlyTotal,
        COUNT(*) AS monthlyCount
    FROM tblcashtransactions 
    WHERE TransType = 'OUT'
    GROUP BY month
    ORDER BY month DESC
    LIMIT 12
";
$resMonthlyTrend = mysqli_query($con, $sqlMonthlyTrend);
$monthlyData = [];
while ($row = mysqli_fetch_assoc($resMonthlyTrend)) {
    $monthlyData[] = $row;
}
$monthlyData = array_reverse($monthlyData); // Pour afficher du plus ancien au plus récent

?>
<!DOCTYPE html>
<html lang="fr">
<head>
  <title>Gestion d'inventaire | Rapport des dépenses</title>
  <?php include_once('includes/cs.php'); ?>
  <?php include_once('includes/responsive.php'); ?>
  <style>
    .balance-box {
      border: 1px solid #ccc;
      padding: 15px;
      margin-bottom: 20px;
      background-color: #f9f9f9;
    }
    .expense-summary {
      background-color: #fff2cc;
      border-left: 4px solid #ffc107;
      padding: 15px;
      margin-bottom: 20px;
    }
    .text-success { color: #468847; }
    .text-error { color: #b94a48; }
    .text-warning { color: #c09853; }
    .text-info { color: #3a87ad; }
    .highlight-total {
      background-color: #f8d7da; 
      font-weight: bold;
      font-size: 1.2em;
    }
    
    .category-item {
      display: flex;
      justify-content: space-between;
      align-items: center;
      padding: 8px 12px;
      margin-bottom: 5px;
      background-color: #f8f9fa;
      border-radius: 4px;
      border-left: 3px solid #dc3545;
    }
    
    .filter-box {
      background-color: #e9ecef;
      padding: 15px;
      border-radius: 5px;
      margin-bottom: 20px;
    }
    
    .stat-card {
      text-align: center;
      padding: 20px;
      background-color: white;
      border: 1px solid #dee2e6;
      border-radius: 5px;
      margin-bottom: 15px;
    }
    
    .stat-number {
      font-size: 1.8em;
      font-weight: bold;
      margin-bottom: 5px;
    }
    
    .progress-bar {
      background-color: #f5f5f5;
      border-radius: 4px;
      overflow: hidden;
      height: 20px;
      margin: 5px 0;
    }
    
    .progress-fill {
      background-color: #dc3545;
      height: 100%;
      transition: width 0.3s ease;
    }
    
    .alert-info {
      background-color: #d9edf7;
      border-color: #bce8f1;
      color: #3a87ad;
      padding: 8px;
      margin-bottom: 15px;
      border-radius: 4px;
    }
    
    .expense-row {
      background-color: #fff5f5;
    }
    
    .big-expense {
      background-color: #f8d7da;
      font-weight: bold;
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
      <a href="transact.php" class="tip-bottom">Transactions</a>
      <a href="expense-report.php" class="current">Rapport des dépenses</a>
    </div>
    <h1>📊 Rapport des dépenses - <?php echo $periodTitle; ?></h1>
  </div>

  <div class="container-fluid">
    <hr>
    
    <div class="alert-info">
      <strong>💡 Information:</strong> Ce rapport analyse toutes vos sorties d'argent (transactions OUT) pour vous aider à contrôler vos dépenses. 
      Utilisez les filtres pour analyser différentes périodes.
    </div>

    <!-- Filtres -->
    <div class="row-fluid">
      <div class="span12">
        <div class="filter-box">
          <h4>🔍 Filtres d'analyse</h4>
          <form method="GET" class="form-inline">
            <div class="control-group" style="display: inline-block; margin-right: 15px;">
              <label class="control-label">Type de période :</label>
              <select name="filter_type" id="filter_type" onchange="toggleFilters()">
                <option value="month" <?php echo ($filterType == 'month') ? 'selected' : ''; ?>>Par mois</option>
                <option value="year" <?php echo ($filterType == 'year') ? 'selected' : ''; ?>>Par année</option>
                <option value="all" <?php echo ($filterType == 'all') ? 'selected' : ''; ?>>Toutes les périodes</option>
              </select>
            </div>
            
            <div id="month_filter" class="control-group" style="display: inline-block; margin-right: 15px;">
              <label class="control-label">Mois :</label>
              <input type="month" name="month" value="<?php echo $filterMonth; ?>" />
            </div>
            
            <div id="year_filter" class="control-group" style="display: inline-block; margin-right: 15px;">
              <label class="control-label">Année :</label>
              <select name="year">
                <?php
                $currentYearInt = intval(date('Y'));
                for ($y = $currentYearInt; $y >= $currentYearInt - 5; $y--) {
                    $selected = ($y == $filterYear) ? 'selected' : '';
                    echo "<option value='$y' $selected>$y</option>";
                }
                ?>
              </select>
            </div>
            
            <button type="submit" class="btn btn-primary">Filtrer</button>
            <a href="expense-report.php" class="btn btn-default">Reset</a>
          </form>
        </div>
      </div>
    </div>

    <!-- Résumé des dépenses -->
    <div class="row-fluid">
      <div class="span12">
        <div class="expense-summary">
          <div class="row-fluid">
            <div class="span8">
              <h3>💸 Total des dépenses (<?php echo $periodTitle; ?>): 
                <span class="text-error highlight-total">
                  <?php echo number_format($totalExpenses, 2); ?> €
                </span>
              </h3>
              
              <div class="row-fluid" style="margin-top: 15px;">
                <div class="span4">
                  <div class="stat-card">
                    <div class="stat-number text-error"><?php echo $totalTransactions; ?></div>
                    <div>Transactions</div>
                  </div>
                </div>
                <div class="span4">
                  <div class="stat-card">
                    <div class="stat-number text-info">
                      <?php echo $totalTransactions > 0 ? number_format($totalExpenses / $totalTransactions, 2) : '0.00'; ?>
                    </div>
                    <div>Dépense moyenne</div>
                  </div>
                </div>
                <div class="span4">
                  <div class="stat-card">
                    <div class="stat-number text-warning">
                      <?php echo $previousPeriodTotal > 0 ? number_format($previousPeriodTotal, 2) : 'N/A'; ?>
                    </div>
                    <div>Période précédente</div>
                  </div>
                </div>
              </div>
              
              <?php if ($comparisonText): ?>
                <div style="margin-top: 10px; font-size: 1.1em;">
                  <strong>📈 Évolution:</strong> <?php echo $comparisonText; ?>
                </div>
              <?php endif; ?>
            </div>
            
            <div class="span4">
              <div style="padding: 20px; background-color: white; border-radius: 5px;">
                <h5>📅 Informations période:</h5>
                <?php if ($firstTransaction && $lastTransaction): ?>
                  <p><strong>Première transaction:</strong><br><?php echo date('d/m/Y', strtotime($firstTransaction)); ?></p>
                  <p><strong>Dernière transaction:</strong><br><?php echo date('d/m/Y', strtotime($lastTransaction)); ?></p>
                <?php endif; ?>
                
                <hr>
                <h5>🎯 Actions rapides:</h5>
                <p><small>
                  • <a href="transact.php">Ajouter une transaction</a><br>
                  • <a href="?filter_type=month&month=<?php echo date('Y-m', strtotime('-1 month')); ?>">Mois précédent</a><br>
                  • <a href="?filter_type=year&year=<?php echo date('Y') - 1; ?>">Année précédente</a>
                </small></p>
              </div>
            </div>
          </div>
        </div>
      </div>
    </div>

    <!-- Répartition par catégories et top dépenses -->
    <div class="row-fluid">
      <div class="span6">
        <div class="widget-box">
          <div class="widget-title">
            <span class="icon"><i class="icon-chart"></i></span>
            <h5>Répartition par catégories</h5>
          </div>
          <div class="widget-content">
            <?php if (mysqli_num_rows($resExpensesByCategory) > 0): ?>
              <?php while ($category = mysqli_fetch_assoc($resExpensesByCategory)): 
                $percentage = $totalExpenses > 0 ? ($category['categoryTotal'] / $totalExpenses) * 100 : 0;
              ?>
              <div class="category-item">
                <div>
                  <strong><?php echo $category['category']; ?></strong><br>
                  <small><?php echo $category['transactionCount']; ?> transaction(s)</small>
                </div>
                <div style="text-align: right;">
                  <strong><?php echo number_format($category['categoryTotal'], 2); ?></strong><br>
                  <small><?php echo number_format($percentage, 1); ?>%</small>
                </div>
              </div>
              <div class="progress-bar">
                <div class="progress-fill" style="width: <?php echo $percentage; ?>%;"></div>
              </div>
              <?php endwhile; ?>
            <?php else: ?>
              <p class="text-info">Aucune dépense pour cette période.</p>
            <?php endif; ?>
          </div>
        </div>
      </div>
      
      <div class="span6">
        <div class="widget-box">
          <div class="widget-title">
            <span class="icon"><i class="icon-star"></i></span>
            <h5>Top 5 des plus grosses dépenses</h5>
          </div>
          <div class="widget-content">
            <?php if (mysqli_num_rows($resTopExpenses) > 0): ?>
              <table class="table table-striped">
                <thead>
                  <tr>
                    <th>Date</th>
                    <th>Montant</th>
                    <th>Description</th>
                  </tr>
                </thead>
                <tbody>
                  <?php while ($expense = mysqli_fetch_assoc($resTopExpenses)): ?>
                  <tr class="<?php echo ($expense['Amount'] > ($totalExpenses * 0.1)) ? 'big-expense' : ''; ?>">
                    <td><?php echo date('d/m/Y', strtotime($expense['TransDate'])); ?></td>
                    <td style="text-align: right;"><strong><?php echo number_format($expense['Amount'], 2); ?></strong></td>
                    <td><?php echo $expense['Comments']; ?></td>
                  </tr>
                  <?php endwhile; ?>
                </tbody>
              </table>
            <?php else: ?>
              <p class="text-info">Aucune dépense pour cette période.</p>
            <?php endif; ?>
          </div>
        </div>
      </div>
    </div>

    <!-- Évolution mensuelle -->
    <?php if (count($monthlyData) > 1): ?>
    <div class="row-fluid">
      <div class="span12">
        <div class="widget-box">
          <div class="widget-title">
            <span class="icon"><i class="icon-graph"></i></span>
            <h5>Évolution des dépenses (12 derniers mois)</h5>
          </div>
          <div class="widget-content">
            <table class="table table-bordered">
              <thead>
                <tr>
                  <th>Mois</th>
                  <th>Montant</th>
                  <th>Transactions</th>
                  <th>Évolution</th>
                </tr>
              </thead>
              <tbody>
                <?php 
                $previousAmount = 0;
                foreach ($monthlyData as $month): 
                  $evolutionPercent = 0;
                  $evolutionClass = '';
                  if ($previousAmount > 0) {
                    $evolutionPercent = (($month['monthlyTotal'] - $previousAmount) / $previousAmount) * 100;
                    $evolutionClass = $evolutionPercent >= 0 ? 'text-error' : 'text-success';
                  }
                ?>
                <tr>
                  <td><?php echo date('F Y', strtotime($month['month'] . '-01')); ?></td>
                  <td style="text-align: right;"><strong><?php echo number_format($month['monthlyTotal'], 2); ?></strong></td>
                  <td style="text-align: center;"><?php echo $month['monthlyCount']; ?></td>
                  <td style="text-align: center;" class="<?php echo $evolutionClass; ?>">
                    <?php 
                    if ($previousAmount > 0) {
                      echo ($evolutionPercent >= 0 ? '+' : '') . number_format($evolutionPercent, 1) . '%';
                    } else {
                      echo '-';
                    }
                    ?>
                  </td>
                </tr>
                <?php 
                $previousAmount = $month['monthlyTotal'];
                endforeach; 
                ?>
              </tbody>
            </table>
          </div>
        </div>
      </div>
    </div>
    <?php endif; ?>

    <!-- Liste détaillée des transactions -->
    <div class="row-fluid">
      <div class="span12">
        <div class="widget-box">
          <div class="widget-title">
            <span class="icon"><i class="icon-th"></i></span>
            <h5>Détail des transactions de sortie - <?php echo $periodTitle; ?></h5>
          </div>
          <div class="widget-content nopadding">
            <table class="table table-bordered">
              <thead>
                <tr>
                  <th width="5%">#</th>
                  <th width="15%">Date/Heure</th>
                  <th width="15%">Montant</th>
                  <th width="15%">Solde après</th>
                  <th width="50%">Description</th>
                </tr>
              </thead>
              <tbody>
                <?php
                // Récupérer toutes les transactions OUT pour la période
                $sqlAllTransactions = "
                    SELECT * FROM tblcashtransactions 
                    $whereCondition
                    ORDER BY TransDate DESC, ID DESC
                ";
                $resAllTransactions = mysqli_query($con, $sqlAllTransactions);
                $cnt = 1;
                
                if (mysqli_num_rows($resAllTransactions) > 0) {
                  while ($row = mysqli_fetch_assoc($resAllTransactions)) {
                    $amount = floatval($row['Amount']);
                    $balance = floatval($row['BalanceAfter']);
                    $rowClass = ($amount > 50000) ? 'big-expense' : 'expense-row';
                  ?>
                  <tr class="<?php echo $rowClass; ?>">
                    <td><?php echo $cnt; ?></td>
                    <td><?php echo date('d/m/Y H:i', strtotime($row['TransDate'])); ?></td>
                    <td style="text-align: right;">
                      <strong class="text-error">-<?php echo number_format($amount, 2); ?></strong>
                    </td>
                    <td style="text-align: right;">
                      <?php echo number_format($balance, 2); ?>
                    </td>
                    <td><?php echo $row['Comments']; ?></td>
                  </tr>
                  <?php
                    $cnt++;
                  }
                } else {
                  echo '<tr><td colspan="5" style="text-align: center; color: #468847;"><strong>✅ Aucune dépense pour cette période - C\'est excellent!</strong></td></tr>';
                }
                ?>
              </tbody>
              <?php if ($totalExpenses > 0): ?>
              <tfoot>
                <tr class="highlight-total">
                  <th colspan="2">TOTAL DES DÉPENSES</th>
                  <th style="text-align: right;" class="text-error">
                    -<?php echo number_format($totalExpenses, 2); ?>
                  </th>
                  <th colspan="2"><?php echo $totalTransactions; ?> transaction(s)</th>
                </tr>
              </tfoot>
              <?php endif; ?>
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
  // Initialiser l'affichage des filtres
  toggleFilters();
});

function toggleFilters() {
  var filterType = document.getElementById('filter_type').value;
  var monthFilter = document.getElementById('month_filter');
  var yearFilter = document.getElementById('year_filter');
  
  // Cacher tous les filtres d'abord
  monthFilter.style.display = 'none';
  yearFilter.style.display = 'none';
  
  // Afficher le filtre approprié
  if (filterType === 'month') {
    monthFilter.style.display = 'inline-block';
  } else if (filterType === 'year') {
    yearFilter.style.display = 'inline-block';
  }
}
</script>
</body>
</html>