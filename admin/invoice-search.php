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
<title>Système de Gestion d'Inventaire || Rechercher Facture</title>
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
  
  /* Styles pour les factures à terme */
  .credit-badge {
    display: inline-block;
    padding: 3px 7px;
    background-color: #f0ad4e;
    color: white;
    border-radius: 3px;
    font-size: 12px;
    margin-left: 10px;
  }
  
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
    
    .credit-badge {
      border: 1px solid #000;
      background-color: transparent !important;
      color: #000 !important;
    }
    
    .dues-info {
      background-color: transparent !important;
      border: 1px dashed #000 !important;
    }
    
    .payment-label {
      color: #000 !important;
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
    <div id="breadcrumb"> <a href="dashboard.php" title="Aller à l'accueil" class="tip-bottom"><i class="icon-home"></i> Accueil</a> <a href="invoice-search.php" class="current">Rechercher Facture</a> </div>
    <h1>Rechercher Facture</h1>
  </div>
  
  <div class="container-fluid">
    <hr class="no-print">
    <div class="row-fluid">
      <div class="span12">
        <!-- Formulaire de recherche - caché à l'impression -->
        <div class="widget-box search-form no-print">
          <div class="widget-title">
            <span class="icon"><i class="icon-search"></i></span>
            <h5>Rechercher une Facture</h5>
          </div>
          <div class="widget-content">
            <form method="post" class="form-horizontal">
              <div class="control-group">
                <label class="control-label">Rechercher par :</label>
                <div class="controls">
                  <input type="text" class="span6" name="searchdata" id="searchdata" value="<?php echo isset($_POST['searchdata']) ? htmlspecialchars($_POST['searchdata']) : ''; ?>" required='true' placeholder="Numéro de facture ou numéro de mobile"/>
                  <button class="btn btn-primary" type="submit" name="search"><i class="icon-search"></i> Rechercher</button>
                </div>
              </div>
            </form>
          </div>
        </div>
      
        <?php
        if(isset($_POST['search'])) { 
          // Sécuriser la donnée de recherche
          $sdata = mysqli_real_escape_string($con, $_POST['searchdata']);
        ?>
        <div class="no-print">
          <h4 align="center">Résultat pour le mot-clé "<?php echo htmlspecialchars($sdata); ?>"</h4>
        </div>
        
        <?php
        // Préparer la requête pour récupérer les informations du client
        $stmt = mysqli_prepare($con, "SELECT DISTINCT 
                                      tblcustomer.CustomerName,
                                      tblcustomer.MobileNumber,
                                      tblcustomer.ModeofPayment,
                                      tblcustomer.BillingDate,
                                      tblcustomer.BillingNumber,
                                      tblcustomer.FinalAmount,
                                      tblcustomer.Paid,
                                      tblcustomer.Dues
                                    FROM 
                                      tblcustomer
                                    LEFT JOIN 
                                      tblcart ON tblcustomer.BillingNumber = tblcart.BillingId
                                    LEFT JOIN 
                                      tblcreditcart ON tblcustomer.BillingNumber = tblcreditcart.BillingId
                                    WHERE 
                                      tblcustomer.BillingNumber = ? OR tblcustomer.MobileNumber = ?
                                    LIMIT 1");
        
        mysqli_stmt_bind_param($stmt, "ss", $sdata, $sdata);
        mysqli_stmt_execute($stmt);
        $customerResult = mysqli_stmt_get_result($stmt);
        
        if(mysqli_num_rows($customerResult) > 0) {
          $customerRow = mysqli_fetch_assoc($customerResult);
          $invoiceid = $customerRow['BillingNumber'];
          $finalAmount = $customerRow['FinalAmount'];
          $paidAmount = $customerRow['Paid'];
          $duesAmount = $customerRow['Dues'];
          $formattedDate = date("d/m/Y", strtotime($customerRow['BillingDate']));
          
          // Déterminer si c'est une facture à terme
          $isCredit = ($customerRow['Dues'] > 0 || $customerRow['ModeofPayment'] == 'credit');
          
          // Vérifier dans quelle table se trouvent les articles
          $checkCreditCart = mysqli_query($con, "SELECT COUNT(*) as count FROM tblcreditcart WHERE BillingId='$invoiceid'");
          $checkRegularCart = mysqli_query($con, "SELECT COUNT(*) as count FROM tblcart WHERE BillingId='$invoiceid'");
          
          $creditItems = 0;
          $regularItems = 0;
          
          if ($rowCredit = mysqli_fetch_assoc($checkCreditCart)) {
            $creditItems = $rowCredit['count'];
          }
          
          if ($rowRegular = mysqli_fetch_assoc($checkRegularCart)) {
            $regularItems = $rowRegular['count'];
          }
          
          // Déterminer quelle table utiliser
          $useTable = ($creditItems > 0) ? 'tblcreditcart' : 'tblcart';
        ?>
        <div id="printArea">
          <!-- En-tête qui n'apparaît qu'à l'impression -->
          <div class="print-header">
            <h2>Système de Gestion d'Inventaire</h2>
            <p>Facture #<?php echo htmlspecialchars($invoiceid); ?></p>
          </div>
          
          <div class="invoice-box">
            <div class="invoice-header">
              <div class="row-fluid">
                <div class="span6">
                  <h3>
                    Facture #<?php echo htmlspecialchars($invoiceid); ?>
                    <?php if ($isCredit): ?>
                    <span class="credit-badge">Vente à Terme</span>
                    <?php endif; ?>
                  </h3>
                  <p>Date: <?php echo $formattedDate; ?></p>
                </div>
                <div class="span6 text-right">
                  <h4><?php echo htmlspecialchars($_SERVER['HTTP_HOST']); ?></h4>
                  <p>Système de Gestion d'Inventaire</p>
                </div>
              </div>
            </div>
            
            <table class="table customer-info">
              <tr>
                <th width="25%">Nom du client:</th>
                <td width="25%"><?php echo htmlspecialchars($customerRow['CustomerName']); ?></td>
                <th width="25%">Numéro de mobile:</th>
                <td width="25%"><?php echo htmlspecialchars($customerRow['MobileNumber']); ?></td>
              </tr>
              <tr>
                <th>Mode de paiement:</th>
                <td colspan="3"><?php echo htmlspecialchars($customerRow['ModeofPayment']); ?></td>
              </tr>
            </table>
            
            <?php if ($isCredit): ?>
            <!-- Affichage des informations de paiement pour les factures à terme -->
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
            <?php endif; ?>
          
            <div class="widget-box">
              <div class="widget-title no-print"> 
                <span class="icon"><i class="icon-th"></i></span>
                <h5>Détails des produits</h5>
              </div>
              <div class="widget-content nopadding">
                <table class="table table-bordered table-striped">
                  <thead>
                    <tr>
                      <th width="5%">N°</th>
                      <th width="35%">Nom du produit</th>
                      <th width="15%">Référence</th>
                      <th width="10%">Quantité</th>
                      <th width="15%">Prix unitaire</th>
                      <th width="20%">Total</th>
                    </tr>
                  </thead>
                  <tbody>
                  <?php
                  // Préparer la requête pour récupérer les détails des produits selon la table appropriée
                  $stmt = mysqli_prepare($con, "SELECT 
                                              tblproducts.ProductName,
                                              tblproducts.ModelNumber,
                                              tblproducts.Price,
                                              $useTable.ProductQty,
                                              $useTable.Price as CartPrice
                                            FROM 
                                              $useTable
                                            JOIN 
                                              tblproducts ON $useTable.ProductId = tblproducts.ID
                                            WHERE 
                                              $useTable.BillingId = ?
                                            ORDER BY
                                              tblproducts.ProductName ASC");
                  
                  mysqli_stmt_bind_param($stmt, "s", $invoiceid);
                  mysqli_stmt_execute($stmt);
                  $productResult = mysqli_stmt_get_result($stmt);
                  
                  if(mysqli_num_rows($productResult) > 0) {
                    $cnt = 1;
                    $gtotal = 0;
                    
                    while($productRow = mysqli_fetch_assoc($productResult)) {
                      $pq = $productRow['ProductQty'];
                      $ppu = $productRow['CartPrice'] ?: $productRow['Price']; // Utiliser le prix du panier s'il existe
                      $total = $pq * $ppu;
                      $gtotal += $total;
                  ?>
                    <tr>
                      <td><?php echo $cnt; ?></td>
                      <td><?php echo htmlspecialchars($productRow['ProductName']); ?></td>
                      <td><?php echo htmlspecialchars($productRow['ModelNumber']); ?></td>
                      <td><?php echo $pq; ?></td>
                      <td><?php echo number_format($ppu, 2); ?></td>
                      <td><?php echo number_format($total, 2); ?></td>
                    </tr>
                  <?php
                      $cnt++;
                    }
                  
                    // Si le montant final existe dans la base de données, l'utiliser
                    $displayTotal = $finalAmount ?: $gtotal;
                  ?>
                    <tr>
                      <th colspan="5" class="text-right invoice-total">Total général</th>
                      <th class="invoice-total"><?php echo number_format($displayTotal, 2); ?></th>
                    </tr>
                  <?php 
                  } else { 
                  ?>
                    <tr>
                      <td colspan="6" class="text-center">Aucun produit trouvé pour cette facture</td>
                    </tr>
                  <?php 
                  } 
                  ?>
                  </tbody>
                </table>
              </div>
            </div>
            
            <!-- Pied de page de facture -->
            <div class="row-fluid">
              <div class="span12">
                <p style="margin-top: 20px;">Merci pour votre achat!</p>
                <p><small>Cette facture a été générée automatiquement par le système.</small></p>
              </div>
            </div>
            
            <!-- Bouton d'impression - caché à l'impression -->
            <div class="row-fluid no-print" style="margin-top: 20px;">
              <div class="span12 text-center">
                <button class="btn btn-primary" onclick="window.print();">
                  <i class="icon-print"></i> Imprimer Facture
                </button>
              </div>
            </div>
          </div>
        </div>
        <?php 
        } else { 
        ?>
          <div class="alert alert-error">
            <button class="close" data-dismiss="alert">×</button>
            <strong>Erreur!</strong> Aucune facture trouvée pour ce numéro de facture ou numéro de mobile.
          </div>
        <?php 
        }
        } 
        ?>
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