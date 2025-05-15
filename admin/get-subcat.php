<?php
session_start();
error_reporting(0);
include('includes/dbconnection.php');

// Optionnel : vérifier la session
if (strlen($_SESSION['imsaid'] == 0)) {
  header('location:logout.php');
  exit;
}

if (isset($_POST['catid'])) {
    $cid = intval($_POST['catid']);

    // Requête pour récupérer les sous-catégories
    $query = mysqli_query($con, "
        SELECT ID, SubCategoryname
        FROM tblsubcategory
        WHERE CatID='$cid'
          AND Status='1'
        ORDER BY SubCategoryname ASC
    ");

    $count = mysqli_num_rows($query);
    if ($count > 0) {
        // On propose une option par défaut
        echo '<option value="">Sélectionnez une Sous-Catégorie</option>';
        while ($row = mysqli_fetch_assoc($query)) {
            echo '<option value="'.$row['ID'].'">'.$row['SubCategoryname'].'</option>';
        }
    } else {
        // Aucune sous-catégorie trouvée pour cette catégorie
        echo '<option value="">Aucune sous-catégorie disponible</option>';
    }
} else {
    // Si catid n'est pas défini dans $_POST
    echo '<option value="">Erreur : aucune catégorie reçue</option>';
}
?>
