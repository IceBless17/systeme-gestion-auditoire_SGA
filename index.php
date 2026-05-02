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

// Démarrer la session pour l'authentification
session_start();

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
$plannings_archives = [];
$users = [];
$pending_user = $_SESSION['pending_user'] ?? null;
$webauthn_register_needed = $_SESSION['webauthn_register_needed'] ?? false;
$webauthn_auth_needed = $_SESSION['webauthn_auth_needed'] ?? false;

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
    $users = charger_users(DATA_PATH . '/users.json');

    // Si l'utilisateur n'est pas connecté, forcer la page de connexion
    if (!isset($_SESSION['user']) && !in_array($action, ['login', 'logout', 'webauthn_register_start', 'webauthn_register_finish', 'webauthn_login_start', 'webauthn_login_finish'])) {
        $action = 'login';
    }

    // Traitement des formulaires de données
    if ($_SERVER['REQUEST_METHOD'] === 'POST') {
        $formType = isset($_POST['form_type']) ? sanitize_input($_POST['form_type']) : '';

        if ($formType === 'login_password') {
            $username = sanitize_input($_POST['username'] ?? '');
            $password = $_POST['password'] ?? '';

            if ($username === '' || $password === '') {
                $erreurs[] = 'Veuillez saisir votre nom d’utilisateur et votre mot de passe.';
                $action = 'login';
            } elseif (!verify_user_password($username, $password, $users)) {
                $erreurs[] = 'Utilisateur ou mot de passe incorrect.';
                $action = 'login';
            } else {
                $user = get_user($username, $users);
                if ($user === null) {
                    $erreurs[] = 'Utilisateur introuvable.';
                    $action = 'login';
                } elseif (!empty($user['authenticators'])) {
                    $_SESSION['pending_user'] = $username;
                    $_SESSION['webauthn_auth_needed'] = true;
                    $_SESSION['webauthn_register_needed'] = false;
                    $pending_user = $username;
                    $webauthn_auth_needed = true;
                    $action = 'login';
                    $messages[] = '✅ Mot de passe validé. Veuillez terminer la connexion avec votre dispositif WebAuthn.';
                } else {
                    $_SESSION['pending_user'] = $username;
                    $_SESSION['webauthn_register_needed'] = true;
                    $_SESSION['webauthn_auth_needed'] = false;
                    $pending_user = $username;
                    $webauthn_register_needed = true;
                    $action = 'login';
                    $messages[] = '✅ Mot de passe validé. Enregistrez un dispositif WebAuthn pour activer la double authentification.';
                }
            }
        } elseif ($action === 'logout') {
            session_destroy();
            session_start();
            $messages[] = '✅ Vous êtes déconnecté.';
            $action = 'login';
        } elseif ($action === 'webauthn_register_finish' || $action === 'webauthn_login_finish') {
            header('Content-Type: application/json');
            $input = json_decode(file_get_contents('php://input'), true);
            $username = $_SESSION['pending_user'] ?? $_SESSION['user'] ?? null;

            try {
                if (!$username) {
                    throw new Exception('Utilisateur manquant pour WebAuthn.');
                }

                if ($action === 'webauthn_register_finish') {
                    $authenticator = webauthn_verify_registration_response($input, $username);
                    $users[$username]['authenticators'][] = $authenticator;
                    sauvegarder_users($users, DATA_PATH . '/users.json');
                    $_SESSION['user'] = $username;
                    unset($_SESSION['pending_user'], $_SESSION['webauthn_auth_needed'], $_SESSION['webauthn_register_needed'], $_SESSION['webauthn_reg_challenge'], $_SESSION['webauthn_challenge_user']);
                    echo json_encode(['success' => true]);
                    exit;
                }

                if ($action === 'webauthn_login_finish') {
                    if (webauthn_verify_authentication_response($input, $username, $users)) {
                        sauvegarder_users($users, DATA_PATH . '/users.json');
                        $_SESSION['user'] = $username;
                        unset($_SESSION['pending_user'], $_SESSION['webauthn_auth_needed'], $_SESSION['webauthn_register_needed'], $_SESSION['webauthn_auth_challenge'], $_SESSION['webauthn_challenge_user']);
                        echo json_encode(['success' => true]);
                        exit;
                    }
                }
            } catch (Exception $e) {
                http_response_code(400);
                echo json_encode(['success' => false, 'error' => $e->getMessage()]);
                exit;
            }
        } elseif (!isset($_SESSION['user'])) {
            $erreurs[] = 'Authentification requise.';
            $action = 'login';
        }

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

    if ($action === 'webauthn_register_start' && $_SERVER['REQUEST_METHOD'] === 'GET') {
        header('Content-Type: application/json');
        $username = $_SESSION['user'] ?? $_SESSION['pending_user'] ?? null;
        if (!$username) {
            http_response_code(403);
            echo json_encode(['success' => false, 'error' => 'Authentification requise.']);
            exit;
        }

        $user = get_user($username, $users);
        echo json_encode(webauthn_registration_options($username, $user));
        exit;
    }

    if ($action === 'webauthn_login_start' && $_SERVER['REQUEST_METHOD'] === 'GET') {
        header('Content-Type: application/json');
        $username = $_SESSION['pending_user'] ?? null;
        if (!$username) {
            http_response_code(403);
            echo json_encode(['success' => false, 'error' => 'Authentification requise.']);
            exit;
        }

        $user = get_user($username, $users);
        echo json_encode(webauthn_authentication_options($username, $user));
        exit;
    }

    if ($action === 'logout' && $_SERVER['REQUEST_METHOD'] !== 'POST') {
        session_destroy();
        session_start();
        $messages[] = '✅ Vous êtes déconnecté.';
        $action = 'login';
    }

    switch ($action) {
        case 'generer':
            // Archiver le planning existant avant d'en créer un nouveau
            $archive_path = DATA_PATH . '/archives';
            if (archiver_planning(DATA_PATH . '/planning.json', $archive_path)) {
                $messages[] = "✓ Planning précédent archivé avec succès.";
            }
            
            // Générer le nouveau planning
            $creneaux = generer_creneaux();
            $result = generer_planning($salles, $promotions, $cours, $options, $creneaux);
            $planning = $result['planning'];
            $warnings = $result['warnings'];
            
            // Afficher les résultats
            if (!empty($planning)) {
                $messages[] = "✅ Planning généré avec succès !";
                $messages[] = "📊 " . $result['total_creneaux'] . " affectations créées couvrant " . $result['jours_couverts'] . " jours.";
            } else {
                $erreurs[] = "❌ Aucune affectation n'a pu être créée. Vérifiez les effectifs et capacités.";
            }
            
            // Afficher les warnings
            if (!empty($warnings)) {
                foreach ($warnings as $warning) {
                    $erreurs[] = $warning;
                }
            }
            
            // Sauvegarder le planning généré
            if (!empty($planning) && sauvegarder_planning($planning, DATA_PATH . '/planning.json')) {
                $messages[] = "✓ Planning sauvegardé en tant que version actuelle.";
            } elseif (!empty($planning)) {
                $erreurs[] = "✗ Erreur lors de la sauvegarde du planning.";
            }
            
            $action = 'afficher'; // Afficher après génération
            if (file_exists(DATA_PATH . '/planning.json')) {
                $planning = charger_planning(DATA_PATH . '/planning.json');
            }
            break;
            
        case 'archives':
            // Afficher les plannings archivés
            $archive_path = DATA_PATH . '/archives';
            $plannings_archives = lister_plannings_archives($archive_path);
            
            // Traiter les actions sur les archives
            if ($_SERVER['REQUEST_METHOD'] === 'POST') {
                $action_archive = isset($_POST['action_archive']) ? sanitize_input($_POST['action_archive']) : '';
                $nom_fichier = isset($_POST['nom_fichier']) ? sanitize_input($_POST['nom_fichier']) : '';
                
                if ($action_archive === 'restaurer' && $nom_fichier) {
                    try {
                        restaurer_planning($archive_path, $nom_fichier, DATA_PATH . '/planning.json');
                        $messages[] = "✅ Planning restauré avec succès : " . $nom_fichier;
                        $planning = charger_planning(DATA_PATH . '/planning.json');
                        $plannings_archives = lister_plannings_archives($archive_path);
                    } catch (Exception $e) {
                        $erreurs[] = "❌ Erreur : " . $e->getMessage();
                    }
                } elseif ($action_archive === 'supprimer' && $nom_fichier) {
                    if (supprimer_planning_archive($archive_path, $nom_fichier)) {
                        $messages[] = "✅ Planning supprimé : " . $nom_fichier;
                        $plannings_archives = lister_plannings_archives($archive_path);
                    } else {
                        $erreurs[] = "❌ Erreur lors de la suppression du planning";
                    }
                }
            }
            break;
            
        case 'afficher':
            // Charger et afficher le planning existant
            if (file_exists(DATA_PATH . '/planning.json')) {
                $planning = charger_planning(DATA_PATH . '/planning.json');
                if (empty($messages)) { // Ne pas afficher le message s'il y en a déjà
                    $messages[] = "📅 Planning chargé : " . count($planning) . " affectations.";
                }
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
            
        case 'salles':
        case 'promotions':
        case 'cours':
        case 'options':
            // Sections de gestion - pas de message auto
            break;
            
        case 'dashboard':
        default:
            // Page d'accueil - dashboard
            if (file_exists(DATA_PATH . '/planning.json')) {
                $planning = charger_planning(DATA_PATH . '/planning.json');
                $rapport_occupation = rapport_occupation_salles($planning, $salles);
                $conflits = detecter_conflits($planning);
                
                $messages[] = "📊 Dashboard : système opérationnel.";
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
                    <?php if (isset($_SESSION['user'])): ?>
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
                        <a href="?action=archives" class="nav-link <?php echo $action === 'archives' ? 'active' : ''; ?>">
                            📦 Archives
                        </a>
                        <a href="?action=settings" class="nav-link <?php echo $action === 'settings' ? 'active' : ''; ?>">
                            👤 Mon compte
                        </a>
                        <a href="?action=logout" class="nav-link">
                            🚪 Déconnexion
                        </a>
                    <?php else: ?>
                        <a href="?action=login" class="nav-link <?php echo $action === 'login' ? 'active' : ''; ?>">
                            🔐 Connexion
                        </a>
                    <?php endif; ?>
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

            <?php elseif ($action === 'login'): ?>
                <section class="section login-section">
                    <h2>🔐 Connexion Administrateur</h2>

                    <?php if (!$pending_user): ?>
                        <form method="POST" action="?action=login" class="form-card">
                            <input type="hidden" name="form_type" value="login_password">

                            <label>Nom d'utilisateur
                                <input type="text" name="username" required>
                            </label>

                            <label>Mot de passe
                                <input type="password" name="password" required>
                            </label>

                            <button type="submit" class="btn btn-primary">Se connecter</button>
                        </form>

                        <p style="margin-top: 1rem;">Compte par défaut : <strong>admin</strong> / <strong>admin123</strong></p>
                    <?php else: ?>
                        <div class="info-card">
                            <p>Bonjour <strong><?php echo htmlspecialchars($pending_user); ?></strong>.</p>
                            <?php if ($webauthn_auth_needed): ?>
                                <p>Veuillez valider la deuxième étape de la connexion avec votre dispositif WebAuthn.</p>
                                <button id="webauthn-login-button" class="btn btn-primary">Se connecter avec WebAuthn</button>
                            <?php else: ?>
                                <p>Enregistrez un dispositif WebAuthn pour activer la double authentification.</p>
                                <button id="webauthn-register-button" class="btn btn-primary">Enregistrer un dispositif WebAuthn</button>
                            <?php endif; ?>
                        </div>
                    <?php endif; ?>
                </section>

            <?php elseif ($action === 'settings'): ?>
                <section class="section">
                    <h2>👤 Paramètres du compte</h2>

                    <p>Utilisateur connecté : <strong><?php echo htmlspecialchars($_SESSION['user']); ?></strong></p>

                    <div class="section-block">
                        <h3>Dispositifs WebAuthn enregistrés</h3>
                        <?php if (!empty($users[$_SESSION['user']]['authenticators'])): ?>
                            <ul class="list">
                                <?php foreach ($users[$_SESSION['user']]['authenticators'] as $auth): ?>
                                    <li>
                                        <?php echo htmlspecialchars($auth['name']); ?> - enregistré le <?php echo htmlspecialchars($auth['created_at']); ?>
                                    </li>
                                <?php endforeach; ?>
                            </ul>
                        <?php else: ?>
                            <p>Aucun dispositif WebAuthn n'est encore enregistré.</p>
                        <?php endif; ?>

                        <button id="webauthn-register-button" class="btn btn-primary">Enregistrer un nouveau dispositif</button>
                    </div>
                </section>

            <?php elseif ($action === 'salles'): ?>
                <!-- GESTION DES SALLES -->
                <?php $editId = isset($_GET['edit']) ? sanitize_input($_GET['edit']) : ''; ?>
                <section class="section">
                    <h2>🏢 Gestion des Salles</h2>
                    <form method="POST" action="?action=salles" class="form-add">
                        <input type="hidden" name="form_type" value="add_salle">
                        <div class="form-row">
                            <div class="form-group">
                                <label>ID Salle</label>
                                <input type="text" name="id" placeholder="ex: AUD-L1" required>
                            </div>
                            <div class="form-group">
                                <label>Désignation</label>
                                <input type="text" name="designation" placeholder="ex: Auditoire principal - L1" required>
                            </div>
                            <div class="form-group">
                                <label>Capacité</label>
                                <input type="number" name="capacite" placeholder="ex: 150" min="1" required>
                            </div>
                        </div>
                        <button type="submit" class="btn btn-primary">➕ Ajouter une salle</button>
                    </form>

                    <?php if ($editId && isset($salles[$editId])): ?>
                    <form method="POST" action="?action=salles" class="form-add mt-3">
                        <input type="hidden" name="form_type" value="edit_salle">
                        <input type="hidden" name="id" value="<?php echo htmlspecialchars($editId); ?>">
                        <h3>✏️ Modifier la salle <?php echo htmlspecialchars($editId); ?></h3>
                        <div class="form-row">
                            <div class="form-group">
                                <label>Désignation</label>
                                <input type="text" name="designation" value="<?php echo htmlspecialchars($salles[$editId]['designation']); ?>" required>
                            </div>
                            <div class="form-group">
                                <label>Capacité</label>
                                <input type="number" name="capacite" value="<?php echo htmlspecialchars($salles[$editId]['capacite']); ?>" min="1" required>
                            </div>
                        </div>
                        <button type="submit" class="btn btn-secondary">💾 Enregistrer</button>
                    </form>
                    <?php endif; ?>

                    <div class="table-wrapper mt-3">
                        <table>
                            <thead>
                                <tr>
                                    <th>ID</th>
                                    <th>Désignation</th>
                                    <th>Capacité</th>
                                    <th>Actions</th>
                                </tr>
                            </thead>
                            <tbody>
                                <?php foreach ($salles as $id => $salle): ?>
                                <tr>
                                    <td><?php echo htmlspecialchars($id); ?></td>
                                    <td><?php echo htmlspecialchars($salle['designation']); ?></td>
                                    <td><?php echo htmlspecialchars($salle['capacite']); ?> places</td>
                                    <td><a href="?action=salles&edit=<?php echo urlencode($id); ?>" class="btn btn-secondary">✏️ Modifier</a></td>
                                </tr>
                                <?php endforeach; ?>
                            </tbody>
                        </table>
                    </div>
                </section>

            <?php elseif ($action === 'promotions'): ?>
                <!-- GESTION DES PROMOTIONS -->
                <?php $editId = isset($_GET['edit']) ? sanitize_input($_GET['edit']) : ''; ?>
                <section class="section">
                    <h2>👥 Gestion des Promotions</h2>
                    <form method="POST" action="?action=promotions" class="form-add">
                        <input type="hidden" name="form_type" value="add_promotion">
                        <div class="form-row">
                            <div class="form-group">
                                <label>ID Promotion</label>
                                <input type="text" name="id" placeholder="ex: L1" required>
                            </div>
                            <div class="form-group">
                                <label>Libellé</label>
                                <input type="text" name="libelle" placeholder="ex: Licence 1" required>
                            </div>
                            <div class="form-group">
                                <label>Effectif</label>
                                <input type="number" name="effectif" placeholder="ex: 120" min="1" required>
                            </div>
                        </div>
                        <button type="submit" class="btn btn-primary">➕ Ajouter une promotion</button>
                    </form>

                    <?php if ($editId && isset($promotions[$editId])): ?>
                    <form method="POST" action="?action=promotions" class="form-add mt-3">
                        <input type="hidden" name="form_type" value="edit_promotion">
                        <input type="hidden" name="id" value="<?php echo htmlspecialchars($editId); ?>">
                        <h3>✏️ Modifier la promotion <?php echo htmlspecialchars($editId); ?></h3>
                        <div class="form-row">
                            <div class="form-group">
                                <label>Libellé</label>
                                <input type="text" name="libelle" value="<?php echo htmlspecialchars($promotions[$editId]['libelle']); ?>" required>
                            </div>
                            <div class="form-group">
                                <label>Effectif</label>
                                <input type="number" name="effectif" value="<?php echo htmlspecialchars($promotions[$editId]['effectif']); ?>" min="1" required>
                            </div>
                        </div>
                        <button type="submit" class="btn btn-secondary">💾 Enregistrer</button>
                    </form>
                    <?php endif; ?>

                    <div class="table-wrapper mt-3">
                        <table>
                            <thead>
                                <tr>
                                    <th>ID</th>
                                    <th>Libellé</th>
                                    <th>Effectif</th>
                                    <th>Actions</th>
                                </tr>
                            </thead>
                            <tbody>
                                <?php foreach ($promotions as $id => $promo): ?>
                                <tr>
                                    <td><?php echo htmlspecialchars($id); ?></td>
                                    <td><?php echo htmlspecialchars($promo['libelle']); ?></td>
                                    <td><?php echo htmlspecialchars($promo['effectif']); ?> étudiants</td>
                                    <td><a href="?action=promotions&edit=<?php echo urlencode($id); ?>" class="btn btn-secondary">✏️ Modifier</a></td>
                                </tr>
                                <?php endforeach; ?>
                            </tbody>
                        </table>
                    </div>
                </section>

            <?php elseif ($action === 'cours'): ?>
                <!-- GESTION DES COURS -->
                <?php $editId = isset($_GET['edit']) ? sanitize_input($_GET['edit']) : ''; ?>
                <section class="section">
                    <h2>📖 Gestion des Cours</h2>
                    <form method="POST" action="?action=cours" class="form-add">
                        <input type="hidden" name="form_type" value="add_cours">
                        <div class="form-row">
                            <div class="form-group">
                                <label>ID Cours</label>
                                <input type="text" name="id" placeholder="ex: C001" required>
                            </div>
                            <div class="form-group">
                                <label>Intitulé</label>
                                <input type="text" name="intitule" placeholder="ex: Circuits Logiques" required>
                            </div>
                            <div class="form-group">
                                <label>Volume Horaire</label>
                                <input type="number" name="volume_horaire" placeholder="ex: 4" min="1" required>
                            </div>
                        </div>
                        <div class="form-row">
                            <div class="form-group">
                                <label>Type</label>
                                <select name="type" required>
                                    <option value="">-- Sélectionner --</option>
                                    <option value="tronc_commun">Tronc Commun</option>
                                    <option value="option">Option</option>
                                </select>
                            </div>
                            <div class="form-group">
                                <label>Promotion</label>
                                <input type="text" name="promotion" placeholder="ex: L1 ou L3" required>
                            </div>
                        </div>
                        <button type="submit" class="btn btn-primary">➕ Ajouter un cours</button>
                    </form>

                    <?php if ($editId):
                        $coursEdit = null;
                        foreach ($cours as $item) {
                            if ($item['id'] === $editId) {
                                $coursEdit = $item;
                                break;
                            }
                        }
                    ?>
                    <?php if ($coursEdit): ?>
                    <form method="POST" action="?action=cours" class="form-add mt-3">
                        <input type="hidden" name="form_type" value="edit_cours">
                        <input type="hidden" name="id" value="<?php echo htmlspecialchars($editId); ?>">
                        <h3>✏️ Modifier le cours <?php echo htmlspecialchars($editId); ?></h3>
                        <div class="form-row">
                            <div class="form-group">
                                <label>Intitulé</label>
                                <input type="text" name="intitule" value="<?php echo htmlspecialchars($coursEdit['intitule']); ?>" required>
                            </div>
                            <div class="form-group">
                                <label>Volume Horaire</label>
                                <input type="number" name="volume_horaire" value="<?php echo htmlspecialchars($coursEdit['volume_horaire']); ?>" min="1" required>
                            </div>
                            <div class="form-group">
                                <label>Type</label>
                                <select name="type" required>
                                    <option value="tronc_commun" <?php echo $coursEdit['type'] === 'tronc_commun' ? 'selected' : ''; ?>>Tronc Commun</option>
                                    <option value="option" <?php echo $coursEdit['type'] === 'option' ? 'selected' : ''; ?>>Option</option>
                                </select>
                            </div>
                            <div class="form-group">
                                <label>Promotion</label>
                                <input type="text" name="promotion" value="<?php echo htmlspecialchars($coursEdit['promotion']); ?>" required>
                            </div>
                        </div>
                        <button type="submit" class="btn btn-secondary">💾 Enregistrer</button>
                    </form>
                    <?php endif; ?>
                    <?php endif; ?>

                    <div class="table-wrapper mt-3">
                        <table>
                            <thead>
                                <tr>
                                    <th>ID</th>
                                    <th>Intitulé</th>
                                    <th>Volume</th>
                                    <th>Type</th>
                                    <th>Promotion</th>
                                    <th>Actions</th>
                                </tr>
                            </thead>
                            <tbody>
                                <?php foreach ($cours as $item): ?>
                                <tr>
                                    <td><?php echo htmlspecialchars($item['id']); ?></td>
                                    <td><?php echo htmlspecialchars($item['intitule']); ?></td>
                                    <td><?php echo htmlspecialchars($item['volume_horaire']); ?>h</td>
                                    <td><?php echo htmlspecialchars(str_replace('_', ' ', $item['type'])); ?></td>
                                    <td><?php echo htmlspecialchars($item['promotion']); ?></td>
                                    <td><a href="?action=cours&edit=<?php echo urlencode($item['id']); ?>" class="btn btn-secondary">✏️ Modifier</a></td>
                                </tr>
                                <?php endforeach; ?>
                            </tbody>
                        </table>
                    </div>
                </section>

            <?php elseif ($action === 'options'): ?>
                <!-- GESTION DES OPTIONS -->
                <?php $editId = isset($_GET['edit']) ? sanitize_input($_GET['edit']) : ''; ?>
                <section class="section">
                    <h2>🎯 Gestion des Options</h2>
                    <form method="POST" action="?action=options" class="form-add">
                        <input type="hidden" name="form_type" value="add_option">
                        <div class="form-row">
                            <div class="form-group">
                                <label>ID Option</label>
                                <input type="text" name="id" placeholder="ex: OPT-L3-SI" required>
                            </div>
                            <div class="form-group">
                                <label>Libellé</label>
                                <input type="text" name="libelle" placeholder="ex: Sécurité Informatique" required>
                            </div>
                            <div class="form-group">
                                <label>Promotion parent</label>
                                <input type="text" name="promotion_parent" placeholder="ex: L3" required>
                            </div>
                            <div class="form-group">
                                <label>Effectif</label>
                                <input type="number" name="effectif" placeholder="ex: 25" min="1" required>
                            </div>
                        </div>
                        <button type="submit" class="btn btn-primary">➕ Ajouter une option</button>
                    </form>

                    <?php if ($editId):
                        $optionEdit = null;
                        foreach ($options as $item) {
                            if ($item['id'] === $editId) {
                                $optionEdit = $item;
                                break;
                            }
                        }
                    ?>
                    <?php if ($optionEdit): ?>
                    <form method="POST" action="?action=options" class="form-add mt-3">
                        <input type="hidden" name="form_type" value="edit_option">
                        <input type="hidden" name="id" value="<?php echo htmlspecialchars($editId); ?>">
                        <h3>✏️ Modifier l'option <?php echo htmlspecialchars($editId); ?></h3>
                        <div class="form-row">
                            <div class="form-group">
                                <label>Libellé</label>
                                <input type="text" name="libelle" value="<?php echo htmlspecialchars($optionEdit['libelle']); ?>" required>
                            </div>
                            <div class="form-group">
                                <label>Promotion parent</label>
                                <input type="text" name="promotion_parent" value="<?php echo htmlspecialchars($optionEdit['promotion_parent']); ?>" required>
                            </div>
                            <div class="form-group">
                                <label>Effectif</label>
                                <input type="number" name="effectif" value="<?php echo htmlspecialchars($optionEdit['effectif']); ?>" min="1" required>
                            </div>
                        </div>
                        <button type="submit" class="btn btn-secondary">💾 Enregistrer</button>
                    </form>
                    <?php endif; ?>
                    <?php endif; ?>

                    <div class="table-wrapper mt-3">
                        <table>
                            <thead>
                                <tr>
                                    <th>ID</th>
                                    <th>Libellé</th>
                                    <th>Promotion</th>
                                    <th>Effectif</th>
                                    <th>Actions</th>
                                </tr>
                            </thead>
                            <tbody>
                                <?php foreach ($options as $item): ?>
                                <tr>
                                    <td><?php echo htmlspecialchars($item['id']); ?></td>
                                    <td><?php echo htmlspecialchars($item['libelle']); ?></td>
                                    <td><?php echo htmlspecialchars($item['promotion_parent']); ?></td>
                                    <td><?php echo htmlspecialchars($item['effectif']); ?> étudiants</td>
                                    <td><a href="?action=options&edit=<?php echo urlencode($item['id']); ?>" class="btn btn-secondary">✏️ Modifier</a></td>
                                </tr>
                                <?php endforeach; ?>
                            </tbody>
                        </table>
                    </div>
                </section>

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

            <?php elseif ($action === 'archives'): ?>
                <!-- ARCHIVES DES PLANNINGS -->
                <section class="section">
                    <h2>📦 Archives des Plannings</h2>
                    
                    <?php if (empty($plannings_archives)): ?>
                        <div class="alert alert-info">
                            <p>Aucun planning archivé. Les plannings seront archivés automatiquement lors de la génération de nouveaux plannings.</p>
                        </div>
                    <?php else: ?>
                        <div class="table-wrapper">
                            <table>
                                <thead>
                                    <tr>
                                        <th>Date</th>
                                        <th>Affectations</th>
                                        <th>Taille</th>
                                        <th>Actions</th>
                                    </tr>
                                </thead>
                                <tbody>
                                    <?php foreach ($plannings_archives as $archive): ?>
                                        <tr>
                                            <td>
                                                <strong><?php echo htmlspecialchars($archive['date_lisible']); ?></strong>
                                                <br><small style="color: #999;"><?php echo htmlspecialchars($archive['fichier']); ?></small>
                                            </td>
                                            <td><?php echo $archive['affectations']; ?> affectations</td>
                                            <td><?php echo round($archive['taille'] / 1024, 2); ?> KB</td>
                                            <td>
                                                <form method="POST" action="?action=archives" style="display: inline;">
                                                    <input type="hidden" name="nom_fichier" value="<?php echo htmlspecialchars($archive['fichier']); ?>">
                                                    <button type="submit" name="action_archive" value="restaurer" class="btn btn-secondary" onclick="return confirm('Restaurer ce planning ? Le planning actuel sera archivé.')">
                                                        ↩️ Restaurer
                                                    </button>
                                                </form>
                                                <form method="POST" action="?action=archives" style="display: inline;">
                                                    <input type="hidden" name="nom_fichier" value="<?php echo htmlspecialchars($archive['fichier']); ?>">
                                                    <button type="submit" name="action_archive" value="supprimer" class="btn btn-danger" onclick="return confirm('Supprimer définitivement ce planning ?')">
                                                        🗑️ Supprimer
                                                    </button>
                                                </form>
                                            </td>
                                        </tr>
                                    <?php endforeach; ?>
                                </tbody>
                            </table>
                        </div>
                    <?php endif; ?>
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

    <script>
        function base64UrlToBuffer(base64url) {
            const padding = '='.repeat((4 - (base64url.length % 4)) % 4);
            const base64 = (base64url + padding).replace(/-/g, '+').replace(/_/g, '/');
            const raw = atob(base64);
            const buffer = new Uint8Array(raw.length);
            for (let i = 0; i < raw.length; ++i) {
                buffer[i] = raw.charCodeAt(i);
            }
            return buffer.buffer;
        }

        function bufferToBase64Url(buffer) {
            const bytes = new Uint8Array(buffer);
            let binary = '';
            for (let i = 0; i < bytes.byteLength; i++) {
                binary += String.fromCharCode(bytes[i]);
            }
            return btoa(binary).replace(/\+/g, '-').replace(/\//g, '_').replace(/=+$/, '');
        }

        function preparePublicKeyOptions(options) {
            if (!options || !options.publicKey) {
                throw new Error('Options WebAuthn invalides');
            }

            options.publicKey.challenge = base64UrlToBuffer(options.publicKey.challenge);
            if (options.publicKey.user && options.publicKey.user.id) {
                options.publicKey.user.id = base64UrlToBuffer(options.publicKey.user.id);
            }
            if (options.publicKey.allowCredentials) {
                options.publicKey.allowCredentials = options.publicKey.allowCredentials.map(item => ({
                    type: item.type,
                    id: base64UrlToBuffer(item.id)
                }));
            }
            return options;
        }

        async function sendWebAuthnResponse(endpoint, credential) {
            const data = {
                id: credential.id,
                rawId: bufferToBase64Url(credential.rawId),
                type: credential.type,
                response: {
                    clientDataJSON: bufferToBase64Url(credential.response.clientDataJSON)
                }
            };

            if (credential.response.attestationObject) {
                data.response.attestationObject = bufferToBase64Url(credential.response.attestationObject);
            }
            if (credential.response.authenticatorData) {
                data.response.authenticatorData = bufferToBase64Url(credential.response.authenticatorData);
            }
            if (credential.response.signature) {
                data.response.signature = bufferToBase64Url(credential.response.signature);
            }
            if (credential.response.userHandle) {
                data.response.userHandle = bufferToBase64Url(credential.response.userHandle);
            }

            const result = await fetch(endpoint, {
                method: 'POST',
                headers: {'Content-Type': 'application/json'},
                credentials: 'same-origin',
                body: JSON.stringify(data)
            });
            return result.json();
        }

        async function startWebAuthnFlow(startUrl, finishUrl) {
            if (!window.PublicKeyCredential) {
                alert('WebAuthn n\'est pas pris en charge par ce navigateur.');
                return;
            }

            const response = await fetch(startUrl, {credentials: 'same-origin'});
            if (!response.ok) {
                const error = await response.json();
                alert(error.error || 'Impossible de démarrer WebAuthn.');
                return;
            }

            const options = await response.json();
            const publicKey = preparePublicKeyOptions(options);
            const credential = await navigator.credentials[finishUrl === '?action=webauthn_register_finish' ? 'create' : 'get'](publicKey);
            const result = await sendWebAuthnResponse(finishUrl, credential);
            if (result.success) {
                window.location.href = '?action=dashboard';
            } else {
                alert(result.error || 'Échec de l’authentification WebAuthn.');
            }
        }

        document.addEventListener('DOMContentLoaded', function () {
            const registerButton = document.getElementById('webauthn-register-button');
            if (registerButton) {
                registerButton.addEventListener('click', function () {
                    startWebAuthnFlow('?action=webauthn_register_start', '?action=webauthn_register_finish');
                });
            }

            const loginButton = document.getElementById('webauthn-login-button');
            if (loginButton) {
                loginButton.addEventListener('click', function () {
                    startWebAuthnFlow('?action=webauthn_login_start', '?action=webauthn_login_finish');
                });
            }
        });
    </script>
    <script src="assets/js/script.js"></script>
</body>
</html>
