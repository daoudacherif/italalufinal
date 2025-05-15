<!doctype html>
<html lang="fr">
<head>
    <!-- Meta tags de base -->
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1, shrink-to-fit=no">

    <title>Gescometstmc - Commerce general</title>
    <meta name="description"
          content="Gescometstmc est une entreprise spécialisée dans l'importation et la vente de matériaux de construction en aluminium en République de Guinée." />

    <!-- Inter UI font -->
    <link href="https://rsms.me/inter/inter-ui.css" rel="stylesheet">

    <!-- Font Awesome -->
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/5.15.4/css/all.min.css" />

    <!-- Bootstrap CSS -->
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css">

    <style>
        html { scroll-behavior: smooth; }

        body {
            font-family: 'Inter', sans-serif;
            color: #333;
            background-color: #f8f9fa;
            overflow-x: hidden;
        }

        header {
            background: linear-gradient(135deg, #005f73, #0a9396);
            padding: 1rem 0;
            position: sticky;
            top: 0;
            z-index: 1000;
            box-shadow: 0 2px 8px rgba(0,0,0,0.2);
        }

        .navbar-brand {
            color: #fff !important;
            font-weight: 700;
            transition: transform 0.3s ease;
            font-size: 1.5rem;
        }

        .navbar-brand:hover { transform: scale(1.05); }

        .nav-link { 
            color: white !important; 
            font-weight: 500;
            margin: 0 5px;
            transition: all 0.3s ease;
        }

        .nav-link:hover {
            color: #ee9b00 !important;
        }

        /* Styles pour le dropdown */
        .dropdown-menu {
            background-color: #f8f9fa;
            border: none;
            border-radius: 8px;
            box-shadow: 0 4px 10px rgba(0,0,0,0.1);
            margin-top: 8px;
        }

        .dropdown-item {
            color: #333;
            font-weight: 500;
            padding: 8px 15px;
            transition: all 2s ease;
        }

        .dropdown-item:hover, .dropdown-item:focus {
            background-color: #e9ecef;
            color: #0a9396;
        }

        .dropdown-toggle::after {
            margin-left: 5px;
            vertical-align: middle;
        }

        .hero {
    min-height: 90vh;
    background: url('https://images.unsplash.com/photo-1560748952-1d2d768c2337?ixlib=rb-4.0.3&auto=format&fit=crop&w=1920&q=80') no-repeat center center/cover;
    display: flex;
    flex-direction: column;
    justify-content: center;
    align-items: center;
    color: white;
    text-align: center;
    padding: 2rem 1rem;
}

        .hero h1 {
            font-size: 3.5rem;
            font-weight: 700;
            margin-bottom: 1.5rem;
            opacity: 0;
            animation: fadeIn 1s forwards;
        }

        .hero p {
            font-size: 1.25rem;
            max-width: 800px;
            margin-bottom: 2rem;
            opacity: 0;
            animation: fadeIn 1s forwards;
        }

        .hero h1 { animation-delay: 0.3s; }
        .hero p { animation-delay: 0.6s; }
        .hero button { animation-delay: 0.9s; }

        @keyframes fadeIn {
            from { opacity: 0; transform: translateY(20px); }
            to { opacity: 1; transform: translateY(0); }
        }

        .btn-hero {
            background-color: #ee9b00;
            color: #fff;
            padding: 12px 30px;
            border-radius: 30px;
            font-weight: 600;
            border: none;
            transition: all 0.3s ease;
            opacity: 0;
            animation: fadeIn 1s forwards;
            animation-delay: 0.9s;
        }

        .btn-hero:hover { 
            background-color: #ca6702; 
            transform: translateY(-3px);
            box-shadow: 0 4px 15px rgba(0,0,0,0.15);
        }

        /* Bouton de navigation */
        .btn-nav {
            padding: 8px 20px;
            border-radius: 50px;
            cursor: pointer;
            border: 1px solid white;
            background-color: transparent;
            color: white;
            letter-spacing: 1px;
            font-size: 14px;
            font-weight: 500;
            transition: all 0.5s ease;
        }

        .btn-nav:hover {
            background-color: white;
            color: #0a9396;
            box-shadow: 0 4px 10px rgba(255, 255, 255, 0.2);
        }

        /* Services section */
        .services {
            padding: 5rem 0;
            background-color: #fff;
        }

        .section-title {
            text-align: center;
            margin-bottom: 3rem;
            color: #005f73;
        }

        .service-card {
            padding: 2rem;
            border-radius: 10px;
            box-shadow: 0 5px 15px rgba(0,0,0,0.05);
            transition: all 0.3s ease;
            height: 100%;
            background-color: white;
        }

        .service-card:hover {
            transform: translateY(-10px);
            box-shadow: 0 10px 25px rgba(0,0,0,0.1);
        }

        .service-icon {
            font-size: 2.5rem;
            margin-bottom: 1.5rem;
            color: #0a9396;
        }

        /* Footer */
        footer {
            background-color: #005f73;
            color: white;
            padding: 3rem 0 1.5rem 0;
        }

        .footer-heading {
            font-size: 1.2rem;
            font-weight: 600;
            margin-bottom: 1.5rem;
        }

        .footer-link {
            color: rgba(255,255,255,0.8);
            text-decoration: none;
            display: block;
            margin-bottom: 0.5rem;
            transition: all 0.3s ease;
        }

        .footer-link:hover {
            color: white;
            transform: translateX(5px);
        }

        .footer-contact {
            display: flex;
            align-items: center;
            margin-bottom: 1rem;
        }

        .footer-contact i {
            margin-right: 10px;
            color: #ee9b00;
        }

        .footer-bottom {
            border-top: 1px solid rgba(255,255,255,0.1);
            padding-top: 1.5rem;
            margin-top: 3rem;
            text-align: center;
            font-size: 0.9rem;
            color: rgba(255,255,255,0.7);
        }

        /* Responsive adjustments */
        @media (max-width: 992px) {
            .hero h1 {
                font-size: 2.5rem;
            }

            .hero p {
                font-size: 1rem;
            }
        }

        @media (max-width: 768px) {
            .service-card {
                margin-bottom: 1.5rem;
            }
        }
    </style>
</head>

<body>

<!-- Header -->
<header>
    <nav class="navbar navbar-expand-lg navbar-dark">
        <div class="container">
            <a class="navbar-brand" href="#">GESCOMETSTMC</a>
            <button class="navbar-toggler" type="button" data-bs-toggle="collapse" data-bs-target="#navbarNav" aria-controls="navbarNav" aria-expanded="false" aria-label="Toggle navigation">
                <span class="navbar-toggler-icon"></span>
            </button>
            <div class="collapse navbar-collapse" id="navbarNav">
                <ul class="navbar-nav ms-auto">
                    <li class="nav-item">
                        <a class="nav-link" href="#services">Service</a>
                    </li>
                    <li class="nav-item">
                        <a class="nav-link" href="#contact">Contact</a>
                    </li>
                    <li class="nav-item dropdown">
                        <a class="nav-link dropdown-toggle" href="#" role="button" data-bs-toggle="dropdown" aria-expanded="false">
                            Magasins
                        </a>
                        <ul class="dropdown-menu">
                            <li><a class="dropdown-item" href="https://second.etstmc.com/admin/login.php">Bailobaya</a></li>
                            <li><hr class="dropdown-divider"></li>
                            <li><a class="dropdown-item" href="#">Autre magasin</a></li>
                        </ul>
                    </li>
                    <li class="nav-item ms-lg-3">
                        <a class="btn-nav" href="admin/login.php">Connexion</a>
                    </li>
                </ul>
            </div>
        </div>
    </nav>
</header>

<!-- Hero Section -->
<section class="hero">
    <div class="container text-center">
        <h1>Votre Partenaire en Commerce Général</h1>
        <p>GESCOMETSTMC, leader dans l'importation et la distribution de produits de qualité. Nous vous offrons une large gamme d'articles et équipements professionnels avec un service client exceptionnel.</p>
        <button class="btn btn-hero">Découvrir nos offres</button>
    </div>
</section>

<!-- Services Section -->
<section class="services" id="services">
    <div class="container">
        <h2 class="section-title">Nos Services</h2>
        <div class="row">
            <div class="col-md-4 mb-4">
                <div class="service-card">
                    <div class="service-icon">
                        <i class="fas fa-truck"></i>
                    </div>
                    <h3>Importation</h3>
                    <p>Nous importons une large gamme de produits de qualité pour répondre à vos besoins.</p>
                </div>
            </div>
            <div class="col-md-4 mb-4">
                <div class="service-card">
                    <div class="service-icon">
                        <i class="fas fa-store"></i>
                    </div>
                    <h3>Vente en Gros</h3>
                    <p>Profitez de nos tarifs compétitifs pour vos achats en gros et demi-gros.</p>
                </div>
            </div>
            <div class="col-md-4 mb-4">
                <div class="service-card">
                    <div class="service-icon">
                        <i class="fas fa-tools"></i>
                    </div>
                    <h3>Équipements Professionnels</h3>
                    <p>Des équipements de qualité pour tous vos besoins professionnels et industriels.</p>
                </div>
            </div>
        </div>
    </div>
</section>

<!-- Contact Section -->
<section class="py-5 bg-light" id="contact">
    <div class="container">
        <h2 class="section-title">Contactez-nous</h2>
        <div class="row">
            <div class="col-lg-6 mx-auto">
                <div class="bg-white p-4 rounded shadow">
                    <form>
                        <div class="mb-3">
                            <label for="name" class="form-label">Nom</label>
                            <input type="text" class="form-control" id="name" placeholder="Votre nom">
                        </div>
                        <div class="mb-3">
                            <label for="email" class="form-label">Email</label>
                            <input type="email" class="form-control" id="email" placeholder="Votre email">
                        </div>
                        <div class="mb-3">
                            <label for="message" class="form-label">Message</label>
                            <textarea class="form-control" id="message" rows="4" placeholder="Votre message"></textarea>
                        </div>
                        <button type="submit" class="btn btn-hero w-100">Envoyer</button>
                    </form>
                </div>
            </div>
        </div>
    </div>
</section>

<!-- Footer -->
<footer>
    <div class="container">
        <div class="row">
            <div class="col-md-4 mb-4">
                <h4 class="footer-heading">À propos de GESCOMETSTMC</h4>
                <p>Leader dans l'importation et la distribution de produits de qualité en République de Guinée depuis plusieurs années.</p>
            </div>
            <div class="col-md-3 mb-4">
                <h4 class="footer-heading">Liens Rapides</h4>
                <a href="#" class="footer-link">Accueil</a>
                <a href="#services" class="footer-link">Services</a>
                <a href="#contact" class="footer-link">Contact</a>
                <a href="admin/login.php" class="footer-link">Connexion</a>
            </div>
            <div class="col-md-5 mb-4">
                <h4 class="footer-heading">Coordonnées</h4>
                <div class="footer-contact">
                    <i class="fas fa-map-marker-alt"></i>
                    <span>Conakry, République de Guinée</span>
                </div>
                <div class="footer-contact">
                    <i class="fas fa-phone-alt"></i>
                    <span>+224 00 00 00 00</span>
                </div>
                <div class="footer-contact">
                    <i class="fas fa-envelope"></i>
                    <span>contact@etstmc.com</span>
                </div>
            </div>
        </div>
        <div class="footer-bottom">
            <p>&copy; 2025 GESCOMETSTMC. Tous droits réservés.</p>
        </div>
    </div>
</footer>

<!-- Include Bootstrap Bundle JS -->
<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/js/bootstrap.bundle.min.js"></script>
<script>
    // Assurer que les dropdowns fonctionnent correctement
    document.addEventListener('DOMContentLoaded', function() {
        var dropdownElementList = [].slice.call(document.querySelectorAll('.dropdown-toggle'));
        dropdownElementList.map(function(dropdownToggleEl) {
            return new bootstrap.Dropdown(dropdownToggleEl);
        });

        // Fermer le menu mobile après clic sur un lien
        var navLinks = document.querySelectorAll('.navbar-nav .nav-link');
        var navbarCollapse = document.querySelector('.navbar-collapse');
        navLinks.forEach(function(link) {
            link.addEventListener('click', function() {
                if (window.innerWidth < 992) {
                    bootstrap.Collapse.getInstance(navbarCollapse).hide();
                }
            });
        });
    });
</script>
</body>
</html>