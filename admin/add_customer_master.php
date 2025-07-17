<?php 
session_start();
error_reporting(E_ALL);
include('includes/dbconnection.php');

// Vérifie que l'admin est connecté
if (empty($_SESSION['imsaid'])) {
    header('Location: logout.php');
    exit;
}

$success_message = '';
$error_message = '';

// Check if tblcustomer_master exists, if not create it
$tableCheck = mysqli_query($con, "SHOW TABLES LIKE 'tblcustomer_master'");
if (mysqli_num_rows($tableCheck) == 0) {
    // Create the table with credit limit
    $createTable = "
    CREATE TABLE `tblcustomer_master` (
      `id` int(11) NOT NULL AUTO_INCREMENT,
      `CustomerName` varchar(255) NOT NULL,
      `CustomerContact` varchar(20) NOT NULL COMMENT 'Phone number (Guinean format)',
      `CustomerEmail` varchar(255) DEFAULT NULL,
      `CustomerAddress` text DEFAULT NULL,
      `CustomerRegdate` timestamp NULL DEFAULT CURRENT_TIMESTAMP,
      `Status` enum('active','inactive') DEFAULT 'active',
      `TotalPurchases` decimal(12,2) DEFAULT 0.00 COMMENT 'Total lifetime purchases',
      `TotalDues` decimal(12,2) DEFAULT 0.00 COMMENT 'Current outstanding amount',
      `CreditLimit` decimal(12,2) DEFAULT 0.00 COMMENT 'Credit limit for customer',
      `LastPurchaseDate` timestamp NULL DEFAULT NULL,
      `Notes` text DEFAULT NULL,
      PRIMARY KEY (`id`),
      UNIQUE KEY `unique_contact` (`CustomerContact`),
      KEY `idx_customer_email` (`CustomerEmail`),
      KEY `idx_customer_name` (`CustomerName`),
      KEY `idx_status` (`Status`)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci 
    COMMENT='Master customer table for customer management'";
    
    if (mysqli_query($con, $createTable)) {
        $success_message = 'Table client créée avec succès. ';
    } else {
        $error_message = 'Erreur lors de la création de la table: ' . mysqli_error($con);
    }
} else {
    // Check if CreditLimit column exists, if not add it
    $columnCheck = mysqli_query($con, "SHOW COLUMNS FROM tblcustomer_master LIKE 'CreditLimit'");
    if (mysqli_num_rows($columnCheck) == 0) {
        $addColumn = "ALTER TABLE `tblcustomer_master` ADD `CreditLimit` decimal(12,2) DEFAULT 0.00 COMMENT 'Credit limit for customer' AFTER `TotalDues`";
        if (mysqli_query($con, $addColumn)) {
            $success_message = 'Colonne plafond de crédit ajoutée avec succès. ';
        } else {
            $error_message = 'Erreur lors de l\'ajout de la colonne plafond: ' . mysqli_error($con);
        }
    }
}

// Ajouter un nouveau client
if (isset($_POST['add_customer'])) {
    $name = mysqli_real_escape_string($con, trim($_POST['customer_name']));
    $mobile = preg_replace('/[^0-9+]/', '', $_POST['customer_mobile']);
    $email = mysqli_real_escape_string($con, trim($_POST['customer_email']));
    $address = mysqli_real_escape_string($con, trim($_POST['customer_address']));
    $creditLimit = floatval($_POST['credit_limit']); // Nouveau champ plafond
    
    // Validation
    if (empty($name) || empty($mobile)) {
        $error_message = 'Le nom et le numéro de téléphone sont obligatoires';
    } elseif (!preg_match('/^(\+?224)?6[0-9]{8}$/', $mobile)) {
        $error_message = 'Format de numéro invalide. Utilisez le format: 623XXXXXXXX';
    } elseif ($creditLimit < 0) {
        $error_message = 'Le plafond de crédit ne peut pas être négatif';
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
                $insertQuery = "INSERT INTO tblcustomer_master (CustomerName, CustomerContact, CustomerEmail, CustomerAddress, CreditLimit, CustomerRegdate) 
                                VALUES ('$name', '$mobile', '$email', '$address', '$creditLimit', NOW())";
                
                if (mysqli_query($con, $insertQuery)) {
                    $success_message .= 'Client ajouté avec succès dans le répertoire client';
                    // Réinitialiser les champs après succès
                    $_POST = array();
                } else {
                    $error_message = 'Erreur lors de l\'ajout du client: ' . mysqli_error($con);
                }
            }
        }
    }
}

// Statistiques pour l'affichage - utiliser la table master des clients
$totalCustomers = 0;
$todayCustomers = 0;
$totalBills = 0;
$totalRevenue = 0;
$totalCreditLimit = 0;

// Check if table exists before querying
$tableCheck = mysqli_query($con, "SHOW TABLES LIKE 'tblcustomer_master'");
if (mysqli_num_rows($tableCheck) > 0) {
    $totalCustomers = mysqli_fetch_assoc(mysqli_query($con, "SELECT COUNT(*) as count FROM tblcustomer_master"))['count'];
    $todayCustomers = mysqli_fetch_assoc(mysqli_query($con, "SELECT COUNT(*) as count FROM tblcustomer_master WHERE DATE(CustomerRegdate) = CURDATE()"))['count'];
    $totalCreditLimit = mysqli_fetch_assoc(mysqli_query($con, "SELECT SUM(CreditLimit) as total FROM tblcustomer_master"))['total'];
}

// Statistiques du système de facturation existant
$totalBills = mysqli_fetch_assoc(mysqli_query($con, "SELECT COUNT(*) as count FROM tblcustomer"))['count'];
$totalRevenue = mysqli_fetch_assoc(mysqli_query($con, "SELECT SUM(FinalAmount) as total FROM tblcustomer"))['total'];
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
        .billing-stats {
            background-color: #f0f8e7;
            border-left: 4px solid #28a745;
            padding: 15px;
            margin-bottom: 20px;
        }
        .credit-stats {
            background-color: #fff3cd;
            border-left: 4px solid #ffc107;
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
        .setup-info {
            background-color: #fcf8e3;
            border: 1px solid #faebcc;
            color: #8a6d3b;
            padding: 15px;
            margin-bottom: 20px;
            border-radius: 4px;
        }
        .credit-limit-warning {
            background-color: #fff5f5;
            border: 1px solid #fed7d7;
            color: #c53030;
            padding: 10px;
            border-radius: 4px;
            margin-top: 10px;
            display: none;
        }
        .currency-symbol {
            font-weight: bold;
            color: #666;
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
            <!-- Info de configuration -->
            <div class="setup-info">
                <i class="icon-info-sign"></i>
                <strong>Configuration automatique:</strong> Cette page configure automatiquement votre système de gestion client. 
                Le répertoire client sera séparé de votre système de facturation existant.
            </div>

            <!-- Statistiques Clients -->
            <div class="customer-stats">
                <i class="icon-user"></i> 
                <strong>Répertoire Client:</strong> <?php echo $totalCustomers; ?> clients
                <span style="margin-left: 20px;">
                    <i class="icon-calendar"></i>
                    <strong>Nouveaux aujourd'hui:</strong> <?php echo $todayCustomers; ?>
                </span>
            </div>

            <!-- Statistiques Plafond de Crédit -->
            <div class="credit-stats">
                <i class="icon-credit-card"></i> 
                <strong>Plafond Total Accordé:</strong> <?php echo number_format($totalCreditLimit ?: 0); ?> GNF
            </div>

            <!-- Statistiques Facturation -->
            <div class="billing-stats">
                <i class="icon-file-text"></i> 
                <strong>Système de Facturation:</strong> <?php echo $totalBills; ?> factures
                <span style="margin-left: 20px;">
                    <i class="icon-money"></i>
                    <strong>Chiffre d'affaires:</strong> <?php echo number_format($totalRevenue ?: 0); ?> GNF
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
                                        <label class="control-label">Plafond de Crédit</label>
                                        <div class="controls">
                                            <div class="input-append">
                                                <input type="number" name="credit_limit" id="credit_limit" 
                                                       class="span6" min="0" step="1000"
                                                       placeholder="0"
                                                       value="<?php echo isset($_POST['credit_limit']) ? htmlspecialchars($_POST['credit_limit']) : '0'; ?>">
                                                <span class="add-on currency-symbol">GNF</span>
                                            </div>
                                            <div class="form-validation" id="credit_validation">
                                                <small class="muted">Montant maximum que le client peut devoir (0 = aucune limite)</small>
                                            </div>
                                            <div class="credit-limit-warning" id="credit_warning">
                                                <i class="icon-warning-sign"></i>
                                                <strong>Attention:</strong> Un plafond élevé présente des risques financiers. Assurez-vous de la solvabilité du client.
                                            </div>
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
                                        <a href="manage_customers.php" class="btn btn-large">
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
                            
                            <h6><i class="icon-credit-card"></i> Plafond de Crédit</h6>
                            <ul>
                                <li><strong>0 GNF :</strong> Aucune limite de crédit</li>
                                <li><strong>&gt; 0 :</strong> Montant maximum de dette autorisé</li>
                                <li>Le système empêchera les ventes si le plafond est dépassé</li>
                                <li>Recommandé : 50 000 à 500 000 GNF selon le client</li>
                            </ul>
                            
                            <h6><i class="icon-info-sign"></i> Système</h6>
                            <p>Cette page gère le <strong>répertoire client</strong> principal. Vos factures existantes restent intactes.</p>
                            
                            <h6><i class="icon-link"></i> Intégration</h6>
                            <p>Les clients créés ici seront automatiquement liés aux futures factures avec contrôle du plafond.</p>
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
                            <span class="preview-label">Plafond :</span>
                            <span id="preview_credit" class="currency-symbol">0 GNF</span>
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
            
            // Validation du plafond de crédit
            $('#credit_limit').on('input blur', function() {
                const value = parseFloat($(this).val()) || 0;
                const validation = $('#credit_validation');
                const warning = $('#credit_warning');
                
                if (value < 0) {
                    validation.html('<span class="validation-error">Le plafond ne peut pas être négatif</span>');
                    warning.hide();
                } else if (value === 0) {
                    validation.html('<span class="validation-success">✓ Aucune limite de crédit</span>');
                    warning.hide();
                } else if (value > 1000000) {
                    validation.html('<span class="validation-error">Plafond très élevé - Vérifiez la solvabilité</span>');
                    warning.show();
                } else if (value > 500000) {
                    validation.html('<span class="validation-success">✓ Plafond élevé défini</span>');
                    warning.show();
                } else {
                    validation.html('<span class="validation-success">✓ Plafond défini: ' + formatNumber(value) + ' GNF</span>');
                    warning.hide();
                }
                updatePreview();
            });
            
            // Mise à jour de l'aperçu
            $('#customer_address').on('input', updatePreview);
            
            function formatNumber(num) {
                return num.toString().replace(/\B(?=(\d{3})+(?!\d))/g, " ");
            }
            
            function updatePreview() {
                const name = $('#customer_name').val().trim();
                const mobile = $('#customer_mobile').val().trim();
                const email = $('#customer_email').val().trim();
                const address = $('#customer_address').val().trim();
                const creditLimit = parseFloat($('#credit_limit').val()) || 0;
                
                $('#preview_name').text(name || '-');
                $('#preview_mobile').text(mobile || '-');
                $('#preview_email').text(email || '-');
                $('#preview_credit').text(formatNumber(creditLimit) + ' GNF');
                $('#preview_address').text(address || '-');
                
                // Afficher l'aperçu si au moins un champ est rempli
                if (name || mobile || email || address || creditLimit > 0) {
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
                const creditLimit = parseFloat($('#credit_limit').val()) || 0;
                
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
                
                if (creditLimit < 0) {
                    errorMessage += 'Le plafond de crédit ne peut pas être négatif.\n';
                    isValid = false;
                }
                
                if (!isValid) {
                    alert(errorMessage);
                    e.preventDefault();
                    return false;
                }
                
                // Confirmation avant ajout avec plafond
                let confirmMessage = `Confirmer l'ajout du client :\n\nNom: ${name}\nTéléphone: ${mobile}\nEmail: ${email || 'Non renseigné'}\nPlafond de crédit: ${formatNumber(creditLimit)} GNF`;
                
                if (creditLimit > 500000) {
                    confirmMessage += '\n\n⚠️ ATTENTION: Plafond de crédit élevé!';
                }
                
                if (!confirm(confirmMessage)) {
                    e.preventDefault();
                    return false;
                }
            });
        });
        
        function resetForm() {
            $('#customerPreview').hide();
            $('#credit_warning').hide();
            $('.form-validation').html('');
            $('#mobile_validation').html('<small class="muted">Format: 623XXXXXXXX (numéros Guinéens)</small>');
            $('#credit_validation').html('<small class="muted">Montant maximum que le client peut devoir (0 = aucune limite)</small>');
        }
    </script>
</body>
</html>