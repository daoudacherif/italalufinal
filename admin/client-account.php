<?php
session_start();
error_reporting(0);
include('includes/dbconnection.php');

// Vérifier si l'admin est connecté
if (strlen($_SESSION['imsaid'] == 0)) {
  header('location:logout.php');
  exit;
}

// ==========================
// 1) Filtre de recherche
// ==========================
$searchTerm = '';
$whereClause = '';
if (isset($_GET['searchTerm']) && !empty($_GET['searchTerm'])) {
  $searchTerm = mysqli_real_escape_string($con, $_GET['searchTerm']);
  $whereClause = "WHERE (tc.CustomerName LIKE '%$searchTerm%' OR tc.MobileNumber LIKE '%$searchTerm%')";
}

// ==========================
// 2) Requête pour lister les clients avec informations d'échéances
// ==========================
$sql = "
  SELECT 
    tc.CustomerName,
    tc.MobileNumber,
    SUM(tc.FinalAmount) AS totalBilled,
    SUM(tc.Paid) AS totalPaid,
    SUM(tc.Dues) AS totalDue,
    COUNT(tc.ID) AS nombreFactures,
    MIN(CASE WHEN tc.Dues > 0 AND tcc.DateEcheance IS NOT NULL THEN tcc.DateEcheance END) AS prochaineEcheance,
    COUNT(CASE WHEN tc.Dues > 0 AND tcc.DateEcheance < CURDATE() THEN 1 END) AS facturesEchues,
    COUNT(CASE WHEN tc.Dues > 0 AND tcc.DateEcheance BETWEEN CURDATE() AND DATE_ADD(CURDATE(), INTERVAL 7 DAY) THEN 1 END) AS facturesBientotEchues,
    MAX(tc.BillingDate) AS derniereFacture
  FROM tblcustomer tc
  LEFT JOIN tblcreditcart tcc ON tcc.BillingId = tc.BillingNumber
  $whereClause
  GROUP BY tc.CustomerName, tc.MobileNumber
  HAVING SUM(tc.FinalAmount) > 0
  ORDER BY 
    CASE 
      WHEN SUM(tc.Dues) > 0 AND MIN(CASE WHEN tc.Dues > 0 AND tcc.DateEcheance IS NOT NULL THEN tcc.DateEcheance END) < CURDATE() THEN 1
      WHEN SUM(tc.Dues) > 0 AND MIN(CASE WHEN tc.Dues > 0 AND tcc.DateEcheance IS NOT NULL THEN tcc.DateEcheance END) BETWEEN CURDATE() AND DATE_ADD(CURDATE(), INTERVAL 7 DAY) THEN 2
      ELSE 3
    END,
    tc.CustomerName ASC
";
$res = mysqli_query($con, $sql);

// Statistiques générales
$statsQuery = "
  SELECT 
    COUNT(DISTINCT tc.CustomerName, tc.MobileNumber) as totalClients,
    SUM(tc.Dues) as totalCreances,
    COUNT(CASE WHEN tc.Dues > 0 AND tcc.DateEcheance < CURDATE() THEN 1 END) as facturesEchues,
    COUNT(CASE WHEN tc.Dues > 0 AND tcc.DateEcheance BETWEEN CURDATE() AND DATE_ADD(CURDATE(), INTERVAL 7 DAY) THEN 1 END) as facturesBientot
  FROM tblcustomer tc
  LEFT JOIN tblcreditcart tcc ON tcc.BillingId = tc.BillingNumber
  WHERE tc.FinalAmount > 0
";
$statsResult = mysqli_query($con, $statsQuery);
$stats = mysqli_fetch_assoc($statsResult);
?>
<!DOCTYPE html>
<html lang="fr">
<head>
  <title>Compte Client - Suivi des Échéances</title>
  <meta name="viewport" content="width=device-width, initial-scale=1.0">
  <?php include_once('includes/cs.php'); ?>
  <?php include_once('includes/responsive.php'); ?>
  <style>
    .stats-row {
      display: flex;
      gap: 15px;
      margin-bottom: 20px;
      flex-wrap: wrap;
    }
    
    .stat-card {
      flex: 1;
      min-width: 200px;
      background: #fff;
      border-radius: 8px;
      padding: 15px;
      box-shadow: 0 2px 4px rgba(0,0,0,0.1);
      border-left: 4px solid;
    }
    
    .stat-card.total { border-left-color: #007bff; }
    .stat-card.creances { border-left-color: #28a745; }
    .stat-card.echues { border-left-color: #dc3545; }
    .stat-card.bientot { border-left-color: #ffc107; }
    
    .stat-value {
      font-size: 24px;
      font-weight: bold;
      margin-bottom: 5px;
    }
    
    .stat-label {
      font-size: 12px;
      color: #666;
      text-transform: uppercase;
    }
    
    .search-section {
      background: #f8f9fa;
      padding: 15px;
      border-radius: 5px;
      margin-bottom: 20px;
    }
    
    .echeance-badge {
      padding: 2px 6px;
      border-radius: 12px;
      font-size: 10px;
      font-weight: bold;
      text-transform: uppercase;
    }
    
    .echeance-echue {
      background-color: #dc3545;
      color: white;
    }
    
    .echeance-bientot {
      background-color: #ffc107;
      color: #212529;
    }
    
    .echeance-normale {
      background-color: #28a745;
      color: white;
    }
    
    .echeance-reglee {
      background-color: #6c757d;
      color: white;
    }
    
    .client-row.urgent {
      background-color: #fff5f5 !important;
      border-left: 3px solid #dc3545;
    }
    
    .client-row.attention {
      background-color: #fffbf0 !important;
      border-left: 3px solid #ffc107;
    }
    
    .montant-du {
      color: #dc3545;
      font-weight: bold;
    }
    
    .montant-ok {
      color: #28a745;
      font-weight: bold;
    }
    
    .refresh-indicator {
      position: fixed;
      top: 10px;
      right: 10px;
      background: #007bff;
      color: white;
      padding: 5px 10px;
      border-radius: 15px;
      font-size: 11px;
      display: none;
    }
    
    .quick-response {
      background: #e7f3ff;
      border: 1px solid #bee5eb;
      border-radius: 4px;
      padding: 10px;
      margin-top: 10px;
      display: none;
    }
    
    .response-template {
      background: #f8f9fa;
      border: 1px solid #dee2e6;
      border-radius: 4px;
      padding: 8px;
      margin: 5px 0;
      cursor: pointer;
    }
    
    .response-template:hover {
      background: #e9ecef;
    }
    
    @media (max-width: 768px) {
      .stats-row {
        flex-direction: column;
      }
    }
  </style>
</head>
<body>

<?php include_once('includes/header.php'); ?>
<?php include_once('includes/sidebar.php'); ?>

<div id="content">
  <div id="content-header">
    <div id="breadcrumb"> 
      <a href="dashboard.php" title="Aller à l'accueil" class="tip-bottom">
        <i class="icon-home"></i> Accueil
      </a> 
      <a href="client-account.php" class="current">Compte Client</a> 
    </div>
    <h1>Compte Client - Suivi des Échéances</h1>
  </div>
  
  <div class="container-fluid">
    <!-- Indicateur de mise à jour -->
    <div id="refreshIndicator" class="refresh-indicator">
      <i class="icon-refresh"></i> Mise à jour...
    </div>

    <!-- Statistiques -->
    <div class="stats-row">
      <div class="stat-card total">
        <div class="stat-value"><?php echo $stats['totalClients']; ?></div>
        <div class="stat-label">Clients Total</div>
      </div>
      <div class="stat-card creances">
        <div class="stat-value"><?php echo number_format($stats['totalCreances']); ?> GNF</div>
        <div class="stat-label">Créances Totales</div>
      </div>
      <div class="stat-card echues">
        <div class="stat-value"><?php echo $stats['facturesEchues']; ?></div>
        <div class="stat-label">Factures Échues</div>
      </div>
      <div class="stat-card bientot">
        <div class="stat-value"><?php echo $stats['facturesBientot']; ?></div>
        <div class="stat-label">Bientôt Échues</div>
      </div>
    </div>

    <!-- Section de recherche améliorée -->
    <div class="search-section">
      <form method="get" action="client-account.php" class="form-inline">
        <div class="row-fluid">
          <div class="span6">
            <label>Rechercher un client :</label>
            <input type="text" name="searchTerm" placeholder="Nom, téléphone..." 
                   value="<?php echo htmlspecialchars($searchTerm); ?>" class="span12" 
                   id="searchInput" />
          </div>
          <div class="span3">
            <label>&nbsp;</label>
            <button type="submit" class="btn btn-primary span12">
              <i class="icon-search"></i> Rechercher
            </button>
          </div>
          <div class="span3">
            <label>&nbsp;</label>
            <button type="button" onclick="toggleQuickResponse()" class="btn btn-info span12">
              <i class="icon-comment"></i> Réponse Rapide
            </button>
          </div>
        </div>
      </form>
      
      <!-- Section réponse rapide -->
      <div id="quickResponseSection" class="quick-response">
        <h5><i class="icon-comment"></i> Modèles de Réponse Client</h5>
        <div class="response-template" onclick="copyToClipboard(this.textContent)">
          "Bonjour, votre facture N°[NUMERO] arrive à échéance le [DATE]. Merci de prévoir le règlement."
        </div>
        <div class="response-template" onclick="copyToClipboard(this.textContent)">
          "Rappel: votre facture de [MONTANT] GNF est échue depuis le [DATE]. Merci de régulariser."
        </div>
        <div class="response-template" onclick="copyToClipboard(this.textContent)">
          "Votre solde actuel est de [MONTANT] GNF. Prochaine échéance: [DATE]."
        </div>
        <small class="text-muted">Cliquez sur un modèle pour le copier. Remplacez [NUMERO], [DATE], [MONTANT] par les vraies valeurs.</small>
      </div>
    </div>

    <!-- Actions rapides -->
    <div class="row-fluid">
      <div class="span12">
        <a href="dettecart.php" class="btn btn-success">
          <i class="icon-plus"></i> Nouvelle Vente
        </a>
        <a href="factures_echeance.php" class="btn btn-warning">
          <i class="icon-time"></i> Gestion Échéances
        </a>
        <button onclick="autoRefresh()" class="btn btn-info" id="refreshBtn">
          <i class="icon-refresh"></i> Actualiser
        </button>
        <span class="text-muted small" id="lastUpdate">
          Dernière mise à jour: <?php echo date('H:i:s'); ?>
        </span>
      </div>
    </div>
    <hr>

    <!-- Tableau des clients avec échéances -->
    <div class="widget-box">
      <div class="widget-title"> 
        <span class="icon"><i class="icon-th"></i></span>
        <h5>Liste des clients avec échéances</h5>
      </div>
      <div class="widget-content nopadding">
        <table class="table table-bordered table-striped" id="clientsTable">
          <thead>
            <tr>
              <th>#</th>
              <th>Nom du client</th>
              <th>Téléphone</th>
              <th>Factures</th>
              <th>Total Facturé</th>
              <th>Total Payé</th>
              <th>Reste à Payer</th>
              <th>Prochaine Échéance</th>
              <th>Statut</th>
              <th>Action</th>
            </tr>
          </thead>
          <tbody>
          <?php
          // Variables pour accumuler les totaux
          $grandBilled = 0;
          $grandPaid   = 0;
          $grandDue    = 0;

          $cnt = 1;
          while ($row = mysqli_fetch_assoc($res)) {
            $customerName = $row['CustomerName'];
            $mobile       = $row['MobileNumber'];
            $billed       = intval($row['totalBilled']);
            $paid         = intval($row['totalPaid']);
            $due          = intval($row['totalDue']);
            $nombreFactures = $row['nombreFactures'];
            $prochaineEcheance = $row['prochaineEcheance'];
            $facturesEchues = $row['facturesEchues'];
            $facturesBientot = $row['facturesBientotEchues'];

            // Accumuler dans les variables globales
            $grandBilled += $billed;
            $grandPaid   += $paid;
            $grandDue    += $due;

            // Déterminer le statut et la classe CSS
            $statusClass = '';
            $statusBadge = '';
            $rowClass = '';
            
            if ($due <= 0) {
              $statusBadge = '<span class="echeance-badge echeance-reglee">Réglé</span>';
              $rowClass = '';
            } elseif ($facturesEchues > 0) {
              $statusBadge = '<span class="echeance-badge echeance-echue">Échu (' . $facturesEchues . ')</span>';
              $rowClass = 'client-row urgent';
            } elseif ($facturesBientot > 0) {
              $statusBadge = '<span class="echeance-badge echeance-bientot">Bientôt (' . $facturesBientot . ')</span>';
              $rowClass = 'client-row attention';
            } elseif ($prochaineEcheance) {
              $statusBadge = '<span class="echeance-badge echeance-normale">En cours</span>';
            } else {
              $statusBadge = '<span class="echeance-badge echeance-reglee">Sans échéance</span>';
            }

            $echeanceDisplay = $prochaineEcheance ? date('d/m/Y', strtotime($prochaineEcheance)) : '-';
            $montantClass = $due > 0 ? 'montant-du' : 'montant-ok';
            ?>
            <tr class="<?php echo $rowClass; ?>" data-customer="<?php echo htmlspecialchars($customerName); ?>" data-mobile="<?php echo $mobile; ?>" data-due="<?php echo $due; ?>" data-echeance="<?php echo $echeanceDisplay; ?>">
              <td><?php echo $cnt++; ?></td>
              <td>
                <strong><?php echo htmlspecialchars($customerName); ?></strong>
                <?php if ($facturesEchues > 0 || $facturesBientot > 0): ?>
                  <br><small class="text-muted"><?php echo $nombreFactures; ?> facture(s)</small>
                <?php endif; ?>
              </td>
              <td>
                <a href="tel:<?php echo $mobile; ?>" class="btn btn-mini btn-info">
                  <i class="icon-phone"></i> <?php echo $mobile; ?>
                </a>
              </td>
              <td>
                <span class="badge badge-info"><?php echo $nombreFactures; ?></span>
              </td>
              <td><?php echo number_format($billed, 0); ?> GNF</td>
              <td><?php echo number_format($paid, 0); ?> GNF</td>
              <td class="<?php echo $montantClass; ?>">
                <?php echo number_format($due, 0); ?> GNF
              </td>
              <td>
                <?php echo $echeanceDisplay; ?>
                <?php if ($prochaineEcheance && $due > 0): ?>
                  <?php 
                  $jours = floor((strtotime($prochaineEcheance) - time()) / (60*60*24));
                  if ($jours < 0): ?>
                    <br><small class="text-danger">En retard de <?php echo abs($jours); ?> jour(s)</small>
                  <?php elseif ($jours <= 7): ?>
                    <br><small class="text-warning">Dans <?php echo $jours; ?> jour(s)</small>
                  <?php endif; ?>
                <?php endif; ?>
              </td>
              <td><?php echo $statusBadge; ?></td>
              <td>
                <div class="btn-group">
                  <a href="client-account-details.php?name=<?php echo urlencode($customerName); ?>&mobile=<?php echo urlencode($mobile); ?>" 
                    class="btn btn-info btn-small" title="Voir détails">
                    <i class="icon-list"></i>
                  </a>
                  <?php if ($due > 0): ?>
                  <button onclick="generateResponse('<?php echo htmlspecialchars($customerName); ?>', '<?php echo $mobile; ?>', <?php echo $due; ?>, '<?php echo $echeanceDisplay; ?>')" 
                          class="btn btn-warning btn-small" title="Générer réponse">
                    <i class="icon-comment"></i>
                  </button>
                  <?php endif; ?>
                </div>
              </td>
            </tr>
            <?php
          } // end while
          ?>
          </tbody>

          <!-- Ligne de total -->
          <tfoot>
            <tr style="font-weight: bold; background-color: #f5f5f5;">
              <td colspan="4" style="text-align: right;">TOTAL GÉNÉRAL</td>
              <td><?php echo number_format($grandBilled, 0); ?> GNF</td>
              <td><?php echo number_format($grandPaid, 0); ?> GNF</td>
              <td class="<?php echo $grandDue > 0 ? 'montant-du' : 'montant-ok'; ?>">
                <?php echo number_format($grandDue, 0); ?> GNF
              </td>
              <td colspan="3"></td>
            </tr>
          </tfoot>
        </table>
      </div>
    </div>

  </div><!-- container-fluid -->
</div><!-- content -->

<!-- Modal pour réponse générée -->
<div id="responseModal" class="modal" style="display: none;">
  <div class="modal-dialog">
    <div class="modal-content" style="background: white; padding: 20px; border-radius: 5px; margin: 10% auto; width: 50%;">
      <div class="modal-header">
        <h4>Réponse pour le Client</h4>
        <button type="button" onclick="closeModal()" style="float: right; background: none; border: none; font-size: 20px;">&times;</button>
      </div>
      <div class="modal-body">
        <p><strong>Client:</strong> <span id="modalCustomerName"></span></p>
        <p><strong>Téléphone:</strong> <span id="modalCustomerPhone"></span></p>
        <hr>
        <label>Message à envoyer:</label>
        <textarea id="generatedMessage" class="span12" rows="4" style="width: 100%;"></textarea>
        <br><br>
        <button onclick="copyMessage()" class="btn btn-primary">
          <i class="icon-copy"></i> Copier le Message
        </button>
        <button onclick="sendSMS()" class="btn btn-success">
          <i class="icon-phone"></i> Envoyer SMS
        </button>
      </div>
    </div>
  </div>
</div>

<?php include_once('includes/footer.php'); ?>

<!-- scripts -->
<script src="js/jquery.min.js"></script>
<script src="js/jquery.ui.custom.js"></script>
<script src="js/bootstrap.min.js"></script>
<script src="js/jquery.uniform.js"></script>
<script src="js/select2.min.js"></script>
<script src="js/jquery.dataTables.min.js"></script>
<script src="js/matrix.js"></script>
<script src="js/matrix.tables.js"></script>

<script>
// Variables globales
let autoRefreshEnabled = false;
let refreshInterval;

// Fonction pour basculer la section réponse rapide
function toggleQuickResponse() {
  const section = document.getElementById('quickResponseSection');
  section.style.display = section.style.display === 'none' ? 'block' : 'none';
}

// Copier du texte dans le presse-papier
function copyToClipboard(text) {
  navigator.clipboard.writeText(text).then(function() {
    alert('Modèle copié dans le presse-papier !');
  });
}

// Générer une réponse personnalisée pour un client
function generateResponse(customerName, phone, dueAmount, echeanceDate) {
  document.getElementById('modalCustomerName').textContent = customerName;
  document.getElementById('modalCustomerPhone').textContent = phone;
  
  let message = '';
  if (echeanceDate !== '-') {
    const today = new Date();
    const echeance = new Date(echeanceDate.split('/').reverse().join('-'));
    const diffTime = echeance - today;
    const diffDays = Math.ceil(diffTime / (1000 * 60 * 60 * 24));
    
    if (diffDays < 0) {
      message = `Bonjour ${customerName}, votre facture est en retard de ${Math.abs(diffDays)} jour(s). Montant dû: ${new Intl.NumberFormat().format(dueAmount)} GNF. Merci de régulariser rapidement.`;
    } else if (diffDays <= 7) {
      message = `Bonjour ${customerName}, votre facture arrive à échéance dans ${diffDays} jour(s) (${echeanceDate}). Montant: ${new Intl.NumberFormat().format(dueAmount)} GNF. Merci de prévoir le règlement.`;
    } else {
      message = `Bonjour ${customerName}, votre solde actuel est de ${new Intl.NumberFormat().format(dueAmount)} GNF. Prochaine échéance: ${echeanceDate}. Merci.`;
    }
  } else {
    message = `Bonjour ${customerName}, votre solde actuel est de ${new Intl.NumberFormat().format(dueAmount)} GNF. Merci de nous contacter pour les détails d'échéance.`;
  }
  
  document.getElementById('generatedMessage').value = message;
  document.getElementById('responseModal').style.display = 'block';
}

// Fermer le modal
function closeModal() {
  document.getElementById('responseModal').style.display = 'none';
}

// Copier le message généré
function copyMessage() {
  const message = document.getElementById('generatedMessage').value;
  navigator.clipboard.writeText(message).then(function() {
    alert('Message copié ! Vous pouvez maintenant le coller dans WhatsApp ou SMS.');
  });
}

// Envoyer SMS (redirection vers l'application SMS)
function sendSMS() {
  const phone = document.getElementById('modalCustomerPhone').textContent;
  const message = document.getElementById('generatedMessage').value;
  const smsUrl = `sms:${phone}?body=${encodeURIComponent(message)}`;
  window.open(smsUrl);
}

// Actualisation automatique
function autoRefresh() {
  document.getElementById('refreshIndicator').style.display = 'block';
  
  setTimeout(function() {
    location.reload();
  }, 1000);
}

// Recherche en temps réel
document.getElementById('searchInput').addEventListener('input', function() {
  const searchTerm = this.value.toLowerCase();
  const rows = document.querySelectorAll('#clientsTable tbody tr');
  
  rows.forEach(function(row) {
    const customerName = row.getAttribute('data-customer').toLowerCase();
    const mobile = row.getAttribute('data-mobile').toLowerCase();
    
    if (customerName.includes(searchTerm) || mobile.includes(searchTerm)) {
      row.style.display = '';
    } else {
      row.style.display = 'none';
    }
  });
});

// Mise à jour automatique toutes les 30 secondes
setInterval(function() {
  document.getElementById('lastUpdate').textContent = 'Dernière mise à jour: ' + new Date().toLocaleTimeString();
}, 30000);

// Fermer le modal en cliquant à l'extérieur
window.onclick = function(event) {
  const modal = document.getElementById('responseModal');
  if (event.target == modal) {
    closeModal();
  }
}

// Notification sonore pour les échéances urgentes
document.addEventListener('DOMContentLoaded', function() {
  const urgentRows = document.querySelectorAll('.client-row.urgent');
  if (urgentRows.length > 0) {
    // Vous pouvez ajouter une notification ici
    console.log(`${urgentRows.length} client(s) avec des factures échues détecté(s)`);
  }
});
</script>

</body>
</html>