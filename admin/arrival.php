<?php
session_start();
error_reporting(E_ALL);
ini_set('display_errors', 1);
include('includes/dbconnection.php');

if (!$con) {
    die("Erreur de connexion à la base de données: " . mysqli_connect_error());
}

if (strlen($_SESSION['imsaid']) == 0) {
    header('location:logout.php');
    exit;
}

// ====================================================================
// FONCTIONS UTILITAIRES POUR IMPORTATIONS
// ====================================================================

function updateImportTotals($con, $importId) {
    $sql = "UPDATE tblimportations 
            SET TotalFees = (SELECT COALESCE(SUM(Amount), 0) FROM tblimportfees WHERE ImportationID = $importId),
                TotalCostGNF = TotalValueGNF + (SELECT COALESCE(SUM(Amount), 0) FROM tblimportfees WHERE ImportationID = $importId)
            WHERE ID = $importId";
    return mysqli_query($con, $sql);
}

function calculateProductCostPrice($con, $arrivalId) {
    $sql = "SELECT pa.*, i.ExchangeRate 
            FROM tblproductarrivals pa 
            LEFT JOIN tblimportations i ON i.ID = pa.ImportationID 
            WHERE pa.ID = $arrivalId";
    
    $result = mysqli_query($con, $sql);
    if ($result && mysqli_num_rows($result) > 0) {
        $data = mysqli_fetch_assoc($result);
        
        $unitPriceUSD = floatval($data['UnitPriceUSD']);
        $allocatedFees = floatval($data['AllocatedFees']);
        $quantity = floatval($data['Quantity']);
        $exchangeRate = floatval($data['ExchangeRate']);
        
        // Prix de revient = (Prix USD * Taux) + (Frais alloués / Quantité)
        $unitCostPriceGNF = ($unitPriceUSD * $exchangeRate) + ($allocatedFees / $quantity);
        
        // Mettre à jour l'arrivage
        $updateSql = "UPDATE tblproductarrivals 
                      SET UnitCostPrice = $unitCostPriceGNF 
                      WHERE ID = $arrivalId";
        mysqli_query($con, $updateSql);
        
        return $unitCostPriceGNF;
    }
    return 0;
}

// ====================================================================
// GESTION DES FORMULAIRES
// ====================================================================

// 1) CRÉER UN NOUVEAU DOSSIER D'IMPORTATION
if (isset($_POST['create_import'])) {
    $importRef = mysqli_real_escape_string($con, $_POST['import_ref']);
    $factureNumber = mysqli_real_escape_string($con, $_POST['facture_number']);
    $blNumber = mysqli_real_escape_string($con, $_POST['bl_number']);
    $containerNumber = mysqli_real_escape_string($con, $_POST['container_number']);
    $supplierID = intval($_POST['supplier_id']);
    $importDate = $_POST['import_date'];
    $totalValueUSD = floatval($_POST['total_value_usd']);
    $exchangeRate = floatval($_POST['exchange_rate']);
    $description = mysqli_real_escape_string($con, $_POST['description']);
    
    $totalValueGNF = $totalValueUSD * $exchangeRate;
    
    $sql = "INSERT INTO tblimportations (ImportRef, FactureNumber, BLNumber, ContainerNumber, SupplierID, ImportDate, TotalValueUSD, ExchangeRate, TotalValueGNF, Description) 
            VALUES ('$importRef', '$factureNumber', '$blNumber', '$containerNumber', $supplierID, '$importDate', $totalValueUSD, $exchangeRate, $totalValueGNF, '$description')";
    
    if (mysqli_query($con, $sql)) {
        $importId = mysqli_insert_id($con);
        echo "<script>alert('Dossier d\\'importation créé avec succès! ID: $importId');</script>";
        $_SESSION['current_import_id'] = $importId;
    } else {
        echo "<script>alert('Erreur lors de la création: " . mysqli_error($con) . "');</script>";
    }
    
    echo "<script>window.location.href='arrival.php'</script>";
    exit;
}

// 2) AJOUTER DES FRAIS À UNE IMPORTATION
if (isset($_POST['add_import_fees'])) {
    $importId = intval($_POST['import_id']);
    $feeTypes = $_POST['fee_type_id'] ?? [];
    $amounts = $_POST['fee_amount'] ?? [];
    $payedByClient = $_POST['payed_by_client'] ?? [];
    $comments = $_POST['fee_comments'] ?? [];
    
    $successCount = 0;
    foreach ($feeTypes as $index => $feeTypeId) {
        if ($feeTypeId > 0 && $amounts[$index] > 0) {
            $amount = floatval($amounts[$index]);
            $payedBy = isset($payedByClient[$index]) ? 1 : 0;
            $comment = mysqli_real_escape_string($con, $comments[$index] ?? '');
            
            $sql = "INSERT INTO tblimportfees (ImportationID, FeeTypeID, Amount, PayedByClient, Comments) 
                    VALUES ($importId, $feeTypeId, $amount, $payedBy, '$comment')";
            
            if (mysqli_query($con, $sql)) {
                $successCount++;
            }
        }
    }
    
    if ($successCount > 0) {
        updateImportTotals($con, $importId);
        echo "<script>alert('$successCount frais ajoutés avec succès!');</script>";
    } else {
        echo "<script>alert('Aucun frais valide à ajouter!');</script>";
    }
    
    echo "<script>window.location.href='arrival.php'</script>";
    exit;
}

// 3) AJOUTER PRODUIT À UNE IMPORTATION AVEC CALCUL DE PRIX
if (isset($_POST['add_import_product'])) {
    $importId = intval($_POST['import_id']);
    $productId = intval($_POST['product_id']);
    $quantity = intval($_POST['quantity']);
    $unitPriceUSD = floatval($_POST['unit_price_usd']);
    $allocatedFees = floatval($_POST['allocated_fees']);
    $suggestedMargin = floatval($_POST['suggested_margin']);
    $arrivalDate = $_POST['arrival_date'];
    $deliveryDate = $_POST['delivery_date'] ?? null;
    $deliveryNote = mysqli_real_escape_string($con, $_POST['delivery_note'] ?? '');
    $comments = mysqli_real_escape_string($con, $_POST['comments'] ?? '');
    
    // Obtenir le taux de change de l'importation
    $importQuery = mysqli_query($con, "SELECT ExchangeRate, SupplierID FROM tblimportations WHERE ID = $importId");
    if (!$importQuery || mysqli_num_rows($importQuery) == 0) {
        echo "<script>alert('Importation non trouvée!');</script>";
        echo "<script>window.location.href='arrival.php'</script>";
        exit;
    }
    
    $importData = mysqli_fetch_assoc($importQuery);
    $exchangeRate = floatval($importData['ExchangeRate']);
    $supplierId = intval($importData['SupplierID']);
    
    // Calculer les coûts
    $totalCostUSD = $unitPriceUSD * $quantity;
    $totalCostGNF = $totalCostUSD * $exchangeRate;
    $unitCostPriceGNF = ($unitPriceUSD * $exchangeRate) + ($allocatedFees / $quantity);
    $suggestedSalePrice = $unitCostPriceGNF * (1 + ($suggestedMargin / 100));
    
    // Insérer dans tblproductarrivals
    $sql = "INSERT INTO tblproductarrivals (
                ProductID, SupplierID, ImportationID, ArrivalDate, DeliveryDate, DeliveryNote, 
                Quantity, Cost, UnitPriceUSD, AllocatedFees, UnitCostPrice, 
                SuggestedMargin, SuggestedSalePrice, Comments
            ) VALUES (
                $productId, $supplierId, $importId, '$arrivalDate', " . 
                ($deliveryDate ? "'$deliveryDate'" : "NULL") . ", '$deliveryNote', 
                $quantity, $totalCostGNF, $unitPriceUSD, $allocatedFees, $unitCostPriceGNF,
                $suggestedMargin, $suggestedSalePrice, '$comments'
            )";
    
    if (mysqli_query($con, $sql)) {
        $arrivalId = mysqli_insert_id($con);
        
        // Mettre à jour le stock
        $stockQuery = mysqli_query($con, "SELECT Stock FROM tblproducts WHERE ID = $productId");
        if ($stockQuery && mysqli_num_rows($stockQuery) > 0) {
            $stockData = mysqli_fetch_assoc($stockQuery);
            $currentStock = intval($stockData['Stock']);
            $newStock = $currentStock + $quantity;
            
            $updateStockSql = "UPDATE tblproducts SET Stock = $newStock WHERE ID = $productId";
            mysqli_query($con, $updateStockSql);
        }
        
        echo "<script>alert('Produit ajouté à l\\'importation avec succès!\\nPrix de revient: " . number_format($unitCostPriceGNF, 2) . " GNF\\nPrix de vente suggéré: " . number_format($suggestedSalePrice, 2) . " GNF');</script>";
    } else {
        echo "<script>alert('Erreur lors de l\\'ajout: " . mysqli_error($con) . "');</script>";
    }
    
    echo "<script>window.location.href='arrival.php'</script>";
    exit;
}

// 4) APPLIQUER LES PRIX DE VENTE AUX PRODUITS
if (isset($_POST['apply_sale_prices'])) {
    $arrivalIds = $_POST['arrival_id'] ?? [];
    $salePrices = $_POST['sale_price'] ?? [];
    $targetMargins = $_POST['target_margin'] ?? [];
    
    $successCount = 0;
    foreach ($arrivalIds as $index => $arrivalId) {
        $arrivalId = intval($arrivalId);
        $salePrice = floatval($salePrices[$index]);
        $targetMargin = floatval($targetMargins[$index]);
        
        if ($arrivalId > 0 && $salePrice > 0) {
            // Obtenir les données de l'arrivage
            $arrivalQuery = mysqli_query($con, "SELECT ProductID, UnitCostPrice FROM tblproductarrivals WHERE ID = $arrivalId");
            if ($arrivalQuery && mysqli_num_rows($arrivalQuery) > 0) {
                $arrivalData = mysqli_fetch_assoc($arrivalQuery);
                $productId = intval($arrivalData['ProductID']);
                $unitCostPrice = floatval($arrivalData['UnitCostPrice']);
                
                // Mettre à jour le produit avec le nouveau prix de vente
                $updateProductSql = "UPDATE tblproducts 
                                   SET Price = $salePrice, 
                                       CostPrice = $unitCostPrice,
                                       TargetMargin = $targetMargin,
                                       LastCostUpdate = NOW()
                                   WHERE ID = $productId";
                
                if (mysqli_query($con, $updateProductSql)) {
                    // Marquer l'arrivage comme appliqué
                    $updateArrivalSql = "UPDATE tblproductarrivals 
                                       SET AppliedToProduct = 1, 
                                           SuggestedSalePrice = $salePrice,
                                           SuggestedMargin = $targetMargin
                                       WHERE ID = $arrivalId";
                    mysqli_query($con, $updateArrivalSql);
                    
                    $successCount++;
                }
            }
        }
    }
    
    if ($successCount > 0) {
        echo "<script>alert('$successCount prix de vente appliqués avec succès!');</script>";
    } else {
        echo "<script>alert('Aucun prix appliqué!');</script>";
    }
    
    echo "<script>window.location.href='arrival.php'</script>";
    exit;
}

// 5) SÉLECTIONNER UNE IMPORTATION POUR TRAVAILLER DESSUS
if (isset($_GET['select_import'])) {
    $_SESSION['current_import_id'] = intval($_GET['select_import']);
    echo "<script>window.location.href='arrival.php'</script>";
    exit;
}

// Obtenir l'importation courante
$currentImportId = $_SESSION['current_import_id'] ?? 0;
$currentImport = null;
if ($currentImportId > 0) {
    $importQuery = mysqli_query($con, "
        SELECT i.*, s.SupplierName 
        FROM tblimportations i 
        LEFT JOIN tblsupplier s ON s.ID = i.SupplierID 
        WHERE i.ID = $currentImportId
    ");
    if ($importQuery && mysqli_num_rows($importQuery) > 0) {
        $currentImport = mysqli_fetch_assoc($importQuery);
    }
}

// Obtenir les types de frais
$feeTypesQuery = mysqli_query($con, "SELECT * FROM tblimportfees_types WHERE IsActive = 1 ORDER BY FeeName");

// Obtenir les importations récentes
$recentImportsQuery = mysqli_query($con, "
    SELECT i.*, s.SupplierName, 
           COUNT(pa.ID) as ProductCount,
           SUM(pa.Quantity) as TotalQuantity
    FROM tblimportations i 
    LEFT JOIN tblsupplier s ON s.ID = i.SupplierID 
    LEFT JOIN tblproductarrivals pa ON pa.ImportationID = i.ID
    GROUP BY i.ID 
    ORDER BY i.ImportDate DESC, i.ID DESC 
    LIMIT 10
");

// Obtenir les fournisseurs
$suppliersQuery = mysqli_query($con, "SELECT ID, SupplierName FROM tblsupplier ORDER BY SupplierName");

// Obtenir les produits pour la sélection
$productsQuery = mysqli_query($con, "SELECT ID, ProductName, Price FROM tblproducts WHERE Status = 1 ORDER BY ProductName");
?>

<!DOCTYPE html>
<html lang="fr">
<head>
    <title>Gestion des Importations | ITALALU</title>
    <?php include_once('includes/cs.php'); ?>
    <?php include_once('includes/responsive.php'); ?>
    <style>
        .import-header {
            background: linear-gradient(135deg, #2c3e50, #3498db);
            color: white;
            padding: 20px;
            border-radius: 8px;
            margin-bottom: 20px;
        }
        .import-card {
            border: 1px solid #ddd;
            border-radius: 8px;
            padding: 15px;
            margin-bottom: 20px;
            background: #f8f9fa;
        }
        .fee-row {
            background: #fff;
            border: 1px solid #e9ecef;
            padding: 10px;
            margin: 5px 0;
            border-radius: 5px;
        }
        .cost-breakdown {
            background: #e8f4f8;
            border: 1px solid #bee5eb;
            padding: 15px;
            border-radius: 5px;
            font-family: 'Courier New', monospace;
        }
        .price-calculation {
            background: #fff3cd;
            border: 1px solid #ffeaa7;
            padding: 10px;
            border-radius: 5px;
            margin: 10px 0;
        }
        .import-status-en_cours { color: #007bff; }
        .import-status-termine { color: #28a745; }
        .import-status-annule { color: #dc3545; }
        .currency-usd { color: #2ecc71; font-weight: bold; }
        .currency-gnf { color: #e74c3c; font-weight: bold; }
        .tabs {
            margin-bottom: 20px;
        }
        .tab-content {
            display: none;
        }
        .tab-content.active {
            display: block;
        }
        .nav-tabs {
            list-style: none;
            padding: 0;
            margin: 0;
            border-bottom: 2px solid #ddd;
        }
        .nav-tabs li {
            display: inline-block;
            margin-right: 5px;
        }
        .nav-tabs li a {
            display: block;
            padding: 10px 20px;
            background: #f8f9fa;
            color: #333;
            text-decoration: none;
            border: 1px solid #ddd;
            border-bottom: none;
            border-radius: 5px 5px 0 0;
        }
        .nav-tabs li.active a {
            background: #007bff;
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
            <a href="dashboard.php" class="tip-bottom"><i class="icon-home"></i> Accueil</a>
            <a href="arrival.php" class="current">Gestion des Importations</a>
        </div>
        <h1>🚢 Gestion Complète des Importations & Calcul des Prix</h1>
    </div>

    <div class="container-fluid">
        
        <!-- EN-TÊTE IMPORTATION COURANTE -->
        <?php if ($currentImport) { ?>
        <div class="import-header">
            <div class="row-fluid">
                <div class="span8">
                    <h3><i class="icon-folder-open"></i> Dossier Actuel: <?php echo $currentImport['ImportRef']; ?></h3>
                    <p><strong>Fournisseur:</strong> <?php echo $currentImport['SupplierName']; ?></p>
                    <p><strong>BL:</strong> <?php echo $currentImport['BLNumber']; ?> | 
                       <strong>Container:</strong> <?php echo $currentImport['ContainerNumber']; ?></p>
                </div>
                <div class="span4" style="text-align: right;">
                    <p><strong class="currency-usd">Valeur: $<?php echo number_format($currentImport['TotalValueUSD'], 2); ?></strong></p>
                    <p><strong>Taux:</strong> <?php echo number_format($currentImport['ExchangeRate'], 0); ?> GNF/USD</p>
                    <p><strong class="currency-gnf">Total: <?php echo number_format($currentImport['TotalCostGNF'], 0); ?> GNF</strong></p>
                    <span class="label label-<?php echo $currentImport['Status'] == 'en_cours' ? 'warning' : ($currentImport['Status'] == 'termine' ? 'success' : 'important'); ?>">
                        <?php echo strtoupper($currentImport['Status']); ?>
                    </span>
                </div>
            </div>
        </div>
        <?php } ?>

        <!-- ONGLETS DE NAVIGATION -->
        <div class="tabs">
            <ul class="nav-tabs">
                <li class="active"><a href="#tab-imports" onclick="showTab('imports')">📋 Dossiers d'Importation</a></li>
                <li><a href="#tab-fees" onclick="showTab('fees')">💰 Frais d'Importation</a></li>
                <li><a href="#tab-products" onclick="showTab('products')">📦 Produits & Prix</a></li>
                <li><a href="#tab-pricing" onclick="showTab('pricing')">🏷️ Application des Prix</a></li>
                <li><a href="#tab-reports" onclick="showTab('reports')">📊 Rapports</a></li>
            </ul>

            <!-- TAB 1: GESTION DES DOSSIERS D'IMPORTATION -->
            <div id="tab-imports" class="tab-content active">
                <div class="row-fluid">
                    <!-- CRÉER NOUVEAU DOSSIER -->
                    <div class="span6">
                        <div class="widget-box">
                            <div class="widget-title">
                                <span class="icon"><i class="icon-plus"></i></span>
                                <h5>🆕 Créer Nouveau Dossier d'Importation</h5>
                            </div>
                            <div class="widget-content">
                                <form method="post" class="form-horizontal">
                                    <div class="control-group">
                                        <label class="control-label">Référence Dossier:</label>
                                        <div class="controls">
                                            <input type="text" name="import_ref" placeholder="Ex: 003/CT/2025" required />
                                        </div>
                                    </div>
                                    
                                    <div class="control-group">
                                        <label class="control-label">N° Facture:</label>
                                        <div class="controls">
                                            <input type="text" name="facture_number" placeholder="Ex: 007" />
                                        </div>
                                    </div>
                                    
                                    <div class="control-group">
                                        <label class="control-label">N° BL:</label>
                                        <div class="controls">
                                            <input type="text" name="bl_number" placeholder="Ex: 234034521" />
                                        </div>
                                    </div>
                                    
                                    <div class="control-group">
                                        <label class="control-label">N° Container:</label>
                                        <div class="controls">
                                            <input type="text" name="container_number" placeholder="Ex: MSKU1743847/0" />
                                        </div>
                                    </div>
                                    
                                    <div class="control-group">
                                        <label class="control-label">Fournisseur:</label>
                                        <div class="controls">
                                            <select name="supplier_id" required>
                                                <option value="">-- Choisir Fournisseur --</option>
                                                <?php
                                                mysqli_data_seek($suppliersQuery, 0);
                                                while ($supplier = mysqli_fetch_assoc($suppliersQuery)) {
                                                    echo '<option value="'.$supplier['ID'].'">'.$supplier['SupplierName'].'</option>';
                                                }
                                                ?>
                                            </select>
                                        </div>
                                    </div>
                                    
                                    <div class="control-group">
                                        <label class="control-label">Date d'Importation:</label>
                                        <div class="controls">
                                            <input type="date" name="import_date" value="<?php echo date('Y-m-d'); ?>" required />
                                        </div>
                                    </div>
                                    
                                    <div class="control-group">
                                        <label class="control-label">Valeur Facture (USD):</label>
                                        <div class="controls">
                                            <input type="number" name="total_value_usd" step="0.01" min="0" placeholder="68076.40" required />
                                        </div>
                                    </div>
                                    
                                    <div class="control-group">
                                        <label class="control-label">Taux de Change (GNF/USD):</label>
                                        <div class="controls">
                                            <input type="number" name="exchange_rate" step="0.01" min="0" placeholder="12700" required />
                                        </div>
                                    </div>
                                    
                                    <div class="control-group">
                                        <label class="control-label">Description:</label>
                                        <div class="controls">
                                            <textarea name="description" placeholder="FRAIS DE SORTIE D'ALUMINIUM PROFILES..."></textarea>
                                        </div>
                                    </div>
                                    
                                    <div class="form-actions">
                                        <button type="submit" name="create_import" class="btn btn-success">
                                            <i class="icon-plus"></i> Créer Dossier d'Importation
                                        </button>
                                    </div>
                                </form>
                            </div>
                        </div>
                    </div>

                    <!-- LISTE DES IMPORTATIONS RÉCENTES -->
                    <div class="span6">
                        <div class="widget-box">
                            <div class="widget-title">
                                <span class="icon"><i class="icon-list"></i></span>
                                <h5>📋 Dossiers d'Importation Récents</h5>
                            </div>
                            <div class="widget-content nopadding">
                                <table class="table table-bordered table-striped">
                                    <thead>
                                        <tr>
                                            <th>Référence</th>
                                            <th>Fournisseur</th>
                                            <th>Valeur (USD)</th>
                                            <th>Total (GNF)</th>
                                            <th>Statut</th>
                                            <th>Action</th>
                                        </tr>
                                    </thead>
                                    <tbody>
                                        <?php while ($import = mysqli_fetch_assoc($recentImportsQuery)) { ?>
                                        <tr <?php echo ($import['ID'] == $currentImportId) ? 'style="background-color: #e8f4f8;"' : ''; ?>>
                                            <td>
                                                <strong><?php echo $import['ImportRef']; ?></strong><br>
                                                <small><?php echo $import['BLNumber']; ?></small>
                                            </td>
                                            <td><?php echo $import['SupplierName']; ?></td>
                                            <td class="currency-usd">$<?php echo number_format($import['TotalValueUSD'], 0); ?></td>
                                            <td class="currency-gnf"><?php echo number_format($import['TotalCostGNF'], 0); ?></td>
                                            <td>
                                                <span class="label import-status-<?php echo $import['Status']; ?>">
                                                    <?php echo strtoupper($import['Status']); ?>
                                                </span><br>
                                                <small><?php echo $import['ProductCount']; ?> produits</small>
                                            </td>
                                            <td>
                                                <?php if ($import['ID'] != $currentImportId) { ?>
                                                <a href="arrival.php?select_import=<?php echo $import['ID']; ?>" 
                                                   class="btn btn-mini btn-primary">
                                                    <i class="icon-folder-open"></i> Sélectionner
                                                </a>
                                                <?php } else { ?>
                                                <span class="label label-info">Actuel</span>
                                                <?php } ?>
                                            </td>
                                        </tr>
                                        <?php } ?>
                                    </tbody>
                                </table>
                            </div>
                        </div>
                    </div>
                </div>
            </div>

            <!-- TAB 2: GESTION DES FRAIS -->
            <div id="tab-fees" class="tab-content">
                <?php if ($currentImport) { ?>
                <div class="row-fluid">
                    <div class="span8">
                        <div class="widget-box">
                            <div class="widget-title">
                                <span class="icon"><i class="icon-money"></i></span>
                                <h5>💰 Ajouter Frais d'Importation</h5>
                            </div>
                            <div class="widget-content">
                                <form method="post" id="feesForm">
                                    <input type="hidden" name="import_id" value="<?php echo $currentImportId; ?>" />
                                    
                                    <div id="feesContainer">
                                        <?php 
                                        $feeIndex = 0;
                                        mysqli_data_seek($feeTypesQuery, 0);
                                        while ($feeType = mysqli_fetch_assoc($feeTypesQuery)) { 
                                        ?>
                                        <div class="fee-row">
                                            <div class="row-fluid">
                                                <div class="span4">
                                                    <label>
                                                        <input type="checkbox" name="fee_type_id[]" value="<?php echo $feeType['ID']; ?>" 
                                                               onchange="toggleFeeRow(this, <?php echo $feeIndex; ?>)" />
                                                        <strong><?php echo $feeType['FeeName']; ?></strong>
                                                    </label>
                                                    <small style="display: block; color: #666;"><?php echo $feeType['Description']; ?></small>
                                                </div>
                                                <div class="span3">
                                                    <input type="number" name="fee_amount[]" step="0.01" min="0" 
                                                           value="<?php echo $feeType['DefaultAmount']; ?>"
                                                           placeholder="Montant en GNF" class="fee-amount" disabled />
                                                </div>
                                                <div class="span2">
                                                    <label>
                                                        <input type="checkbox" name="payed_by_client[]" value="<?php echo $feeIndex; ?>" 
                                                               <?php echo $feeType['PayedByClient'] ? 'checked' : ''; ?> disabled />
                                                        Client paie
                                                    </label>
                                                </div>
                                                <div class="span3">
                                                    <input type="text" name="fee_comments[]" placeholder="Commentaires" class="fee-comment" disabled />
                                                </div>
                                            </div>
                                        </div>
                                        <?php 
                                        $feeIndex++;
                                        } 
                                        ?>
                                    </div>
                                    
                                    <div class="form-actions">
                                        <button type="submit" name="add_import_fees" class="btn btn-success">
                                            <i class="icon-plus"></i> Ajouter Frais Sélectionnés
                                        </button>
                                        <button type="button" class="btn btn-info" onclick="calculateTotalFees()">
                                            <i class="icon-calculator"></i> Calculer Total
                                        </button>
                                    </div>
                                    
                                    <div id="feesSummary" class="cost-breakdown" style="display: none;">
                                        <h6>💰 Résumé des Frais:</h6>
                                        <div id="feesBreakdown"></div>
                                    </div>
                                </form>
                            </div>
                        </div>
                    </div>

                    <!-- FRAIS DÉJÀ AJOUTÉS -->
                    <div class="span4">
                        <div class="widget-box">
                            <div class="widget-title">
                                <span class="icon"><i class="icon-list-alt"></i></span>
                                <h5>📋 Frais Enregistrés</h5>
                            </div>
                            <div class="widget-content">
                                <?php
                                $existingFeesQuery = mysqli_query($con, "
                                    SELECT f.*, ft.FeeName 
                                    FROM tblimportfees f 
                                    LEFT JOIN tblimportfees_types ft ON ft.ID = f.FeeTypeID 
                                    WHERE f.ImportationID = $currentImportId 
                                    ORDER BY f.ID
                                ");
                                
                                $totalExistingFees = 0;
                                if (mysqli_num_rows($existingFeesQuery) > 0) {
                                    while ($fee = mysqli_fetch_assoc($existingFeesQuery)) {
                                        $totalExistingFees += $fee['Amount'];
                                        ?>
                                        <div class="fee-item" style="padding: 8px; border-bottom: 1px solid #eee;">
                                            <strong><?php echo $fee['FeeName']; ?></strong><br>
                                            <span class="currency-gnf"><?php echo number_format($fee['Amount'], 0); ?> GNF</span>
                                            <?php if ($fee['PayedByClient']) { ?>
                                                <span class="label label-info">Client</span>
                                            <?php } ?>
                                            <?php if ($fee['Comments']) { ?>
                                                <br><small><?php echo $fee['Comments']; ?></small>
                                            <?php } ?>
                                        </div>
                                        <?php
                                    }
                                    ?>
                                    <div style="padding: 10px; background: #f8f9fa; margin-top: 10px;">
                                        <strong>Total Frais: <span class="currency-gnf"><?php echo number_format($totalExistingFees, 0); ?> GNF</span></strong>
                                    </div>
                                    <?php
                                } else {
                                    echo '<p style="text-align: center; color: #999;">Aucun frais enregistré</p>';
                                }
                                ?>
                            </div>
                        </div>
                    </div>
                </div>
                <?php } else { ?>
                <div class="alert alert-warning">
                    <strong>⚠️ Aucun dossier d'importation sélectionné!</strong><br>
                    Sélectionnez ou créez un dossier d'importation d'abord.
                </div>
                <?php } ?>
            </div>

            <!-- TAB 3: PRODUITS & PRIX -->
            <div id="tab-products" class="tab-content">
                <?php if ($currentImport) { ?>
                <div class="row-fluid">
                    <div class="span8">
                        <div class="widget-box">
                            <div class="widget-title">
                                <span class="icon"><i class="icon-shopping-cart"></i></span>
                                <h5>📦 Ajouter Produit à l'Importation</h5>
                            </div>
                            <div class="widget-content">
                                <form method="post" class="form-horizontal" id="productForm">
                                    <input type="hidden" name="import_id" value="<?php echo $currentImportId; ?>" />
                                    
                                    <div class="control-group">
                                        <label class="control-label">Produit:</label>
                                        <div class="controls">
                                            <select name="product_id" id="productSelect" required onchange="updateProductInfo()">
                                                <option value="">-- Choisir Produit --</option>
                                                <?php
                                                mysqli_data_seek($productsQuery, 0);
                                                while ($product = mysqli_fetch_assoc($productsQuery)) {
                                                    echo '<option value="'.$product['ID'].'" data-current-price="'.$product['Price'].'">'.$product['ProductName'].'</option>';
                                                }
                                                ?>
                                            </select>
                                        </div>
                                    </div>
                                    
                                    <div class="control-group">
                                        <label class="control-label">Quantité:</label>
                                        <div class="controls">
                                            <input type="number" name="quantity" id="quantity" min="1" value="1" required onchange="calculateCosts()" />
                                        </div>
                                    </div>
                                    
                                    <div class="control-group">
                                        <label class="control-label">Prix Unitaire (USD):</label>
                                        <div class="controls">
                                            <input type="number" name="unit_price_usd" id="unitPriceUSD" step="0.01" min="0" required onchange="calculateCosts()" />
                                            <span class="help-inline">Prix de la facture fournisseur</span>
                                        </div>
                                    </div>
                                    
                                    <div class="control-group">
                                        <label class="control-label">Frais Alloués (GNF):</label>
                                        <div class="controls">
                                            <input type="number" name="allocated_fees" id="allocatedFees" step="0.01" min="0" value="0" onchange="calculateCosts()" />
                                            <span class="help-inline">Part des frais d'importation pour ce produit</span>
                                        </div>
                                    </div>
                                    
                                    <div class="control-group">
                                        <label class="control-label">Marge Suggérée (%):</label>
                                        <div class="controls">
                                            <input type="number" name="suggested_margin" id="suggestedMargin" step="0.01" min="0" value="25" onchange="calculateCosts()" />
                                            <span class="help-inline">Marge bénéficiaire désirée</span>
                                        </div>
                                    </div>
                                    
                                    <div class="price-calculation" id="costCalculation" style="display: none;">
                                        <h6>🧮 Calcul des Coûts:</h6>
                                        <div id="costBreakdown"></div>
                                    </div>
                                    
                                    <div class="control-group">
                                        <label class="control-label">Date d'Arrivage:</label>
                                        <div class="controls">
                                            <input type="date" name="arrival_date" value="<?php echo date('Y-m-d'); ?>" required />
                                        </div>
                                    </div>
                                    
                                    <div class="control-group">
                                        <label class="control-label">Date de Livraison:</label>
                                        <div class="controls">
                                            <input type="date" name="delivery_date" value="<?php echo $currentImport['ImportDate']; ?>" />
                                        </div>
                                    </div>
                                    
                                    <div class="control-group">
                                        <label class="control-label">Bon de Livraison:</label>
                                        <div class="controls">
                                            <input type="text" name="delivery_note" value="<?php echo $currentImport['BLNumber']; ?>" />
                                        </div>
                                    </div>
                                    
                                    <div class="control-group">
                                        <label class="control-label">Commentaires:</label>
                                        <div class="controls">
                                            <textarea name="comments" placeholder="Notes supplémentaires..."></textarea>
                                        </div>
                                    </div>
                                    
                                    <div class="form-actions">
                                        <button type="submit" name="add_import_product" class="btn btn-success">
                                            <i class="icon-plus"></i> Ajouter Produit à l'Importation
                                        </button>
                                    </div>
                                </form>
                            </div>
                        </div>
                    </div>

                    <!-- AIDE AU CALCUL DES FRAIS -->
                    <div class="span4">
                        <div class="widget-box">
                            <div class="widget-title">
                                <span class="icon"><i class="icon-info-sign"></i></span>
                                <h5>ℹ️ Aide & Infos</h5>
                            </div>
                            <div class="widget-content">
                                <div class="import-card">
                                    <h6>📊 Informations Importation:</h6>
                                    <p><strong>Référence:</strong> <?php echo $currentImport['ImportRef']; ?></p>
                                    <p><strong>Valeur Facture:</strong> <span class="currency-usd">$<?php echo number_format($currentImport['TotalValueUSD'], 2); ?></span></p>
                                    <p><strong>Taux de Change:</strong> <?php echo number_format($currentImport['ExchangeRate'], 0); ?> GNF/USD</p>
                                    <p><strong>Total Frais:</strong> <span class="currency-gnf"><?php echo number_format($currentImport['TotalFees'], 0); ?> GNF</span></p>
                                    <p><strong>Coût Total:</strong> <span class="currency-gnf"><?php echo number_format($currentImport['TotalCostGNF'], 0); ?> GNF</span></p>
                                </div>
                                
                                <div class="import-card">
                                    <h6>💡 Conseils pour Répartition des Frais:</h6>
                                    <ul style="font-size: 12px;">
                                        <li>Répartir selon la valeur du produit</li>
                                        <li>Considérer le poids/volume</li>
                                        <li>Produits chers = plus de frais</li>
                                        <li>Marge recommandée: 20-35%</li>
                                    </ul>
                                </div>

                                <div class="import-card">
                                    <h6>🧮 Calculateur Rapide de Frais:</h6>
                                    <div class="input-group">
                                        <input type="number" id="quickCalcPercent" placeholder="% de <?php echo number_format($currentImport['TotalFees'], 0); ?>" 
                                               onchange="quickCalculateFees()" style="width: 60px;" />
                                        <span> % = </span>
                                        <strong id="quickCalcResult" class="currency-gnf">0 GNF</strong>
                                    </div>
                                </div>
                            </div>
                        </div>
                    </div>
                </div>
                <?php } else { ?>
                <div class="alert alert-warning">
                    <strong>⚠️ Aucun dossier d'importation sélectionné!</strong><br>
                    Sélectionnez ou créez un dossier d'importation d'abord.
                </div>
                <?php } ?>
            </div>

            <!-- TAB 4: APPLICATION DES PRIX -->
            <div id="tab-pricing" class="tab-content">
                <?php if ($currentImport) { ?>
                <div class="row-fluid">
                    <div class="span12">
                        <div class="widget-box">
                            <div class="widget-title">
                                <span class="icon"><i class="icon-tags"></i></span>
                                <h5>🏷️ Application des Prix de Vente</h5>
                            </div>
                            <div class="widget-content nopadding">
                                <?php
                                $importProductsQuery = mysqli_query($con, "
                                    SELECT pa.*, p.ProductName, p.Price as CurrentPrice 
                                    FROM tblproductarrivals pa 
                                    LEFT JOIN tblproducts p ON p.ID = pa.ProductID 
                                    WHERE pa.ImportationID = $currentImportId 
                                    ORDER BY pa.ID DESC
                                ");
                                
                                if (mysqli_num_rows($importProductsQuery) > 0) {
                                ?>
                                <form method="post">
                                    <table class="table table-bordered table-striped">
                                        <thead>
                                            <tr>
                                                <th>Produit</th>
                                                <th>Qté</th>
                                                <th>Prix USD</th>
                                                <th>Frais Alloués</th>
                                                <th>Prix Revient</th>
                                                <th>Prix Actuel</th>
                                                <th>Prix Suggéré</th>
                                                <th>Nouveau Prix</th>
                                                <th>Marge</th>
                                                <th>Statut</th>
                                            </tr>
                                        </thead>
                                        <tbody>
                                            <?php while ($product = mysqli_fetch_assoc($importProductsQuery)) { ?>
                                            <tr>
                                                <td>
                                                    <strong><?php echo $product['ProductName']; ?></strong>
                                                    <input type="hidden" name="arrival_id[]" value="<?php echo $product['ID']; ?>" />
                                                </td>
                                                <td><?php echo $product['Quantity']; ?></td>
                                                <td class="currency-usd">$<?php echo number_format($product['UnitPriceUSD'], 2); ?></td>
                                                <td class="currency-gnf"><?php echo number_format($product['AllocatedFees'], 0); ?></td>
                                                <td class="currency-gnf">
                                                    <strong><?php echo number_format($product['UnitCostPrice'], 0); ?></strong>
                                                </td>
                                                <td class="currency-gnf"><?php echo number_format($product['CurrentPrice'], 0); ?></td>
                                                <td class="currency-gnf"><?php echo number_format($product['SuggestedSalePrice'], 0); ?></td>
                                                <td>
                                                    <input type="number" name="sale_price[]" 
                                                           value="<?php echo $product['SuggestedSalePrice']; ?>" 
                                                           step="0.01" min="0" style="width: 100px;" 
                                                           onchange="calculateMargin(this, <?php echo $product['UnitCostPrice']; ?>)" />
                                                </td>
                                                <td>
                                                    <input type="number" name="target_margin[]" 
                                                           value="<?php echo $product['SuggestedMargin']; ?>" 
                                                           step="0.01" min="0" style="width: 60px;" />%
                                                </td>
                                                <td>
                                                    <?php if ($product['AppliedToProduct']) { ?>
                                                        <span class="label label-success">✅ Appliqué</span>
                                                    <?php } else { ?>
                                                        <span class="label label-warning">⏳ En attente</span>
                                                    <?php } ?>
                                                </td>
                                            </tr>
                                            <?php } ?>
                                        </tbody>
                                    </table>
                                    
                                    <div style="padding: 15px;">
                                        <button type="submit" name="apply_sale_prices" class="btn btn-success btn-large">
                                            <i class="icon-check"></i> 🏷️ Appliquer Tous les Prix de Vente
                                        </button>
                                        <p class="help-block">
                                            <strong>⚠️ Attention:</strong> Cette action mettra à jour les prix de vente dans votre catalogue produits.
                                        </p>
                                    </div>
                                </form>
                                <?php } else { ?>
                                <div style="padding: 20px; text-align: center;">
                                    <p>Aucun produit ajouté à cette importation.</p>
                                    <a href="#tab-products" onclick="showTab('products')" class="btn btn-primary">
                                        <i class="icon-plus"></i> Ajouter des Produits
                                    </a>
                                </div>
                                <?php } ?>
                            </div>
                        </div>
                    </div>
                </div>
                <?php } else { ?>
                <div class="alert alert-warning">
                    <strong>⚠️ Aucun dossier d'importation sélectionné!</strong><br>
                    Sélectionnez ou créez un dossier d'importation d'abord.
                </div>
                <?php } ?>
            </div>

            <!-- TAB 5: RAPPORTS -->
            <div id="tab-reports" class="tab-content">
                <div class="row-fluid">
                    <div class="span12">
                        <div class="widget-box">
                            <div class="widget-title">
                                <span class="icon"><i class="icon-bar-chart"></i></span>
                                <h5>📊 Rapports d'Importation</h5>
                            </div>
                            <div class="widget-content">
                                <?php
                                // Statistiques générales
                                $statsQuery = mysqli_query($con, "
                                    SELECT 
                                        COUNT(*) as TotalImports,
                                        SUM(TotalValueUSD) as TotalValueUSD,
                                        SUM(TotalFees) as TotalFees,
                                        SUM(TotalCostGNF) as TotalCostGNF,
                                        AVG(ExchangeRate) as AvgExchangeRate
                                    FROM tblimportations 
                                    WHERE ImportDate >= DATE_SUB(NOW(), INTERVAL 12 MONTH)
                                ");
                                
                                if ($statsQuery && mysqli_num_rows($statsQuery) > 0) {
                                    $stats = mysqli_fetch_assoc($statsQuery);
                                ?>
                                <div class="row-fluid">
                                    <div class="span3">
                                        <div class="stat-box" style="background: #3498db; color: white; padding: 20px; text-align: center; border-radius: 5px;">
                                            <h3><?php echo $stats['TotalImports']; ?></h3>
                                            <p>Importations (12 mois)</p>
                                        </div>
                                    </div>
                                    <div class="span3">
                                        <div class="stat-box" style="background: #2ecc71; color: white; padding: 20px; text-align: center; border-radius: 5px;">
                                            <h3>$<?php echo number_format($stats['TotalValueUSD'], 0); ?></h3>
                                            <p>Valeur Totale (USD)</p>
                                        </div>
                                    </div>
                                    <div class="span3">
                                        <div class="stat-box" style="background: #e74c3c; color: white; padding: 20px; text-align: center; border-radius: 5px;">
                                            <h3><?php echo number_format($stats['TotalFees'], 0); ?></h3>
                                            <p>Total Frais (GNF)</p>
                                        </div>
                                    </div>
                                    <div class="span3">
                                        <div class="stat-box" style="background: #9b59b6; color: white; padding: 20px; text-align: center; border-radius: 5px;">
                                            <h3><?php echo number_format($stats['AvgExchangeRate'], 0); ?></h3>
                                            <p>Taux Moyen (GNF/USD)</p>
                                        </div>
                                    </div>
                                </div>
                                <?php } ?>
                                
                                <hr>
                                
                                <!-- Détail par importation -->
                                <h5>📋 Détail des Importations Récentes</h5>
                                <table class="table table-bordered table-striped">
                                    <thead>
                                        <tr>
                                            <th>Référence</th>
                                            <th>Date</th>
                                            <th>Fournisseur</th>
                                            <th>Valeur (USD)</th>
                                            <th>Frais (GNF)</th>
                                            <th>Total (GNF)</th>
                                            <th>Taux</th>
                                            <th>Produits</th>
                                            <th>Statut</th>
                                        </tr>
                                    </thead>
                                    <tbody>
                                        <?php
                                        $detailQuery = mysqli_query($con, "
                                            SELECT i.*, s.SupplierName,
                                                   COUNT(pa.ID) as ProductCount,
                                                   SUM(pa.Quantity) as TotalQuantity
                                            FROM tblimportations i 
                                            LEFT JOIN tblsupplier s ON s.ID = i.SupplierID 
                                            LEFT JOIN tblproductarrivals pa ON pa.ImportationID = i.ID
                                            GROUP BY i.ID 
                                            ORDER BY i.ImportDate DESC 
                                            LIMIT 20
                                        ");
                                        
                                        while ($detail = mysqli_fetch_assoc($detailQuery)) {
                                        ?>
                                        <tr>
                                            <td>
                                                <strong><?php echo $detail['ImportRef']; ?></strong><br>
                                                <small><?php echo $detail['BLNumber']; ?></small>
                                            </td>
                                            <td><?php echo date('d/m/Y', strtotime($detail['ImportDate'])); ?></td>
                                            <td><?php echo $detail['SupplierName']; ?></td>
                                            <td class="currency-usd">$<?php echo number_format($detail['TotalValueUSD'], 0); ?></td>
                                            <td class="currency-gnf"><?php echo number_format($detail['TotalFees'], 0); ?></td>
                                            <td class="currency-gnf"><strong><?php echo number_format($detail['TotalCostGNF'], 0); ?></strong></td>
                                            <td><?php echo number_format($detail['ExchangeRate'], 0); ?></td>
                                            <td>
                                                <?php echo $detail['ProductCount']; ?> types<br>
                                                <small><?php echo $detail['TotalQuantity']; ?> unités</small>
                                            </td>
                                            <td>
                                                <span class="label import-status-<?php echo $detail['Status']; ?>">
                                                    <?php echo strtoupper($detail['Status']); ?>
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
            </div>
        </div>
    </div>
</div>

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

<script>
// GESTION DES ONGLETS
function showTab(tabName) {
    // Masquer tous les onglets
    document.querySelectorAll('.tab-content').forEach(tab => {
        tab.classList.remove('active');
    });
    
    // Désactiver tous les liens
    document.querySelectorAll('.nav-tabs li').forEach(li => {
        li.classList.remove('active');
    });
    
    // Activer l'onglet sélectionné
    document.getElementById('tab-' + tabName).classList.add('active');
    
    // Activer le lien correspondant
    document.querySelector('a[href="#tab-' + tabName + '"]').parentNode.classList.add('active');
}

// GESTION DES FRAIS
function toggleFeeRow(checkbox, index) {
    const row = checkbox.closest('.fee-row');
    const inputs = row.querySelectorAll('input:not([type="checkbox"])');
    
    inputs.forEach(input => {
        input.disabled = !checkbox.checked;
        if (!checkbox.checked) {
            input.value = input.type === 'number' ? '0' : '';
        }
    });
    
    calculateTotalFees();
}

function calculateTotalFees() {
    const checkedBoxes = document.querySelectorAll('input[name="fee_type_id[]"]:checked');
    let totalFees = 0;
    let breakdown = '<table style="width: 100%; font-size: 12px;">';
    
    checkedBoxes.forEach((checkbox, index) => {
        const row = checkbox.closest('.fee-row');
        const amountInput = row.querySelector('.fee-amount');
        const amount = parseFloat(amountInput.value) || 0;
        const feeName = checkbox.nextElementSibling.textContent.trim();
        const isClientPaid = row.querySelector('input[name="payed_by_client[]"]').checked;
        
        if (amount > 0) {
            totalFees += amount;
            breakdown += `<tr>
                <td>${feeName}</td>
                <td style="text-align: right;">${amount.toLocaleString('fr-FR')} GNF</td>
                <td>${isClientPaid ? '<span style="color: blue;">(Client)</span>' : ''}</td>
            </tr>`;
        }
    });
    
    breakdown += `<tr style="border-top: 2px solid #333; font-weight: bold;">
        <td>TOTAL:</td>
        <td style="text-align: right; color: #e74c3c;">${totalFees.toLocaleString('fr-FR')} GNF</td>
        <td></td>
    </tr></table>`;
    
    document.getElementById('feesBreakdown').innerHTML = breakdown;
    document.getElementById('feesSummary').style.display = totalFees > 0 ? 'block' : 'none';
}

// CALCUL DES COÛTS PRODUIT
function calculateCosts() {
    const quantity = parseFloat(document.getElementById('quantity').value) || 0;
    const unitPriceUSD = parseFloat(document.getElementById('unitPriceUSD').value) || 0;
    const allocatedFees = parseFloat(document.getElementById('allocatedFees').value) || 0;
    const margin = parseFloat(document.getElementById('suggestedMargin').value) || 0;
    
    // Obtenir le taux de change depuis l'importation courante
    const exchangeRate = <?php echo $currentImport ? $currentImport['ExchangeRate'] : 12700; ?>;
    
    if (quantity > 0 && unitPriceUSD > 0) {
        // Calculs
        const totalValueUSD = unitPriceUSD * quantity;
        const totalValueGNF = totalValueUSD * exchangeRate;
        const costPriceUnitGNF = (unitPriceUSD * exchangeRate) + (allocatedFees / quantity);
        const salePriceSuggested = costPriceUnitGNF * (1 + (margin / 100));
        const totalSaleValue = salePriceSuggested * quantity;
        const totalProfit = totalSaleValue - (totalValueGNF + allocatedFees);
        
        const breakdown = `
            <table style="width: 100%; font-size: 12px;">
                <tr><td>Prix unitaire USD:</td><td style="text-align: right; color: #2ecc71;">${unitPriceUSD.toFixed(2)}</td></tr>
                <tr><td>Quantité:</td><td style="text-align: right;">${quantity}</td></tr>
                <tr><td>Valeur totale USD:</td><td style="text-align: right; color: #2ecc71;">${totalValueUSD.toFixed(2)}</td></tr>
                <tr><td>Taux de change:</td><td style="text-align: right;">${exchangeRate.toLocaleString('fr-FR')} GNF/USD</td></tr>
                <tr><td>Valeur totale GNF:</td><td style="text-align: right; color: #e74c3c;">${totalValueGNF.toLocaleString('fr-FR')} GNF</td></tr>
                <tr><td>Frais alloués:</td><td style="text-align: right; color: #e74c3c;">+${allocatedFees.toLocaleString('fr-FR')} GNF</td></tr>
                <tr style="border-top: 1px solid #333; font-weight: bold;">
                    <td>Prix de revient unitaire:</td>
                    <td style="text-align: right; color: #e74c3c;">${costPriceUnitGNF.toLocaleString('fr-FR')} GNF</td>
                </tr>
                <tr><td>Marge suggérée:</td><td style="text-align: right;">${margin}%</td></tr>
                <tr style="border-top: 1px solid #333; font-weight: bold; background: #f8f9fa;">
                    <td>Prix de vente suggéré:</td>
                    <td style="text-align: right; color: #007bff; font-size: 14px;">${salePriceSuggested.toLocaleString('fr-FR')} GNF</td>
                </tr>
                <tr><td>Valeur vente totale:</td><td style="text-align: right; color: #007bff;">${totalSaleValue.toLocaleString('fr-FR')} GNF</td></tr>
                <tr style="background: #d4edda;"><td>Profit total estimé:</td><td style="text-align: right; color: #28a745; font-weight: bold;">${totalProfit.toLocaleString('fr-FR')} GNF</td></tr>
            </table>
        `;
        
        document.getElementById('costBreakdown').innerHTML = breakdown;
        document.getElementById('costCalculation').style.display = 'block';
    } else {
        document.getElementById('costCalculation').style.display = 'none';
    }
}

// CALCUL RAPIDE DES FRAIS
function quickCalculateFees() {
    const percent = parseFloat(document.getElementById('quickCalcPercent').value) || 0;
    const totalFees = <?php echo $currentImport ? $currentImport['TotalFees'] : 0; ?>;
    const result = (totalFees * percent) / 100;
    
    document.getElementById('quickCalcResult').textContent = result.toLocaleString('fr-FR') + ' GNF';
    
    // Mettre à jour le champ frais alloués s'il existe
    const allocatedFeesInput = document.getElementById('allocatedFees');
    if (allocatedFeesInput) {
        allocatedFeesInput.value = result.toFixed(2);
        calculateCosts();
    }
}

// CALCUL DE MARGE DANS LE TABLEAU PRICING
function calculateMargin(salePriceInput, costPrice) {
    const salePrice = parseFloat(salePriceInput.value) || 0;
    const marginPercent = salePrice > 0 ? ((salePrice - costPrice) / salePrice) * 100 : 0;
    
    // Trouver le champ marge correspondant dans la même ligne
    const row = salePriceInput.closest('tr');
    const marginInput = row.querySelector('input[name="target_margin[]"]');
    if (marginInput) {
        marginInput.value = marginPercent.toFixed(2);
    }
    
    // Coloriser selon la marge
    if (marginPercent < 0) {
        salePriceInput.style.backgroundColor = '#f8d7da'; // Rouge - perte
    } else if (marginPercent < 10) {
        salePriceInput.style.backgroundColor = '#fff3cd'; // Jaune - faible
    } else if (marginPercent > 30) {
        salePriceInput.style.backgroundColor = '#d4edda'; // Vert - excellente
    } else {
        salePriceInput.style.backgroundColor = ''; // Normal
    }
}

// MISE À JOUR DES INFOS PRODUIT
function updateProductInfo() {
    const productSelect = document.getElementById('productSelect');
    const selectedOption = productSelect.options[productSelect.selectedIndex];
    
    if (selectedOption && selectedOption.value) {
        const currentPrice = selectedOption.getAttribute('data-current-price');
        
        // Optionnel: pré-remplir certains champs basés sur le produit sélectionné
        console.log('Produit sélectionné, prix actuel:', currentPrice);
    }
}

// INITIALISATION
document.addEventListener('DOMContentLoaded', function() {
    // Activer la première tab par défaut
    showTab('imports');
    
    // Calculer les coûts si des valeurs sont déjà présentes
    calculateCosts();
    
    // Calculer les frais si des cases sont cochées
    calculateTotalFees();
});
</script>

</body>
</html>