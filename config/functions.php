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
// SECTION 1B : AUTHENTIFICATION ET WEBAUTHN
// ============================================================================

/**
 * Charge les utilisateurs depuis le fichier JSON ou crée un administrateur par défaut
 * @param string $chemin_fichier
 * @return array
 */
function charger_users($chemin_fichier) {
    if (!file_exists($chemin_fichier)) {
        $users = [
            'admin' => [
                'username' => 'admin',
                'display_name' => 'Administrateur',
                'password_hash' => password_hash('admin123', PASSWORD_DEFAULT),
                'authenticators' => [],
                'created_at' => date('c')
            ]
        ];
        sauvegarder_json($users, $chemin_fichier);
        return $users;
    }

    $contenu = file_get_contents($chemin_fichier);
    if ($contenu === false) {
        throw new Exception("Erreur : Impossible de lire le fichier users.json");
    }

    $donnees = json_decode($contenu, true);
    if ($donnees === null) {
        throw new Exception("Erreur : Format JSON invalide dans users.json");
    }

    return $donnees;
}

/**
 * Sauvegarde les utilisateurs dans le fichier JSON
 * @param array $users
 * @param string $chemin_fichier
 * @return bool
 */
function sauvegarder_users($users, $chemin_fichier) {
    return sauvegarder_json($users, $chemin_fichier);
}

/**
 * Récupère un utilisateur par nom
 * @param string $username
 * @param array $users
 * @return array|null
 */
function get_user($username, $users) {
    return isset($users[$username]) ? $users[$username] : null;
}

/**
 * Vérifie le mot de passe d'un utilisateur
 * @param string $username
 * @param string $password
 * @param array $users
 * @return bool
 */
function verify_user_password($username, $password, $users) {
    $user = get_user($username, $users);
    return $user !== null && password_verify($password, $user['password_hash']);
}

/**
 * Encode en base64url
 * @param string $data
 * @return string
 */
function base64url_encode($data) {
    return rtrim(strtr(base64_encode($data), '+/', '-_'), '=');
}

/**
 * Décode une chaîne base64url
 * @param string $data
 * @return string|false
 */
function base64url_decode($data) {
    $remainder = strlen($data) % 4;
    if ($remainder) {
        $data .= str_repeat('=', 4 - $remainder);
    }
    return base64_decode(strtr($data, '-_', '+/'));
}

/**
 * Génère un challenge WebAuthn sécurisé encodé en base64url
 * @param int $length
 * @return string
 */
function generate_webauthn_challenge($length = 32) {
    return base64url_encode(random_bytes($length));
}

/**
 * Retourne l'origine actuelle du site
 * @return string
 */
function get_origin() {
    $scheme = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') ? 'https' : 'http';
    $host = $_SERVER['HTTP_HOST'] ?? 'localhost';
    return $scheme . '://' . $host;
}

/**
 * Retourne l'ID RP attendu pour WebAuthn
 * @return string
 */
function get_rp_id() {
    return $_SERVER['SERVER_NAME'] ?? 'localhost';
}

/**
 * Crée les options d'enregistrement WebAuthn pour un utilisateur
 * @param string $username
 * @param array $user
 * @return array
 */
function webauthn_registration_options($username, $user) {
    $challenge = generate_webauthn_challenge();
    $_SESSION['webauthn_reg_challenge'] = $challenge;
    $_SESSION['webauthn_challenge_user'] = $username;

    return [
        'publicKey' => [
            'challenge' => $challenge,
            'rp' => [
                'name' => 'SGA',
                'id' => get_rp_id()
            ],
            'user' => [
                'id' => base64url_encode($username),
                'name' => $username,
                'displayName' => $user['display_name'] ?? $username
            ],
            'pubKeyCredParams' => [
                ['type' => 'public-key', 'alg' => -7],
                ['type' => 'public-key', 'alg' => -257]
            ],
            'timeout' => 60000,
            'attestation' => 'none',
            'authenticatorSelection' => [
                'userVerification' => 'preferred'
            ]
        ]
    ];
}

/**
 * Crée les options d'authentification WebAuthn pour un utilisateur
 * @param string $username
 * @param array $user
 * @return array
 */
function webauthn_authentication_options($username, $user) {
    $challenge = generate_webauthn_challenge();
    $_SESSION['webauthn_auth_challenge'] = $challenge;
    $_SESSION['webauthn_challenge_user'] = $username;

    $allowCredentials = [];
    if (!empty($user['authenticators'])) {
        foreach ($user['authenticators'] as $authenticator) {
            $allowCredentials[] = [
                'type' => 'public-key',
                'id' => $authenticator['id']
            ];
        }
    }

    return [
        'publicKey' => [
            'challenge' => $challenge,
            'timeout' => 60000,
            'rpId' => get_rp_id(),
            'allowCredentials' => $allowCredentials,
            'userVerification' => 'preferred'
        ]
    ];
}

/**
 * Vérifie la réponse d'enregistrement WebAuthn et retourne un authenticator valide
 * @param array $response
 * @param string $username
 * @return array
 * @throws Exception
 */
function webauthn_verify_registration_response($response, $username) {
    if (empty($_SESSION['webauthn_reg_challenge']) || empty($_SESSION['webauthn_challenge_user'])) {
        throw new Exception('Session WebAuthn invalide.');
    }
    if ($_SESSION['webauthn_challenge_user'] !== $username) {
        throw new Exception('Utilisateur WebAuthn non valide.');
    }

    if (empty($response['id']) || empty($response['rawId']) || empty($response['response']['clientDataJSON']) || empty($response['response']['attestationObject'])) {
        throw new Exception('Réponse WebAuthn incomplète.');
    }

    $clientDataJSON = base64url_decode($response['response']['clientDataJSON']);
    $attestationObject = base64url_decode($response['response']['attestationObject']);

    if ($clientDataJSON === false || $attestationObject === false) {
        throw new Exception('Impossible de décoder les données WebAuthn.');
    }

    $clientData = json_decode($clientDataJSON, true);
    if ($clientData === null) {
        throw new Exception('ClientDataJSON invalide.');
    }

    if (($clientData['type'] ?? '') !== 'webauthn.create') {
        throw new Exception('Type WebAuthn invalide.');
    }
    if (($clientData['challenge'] ?? '') !== $_SESSION['webauthn_reg_challenge']) {
        throw new Exception('Challenge WebAuthn non valide.');
    }
    if (($clientData['origin'] ?? '') !== get_origin()) {
        throw new Exception('Origine WebAuthn non valide.');
    }

    $attestation = cbor_decode($attestationObject);
    if (!isset($attestation['authData'])) {
        throw new Exception('Attestation WebAuthn invalide.');
    }

    $authData = $attestation['authData'];
    $parsed = parse_webauthn_auth_data($authData);

    $credentialId = base64url_encode($parsed['credentialId']);
    $publicKeyPem = cose_key_to_pem($parsed['credentialPublicKey']);

    return [
        'id' => $credentialId,
        'name' => 'Dispositif WebAuthn',
        'publicKeyPem' => $publicKeyPem,
        'counter' => $parsed['signCount'],
        'created_at' => date('c')
    ];
}

/**
 * Vérifie la réponse d'authentification WebAuthn
 * @param array $response
 * @param string $username
 * @param array &$users
 * @return bool
 * @throws Exception
 */
function webauthn_verify_authentication_response($response, $username, &$users) {
    if (empty($_SESSION['webauthn_auth_challenge']) || empty($_SESSION['webauthn_challenge_user'])) {
        throw new Exception('Session WebAuthn invalide.');
    }
    if ($_SESSION['webauthn_challenge_user'] !== $username) {
        throw new Exception('Utilisateur WebAuthn non valide.');
    }

    if (empty($response['id']) || empty($response['rawId']) || empty($response['response']['clientDataJSON']) || empty($response['response']['authenticatorData']) || empty($response['response']['signature'])) {
        throw new Exception('Réponse d’authentification WebAuthn incomplète.');
    }

    $clientDataJSON = base64url_decode($response['response']['clientDataJSON']);
    $authenticatorData = base64url_decode($response['response']['authenticatorData']);
    $signature = base64url_decode($response['response']['signature']);
    $credentialId = base64url_decode($response['rawId']);

    if ($clientDataJSON === false || $authenticatorData === false || $signature === false || $credentialId === false) {
        throw new Exception('Impossible de décoder les données WebAuthn.');
    }

    $clientData = json_decode($clientDataJSON, true);
    if ($clientData === null) {
        throw new Exception('ClientDataJSON invalide.');
    }

    if (($clientData['type'] ?? '') !== 'webauthn.get') {
        throw new Exception('Type WebAuthn invalide.');
    }
    if (($clientData['challenge'] ?? '') !== $_SESSION['webauthn_auth_challenge']) {
        throw new Exception('Challenge WebAuthn non valide.');
    }
    if (($clientData['origin'] ?? '') !== get_origin()) {
        throw new Exception('Origine WebAuthn non valide.');
    }

    $user = get_user($username, $users);
    if ($user === null || empty($user['authenticators'])) {
        throw new Exception('Aucun dispositif WebAuthn enregistré.');
    }

    $credentialIdB64 = base64url_encode($credentialId);
    $authenticatorIndex = null;
    foreach ($user['authenticators'] as $index => $authenticator) {
        if ($authenticator['id'] === $credentialIdB64) {
            $authenticatorIndex = $index;
            break;
        }
    }
    if ($authenticatorIndex === null) {
        throw new Exception('Dispositif WebAuthn non reconnu.');
    }

    $storedAuthenticator = $user['authenticators'][$authenticatorIndex];
    $publicKeyPem = $storedAuthenticator['publicKeyPem'];

    $hash = hash('sha256', $clientDataJSON, true);
    $signatureBase = $authenticatorData . $hash;

    $verified = openssl_verify($signatureBase, $signature, $publicKeyPem, OPENSSL_ALGO_SHA256);
    if ($verified !== 1) {
        throw new Exception('Signature WebAuthn non valide.');
    }

    $rpIdHash = substr($authenticatorData, 0, 32);
    $expectedRpIdHash = hash('sha256', get_rp_id(), true);
    if ($rpIdHash !== $expectedRpIdHash) {
        throw new Exception('RP ID WebAuthn non valide.');
    }

    $signCount = unpack('N', substr($authenticatorData, 33, 4))[1];
    if ($signCount <= $storedAuthenticator['counter']) {
        throw new Exception('Incrémentation de compteur WebAuthn invalide.');
    }

    $users[$username]['authenticators'][$authenticatorIndex]['counter'] = $signCount;
    return true;
}

/**
 * Analyse les données d'authentificateur WebAuthn
 * @param string $authData
 * @return array
 * @throws Exception
 */
function parse_webauthn_auth_data($authData) {
    if (strlen($authData) < 55) {
        throw new Exception('authData WebAuthn trop court.');
    }

    $rpIdHash = substr($authData, 0, 32);
    $flags = ord($authData[32]);
    $signCount = unpack('N', substr($authData, 33, 4))[1];

    $attestedCredentialData = substr($authData, 37);
    $aaguid = substr($attestedCredentialData, 0, 16);
    $credentialIdLength = unpack('n', substr($attestedCredentialData, 16, 2))[1];
    $credentialId = substr($attestedCredentialData, 18, $credentialIdLength);
    $publicKeyCbor = substr($attestedCredentialData, 18 + $credentialIdLength);

    return [
        'rpIdHash' => $rpIdHash,
        'flags' => $flags,
        'signCount' => $signCount,
        'credentialId' => $credentialId,
        'credentialPublicKey' => $publicKeyCbor
    ];
}

/**
 * Décode du CBOR minimal pour WebAuthn
 * @param string $data
 * @param int $pos
 * @return mixed
 */
function cbor_decode($data, &$pos = 0) {
    if ($pos >= strlen($data)) {
        return null;
    }

    $initial = ord($data[$pos++]);
    $major = $initial >> 5;
    $additional = $initial & 0x1f;
    $length = cbor_read_length($data, $pos, $additional);

    switch ($major) {
        case 0:
            return $length;
        case 1:
            return -1 - $length;
        case 2:
            $bytes = substr($data, $pos, $length);
            $pos += $length;
            return $bytes;
        case 3:
            $string = substr($data, $pos, $length);
            $pos += $length;
            return $string;
        case 4:
            $array = [];
            for ($i = 0; $i < $length; $i++) {
                $array[] = cbor_decode($data, $pos);
            }
            return $array;
        case 5:
            $map = [];
            for ($i = 0; $i < $length; $i++) {
                $key = cbor_decode($data, $pos);
                $value = cbor_decode($data, $pos);
                $map[$key] = $value;
            }
            return $map;
        case 6:
            return cbor_decode($data, $pos);
        case 7:
            if ($additional === 20) return false;
            if ($additional === 21) return true;
            if ($additional === 22) return null;
            if ($additional === 23) return null;
            return null;
    }

    return null;
}

/**
 * Lit la longueur CBOR supplémentaire
 * @param string $data
 * @param int $pos
 * @param int $additional
 * @return int
 */
function cbor_read_length($data, &$pos, $additional) {
    if ($additional < 24) {
        return $additional;
    }
    if ($additional === 24) {
        return ord($data[$pos++]);
    }
    if ($additional === 25) {
        $value = unpack('n', substr($data, $pos, 2))[1];
        $pos += 2;
        return $value;
    }
    if ($additional === 26) {
        $value = unpack('N', substr($data, $pos, 4))[1];
        $pos += 4;
        return $value;
    }
    if ($additional === 27) {
        $high = unpack('N', substr($data, $pos, 4))[1];
        $low = unpack('N', substr($data, $pos + 4, 4))[1];
        $pos += 8;
        return ($high << 32) | $low;
    }
    throw new Exception('Taille CBOR non prise en charge');
}

/**
 * Convertit une clé COSE au format PEM pour EC2 P-256
 * @param string $coseKeyCbor
 * @return string
 * @throws Exception
 */
function cose_key_to_pem($coseKeyCbor) {
    $pos = 0;
    $coseKey = cbor_decode($coseKeyCbor, $pos);
    if (!is_array($coseKey) || !isset($coseKey[1], $coseKey[3], $coseKey[-1], $coseKey[-2], $coseKey[-3])) {
        throw new Exception('Clé COSE invalide.');
    }

    if ($coseKey[1] !== 2 || $coseKey[3] !== -7) {
        throw new Exception('Type de clé COSE non pris en charge.');
    }

    return ec_public_key_to_pem($coseKey[-2], $coseKey[-3]);
}

/**
 * Convertit une clé EC publique en PEM
 * @param string $x
 * @param string $y
 * @return string
 */
function ec_public_key_to_pem($x, $y) {
    $publicKey = "\x04" . $x . $y;
    $oid = hex2bin('301306072a8648ce3d020106082a8648ce3d030107');
    $bitString = "\x03" . encode_der_length(strlen($publicKey) + 1) . "\x00" . $publicKey;
    $sequence = "\x30" . encode_der_length(strlen($oid) + strlen($bitString)) . $oid . $bitString;
    $pem = chunk_split(base64_encode($sequence), 64, "\n");
    return "-----BEGIN PUBLIC KEY-----\n" . $pem . "-----END PUBLIC KEY-----\n";
}

/**
 * Encode une longueur DER
 * @param int $length
 * @return string
 */
function encode_der_length($length) {
    if ($length < 128) {
        return chr($length);
    }
    $hexLength = dechex($length);
    if (strlen($hexLength) % 2 === 1) {
        $hexLength = '0' . $hexLength;
    }
    $lengthBytes = hex2bin($hexLength);
    return chr(0x80 | strlen($lengthBytes)) . $lengthBytes;
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
 * Trouve une salle disponible et adaptée selon l'effectif, en priorisant la plus petite capacité possible
 * @param array $salles Salles disponibles
 * @param array $planning Planning actuel
 * @param string $creneau Créneau demandé
 * @param int $effectif Effectif du groupe
 * @return string|null ID de salle ou null
 */
function trouver_salle_disponible($salles, $planning, $creneau, $effectif) {
    // Collecter toutes les salles suffisantes et disponibles
    $salles_candidates = [];
    foreach ($salles as $id_salle => $salle) {
        if (salle_disponible($planning, $id_salle, $creneau) && capacite_suffisante($salles, $id_salle, $effectif)) {
            $salles_candidates[] = $id_salle;
        }
    }

    if (empty($salles_candidates)) {
        return null;
    }

    // Compter combien de fois chaque salle candidate est déjà utilisée dans le planning
    $usage = array_fill_keys($salles_candidates, 0);
    foreach ($planning as $aff) {
        if (isset($usage[$aff['salle']])) {
            $usage[$aff['salle']]++;
        }
    }

    // Retourner la salle candidate la moins utilisée (équilibrage)
    asort($usage);
    return array_key_first($usage);
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
 * Lundi à vendredi, 08:00-12:00 et 13:00-17:00
 * @return array Liste des créneaux au format "JOUR_HH:MM"
 */
function generer_creneaux() {
    $jours = ['Lundi', 'Mardi', 'Mercredi', 'Jeudi', 'Vendredi'];
    $heures = ['08:00', '13:00']; // 08:00-12:00 et 13:00-17:00
    $creneaux = [];
    
    foreach ($jours as $jour) {
        foreach ($heures as $heure) {
            $creneaux[] = $jour . "_" . $heure;
        }
    }
    
    return $creneaux;
}

/**
 * Fonction PRINCIPALE de génération du planning - NOUVELLE LOGIQUE
 * 
 * Stratégie améliorée :
 * 1. Trier les cours de tronc commun par promotion puis par ID
 * 2. Pour chaque promotion, pour chaque cours :
 *    - Chercher un créneau LIBRE pour ce groupe (non occupé)
 *    - Puis chercher une salle avec capacité EXACTE ou supérieure
 *    - Marquer créneau et salle comme occupés
 * 3. REMPLIR TOUS LES JOURS (Lun-Ven) en distribuant équitablement
 * 4. Ajouter les options ensuite
 * 5. Retourner planning + warnings pour tracking
 * 
 * @param array $salles Salles disponibles
 * @param array $promotions Promotions et effectifs
 * @param array $cours Cours à planifier
 * @param array $options Options avec effectifs
 * @param array $creneaux_disponibles Créneaux de la semaine
 * @return array Planning généré avec affectations et métadonnées
 */
function generer_planning($salles, $promotions, $cours, $options, $creneaux_disponibles) {
    $planning = [];
    $warnings = [];
    $non_affectes = [];
    
    // Construire la liste des cours à planifier, tronc commun puis options
    $cours_a_planifier = [];
    foreach ($cours as $c) {
        if ($c['type'] === 'tronc_commun') {
            $cours_a_planifier[] = $c;
        }
    }
    foreach ($options as $id_option => $option) {
        $cours_a_planifier[] = [
            'id' => 'OPT_' . $id_option,
            'intitule' => $option['libelle'],
            'type' => 'option',
            'promotion' => $option['promotion_parent'],
            'option_id' => $id_option
        ];
    }
    
    // Trier les cours par priorité : tronc commun d'abord, puis options
    // Mélanger aléatoirement à l'intérieur de chaque groupe pour varier le planning
    usort($cours_a_planifier, function ($a, $b) {
        if ($a['type'] === $b['type']) {
            return rand(-1, 1);
        }
        return $a['type'] === 'tronc_commun' ? -1 : 1;
    });
    
    $total_creneaux = count($creneaux_disponibles);
    $slot_depart = rand(0, $total_creneaux - 1);
    
    foreach ($cours_a_planifier as $cours_item) {
        $id_cours = $cours_item['id'];
        $id_groupe = ($cours_item['type'] === 'option') ? $cours_item['option_id'] : $cours_item['promotion'];
        $intitule = $cours_item['intitule'];
        
        if ($cours_item['type'] === 'tronc_commun') {
            $promotion_id = $cours_item['promotion'];
            if (!isset($promotions[$promotion_id])) {
                $warnings[] = "⚠️ Promotion {$promotion_id} introuvable pour cours {$id_cours}";
                continue;
            }
            $effectif = $promotions[$promotion_id]['effectif'];
        } else {
            $option_id = $cours_item['option_id'];
            if (!isset($options[$option_id])) {
                $warnings[] = "⚠️ Option {$option_id} introuvable pour la planification";
                continue;
            }
            $effectif = $options[$option_id]['effectif'];
        }
        
        // Vérifier qu'il existe une salle adaptée en capacité
        $salle_adaptee = null;
        foreach ($salles as $id_salle => $salle) {
            if ($effectif <= $salle['capacite']) {
                $salle_adaptee = $id_salle;
                break;
            }
        }
        if ($salle_adaptee === null) {
            $non_affectes[] = "⚠️ {$intitule} ({$id_groupe}, {$effectif} étudiants) : aucune salle de capacité suffisante.";
            continue;
        }
        
        $affecte = false;
        for ($decalage = 0; $decalage < $total_creneaux; $decalage++) {
            $index = ($slot_depart + $decalage) % $total_creneaux;
            $creneau = $creneaux_disponibles[$index];

            if (!creneau_libre_groupe($planning, $id_groupe, $creneau)) {
                continue;
            }
            
            $id_salle = trouver_salle_disponible($salles, $planning, $creneau, $effectif);
            if ($id_salle === null) {
                continue;
            }
            
            $planning[] = [
                'creneau' => $creneau,
                'salle' => $id_salle,
                'cours' => $id_cours,
                'groupe' => $id_groupe,
                'effectif' => $effectif,
                'intitule_cours' => $intitule
            ];
            $affecte = true;
            $slot_depart = ($index + 1) % $total_creneaux;
            break;
        }
        
        if (!$affecte) {
            $non_affectes[] = "⚠️ {$intitule} ({$id_groupe}) : aucun créneau disponible.";
        }
    }
    
    $warnings = array_merge($warnings, $non_affectes);
    
    $jours_couverts = [];
    foreach ($planning as $aff) {
        $jour = explode('_', $aff['creneau'])[0];
        $jours_couverts[$jour] = true;
    }
    
    return [
        'planning' => $planning,
        'warnings' => $warnings,
        'jours_couverts' => count($jours_couverts),
        'total_creneaux' => count($planning)
    ];
}

// ============================================================================
// SECTION 4 : ARCHIVAGE ET GESTION DES PLANNINGS
// ============================================================================

/**
 * Archive le planning existant avant d'en créer un nouveau
 * @param string $chemin_fichier Chemin vers planning.json
 * @param string $chemin_archive Chemin du dossier archives
 * @return bool Succès
 */
function archiver_planning($chemin_fichier, $chemin_archive) {
    // Créer le dossier archives s'il n'existe pas
    if (!is_dir($chemin_archive)) {
        mkdir($chemin_archive, 0755, true);
    }
    
    // Si le planning existe, l'archiver avec timestamp
    if (file_exists($chemin_fichier)) {
        $timestamp = date('Y-m-d_H-i-s');
        $nom_archive = 'planning_' . $timestamp . '.json';
        $chemin_archive_fichier = $chemin_archive . '/' . $nom_archive;
        
        return copy($chemin_fichier, $chemin_archive_fichier) !== false;
    }
    
    return true;
}

/**
 * Liste tous les plannings archivés
 * @param string $chemin_archive Chemin du dossier archives
 * @return array Liste des plannings avec infos
 */
function lister_plannings_archives($chemin_archive) {
    $plannings = [];
    
    if (!is_dir($chemin_archive)) {
        return $plannings;
    }
    
    $fichiers = scandir($chemin_archive);
    foreach ($fichiers as $fichier) {
        if (preg_match('/^planning_(\d{4}-\d{2}-\d{2}_\d{2}-\d{2}-\d{2})\.json$/', $fichier, $matches)) {
            $chemin_complet = $chemin_archive . '/' . $fichier;
            $contenu = file_get_contents($chemin_complet);
            $data = json_decode($contenu, true);
            
            $plannings[] = [
                'fichier' => $fichier,
                'timestamp' => $matches[1],
                'date_lisible' => str_replace('_', ' ', $matches[1]),
                'affectations' => count($data ?? []),
                'taille' => filesize($chemin_complet)
            ];
        }
    }
    
    // Trier par timestamp décroissant (plus récents d'abord)
    usort($plannings, function($a, $b) {
        return strcmp($b['timestamp'], $a['timestamp']);
    });
    
    return $plannings;
}

/**
 * Charge un planning archivé
 * @param string $chemin_archive Chemin du dossier archives
 * @param string $nom_fichier Nom du fichier à charger
 * @return array Planning chargé
 */
function charger_planning_archive($chemin_archive, $nom_fichier) {
    // Sécurité : vérifier que le fichier est dans le bon dossier et a le bon format
    if (!preg_match('/^planning_\d{4}-\d{2}-\d{2}_\d{2}-\d{2}-\d{2}\.json$/', $nom_fichier)) {
        throw new Exception("Nom de fichier invalide");
    }
    
    $chemin_complet = $chemin_archive . '/' . $nom_fichier;
    
    if (!file_exists($chemin_complet)) {
        throw new Exception("Fichier archive introuvable");
    }
    
    $contenu = file_get_contents($chemin_complet);
    if ($contenu === false) {
        throw new Exception("Impossible de lire le fichier archive");
    }
    
    $planning = json_decode($contenu, true);
    if ($planning === null) {
        throw new Exception("Format JSON invalide dans l'archive");
    }
    
    return $planning;
}

/**
 * Restaure un planning archivé en tant que planning actuel
 * @param string $chemin_archive Chemin du dossier archives
 * @param string $nom_fichier Nom du fichier à restaurer
 * @param string $chemin_planning_actuel Chemin du planning actuel
 * @return bool Succès
 */
function restaurer_planning($chemin_archive, $nom_fichier, $chemin_planning_actuel) {
    $planning = charger_planning_archive($chemin_archive, $nom_fichier);
    
    // Archiver le planning actuel avant de le remplacer
    archiver_planning($chemin_planning_actuel, $chemin_archive);
    
    // Écrire le planning restauré
    $json = json_encode($planning, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE);
    return file_put_contents($chemin_planning_actuel, $json) !== false;
}

/**
 * Supprime un planning archivé
 * @param string $chemin_archive Chemin du dossier archives
 * @param string $nom_fichier Nom du fichier à supprimer
 * @return bool Succès
 */
function supprimer_planning_archive($chemin_archive, $nom_fichier) {
    // Sécurité
    if (!preg_match('/^planning_\d{4}-\d{2}-\d{2}_\d{2}-\d{2}-\d{2}\.json$/', $nom_fichier)) {
        return false;
    }
    
    $chemin_complet = $chemin_archive . '/' . $nom_fichier;
    
    if (file_exists($chemin_complet)) {
        return unlink($chemin_complet);
    }
    
    return false;
}

// ============================================================================
// SECTION 4B : SAUVEGARDE DU PLANNING
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
    $heures = ['08:00', '13:00'];
    
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
        // Calculer heure de fin: 08:00->12:00, 13:00->17:00
        $heure_debut = intval(substr($heure, 0, 2));
        $heure_fin = ($heure_debut === 8) ? 12 : 17;
        $heures_affichage = $heure . ' - ' . str_pad($heure_fin, 2, '0', STR_PAD_LEFT) . ':00';
        
        $html .= '<tr><td class="horaire">' . $heures_affichage . '</td>';
        
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
    $creneaux_total = 10; // 5 jours × 2 créneaux (08:00 et 13:00)
    
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
