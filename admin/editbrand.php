<?php
session_start();
error_reporting(0);
include('includes/dbconnection.php');
if (strlen($_SESSION['imsaid']==0)) {
  header('location:logout.php');
  } else{
    if(isset($_POST['submit']))
  {
    $eid=$_GET['editid'];
    $brandname=$_POST['brandname'];
    $status=$_POST['status'];
     
    $query=mysqli_query($con, "update tblbrand set BrandName='$brandname',Status='$status' where ID=$eid");
    if ($query) {
   
    echo '<script>alert("Le nom de la marque a été mis à jour.")</script>';
  }
  else
    {
     echo '<script>alert("Quelque chose s\'est mal passé. Veuillez réessayer")</script>';
    }

  
}
  ?>
<!DOCTYPE html>
<html lang="fr">
<head>
<title>Système de gestion d'inventaire || Mettre à jour la marque</title>
<?php include_once('includes/cs.php');?>
<?php include_once('includes/responsive.php'); ?>
<!--Header-part-->
<?php include_once('includes/header.php');?>
<?php include_once('includes/sidebar.php');?>


<div id="content">
<div id="content-header">
  <div id="breadcrumb"> <a href="dashboard.php" title="Aller à l'accueil" class="tip-bottom"><i class="icon-home"></i> Accueil</a> <strong>Mettre à jour la marque</strong></div>
  <h1>Mettre à jour la marque</h1>
</div>
<div class="container-fluid">
  <hr>
  <div class="row-fluid">
    <div class="span12">
      <div class="widget-box">
        <div class="widget-title"> <span class="icon"> <i class="icon-align-justify"></i> </span>
          <h5>Mettre à jour la marque</h5>
        </div>
        <div class="widget-content nopadding">
          <form method="post" class="form-horizontal">
           <?php
 $eid=$_GET['editid'];
$ret=mysqli_query($con,"select * from tblbrand where ID='$eid'");
$cnt=1;
while ($row=mysqli_fetch_array($ret)) {

?>
            <div class="control-group">
              <label class="control-label">Nom de la marque :</label>
              <div class="controls">
                <input type="text" class="span11" name="brandname" id="brandname" value="<?php  echo $row['BrandName'];?>" required='true' />
              </div>
            </div>
            <div class="control-group">
              <label class="control-label">Statut :</label>
              <div class="controls">
                <?php  if($row['Status']=="1"){ ?>
                <input type="checkbox"  name="status" id="status" value="1"  checked="true"/>
                <?php } else { ?>
                  <input type="checkbox" value='1' name="status" id="status" />
                  <?php } ?>
              </div>
            </div>
            
           <?php } ?>
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