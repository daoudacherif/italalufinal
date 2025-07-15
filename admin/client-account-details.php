<?php
session_start();
error_reporting(0);
include('includes/dbconnection.php');

if (strlen($_SESSION['imsaid'] == 0)) {
  header('location:logout.php');
  exit;
}

// Récupère le nom/téléphone passés en GET
$customerName = mysqli_real_escape_string($con, $_GET['name']);
$mobile       = mysqli_real_escape_string($con, $_GET['mobile']);

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
<?php include_once('includes/header.php'); ?>
<?php include_once('includes/sidebar.php'); ?>

<div id="content">
  <div id="content-header">
    <h1>Détails pour <?php echo htmlspecialchars($customerName); ?> (<?php echo htmlspecialchars($mobile); ?>)</h1>
  </div>
  <div class="container-fluid">
    <hr>

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
        $finalAmt = floatval($row['FinalAmount']);
        $paidAmt  = floatval($row['Paid']);
        $dueAmt   = floatval($row['Dues']);

        // On cumule
        $sumFinal += $finalAmt;
        $sumPaid  += $paidAmt;
        $sumDues  += $dueAmt;
        ?>
        <tr>
          <td><?php echo $cnt++; ?></td>
          <td><?php echo $row['BillingNumber']; ?></td>
          <td><?php echo $row['BillingDate']; ?></td>
          <td><?php echo number_format($finalAmt,2); ?></td>
          <td><?php echo number_format($paidAmt,2); ?></td>
          <td><?php echo number_format($dueAmt,2); ?></td>
        </tr>
        <?php
      }
      ?>
      </tbody>
      <tfoot>
        <tr style="font-weight: bold;">
          <td colspan="3" style="text-align: right;">TOTAL</td>
          <td><?php echo number_format($sumFinal,2); ?></td>
          <td><?php echo number_format($sumPaid,2); ?></td>
          <td><?php echo number_format($sumDues,2); ?></td>
        </tr>
      </tfoot>
    </table>
  </div><!-- container-fluid -->
  <div class="container-fluid">
  <a href="client-account.php" class="btn btn-secondary" style="margin-bottom: 15px;">← Retour</a>

  <table class="table table-bordered table-striped">

</div><!-- content -->

<?php include_once('includes/footer.php'); ?>
<script src="js/jquery.min.js"></script>
<script src="js/bootstrap.min.js"></script>
</body>
</html>