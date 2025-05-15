<?php 
session_start();
error_reporting(E_ALL);
include('includes/dbconnection.php');

// Vérifier si l'admin est connecté
if (empty($_SESSION['imsaid'])) {
    header('location:logout.php');
    exit;
}

// Désactivation d'un produit
if (isset($_GET['delid'])) {
    $delid = intval($_GET['delid']);
    mysqli_query($con, "UPDATE tblproducts SET Status = 0 WHERE ID = $delid");
    echo "<script>alert('Produit désactivé avec succès');</script>";
    echo "<script>window.location.href='manage-inventory.php';</script>";
    exit;
}

// Activation d'un produit
if (isset($_GET['actid'])) {
    $actid = intval($_GET['actid']);
    mysqli_query($con, "UPDATE tblproducts SET Status = 1 WHERE ID = $actid");
    echo "<script>alert('Produit activé avec succès');</script>";
    echo "<script>window.location.href='manage-inventory.php';</script>";
    exit;
}
?>
<!DOCTYPE html>
<html lang="fr">
<head>
  <meta charset="UTF-8">
  <title>Gestion des Articles</title>
  <?php include_once('includes/cs.php'); ?>
  <?php include_once('includes/responsive.php'); ?>
  <style>
    .text-danger { color: #d9534f; }
    .btn-action { margin: 2px; }
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
      <strong>Gestion des Articles</strong>
    </div>
    <h1>Gestion des Articles</h1>
  </div>
  <div class="container-fluid">
    <hr>
    <div class="row-fluid">
      <div class="span12">
        <div class="widget-box">
          <div class="widget-title">
            <span class="icon"><i class="icon-th"></i></span>
            <h5>Gestion des Articles</h5>
            <span class="icon">
              <a href="add-product.php" class="btn btn-mini btn-success" style="margin: 5px;">
                <i class="icon-plus"></i> Ajouter un article
              </a>
            </span>
          </div>
          <div class="widget-content nopadding">
            <table class="table table-bordered data-table">
              <thead>
                <tr>
                  <th>N°</th>
                  <th>Nom</th>
                  <th>Catégorie</th>
                  <th>Marque</th>
                  <th>Modèle</th>
                  <th>Stock Initial</th>
                  <th>Vendus</th>
                  <th>Stock Restant</th>
                  <th>Prix</th>
                  <th>Statut</th>
                  <th>Actions</th>
                </tr>
              </thead>
              <tbody>
                <?php
                // Requête SQL avec ajout du champ Prix
                $sql = "
                  SELECT 
                    p.ID            AS pid,
                    p.ProductName,
                    COALESCE(c.CategoryName, 'N/A') AS CategoryName,
                    p.BrandName,
                    p.ModelNumber,
                    p.Stock         AS initial_stock,
                    p.Price,
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
                  ORDER BY p.ID DESC
                ";
                $ret = mysqli_query($con, $sql) 
                  or die('Erreur SQL : ' . mysqli_error($con));

                if (mysqli_num_rows($ret) > 0) {
                  $cnt = 1;
                  while ($row = mysqli_fetch_assoc($ret)) {
                    // Calcul du stock restant en tenant compte des ventes
                    $sold = intval($row['sold_qty']);
                    $initial = intval($row['initial_stock']);
                    
                    // Le stock restant est: initial - vendu
                    $remaining = $initial - $sold;
                    $remaining = max(0, $remaining);
                    
                    // Préparation du label de statut
                    $statusLabel = $row['Status'] == 1
                        ? '<span class="label label-success">Actif</span>'
                        : '<span class="label label-important">Inactif</span>';
                    ?>
                    <tr>
                      <td><?= $cnt ?></td>
                      <td><?= htmlspecialchars($row['ProductName']) ?></td>
                      <td><?= htmlspecialchars($row['CategoryName']) ?></td>
                      <td><?= htmlspecialchars($row['BrandName']) ?></td>
                      <td><?= htmlspecialchars($row['ModelNumber']) ?></td>
                      <td><?= $initial ?></td>
                      <td><?= $sold ?></td>
                      <td class="<?= $remaining === 0 ? 'text-danger' : '' ?>">
                        <?= $remaining === 0 ? 'Épuisé' : $remaining ?>
                      </td>
                      <td><?= number_format(floatval($row['Price']), 2) ?></td>
                      <td class="center"><?= $statusLabel ?></td>
                      <td class="center">
                          <a href="editproducts.php?editid=<?= $row['pid'] ?>" class="btn btn-mini btn-info btn-action">
                              <i class="icon-edit"></i>
                          </a>
                          <?php if ($row['Status'] == 1): ?>
                              <a href="manage-inventory.php?delid=<?= $row['pid'] ?>" 
                                 onclick="return confirm('Voulez-vous désactiver cet article ?')" 
                                 class="btn btn-mini btn-danger btn-action">
                                  <i class="icon-unlock"></i> Désactiver
                              </a>
                          <?php else: ?>
                              <a href="manage-inventory.php?actid=<?= $row['pid'] ?>" 
                                 onclick="return confirm('Voulez-vous activer cet article ?')" 
                                 class="btn btn-mini btn-success btn-action">
                                  <i class="icon-lock"></i> Activer
                              </a>
                          <?php endif; ?>
                      </td>
                    </tr>
                    <?php
                    $cnt++;
                  }
                } else {
                  echo '<tr><td colspan="11" class="text-center">Aucun article trouvé</td></tr>';
                }
                ?>
              </tbody>
            </table>
          </div><!-- widget-content -->
        </div><!-- widget-box -->
      </div><!-- span12 -->
    </div><!-- row-fluid -->
  </div><!-- container-fluid -->
</div><!-- content -->

<?php include_once('includes/footer.php'); ?>

<!-- scripts pour DataTable -->
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