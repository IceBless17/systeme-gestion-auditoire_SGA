/**
 * SYSTÈME DE GESTION DES AUDITOIRES (SGA)
 * Script JavaScript - Interactivité légère
 */

document.addEventListener('DOMContentLoaded', function() {
    // ====================================================================
    // ANIMATIONS D'ENTRÉE
    // ====================================================================
    
    // Animer l'apparition des cartes au chargement
    const cards = document.querySelectorAll('.stat-card, .info-card, .occupation-card');
    cards.forEach((card, index) => {
        card.style.opacity = '0';
        card.style.transform = 'translateY(20px)';
        
        setTimeout(() => {
            card.style.transition = 'all 0.5s ease-out';
            card.style.opacity = '1';
            card.style.transform = 'translateY(0)';
        }, index * 100);
    });

    // ====================================================================
    // FERMETURE DES ALERTES
    // ====================================================================
    
    const alerts = document.querySelectorAll('.alert');
    alerts.forEach(alert => {
        // Créer un bouton de fermeture
        const closeBtn = document.createElement('button');
        closeBtn.innerHTML = '×';
        closeBtn.style.cssText = `
            position: absolute;
            right: 1rem;
            top: 50%;
            transform: translateY(-50%);
            background: none;
            border: none;
            font-size: 1.5rem;
            cursor: pointer;
            opacity: 0.7;
            transition: opacity 0.3s ease;
        `;
        closeBtn.onmouseover = () => closeBtn.style.opacity = '1';
        closeBtn.onmouseout = () => closeBtn.style.opacity = '0.7';
        closeBtn.onclick = () => {
            alert.style.animation = 'slideOut 0.3s ease-out forwards';
            setTimeout(() => alert.remove(), 300);
        };
        
        alert.style.position = 'relative';
        alert.appendChild(closeBtn);
    });

    // ====================================================================
    // INTERACTIVITÉ DES LIGNES DE TABLEAU
    // ====================================================================
    
    const tableRows = document.querySelectorAll('.rapport-table tbody tr');
    tableRows.forEach(row => {
        row.style.cursor = 'pointer';
        row.onmouseover = function() {
            this.style.transform = 'scale(1.02)';
            this.style.boxShadow = '0 4px 8px rgba(0, 0, 0, 0.1)';
        };
        row.onmouseout = function() {
            this.style.transform = 'scale(1)';
            this.style.boxShadow = 'none';
        };
    });

    // ====================================================================
    // BARRE DE PROGRESSION ANIMÉE
    // ====================================================================
    
    const progressBars = document.querySelectorAll('.progress');
    progressBars.forEach(bar => {
        const targetWidth = bar.style.width;
        bar.style.width = '0%';
        
        setTimeout(() => {
            bar.style.transition = 'width 0.6s ease-out';
            bar.style.width = targetWidth;
        }, 100);
    });

    // ====================================================================
    // SMOOTH SCROLL POUR LES LIENS INTERNES
    // ====================================================================
    
    document.querySelectorAll('a[href^="#"]').forEach(anchor => {
        anchor.addEventListener('click', function(e) {
            e.preventDefault();
            const target = document.querySelector(this.getAttribute('href'));
            if (target) {
                target.scrollIntoView({ behavior: 'smooth', block: 'start' });
            }
        });
    });

    // ====================================================================
    // AFFICHAGE DYNAMIQUE DE STATISTIQUES
    // ====================================================================
    
    function animateCounter(element, target, duration = 1000) {
        let current = 0;
        const increment = target / (duration / 16);
        const timer = setInterval(() => {
            current += increment;
            if (current >= target) {
                element.textContent = target;
                clearInterval(timer);
            } else {
                element.textContent = Math.floor(current);
            }
        }, 16);
    }

    // Animer les nombres dans les cartes de statistiques
    const statNumbers = document.querySelectorAll('.stat-number');
    statNumbers.forEach(stat => {
        const observer = new IntersectionObserver(entries => {
            if (entries[0].isIntersecting && !stat.dataset.animated) {
                stat.dataset.animated = true;
                const target = parseInt(stat.textContent);
                animateCounter(stat, target);
            }
        });
        observer.observe(stat);
    });

    // ====================================================================
    // CONFIRMATION AVANT ACTIONS CRITIQUES
    // ====================================================================
    
    const generateBtn = document.querySelector('a[href*="generer"]');
    if (generateBtn) {
        generateBtn.onclick = function(e) {
            if (!confirm('Êtes-vous sûr ? Cela va régénérer le planning complet.')) {
                e.preventDefault();
            }
        };
    }

    const effectifInput = document.getElementById('effectif');
    const salleSelect = document.getElementById('salle');
    const sallePropositions = document.getElementById('sallePropositions');
    const capacityError = document.getElementById('capacityError');

    if (effectifInput && salleSelect && sallePropositions && capacityError) {
        const updateValidation = () => {
            const effectif = parseInt(effectifInput.value, 10) || 0;
            const selectedOption = salleSelect.options[salleSelect.selectedIndex];
            const capacity = selectedOption ? parseInt(selectedOption.dataset.capacite, 10) || 0 : 0;

            // Validation de capacité
            if (effectif > 0 && capacity > 0 && effectif > capacity) {
                capacityError.textContent = `Le nombre d'étudiants (${effectif}) dépasse la capacité de l'auditoire choisi (${capacity} places).`;
                capacityError.style.display = 'block';
            } else {
                capacityError.style.display = 'none';
            }

            // Mise à jour des propositions
            const proposals = [];
            const options = Array.from(salleSelect.options).slice(1);
            options.forEach(option => {
                const cap = parseInt(option.dataset.capacite, 10) || 0;
                if (cap >= effectif && effectif > 0) {
                    proposals.push(`${option.text} (${cap} places)`);
                }
            });

            if (effectif <= 0) {
                sallePropositions.textContent = 'Entrez un effectif pour afficher les auditoires proposés.';
                sallePropositions.style.color = '#374151';
            } else if (proposals.length === 0) {
                sallePropositions.textContent = 'Aucune proposition d\'auditoire ne correspond à cet effectif. Vérifiez le nombre d\'étudiants ou ajoutez une salle.';
                sallePropositions.style.color = '#b91c1c';
            } else {
                sallePropositions.innerHTML = '<strong>Propositions :</strong> ' + proposals.join(', ');
                sallePropositions.style.color = '#111827';
            }
        };

        effectifInput.addEventListener('input', updateValidation);
        salleSelect.addEventListener('change', updateValidation);
        updateValidation();

        // Empêcher la soumission si erreur de capacité
        const form = document.querySelector('.interactive-planning-form');
        if (form) {
            form.addEventListener('submit', function(e) {
                const effectif = parseInt(effectifInput.value, 10) || 0;
                const selectedOption = salleSelect.options[salleSelect.selectedIndex];
                const capacity = selectedOption ? parseInt(selectedOption.dataset.capacite, 10) || 0 : 0;

                if (effectif > 0 && capacity > 0 && effectif > capacity) {
                    e.preventDefault();
                    alert('Le nombre d\'étudiants dépasse la capacité de l\'auditoire choisi. Veuillez corriger avant de soumettre.');
                    return false;
                }
            });
        }
    }

    // ====================================================================
    // RESPONSIVE NAVIGATION
    // ====================================================================
    
    // Ajouter une classe d'actif au lien de navigation correspondant
    const currentPage = new URLSearchParams(window.location.search).get('action') || 'dashboard';
    const navLinks = document.querySelectorAll('.nav-link');
    navLinks.forEach(link => {
        if (link.href.includes('action=' + currentPage)) {
            link.classList.add('active');
        }
    });

    // ====================================================================
    // GESTION CLAVIER
    // ====================================================================
    
    document.addEventListener('keydown', function(e) {
        // Raccourci clavier : Ctrl+G pour générer
        if (e.ctrlKey && e.key === 'g') {
            e.preventDefault();
            const generateLink = document.querySelector('a[href*="generer"]');
            if (generateLink) generateLink.click();
        }
        
        // Raccourci clavier : Ctrl+1, Ctrl+2, etc. pour naviguer
        if (e.ctrlKey && /^[1-5]$/.test(e.key)) {
            e.preventDefault();
            const links = document.querySelectorAll('.nav-link');
            if (links[parseInt(e.key) - 1]) {
                window.location.href = links[parseInt(e.key) - 1].href;
            }
        }
    });

    // ====================================================================
    // PARTAGE DE DONNÉES
    // ====================================================================
    
    if (navigator.share) {
        const shareBtn = document.createElement('button');
        shareBtn.textContent = '📤 Partager';
        shareBtn.className = 'btn btn-secondary';
        shareBtn.style.marginLeft = '1rem';
        
        shareBtn.onclick = () => {
            navigator.share({
                title: 'Planning Auditoires',
                text: 'Consultez le planning généré du système de gestion des auditoires',
                url: window.location.href
            });
        };
        
        const downloadSection = document.querySelector('.download-section');
        if (downloadSection) {
            downloadSection.appendChild(shareBtn);
        }
    }

    // ====================================================================
    // TOAST NOTIFICATIONS (SIMPLE)
    // ====================================================================
    
    window.showToast = function(message, type = 'info') {
        const toast = document.createElement('div');
        toast.style.cssText = `
            position: fixed;
            bottom: 20px;
            right: 20px;
            background: ${type === 'success' ? '#10b981' : type === 'error' ? '#ef4444' : '#2563eb'};
            color: white;
            padding: 1rem 1.5rem;
            border-radius: 0.5rem;
            box-shadow: 0 4px 12px rgba(0, 0, 0, 0.15);
            z-index: 1000;
            animation: slideInUp 0.3s ease-out;
        `;
        toast.textContent = message;
        document.body.appendChild(toast);
        
        setTimeout(() => {
            toast.style.animation = 'slideOutDown 0.3s ease-out forwards';
            setTimeout(() => toast.remove(), 300);
        }, 3000);
    };

    // ====================================================================
    // VÉRIFICATION DE LA CONNEXION
    // ====================================================================
    
    window.addEventListener('online', () => {
        showToast('✓ Connexion rétablie', 'success');
    });

    window.addEventListener('offline', () => {
        showToast('⚠ Vous êtes hors ligne', 'error');
    });

    // ====================================================================
    // IMPRESSION DU PLANNING
    // ====================================================================
    
    window.printPlanning = function() {
        const printWindow = window.open('', '', 'width=1000,height=600');
        const content = document.querySelector('.planning-container').innerHTML;
        
        printWindow.document.write(`
            <html>
            <head>
                <title>Planning Auditoires</title>
                <style>
                    body { font-family: Arial, sans-serif; }
                    table { width: 100%; border-collapse: collapse; }
                    th, td { border: 1px solid #ccc; padding: 8px; text-align: left; }
                    th { background: #667eea; color: white; }
                </style>
            </head>
            <body onload="window.print()">
                ${content}
            </body>
            </html>
        `);
        printWindow.document.close();
    };

    console.log('✓ Système SGA chargé et prêt');
});

// Ajout des animations CSS manquantes
const style = document.createElement('style');
style.textContent = `
    @keyframes slideOut {
        to {
            opacity: 0;
            transform: translateX(100%);
        }
    }
    
    @keyframes slideInUp {
        from {
            opacity: 0;
            transform: translateY(20px);
        }
        to {
            opacity: 1;
            transform: translateY(0);
        }
    }
    
    @keyframes slideOutDown {
        to {
            opacity: 0;
            transform: translateY(20px);
        }
    }
`;
document.head.appendChild(style);
