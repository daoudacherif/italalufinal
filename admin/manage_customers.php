<?php 
session_start();
error_reporting(E_ALL);
include('includes/dbconnection.php');

// Vérifie que l'admin est connecté
if (empty($_SESSION['imsaid'])) {
    header('Location: logout.php');
    exit;
}

// Ajouter un nouveau client
if (isset($_POST['add_customer'])) {
    $name = mysqli_real_escape_string($con, trim($_POST['customer_name']));
    $mobile = preg_replace('/[^0-9+]/', '', $_POST['customer_mobile']);
    $email = mysqli_real_escape_string($con, trim($_POST['customer_email']));
    $address = mysqli_real_escape_string($con, trim($_POST['customer_address']));
    
    // Validation
    if (empty($name) || empty($mobile)) {
        echo "<script>alert('Le nom et le numéro de téléphone sont obligatoires'); window.location='manage_customers.php';</script>";
        exit;
    }
    
    // Vérifier le format du numéro
    if (!preg_match('/^(\+?224)?6[0-9]{8}$/', $mobile)) {
        echo "<script>alert('Format de numéro invalide. Utilisez: 623XXXXXXXX'); window.location='manage_customers.php';</script>";
        exit;
    }
    
    // Vérifier si le client existe déjà
    $checkQuery = mysqli_query($con, "SELECT ID FROM tblcustomer WHERE CustomerContact='$mobile' LIMIT 1");
    if (mysqli_num_rows($checkQuery) > 0) {
        echo "<script>alert('Un client avec ce numéro existe déjà'); window.location='manage_customers.php';</script>";
        exit;
    }
    
    $insertQuery = "INSERT INTO tblcustomer (CustomerName, CustomerContact, CustomerEmail, CustomerAddress, CustomerRegdate) 
                    VALUES ('$name', '$mobile', '$email', '$address', NOW())";
    
    if (mysqli_query($con, $insertQuery)) {
        echo "<script>alert('Client ajouté avec succès'); window.location='manage_customers.php';</script>";
    } else {
        echo "<script>alert('Erreur lors de l\'ajout du client'); window.location='manage_customers.php';</script>";
    }
    exit;
}

// Modifier un client
if (isset($_POST['edit_customer'])) {
    $id = intval($_POST['customer_id']);
    $name = mysqli_real_escape_string($con, trim($_POST['edit_name']));
    $mobile = preg_replace('/[^0-9+]/', '', $_POST['edit_mobile']);
    $email = mysqli_real_escape_string($con, trim($_POST['edit_email']));
    $address = mysqli_real_escape_string($con, trim($_POST['edit_address']));
    
    // Validation
    if (empty($name) || empty($mobile)) {
        echo "<script>alert('Le nom et le numéro de téléphone sont obligatoires'); window.location='manage_customers.php';</script>";
        exit;
    }
    
    // Vérifier le format du numéro
    if (!preg_match('/^(\+?224)?6[0-9]{8}$/', $mobile)) {
        echo "<script>alert('Format de numéro invalide'); window.location='manage_customers.php';</script>";
        exit;
    }
    
    $updateQuery = "UPDATE tblcustomer SET 
                    CustomerName='$name', 
                    CustomerContact='$mobile', 
                    CustomerEmail='$email', 
                    CustomerAddress='$address' 
                    WHERE id='$id'";
    
    if (mysqli_query($con, $updateQuery)) {
        echo "<script>alert('Client modifié avec succès'); window.location='manage_customers.php';</script>";
    } else {
        echo "<script>alert('Erreur lors de la modification'); window.location='manage_customers.php';</script>";
    }
    exit;
}

// Supprimer un client
if (isset($_GET['delete_id'])) {
    $id = intval($_GET['delete_id']);
    
    // Vérifier si le client a des factures
    $checkBills = mysqli_query($con, "SELECT COUNT(*) as count FROM tblcustomer WHERE id='$id' AND (BillingNumber IS NOT NULL OR FinalAmount > 0)");
    $billData = mysqli_fetch_assoc($checkBills);
    
    if ($billData['count'] > 0) {
        echo "<script>alert('Impossible de supprimer ce client car il a des factures associées'); window.location='manage_customers.php';</script>";
        exit;
    }
    
    if (mysqli_query($con, "DELETE FROM tblcustomer WHERE id='$id'")) {
        echo "<script>alert('Client supprimé avec succès'); window.location='manage_customers.php';</script>";
    } else {
        echo "<script>alert('Erreur lors de la suppression'); window.location='manage_customers.php';</script>";
    }
    exit;
}

// Recherche
$searchTerm = '';
$whereClause = '';
if (!empty($_GET['search'])) {
    $searchTerm = mysqli_real_escape_string($con, $_GET['search']);
    $whereClause = "WHERE CustomerName LIKE '%$searchTerm%' OR CustomerContact LIKE '%$searchTerm%' OR CustomerEmail LIKE '%$searchTerm%'";
}

// Pagination
$page = isset($_GET['page']) ? max(1, intval($_GET['page'])) : 1;
$recordsPerPage = 20;
$offset = ($page - 1) * $recordsPerPage;

// Compter le total
$countQuery = "SELECT COUNT(*) as total FROM tblcustomer $whereClause";
$countResult = mysqli_query($con, $countQuery);
$totalRecords = mysqli_fetch_assoc($countResult)['total'];
$totalPages = ceil($totalRecords / $recordsPerPage);

// Récupérer les clients
$customersQuery = "SELECT * FROM tblcustomer $whereClause 
                   ORDER BY CustomerRegdate DESC 
                   LIMIT $offset, $recordsPerPage";
$customersResult = mysqli_query($con, $customersQuery);
?>

<!DOCTYPE html>
<html lang="fr">
<head>
    <title>Gestion des Clients | Système de Gestion d'Inventaire</title>
    <?php include_once('includes/cs.php'); ?>
    <?php include_once('includes/responsive.php'); ?>
    <style>
        .customer-form {
            background-color: #f8f9fa;
            border: 1px solid #dee2e6;
            border-radius: 5px;
            padding: 20px;
            margin-bottom: 20px;
        }
        .customer-stats {
            background-color: #e7f3ff;
            border-left: 4px solid #007bff;
            padding: 15px;
            margin-bottom: 20px;
        }
        .action-buttons {
            white-space: nowrap;
        }
        .modal {
            display: none;
            position: fixed;
            z-index: 1000;
            left: 0;
            top: 0;
            width: 100%;
            height: 100%;
            background-color: rgba(0,0,0,0.5);
        }
        .modal-content {
            background-color: #fefefe;
            margin: 15% auto;
            padding: 20px;
            border: 1px solid #888;
            width: 50%;
            border-radius: 5px;
        }
        .close {
            color: #aaa;
            float: right;
            font-size: 28px;
            font-weight: bold;
            cursor: pointer;
        }
        .close:hover {
            color: black;
        }
        .pagination {
            text-align: center;
            margin-top: 20px;
        }
        .pagination a {
            display: inline-block;
            padding: 8px 16px;
            margin: 0 4px;
            text-decoration: none;
            border: 1px solid #ddd;
            color: #007bff;
        }
        .pagination a.active {
            background-color: #007bff;
            color: white;
            border: 1px solid #007bff;
        }
        .pagination a:hover:not(.active) {
            background-color: #ddd;
        }
    </style>
</head>
<body>
    <?php include_once('includes/header.php'); ?>
    <?php include_once('includes/sidebar.php'); ?>
    
    <div id="content">
        <div id="content-header">
            <div id="breadcrumb">
                <a href="dashboard.php" class="tip-bottom">
                    <i class="icon-home"></i> Accueil
                </a>
                <a href="manage_customers.php" class="current">Gestion des Clients</a>
            </div>
            <h1>Gestion des Clients</h1>
        </div>
        
        <div class="container-fluid">
            <!-- Statistiques -->
            <div class="customer-stats">
                <i class="icon-user"></i> 
                <strong>Total des clients :</strong> <?php echo $totalRecords; ?>
                <span style="margin-left: 20px;">
                    <i class="icon-calendar"></i>
                    <strong>Nouveaux aujourd'hui :</strong>
                    <?php 
                    $todayCount = mysqli_query($con, "SELECT COUNT(*) as count FROM tblcustomer WHERE DATE(CustomerRegdate) = CURDATE()");
                    echo mysqli_fetch_assoc($todayCount)['count'];
                    ?>
                </span>
            </div>

            <!-- Formulaire d'ajout -->
            <div class="customer-form">
                <h4><i class="icon-plus"></i> Ajouter un Nouveau Client</h4>
                <form method="post" class="form-horizontal">
                    <div class="row-fluid">
                        <div class="span6">
                            <div class="control-group">
                                <label class="control-label">Nom Complet *</label>
                                <div class="controls">
                                    <input type="text" name="customer_name" class="span12" required>
                                </div>
                            </div>
                            <div class="control-group">
                                <label class="control-label">Téléphone *</label>
                                <div class="controls">
                                    <input type="tel" name="customer_mobile" class="span12" 
                                           pattern="^(\+?224)?6[0-9]{8}$" 
                                           placeholder="623XXXXXXXX" required>
                                </div>
                            </div>
                        </div>
                        <div class="span6">
                            <div class="control-group">
                                <label class="control-label">Email</label>
                                <div class="controls">
                                    <input type="email" name="customer_email" class="span12">
                                </div>
                            </div>
                            <div class="control-group">
                                <label class="control-label">Adresse</label>
                                <div class="controls">
                                    <textarea name="customer_address" class="span12" rows="2"></textarea>
                                </div>
                            </div>
                        </div>
                    </div>
                    <div class="form-actions">
                        <button type="submit" name="add_customer" class="btn btn-primary">
                            <i class="icon-ok"></i> Ajouter le Client
                        </button>
                        <button type="reset" class="btn">Réinitialiser</button>
                    </div>
                </form>
            </div>

            <!-- Barre de recherche -->
            <div class="row-fluid">
                <div class="span12">
                    <form method="get" class="form-inline">
                        <div class="input-append">
                            <input type="text" name="search" value="<?php echo htmlspecialchars($searchTerm); ?>" 
                                   placeholder="Rechercher par nom, téléphone ou email..." class="span4">
                            <button type="submit" class="btn btn-primary">
                                <i class="icon-search"></i> Rechercher
                            </button>
                        </div>
                        <?php if (!empty($searchTerm)): ?>
                        <a href="manage_customers.php" class="btn">
                            <i class="icon-remove"></i> Effacer
                        </a>
                        <?php endif; ?>
                    </form>
                </div>
            </div>
            <hr>

            <!-- Liste des clients -->
            <div class="widget-box">
                <div class="widget-title">
                    <span class="icon"><i class="icon-th"></i></span>
                    <h5>Liste des Clients (<?php echo $totalRecords; ?>)</h5>
                </div>
                <div class="widget-content nopadding">
                    <table class="table table-bordered table-striped">
                        <thead>
                            <tr>
                                <th>#</th>
                                <th>Nom</th>
                                <th>Téléphone</th>
                                <th>Email</th>
                                <th>Adresse</th>
                                <th>Date d'inscription</th>
                                <th>Actions</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php 
                            if (mysqli_num_rows($customersResult) > 0) {
                                $i = $offset + 1;
                                while ($customer = mysqli_fetch_assoc($customersResult)) {
                                    ?>
                                    <tr>
                                        <td><?php echo $i++; ?></td>
                                        <td><?php echo htmlspecialchars($customer['CustomerName']); ?></td>
                                        <td><?php echo htmlspecialchars($customer['CustomerContact']); ?></td>
                                        <td><?php echo htmlspecialchars($customer['CustomerEmail']); ?></td>
                                        <td><?php echo htmlspecialchars($customer['CustomerAddress']); ?></td>
                                        <td><?php echo date('d/m/Y H:i', strtotime($customer['CustomerRegdate'])); ?></td>
                                        <td class="action-buttons">
                                            <button onclick="editCustomer(<?php echo $customer['id']; ?>, '<?php echo addslashes($customer['CustomerName']); ?>', '<?php echo $customer['CustomerContact']; ?>', '<?php echo addslashes($customer['CustomerEmail']); ?>', '<?php echo addslashes($customer['CustomerAddress']); ?>')" 
                                                    class="btn btn-warning btn-small">
                                                <i class="icon-edit"></i> Modifier
                                            </button>
                                            <a href="manage_customers.php?delete_id=<?php echo $customer['id']; ?>" 
                                               onclick="return confirm('Êtes-vous sûr de vouloir supprimer ce client ?')" 
                                               class="btn btn-danger btn-small">
                                                <i class="icon-trash"></i> Supprimer
                                            </a>
                                        </td>
                                    </tr>
                                    <?php
                                }
                            } else {
                                ?>
                                <tr>
                                    <td colspan="7" class="text-center" style="color: red;">
                                        <?php echo !empty($searchTerm) ? 'Aucun client trouvé pour cette recherche' : 'Aucun client enregistré'; ?>
                                    </td>
                                </tr>
                                <?php
                            }
                            ?>
                        </tbody>
                    </table>
                </div>
            </div>

            <!-- Pagination -->
            <?php if ($totalPages > 1): ?>
            <div class="pagination">
                <?php if ($page > 1): ?>
                    <a href="?page=<?php echo $page-1; ?><?php echo !empty($searchTerm) ? '&search='.urlencode($searchTerm) : ''; ?>">&laquo; Précédent</a>
                <?php endif; ?>
                
                <?php
                $start = max(1, $page - 2);
                $end = min($totalPages, $page + 2);
                
                for ($i = $start; $i <= $end; $i++): ?>
                    <a href="?page=<?php echo $i; ?><?php echo !empty($searchTerm) ? '&search='.urlencode($searchTerm) : ''; ?>" 
                       class="<?php echo $i == $page ? 'active' : ''; ?>">
                        <?php echo $i; ?>
                    </a>
                <?php endfor; ?>
                
                <?php if ($page < $totalPages): ?>
                    <a href="?page=<?php echo $page+1; ?><?php echo !empty($searchTerm) ? '&search='.urlencode($searchTerm) : ''; ?>">Suivant &raquo;</a>
                <?php endif; ?>
            </div>
            <?php endif; ?>
        </div>
    </div>

    <!-- Modal de modification -->
    <div id="editModal" class="modal">
        <div class="modal-content">
            <span class="close">&times;</span>
            <h3>Modifier le Client</h3>
            <form method="post" class="form-horizontal">
                <input type="hidden" name="customer_id" id="edit_id">
                
                <div class="control-group">
                    <label class="control-label">Nom Complet *</label>
                    <div class="controls">
                        <input type="text" name="edit_name" id="edit_name" class="span11" required>
                    </div>
                </div>
                
                <div class="control-group">
                    <label class="control-label">Téléphone *</label>
                    <div class="controls">
                        <input type="tel" name="edit_mobile" id="edit_mobile" class="span11" 
                               pattern="^(\+?224)?6[0-9]{8}$" required>
                    </div>
                </div>
                
                <div class="control-group">
                    <label class="control-label">Email</label>
                    <div class="controls">
                        <input type="email" name="edit_email" id="edit_email" class="span11">
                    </div>
                </div>
                
                <div class="control-group">
                    <label class="control-label">Adresse</label>
                    <div class="controls">
                        <textarea name="edit_address" id="edit_address" class="span11" rows="3"></textarea>
                    </div>
                </div>
                
                <div class="form-actions">
                    <button type="submit" name="edit_customer" class="btn btn-primary">
                        <i class="icon-ok"></i> Modifier
                    </button>
                    <button type="button" onclick="closeModal()" class="btn">Annuler</button>
                </div>
            </form>
        </div>
    </div>

    <?php include_once('includes/footer.php'); ?>
    
    <script src="js/jquery.min.js"></script>
    <script src="js/bootstrap.min.js"></script>
    <script src="js/matrix.js"></script>
    
    <script>
        function editCustomer(id, name, mobile, email, address) {
            document.getElementById('edit_id').value = id;
            document.getElementById('edit_name').value = name;
            document.getElementById('edit_mobile').value = mobile;
            document.getElementById('edit_email').value = email;
            document.getElementById('edit_address').value = address;
            document.getElementById('editModal').style.display = 'block';
        }
        
        function closeModal() {
            document.getElementById('editModal').style.display = 'none';
        }
        
        // Fermer le modal en cliquant sur X ou en dehors
        window.onclick = function(event) {
            var modal = document.getElementById('editModal');
            if (event.target == modal || event.target.className == 'close') {
                modal.style.display = 'none';
            }
        }
        
        // Validation du numéro en temps réel
        document.addEventListener('DOMContentLoaded', function() {
            const mobileInputs = document.querySelectorAll('input[type="tel"]');
            const nimbaFormats = /^(\+?224)?6[0-9]{8}$/;
            
            mobileInputs.forEach(function(input) {
                input.addEventListener('input', function() {
                    const value = this.value.replace(/[^0-9+]/g, '');
                    this.value = value;
                    
                    if (value && !nimbaFormats.test(value)) {
                        this.style.borderColor = '#d9534f';
                        this.title = 'Format invalide. Utilisez: 623XXXXXXXX';
                    } else {
                        this.style.borderColor = '#28a745';
                        this.title = 'Format valide';
                    }
                });
            });
        });
    </script>
</body>
</html>