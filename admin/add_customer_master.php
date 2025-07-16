<?php 
session_start();
ini_set('display_errors', 1);
ini_set('display_startup_errors', 1);
error_reporting(E_ALL);
include('includes/dbconnection.php');

// Vérifie que l'admin est connecté
if (empty($_SESSION['imsaid'])) {
    header('Location: logout.php');
    exit;
}

$success_message = '';
$error_message = '';

// Ajouter un nouveau client
if (isset($_POST['add_customer'])) {
    $name = mysqli_real_escape_string($con, trim($_POST['customer_name']));
    $mobile = preg_replace('/[^0-9+]/', '', $_POST['customer_mobile']);
    $email = mysqli_real_escape_string($con, trim($_POST['customer_email']));
    $address = mysqli_real_escape_string($con, trim($_POST['customer_address']));
    
    // Validation
    if (empty($name) || empty($mobile)) {
        $error_message = 'Le nom et le numéro de téléphone sont obligatoires';
    } elseif (!preg_match('/^(\+?224)?6[0-9]{8}$/', $mobile)) {
        $error_message = 'Format de numéro invalide. Utilisez le format: 623XXXXXXXX';
    } else {
        // Vérifier si le client existe déjà dans la table master
        $checkQuery = mysqli_query($con, "SELECT id FROM tblcustomer_master WHERE CustomerContact='$mobile' LIMIT 1");
        if (mysqli_num_rows($checkQuery) > 0) {
            $error_message = 'Un client avec ce numéro de téléphone existe déjà';
        } else {
            // Valider l'email si fourni
            if (!empty($email) && !filter_var($email, FILTER_VALIDATE_EMAIL)) {
                $error_message = 'Format d\'email invalide';
            } else {
                $insertQuery = "INSERT INTO tblcustomer_master (CustomerName, CustomerContact, CustomerEmail, CustomerAddress, CustomerRegdate) 
                                VALUES ('$name', '$mobile', '$email', '$address', NOW())";
                
                if (mysqli_query($con, $insertQuery)) {
                    $success_message = 'Client ajouté avec succès dans le répertoire client';
                    // Réinitialiser les champs après succès
                    $_POST = array();
                } else {
                    $error_message = 'Erreur lors de l\'ajout du client: ' . mysqli_error($con);
                }
            }
        }
    }
}

// Statistiques pour l'affichage
$totalCustomers = mysqli_fetch_assoc(mysqli_query($con, "SELECT COUNT(*) as count FROM tblcustomer"))['count'];
$todayCustomers = mysqli_fetch_assoc(mysqli_query($con, "SELECT COUNT(*) as count FROM tblcustomer WHERE DATE(CustomerRegdate) = CURDATE()"))['count'];
?>

<!DOCTYPE html>
<html lang="fr">
<head>
    <title>Ajouter un Client | Système de Gestion d'Inventaire</title>
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
        .alert {
            padding: 15px;
            margin-bottom: 20px;
            border: 1px solid transparent;
            border-radius: 4px;
        }
        .alert-success {
            color: #3c763d;
            background-color: #dff0d8;
            border-color: #d6e9c6;
        }
        .alert-danger {
            color: #a94442;
            background-color: #f2dede;
            border-color: #ebccd1;
        }
        .form-validation {
            margin-top: 5px;
            font-size: 12px;
        }
        .validation-error {
            color: #d9534f;
        }
        .validation-success {
            color: #5cb85c;
        }
        .required {
            color: #d9534f;
        }
        .form-actions {
            background-color: #f5f5f5;
            border-top: 1px solid #ddd;
            border-radius: 0 0 4px 4px;
            margin: 20px -20px -20px;
            padding: 19px 20px 20px;
        }
        .customer-preview {
            background-color: #fff;
            border: 1px solid #ddd;
            border-radius: 5px;
            padding: 15px;
            margin-top: 20px;
            display: none;
        }
        .preview-title {
            color: #333;
            margin-bottom: 10px;
            font-weight: bold;
        }
        .preview-field {
            margin-bottom: 8px;
        }
        .preview-label {
            font-weight: bold;
            display: inline-block;
            width: 120px;
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
                <a href="manage_customer_master.php" class="tip-bottom">
                    <i class="icon-user"></i> Répertoire Client
                </a>
                <a href="add_customer_master.php" class="current">Ajouter un Client</a>
            </div>
            <h1>Ajouter un Nouveau Client</h1>
        </div>
        
        <div class="container-fluid">
            <!-- Statistiques -->
            <div class="customer-stats">
                <i class="icon-user"></i> 
                <strong>Total des clients :</strong> <?php echo $totalCustomers; ?>
                <span style="margin-left: 20px;">
                    <i class="icon-calendar"></i>
                    <strong>Nouveaux aujourd'hui :</strong> <?php echo $todayCustomers; ?>
                </span>
            </div>

            <!-- Messages d'alerte -->
            <?php if (!empty($success_message)): ?>
            <div class="alert alert-success">
                <i class="icon-ok"></i> <?php echo $success_message; ?>
                <a href="manage_customer_master.php" style="margin-left: 15px;" class="btn btn-small btn-primary">
                    <i class="icon-list"></i> Voir le répertoire client
                </a>
            </div>
            <?php endif; ?>

            <?php if (!empty($error_message)): ?>
            <div class="alert alert-danger">
                <i class="icon-remove"></i> <?php echo $error_message; ?>
            </div>
            <?php endif; ?>

            <div class="row-fluid">
                <div class="span8">
                    <!-- Formulaire d'ajout -->
                    <div class="widget-box">
                        <div class="widget-title">
                            <span class="icon"><i class="icon-plus"></i></span>
                            <h5>Informations du Client</h5>
                        </div>
                        <div class="widget-content nopadding">
                            <div class="customer-form">
                                <form method="post" class="form-horizontal" id="customerForm">
                                    <div class="control-group">
                                        <label class="control-label">Nom Complet <span class="required">*</span></label>
                                        <div class="controls">
                                            <input type="text" name="customer_name" id="customer_name" 
                                                   class="span10" required 
                                                   value="<?php echo isset($_POST['customer_name']) ? htmlspecialchars($_POST['customer_name']) : ''; ?>"
                                                   placeholder="Entrez le nom complet du client">
                                            <div class="form-validation" id="name_validation"></div>
                                        </div>
                                    </div>
                                    
                                    <div class="control-group">
                                        <label class="control-label">Numéro de Téléphone <span class="required">*</span></label>
                                        <div class="controls">
                                            <div class="input-prepend">
                                                <span class="add-on"><i class="icon-phone"></i></span>
                                                <input type="tel" name="customer_mobile" id="customer_mobile" 
                                                       class="span8" required 
                                                       pattern="^(\+?224)?6[0-9]{8}$" 
                                                       placeholder="623XXXXXXXX"
                                                       value="<?php echo isset($_POST['customer_mobile']) ? htmlspecialchars($_POST['customer_mobile']) : ''; ?>">
                                            </div>
                                            <div class="form-validation" id="mobile_validation">
                                                <small class="muted">Format: 623XXXXXXXX (numéros Guinéens)</small>
                                            </div>
                                        </div>
                                    </div>
                                    
                                    <div class="control-group">
                                        <label class="control-label">Adresse Email</label>
                                        <div class="controls">
                                            <div class="input-prepend">
                                                <span class="add-on"><i class="icon-envelope"></i></span>
                                                <input type="email" name="customer_email" id="customer_email" 
                                                       class="span8"
                                                       placeholder="email@exemple.com"
                                                       value="<?php echo isset($_POST['customer_email']) ? htmlspecialchars($_POST['customer_email']) : ''; ?>">
                                            </div>
                                            <div class="form-validation" id="email_validation"></div>
                                        </div>
                                    </div>
                                    
                                    <div class="control-group">
                                        <label class="control-label">Adresse Physique</label>
                                        <div class="controls">
                                            <textarea name="customer_address" id="customer_address" 
                                                      class="span10" rows="3" 
                                                      placeholder="Adresse complète du client (optionnel)"><?php echo isset($_POST['customer_address']) ? htmlspecialchars($_POST['customer_address']) : ''; ?></textarea>
                                            <div class="form-validation" id="address_validation"></div>
                                        </div>
                                    </div>
                                    
                                    <div class="form-actions">
                                        <button type="submit" name="add_customer" class="btn btn-primary btn-large">
                                            <i class="icon-ok"></i> Ajouter le Client
                                        </button>
                                        <button type="reset" class="btn btn-large" onclick="resetForm()">
                                            <i class="icon-refresh"></i> Réinitialiser
                                        </button>
                                        <a href="manage_customer_master.php" class="btn btn-large">
                                            <i class="icon-arrow-left"></i> Retour au répertoire
                                        </a>
                                    </div>
                                </form>
                            </div>
                        </div>
                    </div>
                </div>

                <div class="span4">
                    <!-- Aide et instructions -->
                    <div class="widget-box">
                        <div class="widget-title">
                            <span class="icon"><i class="icon-info-sign"></i></span>
                            <h5>Instructions</h5>
                        </div>
                        <div class="widget-content">
                            <h6><i class="icon-star"></i> Champs Obligatoires</h6>
                            <ul>
                                <li><strong>Nom Complet :</strong> Nom et prénom du client</li>
                                <li><strong>Téléphone :</strong> Numéro de téléphone guinéen valide</li>
                            </ul>
                            
                            <h6><i class="icon-phone"></i> Format du Téléphone</h6>
                            <ul>
                                <li>Format accepté : <code>623XXXXXXXX</code></li>
                                <li>Avec indicatif : <code>+224623XXXXXXXX</code></li>
                                <li>Les numéros doivent commencer par 6</li>
                            </ul>
                            
                            <h6><i class="icon-envelope"></i> Email (Optionnel)</h6>
                            <p>Format email valide requis si renseigné</p>
                        </div>
                    </div>

                    <!-- Aperçu du client -->
                    <div class="customer-preview" id="customerPreview">
                        <div class="preview-title">
                            <i class="icon-eye-open"></i> Aperçu du Client
                        </div>
                        <div class="preview-field">
                            <span class="preview-label">Nom :</span>
                            <span id="preview_name">-</span>
                        </div>
                        <div class="preview-field">
                            <span class="preview-label">Téléphone :</span>
                            <span id="preview_mobile">-</span>
                        </div>
                        <div class="preview-field">
                            <span class="preview-label">Email :</span>
                            <span id="preview_email">-</span>
                        </div>
                        <div class="preview-field">
                            <span class="preview-label">Adresse :</span>
                            <span id="preview_address">-</span>
                        </div>
                    </div>
                </div>
            </div>
        </div>
    </div>

    <?php include_once('includes/footer.php'); ?>
    
    <script src="js/jquery.min.js"></script>
    <script src="js/bootstrap.min.js"></script>
    <script src="js/matrix.js"></script>
    
    <script>
        $(document).ready(function() {
            // Validation en temps réel
            const phonePattern = /^(\+?224)?6[0-9]{8}$/;
            const emailPattern = /^[^\s@]+@[^\s@]+\.[^\s@]+$/;
            
            // Validation du nom
            $('#customer_name').on('input blur', function() {
                const value = $(this).val().trim();
                const validation = $('#name_validation');
                
                if (value.length === 0) {
                    validation.html('').removeClass('validation-error validation-success');
                } else if (value.length < 2) {
                    validation.html('<span class="validation-error">Le nom doit contenir au moins 2 caractères</span>');
                } else if (!/^[a-zA-ZÀ-ÿ\s]+$/.test(value)) {
                    validation.html('<span class="validation-error">Le nom ne doit contenir que des lettres</span>');
                } else {
                    validation.html('<span class="validation-success">✓ Nom valide</span>');
                }
                updatePreview();
            });
            
            // Validation du téléphone
            $('#customer_mobile').on('input blur', function() {
                let value = $(this).val().replace(/[^0-9+]/g, '');
                $(this).val(value);
                
                const validation = $('#mobile_validation');
                
                if (value.length === 0) {
                    validation.html('<small class="muted">Format: 623XXXXXXXX (numéros Guinéens)</small>');
                } else if (!phonePattern.test(value)) {
                    validation.html('<span class="validation-error">Format invalide. Utilisez: 623XXXXXXXX</span>');
                } else {
                    validation.html('<span class="validation-success">✓ Numéro valide</span>');
                }
                updatePreview();
            });
            
            // Validation de l'email
            $('#customer_email').on('input blur', function() {
                const value = $(this).val().trim();
                const validation = $('#email_validation');
                
                if (value.length === 0) {
                    validation.html('').removeClass('validation-error validation-success');
                } else if (!emailPattern.test(value)) {
                    validation.html('<span class="validation-error">Format d\'email invalide</span>');
                } else {
                    validation.html('<span class="validation-success">✓ Email valide</span>');
                }
                updatePreview();
            });
            
            // Mise à jour de l'aperçu
            $('#customer_address').on('input', updatePreview);
            
            function updatePreview() {
                const name = $('#customer_name').val().trim();
                const mobile = $('#customer_mobile').val().trim();
                const email = $('#customer_email').val().trim();
                const address = $('#customer_address').val().trim();
                
                $('#preview_name').text(name || '-');
                $('#preview_mobile').text(mobile || '-');
                $('#preview_email').text(email || '-');
                $('#preview_address').text(address || '-');
                
                // Afficher l'aperçu si au moins un champ est rempli
                if (name || mobile || email || address) {
                    $('#customerPreview').show();
                } else {
                    $('#customerPreview').hide();
                }
            }
            
            // Validation avant soumission
            $('#customerForm').on('submit', function(e) {
                const name = $('#customer_name').val().trim();
                const mobile = $('#customer_mobile').val().trim();
                const email = $('#customer_email').val().trim();
                
                let isValid = true;
                let errorMessage = '';
                
                if (!name) {
                    errorMessage += 'Le nom est obligatoire.\n';
                    isValid = false;
                }
                
                if (!mobile) {
                    errorMessage += 'Le numéro de téléphone est obligatoire.\n';
                    isValid = false;
                } else if (!phonePattern.test(mobile)) {
                    errorMessage += 'Le format du numéro de téléphone est invalide.\n';
                    isValid = false;
                }
                
                if (email && !emailPattern.test(email)) {
                    errorMessage += 'Le format de l\'email est invalide.\n';
                    isValid = false;
                }
                
                if (!isValid) {
                    alert(errorMessage);
                    e.preventDefault();
                    return false;
                }
                
                // Confirmation avant ajout
                const confirmMessage = `Confirmer l'ajout du client :\n\nNom: ${name}\nTéléphone: ${mobile}\nEmail: ${email || 'Non renseigné'}`;
                if (!confirm(confirmMessage)) {
                    e.preventDefault();
                    return false;
                }
            });
        });
        
        function resetForm() {
            $('#customerPreview').hide();
            $('.form-validation').html('');
            $('#mobile_validation').html('<small class="muted">Format: 623XXXXXXXX (numéros Guinéens)</small>');
        }
    </script>
</body>
</html>