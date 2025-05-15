<?php
session_start();
error_reporting(0);
include('includes/dbconnection.php');
if (strlen($_SESSION['imsaid']==0)) {
  header('location:logout.php');
} else {
  // Get the current user's information
  $currentAdminId = $_SESSION['imsaid'];
  $adminQuery = mysqli_query($con, "SELECT UserName FROM tbladmin WHERE ID='$currentAdminId'");
  $adminData = mysqli_fetch_array($adminQuery);
  $isAdmin = ($adminData['UserName'] != 'saler'); // Check if current user is admin (not saler)

  // Handle password update
  if(isset($_POST['submit'])) {
    $userId = isset($_POST['user_id']) ? $_POST['user_id'] : $currentAdminId;
    
    // Self password change - requires current password verification
    if($userId == $currentAdminId) {
      $currentPassword = $_POST['currentpassword'];
      
      // Get the current user's stored password
      $userQuery = mysqli_prepare($con, "SELECT Password FROM tbladmin WHERE ID=?");
      mysqli_stmt_bind_param($userQuery, "i", $currentAdminId);
      mysqli_stmt_execute($userQuery);
      $result = mysqli_stmt_get_result($userQuery);
      $userData = mysqli_fetch_array($result);
      $storedPassword = $userData['Password'];
      
      // Verify password - handle both new password_hash and legacy MD5
      $passwordVerified = password_verify($currentPassword, $storedPassword) || 
                         md5($currentPassword) == $storedPassword;
      
      if($passwordVerified) {
        // Create new secure password hash
        $newPasswordHash = password_hash($_POST['newpassword'], PASSWORD_DEFAULT);
        
        // Update the password
        $updateStmt = mysqli_prepare($con, "UPDATE tbladmin SET Password=? WHERE ID=?");
        mysqli_stmt_bind_param($updateStmt, "si", $newPasswordHash, $userId);
        $success = mysqli_stmt_execute($updateStmt);
        
        if($success) {
          echo '<script>alert("Votre mot de passe a été changé avec succès.")</script>';
        } else {
          echo '<script>alert("Une erreur est survenue. Veuillez réessayer.")</script>';
        }
      } else {
        echo '<script>alert("Votre mot de passe actuel est incorrect.")</script>';
      }
    } 
    // Admin changing another user's password - no current password needed
    else if($isAdmin) {
      // Create new secure password hash
      $newPasswordHash = password_hash($_POST['newpassword'], PASSWORD_DEFAULT);
      
      // Update the password
      $updateStmt = mysqli_prepare($con, "UPDATE tbladmin SET Password=? WHERE ID=?");
      mysqli_stmt_bind_param($updateStmt, "si", $newPasswordHash, $userId);
      $success = mysqli_stmt_execute($updateStmt);
      
      if($success) {
        echo '<script>alert("Le mot de passe a été changé avec succès.")</script>';
      } else {
        echo '<script>alert("Une erreur est survenue. Veuillez réessayer.")</script>';
      }
    } else {
      echo '<script>alert("Vous n\'avez pas l\'autorisation de modifier le mot de passe d\'autres utilisateurs.")</script>';
    }
  }
?>
<!DOCTYPE html>
<html lang="fr">
<head>
<title>Système de gestion d'inventaire || Changer le mot de passe</title>
<?php include_once('includes/cs.php');?>
<script type="text/javascript">
function checkpass()
{
if(document.changepassword.newpassword.value!=document.changepassword.confirmpassword.value)
{
alert('Le nouveau mot de passe et le champ de confirmation du mot de passe ne correspondent pas');
document.changepassword.confirmpassword.focus();
return false;
}
return true;
} 

function toggleCurrentPassword() {
  var userId = document.getElementById('user_id').value;
  var currentAdminId = '<?php echo $currentAdminId; ?>';
  var currentPasswordField = document.getElementById('currentPasswordField');
  
  if(userId == currentAdminId) {
    currentPasswordField.style.display = 'block';
  } else {
    currentPasswordField.style.display = 'none';
  }
}
</script>
<?php include_once('includes/responsive.php'); ?>
<!--Header-part-->
<?php include_once('includes/header.php');?>
<?php include_once('includes/sidebar.php');?>


<div id="content">
<div id="content-header">
  <div id="breadcrumb"> <a href="dashboard.php" title="Aller à l'accueil" class="tip-bottom"><i class="icon-home"></i> Accueil</a> <a href="change-password.php" class="tip-bottom">Changer le mot de passe</a></div>
  <h1>Changer le mot de passe</h1>
</div>
<div class="container-fluid">
  <hr>
  <div class="row-fluid">
    <div class="span12">
      <div class="widget-box">
        <div class="widget-title"> <span class="icon"> <i class="icon-align-justify"></i> </span>
          <h5>Changer le mot de passe</h5>
        </div>
        <div class="widget-content nopadding">
          <form method="post" class="form-horizontal" name="changepassword" onsubmit="return checkpass();">
            
            <?php if($isAdmin) { ?>
            <div class="control-group">
              <label class="control-label">Sélectionner l'utilisateur :</label>
              <div class="controls">
                <select name="user_id" id="user_id" class="span11" onchange="toggleCurrentPassword()">
                  <?php
                  // If admin, show all users in dropdown
                  $userQuery = mysqli_query($con, "SELECT ID, AdminName FROM tbladmin");
                  while($user = mysqli_fetch_array($userQuery)) {
                    $selected = ($user['ID'] == $currentAdminId) ? 'selected' : '';
                    echo "<option value='{$user['ID']}' $selected>{$user['AdminName']}</option>";
                  }
                  ?>
                </select>
              </div>
            </div>
            <?php } else { ?>
            <input type="hidden" name="user_id" value="<?php echo $currentAdminId; ?>">
            <?php } ?>
            
            <div class="control-group" id="currentPasswordField">
              <label class="control-label">Mot de passe actuel :</label>
              <div class="controls">
                <input type="password" class="span11" name="currentpassword" id="currentpassword" value="" <?php echo ($isAdmin) ? '' : 'required'; ?> />
              </div>
            </div>
            <div class="control-group">
              <label class="control-label">Nouveau mot de passe :</label>
              <div class="controls">
                <input type="password" class="span11" name="newpassword" id="newpassword" value="" required='true' />
              </div>
            </div>
            <div class="control-group">
              <label class="control-label">Confirmer le mot de passe</label>
              <div class="controls">
                <input type="password" class="span11" name="confirmpassword" id="confirmpassword" value="" required='true' />
              </div>
            </div>
            <div class="form-actions">
              <button type="submit" class="btn btn-success" name="submit">Mettre à jour</button>
            </div>
          </form>
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