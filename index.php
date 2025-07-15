<!DOCTYPE html>
<html lang="fr">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>ITALALU - Importateur de Matériaux Aluminium</title>
    <base target="_self">
    <script src="https://cdn.tailwindcss.com"></script>
    <script src="https://cdn.jsdelivr.net/npm/@preline/preline@2.0.0/dist/preline.min.js"></script>
    <script>
        tailwind.config = {
            theme: {
                extend: {
                    colors: {
                        'primary': '#ff7d00',
                        'primary-dark': '#e56f00',
                        'secondary': '#15616d',
                        'dark': '#001524',
                        'light': '#ffecd1',
                        'accent': '#78290f',
                    }
                }
            }
        }
    </script>
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
    <style>
        .hero-bg {
            background: linear-gradient(135deg, rgba(255, 125, 0, 0.1) 0%, rgba(21, 97, 109, 0.1) 100%);
        }
        .btn-primary {
            background-color: #ff7d00;
            transition: all 0.3s ease;
        }
        .btn-primary:hover {
            background-color: #e56f00;
            transform: translateY(-2px);
            box-shadow: 0 10px 20px rgba(255, 125, 0, 0.3);
        }
        .dropdown:hover .dropdown-menu {
            display: block;
        }
        .dropdown-menu {
            transform: scale(0.95);
            opacity: 0;
            transition: all 0.1s ease-out;
            pointer-events: none;
        }
        .dropdown-menu.show {
            transform: scale(1);
            opacity: 1;
            pointer-events: auto;
        }
        
        /* Mobile dropdown animations */
        #mobile-shops-menu {
            max-height: 0;
            overflow: hidden;
            transition: max-height 0.3s ease-out;
        }
        
        #mobile-shops-menu:not(.hidden) {
            max-height: 200px;
        }
        
        #mobile-shops-toggle i {
            transition: transform 0.3s ease;
        }
        
        /* Custom aluminum-themed animations */
        .aluminum-shine {
            background: linear-gradient(45deg, transparent 30%, rgba(255, 255, 255, 0.5) 50%, transparent 70%);
            background-size: 200% 200%;
            animation: shine 3s ease-in-out infinite;
        }
        
        @keyframes shine {
            0% { background-position: 200% 200%; }
            100% { background-position: -200% -200%; }
        }
    </style>
</head>
<body class="font-sans bg-light">
    <!-- Header -->
    <header class="bg-dark shadow-lg sticky top-0 z-50">
        <div class="container mx-auto px-4 py-4 flex justify-between items-center">
            <div class="flex items-center">
                <div class="w-12 h-12 rounded-full bg-primary flex items-center justify-center text-white font-bold text-xl mr-3 aluminum-shine">
                    <span class="relative z-10">GNS</span>
                </div>
                <h1 class="text-2xl font-bold text-light">GNS GROUPES</h1>
            </div>
            <div class="hidden md:flex space-x-6 items-center">
                <a href="#accueil" class="text-light hover:text-primary transition">Accueil</a>
                
                <div class="relative inline-block text-left">
                    <div>
                        <button type="button" class="inline-flex w-full justify-center gap-x-1.5 rounded-md bg-secondary px-3 py-2 text-sm font-semibold text-light shadow-xs ring-1 ring-secondary ring-inset hover:bg-secondary/80 transition-colors" id="menu-button" aria-expanded="false" aria-haspopup="true">
                            Magazins
                            <svg class="-mr-1 size-5 text-light transition-transform" viewBox="0 0 20 20" fill="currentColor" aria-hidden="true" data-slot="icon" id="dropdown-arrow">
                                <path fill-rule="evenodd" d="M5.22 8.22a.75.75 0 0 1 1.06 0L10 11.94l3.72-3.72a.75.75 0 1 1 1.06 1.06l-4.25 4.25a.75.75 0 0 1-1.06 0L5.22 9.28a.75.75 0 0 1 0-1.06Z" clip-rule="evenodd" />
                            </svg>
                        </button>
                    </div>

                    <div class="dropdown-menu absolute right-0 z-10 mt-2 w-56 origin-top-right rounded-md bg-light shadow-lg ring-1 ring-black/5 focus:outline-hidden" role="menu" aria-orientation="vertical" aria-labelledby="menu-button" tabindex="-1" id="dropdown-menu">
                        <div class="py-1" role="none">
                            
                            <a href="https://second.italalu.com/admin/login.php" class="block px-4 py-2 text-sm text-dark hover:bg-primary/10 hover:text-primary transition-colors" role="menuitem" tabindex="-1">Kountia</a>
                        </div>
                    </div>
                </div>
                
                <a href="#produits" class="text-light hover:text-primary transition">Produits</a>
                <a href="#services" class="text-light hover:text-primary transition">Services</a>
                <a href="#contact" class="text-light hover:text-primary transition">Contact</a>
            </div>
            <div class="flex items-center space-x-4">
                <a href="admin/login.php" class="hidden md:block text-light hover:text-primary transition" id="login-btn">
                    <i class="fas fa-user mr-1"></i>
                    Connexion
                </a>
                <a href="tel:+224610 04 66 12" class="bg-primary text-white px-4 py-2 rounded-lg flex items-center hover:bg-primary-dark transition">
                    <i class="fas fa-phone mr-2"></i>
                    <span>610 04 66 12</span>
                </a>
                <button class="md:hidden text-light" id="menu-toggle">
                    <i class="fas fa-bars text-2xl"></i>
                </button>
            </div>
        </div>
    </header>

    <!-- Mobile Menu -->
    <div class="hidden bg-dark shadow-lg" id="mobile-menu">
        <div class="container mx-auto px-4 py-4 flex flex-col space-y-4">
            <a href="#accueil" class="text-light hover:text-primary transition py-2">Accueil</a>
            
            <!-- Mobile Dropdown Showrooms -->
            <div class="relative">
                <button class="flex items-center justify-between w-full text-light hover:text-primary transition py-2" id="mobile-shops-toggle">
                    <span>Showrooms</span>
                    <i class="fas fa-chevron-down text-xs"></i>
                </button>
                <div class="hidden pl-4 mt-2 space-y-2" id="mobile-shops-menu">
                    <a href="https://second.italalu.com/admin/login.php" class="block text-light hover:text-primary transition py-1">KOUNTIA</a>
                </div>
            </div>

            <a href="#produits" class="text-light hover:text-primary transition py-2">Produits</a>
            <a href="#services" class="text-light hover:text-primary transition py-2">Services</a>
            <a href="#contact" class="text-light hover:text-primary transition py-2">Contact</a>
            <a href="admin/login.php" class="text-light hover:text-primary transition py-2">Connexion</a>
        </div>
    </div>

    <!-- Hero Section -->
    <section id="accueil" class="hero-bg py-16 md:py-24">
        <div class="container mx-auto px-4">
            <div class="flex flex-col md:flex-row items-center">
                <div class="md:w-1/2 mb-12 md:mb-0">
                    <h1 class="text-4xl md:text-5xl font-bold text-dark mb-6">Leader de l'importation de matériaux aluminium en Guinée</h1>
                    <p class="text-lg text-secondary mb-8">ITALALU vous propose une gamme complète de matériaux de construction en aluminium de haute qualité, importés directement d'Italie pour vos projets les plus ambitieux.</p>
                    <div class="flex flex-wrap gap-4">
                        <a href="#produits" class="btn-primary text-white px-8 py-3 rounded-lg font-medium shadow-lg">Découvrir nos produits</a>
                        <a href="#contact" class="border-2 border-primary text-primary px-8 py-3 rounded-lg font-medium hover:bg-primary hover:text-white transition">Demander un devis</a>
                    </div>
                </div>
                <div class="md:w-1/2">
                    <img src="https://cdn.pixabay.com/photo/2016/02/19/16/29/construction-1210677_1280.jpg" 
                         alt="Structures en aluminium moderne" 
                         class="rounded-lg shadow-xl w-full">
                </div>
            </div>
        </div>
    </section>

  <!-- Produits Section -->
<section id="produits" class="py-16 bg-white">
    <div class="container mx-auto px-4">
        <div class="text-center mb-12">
            <h2 class="text-3xl font-bold text-dark mb-4">Nos Produits Aluminium</h2>
            <p class="text-secondary max-w-2xl mx-auto">Découvrez notre gamme complète de matériaux en aluminium importés d'Italie</p>
        </div>
        
        <div class="grid grid-cols-1 md:grid-cols-2 lg:grid-cols-3 gap-8">
            <!-- Produit 1 -->
            <div class="bg-light rounded-lg overflow-hidden shadow-md hover:shadow-xl transition-shadow">
                <div class="h-48 bg-gradient-to-br from-secondary to-primary flex items-center justify-center aluminum-shine">
                    <i class="fas fa-window-maximize text-white text-6xl relative z-10"></i>
                </div>
                <div class="p-6">
                    <h3 class="text-xl font-bold mb-2 text-dark">Fenêtres Aluminium</h3>
                    <p class="text-secondary mb-4">Fenêtres coulissantes et battantes en aluminium anodisé, isolation thermique optimale.</p>
                    <a href="#contact" class="text-primary font-medium hover:underline">Commander →</a>
                </div>
            </div>
            
            <!-- Produit 2 -->
            <div class="bg-light rounded-lg overflow-hidden shadow-md hover:shadow-xl transition-shadow">
                <div class="h-48 bg-gradient-to-br from-secondary to-primary flex items-center justify-center aluminum-shine">
                    <i class="fas fa-door-open text-white text-6xl relative z-10"></i>
                </div>
                <div class="p-6">
                    <h3 class="text-xl font-bold mb-2 text-dark">Portes Aluminium</h3>
                    <p class="text-secondary mb-4">Portes d'entrée et intérieures en aluminium, design moderne et sécurisé.</p>
                    <a href="#contact" class="text-primary font-medium hover:underline">Commander →</a>
                </div>
            </div>
            
            <!-- Produit 3 -->
            <div class="bg-light rounded-lg overflow-hidden shadow-md hover:shadow-xl transition-shadow">
                <div class="h-48 bg-gradient-to-br from-secondary to-primary flex items-center justify-center aluminum-shine">
                    <i class="fas fa-bars text-white text-6xl relative z-10"></i>
                </div>
                <div class="p-6">
                    <h3 class="text-xl font-bold mb-2 text-dark">Profilés Aluminium</h3>
                    <p class="text-secondary mb-4">Large gamme de profilés aluminium pour toutes vos constructions.</p>
                    <a href="#contact" class="text-primary font-medium hover:underline">Commander →</a>
                </div>
            </div>

            <!-- Produit 4 -->
            <div class="bg-light rounded-lg overflow-hidden shadow-md hover:shadow-xl transition-shadow">
                <div class="h-48 bg-gradient-to-br from-secondary to-primary flex items-center justify-center aluminum-shine">
                    <i class="fas fa-solar-panel text-white text-6xl relative z-10"></i>
                </div>
                <div class="p-6">
                    <h3 class="text-xl font-bold mb-2 text-dark">Façades Ventilées</h3>
                    <p class="text-secondary mb-4">Systèmes de façades ventilées en aluminium composite pour bâtiments modernes.</p>
                    <a href="#contact" class="text-primary font-medium hover:underline">Commander →</a>
                </div>
            </div>

            <!-- Produit 5 -->
            <div class="bg-light rounded-lg overflow-hidden shadow-md hover:shadow-xl transition-shadow">
                <div class="h-48 bg-gradient-to-br from-secondary to-primary flex items-center justify-center aluminum-shine">
                    <i class="fas fa-border-all text-white text-6xl relative z-10"></i>
                </div>
                <div class="p-6">
                    <h3 class="text-xl font-bold mb-2 text-dark">Grilles et Garde-Corps</h3>
                    <p class="text-secondary mb-4">Grilles de sécurité et garde-corps en aluminium, design personnalisable.</p>
                    <a href="#contact" class="text-primary font-medium hover:underline">Commander →</a>
                </div>
            </div>

            <!-- Produit 6 -->
            <div class="bg-light rounded-lg overflow-hidden shadow-md hover:shadow-xl transition-shadow">
                <div class="h-48 bg-gradient-to-br from-secondary to-primary flex items-center justify-center aluminum-shine">
                    <i class="fas fa-home text-white text-6xl relative z-10"></i>
                </div>
                <div class="p-6">
                    <h3 class="text-xl font-bold mb-2 text-dark">Vérandas & Pergolas</h3>
                    <p class="text-secondary mb-4">Structures aluminium pour vérandas et pergolas bioclimatiques.</p>
                    <a href="#contact" class="text-primary font-medium hover:underline">Commander →</a>
                </div>
            </div>
        </div>
    </div>
</section>

    <!-- Services Section -->
    <section id="services" class="py-16 bg-secondary text-light">
        <div class="container mx-auto px-4">
            <div class="text-center mb-12">
                <h2 class="text-3xl font-bold mb-4">Nos Services Premium</h2>
                <p class="max-w-2xl mx-auto">Un accompagnement complet pour vos projets aluminium</p>
            </div>
            
            <div class="grid grid-cols-1 md:grid-cols-3 gap-8">
                <div class="bg-dark/50 backdrop-blur p-8 rounded-lg shadow-md text-center hover:bg-dark/70 transition">
                    <div class="w-16 h-16 bg-primary rounded-full flex items-center justify-center mx-auto mb-4">
                        <i class="fas fa-ship text-white text-2xl"></i>
                    </div>
                    <h3 class="text-xl font-bold mb-3">Import Direct</h3>
                    <p>Importation directe depuis l'Italie garantissant qualité et meilleurs prix du marché.</p>
                </div>
                
                <div class="bg-dark/50 backdrop-blur p-8 rounded-lg shadow-md text-center hover:bg-dark/70 transition">
                    <div class="w-16 h-16 bg-primary rounded-full flex items-center justify-center mx-auto mb-4">
                        <i class="fas fa-pencil-ruler text-white text-2xl"></i>
                    </div>
                    <h3 class="text-xl font-bold mb-3">Design sur Mesure</h3>
                    <p>Conception personnalisée selon vos plans avec notre équipe technique italienne.</p>
                </div>
                
                <div class="bg-dark/50 backdrop-blur p-8 rounded-lg shadow-md text-center hover:bg-dark/70 transition">
                    <div class="w-16 h-16 bg-primary rounded-full flex items-center justify-center mx-auto mb-4">
                        <i class="fas fa-tools text-white text-2xl"></i>
                    </div>
                    <h3 class="text-xl font-bold mb-3">Installation Pro</h3>
                    <p>Équipe d'installateurs certifiés pour une pose parfaite de vos menuiseries aluminium.</p>
                </div>
            </div>
        </div>
    </section>

    <!-- Why Choose Us Section -->
    <section class="py-16 bg-light">
        <div class="container mx-auto px-4">
            <div class="text-center mb-12">
                <h2 class="text-3xl font-bold text-dark mb-4">Pourquoi Choisir ITALALU?</h2>
            </div>
            
            <div class="grid grid-cols-1 md:grid-cols-2 gap-12 items-center">
                <div>
                    <div class="space-y-6">
                        <div class="flex items-start">
                            <div class="w-12 h-12 bg-primary/20 rounded-full flex items-center justify-center flex-shrink-0 mr-4">
                                <i class="fas fa-certificate text-primary"></i>
                            </div>
                            <div>
                                <h3 class="font-bold text-dark mb-2">Qualité Italienne Certifiée</h3>
                                <p class="text-secondary">Tous nos produits respectent les normes européennes les plus strictes.</p>
                            </div>
                        </div>
                        
                        <div class="flex items-start">
                            <div class="w-12 h-12 bg-primary/20 rounded-full flex items-center justify-center flex-shrink-0 mr-4">
                                <i class="fas fa-clock text-primary"></i>
                            </div>
                            <div>
                                <h3 class="font-bold text-dark mb-2">Livraison Rapide</h3>
                                <p class="text-secondary">Stock permanent et livraison en 48h sur Conakry et environs.</p>
                            </div>
                        </div>
                        
                        <div class="flex items-start">
                            <div class="w-12 h-12 bg-primary/20 rounded-full flex items-center justify-center flex-shrink-0 mr-4">
                                <i class="fas fa-shield-alt text-primary"></i>
                            </div>
                            <div>
                                <h3 class="font-bold text-dark mb-2">Garantie 10 Ans</h3>
                                <p class="text-secondary">Une garantie décennale sur tous nos produits aluminium.</p>
                            </div>
                        </div>
                    </div>
                </div>
                
                <div>
                    <img src="https://images.unsplash.com/photo-1541888946425-d81bb19240f5?w=600" 
                         alt="Construction moderne en aluminium" 
                         class="rounded-lg shadow-xl w-full">
                </div>
            </div>
        </div>
    </section>

    <!-- Contact Section -->
    <section id="contact" class="py-16 bg-accent text-light">
        <div class="container mx-auto px-4">
            <div class="text-center mb-12">
                <h2 class="text-3xl font-bold mb-4">Contactez-nous</h2>
                <p class="max-w-2xl mx-auto">Obtenez votre devis personnalisé pour vos projets aluminium</p>
            </div>
            
            <div class="grid grid-cols-1 md:grid-cols-2 gap-12">
                <div>
                    <h3 class="text-xl font-bold mb-4">Nos Coordonnées</h3>
                    <div class="space-y-4">
                        <div class="flex items-start">
                            <i class="fas fa-map-marker-alt mr-3 mt-1 text-primary"></i>
                            <div>
                                <p class="font-medium">Siège Social</p>
                                <p>sonfinia, Kountia</p>
                            </div>
                        </div>
                        <div class="flex items-start">
                            <i class="fas fa-phone mr-3 mt-1 text-primary"></i>
                            <div>
                                <p class="font-medium">Téléphone</p>
                                <p>+224 610 04 66 12</p>
                            </div>
                        </div>
                        <div class="flex items-start">
                            <i class="fas fa-envelope mr-3 mt-1 text-primary"></i>
                            <div>
                                <p class="font-medium">Email</p>
                                <p>contact@italalu.gn</p>
                            </div>
                        </div>
                        <div class="flex items-start">
                            <i class="fas fa-clock mr-3 mt-1 text-primary"></i>
                            <div>
                                <p class="font-medium">Horaires</p>
                                <p>Lundi - Vendredi: 8h - 18h</p>
                                <p>Samedi: 9h - 16h</p>
                            </div>
                        </div>
                    </div>
                </div>
                
                <div>
                    <form class="space-y-4">
                        <div>
                            <label for="name" class="block mb-1">Nom complet</label>
                            <input type="text" id="name" class="w-full px-4 py-2 rounded-lg bg-white/10 border border-white/30 focus:outline-none focus:ring-2 focus:ring-primary text-light placeholder-light/60" placeholder="Votre nom">
                        </div>
                        <div>
                            <label for="phone" class="block mb-1">Téléphone</label>
                            <input type="tel" id="phone" class="w-full px-4 py-2 rounded-lg bg-white/10 border border-white/30 focus:outline-none focus:ring-2 focus:ring-primary text-light placeholder-light/60" placeholder="Votre numéro">
                        </div>
                        <div>
                            <label for="project" class="block mb-1">Type de projet</label>
                            <select id="project" class="w-full px-4 py-2 rounded-lg bg-white/10 border border-white/30 focus:outline-none focus:ring-2 focus:ring-primary text-light">
                                <option value="">Sélectionnez un type</option>
                                <option value="residential">Résidentiel</option>
                                <option value="commercial">Commercial</option>
                                <option value="industrial">Industriel</option>
                            </select>
                        </div>
                        <div>
                            <label for="message" class="block mb-1">Message</label>
                            <textarea id="message" rows="4" class="w-full px-4 py-2 rounded-lg bg-white/10 border border-white/30 focus:outline-none focus:ring-2 focus:ring-primary text-light placeholder-light/60" placeholder="Décrivez votre projet"></textarea>
                        </div>
                        <button type="submit" class="bg-primary text-white px-6 py-3 rounded-lg font-bold hover:bg-primary-dark transition w-full md:w-auto">Envoyer la demande</button>
                    </form>
                </div>
            </div>
        </div>
    </section>

    <!-- Footer -->
    <footer class="bg-dark text-light py-12">
        <div class="container mx-auto px-4">
            <div class="grid grid-cols-1 md:grid-cols-4 gap-8">
                <div>
                    <div class="flex items-center mb-4">
                        <div class="w-10 h-10 rounded-full bg-primary flex items-center justify-center text-white font-bold text-lg mr-3 aluminum-shine">
                            <span class="relative z-10">IA</span>
                        </div>
                        <h3 class="text-xl font-bold">ITALALU</h3>
                    </div>
                    <p class="text-light/70">Leader de l'importation de matériaux aluminium en Guinée. Qualité italienne, service guinéen.</p>
                </div>
                
                <div>
                    <h3 class="text-lg font-bold mb-4">Navigation</h3>
                    <ul class="space-y-2">
                        <li><a href="#accueil" class="text-light/70 hover:text-primary transition">Accueil</a></li>
                        <li><a href="#produits" class="text-light/70 hover:text-primary transition">Produits</a></li>
                        <li><a href="#services" class="text-light/70 hover:text-primary transition">Services</a></li>
                        <li><a href="#contact" class="text-light/70 hover:text-primary transition">Contact</a></li>
                    </ul>
                </div>
                
                <div>
                    <h3 class="text-lg font-bold mb-4">Produits</h3>
                    <ul class="space-y-2">
                        <li><a href="#" class="text-light/70 hover:text-primary transition">Fenêtres</a></li>
                        <li><a href="#" class="text-light/70 hover:text-primary transition">Portes</a></li>
                        <li><a href="#" class="text-light/70 hover:text-primary transition">Façades</a></li>
                        <li><a href="#" class="text-light/70 hover:text-primary transition">Vérandas</a></li>
                    </ul>
                </div>
                
                <div>
                    <h3 class="text-lg font-bold mb-4">Suivez-nous</h3>
                    <div class="flex space-x-4">
                        <a href="#" class="w-10 h-10 bg-primary/20 rounded-full flex items-center justify-center hover:bg-primary transition">
                            <i class="fab fa-facebook-f"></i>
                        </a>
                        <a href="#" class="w-10 h-10 bg-primary/20 rounded-full flex items-center justify-center hover:bg-primary transition">
                            <i class="fab fa-instagram"></i>
                        </a>
                        <a href="#" class="w-10 h-10 bg-primary/20 rounded-full flex items-center justify-center hover:bg-primary transition">
                            <i class="fab fa-linkedin-in"></i>
                        </a>
                    </div>
                </div>
            </div>
            
            <div class="border-t border-light/20 mt-12 pt-8 text-center text-light/60">
                <p>&copy; 2024 ITALALU. Tous droits réservés. | Importateur exclusif de matériaux aluminium italiens en Guinée</p>
            </div>
        </div>
    </footer>

    <script>
        // Dropdown functionality
        const dropdownButton = document.getElementById('menu-button');
        const dropdownMenu = document.getElementById('dropdown-menu');
        const dropdownArrow = document.getElementById('dropdown-arrow');

        function toggleDropdown() {
            const isOpen = dropdownMenu.classList.contains('show');
            
            if (isOpen) {
                closeDropdown();
            } else {
                openDropdown();
            }
        }

        function openDropdown() {
            dropdownMenu.classList.add('show');
            dropdownButton.setAttribute('aria-expanded', 'true');
            dropdownArrow.style.transform = 'rotate(180deg)';
        }

        function closeDropdown() {
            dropdownMenu.classList.remove('show');
            dropdownButton.setAttribute('aria-expanded', 'false');
            dropdownArrow.style.transform = 'rotate(0deg)';
        }

        // Toggle dropdown on button click
        dropdownButton.addEventListener('click', function(e) {
            e.stopPropagation();
            toggleDropdown();
        });

        // Close dropdown when clicking outside
        document.addEventListener('click', function(e) {
            if (!dropdownButton.contains(e.target) && !dropdownMenu.contains(e.target)) {
                closeDropdown();
            }
        });

        // Close dropdown when pressing Escape
        document.addEventListener('keydown', function(e) {
            if (e.key === 'Escape') {
                closeDropdown();
            }
        });

        // Handle dropdown menu item clicks
        dropdownMenu.addEventListener('click', function(e) {
            if (e.target.tagName === 'A' || e.target.tagName === 'BUTTON') {
                closeDropdown();
            }
        });

        // Mobile menu elements
        const mobileMenu = document.getElementById('mobile-menu');
        const menuToggle = document.getElementById('menu-toggle');
        const mobileShopsToggle = document.getElementById('mobile-shops-toggle');
        const mobileShopsMenu = document.getElementById('mobile-shops-menu');
        const mobileChevron = mobileShopsToggle.querySelector('i');

        // Mobile menu toggle
        menuToggle.addEventListener('click', function() {
            mobileMenu.classList.toggle('hidden');
        });
        
        // Close mobile menu when clicking on links
        mobileMenu.addEventListener('click', function(e) {
            if (e.target.tagName === 'A' && e.target.getAttribute('href').startsWith('#')) {
                mobileMenu.classList.add('hidden');
                // Also close the shops submenu
                mobileShopsMenu.classList.add('hidden');
                mobileChevron.style.transform = 'rotate(0deg)';
            }
        });

        // Mobile shops menu toggle
        mobileShopsToggle.addEventListener('click', function() {
            const isHidden = mobileShopsMenu.classList.contains('hidden');
            
            if (isHidden) {
                mobileShopsMenu.classList.remove('hidden');
                mobileChevron.style.transform = 'rotate(180deg)';
            } else {
                mobileShopsMenu.classList.add('hidden');
                mobileChevron.style.transform = 'rotate(0deg)';
            }
        });

        // Smooth scrolling
        document.querySelectorAll('a[href^="#"]').forEach(anchor => {
            anchor.addEventListener('click', function(e) {
                e.preventDefault();
                const target = document.querySelector(this.getAttribute('href'));
                if (target) {
                    target.scrollIntoView({
                        behavior: 'smooth'
                    });
                }
            });
        });

        // Form submission
        document.querySelector('form').addEventListener('submit', function(e) {
            e.preventDefault();
            alert('Merci pour votre demande ! Nous vous contacterons dans les plus brefs délais.');
            this.reset();
        });

        // Login button
        const loginBtn = document.getElementById('login-btn');
        if (loginBtn) {
            loginBtn.addEventListener('click', function(e) {
                e.preventDefault();
                window.location.href = 'admin/login.php';
            });
        }

        // Add scroll effect to header
        window.addEventListener('scroll', function() {
            const header = document.querySelector('header');
            if (window.scrollY > 100) {
                header.classList.add('shadow-2xl');
            } else {
                header.classList.remove('shadow-2xl');
            }
        });
    </script>
</body>
</html>