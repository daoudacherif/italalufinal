<?php 
session_start();
error_reporting(E_ALL);
include('includes/dbconnection.php');

// Vérifier si l'admin est connecté
if (empty($_SESSION['imsaid'])) {
    header('location:logout.php');
    exit;
}
?>
<!DOCTYPE html>
<html lang="fr">
<head>
  <meta charset="UTF-8">
  <title>Inventaire des Articles</title>
  <?php include_once('includes/cs.php'); ?>
  <?php include_once('includes/responsive.php'); ?>
  <style>
    .stock-critical { color: #c62828; font-weight: bold; }
    .stock-low { color: #ef6c00; font-weight: bold; }
    .stock-good { color: #2e7d32; }
    
    /* Styles complets pour l'impression - Masquer tous les éléments UI */
    @media print {
      /* Masquer tous les éléments d'interface utilisateur */
      .no-print,
      header,
      #header,
      nav,
      .navbar,
      .nav,
      #sidebar,
      .sidebar,
      #content-header,
      #breadcrumb,
      .breadcrumb,
      footer,
      #footer,
      .footer,
      .widget-title,
      .btn,
      .button,
      .action-buttons,
      .alert,
      .dataTables_wrapper .dataTables_length,
      .dataTables_wrapper .dataTables_filter,
      .dataTables_wrapper .dataTables_info,
      .dataTables_wrapper .dataTables_paginate,
      .dataTables_wrapper .dataTables_processing,
      .pagination,
      .pager,
      input,
      select,
      textarea,
      .form-control,
      .dropdown,
      .modal,
      .tooltip,
      .popover {
        display: none !important;
        visibility: hidden !important;
      }
      
      /* Styles pour la page d'impression */
      body {
        margin: 0 !important;
        padding: 15px !important;
        background: white !important;
        color: black !important;
        font-family: Arial, sans-serif !important;
        font-size: 12px !important;
        line-height: 1.3 !important;
      }
      
      /* Réinitialiser le contenu principal */
      #content {
        margin: 0 !important;
        padding: 0 !important;
        width: 100% !important;
        max-width: none !important;
      }
      
      .container-fluid {
        margin: 0 !important;
        padding: 0 !important;
        width: 100% !important;
      }
      
      .row-fluid {
        margin: 0 !important;
        width: 100% !important;
      }
      
      .span12 {
        width: 100% !important;
        margin: 0 !important;
      }
      
      .widget-box {
        border: none !important;
        background: transparent !important;
        box-shadow: none !important;
        margin: 0 !important;
      }
      
      .widget-content {
        padding: 0 !important;
        background: transparent !important;
      }
      
      /* Styles du tableau pour l'impression */
      .table {
        width: 100% !important;
        font-size: 10px !important;
        border-collapse: collapse !important;
        margin: 0 !important;
        background: white !important;
      }
      
      .table th,
      .table td {
        border: 1px solid #000 !important;
        padding: 3px 5px !important;
        text-align: left !important;
        vertical-align: top !important;
        background: white !important;
        color: black !important;
        font-size: 10px !important;
        line-height: 1.2 !important;
      }
      
      .table th {
        background: #f0f0f0 !important;
        font-weight: bold !important;
        text-align: center !important;
      }
      
      /* En-tête d'impression */
      .print-header {
        display: block !important;
        text-align: center !important;
        margin-bottom: 20px !important;
        border-bottom: 2px solid #000 !important;
        padding-bottom: 10px !important;
      }
      
      .print-header h1 {
        font-size: 18px !important;
        margin: 0 0 10px 0 !important;
        font-weight: bold !important;
        color: black !important;
      }
      
      .print-header p {
        font-size: 12px !important;
        margin: 0 !important;
        color: black !important;
      }
      
      /* Résumé pour l'impression */
      .print-summary-table {
        display: block !important;
        margin-top: 20px !important;
        page-break-inside: avoid !important;
      }
      
      .print-summary-table h3 {
        font-size: 14px !important;
        margin: 0 0 10px 0 !important;
        font-weight: bold !important;
        color: black !important;
      }
      
      .print-summary-table table {
        width: 50% !important;
        border-collapse: collapse !important;
        font-size: 10px !important;
      }
      
      .print-summary-table td {
        border: 1px solid #000 !important;
        padding: 5px !important;
        background: white !important;
        color: black !important;
      }
      
      /* Éviter les coupures de page */
      .table tr {
        page-break-inside: avoid !important;
      }
      
      /* Masquer toutes les colonnes d'action */
      .table th:last-child,
      .table td:last-child {
        display: none !important;
      }
      
      /* Forcer l'affichage des couleurs de stock en noir pour l'impression */
      .stock-critical,
      .stock-low,
      .stock-good {
        color: black !important;
        font-weight: bold !important;
      }
      
      /* Masquer hr */
      hr {
        display: none !important;
      }
    }
    
    /* Styles normaux (écran) */
    .print-header {
      display: none;
    }
    
    .action-buttons {
      white-space: nowrap;
    }
    
    .btn-print {
      background-color: #5bc0de;
      border-color: #46b8da;
      color: white;
      margin-bottom: 15px;
    }
    
    .btn-print:hover {
      background-color: #31b0d5;
      border-color: #269abc;
      color: white;
    }
    
    .print-summary-table {
      display: none;
    }
  </style>
</head>
<body>
<?php include_once('includes/header.php'); ?>
<?php include_once('includes/sidebar.php'); ?>

<div id="content">
  <div id="content-header" class="no-print">
    <div id="breadcrumb">
      <a href="dashboard.php" class="tip-bottom">
        <i class="icon-home"></i> Accueil
      </a>
      <strong>Voir l'Inventaire des Articles</strong>
    </div>
    <h1>Inventaire des Articles</h1>
  </div>
  
  <!-- En-tête pour l'impression -->
  <div class="print-header">
    <h1>INVENTAIRE DES ARTICLES</h1>
    <p>Date d'impression : <?= date('d/m/Y H:i') ?></p>
  </div>
  
  <div class="container-fluid">
    <hr class="no-print">
    
    <!-- Bouton d'impression -->
    <div class="row-fluid no-print">
      <div class="span12">
        <button onclick="window.print()" class="btn btn-print">
          <i class="icon-print"></i> Imprimer l'inventaire
        </button>
      </div>
    </div>
    
    <div class="row-fluid">
      <div class="span12">
        <div class="widget-box">
          <div class="widget-title no-print">
            <span class="icon"><i class="icon-th"></i></span>
            <h5>Inventaire des Articles</h5>
          </div>
          <div class="widget-content nopadding">
            <table class="table table-bordered data-table">
              <thead>
                <tr>
                  <th>N°</th>
                  <th>Nom du Article</th>
                  <th>Catégorie</th>
                  <th>Marque</th>
                  <th>Modèle</th>
                  <th>Stock Initial</th>
                  <th>Vendus</th>
                  <th>Retournés</th>
                  <th>Stock Actuel</th>
                  <th>Statut</th>
                  <th class="no-print">Actions</th>
                </tr>
              </thead>
              <tbody>
                <?php
                // Requête pour récupérer l'inventaire (logique métier inchangée)
                $sql = "
                  SELECT 
                    p.ID            AS pid,
                    p.ProductName,
                    COALESCE(c.CategoryName, 'N/A') AS CategoryName,
                    p.BrandName,
                    p.ModelNumber,
                    p.Stock         AS current_stock,
                    COALESCE(SUM(cart.ProductQty), 0) AS sold_qty,
                    COALESCE(
                      (SELECT SUM(Quantity) FROM tblreturns WHERE ProductID = p.ID),
                      0
                    ) AS returned_qty,
                    p.Status
                  FROM tblproducts p
                  LEFT JOIN tblcategory c 
                    ON c.ID = p.CatID
                  LEFT JOIN tblcart cart 
                    ON cart.ProductId = p.ID 
                   AND cart.IsCheckOut = 1
                  GROUP BY p.ID
                  ORDER BY p.Stock ASC, p.ID DESC
                ";
                $ret = mysqli_query($con, $sql) 
                  or die('Erreur SQL : ' . mysqli_error($con));

                if (mysqli_num_rows($ret) > 0) {
                  $cnt = 1;
                  while ($row = mysqli_fetch_assoc($ret)) {
                    // Le stock actuel est déjà dans la base de données
                    $current_stock = intval($row['current_stock']);
                    $sold = intval($row['sold_qty']);
                    $returned = intval($row['returned_qty']);
                    
                    // Calcul du stock initial = stock actuel + vendu - retourné (logique inchangée)
                    $initial_stock = $current_stock + $sold - $returned;
                    
                    // Déterminer la classe CSS pour le niveau de stock
                    $stockClass = '';
                    if ($current_stock == 0) {
                        $stockClass = 'stock-critical';
                    } elseif ($current_stock <= 5) {
                        $stockClass = 'stock-low';
                    } else {
                        $stockClass = 'stock-good';
                    }
                    ?>
                    <tr>
                      <td><?= $cnt ?></td>
                      <td><?= htmlspecialchars($row['ProductName']) ?></td>
                      <td><?= htmlspecialchars($row['CategoryName']) ?></td>
                      <td><?= htmlspecialchars($row['BrandName']) ?></td>
                      <td><?= htmlspecialchars($row['ModelNumber']) ?></td>
                      <td><?= $initial_stock ?></td>
                      <td><?= $sold ?></td>
                      <td><?= $returned ?></td>
                      <td class="<?= $stockClass ?>">
                        <?= $current_stock === 0 ? 'Épuisé' : $current_stock ?>
                      </td>
                      <td><?= $row['Status'] == 1 ? 'Actif' : 'Inactif' ?></td>
                      <td class="no-print">
                        <div class="action-buttons">
                          <a href="product-history.php?pid=<?= $row['pid'] ?>" 
                             class="btn btn-info btn-mini tip-top" 
                             title="Voir l'historique">
                            <i class="icon-time"></i> Historique
                          </a>
                        </div>
                      </td>
                    </tr>
                    <?php
                    $cnt++;
                  }
                } else {
                  echo '<tr><td colspan="11" class="text-center">Aucun Article trouvé</td></tr>';
                }
                ?>
              </tbody>
            </table>
          </div><!-- widget-content -->
        </div><!-- widget-box -->
      </div><!-- span12 -->
    </div><!-- row-fluid -->
    
    <!-- Résumé pour l'écran -->
    <div class="row-fluid" style="margin-top: 20px;">
      <div class="span12">
        <div class="print-summary">
          <?php
          // Statistiques globales
          $statsQuery = "
            SELECT 
              COUNT(*) as total_products,
              SUM(CASE WHEN Stock = 0 THEN 1 ELSE 0 END) as products_out_of_stock,
              SUM(CASE WHEN Stock <= 5 AND Stock > 0 THEN 1 ELSE 0 END) as products_low_stock,
              SUM(Stock) as total_stock_units
            FROM tblproducts 
            WHERE Status = 1
          ";
          $statsResult = mysqli_query($con, $statsQuery);
          $stats = mysqli_fetch_assoc($statsResult);
          ?>
          <div class="alert alert-info no-print">
            <h4>Résumé de l'inventaire</h4>
            <p><strong>Total produits actifs :</strong> <?= $stats['total_products'] ?></p>
            <p><strong>Produits en rupture :</strong> <?= $stats['products_out_of_stock'] ?></p>
            <p><strong>Produits en stock faible :</strong> <?= $stats['products_low_stock'] ?></p>
            <p><strong>Total unités en stock :</strong> <?= $stats['total_stock_units'] ?></p>
          </div>
          
          <!-- Version imprimable du résumé -->
          <div class="print-summary-table">
            <h3>Résumé de l'inventaire</h3>
            <table>
              <tr>
                <td><strong>Total produits actifs</strong></td>
                <td><?= $stats['total_products'] ?></td>
              </tr>
              <tr>
                <td><strong>Produits en rupture</strong></td>
                <td><?= $stats['products_out_of_stock'] ?></td>
              </tr>
              <tr>
                <td><strong>Produits en stock faible</strong></td>
                <td><?= $stats['products_low_stock'] ?></td>
              </tr>
              <tr>
                <td><strong>Total unités en stock</strong></td>
                <td><?= $stats['total_stock_units'] ?></td>
              </tr>
            </table>
          </div>
        </div>
      </div>
    </div>
    
  </div><!-- container-fluid -->
</div><!-- content -->

<?php include_once('includes/footer.php'); ?>

<!-- scripts pour DataTable si nécessaire -->
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
    // Vérifier si DataTable existe déjà et le détruire si nécessaire
    if ($.fn.DataTable.isDataTable('.data-table')) {
        $('.data-table').DataTable().destroy();
    }
    
    // Initialisation DataTable avec configuration pour l'impression
    $('.data-table').dataTable({
        "destroy": true, // Permet de réinitialiser automatiquement
        "pageLength": 50,
        "order": [[ 8, "asc" ]], // Trier par stock actuel croissant
        "language": {
            "url": "//cdn.datatables.net/plug-ins/1.10.24/i18n/French.json"
        }
    });
    
    // Gestion de l'impression - Afficher tous les éléments lors de l'impression
    window.addEventListener('beforeprint', function() {
        // Afficher tous les éléments du tableau sans pagination
        var table = $('.data-table').DataTable();
        table.page.len(-1).draw();
    });
    
    window.addEventListener('afterprint', function() {
        // Restaurer la pagination normale
        var table = $('.data-table').DataTable();
        table.page.len(50).draw();
    });
});

// Fonction d'impression personnalisée
function printInventory() {
    window.print();
}

// Tooltip pour les boutons d'action
$(document).ready(function() {
    $('.tip-top').tooltip({
        placement: 'top'
    });
});
</script>

</body>
</html>