<?php
/**
 * SYSTÈME DE GESTION DES AUDITOIRES (SGA)
 * Script Principal - index.php
 * 
 * Ce script orchestre l'ensemble du système :
 * - Chargement des données depuis les fichiers JSON
 * - Génération du planning automatique
 * - Sauvegarde du planning
 * - Affichage du planning en HTML
 * - Gestion des actions (générer, afficher, télécharger rapports)
 */

// Configuration
define('ROOT_PATH', __DIR__);
define('DATA_PATH', ROOT_PATH . '/data');
define('CONFIG_PATH', ROOT_PATH . '/config');

// Inclure les fonctions
require_once CONFIG_PATH . '/functions.php';

/**
 * Nettoie une valeur utilisateur
 * @param mixed $valeur
 * @return string
 */
function sanitize_input($valeur) {
    return trim(htmlspecialchars((string)$valeur, ENT_QUOTES, 'UTF-8'));
}

// Variables pour stocker les messages
$messages = [];
$erreurs = [];
$planning = [];
$rapport_occupation = [];
$salles = [];
$promotions = [];
$cours = [];
$options = [];
$conflits = [];

// ============================================================================
// TRAITEMENT DES ACTIONS
// ============================================================================

// Action par défaut
$action = isset($_GET['action']) ? $_GET['action'] : 'dashboard';

try {
    // Charger les données
    $salles = charger_salles(DATA_PATH . '/salles.json');
    $promotions = charger_promotions(DATA_PATH . '/promotions.json');
    $cours = charger_cours(DATA_PATH . '/cours.json');
    $options = charger_options(DATA_PATH . '/options.json');
    
    // Traitement des formulaires de données
    if ($_SERVER['REQUEST_METHOD'] === 'POST') {
        $formType = isset($_POST['form_type']) ? sanitize_input($_POST['form_type']) : '';

        if ($formType === 'add_salle') {
            $nouvelleSalle = [
                'id' => sanitize_input($_POST['id'] ?? ''),
                'designation' => sanitize_input($_POST['designation'] ?? ''),
                'capacite' => (int)($_POST['capacite'] ?? 0)
            ];

            if ($nouvelleSalle['id'] === '' || $nouvelleSalle['designation'] === '' || $nouvelleSalle['capacite'] <= 0) {
                $erreurs[] = 'Veuillez remplir tous les champs valides pour ajouter une salle.';
            } elseif (isset($salles[$nouvelleSalle['id']])) {
                $erreurs[] = 'Cette salle existe déjà.';
            } else {
                $salles[$nouvelleSalle['id']] = $nouvelleSalle;
                if (sauvegarder_json(array_values($salles), DATA_PATH . '/salles.json')) {
                    $messages[] = '✅ Salle ajoutée avec succès.';
                } else {
                    $erreurs[] = 'Erreur lors de la sauvegarde de la salle.';
                }
            }
            $action = 'salles';
        }

        if ($formType === 'edit_salle') {
            $id = sanitize_input($_POST['id'] ?? '');
            if (!isset($salles[$id])) {
                $erreurs[] = 'La salle n\'existe pas.';
            } else {
                $salles[$id]['designation'] = sanitize_input($_POST['designation'] ?? $salles[$id]['designation']);
                $salles[$id]['capacite'] = (int)($_POST['capacite'] ?? $salles[$id]['capacite']);
                if ($salles[$id]['capacite'] <= 0) {
                    $erreurs[] = 'La capacité doit être un nombre positif.';
                } else {
                    if (sauvegarder_json(array_values($salles), DATA_PATH . '/salles.json')) {
                        $messages[] = '✅ Salle mise à jour avec succès.';
                    } else {
                        $erreurs[] = 'Erreur lors de la sauvegarde de la salle.';
                    }
                }
            }
            $action = 'salles';
        }

        if ($formType === 'add_promotion') {
            $nouvellePromo = [
                'id' => sanitize_input($_POST['id'] ?? ''),
                'libelle' => sanitize_input($_POST['libelle'] ?? ''),
                'effectif' => (int)($_POST['effectif'] ?? 0)
            ];

            if ($nouvellePromo['id'] === '' || $nouvellePromo['libelle'] === '' || $nouvellePromo['effectif'] <= 0) {
                $erreurs[] = 'Veuillez remplir tous les champs valides pour ajouter une promotion.';
            } elseif (isset($promotions[$nouvellePromo['id']])) {
                $erreurs[] = 'Cette promotion existe déjà.';
            } else {
                $promotions[$nouvellePromo['id']] = $nouvellePromo;
                if (sauvegarder_json(array_values($promotions), DATA_PATH . '/promotions.json')) {
                    $messages[] = '✅ Promotion ajoutée avec succès.';
                } else {
                    $erreurs[] = 'Erreur lors de la sauvegarde de la promotion.';
                }
            }
            $action = 'promotions';
        }

        if ($formType === 'edit_promotion') {
            $id = sanitize_input($_POST['id'] ?? '');
            if (!isset($promotions[$id])) {
                $erreurs[] = 'La promotion n\'existe pas.';
            } else {
                $promotions[$id]['libelle'] = sanitize_input($_POST['libelle'] ?? $promotions[$id]['libelle']);
                $promotions[$id]['effectif'] = (int)($_POST['effectif'] ?? $promotions[$id]['effectif']);
                if ($promotions[$id]['effectif'] <= 0) {
                    $erreurs[] = 'L\'effectif doit être un nombre positif.';
                } else {
                    if (sauvegarder_json(array_values($promotions), DATA_PATH . '/promotions.json')) {
                        $messages[] = '✅ Promotion mise à jour avec succès.';
                    } else {
                        $erreurs[] = 'Erreur lors de la sauvegarde de la promotion.';
                    }
                }
            }
            $action = 'promotions';
        }

        if ($formType === 'add_cours') {
            $nouveauCours = [
                'id' => sanitize_input($_POST['id'] ?? ''),
                'intitule' => sanitize_input($_POST['intitule'] ?? ''),
                'volume_horaire' => (int)($_POST['volume_horaire'] ?? 0),
                'type' => sanitize_input($_POST['type'] ?? ''),
                'promotion' => sanitize_input($_POST['promotion'] ?? '')
            ];

            if ($nouveauCours['id'] === '' || $nouveauCours['intitule'] === '' || $nouveauCours['volume_horaire'] <= 0 || ($nouveauCours['type'] !== 'tronc_commun' && $nouveauCours['type'] !== 'option') || $nouveauCours['promotion'] === '') {
                $erreurs[] = 'Veuillez remplir tous les champs valides pour ajouter un cours.';
            } else {
                $existe = false;
                foreach ($cours as $item) {
                    if ($item['id'] === $nouveauCours['id']) {
                        $existe = true;
                        break;
                    }
                }
                if ($existe) {
                    $erreurs[] = 'Ce cours existe déjà.';
                } else {
                    $cours[] = $nouveauCours;
                    if (sauvegarder_json($cours, DATA_PATH . '/cours.json')) {
                        $messages[] = '✅ Cours ajouté avec succès.';
                    } else {
                        $erreurs[] = 'Erreur lors de la sauvegarde du cours.';
                    }
                }
            }
            $action = 'cours';
        }

        if ($formType === 'edit_cours') {
            $id = sanitize_input($_POST['id'] ?? '');
            $index = null;
            foreach ($cours as $key => $item) {
                if ($item['id'] === $id) {
                    $index = $key;
                    break;
                }
            }
            if ($index === null) {
                $erreurs[] = 'Le cours n\'existe pas.';
            } else {
                $cours[$index]['intitule'] = sanitize_input($_POST['intitule'] ?? $cours[$index]['intitule']);
                $cours[$index]['volume_horaire'] = (int)($_POST['volume_horaire'] ?? $cours[$index]['volume_horaire']);
                $cours[$index]['type'] = sanitize_input($_POST['type'] ?? $cours[$index]['type']);
                $cours[$index]['promotion'] = sanitize_input($_POST['promotion'] ?? $cours[$index]['promotion']);

                if ($cours[$index]['volume_horaire'] <= 0) {
                    $erreurs[] = 'Le volume horaire doit être un nombre positif.';
                } elseif ($cours[$index]['type'] !== 'tronc_commun' && $cours[$index]['type'] !== 'option') {
                    $erreurs[] = 'Le type de cours doit être tronc_commun ou option.';
                } else {
                    if (sauvegarder_json($cours, DATA_PATH . '/cours.json')) {
                        $messages[] = '✅ Cours mis à jour avec succès.';
                    } else {
                        $erreurs[] = 'Erreur lors de la sauvegarde du cours.';
                    }
                }
            }
            $action = 'cours';
        }

        if ($formType === 'add_option') {
            $nouvelleOption = [
                'id' => sanitize_input($_POST['id'] ?? ''),
                'libelle' => sanitize_input($_POST['libelle'] ?? ''),
                'promotion_parent' => sanitize_input($_POST['promotion_parent'] ?? ''),
                'effectif' => (int)($_POST['effectif'] ?? 0)
            ];

            if ($nouvelleOption['id'] === '' || $nouvelleOption['libelle'] === '' || $nouvelleOption['promotion_parent'] === '' || $nouvelleOption['effectif'] <= 0) {
                $erreurs[] = 'Veuillez remplir tous les champs valides pour ajouter une option.';
            } else {
                $existe = false;
                foreach ($options as $item) {
                    if ($item['id'] === $nouvelleOption['id']) {
                        $existe = true;
                        break;
                    }
                }
                if ($existe) {
                    $erreurs[] = 'Cette option existe déjà.';
                } else {
                    $options[] = $nouvelleOption;
                    if (sauvegarder_json($options, DATA_PATH . '/options.json')) {
                        $messages[] = '✅ Option ajoutée avec succès.';
                    } else {
                        $erreurs[] = 'Erreur lors de la sauvegarde de l\'option.';
                    }
                }
            }
            $action = 'options';
        }

        if ($formType === 'edit_option') {
            $id = sanitize_input($_POST['id'] ?? '');
            $index = null;
            foreach ($options as $key => $item) {
                if ($item['id'] === $id) {
                    $index = $key;
                    break;
                }
            }
            if ($index === null) {
                $erreurs[] = 'L\'option n\'existe pas.';
            } else {
                $options[$index]['libelle'] = sanitize_input($_POST['libelle'] ?? $options[$index]['libelle']);
                $options[$index]['promotion_parent'] = sanitize_input($_POST['promotion_parent'] ?? $options[$index]['promotion_parent']);
                $options[$index]['effectif'] = (int)($_POST['effectif'] ?? $options[$index]['effectif']);

                if ($options[$index]['effectif'] <= 0) {
                    $erreurs[] = 'L\'effectif doit être un nombre positif.';
                } else {
                    if (sauvegarder_json($options, DATA_PATH . '/options.json')) {
                        $messages[] = '✅ Option mise à jour avec succès.';
                    } else {
                        $erreurs[] = 'Erreur lors de la sauvegarde de l\'option.';
                    }
                }
            }
            $action = 'options';
        }
    }

    switch ($action) {
        case 'generer':
            // Générer le planning
            $creneaux = generer_creneaux();
            $planning = generer_planning($salles, $promotions, $cours, $options, $creneaux);
            
            // Sauvegarder
            if (sauvegarder_planning($planning, DATA_PATH . '/planning.json')) {
                $messages[] = "✓ Planning généré et sauvegardé avec succès !";
                $messages[] = count($planning) . " affectations créées.";
            } else {
                $erreurs[] = "✗ Erreur lors de la sauvegarde du planning.";
            }
            
            $action = 'afficher'; // Afficher après génération
            $planning = charger_planning(DATA_PATH . '/planning.json');
            break;
            
        case 'afficher':
            // Charger et afficher le planning existant
            if (file_exists(DATA_PATH . '/planning.json')) {
                $planning = charger_planning(DATA_PATH . '/planning.json');
                $messages[] = "Planning chargé : " . count($planning) . " affectations.";
            } else {
                $erreurs[] = "⚠ Aucun planning généré. Veuillez d'abord générer le planning.";
            }
            break;
            
        case 'rapport':
            // Générer le rapport d'occupation
            if (file_exists(DATA_PATH . '/planning.json')) {
                $planning = charger_planning(DATA_PATH . '/planning.json');
                $rapport_occupation = rapport_occupation_salles($planning, $salles);
                
                // Sauvegarder le rapport
                if (sauvegarder_rapport_occupation($rapport_occupation, DATA_PATH . '/rapport_occupation.txt')) {
                    $messages[] = "✓ Rapport d'occupation généré et sauvegardé.";
                } else {
                    $erreurs[] = "✗ Erreur lors de la sauvegarde du rapport.";
                }
            } else {
                $erreurs[] = "⚠ Aucun planning généré. Générez d'abord le planning.";
            }
            break;
            
        case 'conflits':
            // Détecter les conflits
            if (file_exists(DATA_PATH . '/planning.json')) {
                $planning = charger_planning(DATA_PATH . '/planning.json');
                $conflits = detecter_conflits($planning);
                
                if (empty($conflits)) {
                    $messages[] = "✓ Aucun conflit détecté. Planning valide.";
                } else {
                    $erreurs[] = count($conflits) . " conflit(s) détecté(s) :";
                    foreach ($conflits as $conflit) {
                        $erreurs[] = " - " . $conflit['message'];
                    }
                }
            } else {
                $erreurs[] = "⚠ Aucun planning généré.";
            }
            break;
            
        case 'dashboard':
        default:
            // Page d'accueil - dashboard
            if (file_exists(DATA_PATH . '/planning.json')) {
                $planning = charger_planning(DATA_PATH . '/planning.json');
                $rapport_occupation = rapport_occupation_salles($planning, $salles);
                $conflits = detecter_conflits($planning);
                
                $messages[] = "Dashboard chargé avec succès.";
                if (!empty($conflits)) {
                    $erreurs[] = "⚠ " . count($conflits) . " conflit(s) détecté(s) dans le planning";
                }
            } else {
                $messages[] = "Aucun planning généré. Cliquez sur \"Générer le Planning\" pour commencer.";
            }
            break;
    }
    
} catch (Exception $e) {
    $erreurs[] = $e->getMessage();
}

?>
<!DOCTYPE html>
<html lang="fr">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Système de Gestion des Auditoires (SGA)</title>
    <link rel="stylesheet" href="assets/css/style.css">
</head>
<body>
    <div class="container">
        <!-- HEADER -->
        <header class="header">
            <div class="header-content">
                <div class="logo">
                    <h1>🏛️ SGA</h1>
                    <p>Système de Gestion des Auditoires</p>
                </div>
                <nav class="nav">
                    <a href="?action=dashboard" class="nav-link <?php echo $action === 'dashboard' ? 'active' : ''; ?>">
                        📊 Dashboard
                    </a>
                    <a href="?action=salles" class="nav-link <?php echo $action === 'salles' ? 'active' : ''; ?>">
                        🏢 Salles
                    </a>
                    <a href="?action=promotions" class="nav-link <?php echo $action === 'promotions' ? 'active' : ''; ?>">
                        👥 Promotions
                    </a>
                    <a href="?action=cours" class="nav-link <?php echo $action === 'cours' ? 'active' : ''; ?>">
                        📖 Cours
                    </a>
                    <a href="?action=options" class="nav-link <?php echo $action === 'options' ? 'active' : ''; ?>">
                        🎯 Options
                    </a>
                    <a href="?action=generer" class="nav-link <?php echo $action === 'generer' ? 'active' : ''; ?>">
                        ⚙️ Générer Planning
                    </a>
                    <a href="?action=afficher" class="nav-link <?php echo $action === 'afficher' ? 'active' : ''; ?>">
                        📅 Planning
                    </a>
                    <a href="?action=rapport" class="nav-link <?php echo $action === 'rapport' ? 'active' : ''; ?>">
                        📈 Rapports
                    </a>
                    <a href="?action=conflits" class="nav-link <?php echo $action === 'conflits' ? 'active' : ''; ?>">
                        ⚠️ Conflits
                    </a>
                </nav>
            </div>
        </header>

        <!-- MESSAGES & ERREURS -->
        <?php if (!empty($messages) || !empty($erreurs)): ?>
        <div class="alerts">
            <?php foreach ($messages as $msg): ?>
                <div class="alert alert-success">
                    <?php echo htmlspecialchars($msg); ?>
                </div>
            <?php endforeach; ?>
            
            <?php foreach ($erreurs as $err): ?>
                <div class="alert alert-error">
                    <?php echo htmlspecialchars($err); ?>
                </div>
            <?php endforeach; ?>
        </div>
        <?php endif; ?>

        <!-- CONTENU PRINCIPAL -->
        <main class="main">
            <?php if ($action === 'dashboard'): ?>
                <!-- DASHBOARD -->
                <section class="section">
                    <h2>📊 Dashboard Système</h2>
                    
                    <div class="stats-grid">
                        <div class="stat-card">
                            <h3>Salles</h3>
                            <p class="stat-number"><?php echo count($salles); ?></p>
                            <p class="stat-label">Espaces disponibles</p>
                        </div>
                        
                        <div class="stat-card">
                            <h3>Promotions</h3>
                            <p class="stat-number"><?php echo count($promotions); ?></p>
                            <p class="stat-label">Groupes d'étudiants</p>
                        </div>
                        
                        <div class="stat-card">
                            <h3>Cours</h3>
                            <p class="stat-number"><?php echo count($cours); ?></p>
                            <p class="stat-label">À planifier</p>
                        </div>
                        
                        <div class="stat-card">
                            <h3>Options</h3>
                            <p class="stat-number"><?php echo count($options); ?></p>
                            <p class="stat-label">Filières spécialisées</p>
                        </div>
                    </div>

                    <div class="actions">
                        <a href="?action=generer" class="btn btn-primary">
                            ⚙️ Générer le Planning
                        </a>
                    </div>
                </section>

                <!-- INFORMATIONS SYSTÈME -->
                <section class="section">
                    <h2>📋 Configuration Système</h2>
                    
                    <div class="info-grid">
                        <div class="info-card">
                            <h4>Salles Disponibles</h4>
                            <ul class="list">
                                <?php foreach ($salles as $id => $salle): ?>
                                    <li>
                                        <strong><?php echo htmlspecialchars($id); ?></strong>
                                        <span class="capacity"><?php echo $salle['capacite']; ?> places</span>
                                    </li>
                                <?php endforeach; ?>
                            </ul>
                        </div>

                        <div class="info-card">
                            <h4>Promotions & Effectifs</h4>
                            <ul class="list">
                                <?php foreach ($promotions as $id => $promo): ?>
                                    <li>
                                        <strong><?php echo htmlspecialchars($promo['libelle']); ?></strong>
                                        <span class="capacity"><?php echo $promo['effectif']; ?> étudiants</span>
                                    </li>
                                <?php endforeach; ?>
                            </ul>
                        </div>
                    </div>
                </section>

                <!-- STATISTIQUES PLANNING -->
                <?php if (!empty($rapport_occupation)): ?>
                <section class="section">
                    <h2>📊 Taux d'Occupation des Salles</h2>
                    
                    <div class="occupation-grid">
                        <?php foreach ($rapport_occupation as $id_salle => $stats): ?>
                            <div class="occupation-card">
                                <h4><?php echo htmlspecialchars($id_salle); ?></h4>
                                <p class="occupation-stat">
                                    <strong><?php echo $stats['taux_occupation']; ?>%</strong> occupée
                                </p>
                                <div class="progress-bar">
                                    <div class="progress" style="width: <?php echo $stats['taux_occupation']; ?>%"></div>
                                </div>
                                <small>
                                    <?php echo $stats['creneaux_occupes']; ?> créneaux / 
                                    <?php echo $stats['creneaux_occupes'] + $stats['creneaux_libres']; ?> total
                                </small>
                            </div>
                        <?php endforeach; ?>
                    </div>
                </section>
                <?php endif; ?>

            <?php elseif ($action === 'afficher' && !empty($planning)): ?>
                <!-- AFFICHAGE PLANNING -->
                <section class="section">
                    <h2>📅 Planning Hebdomadaire</h2>
                    <div class="planning-container">
                        <?php echo afficher_planning_html($planning, $salles); ?>
                    </div>
                </section>

            <?php elseif ($action === 'rapport' && !empty($rapport_occupation)): ?>
                <!-- RAPPORT D'OCCUPATION -->
                <section class="section">
                    <h2>📈 Rapport d'Occupation des Salles</h2>
                    
                    <div class="rapport-container">
                        <table class="rapport-table">
                            <thead>
                                <tr>
                                    <th>Salle</th>
                                    <th>Capacité</th>
                                    <th>Créneaux Occupés</th>
                                    <th>Créneaux Libres</th>
                                    <th>Taux d'Occupation</th>
                                </tr>
                            </thead>
                            <tbody>
                                <?php foreach ($rapport_occupation as $id_salle => $stats): ?>
                                    <tr>
                                        <td><strong><?php echo htmlspecialchars($id_salle); ?></strong></td>
                                        <td><?php echo $stats['capacite']; ?></td>
                                        <td><?php echo $stats['creneaux_occupes']; ?></td>
                                        <td><?php echo $stats['creneaux_libres']; ?></td>
                                        <td>
                                            <div class="progress-inline">
                                                <div class="progress-bar">
                                                    <div class="progress" style="width: <?php echo $stats['taux_occupation']; ?>%"></div>
                                                </div>
                                                <span><?php echo $stats['taux_occupation']; ?>%</span>
                                            </div>
                                        </td>
                                    </tr>
                                <?php endforeach; ?>
                            </tbody>
                        </table>
                    </div>
                    
                    <div class="download-section">
                        <a href="data/rapport_occupation.txt" download class="btn btn-secondary">
                            📥 Télécharger Rapport TXT
                        </a>
                    </div>
                </section>

            <?php else: ?>
                <!-- PAGE PAR DÉFAUT -->
                <section class="section">
                    <h2>📍 Aucune donnée à afficher</h2>
                    <p>Veuillez générer le planning pour voir les résultats.</p>
                </section>
            <?php endif; ?>
        </main>

        <!-- FOOTER -->
        <footer class="footer">
            <p>&copy; 2025-2026 Université Protestante au Congo - Faculté des Sciences Informatiques</p>
            <p>Système de Gestion des Auditoires v1.0</p>
        </footer>
    </div>

    <script src="assets/js/script.js"></script>
</body>
</html>
