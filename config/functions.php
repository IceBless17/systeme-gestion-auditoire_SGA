<?php
/**
 * SYSTÈME DE GESTION DES AUDITOIRES (SGA)
 * Fonctions PHP procédurales pour la gestion des salles, promotions et plannings
 * 
 * Ce fichier contient toutes les fonctions nécessaires :
 * - Chargement des données (salles, promotions, cours, options)
 * - Validation des contraintes métier
 * - Génération du planning automatique
 * - Sauvegarde et affichage du planning
 */

// ============================================================================
// SECTION 1 : CHARGEMENT DES FICHIERS DE DONNÉES
// ============================================================================

/**
 * Charge les données des salles depuis le fichier JSON
 * @param string $chemin_fichier Chemin vers salles.json
 * @return array Tableau associatif des salles indexé par ID
 * @throws Exception Si fichier introuvable ou JSON malformé
 */
function charger_salles($chemin_fichier) {
    if (!file_exists($chemin_fichier)) {
        throw new Exception("Erreur : Fichier salles introuvable ($chemin_fichier)");
    }
    
    $contenu = file_get_contents($chemin_fichier);
    if ($contenu === false) {
        throw new Exception("Erreur : Impossible de lire le fichier salles.json");
    }
    
    $donnees = json_decode($contenu, true);
    if ($donnees === null) {
        throw new Exception("Erreur : Format JSON invalide dans salles.json");
    }
    
    $salles = [];
    foreach ($donnees as $salle) {
        if (empty($salle['id']) || empty($salle['capacite'])) {
            throw new Exception("Erreur : Champ manquant dans une salle");
        }
        $salles[$salle['id']] = $salle;
    }
    
    return $salles;
}

/**
 * Charge les promotions depuis le fichier JSON
 * @param string $chemin_fichier Chemin vers promotions.json
 * @return array Tableau associatif des promotions indexé par ID
 */
function charger_promotions($chemin_fichier) {
    if (!file_exists($chemin_fichier)) {
        throw new Exception("Erreur : Fichier promotions introuvable");
    }
    
    $contenu = file_get_contents($chemin_fichier);
    if ($contenu === false) {
        throw new Exception("Erreur : Impossible de lire le fichier promotions.json");
    }
    
    $donnees = json_decode($contenu, true);
    if ($donnees === null) {
        throw new Exception("Erreur : Format JSON invalide dans promotions.json");
    }
    
    $promotions = [];
    foreach ($donnees as $promo) {
        if (empty($promo['id']) || empty($promo['effectif'])) {
            throw new Exception("Erreur : Champ manquant dans une promotion");
        }
        $promotions[$promo['id']] = $promo;
    }
    
    return $promotions;
}

/**
 * Charge les cours depuis le fichier JSON
 * @param string $chemin_fichier Chemin vers cours.json
 * @return array Tableau des cours
 */
function charger_cours($chemin_fichier) {
    if (!file_exists($chemin_fichier)) {
        throw new Exception("Erreur : Fichier cours introuvable");
    }
    
    $contenu = file_get_contents($chemin_fichier);
    if ($contenu === false) {
        throw new Exception("Erreur : Impossible de lire le fichier cours.json");
    }
    
    $donnees = json_decode($contenu, true);
    if ($donnees === null) {
        throw new Exception("Erreur : Format JSON invalide dans cours.json");
    }
    
    foreach ($donnees as $cours) {
        if (empty($cours['id']) || empty($cours['intitule']) || empty($cours['type'])) {
            throw new Exception("Erreur : Champ manquant dans un cours");
        }
    }
    
    return $donnees;
}

/**
 * Charge les options depuis le fichier JSON
 * @param string $chemin_fichier Chemin vers options.json
 * @return array Tableau associatif des options
 */
function charger_options($chemin_fichier) {
    if (!file_exists($chemin_fichier)) {
        throw new Exception("Erreur : Fichier options introuvable");
    }
    
    $contenu = file_get_contents($chemin_fichier);
    if ($contenu === false) {
        throw new Exception("Erreur : Impossible de lire le fichier options.json");
    }
    
    $donnees = json_decode($contenu, true);
    if ($donnees === null) {
        throw new Exception("Erreur : Format JSON invalide dans options.json");
    }
    
    $options = [];
    foreach ($donnees as $option) {
        if (empty($option['id']) || empty($option['effectif'])) {
            throw new Exception("Erreur : Champ manquant dans une option");
        }
        $options[$option['id']] = $option;
    }
    
    return $options;
}

// ============================================================================
// SECTION 2 : VÉRIFICATION DES CONTRAINTES MÉTIER
// ============================================================================

/**
 * Vérifie si une salle est disponible pour un créneau donné
 * @param array $planning Planning actuel
 * @param string $id_salle ID de la salle
 * @param string $creneau Créneau (format: "JOUR_HH:MM")
 * @return bool true si salle libre, false sinon
 */
function salle_disponible($planning, $id_salle, $creneau) {
    foreach ($planning as $affectation) {
        if ($affectation['salle'] === $id_salle && $affectation['creneau'] === $creneau) {
            return false; // Salle occupée
        }
    }
    return true; // Salle libre
}

/**
 * Vérifie si la capacité d'une salle est suffisante pour un groupe
 * @param array $salles Tableau des salles
 * @param string $id_salle ID de la salle
 * @param int $effectif Effectif du groupe
 * @return bool true si capacité suffisante, false sinon
 */
function capacite_suffisante($salles, $id_salle, $effectif) {
    if (!isset($salles[$id_salle])) {
        return false;
    }
    return $effectif <= $salles[$id_salle]['capacite'];
}

/**
 * Vérifie si un groupe (promotion/option) est libre sur un créneau
 * @param array $planning Planning actuel
 * @param string $id_groupe ID du groupe
 * @param string $creneau Créneau
 * @return bool true si groupe libre, false sinon
 */
function creneau_libre_groupe($planning, $id_groupe, $creneau) {
    foreach ($planning as $affectation) {
        if ($affectation['groupe'] === $id_groupe && $affectation['creneau'] === $creneau) {
            return false; // Groupe occupé ce créneau
        }
    }
    return true; // Groupe libre
}

// ============================================================================
// SECTION 3 : GÉNÉRATION DU PLANNING
// ============================================================================

/**
 * Génère les créneaux disponibles pour la semaine
 * Lundi à vendredi, 8h-17h, blocs de 4h
 * @return array Liste des créneaux au format "JOUR_HH:MM"
 */
function generer_creneaux() {
    $jours = ['Lundi', 'Mardi', 'Mercredi', 'Jeudi', 'Vendredi'];
    $heures = ['08:00', '12:00'];
    $creneaux = [];
    
    foreach ($jours as $jour) {
        foreach ($heures as $heure) {
            $creneaux[] = $jour . "_" . $heure;
        }
    }
    
    return $creneaux;
}

/**
 * Fonction PRINCIPALE de génération du planning
 * 
 * Stratégie d'affectation :
 * 1. Trier les cours par promotion et par type (tronc commun d'abord)
 * 2. Pour chaque cours, identifier le groupe concerné et son effectif
 * 3. Pour chaque créneau disponible :
 *    - Vérifier que le groupe est libre
 *    - Chercher une salle appropriée (libre + capacité suffisante)
 *    - Affecter le cours à la salle
 * 4. Éviter les collisions et respecter les contraintes de capacité
 * 
 * @param array $salles Salles disponibles
 * @param array $promotions Promotions et effectifs
 * @param array $cours Cours à planifier
 * @param array $options Options avec effectifs
 * @param array $creneaux_disponibles Créneaux de la semaine
 * @return array Planning généré avec affectations
 */
function generer_planning($salles, $promotions, $cours, $options, $creneaux_disponibles) {
    $planning = [];
    $index_creneau = 0; // Pointeur sur les créneaux
    
    // Séparer tronc commun et options, trier par promotion
    $cours_tries = [];
    
    // D'abord les cours de tronc commun
    foreach ($cours as $c) {
        if ($c['type'] === 'tronc_commun') {
            $cours_tries[] = $c;
        }
    }
    
    // Ensuite les cours d'option
    foreach ($cours as $c) {
        if ($c['type'] === 'option') {
            $cours_tries[] = $c;
        }
    }
    
    // Assigner chaque cours
    foreach ($cours_tries as $cours_item) {
        $id_cours = $cours_item['id'];
        
        // Déterminer le groupe et son effectif
        if ($cours_item['type'] === 'tronc_commun') {
            // Tronc commun : c'est toute la promotion
            $id_promotion = $cours_item['promotion'];
            $effectif = $promotions[$id_promotion]['effectif'];
            $id_groupe = $id_promotion; // Groupe = promotion entière
        } else {
            // Option : c'est un groupe d'option spécifique
            // On suppose qu'il y a un cours par option dans le fichier cours.json
            // En réalité, il faudrait mapper les options aux cours d'option
            // Pour simplifier : utiliser les options
            continue; // Les options seront gérées séparément
        }
        
        // Chercher un créneau libre pour ce groupe
        for ($i = 0; $i < count($creneaux_disponibles); $i++) {
            $creneau = $creneaux_disponibles[$i];
            
            // Vérifier si le groupe est libre ce créneau
            if (!creneau_libre_groupe($planning, $id_groupe, $creneau)) {
                continue; // Groupe occupé, passer au créneau suivant
            }
            
            // Chercher une salle appropriée
            $salle_trouvee = null;
            foreach ($salles as $id_salle => $salle) {
                // Vérifier disponibilité et capacité
                if (salle_disponible($planning, $id_salle, $creneau) &&
                    capacite_suffisante($salles, $id_salle, $effectif)) {
                    $salle_trouvee = $id_salle;
                    break; // Première salle appropriée
                }
            }
            
            if ($salle_trouvee) {
                // Affectation réussie
                $planning[] = [
                    'creneau' => $creneau,
                    'salle' => $salle_trouvee,
                    'cours' => $id_cours,
                    'groupe' => $id_groupe,
                    'effectif' => $effectif,
                    'intitule_cours' => $cours_item['intitule']
                ];
                break; // Cours assigné, passer au suivant
            }
        }
    }
    
    // Ajouter les cours d'option
    foreach ($options as $id_option => $option) {
        $effectif = $option['effectif'];
        $id_groupe = $id_option;
        
        // Chercher les cours associés à cette option
        $cours_option = null;
        foreach ($cours as $c) {
            if ($c['type'] === 'option' && isset($c['promotion'])) {
                if ($c['promotion'] === $option['promotion_parent']) {
                    $cours_option = $c;
                    break;
                }
            }
        }
        
        if (!$cours_option) {
            continue;
        }
        
        // Chercher un créneau
        for ($i = 0; $i < count($creneaux_disponibles); $i++) {
            $creneau = $creneaux_disponibles[$i];
            
            if (!creneau_libre_groupe($planning, $id_groupe, $creneau)) {
                continue;
            }
            
            // Chercher salle (petite capacité pour les TP)
            $salle_trouvee = null;
            foreach ($salles as $id_salle => $salle) {
                if (salle_disponible($planning, $id_salle, $creneau) &&
                    capacite_suffisante($salles, $id_salle, $effectif)) {
                    $salle_trouvee = $id_salle;
                    break;
                }
            }
            
            if ($salle_trouvee) {
                $planning[] = [
                    'creneau' => $creneau,
                    'salle' => $salle_trouvee,
                    'cours' => $cours_option['id'],
                    'groupe' => $id_groupe,
                    'effectif' => $effectif,
                    'intitule_cours' => $option['libelle']
                ];
                break;
            }
        }
    }
    
    return $planning;
}

// ============================================================================
// SECTION 4 : SAUVEGARDE DU PLANNING
// ============================================================================

/**
 * Sauvegarde le planning généré dans un fichier JSON
 * @param array $planning Planning à sauvegarder
 * @param string $chemin_fichier Chemin vers le fichier de sortie
 * @return bool true si succès, false sinon
 */
function sauvegarder_planning($planning, $chemin_fichier) {
    $json = json_encode($planning, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE);
    
    if ($json === false) {
        return false;
    }
    
    if (file_put_contents($chemin_fichier, $json) === false) {
        return false;
    }
    
    return true;
}

/**
 * Sauvegarde n'importe quel tableau en JSON
 * @param array $donnees
 * @param string $chemin_fichier
 * @return bool
 */
function sauvegarder_json(array $donnees, $chemin_fichier) {
    $json = json_encode($donnees, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE);
    if ($json === false) {
        return false;
    }
    return file_put_contents($chemin_fichier, $json) !== false;
}

/**
 * Charge le planning depuis un fichier JSON
 * @param string $chemin_fichier Chemin vers planning.json
 * @return array Planning chargé
 */
function charger_planning($chemin_fichier) {
    if (!file_exists($chemin_fichier)) {
        throw new Exception("Erreur : Fichier planning introuvable");
    }
    
    $contenu = file_get_contents($chemin_fichier);
    if ($contenu === false) {
        throw new Exception("Erreur : Impossible de lire le fichier planning.json");
    }
    
    $planning = json_decode($contenu, true);
    if ($planning === null) {
        throw new Exception("Erreur : Format JSON invalide dans planning.json");
    }
    
    return $planning;
}

// ============================================================================
// SECTION 5 : AFFICHAGE HTML DU PLANNING
// ============================================================================

/**
 * Affiche le planning sous forme de tableau HTML
 * Jours en colonnes, créneaux en lignes
 * @param array $planning Planning à afficher
 * @param array $salles Données des salles (pour affichage)
 * @return string HTML du tableau
 */
function afficher_planning_html($planning, $salles = []) {
    $jours = ['Lundi', 'Mardi', 'Mercredi', 'Jeudi', 'Vendredi'];
    $heures = ['08:00', '12:00'];
    
    // Indexer le planning par créneau pour accès rapide
    $planning_index = [];
    foreach ($planning as $affectation) {
        $planning_index[$affectation['creneau']][] = $affectation;
    }
    
    $html = '<table class="planning-table">';
    $html .= '<thead><tr><th>Horaire</th>';
    
    foreach ($jours as $jour) {
        $html .= '<th>' . $jour . '</th>';
    }
    $html .= '</tr></thead><tbody>';
    
    // Pour chaque créneau horaire
    foreach ($heures as $heure) {
        $html .= '<tr><td class="horaire">' . $heure . ' - ' . (intval(substr($heure, 0, 2)) + 4) . ':00</td>';
        
        foreach ($jours as $jour) {
            $creneau_key = $jour . "_" . $heure;
            $html .= '<td class="creneau">';
            
            if (isset($planning_index[$creneau_key])) {
                foreach ($planning_index[$creneau_key] as $affectation) {
                    $nom_salle = isset($salles[$affectation['salle']]) 
                        ? $salles[$affectation['salle']]['id'] 
                        : $affectation['salle'];
                    
                    $html .= '<div class="affectation" title="' . htmlspecialchars($affectation['intitule_cours']) . '">';
                    $html .= '<strong>' . htmlspecialchars($affectation['intitule_cours']) . '</strong><br>';
                    $html .= '<small>Salle: ' . htmlspecialchars($nom_salle) . '</small><br>';
                    $html .= '<small>Groupe: ' . htmlspecialchars($affectation['groupe']) . '</small><br>';
                    $html .= '<small>(' . $affectation['effectif'] . ' pers.)</small>';
                    $html .= '</div>';
                }
            }
            
            $html .= '</td>';
        }
        $html .= '</tr>';
    }
    
    $html .= '</tbody></table>';
    return $html;
}

// ============================================================================
// SECTION 6 : RAPPORTS ET STATISTIQUES
// ============================================================================

/**
 * Détecte tous les conflits dans le planning
 * @param array $planning Planning à analyser
 * @return array Liste des conflits détectés
 */
function detecter_conflits($planning) {
    $conflits = [];
    
    // Vérifier les collisions de salles (même salle, même créneau)
    $salles_creneaux = [];
    foreach ($planning as $aff) {
        $key = $aff['salle'] . "|" . $aff['creneau'];
        if (isset($salles_creneaux[$key])) {
            $conflits[] = [
                'type' => 'collision_salle',
                'message' => "Collision salle {$aff['salle']} à {$aff['creneau']}",
                'salle' => $aff['salle'],
                'creneau' => $aff['creneau']
            ];
        }
        $salles_creneaux[$key] = true;
    }
    
    // Vérifier les groupes en deux places simultanément
    $groupes_creneaux = [];
    foreach ($planning as $aff) {
        $key = $aff['groupe'] . "|" . $aff['creneau'];
        if (isset($groupes_creneaux[$key])) {
            $conflits[] = [
                'type' => 'groupe_double',
                'message' => "Groupe {$aff['groupe']} en deux salles à {$aff['creneau']}",
                'groupe' => $aff['groupe'],
                'creneau' => $aff['creneau']
            ];
        }
        $groupes_creneaux[$key] = true;
    }
    
    return $conflits;
}

/**
 * Génère un rapport d'occupation des salles
 * @param array $planning Planning
 * @param array $salles Données des salles
 * @return array Rapport d'occupation par salle
 */
function rapport_occupation_salles($planning, $salles) {
    $rapport = [];
    $creneaux_total = 10; // 5 jours × 2 créneaux
    
    foreach ($salles as $id_salle => $salle) {
        $occupee = 0;
        foreach ($planning as $aff) {
            if ($aff['salle'] === $id_salle) {
                $occupee++;
            }
        }
        
        $taux = ($occupee / $creneaux_total) * 100;
        
        $rapport[$id_salle] = [
            'designation' => $salle['designation'],
            'capacite' => $salle['capacite'],
            'creneaux_occupes' => $occupee,
            'creneaux_libres' => $creneaux_total - $occupee,
            'taux_occupation' => round($taux, 2)
        ];
    }
    
    return $rapport;
}

/**
 * Sauvegarde le rapport d'occupation dans un fichier texte
 * @param array $rapport Rapport généré
 * @param string $chemin_fichier Chemin du fichier de sortie
 * @return bool Succès
 */
function sauvegarder_rapport_occupation($rapport, $chemin_fichier) {
    $contenu = "========================================\n";
    $contenu .= "RAPPORT D'OCCUPATION DES SALLES\n";
    $contenu .= "Généré le : " . date('Y-m-d H:i:s') . "\n";
    $contenu .= "========================================\n\n";
    
    foreach ($rapport as $id_salle => $stats) {
        $contenu .= "SALLE : " . $stats['designation'] . " ($id_salle)\n";
        $contenu .= "  Capacité : " . $stats['capacite'] . " places\n";
        $contenu .= "  Créneaux occupés : " . $stats['creneaux_occupes'] . "\n";
        $contenu .= "  Créneaux libres : " . $stats['creneaux_libres'] . "\n";
        $contenu .= "  Taux d'occupation : " . $stats['taux_occupation'] . "%\n";
        $contenu .= "----------------------------------------\n\n";
    }
    
    return file_put_contents($chemin_fichier, $contenu) !== false;
}
