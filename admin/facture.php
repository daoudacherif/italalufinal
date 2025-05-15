<?php
session_start();
error_reporting(0);
include('includes/dbconnection.php');

// Vérifier si l'admin est connecté
if (strlen($_SESSION['imsaid']) == 0) {
  header('location:logout.php');
  exit;
}

// ==========================
// 1) Gérer la suppression d'une facture
// ==========================
if (isset($_GET['delete_id']) && !empty($_GET['delete_id'])) {
  $billingId = intval($_GET['delete_id']);
  $type = $_GET['type']; // 'cart' ou 'credit'
  
  // Table à utiliser selon le type
  $tableToUse = ($type == 'credit') ? 'tblcreditcart' : 'tblcart';
  
  // 1. Récupérer les données de la facture pour mettre à jour le stock
  $sqlCartItems = "SELECT ProductId, ProductQty FROM $tableToUse WHERE BillingId='$billingId'";
  $cartQuery = mysqli_query($con, $sqlCartItems);
  
  // 2. Mettre à jour le stock pour chaque produit
  $updateSuccess = true;
  while ($item = mysqli_fetch_assoc($cartQuery)) {
    $productId = $item['ProductId'];
    $quantity = $item['ProductQty'];
    
    // Mettre à jour le stock dans tblproducts (augmenter le stock)
    $updateStock = "UPDATE tblproducts SET Stock = Stock + $quantity WHERE ID='$productId'";
    if (!mysqli_query($con, $updateStock)) {
      $updateSuccess = false;
      break;
    }
  }
  
  // 3. Supprimer les données de la facture
  if ($updateSuccess) {
    // Supprimer de la table appropriée
    $deleteQuery = "DELETE FROM $tableToUse WHERE BillingId='$billingId'";
    mysqli_query($con, $deleteQuery);
    
    echo "<script>alert('Facture supprimée avec succès et stock mis à jour!');</script>";
  } else {
    echo "<script>alert('Erreur lors de la mise à jour du stock.');</script>";
  }
  
  echo "<script>window.location.href='facture.php'</script>";
  exit;
}
?>
<!DOCTYPE html>
<html lang="fr">
<head>
  <title>Gestion des stocks | Factures</title>
  <?php include_once('includes/cs.php'); ?>
  <?php include_once('includes/responsive.php'); ?>
</head>
<body>

<!-- Header + Sidebar -->
<?php include_once('includes/header.php'); ?>
<?php include_once('includes/sidebar.php'); ?>

<div id="content">
  <div id="content-header">
    <div id="breadcrumb">
      <a href="dashboard.php" title="Aller à l'accueil" class="tip-bottom">
        <i class="icon-home"></i> Accueil
      </a>
      <a href="facture.php" class="current">Factures</a>
    </div>
    <h1>Gérer les factures</h1>
  </div>

  <div class="container-fluid">
    <hr>

    <!-- =========== ONGLETS POUR SÉPARER LES FACTURES =========== -->
    <div class="widget-box">
      <div class="widget-title">
        <ul class="nav nav-tabs">
          <li class="active"><a data-toggle="tab" href="#tab-comptant">Factures Comptant</a></li>
          <li><a data-toggle="tab" href="#tab-credit">Factures à Terme</a></li>
        </ul>
      </div>
      <div class="widget-content tab-content">
        <!-- ONGLET FACTURES COMPTANT -->
        <div id="tab-comptant" class="tab-pane active">
          <div class="widget-box">
            <div class="widget-title">
              <span class="icon"><i class="icon-th"></i></span>
              <h5>Liste des factures comptant</h5>
            </div>
            <div class="widget-content nopadding">
              <table class="table table-bordered data-table">
                <thead>
                  <tr>
                    <th>#</th>
                    <th>Numéro de facture</th>
                    <th>Date</th>
                    <th>Nombre d'articles</th>
                    <th>Total</th>
                    <th>Action</th>
                  </tr>
                </thead>
                <tbody>
                  <?php
                  // Récupérer la liste des factures comptant
                  $sqlFacturesComptant = "
                    SELECT 
                      BillingId, 
                      CartDate,
                      COUNT(*) as ItemCount,
                      SUM(Price * ProductQty) as Total
                    FROM tblcart 
                    WHERE IsCheckOut = 1
                    GROUP BY BillingId, CartDate
                    ORDER BY CartDate DESC
                  ";
                  $factureComptantQuery = mysqli_query($con, $sqlFacturesComptant);
                  $cnt = 1;
                  while ($row = mysqli_fetch_assoc($factureComptantQuery)) {
                    ?>
                    <tr>
                      <td><?php echo $cnt; ?></td>
                      <td><?php echo $row['BillingId']; ?></td>
                      <td><?php echo $row['CartDate']; ?></td>
                      <td><?php echo $row['ItemCount']; ?></td>
                      <td><?php echo number_format($row['Total'], 2); ?> €</td>
                      <td>
                        <a href="facture-details.php?id=<?php echo $row['BillingId']; ?>&type=cart" class="btn btn-info btn-mini">
                          <i class="icon-eye-open"></i> Détails
                        </a>
                        <a href="facture.php?delete_id=<?php echo $row['BillingId']; ?>&type=cart" 
                          class="btn btn-danger btn-mini" 
                          onclick="return confirm('Êtes-vous sûr de vouloir supprimer cette facture? Cette action mettra à jour le stock des produits.')">
                          <i class="icon-trash"></i> Supprimer
                        </a>
                      </td>
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
        
        <!-- ONGLET FACTURES À TERME -->
        <div id="tab-credit" class="tab-pane">
          <div class="widget-box">
            <div class="widget-title">
              <span class="icon"><i class="icon-th"></i></span>
              <h5>Liste des factures à terme</h5>
            </div>
            <div class="widget-content nopadding">
              <table class="table table-bordered data-table">
                <thead>
                  <tr>
                    <th>#</th>
                    <th>Numéro de facture</th>
                    <th>Date</th>
                    <th>Nombre d'articles</th>
                    <th>Total</th>
                    <th>Action</th>
                  </tr>
                </thead>
                <tbody>
                  <?php
                  // Récupérer la liste des factures à crédit
                  $sqlFacturesCredit = "
                    SELECT 
                      BillingId, 
                      CartDate,
                      COUNT(*) as ItemCount,
                      SUM(Price * ProductQty) as Total
                    FROM tblcreditcart 
                    WHERE IsCheckOut = 1
                    GROUP BY BillingId, CartDate
                    ORDER BY CartDate DESC
                  ";
                  $factureCreditQuery = mysqli_query($con, $sqlFacturesCredit);
                  $cnt = 1;
                  while ($row = mysqli_fetch_assoc($factureCreditQuery)) {
                    ?>
                    <tr>
                      <td><?php echo $cnt; ?></td>
                      <td><?php echo $row['BillingId']; ?></td>
                      <td><?php echo $row['CartDate']; ?></td>
                      <td><?php echo $row['ItemCount']; ?></td>
                      <td><?php echo number_format($row['Total'], 2); ?> €</td>
                      <td>
                        <a href="facture-details.php?id=<?php echo $row['BillingId']; ?>&type=credit" class="btn btn-info btn-mini">
                          <i class="icon-eye-open"></i> Détails
                        </a>
                        <a href="facture.php?delete_id=<?php echo $row['BillingId']; ?>&type=credit" 
                          class="btn btn-danger btn-mini" 
                          onclick="return confirm('Êtes-vous sûr de vouloir supprimer cette facture? Cette action mettra à jour le stock des produits.')">
                          <i class="icon-trash"></i> Supprimer
                        </a>
                      </td>
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
    </div>
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
</body>
</html>