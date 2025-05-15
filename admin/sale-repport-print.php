<?php
session_start();
error_reporting(E_ALL);
include_once 'includes/dbconnection.php';

// Vérifier si l'admin est connecté
if (empty($_SESSION['imsaid'])) {
    header('Location: logout.php');
    exit;
}

// Récupérer les paramètres du rapport
$fdate = filter_input(INPUT_POST, 'fromdate', FILTER_SANITIZE_STRING);
$tdate = filter_input(INPUT_POST, 'todate', FILTER_SANITIZE_STRING);
$rtype = filter_input(INPUT_POST, 'requesttype', FILTER_SANITIZE_STRING);
?>
<!DOCTYPE html>
<html lang="fr">
<head>
  <meta charset="UTF-8">
  <meta name="viewport" content="width=device-width, initial-scale=1.0">
  <title>Rapport de ventes imprimable</title>
  <?php include_once('includes/cs.php'); ?>
  <?php include_once('includes/responsive.php'); ?>
  <style>
    /* Masquer les éléments non imprimables */
    @media print {
      .no-print { display: none !important; }
      #footer, #header, .sidebar, .dataTables_length, .dataTables_filter, .dataTables_info, .dataTables_paginate { display: none; }
    }
  </style>
</head>
<body>
<?php include_once('includes/header.php'); ?>
<?php include_once('includes/sidebar.php'); ?>

<div id="content">
  <div id="content-header">
    <div id="breadcrumb">
      <a href="dashboard.php" class="tip-bottom"><i class="icon-home"></i> Accueil</a>
      <a href="#" class="current">Détails du rapport de ventes</a>
    </div>
    <h1>Détails du rapport de ventes</h1>
    <button class="btn btn-success no-print" onclick="window.print()">Imprimer ce rapport</button>
  </div>

  <div class="container-fluid" id="printableArea">
    <hr>
    <div class="widget-box">
      <div class="widget-title"><span class="icon"><i class="icon-th"></i></span>
        <?php
        if ($rtype === 'mtwise') {
            $m1 = date('F', strtotime($fdate));
            $y1 = date('Y', strtotime($fdate));
            $m2 = date('F', strtotime($tdate));
            $y2 = date('Y', strtotime($tdate));
            echo "<h5 style='color:blue;'>Rapport de ventes de $m1-$y1 à $m2-$y2</h5>";
        } else {
            $y1 = date('Y', strtotime($fdate));
            $y2 = date('Y', strtotime($tdate));
            echo "<h5 style='color:blue;'>Rapport de ventes de l'année $y1 à l'année $y2</h5>";
        }
        ?>
      </div>
      <div class="widget-content nopadding">
        <table class="table table-bordered data-table">
          <thead>
            <tr>
              <th>N°</th>
              <?php if ($rtype === 'mtwise'): ?>
                <th>Mois / Année</th>
              <?php else: ?>
                <th>Année</th>
              <?php endif; ?>
              <th>Nom du produit</th>
              <th>Numéro de modèle</th>
              <th>Quantité vendue</th>
              <th>Prix unitaire</th>
              <th>Total</th>
            </tr>
          </thead>
          <tbody>
            <?php
            // Construire la requête selon le type
            if ($rtype === 'mtwise') {
                $sql = "SELECT MONTH(c.CartDate) AS lmonth, YEAR(c.CartDate) AS lyear, p.ProductName, p.ModelNumber, p.Price, SUM(c.ProductQty) AS selledqty
                        FROM tblproducts p
                        JOIN tblcart c ON p.ID = c.ProductId
                        WHERE DATE(c.CartDate) BETWEEN ? AND ?
                        GROUP BY lmonth, lyear, p.ProductName";
            } else {
                $sql = "SELECT YEAR(c.CartDate) AS lyear, p.ProductName, p.ModelNumber, p.Price, SUM(c.ProductQty) AS selledqty
                        FROM tblproducts p
                        JOIN tblcart c ON p.ID = c.ProductId
                        WHERE DATE(c.CartDate) BETWEEN ? AND ?
                        GROUP BY lyear, p.ProductName";
            }
            $stmt = $con->prepare($sql);
            $stmt->bind_param('ss', $fdate, $tdate);
            $stmt->execute();
            $res = $stmt->get_result();

            $cnt = 1;
            $gtotal = 0;
            while ($row = $res->fetch_assoc()) {
                $qty = (int)$row['selledqty'];
                $ppu = (float)$row['Price'];
                $total = $qty * $ppu;
                $gtotal += $total;
                echo '<tr>';
                echo "<td>{$cnt}</td>";
                echo $rtype === 'mtwise'
                     ? "<td>{$row['lmonth']}/{$row['lyear']}</td>"
                     : "<td>{$row['lyear']}</td>";
                echo "<td>" . htmlspecialchars($row['ProductName']) . "</td>";
                echo "<td>" . htmlspecialchars($row['ModelNumber']) . "</td>";
                echo "<td>{$qty}</td>";
                echo "<td>{$ppu}</td>";
                echo "<td>{$total}</td>";
                echo '</tr>';
                $cnt++;
            }
            echo "<tr><th colspan='6' style='text-align:center;color:red;'>Total général</th><th style='color:red;'>{$gtotal}</th></tr>";
            ?>
          </tbody>
        </table>
      </div>
    </div>
  </div>
</div>

<?php include_once('includes/footer.php'); ?>

<!-- Scripts -->
<script src="js/jquery.min.js"></script>
<script src="js/bootstrap.min.js"></script>
<script src="js/jquery.dataTables.min.js"></script>
<script src="js/matrix.tables.js"></script>
</body>
</html>
