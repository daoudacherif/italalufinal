<?php 
session_start();
error_reporting(E_ALL);
include('includes/dbconnection.php');

// Vérifie que l'admin est connecté
if (empty($_SESSION['imsaid'])) {
    header('Location: logout.php');
    exit;
}

// Get the current admin ID from session
$currentAdminID = $_SESSION['imsaid'];

// Get the current admin name
$adminQuery = mysqli_query($con, "SELECT AdminName FROM tbladmin WHERE ID = '$currentAdminID'");
$adminData = mysqli_fetch_assoc($adminQuery);
$currentAdminName = $adminData['AdminName'];

/**
 * Fonction pour calculer la date d'échéance
 */
function calculateEcheanceDate($typeEcheance, $nombreJours = 0) {
    switch ($typeEcheance) {
        case 'immediat':
            return date('Y-m-d');
        case '7_jours':
            return date('Y-m-d', strtotime('+7 days'));
        case '15_jours':
            return date('Y-m-d', strtotime('+15 days'));
        case '30_jours':
            return date('Y-m-d', strtotime('+30 days'));
        case '60_jours':
            return date('Y-m-d', strtotime('+60 days'));
        case '90_jours':
            return date('Y-m-d', strtotime('+90 days'));
        case 'personnalise':
            return date('Y-m-d', strtotime("+$nombreJours days"));
        default:
            return date('Y-m-d');
    }
}

/**
 * FONCTION SMS SIMPLIFIÉE
 */
function sendSmsNotification($to, $message) {
    // Validation des paramètres d'entrée
    if (empty($to) || empty($message)) {
        error_log("Nimba SMS Error: Numéro ou message vide");
        return false;
    }
    
    // Nettoyage et validation du numéro
    $to = trim($to);
    $to = preg_replace('/[^0-9+]/', '', $to);
    
    if (!preg_match('/^(\+?224)?6[0-9]{8}$/', $to)) {
        error_log("Nimba SMS Error: Format de numéro invalide: $to");
        return false;
    }
    
    // Validation longueur message
    if (strlen($message) > 665) {
        error_log("Nimba SMS Error: Message trop long (" . strlen($message) . " caractères)");
        return false;
    }
    
    // Configuration API Nimba
    $url = "https://api.nimbasms.com/v1/messages";
    $service_id = "0b0aa04ddcf33f25a796fc8aac76b66e";
    $secret_token = "Lt-PsM_2LdTPZPtkCmL5DXHiRJVcJRlj8p5nTxQap9iPJoknVoyXGR8uv-wT6aVEErBgJBRoqPbp8cHyKGzqgSw3CkC_ypLH4u8SAV3NjH8";
    $sender_name = "SMS 9080";
    
    $authString = base64_encode($service_id . ":" . $secret_token);
    
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
    
    // Envoi avec cURL
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

/**
 * Create or get customer master ID for billing
 */
function createOrGetCustomerMaster($con, $customerName, $mobileNumber, $email = null, $address = null) {
    $cleanMobile = preg_replace('/[^0-9+]/', '', $mobileNumber);
    $cleanName = mysqli_real_escape_string($con, trim($customerName));
    $cleanEmail = !empty($email) ? mysqli_real_escape_string($con, trim($email)) : null;
    $cleanAddress = !empty($address) ? mysqli_real_escape_string($con, trim($address)) : null;
    
    // Check if customer already exists in master table
    $checkQuery = mysqli_query($con, "SELECT id FROM tblcustomer_master WHERE CustomerContact = '$cleanMobile' LIMIT 1");
    
    if (mysqli_num_rows($checkQuery) > 0) {
        $customer = mysqli_fetch_assoc($checkQuery);
        
        // Update last purchase date and info if provided
        $updateQuery = "UPDATE tblcustomer_master SET LastPurchaseDate = NOW()";
        if ($cleanEmail) {
            $updateQuery .= ", CustomerEmail = '$cleanEmail'";
        }
        if ($cleanAddress) {
            $updateQuery .= ", CustomerAddress = '$cleanAddress'";
        }
        $updateQuery .= " WHERE id = '{$customer['id']}'";
        mysqli_query($con, $updateQuery);
        
        return $customer['id'];
    } else {
        // Create new customer
        $insertQuery = "INSERT INTO tblcustomer_master (CustomerName, CustomerContact, CustomerEmail, CustomerAddress, CustomerRegdate, LastPurchaseDate) VALUES ('$cleanName', '$cleanMobile', " . ($cleanEmail ? "'$cleanEmail'" : "NULL") . ", " . ($cleanAddress ? "'$cleanAddress'" : "NULL") . ", NOW(), NOW())";
        
        if (mysqli_query($con, $insertQuery)) {
            return mysqli_insert_id($con);
        } else {
            error_log("Failed to create customer master record: " . mysqli_error($con));
            return null;
        }
    }
}

/**
 * Update customer master statistics
 */
function updateCustomerMasterStats($con, $customerMasterId) {
    $updateQuery = "UPDATE tblcustomer_master cm SET TotalPurchases = COALESCE((SELECT SUM(FinalAmount) FROM tblcustomer WHERE customer_master_id = cm.id), 0), TotalDues = COALESCE((SELECT SUM(Dues) FROM tblcustomer WHERE customer_master_id = cm.id), 0), LastPurchaseDate = COALESCE((SELECT MAX(BillingDate) FROM tblcustomer WHERE customer_master_id = cm.id), cm.LastPurchaseDate) WHERE cm.id = '$customerMasterId'";
    mysqli_query($con, $updateQuery);
}

// ----------- Gestion Panier -----------

// Ajout au panier AVEC échéances
if (isset($_POST['addtocart'])) {
    $productId = intval($_POST['productid']);
    $quantity = max(1, intval($_POST['quantity']));
    $price = max(0, floatval($_POST['price']));
    $typeEcheance = mysqli_real_escape_string($con, $_POST['type_echeance']);
    $nombreJours = intval($_POST['nombre_jours']);
    
    // Calculer la date d'échéance
    $dateEcheance = calculateEcheanceDate($typeEcheance, $nombreJours);

    // Vérifier le stock disponible
    $stockCheck = mysqli_query($con, "SELECT Stock, ProductName FROM tblproducts WHERE ID='$productId'");
    if ($row = mysqli_fetch_assoc($stockCheck)) {
        if ($row['Stock'] <= 0) {
            echo "<script>alert(" . json_encode("Article \"{$row['ProductName']}\" en rupture de stock.") . "); window.location='dettecart.php';</script>";
            exit;
        }
        
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
        if ($newQty > $row['Stock']) {
            echo "<script>alert(" . json_encode("Quantité totale demandée ($newQty) supérieure au stock disponible ({$row['Stock']}) pour \"{$row['ProductName']}\".") . "); window.location='dettecart.php';</script>";
            exit;
        }
        // Mettre à jour avec les nouvelles informations d'échéance
        mysqli_query($con, "UPDATE tblcreditcart SET ProductQty='$newQty', Price='$price', TypeEcheance='$typeEcheance', NombreJours='$nombreJours', DateEcheance='$dateEcheance' WHERE ID='{$c['ID']}'") or die(mysqli_error($con));
    } else {
        // Insérer avec les informations d'échéance
        mysqli_query($con, "INSERT INTO tblcreditcart(ProductId, ProductQty, Price, IsCheckOut, TypeEcheance, NombreJours, DateEcheance, AdminID) VALUES('$productId', '$quantity', '$price', 0, '$typeEcheance', '$nombreJours', '$dateEcheance', '$currentAdminID')") or die(mysqli_error($con));
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

// Appliquer une remise
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
        $discountValue = min(100, $discountValue);
        $actualDiscount = ($discountValue / 100) * $grandTotal;
    } else {
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

// Récupérer la liste des noms de produits pour la datalist
$productNames = [];
$productQuery = mysqli_query($con, "SELECT ProductName FROM tblproducts ORDER BY ProductName");
while ($row = mysqli_fetch_assoc($productQuery)) {
    $productNames[] = $row['ProductName'];
}

// Récupérer la liste des clients existants
$existingCustomers = [];
$customerQuery = mysqli_query($con, "SELECT id, CustomerName, CustomerContact, CustomerEmail FROM tblcustomer_master WHERE Status = 'active' ORDER BY CustomerName");
while ($row = mysqli_fetch_assoc($customerQuery)) {
    $existingCustomers[] = $row;
}

// Checkout + Facturation avec échéances
if (isset($_POST['submit'])) {
    $customerType = $_POST['customer_type'];
    $custname = '';
    $custmobile = '';
    $custemail = '';
    $custaddress = '';
    $customerMasterId = null;
    
    if ($customerType === 'existing') {
        $customerMasterId = intval($_POST['existing_customer']);
        
        $customerInfo = mysqli_query($con, "SELECT CustomerName, CustomerContact, CustomerEmail, CustomerAddress FROM tblcustomer_master WHERE id='$customerMasterId'");
        if ($customerData = mysqli_fetch_assoc($customerInfo)) {
            $custname = $customerData['CustomerName'];
            $custmobile = $customerData['CustomerContact'];
            $custemail = $customerData['CustomerEmail'];
            $custaddress = $customerData['CustomerAddress'];
        } else {
            echo "<script>alert('Client sélectionné introuvable'); window.location='dettecart.php';</script>";
            exit;
        }
    } else {
        $custname = mysqli_real_escape_string($con, trim($_POST['customername']));
        $custmobile = preg_replace('/[^0-9+]/', '', $_POST['mobilenumber']);
        $custemail = mysqli_real_escape_string($con, trim($_POST['customeremail']));
        $custaddress = mysqli_real_escape_string($con, trim($_POST['customeraddress']));
        
        if (empty($custname) || empty($custmobile)) {
            echo "<script>alert('Le nom et le numéro de téléphone sont obligatoires'); window.location='dettecart.php';</script>";
            exit;
        }
        
        if (!preg_match('/^(\+?224)?6[0-9]{8}$/', $custmobile)) {
            echo "<script>alert('Format de numéro invalide'); window.location='dettecart.php';</script>";
            exit;
        }
        
        $customerMasterId = createOrGetCustomerMaster($con, $custname, $custmobile, $custemail, $custaddress);
        
        if (!$customerMasterId) {
            echo "<script>alert('Erreur lors de la création du client'); window.location='dettecart.php';</script>";
            exit;
        }
    }
    
    $modepayment = mysqli_real_escape_string($con, $_POST['modepayment']);
    $paidNow = max(0, floatval($_POST['paid']));
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
    $stockCheck = mysqli_query($con, "SELECT p.ProductName, p.Stock, c.ProductQty FROM tblcreditcart c JOIN tblproducts p ON p.ID = c.ProductId WHERE c.IsCheckOut=0");
    
    $stockErrors = [];
    while ($row = mysqli_fetch_assoc($stockCheck)) {
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

    // Start transaction
    mysqli_autocommit($con, FALSE);
    
    try {
        // 1. Update cart with billing number et mettre à jour le statut des échéances
        $updateCart = mysqli_query($con, "UPDATE tblcreditcart SET BillingId='$billingnum', IsCheckOut=1, StatutEcheance='en_cours' WHERE IsCheckOut=0");
        if (!$updateCart) throw new Exception('Failed to update cart');
        
        // 2. Insert customer billing record
        $insertCustomerQuery = "INSERT INTO tblcustomer(BillingNumber, CustomerName, MobileNumber, ModeOfPayment, BillingDate, FinalAmount, Paid, Dues, customer_master_id) VALUES('$billingnum', '$custname', '$custmobile', '$modepayment', NOW(), '$netTotal', '$paidNow', '$dues', '$customerMasterId')";
        
        $insertCustomer = mysqli_query($con, $insertCustomerQuery);
        if (!$insertCustomer) throw new Exception('Failed to insert customer record');
        
        $billingRecordId = mysqli_insert_id($con);
        
        // 3. Insert payment record if payment was made
        if ($paidNow > 0) {
            $paymentReference = "INV-$billingnum-INITIAL";
            $paymentComments = "Paiement initial lors de la facturation";
            
            $insertPayment = mysqli_query($con, "INSERT INTO tblpayments(CustomerID, BillingNumber, PaymentAmount, PaymentDate, PaymentMethod, ReferenceNumber, Comments) VALUES('$billingRecordId', '$billingnum', '$paidNow', NOW(), '$modepayment', '$paymentReference', '$paymentComments')");
            if (!$insertPayment) throw new Exception('Failed to insert payment record');
        }
        
        // 4. Update product stock
        $updateStock = mysqli_query($con, "UPDATE tblproducts p JOIN tblcreditcart c ON p.ID = c.ProductId SET p.Stock = p.Stock - c.ProductQty WHERE c.BillingId='$billingnum' AND c.IsCheckOut = 1");
        if (!$updateStock) throw new Exception('Failed to update stock');
        
        // 5. Update customer master statistics
        updateCustomerMasterStats($con, $customerMasterId);
        
        // Commit transaction
        mysqli_commit($con);
        mysqli_autocommit($con, TRUE);
        
        $smsStatusMessage = "";
        
        // SMS avec informations sur les échéances
        if ($sendSms) {
            // Récupérer les informations d'échéance du panier
            $echeanceInfo = mysqli_query($con, "SELECT DateEcheance, TypeEcheance FROM tblcreditcart WHERE BillingId='$billingnum' LIMIT 1");
            $echeanceData = mysqli_fetch_assoc($echeanceInfo);
            
            if ($dues > 0 && $echeanceData['DateEcheance']) {
                $dateEcheanceFr = date('d/m/Y', strtotime($echeanceData['DateEcheance']));
                $smsMessage = "Bonjour $custname, votre commande (Facture No: $billingnum) a été validée. Solde dû: " . number_format($dues, 0, ',', ' ') . " GNF. Échéance: $dateEcheanceFr. Merci.";
            } else {
                $smsMessage = "Bonjour $custname, votre commande (Facture No: $billingnum) a été validée avec succès. Merci pour votre confiance.";
            }

            $smsResult = sendSmsNotification($custmobile, $smsMessage);
            
            // Log SMS
            $tableExists = mysqli_query($con, "SHOW TABLES LIKE 'tbl_sms_logs'");
            if (mysqli_num_rows($tableExists) > 0) {
                $smsLogQuery = "INSERT INTO tbl_sms_logs (recipient, message, status, send_date) VALUES ('$custmobile', '" . mysqli_real_escape_string($con, $smsMessage) . "', " . ($smsResult ? '1' : '0') . ", NOW())";
                mysqli_query($con, $smsLogQuery);
            }
            
            $smsStatusMessage = $smsResult ? " - SMS envoyé ✅" : " - Échec SMS ❌";
        } else {
            $smsStatusMessage = " - SMS non envoyé";
        }

        // Clear session variables
        unset($_SESSION['credit_discount']);
        unset($_SESSION['credit_discountType']);
        unset($_SESSION['credit_discountValue']);
        $_SESSION['invoiceid'] = $billingnum;

        $paymentInfo = "";
        if ($paidNow > 0) {
            $paymentInfo = " - Paiement: " . number_format($paidNow, 0, ',', ' ') . " GNF";
        }

        echo "<script>alert(" . json_encode("Facture créée: $billingnum - Échéances configurées$paymentInfo$smsStatusMessage") . "); window.location='invoice_dettecard.php?print=auto';</script>";
        exit;
        
    } catch (Exception $e) {
        mysqli_rollback($con);
        mysqli_autocommit($con, TRUE);
        die('Erreur lors de la création de la facture: ' . $e->getMessage() . ' - ' . mysqli_error($con));
    }
}

// Vérifier les stocks pour l'affichage
$cartProducts = mysqli_query($con, "SELECT c.ID, c.ProductId, c.ProductQty, p.Stock, p.ProductName FROM tblcreditcart c JOIN tblproducts p ON p.ID = c.ProductId WHERE c.IsCheckOut=0");

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
    <title>Système de Gestion d'Inventaire | Panier à Terme avec Échéances</title>
    <?php include_once('includes/cs.php'); ?>
    <?php include_once('includes/responsive.php'); ?>
    
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
        
        .customer-selection {
            background-color: #f8f9fa;
            border: 1px solid #dee2e6;
            border-radius: 4px;
            padding: 15px;
            margin-bottom: 15px;
        }
        
        .customer-type-radio {
            margin-bottom: 15px;
        }
        
        .customer-type-radio label {
            margin-right: 20px;
            font-weight: bold;
        }
        
        .customer-form-section {
            display: none;
            border-top: 1px solid #dee2e6;
            padding-top: 15px;
            margin-top: 10px;
        }
        
        .customer-form-section.active {
            display: block;
        }
        
        .customer-info-display {
            background-color: #e7f3ff;
            padding: 10px;
            border-radius: 4px;
            margin-top: 10px;
        }
        
        .manage-customers-link {
            background-color: #d4edda;
            border: 1px solid #c3e6cb;
            border-radius: 4px;
            padding: 10px;
            margin-bottom: 15px;
            text-align: center;
        }
        
        .manage-customers-link a {
            color: #155724;
            text-decoration: none;
            font-weight: bold;
        }
        
        .manage-customers-link a:hover {
            text-decoration: underline;
        }
        
        /* Styles pour les échéances */
        .echeance-selection {
            background-color: #fff8dc;
            border: 1px solid #f5deb3;
            border-radius: 4px;
            padding: 15px;
            margin-bottom: 15px;
        }
        
        .echeance-types {
            display: flex;
            flex-wrap: wrap;
            gap: 10px;
            margin-bottom: 15px;
        }
        
        .echeance-type {
            flex: 1;
            min-width: 120px;
        }
        
        .echeance-type label {
            display: block;
            padding: 8px 12px;
            border: 1px solid #ddd;
            border-radius: 4px;
            background-color: #f9f9f9;
            cursor: pointer;
            text-align: center;
            font-size: 12px;
        }
        
        .echeance-type input[type="radio"] {
            display: none;
        }
        
        .echeance-type input[type="radio"]:checked + label {
            background-color: #007bff;
            color: white;
            border-color: #007bff;
        }
        
        .echeance-custom {
            display: none;
            margin-top: 10px;
        }
        
        .echeance-custom.active {
            display: block;
        }
        
        .echeance-info {
            background-color: #e7f3ff;
            border: 1px solid #bee5eb;
            border-radius: 4px;
            padding: 10px;
            margin-top: 10px;
            font-size: 12px;
        }
        
        .echeance-badge {
            background-color: #17a2b8;
            color: white;
            padding: 2px 6px;
            border-radius: 3px;
            font-size: 11px;
        }
        
        .echeance-date {
            font-weight: bold;
            color: #28a745;
        }
    </style>
</head>
<body>
    <?php include_once('includes/header.php'); ?>
    <?php include_once('includes/sidebar.php'); ?>
  
    <div id="content">
        <div id="content-header">
            <div id="breadcrumb">
                <a href="dashboard.php" class="tip-bottom">
                    <i class="icon-home"></i> Accueil
                </a>
                <a href="dettecart.php" class="current">Panier à Terme avec Échéances</a>
            </div>
            <h1>Panier à Terme avec Système d'Échéances</h1>
        </div>
  
        <div class="container-fluid">
            <hr>
            
            <!-- Lien vers la gestion des clients -->
            <div class="manage-customers-link">
                <i class="icon-user"></i>
                <a href="add_customer_master.php" target="_blank">
                    Gérer le Répertoire Client (Ajouter, Modifier, Supprimer)
                </a>
                - Ouvrir dans un nouvel onglet
            </div>
            
            <!-- Indicateur de panier utilisateur -->
            <div class="user-cart-indicator">
                <i class="icon-user"></i> <strong>Panier géré par: <?php echo htmlspecialchars($currentAdminName); ?></strong>
                <p class="text-muted small">Système d'échéances intégré pour la gestion des créances</p>
            </div>
            
            <?php if ($hasStockIssue): ?>
            <div class="global-warning">
                <strong><i class="icon-warning-sign"></i> Attention !</strong> Problèmes de stock détectés :
                <ul>
                    <?php foreach($stockIssueProducts as $product): ?>
                    <li><?php echo htmlspecialchars($product); ?></li>
                    <?php endforeach; ?>
                </ul>
            </div>
            
            <script>
                document.addEventListener('DOMContentLoaded', function() {
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
            
            <!-- Formulaire de recherche -->
            <div class="row-fluid">
                <div class="span12">
                    <form method="get" action="dettecart.php" class="form-inline">
                        <label>Rechercher des Articles :</label>
                        <input type="text" name="searchTerm" class="span3" placeholder="Nom du produit..." list="productsList" />
                        <datalist id="productsList">
                            <?php foreach ($productNames as $pname): ?>
                            <option value="<?php echo htmlspecialchars($pname); ?>"></option>
                            <?php endforeach; ?>
                        </datalist>
                        <button type="submit" class="btn btn-primary">Rechercher</button>
                    </form>
                </div>
            </div>
            <hr>
  
            <!-- Résultats de recherche -->
            <?php if (!empty($_GET['searchTerm'])): 
                $searchTerm = mysqli_real_escape_string($con, $_GET['searchTerm']);
                $sql = "SELECT p.ID, p.ProductName, p.BrandName, p.ModelNumber, p.Price, p.Stock, c.CategoryName, s.SubCategoryName FROM tblproducts p LEFT JOIN tblcategory c ON c.ID = p.CatID LEFT JOIN tblsubcategory s ON s.ID = p.SubcatID WHERE (p.ProductName LIKE '%$searchTerm%' OR p.ModelNumber LIKE '%$searchTerm%')";
                $res = mysqli_query($con, $sql);
                $count = mysqli_num_rows($res);
            ?>
            <div class="row-fluid">
                <div class="span12">
                    <h4>Résultats de recherche pour "<em><?php echo htmlentities($searchTerm); ?></em>"</h4>
                    <?php if ($count > 0): ?>
                        <table class="table table-bordered table-striped">
                            <thead>
                                <tr>
                                    <th>#</th>
                                    <th>Nom du Produit</th>
                                    <th>Catégorie</th>
                                    <th>Marque</th>
                                    <th>Prix</th>
                                    <th>Stock</th>
                                    <th>Échéance</th>
                                    <th>Prix/Qté</th>
                                    <th>Ajouter</th>
                                </tr>
                            </thead>
                            <tbody>
                            <?php 
                            $i = 1;
                            while ($row = mysqli_fetch_assoc($res)): 
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
                                    <td><?php echo $row['BrandName']; ?></td>
                                    <td><?php echo $row['Price']; ?></td>
                                    <td><?php echo $row['Stock'] . ' ' . $stockStatus; ?></td>
                                    <td>
                                        <form method="post" action="dettecart.php" style="margin:0;">
                                            <input type="hidden" name="productid" value="<?php echo $row['ID']; ?>" />
                                            
                                            <!-- Sélection type d'échéance -->
                                            <select name="type_echeance" class="echeance-select" style="width: 100px; font-size: 11px;">
                                                <option value="immediat">Immédiat</option>
                                                <option value="7_jours">7 jours</option>
                                                <option value="15_jours">15 jours</option>
                                                <option value="30_jours" selected>30 jours</option>
                                                <option value="60_jours">60 jours</option>
                                                <option value="90_jours">90 jours</option>
                                                <option value="personnalise">Personnalisé</option>
                                            </select>
                                            
                                            <!-- Nombre de jours personnalisé -->
                                            <input type="number" name="nombre_jours" min="1" max="365" value="30" style="width: 50px; font-size: 11px; display: none;" class="jours-custom" />
                                    </td>
                                    <td>
                                        <input type="number" name="price" step="any" value="<?php echo $row['Price']; ?>" style="width:60px; font-size: 11px;" />
                                        <input type="number" name="quantity" value="1" min="1" max="<?php echo $row['Stock']; ?>" style="width:40px; font-size: 11px;" <?php echo $disableAdd ? 'disabled' : ''; ?> />
                                    </td>
                                    <td>
                                        <button type="submit" name="addtocart" class="btn btn-success btn-mini" <?php echo $disableAdd ? 'disabled' : ''; ?>>
                                            <i class="icon-plus"></i>
                                        </button>
                                        </form>
                                    </td>
                                </tr>
                            <?php endwhile; ?>
                            </tbody>
                        </table>
                    <?php else: ?>
                        <p style="color:red;">Aucun produit trouvé.</p>
                    <?php endif; ?>
                </div>
            </div>
            <hr>
            <?php endif; ?>
  
            <!-- Panier + Checkout -->
            <div class="row-fluid">
                <div class="span12">
                    <!-- Formulaire de remise -->
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

                    <!-- Formulaire de checkout -->
                    <form method="post" class="form-horizontal" name="submit">
                        
                        <!-- Sélection du client -->
                        <div class="customer-selection">
                            <h4><i class="icon-user"></i> Informations Client</h4>
                            
                            <div class="customer-type-radio">
                                <label><input type="radio" name="customer_type" value="existing" id="existing_customer_radio"> Client Existant</label>
                                <label><input type="radio" name="customer_type" value="new" id="new_customer_radio" checked> Nouveau Client</label>
                            </div>
                            
                            <!-- Section client existant -->
                            <div class="customer-form-section" id="existing_customer_section">
                                <div class="control-group">
                                    <label class="control-label">Sélectionner le Client :</label>
                                    <div class="controls">
                                        <select name="existing_customer" id="customer_select" class="span6">
                                            <option value="">-- Choisir un client --</option>
                                            <?php foreach ($existingCustomers as $customer): ?>
                                                <option value="<?php echo $customer['id']; ?>" 
                                                        data-name="<?php echo htmlspecialchars($customer['CustomerName']); ?>"
                                                        data-contact="<?php echo htmlspecialchars($customer['CustomerContact']); ?>"
                                                        data-email="<?php echo htmlspecialchars($customer['CustomerEmail']); ?>">
                                                    <?php echo htmlspecialchars($customer['CustomerName']); ?> 
                                                    (<?php echo htmlspecialchars($customer['CustomerContact']); ?>)
                                                </option>
                                            <?php endforeach; ?>
                                        </select>
                                    </div>
                                </div>
                                
                                <div class="customer-info-display" id="customer_info" style="display: none;">
                                    <h5>Informations du client :</h5>
                                    <p><strong>Nom :</strong> <span id="selected_name"></span></p>
                                    <p><strong>Téléphone :</strong> <span id="selected_contact"></span></p>
                                    <p><strong>Email :</strong> <span id="selected_email"></span></p>
                                </div>
                            </div>
                            
                            <!-- Section nouveau client -->
                            <div class="customer-form-section active" id="new_customer_section">
                                <div class="control-group">
                                    <label class="control-label">Nom du Client :</label>
                                    <div class="controls">
                                        <input type="text" class="span6" name="customername" id="new_customer_name" />
                                    </div>
                                </div>
                                <div class="control-group">
                                    <label class="control-label">Numéro de Mobile :</label>
                                    <div class="controls">
                                        <input type="tel" class="span6" name="mobilenumber" id="new_customer_mobile" pattern="^(\+?224)?6[0-9]{8}$" placeholder="623XXXXXXXX" />
                                    </div>
                                </div>
                                <div class="control-group">
                                    <label class="control-label">Email :</label>
                                    <div class="controls">
                                        <input type="email" class="span6" name="customeremail" id="new_customer_email" />
                                    </div>
                                </div>
                                <div class="control-group">
                                    <label class="control-label">Adresse :</label>
                                    <div class="controls">
                                        <textarea name="customeraddress" class="span6" rows="2" id="new_customer_address"></textarea>
                                    </div>
                                </div>
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
                                <input type="number" name="paid" step="any" value="0" class="span6" />
                                <p style="font-size: 12px; color: #666;">(Laissez 0 si rien n'est payé maintenant)</p>
                            </div>
                        </div>
                        
                        <!-- Option SMS -->
                        <div class="control-group">
                            <label class="control-label">Notification SMS :</label>
                            <div class="controls">
                                <div class="sms-option">
                                    <label>
                                        <input type="checkbox" name="send_sms" value="1" checked>
                                        <i class="icon-comment"></i> Envoyer un SMS avec les détails d'échéance
                                    </label>
                                    <div class="help-text">
                                        Le client recevra un SMS avec les détails de sa commande et la date d'échéance.
                                    </div>
                                </div>
                            </div>
                        </div>
  
                        <div class="form-actions" style="text-align:center;">
                            <button class="btn btn-primary" type="submit" name="submit" <?php echo $hasStockIssue ? 'disabled' : ''; ?>>
                                <i class="icon-ok"></i> Valider & Créer la Facture avec Échéances
                            </button>
                        </div>
                    </form>
  
                    <!-- Tableau du panier avec échéances -->
                    <div class="widget-box">
                        <div class="widget-title">
                            <span class="icon"><i class="icon-th"></i></span>
                            <h5>Articles dans le Panier avec Échéances</h5>
                        </div>
                        <div class="widget-content nopadding">
                            <table class="table table-bordered" style="font-size: 14px">
                                <thead>
                                    <tr>
                                        <th>#</th>
                                        <th>Nom du Produit</th>
                                        <th>Quantité</th>
                                        <th>Stock</th>
                                        <th>Prix unitaire</th>
                                        <th>Total</th>
                                        <th>Échéance</th>
                                        <th>Date d'échéance</th>
                                        <th>Action</th>
                                    </tr>
                                </thead>
                                <tbody>
                                    <?php
                                    $ret = mysqli_query($con, "SELECT tblcreditcart.ID as cid, tblcreditcart.ProductQty, tblcreditcart.Price as cartPrice, tblcreditcart.TypeEcheance, tblcreditcart.NombreJours, tblcreditcart.DateEcheance, tblproducts.ProductName, tblproducts.Stock FROM tblcreditcart LEFT JOIN tblproducts ON tblproducts.ID = tblcreditcart.ProductId WHERE tblcreditcart.IsCheckOut = 0 ORDER BY tblcreditcart.ID ASC");
                                    $cnt = 1;
                                    $grandTotal = 0;
                                    $num = mysqli_num_rows($ret);
                                    if ($num > 0) {
                                        while ($row = mysqli_fetch_array($ret)) {
                                            $pq = $row['ProductQty'];
                                            $ppu = $row['cartPrice'];
                                            $stock = $row['Stock'];
                                            $lineTotal = $pq * $ppu;
                                            $grandTotal += $lineTotal;
                                            
                                            // Statut du stock
                                            $stockIssue = ($stock <= 0 || $stock < $pq);
                                            $rowClass = $stockIssue ? 'class="stock-error"' : '';
                                            $stockStatus = '';
                                            
                                            if ($stock <= 0) {
                                                $stockStatus = '<span class="stock-warning">RUPTURE</span>';
                                            } elseif ($stock < $pq) {
                                                $stockStatus = '<span class="stock-warning">INSUFFISANT</span>';
                                            }
                                            
                                            // Type d'échéance
                                            $typeEcheanceLabels = [
                                                'immediat' => 'Immédiat',
                                                '7_jours' => '7 jours',
                                                '15_jours' => '15 jours',
                                                '30_jours' => '30 jours',
                                                '60_jours' => '60 jours',
                                                '90_jours' => '90 jours',
                                                'personnalise' => 'Personnalisé'
                                            ];
                                            
                                            $typeEcheanceLabel = $typeEcheanceLabels[$row['TypeEcheance']] ?? 'Immédiat';
                                            if ($row['TypeEcheance'] == 'personnalise') {
                                                $typeEcheanceLabel .= ' (' . $row['NombreJours'] . ' jours)';
                                            }
                                            
                                            $dateEcheance = $row['DateEcheance'] ? date('d/m/Y', strtotime($row['DateEcheance'])) : 'Non définie';
                                            ?>
                                            <tr <?php echo $rowClass; ?>>
                                                <td><?php echo $cnt; ?></td>
                                                <td><?php echo $row['ProductName']; ?></td>
                                                <td><?php echo $pq; ?></td>
                                                <td><?php echo $stock . ' ' . $stockStatus; ?></td>
                                                <td><?php echo number_format($ppu, 2); ?></td>
                                                <td><?php echo number_format($lineTotal, 2); ?></td>
                                                <td>
                                                    <span class="echeance-badge"><?php echo $typeEcheanceLabel; ?></span>
                                                </td>
                                                <td>
                                                    <span class="echeance-date"><?php echo $dateEcheance; ?></span>
                                                </td>
                                                <td>
                                                    <a href="dettecart.php?delid=<?php echo $row['cid']; ?>" onclick="return confirm('Voulez-vous vraiment supprimer cet article ?');">
                                                        <i class="icon-trash"></i>
                                                    </a>
                                                </td>
                                            </tr>
                                            <?php $cnt++;
                                        }
                                        $netTotal = $grandTotal - $discount;
                                        if ($netTotal < 0) {
                                            $netTotal = 0;
                                        }
                                        ?>
                                        <tr>
                                            <th colspan="5" style="text-align: right; font-weight: bold;">Total Général</th>
                                            <th colspan="4" style="text-align: center; font-weight: bold;"><?php echo number_format($grandTotal, 2); ?></th>
                                        </tr>
                                        <tr>
                                            <th colspan="5" style="text-align: right; font-weight: bold;">
                                                Remise
                                                <?php if ($discountType == 'percentage'): ?>
                                                    (<?php echo $discountValue; ?>%)
                                                <?php endif; ?>
                                            </th>
                                            <th colspan="4" style="text-align: center; font-weight: bold;"><?php echo number_format($discount, 2); ?></th>
                                        </tr>
                                        <tr>
                                            <th colspan="5" style="text-align: right; font-weight: bold; color: green;">Total Net</th>
                                            <th colspan="4" style="text-align: center; font-weight: bold; color: green;"><?php echo number_format($netTotal, 2); ?></th>
                                        </tr>
                                        <?php
                                    } else {
                                        ?>
                                        <tr>
                                            <td colspan="9" style="color:red; text-align:center;">Aucun article dans le panier</td>
                                        </tr>
                                        <?php
                                    }
                                    ?>
                                </tbody>
                            </table>
                        </div>
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
    document.addEventListener('DOMContentLoaded', function() {
        // Gestion des types de clients
        const existingRadio = document.getElementById('existing_customer_radio');
        const newRadio = document.getElementById('new_customer_radio');
        const existingSection = document.getElementById('existing_customer_section');
        const newSection = document.getElementById('new_customer_section');
        const customerSelect = document.getElementById('customer_select');
        const customerInfo = document.getElementById('customer_info');
        
        function toggleCustomerSections() {
            if (existingRadio.checked) {
                existingSection.classList.add('active');
                newSection.classList.remove('active');
                customerSelect.required = true;
                document.getElementById('new_customer_name').required = false;
                document.getElementById('new_customer_mobile').required = false;
            } else {
                existingSection.classList.remove('active');
                newSection.classList.add('active');
                customerSelect.required = false;
                customerInfo.style.display = 'none';
                document.getElementById('new_customer_name').required = true;
                document.getElementById('new_customer_mobile').required = true;
            }
        }
        
        existingRadio.addEventListener('change', toggleCustomerSections);
        newRadio.addEventListener('change', toggleCustomerSections);
        
        customerSelect.addEventListener('change', function() {
            const selectedOption = this.options[this.selectedIndex];
            
            if (selectedOption.value) {
                const customerName = selectedOption.getAttribute('data-name');
                const customerContact = selectedOption.getAttribute('data-contact');
                const customerEmail = selectedOption.getAttribute('data-email');
                
                document.getElementById('selected_name').textContent = customerName;
                document.getElementById('selected_contact').textContent = customerContact;
                document.getElementById('selected_email').textContent = customerEmail || 'Non renseigné';
                
                customerInfo.style.display = 'block';
            } else {
                customerInfo.style.display = 'none';
            }
        });
        
        // Gestion des échéances dans le formulaire de recherche
        document.querySelectorAll('.echeance-select').forEach(function(select) {
            select.addEventListener('change', function() {
                const customInput = this.parentNode.querySelector('.jours-custom');
                if (this.value === 'personnalise') {
                    customInput.style.display = 'inline-block';
                } else {
                    customInput.style.display = 'none';
                }
            });
        });
        
        // Validation du numéro de téléphone
        const mobileInput = document.getElementById('new_customer_mobile');
        const nimbaFormats = /^(\+?224)?6[0-9]{8}$/;
        
        if (mobileInput) {
            mobileInput.addEventListener('input', function() {
                const value = this.value.replace(/[^0-9+]/g, '');
                this.value = value;
                
                if (value && !nimbaFormats.test(value)) {
                    this.style.borderColor = '#d9534f';
                    this.title = 'Format invalide. Utilisez: 623XXXXXXXX';
                } else {
                    this.style.borderColor = '#28a745';
                    this.title = 'Format valide';
                }
            });
        }
        
        // Validation du formulaire
        const form = document.querySelector('form[name="submit"]');
        if (form) {
            form.addEventListener('submit', function(e) {
                const customerType = document.querySelector('input[name="customer_type"]:checked').value;
                
                if (customerType === 'existing') {
                    const selectedCustomer = customerSelect.value;
                    if (!selectedCustomer) {
                        alert('Veuillez sélectionner un client existant');
                        e.preventDefault();
                        return false;
                    }
                } else {
                    const customerName = document.getElementById('new_customer_name').value;
                    const mobile = document.getElementById('new_customer_mobile').value;
                    
                    if (!customerName.trim() || !mobile.trim()) {
                        alert('Le nom et le numéro de téléphone sont obligatoires');
                        e.preventDefault();
                        return false;
                    }
                    
                    if (!nimbaFormats.test(mobile.replace(/[^0-9+]/g, ''))) {
                        alert('Format de numéro invalide. Utilisez: 623XXXXXXXX');
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