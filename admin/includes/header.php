<!--Header-part-->
<div id="header">
  <h2 style="padding-top: 20px;padding-left: 10px">
    <a href="dashboard.php">
      <strong style="color: red">ITALALU</strong>
    </a>
  </h2>
</div>
<!--close-Header-part-->

<!--top-Header-menu-->
<div id="user-nav" class="navbar navbar-inverse">
  <ul class="nav">
    <?php
    // Vérifier si l'utilisateur est connecté
    if (!isset($_SESSION['imsaid'])) {
        header('Location: login.php');
        exit();
    }
    
    // Récupérer le type d'utilisateur connecté avec requête préparée
    $adminid = $_SESSION['imsaid'];
    $stmt = mysqli_prepare($con, "SELECT AdminName, UserName FROM tbladmin WHERE ID = ?");
    mysqli_stmt_bind_param($stmt, "i", $adminid);
    mysqli_stmt_execute($stmt);
    $result = mysqli_stmt_get_result($stmt);
    $row = mysqli_fetch_array($result);
    
    if (!$row) {
        header('Location: logout.php');
        exit();
    }
    
    $name = htmlspecialchars($row['AdminName']);
    $username = $row['UserName'];
    
    // Récupérer le compteur du panier avec requête préparée
    $stmt2 = mysqli_prepare($con, "SELECT COUNT(*) as count FROM tblcart WHERE IsCheckOut = '0'");
    mysqli_stmt_execute($stmt2);
    $result2 = mysqli_stmt_get_result($stmt2);
    $cartrow = mysqli_fetch_array($result2);
    $cartcountcount = $cartrow['count'];
    ?>
    
    <!-- Menu utilisateur avec dropdown -->
    <li class="dropdown" id="profile-messages">
      <a title="" href="#" data-toggle="dropdown" data-target="#profile-messages" class="dropdown-toggle">
        <i class="icon icon-user"></i> 
        <span class="text">Welcome <?php echo $name; ?></span>
        <b class="caret"></b>
      </a>
      <ul class="dropdown-menu">
        <?php if($username !== 'saler'): ?>
        <!-- Profile link seulement pour les utilisateurs normaux -->
        <li><a href="profile.php"><i class="icon-user"></i> Profil</a></li>
        <li class="divider"></li>
        <?php endif; ?>
        <li><a href="change-password.php"><i class="icon-check"></i> Paramètres</a></li>
        <li class="divider"></li>
        <li><a href="logout.php"><i class="icon-key"></i> Déconnexion</a></li>
      </ul>
    </li>
    
    <!-- Panier (affiché pour tous les utilisateurs) -->
    <li id="menu-messages">
      <a href="cart.php" data-target="#menu-messages">
        <i class="icon icon-shopping-cart" style="color: white;font-size: 15px"></i> 
        <span class="text" style="font-size: 15px">Panier</span> 
        <span class="label label-important"><?php echo htmlentities($cartcountcount); ?></span>
      </a>
    </li>
    
    <?php if($username !== 'saler'): ?>
    <!-- Paramètres (seulement pour les utilisateurs normaux) -->
    <li class="">
      <a title="" href="change-password.php">
        <i class="icon icon-cog"></i> 
        <span class="text">Paramètres</span>
      </a>
    </li>
    <?php endif; ?>
    
    <!-- Déconnexion (pour tous les utilisateurs) -->
    <li class="">
      <a title="" href="logout.php">
        <i class="icon icon-share-alt"></i> 
        <span class="text">Déconnexion</span>
      </a>
    </li>
  </ul>
</div>
<!--close-top-Header-menu-->