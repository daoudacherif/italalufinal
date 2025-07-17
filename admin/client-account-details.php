<?php
session_start();
error_reporting(0);
include('includes/dbconnection.php');

if (strlen($_SESSION['imsaid'] == 0)) {
  header('location:logout.php');
  exit;
}

// Récupère le nom/téléphone passés en GET
$customerName = mysqli_real_escape_string($con, $_GET['name']);
$mobile = mysqli_real_escape_string($con, $_GET['mobile']);

// Requête : toutes les factures de ce client avec informations d'échéances
$sql = "
  SELECT 
    tc.ID, 
    tc.BillingNumber, 
    tc.BillingDate, 
    tc.FinalAmount, 
    tc.Paid, 
    tc.Dues,
    tc.ModeOfPayment,
    tcc.DateEcheance,
    tcc.TypeEcheance,
    tcc.StatutEcheance,
    CASE 
      WHEN tc.Dues <= 0 THEN 'Réglé'
      WHEN tcc.DateEcheance IS NULL THEN 'Sans échéance'
      WHEN tcc.DateEcheance < CURDATE() THEN 'Échu'
      WHEN tcc.DateEcheance = CURDATE() THEN 'Échéance aujourd\'hui'
      WHEN DATEDIFF(tcc.DateEcheance, CURDATE()) <= 7 THEN 'Bientôt échu'
      ELSE 'En cours'
    END as StatutFacture,
    DATEDIFF(CURDATE(), tcc.DateEcheance) as JoursRetard
  FROM tblcustomer tc
  LEFT JOIN tblcreditcart tcc ON tcc.BillingId = tc.BillingNumber
  WHERE tc.CustomerName='$customerName' AND tc.MobileNumber='$mobile'
  ORDER BY 
    CASE 
      WHEN tc.Dues > 0 AND tcc.DateEcheance < CURDATE() THEN 1
      WHEN tc.Dues > 0 AND tcc.DateEcheance = CURDATE() THEN 2
      WHEN tc.Dues > 0 AND DATEDIFF(tcc.DateEcheance, CURDATE()) <= 7 THEN 3
      ELSE 4
    END,
    tc.BillingDate DESC
";
$res = mysqli_query($con, $sql);

// Informations client depuis la table master si disponible
$clientInfo = null;
$clientQuery = "SELECT CustomerEmail, CustomerAddress FROM tblcustomer_master WHERE CustomerContact = '$mobile' LIMIT 1";
$clientResult = mysqli_query($con, $clientQuery);
if (mysqli_num_rows($clientResult) > 0) {
  $clientInfo = mysqli_fetch_assoc($clientResult);
}
?>
<!DOCTYPE html>
<html lang="fr">
<head>
  <title>Détails du Compte Client - <?php echo htmlspecialchars($customerName); ?></title>
  <meta name="viewport" content="width=device-width, initial-scale=1.0">
  <?php include_once('includes/cs.php'); ?>
  <?php include_once('includes/responsive.php'); ?>
  <style>
    .client-header {
      background: linear-gradient(135deg, #667eea 0%, #764ba2 100%);
      color: white;
      padding: 20px;
      border-radius: 8px;
      margin-bottom: 20px;
    }
    
    .client-info {
      display: flex;
      justify-content: space-between;
      align-items: center;
      flex-wrap: wrap;
    }
    
    .client-details h2 {
      margin: 0;
      margin-bottom: 5px;
    }
    
    .client-contact {
      font-size: 14px;
      opacity: 0.9;
    }
    
    .client-actions {
      display: flex;
      gap: 10px;
      flex-wrap: wrap;
    }
    
    .summary-cards {
      display: flex;
      gap: 15px;
      margin-bottom: 20px;
      flex-wrap: wrap;
    }
    
    .summary-card {
      flex: 1;
      min-width: 150px;
      background: white;
      border-radius: 8px;
      padding: 15px;
      box-shadow: 0 2px 4px rgba(0,0,0,0.1);
      border-left: 4px solid;
      text-align: center;
    }
    
    .summary-card.total { border-left-color: #007bff; }
    .summary-card.paid { border-left-color: #28a745; }
    .summary-card.due { border-left-color: #dc3545; }
    .summary-card.echeance { border-left-color: #ffc107; }
    
    .summary-value {
      font-size: 20px;
      font-weight: bold;
      margin-bottom: 5px;
    }
    
    .summary-label {
      font-size: 11px;
      color: #666;
      text-transform: uppercase;
    }
    
    .status-badge {
      padding: 3px 8px;
      border-radius: 12px;
      font-size: 10px;
      font-weight: bold;
      text-transform: uppercase;
    }
    
    .status-regle { background: #28a745; color: white; }
    .status-echu { background: #dc3545; color: white; }
    .status-aujourd-hui { background: #ffc107; color: #212529; }
    .status-bientot { background: #17a2b8; color: white; }
    .status-en-cours { background: #6c757d; color: white; }
    .status-sans { background: #e9ecef; color: #495057; }
    
    .facture-echue {
      background-color: #fff5f5 !important;
      border-left: 3px solid #dc3545;
    }
    
    .facture-attention {
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
    
    .retard-info {
      color: #dc3545;
      font-size: 11px;
      font-weight: bold;
    }
    
    .echeance-info {
      font-size: 12px;
      color: #666;
    }
    
    .quick-actions {
      background: #f8f9fa;
      padding: 15px;
      border-radius: 5px;
      margin-bottom: 20px;
    }
    
    .payment-modal {
      display: none;
      position: fixed;
      z-index: 1000;
      left: 0;
      top: 0;
      width: 100%;
      height: 100%;
      background-color: rgba(0,0,0,0.5);
    }
    
    .payment-modal-content {
      background-color: #fefefe;
      margin: 10% auto;
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
    
    @media (max-width: 768px) {
      .client-info {
        flex-direction: column;
        text-align: center;
      }
      
      .client-actions {
        justify-content: center;
        margin-top: 15px;
      }
      
      .summary-cards {
        flex-direction: column;
      }
      
      .payment-modal-content {
        width: 90%;
        margin: 5% auto;
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
      <a href="client-account.php" class="tip-bottom">Compte Client</a>
      <a href="#" class="current">Détails Client</a>
    </div>
  </div>
  
  <div class="container-fluid">
    <!-- En-tête client -->
    <div class="client-header">
      <div class="client-info">
        <div class="client-details">
          <h2><i class="icon-user"></i> <?php echo htmlspecialchars($customerName); ?></h2>
          <div class="client-contact">
            <i class="icon-phone"></i> <?php echo htmlspecialchars($mobile); ?>
            <?php if ($clientInfo && $clientInfo['CustomerEmail']): ?>
              <br><i class="icon-envelope"></i> <?php echo htmlspecialchars($clientInfo['CustomerEmail']); ?>
            <?php endif; ?>
            <?php if ($clientInfo && $clientInfo['CustomerAddress']): ?>
              <br><i class="icon-map-marker"></i> <?php echo htmlspecialchars($clientInfo['CustomerAddress']); ?>
            <?php endif; ?>
          </div>
        </div>
        
        <div class="client-actions">
          <a href="tel:<?php echo $mobile; ?>" class="btn btn-success">
            <i class="icon-phone"></i> Appeler
          </a>
          <button onclick="generateClientMessage()" class="btn btn-info">
            <i class="icon-comment"></i> Message
          </button>
          <a href="dettecart.php" class="btn btn-warning">
            <i class="icon-plus"></i> Nouvelle Vente
          </a>
        </div>
      </div>
    </div>

    <?php
    // Calculer les totaux et statistiques
    $sumFinal = 0;
    $sumPaid = 0;
    $sumDues = 0;
    $facturesEchues = 0;
    $prochaineEcheance = null;
    $totalFactures = 0;

    // Premier passage pour calculer les totaux
    $tempRes = mysqli_query($con, $sql);
    while ($row = mysqli_fetch_assoc($tempRes)) {
      $sumFinal += floatval($row['FinalAmount']);
      $sumPaid += floatval($row['Paid']);
      $sumDues += floatval($row['Dues']);
      $totalFactures++;
      
      if ($row['StatutFacture'] === 'Échu') {
        $facturesEchues++;
      }
      
      if ($row['Dues'] > 0 && $row['DateEcheance'] && (!$prochaineEcheance || $row['DateEcheance'] < $prochaineEcheance)) {
        $prochaineEcheance = $row['DateEcheance'];
      }
    }
    ?>

    <!-- Cartes de résumé -->
    <div class="summary-cards">
      <div class="summary-card total">
        <div class="summary-value"><?php echo $totalFactures; ?></div>
        <div class="summary-label">Factures Total</div>
      </div>
      <div class="summary-card total">
        <div class="summary-value"><?php echo number_format($sumFinal, 0); ?> GNF</div>
        <div class="summary-label">Total Facturé</div>
      </div>
      <div class="summary-card paid">
        <div class="summary-value"><?php echo number_format($sumPaid, 0); ?> GNF</div>
        <div class="summary-label">Total Payé</div>
      </div>
      <div class="summary-card due">
        <div class="summary-value"><?php echo number_format($sumDues, 0); ?> GNF</div>
        <div class="summary-label">Reste à Payer</div>
      </div>
      <div class="summary-card echeance">
        <div class="summary-value">
          <?php echo $prochaineEcheance ? date('d/m/Y', strtotime($prochaineEcheance)) : 'Aucune'; ?>
        </div>
        <div class="summary-label">Prochaine Échéance</div>
      </div>
    </div>

    <!-- Actions rapides -->
    <div class="quick-actions">
      <div class="row-fluid">
        <div class="span6">
          <h5><i class="icon-cog"></i> Actions Rapides</h5>
          <?php if ($sumDues > 0): ?>
          <button onclick="openPaymentModal()" class="btn btn-success">
            <i class="icon-money"></i> Enregistrer Paiement
          </button>
          <button onclick="sendReminderSMS()" class="btn btn-warning">
            <i class="icon-bullhorn"></i> Envoyer Rappel
          </button>
          <?php endif; ?>
          <button onclick="exportClientData()" class="btn btn-info">
            <i class="icon-download"></i> Exporter Données
          </button>
        </div>
        <div class="span6">
          <h5><i class="icon-info-sign"></i> Résumé</h5>
          <p class="text-muted">
            Ce client a <strong><?php echo $totalFactures; ?> facture(s)</strong>.
            <?php if ($facturesEchues > 0): ?>
              <span class="text-danger"><strong><?php echo $facturesEchues; ?> facture(s) échue(s)</strong></span>.
            <?php endif; ?>
            <?php if ($sumDues > 0): ?>
              Solde impayé: <strong class="text-danger"><?php echo number_format($sumDues, 0); ?> GNF</strong>.
            <?php else: ?>
              <span class="text-success">Toutes les factures sont réglées</span>.
            <?php endif; ?>
          </p>
        </div>
      </div>
    </div>

    <a href="client-account.php" class="btn btn-secondary" style="margin-bottom: 15px;">
      <i class="icon-arrow-left"></i> Retour à la Liste
    </a>

    <!-- Tableau des factures avec échéances -->
    <div class="widget-box">
      <div class="widget-title">
        <span class="icon"><i class="icon-list"></i></span>
        <h5>Historique des Factures avec Échéances</h5>
      </div>
      <div class="widget-content nopadding">
        <table class="table table-bordered table-striped">
          <thead>
            <tr>
              <th>#</th>
              <th>Numéro de Facture</th>
              <th>Date Facture</th>
              <th>Échéance</th>
              <th>Montant Final</th>
              <th>Payé</th>
              <th>Reste</th>
              <th>Statut</th>
              <th>Actions</th>
            </tr>
          </thead>
          <tbody>
          <?php
          $cnt = 1;
          $res = mysqli_query($con, $sql); // Re-exécuter la requête
          while ($row = mysqli_fetch_assoc($res)) {
            $finalAmt = floatval($row['FinalAmount']);
            $paidAmt = floatval($row['Paid']);
            $dueAmt = floatval($row['Dues']);
            $dateEcheance = $row['DateEcheance'];
            $statutFacture = $row['StatutFacture'];
            $joursRetard = $row['JoursRetard'];

            // Déterminer la classe CSS de la ligne
            $rowClass = '';
            if ($statutFacture === 'Échu') {
              $rowClass = 'facture-echue';
            } elseif ($statutFacture === 'Échéance aujourd\'hui' || $statutFacture === 'Bientôt échu') {
              $rowClass = 'facture-attention';
            }

            // Badge de statut
            $statusClass = '';
            switch ($statutFacture) {
              case 'Réglé': $statusClass = 'status-regle'; break;
              case 'Échu': $statusClass = 'status-echu'; break;
              case 'Échéance aujourd\'hui': $statusClass = 'status-aujourd-hui'; break;
              case 'Bientôt échu': $statusClass = 'status-bientot'; break;
              case 'En cours': $statusClass = 'status-en-cours'; break;
              default: $statusClass = 'status-sans'; break;
            }

            $montantClass = $dueAmt > 0 ? 'montant-du' : 'montant-ok';
            ?>
            <tr class="<?php echo $rowClass; ?>">
              <td><?php echo $cnt++; ?></td>
              <td>
                <strong><?php echo $row['BillingNumber']; ?></strong>
                <br><small class="text-muted"><?php echo ucfirst($row['ModeOfPayment']); ?></small>
              </td>
              <td><?php echo date('d/m/Y H:i', strtotime($row['BillingDate'])); ?></td>
              <td>
                <?php if ($dateEcheance): ?>
                  <?php echo date('d/m/Y', strtotime($dateEcheance)); ?>
                  <?php if ($joursRetard > 0): ?>
                    <br><span class="retard-info">+<?php echo $joursRetard; ?> jour(s) de retard</span>
                  <?php elseif ($joursRetard <= 0 && $dueAmt > 0): ?>
                    <?php 
                    $joursRestants = abs($joursRetard);
                    if ($joursRestants <= 7): ?>
                      <br><span class="echeance-info">Dans <?php echo $joursRestants; ?> jour(s)</span>
                    <?php endif; ?>
                  <?php endif; ?>
                <?php else: ?>
                  <span class="text-muted">Non définie</span>
                <?php endif; ?>
              </td>
              <td><?php echo number_format($finalAmt, 0); ?> GNF</td>
              <td><?php echo number_format($paidAmt, 0); ?> GNF</td>
              <td class="<?php echo $montantClass; ?>">
                <?php echo number_format($dueAmt, 0); ?> GNF
              </td>
              <td>
                <span class="status-badge <?php echo $statusClass; ?>">
                  <?php echo $statutFacture; ?>
                </span>
              </td>
              <td>
                <div class="btn-group">
                  <?php if ($dueAmt > 0): ?>
                  <button onclick="openPaymentModalForInvoice('<?php echo $row['BillingNumber']; ?>', <?php echo $dueAmt; ?>)" 
                          class="btn btn-success btn-mini" title="Paiement">
                    <i class="icon-money"></i>
                  </button>
                  <?php endif; ?>
                  <a href="invoice_details.php?billingnum=<?php echo $row['BillingNumber']; ?>" 
                     class="btn btn-info btn-mini" title="Voir facture">
                    <i class="icon-eye-open"></i>
                  </a>
                </div>
              </td>
            </tr>
            <?php
          }
          ?>
          </tbody>
          <tfoot>
            <tr style="font-weight: bold; background-color: #f5f5f5;">
              <td colspan="4" style="text-align: right;">TOTAL</td>
              <td><?php echo number_format($sumFinal, 0); ?> GNF</td>
              <td><?php echo number_format($sumPaid, 0); ?> GNF</td>
              <td class="<?php echo $sumDues > 0 ? 'montant-du' : 'montant-ok'; ?>">
                <?php echo number_format($sumDues, 0); ?> GNF
              </td>
              <td colspan="2"></td>
            </tr>
          </tfoot>
        </table>
      </div>
    </div>

  </div><!-- container-fluid -->
</div><!-- content -->


<!-- Modal de message -->
<div id="messageModal" class="payment-modal">
  <div class="payment-modal-content">
    <span class="close" onclick="closeMessageModal()">&times;</span>
    <h3>Message pour <?php echo htmlspecialchars($customerName); ?></h3>
    <div class="control-group">
      <label>Message:</label>
      <textarea id="clientMessage" rows="4" class="span12"></textarea>
    </div>
    <div class="form-actions">
      <button onclick="copyClientMessage()" class="btn btn-primary">
        <i class="icon-copy"></i> Copier
      </button>
      <button onclick="sendClientSMS()" class="btn btn-success">
        <i class="icon-phone"></i> Envoyer SMS
      </button>
      <button onclick="closeMessageModal()" class="btn">Fermer</button>
    </div>
  </div>
</div>

<?php include_once('includes/footer.php'); ?>

<script src="js/jquery.min.js"></script>
<script src="js/bootstrap.min.js"></script>

<script>
// Variables globales
const customerName = '<?php echo htmlspecialchars($customerName); ?>';
const customerMobile = '<?php echo $mobile; ?>';
const totalDue = <?php echo $sumDues; ?>;
const nextEcheance = '<?php echo $prochaineEcheance ? date('d/m/Y', strtotime($prochaineEcheance)) : ''; ?>';

// Gestion du modal de paiement
function openPaymentModal() {
  document.getElementById('paymentInvoice').value = 'Toutes les factures';
  document.getElementById('paymentDue').value = new Intl.NumberFormat().format(totalDue) + ' GNF';
  document.getElementById('paymentAmount').value = totalDue;
  document.getElementById('paymentAmount').max = totalDue;
  document.getElementById('paymentModal').style.display = 'block';
}

function openPaymentModalForInvoice(invoiceNumber, dueAmount) {
  document.getElementById('paymentInvoice').value = invoiceNumber;
  document.getElementById('paymentDue').value = new Intl.NumberFormat().format(dueAmount) + ' GNF';
  document.getElementById('paymentAmount').value = dueAmount;
  document.getElementById('paymentAmount').max = dueAmount;
  document.getElementById('paymentModal').style.display = 'block';
}

function closePaymentModal() {
  document.getElementById('paymentModal').style.display = 'none';
}

// Générer message pour le client
function generateClientMessage() {
  let message = `Bonjour ${customerName},\n\n`;
  
  if (totalDue > 0) {
    message += `Votre solde actuel est de ${new Intl.NumberFormat().format(totalDue)} GNF.\n`;
    
    if (nextEcheance) {
      const today = new Date();
      const echeance = new Date(nextEcheance.split('/').reverse().join('-'));
      const diffTime = echeance - today;
      const diffDays = Math.ceil(diffTime / (1000 * 60 * 60 * 24));
      
      if (diffDays < 0) {
        message += `URGENT: Votre facture est en retard de ${Math.abs(diffDays)} jour(s). Merci de régulariser rapidement.\n`;
      } else if (diffDays <= 7) {
        message += `Votre prochaine échéance est dans ${diffDays} jour(s) (${nextEcheance}).\n`;
      } else {
        message += `Votre prochaine échéance est le ${nextEcheance}.\n`;
      }
    }
  } else {
    message += `Toutes vos factures sont réglées. Merci pour votre ponctualité !\n`;
  }
  
  message += `\nMerci pour votre confiance.\nITALALU`;
  
  document.getElementById('clientMessage').value = message;
  document.getElementById('messageModal').style.display = 'block';
}

function closeMessageModal() {
  document.getElementById('messageModal').style.display = 'none';
}

function copyClientMessage() {
  const message = document.getElementById('clientMessage').value;
  navigator.clipboard.writeText(message).then(function() {
    alert('Message copié dans le presse-papier !');
  });
}

function sendClientSMS() {
  const message = document.getElementById('clientMessage').value;
  const smsUrl = `sms:${customerMobile}?body=${encodeURIComponent(message)}`;
  window.open(smsUrl);
}

// Envoyer rappel SMS
function sendReminderSMS() {
  if (totalDue > 0) {
    generateClientMessage(); // Utilise la même logique de génération de message
  } else {
    alert('Ce client n\'a pas de factures impayées.');
  }
}

// Exporter les données client
function exportClientData() {
  const table = document.querySelector('.table');
  let csv = [];
  
  // En-tête
  csv.push([
    '"Facture"',
    '"Date"',
    '"Échéance"',
    '"Montant"',
    '"Payé"',
    '"Dû"',
    '"Statut"'
  ].join(','));
  
  // Données
  table.querySelectorAll('tbody tr').forEach(tr => {
    const cells = tr.querySelectorAll('td');
    if (cells.length > 1) {
      const row = [
        '"' + cells[1].textContent.trim().split('\n')[0] + '"',
        '"' + cells[2].textContent.trim() + '"',
        '"' + cells[3].textContent.trim().split('\n')[0] + '"',
        '"' + cells[4].textContent.trim() + '"',
        '"' + cells[5].textContent.trim() + '"',
        '"' + cells[6].textContent.trim() + '"',
        '"' + cells[7].textContent.trim() + '"'
      ];
      csv.push(row.join(','));
    }
  });
  
  // Téléchargement
  const csvContent = csv.join('\n');
  const blob = new Blob([csvContent], { type: 'text/csv;charset=utf-8;' });
  const link = document.createElement('a');
  
  if (link.download !== undefined) {
    const url = URL.createObjectURL(blob);
    link.setAttribute('href', url);
    link.setAttribute('download', `client_${customerName.replace(/\s+/g, '_')}_${new Date().toISOString().split('T')[0]}.csv`);
    link.style.visibility = 'hidden';
    document.body.appendChild(link);
    link.click();
    document.body.removeChild(link);
  }
}

// Gestion du formulaire de paiement
document.getElementById('paymentForm').addEventListener('submit', function(e) {
  e.preventDefault();
  
  const amount = document.getElementById('paymentAmount').value;
  const invoice = document.getElementById('paymentInvoice').value;
  
  if (confirm(`Confirmer le paiement de ${amount} GNF pour ${invoice} ?`)) {
    // Ici vous pouvez ajouter l'appel AJAX pour enregistrer le paiement
    alert('Paiement enregistré ! (Fonctionnalité à implémenter côté serveur)');
    closePaymentModal();
    location.reload();
  }
});

// Fermer les modals en cliquant à l'extérieur
window.onclick = function(event) {
  const paymentModal = document.getElementById('paymentModal');
  const messageModal = document.getElementById('messageModal');
  
  if (event.target == paymentModal) {
    closePaymentModal();
  }
  if (event.target == messageModal) {
    closeMessageModal();
  }
}

// Mise à jour automatique de la page toutes les 2 minutes
setInterval(function() {
  if (document.hidden === false) {
    location.reload();
  }
}, 120000);
</script>

</body>
</html>