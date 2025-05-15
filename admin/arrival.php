<?php
session_start();
error_reporting(0);
include('includes/dbconnection.php');

if (strlen($_SESSION['imsaid'] == 0)) {
    header('location:logout.php');
    exit;
}

// 1) Handle arrival submission (multiple products)
if (isset($_POST['submit'])) {
    $arrivalDate = $_POST['arrivaldate'];
    $supplierID = intval($_POST['supplierid']);
    
    // Check if we have products to process
    if(isset($_POST['productid']) && is_array($_POST['productid'])) {
        $productIDs = $_POST['productid'];
        $quantities = $_POST['quantity'];
        $comments = $_POST['comments'];
        
        $successCount = 0;
        $errorCount = 0;
        
        // Process each product
        foreach($productIDs as $index => $productID) {
            $productID = intval($productID);
            $quantity = intval($quantities[$index]);
            $comment = mysqli_real_escape_string($con, $comments[$index]);
            
            // Validate data
            if ($productID <= 0 || $quantity <= 0) {
                $errorCount++;
                continue;
            }
            
            // Get product price
            $priceQ = mysqli_query($con, "SELECT Price FROM tblproducts WHERE ID='$productID' LIMIT 1");
            $priceR = mysqli_fetch_assoc($priceQ);
            $unitPrice = floatval($priceR['Price']);
            
            // Calculate total cost
            $cost = $unitPrice * $quantity;
            
            // Insert into tblproductarrivals
            $sqlInsert = "
              INSERT INTO tblproductarrivals(ProductID, SupplierID, ArrivalDate, Quantity, Cost, Comments)
              VALUES('$productID', '$supplierID', '$arrivalDate', '$quantity', '$cost', '$comment')
            ";
            $queryInsert = mysqli_query($con, $sqlInsert);
            
            if ($queryInsert) {
                // Update product stock
                $sqlUpdate = "UPDATE tblproducts
                              SET Stock = Stock + $quantity
                              WHERE ID='$productID'";
                mysqli_query($con, $sqlUpdate);
                $successCount++;
            } else {
                $errorCount++;
            }
        }
        
        // Display result message
        if ($successCount > 0) {
            echo "<script>alert('$successCount arrivages de produits enregistrés avec succès! $errorCount avec erreurs.');</script>";
        } else {
            echo "<script>alert('Erreur lors de l\'enregistrement des arrivages de produits!');</script>";
        }
    } else {
        echo "<script>alert('Aucun produit sélectionné!');</script>";
    }
    
    echo "<script>window.location.href='arrival.php'</script>";
    exit;
}

// 2) Handle adding product to temporary arrival list
if (isset($_POST['addtoarrival'])) {
    $productId = intval($_POST['productid']);
    $quantity = max(1, intval($_POST['quantity']));
    
    // Check if valid product
    if ($productId > 0) {
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
                    $found = true;
                    break;
                }
            }
            
            if (!$found) {
                $_SESSION['temp_arrivals'][] = array(
                    'productid' => $productId,
                    'productname' => $productData['ProductName'],
                    'unitprice' => $productData['Price'],
                    'quantity' => $quantity,
                    'comments' => ''
                );
            }
            
            echo "<script>alert('Produit ajouté à la liste d\'arrivage!');</script>";
        }
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

// 5) Liste des arrivages récents
$sqlArrivals = "
  SELECT a.ID as arrivalID,
         a.ArrivalDate,
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
    <h1>Gérer les Arrivages de Produits (Entrée Stock)</h1>
  </div>

  <div class="container-fluid">
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
            p.Stock,
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
              <th>Prix</th>
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
              <td><?php echo $row['Price']; ?></td>
              <td><?php echo $row['Stock'] . ' ' . $stockStatus; ?></td>
              <td>
                <form method="post" action="arrival.php" style="margin:0;">
                  <input type="hidden" name="productid" value="<?php echo $row['ID']; ?>" />
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
            <h5>Arrivages de Produits en Attente</h5>
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
                  <th>Prix Unitaire</th>
                  <th>Quantité</th>
                  <th>Prix Total</th>
                  <th>Commentaires</th>
                  <th>Action</th>
                </tr>
              </thead>
              <tbody>
                <?php
                if (isset($_SESSION['temp_arrivals']) && count($_SESSION['temp_arrivals']) > 0) {
                  $cnt = 1;
                  foreach ($_SESSION['temp_arrivals'] as $index => $item) {
                    $totalPrice = $item['unitprice'] * $item['quantity'];
                  ?>
                  <tr>
                    <td><?php echo $cnt++; ?></td>
                    <td>
                      <?php echo $item['productname']; ?>
                      <input type="hidden" name="productid[]" value="<?php echo $item['productid']; ?>" />
                    </td>
                    <td><?php echo number_format($item['unitprice'], 2); ?></td>
                    <td>
                      <input type="number" name="quantity[]" value="<?php echo $item['quantity']; ?>" 
                             min="1" style="width:60px;" required />
                    </td>
                    <td><?php echo number_format($totalPrice, 2); ?></td>
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
                  <tr>
                    <td colspan="7">
                      <div class="control-group">
                        <label class="control-label">Date d'Arrivage:</label>
                        <div class="controls">
                          <input type="date" name="arrivaldate" value="<?php echo date('Y-m-d'); ?>" required />
                        </div>
                      </div>
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
                      <button type="submit" name="submit" class="btn btn-success btn-large">
                        <i class="icon-check"></i> Enregistrer Tous les Arrivages
                      </button>
                    </td>
                  </tr>
                <?php } else { ?>
                  <tr>
                    <td colspan="7" style="text-align:center;">Aucun arrivage en attente. Utilisez la recherche ci-dessus pour ajouter des produits.</td>
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

    <!-- FORMULAIRE D'ARRIVAGE PRODUIT UNIQUE (optionnel - peut être supprimé) -->
    <div class="row-fluid">
      <div class="span12">
        <div class="widget-box">
          <div class="widget-title">
            <span class="icon"><i class="icon-align-justify"></i></span>
            <h5>Ajout Rapide d'Arrivage Produit Unique</h5>
          </div>
          <div class="widget-content nopadding">
            <form method="post" class="form-horizontal" id="singleArrivalForm">
              <!-- Arrival Date -->
              <div class="control-group">
                <label class="control-label">Date d'Arrivage:</label>
                <div class="controls">
                  <input type="date" name="arrivaldate" value="<?php echo date('Y-m-d'); ?>" required />
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

              <!-- Quantity -->
              <div class="control-group">
                <label class="control-label">Quantité:</label>
                <div class="controls">
                  <input type="number" name="quantity" id="quantity" min="1" value="1" required />
                </div>
              </div>

              <!-- Cost (auto-calculated) -->
              <div class="control-group">
                <label class="control-label">Coût Total (auto):</label>
                <div class="controls">
                  <input type="number" name="cost" id="cost" step="any" min="0" value="0" readonly />
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
                <button type="submit" name="submit" class="btn btn-success">
                  Enregistrer l'Arrivage Unique
                </button>
              </div>
            </form>
          </div>
        </div>
      </div>
    </div>

    <hr>

    <!-- LISTE DES ARRIVAGES RÉCENTS -->
    <div class="row-fluid">
      <div class="span12">
        <div class="widget-box">
          <div class="widget-title">
            <span class="icon"><i class="icon-th"></i></span>
            <h5>Arrivages Récents de Produits</h5>
          </div>
          <div class="widget-content nopadding">
            <table class="table table-bordered data-table">
              <thead>
                <tr>
                  <th>#</th>
                  <th>Date d'Arrivage</th>
                  <th>Produit</th>
                  <th>Fournisseur</th>
                  <th>Qté</th>
                  <th>Prix Unitaire</th>
                  <th>Prix Total</th>
                  <th>Coût (Saisi)</th>
                  <th>Commentaires</th>
                </tr>
              </thead>
              <tbody>
                <?php
                $cnt = 1;
                while ($row = mysqli_fetch_assoc($resArrivals)) {
                  $unitPrice = floatval($row['UnitPrice']);
                  $qty       = floatval($row['Quantity']);
                  $lineTotal = $unitPrice * $qty;
                  ?>
                  <tr>
                    <td><?php echo $cnt; ?></td>
                    <td><?php echo $row['ArrivalDate']; ?></td>
                    <td><?php echo $row['ProductName']; ?></td>
                    <td><?php echo $row['SupplierName']; ?></td>
                    <td><?php echo $qty; ?></td>
                    <td><?php echo number_format($unitPrice,2); ?></td>
                    <td><?php echo number_format($lineTotal,2); ?></td>
                    <td><?php echo number_format($row['Cost'],2); ?></td>
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
// Auto-calc cost client-side for single product form
function updateCost() {
  const productSelect = document.getElementById('productSelect');
  const quantityInput = document.getElementById('quantity');
  const costInput     = document.getElementById('cost');

  if (!productSelect || !quantityInput || !costInput) return;

  // Get unit price from data-price
  const selectedOption = productSelect.options[productSelect.selectedIndex];
  const unitPrice = parseFloat(selectedOption.getAttribute('data-price')) || 0;

  // Get quantity
  const qty = parseFloat(quantityInput.value) || 0;

  // Calculate
  const total = unitPrice * qty;
  costInput.value = total.toFixed(2);
}

// Listen for changes
document.addEventListener('DOMContentLoaded', function() {
  // On product select
  const productSelect = document.getElementById('productSelect');
  if (productSelect) {
    productSelect.addEventListener('change', updateCost);
  }

  // On quantity
  const quantityInput = document.getElementById('quantity');
  if (quantityInput) {
    quantityInput.addEventListener('input', updateCost);
  }
});
</script>
</body>
</html>