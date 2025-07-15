<?php
session_start();
ini_set('display_errors', 1);
ini_set('display_startup_errors', 1);
error_reporting(E_ALL);

include('includes/dbconnection.php');

// Check if user is logged in
if (!isset($_SESSION['imsaid']) || empty($_SESSION['imsaid'])) {
    header("Location: login.php");
    exit;
}

$currentAdminID = $_SESSION['imsaid'];

// Get admin name
$adminQuery = mysqli_query($con, "SELECT AdminName FROM tbladmin WHERE ID = '$currentAdminID'");
$adminData = mysqli_fetch_assoc($adminQuery);
$currentAdminName = $adminData['AdminName'];

// Traitement de l'ajout d'une vente rapide (VERSION SIMPLIFIÉE)
if (isset($_POST['addQuickSale'])) {
    $productId = intval($_POST['productid']);
    $quantity = max(1, intval($_POST['quantity']));
    $price = max(0, floatval($_POST['price']));
    
    // Vérifier le stock disponible
    $stockRes = mysqli_query($con, "
        SELECT Stock, ProductName 
        FROM tblproducts 
        WHERE ID = '$productId' 
        LIMIT 1
    ");
    
    if (!$stockRes || mysqli_num_rows($stockRes) === 0) {
        echo "<script>alert('Produit introuvable.'); window.location.href='quick_sales.php';</script>";
        exit;
    }
    
    $row = mysqli_fetch_assoc($stockRes);
    $currentStock = intval($row['Stock']);
    $productName = $row['ProductName'];
    
    if ($currentStock <= 0) {
        echo "<script>
                alert('Article \"" . addslashes($productName) . "\" en rupture de stock.');
                window.location.href='quick_sales.php';
              </script>";
        exit;
    }
    
    if ($currentStock < $quantity) {
        echo "<script>
                alert('Stock insuffisant pour \"" . addslashes($productName) . "\". Stock disponible: " . $currentStock . "');
                window.location.href='quick_sales.php';
              </script>";
        exit;
    }
    
    // AJOUT SIMPLE : Toujours ajouter une nouvelle ligne (même pour le même produit)
    $insertQuery = mysqli_query($con, "
        INSERT INTO tblcart(
            ProductId, 
            ProductQty, 
            Price, 
            IsCheckOut, 
            AdminID
        ) VALUES(
            '$productId', 
            '$quantity', 
            '$price', 
            '0', 
            '$currentAdminID'
        )
    ");
    
    if ($insertQuery) {
        echo "<script>
                alert('Vente rapide ajoutée : " . addslashes($productName) . " (Prix: " . $price . " GNF)');
                window.location.href='quick_sales.php';
              </script>";
    } else {
        echo "<script>alert('Erreur lors de l\\'ajout.'); window.location.href='quick_sales.php';</script>";
    }
    exit;
}

// Suppression d'une vente rapide
if (isset($_GET['delid'])) {
    $delid = intval($_GET['delid']);
    $deleteQuery = mysqli_query($con, "
        DELETE FROM tblcart 
        WHERE ID = $delid AND IsCheckOut = 0 AND AdminID = '$currentAdminID'
    ");
    if ($deleteQuery) {
        echo "<script>
                alert('Vente rapide supprimée');
                window.location.href='quick_sales.php';
              </script>";
    }
    exit;
}

// Finaliser toutes les ventes rapides (rediriger vers cart.php)
if (isset($_POST['finalizeQuickSales'])) {
    header("Location: cart.php");
    exit;
}

// Vider toutes les ventes rapides
if (isset($_POST['clearQuickSales'])) {
    mysqli_query($con, "
        DELETE FROM tblcart 
        WHERE IsCheckOut = 0 AND AdminID = '$currentAdminID'
    ");
    echo "<script>
            alert('Toutes les ventes rapides ont été supprimées');
            window.location.href='quick_sales.php';
          </script>";
    exit;
}

// Récupérer les noms de produits pour le datalist
$productNamesQuery = mysqli_query($con, "SELECT DISTINCT ProductName FROM tblproducts ORDER BY ProductName ASC");
$productNames = array();
if ($productNamesQuery) {
    while ($row = mysqli_fetch_assoc($productNamesQuery)) {
        $productNames[] = $row['ProductName'];
    }
}
?>

<!DOCTYPE html>
<html lang="fr">
<head>
    <title>Système de gestion des stocks | Ventes Rapides</title>
    <?php include_once('includes/cs.php'); ?>
    <?php include_once('includes/responsive.php'); ?>
    <style>
        /* Styles existants */
        .quick-sale-form {
            background: linear-gradient(135deg, #f8f9fa 0%, #e9ecef 100%);
            border: 2px solid #27a9e3;
            border-radius: 12px;
            padding: 25px;
            margin-bottom: 20px;
            box-shadow: 0 4px 12px rgba(39, 169, 227, 0.1);
        }
        
        .user-cart-indicator {
            background: linear-gradient(135deg, #667eea 0%, #764ba2 100%);
            color: white;
            padding: 15px 20px;
            border-radius: 8px;
            margin-bottom: 20px;
            box-shadow: 0 2px 10px rgba(102, 126, 234, 0.2);
        }
        
        .user-cart-indicator i {
            margin-right: 8px;
            font-size: 16px;
        }
        
        /* Nouveaux styles pour la recherche */
        .search-section {
            background: #f8f9fa;
            border: 1px solid #dee2e6;
            border-radius: 8px;
            padding: 20px;
            margin-bottom: 25px;
        }
        
        .search-section h4 {
            color: #27a9e3;
            margin-bottom: 15px;
            font-size: 18px;
        }
        
        /* Styles pour les indicateurs de stock */
        .stock-warning {
            color: #d9534f;
            font-weight: bold;
            margin-left: 5px;
        }
        
        tr.stock-error {
            background-color: #f2dede !important;
        }
        
        .stock-status {
            padding: 2px 5px;
            border-radius: 3px;
            font-size: 12px;
            font-weight: bold;
        }
        
        .stock-ok {
            background-color: #dff0d8;
            color: #3c763d;
        }
        
        .stock-warning {
            background-color: #fcf8e3;
            color: #8a6d3b;
        }
        
        .stock-danger {
            background-color: #f2dede;
            color: #a94442;
        }
        
        /* Liste des ventes rapides existantes */
        .quick-sale-item {
            border-left: 4px solid #5cb85c;
            background-color: #f0fff0 !important;
            transition: all 0.3s ease;
        }
        
        .quick-sale-item:hover {
            background-color: #e8f5e8 !important;
        }
        
        .price-highlight {
            background: linear-gradient(135deg, #fff3cd 0%, #ffeaa7 100%);
            border: 1px solid #ffc107;
            border-radius: 4px;
            padding: 3px 8px;
            font-weight: 600;
            color: #856404;
        }
        
        /* Panel d'actions */
        .actions-panel {
            background: linear-gradient(135deg, #f8f9fa 0%, #e9ecef 100%);
            border: 1px solid #dee2e6;
            padding: 20px;
            border-radius: 8px;
            margin-top: 20px;
            text-align: center;
            box-shadow: 0 2px 8px rgba(0,0,0,0.05);
        }
        
        .btn-action {
            margin: 0 8px 10px 8px;
            padding: 12px 24px;
            border: none;
            border-radius: 6px;
            cursor: pointer;
            font-weight: 600;
            font-size: 14px;
            transition: all 0.3s ease;
            box-shadow: 0 2px 6px rgba(0,0,0,0.1);
        }
        
        .btn-finalize {
            background: linear-gradient(135deg, #28a745 0%, #20c997 100%);
            color: white;
        }
        
        .btn-clear {
            background: linear-gradient(135deg, #dc3545 0%, #e74c3c 100%);
            color: white;
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
                <a href="quick_sales.php" class="current">Ventes Rapides</a>
            </div>
            <h1>🚀 Ventes Rapides</h1>
        </div>

        <div class="container-fluid">
            <!-- Indicateur utilisateur -->
            <div class="user-cart-indicator">
                <i class="icon-user"></i> 
                <strong>Ventes gérées par: <?php echo htmlspecialchars($currentAdminName ?? ''); ?></strong>
            </div>

            <!-- Section de recherche -->
            <div class="search-section">
                <h4><i class="icon-search"></i> Rechercher des Articles</h4>
                <form method="get" action="quick_sales.php" class="form-inline">
                    <input type="text" name="searchTerm" class="span4" 
                           placeholder="Nom du Article, modèle..." 
                           list="productsList"
                           value="<?php echo isset($_GET['searchTerm']) ? htmlspecialchars($_GET['searchTerm'] ?? '') : ''; ?>" />
                    <datalist id="productsList">
                        <?php foreach ($productNames as $pname): ?>
                            <option value="<?php echo htmlspecialchars($pname ?? ''); ?>"></option>
                        <?php endforeach; ?>
                    </datalist>
                    <button type="submit" class="btn btn-primary">
                        <i class="icon-search"></i> Rechercher
                    </button>
                    <?php if (!empty($_GET['searchTerm'])): ?>
                        <a href="quick_sales.php" class="btn">
                            <i class="icon-remove"></i> Effacer
                        </a>
                    <?php endif; ?>
                </form>
            </div>

            <?php
            // Affichage des résultats de recherche
            if (!empty($_GET['searchTerm'])) {
                $searchTerm = mysqli_real_escape_string($con, $_GET['searchTerm']);
                $sql = "
                    SELECT 
                        p.ID,
                        p.ProductName,
                        p.ModelNumber,
                        p.Price,
                        p.Stock,
                        c.CategoryName
                    FROM tblproducts p
                    LEFT JOIN tblcategory c ON c.ID = p.CatID
                    WHERE 
                        p.ProductName LIKE ?
                        OR p.ModelNumber LIKE ?
                ";

                $stmt = mysqli_prepare($con, $sql);
                if (!$stmt) {
                    die("MySQL prepare error: " . mysqli_error($con));
                }
                
                $searchParam = "%$searchTerm%";
                mysqli_stmt_bind_param($stmt, "ss", $searchParam, $searchParam);
                mysqli_stmt_execute($stmt);
                $res = mysqli_stmt_get_result($stmt);
                
                $count = mysqli_num_rows($res);
            ?>

            <div class="widget-box">
                <div class="widget-title">
                    <span class="icon"><i class="icon-search"></i></span>
                    <h5>Résultats de recherche pour "<?php echo htmlspecialchars($_GET['searchTerm'] ?? ''); ?>"</h5>
                </div>
                <div class="widget-content nopadding">
                    <?php if ($count > 0): ?>
                        <table class="table table-bordered table-striped">
                            <thead>
                                <tr>
                                    <th>#</th>
                                    <th>Nom du Article</th>
                                    <th>Catégorie</th>
                                    <th>Modèle</th>
                                    <th>Prix par Défaut</th>
                                    <th>Stock</th>
                                    <th>Prix Personnalisé</th>
                                    <th>Quantité</th>
                                    <th>Action</th>
                                </tr>
                            </thead>
                            <tbody>
                            <?php
                            $i = 1;
                            while ($row = mysqli_fetch_assoc($res)) {
                                $disableAdd = ($row['Stock'] <= 0);
                                $rowClass = $disableAdd ? 'class="stock-error"' : '';
                                $stockStatus = '';
                                
                                if ($row['Stock'] <= 0) {
                                    $stockStatus = '<span class="stock-status stock-danger">Rupture</span>';
                                } elseif ($row['Stock'] < 5) {
                                    $stockStatus = '<span class="stock-status stock-warning">Faible</span>';
                                } else {
                                    $stockStatus = '<span class="stock-status stock-ok">Disponible</span>';
                                }
                                ?>
                                <tr <?php echo $rowClass; ?>>
                                    <td><?php echo $i++; ?></td>
                                    <td><strong><?php echo htmlspecialchars($row['ProductName'] ?? ''); ?></strong></td>
                                    <td><?php echo htmlspecialchars($row['CategoryName'] ?? ''); ?></td>
                                    <td><?php echo htmlspecialchars($row['ModelNumber'] ?? ''); ?></td>
                                    <td><?php echo number_format($row['Price'], 2); ?> GNF</td>
                                    <td><?php echo $row['Stock'] . ' ' . $stockStatus; ?></td>
                                    <td>
                                        <form method="post" action="quick_sales.php" style="margin:0; display: inline-flex; align-items: center;">
                                            <input type="hidden" name="productid" value="<?php echo $row['ID']; ?>" />
                                            <input type="number" name="price" step="any" 
                                                   value="<?php echo $row['Price']; ?>" 
                                                   style="width:100px;" 
                                                   <?php echo $disableAdd ? 'disabled' : ''; ?> />
                                    </td>
                                    <td>
                                        <input type="number" name="quantity" value="1" min="1" 
                                               max="<?php echo $row['Stock']; ?>" 
                                               style="width:60px;" 
                                               <?php echo $disableAdd ? 'disabled' : ''; ?> />
                                    </td>
                                    <td>
                                        <button type="submit" name="addQuickSale" 
                                                class="btn btn-success btn-small" 
                                                <?php echo $disableAdd ? 'disabled' : ''; ?>>
                                            <i class="icon-plus"></i> Ajouter
                                        </button>
                                        </form>
                                    </td>
                                </tr>
                                <?php
                            }
                            ?>
                            </tbody>
                        </table>
                    <?php else: ?>
                        <div style="padding: 20px; text-align: center; color: #999;">
                            <i class="icon-info-sign"></i> Aucun article correspondant trouvé.
                        </div>
                    <?php endif; ?>
                </div>
            </div>

            <?php 
            } // Fin de l'affichage des résultats de recherche
            ?>

            <!-- Liste des ventes rapides en cours -->
            <div class="widget-box" style="margin-top: 20px;">
                <div class="widget-title">
                    <span class="icon"><i class="icon-shopping-cart"></i></span>
                    <h5>Ventes Rapides en Cours</h5>
                </div>
                <div class="widget-content nopadding">
                    <table class="table table-bordered">
                        <thead>
                            <tr>
                                <th>N°</th>
                                <th>Produit</th>
                                <th>Prix Unitaire</th>
                                <th>Quantité</th>
                                <th>Total Ligne</th>
                                <th>Action</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php
                            $salesQuery = mysqli_query($con, "
                                SELECT 
                                    c.ID,
                                    c.ProductQty,
                                    c.Price,
                                    p.ProductName,
                                    p.Price as BasePrice
                                FROM tblcart c
                                LEFT JOIN tblproducts p ON p.ID = c.ProductId
                                WHERE c.IsCheckOut = 0 AND c.AdminID = '$currentAdminID'
                                ORDER BY c.ID DESC
                            ");
                            
                            $cnt = 1;
                            $grandTotal = 0;
                            $totalItems = 0;
                            
                            if (mysqli_num_rows($salesQuery) > 0) {
                                while ($sale = mysqli_fetch_assoc($salesQuery)) {
                                    $lineTotal = $sale['ProductQty'] * $sale['Price'];
                                    $grandTotal += $lineTotal;
                                    $totalItems += $sale['ProductQty'];
                                    
                                    $priceChanged = ($sale['Price'] != $sale['BasePrice']);
                                    $priceClass = $priceChanged ? 'price-highlight' : '';
                                    ?>
                                    <tr class="quick-sale-item">
                                        <td><?php echo $cnt++; ?></td>
                                        <td>
                                            <strong><?php echo htmlspecialchars($sale['ProductName'] ?? ''); ?></strong>
                                        </td>
                                        <td>
                                            <span class="<?php echo $priceClass; ?>">
                                                <?php echo number_format($sale['Price'], 2); ?> GNF
                                            </span>
                                            <?php if ($priceChanged): ?>
                                                <br><small style="color: #666;">
                                                    (Base: <?php echo number_format($sale['BasePrice'], 2); ?> GNF)
                                                </small>
                                            <?php endif; ?>
                                        </td>
                                        <td><?php echo $sale['ProductQty']; ?></td>
                                        <td><strong><?php echo number_format($lineTotal, 2); ?> GNF</strong></td>
                                        <td>
                                            <a href="quick_sales.php?delid=<?php echo $sale['ID']; ?>" 
                                               onclick="return confirm('Supprimer cette vente ?');" 
                                               class="btn btn-danger btn-small">
                                                <i class="icon-trash"></i>
                                            </a>
                                        </td>
                                    </tr>
                                    <?php
                                }
                                ?>
                                <tr style="background: #f0f8ff;">
                                    <td colspan="4" style="text-align: right; font-weight: bold;">
                                        TOTAL GÉNÉRAL:
                                    </td>
                                    <td style="font-weight: bold; font-size: 16px;">
                                        <?php echo number_format($grandTotal, 2); ?> GNF
                                    </td>
                                    <td style="text-align: center;">
                                        <strong><?php echo $totalItems; ?> articles</strong>
                                    </td>
                                </tr>
                                <?php
                            } else {
                                ?>
                                <tr>
                                    <td colspan="6" style="text-align: center; color: #999; padding: 30px;">
                                        Aucune vente rapide en cours.
                                    </td>
                                </tr>
                                <?php
                            }
                            ?>
                        </tbody>
                    </table>
                </div>
            </div>

            <!-- Actions -->
            <?php if (mysqli_num_rows($salesQuery) > 0): ?>
            <div class="actions-panel">
                <h4><i class="icon-cogs"></i> Actions</h4>
                <form method="post" style="display: inline-block; margin-right: 20px;">
                    <button type="submit" name="finalizeQuickSales" class="btn-action btn-finalize">
                        <i class="icon-ok-circle"></i> Finaliser & Créer Facture
                    </button>
                </form>
                
                <form method="post" style="display: inline-block;" 
                      onsubmit="return confirm('Supprimer TOUTES les ventes ?');">
                    <button type="submit" name="clearQuickSales" class="btn-action btn-clear">
                        <i class="icon-remove-circle"></i> Tout Supprimer
                    </button>
                </form>
            </div>
            <?php endif; ?>
        </div>
    </div>

    <?php include_once('includes/footer.php'); ?>

    <script src="js/jquery.min.js"></script>
    <script src="js/bootstrap.min.js"></script>
    <script src="js/matrix.js"></script>
</body>
</html>