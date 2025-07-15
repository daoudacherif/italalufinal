<?php 
session_start();
error_reporting(E_ALL);
include('includes/dbconnection.php');

// Vérifier si l'admin est connecté
if (empty($_SESSION['imsaid'])) {
    header('location:logout.php');
    exit;
}

// Récupérer l'ID du produit depuis l'URL
$productId = isset($_GET['pid']) ? intval($_GET['pid']) : 0;

// Si aucun produit spécifié, rediriger vers la liste des produits
if ($productId == 0) {
    header('location:manage-product.php');
    exit;
}

// Récupérer les informations du produit
$productQuery = "SELECT * FROM tblproducts WHERE ID = ?";
$stmt = mysqli_prepare($con, $productQuery);
mysqli_stmt_bind_param($stmt, "i", $productId);
mysqli_stmt_execute($stmt);
$productResult = mysqli_stmt_get_result($stmt);
$product = mysqli_fetch_assoc($productResult);

if (!$product) {
    echo "<script>alert('Produit non trouvé'); window.location.href='manage-product.php';</script>";
    exit;
}
?>
<!DOCTYPE html>
<html lang="fr">
<head>
  <meta charset="UTF-8">
  <title>Historique du Produit - <?= htmlspecialchars($product['ProductName']) ?></title>
  <?php include_once('includes/cs.php'); ?>
  <?php include_once('includes/responsive.php'); ?>
  <style>
    .product-info {
        background: #f8f9fa;
        padding: 15px;
        border-radius: 5px;
        margin-bottom: 20px;
    }
    .movement-in { color: #28a745; font-weight: bold; }
    .movement-out { color: #dc3545; font-weight: bold; }
    .movement-return { color: #17a2b8; font-weight: bold; }
    .current-stock {
        font-size: 1.2em;
        font-weight: bold;
        color: #007bff;
    }
    .alert-low-stock {
        background-color: #fff3cd;
        border-color: #ffeaa7;
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
      <a href="dashboard.php" class="tip-bottom">
        <i class="icon-home"></i> Accueil
      </a>
      <a href="manage-product.php" class="tip-bottom">Gestion Produits</a>
      <strong>Historique du Produit</strong>
    </div>
    <h1>Historique du Produit</h1>
  </div>
  
  <div class="container-fluid">
    <!-- Informations du produit -->
    <div class="row-fluid">
      <div class="span12">
        <div class="product-info">
          <div class="row-fluid">
            <div class="span8">
              <h3><?= htmlspecialchars($product['ProductName']) ?></h3>
              <p><strong>Marque:</strong> <?= htmlspecialchars($product['BrandName']) ?></p>
              <p><strong>Modèle:</strong> <?= htmlspecialchars($product['ModelNumber']) ?></p>
              <p><strong>Description:</strong> <?= htmlspecialchars($product['ProductDescription']) ?></p>
            </div>
            <div class="span4">
              <p class="current-stock">Stock Actuel: <?= $product['Stock'] ?> unités</p>
              <p><strong>Prix:</strong> <?= number_format($product['ProductPrice'], 2) ?> €</p>
              <p><strong>Statut:</strong> 
                <span class="badge <?= $product['Status'] == 1 ? 'badge-success' : 'badge-important' ?>">
                  <?= $product['Status'] == 1 ? 'Actif' : 'Inactif' ?>
                </span>
              </p>
              <?php if($product['Stock'] <= 5): ?>
                <div class="alert alert-low-stock">
                  <strong>Attention!</strong> Stock faible
                </div>
              <?php endif; ?>
            </div>
          </div>
        </div>
      </div>
    </div>

    <hr>
    
    <!-- Historique des mouvements -->
    <div class="row-fluid">
      <div class="span12">
        <div class="widget-box">
          <div class="widget-title">
            <span class="icon"><i class="icon-time"></i></span>
            <h5>Historique des Mouvements de Stock</h5>
          </div>
          <div class="widget-content nopadding">
            <table class="table table-bordered data-table">
              <thead>
                <tr>
                  <th>Date</th>
                  <th>Type de Mouvement</th>
                  <th>Quantité</th>
                  <th>Stock Avant</th>
                  <th>Stock Après</th>
                  <th>Référence</th>
                  <th>Détails</th>
                </tr>
              </thead>
              <tbody>
                <?php
                // Requête pour l'historique des mouvements
                $historyQuery = "
                    (SELECT 
                        cart.IsCheckOut as date_transaction,
                        'Vente' as movement_type,
                        cart.ProductQty as quantity,
                        cart.ProductQty as reference_id,
                        CONCAT('Commande #', cart.ID) as details,
                        cart.IsCheckOut as sort_date
                    FROM tblcart cart 
                    WHERE cart.ProductId = ? AND cart.IsCheckOut = 1)
                    
                    UNION ALL
                    
                    (SELECT 
                        r.ReturnDate as date_transaction,
                        'Retour' as movement_type,
                        r.Quantity as quantity,
                        r.ID as reference_id,
                        CONCAT('Retour - ', r.Reason) as details,
                        r.ReturnDate as sort_date
                    FROM tblreturns r 
                    WHERE r.ProductID = ?)
                    
                    ORDER BY sort_date DESC
                ";
                
                $stmt = mysqli_prepare($con, $historyQuery);
                if ($stmt) {
                    mysqli_stmt_bind_param($stmt, "ii", $productId, $productId);
                    mysqli_stmt_execute($stmt);
                    $result = mysqli_stmt_get_result($stmt);
                    
                    if (mysqli_num_rows($result) > 0) {
                        $current_stock = $product['Stock'];
                        
                        while ($row = mysqli_fetch_assoc($result)) {
                            $quantity = intval($row['quantity']);
                            $movement_type = $row['movement_type'];
                            
                            // Calculer le stock avant ce mouvement
                            if ($movement_type == 'Vente') {
                                $stock_before = $current_stock + $quantity;
                                $stock_after = $current_stock;
                                $css_class = 'movement-out';
                                $quantity_display = '-' . $quantity;
                            } else { // Retour
                                $stock_before = $current_stock - $quantity;
                                $stock_after = $current_stock;
                                $css_class = 'movement-return';
                                $quantity_display = '+' . $quantity;
                            }
                            
                            // Ajuster le stock actuel pour le prochain calcul
                            $current_stock = $stock_before;
                            ?>
                            <tr>
                              <td><?= date('d/m/Y H:i', strtotime($row['date_transaction'])) ?></td>
                              <td>
                                <span class="badge <?= $movement_type == 'Vente' ? 'badge-important' : 'badge-info' ?>">
                                  <?= $movement_type ?>
                                </span>
                              </td>
                              <td class="<?= $css_class ?>"><?= $quantity_display ?></td>
                              <td><?= $stock_before ?></td>
                              <td><?= $stock_after ?></td>
                              <td>#<?= $row['reference_id'] ?></td>
                              <td><?= htmlspecialchars($row['details']) ?></td>
                            </tr>
                            <?php
                        }
                    } else {
                        echo '<tr><td colspan="7" class="text-center">Aucun mouvement trouvé pour ce produit</td></tr>';
                    }
                    mysqli_stmt_close($stmt);
                } else {
                    echo '<tr><td colspan="7" class="text-center text-danger">Erreur lors du chargement de l\'historique</td></tr>';
                }
                ?>
              </tbody>
            </table>
          </div>
        </div>
      </div>
    </div>

    <!-- Statistiques rapides -->
    <div class="row-fluid">
      <div class="span4">
        <div class="widget-box">
          <div class="widget-title">
            <span class="icon"><i class="icon-signal"></i></span>
            <h5>Total Vendus</h5>
          </div>
          <div class="widget-content">
            <?php
            $salesQuery = "SELECT COALESCE(SUM(ProductQty), 0) as total_sold FROM tblcart WHERE ProductId = ? AND IsCheckOut = 1";
            $stmt = mysqli_prepare($con, $salesQuery);
            mysqli_stmt_bind_param($stmt, "i", $productId);
            mysqli_stmt_execute($stmt);
            $salesResult = mysqli_stmt_get_result($stmt);
            $salesData = mysqli_fetch_assoc($salesResult);
            ?>
            <h2 class="text-success"><?= $salesData['total_sold'] ?> unités</h2>
          </div>
        </div>
      </div>
      
      <div class="span4">
        <div class="widget-box">
          <div class="widget-title">
            <span class="icon"><i class="icon-repeat"></i></span>
            <h5>Total Retournés</h5>
          </div>
          <div class="widget-content">
            <?php
            $returnsQuery = "SELECT COALESCE(SUM(Quantity), 0) as total_returned FROM tblreturns WHERE ProductID = ?";
            $stmt = mysqli_prepare($con, $returnsQuery);
            mysqli_stmt_bind_param($stmt, "i", $productId);
            mysqli_stmt_execute($stmt);
            $returnsResult = mysqli_stmt_get_result($stmt);
            $returnsData = mysqli_fetch_assoc($returnsResult);
            ?>
            <h2 class="text-info"><?= $returnsData['total_returned'] ?> unités</h2>
          </div>
        </div>
      </div>
      
      <div class="span4">
        <div class="widget-box">
          <div class="widget-title">
            <span class="icon"><i class="icon-euro"></i></span>
            <h5>Chiffre d'Affaires</h5>
          </div>
          <div class="widget-content">
            <?php
            $revenueQuery = "
                SELECT COALESCE(SUM(cart.ProductQty * p.ProductPrice), 0) as total_revenue 
                FROM tblcart cart 
                JOIN tblproducts p ON p.ID = cart.ProductId 
                WHERE cart.ProductId = ? AND cart.IsCheckOut = 1
            ";
            $stmt = mysqli_prepare($con, $revenueQuery);
            mysqli_stmt_bind_param($stmt, "i", $productId);
            mysqli_stmt_execute($stmt);
            $revenueResult = mysqli_stmt_get_result($stmt);
            $revenueData = mysqli_fetch_assoc($revenueResult);
            ?>
            <h2 class="text-primary"><?= number_format($revenueData['total_revenue'], 2) ?> €</h2>
          </div>
        </div>
      </div>
    </div>
    
  </div>
</div>

<?php include_once('includes/footer.php'); ?>

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
    $('.data-table').dataTable({
        "order": [[ 0, "desc" ]], // Trier par date décroissante
        "pageLength": 25,
        "language": {
            "url": "//cdn.datatables.net/plug-ins/1.10.24/i18n/French.json"
        }
    });
});
</script>

</body>
</html>