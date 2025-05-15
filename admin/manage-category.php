<?php
session_start();
error_reporting(E_ALL);
include('includes/dbconnection.php');

// 1) Vérifier si l'admin est connecté
if (strlen($_SESSION['imsaid']) == 0) {
    header('location:logout.php');
    exit;
}

// 2) Traitement de l’ajout de catégorie
if (isset($_POST['addcat'])) {
    $catName = trim(mysqli_real_escape_string($con, $_POST['categoryname']));
    if ($catName !== '') {
        $now = date('Y-m-d H:i:s');
        $sql = "INSERT INTO tblcategory (CategoryName, Status, CreationDate) 
                VALUES ('$catName', 1, '$now')";
        if (mysqli_query($con, $sql)) {
            $msg = "Catégorie ajoutée avec succès.";
        } else {
            $err = "Erreur ajout : " . mysqli_error($con);
        }
    } else {
        $err = "Le nom de la catégorie ne peut être vide.";
    }
}

// 3) Traitement de la suppression (ou désactivation) d’une catégorie
if (isset($_GET['delid'])) {
    $delid = intval($_GET['delid']);
    // Option 1 : suppression physique
    // mysqli_query($con, "DELETE FROM tblcategory WHERE ID = $delid");
    // Option 2 : désactivation
    mysqli_query($con, "UPDATE tblcategory SET Status = 0 WHERE ID = $delid");
    header('location:manage-category.php');
    exit;
}
?>
<!DOCTYPE html>
<html lang="fr">
<head>
    <meta charset="UTF-8">
    <title>Gérer les catégories</title>
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
      <a class="current">Gérer les catégories</a>
    </div>
    <h1>Gérer les catégories</h1>
  </div>
  <div class="container-fluid">
    <hr>

    <!-- affichage des messages -->
    <?php if (isset($msg)): ?>
      <div class="alert alert-success"><?= $msg ?></div>
    <?php elseif (isset($err)): ?>
      <div class="alert alert-danger"><?= $err ?></div>
    <?php endif; ?>

    <!-- formulaire d'ajout -->
    <div class="row-fluid mb-3">
      <div class="span6 offset3">
        <div class="widget-box">
          <div class="widget-title"><h5>Ajouter une catégorie</h5></div>
          <div class="widget-content nopadding">
            <form method="post" class="form-horizontal">
              <div class="control-group">
                <label class="control-label">Nom de la catégorie :</label>
                <div class="controls">
                  <input type="text" name="categoryname" class="span11" required />
                </div>
              </div>
              <div class="form-actions text-center">
                <button type="submit" name="addcat" class="btn btn-success">Ajouter</button>
              </div>
            </form>
          </div>
        </div>
      </div>
    </div>

    <!-- tableau des catégories -->
    <div class="row-fluid">
      <div class="span12">
        <div class="widget-box">
          <div class="widget-title">
            <span class="icon"><i class="icon-th"></i></span>
            <h5>Liste des catégories</h5>
          </div>
          <div class="widget-content nopadding">
            <table class="table table-bordered data-table">
              <thead>
                <tr>
                  <th>N°</th>
                  <th>Nom</th>
                  <th>Statut</th>
                  <th>Créé le</th>
                  <th>Actions</th>
                </tr>
              </thead>
              <tbody>
                <?php
                  $ret = mysqli_query($con, "SELECT ID, CategoryName, Status, CreationDate FROM tblcategory ORDER BY CreationDate DESC");
                  $cnt = 1;
                  while ($row = mysqli_fetch_assoc($ret)) {
                    $statusLabel = $row['Status'] == 1 
                        ? '<span class="label label-success">Actif</span>' 
                        : '<span class="label label-danger">Inactif</span>';
                ?>
                  <tr class="gradeX">
                    <td><?= $cnt++ ?></td>
                    <td><?= htmlspecialchars($row['CategoryName']) ?></td>
                    <td class="center"><?= $statusLabel ?></td>
                    <td><?= date('d/m/Y H:i', strtotime($row['CreationDate'])) ?></td>
                    <td class="center">
                      <a href="editcategory.php?editid=<?= $row['ID'] ?>" class="btn btn-mini btn-info">
                        <i class="icon-edit"></i>
                      </a>
                      <a href="manage-category.php?delid=<?= $row['ID'] ?>"
                         onclick="return confirm('Voulez-vous vraiment désactiver cette catégorie ?')"
                         class="btn btn-mini btn-danger">
                        <i class="icon-trash"></i>
                      </a>
                    </td>
                  </tr>
                <?php } // while ?>
              </tbody>
            </table>
          </div>
        </div>
      </div>
    </div>

  </div><!-- container-fluid -->
</div><!-- content -->

<?php include_once('includes/footer.php'); ?>
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
