<?php
session_start();
error_reporting(0);
include('includes/dbconnection.php');
if (strlen($_SESSION['imsaid']==0)) {
  header('location:logout.php');
} else {
  
  // Handle activation/deactivation/deletion
  if(isset($_GET['action']) && isset($_GET['id'])) {
    $id = intval($_GET['id']);
    
    // Activate user
    if($_GET['action'] == 'activate') {
      $stmt = $con->prepare("UPDATE tbladmin SET Status=1 WHERE ID=?");
      $stmt->bind_param("i", $id);
      $success = $stmt->execute();
      if($success) {
        echo "<script>alert('Utilisateur activé avec succès.');</script>";
        echo "<script>window.location.href='profile.php'</script>";
      }
    }
    
    // Deactivate user
    if($_GET['action'] == 'deactivate') {
      $stmt = $con->prepare("UPDATE tbladmin SET Status=0 WHERE ID=?");
      $stmt->bind_param("i", $id);
      $success = $stmt->execute();
      if($success) {
        echo "<script>alert('Utilisateur désactivé avec succès.');</script>";
        echo "<script>window.location.href='profile.php'</script>";
      }
    }
    
    // Delete user
    if($_GET['action'] == 'delete') {
      $stmt = $con->prepare("DELETE FROM tbladmin WHERE ID=?");
      $stmt->bind_param("i", $id);
      $success = $stmt->execute();
      if($success) {
        echo "<script>alert('Utilisateur supprimé avec succès.');</script>";
        echo "<script>window.location.href='profile.php'</script>";
      }
    }
  }
  
  // Handle user creation
  if(isset($_POST['createUser'])) {
    $adminid = $_SESSION['imsaid'];
    $username = $_POST['username'];
    
    // Check if the username is "saler" as required
    if($username == 'saler') {
      $adminname = $_POST['adminname'];
      $password = password_hash($_POST['password'], PASSWORD_DEFAULT); // Secure hashing
      $mobileno = $_POST['mobileno'];
      $email = $_POST['email'];
      $regdate = date('Y-m-d H:i:s');
      $status = 1; // Default to active
      
      // Check if email exists using prepared statement
      $stmt = $con->prepare("SELECT ID FROM tbladmin WHERE Email=?");
      $stmt->bind_param("s", $email);
      $stmt->execute();
      $result = $stmt->get_result();
      
      if($result->num_rows > 0) {
        echo '<script>alert("Cette adresse e-mail est déjà utilisée. Veuillez en utiliser une autre.")</script>';
      } else {
        // Create user with prepared statement
        $stmt = $con->prepare("INSERT INTO tbladmin(AdminName, UserName, MobileNumber, Email, Password, AdminRegdate, Status) 
                              VALUES(?, ?, ?, ?, ?, ?, ?)");
        $stmt->bind_param("ssssssi", $adminname, $username, $mobileno, $email, $password, $regdate, $status);
        $success = $stmt->execute();
        
        if($success) {
          echo '<script>alert("Nouvel utilisateur saler créé avec succès.")</script>';
        } else {
          echo '<script>alert("Échec de la création. Veuillez réessayer.")</script>';
        }
      }
    } else {
      echo '<script>alert("Vous ne pouvez créer que des utilisateurs avec le nom d\'utilisateur \"saler\".")</script>';
    }
  }
?>

<!DOCTYPE html>
<html lang="fr">
<head>
<title>Système de gestion des stocks || Gestion des utilisateurs</title>
<?php include_once('includes/cs.php');?>
<?php include_once('includes/responsive.php'); ?>

<!--Header-part-->
<?php include_once('includes/header.php');?>
<?php include_once('includes/sidebar.php');?>

<div id="content">
<div id="content-header">
  <div id="breadcrumb"> <a href="dashboard.php" title="Aller à l'accueil" class="tip-bottom"><i class="icon-home"></i> Accueil</a> <a href="manage-users.php" class="tip-bottom">Gestion des utilisateurs</a></div>
  <h1>Gestion des utilisateurs</h1>
</div>
<div class="container-fluid">
  <hr>
  <div class="row-fluid">
    <div class="span12">
      <div class="widget-box">
        <div class="widget-title"> <span class="icon"> <i class="icon-align-justify"></i> </span>
          <h5>Créer un nouvel utilisateur</h5>
        </div>
        <div class="widget-content nopadding">
          <form method="post" class="form-horizontal">
            <div class="control-group">
              <label class="control-label">Nom d'administrateur :</label>
              <div class="controls">
                <input type="text" class="span11" name="adminname" id="adminname" required='true' />
              </div>
            </div>
            
            <!-- Hidden username field set to "saler" -->
            <input type="hidden" name="username" value="saler">
           
            <div class="control-group">
              <label class="control-label">Mot de passe :</label>
              <div class="controls">
                <input type="password" class="span11" name="password" id="password" required='true' />
              </div>
            </div>
            <div class="control-group">
              <label class="control-label">Numéro de contact :</label>
              <div class="controls">
                <input type="text" class="span11" name="mobileno" id="mobileno" required='true' maxlength='10' pattern='[0-9]+' />
              </div>
            </div>
            <div class="control-group">
              <label class="control-label">Adresse e-mail :</label>
              <div class="controls">
                <input type="email" class="span11" name="email" id="email" required='true' />
              </div>
            </div>
            <div class="form-actions">
              <button type="submit" class="btn btn-success" name="createUser">Créer utilisateur</button>
            </div>
          </form>
        </div>
      </div>
      
      <!-- Display existing users -->
      <div class="widget-box">
        <div class="widget-title"> <span class="icon"><i class="icon-th"></i></span>
          <h5>Liste des utilisateurs</h5>
        </div>
        <div class="widget-content nopadding">
          <table class="table table-bordered data-table">
            <thead>
              <tr>
                <th>#</th>
                <th>Nom</th>
                <th>Nom d'utilisateur</th>
                <th>Contact</th>
                <th>Email</th>
                <th>Date d'inscription</th>
                <th>Statut</th>
                <th>Action</th>
              </tr>
            </thead>
            <tbody>
              <?php
              // Use prepared statement for getting users
              $stmt = $con->prepare("SELECT * FROM tbladmin");
              $stmt->execute();
              $result = $stmt->get_result();
              $cnt=1;
              while ($row = $result->fetch_assoc()) {
              ?>
              <tr class="gradeX">
                <td><?php echo $cnt;?></td>
                <td><?php echo $row['AdminName'];?></td>
                <td><?php echo $row['UserName'];?></td>
                <td><?php echo $row['MobileNumber'];?></td>
                <td><?php echo $row['Email'];?></td>
                <td><?php echo $row['AdminRegdate'];?></td>
                <td>
                  <?php 
                  if($row['Status'] == 1) {
                    echo '<span class="label label-success">Actif</span>';
                  } else {
                    echo '<span class="label label-important">Inactif</span>';
                  }
                  ?>
                </td>
                <td class="center">
                  <?php if($row['Status'] == 1) { ?>
                    <a href="profile.php?action=deactivate&id=<?php echo $row['ID']; ?>" class="btn btn-warning btn-mini" onclick="return confirm('Êtes-vous sûr de vouloir désactiver cet utilisateur?')">Désactiver</a>
                  <?php } else { ?>
                    <a href="profile.php?action=activate&id=<?php echo $row['ID']; ?>" class="btn btn-success btn-mini" onclick="return confirm('Êtes-vous sûr de vouloir activer cet utilisateur?')">Activer</a>
                  <?php } ?>
                  <a href="profile.php?action=delete&id=<?php echo $row['ID']; ?>" class="btn btn-danger btn-mini" onclick="return confirm('Êtes-vous sûr de vouloir supprimer cet utilisateur? Cette action est irréversible.')">Supprimer</a>
                </td>
              </tr>
              <?php 
              $cnt=$cnt+1;
              }?>
            </tbody>
          </table>
        </div>
      </div>
    </div>
  </div>
</div>
</div>
<?php include_once('includes/footer.php');?>
<?php include_once('includes/js.php');?>
</body>
</html>
<?php } ?>