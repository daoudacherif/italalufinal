<?php
session_start();
error_reporting(0);
include('includes/dbconnection.php');

// Check if admin is logged in
if (strlen($_SESSION['imsaid'] == 0)) {
  header('location:logout.php');
  exit;
}

// ==========================
// 1) Handle new return submission
// ==========================
if (isset($_POST['submit'])) {
  $billingNumber = mysqli_real_escape_string($con, $_POST['billingnumber']);
  $productID     = intval($_POST['productid']);
  $quantity      = intval($_POST['quantity']);
  $returnPrice   = floatval($_POST['price']); // <-- new price field
  $returnDate    = $_POST['returndate'];
  $reason        = mysqli_real_escape_string($con, $_POST['reason']);

  // Basic validation
  if (empty($billingNumber) || $productID <= 0 || $quantity <= 0 || $returnPrice < 0) {
    echo "<script>alert('Données invalides. Veuillez vérifier le numéro de facturation, le produit, la quantité et le prix.');</script>";
  } else {
    // Insert into tblreturns (including ReturnPrice)
    $sqlInsert = "
      INSERT INTO tblreturns(
        BillingNumber,
        ReturnDate,
        ProductID,
        Quantity,
        Reason,
        ReturnPrice
      ) VALUES(
        '$billingNumber',
        '$returnDate',
        '$productID',
        '$quantity',
        '$reason',
        '$returnPrice'
      )
    ";
    $queryInsert = mysqli_query($con, $sqlInsert);

    if ($queryInsert) {
      // Update product stock
      $sqlUpdate = "UPDATE tblproducts
              SET Stock = Stock + $quantity
              WHERE ID='$productID'";
      mysqli_query($con, $sqlUpdate);

      echo "<script>alert('Retour enregistré (avec prix personnalisé) et stock mis à jour!');</script>";
    } else {
      echo "<script>alert('Erreur lors de l\'insertion de l\'enregistrement de retour.');</script>";
    }
  }
  // Refresh
  echo "<script>window.location.href='return.php'</script>";
  exit;
}
?>
<!DOCTYPE html>
<html lang="fr">
<head>
  <title>Gestion des stocks | Retours de Article</title>
  <?php include_once('includes/cs.php'); ?>
  <?php include_once('includes/responsive.php'); ?>
<!-- Header + Sidebar -->
<?php include_once('includes/header.php'); ?>
<?php include_once('includes/sidebar.php'); ?>

<div id="content">
  <div id="content-header">
    <div id="breadcrumb">
    <a href="dashboard.php" title="Aller à l'accueil" class="tip-bottom">
      <i class="icon-home"></i> Accueil
    </a>
    <a href="return.php" class="current">Retours de Article</a>
    </div>
    <h1>Gérer les retours de Article</h1>
  </div>

  <div class="container-fluid">
    <hr>

    <!-- =========== NEW RETURN FORM =========== -->
    <div class="row-fluid">
      <div class="span12">
        <div class="widget-box">
          <div class="widget-title">
          <span class="icon"><i class="icon-align-justify"></i></span>
          <h5>Ajouter un nouveau retour</h5>
          </div>
          <div class="widget-content nopadding">
          <form method="post" class="form-horizontal">

            <!-- Billing Number -->
            <div class="control-group">
            <label class="control-label">Numéro de facture :</label>
            <div class="controls">
              <input type="text" name="billingnumber" placeholder="ex. 123456789" required />
            </div>
            </div>

            <!-- Return Date -->
            <div class="control-group">
            <label class="control-label">Date de retour :</label>
            <div class="controls">
              <input type="date" name="returndate" value="<?php echo date('Y-m-d'); ?>" required />
            </div>
            </div>

            <!-- Product Selection -->
            <div class="control-group">
            <label class="control-label">Sélectionner un produit :</label>
            <div class="controls">
              <select name="productid" required>
              <option value="">-- Choisir un produit --</option>
              <?php
              // Load products from tblproducts
              $prodQuery = mysqli_query($con, "SELECT ID, ProductName FROM tblproducts ORDER BY ProductName ASC");
              while ($prodRow = mysqli_fetch_assoc($prodQuery)) {
                echo '<option value="'.$prodRow['ID'].'">'.$prodRow['ProductName'].'</option>';
              }
              ?>
              </select>
            </div>
            </div>

            <!-- Quantity -->
            <div class="control-group">
            <label class="control-label">Quantité retournée :</label>
            <div class="controls">
              <input type="number" name="quantity" min="1" value="1" required />
            </div>
            </div>

            <!-- Price (new field) -->
            <div class="control-group">
            <label class="control-label">Prix :</label>
            <div class="controls">
              <input type="number" name="price" step="any" min="0" value="0" required />
            </div>
            </div>

            <!-- Reason -->
            <div class="control-group">
            <label class="control-label">Raison (facultatif) :</label>
            <div class="controls">
              <input type="text" name="reason" placeholder="ex. Défaut, Mauvaise taille, etc." />
            </div>
            </div>

            <div class="form-actions">
            <button type="submit" name="submit" class="btn btn-success">
              Enregistrer le retour
            </button>
            </div>
          </form>
          </div><!-- widget-content nopadding -->
        </div><!-- widget-box -->
      </div>
    </div><!-- row-fluid -->

    <hr>

    <!-- =========== LIST OF RECENT RETURNS =========== -->
    <div class="row-fluid">
      <div class="span12">
        <div class="widget-box">
          <div class="widget-title">
          <span class="icon"><i class="icon-th"></i></span>
          <h5>Retours récents</h5>
          </div>
          <div class="widget-content nopadding">
          <table class="table table-bordered data-table">
            <thead>
            <tr>
              <th>#</th>
              <th>Numéro de facture</th>
              <th>Date de retour</th>
              <th>Produit</th>
              <th>Quantité</th>
              <th>Prix</th>
              <th>Raison</th>
            </tr>
            </thead>
            <tbody>
            <?php
            // Join tblreturns with tblproducts to display product name
            $sqlReturns = "
              SELECT r.ID as returnID,
                 r.BillingNumber,
                 r.ReturnDate,
                 r.Quantity,
                 r.Reason,
                 r.ReturnPrice,
                 p.ProductName
              FROM tblreturns r
              LEFT JOIN tblproducts p ON p.ID = r.ProductID
              ORDER BY r.ID DESC
              LIMIT 50
            ";
            $returnsQuery = mysqli_query($con, $sqlReturns);
            $cnt = 1;
            while ($row = mysqli_fetch_assoc($returnsQuery)) {
              ?>
              <tr>
                <td><?php echo $cnt; ?></td>
                <td><?php echo $row['BillingNumber']; ?></td>
                <td><?php echo $row['ReturnDate']; ?></td>
                <td><?php echo $row['ProductName']; ?></td>
                <td><?php echo $row['Quantity']; ?></td>
                <td><?php echo number_format($row['ReturnPrice'],2); ?></td>
                <td><?php echo $row['Reason']; ?></td>
              </tr>
              <?php
              $cnt++;
            }
            ?>
            </tbody>
          </table>
          </div><!-- widget-content nopadding -->
        </div><!-- widget-box -->
      </div>
    </div><!-- row-fluid -->

  </div><!-- container-fluid -->
</div><!-- content -->

<?php include_once('includes/footer.php'); ?>

<!-- Scripts -->
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