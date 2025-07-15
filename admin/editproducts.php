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
    $pname=$_POST['pname'];
    $category=$_POST['category'];
    $subcategory=$_POST['subcategory'] ? $_POST['subcategory'] : NULL;
    $bname=$_POST['bname'] ? $_POST['bname'] : NULL;
    $modelno=$_POST['modelno'];
    $stock=$_POST['stock'];
    $price=$_POST['price'];
    $status=isset($_POST['status']) ? 1 : 0;
     
    $query=mysqli_query($con, "update tblproducts set ProductName='$pname',CatID='$category',SubcatID=".($subcategory ? "'$subcategory'" : "NULL").",BrandName=".($bname ? "'$bname'" : "NULL").",ModelNumber='$modelno',Stock='$stock',Price='$price',Status='$status' where ID='$eid'");
    if ($query) {
   
    echo '<script>alert("Le produit a été mis à jour.")</script>';
  }
  else
    {
     echo '<script>alert("Quelque chose a mal tourné. Veuillez réessayer")</script>';
    }

  
}
  ?>
<!DOCTYPE html>
<html lang="fr">
<head>
<title>Système de gestion des stocks || Mettre à jour les produits</title>
<?php include_once('includes/cs.php');?>
<script>
function getSubCat(val) {
  $.ajax({
type:"POST",
url:"get-subcat.php",
data:'catid='+val,
success:function(data){
$("#subcategory").html(data);
}

  });
}
</script>
<?php include_once('includes/responsive.php'); ?>

<!--Header-part-->
<?php include_once('includes/header.php');?>
<?php include_once('includes/sidebar.php');?>


<div id="content">
<div id="content-header">
  <div id="breadcrumb"> <a href="dashboard.php" title="Aller à l'accueil" class="tip-bottom"><i class="icon-home"></i> Accueil</a> <strong>Mettre à jour le produit</strong></div>
  <h1>Mettre à jour le produit</h1>
</div>
<div class="container-fluid">
  <hr>
  <div class="row-fluid">
    <div class="span12">
      <div class="widget-box">
        <div class="widget-title"> <span class="icon"> <i class="icon-align-justify"></i> </span>
          <h5>Mettre à jour le produit</h5>
        </div>
        <div class="widget-content nopadding">
          <form method="post" class="form-horizontal">
            <?php
            $eid=$_GET['editid'];
            // Fixed query with LEFT JOIN to handle NULL subcategories and brands
            $ret=mysqli_query($con,"select 
                tblcategory.CategoryName as catname,
                tblcategory.ID as catid,
                tblsubcategory.SubCategoryname as subcat,
                tblsubcategory.ID as scatid,
                tblproducts.ID as pid,
                tblproducts.ProductName,
                tblproducts.BrandName,
                tblproducts.Status,
                tblproducts.Price,
                tblproducts.CreationDate,
                tblproducts.ModelNumber,
                tblproducts.Stock,
                tblproducts.SubcatID,
                tblproducts.CatID
                from tblproducts 
                inner join tblcategory on tblcategory.ID=tblproducts.CatID 
                left join tblsubcategory on tblsubcategory.ID=tblproducts.SubcatID 
                where tblproducts.ID='$eid'");

$cnt=1;
while ($row=mysqli_fetch_array($ret)) {

?>
           <div class="control-group">
              <label class="control-label">Nom du produit :</label>
              <div class="controls">
                <input type="text" class="span11" name="pname" id="pname" value="<?php echo htmlspecialchars($row['ProductName']);?>" required='true'/>
              </div>
            </div>
            <div class="control-group">
              <label class="control-label">Catégorie :</label>
              <div class="controls">
                <select type="text" class="span11" name="category" id="category" onChange="getSubCat(this.value)" required='true' />
                   <option value="<?php echo $row['catid'];?>"><?php echo htmlspecialchars($row['catname']);?></option>
                    <?php $query=mysqli_query($con,"select * from tblcategory where Status='1' AND ID != '".$row['catid']."'");
              while($rw=mysqli_fetch_array($query))
              {
              ?>      
                  <option value="<?php echo $rw['ID'];?>"><?php echo htmlspecialchars($rw['CategoryName']);?></option>
                  <?php } ?>
                </select>
              </div>
            </div>
            <div class="control-group">
              <label class="control-label">Nom de la sous-catégorie :</label>
              <div class="controls">
                <select type="text" class="span11" name="subcategory" id="subcategory" />
                  <option value="">-- Aucune sous-catégorie --</option>
                  <?php if($row['scatid']) { ?>
                  <option value="<?php echo $row['scatid'];?>" selected><?php echo htmlspecialchars($row['subcat']);?></option>
                  <?php } ?>
                  <?php 
                  // Load subcategories for current category
                  $subcat_query=mysqli_query($con,"select * from tblsubcategory where CatID='".$row['CatID']."' AND Status='1'".($row['scatid'] ? " AND ID != '".$row['scatid']."'" : ""));
                  if($subcat_query && mysqli_num_rows($subcat_query) > 0) {
                    while($subcat_row=mysqli_fetch_array($subcat_query)) {
                  ?>
                    <option value="<?php echo $subcat_row['ID'];?>"><?php echo htmlspecialchars($subcat_row['SubCategoryname']);?></option>
                  <?php } 
                  } ?>
                </select>
                <span class="help-block">Optionnel - Laissez vide si aucune sous-catégorie</span>
              </div>
            </div>
            <div class="control-group">
              <label class="control-label">Nom de la marque :</label>
              <div class="controls">
                <select type="text" class="span11" name="bname" id="bname" />
                  <option value="">-- Aucune marque --</option>
                  <?php if($row['BrandName']) { ?>
                  <option value="<?php echo htmlspecialchars($row['BrandName']);?>" selected><?php echo htmlspecialchars($row['BrandName']);?></option>
                  <?php } ?>
                  <?php 
                  // Check if tblbrand table exists and load brands
                  $brand_check = mysqli_query($con, "SHOW TABLES LIKE 'tblbrand'");
                  if($brand_check && mysqli_num_rows($brand_check) > 0) {
                    $query1=mysqli_query($con,"select * from tblbrand where Status='1'".($row['BrandName'] ? " AND BrandName != '".$row['BrandName']."'" : ""));
                    if($query1) {
                      while($row1=mysqli_fetch_array($query1)) {
                  ?>
                    <option value="<?php echo htmlspecialchars($row1['BrandName']);?>"><?php echo htmlspecialchars($row1['BrandName']);?></option>
                  <?php } 
                    }
                  } ?>
                </select>
                <span class="help-block">Optionnel - Laissez vide si aucune marque</span>
              </div>
            </div>
            <div class="control-group">
              <label class="control-label">Numéro de modèle :</label>
              <div class="controls">
                <input type="text" class="span11"  name="modelno" id="modelno" value="<?php echo htmlspecialchars($row['ModelNumber']);?>" maxlength="50"  />
                <span class="help-block">Optionnel - Référence ou code du produit</span>
              </div>
            </div>
            <div class="control-group">
              <label class="control-label">Stock (unités) :</label>
              <div class="controls">
                <input type="number" class="span11"  name="stock" id="stock" value="<?php echo $row['Stock'];?>" required="true" min="0"/>
              </div>
            </div>
            <div class="control-group">
              <label class="control-label">Prix (par unité) :</label>
              <div class="controls">
                <input type="number" class="span11" name="price" id="price" value="<?php echo $row['Price'];?>" required="true" min="0" step="0.01"/>
              </div>
            </div>
            <div class="control-group">
              <label class="control-label">Statut :</label>
              <div class="controls">
                <label class="checkbox">
                  <input type="checkbox" name="status" id="status" value="1" <?php echo ($row['Status']=="1") ? 'checked="checked"' : ''; ?> />
                  Produit actif
                </label>
              </div>
            </div>         
           <?php } ?>
            <div class="form-actions">
              <button type="submit" class="btn btn-success" name="submit">Mettre à jour</button>
              <a href="manage-products.php" class="btn">Annuler</a>
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