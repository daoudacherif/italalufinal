<?php
session_start();
error_reporting(0);
include('includes/dbconnection.php');
if (strlen($_SESSION['imsaid']==0)) {
  header('location:logout.php');
} else {

// Handle return submission
if(isset($_POST['process_return'])) {
    $billing_number = mysqli_real_escape_string($con, $_POST['billing_number']);
    $return_reason = mysqli_real_escape_string($con, $_POST['return_reason']);
    $return_date = date('Y-m-d H:i:s');
    
    $total_return_amount = 0;
    $items_returned = 0;
    
    // Get current customer dues before processing returns
    $customer_query = mysqli_query($con, "SELECT Dues, ModeofPayment FROM tblcustomer WHERE BillingNumber='$billing_number'");
    $customer_data = mysqli_fetch_assoc($customer_query);
    $current_dues = $customer_data['Dues'];
    $payment_mode = $customer_data['ModeofPayment'];
    
    // Process each returned item
    if(isset($_POST['return_qty']) && is_array($_POST['return_qty'])) {
        foreach($_POST['return_qty'] as $product_id => $return_qty) {
            $return_qty = intval($return_qty);
            if($return_qty > 0) {
                // Get product details
                $product_query = mysqli_query($con, "SELECT Price FROM tblproducts WHERE ID='$product_id'");
                $product_data = mysqli_fetch_assoc($product_query);
                $unit_price = $product_data['Price'];
                $return_price = $return_qty * $unit_price;
                
                // Insert return record using correct column names
                $insert_return = mysqli_query($con, "INSERT INTO tblreturns 
                    (BillingNumber, ReturnDate, ProductID, Quantity, Reason, ReturnPrice) 
                    VALUES 
                    ('$billing_number', '$return_date', '$product_id', '$return_qty', '$return_reason', '$return_price')");
                
                if($insert_return) {
                    // Update product stock (add back returned quantity) - using correct column name 'Stock'
                    mysqli_query($con, "UPDATE tblproducts SET Stock = Stock + $return_qty WHERE ID='$product_id'");
                    
                    $total_return_amount += $return_price;
                    $items_returned++;
                }
            }
        }
        
        // Update customer dues if there are outstanding dues
        if($items_returned > 0 && $current_dues > 0) {
            // Calculate new dues amount (cannot go below 0)
            $new_dues = max(0, $current_dues - $total_return_amount);
            
            // Update customer dues
            $update_dues = mysqli_query($con, "UPDATE tblcustomer SET Dues = '$new_dues' WHERE BillingNumber = '$billing_number'");
            
            if($update_dues) {
                $dues_reduced = $current_dues - $new_dues;
                $dues_info = " | Dettes réduites de " . number_format($dues_reduced, 2) . " GNF (Nouvelle dette: " . number_format($new_dues, 2) . " GNF)";
            }
        }
    }
    
    if($items_returned > 0) {
        $success_msg = "Retour traité avec succès! $items_returned produit(s) retourné(s) pour un montant total de " . number_format($total_return_amount, 2) . " GNF";
        if(isset($dues_info)) {
            $success_msg .= $dues_info;
        }
    } else {
        $error_msg = "Aucun produit sélectionné pour le retour.";
    }
}

?>
<!DOCTYPE html>
<html lang="fr">
<head>
<title>Système de Gestion d'Inventaire || Retour de Produits</title>
<?php include_once('includes/cs.php');?>
<?php include_once('includes/responsive.php'); ?>
<style>
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
  
  /* Return-specific styles */
  .return-form {
    background-color: #e8f5e8;
    border: 2px solid #4caf50;
    border-radius: 4px;
    padding: 20px;
    margin-top: 20px;
  }
  
  .return-qty-input {
    width: 60px;
    text-align: center;
  }
  
  .return-checkbox {
    margin-right: 10px;
  }
  
  .return-row {
    background-color: #f0f8f0;
  }
  
  .return-summary {
    background-color: #d4edda;
    border: 1px solid #c3e6cb;
    border-radius: 4px;
    padding: 15px;
    margin-top: 20px;
  }
  
  .alert-success {
    background-color: #d4edda;
    border-color: #c3e6cb;
    color: #155724;
    padding: 10px;
    border-radius: 4px;
    margin-bottom: 20px;
  }
  
  .alert-error {
    background-color: #f8d7da;
    border-color: #f5c6cb;
    color: #721c24;
    padding: 10px;
    border-radius: 4px;
    margin-bottom: 20px;
  }
  
  .alert-info {
    background-color: #d1ecf1;
    border-color: #bee5eb;
    color: #0c5460;
    padding: 10px;
    border-radius: 4px;
    margin-bottom: 20px;
  }
  
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
  
  .stock-info {
    font-size: 11px;
    color: #666;
    font-style: italic;
  }
  
  .returns-history {
    background-color: #fff5f5;
    border: 1px solid #fecaca;
    border-radius: 4px;
    padding: 15px;
    margin: 20px 0;
  }

  .returns-summary {
    background-color: #fef2f2;
    border: 1px solid #fca5a5;
    border-radius: 4px;
    padding: 10px;
    margin-bottom: 15px;
    text-align: center;
  }

  .no-returns {
    text-align: center;
    color: #6b7280;
    font-style: italic;
    padding: 20px;
  }

  .return-amount {
    font-weight: bold;
    color: #dc2626;
  }

  .return-date {
    font-size: 12px;
    color: #6b7280;
  }

  .return-reason {
    background-color: #fef3c7;
    padding: 2px 6px;
    border-radius: 3px;
    font-size: 11px;
    color: #92400e;
  }
</style>
<script>
function toggleReturnRow(checkbox, productId) {
    var row = document.getElementById('product_row_' + productId);
    var qtyInput = document.getElementById('return_qty_' + productId);
    
    if(checkbox.checked) {
        row.classList.add('return-row');
        qtyInput.disabled = false;
        qtyInput.focus();
    } else {
        row.classList.remove('return-row');
        qtyInput.disabled = true;
        qtyInput.value = '';
    }
    calculateReturnTotal();
}

function validateReturnQty(input, maxQty) {
    var value = parseInt(input.value);
    if(value > maxQty) {
        alert('La quantité de retour ne peut pas dépasser la quantité achetée (' + maxQty + ')');
        input.value = maxQty;
    }
    if(value < 0) {
        input.value = 0;
    }
    calculateReturnTotal();
}

function calculateReturnTotal() {
    var total = 0;
    var checkboxes = document.querySelectorAll('input[name="return_items[]"]:checked');
    
    checkboxes.forEach(function(checkbox) {
        var productId = checkbox.value;
        var qtyInput = document.getElementById('return_qty_' + productId);
        var unitPrice = parseFloat(document.getElementById('unit_price_' + productId).value);
        var qty = parseInt(qtyInput.value) || 0;
        
        total += qty * unitPrice;
    });
    
    document.getElementById('return_total_display').textContent = total.toFixed(2);
    
    // Calculate new dues if applicable
    var currentDues = parseFloat(document.getElementById('current_dues').value) || 0;
    if(currentDues > 0) {
        var newDues = Math.max(0, currentDues - total);
        var duesReduction = currentDues - newDues;
        
        document.getElementById('dues_reduction_display').textContent = duesReduction.toFixed(2);
        document.getElementById('new_dues_display').textContent = newDues.toFixed(2);
        document.getElementById('dues_calculation').style.display = 'block';
    } else {
        document.getElementById('dues_calculation').style.display = 'none';
    }
}

function selectAllProducts() {
    var checkboxes = document.querySelectorAll('input[name="return_items[]"]');
    var selectAll = document.getElementById('select_all').checked;
    
    checkboxes.forEach(function(checkbox) {
        checkbox.checked = selectAll;
        toggleReturnRow(checkbox, checkbox.value);
    });
}
</script>
</head>
<body>
<?php include_once('includes/header.php');?>
<?php include_once('includes/sidebar.php');?>

<div id="content">
  <div id="content-header">
    <div id="breadcrumb"> 
      <a href="dashboard.php" title="Aller à l'accueil" class="tip-bottom"><i class="icon-home"></i> Accueil</a> 
      <a href="product-return.php" class="current">Retour de Produits</a> 
    </div>
    <h1>Retour de Produits</h1>
  </div>
  
  <div class="container-fluid">
    <hr>
    
    <?php if(isset($success_msg)) { ?>
    <div class="alert alert-success">
      <button class="close" data-dismiss="alert">×</button>
      <strong>Succès!</strong> <?php echo $success_msg; ?>
    </div>
    <?php } ?>
    
    <?php if(isset($error_msg)) { ?>
    <div class="alert alert-error">
      <button class="close" data-dismiss="alert">×</button>
      <strong>Erreur!</strong> <?php echo $error_msg; ?>
    </div>
    <?php } ?>
    
    <div class="row-fluid">
      <div class="span12">
        <!-- Search Form -->
        <div class="widget-box search-form">
          <div class="widget-title">
            <span class="icon"><i class="icon-search"></i></span>
            <h5>Rechercher une Facture pour Retour</h5>
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
          $sdata = mysqli_real_escape_string($con, $_POST['searchdata']);
        ?>
        <div>
          <h4 align="center">Facture trouvée pour "<?php echo htmlspecialchars($sdata); ?>"</h4>
        </div>
        
        <?php
        // Get customer information using correct column names
        $stmt = mysqli_prepare($con, "SELECT 
                                      CustomerName,
                                      MobileNumber,
                                      ModeofPayment,
                                      BillingDate,
                                      BillingNumber,
                                      FinalAmount,
                                      Paid,
                                      Dues
                                    FROM 
                                      tblcustomer
                                    WHERE 
                                      BillingNumber = ? OR MobileNumber = ?
                                    LIMIT 1");
        
        mysqli_stmt_bind_param($stmt, "ss", $sdata, $sdata);
        mysqli_stmt_execute($stmt);
        $customerResult = mysqli_stmt_get_result($stmt);
        
        if(mysqli_num_rows($customerResult) > 0) {
          $customerRow = mysqli_fetch_assoc($customerResult);
          $billing_number = $customerRow['BillingNumber'];
          $finalAmount = $customerRow['FinalAmount'];
          $paidAmount = $customerRow['Paid'];
          $duesAmount = $customerRow['Dues'];
          $formattedDate = date("d/m/Y", strtotime($customerRow['BillingDate']));
          
          $isCredit = ($customerRow['Dues'] > 0 || $customerRow['ModeofPayment'] == 'credit');
          
          // Determine which table to use for cart items
          $checkCreditCart = mysqli_query($con, "SELECT COUNT(*) as count FROM tblcreditcart WHERE BillingId='$billing_number'");
          $checkRegularCart = mysqli_query($con, "SELECT COUNT(*) as count FROM tblcart WHERE BillingId='$billing_number'");
          
          $creditItems = 0;
          $regularItems = 0;
          
          if ($rowCredit = mysqli_fetch_assoc($checkCreditCart)) {
            $creditItems = $rowCredit['count'];
          }
          
          if ($rowRegular = mysqli_fetch_assoc($checkRegularCart)) {
            $regularItems = $rowRegular['count'];
          }
          
          $useTable = ($creditItems > 0) ? 'tblcreditcart' : 'tblcart';
        ?>
        
        <div class="invoice-box">
          <div class="invoice-header">
            <div class="row-fluid">
              <div class="span6">
                <h3>
                  Facture #<?php echo htmlspecialchars($billing_number); ?>
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
                <span class="payment-label">Dette actuelle:</span> 
                <span class="payment-value"><?php echo number_format($duesAmount, 2); ?> GNF</span>
                <?php if($duesAmount > 0): ?>
                <br><small style="color: #856404; font-style: italic;">
                  ⚠️ Les retours réduiront cette dette
                </small>
                <?php endif; ?>
              </div>
            </div>
          </div>
          <?php endif; ?>
        </div>

        <?php
        // === AJOUT DE L'HISTORIQUE DES RETOURS (À LA BONNE PLACE) ===
        
        // Récupérer l'historique des retours pour cette facture
        $returnsQuery = mysqli_query($con, "
            SELECT 
                r.ID,
                r.ReturnDate,
                r.ProductID,
                r.Quantity,
                r.Reason,
                r.ReturnPrice,
                p.ProductName,
                p.ModelNumber
            FROM 
                tblreturns r
            JOIN 
                tblproducts p ON r.ProductID = p.ID
            WHERE 
                r.BillingNumber = '$billing_number'
            ORDER BY 
                r.ReturnDate DESC
        ");

        $totalReturns = mysqli_num_rows($returnsQuery);
        $totalReturnAmount = 0;

        // Calculer le montant total des retours
        if($totalReturns > 0) {
            $sumQuery = mysqli_query($con, "SELECT SUM(ReturnPrice) as total_return_amount FROM tblreturns WHERE BillingNumber = '$billing_number'");
            $sumData = mysqli_fetch_assoc($sumQuery);
            $totalReturnAmount = $sumData['total_return_amount'] ?: 0;
        }
        ?>

        <!-- Section Historique des Retours -->
        <div class="returns-history">
            <div class="widget-title" style="background: none; border: none; padding: 0; margin-bottom: 15px;">
                <span class="icon"><i class="icon-list"></i></span>
                <h5 style="color: #dc2626;">Historique des Retours pour cette Facture</h5>
            </div>
            
            <?php if($totalReturns > 0): ?>
            <!-- Résumé des retours -->
            <div class="returns-summary">
                <div class="row-fluid">
                    <div class="span4">
                        <strong>Total des retours :</strong> <?php echo $totalReturns; ?> transaction(s)
                    </div>
                    <div class="span4">
                        <strong>Montant total retourné :</strong> 
                        <span class="return-amount"><?php echo number_format($totalReturnAmount, 2); ?> GNF</span>
                    </div>
                    <div class="span4">
                        <strong>Statut :</strong> 
                        <span style="color: #dc2626;">Retours effectués</span>
                    </div>
                </div>
            </div>
            
            <!-- Tableau détaillé des retours -->
            <div class="widget-box">
                <div class="widget-content nopadding">
                    <table class="table table-bordered table-striped">
                        <thead>
                            <tr style="background-color: #fca5a5;">
                                <th width="8%">ID</th>
                                <th width="15%">Date de retour</th>
                                <th width="25%">Produit</th>
                                <th width="12%">Référence</th>
                                <th width="8%">Quantité</th>
                                <th width="12%">Montant</th>
                                <th width="20%">Motif</th>
                            </tr>
                        </thead>
                        <tbody>
                        <?php
                        // Reset le pointeur de résultat pour parcourir à nouveau
                        mysqli_data_seek($returnsQuery, 0);
                        while($returnRow = mysqli_fetch_assoc($returnsQuery)): 
                            $returnDate = date("d/m/Y H:i", strtotime($returnRow['ReturnDate']));
                        ?>
                            <tr>
                                <td><?php echo $returnRow['ID']; ?></td>
                                <td>
                                    <span class="return-date"><?php echo $returnDate; ?></span>
                                </td>
                                <td><?php echo htmlspecialchars($returnRow['ProductName']); ?></td>
                                <td><?php echo htmlspecialchars($returnRow['ModelNumber']); ?></td>
                                <td class="text-center">
                                    <strong><?php echo $returnRow['Quantity']; ?></strong>
                                </td>
                                <td class="text-right">
                                    <span class="return-amount"><?php echo number_format($returnRow['ReturnPrice'], 2); ?> GNF</span>
                                </td>
                                <td>
                                    <span class="return-reason"><?php echo htmlspecialchars($returnRow['Reason']); ?></span>
                                </td>
                            </tr>
                        <?php endwhile; ?>
                        </tbody>
                        <tfoot>
                            <tr style="background-color: #fee2e2; font-weight: bold;">
                                <td colspan="5" class="text-right">TOTAL RETOURNÉ :</td>
                                <td class="text-right">
                                    <span class="return-amount"><?php echo number_format($totalReturnAmount, 2); ?> GNF</span>
                                </td>
                                <td></td>
                            </tr>
                        </tfoot>
                    </table>
                </div>
            </div>
            
            <?php else: ?>
            <!-- Aucun retour trouvé -->
            <div class="no-returns">
                <i class="icon-info-sign" style="font-size: 24px; color: #6b7280; margin-bottom: 10px;"></i>
                <p>Aucun retour n'a été effectué pour cette facture.</p>
            </div>
            <?php endif; ?>
        </div>

        <?php
        // === SECTION OPTIONNELLE: Statistiques rapides ===
        if($totalReturns > 0):
            $returnPercentage = ($totalReturnAmount / $finalAmount) * 100;
        ?>
        <div class="alert alert-info">
            <strong>Statistiques :</strong> 
            <?php echo number_format($returnPercentage, 1); ?>% du montant total de la facture a été retourné
            (<?php echo number_format($totalReturnAmount, 2); ?> GNF sur <?php echo number_format($finalAmount, 2); ?> GNF)
        </div>
        <?php endif; ?>

        <!-- Formulaire de Retour -->
        <form method="post" class="return-form">
            <input type="hidden" name="billing_number" value="<?php echo $billing_number; ?>">
            
            <div class="widget-box">
              <div class="widget-title"> 
                <span class="icon"><i class="icon-th"></i></span>
                <h5>Sélectionner les produits à retourner</h5>
              </div>
              <div class="widget-content nopadding">
                <table class="table table-bordered table-striped">
                  <thead>
                    <tr>
                      <th width="5%">
                        <input type="checkbox" id="select_all" onchange="selectAllProducts()"> Tout
                      </th>
                      <th width="25%">Nom du produit</th>
                      <th width="10%">Référence</th>
                      <th width="8%">Stock</th>
                      <th width="8%">Qté achetée</th>
                      <th width="12%">Prix unitaire</th>
                      <th width="12%">Qté à retourner</th>
                      <th width="15%">Montant retour</th>
                    </tr>
                  </thead>
                  <tbody>
                  <?php
                  // Get product details using correct column names
                  $stmt = mysqli_prepare($con, "SELECT 
                                              p.ID,
                                              p.ProductName,
                                              p.ModelNumber,
                                              p.Price,
                                              p.Stock,
                                              c.ProductQty,
                                              c.Price as CartPrice
                                            FROM 
                                              $useTable c
                                            JOIN 
                                              tblproducts p ON c.ProductId = p.ID
                                            WHERE 
                                              c.BillingId = ?
                                            ORDER BY
                                              p.ProductName ASC");
                  
                  mysqli_stmt_bind_param($stmt, "s", $billing_number);
                  mysqli_stmt_execute($stmt);
                  $productResult = mysqli_stmt_get_result($stmt);
                  
                  if(mysqli_num_rows($productResult) > 0) {
                    while($productRow = mysqli_fetch_assoc($productResult)) {
                      $product_id = $productRow['ID'];
                      $pq = $productRow['ProductQty'];
                      $current_stock = $productRow['Stock'];
                      $ppu = $productRow['CartPrice'] ?: $productRow['Price'];
                      $total = $pq * $ppu;
                      
                      // Check if this product was already returned
                      $returned_query = mysqli_query($con, "SELECT SUM(Quantity) as returned_qty FROM tblreturns WHERE BillingNumber='$billing_number' AND ProductID='$product_id'");
                      $returned_data = mysqli_fetch_assoc($returned_query);
                      $already_returned = $returned_data['returned_qty'] ?: 0;
                      $available_for_return = $pq - $already_returned;
                  ?>
                    <tr id="product_row_<?php echo $product_id; ?>">
                      <td>
                        <?php if($available_for_return > 0): ?>
                        <input type="checkbox" name="return_items[]" value="<?php echo $product_id; ?>" 
                               class="return-checkbox" onchange="toggleReturnRow(this, <?php echo $product_id; ?>)">
                        <?php else: ?>
                        <span style="color: #999;">Déjà retourné</span>
                        <?php endif; ?>
                      </td>
                      <td>
                        <?php echo htmlspecialchars($productRow['ProductName']); ?>
                        <?php if($already_returned > 0): ?>
                        <br><small class="stock-info">Déjà retourné: <?php echo $already_returned; ?></small>
                        <?php endif; ?>
                      </td>
                      <td><?php echo htmlspecialchars($productRow['ModelNumber']); ?></td>
                      <td>
                        <span class="stock-info"><?php echo $current_stock; ?></span>
                      </td>
                      <td><?php echo $pq; ?></td>
                      <td><?php echo number_format($ppu, 2); ?></td>
                      <td>
                        <?php if($available_for_return > 0): ?>
                        <input type="number" 
                               id="return_qty_<?php echo $product_id; ?>"
                               name="return_qty[<?php echo $product_id; ?>]" 
                               class="return-qty-input" 
                               min="0" 
                               max="<?php echo $available_for_return; ?>" 
                               disabled
                               onchange="validateReturnQty(this, <?php echo $available_for_return; ?>)"
                               oninput="calculateReturnTotal()">
                        <br><small class="stock-info">Max: <?php echo $available_for_return; ?></small>
                        <?php else: ?>
                        <span style="color: #999;">0</span>
                        <?php endif; ?>
                        <input type="hidden" id="unit_price_<?php echo $product_id; ?>" value="<?php echo $ppu; ?>">
                      </td>
                      <td><?php echo number_format($total, 2); ?></td>
                    </tr>
                  <?php
                    }
                  } else { 
                  ?>
                    <tr>
                      <td colspan="8" class="text-center">Aucun produit trouvé pour cette facture</td>
                    </tr>
                  <?php 
                  } 
                  ?>
                  </tbody>
                </table>
              </div>
            </div>
            
            <!-- Return Summary and Reason -->
            <div class="return-summary">
              <div class="row-fluid">
                <div class="span6">
                  <div class="control-group">
                    <label class="control-label"><strong>Motif du retour:</strong></label>
                    <div class="controls">
                      <select name="return_reason" required class="span10">
                        <option value="">Sélectionner un motif</option>
                        <option value="Produit défectueux">Produit défectueux</option>
                        <option value="Erreur de commande">Erreur de commande</option>
                        <option value="Client insatisfait">Client insatisfait</option>
                        <option value="Produit endommagé">Produit endommagé</option>
                        <option value="Produit périmé">Produit périmé</option>
                        <option value="Mauvaise taille/couleur">Mauvaise taille/couleur</option>
                        <option value="Autre">Autre</option>
                      </select>
                    </div>
                  </div>
                </div>
                <div class="span6">
                  <div class="text-right">
                    <h4>Montant total du retour: <span id="return_total_display">0.00</span> GNF</h4>
                    
                    <!-- Dues Calculation Display -->
                    <?php if($duesAmount > 0): ?>
                    <input type="hidden" id="current_dues" value="<?php echo $duesAmount; ?>">
                    <div id="dues_calculation" style="display: none; margin-top: 10px; padding: 10px; background-color: #fff3cd; border: 1px solid #ffeeba; border-radius: 4px;">
                      <h5 style="margin: 0; color: #856404;">Impact sur les dettes:</h5>
                      <p style="margin: 5px 0; color: #856404;">
                        <strong>Dette actuelle:</strong> <?php echo number_format($duesAmount, 2); ?> GNF<br>
                        <strong>Réduction de dette:</strong> <span id="dues_reduction_display">0.00</span> GNF<br>
                        <strong>Nouvelle dette:</strong> <span id="new_dues_display"><?php echo number_format($duesAmount, 2); ?></span> GNF
                      </p>
                    </div>
                    <?php endif; ?>
                  </div>
                </div>
              </div>
              
              <div class="row-fluid">
                <div class="span12 text-center">
                  <button type="submit" name="process_return" class="btn btn-success btn-large">
                    <i class="icon-ok"></i> Traiter le Retour
                  </button>
                  <button type="button" class="btn btn-secondary" onclick="window.location.reload();">
                    <i class="icon-refresh"></i> Annuler
                  </button>
                </div>
              </div>
            </div>
          </form>
        
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

<?php include_once('includes/footer.php');?>

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