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
 * Obtenir un access token OAuth2 de Nimba using cURL.
 */
function getAccessToken() {
    $url = "https://api.nimbasms.com/v1/oauth/token";  // Verify this URL with your Nimba documentation.
    
    // Replace with your real credentials
    $client_id     = "1608e90e20415c7edf0226bf86e7effd";      
    $client_secret = "kokICa68N6NJESoJt09IAFXjO05tYwdVV-Xjrql7o8pTi29ssdPJyNgPBdRIeLx6_690b_wzM27foyDRpvmHztN7ep6ICm36CgNggEzGxRs";
    
    // Encode the credentials in Base64 ("client_id:client_secret")
    $credentials = base64_encode($client_id . ":" . $client_secret);
    
    $headers = array(
        "Authorization: Basic " . $credentials,
        "Content-Type: application/x-www-form-urlencoded"
    );
    
    $postData = http_build_query(array(
        "grant_type" => "client_credentials"
    ));
    
    // Use cURL for the POST request
    $ch = curl_init();
    curl_setopt($ch, CURLOPT_URL, $url);
    curl_setopt($ch, CURLOPT_POST, true);
    curl_setopt($ch, CURLOPT_HTTPHEADER, $headers);
    curl_setopt($ch, CURLOPT_POSTFIELDS, $postData);
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, false);  // For development only!
    $response = curl_exec($ch);
    $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    
    if ($response === FALSE) {
        $error = curl_error($ch);
        error_log("cURL error while obtaining token: " . $error);
        curl_close($ch);
        return false;
    }
    curl_close($ch);
    
    if ($httpCode != 200) {
        error_log("Error obtaining access token. HTTP Code: $httpCode. Response: $response");
        return false;
    }
    
    $decoded = json_decode($response, true);
    if (!isset($decoded['access_token'])) {
        error_log("API error (token): " . print_r($decoded, true));
        return false;
    }
    return $decoded['access_token'];
}

/**
 * Function to send an SMS via the Nimba API.
 * The message content is passed via the $message parameter.
 * The payload sent is logged so you can verify the SMS content.
 */
function sendSmsNotification($to, $message) {
    // Nimba API endpoint for sending SMS
    $url = "https://api.nimbasms.com/v1/messages";
    
    // Replace with your actual service credentials (as provided by Nimba)
    $service_id    = "1608e90e20415c7edf0226bf86e7effd";    
    $secret_token  = "kokICa68N6NJESoJt09IAFXjO05tYwdVV-Xjrql7o8pTi29ssdPJyNgPBdRIeLx6_690b_wzM27foyDRpvmHztN7ep6ICm36CgNggEzGxRs";
    
    // Build the Basic Auth string (Base64 of "service_id:secret_token")
    $authString = base64_encode($service_id . ":" . $secret_token);
    
    // Prepare the JSON payload with recipient, message and sender_name
    $payload = array(
        "to"          => array($to),
        "message"     => $message,
        "sender_name" => "SMS 9080"   // Replace with your approved sender name with Nimba
    );
    $postData = json_encode($payload);
    
    // Log the payload for debugging (check your server error logs)
    error_log("Nimba SMS Payload: " . $postData);
    
    $headers = array(
        "Authorization: Basic " . $authString,
        "Content-Type: application/json"
    );
    
    $options = array(
        "http" => array(
            "method"        => "POST",
            "header"        => implode("\r\n", $headers),
            "content"       => $postData,
            "ignore_errors" => true
        )
    );
    
    $context = stream_context_create($options);
    $response = file_get_contents($url, false, $context);
    
    // Log complete API response for debugging
    error_log("Nimba API SMS Response: " . $response);
    
    // Retrieve HTTP status code from response headers
    $http_response_header = isset($http_response_header) ? $http_response_header : array();
    if (empty($http_response_header)) {
        error_log("No HTTP response headers - SMS send failed");
        return false;
    }
    
    $status_line = $http_response_header[0];
    preg_match('{HTTP\/\S*\s(\d{3})}', $status_line, $match);
    $status_code = isset($match[1]) ? $match[1] : 0;
    
    if ($status_code != 201) {
        error_log("SMS send failed. HTTP Code: $status_code. Details: " . print_r(json_decode($response, true), true));
        return false;
    }
    
    return true;
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
            echo "<script>alert('Article \"" . htmlspecialchars($row['ProductName']) . "\" en rupture de stock.'); window.location='dettecart.php';</script>";
            exit;
        }
        
        // Vérification que la quantité demandée est disponible
        if ($row['Stock'] < $quantity) {
            echo "<script>alert('Stock insuffisant pour \"" . htmlspecialchars($row['ProductName']) . "\". Stock disponible: " . $row['Stock'] . "'); window.location='dettecart.php';</script>";
            exit;
        }
    } else {
        echo "<script>alert('Article introuvable.'); window.location='dettecart.php';</script>";
        exit;
    }

    // Vérifier si l'article est déjà dans le panier
    $existCheck = mysqli_query($con, "SELECT ID, ProductQty FROM tblcreditcart WHERE ProductId='$productId' AND IsCheckOut=0 LIMIT 1");
    if (mysqli_num_rows($existCheck) > 0) {
        $c = mysqli_fetch_assoc($existCheck);
        $newQty = $c['ProductQty'] + $quantity;
        // Vérification que la nouvelle quantité totale ne dépasse pas le stock disponible
        if ($newQty > $row['Stock']) {
            echo "<script>alert('Quantité totale demandée (" . $newQty . ") supérieure au stock disponible (" . $row['Stock'] . ") pour \"" . htmlspecialchars($row['ProductName']) . "\".'); window.location='dettecart.php';</script>";
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

// Checkout + Facturation
if (isset($_POST['submit'])) {
    $custname = mysqli_real_escape_string($con, trim($_POST['customername']));
    $custmobile = preg_replace('/[^0-9+]/', '', $_POST['mobilenumber']);
    $modepayment = mysqli_real_escape_string($con, $_POST['modepayment']);
    $paidNow = max(0, floatval($_POST['paid']));

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
        $errorMsg = "Impossible de finaliser la commande:\n- " . implode("\n- ", $stockErrors);
        echo "<script>alert(" . json_encode($errorMsg) . "); window.location='dettecart.php';</script>";
        exit;
    }

    $billingnum = mt_rand(100000000, 999999999);

    // Validation du panier + Création facture
    $queries = "
        UPDATE tblcreditcart SET BillingId='$billingnum', IsCheckOut=1 WHERE IsCheckOut=0;
        INSERT INTO tblcustomer(BillingNumber, CustomerName, MobileNumber, ModeOfPayment, BillingDate, FinalAmount, Paid, Dues)
        VALUES('$billingnum', '$custname', '$custmobile', '$modepayment', NOW(), '$netTotal', '$paidNow', '$dues');
    ";
    if (mysqli_multi_query($con, $queries)) {
        while (mysqli_more_results($con) && mysqli_next_result($con)) {}

        // Décrémentation du stock
        mysqli_query($con, "
            UPDATE tblproducts p
            JOIN tblcreditcart c ON p.ID = c.ProductId
            SET p.Stock = p.Stock - c.ProductQty
            WHERE c.BillingId='$billingnum'
              AND c.IsCheckOut = 1
        ") or die(mysqli_error($con));

        // SMS personnalisé avec vérification du statut d'envoi
        if ($dues > 0) {
            $smsMessage = "Bonjour $custname, votre commande est enregistrée. Solde dû: " . number_format($dues, 0, ',', ' ') . " GNF.";
        } else {
            $smsMessage = "Bonjour $custname, votre commande est confirmée. Merci pour votre confiance !";
        }

        // Envoyer le SMS et stocker le résultat (true/false)
        $smsResult = sendSmsNotification($custmobile, $smsMessage);

        // Journal de l'envoi SMS (si la table existe)
        $tableExists = mysqli_query($con, "SHOW TABLES LIKE 'tbl_sms_logs'");
        if (mysqli_num_rows($tableExists) > 0) {
            $smsLogQuery = "INSERT INTO tbl_sms_logs (recipient, message, status, send_date) 
                           VALUES ('$custmobile', '" . mysqli_real_escape_string($con, $smsMessage) . "', " . 
                           ($smsResult ? '1' : '0') . ", NOW())";
            mysqli_query($con, $smsLogQuery);
        }

        unset($_SESSION['credit_discount']);
        unset($_SESSION['credit_discountType']);
        unset($_SESSION['credit_discountValue']);
        $_SESSION['invoiceid'] = $billingnum;

        // Afficher le statut de l'envoi SMS dans le message d'alerte
        if ($smsResult) {
            echo "<script>alert('Facture créée: $billingnum - SMS envoyé avec succès'); window.location='invoice_dettecard.php?print=auto';</script>";
        } else {
            echo "<script>alert('Facture créée: $billingnum - ÉCHEC de l\'envoi du SMS'); window.location='invoice_dettecard.php?print=auto';</script>";
        }
        exit;
    } else {
        die('Erreur SQL : ' . mysqli_error($con));
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
                                <!-- Validation pour le format guinéen : +224 suivi de 9 chiffres -->
                                <input type="tel"
                                       class="span11"
                                       name="mobilenumber"
                                       required
                                       pattern="^\+224[0-9]{9}$"
                                       placeholder="+224-XXXXXXXXX"
                                       title="Format: +224 suivi de 9 chiffres">
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
  
                        <div class="form-actions" style="text-align:center;">
                            <button class="btn btn-primary" type="submit" name="submit" <?php echo $hasStockIssue ? 'disabled' : ''; ?>>
                                Valider & Créer la Facture
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
                                                $stockStatus = '<span class="stock-warning">INSUFFISANT</span>';}
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
    </body>
    </html>