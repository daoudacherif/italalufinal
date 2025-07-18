<?php
session_start();
error_reporting(E_ALL);
include('includes/dbconnection.php');
use Dompdf\Dompdf;

// Vérifier si l'admin est connecté
if (empty($_SESSION['imsaid'])) {
    header('location:logout.php');
    exit;
}
// Inclure dompdf (si nécessaire)
if (file_exists('dompdf/autoload.inc.php')) {
    require_once 'dompdf/autoload.inc.php';
    $dompdf_available = true;
    $dompdf_available = true;
} else {
    $dompdf_available = false;
}

// --- 1) Récupérer dates de filtrage ---
$start = isset($_GET['start']) ? $_GET['start'] : date('Y-m-d', strtotime('-30 days'));
$end = isset($_GET['end']) ? $_GET['end'] : date('Y-m-d');

// On formate pour un BETWEEN en SQL (inclusif sur la journée)
$startDateTime = $start . " 00:00:00";
$endDateTime = $end . " 23:59:59";

// --- 2) Traitement des exports si demandés ---
$export = isset($_GET['export']) ? $_GET['export'] : '';

// Récupération des données (utilisé pour tous les cas : affichage et exports)
// --- Calculer les totaux (Ventes, Dépôts, Retraits, Retours) ---

// CORRECTION : Ventes Régulières - Utilise FinalAmount avec remises
$sqlSalesRegular = "
  SELECT COALESCE(SUM(cust.FinalAmount), 0) AS totalSales
  FROM tblcustomer cust
  WHERE cust.ModeofPayment != 'credit'
    AND EXISTS (
      SELECT 1 FROM tblcart c 
      WHERE c.BillingId = cust.BillingNumber 
        AND c.CartDate BETWEEN ? AND ?
        AND c.IsCheckOut = '1'
    )
";
$stmtSalesRegular = $con->prepare($sqlSalesRegular);
$stmtSalesRegular->bind_param('ss', $startDateTime, $endDateTime);
$stmtSalesRegular->execute();
$resultSalesRegular = $stmtSalesRegular->get_result();
$rowSalesRegular = $resultSalesRegular->fetch_assoc();
$totalSalesRegular = $rowSalesRegular['totalSales'];
$stmtSalesRegular->close();

// CORRECTION : Ventes à Crédit - Utilise FinalAmount avec remises
$sqlSalesCredit = "
  SELECT COALESCE(SUM(cust.FinalAmount), 0) AS totalSales
  FROM tblcustomer cust
  WHERE cust.ModeofPayment = 'credit'
    AND EXISTS (
      SELECT 1 FROM tblcart c 
      WHERE c.BillingId = cust.BillingNumber 
        AND c.CartDate BETWEEN ? AND ?
        AND c.IsCheckOut = '1'
    )
";
$stmtSalesCredit = $con->prepare($sqlSalesCredit);
$stmtSalesCredit->bind_param('ss', $startDateTime, $endDateTime);
$stmtSalesCredit->execute();
$resultSalesCredit = $stmtSalesCredit->get_result();
$rowSalesCredit = $resultSalesCredit->fetch_assoc();
$totalSalesCredit = $rowSalesCredit['totalSales'];
$stmtSalesCredit->close();

// Montants réellement payés par les clients - MODIFIED to use tblpayments
$sqlPaidAmounts = "
  SELECT COALESCE(SUM(PaymentAmount), 0) AS totalPaid
  FROM tblpayments
  WHERE PaymentDate BETWEEN ? AND ?
";
$stmtPaidAmounts = $con->prepare($sqlPaidAmounts);
$stmtPaidAmounts->bind_param('ss', $startDateTime, $endDateTime);
$stmtPaidAmounts->execute();
$resultPaidAmounts = $stmtPaidAmounts->get_result();
$rowPaidAmounts = $resultPaidAmounts->fetch_assoc();
$totalPaid = $rowPaidAmounts['totalPaid'];
$stmtPaidAmounts->close();

// Statistiques par méthode de paiement - NEW
$sqlPaymentMethods = "
  SELECT 
    PaymentMethod,
    COUNT(*) as count,
    SUM(PaymentAmount) as total
  FROM tblpayments
  WHERE PaymentDate BETWEEN ? AND ?
  GROUP BY PaymentMethod
  ORDER BY total DESC
";
$stmtPaymentMethods = $con->prepare($sqlPaymentMethods);
$stmtPaymentMethods->bind_param('ss', $startDateTime, $endDateTime);
$stmtPaymentMethods->execute();
$resultPaymentMethods = $stmtPaymentMethods->get_result();
$paymentMethods = [];
while ($row = $resultPaymentMethods->fetch_assoc()) {
  $paymentMethods[$row['PaymentMethod']] = [
    'count' => $row['count'],
    'total' => $row['total']
  ];
}
$stmtPaymentMethods->close();

// Dépôts/Retraits
$sqlTransactions = "
  SELECT
    COALESCE(SUM(CASE WHEN TransType='IN' THEN Amount ELSE 0 END), 0) AS totalDeposits,
    COALESCE(SUM(CASE WHEN TransType='OUT' THEN Amount ELSE 0 END), 0) AS totalWithdrawals
  FROM tblcashtransactions
  WHERE TransDate BETWEEN ? AND ?
";
$stmtTransactions = $con->prepare($sqlTransactions);
$stmtTransactions->bind_param('ss', $startDateTime, $endDateTime);
$stmtTransactions->execute();
$resultTransactions = $stmtTransactions->get_result();
$rowTransactions = $resultTransactions->fetch_assoc();
$totalDeposits = $rowTransactions['totalDeposits'];
$totalWithdrawals = $rowTransactions['totalWithdrawals'];
$stmtTransactions->close();

// Retours
$sqlReturns = "
  SELECT COALESCE(SUM(r.Quantity * p.Price), 0) AS totalReturns
  FROM tblreturns r
  JOIN tblproducts p ON p.ID = r.ProductID
  WHERE r.ReturnDate BETWEEN ? AND ?
";
$stmtReturns = $con->prepare($sqlReturns);
$stmtReturns->bind_param('ss', $start, $end);
$stmtReturns->execute();
$resultReturns = $stmtReturns->get_result();
$rowReturns = $resultReturns->fetch_assoc();
$totalReturns = $rowReturns['totalReturns'];
$stmtReturns->close();

// Solde final (SANS les ventes à crédit, uniquement montants payés)
$netBalance = ($totalSalesRegular + $totalPaid + $totalDeposits) - ($totalWithdrawals + $totalReturns);

// --- 3) CORRECTION : Liste unifiée pour l'affichage / export ---
$sqlList = "
  -- Ventes régulières (CORRIGÉ - utilise FinalAmount)
  SELECT 'Vente' AS Type, cust.FinalAmount AS Amount,
       c.CartDate AS Date, 
       CONCAT('Facture #', cust.BillingNumber, ' - ', cust.CustomerName) COLLATE utf8mb4_unicode_ci AS Comment
  FROM tblcustomer cust
  JOIN tblcart c ON c.BillingId = cust.BillingNumber
  WHERE c.IsCheckOut='1'
    AND c.CartDate BETWEEN ? AND ?
    AND cust.ModeofPayment != 'credit'
  GROUP BY cust.BillingNumber
  
  UNION ALL
  
  -- Ventes à crédit (CORRIGÉ - utilise FinalAmount)
  SELECT 'Vente à Terme' AS Type, cust.FinalAmount AS Amount,
       c.CartDate AS Date, 
       CONCAT('Facture #', cust.BillingNumber, ' - ', cust.CustomerName) COLLATE utf8mb4_unicode_ci AS Comment
  FROM tblcustomer cust
  JOIN tblcart c ON c.BillingId = cust.BillingNumber
  WHERE c.IsCheckOut='1'
    AND c.CartDate BETWEEN ? AND ?
    AND cust.ModeofPayment = 'credit'
  GROUP BY cust.BillingNumber
  
  UNION ALL
  
  -- Paiements clients - MODIFIED to use tblpayments
  SELECT 'Paiement Client' AS Type, p.PaymentAmount AS Amount,
       p.PaymentDate AS Date, 
       CONCAT(c.CustomerName, ' (', p.PaymentMethod, 
          CASE WHEN p.ReferenceNumber IS NOT NULL AND p.ReferenceNumber != '' 
               THEN CONCAT(', Réf: ', p.ReferenceNumber) 
               ELSE '' 
          END, ')') COLLATE utf8mb4_unicode_ci AS Comment
  FROM tblpayments p
  JOIN tblcustomer c ON p.CustomerID = c.ID
  WHERE p.PaymentDate BETWEEN ? AND ?
  
  UNION ALL
  
  -- Transactions de caisse
  SELECT 
    CASE 
      WHEN TransType='IN' THEN 'Dépôt' 
      WHEN TransType='OUT' THEN 'Retrait'
      ELSE TransType 
    END AS Type, 
    Amount, TransDate AS Date, Comments COLLATE utf8mb4_unicode_ci AS Comment
  FROM tblcashtransactions
  WHERE TransDate BETWEEN ? AND ?
  
  UNION ALL
  
  -- Retours
  SELECT 'Retour' AS Type, (r.Quantity * p.Price) AS Amount,
       r.ReturnDate AS Date, r.Reason COLLATE utf8mb4_unicode_ci AS Comment
  FROM tblreturns r
  JOIN tblproducts p ON p.ID = r.ProductID
  WHERE r.ReturnDate BETWEEN ? AND ?
  
  ORDER BY Date DESC
";
$stmtList = $con->prepare($sqlList);
$stmtList->bind_param('ssssssssss', $startDateTime, $endDateTime, $startDateTime, $endDateTime, $startDateTime, $endDateTime, $startDateTime, $endDateTime, $start, $end);
$stmtList->execute();
$resultList = $stmtList->get_result();

// --- 4) Statistiques par produit ---
$sqlProducts = "
  SELECT 
    p.ID,
    p.ProductName,
    COALESCE(c.CategoryName, 'N/A') AS CategoryName,
    p.BrandName,
    p.Stock AS initial_stock,
    (COALESCE(SUM(cart_regular.ProductQty), 0) + COALESCE(SUM(cart_credit.ProductQty), 0)) AS sold_qty,
    COALESCE(
      (SELECT SUM(Quantity) FROM tblreturns WHERE ProductID = p.ID AND 
      ReturnDate BETWEEN ? AND ?),
      0
    ) AS returned_qty,
    p.Price,
    (COALESCE(SUM(cart_regular.ProductQty * cart_regular.Price), 0) + COALESCE(SUM(cart_credit.ProductQty * cart_credit.Price), 0)) AS total_sales
  FROM tblproducts p
  LEFT JOIN tblcategory c ON c.ID = p.CatID
  -- Jointure pour les ventes régulières
  LEFT JOIN tblcart cart_regular ON cart_regular.ProductId = p.ID AND cart_regular.IsCheckOut = 1
    AND cart_regular.CartDate BETWEEN ? AND ?
  -- Jointure pour les ventes à crédit
  LEFT JOIN tblcreditcart cart_credit ON cart_credit.ProductId = p.ID AND cart_credit.IsCheckOut = 1
    AND cart_credit.CartDate BETWEEN ? AND ?
  GROUP BY p.ID
  ORDER BY total_sales DESC
  LIMIT 10
";
$stmtProducts = $con->prepare($sqlProducts);
$stmtProducts->bind_param('ssssss', $start, $end, $startDateTime, $endDateTime, $startDateTime, $endDateTime);
$stmtProducts->execute();
$resultProducts = $stmtProducts->get_result();

// ========== A) Export PDF via dompdf ==========
if ($export === 'pdf' && $dompdf_available) {
  // 1) Créer une instance Dompdf
  $dompdf = new Dompdf();

  // 2) Construire le HTML minimal à exporter
  ob_start();
  ?>
  <style>
    body { font-family: Arial, sans-serif; font-size: 12px; }
    table { width: 100%; border-collapse: collapse; margin-bottom: 20px; }
    th, td { border: 1px solid #ddd; padding: 8px; text-align: left; }
    th { background-color: #f2f2f2; }
    h2 { color: #333; }
    .text-right { text-align: right; }
    .summary { margin-bottom: 20px; }
  </style>
  <h2>Rapport Financier du <?php echo date('d/m/Y', strtotime($start)); ?> au <?php echo date('d/m/Y', strtotime($end)); ?></h2>
  
  <div class="summary">
    <h3>Résumé</h3>
    <table>
      <tr><th>Ventes Régulières (avec remises)</th><td class="text-right"><?php echo number_format($totalSalesRegular, 2); ?></td></tr>
      <tr><th>Paiements reçus des clients</th><td class="text-right"><?php echo number_format($totalPaid, 2); ?></td></tr>
      <tr><th>Total Encaissé (Ventes+Paiements)</th><td class="text-right"><strong><?php echo number_format($totalSalesRegular + $totalPaid, 2); ?></strong></td></tr>
      <tr><th>Ventes à terme (avec remises, non incluses dans le solde)</th><td class="text-right"><?php echo number_format($totalSalesCredit, 2); ?></td></tr>
      <tr><th>Dépôts</th><td class="text-right"><?php echo number_format($totalDeposits, 2); ?></td></tr>
      <tr><th>Retraits</th><td class="text-right"><?php echo number_format($totalWithdrawals, 2); ?></td></tr>
      <tr><th>Retours</th><td class="text-right"><?php echo number_format($totalReturns, 2); ?></td></tr>
      <tr><th>Solde Final (En caisse)</th><td class="text-right"><strong><?php echo number_format($netBalance, 2); ?></strong></td></tr>
    </table>
  </div>

  <!-- AJOUT - Statistiques par méthode de paiement -->
  <div class="summary">
    <h3>Paiements par Méthode</h3>
    <table>
      <?php foreach ($paymentMethods as $method => $data): ?>
      <tr>
        <th><?php echo htmlspecialchars($method); ?></th>
        <td class="text-right"><?php echo number_format($data['total'], 2); ?></td>
        <td>(<?php echo $data['count']; ?> transactions)</td>
      </tr>
      <?php endforeach; ?>
    </table>
  </div>

  <h3>Transactions détaillées</h3>
  <table>
    <tr>
      <th>#</th>
      <th>Type</th>
      <th>Montant</th>
      <th>Date</th>
      <th>Commentaire</th>
    </tr>
    <?php
    $cnt=1;
    $resultList->data_seek(0); // reset pointer
    while($row = $resultList->fetch_assoc()) {
    ?>
    <tr>
      <td><?php echo $cnt++; ?></td>
      <td><?php echo $row['Type']; ?></td>
      <td class="text-right"><?php echo number_format($row['Amount'], 2); ?></td>
      <td><?php echo date('d/m/Y H:i', strtotime($row['Date'])); ?></td>
      <td><?php echo htmlspecialchars($row['Comment']); ?></td>
    </tr>
    <?php
    }
    ?>
  </table>
  
  <h3>Top Produits Vendus</h3>
  <table>
    <tr>
      <th>#</th>
      <th>Produit</th>
      <th>Catégorie</th>
      <th>Quantité Vendue</th>
      <th>Retours</th>
      <th>Ventes Totales</th>
    </tr>
    <?php
    $cnt=1;
    $resultProducts->data_seek(0); // reset pointer
    while($row = $resultProducts->fetch_assoc()) {
      $sold = (int)$row['sold_qty'];
      $returned = (int)$row['returned_qty'];
      $net_sold = $sold - $returned;
    ?>
    <tr>
      <td><?php echo $cnt++; ?></td>
      <td><?php echo htmlspecialchars($row['ProductName']); ?></td>
      <td><?php echo htmlspecialchars($row['CategoryName']); ?></td>
      <td><?php echo $sold; ?></td>
      <td><?php echo $returned; ?></td>
      <td class="text-right"><?php echo number_format($row['total_sales'], 2); ?></td>
    </tr>
    <?php
    }
    ?>
  </table>
  <?php
  $html = ob_get_clean();

  // 3) Passer le HTML à dompdf
  $dompdf->loadHtml($html);
  $dompdf->setPaper('A4', 'portrait');
  $dompdf->render();

  // 4) Output PDF
  $dompdf->stream("rapport_financier_".date('Ymd').".pdf", array("Attachment" => true));
  exit;
}

// ========== B) Export Excel ==========
if ($export === 'excel') {
  // 1) Nom du fichier
  $filename = "rapport_financier_".date('Ymd').".xls";

  // 2) Headers HTTP pour l'export Excel
  header("Content-Type: application/vnd.ms-excel");
  header("Content-Disposition: attachment; filename=\"$filename\"");
  header("Cache-Control: max-age=0");

  // 3) Construire un tableau HTML
  echo '
  <style>
    table { border-collapse: collapse; }
    th, td { border: 1px solid black; padding: 5px; }
    th { background-color: #f2f2f2; }
    .text-right { text-align: right; }
  </style>';
  
  echo "<h2>Rapport Financier du ".date('d/m/Y', strtotime($start))." au ".date('d/m/Y', strtotime($end))."</h2>";
  
  echo "<h3>Résumé</h3>";
  echo "<table border='1'>";
  echo "<tr><th>Ventes Régulières (avec remises)</th><td class='text-right'>".number_format($totalSalesRegular, 2)."</td></tr>";
  echo "<tr><th>Paiements reçus des clients</th><td class='text-right'>".number_format($totalPaid, 2)."</td></tr>";
  echo "<tr><th>Total Encaissé (Ventes+Paiements)</th><td class='text-right'><strong>".number_format($totalSalesRegular + $totalPaid, 2)."</strong></td></tr>";
  echo "<tr><th>Ventes à terme (avec remises, non incluses dans le solde)</th><td class='text-right'>".number_format($totalSalesCredit, 2)."</td></tr>";
  echo "<tr><th>Dépôts</th><td class='text-right'>".number_format($totalDeposits, 2)."</td></tr>";
  echo "<tr><th>Retraits</th><td class='text-right'>".number_format($totalWithdrawals, 2)."</td></tr>";
  echo "<tr><th>Retours</th><td class='text-right'>".number_format($totalReturns, 2)."</td></tr>";
  echo "<tr><th>Solde Final (En caisse)</th><td class='text-right'><strong>".number_format($netBalance, 2)."</strong></td></tr>";
  echo "</table>";
  
  // AJOUT - Statistiques par méthode de paiement en Excel
  echo "<h3>Paiements par Méthode</h3>";
  echo "<table border='1'>";
  echo "<tr><th>Méthode</th><th>Montant</th><th>Transactions</th></tr>";
  foreach ($paymentMethods as $method => $data) {
    echo "<tr>";
    echo "<td>".htmlspecialchars($method)."</td>";
    echo "<td class='text-right'>".number_format($data['total'], 2)."</td>";
    echo "<td>".$data['count']."</td>";
    echo "</tr>";
  }
  echo "</table>";
  
  echo "<h3>Transactions détaillées</h3>";
  echo "<table border='1'>";
  echo "<tr><th>#</th><th>Type</th><th>Montant</th><th>Date</th><th>Commentaire</th></tr>";
  $cnt=1;
  $resultList->data_seek(0); // reset pointer
  while($row = $resultList->fetch_assoc()) {
    echo "<tr>";
    echo "<td>".$cnt++."</td>";
    echo "<td>".$row['Type']."</td>";
    echo "<td class='text-right'>".number_format($row['Amount'], 2)."</td>";
    echo "<td>".date('d/m/Y H:i', strtotime($row['Date']))."</td>";
    echo "<td>".htmlspecialchars($row['Comment'])."</td>";
    echo "</tr>";
  }
  echo "</table>";
  
  echo "<h3>Top Produits Vendus</h3>";
  echo "<table border='1'>";
  echo "<tr><th>#</th><th>Produit</th><th>Catégorie</th><th>Quantité Vendue</th><th>Retours</th><th>Ventes Totales</th></tr>";
  $cnt=1;
  $resultProducts->data_seek(0); // reset pointer
  while($row = $resultProducts->fetch_assoc()) {
    $sold = (int)$row['sold_qty'];
    $returned = (int)$row['returned_qty'];
    
    echo "<tr>";
    echo "<td>".$cnt++."</td>";
    echo "<td>".htmlspecialchars($row['ProductName'])."</td>";
    echo "<td>".htmlspecialchars($row['CategoryName'])."</td>";
    echo "<td>".$sold."</td>";
    echo "<td>".$returned."</td>";
    echo "<td class='text-right'>".number_format($row['total_sales'], 2)."</td>";
    echo "</tr>";
  }
  echo "</table>";
  exit;
}

// --- 5) Sinon, on affiche la page HTML classique ---
?>
<!DOCTYPE html>
<html lang="fr">
<head>
  <meta charset="UTF-8">
  <meta name="viewport" content="width=device-width, initial-scale=1.0">
  <title>Rapport Financier</title>
  <?php include_once('includes/cs.php'); ?>
  <?php include_once('includes/responsive.php'); ?>
  <style>
    /* Styles pour impression */
    @media print {
      /* Cacher tous les éléments de navigation et UI */
      header, #header, .header, 
      #sidebar, .sidebar, 
      #user-nav, #search, .navbar, 
      footer, #footer, .footer,
      .no-print, #breadcrumb, 
      #content-header, .widget-title {
        display: none !important;
      }
      
      /* Afficher l'en-tête d'impression qui est normalement caché */
      .print-header {
        display: block;
        text-align: center;
        margin-bottom: 20px;
      }
      
      /* Ajuster la mise en page pour l'impression */
      body {
        background: white !important;
        margin: 0 !important;
        padding: 0 !important;
      }
      
      #content {
        margin: 0 !important;
        padding: 0 !important;
        width: 100% !important;
        left: 0 !important;
        position: relative !important;
      }
      
      .container-fluid {
        padding: 0 !important;
        margin: 0 !important;
        width: 100% !important;
      }
      
      .row-fluid .span12 {
        width: 100% !important;
        margin: 0 !important;
        float: none !important;
      }
      
      /* Retirer les bordures et couleurs de fond pour l'impression */
      .widget-box {
        border: none !important;
        box-shadow: none !important;
        margin: 0 !important;
        padding: 0 !important;
        background: none !important;
      }
      
      /* Assurer que les tableaux s'impriment correctement */
      table { page-break-inside: auto; }
      tr { page-break-inside: avoid; page-break-after: auto; }
      thead { display: table-header-group; }
      tfoot { display: table-footer-group; }
      
      /* Supprimer les marges et espacements inutiles */
      hr, br.print-hidden {
        display: none !important;
      }
      
      /* Styles pour les tableaux à l'impression */
      .data-table {
        width: 100%;
        border-collapse: collapse;
        margin-bottom: 20px;
      }
      
      .data-table th,
      .data-table td {
        border: 1px solid #000 !important;
        padding: 8px !important;
        text-align: left;
      }
      
      .data-table th {
        font-weight: bold;
        background-color: #f5f5f5 !important;
      }
      
      /* Formatage des titres et sections */
      h1, h2, h3, h4, h5 {
        page-break-after: avoid;
      }
      
      /* Forcer l'impression en noir et blanc par défaut */
      * {
        color: black !important;
        text-shadow: none !important;
        filter: none !important;
        -ms-filter: none !important;
      }
      
      /* Exceptions pour certains éléments spécifiques */
      .text-danger {
        color: #d9534f !important;
      }
      
      .widget-content {
        page-break-inside: avoid !important;
      }
      
      /* Cacher les éléments de UI non nécessaires pour l'impression */
      .btn, .form-actions, .buttons, .pagination {
        display: none !important;
      }
      
      /* Assurer que tout passe bien à l'impression */
      .print-full-width {
        width: 100% !important;
        float: none !important;
      }
      
      .print-visible {
        display: block !important;
        visibility: visible !important;
      }
      
      /* Cacher le datatable search et pagination */
      .dataTables_wrapper .dataTables_filter,
      .dataTables_wrapper .dataTables_paginate,
      .dataTables_wrapper .dataTables_info,
      .dataTables_wrapper .dataTables_length {
        display: none !important;
      }
    }
    
    /* Styles normaux (hors impression) */
    .print-header {
      display: none;
    }
    
    .text-right {
      text-align: right;
    }
    
    .stat-boxes li {
      margin-bottom: 10px;
    }
    
    .table th {
      font-weight: bold;
    }
    
    .summary-box {
      background-color: #f9f9f9;
      border: 1px solid #ddd;
      border-radius: 4px;
      padding: 15px;
      margin-bottom: 20px;
    }
    
    .btn-print {
      margin-left: 10px;
    }
    
    /* Style pour les ventes à terme */
    .label-credit {
      background-color: #f0ad4e;
    }
    
    /* Style pour les paiements clients */
    .label-payment {
      background-color: #5bc0de;
    }
    
    /* AJOUT - Styles pour les méthodes de paiement */
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
    
    .payment-stats {
      text-align: center;
      padding: 10px;
      border-radius: 5px;
      margin-bottom: 10px;
    }
    .payment-stats h4 {
      margin-top: 0;
    }
    .payment-stats h3 {
      margin: 5px 0;
      font-size: 24px;
    }
    
    .correction-notice {
      background-color: #d4edda;
      border: 1px solid #c3e6cb;
      border-radius: 4px;
      padding: 10px;
      margin-bottom: 15px;
      color: #155724;
    }
  </style>
</head>
<body>
<!-- Éléments qui seront cachés à l'impression -->
<div class="no-print">
  <?php include_once('includes/header.php'); ?>
  <?php include_once('includes/sidebar.php'); ?>
</div>

<div id="content">
  <!-- En-tête de contenu - caché à l'impression -->
  <div id="content-header" class="no-print">
    <div id="breadcrumb">
      <a href="dashboard.php" title="Accueil" class="tip-bottom"><i class="icon-home"></i> Accueil</a>
      <a href="report.php" class="current">Rapport Financier</a>
    </div>
    <h1>Rapport Financier</h1>
  </div>

  <div class="container-fluid">
    <hr class="no-print">

    <!-- Formulaire de filtre par dates - caché à l'impression -->
    <div class="row-fluid no-print">
      <div class="span12">
        <div class="widget-box">
          <div class="widget-title">
            <span class="icon"><i class="icon-calendar"></i></span>
            <h5>Sélectionner la période du rapport</h5>
          </div>
          <div class="widget-content nopadding">
            <form method="get" class="form-horizontal" action="<?php echo htmlspecialchars($_SERVER['PHP_SELF']); ?>">
              <div class="control-group">
                <label class="control-label">De Date :</label>
                <div class="controls">
                  <input type="date" class="span11" name="start" id="start" value="<?php echo $start; ?>" required="true">
                </div>
              </div>
              <div class="control-group">
                <label class="control-label">À Date :</label>
                <div class="controls">
                  <input type="date" class="span11" name="end" id="end" value="<?php echo $end; ?>" required="true">
                </div>
              </div>
              <div class="form-actions">
                <button type="submit" class="btn btn-success"><i class="icon-search"></i> Afficher le Rapport</button>
                <button type="button" class="btn btn-primary btn-print" onclick="window.print();">
                  <i class="icon-print"></i> Imprimer
                </button>
                <a href="<?php echo htmlspecialchars($_SERVER['PHP_SELF']); ?>?start=<?php echo $start; ?>&end=<?php echo $end; ?>&export=pdf" class="btn btn-danger">
                  <i class="icon-file"></i> Exporter PDF
                </a>
                <a href="<?php echo htmlspecialchars($_SERVER['PHP_SELF']); ?>?start=<?php echo $start; ?>&end=<?php echo $end; ?>&export=excel" class="btn btn-info">
                  <i class="icon-table"></i> Exporter Excel
                </a>
                <!-- AJOUT - Lien vers l'historique des paiements avec les mêmes dates -->
                <a href="payment-history.php?start=<?php echo $start; ?>&end=<?php echo $end; ?>" class="btn">
                  <i class="icon-time"></i> Historique des paiements
                </a>
              </div>
            </form>
          </div>
        </div>
      </div>
    </div>

    <!-- Zone d'impression -->
    <div id="printArea">
      <!-- En-tête visible uniquement à l'impression -->
      <div class="print-header">
        <h2>Système de Gestion d'Inventaire</h2>
        <h3>Rapport Financier du <?php echo date('d/m/Y', strtotime($start)); ?> au <?php echo date('d/m/Y', strtotime($end)); ?></h3>
        <p>Période: <?php echo round((strtotime($end) - strtotime($start)) / 86400); ?> jours</p>
      </div>

      <!-- Tableau récapitulatif -->
      <div class="row-fluid">
        <div class="span12">
          <div class="widget-box summary-box">
            <div class="widget-title">
              <span class="icon"><i class="icon-signal"></i></span>
              <h5>Résumé du <?php echo date('d/m/Y', strtotime($start)); ?> au <?php echo date('d/m/Y', strtotime($end)); ?></h5>
              <span class="label label-info">Période: <?php echo round((strtotime($end) - strtotime($start)) / 86400); ?> jours</span>
            </div>
            <div class="widget-content">
              <div class="row-fluid">
                <div class="span12">
                  <table class="table table-bordered">
                    <tr>
                      <th>Ventes régulières (avec remises)</th>
                      <td class="text-right"><?php echo number_format($totalSalesRegular, 2); ?></td>
                    </tr>
                    <tr>
                      <th>Paiements reçus des clients</th>
                      <td class="text-right"><?php echo number_format($totalPaid, 2); ?></td>
                    </tr>
                    <tr>
                      <th>Total Encaissé (Ventes+Paiements)</th>
                      <td class="text-right"><strong><?php echo number_format($totalSalesRegular + $totalPaid, 2); ?></strong></td>
                    </tr>
                    <tr>
                      <th>Ventes à terme (avec remises, non incluses dans le solde)</th>
                      <td class="text-right"><?php echo number_format($totalSalesCredit, 2); ?></td>
                    </tr>
                    <tr>
                      <th>Dépôts</th>
                      <td class="text-right"><?php echo number_format($totalDeposits, 2); ?></td>
                    </tr>
                    <tr>
                      <th>Retraits</th>
                      <td class="text-right"><?php echo number_format($totalWithdrawals, 2); ?></td>
                    </tr>
                    <tr>
                      <th>Retours</th>
                      <td class="text-right"><?php echo number_format($totalReturns, 2); ?></td>
                    </tr>
                    <tr>
                      <th>Solde Final (En caisse)</th>
                      <td class="text-right"><strong><?php echo number_format($netBalance, 2); ?></strong></td>
                    </tr>
                  </table>
                </div>
              </div>
            </div>
          </div>
        </div>
      </div>

      <!-- AJOUT - Statistiques par méthode de paiement -->
      <?php if (count($paymentMethods) > 0): ?>
      <div class="row-fluid">
        <div class="span12">
          <div class="widget-box">
            <div class="widget-title">
              <span class="icon"><i class="icon-money"></i></span>
              <h5>Paiements par Méthode</h5>
            </div>
            <div class="widget-content">
              <div class="row-fluid">
                <?php foreach ($paymentMethods as $method => $data): 
                  $methodClass = '';
                  switch($method) {
                    case 'Cash': $methodClass = 'payment-method-cash'; break;
                    case 'Card': $methodClass = 'payment-method-card'; break;
                    case 'Transfer': $methodClass = 'payment-method-transfer'; break;
                    case 'Mobile': $methodClass = 'payment-method-mobile'; break;
                  }
                ?>
                <div class="span3">
                  <div class="payment-stats <?php echo $methodClass; ?>">
                    <h4><?php echo htmlspecialchars($method); ?></h4>
                    <h3><?php echo number_format($data['total'], 2); ?></h3>
                    <p><?php echo $data['count']; ?> transaction(s)</p>
                  </div>
                </div>
                <?php endforeach; ?>
              </div>
            </div>
          </div>
        </div>
      </div>
      <?php endif; ?>

      <!-- Top Produits Vendus -->
      <div class="row-fluid">
        <div class="span12">
          <div class="widget-box">
            <div class="widget-title">
              <span class="icon"><i class="icon-star"></i></span>
              <h5>Top Produits Vendus</h5>
            </div>
            <div class="widget-content">
              <table class="table table-bordered table-striped data-table">
                <thead>
                  <tr>
                    <th width="5%">N°</th>
                    <th width="30%">Produit</th>
                    <th width="15%">Catégorie</th>
                    <th width="10%">Quantité Vendue</th>
                    <th width="10%">Retours</th>
                    <th width="15%">Prix Unitaire</th>
                    <th width="15%">Ventes Totales</th>
                  </tr>
                </thead>
                <tbody>
                  <?php
                  $resultProducts->data_seek(0);
                  $cnt = 1;
                  while($row = $resultProducts->fetch_assoc()) {
                    $sold = (int)$row['sold_qty'];
                    $returned = (int)$row['returned_qty'];
                    $net_sold = $sold - $returned;
                  ?>
                  <tr>
                    <td><?php echo $cnt; ?></td>
                    <td><?php echo htmlspecialchars($row['ProductName']); ?></td>
                    <td><?php echo htmlspecialchars($row['CategoryName']); ?></td>
                    <td><?php echo $sold; ?></td>
                    <td><?php echo $returned; ?></td>
                    <td class="text-right"><?php echo number_format($row['Price'], 2); ?></td>
                    <td class="text-right"><?php echo number_format($row['total_sales'], 2); ?></td>
                  </tr>
                  <?php
                    $cnt++;
                  }
                  ?>
                </tbody>
              </table>
            </div>
          </div>
        </div>
      </div>

      <!-- Transactions détaillées -->
      <div class="row-fluid">
        <div class="span12">
          <div class="widget-box">
            <div class="widget-title">
              <span class="icon"><i class="icon-th"></i></span>
              <h5>Transactions détaillées</h5>
            </div>
            <div class="widget-content">
              <table class="table table-bordered data-table">
                <thead>
                  <tr>
                    <th width="5%">N°</th>
                    <th width="15%">Type</th>
                    <th width="15%">Montant</th>
                    <th width="20%">Date</th>
                    <th width="45%">Commentaire</th>
                  </tr>
                </thead>
                <tbody>
                  <?php
                  $resultList->data_seek(0);
                  $cnt = 1;
                  while($row = $resultList->fetch_assoc()) {
                    // Définir la classe CSS pour le type de transaction
                    $typeClass = '';
                    switch($row['Type']) {
                      case 'Vente': $typeClass = 'label-success'; break;
                      case 'Vente à Terme': $typeClass = 'label-credit'; break;
                      case 'Paiement Client': $typeClass = 'label-payment'; break;
                      case 'Dépôt': $typeClass = 'label-info'; break;
                      case 'Retrait': $typeClass = 'label-warning'; break;
                      case 'Retour': $typeClass = 'label-important'; break;
                      default: $typeClass = '';
                    }
                  ?>
                  <tr>
                    <td><?php echo $cnt; ?></td>
                    <td><span class="label <?php echo $typeClass; ?>"><?php echo $row['Type']; ?></span></td>
                    <td class="text-right"><?php echo number_format($row['Amount'], 2); ?></td>
                    <td><?php echo date('d/m/Y H:i', strtotime($row['Date'])); ?></td>
                    <td><?php echo htmlspecialchars($row['Comment']); ?></td>
                  </tr>
                  <?php
                    $cnt++;
                  }
                  // Fermer les requêtes préparées
                  $stmtList->close();
                  $stmtProducts->close();
                  ?>
                </tbody>
              </table>
            </div>
          </div>
        </div>
      </div>
      
      <!-- Pied de page du rapport (visible à l'impression) -->
      <div class="row-fluid print-visible">
        <div class="span12">
          <p style="margin-top: 20px;"><small>Rapport généré le <?php echo date("d/m/Y H:i"); ?></small></p>
        </div>
      </div>
    </div>
  </div>
</div>

<!-- Pied de page - caché à l'impression -->
<div class="no-print">
  <?php include_once('includes/footer.php'); ?>
</div>

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
  // Validation JS: assure start <= end
  document.addEventListener('DOMContentLoaded', function() {
    const form = document.querySelector('form');
    form && form.addEventListener('submit', function(e) {
      const startDate = new Date(document.getElementById('start').value);
      const endDate = new Date(document.getElementById('end').value);
      if (startDate > endDate) {
        alert('La date de début ne peut pas être après la date de fin.');
        e.preventDefault();
      }
    });
  });
</script>

</body>
</html>