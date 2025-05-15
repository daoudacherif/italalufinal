<?php
session_start();
error_reporting(0);
include('includes/dbconnection.php');

if (strlen($_SESSION['imsaid'] == 0)) {
  header('location:logout.php');
  exit;
}

/**
 * Fonction pour envoyer un SMS via l'API Nimba
 */
function sendSmsNotification($to, $message) {
    $url = "https://api.nimbasms.com/v1/messages";
    $service_id = "1608e90e20415c7edf0226bf86e7effd";    
    $secret_token = "kokICa68N6NJESoJt09IAFXjO05tYwdVV-Xjrql7o8pTi29ssdPJyNgPBdRIeLx6_690b_wzM27foyDRpvmHztN7ep6ICm36CgNggEzGxRs";
    
    $authString = base64_encode($service_id . ":" . $secret_token);
    
    $payload = array(
        "to" => array($to),
        "message" => $message,
        "sender_name" => "SMS 9080"
    );
    
    $postData = json_encode($payload);
    
    $headers = array(
        "Authorization: Basic " . $authString,
        "Content-Type: application/json"
    );
    
    $options = array(
        "http" => array(
            "method" => "POST",
            "header" => implode("\r\n", $headers),
            "content" => $postData,
            "ignore_errors" => true
        )
    );
    
    $context = stream_context_create($options);
    $response = file_get_contents($url, false, $context);
    
    // Vérifier le statut
    if (!$http_response_header) {
        return false;
    }
    
    $status_line = $http_response_header[0];
    preg_match('{HTTP\/\S*\s(\d{3})}', $status_line, $match);
    $status_code = isset($match[1]) ? $match[1] : 0;
    
    return ($status_code == 201);
}

// Récupère le nom/téléphone passés en GET
$customerName = mysqli_real_escape_string($con, $_GET['name']);
$mobile = mysqli_real_escape_string($con, $_GET['mobile']);

// Traitement du paiement
if (isset($_POST['payDues'])) {
    $paymentAmount = intval($_POST['paymentAmount']);
    $paymentMethod = mysqli_real_escape_string($con, $_POST['paymentMethod']);
    
    if ($paymentAmount <= 0) {
        $error = "Le montant du paiement doit être supérieur à zéro.";
    } else {
        // Récupérer le total des dettes du client
        $getDuesQuery = "SELECT SUM(Dues) AS totalDues FROM tblcustomer 
                         WHERE CustomerName='$customerName' AND MobileNumber='$mobile'";
        $getDuesResult = mysqli_query($con, $getDuesQuery);
        $duesData = mysqli_fetch_assoc($getDuesResult);
        $totalDues = intval($duesData['totalDues']);
        
        // Vérifier que le montant à payer n'est pas supérieur aux dettes
        if ($paymentAmount > $totalDues) {
            $paymentAmount = $totalDues; // Limiter au montant total dû
        }
        
        // Calculer le pourcentage que représente ce paiement par rapport au total
        $paymentRatio = $paymentAmount / $totalDues;
        
        // Mettre à jour toutes les factures du client en répartissant le paiement proportionnellement
        $getBillsQuery = "SELECT ID, Dues FROM tblcustomer 
                          WHERE CustomerName='$customerName' AND MobileNumber='$mobile' AND Dues > 0";
        $getBillsResult = mysqli_query($con, $getBillsQuery);
        
        mysqli_begin_transaction($con);
        
        try {
            $totalApplied = 0;
            $billsToUpdate = array();
            
            // Première passe: calculer les montants à appliquer pour chaque facture
            while ($bill = mysqli_fetch_assoc($getBillsResult)) {
                $billId = $bill['ID'];
                $billDues = intval($bill['Dues']);
                
                // Calculer combien on paie pour cette facture (arrondi à l'entier)
                $billPayment = intval(round($billDues * $paymentRatio));
                
                // S'assurer que le montant n'est pas supérieur aux dettes de la facture
                if ($billPayment > $billDues) {
                    $billPayment = $billDues;
                }
                
                $totalApplied += $billPayment;
                $billsToUpdate[$billId] = $billPayment;
            }
            
            // Ajuster le dernier paiement si nécessaire pour atteindre le montant exact
            if ($totalApplied != $paymentAmount && !empty($billsToUpdate)) {
                $difference = $paymentAmount - $totalApplied;
                $lastBillId = array_key_last($billsToUpdate);
                $billsToUpdate[$lastBillId] += $difference;
            }
            
            // Deuxième passe: appliquer les paiements
            foreach ($billsToUpdate as $billId => $billPayment) {
                // Mettre à jour cette facture
                $updateQuery = "UPDATE tblcustomer SET 
                               Paid = Paid + $billPayment, 
                               Dues = Dues - $billPayment,
                               ModeofPayment = '$paymentMethod'
                               WHERE ID = '$billId'";
                               
                mysqli_query($con, $updateQuery);
            }
            
            mysqli_commit($con);
            
            // Calculer le nouveau solde total après paiement
            $getNewDuesQuery = "SELECT SUM(Dues) AS totalDues FROM tblcustomer 
                               WHERE CustomerName='$customerName' AND MobileNumber='$mobile'";
            $getNewDuesResult = mysqli_query($con, $getNewDuesQuery);
            $newDuesData = mysqli_fetch_assoc($getNewDuesResult);
            $newTotalDues = intval($newDuesData['totalDues']);
            
            // Envoyer un SMS au client
            if (isset($_POST['sendSms']) && !empty($mobile)) {
                $formattedAmount = number_format($paymentAmount, 0);
                $formattedDues = number_format($newTotalDues, 0);
                
                $smsMessage = "Cher(e) $customerName, votre paiement de $formattedAmount a été reçu avec succès. ";
                
                if ($newTotalDues > 0) {
                    $smsMessage .= "Votre solde restant est de $formattedDues.";
                } else {
                    $smsMessage .= "Toutes vos factures sont maintenant réglées. Merci!";
                }
                
                sendSmsNotification($mobile, $smsMessage);
            }
            
            $msg = "Paiement de " . number_format($paymentAmount, 0) . " effectué avec succès.";
            
        } catch (Exception $e) {
            mysqli_rollback($con);
            $error = "Erreur lors du paiement: " . $e->getMessage();
        }
    }
}

// Requête : toutes les factures de ce client
$sql = "
  SELECT ID, BillingNumber, BillingDate, FinalAmount, Paid, Dues
  FROM tblcustomer
  WHERE CustomerName='$customerName' AND MobileNumber='$mobile'
  ORDER BY BillingDate DESC
";
$res = mysqli_query($con, $sql);
?>
<!DOCTYPE html>
<html lang="fr">
<head>
  <title>Détails du Compte Client</title>
  <?php include_once('includes/cs.php'); ?>
  <?php include_once('includes/responsive.php'); ?>
  <style>
    .payment-form {
      margin-top: 20px;
      padding: 15px;
      border: 1px solid #ddd;
      border-radius: 5px;
      background-color: #f9f9f9;
    }
    .alert {
      padding: 15px;
      margin-bottom: 20px;
      border: 1px solid transparent;
      border-radius: 4px;
    }
    .alert-success {
      color: #3c763d;
      background-color: #dff0d8;
      border-color: #d6e9c6;
    }
    .alert-danger {
      color: #a94442;
      background-color: #f2dede;
      border-color: #ebccd1;
    }
    .client-info {
      background-color: #f5f5f5;
      border-left: 4px solid #337ab7;
      padding: 10px 15px;
      margin-bottom: 20px;
    }
    .dues-highlight {
      font-size: 18px;
      font-weight: bold;
      color: #d9534f;
    }
  </style>
</head>
<body>
<?php include_once('includes/header.php'); ?>
<?php include_once('includes/sidebar.php'); ?>

<div id="content">
  <div id="content-header">
    <h1>Détails pour <?php echo htmlspecialchars($customerName); ?> (<?php echo htmlspecialchars($mobile); ?>)</h1>
  </div>
  <div class="container-fluid">
    <hr>
    
    <?php if(isset($msg)){ ?>
    <div class="alert alert-success">
      <?php echo htmlspecialchars($msg); ?>
    </div>
    <?php } ?>
    
    <?php if(isset($error)){ ?>
    <div class="alert alert-danger">
      <?php echo $error; ?>
    </div>
    <?php } ?>
    
    <div class="client-info">
      <h4>Informations client</h4>
      <p><strong>Nom:</strong> <?php echo htmlspecialchars($customerName); ?></p>
      <p><strong>Téléphone:</strong> <?php echo htmlspecialchars($mobile); ?></p>
    </div>

    <table class="table table-bordered table-striped">
      <thead>
        <tr>
          <th>#</th>
          <th>Numéro de Facture</th>
          <th>Date</th>
          <th>Montant Final</th>
          <th>Payé</th>
          <th>Reste</th>
        </tr>
      </thead>
      <tbody>
      <?php
      // Variables pour cumuler les totaux
      $sumFinal = 0;
      $sumPaid  = 0;
      $sumDues  = 0;

      $cnt=1;
      while ($row = mysqli_fetch_assoc($res)) {
        $finalAmt = intval($row['FinalAmount']);
        $paidAmt  = intval($row['Paid']);
        $dueAmt   = intval($row['Dues']);

        // On cumule
        $sumFinal += $finalAmt;
        $sumPaid  += $paidAmt;
        $sumDues  += $dueAmt;
        ?>
        <tr>
          <td><?php echo $cnt++; ?></td>
          <td><?php echo $row['BillingNumber']; ?></td>
          <td><?php echo $row['BillingDate']; ?></td>
          <td><?php echo number_format($finalAmt, 0); ?></td>
          <td><?php echo number_format($paidAmt, 0); ?></td>
          <td><?php echo number_format($dueAmt, 0); ?></td>
        </tr>
        <?php
      }
      ?>
      </tbody>
      <tfoot>
        <tr style="font-weight: bold;">
          <td colspan="3" style="text-align: right;">TOTAL</td>
          <td><?php echo number_format($sumFinal, 0); ?></td>
          <td><?php echo number_format($sumPaid, 0); ?></td>
          <td><?php echo number_format($sumDues, 0); ?></td>
        </tr>
      </tfoot>
    </table>
  </div><!-- container-fluid -->
  
  <?php if($sumDues > 0) { ?>
  <div class="container-fluid">
    <div class="payment-form">
      <h3>Règlement de votre solde</h3>
      <p>Votre solde total à payer est de: <span class="dues-highlight"><?php echo number_format($sumDues, 0); ?></span></p>
      
      <form method="post" action="">
        <div class="form-group">
          <label for="paymentAmount"><strong>Montant à payer:</strong></label>
          <input type="number" step="1" min="1" max="<?php echo $sumDues; ?>" class="form-control" id="paymentAmount" name="paymentAmount" value="<?php echo $sumDues; ?>" required>
        </div>
        
        <div class="form-group">
          <label for="paymentMethod"><strong>Mode de paiement:</strong></label>
          <select class="form-control" id="paymentMethod" name="paymentMethod" required>
            <option value="">Sélectionner</option>
            <option value="Espèces">Espèces</option>
            <option value="Carte de crédit">Carte de crédit</option>
            <option value="Mobile Money">Mobile Money</option>
            <option value="Virement bancaire">Virement bancaire</option>
            <option value="Chèque">Chèque</option>
          </select>
        </div>
        
        <div class="form-check" style="margin: 15px 0;">
          <input type="checkbox" class="form-check-input" id="sendSms" name="sendSms" checked>
          <label class="form-check-label" for="sendSms">Envoyer un SMS de confirmation au client</label>
        </div>
        
        <button type="submit" name="payDues" class="btn btn-success btn-lg">Effectuer le paiement</button>
      </form>
    </div>
  </div>
  <?php } else { ?>
  <div class="container-fluid">
    <div class="alert alert-success">
      <h4>Félicitations! Toutes les factures sont intégralement payées.</h4>
      <p>Le client n'a aucun solde dû actuellement.</p>
      
      <div class="form-check" style="margin-top: 15px;">
        <form method="post" action="">
          <input type="hidden" name="sendThankYouSms" value="1">
          <input type="checkbox" class="form-check-input" id="sendThanksSms" name="sendSms" checked>
          <label class="form-check-label" for="sendThanksSms">Envoyer un SMS de remerciement au client</label>
          <button type="submit" class="btn btn-primary" style="margin-top: 10px;">Envoyer SMS</button>
        </form>
      </div>
    </div>
  </div>
  <?php } ?>
  
  <!-- Traitement du SMS de remerciement -->
  <?php
  if(isset($_POST['sendThankYouSms']) && isset($_POST['sendSms'])) {
    $thankYouMessage = "Cher(e) $customerName, nous vous remercions pour votre paiement. Toutes vos factures sont réglées. À bientôt!";
    $smsResult = sendSmsNotification($mobile, $thankYouMessage);
    
    if($smsResult) {
      echo '<script>alert("SMS de remerciement envoyé avec succès.");</script>';
    } else {
      echo '<script>alert("Échec de l\'envoi du SMS de remerciement.");</script>';
    }
  }
  ?>
  
  <div class="container-fluid">
    <a href="client-account.php" class="btn btn-secondary" style="margin: 15px 0;">← Retour</a>
  </div>
</div><!-- content -->

<?php include_once('includes/footer.php'); ?>
<script src="js/jquery.min.js"></script>
<script src="js/bootstrap.min.js"></script>
</body>
</html>