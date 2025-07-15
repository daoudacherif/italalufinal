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
$sqlPayments = "SELECT * FROM tblpayments WHERE CustomerID = $customerId ORDER BY PaymentDate DESC LIMIT 5";
$resPayments = mysqli_query($con, $sqlPayments);

// Handle payment submission
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
        $billingNumber = $customerInfo['BillingNumber'];

        $newPaid = $oldPaid + $payAmount;
        $newDues = $oldDues - $payAmount;
        if ($newDues < 0) {
            $newDues = 0; // cannot go below zero
        }

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

            // Commit the transaction
            mysqli_commit($con);
            echo "<script>
                alert('Paiement enregistré avec succès !');
                window.location.href = 'make-payment.php?id=$customerId';
            </script>";
            exit;
        } catch (Exception $e) {
            // Rollback in case of error
            mysqli_rollback($con);
            echo "<script>alert('Erreur lors de l\\'enregistrement du paiement: " . $e->getMessage() . "');</script>";
        }
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
        .payment-method-cash {
            background-color: #dff0d8;
        }
        .payment-method-card {
            background-color: #d9edf7;
        }
        .payment-method-transfer {
            background-color: #fcf8e3;
        }
        .payment-method-mobile {
            background-color: #f2dede;
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
    
    <div class="row-fluid">
      <!-- Payment Form -->
      <div class="span6">
        <div class="widget-box">
          <div class="widget-title">
            <span class="icon"><i class="icon-money"></i></span>
            <h5>Effectuer un Paiement</h5>
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
                    <input type="number" name="payAmount" step="1" min="1" max="<?php echo $customerInfo['Dues']; ?>" value="<?php echo $customerInfo['Dues']; ?>" class="span8" required />
                    <span class="help-block">Montant maximum : <?php echo number_format($customerInfo['Dues'], 0); ?></span>
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
                </tr>
                <?php
                  }
                } else {
                ?>
                <tr>
                  <td colspan="4" style="text-align: center;">Aucun paiement enregistré</td>
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
</body>
</html>