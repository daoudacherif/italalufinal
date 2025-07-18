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

// Obtenir la structure de tblproducts pour vérifier le nom exact de la colonne
$tableCheck = mysqli_query($con, "DESCRIBE tblproducts");
$stockColumnName = "Stock"; // Par défaut
$stockColumnFound = false;

if ($tableCheck) {
    while ($col = mysqli_fetch_assoc($tableCheck)) {
        // Rechercher la colonne de stock (peut avoir un nom différent ou une casse différente)
        if (strtolower($col['Field']) === 'stock') {
            $stockColumnName = $col['Field']; // Utiliser le nom exact avec la casse correcte
            $stockColumnFound = true;
            break;
        }
    }
}

// NOUVELLE FONCTION : Mise à jour automatique du prix d'achat moyen
function updateProductCostPrice($con, $productId) {
    // Calculer le prix d'achat moyen pondéré des 5 derniers arrivages
    $sql = "
        SELECT 
            AVG(Cost / Quantity) as avgCost,
            MAX(ArrivalDate) as lastUpdate
        FROM (
            SELECT Cost, Quantity, ArrivalDate
            FROM tblproductarrivals 
            WHERE ProductID = $productId 
            AND Quantity > 0 
            AND Cost > 0
            ORDER BY ArrivalDate DESC 
            LIMIT 5
        ) recent_arrivals
    ";
    
    $result = mysqli_query($con, $sql);
    if ($result && mysqli_num_rows($result) > 0) {
        $data = mysqli_fetch_assoc($result);
        $avgCost = $data['avgCost'] ? $data['avgCost'] : 0;
        
        // Mettre à jour le produit
        $updateSql = "
            UPDATE tblproducts 
            SET 
                CostPrice = $avgCost,
                LastCostUpdate = NOW()
            WHERE ID = $productId
        ";
        
        mysqli_query($con, $updateSql);
        return $avgCost;
    }
    return 0;
}

// 1) Handle arrival submission (multiple products)
if (isset($_POST['submit'])) {
    $arrivalDate = $_POST['arrivaldate'];
    $deliveryDate = $_POST['deliverydate']; // NOUVEAU
    $deliveryNote = mysqli_real_escape_string($con, $_POST['deliverynote']); // NOUVEAU
    $supplierID = intval($_POST['supplierid']);
    
    // Check if we have products to process
    if(isset($_POST['productid']) && is_array($_POST['productid'])) {
        $productIDs = $_POST['productid'];
        $quantities = $_POST['quantity'];
        $customPrices = $_POST['customprice']; // NOUVEAU : prix personnalisés
        $comments = isset($_POST['comments']) ? $_POST['comments'] : array();
        
        $successCount = 0;
        $errorCount = 0;
        $errorDetails = array();
        
        // Process each product
        foreach($productIDs as $index => $productID) {
            $productID = intval($productID);
            $quantity = intval($quantities[$index]);
            $customPrice = floatval($customPrices[$index]); // NOUVEAU : prix personnalisé
            $comment = isset($comments[$index]) ? mysqli_real_escape_string($con, $comments[$index]) : '';
            
            // Validate data
            if ($productID <= 0 || $quantity <= 0 || $customPrice <= 0) {
                $errorCount++;
                $errorDetails[] = "Produit ID $productID invalide, quantité nulle ou prix personnalisé invalide";
                continue;
            }
            
            // Get current stock
            $stockQ = mysqli_query($con, "SELECT $stockColumnName FROM tblproducts WHERE ID='$productID' LIMIT 1");
            if (!$stockQ) {
                $errorCount++;
                $errorDetails[] = "Erreur de requête: " . mysqli_error($con);
                continue;
            }
            
            if (mysqli_num_rows($stockQ) == 0) {
                $errorCount++;
                $errorDetails[] = "Produit ID $productID non trouvé";
                continue;
            }
            
            $stockR = mysqli_fetch_assoc($stockQ);
            $currentStock = isset($stockR[$stockColumnName]) ? intval($stockR[$stockColumnName]) : 0;
            
            // Calculate total cost avec prix personnalisé
            $cost = $customPrice * $quantity;
            
            // Insert into tblproductarrivals avec les nouveaux champs
            $sqlInsert = "
              INSERT INTO tblproductarrivals(ProductID, SupplierID, ArrivalDate, DeliveryDate, DeliveryNote, Quantity, Cost, Comments)
              VALUES('$productID', '$supplierID', '$arrivalDate', '$deliveryDate', '$deliveryNote', '$quantity', '$cost', '$comment')
            ";
            $queryInsert = mysqli_query($con, $sqlInsert);
            
            if ($queryInsert) {
                // Calcul du nouveau stock
                $newStock = $currentStock + $quantity;
                
                // Mise à jour du stock seulement
                $sqlUpdate = "UPDATE tblproducts SET $stockColumnName = $newStock WHERE ID=$productID";
                $updateResult = mysqli_query($con, $sqlUpdate);
                
                if ($updateResult) {
                    if (mysqli_affected_rows($con) > 0) {
                        // NOUVEAU : Mise à jour automatique du prix d'achat
                        $newCostPrice = updateProductCostPrice($con, $productID);
                        $successCount++;
                    } else {
                        $errorCount++;
                        $errorDetails[] = "Produit ID $productID trouvé mais aucun stock mis à jour. Stock actuel: $currentStock";
                    }
                } else {
                    $errorCount++;
                    $errorDetails[] = "Erreur mise à jour stock pour ID $productID: " . mysqli_error($con);
                }
            } else {
                $errorCount++;
                $errorDetails[] = "Erreur insertion arrivage pour ID $productID: " . mysqli_error($con);
            }
        }
        
        // Display result message with details
        if ($successCount > 0) {
            echo "<script>alert('$successCount arrivages de produits enregistrés avec succès! " . 
                  ($errorCount > 0 ? "$errorCount avec erreurs." : "") . "');</script>";
        } else {
            $errorMsg = "Erreur lors de l\\'enregistrement des arrivages de produits!\\n" . implode("\\n", $errorDetails);
            echo "<script>alert('$errorMsg');</script>";
        }
    } else {
        echo "<script>alert('Aucun produit sélectionné!');</script>";
    }
    
    echo "<script>window.location.href='arrival.php'</script>";
    exit;
}

// FIX: Add handling for the single product form
if (isset($_POST['submit_single'])) {
    $arrivalDate = $_POST['arrivaldate'];
    $deliveryDate = $_POST['deliverydate']; // NOUVEAU
    $deliveryNote = mysqli_real_escape_string($con, $_POST['deliverynote']); // NOUVEAU
    $supplierID = intval($_POST['supplierid']);
    $productID = intval($_POST['productid']);
    $quantity = intval($_POST['quantity']);
    $customPrice = floatval($_POST['customprice']); // NOUVEAU : prix personnalisé
    $comment = mysqli_real_escape_string($con, $_POST['comments'] ?? '');
    
    // Calculate cost avec prix personnalisé
    $cost = $customPrice * $quantity;
    
    // Validate data
    if ($productID <= 0 || $quantity <= 0 || $supplierID <= 0 || $customPrice <= 0) {
        echo "<script>alert('Données invalides! Vérifiez le prix personnalisé.');</script>";
        echo "<script>window.location.href='arrival.php'</script>";
        exit;
    }
    
    // Get current stock
    $stockQuery = mysqli_query($con, "SELECT $stockColumnName FROM tblproducts WHERE ID=$productID LIMIT 1");
    if (!$stockQuery || mysqli_num_rows($stockQuery) == 0) {
        echo "<script>alert('Produit non trouvé!');</script>";
        echo "<script>window.location.href='arrival.php'</script>";
        exit;
    }
    
    $stockRow = mysqli_fetch_assoc($stockQuery);
    $currentStock = isset($stockRow[$stockColumnName]) ? intval($stockRow[$stockColumnName]) : 0;
    $newStock = $currentStock + $quantity;
    
    // Insert into tblproductarrivals avec les nouveaux champs
    $sqlInsert = "
      INSERT INTO tblproductarrivals(ProductID, SupplierID, ArrivalDate, DeliveryDate, DeliveryNote, Quantity, Cost, Comments)
      VALUES('$productID', '$supplierID', '$arrivalDate', '$deliveryDate', '$deliveryNote', '$quantity', '$cost', '$comment')
    ";
    $queryInsert = mysqli_query($con, $sqlInsert);
    
    if ($queryInsert) {
        // Update product stock with direct value
        $sqlUpdate = "UPDATE tblproducts SET $stockColumnName = $newStock WHERE ID=$productID";
        $updateResult = mysqli_query($con, $sqlUpdate);
        
        if ($updateResult) {
            // Check if any rows were actually updated
            if (mysqli_affected_rows($con) > 0) {
                // NOUVEAU : Mise à jour automatique du prix d'achat
                $newCostPrice = updateProductCostPrice($con, $productID);
                
                echo "<script>alert('Arrivage enregistré avec succès! Stock: $currentStock -> $newStock. Coût: $cost. Nouveau prix d\\'achat moyen: " . number_format($newCostPrice, 2) . ". Bon: $deliveryNote');</script>";
            } else {
                echo "<script>alert('Produit trouvé mais stock non modifié. Vérifiez que l\\'ID du produit est correct.');</script>";
            }
        } else {
            echo "<script>alert('Erreur lors de la mise à jour du stock: " . mysqli_error($con) . "');</script>";
        }
    } else {
        echo "<script>alert('Erreur lors de l\\'enregistrement de l\\'arrivage: " . mysqli_error($con) . "');</script>";
    }
    
    echo "<script>window.location.href='arrival.php'</script>";
    exit;
}

// 2) Handle adding product to temporary arrival list
if (isset($_POST['addtoarrival'])) {
    $productId = intval($_POST['productid']);
    $quantity = max(1, intval($_POST['quantity']));
    $customPrice = floatval($_POST['customprice']); // NOUVEAU : prix personnalisé
    
    // Check if valid product
    if ($productId > 0 && $customPrice > 0) {
        // Get product info
        $prodInfo = mysqli_query($con, "SELECT ProductName, Price FROM tblproducts WHERE ID='$productId' LIMIT 1");
        if (mysqli_num_rows($prodInfo) > 0) {
            $productData = mysqli_fetch_assoc($prodInfo);
            
            // Initialize temp array if not exists
            if (!isset($_SESSION['temp_arrivals'])) {
                $_SESSION['temp_arrivals'] = array();
            }
            
            // Add to temp storage or update quantity if exists
            $found = false;
            foreach ($_SESSION['temp_arrivals'] as $key => $item) {
                if ($item['productid'] == $productId) {
                    $_SESSION['temp_arrivals'][$key]['quantity'] += $quantity;
                    $_SESSION['temp_arrivals'][$key]['customprice'] = $customPrice; // Mettre à jour le prix personnalisé
                    $found = true;
                    break;
                }
            }
            
            if (!$found) {
                $_SESSION['temp_arrivals'][] = array(
                    'productid' => $productId,
                    'productname' => $productData['ProductName'],
                    'unitprice' => $productData['Price'], // Prix original pour référence
                    'customprice' => $customPrice, // NOUVEAU : prix personnalisé
                    'quantity' => $quantity,
                    'comments' => ''
                );
            }
            
            echo "<script>alert('Produit ajouté à la liste d\'arrivage avec prix personnalisé!');</script>";
        }
    } else {
        echo "<script>alert('Produit invalide ou prix personnalisé invalide!');</script>";
    }
    
    echo "<script>window.location.href='arrival.php'</script>";
    exit;
}

// 3) Remove from temp arrival list
if (isset($_GET['delid'])) {
    $delid = intval($_GET['delid']);
    
    if (isset($_SESSION['temp_arrivals']) && isset($_SESSION['temp_arrivals'][$delid])) {
        // Remove the item
        unset($_SESSION['temp_arrivals'][$delid]);
        // Re-index array
        $_SESSION['temp_arrivals'] = array_values($_SESSION['temp_arrivals']);
        
        echo "<script>alert('Produit retiré de la liste d\'arrivage!');</script>";
        echo "<script>window.location.href='arrival.php'</script>";
        exit;
    }
}

// 4) Clear all temp arrivals
if (isset($_GET['clear'])) {
    unset($_SESSION['temp_arrivals']);
    echo "<script>alert('Liste d\'arrivage vidée!');</script>";
    echo "<script>window.location.href='arrival.php'</script>";
    exit;
}

// Get product names for datalist
$productNamesQuery = mysqli_query($con, "SELECT DISTINCT ProductName FROM tblproducts ORDER BY ProductName ASC");
$productNames = array();
if ($productNamesQuery) {
    while ($row = mysqli_fetch_assoc($productNamesQuery)) {
        $productNames[] = $row['ProductName'];
    }
}

// 5) Liste des arrivages récents - MODIFIÉ pour inclure les nouveaux champs
$sqlArrivals = "
  SELECT a.ID as arrivalID,
         a.ArrivalDate,
         a.DeliveryDate,
         a.DeliveryNote,
         a.Quantity,
         a.Cost,
         a.Comments,
         p.ProductName,
         p.Price as UnitPrice,
         s.SupplierName
  FROM tblproductarrivals a
  LEFT JOIN tblproducts p ON p.ID = a.ProductID
  LEFT JOIN tblsupplier s ON s.ID = a.SupplierID
  ORDER BY a.ID DESC
  LIMIT 50
";
$resArrivals = mysqli_query($con, $sqlArrivals);
?>
<!DOCTYPE html>
<html lang="fr">
<style>
  .control-label {
    font-size: 20px;
    font-weight: bolder;
    color: black;  
  }
  .stock-status {
    display: inline-block;
    padding: 2px 5px;
    font-size: 11px;
    border-radius: 3px;
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
  .custom-price {
    background-color: #e8f4f8;
    border: 1px solid #bee5eb;
  }
  .cart-total {
    font-weight: bold;
    color: #007bff;
  }
  
  /* NOUVEAUX STYLES POUR LES CHAMPS DE LIVRAISON */
  .delivery-fields {
    background-color: #f8f9fa;
    border: 1px solid #dee2e6;
    border-radius: 5px;
    padding: 15px;
    margin: 10px 0;
  }
  
  .delivery-note {
    background-color: #e3f2fd;
    border: 1px solid #2196f3;
    border-radius: 3px;
    font-family: 'Courier New', monospace;
  }
  
  .delivery-date {
    background-color: #fff3e0;
    border: 1px solid #ff9800;
    border-radius: 3px;
  }
  
  .delivery-info {
    font-size: 12px;
    color: #6c757d;
    margin-top: 5px;
  }
  
  .delivery-badge {
    display: inline-block;
    padding: 2px 6px;
    font-size: 10px;
    background-color: #28a745;
    color: white;
    border-radius: 10px;
    margin-left: 5px;
  }
  
  .delivery-missing {
    color: #dc3545;
    font-style: italic;
  }
  
  .arrivals-table {
    font-size: 12px;
  }
  
  .arrivals-table th {
    background-color: #343a40;
    color: white;
    text-align: center;
  }
  
  .arrivals-table .delivery-note-cell {
    font-family: 'Courier New', monospace;
    font-weight: bold;
    color: #007bff;
  }
  
  .arrivals-table .delivery-date-cell {
    color: #28a745;
    font-weight: 500;
  }
  
  /* Responsive improvements */
  @media (max-width: 768px) {
    .delivery-fields .span4,
    .delivery-fields .span6 {
      width: 100%;
      margin-bottom: 10px;
    }
  }
</style>
<head>
    <title>Gestion de Stock | Arrivages de Produits</title>
    <?php include_once('includes/cs.php'); ?>
    <?php include_once('includes/responsive.php'); ?>
</head>
<body>
<?php include_once('includes/header.php'); ?>
<?php include_once('includes/sidebar.php'); ?>

<div id="content">
  <div id="content-header">
    <div id="breadcrumb">
      <a href="dashboard.php" class="tip-bottom"><i class="icon-home"></i> Accueil</a>
      <a href="arrival.php" class="current">Arrivages de Produits</a>
    </div>
    <h1>🚚 Gérer les Arrivages de Produits (Entrée Stock + Prix Personnalisé + Bon de Livraison)</h1>
  </div>

  <div class="container-fluid">
    <!-- WIDGET TOTAL PANIER FIXE -->
    <?php if (isset($_SESSION['temp_arrivals']) && count($_SESSION['temp_arrivals']) > 0) {
      $totalPanierWidget = 0;
      foreach ($_SESSION['temp_arrivals'] as $item) {
        $totalPanierWidget += ($item['customprice'] * $item['quantity']);
      }
    ?>
    <div class="alert alert-success" style="margin-bottom: 20px; position: sticky; top: 0; z-index: 100; box-shadow: 0 2px 4px rgba(0,0,0,0.1);">
      <div class="row-fluid">
        <div class="span8">
          <strong><i class="icon-shopping-cart"></i> PANIER D'ARRIVAGE:</strong> 
          <?php echo count($_SESSION['temp_arrivals']); ?> produit(s) en attente
        </div>
        <div class="span4" style="text-align: right;">
          <strong>TOTAL: <span class="cart-total" style="font-size: 18px; color: #2d6987;"><?php echo number_format($totalPanierWidget, 2); ?></span> Gnf</strong>
        </div>
      </div>
    </div>
    <?php } ?>
    
    <hr>
    
    <!-- FORMULAIRE DE RECHERCHE DE PRODUITS -->
    <div class="row-fluid">
      <div class="span12">
        <form method="get" action="arrival.php" class="form-inline">
          <label>Rechercher des Produits:</label>
          <input type="text" name="searchTerm" class="span3" placeholder="Nom du produit..." list="productsList" />
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

    <!-- RÉSULTATS DE RECHERCHE -->
    <?php
    if (!empty($_GET['searchTerm'])) {
      $searchTerm = mysqli_real_escape_string($con, $_GET['searchTerm']);
      $sql = "
        SELECT 
            p.ID,
            p.ProductName,
            p.Price,
            p.$stockColumnName as Stock,
            c.CategoryName
        FROM tblproducts p
        LEFT JOIN tblcategory c ON c.ID = p.CatID
        WHERE 
            p.ProductName LIKE ?
      ";

      // Prepare query
      $stmt = mysqli_prepare($con, $sql);
      if (!$stmt) {
        die("MySQL prepare error: " . mysqli_error($con));
      }
      
      $searchParam = "%$searchTerm%";
      mysqli_stmt_bind_param($stmt, "s", $searchParam);
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
              <th>Nom du Produit</th>
              <th>Catégorie</th>
              <th>Prix Actuel</th>
              <th>Prix Personnalisé</th>
              <th>Stock</th>
              <th>Quantité</th>
              <th>Ajouter à l'Arrivage</th>
            </tr>
          </thead>
          <tbody>
          <?php
          $i = 1;
          while ($row = mysqli_fetch_assoc($res)) {
            $stockStatus = '';
            
            if ($row['Stock'] <= 0) {
              $stockStatus = '<span class="stock-status stock-danger">Rupture</span>';
            } elseif ($row['Stock'] < 5) {
              $stockStatus = '<span class="stock-status stock-warning">Faible</span>';
            } else {
              $stockStatus = '<span class="stock-status stock-ok">Disponible</span>';
            }
          ?>
            <tr>
              <td><?php echo $i++; ?></td>
              <td><?php echo $row['ProductName']; ?></td>
              <td><?php echo $row['CategoryName']; ?></td>
              <td><small><?php echo number_format($row['Price'], 2); ?></small></td>
              <td>
                <form method="post" action="arrival.php" style="margin:0;">
                  <input type="hidden" name="productid" value="<?php echo $row['ID']; ?>" />
                  <input type="number" name="customprice" value="<?php echo $row['Price']; ?>" 
                         step="0.01" min="0.01" style="width:80px;" class="custom-price" 
                         placeholder="Prix perso" title="Prix personnalisé pour ce produit" />
              </td>
              <td><?php echo $row['Stock'] . ' ' . $stockStatus; ?></td>
              <td>
                  <input type="number" name="quantity" value="1" min="1" style="width:60px;" />
              </td>
              <td>
                <button type="submit" name="addtoarrival" class="btn btn-success btn-small">
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
          <p style="color:red;">Aucun produit correspondant trouvé.</p>
        <?php } ?>
      </div>
    </div>
    <hr>
    <?php } ?>

    <!-- LISTE D'ARRIVAGE TEMPORAIRE -->
    <div class="row-fluid">
      <div class="span12">
        <div class="widget-box">
          <div class="widget-title">
            <span class="icon"><i class="icon-th"></i></span>
            <h5>📦 Arrivages de Produits en Attente</h5>
            <?php if (isset($_SESSION['temp_arrivals']) && count($_SESSION['temp_arrivals']) > 0) { ?>
            <a href="arrival.php?clear=1" class="btn btn-small btn-danger" style="float:right;margin:3px;">
              <i class="icon-remove"></i> Tout Effacer
            </a>
            <?php } ?>
          </div>
          <div class="widget-content nopadding">
            <form method="post" action="arrival.php">
            <table class="table table-bordered">
              <thead>
                <tr>
                  <th>#</th>
                  <th>Nom du Produit</th>
                  <th>Prix Actuel</th>
                  <th>Prix Personnalisé</th>
                  <th>Quantité</th>
                  <th>Total</th>
                  <th>Commentaires</th>
                  <th>Action</th>
                </tr>
              </thead>
              <tbody>
                <?php
                if (isset($_SESSION['temp_arrivals']) && count($_SESSION['temp_arrivals']) > 0) {
                  $cnt = 1;
                  $totalPanier = 0;
                  foreach ($_SESSION['temp_arrivals'] as $index => $item) {
                    $totalLine = $item['customprice'] * $item['quantity'];
                    $totalPanier += $totalLine;
                  ?>
                  <tr>
                    <td><?php echo $cnt++; ?></td>
                    <td>
                      <?php echo $item['productname']; ?>
                      <input type="hidden" name="productid[]" value="<?php echo $item['productid']; ?>" />
                    </td>
                    <td><small><?php echo number_format($item['unitprice'], 2); ?></small></td>
                    <td>
                      <input type="number" name="customprice[]" value="<?php echo $item['customprice']; ?>" 
                             step="0.01" min="0.01" style="width:80px;" class="custom-price cart-price-input" required />
                    </td>
                    <td>
                      <input type="number" name="quantity[]" value="<?php echo $item['quantity']; ?>" 
                             min="1" style="width:60px;" class="cart-quantity-input" required />
                    </td>
                    <td class="line-total"><?php echo number_format($totalLine, 2); ?></td>
                    <td>
                      <input type="text" name="comments[]" 
                             value="<?php echo htmlspecialchars($item['comments']); ?>"
                             placeholder="N° Facture, notes..." />
                    </td>
                    <td>
                      <a href="arrival.php?delid=<?php echo $index; ?>" 
                         onclick="return confirm('Êtes-vous sûr de vouloir retirer cet article?');">
                        <i class="icon-trash"></i>
                      </a>
                    </td>
                  </tr>
                  <?php
                  }
                  ?>
                  <!-- LIGNE DE TOTAL -->
                  <tr style="background-color: #f8f9fa; border-top: 2px solid #007bff;">
                    <td colspan="5" style="text-align: right; font-weight: bold; font-size: 16px; padding: 10px;">
                      <strong>TOTAL DU PANIER:</strong>
                    </td>
                    <td style="font-weight: bold; font-size: 18px; color: #007bff; padding: 10px;">
                      <strong><span class="cart-total"><?php echo number_format($totalPanier, 2); ?></span> Gnf</strong>
                    </td>
                    <td colspan="2"></td>
                  </tr>
                  <tr>
                    <td colspan="8" style="padding: 15px;">
                      <!-- NOUVELLE SECTION INFORMATIONS DE LIVRAISON -->
                      <div class="delivery-fields">
                        <h6 style="margin-top: 0; color: #495057;"><i class="icon-truck"></i> 🚚 Informations de Livraison</h6>
                        <div class="row-fluid">
                          <div class="span4">
                            <div class="control-group">
                              <label class="control-label">Date d'Arrivage:</label>
                              <div class="controls">
                                <input type="date" name="arrivaldate" value="<?php echo date('Y-m-d'); ?>" required />
                                <span class="help-inline">Date de réception en stock</span>
                              </div>
                            </div>
                          </div>
                          <div class="span4">
                            <div class="control-group">
                              <label class="control-label">Date de Livraison:</label>
                              <div class="controls">
                                <input type="date" name="deliverydate" value="<?php echo date('Y-m-d'); ?>" class="delivery-date" />
                                <span class="help-inline">Date de livraison par le fournisseur</span>
                              </div>
                            </div>
                          </div>
                          <div class="span4">
                            <div class="control-group">
                              <label class="control-label">N° Bon de Livraison:</label>
                              <div class="controls">
                                <input type="text" name="deliverynote" placeholder="Ex: BL2025-001" maxlength="100" class="delivery-note" />
                                <span class="help-inline">Numéro du bon de livraison</span>
                              </div>
                            </div>
                          </div>
                        </div>
                        <div class="row-fluid">
                          <div class="span12">
                            <div class="control-group">
                              <label class="control-label">Sélectionner Fournisseur:</label>
                              <div class="controls">
                                <select name="supplierid" required>
                                  <option value="">-- Choisir Fournisseur --</option>
                                  <?php
                                  $suppQ = mysqli_query($con, "SELECT ID, SupplierName FROM tblsupplier ORDER BY SupplierName ASC");
                                  while ($sRow = mysqli_fetch_assoc($suppQ)) {
                                    echo '<option value="'.$sRow['ID'].'">'.$sRow['SupplierName'].'</option>';
                                  }
                                  ?>
                                </select>
                              </div>
                            </div>
                          </div>
                        </div>
                      </div>
                      <div style="text-align: center; margin-top: 15px;">
                        <div class="alert alert-info" style="margin-bottom: 15px;">
                          <strong><i class="icon-info-sign"></i> Résumé:</strong> 
                          <?php echo count($_SESSION['temp_arrivals']); ?> produit(s) • 
                          Total: <strong><span class="cart-total"><?php echo number_format($totalPanier, 2); ?></span> Gnf</strong>
                        </div>
                        <button type="submit" name="submit" class="btn btn-success btn-large">
                          <i class="icon-check"></i> 📦 Enregistrer Tous les Arrivages (<span class="cart-total"><?php echo number_format($totalPanier, 2); ?></span> Gnf)
                        </button>
                      </div>
                    </td>
                  </tr>
                <?php } else { ?>
                  <tr>
                    <td colspan="8" style="text-align:center;">Aucun arrivage en attente. Utilisez la recherche ci-dessus pour ajouter des produits.</td>
                  </tr>
                <?php } ?>
              </tbody>
            </table>
            </form>
          </div>
        </div>
      </div>
    </div>

    <hr>

    <!-- FORMULAIRE D'ARRIVAGE PRODUIT UNIQUE -->
    <div class="row-fluid">
      <div class="span12">
        <div class="widget-box">
          <div class="widget-title">
            <span class="icon"><i class="icon-align-justify"></i></span>
            <h5>⚡ Ajout Rapide d'Arrivage Produit Unique</h5>
          </div>
          <div class="widget-content nopadding">
            <form method="post" class="form-horizontal" id="singleArrivalForm">
              
              <!-- NOUVELLE SECTION INFORMATIONS DE LIVRAISON -->
              <div class="delivery-fields">
                <h6 style="margin-top: 0; color: #495057;"><i class="icon-truck"></i> 🚚 Informations de Livraison</h6>
                
                <!-- Arrival Date -->
                <div class="control-group">
                  <label class="control-label">Date d'Arrivage:</label>
                  <div class="controls">
                    <input type="date" name="arrivaldate" value="<?php echo date('Y-m-d'); ?>" required />
                    <span class="help-inline">Date de réception en stock</span>
                  </div>
                </div>

                <!-- Delivery Date -->
                <div class="control-group">
                  <label class="control-label">Date de Livraison:</label>
                  <div class="controls">
                    <input type="date" name="deliverydate" value="<?php echo date('Y-m-d'); ?>" class="delivery-date" />
                    <span class="help-inline">Date de livraison par le fournisseur</span>
                  </div>
                </div>

                <!-- Delivery Note -->
                <div class="control-group">
                  <label class="control-label">N° Bon de Livraison:</label>
                  <div class="controls">
                    <input type="text" name="deliverynote" placeholder="Ex: BL2025-001" maxlength="100" class="delivery-note" />
                    <span class="help-inline">Numéro du bon de livraison du fournisseur</span>
                  </div>
                </div>
              </div>

              <!-- Product -->
              <div class="control-group">
                <label class="control-label">Sélectionner Produit:</label>
                <div class="controls">
                  <select name="productid" id="productSelect" required>
                    <option value="">-- Choisir Produit --</option>
                    <?php
                    // Load products with data-price
                    $prodQ = mysqli_query($con, "SELECT ID, ProductName, Price FROM tblproducts ORDER BY ProductName ASC");
                    while ($pRow = mysqli_fetch_assoc($prodQ)) {
                      echo '<option value="'.$pRow['ID'].'" data-price="'.$pRow['Price'].'">'.$pRow['ProductName'].'</option>';
                    }
                    ?>
                  </select>
                </div>
              </div>

              <!-- Supplier -->
              <div class="control-group">
                <label class="control-label">Sélectionner Fournisseur:</label>
                <div class="controls">
                  <select name="supplierid" required>
                    <option value="">-- Choisir Fournisseur --</option>
                    <?php
                    $suppQ = mysqli_query($con, "SELECT ID, SupplierName FROM tblsupplier ORDER BY SupplierName ASC");
                    while ($sRow = mysqli_fetch_assoc($suppQ)) {
                      echo '<option value="'.$sRow['ID'].'">'.$sRow['SupplierName'].'</option>';
                    }
                    ?>
                  </select>
                </div>
              </div>

              <!-- Prix Personnalisé -->
              <div class="control-group">
                <label class="control-label">Prix Personnalisé:</label>
                <div class="controls">
                  <input type="number" name="customprice" id="customPrice" step="0.01" min="0.01" value="0" 
                         class="custom-price" placeholder="Prix personnalisé" required />
                  <span class="help-inline">Prix à utiliser pour le calcul du coût</span>
                </div>
              </div>

              <!-- Quantity -->
              <div class="control-group">
                <label class="control-label">Quantité:</label>
                <div class="controls">
                  <input type="number" name="quantity" id="quantity" min="1" value="1" required />
                </div>
              </div>

              <!-- Coût Total (calculé automatiquement) -->
              <div class="control-group">
                <label class="control-label">Coût Total (auto):</label>
                <div class="controls">
                  <input type="number" id="costDisplay" step="any" min="0" value="0" readonly 
                         style="background-color: #f5f5f5;" />
                  <span class="help-inline">Prix personnalisé × Quantité</span>
                </div>
              </div>

              <!-- Comments (Optional) -->
              <div class="control-group">
                <label class="control-label">Commentaires (optionnel):</label>
                <div class="controls">
                  <input type="text" name="comments" placeholder="N° Facture, notes..." />
                </div>
              </div>

              <div class="form-actions">
                <button type="submit" name="submit_single" class="btn btn-success">
                  📦 Enregistrer l'Arrivage Unique
                </button>
              </div>
            </form>
          </div>
        </div>
      </div>
    </div>

    <hr>

    <!-- LISTE DES ARRIVAGES RÉCENTS - MODIFIÉE POUR INCLURE LES NOUVELLES COLONNES -->
    <div class="row-fluid">
      <div class="span12">
        <div class="widget-box">
          <div class="widget-title">
            <span class="icon"><i class="icon-th"></i></span>
            <h5>📋 Arrivages Récents de Produits</h5>
          </div>
          <div class="widget-content nopadding">
            <table class="table table-bordered data-table arrivals-table">
              <thead>
                <tr>
                  <th>#</th>
                  <th>Date d'Arrivage</th>
                  <th>Date de Livraison</th>
                  <th>Bon de Livraison</th>
                  <th>Produit</th>
                  <th>Fournisseur</th>
                  <th>Qté</th>
                  <th>Prix Unitaire Actuel</th>
                  <th>Coût Total Enregistré</th>
                  <th>Commentaires</th>
                </tr>
              </thead>
              <tbody>
                <?php
                $cnt = 1;
                while ($row = mysqli_fetch_assoc($resArrivals)) {
                  $unitPrice = floatval($row['UnitPrice']);
                  $qty       = floatval($row['Quantity']);
                  $costRecorded = floatval($row['Cost']);
                  ?>
                  <tr>
                    <td><?php echo $cnt; ?></td>
                    <td><?php echo $row['ArrivalDate']; ?></td>
                    <td class="delivery-date-cell">
                      <?php echo $row['DeliveryDate'] ? $row['DeliveryDate'] : '<span class="delivery-missing">-</span>'; ?>
                    </td>
                    <td class="delivery-note-cell">
                      <?php 
                      if ($row['DeliveryNote']) {
                        echo $row['DeliveryNote'] . '<span class="delivery-badge">✓</span>';
                      } else {
                        echo '<span class="delivery-missing">-</span>';
                      }
                      ?>
                    </td>
                    <td><?php echo $row['ProductName']; ?></td>
                    <td><?php echo $row['SupplierName']; ?></td>
                    <td><?php echo $qty; ?></td>
                    <td><?php echo number_format($unitPrice,2); ?></td>
                    <td><?php echo number_format($costRecorded,2); ?></td>
                    <td><?php echo $row['Comments']; ?></td>
                  </tr>
                  <?php
                  $cnt++;
                }
                ?>
              </tbody>
            </table>
          </div>
        </div>
      </div>
    </div>

    <!-- NOUVELLE SECTION : APERÇU DES MARGES -->
    <div class="row-fluid">
      <div class="span12">
        <div class="widget-box">
          <div class="widget-title">
            <span class="icon"><i class="icon-calculator"></i></span>
            <h5>💰 Impact sur les Marges</h5>
            <a href="margins.php" class="btn btn-small btn-info" style="float:right;margin:3px;">
              <i class="icon-external-link"></i> Voir Toutes les Marges
            </a>
          </div>
          <div class="widget-content nopadding">
            <?php
            // Requête pour les produits récemment arrivés avec calcul de marge
            $sqlRecentMargins = "
                SELECT DISTINCT
                    p.ID,
                    p.ProductName,
                    p.Price as SalePrice,
                    p.CostPrice,
                    p.TargetMargin,
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
                    p.LastCostUpdate
                FROM tblproducts p
                INNER JOIN tblproductarrivals a ON a.ProductID = p.ID
                WHERE a.ArrivalDate >= DATE_SUB(NOW(), INTERVAL 30 DAY)
                ORDER BY p.LastCostUpdate DESC
                LIMIT 10
            ";
            $resRecentMargins = mysqli_query($con, $sqlRecentMargins);
            ?>
            
            <table class="table table-bordered table-striped">
              <thead>
                <tr>
                  <th>Produit</th>
                  <th>Prix Vente</th>
                  <th>Prix Achat Moyen</th>
                  <th>Marge Réelle</th>
                  <th>Marge Cible</th>
                  <th>Profit/Unité</th>
                  <th>Statut</th>
                  <th>Dernière MAJ</th>
                </tr>
              </thead>
              <tbody>
                <?php
                if (mysqli_num_rows($resRecentMargins) > 0) {
                    while ($margin = mysqli_fetch_assoc($resRecentMargins)) {
                        // Déterminer le statut de la marge
                        $statusClass = '';
                        $statusText = '';
                        
                        if ($margin['CostPrice'] == 0) {
                            $statusClass = 'label-info';
                            $statusText = 'Prix d\'achat en attente';
                        } elseif ($margin['TargetMargin'] == 0) {
                            $statusClass = 'label-warning';
                            $statusText = 'Marge cible non définie';
                        } elseif ($margin['ActualMarginPercent'] >= $margin['TargetMargin']) {
                            $statusClass = 'label-success';
                            $statusText = 'Objectif atteint';
                        } else {
                            $statusClass = 'label-important';
                            $statusText = 'Sous l\'objectif';
                        }
                ?>
                <tr>
                  <td><strong><?php echo $margin['ProductName']; ?></strong></td>
                  <td><?php echo number_format($margin['SalePrice'], 2); ?></td>
                  <td><?php echo number_format($margin['CostPrice'], 2); ?></td>
                  <td>
                    <span class="label <?php echo ($margin['ActualMarginPercent'] > 20) ? 'label-success' : (($margin['ActualMarginPercent'] > 10) ? 'label-warning' : 'label-important'); ?>">
                      <?php echo $margin['ActualMarginPercent']; ?>%
                    </span>
                  </td>
                  <td><?php echo $margin['TargetMargin'] ? $margin['TargetMargin'].'%' : '-'; ?></td>
                  <td style="color: <?php echo ($margin['ProfitPerUnit'] > 0) ? '#28a745' : '#dc3545'; ?>; font-weight: bold;">
                    <?php echo number_format($margin['ProfitPerUnit'], 2); ?>
                  </td>
                  <td>
                    <span class="label <?php echo $statusClass; ?>"><?php echo $statusText; ?></span>
                  </td>
                  <td><small><?php echo $margin['LastCostUpdate'] ? date('d/m/Y H:i', strtotime($margin['LastCostUpdate'])) : '-'; ?></small></td>
                </tr>
                <?php
                    }
                } else {
                ?>
                <tr>
                  <td colspan="8" style="text-align: center;">
                    Aucun arrivage récent trouvé. Les marges seront calculées après les premiers arrivages.
                  </td>
                </tr>
                <?php } ?>
              </tbody>
            </table>
            
            <div class="alert alert-info" style="margin: 10px;">
              <strong><i class="icon-info-sign"></i> À propos des marges :</strong>
              Les prix d'achat sont automatiquement calculés à partir de la moyenne des 5 derniers arrivages.
              La marge réelle est calculée selon la formule : <code>(Prix Vente - Prix Achat) / Prix Vente × 100</code>
              <br>
              <a href="margins.php" class="btn btn-small btn-primary" style="margin-top: 5px;">
                <i class="icon-calculator"></i> Gérer les Marges Complètes
              </a>
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
// NOUVEAUX SCRIPTS POUR LES FONCTIONNALITÉS DE LIVRAISON ET MARGES

// Fonction pour générer automatiquement un numéro de bon de livraison
function generateDeliveryNote() {
    const today = new Date();
    const year = today.getFullYear();
    const month = String(today.getMonth() + 1).padStart(2, '0');
    const day = String(today.getDate()).padStart(2, '0');
    const time = String(today.getHours()).padStart(2, '0') + String(today.getMinutes()).padStart(2, '0');
    
    return `BL${year}${month}${day}-${time}`;
}

// Fonction pour auto-compléter le bon de livraison
function autoCompleteDeliveryNote() {
    const deliveryNoteInputs = document.querySelectorAll('input[name="deliverynote"]');
    
    deliveryNoteInputs.forEach(function(input) {
        // Ajouter un bouton pour générer automatiquement
        const autoBtn = document.createElement('button');
        autoBtn.type = 'button';
        autoBtn.className = 'btn btn-mini btn-info';
        autoBtn.innerHTML = '✨ Auto';
        autoBtn.title = 'Générer un numéro automatique';
        autoBtn.style.marginLeft = '5px';
        
        autoBtn.addEventListener('click', function() {
            input.value = generateDeliveryNote();
        });
        
        // Insérer le bouton après l'input
        if (input.parentNode) {
            input.parentNode.appendChild(autoBtn);
        }
    });
}

// NOUVELLE FONCTION : Vérification des marges faibles
function checkMarginAlert(productId, salePrice, costPrice) {
    if (costPrice > 0 && salePrice > 0) {
        const margin = ((salePrice - costPrice) / salePrice) * 100;
        
        if (margin < 10) {
            const confirmed = confirm(
                `⚠️ ATTENTION MARGE FAIBLE!\n\n` +
                `Prix de vente: ${salePrice.toFixed(2)}\n` +
                `Prix d'achat: ${costPrice.toFixed(2)}\n` +
                `Marge: ${margin.toFixed(1)}%\n\n` +
                `Cette marge est inférieure à 10%. Voulez-vous continuer?`
            );
            return confirmed;
        } else if (margin < 0) {
            const confirmed = confirm(
                `❌ ATTENTION PERTE!\n\n` +
                `Prix de vente: ${salePrice.toFixed(2)}\n` +
                `Prix d'achat: ${costPrice.toFixed(2)}\n` +
                `Perte: ${Math.abs(margin).toFixed(1)}%\n\n` +
                `Ce prix génère une PERTE. Êtes-vous sûr de continuer?`
            );
            return confirmed;
        }
    }
    return true;
}

// Fonction pour remplir le prix personnalisé avec le prix actuel
function fillCustomPrice() {
  const productSelect = document.getElementById('productSelect');
  const customPriceInput = document.getElementById('customPrice');

  if (!productSelect || !customPriceInput) return;

  // Get unit price from data-price
  const selectedOption = productSelect.options[productSelect.selectedIndex];
  const currentPrice = parseFloat(selectedOption.getAttribute('data-price')) || 0;
  
  // Pre-fill custom price if it's 0 or empty
  if (parseFloat(customPriceInput.value) <= 0) {
    customPriceInput.value = currentPrice.toFixed(2);
  }
  
  updateSingleCost();
}

// FONCTION AMÉLIORÉE : Calculer le coût et afficher la marge
function updateSingleCost() {
  const customPriceInput = document.getElementById('customPrice');
  const quantityInput = document.getElementById('quantity');
  const costDisplay = document.getElementById('costDisplay');
  const productSelect = document.getElementById('productSelect');

  if (!customPriceInput || !quantityInput || !costDisplay || !productSelect) return;

  const customPrice = parseFloat(customPriceInput.value) || 0;
  const qty = parseFloat(quantityInput.value) || 0;
  const total = customPrice * qty;
  
  costDisplay.value = total.toFixed(2);
  
  // NOUVEAU : Affichage de la marge en temps réel
  const selectedOption = productSelect.options[productSelect.selectedIndex];
  if (selectedOption && selectedOption.value) {
    const salePrice = parseFloat(selectedOption.getAttribute('data-price')) || 0;
    if (salePrice > 0 && customPrice > 0) {
      const margin = ((salePrice - customPrice) / salePrice) * 100;
      const profit = salePrice - customPrice;
      
      // Affichage visuel de la marge
      let marginDisplay = document.getElementById('marginDisplay');
      if (!marginDisplay) {
        marginDisplay = document.createElement('div');
        marginDisplay.id = 'marginDisplay';
        marginDisplay.style.marginTop = '5px';
        marginDisplay.style.fontSize = '12px';
        marginDisplay.style.fontWeight = 'bold';
        marginDisplay.style.padding = '5px';
        marginDisplay.style.borderRadius = '3px';
        costDisplay.parentNode.appendChild(marginDisplay);
      }
      
      let bgColor = '';
      let textColor = '';
      let icon = '';
      let status = '';
      
      if (margin < 0) {
        bgColor = '#f8d7da';
        textColor = '#721c24';
        icon = '❌';
        status = 'PERTE';
      } else if (margin < 10) {
        bgColor = '#fff3cd';
        textColor = '#856404';
        icon = '⚠️';
        status = 'Faible';
      } else if (margin < 20) {
        bgColor = '#d1ecf1';
        textColor = '#0c5460';
        icon = '📉';
        status = 'Correcte';
      } else if (margin < 30) {
        bgColor = '#d4edda';
        textColor = '#155724';
        icon = '📊';
        status = 'Bonne';
      } else {
        bgColor = '#d4edda';
        textColor = '#155724';
        icon = '📈';
        status = 'Excellente';
      }
      
      marginDisplay.style.backgroundColor = bgColor;
      marginDisplay.style.color = textColor;
      marginDisplay.innerHTML = `
        ${icon} Marge: ${margin.toFixed(1)}% (${status}) | 
        Profit/unité: ${profit.toFixed(2)} | 
        Total profit: ${(profit * qty).toFixed(2)}
      `;
    }
  }
}

// Fonction pour mettre à jour le total du panier en temps réel
function updateCartTotal() {
  const priceInputs = document.querySelectorAll('.cart-price-input');
  const quantityInputs = document.querySelectorAll('.cart-quantity-input');
  const lineTotals = document.querySelectorAll('.line-total');
  let totalCart = 0;
  
  // Mettre à jour chaque ligne
  priceInputs.forEach(function(priceInput, index) {
    const price = parseFloat(priceInput.value) || 0;
    const qty = parseFloat(quantityInputs[index].value) || 0;
    const lineTotal = price * qty;
    
    // Mettre à jour l'affichage de la ligne
    if (lineTotals[index]) {
      lineTotals[index].textContent = lineTotal.toFixed(2);
    }
    
    totalCart += lineTotal;
  });
  
  // Mettre à jour l'affichage du total
  const totalDisplays = document.querySelectorAll('.cart-total');
  totalDisplays.forEach(function(display) {
    display.textContent = totalCart.toFixed(2);
  });
}

// Fonction pour synchroniser les dates de livraison avec les dates d'arrivage
function syncDeliveryDate() {
    const arrivalDateInputs = document.querySelectorAll('input[name="arrivaldate"]');
    const deliveryDateInputs = document.querySelectorAll('input[name="deliverydate"]');
    
    arrivalDateInputs.forEach(function(arrivalInput, index) {
        arrivalInput.addEventListener('change', function() {
            if (deliveryDateInputs[index] && !deliveryDateInputs[index].value) {
                deliveryDateInputs[index].value = this.value;
            }
        });
    });
}

// Fonction pour valider les dates
function validateDeliveryDates() {
    const forms = document.querySelectorAll('form');
    
    forms.forEach(function(form) {
        form.addEventListener('submit', function(e) {
            const arrivalDate = form.querySelector('input[name="arrivaldate"]');
            const deliveryDate = form.querySelector('input[name="deliverydate"]');
            
            if (arrivalDate && deliveryDate && arrivalDate.value && deliveryDate.value) {
                const arrival = new Date(arrivalDate.value);
                const delivery = new Date(deliveryDate.value);
                
                if (delivery > arrival) {
                    const daysDiff = Math.ceil((delivery - arrival) / (1000 * 60 * 60 * 24));
                    if (daysDiff > 30) {
                        const confirmed = confirm(
                            `Attention: La date de livraison est ${daysDiff} jours après la date d'arrivage. ` +
                            'Êtes-vous sûr de ces dates?'
                        );
                        if (!confirmed) {
                            e.preventDefault();
                            return false;
                        }
                    }
                }
            }
        });
    });
}

// Listen for changes
document.addEventListener('DOMContentLoaded', function() {
  // Formulaire unique
  const productSelect = document.getElementById('productSelect');
  if (productSelect) {
    productSelect.addEventListener('change', fillCustomPrice);
  }

  const customPriceInput = document.getElementById('customPrice');
  if (customPriceInput) {
    customPriceInput.addEventListener('input', updateSingleCost);
  }

  const quantityInput = document.getElementById('quantity');
  if (quantityInput) {
    quantityInput.addEventListener('input', updateSingleCost);
  }
  
  // Panier temporaire
  const cartPriceInputs = document.querySelectorAll('.cart-price-input');
  const cartQuantityInputs = document.querySelectorAll('.cart-quantity-input');
  
  cartPriceInputs.forEach(function(input) {
    input.addEventListener('input', updateCartTotal);
  });
  
  cartQuantityInputs.forEach(function(input) {
    input.addEventListener('input', updateCartTotal);
  });
  
  // NOUVELLES FONCTIONNALITÉS
  syncDeliveryDate();
  autoCompleteDeliveryNote();
  validateDeliveryDates();
  
  // NOUVEAU : Vérification de marge lors de la soumission du formulaire unique
  const singleForm = document.getElementById('singleArrivalForm');
  if (singleForm) {
      singleForm.addEventListener('submit', function(e) {
          const productSelect = document.getElementById('productSelect');
          const customPriceInput = document.getElementById('customPrice');
          
          if (productSelect && customPriceInput) {
              const selectedOption = productSelect.options[productSelect.selectedIndex];
              if (selectedOption && selectedOption.value) {
                  const salePrice = parseFloat(selectedOption.getAttribute('data-price')) || 0;
                  const costPrice = parseFloat(customPriceInput.value) || 0;
                  
                  if (!checkMarginAlert(selectedOption.value, salePrice, costPrice)) {
                      e.preventDefault();
                      return false;
                  }
              }
          }
      });
  }
  
  // Synchroniser les dates initiales
  const arrivalInputs = document.querySelectorAll('input[name="arrivaldate"]');
  arrivalInputs.forEach(function(input) {
    if (input.value) {
      const event = new Event('change');
      input.dispatchEvent(event);
    }
  });
  
  // NOUVEAU : Animation des lignes de marge pour attirer l'attention
  const marginRows = document.querySelectorAll('table tbody tr');
  marginRows.forEach(function(row) {
    const marginBadge = row.querySelector('.label-important');
    if (marginBadge) {
      row.style.borderLeft = '3px solid #dc3545';
      row.style.animation = 'pulse 2s infinite';
    }
  });
});

// NOUVEAU : CSS pour l'animation
const style = document.createElement('style');
style.textContent = `
  @keyframes pulse {
    0% { opacity: 1; }
    50% { opacity: 0.7; }
    100% { opacity: 1; }
  }
  
  .margin-alert {
    border-left: 3px solid #dc3545 !important;
    background-color: #fff5f5 !important;
  }
`;
document.head.appendChild(style);
</script>
</body>
</html>