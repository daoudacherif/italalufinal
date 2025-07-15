<?php
session_start();
error_reporting(0);
include('includes/dbconnection.php');
if (strlen($_SESSION['imsaid']==0)) {
  header('location:logout.php');
} else{

    // Récupérer le type d'utilisateur connecté
    $adminid = $_SESSION['imsaid'];
    $ret = mysqli_query($con, "SELECT AdminName, UserName FROM tbladmin WHERE ID='$adminid'");
    $row = mysqli_fetch_array($ret);
    $name = $row['AdminName'];
    $username = $row['UserName'];

?>
<!DOCTYPE html>
<html lang="fr">
<head>
  <meta charset="UTF-8">
  <title>Système de Gestion d'Inventaire || Tableau de Bord</title>
  <!-- Viewport meta for responsive scaling -->
  <meta name="viewport" content="width=device-width, initial-scale=1.0">
  
  <!-- Include external CSS (your responsive CSS should be linked here) -->
  <?php include_once('includes/cs.php'); ?>
  <?php include_once('includes/responsive.php'); ?>
  
  <!-- Main application container -->
  <div id="app-container">
    <?php include_once('includes/header.php'); ?>
    <?php include_once('includes/sidebar.php'); ?>
    <!--sidebar-menu-->

    <!-- main-container-part -->
    <div id="content">
      <!--breadcrumbs-->
      <div id="content-header">
        <div id="breadcrumb">
          <a href="dashboard.php" title="Aller à l'accueil" class="tip-bottom">
            <i class="icon-home"></i> Accueil
          </a>
        </div>
      </div>
      <!--End-breadcrumbs-->

      <!--Action boxes-->
      <br />
      <div class="container-fluid">
        <div class="widget-box widget-plain">
          <div class="center">
            <ul class="quick-actions">
              <?php if($username != 'saler'): ?>
              <?php 
              
              $query2 = mysqli_query($con,"Select * from tblcategory where Status='1'");
              $catcount = mysqli_num_rows($query2);
              ?>
              <li class="bg_ly">
                <a href="manage-category.php">
                  <i class="icon-list fa-3x"></i>
                  <span class="label label-success" style="margin-top:7%"><?php echo $catcount; ?></span> Catégories 
                </a>
              </li>
             
              <?php 
              $query4 = mysqli_query($con,"Select * from tblproducts");
              $productcount = mysqli_num_rows($query4);
              ?>
              <li class="bg_ls">
                <a href="manage-product.php">
                  <i class="icon-list-alt"></i>
                  <span class="label label-success" style="margin-top:7%"><?php echo $productcount; ?></span> Articles
                </a>
              </li>
              <?php 
              $query5 = mysqli_query($con,"Select * from tblcustomer");
              $totuser = mysqli_num_rows($query5);
              ?>
              
              <li class="bg_lo span3">
                <a href="profile.php">
                  <i class="icon-user"></i>
                  <span class="label label-success" style="margin-top:5%"><?php echo $totuser; ?></span> Utilisateurs
                </a>
              </li>
              
            </ul>
            <?php endif; ?>
          </div>
        </div>
        <div class="widget-box widget-plain" style="margin-top:12%">
          <div class="center">
            <h3 style="color:blue">Ventes</h3>
            <hr />
            <ul class="site-stats">
              <?php
              // Vente d'aujourd'hui
              $todysale = 0;
              $query6 = mysqli_query($con,"select tblcart.ProductQty as ProductQty,tblproducts.Price
                from tblcart join tblproducts on tblproducts.ID=tblcart.ProductId 
                where date(CartDate)=CURDATE() and IsCheckOut='1'");
              while($row = mysqli_fetch_array($query6))
              {
                $todays_sale = $row['ProductQty'] * $row['Price'];
                $todysale += $todays_sale;
              }
              
              // Ajout des ventes à crédit d'aujourd'hui
              $query6credit = mysqli_query($con,"select tblcreditcart.ProductQty as ProductQty,tblproducts.Price
                from tblcreditcart join tblproducts on tblproducts.ID=tblcreditcart.ProductId 
                where date(CartDate)=CURDATE() and IsCheckOut='1'");
              while($row = mysqli_fetch_array($query6credit))
              {
                $todays_credit_sale = $row['ProductQty'] * $row['Price'];
                $todysale += $todays_credit_sale;
              }
              ?>
              <li class="bg_lh">
                <font style="font-size:22px; font-weight:bold">Gnf</font><strong><?php echo number_format($todysale,2); ?></strong>
                <small>Ventes d'aujourd'hui</small>
              </li>
              
              <?php
              // Vente d'hier
              $yesterdaysale = 0;
              $query7 = mysqli_query($con,"select tblcart.ProductQty as ProductQty,tblproducts.Price
                from tblcart join tblproducts on tblproducts.ID=tblcart.ProductId 
                where date(CartDate)=CURDATE()-1 and IsCheckOut='1'");
              while($row = mysqli_fetch_array($query7))
              {
                $yesterdays_sale = $row['ProductQty'] * $row['Price'];
                $yesterdaysale += $yesterdays_sale;
              }
              
              // Ajout des ventes à crédit d'hier
              $query7credit = mysqli_query($con,"select tblcreditcart.ProductQty as ProductQty,tblproducts.Price
                from tblcreditcart join tblproducts on tblproducts.ID=tblcreditcart.ProductId 
                where date(CartDate)=CURDATE()-1 and IsCheckOut='1'");
              while($row = mysqli_fetch_array($query7credit))
              {
                $yesterdays_credit_sale = $row['ProductQty'] * $row['Price'];
                $yesterdaysale += $yesterdays_credit_sale;
              }
              ?>
              <li class="bg_lh">
                <font style="font-size:22px; font-weight:bold">Gnf</font><strong><?php echo number_format($yesterdaysale,2); ?></strong>
                <small>Ventes d'hier</small>
              </li>
              
              <?php
              // Vente des sept derniers jours
              $tseven = 0;
              $query8 = mysqli_query($con,"select tblcart.ProductQty as ProductQty,tblproducts.Price
                from tblcart join tblproducts on tblproducts.ID=tblcart.ProductId 
                where date(tblcart.CartDate)>=(DATE(NOW()) - INTERVAL 7 DAY) and tblcart.IsCheckOut='1'");
              while($row = mysqli_fetch_array($query8))
              {
                $sevendays_sale = $row['ProductQty'] * $row['Price'];
                $tseven += $sevendays_sale;
              }
              
              // Ajout des ventes à crédit des 7 derniers jours
              $query8credit = mysqli_query($con,"select tblcreditcart.ProductQty as ProductQty,tblproducts.Price
                from tblcreditcart join tblproducts on tblproducts.ID=tblcreditcart.ProductId 
                where date(tblcreditcart.CartDate)>=(DATE(NOW()) - INTERVAL 7 DAY) and tblcreditcart.IsCheckOut='1'");
              while($row = mysqli_fetch_array($query8credit))
              {
                $sevendays_credit_sale = $row['ProductQty'] * $row['Price'];
                $tseven += $sevendays_credit_sale;
              }
              ?>
              <li class="bg_lh">
                <font style="font-size:22px; font-weight:bold">Gnf</font><strong><?php echo number_format($tseven,2); ?></strong>
                <small>Ventes des sept derniers jours</small>
              </li>
              
              <?php
              // Vente totale
              $totalsale = 0;
              $query9 = mysqli_query($con,"select tblcart.ProductQty as ProductQty,tblproducts.Price
                from tblcart join tblproducts on tblproducts.ID=tblcart.ProductId where IsCheckOut='1'");
              while($row = mysqli_fetch_array($query9))
              {
                $total_sale = $row['ProductQty'] * $row['Price'];
                $totalsale += $total_sale;
              }
              
              // Ajout des ventes à crédit totales
              $query9credit = mysqli_query($con,"select tblcreditcart.ProductQty as ProductQty,tblproducts.Price
                from tblcreditcart join tblproducts on tblproducts.ID=tblcreditcart.ProductId where IsCheckOut='1'");
              while($row = mysqli_fetch_array($query9credit))
              {
                $total_credit_sale = $row['ProductQty'] * $row['Price'];
                $totalsale += $total_credit_sale;
              }
              ?>
              <li class="bg_lh">
                <font style="font-size:22px; font-weight:bold">Gnf</font><strong><?php echo number_format($totalsale,2); ?></strong>
                <small>Ventes totales</small>
              </li>
            </ul>
          </div>
        </div>
      </div>
    </div>
    <?php include_once('includes/footer.php'); ?>
  </div><!-- End of #app-container -->

  <!-- Include external JS -->
  <?php include_once('includes/js.php'); ?>
  
  <!-- Optionally, inline JavaScript for additional functionality -->
  <script>
    // Example: Toggle hamburger menu if you want to show/hide the sidebar in mobile view
    document.getElementById('my_menu_input') && document.getElementById('my_menu_input').addEventListener('click', function(){
      var sidebar = document.getElementById('sidebar');
      if(sidebar.style.display === "block") {
        sidebar.style.display = "none";
      } else {
        sidebar.style.display = "block";
      }
    });
  </script>
  
</body>
</html>
<?php } ?>