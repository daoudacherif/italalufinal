<?php
session_start();
// Activer le rapport d'erreurs pour le débogage
error_reporting(E_ALL);
ini_set('display_errors', 1);
include('includes/dbconnection.php');

// Vérifier la connexion
if (!$con) {
    die("Erreur de connexion à la base de données: " . mysqli_connect_error());
}

// Vérification de session
if (strlen($_SESSION['imsaid']) == 0) {
    header('location:logout.php');
    exit;
}

// 1) Mise à jour de la marge cible
if (isset($_POST['update_target_margin'])) {
    $productId = intval($_POST['product_id']);
    $targetMargin = floatval($_POST['target_margin']);
    
    $sql = "UPDATE tblproducts SET TargetMargin = $targetMargin WHERE ID = $productId";
    $result = mysqli_query($con, $sql);
    
    if ($result) {
        echo "<script>alert('Marge cible mise à jour avec succès!');</script>";
    } else {
        echo "<script>alert('Erreur lors de la mise à jour: " . mysqli_error($con) . "');</script>";
    }
}

// 2) Mise à jour du prix de vente basé sur la marge cible
if (isset($_POST['update_sale_price'])) {
    $productId = intval($_POST['product_id']);
    $newPrice = floatval($_POST['new_price']);
    
    $sql = "UPDATE tblproducts SET Price = $newPrice WHERE ID = $productId";
    $result = mysqli_query($con, $sql);
    
    if ($result) {
        echo "<script>alert('Prix de vente mis à jour avec succès!');</script>";
    } else {
        echo "<script>alert('Erreur lors de la mise à jour: " . mysqli_error($con) . "');</script>";
    }
}

// 3) Mise à jour manuelle du prix d'achat
if (isset($_POST['update_cost_price'])) {
    $productId = intval($_POST['product_id']);
    $costPrice = floatval($_POST['cost_price']);
    
    $sql = "UPDATE tblproducts SET CostPrice = $costPrice, LastCostUpdate = NOW() WHERE ID = $productId";
    $result = mysqli_query($con, $sql);
    
    if ($result) {
        echo "<script>alert('Prix d\\'achat mis à jour avec succès!');</script>";
    } else {
        echo "<script>alert('Erreur lors de la mise à jour: " . mysqli_error($con) . "');</script>";
    }
}

// 4) Recalcul automatique des prix d'achat
if (isset($_POST['recalculate_costs'])) {
    $sql = "
        UPDATE tblproducts p SET 
            CostPrice = (
                SELECT AVG(a.Cost / a.Quantity) 
                FROM tblproductarrivals a 
                WHERE a.ProductID = p.ID 
                AND a.Quantity > 0
                AND a.Cost > 0
                GROUP BY a.ProductID
            ),
            LastCostUpdate = (
                SELECT MAX(a.ArrivalDate) 
                FROM tblproductarrivals a 
                WHERE a.ProductID = p.ID
                GROUP BY a.ProductID
            )
        WHERE p.ID IN (
            SELECT DISTINCT ProductID 
            FROM tblproductarrivals 
            WHERE Quantity > 0 AND Cost > 0
        )
    ";
    
    $result = mysqli_query($con, $sql);
    
    if ($result) {
        $affectedRows = mysqli_affected_rows($con);
        echo "<script>alert('$affectedRows produits mis à jour avec succès!');</script>";
    } else {
        echo "<script>alert('Erreur lors du recalcul: " . mysqli_error($con) . "');</script>";
    }
}

// Filtres
$filterCategory = isset($_GET['category']) ? intval($_GET['category']) : 0;
$filterMarginStatus = isset($_GET['margin_status']) ? $_GET['margin_status'] : '';
$searchTerm = isset($_GET['search']) ? mysqli_real_escape_string($con, $_GET['search']) : '';

// Construction de la requête avec filtres
$whereConditions = array();
if ($filterCategory > 0) {
    $whereConditions[] = "p.CatID = $filterCategory";
}
if ($filterMarginStatus) {
    switch ($filterMarginStatus) {
        case 'missing_cost':
            $whereConditions[] = "p.CostPrice = 0 OR p.CostPrice IS NULL";
            break;
        case 'no_target':
            $whereConditions[] = "p.TargetMargin = 0 OR p.TargetMargin IS NULL";
            break;
        case 'above_target':
            $whereConditions[] = "p.TargetMargin > 0 AND ((p.Price - p.CostPrice) / p.Price) * 100 >= p.TargetMargin";
            break;
        case 'below_target':
            $whereConditions[] = "p.TargetMargin > 0 AND ((p.Price - p.CostPrice) / p.Price) * 100 < p.TargetMargin";
            break;
    }
}
if ($searchTerm) {
    $whereConditions[] = "p.ProductName LIKE '%$searchTerm%'";
}

$whereClause = '';
if (!empty($whereConditions)) {
    $whereClause = 'WHERE ' . implode(' AND ', $whereConditions);
}

// Requête principale pour les marges
$sqlMargins = "
    SELECT 
        p.ID,
        p.ProductName,
        p.Price as SalePrice,
        p.CostPrice,
        p.TargetMargin,
        p.Stock,
        p.LastCostUpdate,
        c.CategoryName,
        p.BrandName,
        
        -- Calculs de marges
        CASE 
            WHEN p.CostPrice > 0 THEN 
                ROUND(((p.Price - p.CostPrice) / p.Price) * 100, 2)
            ELSE 0 
        END as ActualMarginPercent,
        
        CASE 
            WHEN p.CostPrice > 0 THEN 
                ROUND(p.Price - p.CostPrice, 2)
            ELSE 0 
        END as ProfitPerUnit,
        
        CASE 
            WHEN p.CostPrice > 0 THEN 
                ROUND((p.Price - p.CostPrice) * p.Stock, 2)
            ELSE 0 
        END as TotalProfitStock,
        
        CASE 
            WHEN p.CostPrice > 0 THEN 
                ROUND(((p.Price - p.CostPrice) / p.CostPrice) * 100, 2)
            ELSE 0 
        END as MarkupPercent,
        
        -- Prix de vente recommandé selon marge cible
        CASE 
            WHEN p.TargetMargin > 0 AND p.CostPrice > 0 THEN 
                ROUND(p.CostPrice / (1 - (p.TargetMargin / 100)), 2)
            ELSE p.Price 
        END as RecommendedSalePrice
        
    FROM tblproducts p
    LEFT JOIN tblcategory c ON c.ID = p.CatID
    $whereClause
    ORDER BY p.ProductName ASC
";

$resMargins = mysqli_query($con, $sqlMargins);

// Statistiques globales
$sqlStats = "
    SELECT 
        COUNT(*) as TotalProducts,
        SUM(CASE WHEN p.CostPrice > 0 THEN 1 ELSE 0 END) as ProductsWithCost,
        SUM(CASE WHEN p.TargetMargin > 0 THEN 1 ELSE 0 END) as ProductsWithTarget,
        AVG(CASE WHEN p.CostPrice > 0 THEN ((p.Price - p.CostPrice) / p.Price) * 100 ELSE 0 END) as AvgMargin,
        SUM(CASE WHEN p.CostPrice > 0 THEN (p.Price - p.CostPrice) * p.Stock ELSE 0 END) as TotalPotentialProfit
    FROM tblproducts p
    WHERE p.Status = 1
";
$resStats = mysqli_query($con, $sqlStats);
$stats = mysqli_fetch_assoc($resStats);

// Categories pour le filtre
$sqlCategories = "SELECT ID, CategoryName FROM tblcategory ORDER BY CategoryName ASC";
$resCategories = mysqli_query($con, $sqlCategories);
?>

<!DOCTYPE html>
<html lang="fr">
<head>
    <title>Gestion de Stock | Marges de Bénéfice</title>
    <?php include_once('includes/cs.php'); ?>
    <?php include_once('includes/responsive.php'); ?>
    <style>
        .margin-good { background-color: #d4edda !important; color: #155724; }
        .margin-warning { background-color: #fff3cd !important; color: #856404; }
        .margin-danger { background-color: #f8d7da !important; color: #721c24; }
        .margin-info { background-color: #d1ecf1 !important; color: #0c5460; }
        
        .stats-card {
            background: linear-gradient(135deg, #667eea 0%, #764ba2 100%);
            color: white;
            padding: 20px;
            border-radius: 10px;
            margin: 10px 0;
        }
        
        .stats-number {
            font-size: 2.5em;
            font-weight: bold;
            margin: 0;
        }
        
        .stats-label {
            font-size: 0.9em;
            opacity: 0.9;
        }
        
        .quick-edit {
            width: 80px;
            padding: 2px 5px;
            font-size: 11px;
        }
        
        .profit-positive { color: #28a745; font-weight: bold; }
        .profit-negative { color: #dc3545; font-weight: bold; }
        
        .action-btn {
            padding: 2px 8px;
            margin: 1px;
            font-size: 10px;
        }
        
        .margin-badge {
            padding: 3px 6px;
            border-radius: 12px;
            font-size: 10px;
            font-weight: bold;
        }
        
        .filter-section {
            background-color: #f8f9fa;
            padding: 15px;
            border-radius: 5px;
            margin: 15px 0;
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
            <a href="margins.php" class="current">Marges de Bénéfice</a>
        </div>
        <h1>💰 Gestion des Marges de Bénéfice</h1>
    </div>

    <div class="container-fluid">
        
        <!-- STATISTIQUES GLOBALES -->
        <div class="row-fluid">
            <div class="span3">
                <div class="stats-card">
                    <div class="stats-number"><?php echo $stats['TotalProducts']; ?></div>
                    <div class="stats-label">Produits Total</div>
                </div>
            </div>
            <div class="span3">
                <div class="stats-card" style="background: linear-gradient(135deg, #f093fb 0%, #f5576c 100%);">
                    <div class="stats-number"><?php echo number_format($stats['AvgMargin'], 1); ?>%</div>
                    <div class="stats-label">Marge Moyenne</div>
                </div>
            </div>
            <div class="span3">
                <div class="stats-card" style="background: linear-gradient(135deg, #4facfe 0%, #00f2fe 100%);">
                    <div class="stats-number"><?php echo $stats['ProductsWithCost']; ?></div>
                    <div class="stats-label">Avec Prix d'Achat</div>
                </div>
            </div>
            <div class="span3">
                <div class="stats-card" style="background: linear-gradient(135deg, #43e97b 0%, #38f9d7 100%);">
                    <div class="stats-number"><?php echo number_format($stats['TotalPotentialProfit'], 0); ?></div>
                    <div class="stats-label">Profit Potentiel Stock</div>
                </div>
            </div>
        </div>

        <!-- ACTIONS RAPIDES -->
        <div class="row-fluid">
            <div class="span12">
                <div class="alert alert-info">
                    <strong>🔧 Actions Rapides:</strong>
                    <form method="post" style="display: inline; margin-left: 10px;">
                        <button type="submit" name="recalculate_costs" class="btn btn-info btn-small"
                                onclick="return confirm('Recalculer tous les prix d\\'achat à partir des arrivages?')">
                            <i class="icon-refresh"></i> Recalculer Prix d'Achat
                        </button>
                    </form>
                    <a href="arrival.php" class="btn btn-success btn-small" style="margin-left: 5px;">
                        <i class="icon-plus"></i> Ajouter Arrivage
                    </a>
                </div>
            </div>
        </div>

        <!-- FILTRES -->
        <div class="filter-section">
            <form method="get" action="margins.php" class="form-inline">
                <label>🔍 Filtres:</label>
                
                <select name="category" class="span2">
                    <option value="">Toutes catégories</option>
                    <?php
                    while ($cat = mysqli_fetch_assoc($resCategories)) {
                        $selected = ($filterCategory == $cat['ID']) ? 'selected' : '';
                        echo '<option value="'.$cat['ID'].'" '.$selected.'>'.$cat['CategoryName'].'</option>';
                    }
                    ?>
                </select>
                
                <select name="margin_status" class="span2">
                    <option value="">Tous statuts</option>
                    <option value="missing_cost" <?php echo ($filterMarginStatus == 'missing_cost') ? 'selected' : ''; ?>>Sans prix d'achat</option>
                    <option value="no_target" <?php echo ($filterMarginStatus == 'no_target') ? 'selected' : ''; ?>>Sans marge cible</option>
                    <option value="above_target" <?php echo ($filterMarginStatus == 'above_target') ? 'selected' : ''; ?>>Au-dessus de l'objectif</option>
                    <option value="below_target" <?php echo ($filterMarginStatus == 'below_target') ? 'selected' : ''; ?>>Sous l'objectif</option>
                </select>
                
                <input type="text" name="search" value="<?php echo htmlspecialchars($searchTerm); ?>" 
                       placeholder="Rechercher produit..." class="span2" />
                
                <button type="submit" class="btn btn-primary">Filtrer</button>
                <a href="margins.php" class="btn btn-secondary">Reset</a>
            </form>
        </div>

        <!-- TABLEAU DES MARGES -->
        <div class="row-fluid">
            <div class="span12">
                <div class="widget-box">
                    <div class="widget-title">
                        <span class="icon"><i class="icon-calculator"></i></span>
                        <h5>📊 Analyse des Marges par Produit</h5>
                    </div>
                    <div class="widget-content nopadding">
                        <table class="table table-bordered table-striped" id="marginsTable">
                            <thead>
                                <tr>
                                    <th>Produit</th>
                                    <th>Catégorie</th>
                                    <th>Prix Achat</th>
                                    <th>Prix Vente</th>
                                    <th>Marge Réelle</th>
                                    <th>Marge Cible</th>
                                    <th>Profit/Unité</th>
                                    <th>Stock</th>
                                    <th>Profit Total</th>
                                    <th>Prix Recommandé</th>
                                    <th>Actions</th>
                                </tr>
                            </thead>
                            <tbody>
                                <?php
                                while ($row = mysqli_fetch_assoc($resMargins)) {
                                    // Déterminer la classe CSS selon la marge
                                    $rowClass = '';
                                    $marginStatus = '';
                                    
                                    if ($row['CostPrice'] == 0) {
                                        $rowClass = 'margin-info';
                                        $marginStatus = 'Prix d\'achat manquant';
                                    } elseif ($row['TargetMargin'] == 0) {
                                        $rowClass = 'margin-warning';
                                        $marginStatus = 'Marge cible non définie';
                                    } elseif ($row['ActualMarginPercent'] >= $row['TargetMargin']) {
                                        $rowClass = 'margin-good';
                                        $marginStatus = 'Objectif atteint';
                                    } else {
                                        $rowClass = 'margin-danger';
                                        $marginStatus = 'Sous l\'objectif';
                                    }
                                ?>
                                <tr class="<?php echo $rowClass; ?>">
                                    <td>
                                        <strong><?php echo $row['ProductName']; ?></strong>
                                        <?php if ($row['BrandName']) echo '<br><small>'.$row['BrandName'].'</small>'; ?>
                                    </td>
                                    <td><?php echo $row['CategoryName']; ?></td>
                                    <td>
                                        <form method="post" style="margin: 0;">
                                            <input type="hidden" name="product_id" value="<?php echo $row['ID']; ?>" />
                                            <input type="number" name="cost_price" value="<?php echo $row['CostPrice']; ?>" 
                                                   step="0.01" class="quick-edit" />
                                            <button type="submit" name="update_cost_price" class="action-btn btn btn-mini btn-info">💾</button>
                                        </form>
                                        <?php if ($row['LastCostUpdate']) { ?>
                                            <small style="color: #666;">MAJ: <?php echo $row['LastCostUpdate']; ?></small>
                                        <?php } ?>
                                    </td>
                                    <td>
                                        <strong><?php echo number_format($row['SalePrice'], 2); ?></strong>
                                    </td>
                                    <td>
                                        <span class="margin-badge <?php echo ($row['ActualMarginPercent'] > 0) ? 'margin-good' : 'margin-danger'; ?>">
                                            <?php echo $row['ActualMarginPercent']; ?>%
                                        </span>
                                    </td>
                                    <td>
                                        <form method="post" style="margin: 0;">
                                            <input type="hidden" name="product_id" value="<?php echo $row['ID']; ?>" />
                                            <input type="number" name="target_margin" value="<?php echo $row['TargetMargin']; ?>" 
                                                   step="0.1" class="quick-edit" placeholder="%" />
                                            <button type="submit" name="update_target_margin" class="action-btn btn btn-mini btn-warning">🎯</button>
                                        </form>
                                    </td>
                                    <td class="<?php echo ($row['ProfitPerUnit'] > 0) ? 'profit-positive' : 'profit-negative'; ?>">
                                        <?php echo number_format($row['ProfitPerUnit'], 2); ?>
                                    </td>
                                    <td><?php echo $row['Stock']; ?></td>
                                    <td class="<?php echo ($row['TotalProfitStock'] > 0) ? 'profit-positive' : 'profit-negative'; ?>">
                                        <?php echo number_format($row['TotalProfitStock'], 2); ?>
                                    </td>
                                    <td>
                                        <?php if ($row['RecommendedSalePrice'] != $row['SalePrice'] && $row['CostPrice'] > 0) { ?>
                                            <form method="post" style="margin: 0;">
                                                <input type="hidden" name="product_id" value="<?php echo $row['ID']; ?>" />
                                                <input type="number" name="new_price" value="<?php echo $row['RecommendedSalePrice']; ?>" 
                                                       step="0.01" class="quick-edit" />
                                                <button type="submit" name="update_sale_price" class="action-btn btn btn-mini btn-success"
                                                        onclick="return confirm('Appliquer le prix recommandé?')">✅</button>
                                            </form>
                                        <?php } else { ?>
                                            <span style="color: #28a745;">✓ Optimal</span>
                                        <?php } ?>
                                    </td>
                                    <td>
                                        <span class="margin-badge margin-info" style="font-size: 9px;">
                                            <?php echo $marginStatus; ?>
                                        </span>
                                    </td>
                                </tr>
                                <?php } ?>
                            </tbody>
                        </table>
                    </div>
                </div>
            </div>
        </div>

        <!-- LÉGENDE -->
        <div class="row-fluid">
            <div class="span12">
                <div class="alert alert-info">
                    <strong>📘 Légende:</strong>
                    <span class="margin-badge margin-good">Objectif atteint</span>
                    <span class="margin-badge margin-danger">Sous l'objectif</span>
                    <span class="margin-badge margin-warning">Marge cible non définie</span>
                    <span class="margin-badge margin-info">Prix d'achat manquant</span>
                    <br><br>
                    <strong>💡 Formules:</strong>
                    Marge = (Prix Vente - Prix Achat) / Prix Vente × 100 | 
                    Markup = (Prix Vente - Prix Achat) / Prix Achat × 100 | 
                    Prix Recommandé = Prix Achat / (1 - Marge Cible/100)
                </div>
            </div>
        </div>
    </div>
</div>

<?php include_once('includes/footer.php'); ?>

<script src="js/jquery.min.js"></script>
<script src="js/jquery.ui.custom.js"></script>
<script src="js/bootstrap.min.js"></script>
<script src="js/jquery.uniform.js"></script>
<script src="js/select2.min.js"></script>
<script src="js/jquery.dataTables.min.js"></script>
<script src="js/matrix.js"></script>
<script src="js/matrix.tables.js"></script>
<script>
$(document).ready(function() {
    $('#marginsTable').dataTable({
        "pageLength": 25,
        "order": [[ 4, "desc" ]], // Trier par marge réelle
        "columnDefs": [
            { "orderable": false, "targets": [10] } // Actions non triables
        ],
        "language": {
            "url": "//cdn.datatables.net/plug-ins/1.10.24/i18n/French.json"
        }
    });
    
    // Alerte pour marges négatives
    $('.profit-negative').each(function() {
        $(this).closest('tr').css('border-left', '3px solid #dc3545');
    });
});
</script>
</body>
</html>