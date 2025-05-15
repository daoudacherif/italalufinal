<?php
session_start();
error_reporting(0);
include('includes/dbconnection.php');

// Updated login code to use AdminName instead of UserName
if(isset($_POST['login']))
{
    $adminname = $_POST['adminname']; // Changed to adminname 
    $password = $_POST['password']; 
    
    // Use prepared statement to prevent SQL injection - now using AdminName
    $stmt = $con->prepare("SELECT ID, UserName, Password, Status FROM tbladmin WHERE AdminName=?");
    $stmt->bind_param("s", $adminname);
    $stmt->execute();
    $result = $stmt->get_result();
    
    if($result->num_rows > 0) {
        $user = $result->fetch_assoc();
        
        // Check if user is active
        if($user['Status'] == 0) {
            echo '<script>alert("Votre compte a été désactivé. Veuillez contacter l\'administrateur.")</script>';
        } 
        else {
            // Only check password if account is active
            // Verify password - if using password_hash, use password_verify
            if(password_verify($password, $user['Password'])) {
                $_SESSION['imsaid'] = $user['ID'];
                header('location:dashboard.php');
                exit(); // Add exit to stop further execution
            } 
            // For backward compatibility with md5 passwords
            else if(md5($password) == $user['Password']) {
                $_SESSION['imsaid'] = $user['ID'];
                
                // Optional: upgrade old MD5 password to new secure hash
                $new_hash = password_hash($password, PASSWORD_DEFAULT);
                $update_stmt = $con->prepare("UPDATE tbladmin SET Password=? WHERE ID=?");
                $update_stmt->bind_param("si", $new_hash, $user['ID']);
                $update_stmt->execute();
                
                header('location:dashboard.php');
                exit(); // Add exit to stop further execution
            }
            else {
                echo '<script>alert("Mot de passe incorrect. Veuillez réessayer.")</script>';
            }
        }
    } else {
        echo '<script>alert("Nom d\'administrateur invalide. Veuillez réessayer.")</script>';
    }
}
?>
<!DOCTYPE html>
<html lang="fr">
        
<head>
    <title>Système de gestion d'inventaire || Page de connexion</title>
    <meta charset="UTF-8" />
            
    <link rel="stylesheet" href="css/bootstrap.min.css" />
    <link rel="stylesheet" href="css/bootstrap-responsive.min.css" />
    <link rel="stylesheet" href="css/matrix-login.css" />
    <link href="font-awesome/css/font-awesome.css" rel="stylesheet" />
    <link href='http://fonts.googleapis.com/css?family=Open+Sans:400,700,800' rel='stylesheet' type='text/css'>
    <?php include_once('includes/responsive.php'); ?>
<body>
    <div id="loginbox">            
        <form id="loginform" class="form-vertical" method="post">
            <div class="control-group normal_text"> <h3>Inventaire</strong> <strong style="color: orange">Système</strong></h3></div>
            <div class="control-group">
                <div class="controls">
                    <div class="main_input_box">
                        <span class="add-on bg_lg"><i class="icon-user"> </i></span><input type="text" placeholder="Nom d'administrateur" name="adminname" required="true" />
                    </div>
                </div>
            </div>
            <div class="control-group">
                <div class="controls">
                    <div class="main_input_box">
                        <span class="add-on bg_ly"><i class="icon-lock"></i></span><input type="password" placeholder="Mot de passe" name="password" required="true"/>
                    </div>
                </div>
            </div>
            <div class="form-actions">
                <span class="pull-right"><input type="submit" class="btn btn-success" name="login" value="Se connecter"></span>
            </div>
        </form>
        <div style="padding-left: 180px;">
            <a href="../index.php" class="flip-link btn btn-info"><i class="icon-home"></i> Retour à l'accueil</a>
        </div>
    </div>
    
    <script src="js/jquery.min.js"></script>  
    <script src="js/matrix.login.js"></script> 
</body>
</html>