<?php
session_start();
error_reporting(0);
include('includes/dbconnection.php');
if (strlen($_SESSION['imsaid']==0)) {
  header('location:logout.php');
} else {
// Code pour ajouter au panier
if(isset($_POST['cart'])) {
  $pid = intval($_POST['pid']);
  $pqty = intval($_POST['pqty']);
  $price = floatval($_POST['price']);
  $ischecout = 0;
  
  // Vérifier le stock disponible
  $stockQuery = mysqli_query($con, "SELECT Stock FROM tblproducts WHERE ID = '$pid'");
  $stockData = mysqli_fetch_assoc($stockQuery);
  $totalStock = $stockData['Stock'];
  
  // Vérifier combien sont déjà dans le panier
  $cartQuery = mysqli_query($con, "SELECT SUM(ProductQty) as cart_qty FROM tblcart WHERE ProductId = '$pid' AND IsCheckOut = 0");
  $cartData = mysqli_fetch_assoc($cartQuery);
  $cartQty = $cartData['cart_qty'] ?: 0;
  
  // Calculer stock restant
  $remainingStock = $totalStock - $cartQty;
  
  if($pqty <= $remainingStock) {
    $query = mysqli_query($con, "INSERT INTO tblcart(ProductId, ProductQty, Price, IsCheckOut) VALUES('$pid', '$pqty', '$price', '$ischecout')");
    if($query) {
      echo "<script>alert('Le produit a été ajouté au panier');</script>"; 
      echo "<script>window.location.href = 'search.php'</script>";
    } else {
      echo "<script>alert('Erreur lors de l'ajout au panier');</script>";
    }
  } else {
    echo "<script>alert('Vous ne pouvez pas ajouter une quantité supérieure à la quantité restante ($remainingStock)');</script>";
  }
}
?>
<!DOCTYPE html>
<html lang="fr">
<head>
<title>Système de gestion des stocks || Rechercher des Articles</title>
<?php include_once('includes/cs.php');?>
<?php include_once('includes/responsive.php'); ?>
<style>
  .stock-status {
    display: inline-block;
    padding: 2px 6px;
    border-radius: 3px;
    font-size: 12px;
    font-weight: bold;
  }
  .in-stock {
    background-color: #dff0d8;
    color: #3c763d;
  }
  .low-stock {
    background-color: #fcf8e3;
    color: #8a6d3b;
  }
  .out-of-stock {
    background-color: #f2dede;
    color: #a94442;
  }
  .search-form {
    background-color: #f5f5f5;
    padding: 15px;
    border-radius: 5px;
    margin-bottom: 20px;
  }
  .search-form .control-group {
    margin-bottom: 0;
  }
</style>
</head>
<body>
<?php include_once('includes/header.php');?>
<?php include_once('includes/sidebar.php');?>

<div id="content">
  <div id="content-header">
    <div id="breadcrumb"> <a href="dashboard.php" title="Aller à l'accueil" class="tip-bottom"><i class="icon-home"></i> Accueil</a> <a href="search.php" class="current">Rechercher des Articles</a> </div>
    <h1>Rechercher des Articles</h1>
  </div>
  <div class="container-fluid">
    <hr>
    <div class="row-fluid">
      <div class="span12">
        <div class="widget-box search-form">
          <div class="widget-title">
            <span class="icon"><i class="icon-search"></i></span>
            <h5>Rechercher un Article</h5>
          </div>
          <div class="widget-content">
            <form method="post" class="form-horizontal">
              <div class="control-group">
                <label class="control-label">Rechercher par nom :</label>
                <div class="controls">
                  <input type="text" class="span6" name="pname" id="pname" value="<?php echo isset($_POST['pname']) ? htmlspecialchars($_POST['pname']) : ''; ?>" required='true' placeholder="Entrez le nom de l'article..." />
                  <button class="btn btn-primary" type="submit" name="search"><i class="icon-search"></i> Rechercher</button>
                </div>
              </div>
            </form>
          </div>
        </div>
        
        <?php
        if(isset($_POST['search'])) { 
          $sdata = mysqli_real_escape_string($con, $_POST['pname']);
        ?>
        <h4>Résultat pour le mot-clé "<?php echo htmlspecialchars($sdata); ?>"</h4> 
        
        <div class="widget-box">
          <div class="widget-title"> 
            <span class="icon"><i class="icon-th"></i></span>
            <h5>Articles trouvés</h5>
          </div>
          <div class="widget-content nopadding">
            <table class="table table-bordered table-striped data-table">
              <thead>
                <tr>
                  <th width="5%">N°</th>
                  <th width="20%">Nom de l'article</th>
                  <th width="10%">Catégorie</th>
                  <th width="10%">Sous-catégorie</th>
                  <th width="10%">Marque</th>
                  <th width="10%">Modèle</th>
                  <th width="10%">Prix</th>
                  <th width="8%">Stock</th>
                  <th width="8%">Disponible</th>
                  <th width="10%">Statut</th>
                  <th width="10%">Action</th>
                </tr>
              </thead>
              <tbody>
              <?php
              // Requête SQL améliorée avec jointures
              $ret = mysqli_query($con, "
                SELECT 
                  p.ID as pid,
                  p.ProductName,
                  p.BrandName,
                  p.ModelNumber,
                  p.Stock,
                  p.Price,
                  p.Status,
                  p.CreationDate,
                  c.CategoryName,
                  sc.SubCategoryname as subcat,
                  IFNULL(SUM(cart.ProductQty), 0) as selledqty
                FROM 
                  tblproducts p
                LEFT JOIN 
                  tblcategory c ON c.ID = p.CatID
                LEFT JOIN 
                  tblsubcategory sc ON sc.ID = p.SubcatID
                LEFT JOIN 
                  tblcart cart ON p.ID = cart.ProductId AND cart.IsCheckOut = 0
                WHERE 
                  p.ProductName LIKE '%$sdata%'
                GROUP BY 
                  p.ID
                ORDER BY 
                  p.ProductName ASC
              ");
              
              $num = mysqli_num_rows($ret);
              if($num > 0) {
                $cnt = 1;
                while ($row = mysqli_fetch_array($ret)) {
                  // Calculer le stock disponible
                  $totalStock = $row['Stock'];
                  $inCartQty = $row['selledqty'];
                  $availableStock = $totalStock - $inCartQty;
                  
                  // Déterminer le statut du stock
                  $stockStatusClass = '';
                  $stockStatusText = '';
                  
                  if($availableStock <= 0) {
                    $stockStatusClass = 'out-of-stock';
                    $stockStatusText = 'Épuisé';
                  } elseif($availableStock < 5) {
                    $stockStatusClass = 'low-stock';
                    $stockStatusText = 'Faible';
                  } else {
                    $stockStatusClass = 'in-stock';
                    $stockStatusText = 'Disponible';
                  }
                  
                  // Déterminer si le produit est actif
                  $isActive = ($row['Status'] == "1");
              ?>
                <tr>
                  <td><?php echo $cnt; ?></td>
                  <td><?php echo htmlspecialchars($row['ProductName']); ?></td>
                  <td><?php echo htmlspecialchars($row['CategoryName']); ?></td>
                  <td><?php echo htmlspecialchars($row['subcat']); ?></td>
                  <td><?php echo htmlspecialchars($row['BrandName']); ?></td>
                  <td><?php echo htmlspecialchars($row['ModelNumber']); ?></td>
                  <td><?php echo number_format($row['Price'], 2); ?></td>
                  <td><?php echo $row['Stock']; ?></td>
                  <td>
                    <?php echo $availableStock; ?>
                    <span class="stock-status <?php echo $stockStatusClass; ?>"><?php echo $stockStatusText; ?></span>
                  </td>
                  <td>
                    <?php if($isActive): ?>
                      <span class="label label-success">Actif</span>
                    <?php else: ?>
                      <span class="label label-important">Inactif</span>
                    <?php endif; ?>
                  </td>
                  <td>
                    <?php if($isActive && $availableStock > 0): ?>
                      <form name="cart" method="post">
                        <input type="hidden" name="pid" value="<?php echo $row['pid']; ?>">
                        <input type="hidden" name="price" value="<?php echo $row['Price']; ?>">
                        <div class="input-append">
                          <input type="number" name="pqty" value="1" min="1" max="<?php echo $availableStock; ?>" required="true" style="width:40px;">
                          <button type="submit" name="cart" class="btn btn-success btn-small">
                            <i class="icon-shopping-cart"></i>
                          </button>
                        </div>
                      </form>
                    <?php else: ?>
                      <button class="btn btn-small" disabled>Indisponible</button>
                    <?php endif; ?>
                  </td>
                </tr>
              <?php 
                  $cnt++;
                }
              } else { 
              ?>
                <tr>
                  <td colspan="11" class="text-center">Aucun article trouvé correspondant à votre recherche.</td>
                </tr>
              <?php 
              } 
              ?>
              </tbody>
            </table>
          </div>
        </div>
        <?php } ?>
      </div>
    </div>
  </div>
</div>

<!--Footer-part-->
<?php include_once('includes/footer.php');?>
<!--end-Footer-part-->

<script src="js/jquery.min.js"></script> 
<script src="js/jquery.ui.custom.js"></script> 
<script src="js/bootstrap.min.js"></script> 
<script src="js/jquery.uniform.js"></script> 
<script src="js/select2.min.js"></script> 
<script src="js/matrix.js"></script> 
<script src="js/matrix.tables.js"></script>
</body>
</html>
<?php } ?>