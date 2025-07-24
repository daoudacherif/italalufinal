<?php
session_start();
// Affiche toutes les erreurs (à désactiver en production)
ini_set('display_errors', 1);
ini_set('display_startup_errors', 1);
error_reporting(E_ALL);

include('includes/dbconnection.php');

// Check if user is logged in
if (!isset($_SESSION['imsaid']) || empty($_SESSION['imsaid'])) {
    header("Location: login.php");
    exit;
}

// Get the current admin ID from session
$currentAdminID = $_SESSION['imsaid'];

// Get the current admin name (since it's not stored in session)
$adminQuery = mysqli_query($con, "SELECT AdminName FROM tbladmin WHERE ID = '$currentAdminID'");
$adminData = mysqli_fetch_assoc($adminQuery);
$currentAdminName = $adminData['AdminName'];

// Appliquer une remise (en valeur absolue ou en pourcentage)
if (isset($_POST['applyDiscount'])) {
    $discountValue = max(0, floatval($_POST['discount']));
    
    // Calculer le grand total avant d'appliquer la remise
    $grandTotal = 0;
    $cartQuery = mysqli_query($con, "SELECT ProductQty, Price FROM tblcart WHERE IsCheckOut=0 AND AdminID='$currentAdminID'");
    while ($row = mysqli_fetch_assoc($cartQuery)) {
        $grandTotal += $row['ProductQty'] * $row['Price'];
    }
    
    // Déterminer si c'est un pourcentage ou une valeur absolue
    $isPercentage = isset($_POST['discountType']) && $_POST['discountType'] === 'percentage';
    
    if ($isPercentage) {
        // Limiter le pourcentage à 100% maximum
        $discountValue = min(100, $discountValue);
        // Calculer la remise en valeur absolue basée sur le pourcentage
        $actualDiscount = ($discountValue / 100) * $grandTotal;
    } else {
        // Remise en valeur absolue (limiter à la valeur du panier)
        $actualDiscount = min($grandTotal, $discountValue);
    }
    
    // Stocker les informations de remise dans la session
    $_SESSION['discount'] = $actualDiscount;
    $_SESSION['discountType'] = $isPercentage ? 'percentage' : 'absolute';
    $_SESSION['discountValue'] = $discountValue;
    
    header("Location: cart.php");
    exit;
}

// Récupérer les informations de remise de la session
$discount = $_SESSION['discount'] ?? 0;
$discountType = $_SESSION['discountType'] ?? 'absolute';
$discountValue = $_SESSION['discountValue'] ?? 0;

// Traitement de la suppression d'un élément du panier
if (isset($_GET['delid'])) {
    $delid = intval($_GET['delid']);
    $deleteQuery = mysqli_query($con, "DELETE FROM tblcart WHERE ID = $delid AND IsCheckOut = 0 AND AdminID = '$currentAdminID'");
    if ($deleteQuery) {
        echo "<script>
                alert('Article retiré du panier');
                window.location.href='cart.php';
              </script>";
        exit;
    }
}

//////////////////////////////////////////////////////////////////////////
// GESTION DE L'AJOUT AU PANIER AVEC VÉRIFICATION STRICTE DU STOCK
//////////////////////////////////////////////////////////////////////////
if (isset($_POST['addtocart'])) {
    $productId = intval($_POST['productid']);
    $quantity  = max(1, intval($_POST['quantity']));
    $price     = max(0, floatval($_POST['price']));

    // 1) Récupérer le stock et les informations du produit
    $stockRes = mysqli_query($con, "
        SELECT 
            p.Stock,
            p.ProductName,
            p.ID
        FROM tblproducts p
        WHERE p.ID = '$productId'
        LIMIT 1
    ");
    
    if (!$stockRes || mysqli_num_rows($stockRes) === 0) {
        echo "<script>
                alert('Article introuvable.');
                window.location.href='cart.php';
              </script>";
        exit;
    }
    
    $row = mysqli_fetch_assoc($stockRes);
    $currentStock = intval($row['Stock']);
    $productName = $row['ProductName'];

    // 2) Vérifier que le stock est strictement supérieur à 0
    if ($currentStock <= 0) {
        echo "<script>
                alert('Article \"" . htmlspecialchars($productName) . "\" en rupture de stock.');
                window.location.href='cart.php';
              </script>";
        exit;
    }

    // 3) Vérifier que la quantité demandée est disponible
    if ($quantity > $currentStock) {
        echo "<script>
                alert('Stock insuffisant pour \"" . htmlspecialchars($productName) . "\". Stock disponible: " . $currentStock . "');
                window.location.href='cart.php';
              </script>";
        exit;
    }

    // 4) Vérifier si l'article existe déjà dans le panier
    $checkCart = mysqli_query($con, "
        SELECT ID, ProductQty 
        FROM tblcart 
        WHERE ProductId='$productId' AND IsCheckOut=0 AND AdminID='$currentAdminID'
        LIMIT 1
    ");
    
    if (mysqli_num_rows($checkCart) > 0) {
        $c = mysqli_fetch_assoc($checkCart);
        $newQty = $c['ProductQty'] + $quantity;
        
        // Vérifier que la nouvelle quantité totale ne dépasse pas le stock disponible
        if ($newQty > $currentStock) {
            echo "<script>
                    alert('Quantité totale demandée (" . $newQty . ") supérieure au stock disponible (" . $currentStock . ") pour \"" . htmlspecialchars($productName) . "\".');
                    window.location.href='cart.php';
                  </script>";
            exit;
        }
        
        // Mettre à jour la quantité
        mysqli_query($con, "
            UPDATE tblcart 
            SET ProductQty='$newQty', Price='$price' 
            WHERE ID='{$c['ID']}'
        ") or die(mysqli_error($con));
    } else {
        // Ajouter nouveau produit au panier
        mysqli_query($con, "
            INSERT INTO tblcart(ProductId, ProductQty, Price, IsCheckOut, AdminID) 
            VALUES('$productId', '$quantity', '$price', '0', '$currentAdminID')
        ") or die(mysqli_error($con));
    }

    echo "<script>
            alert('Article \"" . htmlspecialchars($productName) . "\" ajouté au panier !');
            window.location.href='cart.php';
          </script>";
    exit;
}

//////////////////////////////////////////////////////////////////////////
// VALIDATION DU PANIER / CHECKOUT AVEC VÉRIFICATION STRICTE
//////////////////////////////////////////////////////////////////////////
if (isset($_POST['submit'])) {
    // Récupération des infos client
    $custname     = mysqli_real_escape_string($con, trim($_POST['customername']));
    $custmobile   = preg_replace('/[^0-9+]/','', $_POST['mobilenumber']);
    $modepayment  = mysqli_real_escape_string($con, $_POST['modepayment']);
    $discount     = $_SESSION['discount'] ?? 0;

    // Calcul du total
    $cartQ = mysqli_query($con, "SELECT ProductQty, Price FROM tblcart WHERE IsCheckOut=0 AND AdminID='$currentAdminID'");
    $grand = 0;
    while ($r = mysqli_fetch_assoc($cartQ)) {
        $grand += $r['ProductQty'] * $r['Price'];
    }
    $netTotal = max(0, $grand - $discount);

    // Vérification finale des stocks avant validation
    $stockCheck = mysqli_query($con, "
        SELECT 
            p.ProductName, 
            p.Stock, 
            c.ProductQty
        FROM tblcart c
        JOIN tblproducts p ON p.ID = c.ProductId
        WHERE c.IsCheckOut = 0 AND c.AdminID = '$currentAdminID'
    ");
    
    $stockErrors = [];
    while ($row = mysqli_fetch_assoc($stockCheck)) {
        // Vérification du stock suffisant
        if ($row['Stock'] <= 0) {
            $stockErrors[] = "{$row['ProductName']} est en rupture de stock";
        }
        else if ($row['Stock'] < $row['ProductQty']) {
            $stockErrors[] = "Stock insuffisant pour {$row['ProductName']} (demandé: {$row['ProductQty']}, disponible: {$row['Stock']})";
        }
    }
    
    if (!empty($stockErrors)) {
        $errorMsg = "Impossible de finaliser la commande:\\n- " . implode("\\n- ", $stockErrors);
        echo "<script>alert(" . json_encode($errorMsg) . "); window.location='cart.php';</script>";
        exit;
    }

    // Générer un numéro de facture unique
    $billingnum = mt_rand(1000, 9999);

    // Mise à jour du panier + insertion client
    $query  = "UPDATE tblcart SET BillingId='$billingnum', IsCheckOut=1 WHERE IsCheckOut=0 AND AdminID='$currentAdminID';";
    $query .= "INSERT INTO tblcustomer
                 (BillingNumber, CustomerName, MobileNumber, ModeofPayment, FinalAmount)
               VALUES
                 ('$billingnum','$custname','$custmobile','$modepayment','$netTotal');";
    $result = mysqli_multi_query($con, $query);

    if ($result) {
        // Vider les résultats de la multi_query pour éviter le 'out of sync'
        while (mysqli_more_results($con) && mysqli_next_result($con)) {
            // rien à faire, on boucle juste
        }

        // Décrémenter le stock dans tblproducts
        $updateStockSql = "
            UPDATE tblproducts p
            JOIN tblcart c ON p.ID = c.ProductId
            SET p.Stock = p.Stock - c.ProductQty
            WHERE c.BillingId = '$billingnum'
              AND c.IsCheckOut = 1
        ";
        mysqli_query($con, $updateStockSql) or die(mysqli_error($con));

        $_SESSION['invoiceid'] = $billingnum;
        unset($_SESSION['discount']);
        unset($_SESSION['discountType']);
        unset($_SESSION['discountValue']);

        echo "<script>
                alert('Facture créée : $billingnum');
                window.location.href='invoice.php?print=auto';
              </script>";
        exit;
    } else {
        echo "<script>alert('Erreur lors du paiement');</script>";
    }
}

// Récupérer les noms de Articles pour le datalist
$productNamesQuery = mysqli_query($con, "SELECT DISTINCT ProductName FROM tblproducts ORDER BY ProductName ASC");
$productNames = array();
if ($productNamesQuery) {
    while ($row = mysqli_fetch_assoc($productNamesQuery)) {
        $productNames[] = $row['ProductName'];
    }
}

// Vérifier les stocks pour l'affichage du panier
$hasStockIssue = false;
$stockIssueProducts = [];

$cartProducts = mysqli_query($con, "
    SELECT c.ID, c.ProductId, c.ProductQty, p.Stock, p.ProductName 
    FROM tblcart c
    JOIN tblproducts p ON p.ID = c.ProductId
    WHERE c.IsCheckOut=0 AND c.AdminID='$currentAdminID'
");

while ($product = mysqli_fetch_assoc($cartProducts)) {
    if ($product['Stock'] <= 0 || $product['Stock'] < $product['ProductQty']) {
        $hasStockIssue = true;
        $stockIssueProducts[] = $product['ProductName'];
    }
}
?>
<!DOCTYPE html>
<html lang="fr">
<head>
    <title>Système de gestion des stocks | Panier</title>
    <?php include_once('includes/cs.php'); ?>
    <?php include_once('includes/responsive.php'); ?>
    <style>
        /* Styles pour les indicateurs de stock */
        .stock-warning {
            color: #d9534f;
            font-weight: bold;
            margin-left: 5px;
        }
        
        tr.stock-error {
            background-color: #f2dede !important;
        }
        
        .global-warning {
            background-color: #f2dede;
            border: 1px solid #ebccd1;
            color: #a94442;
            padding: 10px 15px;
            margin-bottom: 15px;
            border-radius: 4px;
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
        
        .user-cart-indicator {
            background-color: #f8f8f8;
            border-left: 4px solid #27a9e3;
            padding: 10px;
            margin-bottom: 15px;
        }
        .user-cart-indicator i {
            margin-right: 5px;
            color: #27a9e3;
        }
    </style>
</head>
<body>
    <!-- Header + Sidebar -->
    <?php include_once('includes/header.php'); ?>
    <?php include_once('includes/sidebar.php'); ?>

    <div id="content">
        <div id="content-header">
            <div id="breadcrumb">
                <a href="dashboard.php" title="Aller à l'accueil" class="tip-bottom">
                    <i class="icon-home"></i> Accueil
                </a>
                <a href="cart.php" class="current">Panier de Articles</a>
            </div>
            <h1>Panier de Articles</h1>
        </div>

        <div class="container-fluid">
            <hr>
            <!-- Indicateur de panier utilisateur -->
            <div class="user-cart-indicator">
                <i class="icon-user"></i> <strong>Panier géré par: <?php echo htmlspecialchars($currentAdminName); ?></strong>
            </div>
            
            <!-- Message d'alerte si problème de stock -->
            <?php if ($hasStockIssue): ?>
            <div class="global-warning">
                <strong><i class="icon-warning-sign"></i> Attention !</strong> Certains Articles dans votre panier ont des problèmes de stock :
                <ul>
                    <?php foreach($stockIssueProducts as $product): ?>
                    <li><?php echo htmlspecialchars($product); ?></li>
                    <?php endforeach; ?>
                </ul>
                Veuillez ajuster les quantités ou supprimer ces Articles avant de finaliser la commande.
            </div>
            
            <!-- Script pour désactiver le bouton de paiement en cas de problème de stock -->
            <script>
                document.addEventListener('DOMContentLoaded', function() {
                    // Désactiver le bouton de validation
                    var submitBtn = document.querySelector('button[name="submit"]');
                    if (submitBtn) {
                        submitBtn.disabled = true;
                        submitBtn.title = "Veuillez d'abord résoudre les problèmes de stock";
                        submitBtn.style.opacity = "0.5";
                        submitBtn.style.cursor = "not-allowed";
                    }
                });
            </script>
            <?php endif; ?>
            
            <!-- ========== FORMULAIRE DE RECHERCHE (avec datalist) ========== -->
            <div class="row-fluid">
                <div class="span12">
                    <form method="get" action="cart.php" class="form-inline">
                        <label>Rechercher des Articles :</label>
                        <input type="text" name="searchTerm" class="span3" placeholder="Nom du Article..." list="productsList" />
                        <datalist id="productsList">
                            <?php
                            foreach ($productNames as $pname) {
                                echo '<option value="' . htmlspecialchars($pname) . '"></option>';
                            }
                            ?>
                        </datalist>
                        <button type="submit" class="btn btn-primary">Rechercher</button>
                    </form>
                </div>
            </div>
            <hr>

            <?php
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
                    LEFT JOIN tblcategory c 
                        ON c.ID = p.CatID
                    WHERE 
                        p.ProductName LIKE ?
                        OR p.ModelNumber LIKE ?
                ";

                // Utiliser les requêtes préparées pour prévenir les injections SQL
                $stmt = mysqli_prepare($con, $sql);
                if (!$stmt) {
                    die("MySQL prepare error: " . mysqli_error($con));
                }
                
                $searchParam = "%$searchTerm%";
                mysqli_stmt_bind_param($stmt, "ss", $searchParam, $searchParam);
                mysqli_stmt_execute($stmt);
                $res = mysqli_stmt_get_result($stmt);
                
                if (!$res) {
                    die("MySQL error: " . mysqli_error($con));
                }

                $count = mysqli_num_rows($res);
            ?>

            <div class="row-fluid">
                <div class="span12">
                    <h4>Résultats de recherche pour "<em><?= htmlspecialchars($_GET['searchTerm']) ?></em>"</h4>

                    <?php if ($count > 0) { ?>
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
                                        <th>Ajouter</th>
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
                                        <td><?php echo $row['ProductName']; ?></td>
                                        <td><?php echo $row['CategoryName']; ?></td>
                                        <td><?php echo $row['ModelNumber']; ?></td>
                                        <td><?php echo $row['Price']; ?></td>
                                        <td><?php echo $row['Stock'] . ' ' . $stockStatus; ?></td>
                                        <td>
                                            <form method="post" action="cart.php" style="margin:0;">
                                                <input type="hidden" name="productid" value="<?php echo $row['ID']; ?>" />
                                                <input type="number" name="price" step="any" 
                                                       value="<?php echo $row['Price']; ?>" style="width:80px;" />
                                        </td>
                                        <td>
                                            <input type="number" name="quantity" value="1" min="1" max="<?php echo $row['Stock']; ?>" style="width:60px;" <?php echo $disableAdd ? 'disabled' : ''; ?> />
                                        </td>
                                        <td>
                                            <button type="submit" name="addtocart" class="btn btn-success btn-small" <?php echo $disableAdd ? 'disabled' : ''; ?>>
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
                        <?php } else { ?>
                            <p style="color:red;">Aucun Article correspondant trouvé.</p>
                        <?php } ?>
                    </div>
                </div>
                <hr>
            <?php } ?>

            <!-- ========== PANIER + REMISE + PAIEMENT ========== -->
            <div class="row-fluid">
                <div class="span12">
                    <!-- FORMULAIRE DE REMISE avec option pour pourcentage -->
                    <form method="post" class="form-inline" style="text-align:right;">
                        <label>Remise :</label>
                        <input type="number" name="discount" step="any" value="<?php echo $discountValue; ?>" style="width:80px;" />
                        
                        <select name="discountType" style="width:120px; margin-left:5px;">
                            <option value="absolute" <?php echo ($discountType == 'absolute') ? 'selected' : ''; ?>>Valeur absolue</option>
                            <option value="percentage" <?php echo ($discountType == 'percentage') ? 'selected' : ''; ?>>Pourcentage (%)</option>
                        </select>
                        
                        <button class="btn btn-info" type="submit" name="applyDiscount" style="margin-left:5px;">Appliquer</button>
                    </form>
                    <hr>

                    <!-- Formulaire checkout (informations client) -->
                    <form method="post" class="form-horizontal" name="submit">
                        <div class="control-group">
                            <label class="control-label">Nom du client :</label>
                            <div class="controls">
                                <input type="text" class="span11" id="customername" name="customername" required />
                            </div>
                        </div>
                        <div class="control-group">
                            <label class="control-label">Numéro de mobile du client :</label>
                            <div class="controls">
                                <input type="tel"
                                       class="span11"
                                       id="mobilenumber"
                                       name="mobilenumber"
                                       required
                                       pattern="^\+224[0-9]{9}$"
                                       placeholder="+224xxxxxxxxx"
                                       title="Format: +224 suivi de 9 chiffres">
                            </div>
                        </div>
                        <div class="control-group">
                            <label class="control-label">Mode de paiement :</label>
                            <div class="controls">
                                <label><input type="radio" name="modepayment" value="cash" checked> Espèces</label>
                                <label><input type="radio" name="modepayment" value="card"> Carte</label>
                                <label><input type="radio" name="modepayment" value="credit"> Crédit (Terme)</label>
                            </div>
                        </div>
                        <div class="text-center">
                            <button class="btn btn-primary" type="submit" name="submit" <?php echo $hasStockIssue ? 'disabled' : ''; ?>>
                                Paiement & Créer une facture
                            </button>
                        </div>
                    </form>

                    <!-- Tableau du panier -->
                    <div class="widget-box">
                        <div class="widget-title">
                            <span class="icon"><i class="icon-th"></i></span>
                            <h5>Articles dans le panier</h5>
                        </div>
                        <div class="widget-content nopadding">
                            <table class="table table-bordered" style="font-size: 15px">
                                <thead>
                                    <tr>
                                        <th>N°</th>
                                        <th>Nom du Article</th>
                                        <th>Quantité</th>
                                        <th>Stock</th>
                                        <th>Prix de base</th>
                                        <th>Prix appliqué</th>
                                        <th>Total</th>
                                        <th>Action</th>
                                    </tr>
                                </thead>
                                <tbody>
                                    <?php
                                    $ret = mysqli_query($con, "
                                      SELECT 
                                        tblcart.ID as cid,
                                        tblcart.ProductQty,
                                        tblcart.Price as cartPrice,
                                        tblproducts.ProductName,
                                        tblproducts.Stock,
                                        tblproducts.Price as basePrice
                                      FROM tblcart
                                      LEFT JOIN tblproducts ON tblproducts.ID = tblcart.ProductId
                                      WHERE tblcart.IsCheckOut = 0 AND tblcart.AdminID = '$currentAdminID'
                                      ORDER BY tblcart.ID ASC
                                    ");
                                    $cnt = 1;
                                    $grandTotal = 0;
                                    $num = mysqli_num_rows($ret);
                                    
                                    if ($num > 0) {
                                        while ($row = mysqli_fetch_array($ret)) {
                                            $pq = $row['ProductQty'];
                                            $ppu = $row['cartPrice'];
                                            $basePrice = $row['basePrice'];
                                            $stock = $row['Stock'];
                                            $lineTotal = $pq * $ppu;
                                            $grandTotal += $lineTotal;
                                            
                                            // Vérification du stock pour cette ligne
                                            $stockIssue = ($stock <= 0 || $stock < $pq);
                                            $rowClass = $stockIssue ? 'class="stock-error"' : '';
                                            $stockStatus = '';
                                            
                                            if ($stock <= 0) {
                                                $stockStatus = '<span class="stock-warning">RUPTURE</span>';
                                            } elseif ($stock < $pq) {
                                                $stockStatus = '<span class="stock-warning">INSUFFISANT</span>';
                                            }
                                            
                                            // Déterminer si le prix a été modifié par rapport au prix de base
                                            $prixModifie = ($ppu != $basePrice);
                                            ?>
                                            <tr <?php echo $rowClass; ?>>
                                                <td><?php echo $cnt; ?></td>
                                                <td>
                                                    <?php echo $row['ProductName']; ?>
                                                    <?php echo $stockStatus; ?>
                                                </td>
                                                <td><?php echo $pq; ?></td>
                                                <td>
                                                    <?php echo $stock; ?>
                                                    <?php if ($stockIssue): ?>
                                                        <br><span class="label label-important">Attention!</span>
                                                    <?php endif; ?>
                                                </td>
                                                <td>
                                                    <?php echo number_format($basePrice, 2); ?> GNF
                                                </td>
                                                <td <?php echo $prixModifie ? 'style="color: #f89406; font-weight: bold;"' : ''; ?>>
                                                    <?php echo number_format($ppu, 2); ?> GNF
                                                    <?php if ($prixModifie): ?>
                                                        <?php 
                                                        $variation = (($ppu / $basePrice) - 1) * 100;
                                                        $symbole = $variation >= 0 ? '+' : '';
                                                        echo '<br><small class="text-muted">(' . $symbole . number_format($variation, 1) . '%)</small>'; 
                                                        ?>
                                                    <?php endif; ?>
                                                </td>
                                                <td><?php echo number_format($lineTotal, 2); ?> GNF</td>
                                                <td>
                                                    <a href="cart.php?delid=<?php echo $row['cid']; ?>"
                                                       onclick="return confirm('Voulez-vous vraiment retirer cet article?');">
                                                        <i class="icon-trash"></i>
                                                    </a>
                                                </td>
                                            </tr>
                                            <?php
                                            $cnt++;
                                        }
                                        $netTotal = $grandTotal - $discount;
                                        if ($netTotal < 0) {
                                            $netTotal = 0;
                                        }
                                        ?>
                                        <tr>
                                            <th colspan="6" style="text-align: right; font-weight: bold;">Total Général</th>
                                            <th colspan="2" style="text-align: center; font-weight: bold;"><?php echo number_format($grandTotal, 2); ?> GNF</th>
                                        </tr>
                                        <tr>
                                            <th colspan="6" style="text-align: right; font-weight: bold;">
                                                Remise
                                                <?php if ($discountType == 'percentage'): ?>
                                                    (<?php echo $discountValue; ?>%)
                                                <?php endif; ?>
                                            </th>
                                            <th colspan="2" style="text-align: center; font-weight: bold;"><?php echo number_format($discount, 2); ?> GNF</th>
                                        </tr>
                                        <tr>
                                            <th colspan="6" style="text-align: right; font-weight: bold; color: green;">Total Net</th>
                                            <th colspan="2" style="text-align: center; font-weight: bold; color: green;"><?php echo number_format($netTotal, 2); ?> GNF</th>
                                        </tr>
                                    <?php
                                    } else {
                                        ?>
                                        <tr>
                                            <td colspan="8" style="color:red; text-align:center">Aucun article trouvé dans le panier</td>
                                        </tr>
                                        <?php
                                    }
                                    ?>
                                </tbody>
                            </table>
                        </div><!-- widget-content -->
                    </div><!-- widget-box -->
                </div>
            </div><!-- row-fluid -->
        </div><!-- container-fluid -->
    </div><!-- content -->

    <!-- Footer -->
    <?php include_once('includes/footer.php'); ?>

    <!-- SCRIPTS -->
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