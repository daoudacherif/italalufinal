<?php
session_start();
error_reporting(0);
include('includes/dbconnection.php');

if (strlen($_SESSION['imsaid'] ?? '') == 0) {
    header('location:logout.php');
    exit;
}

if (isset($_POST['submit'])) {
    $pname    = mysqli_real_escape_string($con, $_POST['pname']);
    $category = $_POST['category'];
    $modelno  = $_POST['modelno'];
    $stock    = $_POST['stock'];
    $price    = $_POST['price'];
    $status   = isset($_POST['status']) ? 1 : 0;

    $checkQuery = mysqli_query($con, "SELECT ID FROM tblproducts WHERE ProductName='$pname'");
    if (mysqli_num_rows($checkQuery) > 0) {
        echo '<script>alert("Ce produit existe déjà. Veuillez choisir un autre nom.");</script>';
    } else {
        $query = mysqli_query($con, "
            INSERT INTO tblproducts(ProductName, CatID, ModelNumber, Stock, Price, Status)
            VALUES('$pname', '$category', '$modelno', '$stock', '$price', '$status')
        ");
        echo $query
            ? '<script>alert("Le produit a été créé.");</script>'
            : '<script>alert("Quelque chose s\'est mal passé. Veuillez réessayer");</script>';
    }
}
?>
<!DOCTYPE html>
<html lang="fr">
<head>
    <title>Système de Gestion des Stocks || Ajouter des Produits</title>
    <?php include_once('includes/cs.php'); ?>
</head>
<body>
<?php include_once('includes/header.php'); ?>
<?php include_once('includes/sidebar.php'); ?>

<div id="content">
    <div id="content-header">
        <div id="breadcrumb">
            <a href="dashboard.php" class="tip-bottom"><i class="icon-home"></i> Accueil</a>
            <a href="add-product.php" class="tip-bottom">Ajouter un Produit</a>
        </div>
        <h1>Ajouter un Produit</h1>
    </div>
    <div class="container-fluid">
        <hr>
        <div class="row-fluid">
            <div class="span12">
                <div class="widget-box">
                    <div class="widget-title">
                        <span class="icon"> <i class="icon-align-justify"></i> </span>
                        <h5>Ajouter un Produit</h5>
                    </div>
                    <div class="widget-content nopadding">
                        <form method="post" class="form-horizontal">
                            <div class="control-group">
                                <label class="control-label">Nom du Produit :</label>
                                <div class="controls">
                                    <input type="text" class="span11" name="pname" required placeholder="Entrez le nom du produit" />
                                </div>
                            </div>

                            <div class="control-group">
                                <label class="control-label">Catégorie :</label>
                                <div class="controls">
                                    <select class="span11" name="category" required>
                                        <option value="">Sélectionnez une Catégorie</option>
                                        <?php
                                        $catQuery = mysqli_query($con, "SELECT ID, CategoryName FROM tblcategory WHERE Status='1'");
                                        while ($row = mysqli_fetch_assoc($catQuery)) {
                                            echo '<option value="' . $row['ID'] . '">' . $row['CategoryName'] . '</option>';
                                        }
                                        ?>
                                    </select>
                                </div>
                            </div>

                            <div class="control-group">
                                <label class="control-label">Numéro de Modèle :</label>
                                <div class="controls">
                                    <input type="text" class="span11" name="modelno" maxlength="20" placeholder="Ex: ABC12" />
                                </div>
                            </div>

                            <div class="control-group">
                                <label class="control-label">Stock (unités) :</label>
                                <div class="controls">
                                    <input type="number" class="span11" name="stock" required placeholder="Entrez le stock" />
                                </div>
                            </div>

                            <div class="control-group">
                                <label class="control-label">Prix (par unité) :</label>
                                <div class="controls">
                                    <input type="number" step="any" class="span11" name="price" required placeholder="Entrez le prix" />
                                </div>
                            </div>

                            <div class="control-group">
                                <label class="control-label">Statut :</label>
                                <div class="controls">
                                    <input type="checkbox" name="status" value="1" /> (cocher pour Actif)
                                </div>
                            </div>

                            <div class="form-actions">
                                <button type="submit" class="btn btn-success" name="submit">Ajouter</button>
                            </div>
                        </form>
                    </div>
                </div>
            </div>
        </div>
    </div>
</div>

<?php include_once('includes/footer.php'); ?>
<?php include_once('includes/js.php'); ?>
</body>
</html>