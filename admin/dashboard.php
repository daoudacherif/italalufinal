<?php
session_start();
error_reporting(0);
include('includes/dbconnection.php');
if (strlen($_SESSION['imsaid'] == 0)) {
    header('location:logout.php');
    exit;
}
?>
<!DOCTYPE html>
<html lang="fr">
<head>
    <meta charset="UTF-8">
    <title>Système de Gestion d'Inventaire || Tableau de Bord</title>
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <?php include_once('includes/cs.php'); ?>
    <?php include_once('includes/responsive.php'); ?>
</head>
<body>
<div id="app-container">
    <?php include_once('includes/header.php'); ?>
    <?php include_once('includes/sidebar.php'); ?>

    <div id="content">
        <div id="content-header">
            <div id="breadcrumb">
                <a href="dashboard.php" title="Aller à l'accueil" class="tip-bottom">
                    <i class="icon-home"></i> Accueil
                </a>
            </div>
        </div>
        <br/>

        <div class="container-fluid">
            <!-- Quick stats -->
            <div class="widget-box widget-plain">
                <div class="center">
                    <ul class="quick-actions">
                        <?php
                        $catcount = mysqli_num_rows(mysqli_query($con, "SELECT * FROM tblcategory WHERE Status='1'"));
                        ?>
                        <li class="bg_ly">
                            <a href="manage-category.php">
                                <i class="icon-list fa-3x"></i>
                                <span class="label label-success"><?php echo $catcount; ?></span> Catégories
                            </a>
                        </li>

                        <?php
                        $productcount = mysqli_num_rows(mysqli_query($con, "SELECT * FROM tblproducts"));
                        ?>
                        <li class="bg_ls">
                            <a href="manage-product.php">
                                <i class="icon-list-alt"></i>
                                <span class="label label-success"><?php echo $productcount; ?></span> Articles
                            </a>
                        </li>

                        <?php
                        $totuser = mysqli_num_rows(mysqli_query($con, "SELECT * FROM tblcustomer"));
                        ?>
                        <li class="bg_lo span3">
                            <a href="profile.php">
                                <i class="icon-user"></i>
                                <span class="label label-success"><?php echo $totuser; ?></span> Utilisateurs
                            </a>
                        </li>
                    </ul>
                </div>
            </div>

            <!-- Sales widget -->
            <div class="widget-box widget-plain" style="margin-top:12%">
                <div class="center">
                    <h3 style="color:blue">Ventes (encaissements réels)</h3>
                    <hr/>
                    <ul class="site-stats">
                        <?php
                        // 1) Ventes d'aujourd'hui (somme des paid pour les factures émises aujourd'hui)
                        $sql_today = "SELECT SUM(paid) AS sum_today 
                                      FROM tblcustomer 
                                      WHERE DATE(BillingDate) = CURDATE()";
                        $row = mysqli_fetch_assoc(mysqli_query($con, $sql_today));
                        $sum_today = $row['sum_today'] ?? 0.0;

                        // 2) Ventes d'hier
                        $sql_yesterday = "SELECT SUM(paid) AS sum_yesterday 
                                          FROM tblcustomer 
                                          WHERE DATE(BillingDate) = CURDATE() - INTERVAL 1 DAY";
                        $row = mysqli_fetch_assoc(mysqli_query($con, $sql_yesterday));
                        $sum_yesterday = $row['sum_yesterday'] ?? 0.0;

                        // 3) Ventes des 7 derniers jours (y compris aujourd'hui)
                        $sql_7days = "SELECT SUM(paid) AS sum_7days
                                      FROM tblcustomer
                                      WHERE DATE(BillingDate) >= CURDATE() - INTERVAL 7 DAY";
                        $row = mysqli_fetch_assoc(mysqli_query($con, $sql_7days));
                        $sum_7days = $row['sum_7days'] ?? 0.0;

                        // 4) Ventes totales (tous les paiements)
                        $sql_total = "SELECT SUM(paid) AS sum_total FROM tblcustomer";
                        $row = mysqli_fetch_assoc(mysqli_query($con, $sql_total));
                        $sum_total = $row['sum_total'] ?? 0.0;
                        ?>

                        <li class="bg_lh">
                            <font style="font-size:22px; font-weight:bold">$</font>
                            <strong><?php echo number_format($sum_today, 2); ?></strong>
                            <small>Ventes d'aujourd'hui</small>
                        </li>

                        <li class="bg_lh">
                            <font style="font-size:22px; font-weight:bold">$</font>
                            <strong><?php echo number_format($sum_yesterday, 2); ?></strong>
                            <small>Ventes d'hier</small>
                        </li>

                        <li class="bg_lh">
                            <font style="font-size:22px; font-weight:bold">$</font>
                            <strong><?php echo number_format($sum_7days, 2); ?></strong>
                            <small>Ventes des 7 derniers jours</small>
                        </li>

                        <li class="bg_lh">
                            <font style="font-size:22px; font-weight:bold">$</font>
                            <strong><?php echo number_format($sum_total, 2); ?></strong>
                            <small>Ventes totales</small>
                        </li>
                    </ul>
                </div>
            </div>
        </div>

    </div>

    <?php include_once('includes/footer.php'); ?>
</div>

<?php include_once('includes/js.php'); ?>

<script>
    document.getElementById('my_menu_input') &&
    document.getElementById('my_menu_input').addEventListener('click', function () {
        var sidebar = document.getElementById('sidebar');
        sidebar.style.display = sidebar.style.display === "block" ? "none" : "block";
    });
</script>

</body>
</html>
