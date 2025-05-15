<?php
session_start();
error_reporting(0);
include('includes/dbconnection.php');
if (strlen($_SESSION['imsaid']==0)) {
  header('location:logout.php');
} else {
?>
<!DOCTYPE html>
<html lang="fr">
<head>
<title>Système de gestion d'inventaire || Détails du rapport de ventes</title>
<?php include_once('includes/cs.php');?>
<?php include_once('includes/responsive.php'); ?>
<style>
  /* Styles pour l'interface normale */
  .report-box {
    background-color: #f9f9f9;
    border: 1px solid #ddd;
    border-radius: 4px;
    padding: 15px;
    margin-bottom: 20px;
  }
  .report-header {
    border-bottom: 1px solid #eee;
    padding-bottom: 10px;
    margin-bottom: 15px;
  }
  .report-total {
    font-weight: bold;
    color: #d9534f;
  }
  .print-header {
    display: none;
  }
  
  /* Styles spécifiques pour l'impression */
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
    
    /* Forcer l'impression en noir et blanc par défaut */
    * {
      color: black !important;
      text-shadow: none !important;
      filter: none !important;
      -ms-filter: none !important;
    }
    
    /* Sauf pour certains éléments spécifiques */
    .report-total {
      color: #d9534f !important;
    }
    
    /* Assurer que les liens sont visibles et sans URL */
    a, a:visited {
      text-decoration: underline;
    }
    a[href]:after {
      content: "";
    }
    
    /* Masquer le bouton d'impression */
    .btn-print, .no-print {
      display: none !important;
    }
  }
</style>
</head>
<body>
<!-- Éléments qui seront cachés à l'impression -->
<div class="no-print">
  <?php include_once('includes/header.php');?>
  <?php include_once('includes/sidebar.php');?>
</div>

<div id="content">
  <!-- En-tête de contenu - caché à l'impression -->
  <div id="content-header" class="no-print">
    <div id="breadcrumb"> <a href="dashboard.php" title="Aller à l'accueil" class="tip-bottom"><i class="icon-home"></i> Accueil</a> <a href="sales-report.php" class="current">Détails du rapport de ventes</a> </div>
    <h1>Détails du rapport de ventes</h1>
  </div>
  
  <div class="container-fluid">
    <hr class="no-print">
    <div class="row-fluid">
      <div class="span12" id="printArea">
        <!-- En-tête qui n'apparaît qu'à l'impression -->
        <div class="print-header">
          <h2>Système de Gestion d'Inventaire</h2>
          <p>Rapport de ventes</p>
        </div>
        
        <div class="report-box">
          <?php
            $fdate = $_POST['fromdate'];
            $tdate = $_POST['todate'];
            $rtype = $_POST['requesttype'];
          ?>
          
          <?php if($rtype=='mtwise'){ 
            $month1 = strtotime($fdate);
            $month2 = strtotime($tdate);
            $m1 = date("F", $month1);
            $m2 = date("F", $month2);
            $y1 = date("Y", $month1);
            $y2 = date("Y", $month2);
          ?>
          
          <div class="report-header">
            <h3>Rapport de ventes de <?php echo $m1."-".$y1;?> à <?php echo $m2."-".$y2;?></h3>
          </div>
          
          <div class="widget-content">
            <table class="table table-bordered table-striped">
              <thead>
                <tr>
                  <th width="5%">N°</th>
                  <th width="15%">Mois / Année</th>
                  <th width="30%">Nom du produit</th>
                  <th width="15%">Numéro de modèle</th>
                  <th width="10%">Quantité vendue</th>
                  <th width="10%">Prix unitaire</th>
                  <th width="15%">Total</th>
                </tr>
              </thead>
              <tbody>
                <?php
                $ret = mysqli_query($con, "SELECT 
                                        month(tblcart.CartDate) as lmonth,
                                        year(tblcart.CartDate) as lyear,
                                        tblproducts.ProductName,
                                        tblproducts.Price,
                                        tblproducts.ModelNumber,
                                        sum(tblcart.ProductQty) as selledqty 
                                    FROM 
                                        tblproducts 
                                    LEFT JOIN 
                                        tblcart ON tblproducts.ID=tblcart.ProductId 
                                    WHERE 
                                        date(tblcart.CartDate) BETWEEN '$fdate' AND '$tdate' 
                                    GROUP BY 
                                        lmonth, lyear, tblproducts.ProductName");
                
                $num = mysqli_num_rows($ret);
                if($num > 0) {
                    $cnt = 1;
                    $gtotal = 0;
                    
                    while ($row = mysqli_fetch_array($ret)) {
                        $qty = $row['selledqty'];
                        $ppunit = $row['Price'];
                        $total = $qty * $ppunit;
                        $gtotal += $total;
                ?>
                <tr>
                  <td><?php echo $cnt; ?></td>
                  <td><?php echo $row['lmonth']."/".$row['lyear']; ?></td>
                  <td><?php echo htmlspecialchars($row['ProductName']); ?></td>
                  <td><?php echo htmlspecialchars($row['ModelNumber']); ?></td>
                  <td><?php echo $qty; ?></td>
                  <td><?php echo number_format($ppunit, 2); ?></td>
                  <td><?php echo number_format($total, 2); ?></td>
                </tr>
                <?php 
                        $cnt++;
                    }
                ?>
                <tr>
                  <th colspan="6" style="text-align: right; color: #d9534f; font-weight: bold; font-size: 15px" class="report-total">Total général</th>  
                  <th style="text-align: center; color: #d9534f; font-weight: bold; font-size: 15px" class="report-total"><?php echo number_format($gtotal, 2); ?></th>  
                </tr>
                <?php } else { ?>
                <tr>
                  <td colspan="7" class="text-center">Aucune donnée trouvée pour cette période</td>
                </tr>
                <?php } ?>
              </tbody>
            </table>
          </div>
          
          <?php } else { 
            $year1 = strtotime($fdate);
            $year2 = strtotime($tdate);
            $y1 = date("Y", $year1);
            $y2 = date("Y", $year2);
          ?>
          
          <div class="report-header">
            <h3>Rapport de ventes de l'année <?php echo $y1; ?> à l'année <?php echo $y2; ?></h3>
          </div>
          
          <div class="widget-content">
            <table class="table table-bordered table-striped">
              <thead>
                <tr>
                  <th width="5%">N°</th>
                  <th width="10%">Année</th>
                  <th width="35%">Nom du produit</th>
                  <th width="15%">Numéro de modèle</th>
                  <th width="10%">Quantité vendue</th>
                  <th width="10%">Prix unitaire</th>
                  <th width="15%">Total</th>
                </tr>
              </thead>
              <tbody>
                <?php
                $ret = mysqli_query($con, "SELECT 
                                        year(tblcart.CartDate) as lyear,
                                        tblproducts.ProductName,
                                        tblproducts.Price,
                                        tblproducts.ModelNumber,
                                        sum(tblcart.ProductQty) as selledqty 
                                    FROM 
                                        tblproducts 
                                    LEFT JOIN 
                                        tblcart ON tblproducts.ID=tblcart.ProductId 
                                    WHERE 
                                        date(tblcart.CartDate) BETWEEN '$fdate' AND '$tdate' 
                                    GROUP BY 
                                        lyear, tblproducts.ProductName");
                
                $num = mysqli_num_rows($ret);
                if($num > 0) {
                    $cnt = 1;
                    $gtotal = 0;
                    
                    while ($row = mysqli_fetch_array($ret)) {
                        $qty = $row['selledqty'];
                        $ppunit = $row['Price'];
                        $total = $qty * $ppunit;
                        $gtotal += $total;
                ?>
                <tr>
                  <td><?php echo $cnt; ?></td>
                  <td><?php echo $row['lyear']; ?></td>
                  <td><?php echo htmlspecialchars($row['ProductName']); ?></td>
                  <td><?php echo htmlspecialchars($row['ModelNumber']); ?></td>
                  <td><?php echo $qty; ?></td>
                  <td><?php echo number_format($ppunit, 2); ?></td>
                  <td><?php echo number_format($total, 2); ?></td>
                </tr>
                <?php 
                        $cnt++;
                    }
                ?>
                <tr>
                  <th colspan="6" style="text-align: right; color: #d9534f; font-weight: bold; font-size: 15px" class="report-total">Total général</th>  
                  <th style="text-align: center; color: #d9534f; font-weight: bold; font-size: 15px" class="report-total"><?php echo number_format($gtotal, 2); ?></th>  
                </tr>
                <?php } else { ?>
                <tr>
                  <td colspan="7" class="text-center">Aucune donnée trouvée pour cette période</td>
                </tr>
                <?php } ?>
              </tbody>
            </table>
          </div>
          <?php } ?>
          
          <!-- Pied de page du rapport -->
          <div class="row-fluid">
            <div class="span12">
              <p style="margin-top: 20px;"><small>Rapport généré le <?php echo date("d/m/Y H:i"); ?></small></p>
            </div>
          </div>
        </div>
        
        <!-- Boutons d'impression - cachés à l'impression -->
        <div class="row-fluid no-print" style="margin-top: 20px;">
          <div class="span12 text-center">
            <button class="btn btn-primary btn-print" onclick="window.print();">
              <i class="icon-print"></i> Imprimer le rapport
            </button>
            <a href="sales-report.php" class="btn">
              <i class="icon-arrow-left"></i> Retour
            </a>
          </div>
        </div>
      </div>
    </div>
  </div>
</div>

<!-- Pied de page - caché à l'impression -->
<div class="no-print">
  <?php include_once('includes/footer.php');?>
</div>

<!-- Scripts JS - ne s'exécutent pas lors de l'impression -->
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
<?php } ?>