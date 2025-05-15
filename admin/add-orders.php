<?php
session_start();
error_reporting(0);

// 1) Inclure la connexion à la base de données
include('includes/dbconnection.php');

// 2) Optionnel : Vérifier si l'utilisateur est connecté
if (strlen($_SESSION['imsaid']) == 0) {
    header('location:logout.php');
    exit;
}

// 3) Gérer la soumission du formulaire
if (isset($_POST['submit'])) {
    // -- Champs de l'en-tête de la commande
    $orderdate     = $_POST['orderdate'];
    $recipientName = $_POST['rname'];
    $recipientContact = $_POST['rcontact'];
    $subtotal      = $_POST['subtotal'];
    $tax           = $_POST['tax'];
    $discount      = $_POST['discount'];
    $nettotal      = $_POST['nettotal'];
    $paid          = $_POST['paid'];
    $dues          = $_POST['dues'];
    $paymentmode   = $_POST['paymentmode'];

    // -- Générer un numéro de commande aléatoire
    $ordernum = mt_rand(100000000, 999999999);

    // 4) Insérer dans tblorders (en-tête)
    $sqlOrder = "INSERT INTO tblorders(
        OrderNumber,
        OrderDate,
        RecipientName,
        RecipientContact,
        Subtotal,
        Tax,
        Discount,
        NetTotal,
        Paid,
        Dues,
        PaymentMethod
    ) VALUES(
        '$ordernum',
        '$orderdate',
        '$recipientName',
        '$recipientContact',
        '$subtotal',
        '$tax',
        '$discount',
        '$nettotal',
        '$paid',
        '$dues',
        '$paymentmode'
    )";
    $queryOrder = mysqli_query($con, $sqlOrder);

    if (!$queryOrder) {
        echo '<script>alert("Erreur : Impossible d\'insérer dans tblorders.");</script>';
        exit;
    }

    // -- Obtenir le nouvel OrderID
    $orderID = mysqli_insert_id($con);

    // 5) Insérer chaque ligne de  dans tblorderdetails
    $productArray = $_POST['product']; // tableau des IDs des produits
    $priceArray   = $_POST['price'];   // tableau des prix
    $qtyArray     = $_POST['qty'];     // tableau des quantités
    $totalArray   = $_POST['total'];   // tableau des totaux par ligne

    // Vérifier que tous les tableaux ont la même longueur
    for ($i = 0; $i < count($productArray); $i++) {
        $productID = mysqli_real_escape_string($con, $productArray[$i]);
        $price     = mysqli_real_escape_string($con, $priceArray[$i]);
        $qty       = mysqli_real_escape_string($con, $qtyArray[$i]);
        $lineTotal = mysqli_real_escape_string($con, $totalArray[$i]);

        // Insérer l'élément de ligne
        $sqlDetail = "INSERT INTO tblorderdetails(
            OrderID,
            ProductID,
            Price,
            Qty,
            Total
        ) VALUES(
            '$orderID',
            '$productID',
            '$price',
            '$qty',
            '$lineTotal'
        )";
        $queryDetail = mysqli_query($con, $sqlDetail);

        if (!$queryDetail) {
            echo '<script>alert("Erreur lors de l\'insertion d\'une ligne de produit.");</script>';
        } else {
            // Optionnellement, vous pouvez également mettre à jour le stock dans tblproducts ici, par exemple :
            // mysqli_query($con, "UPDATE tblproducts SET Stock = Stock - $qty WHERE ID='$productID'");
        }
    }

    echo '<script>alert("La commande a été ajoutée avec succès !");</script>';
    echo '<script>window.location.href="add-order.php";</script>';
    exit;
}

// ----------------------------------------------------------------
// 4) Si le formulaire n'est pas soumis, afficher le formulaire
?>

<!DOCTYPE html>
<html lang="fr">

<head>
    <title>Système de Gestion des Stocks | Ajouter une Commande</title>

    <?php include_once('includes/header.php'); ?>
    <!-- jQuery (Requis pour la création dynamique de lignes) -->
    <script src="https://ajax.googleapis.com/ajax/libs/jquery/3.5.1/jquery.min.js"></script>

    <style>
      /* Un peu de style minimal pour la clarté */
      table#dynamic_field th, table#dynamic_field td {
        padding: 6px;
        text-align: center;
      }
      .btn_remove, #add {
        margin-top: 5px;
      }
      
    .control-label {
      font-size: 20px;
      font-weight: bolder;
      color: black;  
    }
  
    </style>
 <?php include_once('includes/responsive.php'); ?>
<?php include_once('includes/sidebar.php'); ?>

<div id="content">
    <div id="content-header">
        <div id="breadcrumb">
            <a href="dashboard.php" title="Aller à l'accueil" class="tip-bottom"><i class="icon-home"></i> Accueil</a>
            <a href="add-order.php" class="current">Ajouter une Commande</a>
        </div>
        <h1>Ajouter une Commande (Vente Comptant)</h1>
    </div>

    <div class="container-fluid">
        <hr>

        <div class="row-fluid">
            <div class="span12">
                <div class="widget-box">
                    <div class="widget-title">
                      <span class="icon"> <i class="icon-align-justify"></i> </span>
                      <h5>Ajouter une Commande</h5>
                    </div>

                    <div class="widget-content nopadding">
                        <!-- DÉBUT DU FORMULAIRE -->
                        <form method="post" class="form-horizontal" autocomplete="off">
                            <!-- Date de la Commande -->
                            <div class="control-group">
                                <label class="control-label">Date de la Commande :</label>
                                <div class="controls">
                                    <input type="text" name="orderdate" value="<?php echo date('Y-m-d'); ?>" class="span11" required />
                                </div>
                            </div>

                            <!-- Nom du Destinataire -->
                            <div class="control-group">
                                <label class="control-label">Nom du Destinataire :</label>
                                <div class="controls">
                                    <input type="text" name="rname" class="span11" required />
                                </div>
                            </div>

                            <!-- Contact du Destinataire -->
                            <div class="control-group">
                                <label class="control-label">Contact du Destinataire :</label>
                                <div class="controls">
                                    <input type="text" name="rcontact" class="span11" required />
                                </div>
                            </div>

                            <!-- Tableau pour les produits dynamiques -->
                            <div class="control-group">
                                <div class="controls">
                                  <table id="dynamic_field" border="1" width="100%">
                                    <tr>
                                      <th>Produit</th>
                                      <th>Prix</th>
                                      <th>Quantité</th>
                                      <th>Total</th>
                                      <th>Action</th>
                                    </tr>
                                    <!-- Première ligne -->
                                    <tr id="row1">
                                      <td>
                                        <select name="product[]" id="product1" class="span11" onchange="getPrice(1)" required>
                                          <option value="">Choisir un Produit</option>
                                          <?php
                                          $ret = mysqli_query($con, "SELECT * FROM tblproducts");
                                          while ($row = mysqli_fetch_array($ret)) {
                                              echo '<option value="'.$row['ID'].'">'.$row['ProductName'].'</option>';
                                          }
                                          ?>
                                        </select>
                                      </td>
                                      <td>
                                        <input type="text" name="price[]" id="price1" class="span11 price" readonly />
                                      </td>
                                      <td>
                                        <input type="text" name="qty[]" id="qty1" class="span11 qty" onkeyup="calculateRowTotal(1)" required />
                                      </td>
                                      <td>
                                        <input type="text" name="total[]" id="total1" class="span11 total" readonly />
                                      </td>
                                      <td>
                                        <button type="button" name="add" id="add" class="btn btn-success">Ajouter Plus</button>
                                      </td>
                                    </tr>
                                  </table>
                                </div>
                            </div>

                            <!-- Sous-total -->
                            <div class="control-group">
                                <label class="control-label">Sous-total :</label>
                                <div class="controls">
                                    <input type="text" name="subtotal" id="subtotal" class="span11" readonly />
                                </div>
                            </div>

                            <!-- Taxe -->
                            <div class="control-group">
                                <label class="control-label">Taxe :</label>
                                <div class="controls">
                                    <input type="text" name="tax" id="tax" class="span11" onkeyup="computeNetTotal()" value="0" />
                                </div>
                            </div>

                            <!-- Remise -->
                            <div class="control-group">
                                <label class="control-label">Remise :</label>
                                <div class="controls">
                                    <input type="text" name="discount" id="discount" class="span11" onkeyup="computeNetTotal()" value="0" />
                                </div>
                            </div>

                            <!-- Total Net -->
                            <div class="control-group">
                                <label class="control-label">Total Net :</label>
                                <div class="controls">
                                    <input type="text" name="nettotal" id="nettotal" class="span11" readonly />
                                </div>
                            </div>

                            <!-- Payé -->
                            <div class="control-group">
                                <label class="control-label">Payé :</label>
                                <div class="controls">
                                    <input type="text" name="paid" id="paid" class="span11" onkeyup="calculateDues()" value="0" />
                                </div>
                            </div>

                            <!-- Dû -->
                            <div class="control-group">
                                <label class="control-label">Dû :</label>
                                <div class="controls">
                                    <input type="text" name="dues" id="dues" class="span11" readonly />
                                </div>
                            </div>

                            <!-- Mode de Paiement -->
                            <div class="control-group">
                                <label class="control-label">Mode de Paiement :</label>
                                <div class="controls">
                                    <select name="paymentmode" class="span11" required>
                                      <option value="">Choisir un Mode de Paiement</option>
                                      <option value="Cash">Espèces</option>
                                      <option value="Card">Carte</option>
                                      <option value="Cheque">Chèque</option>
                                      <option value="Demand Draft">Traite</option>
                                    </select>
                                </div>
                            </div>

                            <!-- Soumettre -->
                            <div class="form-actions">
                                <button type="submit" class="btn btn-success" name="submit">Ajouter la Commande</button>
                            </div>
                        </form>
                        <!-- FIN DU FORMULAIRE -->
                    </div>
                </div>
            </div>
        </div>
    </div>
</div>

<?php include_once('includes/footer.php'); ?>

<!-- =========================================
     JAVASCRIPT : Lignes Dynamiques + Calculs
     ========================================= -->
<script>
var i = 1;

// "Ajouter Plus" de lignes
$('#add').click(function() {
    i++;
    // Créer une nouvelle ligne avec des IDs uniques
    $('#dynamic_field').append(
      '<tr id="row' + i + '">' +
        '<td>' +
          '<select name="product[]" id="product' + i + '" class="span11" onchange="getPrice(' + i + ')" required>' +
            '<option value="">Choisir un Produit</option>' +
            '<?php
            // Générer la liste des <option> une fois en PHP
            $ret2 = mysqli_query($con, "SELECT * FROM tblproducts");
            $options = "";
            while($r = mysqli_fetch_array($ret2)) {
              $options .= "<option value=\'".$r['ID']."\'>".$r['ProductName']."</option>";
            }
            echo $options;
            ?>' +
          '</select>' +
        '</td>' +
        '<td>' +
          '<input type="text" name="price[]" id="price' + i + '" class="span11 price" readonly />' +
        '</td>' +
        '<td>' +
          '<input type="text" name="qty[]" id="qty' + i + '" class="span11 qty" onkeyup="calculateRowTotal(' + i + ')" required />' +
        '</td>' +
        '<td>' +
          '<input type="text" name="total[]" id="total' + i + '" class="span11 total" readonly />' +
        '</td>' +
        '<td>' +
          '<button type="button" name="remove" id="' + i + '" class="btn btn-danger btn_remove">X</button>' +
        '</td>' +
      '</tr>'
    );
});

// Supprimer une ligne
$(document).on('click', '.btn_remove', function(){
    var button_id = $(this).attr("id");
    $('#row' + button_id).remove();
    calculateSubtotal(); // Recalculer après suppression
});

// AJAX pour obtenir le prix
function getPrice(rowId) {
    var productId = $('#product' + rowId).val();
    if(productId === "") {
      $('#price' + rowId).val("");
      calculateRowTotal(rowId);
      return;
    }
    $.ajax({
      type: "POST",
      url: "get-price.php",
      data: { product: productId },
      success: function(data) {
        // 'data' doit être le prix
        $('#price' + rowId).val(data);
        calculateRowTotal(rowId);
      }
    });
}

// Calculer le total pour une ligne
function calculateRowTotal(rowId) {
    var price = parseFloat($('#price' + rowId).val()) || 0;
    var qty   = parseFloat($('#qty' + rowId).val())   || 0;
    var total = price * qty;
    $('#total' + rowId).val(total.toFixed(2));

    calculateSubtotal();
}

// Somme de tous les totaux de ligne => Sous-total
function calculateSubtotal() {
    var sum = 0;
    $('.total').each(function() {
        sum += parseFloat($(this).val()) || 0;
    });
    $('#subtotal').val(sum.toFixed(2));
    computeNetTotal();
}

// Calculer le Total Net (sous-total + taxe - remise)
function computeNetTotal() {
    var subtotal = parseFloat($('#subtotal').val()) || 0;
    var tax      = parseFloat($('#tax').val())      || 0;
    var discount = parseFloat($('#discount').val()) || 0;

    var net = subtotal + tax - discount;
    $('#nettotal').val(net.toFixed(2));

    calculateDues();
}

// Calculer le Dû (total net - payé)
function calculateDues() {
    var nettotal = parseFloat($('#nettotal').val()) || 0;
    var paid     = parseFloat($('#paid').val())     || 0;
    var dues     = nettotal - paid;
    $('#dues').val(dues.toFixed(2));
}

// Si l'utilisateur change la taxe/remise, recalculer le total net
$('#tax, #discount').on('keyup change', function() {
    computeNetTotal();
});

// Si l'utilisateur change "payé", recalculer le dû
$('#paid').on('keyup change', function() {
    calculateDues();
});
</script>

</body>
</html>