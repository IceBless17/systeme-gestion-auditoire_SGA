# 🎯 Documentation Technique - Algorithme de Génération du Planning

## Vue d'ensemble

Le système SGA utilise un **algorithme d'affectation glouton** (greedy algorithm) pour générer le planning hebdomadaire sans conflits.

## 1️⃣ Entrées

```
Salles              → 6 espaces (AUD-L1, AUD-L2, AUD-L3, AUD-L4, SALLE-MACH, SALLE-MGT)
Promotions          → 4 groupes principaux (L1, L2, L3, L4) avec effectifs
Cours               → 10 cours (8 tronc commun + 2 options majeures)
Options             → 6 sous-groupes d'option (L3 et L4 uniquement)
Créneaux Hebdo      → 10 slots (5 jours × 2 créneaux/jour : 8h-12h, 12h-17h)
```

## 2️⃣ Processus d'Affectation

### Phase 1 : Préparation

```php
// Étape 1 : Générer les créneaux disponibles
creneaux = [
    "Lundi_08:00",
    "Lundi_12:00",
    "Mardi_08:00",
    ...
    "Vendredi_12:00"
]

// Étape 2 : Trier les cours
cours_tries = [];
Ajouter tous les cours de tronc_commun d'abord
Ajouter tous les cours d'option ensuite

// Étape 3 : Initialiser le planning
planning = []
```

### Phase 2 : Boucle d'Affectation

Pour **chaque cours** dans `cours_tries` :

```
1. IDENTIFIER LE GROUPE & L'EFFECTIF
   ├─ Si tronc_commun
   │  ├─ groupe = promotion entière (ex: "L1")
   │  └─ effectif = effectif_promotion (ex: 120)
   │
   └─ Si option
      ├─ groupe = option_id (ex: "OPT-L3-SI")
      └─ effectif = effectif_option (ex: 25)

2. CHERCHER UN CRÉNEAU LIBRE
   Pour chaque créneau dans creneaux_disponibles :
      ├─ Vérifier : creneau_libre_groupe(planning, groupe, créneau) ?
      │  └─ Si NON → passer créneau suivant
      │
      └─ Si OUI → aller phase 3

3. CHERCHER UNE SALLE APPROPRIÉE
   Pour chaque salle dans salles (triées par ID) :
      ├─ Vérifier : salle_disponible(planning, salle, créneau) ?
      │  └─ Si NON → salle suivante
      │
      ├─ Vérifier : capacite_suffisante(salles, salle, effectif) ?
      │  ├─ Si NON → salle suivante
      │  └─ Si OUI → SALLE TROUVÉE
      │
      └─ Créer affectation

4. CRÉER L'AFFECTATION
   affectation = {
       creneau: "Lundi_08:00",
       salle: "AUD-L1",
       cours: "C001",
       groupe: "L1",
       effectif: 120,
       intitule: "Circuits Logiques"
   }
   planning.push(affectation)
   BREAK (cours affecté, passer au suivant)

5. SI AUCUNE SALLE TROUVÉE
   → Cours non affecté (WARNING - pas grave)
   → BREAK et passer au cours suivant
```

## 3️⃣ Contraintes Métier

### Contrainte 1 : Capacité (OBLIGATOIRE)
```
RÈGLE : effectif_groupe ≤ capacite_salle

EXEMPLE :
  Groupe L1 = 120 étudiants
  Salle AUD-L1 = 120 places → ✓ OK
  Salle SALLE-MACH = 30 places → ✗ REFUSÉ
```

### Contrainte 2 : Disponibilité Salle
```
RÈGLE : Une salle ne peut accueillir qu'un groupe par créneau

EXEMPLE :
  Lundi 08:00 → AUD-L1 affectée au Cours C001
  Lundi 08:00 → AUD-L1 ne peut pas accueillir C002
  
  MAIS :
  Lundi 08:00 → AUD-L2 peut accueillir C002
```

### Contrainte 3 : Disponibilité Groupe
```
RÈGLE : Un groupe ne peut suivre qu'un cours par créneau

EXEMPLE :
  Lundi 08:00 → Promotion L1 suit Circuits Logiques en AUD-L1
  Lundi 08:00 → L1 ne peut pas suivre un autre cours
  
  MAIS :
  Lundi 12:00 → L1 peut suivre Réseaux en AUD-L2
```

## 4️⃣ Fonction Principale

```php
function generer_planning($salles, $promotions, $cours, $options, $creneaux) {
    
    $planning = [];
    $cours_tries = [];
    
    // Étape 1 : Trier cours
    foreach ($cours as $c) {
        if ($c['type'] === 'tronc_commun') {
            $cours_tries[] = $c;
        }
    }
    foreach ($cours as $c) {
        if ($c['type'] === 'option') {
            $cours_tries[] = $c;
        }
    }
    
    // Étape 2 : Affecter chaque cours
    foreach ($cours_tries as $cours_item) {
        
        // Identifier groupe & effectif
        if ($cours_item['type'] === 'tronc_commun') {
            $id_promotion = $cours_item['promotion'];
            $effectif = $promotions[$id_promotion]['effectif'];
            $id_groupe = $id_promotion;
        } else {
            continue; // Options gérées séparément
        }
        
        // Chercher créneau libre
        for ($i = 0; $i < count($creneaux); $i++) {
            $creneau = $creneaux[$i];
            
            if (!creneau_libre_groupe($planning, $id_groupe, $creneau)) {
                continue;
            }
            
            // Chercher salle
            $salle_trouvee = null;
            foreach ($salles as $id_salle => $salle) {
                if (salle_disponible($planning, $id_salle, $creneau) &&
                    capacite_suffisante($salles, $id_salle, $effectif)) {
                    $salle_trouvee = $id_salle;
                    break;
                }
            }
            
            if ($salle_trouvee) {
                // Créer affectation
                $planning[] = [
                    'creneau' => $creneau,
                    'salle' => $salle_trouvee,
                    'cours' => $cours_item['id'],
                    'groupe' => $id_groupe,
                    'effectif' => $effectif,
                    'intitule_cours' => $cours_item['intitule']
                ];
                break; // Cours affecté
            }
        }
    }
    
    return $planning;
}
```

## 5️⃣ Exemple d'Exécution Pas à Pas

### État Initial

```
SALLES :
  AUD-L1  (120 places)
  AUD-L2  (100 places)
  SALLE-MACH (30 places)

PROMOTIONS :
  L1 (120 étudiants)
  L2 (100 étudiants)

COURS TRONC COMMUN :
  C001: Circuits Logiques (4h) → L1
  C003: Systèmes d'exploitation (4h) → L2

CRENEAUX :
  Lundi_08:00
  Lundi_12:00
  ...
```

### Affectation du Cours C001

```
Cours: C001 (Circuits Logiques, promotion L1)
Groupe: L1
Effectif: 120

1. Chercher créneau libre pour L1
   → Lundi_08:00 ? OUI (aucun cours L1 à ce créneau)

2. Chercher salle appropriée pour Lundi_08:00
   → AUD-L1 ?
      - Disponible ? OUI (aucun cours à ce créneau)
      - Capacité (120) >= Effectif (120) ? OUI ✓
   → AFFECTATION : C001 en AUD-L1 le Lundi_08:00

RÉSULTAT :
  planning[0] = {
      creneau: "Lundi_08:00",
      salle: "AUD-L1",
      cours: "C001",
      groupe: "L1",
      effectif: 120,
      intitule_cours: "Circuits Logiques"
  }
```

### Affectation du Cours C003

```
Cours: C003 (Systèmes d'exploitation, promotion L2)
Groupe: L2
Effectif: 100

1. Chercher créneau libre pour L2
   → Lundi_08:00 ? OUI (aucun cours L2 à ce créneau)

2. Chercher salle appropriée pour Lundi_08:00
   → AUD-L1 ?
      - Disponible ? NON ✗ (L1 occupe déjà)
   → AUD-L2 ?
      - Disponible ? OUI
      - Capacité (100) >= Effectif (100) ? OUI ✓
   → AFFECTATION : C003 en AUD-L2 le Lundi_08:00

RÉSULTAT :
  planning[1] = {
      creneau: "Lundi_08:00",
      salle: "AUD-L2",
      cours: "C003",
      groupe: "L2",
      effectif: 100,
      intitule_cours: "Systèmes d'exploitation"
  }
```

## 6️⃣ Complexité Algorithmique

```
Notation: n = nombre cours, m = nombre salles, c = nombre créneaux

Pire cas : O(n × c × m)

Exemple :
  n = 10 cours
  c = 10 créneaux
  m = 6 salles
  
  = 10 × 10 × 6 = 600 opérations
  → Temps d'exécution : < 1ms
```

## 7️⃣ Cas Limites Gérés

### Cas 1 : Pas assez de salles pour tout le monde

```
Situation :
  3 cours de 120 étudiants simultanés
  Mais seulement 2 salles de 120 places

Comportement :
  ✓ 2 cours affectés aux créneaux disponibles
  ⚠ 1 cours non affecté (pas de créneau libre ou salle)
  → Affichage: "Attention : cours non affecté"
```

### Cas 2 : Groupe trop grand pour toutes les salles

```
Situation :
  Promotion L1 = 150 étudiants
  Salle la plus grande = 120 places

Comportement :
  ✗ Affectation impossible
  → Message d'erreur explicite
  → Besoin de modifier capacités ou effectifs
```

### Cas 3 : Tous les créneaux occupés

```
Situation :
  10 créneaux disponibles
  20 cours à affecter

Comportement :
  ✓ 10 cours affectés
  ⚠ 10 cours restent non affectés
  → Rapport: "10 cours manquant de créneaux"
```

## 8️⃣ Optimisations Possibles

### Actuelle (Glouton)
- Rapide et simple
- Peut ne pas être optimal
- Ordre des cours affecte le résultat

### Améliorations futures
- **Algorithme de recuit simulé** : meilleur taux d'affectation
- **Programmation linéaire** : solution optimale garantie
- **Heuristique Best-Fit** : choisir meilleure salle à chaque étape
- **Backtracking** : si échec, revenir et essayer autre créneau

## 9️⃣ Tests Validés

```
✅ Test 1 : Planning sans conflits
   → Générer + charger + afficher = OK

✅ Test 2 : Détection collisions salle
   → Même salle, même créneau = DÉTECTÉ

✅ Test 3 : Détection groupe double
   → Même groupe, deux salles = DÉTECTÉ

✅ Test 4 : Validation capacité
   → Effectif > Capacité = REFUSÉ

✅ Test 5 : Sauvegarde/Rechargement
   → planning.json créé et chargeable = OK
```

## 🔟 Flux Complet d'Exécution

```
index.php?action=generer
    ↓
Charger salles.json
    ↓
Charger promotions.json
    ↓
Charger cours.json
    ↓
Charger options.json
    ↓
generer_creneaux()
    ↓
generer_planning()
    ├─ Trier cours
    ├─ Pour chaque cours:
    │  ├─ Identifier groupe & effectif
    │  ├─ Chercher créneau libre
    │  ├─ Chercher salle
    │  └─ Créer affectation
    └─ Retourner planning
    ↓
sauvegarder_planning() → data/planning.json
    ↓
afficher_planning_html()
    ↓
Affichage navigateur : Tableau HTML
```

## 🎓 Notes Pédagogiques

Cet algorithme illustre :
- **Backtracking** : chercher créneau, puis salle
- **Contraintes** : capacité, disponibilité
- **Gestion d'erreurs** : cas limite, impossibilité
- **Persistance** : sauvegarde JSON
- **Programmation fonctionnelle** : pas d'OOP, PHP procedural pur

---

**Document de référence pour comprendre la logique du SGA**
