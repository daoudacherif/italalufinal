# Analyse Complète de la Base de Données ITALALU

## Vue d'ensemble
Cette base de données gère un système de gestion commerciale pour une entreprise spécialisée dans la vente de produits en aluminium et accessoires. Le système inclut la gestion des produits, clients, fournisseurs, employés, ventes, paiements et inventaire.

## Tables Principales

### 1. **tbladmin** - Gestion des administrateurs/utilisateurs
```sql
CREATE TABLE `tbladmin` (
  `ID` int(10) NOT NULL,
  `AdminName` varchar(200) DEFAULT NULL,
  `UserName` varchar(200) DEFAULT NULL,
  `MobileNumber` bigint(10) DEFAULT NULL,
  `Email` varchar(200) DEFAULT NULL,
  `Password` varchar(200) DEFAULT NULL,
  `AdminRegdate` timestamp NULL DEFAULT current_timestamp(),
  `login_attempts` int(11) DEFAULT 0,
  `account_locked_until` datetime DEFAULT NULL,
  `remember_token` varchar(255) DEFAULT NULL,
  `token_expires` datetime DEFAULT NULL,
  `password_changed_at` datetime DEFAULT NULL,
  `Status` int(11) NOT NULL DEFAULT 1
)
```
**Description**: Table principale pour la gestion des utilisateurs administrateurs du système avec sécurité renforcée.

### 2. **tblproducts** - Catalogue des produits
```sql
CREATE TABLE `tblproducts` (
  `ID` int(10) NOT NULL,
  `ProductName` varchar(200) DEFAULT NULL,
  `CatID` int(5) DEFAULT NULL,
  `SubcatID` int(5) DEFAULT NULL,
  `BrandName` varchar(200) DEFAULT NULL,
  `ModelNumber` varchar(200) DEFAULT NULL,
  `Stock` int(10) DEFAULT NULL,
  `Price` decimal(10,0) DEFAULT NULL,
  `Status` int(2) DEFAULT NULL,
  `CreationDate` timestamp NULL DEFAULT current_timestamp(),
  `CostPrice` decimal(10,2) DEFAULT 0.00 COMMENT 'Prix d''achat unitaire moyen',
  `TargetMargin` decimal(5,2) DEFAULT 0.00 COMMENT 'Marge cible en pourcentage',
  `LastCostUpdate` timestamp NULL DEFAULT NULL COMMENT 'Dernière mise à jour du prix d''achat'
)
```
**Description**: Table centrale du catalogue produits avec gestion des prix, stocks et marges.

### 3. **tblcategory** - Catégories de produits
```sql
CREATE TABLE `tblcategory` (
  `ID` int(10) NOT NULL,
  `CategoryName` varchar(200) DEFAULT NULL,
  `Status` int(2) DEFAULT NULL,
  `CreationDate` timestamp NULL DEFAULT current_timestamp()
)
```
**Description**: Classification des produits (ALUMINIUM BLANCS, ALUMINIUM NATUREL, etc.).

### 4. **tblsubcategory** - Sous-catégories
```sql
CREATE TABLE `tblsubcategory` (
  `ID` int(11) NOT NULL,
  `SubCategoryName` varchar(200) NOT NULL,
  `CategoryID` int(11) NOT NULL,
  `Status` int(11) DEFAULT 1
)
```
**Description**: Sous-classification des produits par catégorie.

### 5. **tblcustomer_master** - Base clients principale
```sql
CREATE TABLE `tblcustomer_master` (
  `id` int(11) NOT NULL,
  `CustomerName` varchar(255) NOT NULL,
  `CustomerContact` varchar(20) NOT NULL COMMENT 'Phone number (Guinean format)',
  `CustomerEmail` varchar(255) DEFAULT NULL,
  `CustomerAddress` text DEFAULT NULL,
  `CustomerRegdate` timestamp NULL DEFAULT current_timestamp(),
  `Status` enum('active','inactive') DEFAULT 'active',
  `TotalPurchases` decimal(12,2) DEFAULT 0.00 COMMENT 'Total lifetime purchases',
  `TotalDues` decimal(12,2) DEFAULT 0.00 COMMENT 'Current outstanding amount',
  `CreditLimit` decimal(12,2) DEFAULT 0.00 COMMENT 'Credit limit for customer',
  `LastPurchaseDate` timestamp NULL DEFAULT NULL,
  `Notes` text DEFAULT NULL
)
```
**Description**: Table maître pour la gestion des clients avec historique et limites de crédit.

### 6. **tblcustomer** - Transactions clients
```sql
CREATE TABLE `tblcustomer` (
  `ID` int(10) NOT NULL,
  `BillingNumber` varchar(120) DEFAULT NULL,
  `CustomerName` varchar(120) DEFAULT NULL,
  `MobileNumber` varchar(15) DEFAULT NULL,
  `ModeofPayment` varchar(50) DEFAULT NULL,
  `BillingDate` timestamp NOT NULL DEFAULT current_timestamp() ON UPDATE current_timestamp(),
  `FinalAmount` int(12) NOT NULL DEFAULT 0,
  `Paid` decimal(10,2) NOT NULL DEFAULT 0.00,
  `Dues` decimal(10,2) NOT NULL DEFAULT 0.00,
  `customer_master_id` int(11) DEFAULT NULL COMMENT 'Link to customer master'
)
```
**Description**: Table des factures et transactions clients.

### 7. **tblcart** - Panier d'achat
```sql
CREATE TABLE `tblcart` (
  `ID` int(10) NOT NULL,
  `ProductId` int(5) DEFAULT NULL,
  `BillingId` int(11) DEFAULT NULL,
  `ProductQty` int(11) DEFAULT NULL,
  `Price` decimal(10,2) NOT NULL DEFAULT 0.00,
  `IsCheckOut` int(5) DEFAULT NULL,
  `CartDate` timestamp NOT NULL DEFAULT current_timestamp() ON UPDATE current_timestamp(),
  `CartType` varchar(20) DEFAULT 'regular',
  `AdminID` int(11) NOT NULL
)
```
**Description**: Gestion du panier d'achat et des lignes de facture.

### 8. **tblcreditcart** - Cartes de crédit et échéances
```sql
CREATE TABLE `tblcreditcart` (
  `ID` int(10) NOT NULL,
  `ProductId` int(5) DEFAULT NULL,
  `BillingId` int(11) DEFAULT NULL,
  `ProductQty` int(11) DEFAULT NULL,
  `Price` decimal(10,2) DEFAULT NULL,
  `IsCheckOut` int(5) DEFAULT 0,
  `CartDate` timestamp NOT NULL DEFAULT current_timestamp(),
  `AdminID` int(11) DEFAULT 1,
  `NombreJours` int(11) DEFAULT 0 COMMENT 'Nombre de jours pour l''échéance',
  `DateEcheance` date DEFAULT NULL COMMENT 'Date d''échéance calculée',
  `TypeEcheance` enum('immediat','7_jours','15_jours','30_jours','60_jours','90_jours','personnalise') DEFAULT 'immediat' COMMENT 'Type d''échéance prédéfini',
  `StatutEcheance` enum('en_cours','echu','regle','en_retard') DEFAULT 'en_cours' COMMENT 'Statut de l''échéance',
  `DateCreationEcheance` timestamp NULL DEFAULT current_timestamp() COMMENT 'Date de création de l''échéance'
)
```
**Description**: Gestion des ventes à crédit avec système d'échéances et paiements différés.

### 9. **tblemployees** - Employés
```sql
CREATE TABLE `tblemployees` (
  `ID` int(11) NOT NULL,
  `EmployeeCode` varchar(50) NOT NULL,
  `FullName` varchar(100) NOT NULL,
  `Position` varchar(100) NOT NULL,
  `Department` varchar(100) DEFAULT NULL,
  `HireDate` date NOT NULL,
  `BaseSalary` decimal(10,2) NOT NULL,
  `PaymentFrequency` enum('weekly','biweekly','monthly') DEFAULT 'monthly',
  `BankAccount` varchar(50) DEFAULT NULL,
  `Phone` varchar(20) DEFAULT NULL,
  `Email` varchar(100) DEFAULT NULL,
  `Status` enum('active','inactive','terminated') DEFAULT 'active',
  `CreatedDate` timestamp NOT NULL DEFAULT current_timestamp(),
  `UpdatedDate` timestamp NOT NULL DEFAULT current_timestamp() ON UPDATE current_timestamp()
)
```
**Description**: Gestion complète du personnel avec informations RH et salariales.

### 10. **tblpayments** - Paiements clients
```sql
CREATE TABLE `tblpayments` (
  `ID` int(11) NOT NULL,
  `CustomerID` int(11) NOT NULL,
  `BillingNumber` varchar(100) NOT NULL,
  `PaymentAmount` int(14) NOT NULL,
  `PaymentDate` datetime NOT NULL DEFAULT current_timestamp(),
  `PaymentMethod` varchar(50) DEFAULT 'Cash',
  `ReferenceNumber` varchar(100) DEFAULT NULL,
  `Comments` text DEFAULT NULL,
  `EcheanceId` int(11) DEFAULT NULL COMMENT 'ID de l''échéance payée (référence vers tblcreditcart.ID)'
)
```
**Description**: Suivi détaillé des paiements reçus des clients avec lien vers les échéances.

### 11. **tblproductarrivals** - Arrivées de produits
```sql
CREATE TABLE `tblproductarrivals` (
  `ID` int(11) NOT NULL,
  `ProductID` int(11) NOT NULL,
  `SupplierID` int(11) NOT NULL,
  `ArrivalDate` date NOT NULL,
  `Quantity` int(11) NOT NULL,
  `Cost` decimal(10,2) NOT NULL DEFAULT 0.00,
  `Paid` decimal(10,2) NOT NULL DEFAULT 0.00,
  `Dues` decimal(10,2) NOT NULL DEFAULT 0.00,
  `Comments` varchar(255) DEFAULT NULL,
  `DeliveryNote` varchar(100) DEFAULT NULL COMMENT 'Numéro du bon de livraison',
  `DeliveryDate` date DEFAULT NULL COMMENT 'Date de livraison'
)
```
**Description**: Gestion des réceptions de marchandises avec suivi des coûts et paiements fournisseurs.

### 12. **tblsupplier** - Fournisseurs
```sql
CREATE TABLE `tblsupplier` (
  `ID` int(11) NOT NULL,
  `SupplierName` varchar(200) NOT NULL,
  `Phone` varchar(50) DEFAULT NULL,
  `Email` varchar(100) DEFAULT NULL,
  `Address` text DEFAULT NULL,
  `Comments` text DEFAULT NULL
)
```
**Description**: Répertoire des fournisseurs.

### 13. **tblsupplierpayments** - Paiements fournisseurs
```sql
CREATE TABLE `tblsupplierpayments` (
  `ID` int(11) NOT NULL,
  `SupplierID` int(11) NOT NULL,
  `PaymentDate` date NOT NULL,
  `Amount` decimal(10,2) NOT NULL,
  `Comments` varchar(255) DEFAULT NULL,
  `PaymentMode` varchar(20) DEFAULT 'espece'
)
```
**Description**: Suivi des paiements aux fournisseurs.

### 14. **tblcashtransactions** - Transactions de caisse
```sql
CREATE TABLE `tblcashtransactions` (
  `ID` int(11) NOT NULL,
  `TransDate` datetime NOT NULL,
  `TransType` enum('IN','OUT') NOT NULL,
  `Amount` decimal(10,2) NOT NULL,
  `BalanceAfter` decimal(10,2) NOT NULL,
  `Comments` varchar(255) DEFAULT NULL
)
```
**Description**: Journal de caisse avec entrées/sorties et solde.

### 15. **tblreturns** - Retours produits
```sql
CREATE TABLE `tblreturns` (
  -- Structure non visible dans l'extrait
)
```
**Description**: Gestion des retours clients.

### 16. **tblsalaryadvances** - Avances sur salaire
```sql
CREATE TABLE `tblsalaryadvances` (
  -- Structure non visible dans l'extrait
)
```
**Description**: Gestion des avances accordées aux employés.

### 17. **tblsalarytransactions** - Transactions salariales
```sql
CREATE TABLE `tblsalarytransactions` (
  -- Structure non visible dans l'extrait
)
```
**Description**: Suivi des paiements de salaires.

### 18. **tblstock_alerts** - Alertes de stock
```sql
CREATE TABLE `tblstock_alerts` (
  -- Structure non visible dans l'extrait
)
```
**Description**: Système d'alertes pour les stocks faibles.

### 19. **tblstock_forecasts** - Prévisions de stock
```sql
CREATE TABLE `tblstock_forecasts` (
  -- Structure non visible dans l'extrait
)
```
**Description**: Prévisions de besoins en stock.

### 20. **tblstock_movements** - Mouvements de stock
```sql
CREATE TABLE `tblstock_movements` (
  -- Structure non visible dans l'extrait
)
```
**Description**: Traçabilité des mouvements de stock.

### 21. **tbl_daily_sales_summary** - Résumé quotidien des ventes
```sql
CREATE TABLE `tbl_daily_sales_summary` (
  `ID` int(11) NOT NULL,
  `AdminID` int(11) NOT NULL,
  `AdminName` varchar(100) NOT NULL,
  `SaleDate` date NOT NULL,
  `TotalSales` int(11) DEFAULT 0 COMMENT 'Nombre total de ventes',
  `TotalItems` int(11) DEFAULT 0 COMMENT 'Nombre total d''articles vendus',
  `TotalAmount` decimal(12,2) DEFAULT 0.00 COMMENT 'Montant total des ventes',
  `CreatedAt` timestamp NULL DEFAULT current_timestamp(),
  `UpdatedAt` timestamp NULL DEFAULT current_timestamp() ON UPDATE current_timestamp()
)
```
**Description**: Table de synthèse pour les rapports quotidiens.

### 22. **tbl_quick_sales_log** - Journal des ventes rapides
```sql
CREATE TABLE `tbl_quick_sales_log` (
  `ID` int(11) NOT NULL,
  `AdminID` int(11) NOT NULL COMMENT 'ID de l''administrateur qui fait la vente',
  `AdminName` varchar(100) NOT NULL COMMENT 'Nom de l''administrateur',
  `ProductID` int(11) NOT NULL COMMENT 'ID du produit vendu',
  `ProductName` varchar(255) NOT NULL COMMENT 'Nom du produit au moment de la vente',
  `BrandName` varchar(100) DEFAULT NULL COMMENT 'Marque du produit',
  `ModelNumber` varchar(100) DEFAULT NULL COMMENT 'Numéro de modèle',
  `Quantity` int(11) NOT NULL DEFAULT 1 COMMENT 'Quantité vendue',
  `Price` decimal(10,2) NOT NULL COMMENT 'Prix de vente appliqué',
  `BasePrice` decimal(10,2) NOT NULL COMMENT 'Prix de base du produit',
  `CustomerNote` varchar(255) DEFAULT NULL COMMENT 'Note sur le client ou la vente',
  `SaleReference` varchar(100) NOT NULL COMMENT 'Référence unique de la vente',
  `CreatedAt` timestamp NULL DEFAULT current_timestamp() COMMENT 'Date et heure de création',
  `UpdatedAt` timestamp NULL DEFAULT current_timestamp() ON UPDATE current_timestamp() COMMENT 'Dernière modification',
  `BillingNumber` varchar(50) DEFAULT NULL COMMENT 'Numéro de facture (quand finalisé)',
  `Status` enum('pending','billed','cancelled') DEFAULT 'pending' COMMENT 'Statut de la vente',
  `LineTotal` decimal(10,2) GENERATED ALWAYS AS (`Quantity` * `Price`) STORED COMMENT 'Total de la ligne (calculé automatiquement)',
  `IsFinalized` tinyint(4) DEFAULT 0 COMMENT '0 = en attente, 1 = finalisé'
)
```
**Description**: Système de ventes rapides avec suivi détaillé.

### 23. **tbl_sms_logs** - Logs SMS
```sql
CREATE TABLE `tbl_sms_logs` (
  -- Structure non visible dans l'extrait
)
```
**Description**: Journal des envois SMS (notifications clients).

## Vues de Base de Données

### 24. **vw_product_margins** - Vue des marges produits
```sql
CREATE VIEW `vw_product_margins` AS 
SELECT 
  `p`.`ID` AS `ID`,
  `p`.`ProductName` AS `ProductName`,
  `p`.`Price` AS `SalePrice`,
  `p`.`CostPrice` AS `CostPrice`,
  `p`.`TargetMargin` AS `TargetMargin`,
  `p`.`Stock` AS `Stock`,
  `p`.`LastCostUpdate` AS `LastCostUpdate`,
  `c`.`CategoryName` AS `CategoryName`,
  `b`.`BrandName` AS `BrandName`,
  CASE WHEN `p`.`CostPrice` > 0 THEN round((`p`.`Price` - `p`.`CostPrice`) / `p`.`Price` * 100,2) ELSE 0 END AS `ActualMarginPercent`,
  CASE WHEN `p`.`CostPrice` > 0 THEN round(`p`.`Price` - `p`.`CostPrice`,2) ELSE 0 END AS `ProfitPerUnit`,
  CASE WHEN `p`.`CostPrice` > 0 THEN round((`p`.`Price` - `p`.`CostPrice`) * `p`.`Stock`,2) ELSE 0 END AS `TotalStockValue`,
  CASE WHEN `p`.`CostPrice` > 0 THEN round((`p`.`Price` - `p`.`CostPrice`) / `p`.`CostPrice` * 100,2) ELSE 0 END AS `MarkupPercent`,
  CASE `RecommendedSalePrice` ELSE `p`.`Price` AS `end` END
FROM ((`tblproducts` `p` 
  left join `tblcategory` `c` on(`c`.`ID` = `p`.`CatID`)) 
  left join (select distinct `tblproducts`.`BrandName` AS `BrandName` from `tblproducts` where `tblproducts`.`BrandName` is not null) `b` on(`b`.`BrandName` = `p`.`BrandName`)) 
WHERE `p`.`Status` = 1
```
**Description**: Vue calculée pour l'analyse des marges, profits et valeurs de stock par produit.

### 25. **v_customer_overview** - Vue d'ensemble clients
```sql
CREATE VIEW `v_customer_overview` AS 
SELECT 
  `cm`.`id` AS `id`,
  `cm`.`CustomerName` AS `CustomerName`,
  `cm`.`CustomerContact` AS `CustomerContact`,
  `cm`.`CustomerEmail` AS `CustomerEmail`,
  `cm`.`CustomerAddress` AS `CustomerAddress`,
  `cm`.`CustomerRegdate` AS `CustomerRegdate`,
  `cm`.`Status` AS `Status`,
  count(`tb`.`ID`) AS `TotalInvoices`,
  coalesce(sum(`tb`.`FinalAmount`),0) AS `TotalPurchases`,
  coalesce(sum(`tb`.`Paid`),0) AS `TotalPaid`,
  coalesce(sum(`tb`.`Dues`),0) AS `TotalDues`,
  max(`tb`.`BillingDate`) AS `LastPurchaseDate`
FROM (`tblcustomer_master` `cm` 
  left join `tblcustomer` `tb` on(`tb`.`customer_master_id` = `cm`.`id`)) 
GROUP BY `cm`.`id`, `cm`.`CustomerName`, `cm`.`CustomerContact`, `cm`.`CustomerEmail`, `cm`.`CustomerAddress`, `cm`.`CustomerRegdate`, `cm`.`Status`
```
**Description**: Vue synthétique des informations clients avec totaux d'achats et dettes.

## Tables avec Structure Non Visible

Les tables suivantes existent dans la base de données mais leur structure complète n'est pas visible dans les extraits analysés :

1. **`tblreturns`** - Gestion des retours clients
2. **`tblsalaryadvances`** - Avances sur salaire
3. **`tblsalarytransactions`** - Transactions salariales
4. **`tblstock_alerts`** - Alertes de stock
5. **`tblstock_forecasts`** - Prévisions de stock
6. **`tblstock_movements`** - Mouvements de stock
7. **`tbl_sms_logs`** - Logs SMS

## Relations Principales

1. **Produits** ↔ **Catégories/Sous-catégories** : Classification hiérarchique
2. **Clients** ↔ **Transactions** : Historique des achats
3. **Produits** ↔ **Stock** : Gestion de l'inventaire
4. **Fournisseurs** ↔ **Paiements** : Suivi des obligations
5. **Employés** ↔ **Salaires** : Gestion RH
6. **Ventes** ↔ **Caisse** : Traçabilité financière
7. **Échéances** ↔ **Paiements** : Suivi du crédit client

## Fonctionnalités Clés

- **Gestion multi-utilisateurs** avec sécurité renforcée
- **Catalogue produits** avec gestion des stocks et prix
- **CRM complet** avec historique clients et crédit
- **Gestion fournisseurs** et paiements
- **Système de caisse** avec journal des transactions
- **Ventes rapides** avec suivi en temps réel
- **Rapports quotidiens** automatisés
- **Alertes de stock** et prévisions
- **Gestion RH** avec avances et salaires
- **Notifications SMS** intégrées
- **Système d'échéances** pour ventes à crédit
- **Vues calculées** pour analyses commerciales

Cette base de données constitue un système de gestion commerciale complet pour une entreprise de vente de produits en aluminium avec une forte orientation vers la traçabilité et le contrôle financier. 