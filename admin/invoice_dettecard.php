<?php
session_start();
error_reporting(0);
include('includes/dbconnection.php');
if (strlen($_SESSION['imsaid']==0)) {
  header('location:logout.php');
} else {
  // Créer une variable pour l'impression automatique
  $printAutomatically = isset($_GET['print']) && $_GET['print'] == 'auto';
?>
<!DOCTYPE html>
<html lang="fr">
<head>
<title>Système de Gestion d'Inventaire || Facture à Terme</title>
<?php include_once('includes/cs.php');?>
<?php include_once('includes/responsive.php'); ?>
<style>
  /* Styles pour l'interface normale */
  .invoice-box {
    background-color: #f9f9f9;
    border: 1px solid #ddd;
    border-radius: 4px;
    padding: 15px;
    margin-bottom: 20px;
  }
  .invoice-header {
    border-bottom: 1px solid #eee;
    padding-bottom: 10px;
    margin-bottom: 15px;
  }
  .invoice-total {
    font-weight: bold;
    color: #d9534f;
  }
  .search-form {
    background-color: #f5f5f5;
    padding: 15px;
    border-radius: 4px;
    margin-bottom: 20px;
  }
  .customer-info td, .customer-info th {
    padding: 8px;
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
    .widget-box, .invoice-box {
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
    .invoice-total {
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
    input[name="printbutton"], .btn-print {
      display: none !important;
    }
  }

  /* Style spécifique pour facture à terme */
  .dues-info {
    background-color: #fff3cd;
    padding: 10px;
    border: 1px solid #ffeeba;
    border-radius: 4px;
    margin-top: 10px;
    margin-bottom: 10px;
  }
  
  .payment-label {
    font-weight: bold;
    color: #856404;
  }

  @media print {
    .dues-info {
      background-color: transparent !important;
      border: 1px dashed #000 !important;
    }
    .payment-label {
      color: black !important;
      font-weight: bold !important;
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
    <div id="breadcrumb"> <a href="dashboard.php" title="Aller à l'accueil" class="tip-bottom"><i class="icon-home"></i> Accueil</a> <a href="dettecart.php" class="current">Facture à Terme</a> </div>
    <h1>Facture à Terme</h1>
  </div>
  
  <div class="container-fluid">
    <hr class="no-print">
    <div class="row-fluid">
      <div class="span12" id="printArea">
        <!-- En-tête qui n'apparaît qu'à l'impression -->
        <div class="print-header">
          <h2>Système de Gestion d'Inventaire</h2>
          <p>Facture #<?php echo $_SESSION['invoiceid']; ?></p>
        </div>
        
        <div class="invoice-box">
          <div class="invoice-header">
            <h3>Facture à Terme #<?php echo $_SESSION['invoiceid']; ?></h3>
          </div>

          <?php     
          $billingid = $_SESSION['invoiceid'];
          $ret = mysqli_query($con, "SELECT DISTINCT 
                                    tblcustomer.CustomerName,
                                    tblcustomer.MobileNumber,
                                    tblcustomer.ModeofPayment,
                                    tblcustomer.BillingDate,
                                    tblcustomer.FinalAmount,
                                    tblcustomer.Paid,
                                    tblcustomer.Dues
                                  FROM 
                                    tblcreditcart 
                                  JOIN 
                                    tblcustomer ON tblcustomer.BillingNumber=tblcreditcart.BillingId 
                                  WHERE 
                                    tblcustomer.BillingNumber='$billingid'");

          while ($row = mysqli_fetch_array($ret)) {
            $formattedDate = date("d/m/Y", strtotime($row['BillingDate']));
            $finalAmount = $row['FinalAmount'];
            $paidAmount = $row['Paid'];
            $duesAmount = $row['Dues'];
          ?>
          <div class="customer-info">
            <table class="table" width="100%" border="1">
              <tr>
                <th width="25%">Nom du client:</th>
                <td width="25%"><?php echo htmlspecialchars($row['CustomerName']); ?></td>
                <th width="25%">Numéro du client:</th>
                <td width="25%"><?php echo htmlspecialchars($row['MobileNumber']); ?></td>
              </tr>
              <tr>
                <th>Mode de paiement:</th>
                <td colspan="3"><?php echo htmlspecialchars($row['ModeofPayment']); ?></td>
              </tr>
              <tr>
                <th>Date:</th>
                <td colspan="3"><?php echo $formattedDate; ?></td>
              </tr>
            </table>
          </div>
          
          <!-- Informations de paiement à terme -->
          <div class="dues-info">
            <div class="row-fluid">
              <div class="span4">
                <span class="payment-label">Montant total:</span> 
                <span class="payment-value"><?php echo number_format($finalAmount, 2); ?> GNF</span>
              </div>
              <div class="span4">
                <span class="payment-label">Montant payé:</span> 
                <span class="payment-value"><?php echo number_format($paidAmount, 2); ?> GNF</span>
              </div>
              <div class="span4">
                <span class="payment-label">Reste à payer:</span> 
                <span class="payment-value"><?php echo number_format($duesAmount, 2); ?> GNF</span>
              </div>
            </div>
          </div>
          <?php } ?>
          
          <div class="widget-box">
            <div class="widget-title no-print"> 
              <span class="icon"><i class="icon-th"></i></span>
              <h5>Inventaire des Articles</h5>
            </div>
            <div class="widget-content nopadding" width="100%" border="1">
              <table class="table table-bordered data-table" style="font-size: 15px">
                <thead>
                  <tr>
                    <th width="5%">N°</th>
                    <th width="30%">Nom du Article</th>
                    <th width="15%">Numéro de modèle</th>
                    <th width="10%">Quantité</th>
                    <th width="15%">Prix unitaire</th>
                    <th width="15%">Total</th>
                  </tr>
                </thead>
                <tbody>
                <?php
                $ret = mysqli_query($con, "SELECT 
                                          tblproducts.ProductName,
                                          tblproducts.ModelNumber,
                                          tblproducts.Price,
                                          tblcreditcart.ProductQty,
                                          tblcreditcart.Price as CartPrice
                                        FROM 
                                          tblcreditcart
                                        JOIN 
                                          tblproducts ON tblproducts.ID=tblcreditcart.ProductId
                                        WHERE 
                                          tblcreditcart.BillingId='$billingid'");
                $cnt = 1;
                $gtotal = 0;

                while ($row = mysqli_fetch_array($ret)) {
                  $pq = $row['ProductQty'];
                  $ppu = $row['CartPrice'] ?: $row['Price']; // Utiliser le prix du panier s'il existe
                  $total = $pq * $ppu;
                ?>
                  <tr>
                    <td><?php echo $cnt; ?></td>
                    <td><?php echo htmlspecialchars($row['ProductName']); ?></td>
                    <td><?php echo htmlspecialchars($row['ModelNumber']); ?></td>
                    <td><?php echo $pq; ?></td>
                    <td><?php echo number_format($ppu, 2); ?></td>
                    <td><?php echo number_format($total, 2); ?></td>
                  </tr>
                <?php 
                  $cnt++;
                  $gtotal += $total;
                }
                ?>
                <tr>
                  <th colspan="5" style="text-align: right; color: #d9534f; font-weight: bold; font-size: 15px" class="invoice-total">Total général</th>
                  <th style="text-align: center; color: #d9534f; font-weight: bold; font-size: 15px" class="invoice-total"><?php echo number_format($gtotal, 2); ?></th>
                </tr>
                </tbody>
              </table>
              
              <!-- Pied de page de facture -->
              <div class="row-fluid">
                <div class="span12">
                  <p style="margin-top: 20px;">Merci pour votre achat!</p>
                  <p><small>Cette facture a été générée automatiquement par le système.</small></p>
                </div>
              </div>
            </div>
          </div>
        </div>
        
        <!-- Bouton d'impression - caché à l'impression -->
        <div class="row-fluid no-print" style="margin-top: 20px;">
          <div class="span12 text-center">
            <button class="btn btn-primary btn-print" onclick="window.print();">
              <i class="icon-print"></i> Imprimer Facture
            </button>
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

<?php if ($printAutomatically): ?>
<script>
  // Lancer l'impression automatiquement à l'ouverture de la page
  window.onload = function() {
    setTimeout(function() {
      window.print();
    }, 1000); // Délai de 1 seconde pour laisser la page se charger complètement
  };
</script>
<?php endif; ?>

</body>
</html>
<?php } ?>