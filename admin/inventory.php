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
      <strong>Voir l'Inventaire des Articles</strong>
    </div>
    <h1>Inventaire des Articles</h1>
  </div>
  <div class="container-fluid">
    <hr>
    <div class="row-fluid">
      <div class="span12">
        <div class="widget-box">
          <div class="widget-title">
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
                  <th>Stock Restant</th>
                  <th>Statut</th>
                </tr>
              </thead>
              <tbody>
                <?php
                // On ne prend que les lignes de panier validées (IsCheckOut = 1)
                $sql = "
                  SELECT 
                    p.ID            AS pid,
                    p.ProductName,
                    COALESCE(c.CategoryName, 'N/A') AS CategoryName,
                    p.BrandName,
                    p.ModelNumber,
                    p.Stock         AS initial_stock,
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
                    // Calcul du stock restant en tenant compte des retours
                    $sold = intval($row['sold_qty']);
                    $returned = intval($row['returned_qty']);
                    $initial = intval($row['initial_stock']);
                    
                    // Le stock restant est: initial - vendu + retourné
                    $remaining = $initial - $sold;
                    $remaining = max(0, $remaining);
                    ?>
                    <tr>
                      <td><?= $cnt ?></td>
                      <td><?= htmlspecialchars($row['ProductName']) ?></td>
                      <td><?= htmlspecialchars($row['CategoryName']) ?></td>
                      <td><?= htmlspecialchars($row['BrandName']) ?></td>
                      <td><?= htmlspecialchars($row['ModelNumber']) ?></td>
                      <td><?= $initial ?></td>
                      <td><?= $sold ?></td>
                      <td><?= $returned ?></td>
                      <td class="<?= $remaining === 0 ? 'text-danger' : '' ?>">
                        <?= $remaining === 0 ? 'Épuisé' : $remaining ?>
                      </td>
                      <td><?= $row['Status'] == 1 ? 'Actif' : 'Inactif' ?></td>
                    </tr>
                    <?php
                    $cnt++;
                  }
                } else {
                  echo '<tr><td colspan="10" class="text-center">Aucun Article trouvé</td></tr>';
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

<!-- scripts pour DataTable si nécessaire -->
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