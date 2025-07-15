<?php 
session_start();
error_reporting(E_ALL);
include('includes/dbconnection.php');

// Vérifie que l'admin est connecté
if (empty($_SESSION['imsaid'])) {
    header('Location: logout.php');
    exit;
}

// Get the current admin ID from session (for display purposes only)
$currentAdminID = $_SESSION['imsaid'];

// Get the current admin name (for display purposes only)
$adminQuery = mysqli_query($con, "SELECT AdminName FROM tbladmin WHERE ID = '$currentAdminID'");
$adminData = mysqli_fetch_assoc($adminQuery);
$currentAdminName = $adminData['AdminName'];

/**
 * FONCTION SMS SIMPLIFIÉE - Un seul sender_name pour éviter les doubles débits
 */
function sendSmsNotification($to, $message) {
    // 1. Validation des paramètres d'entrée
    if (empty($to) || empty($message)) {
        error_log("Nimba SMS Error: Numéro ou message vide");
        return false;
    }
    
    // 2. Nettoyage et validation du numéro
    $to = trim($to);
    $to = preg_replace('/[^0-9+]/', '', $to);
    
    if (!preg_match('/^(\+?224)?6[0-9]{8}$/', $to)) {
        error_log("Nimba SMS Error: Format de numéro invalide: $to");
        return false;
    }
    
    // 3. Validation longueur message
    if (strlen($message) > 665) {
        error_log("Nimba SMS Error: Message trop long (" . strlen($message) . " caractères)");
        return false;
    }
    
    // 4. Configuration API Nimba
    $url = "https://api.nimbasms.com/v1/messages";
    $service_id = "0b0aa04ddcf33f25a796fc8aac76b66e";
    $secret_token = "Lt-PsM_2LdTPZPtkCmL5DXHiRJVcJRlj8p5nTxQap9iPJoknVoyXGR8uv-wT6aVEErBgJBRoqPbp8cHyKGzqgSw3CkC_ypLH4u8SAV3NjH8";
    $sender_name = "SMS 9080"; // Sender unique qui fonctionne
    
    $authString = base64_encode($service_id . ":" . $secret_token);
    
    // 5. Préparation des données (un seul envoi)
    $postData = json_encode([
        "sender_name" => $sender_name,
        "to" => [$to],
        "message" => $message
    ]);
    
    $headers = [
        "Authorization: Basic " . $authString,
        "Content-Type: application/json",
        "Content-Length: " . strlen($postData)
    ];
    
    // Log simplifié
    error_log("Nimba SMS - Envoi vers: $to avec sender: $sender_name");
    
    // 6. Envoi avec cURL (une seule tentative)
    $ch = curl_init();
    curl_setopt_array($ch, [
        CURLOPT_URL => $url,
        CURLOPT_POST => true,
        CURLOPT_HTTPHEADER => $headers,
        CURLOPT_POSTFIELDS => $postData,
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_SSL_VERIFYPEER => false,
        CURLOPT_TIMEOUT => 30,
        CURLOPT_CONNECTTIMEOUT => 10
    ]);
    
    $response = curl_exec($ch);
    $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    $curlError = curl_error($ch);
    curl_close($ch);
    
    // Log de la réponse
    error_log("Nimba SMS - HTTP: $httpCode | Response: $response");
    
    if ($curlError) {
        error_log("Nimba SMS - Curl Error: $curlError");
        return false;
    }
    
    // Vérification du succès (201)
    if ($httpCode == 201) {
        $responseData = json_decode($response, true);
        if (isset($responseData['messageid'])) {
            error_log("✅ Nimba SMS - SUCCESS | Message ID: " . $responseData['messageid']);
        }
        return true;
    } else {
        error_log("❌ Nimba SMS - ECHEC | Code: $httpCode");
        return false;
    }
}

// ----------- Gestion Panier -----------

// Ajout au panier
if (isset($_POST['addtocart'])) {
    $productId = intval($_POST['productid']);
    $quantity  = max(1, intval($_POST['quantity']));
    $price     = max(0, floatval($_POST['price']));

    // Vérifier le stock disponible
    $stockCheck = mysqli_query($con, "SELECT Stock, ProductName FROM tblproducts WHERE ID='$productId'");
    if ($row = mysqli_fetch_assoc($stockCheck)) {
        // Vérification que le stock est strictement supérieur à 0
        if ($row['Stock'] <= 0) {
            echo "<script>alert(" . json_encode("Article \"{$row['ProductName']}\" en rupture de stock.") . "); window.location='dettecart.php';</script>";
            exit;
        }
        
        // Vérification que la quantité demandée est disponible
        if ($row['Stock'] < $quantity) {
            echo "<script>alert(" . json_encode("Stock insuffisant pour \"{$row['ProductName']}\". Stock disponible: {$row['Stock']}") . "); window.location='dettecart.php';</script>";
            exit;
        }
    } else {
        echo "<script>alert(" . json_encode("Article introuvable.") . "); window.location='dettecart.php';</script>";
        exit;
    }

    // Vérifier si l'article est déjà dans le panier
    $existCheck = mysqli_query($con, "SELECT ID, ProductQty FROM tblcreditcart WHERE ProductId='$productId' AND IsCheckOut=0 LIMIT 1");
    if (mysqli_num_rows($existCheck) > 0) {
        $c = mysqli_fetch_assoc($existCheck);
        $newQty = $c['ProductQty'] + $quantity;
        // Vérification que la nouvelle quantité totale ne dépasse pas le stock disponible
        if ($newQty > $row['Stock']) {
            echo "<script>alert(" . json_encode("Quantité totale demandée ($newQty) supérieure au stock disponible ({$row['Stock']}) pour \"{$row['ProductName']}\".") . "); window.location='dettecart.php';</script>";
            exit;
        }
        mysqli_query($con, "UPDATE tblcreditcart SET ProductQty='$newQty', Price='$price' WHERE ID='{$c['ID']}'") or die(mysqli_error($con));
    } else {
        mysqli_query($con, "INSERT INTO tblcreditcart(ProductId, ProductQty, Price, IsCheckOut) VALUES('$productId', '$quantity', '$price', 0)") or die(mysqli_error($con));
    }

    header("Location: dettecart.php");
    exit;
}

// Supprimer un Article
if (isset($_GET['delid'])) {
    $delid = intval($_GET['delid']);
    mysqli_query($con, "DELETE FROM tblcreditcart WHERE ID='$delid'") or die(mysqli_error($con));
    header("Location: dettecart.php");
    exit;
}

// Appliquer une remise (en valeur absolue ou en pourcentage)
if (isset($_POST['applyDiscount'])) {
    $discountValue = max(0, floatval($_POST['discount']));
    
    // Calculer le grand total avant d'appliquer la remise
    $grandTotal = 0;
    $cartQuery = mysqli_query($con, "SELECT ProductQty, Price FROM tblcreditcart WHERE IsCheckOut=0");
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
    $_SESSION['credit_discount'] = $actualDiscount;
    $_SESSION['credit_discountType'] = $isPercentage ? 'percentage' : 'absolute';
    $_SESSION['credit_discountValue'] = $discountValue;
    
    header("Location: dettecart.php");
    exit;
}

// Récupérer les informations de remise de la session
$discount = $_SESSION['credit_discount'] ?? 0;
$discountType = $_SESSION['credit_discountType'] ?? 'absolute';
$discountValue = $_SESSION['credit_discountValue'] ?? 0;

// Vérifier les stocks pour l'affichage
$hasStockIssue = false;
$stockIssueProducts = [];

// Récupérer la liste des noms de Articles pour la datalist
$productNames = [];
$productQuery = mysqli_query($con, "SELECT ProductName FROM tblproducts ORDER BY ProductName");
while ($row = mysqli_fetch_assoc($productQuery)) {
    $productNames[] = $row['ProductName'];
}

// Checkout + Facturation - VERSION SIMPLIFIÉE SANS DOUBLE ENVOI
if (isset($_POST['submit'])) {
    $custname = mysqli_real_escape_string($con, trim($_POST['customername']));
    $custmobile = preg_replace('/[^0-9+]/', '', $_POST['mobilenumber']);
    $modepayment = mysqli_real_escape_string($con, $_POST['modepayment']);
    $paidNow = max(0, floatval($_POST['paid']));
    
    // Vérifier si l'utilisateur veut envoyer un SMS
    $sendSms = isset($_POST['send_sms']) && $_POST['send_sms'] == '1';

    // Calcul total du panier
    $grandTotal = 0;
    $cartQuery = mysqli_query($con, "SELECT ProductQty, Price FROM tblcreditcart WHERE IsCheckOut=0");
    while ($row = mysqli_fetch_assoc($cartQuery)) {
        $grandTotal += $row['ProductQty'] * $row['Price'];
    }

    $netTotal = max(0, $grandTotal - $discount);
    $dues = max(0, $netTotal - $paidNow);

    // Vérification finale du stock
    $stockCheck = mysqli_query($con, "
        SELECT p.ProductName, p.Stock, c.ProductQty
        FROM tblcreditcart c
        JOIN tblproducts p ON p.ID = c.ProductId
        WHERE c.IsCheckOut=0
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
        echo "<script>alert(" . json_encode($errorMsg) . "); window.location='dettecart.php';</script>";
        exit;
    }

    $billingnum = mt_rand(1000, 9999);

    // Start transaction for data consistency
    mysqli_autocommit($con, FALSE);
    
    try {
        // 1. Update cart with billing number
        $updateCart = mysqli_query($con, "UPDATE tblcreditcart SET BillingId='$billingnum', IsCheckOut=1 WHERE IsCheckOut=0");
        if (!$updateCart) throw new Exception('Failed to update cart');
        
        // 2. Insert customer record
        $insertCustomer = mysqli_query($con, "
            INSERT INTO tblcustomer(BillingNumber, CustomerName, MobileNumber, ModeOfPayment, BillingDate, FinalAmount, Paid, Dues)
            VALUES('$billingnum', '$custname', '$custmobile', '$modepayment', NOW(), '$netTotal', '$paidNow', '$dues')
        ");
        if (!$insertCustomer) throw new Exception('Failed to insert customer record');
        
        // Get the customer ID for the payment record
        $customerId = mysqli_insert_id($con);
        
        // 3. Insert payment record ONLY if there was an actual payment
        if ($paidNow > 0) {
            $paymentReference = "INV-$billingnum-INITIAL"; // Generate a reference for initial payment
            $paymentComments = "Paiement initial lors de la facturation";
            
            $insertPayment = mysqli_query($con, "
                INSERT INTO tblpayments(CustomerID, BillingNumber, PaymentAmount, PaymentDate, PaymentMethod, ReferenceNumber, Comments)
                VALUES('$customerId', '$billingnum', '$paidNow', NOW(), '$modepayment', '$paymentReference', '$paymentComments')
            ");
            if (!$insertPayment) throw new Exception('Failed to insert payment record');
        }
        
        // 4. Update product stock
        $updateStock = mysqli_query($con, "
            UPDATE tblproducts p
            JOIN tblcreditcart c ON p.ID = c.ProductId
            SET p.Stock = p.Stock - c.ProductQty
            WHERE c.BillingId='$billingnum'
              AND c.IsCheckOut = 1
        ");
        if (!$updateStock) throw new Exception('Failed to update stock');
        
        // Commit transaction
        mysqli_commit($con);
        mysqli_autocommit($con, TRUE);
        
        // Variable pour le message de résultat
        $smsStatusMessage = "";
        
        // ENVOI SMS SIMPLIFIÉ (UN SEUL ENVOI)
        if ($sendSms) {
            // Message SMS
            if ($dues > 0) {
                $smsMessage = "Bonjour $custname, votre commande (Facture No: $billingnum) a été validée. Solde dû: " . number_format($dues, 0, ',', ' ') . " GNF. Merci.";
            } else {
                $smsMessage = "Bonjour $custname, votre commande (Facture No: $billingnum) a été validée avec succès. Merci pour votre confiance.";
            }

            // UN SEUL ENVOI - Pas de fallback pour éviter les doubles débits
            error_log("=== ENVOI SMS FACTURE $billingnum ===");
            $smsResult = sendSmsNotification($custmobile, $smsMessage);
            error_log("=== FIN ENVOI SMS ===");

            // Journal de l'envoi SMS (si la table existe)
            $tableExists = mysqli_query($con, "SHOW TABLES LIKE 'tbl_sms_logs'");
            if (mysqli_num_rows($tableExists) > 0) {
                $smsLogQuery = "INSERT INTO tbl_sms_logs (recipient, message, status, send_date) 
                               VALUES ('$custmobile', '" . mysqli_real_escape_string($con, $smsMessage) . "', " . 
                               ($smsResult ? '1' : '0') . ", NOW())";
                mysqli_query($con, $smsLogQuery);
            }
            
            // Message de statut simple
            if ($smsResult) {
                $smsStatusMessage = " - SMS envoyé ✅";
            } else {
                $smsStatusMessage = " - Échec SMS ❌";
            }
        } else {
            $smsStatusMessage = " - SMS non envoyé";
        }

        // Clear session variables
        unset($_SESSION['credit_discount']);
        unset($_SESSION['credit_discountType']);
        unset($_SESSION['credit_discountValue']);
        $_SESSION['invoiceid'] = $billingnum;

        // Prepare success message with payment info
        $paymentInfo = "";
        if ($paidNow > 0) {
            $paymentInfo = " - Paiement: " . number_format($paidNow, 0, ',', ' ') . " GNF";
        }

        // Afficher le message simple
        echo "<script>alert(" . json_encode("Facture créée: $billingnum$paymentInfo$smsStatusMessage") . "); window.location='invoice_dettecard.php?print=auto';</script>";
        exit;
        
    } catch (Exception $e) {
        // Rollback transaction on error
        mysqli_rollback($con);
        mysqli_autocommit($con, TRUE);
        die('Erreur lors de la création de la facture: ' . $e->getMessage() . ' - ' . mysqli_error($con));
    }
}

// Vérifier à nouveau les stocks pour l'affichage du panier
$cartProducts = mysqli_query($con, "
    SELECT c.ID, c.ProductId, c.ProductQty, p.Stock, p.ProductName 
    FROM tblcreditcart c
    JOIN tblproducts p ON p.ID = c.ProductId
    WHERE c.IsCheckOut=0
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
    <title>Système de Gestion d'Inventaire | Panier à Terme</title>
    <?php include_once('includes/cs.php'); ?>
    <?php include_once('includes/responsive.php'); ?>
    
    <!-- Style pour les problèmes de stock -->
    <style>
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
        
        .sms-option {
            background-color: #f8f9fa;
            border: 1px solid #dee2e6;
            border-radius: 4px;
            padding: 10px;
            margin: 10px 0;
        }
        
        .sms-option label {
            display: flex;
            align-items: center;
            margin-bottom: 0;
            font-weight: normal;
        }
        
        .sms-option input[type="checkbox"] {
            margin-right: 8px;
        }
        
        .sms-option .help-text {
            font-size: 12px;
            color: #6c757d;
            margin-left: 20px;
            margin-top: 5px;
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
                <a href="dashboard.php" class="tip-bottom">
                    <i class="icon-home"></i> Accueil
                </a>
                <a href="dettecart.php" class="current">Panier à Terme</a>
            </div>
            <h1>Panier à Terme (Vente à crédit possible)</h1>
        </div>
  
        <div class="container-fluid">
            <hr>
            
            <!-- Indicateur de panier utilisateur (visuel uniquement) -->
            <div class="user-cart-indicator">
                <i class="icon-user"></i> <strong>Panier à terme géré par: <?php echo htmlspecialchars($currentAdminName); ?></strong>
                <p class="text-muted small">Note: Tous les utilisateurs partagent ce panier. Pour des paniers séparés par utilisateur, contactez l'administrateur système.</p>
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
            
            <!-- ====================== FORMULAIRE DE RECHERCHE (avec datalist) ====================== -->
            <div class="row-fluid">
                <div class="span12">
                    <form method="get" action="dettecart.php" class="form-inline">
                        <label>Rechercher des Articles :</label>
                        <input type="text" name="searchTerm" class="span3"
                               placeholder="Nom du Article ou modèle..." list="productsList" />
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
  
            <!-- ====================== RÉSULTATS DE RECHERCHE ====================== -->
            <?php
            if (!empty($_GET['searchTerm'])) {
                $searchTerm = mysqli_real_escape_string($con, $_GET['searchTerm']);
                $sql = "
                    SELECT p.ID, p.ProductName, p.BrandName, p.ModelNumber, p.Price, p.Stock,
                           c.CategoryName, s.SubCategoryName
                    FROM tblproducts p
                    LEFT JOIN tblcategory c ON c.ID = p.CatID
                    LEFT JOIN tblsubcategory s ON s.ID = p.SubcatID
                    WHERE (p.ProductName LIKE '%$searchTerm%' OR p.ModelNumber LIKE '%$searchTerm%')
                ";
                $res = mysqli_query($con, $sql);
                $count = mysqli_num_rows($res);
                ?>
                <div class="row-fluid">
                    <div class="span12">
                        <h4>Résultats de recherche pour "<em><?php echo htmlentities($searchTerm); ?></em>"</h4>
                        <?php if ($count > 0) { ?>
                            <table class="table table-bordered table-striped">
                                <thead>
                                    <tr>
                                        <th>#</th>
                                        <th>Nom du Article</th>
                                        <th>Catégorie</th>
                                        <th>Sous-Catégorie</th>
                                        <th>Marque</th>
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
                                        <td><?php echo $row['SubCategoryName']; ?></td>
                                        <td><?php echo $row['BrandName']; ?></td>
                                        <td><?php echo $row['ModelNumber']; ?></td>
                                        <td><?php echo $row['Price']; ?></td>
                                        <td><?php echo $row['Stock'] . ' ' . $stockStatus; ?></td>
                                        <td>
                                            <form method="post" action="dettecart.php" style="margin:0;">
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
  
            <!-- ====================== AFFICHAGE DU PANIER + REMISE + CHECKOUT ====================== -->
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

                    <!-- FORMULAIRE DE CHECKOUT (informations client + montant payé) -->
                    <form method="post" class="form-horizontal" name="submit">
                        <div class="control-group">
                            <label class="control-label">Nom du Client :</label>
                            <div class="controls">
                                <input type="text" class="span11" name="customername" required />
                            </div>
                        </div>
                        <div class="control-group">
                            <label class="control-label">Numéro de Mobile :</label>
                            <div class="controls">
                                <input type="tel"
                                       class="span11"
                                       name="mobilenumber"
                                       required
                                       pattern="^(\+?224)?6[0-9]{8}$"
                                       placeholder="623XXXXXXXX ou +224623XXXXXXXX"
                                       title="Format: 623XXXXXXXX, 224623XXXXXXXX ou +224623XXXXXXXX">
                                <span class="help-inline">Formats acceptés: 623XXXXXXXX, 224623XXXXXXXX, +224623XXXXXXXX</span>
                            </div>
                        </div>
                        <div class="control-group">
                            <label class="control-label">Mode de Paiement :</label>
                            <div class="controls">
                                <label><input type="radio" name="modepayment" value="cash" checked> Espèces</label>
                                <label><input type="radio" name="modepayment" value="card"> Carte</label>
                                <label><input type="radio" name="modepayment" value="credit"> Crédit (Terme)</label>
                            </div>
                        </div>
                        <div class="control-group">
                            <label class="control-label">Montant Payé Maintenant :</label>
                            <div class="controls">
                                <input type="number" name="paid" step="any" value="0" class="span11" />
                                <p style="font-size: 12px; color: #666;">(Laissez 0 si rien n'est payé maintenant)</p>
                            </div>
                        </div>
                        
                        <!-- Option SMS simplifiée -->
                        <div class="control-group">
                            <label class="control-label">Notification SMS :</label>
                            <div class="controls">
                                <div class="sms-option">
                                    <label>
                                        <input type="checkbox" name="send_sms" value="1" checked>
                                        <i class="icon-comment"></i> Envoyer un SMS de confirmation au client
                                    </label>
                                    <div class="help-text">
                                        Le client recevra un SMS avec les détails de sa commande et le solde à payer si applicable.
                                    </div>
                                </div>
                            </div>
                        </div>
  
                        <div class="form-actions" style="text-align:center;">
                            <button class="btn btn-primary" type="submit" name="submit" <?php echo $hasStockIssue ? 'disabled' : ''; ?>>
                                <i class="icon-ok"></i> Valider & Créer la Facture
                            </button>
                        </div>
                    </form>
  
                    <!-- Tableau du panier -->
                    <div class="widget-box">
                        <div class="widget-title">
                            <span class="icon"><i class="icon-th"></i></span>
                            <h5>Articles dans le Panier</h5>
                        </div>
                        <div class="widget-content nopadding">
                            <table class="table table-bordered" style="font-size: 15px">
                                <thead>
                                    <tr>
                                        <th>#</th>
                                        <th>Nom du Article</th>
                                        <th>Quantité</th>
                                        <th>Stock</th>
                                        <th>Prix (unité)</th>
                                        <th>Total</th>
                                        <th>Action</th>
                                    </tr>
                                </thead>
                                <tbody>
                                    <?php
                                    $ret = mysqli_query($con, "
                                      SELECT 
                                        tblcreditcart.ID as cid,
                                        tblcreditcart.ProductQty,
                                        tblcreditcart.Price as cartPrice,
                                        tblproducts.ProductName,
                                        tblproducts.Stock
                                      FROM tblcreditcart
                                      LEFT JOIN tblproducts ON tblproducts.ID = tblcreditcart.ProductId
                                      WHERE tblcreditcart.IsCheckOut = 0
                                      ORDER BY tblcreditcart.ID ASC
                                    ");
                                    $cnt = 1;
                                    $grandTotal = 0;
                                    $num = mysqli_num_rows($ret);
                                    if ($num > 0) {
                                        while ($row = mysqli_fetch_array($ret)) {
                                            $pq    = $row['ProductQty'];
                                            $ppu   = $row['cartPrice'];
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
                                            ?>
                                            <tr <?php echo $rowClass; ?>>
                                                <td><?php echo $cnt; ?></td>
                                                <td><?php echo $row['ProductName']; ?></td>
                                                <td><?php echo $pq; ?></td>
                                                <td>
                                                    <?php echo $stock; ?>
                                                    <?php echo $stockStatus; ?>
                                                </td>
                                                <td><?php echo number_format($ppu, 2); ?></td>
                                                <td><?php echo number_format($lineTotal, 2); ?></td>
                                                <td>
                                                    <a href="dettecart.php?delid=<?php echo $row['cid']; ?>"
                                                       onclick="return confirm('Voulez-vous vraiment supprimer cet article ?');">
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
                                       <!-- Affichage de la remise dans le tableau des totaux -->
                                        <tr>
                                            <th colspan="5" style="text-align: right; font-weight: bold;">Total Général</th>
                                            <th colspan="2" style="text-align: center; font-weight: bold;"><?php echo number_format($grandTotal, 2); ?></th>
                                        </tr>
                                        <tr>
                                            <th colspan="5" style="text-align: right; font-weight: bold;">
                                                Remise
                                                <?php if ($discountType == 'percentage'): ?>
                                                    (<?php echo $discountValue; ?>%)
                                                <?php endif; ?>
                                            </th>
                                            <th colspan="2" style="text-align: center; font-weight: bold;"><?php echo number_format($discount, 2); ?></th>
                                        </tr>
                                        <tr>
                                            <th colspan="5" style="text-align: right; font-weight: bold; color: green;">Total Net</th>
                                            <th colspan="2" style="text-align: center; font-weight: bold; color: green;"><?php echo number_format($netTotal, 2); ?></th>
                                        </tr>
                                        <?php
                                    } else {
                                        ?>
                                        <tr>
                                            <td colspan="7" style="color:red; text-align:center;">Aucun article trouvé dans le panier</td>
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

    <!-- Script de validation du formulaire en temps réel -->
    <script>
    document.addEventListener('DOMContentLoaded', function() {
        // Validation du numéro de téléphone en temps réel
        const mobileInput = document.querySelector('input[name="mobilenumber"]');
        const nimbaFormats = /^(\+?224)?6[0-9]{8}$/;
        
        if (mobileInput) {
            mobileInput.addEventListener('input', function() {
                const value = this.value.replace(/[^0-9+]/g, ''); // Nettoyer
                this.value = value;
                
                if (value && !nimbaFormats.test(value)) {
                    this.style.borderColor = '#d9534f';
                    this.title = 'Format invalide. Utilisez: 623XXXXXXXX, 224623XXXXXXXX ou +224623XXXXXXXX';
                } else {
                    this.style.borderColor = '#28a745';
                    this.title = 'Format valide';
                }
            });
        }
        
        // Validation de la longueur du message SMS
        const form = document.querySelector('form[name="submit"]');
        if (form) {
            form.addEventListener('submit', function(e) {
                const customerName = document.querySelector('input[name="customername"]').value;
                const mobile = document.querySelector('input[name="mobilenumber"]').value;
                const sendSms = document.querySelector('input[name="send_sms"]').checked;
                
                if (sendSms) {
                    // Simuler le message SMS qui sera envoyé
                    const testMessage = `Bonjour ${customerName}, votre commande (Facture No: 1234) a été validée. Solde dû: 100 000 GNF. Merci.`;
                    
                    if (testMessage.length > 665) {
                        alert('Le message SMS généré sera trop long (' + testMessage.length + ' caractères). Limite: 665 caractères. Raccourcissez le nom du client.');
                        e.preventDefault();
                        return false;
                    }
                    
                    // Vérifier le format du numéro avant envoi
                    if (!nimbaFormats.test(mobile.replace(/[^0-9+]/g, ''))) {
                        alert('Format de numéro invalide. Utilisez: 623XXXXXXXX, 224623XXXXXXXX ou +224623XXXXXXXX');
                        e.preventDefault();
                        return false;
                    }
                }
            });
        }
    });
    </script>
</body>
</html>