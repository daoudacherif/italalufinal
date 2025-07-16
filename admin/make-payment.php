<?php
session_start();
error_reporting(0);
include('includes/dbconnection.php');

// Check admin login
if (strlen($_SESSION['imsaid'] == 0)) {
    header('location:logout.php');
    exit;
}

// Check if customer ID is provided
if (!isset($_GET['id']) || empty($_GET['id'])) {
    echo "<script>alert('ID client non spécifié.'); window.location.href='customer-details.php';</script>";
    exit;
}

$customerId = intval($_GET['id']);

// Fetch customer details
$sqlCustomer = "SELECT * FROM tblcustomer WHERE ID = $customerId LIMIT 1";
$resCustomer = mysqli_query($con, $sqlCustomer);
if (mysqli_num_rows($resCustomer) == 0) {
    echo "<script>alert('Client non trouvé.'); window.location.href='customer-details.php';</script>";
    exit;
}
$customerInfo = mysqli_fetch_assoc($resCustomer);

// Fetch recent payments
$sqlPayments = "SELECT p.*, 
                CASE WHEN p.EcheanceId IS NOT NULL THEN 'Échéance' ELSE 'Global' END as TypePaiement
                FROM tblpayments p 
                WHERE p.CustomerID = $customerId 
                ORDER BY p.PaymentDate DESC LIMIT 5";
$resPayments = mysqli_query($con, $sqlPayments);

// Fetch échéances for this customer's billing
$billingNumber = $customerInfo['BillingNumber'];
$sqlEcheances = "SELECT cc.*, cc.ID as CreditCartID, cc.ProductId, cc.ProductQty, cc.Price, 
                 cc.DateEcheance, cc.TypeEcheance, cc.StatutEcheance, cc.NombreJours,
                 CASE 
                   WHEN cc.DateEcheance IS NOT NULL AND cc.DateEcheance < CURDATE() AND cc.StatutEcheance != 'regle' THEN 'en_retard'
                   ELSE cc.StatutEcheance 
                 END as StatutActuel,
                 DATEDIFF(cc.DateEcheance, CURDATE()) as JoursRestants
                 FROM tblcreditcart cc 
                 WHERE cc.BillingId = '$billingNumber' AND cc.IsCheckOut = 1
                 ORDER BY cc.DateEcheance ASC, cc.ID ASC";
$resEcheances = mysqli_query($con, $sqlEcheances);

// Handle échéance payment
if (isset($_POST['payEcheance'])) {
    $creditCartId = intval($_POST['creditCartId']);
    $paymentMethod = isset($_POST['paymentMethod']) ? $_POST['paymentMethod'] : 'Cash';
    $reference = isset($_POST['reference']) ? $_POST['reference'] : null;
    $comments = isset($_POST['comments']) ? $_POST['comments'] : null;

    // Get échéance details
    $sqlEcheanceDetail = "SELECT * FROM tblcreditcart WHERE ID = $creditCartId LIMIT 1";
    $resEcheanceDetail = mysqli_query($con, $sqlEcheanceDetail);
    $echeanceInfo = mysqli_fetch_assoc($resEcheanceDetail);
    
    $echeanceAmount = intval(floatval($echeanceInfo['Price']) * intval($echeanceInfo['ProductQty']));

    // Begin transaction
    mysqli_begin_transaction($con);
    try {
        // 1. Update échéance status
        $updateEcheance = "UPDATE tblcreditcart 
                          SET StatutEcheance = 'regle' 
                          WHERE ID = '$creditCartId'";
        mysqli_query($con, $updateEcheance);

        // 2. Update customer amounts
        $oldPaid = intval($customerInfo['Paid']);
        $oldDues = intval($customerInfo['Dues']);
        
        $newPaid = $oldPaid + $echeanceAmount;
        $newDues = $oldDues - $echeanceAmount;
        if ($newDues < 0) $newDues = 0;

        $updateCustomer = "UPDATE tblcustomer 
                          SET Paid='$newPaid', Dues='$newDues'
                          WHERE ID='$customerId'";
        mysqli_query($con, $updateCustomer);

        // 3. Insert payment record
        $insertPayment = "INSERT INTO tblpayments 
                         (CustomerID, BillingNumber, PaymentAmount, PaymentMethod, ReferenceNumber, Comments, EcheanceId) 
                         VALUES 
                         ('$customerId', '$billingNumber', '$echeanceAmount', '$paymentMethod', '$reference', '$comments', '$creditCartId')";
        mysqli_query($con, $insertPayment);

        mysqli_commit($con);
        echo "<script>
            alert('Paiement d\\'échéance enregistré avec succès !');
            window.location.href = 'make-payment.php?id=$customerId';
        </script>";
        exit;
    } catch (Exception $e) {
        mysqli_rollback($con);
        echo "<script>alert('Erreur lors du paiement de l\\'échéance: " . $e->getMessage() . "');</script>";
    }
}

// Handle regular payment submission
if (isset($_POST['submitPayment'])) {
    $payAmount = intval($_POST['payAmount']);
    $paymentMethod = isset($_POST['paymentMethod']) ? $_POST['paymentMethod'] : 'Cash';
    $reference = isset($_POST['reference']) ? $_POST['reference'] : null;
    $comments = isset($_POST['comments']) ? $_POST['comments'] : null;

    if ($payAmount <= 0) {
        echo "<script>alert('Montant invalide. Doit être > 0.');</script>";
    } else if ($payAmount > $customerInfo['Dues']) {
        echo "<script>alert('Montant du paiement supérieur au montant dû.');</script>";
    } else {
        // Calculate new amounts
        $oldPaid = intval($customerInfo['Paid']);
        $oldDues = intval($customerInfo['Dues']);

        $newPaid = $oldPaid + $payAmount;
        $newDues = $oldDues - $payAmount;
        if ($newDues < 0) $newDues = 0;

        // Begin transaction
        mysqli_begin_transaction($con);
        try {
            // 1. Update the tblcustomer record
            $update = "UPDATE tblcustomer 
                       SET Paid='$newPaid', Dues='$newDues'
                       WHERE ID='$customerId'";
            mysqli_query($con, $update);

            // 2. Insert payment record into tblpayments
            $insertPayment = "INSERT INTO tblpayments 
                             (CustomerID, BillingNumber, PaymentAmount, PaymentMethod, ReferenceNumber, Comments) 
                             VALUES 
                             ('$customerId', '$billingNumber', '$payAmount', '$paymentMethod', '$reference', '$comments')";
            mysqli_query($con, $insertPayment);

            mysqli_commit($con);
            echo "<script>
                alert('Paiement enregistré avec succès !');
                window.location.href = 'make-payment.php?id=$customerId';
            </script>";
            exit;
        } catch (Exception $e) {
            mysqli_rollback($con);
            echo "<script>alert('Erreur lors de l\\'enregistrement du paiement: " . $e->getMessage() . "');</script>";
        }
    }
}

// Function to get status badge
function getStatusBadge($statut) {
    switch($statut) {
        case 'regle': return '<span class="label label-success">Réglé</span>';
        case 'en_retard': return '<span class="label label-important">En retard</span>';
        case 'echu': return '<span class="label label-warning">Échu</span>';
        case 'en_cours': return '<span class="label label-info">En cours</span>';
        default: return '<span class="label">' . $statut . '</span>';
    }
}

// Function to get type échéance
function getTypeEcheance($type) {
    switch($type) {
        case 'immediat': return 'Immédiat';
        case '7_jours': return '7 jours';
        case '15_jours': return '15 jours';
        case '30_jours': return '30 jours';
        case '60_jours': return '60 jours';
        case '90_jours': return '90 jours';
        case 'personnalise': return 'Personnalisé';
        default: return $type;
    }
}
?>
<!DOCTYPE html>
<html lang="fr">
<head>
    <title>Gestion des Stocks | Effectuer un Paiement</title>
    <?php include_once('includes/cs.php'); ?>
    <?php include_once('includes/responsive.php'); ?>
    <style>
        .customer-details {
            background-color: #f9f9f9;
            border-radius: 5px;
            padding: 15px;
            margin-bottom: 20px;
        }
        .payment-box {
            background-color: #fff;
            border: 1px solid #ddd;
            border-radius: 5px;
            padding: 20px;
            margin-bottom: 20px;
        }
        .echeance-item {
            border: 1px solid #ddd;
            border-radius: 5px;
            padding: 15px;
            margin-bottom: 10px;
            background-color: #fff;
        }
        .echeance-en-retard {
            border-left: 4px solid #d9534f;
            background-color: #f2dede;
        }
        .echeance-echu {
            border-left: 4px solid #f0ad4e;
            background-color: #fcf8e3;
        }
        .echeance-en-cours {
            border-left: 4px solid #5bc0de;
            background-color: #d9edf7;
        }
        .echeance-regle {
            border-left: 4px solid #5cb85c;
            background-color: #dff0d8;
        }
        .payment-method-cash { background-color: #dff0d8; }
        .payment-method-card { background-color: #d9edf7; }
        .payment-method-transfer { background-color: #fcf8e3; }
        .payment-method-mobile { background-color: #f2dede; }
        .echeance-summary {
            background-color: #f5f5f5;
            border: 1px solid #ddd;
            border-radius: 5px;
            padding: 15px;
            margin-bottom: 20px;
        }
    </style>
</head>
<body>
<?php include_once('includes/header.php'); ?>
<?php include_once('includes/sidebar.php'); ?>

<div id="content">
  <div id="content-header">
    <div id="breadcrumb">
      <a href="dashboard.php" title="Aller à l'accueil" class="tip-bottom">
        <i class="icon-home"></i> Accueil
      </a>
      <a href="customer-details.php">Détails Client</a>
      <a href="make-payment.php?id=<?php echo $customerId; ?>" class="current">Effectuer un Paiement</a>
    </div>
    <h1>Effectuer un Paiement</h1>
  </div>

  <div class="container-fluid">
    <hr>
    
    <!-- Customer Details -->
    <div class="row-fluid">
      <div class="span12">
        <div class="customer-details">
          <div class="row-fluid">
            <div class="span4">
              <h4>Informations Client</h4>
              <p><strong>Nom :</strong> <?php echo $customerInfo['CustomerName']; ?></p>
              <p><strong>Mobile :</strong> <?php echo $customerInfo['MobileNumber']; ?></p>
              <p><strong>Mode de paiement initial :</strong> <?php echo $customerInfo['ModeofPayment']; ?></p>
            </div>
            <div class="span4">
              <h4>Informations Facture</h4>
              <p><strong>N° Facture :</strong> <?php echo $customerInfo['BillingNumber']; ?></p>
              <p><strong>Date Facture :</strong> <?php echo date('d/m/Y', strtotime($customerInfo['BillingDate'])); ?></p>
              <p><strong>Montant Total :</strong> <?php echo number_format($customerInfo['FinalAmount'], 0); ?></p>
            </div>
            <div class="span4">
              <h4>État Paiement</h4>
              <p><strong>Montant Payé :</strong> <?php echo number_format($customerInfo['Paid'], 0); ?></p>
              <p><strong>Montant Dû :</strong> <?php echo number_format($customerInfo['Dues'], 0); ?></p>
              <p><strong>Statut :</strong> 
                <?php if ($customerInfo['Dues'] <= 0) { ?>
                  <span class="label label-success">Soldé</span>
                <?php } else { ?>
                  <span class="label label-warning">En attente</span>
                <?php } ?>
              </p>
            </div>
          </div>
        </div>
      </div>
    </div>

    <!-- Échéances Summary -->
    <?php 
    // Calculate échéances summary
    $totalEcheances = 0;
    $echeancesEnRetard = 0;
    $echeancesEnCours = 0;
    $echeancesReglees = 0;
    $montantTotal = 0;
    $montantEnRetard = 0;
    
    if (mysqli_num_rows($resEcheances) > 0) {
        mysqli_data_seek($resEcheances, 0); // Reset result pointer
        while ($echeance = mysqli_fetch_assoc($resEcheances)) {
            $totalEcheances++;
            $montant = floatval($echeance['Price']) * intval($echeance['ProductQty']);
            $montantTotal += $montant;
            
            $statut = $echeance['StatutActuel'];
            switch($statut) {
                case 'en_retard':
                    $echeancesEnRetard++;
                    $montantEnRetard += $montant;
                    break;
                case 'en_cours':
                    $echeancesEnCours++;
                    break;
                case 'regle':
                    $echeancesReglees++;
                    break;
            }
        }
        mysqli_data_seek($resEcheances, 0); // Reset again for display
    ?>
    
    <div class="row-fluid">
      <div class="span12">
        <div class="echeance-summary">
          <h4><i class="icon-calendar"></i> Résumé des Échéances</h4>
          <div class="row-fluid">
            <div class="span3">
              <strong>Total Échéances :</strong> <?php echo $totalEcheances; ?>
            </div>
            <div class="span3">
              <strong>En retard :</strong> <span class="text-error"><?php echo $echeancesEnRetard; ?></span>
            </div>
            <div class="span3">
              <strong>En cours :</strong> <span class="text-info"><?php echo $echeancesEnCours; ?></span>
            </div>
            <div class="span3">
              <strong>Réglées :</strong> <span class="text-success"><?php echo $echeancesReglees; ?></span>
            </div>
          </div>
          <div class="row-fluid" style="margin-top: 10px;">
            <div class="span6">
              <strong>Montant total des échéances :</strong> <?php echo number_format($montantTotal, 0); ?> CFA
            </div>
            <div class="span6">
              <strong>Montant en retard :</strong> <span class="text-error"><?php echo number_format($montantEnRetard, 0); ?> CFA</span>
            </div>
          </div>
        </div>
      </div>
    </div>
    <?php } ?>
    
    <!-- Échéances List -->
    <?php if (mysqli_num_rows($resEcheances) > 0) { ?>
    <div class="row-fluid">
      <div class="span12">
        <div class="widget-box">
          <div class="widget-title">
            <span class="icon"><i class="icon-calendar"></i></span>
            <h5>Gestion des Échéances</h5>
          </div>
          <div class="widget-content">
              <?php 
              mysqli_data_seek($resEcheances, 0); // Reset result pointer for display
              while ($echeance = mysqli_fetch_assoc($resEcheances)) { 
                $montantEcheance = floatval($echeance['Price']) * intval($echeance['ProductQty']);
                $statut = $echeance['StatutActuel'];
                $cssClass = 'echeance-' . str_replace('_', '-', $statut);
              ?>
              <div class="echeance-item <?php echo $cssClass; ?>">
                <div class="row-fluid">
                  <div class="span8">
                    <div class="row-fluid">
                      <div class="span6">
                        <strong>Produit ID :</strong> <?php echo $echeance['ProductId']; ?><br>
                        <strong>Quantité :</strong> <?php echo $echeance['ProductQty']; ?><br>
                        <strong>Prix unitaire :</strong> <?php echo number_format($echeance['Price'], 0); ?> CFA<br>
                        <strong>Montant total :</strong> <span class="text-primary"><?php echo number_format($montantEcheance, 0); ?> CFA</span>
                      </div>
                      <div class="span6">
                        <strong>Type :</strong> <?php echo getTypeEcheance($echeance['TypeEcheance']); ?><br>
                        <strong>Date échéance :</strong> 
                        <?php 
                        if ($echeance['DateEcheance']) {
                          echo date('d/m/Y', strtotime($echeance['DateEcheance']));
                          if ($echeance['JoursRestants'] !== null) {
                            if ($echeance['JoursRestants'] < 0) {
                              echo ' <span class="text-error">(' . abs($echeance['JoursRestants']) . ' jours de retard)</span>';
                            } else if ($echeance['JoursRestants'] == 0) {
                              echo ' <span class="text-warning">(Aujourd\'hui)</span>';
                            } else {
                              echo ' <span class="text-info">(dans ' . $echeance['JoursRestants'] . ' jours)</span>';
                            }
                          }
                        } else {
                          echo 'Non définie';
                        }
                        ?><br>
                        <strong>Statut :</strong> <?php echo getStatusBadge($statut); ?>
                      </div>
                    </div>
                  </div>
                  <div class="span4" style="text-align: right;">
                    <h4><?php echo number_format($montantEcheance, 0); ?> CFA</h4>
                    <?php if ($statut != 'regle') { ?>
                    <form method="post" style="margin-top: 10px;">
                      <input type="hidden" name="creditCartId" value="<?php echo $echeance['CreditCartID']; ?>">
                      <div class="input-append">
                        <select name="paymentMethod" class="span8">
                          <option value="Cash">Espèces</option>
                          <option value="Card">Carte</option>
                          <option value="Transfer">Virement</option>
                          <option value="Mobile">Mobile</option>
                        </select>
                      </div>
                      <div style="margin-top: 5px;">
                        <input type="text" name="reference" placeholder="Référence" class="span8">
                      </div>
                      <div style="margin-top: 5px;">
                        <button type="submit" name="payEcheance" class="btn btn-success btn-small">
                          <i class="icon-check"></i> Payer
                        </button>
                      </div>
                    </form>
                    <?php } else { ?>
                      <p class="text-success"><i class="icon-ok"></i> Payé</p>
                    <?php } ?>
                  </div>
                </div>
              </div>
              <?php } ?>
          </div>
        </div>
      </div>
    </div>
    <?php } ?>
    
    <div class="row-fluid">
      <!-- Regular Payment Form -->
      <div class="span6">
        <div class="widget-box">
          <div class="widget-title">
            <span class="icon"><i class="icon-money"></i></span>
            <h5>Paiement Global</h5>
          </div>
          <div class="widget-content">
            <?php if ($customerInfo['Dues'] <= 0) { ?>
              <div class="alert alert-success">
                <strong>Cette facture est déjà soldée !</strong> Aucun paiement supplémentaire n'est nécessaire.
              </div>
            <?php } else { ?>
              <form method="post" class="form-horizontal">
                <div class="control-group">
                  <label class="control-label">Montant du Paiement :</label>
                  <div class="controls">
                    <input type="number" name="payAmount" step="1" min="1" max="<?php echo intval($customerInfo['Dues']); ?>" value="<?php echo intval($customerInfo['Dues']); ?>" class="span8" required />
                    <span class="help-block">Montant maximum : <?php echo number_format($customerInfo['Dues'], 0); ?> CFA</span>
                  </div>
                </div>
                
                <div class="control-group">
                  <label class="control-label">Méthode de Paiement :</label>
                  <div class="controls">
                    <select name="paymentMethod" class="span8">
                      <option value="Cash">Espèces</option>
                      <option value="Card">Carte</option>
                      <option value="Transfer">Virement</option>
                      <option value="Mobile">Mobile</option>
                    </select>
                  </div>
                </div>
                
                <div class="control-group">
                  <label class="control-label">Référence :</label>
                  <div class="controls">
                    <input type="text" name="reference" class="span8" placeholder="Numéro de référence (optionnel)" />
                  </div>
                </div>
                
                <div class="control-group">
                  <label class="control-label">Commentaires :</label>
                  <div class="controls">
                    <textarea name="comments" class="span8" rows="3" placeholder="Commentaires (optionnel)"></textarea>
                  </div>
                </div>
                
                <div class="form-actions">
                  <button type="submit" name="submitPayment" class="btn btn-success">
                    <i class="icon-check"></i> Confirmer le Paiement
                  </button>
                  <a href="customer-details.php" class="btn btn-danger">
                    <i class="icon-remove"></i> Annuler
                  </a>
                </div>
              </form>
            <?php } ?>
          </div>
        </div>
      </div>
      
      <!-- Recent Payments -->
      <div class="span6">
        <div class="widget-box">
          <div class="widget-title">
            <span class="icon"><i class="icon-time"></i></span>
            <h5>Paiements Récents</h5>
          </div>
          <div class="widget-content nopadding">
            <table class="table table-bordered table-striped">
              <thead>
                <tr>
                  <th>Date</th>
                  <th>Montant</th>
                  <th>Méthode</th>
                  <th>Référence</th>
                  <th>Type</th>
                </tr>
              </thead>
              <tbody>
                <?php
                if (mysqli_num_rows($resPayments) > 0) {
                  while ($payment = mysqli_fetch_assoc($resPayments)) {
                    $methodClass = '';
                    switch($payment['PaymentMethod']) {
                      case 'Cash': $methodClass = 'payment-method-cash'; break;
                      case 'Card': $methodClass = 'payment-method-card'; break;
                      case 'Transfer': $methodClass = 'payment-method-transfer'; break;
                      case 'Mobile': $methodClass = 'payment-method-mobile'; break;
                    }
                ?>
                <tr class="<?php echo $methodClass; ?>">
                  <td><?php echo date('d/m/Y H:i', strtotime($payment['PaymentDate'])); ?></td>
                  <td><?php echo number_format($payment['PaymentAmount'], 0); ?></td>
                  <td><?php echo $payment['PaymentMethod']; ?></td>
                  <td><?php echo $payment['ReferenceNumber'] ? $payment['ReferenceNumber'] : '-'; ?></td>
                  <td>
                    <?php 
                    echo $payment['TypePaiement'];
                    ?>
                  </td>
                </tr>
                <?php
                  }
                } else {
                ?>
                <tr>
                  <td colspan="5" style="text-align: center;">Aucun paiement enregistré</td>
                </tr>
                <?php } ?>
              </tbody>
            </table>
          </div>
          <div class="widget-footer">
            <a href="payment-history.php?cid=<?php echo $customerId; ?>" class="btn btn-info btn-block">
              <i class="icon-list"></i> Voir tout l'historique
            </a>
          </div>
        </div>
      </div>
    </div>
    
  </div>
</div>

<!-- Footer -->
<?php include_once('includes/footer.php'); ?>

<!-- Scripts -->
<script src="js/jquery.min.js"></script>
<script src="js/jquery.ui.custom.js"></script>
<script src="js/bootstrap.min.js"></script>
<script src="js/jquery.uniform.js"></script>
<script src="js/select2.min.js"></script>
<script src="js/matrix.js"></script>

<script>
$(document).ready(function() {
    // Confirmation pour les paiements d'échéances
    $('form').on('submit', function(e) {
        if ($(this).find('input[name="creditCartId"]').length > 0) {
            var montant = $(this).closest('.echeance-item').find('h4').text();
            if (!confirm('Confirmer le paiement de l\'échéance de ' + montant + ' ?')) {
                e.preventDefault();
            }
        }
    });
});
</script>
</body>
</html>