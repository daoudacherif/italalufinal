<?php
session_start();
error_reporting(0);
include('includes/dbconnection.php');
if (strlen($_SESSION['imsaid']==0)) {
  header('location:logout.php');
} else {

// Récupérer les paramètres
$fdate = $_POST['fromdate'] ?? date('Y-m-01'); // Premier jour du mois par défaut
$tdate = $_POST['todate'] ?? date('Y-m-d');    // Aujourd'hui par défaut
$rtype = $_POST['requesttype'] ?? 'detailed';

// Calculer la période précédente pour comparaison
$days_diff = (strtotime($tdate) - strtotime($fdate)) / (60 * 60 * 24);
$previous_start = date('Y-m-d', strtotime($fdate . " -" . ($days_diff + 1) . " days"));
$previous_end = date('Y-m-d', strtotime($fdate . " -1 day"));

?>
<!DOCTYPE html>
<html lang="fr">
<head>
<title>Système de gestion d'inventaire || Rapport de ventes détaillé</title>
<?php include_once('includes/cs.php');?>
<?php include_once('includes/responsive.php'); ?>
<style>
  .stats-box {
    background: linear-gradient(135deg, #667eea 0%, #764ba2 100%);
    color: white;
    padding: 20px;
    border-radius: 8px;
    margin-bottom: 20px;
    text-align: center;
  }
  
  .stats-box h3 {
    margin: 0;
    font-size: 2.5em;
    font-weight: bold;
  }
  
  .stats-box p {
    margin: 5px 0 0 0;
    opacity: 0.9;
  }
  
  .comparison {
    font-size: 0.9em;
    margin-top: 10px;
  }
  
  .positive { color: #2ecc71; }
  .negative { color: #e74c3c; }
  
  .chart-container {
    background: white;
    padding: 20px;
    border-radius: 8px;
    margin-bottom: 20px;
    box-shadow: 0 2px 4px rgba(0,0,0,0.1);
  }
  
  .product-rank {
    display: inline-block;
    background: #f39c12;
    color: white;
    padding: 2px 8px;
    border-radius: 12px;
    font-size: 12px;
    font-weight: bold;
  }
  
  .customer-badge {
    background: #3498db;
    color: white;
    padding: 2px 6px;
    border-radius: 4px;
    font-size: 11px;
  }
  
  @media print {
    .no-print { display: none !important; }
    .print-header { display: block; text-align: center; margin-bottom: 20px; }
    body { background: white !important; }
    .stats-box { background: #f8f9fa !important; color: black !important; }
  }
  
  .print-header { display: none; }
</style>
</head>
<body>
<div class="no-print">
  <?php include_once('includes/header.php');?>
  <?php include_once('includes/sidebar.php');?>
</div>

<div id="content">
  <div id="content-header" class="no-print">
    <div id="breadcrumb">
      <a href="dashboard.php" title="Aller à l'accueil" class="tip-bottom"><i class="icon-home"></i> Accueil</a>
      <a href="sales-report.php">Rapport de ventes</a>
      <a href="#" class="current">Analyse détaillée</a>
    </div>
    <h1>Rapport de ventes détaillé</h1>
  </div>
  
  <div class="container-fluid">
    <hr class="no-print">
    
    <!-- Période sélectionnée -->
    <div class="row-fluid no-print">
      <div class="span12">
        <div style="background: #ecf0f1; padding: 15px; border-radius: 5px; margin-bottom: 20px;">
          <h4 style="margin: 0;">
            📊 Période analysée : 
            <strong><?php echo date('d/m/Y', strtotime($fdate)); ?></strong> 
            au 
            <strong><?php echo date('d/m/Y', strtotime($tdate)); ?></strong>
            (<?php echo ($days_diff + 1); ?> jours)
          </h4>
        </div>
      </div>
    </div>

    <?php
    // ================================
    // 1. STATISTIQUES GÉNÉRALES (avec remises)
    // ================================
    
    // Ventes période actuelle (CORRIGÉ - avec remises)
    $sql_current_sales = "
      SELECT 
        COUNT(DISTINCT cust.BillingNumber) as total_orders,
        COUNT(DISTINCT cust.ID) as unique_customers,
        SUM(cust.FinalAmount) as total_revenue,
        AVG(cust.FinalAmount) as avg_order_value,
        SUM(CASE WHEN cust.ModeofPayment = 'credit' THEN cust.FinalAmount ELSE 0 END) as credit_sales,
        SUM(CASE WHEN cust.ModeofPayment != 'credit' THEN cust.FinalAmount ELSE 0 END) as cash_sales
      FROM tblcustomer cust
      JOIN tblcart c ON c.BillingId = cust.BillingNumber
      WHERE DATE(c.CartDate) BETWEEN '$fdate' AND '$tdate'
        AND c.IsCheckOut = '1'
    ";
    $res_current = mysqli_query($con, $sql_current_sales);
    $current_stats = mysqli_fetch_assoc($res_current);
    
    // Ventes période précédente pour comparaison
    $sql_previous_sales = "
      SELECT 
        COUNT(DISTINCT cust.BillingNumber) as total_orders,
        SUM(cust.FinalAmount) as total_revenue,
        AVG(cust.FinalAmount) as avg_order_value
      FROM tblcustomer cust
      JOIN tblcart c ON c.BillingId = cust.BillingNumber
      WHERE DATE(c.CartDate) BETWEEN '$previous_start' AND '$previous_end'
        AND c.IsCheckOut = '1'
    ";
    $res_previous = mysqli_query($con, $sql_previous_sales);
    $previous_stats = mysqli_fetch_assoc($res_previous);
    
    // Calculs des variations
    $revenue_change = $previous_stats['total_revenue'] > 0 ? 
      (($current_stats['total_revenue'] - $previous_stats['total_revenue']) / $previous_stats['total_revenue']) * 100 : 0;
    $orders_change = $previous_stats['total_orders'] > 0 ? 
      (($current_stats['total_orders'] - $previous_stats['total_orders']) / $previous_stats['total_orders']) * 100 : 0;
    ?>

    <!-- Statistiques principales -->
    <div class="row-fluid">
      <div class="span3">
        <div class="stats-box">
          <h3><?php echo number_format($current_stats['total_revenue'], 0); ?></h3>
          <p>Chiffre d'affaires total</p>
          <div class="comparison">
            <?php if($revenue_change > 0): ?>
              <span class="positive">↗ +<?php echo number_format($revenue_change, 1); ?>%</span>
            <?php elseif($revenue_change < 0): ?>
              <span class="negative">↘ <?php echo number_format($revenue_change, 1); ?>%</span>
            <?php else: ?>
              <span>Pas de comparaison</span>
            <?php endif; ?>
          </div>
        </div>
      </div>
      
      <div class="span3">
        <div class="stats-box">
          <h3><?php echo $current_stats['total_orders']; ?></h3>
          <p>Commandes réalisées</p>
          <div class="comparison">
            <?php if($orders_change > 0): ?>
              <span class="positive">↗ +<?php echo number_format($orders_change, 1); ?>%</span>
            <?php elseif($orders_change < 0): ?>
              <span class="negative">↘ <?php echo number_format($orders_change, 1); ?>%</span>
            <?php else: ?>
              <span>Pas de comparaison</span>
            <?php endif; ?>
          </div>
        </div>
      </div>
      
      <div class="span3">
        <div class="stats-box">
          <h3><?php echo number_format($current_stats['avg_order_value'], 0); ?></h3>
          <p>Panier moyen</p>
          <div class="comparison">
            <small>Clients uniques: <?php echo $current_stats['unique_customers']; ?></small>
          </div>
        </div>
      </div>
      
      <div class="span3">
        <div class="stats-box">
          <h3><?php echo number_format(($current_stats['cash_sales'] / $current_stats['total_revenue']) * 100, 1); ?>%</h3>
          <p>Ventes au comptant</p>
          <div class="comparison">
            <small>Crédit: <?php echo number_format($current_stats['credit_sales'], 0); ?></small>
          </div>
        </div>
      </div>
    </div>

    <?php
    // ================================
    // 2. TOP PRODUITS VENDUS (avec remises)
    // ================================
    $sql_top_products = "
      SELECT 
        p.ProductName,
        p.ModelNumber,
        cat.CategoryName,
        SUM(cart.ProductQty) as total_qty,
        COUNT(DISTINCT cust.BillingNumber) as times_sold,
        SUM(cart.ProductQty * cart.Price) as gross_revenue,
        SUM((cart.ProductQty * cart.Price) * (cust.FinalAmount / (
          SELECT SUM(c2.ProductQty * c2.Price) 
          FROM tblcart c2 
          WHERE c2.BillingId = cust.BillingNumber
        ))) as net_revenue
      FROM tblproducts p
      JOIN tblcart cart ON p.ID = cart.ProductId
      JOIN tblcustomer cust ON cart.BillingId = cust.BillingNumber
      LEFT JOIN tblcategory cat ON p.CatID = cat.ID
      WHERE DATE(cart.CartDate) BETWEEN '$fdate' AND '$tdate'
        AND cart.IsCheckOut = '1'
      GROUP BY p.ID
      ORDER BY net_revenue DESC
      LIMIT 10
    ";
    $res_top_products = mysqli_query($con, $sql_top_products);
    ?>

    <!-- Top produits -->
    <div class="row-fluid">
      <div class="span12">
        <div class="chart-container">
          <h4><i class="icon-star"></i> Top 10 Produits (par chiffre d'affaires net)</h4>
          <table class="table table-striped">
            <thead>
              <tr>
                <th width="5%">Rang</th>
                <th width="25%">Produit</th>
                <th width="15%">Catégorie</th>
                <th width="10%">Qté vendue</th>
                <th width="10%">Nb ventes</th>
                <th width="15%">CA brut</th>
                <th width="15%">CA net (après remises)</th>
                <th width="5%">Performance</th>
              </tr>
            </thead>
            <tbody>
              <?php 
              $rank = 1;
              while($product = mysqli_fetch_assoc($res_top_products)): 
                $discount_rate = (($product['gross_revenue'] - $product['net_revenue']) / $product['gross_revenue']) * 100;
              ?>
              <tr>
                <td><span class="product-rank">#<?php echo $rank; ?></span></td>
                <td>
                  <strong><?php echo htmlspecialchars($product['ProductName']); ?></strong><br>
                  <small><?php echo htmlspecialchars($product['ModelNumber']); ?></small>
                </td>
                <td><?php echo htmlspecialchars($product['CategoryName']); ?></td>
                <td><?php echo $product['total_qty']; ?></td>
                <td><?php echo $product['times_sold']; ?> fois</td>
                <td><?php echo number_format($product['gross_revenue'], 0); ?></td>
                <td>
                  <strong><?php echo number_format($product['net_revenue'], 0); ?></strong>
                  <?php if($discount_rate > 0): ?>
                    <br><small class="text-warning">-<?php echo number_format($discount_rate, 1); ?>% remise</small>
                  <?php endif; ?>
                </td>
                <td>
                  <?php if($rank <= 3): ?>
                    <span style="color: gold;">⭐</span>
                  <?php else: ?>
                    <span style="color: silver;">📊</span>
                  <?php endif; ?>
                </td>
              </tr>
              <?php 
              $rank++;
              endwhile; 
              ?>
            </tbody>
          </table>
        </div>
      </div>
    </div>

    <?php
    // ================================
    // 3. ANALYSE PAR CLIENTS
    // ================================
    $sql_top_customers = "
      SELECT 
        cust.CustomerName,
        cust.MobileNumber,
        COUNT(DISTINCT cust.BillingNumber) as total_orders,
        SUM(cust.FinalAmount) as total_spent,
        AVG(cust.FinalAmount) as avg_order,
        MAX(c.CartDate) as last_purchase
      FROM tblcustomer cust
      JOIN tblcart c ON c.BillingId = cust.BillingNumber
      WHERE DATE(c.CartDate) BETWEEN '$fdate' AND '$tdate'
        AND c.IsCheckOut = '1'
      GROUP BY cust.CustomerName, cust.MobileNumber
      ORDER BY total_spent DESC
      LIMIT 10
    ";
    $res_top_customers = mysqli_query($con, $sql_top_customers);
    ?>

    <!-- Top clients -->
    <div class="row-fluid">
      <div class="span12">
        <div class="chart-container">
          <h4><i class="icon-user"></i> Top 10 Clients (par valeur)</h4>
          <table class="table table-striped">
            <thead>
              <tr>
                <th width="5%">Rang</th>
                <th width="25%">Client</th>
                <th width="15%">Téléphone</th>
                <th width="10%">Nb commandes</th>
                <th width="15%">Total dépensé</th>
                <th width="15%">Panier moyen</th>
                <th width="15%">Dernier achat</th>
              </tr>
            </thead>
            <tbody>
              <?php 
              $rank = 1;
              while($customer = mysqli_fetch_assoc($res_top_customers)): 
              ?>
              <tr>
                <td><span class="customer-badge">#<?php echo $rank; ?></span></td>
                <td><strong><?php echo htmlspecialchars($customer['CustomerName']); ?></strong></td>
                <td><?php echo htmlspecialchars($customer['MobileNumber']); ?></td>
                <td><?php echo $customer['total_orders']; ?></td>
                <td><strong><?php echo number_format($customer['total_spent'], 0); ?></strong></td>
                <td><?php echo number_format($customer['avg_order'], 0); ?></td>
                <td><?php echo date('d/m/Y', strtotime($customer['last_purchase'])); ?></td>
              </tr>
              <?php 
              $rank++;
              endwhile; 
              ?>
            </tbody>
          </table>
        </div>
      </div>
    </div>

    <?php
    // ================================
    // 4. ÉVOLUTION QUOTIDIENNE
    // ================================
    $sql_daily_evolution = "
      SELECT 
        DATE(c.CartDate) as sale_date,
        COUNT(DISTINCT cust.BillingNumber) as daily_orders,
        SUM(cust.FinalAmount) as daily_revenue,
        COUNT(DISTINCT cust.ID) as daily_customers
      FROM tblcustomer cust
      JOIN tblcart c ON c.BillingId = cust.BillingNumber
      WHERE DATE(c.CartDate) BETWEEN '$fdate' AND '$tdate'
        AND c.IsCheckOut = '1'
      GROUP BY DATE(c.CartDate)
      ORDER BY sale_date ASC
    ";
    $res_daily = mysqli_query($con, $sql_daily_evolution);
    ?>

    <!-- Évolution quotidienne -->
    <div class="row-fluid">
      <div class="span12">
        <div class="chart-container">
          <h4><i class="icon-line-chart"></i> Évolution quotidienne</h4>
          <table class="table table-striped">
            <thead>
              <tr>
                <th width="20%">Date</th>
                <th width="15%">Commandes</th>
                <th width="20%">Chiffre d'affaires</th>
                <th width="15%">Clients uniques</th>
                <th width="15%">Panier moyen</th>
                <th width="15%">Tendance</th>
              </tr>
            </thead>
            <tbody>
              <?php 
              $previous_revenue = 0;
              while($daily = mysqli_fetch_assoc($res_daily)): 
                $avg_daily = $daily['daily_orders'] > 0 ? $daily['daily_revenue'] / $daily['daily_orders'] : 0;
                $trend = '';
                if($previous_revenue > 0) {
                  $change = (($daily['daily_revenue'] - $previous_revenue) / $previous_revenue) * 100;
                  if($change > 0) {
                    $trend = '<span class="positive">↗ +' . number_format($change, 1) . '%</span>';
                  } elseif($change < 0) {
                    $trend = '<span class="negative">↘ ' . number_format($change, 1) . '%</span>';
                  } else {
                    $trend = '<span>→ 0%</span>';
                  }
                }
                $previous_revenue = $daily['daily_revenue'];
              ?>
              <tr>
                <td><strong><?php echo date('d/m/Y (D)', strtotime($daily['sale_date'])); ?></strong></td>
                <td><?php echo $daily['daily_orders']; ?></td>
                <td><strong><?php echo number_format($daily['daily_revenue'], 0); ?></strong></td>
                <td><?php echo $daily['daily_customers']; ?></td>
                <td><?php echo number_format($avg_daily, 0); ?></td>
                <td><?php echo $trend; ?></td>
              </tr>
              <?php endwhile; ?>
            </tbody>
          </table>
        </div>
      </div>
    </div>

    <?php
    // ================================
    // 5. ANALYSE PAR CATÉGORIE
    // ================================
    $sql_category_analysis = "
      SELECT 
        COALESCE(cat.CategoryName, 'Sans catégorie') as category_name,
        COUNT(DISTINCT p.ID) as products_count,
        SUM(cart.ProductQty) as total_qty_sold,
        SUM(cart.ProductQty * cart.Price) as gross_revenue,
        SUM((cart.ProductQty * cart.Price) * (cust.FinalAmount / (
          SELECT SUM(c2.ProductQty * c2.Price) 
          FROM tblcart c2 
          WHERE c2.BillingId = cust.BillingNumber
        ))) as net_revenue,
        COUNT(DISTINCT cust.BillingNumber) as orders_count
      FROM tblproducts p
      LEFT JOIN tblcategory cat ON p.CatID = cat.ID
      JOIN tblcart cart ON p.ID = cart.ProductId
      JOIN tblcustomer cust ON cart.BillingId = cust.BillingNumber
      WHERE DATE(cart.CartDate) BETWEEN '$fdate' AND '$tdate'
        AND cart.IsCheckOut = '1'
      GROUP BY cat.ID
      ORDER BY net_revenue DESC
    ";
    $res_categories = mysqli_query($con, $sql_category_analysis);
    ?>

    <!-- Analyse par catégorie -->
    <div class="row-fluid">
      <div class="span12">
        <div class="chart-container">
          <h4><i class="icon-tags"></i> Performance par catégorie</h4>
          <table class="table table-striped">
            <thead>
              <tr>
                <th width="25%">Catégorie</th>
                <th width="10%">Produits</th>
                <th width="15%">Quantité vendue</th>
                <th width="10%">Commandes</th>
                <th width="15%">CA brut</th>
                <th width="15%">CA net</th>
                <th width="10%">Part du CA</th>
              </tr>
            </thead>
            <tbody>
              <?php 
              while($category = mysqli_fetch_assoc($res_categories)): 
                $ca_share = ($category['net_revenue'] / $current_stats['total_revenue']) * 100;
              ?>
              <tr>
                <td><strong><?php echo htmlspecialchars($category['category_name']); ?></strong></td>
                <td><?php echo $category['products_count']; ?></td>
                <td><?php echo $category['total_qty_sold']; ?></td>
                <td><?php echo $category['orders_count']; ?></td>
                <td><?php echo number_format($category['gross_revenue'], 0); ?></td>
                <td><strong><?php echo number_format($category['net_revenue'], 0); ?></strong></td>
                <td>
                  <strong><?php echo number_format($ca_share, 1); ?>%</strong>
                  <div style="background: #3498db; height: 4px; width: <?php echo min(100, $ca_share * 2); ?>%; margin-top: 2px;"></div>
                </td>
              </tr>
              <?php endwhile; ?>
            </tbody>
          </table>
        </div>
      </div>
    </div>

    <!-- Actions et exports -->
    <div class="row-fluid no-print" style="margin-top: 30px;">
      <div class="span12 text-center">
        <button class="btn btn-primary" onclick="window.print();">
          <i class="icon-print"></i> Imprimer le rapport
        </button>
        <a href="sales-report.php" class="btn">
          <i class="icon-arrow-left"></i> Nouvelle analyse
        </a>
        <a href="report.php" class="btn btn-info">
          <i class="icon-chart-bar"></i> Rapport financier
        </a>
      </div>
    </div>

    <!-- En-tête pour l'impression -->
    <div class="print-header">
      <h2>Système de Gestion d'Inventaire</h2>
      <h3>Rapport de ventes détaillé</h3>
      <p>Période du <?php echo date('d/m/Y', strtotime($fdate)); ?> au <?php echo date('d/m/Y', strtotime($tdate)); ?></p>
      <p>Généré le <?php echo date('d/m/Y à H:i'); ?></p>
    </div>

  </div>
</div>

<div class="no-print">
  <?php include_once('includes/footer.php');?>
</div>

<script src="js/jquery.min.js"></script> 
<script src="js/jquery.ui.custom.js"></script> a
<script src="js/bootstrap.min.js"></script> 
<script src="js/jquery.uniform.js"></script> 
<script src="js/select2.min.js"></script> 
<script src="js/jquery.dataTables.min.js"></script> 
<script src="js/matrix.js"></script> 
<script src="js/matrix.tables.js"></script>
</body>
</html>
<?php } ?>