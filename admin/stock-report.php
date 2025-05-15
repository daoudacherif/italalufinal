<?php
session_start();
error_reporting(0);
include('includes/dbconnection.php');
if (strlen($_SESSION['imsaid'])==0) {
  header('location:logout.php');
} else {
?>
<!DOCTYPE html>
<html lang="fr">
<head>
  <title>Système de Gestion d'Inventaire || Rapport de Stock</title>
  <?php include_once('includes/cs.php');?>
  <?php include_once('includes/responsive.php'); ?>
</head>
<body>
  <!--Header-part-->
  <?php include_once('includes/header.php');?>
  <?php include_once('includes/sidebar.php');?>

  <div id="content">
    <div id="content-header">
      <div id="breadcrumb"> 
        <a href="dashboard.php" title="Aller à l'accueil" class="tip-bottom"><i class="icon-home"></i> Accueil</a> 
        <a href="stock-report.php" class="current">Rapports de Stock</a>
      </div>
      <h1>Rapports de Stock</h1>
    </div>
    <div class="container-fluid">
      <hr>
      <div class="row-fluid">
        <div class="span12">
          <div class="widget-box">
            <div class="widget-title"> 
              <span class="icon"><i class="icon-calendar"></i></span>
              <h5>Générer un Rapport de Stock Entre Dates</h5>
            </div>
            <div class="widget-content nopadding">
              <form method="post" class="form-horizontal" action="bwdates-stock-reports.php">
                <div class="control-group">
                  <label class="control-label">De Date :</label>
                  <div class="controls">
                    <input type="date" class="span11" name="fromdate" id="fromdate" required='true' />
                  </div>
                </div>
                <div class="control-group">
                  <label class="control-label">À Date :</label>
                  <div class="controls">
                    <input type="date" class="span11" name="todate" id="todate" required='true' />
                  </div>
                </div>
                <div class="form-actions">
                  <button type="submit" class="btn btn-success" name="submit">Générer le Rapport</button>
                </div>
              </form>
            </div>
          </div>
          
          <!-- Stock Report Preview -->
          <div class="widget-box">
            <div class="widget-title">
              <span class="icon"><i class="icon-th"></i></span>
              <h5>Aperçu des Données d'Inventaire</h5>
            </div>
            <div class="widget-content nopadding">
              <table class="table table-bordered table-striped">
                <thead>
                  <tr>
                    <th>ID</th>
                    <th>Nom Produit</th>
                    <th>ID Catégorie</th>
                    <th>ID Sous-catégorie</th>
                    <th>Marque</th>
                    <th>Numéro Modèle</th>
                    <th>Stock</th>
                    <th>Prix</th>
                    <th>Status</th>
                    <th>Date Création</th>
                  </tr>
                </thead>
                <tbody>
                  <?php
                  $query = mysqli_query($con, "SELECT * FROM tblproducts ORDER BY ID DESC LIMIT 10");
                  $cnt = 1;
                  while($row = mysqli_fetch_array($query)) {
                  ?>
                  <tr>
                    <td><?php echo $cnt;?></td>
                    <td><?php echo $row['ProductName'];?></td>
                    <td><?php echo $row['CatID'];?></td>
                    <td><?php echo $row['SubcatID'];?></td>
                    <td><?php echo $row['BrandName'];?></td>
                    <td><?php echo $row['ModelNumber'];?></td>
                    <td><?php echo $row['Stock'];?></td>
                    <td><?php echo $row['Price'];?></td>
                    <td><?php echo $row['Status'] == '1' ? 'Active' : 'Inactive';?></td>
                    <td><?php echo $row['CreationDate'];?></td>
                  </tr>
                  <?php 
                  $cnt++;
                  } ?>
                </tbody>
              </table>
            </div>
          </div>
        </div>
      </div>
    </div>
  </div>
  
  <?php include_once('includes/footer.php');?>
  <?php include_once('includes/js.php');?>
</body>
</html>
<?php } ?>