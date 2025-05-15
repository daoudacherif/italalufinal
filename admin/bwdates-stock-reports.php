<?php
session_start();
error_reporting(E_ALL);
include_once 'includes/dbconnection.php';

// Vérifier si l'admin est connecté
if (empty($_SESSION['imsaid'])) {
    header('Location: logout.php');
    exit;
}

// Initialiser les variables
$fdate = filter_input(INPUT_POST, 'fromdate', FILTER_SANITIZE_STRING) ?: date('Y-m-d', strtotime('-30 days'));
$tdate = filter_input(INPUT_POST, 'todate', FILTER_SANITIZE_STRING) ?: date('Y-m-d');

// Récupérer les données
$products = [];
if ($fdate && $tdate) {
    $stmt = $con->prepare("
        SELECT 
            p.ID, 
            p.ProductName, 
            COALESCE(c.CategoryName, 'N/A') AS CategoryName, 
            p.BrandName, 
            p.ModelNumber, 
            p.Stock AS initial_stock, 
            COALESCE(SUM(cart.ProductQty), 0) AS sold_qty,
            COALESCE(
                (SELECT SUM(Quantity) FROM tblreturns WHERE ProductID = p.ID AND 
                DATE(ReturnDate) BETWEEN ? AND ?),
                0
            ) AS returned_qty,
            p.Status
        FROM tblproducts p
        LEFT JOIN tblcategory c ON c.ID = p.CatID
        LEFT JOIN tblcart cart ON cart.ProductId = p.ID AND cart.IsCheckOut = 1
        WHERE DATE(p.CreationDate) BETWEEN ? AND ?
        GROUP BY p.ID
        ORDER BY p.ID DESC
    ");
    
    $stmt->bind_param('ssss', $fdate, $tdate, $fdate, $tdate);
    $stmt->execute();
    $result = $stmt->get_result();
    
    if ($result->num_rows > 0) {
        while ($row = $result->fetch_assoc()) {
            $products[] = $row;
        }
    }
    $stmt->close();
}
?>
<!DOCTYPE html>
<html lang="fr">
<head>
    <meta charset="UTF-8" />
    <meta name="viewport" content="width=device-width, initial-scale=1.0" />
    <title>Système de Gestion des Inventaires | Rapport de Stock</title>
    <?php include_once 'includes/cs.php'; ?>
    <?php include_once 'includes/responsive.php'; ?>
    <style>
        /* Styles pour l'écran normal */
        .print-only {
            display: none !important;
        }
        
        /* Style pour l'impression - APPROCHE SIMPLIFIÉE */
        @media print {
            /* Cacher tout ce qui n'est pas pour impression */
            body > *:not(.print-only) {
                display: none !important;
            }
            
            /* Afficher uniquement ce qui est destiné à l'impression */
            .print-only {
                display: block !important;
                width: 100% !important;
                margin: 0 !important;
                padding: 0 !important;
            }
            
            /* Style de base pour le tableau d'impression */
            .print-table {
                width: 100% !important;
                border-collapse: collapse !important;
                font-size: 12px !important;
            }
            
            .print-table th,
            .print-table td {
                border: 1px solid #000 !important;
                padding: 5px !important;
                text-align: left !important;
            }
            
            .print-table th {
                background-color: #f0f0f0 !important;
                font-weight: bold !important;
            }
            
            /* Pour éviter les sauts de page au milieu des lignes */
            .print-table tr {
                page-break-inside: avoid !important;
            }
        }
    </style>
</head>
<body>
<!-- Partie visible uniquement à l'impression -->
<div class="print-only">
    <table class="print-table">
        <thead>
            <tr>
                <th>N°</th>
                <th>Nom du Produit</th>
                <th>Catégorie</th>
                <th>Marque</th>
                <th>Modèle</th>
                <th>Stock Initial</th>
                <th>Vendus</th>
                <th>Retournés</th>
                <th>Stock Restant</th>
                <th>Statut</th>
            </tr>
        </thead>
        <tbody>
        <?php
        if (!empty($products)) {
            $cnt = 1;
            $total_initial = 0;
            $total_sold = 0;
            $total_returned = 0;
            $total_remain = 0;
            
            foreach ($products as $row) {
                $initial = (int)$row['initial_stock'];
                $sold = (int)$row['sold_qty'];
                $returned = (int)$row['returned_qty'];
                $remain = $initial - $sold + $returned;
                $remain = max(0, $remain);
                
                // Cumuls pour les totaux
                $total_initial += $initial;
                $total_sold += $sold;
                $total_returned += $returned;
                $total_remain += $remain;
                ?>
                <tr>
                    <td><?= $cnt ?></td>
                    <td><?= htmlspecialchars($row['ProductName']) ?></td>
                    <td><?= htmlspecialchars($row['CategoryName']) ?></td>
                    <td><?= htmlspecialchars($row['BrandName']) ?></td>
                    <td><?= htmlspecialchars($row['ModelNumber']) ?></td>
                    <td><?= $initial ?></td>
                    <td><?= $sold ?></td>
                    <td><?= $returned ?></td>
                    <td><?= $remain === 0 ? 'Épuisé' : $remain ?></td>
                    <td><?= $row['Status'] == '1' ? 'Actif' : 'Inactif' ?></td>
                </tr>
                <?php
                $cnt++;
            }
            // Ajouter une ligne de total
            ?>
            <tr>
                <td colspan="5"><strong>TOTAUX</strong></td>
                <td><strong><?= $total_initial ?></strong></td>
                <td><strong><?= $total_sold ?></strong></td>
                <td><strong><?= $total_returned ?></strong></td>
                <td><strong><?= $total_remain ?></strong></td>
                <td>-</td>
            </tr>
            <?php
        } else {
            echo '<tr><td colspan="10" style="text-align:center">Aucun enregistrement trouvé pour cette période.</td></tr>';
        }
        ?>
        </tbody>
    </table>
</div>

<!-- La partie visible normalement à l'écran -->
<div class="screen-only">
    <!-- Éléments d'interface normale -->
    <?php include_once 'includes/header.php'; ?>
    <?php include_once 'includes/sidebar.php'; ?>

    <div id="content">
        <div id="content-header">
            <div id="breadcrumb">
                <a href="dashboard.php" title="Accueil" class="tip-bottom"><i class="icon-home"></i> Accueil</a>
                <a href="stock-report.php" class="current">Rapport de Stock</a>
            </div>
            <h1>Rapport de Stock</h1>
        </div>
        
        <div class="container-fluid">
            <hr />
            
            <!-- Formulaire de sélection des dates -->
            <div class="row-fluid">
                <div class="span12">
                    <div class="widget-box">
                        <div class="widget-title">
                            <span class="icon"><i class="icon-calendar"></i></span>
                            <h5>Sélectionner la période du rapport</h5>
                        </div>
                        <div class="widget-content nopadding">
                            <form method="post" class="form-horizontal" action="<?php echo htmlspecialchars($_SERVER['PHP_SELF']); ?>">
                                <div class="control-group">
                                    <label class="control-label">De Date :</label>
                                    <div class="controls">
                                        <input type="date" class="span11" name="fromdate" id="fromdate" value="<?php echo $fdate; ?>" required='true' />
                                    </div>
                                </div>
                                <div class="control-group">
                                    <label class="control-label">À Date :</label>
                                    <div class="controls">
                                        <input type="date" class="span11" name="todate" id="todate" value="<?php echo $tdate; ?>" required='true' />
                                    </div>
                                </div>
                                <div class="form-actions">
                                    <button type="submit" class="btn btn-success" name="submit"><i class="icon-search"></i> Générer le Rapport</button>
                                </div>
                            </form>
                        </div>
                    </div>
                </div>
            </div>

            <?php if ($fdate && $tdate): ?>
                <!-- Tableau des résultats pour l'affichage à l'écran -->
                <div class="row-fluid">
                    <div class="span12">
                        <div class="widget-box">
                            <div class="widget-title">
                                <span class="icon"><i class="icon-th"></i></span>
                                <h5>
                                    Rapport d'inventaire du <?= htmlspecialchars($fdate) ?> au <?= htmlspecialchars($tdate) ?>
                                </h5>
                                <div class="buttons">
                                    <button onclick="window.print()" class="btn btn-primary btn-mini"><i class="icon-print"></i> Imprimer Tableau</button>
                                    <a href="export-stock.php?from=<?= urlencode($fdate) ?>&to=<?= urlencode($tdate) ?>" class="btn btn-info btn-mini"><i class="icon-download"></i> Exporter</a>
                                </div>
                            </div>
                            
                            <div class="widget-content">
                                <table class="table table-bordered data-table">
                                    <thead>
                                        <tr>
                                            <th>N°</th>
                                            <th>Nom du Produit</th>
                                            <th>Catégorie</th>
                                            <th>Marque</th>
                                            <th>Modèle</th>
                                            <th>Stock Initial</th>
                                            <th>Vendus</th>
                                            <th>Retournés</th>
                                            <th>Stock Restant</th>
                                            <th>Statut</th>
                                        </tr>
                                    </thead>
                                    <tbody>
                                    <?php
                                    if (!empty($products)) {
                                        $cnt = 1;
                                        foreach ($products as $row) {
                                            $initial = (int)$row['initial_stock'];
                                            $sold = (int)$row['sold_qty'];
                                            $returned = (int)$row['returned_qty'];
                                            $remain = $initial - $sold + $returned;
                                            $remain = max(0, $remain);
                                            ?>
                                            <tr>
                                                <td><?= $cnt ?></td>
                                                <td><?= htmlspecialchars($row['ProductName']) ?></td>
                                                <td><?= htmlspecialchars($row['CategoryName']) ?></td>
                                                <td><?= htmlspecialchars($row['BrandName']) ?></td>
                                                <td><?= htmlspecialchars($row['ModelNumber']) ?></td>
                                                <td><?= $initial ?></td>
                                                <td><?= $sold ?></td>
                                                <td><?= $returned ?></td>
                                                <td><?= $remain === 0 ? 'Épuisé' : $remain ?></td>
                                                <td><?= $row['Status'] == '1' ? 'Actif' : 'Inactif' ?></td>
                                            </tr>
                                            <?php
                                            $cnt++;
                                        }
                                    } else {
                                        echo '<tr><td colspan="10" class="text-center">Aucun enregistrement trouvé pour cette période.</td></tr>';
                                    }
                                    ?>
                                    </tbody>
                                </table>
                            </div>
                        </div>
                    </div>
                </div>
            <?php else: ?>
                <div class="row-fluid">
                    <div class="span12">
                        <div class="alert alert-info">
                            <button class="close" data-dismiss="alert">×</button>
                            <strong>Info!</strong> Veuillez sélectionner les dates de début et de fin pour générer le rapport.
                        </div>
                        
                        <!-- Aperçu des produits récents -->
                        <div class="widget-box">
                            <div class="widget-title">
                                <span class="icon"><i class="icon-th"></i></span>
                                <h5>Aperçu des Produits Récents</h5>
                            </div>
                            <div class="widget-content">
                                <table class="table table-bordered data-table">
                                    <thead>
                                        <tr>
                                            <th>N°</th>
                                            <th>Nom du Produit</th>
                                            <th>Catégorie</th>
                                            <th>Marque</th>
                                            <th>Modèle</th>
                                            <th>Stock Initial</th>
                                            <th>Vendus</th>
                                            <th>Retournés</th>
                                            <th>Stock Restant</th>
                                            <th>Statut</th>
                                        </tr>
                                    </thead>
                                    <tbody>
                                        <?php
                                        $sql = "
                                            SELECT 
                                                p.ID AS pid,
                                                p.ProductName,
                                                COALESCE(c.CategoryName, 'N/A') AS CategoryName,
                                                p.BrandName,
                                                p.ModelNumber,
                                                p.Stock AS initial_stock,
                                                COALESCE(SUM(cart.ProductQty), 0) AS sold_qty,
                                                COALESCE(
                                                    (SELECT SUM(Quantity) FROM tblreturns WHERE ProductID = p.ID),
                                                    0
                                                ) AS returned_qty,
                                                p.Status
                                            FROM tblproducts p
                                            LEFT JOIN tblcategory c ON c.ID = p.CatID
                                            LEFT JOIN tblcart cart ON cart.ProductId = p.ID AND cart.IsCheckOut = 1
                                            GROUP BY p.ID
                                            ORDER BY p.CreationDate DESC 
                                            LIMIT 10
                                        ";
                                        $ret = mysqli_query($con, $sql) or die('Erreur SQL : ' . mysqli_error($con));
                                        
                                        if (mysqli_num_rows($ret) > 0) {
                                            $cnt = 1;
                                            while ($row = mysqli_fetch_assoc($ret)) {
                                                $initial = (int)$row['initial_stock'];
                                                $sold = (int)$row['sold_qty'];
                                                $returned = (int)$row['returned_qty'];
                                                $remain = $initial - $sold + $returned;
                                                $remain = max(0, $remain);
                                                ?>
                                                <tr>
                                                    <td><?= $cnt ?></td>
                                                    <td><?= htmlspecialchars($row['ProductName']) ?></td>
                                                    <td><?= htmlspecialchars($row['CategoryName']) ?></td>
                                                    <td><?= htmlspecialchars($row['BrandName']) ?></td>
                                                    <td><?= htmlspecialchars($row['ModelNumber']) ?></td>
                                                    <td><?= $initial ?></td>
                                                    <td><?= $sold ?></td>
                                                    <td><?= $returned ?></td>
                                                    <td><?= $remain === 0 ? 'Épuisé' : $remain ?></td>
                                                    <td><?= $row['Status'] == '1' ? 'Actif' : 'Inactif' ?></td>
                                                </tr>
                                                <?php
                                                $cnt++;
                                            }
                                        } else {
                                            echo '<tr><td colspan="10" class="text-center">Aucun Article trouvé</td></tr>';
                                        }
                                        ?>
                                    </tbody>
                                </table>
                            </div>
                        </div>
                    </div>
                </div>
            <?php endif; ?>
        </div>
    </div>

    <?php include_once 'includes/footer.php'; ?>
</div>

<!-- Scripts -->
<script src="js/jquery.min.js"></script>
<script src="js/jquery.ui.custom.js"></script>
<script src="js/bootstrap.min.js"></script>
<script src="js/jquery.uniform.js"></script>
<script src="js/select2.min.js"></script>
<script src="js/jquery.dataTables.min.js"></script>
<script src="js/matrix.js"></script>
<script src="js/matrix.tables.js"></script>
<script>
    // Validation JS: assure fromdate <= todate
    document.addEventListener('DOMContentLoaded', function() {
        const form = document.querySelector('form');
        form && form.addEventListener('submit', function(e) {
            const from = new Date(document.getElementById('fromdate').value);
            const to = new Date(document.getElementById('todate').value);
            if (from > to) {
                alert('La date de début ne peut pas être après la date de fin.');
                e.preventDefault();
            }
        });
    });
</script>

</body>
</html>