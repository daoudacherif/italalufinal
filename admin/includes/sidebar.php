<?php
// Récupérer le type d'utilisateur connecté
$adminid = $_SESSION['imsaid'];
$ret = mysqli_query($con, "SELECT UserName FROM tbladmin WHERE ID='$adminid'");
$row = mysqli_fetch_array($ret);
$username = $row['UserName'];

// Compteur du panier avec gestion d'erreur
try {
    // Essayez de déterminer le bon nom de colonne pour l'utilisateur dans la table tblcart
    // Si vous connaissez le nom exact de la colonne, utilisez-le directement à la place de cette logique
    $cartcountcount = 0; // Valeur par défaut
    
    // Tentative avec différents noms de colonnes possibles
    $possible_columns = ['AdminID', 'admin_id', 'UserID', 'user_id'];
    
    foreach($possible_columns as $column) {
        $check_column = mysqli_query($con, "SHOW COLUMNS FROM tblcart LIKE '$column'");
        if(mysqli_num_rows($check_column) > 0) {
            $ret1 = mysqli_query($con, "SELECT count(ID) as cartcount FROM tblcart WHERE $column = '$adminid'");
            $row1 = mysqli_fetch_array($ret1);
            $cartcountcount = $row1['cartcount'];
            break; // Sortir de la boucle si nous trouvons une colonne qui fonctionne
        }
    }
} catch (Exception $e) {
    // En cas d'erreur, simplement définir le compteur à 0
    $cartcountcount = 0;
}
?>

<!-- Menu latéral modernisé et organisé -->
<div id="sidebar">
  <ul>
    <?php if($username != 'saler'): ?>
    <!-- MENU POUR LES UTILISATEURS NORMAUX -->
    
    <!-- Tableau de bord -->
    <li class="<?php echo basename($_SERVER['PHP_SELF']) == 'dashboard.php' ? 'active' : ''; ?>">
      <a href="dashboard.php"><i class="icon icon-home"></i> <span>Tableau de bord</span></a>
    </li>

    <!-- Gestion des catégories -->
    <li class="submenu">
      <a href="#"><i class="icon icon-th-list"></i> <span>Catégories</span></a>
      <ul>
        <li><a href="add-category.php">Ajouter une catégorie</a></li>
        <li><a href="manage-category.php">Gérer les catégories</a></li>
      </ul>
    </li>

    <!-- Articles -->
    <li class="submenu">
      <a href="#"><i class="icon icon-info-sign"></i> <span>Articles</span></a>
      <ul>
        <li><a href="add-product.php">Ajouter un Article</a></li>
        <li><a href="manage-product.php">Gérer les Articles</a></li>
        <li><a href="inventory.php">Inventaire</a></li>
      </ul>
    </li>
    <?php endif; ?>

    <!-- PARTIE COMMUNE (VENTES) - visible pour tous les utilisateurs -->
    <!-- Ventes -->
    <li class="submenu">
      <a href="#"><i class="icon-shopping-cart"></i> <span>Ventes</span></a>
      <ul>
        <li><a href="cart.php">Comptant <span class="label label-important"><?php echo htmlentities($cartcountcount);?></span></a></li>
        <li><a href="dettecart.php">Terme <span class="label label-important"><?php echo htmlentities($cartcountcount);?></span></a></li>
        <li><a href="return.php">Retour</a></li>
        <li><a href="transact.php">Transactions</a></li>
        <li><a href="facture.php">Factures</a></li>
        <li><a href="admin_invoices.php">Factures par Admin</a></li>
      </ul>
    </li>

  
    <!-- SUITE DU MENU POUR LES UTILISATEURS NORMAUX -->
    
    <!-- Recherche -->
    <li class="submenu">
      <a href="#"><i class="icon-search"></i> <span>Recherche</span></a>
      <ul>
        <li><a href="search.php">Rechercher</a></li>
        <li><a href="invoice-search.php">Factures</a></li>
      </ul>
    </li>

    <!-- Fournisseurs -->
    <li class="submenu">
      <a href="#"><i class="icon-group"></i> <span>Fournisseurs</span></a>
      <ul>
        <li><a href="arrival.php">Arrivage</a></li>
        <li><a href="supplier.php">Liste des fournisseurs</a></li>
        <li><a href="supplier-payments.php">Paiements</a></li>
      </ul>
    </li>

    <!-- Clients -->
    <li class="submenu">
      <a href="#"><i class="icon-group"></i> <span>Clients</span></a>
      <ul>
        <li><a href="client-account.php">Compte client</a></li>
        <li><a href="customer-details.php">Détails client</a></li>
      </ul>
    </li>

    <!-- Rapports -->
    <li class="submenu">
      <a href="#"><i class="icon icon-th-list"></i> <span>Rapports</span></a>
      <ul>
        <li><a href="stock-report.php">Stock</a></li>
        <li><a href="sales-report.php">Ventes</a></li>
        <li><a href="daily-repport.php">Journalier</a></li>
      </ul>
    </li>
   
  </ul>
</div>