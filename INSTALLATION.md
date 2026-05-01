# 🚀 Guide d'Installation et Lancement - SGA

## Prérequis

Vous devez avoir **PHP 7.4+** installé sur votre machine.

## ✅ Installation Complète sur Windows

### Étape 1 : Installer PHP

1. **Télécharger PHP**
   - Aller sur https://www.php.net/downloads
   - Télécharger la version **Non-Thread Safe** (.zip)
   - Exemple : `php-8.2.0-nts-Win32-x64.zip`

2. **Extraire PHP**
   - Créer dossier `C:\php`
   - Extraire le contenu du zip dans `C:\php`

3. **Ajouter PHP au PATH** (variables d'environnement)
   - Ouvrir "Modifier les variables d'environnement système"
   - Ajouter `C:\php` à la variable **Path**
   - Redémarrer PowerShell/CMD

4. **Vérifier l'installation**
   ```powershell
   php -v
   # Doit afficher : PHP 8.2.0 (cli) ...
   ```

### Étape 2 : Lancer le Serveur

Depuis le répertoire du projet :

```powershell
cd c:\Users\kl\systeme-gestion-auditoire_SGA
php -S localhost:8000
```

Vous devriez voir :
```
Development Server (http://localhost:8000) started
```

### Étape 3 : Accéder l'Application

Ouvrir le navigateur et aller à : **http://localhost:8000**

---

## 📁 Structure du Projet Généré

```
projet/
│
├── 📄 index.php
│   └─ Script principal PHP qui :
│      • Charge les données JSON
│      • Orchestre les actions
│      • Génère le planning
│      • Affiche l'interface HTML
│
├── 📂 config/
│   └── functions.php (850+ lignes)
│       • charger_salles()
│       • charger_promotions()
│       • charger_cours()
│       • charger_options()
│       • generer_planning() ← ALGORITHME PRINCIPAL
│       • salle_disponible()
│       • capacite_suffisante()
│       • creneau_libre_groupe()
│       • sauvegarder_planning()
│       • charger_planning()
│       • afficher_planning_html()
│       • detecter_conflits()
│       • rapport_occupation_salles()
│       • sauvegarder_rapport_occupation()
│
├── 📂 data/
│   ├── salles.json (6 salles)
│   ├── promotions.json (4 promotions L1-L4)
│   ├── cours.json (10 cours : tronc commun + options)
│   ├── options.json (6 options spécialisées)
│   ├── planning.json (AUTO-GÉNÉRÉ après clic "Générer")
│   └── rapport_occupation.txt (AUTO-GÉNÉRÉ après "Rapports")
│
├── 📂 assets/
│   ├── css/
│   │   └── style.css (900+ lignes)
│   │       • Design moderne gradient bleu-violet
│   │       • Responsive (mobile, tablet, desktop)
│   │       • Animations smooth
│   │       • Cards, buttons, tables, progress bars
│   │       • Dark mode compatible
│   │
│   └── js/
│       └── script.js (300+ lignes)
│           • Animations d'entrée
│           • Fermeture alertes
│           • Compteurs animés
│           • Raccourcis clavier (Ctrl+G, Ctrl+1-5)
│           • Notifications toast
│           • Impression du planning
│
└── 📄 README.md
    └─ Documentation complète
```

---

## 🎯 Flux d'Utilisation

### 1️⃣ **Page d'accueil (Dashboard)**
```
http://localhost:8000
│
├─ Statistiques système
│  ├─ 6 salles
│  ├─ 4 promotions
│  ├─ 10 cours
│  └─ 6 options
│
├─ Configuration
│  ├─ Liste des salles et capacités
│  └─ Promotions et effectifs
│
└─ Bouton "Générer le Planning"
```

### 2️⃣ **Générer le Planning**
```
http://localhost:8000?action=generer
│
├─ Charge data/* (JSON)
├─ Lance algorithme d'affectation
├─ Valide contraintes
│  ├─ Pas de collision salle
│  ├─ Effectif ≤ Capacité
│  └─ Pas de groupe en deux places
├─ Sauvegarde data/planning.json
└─ Affiche résumé + planning
```

### 3️⃣ **Afficher le Planning**
```
http://localhost:8000?action=afficher
│
└─ Tableau HTML 5 jours × 2 créneaux/jour
   ├─ Colonnes: Lundi, Mardi, Mercredi, Jeudi, Vendredi
   ├─ Lignes: 08:00-12:00 | 12:00-17:00
   └─ Cellules: Cours | Salle | Groupe | Effectif
```

### 4️⃣ **Rapport d'Occupation**
```
http://localhost:8000?action=rapport
│
├─ Table avec colonnes:
│  ├─ Salle
│  ├─ Capacité
│  ├─ Créneaux occupés
│  ├─ Créneaux libres
│  └─ Taux d'occupation %
│
└─ Bouton "Télécharger Rapport TXT"
```

### 5️⃣ **Détection de Conflits**
```
http://localhost:8000?action=conflits
│
├─ Analyse planning.json
├─ Cherche collisions:
│  ├─ Même salle au même créneau
│  └─ Même groupe en deux salles
│
└─ Affiche:
   ├─ ✓ Si aucun conflit
   └─ ⚠ Si conflits trouvés
```

---

## 🔧 Configuration des Données

### Modifier les salles

Éditer `data/salles.json` :
```json
[
  {
    "id": "AUD-L1",
    "designation": "Auditoire principal - Licence 1",
    "capacite": 120
  },
  ...
]
```

### Modifier les effectifs

Éditer `data/promotions.json` :
```json
[
  {
    "id": "L1",
    "libelle": "Licence 1",
    "effectif": 120  ← MODIFIER ICI
  },
  ...
]
```

### Ajouter des cours

Éditer `data/cours.json` :
```json
[
  {
    "id": "C099",
    "intitule": "Nouveau Cours",
    "volume_horaire": 4,
    "type": "tronc_commun",  ← ou "option"
    "promotion": "L1"
  },
  ...
]
```

---

## ⌨️ Raccourcis Clavier

| Raccourci | Action |
|-----------|--------|
| **Ctrl+G** | Générer planning |
| **Ctrl+1** | Dashboard |
| **Ctrl+2** | Générer |
| **Ctrl+3** | Planning |
| **Ctrl+4** | Rapports |
| **Ctrl+5** | Conflits |

---

## 🎨 Personnalisation Visuelle

### Changer les couleurs

Dans `assets/css/style.css`, modifier `:root` :

```css
:root {
    --primary-color: #2563eb;      ← Bleu principal
    --primary-dark: #1e40af;       ← Bleu foncé
    --success-color: #10b981;      ← Vert réussi
    --error-color: #ef4444;        ← Rouge erreur
    /* ... */
}
```

### Ajouter un logo

Remplacer le 🏛️ dans `index.php` :
```php
<h1>🏛️ SGA</h1>  ← Remplacer l'emoji
```

---

## 🐛 Troubleshooting

### Erreur : "PHP not found"
```
→ PHP n'est pas installé
→ Voir "Installation Complète sur Windows" ci-dessus
```

### Erreur : "CORS ou fichiers manquants"
```
→ Vérifier que dossiers data/ et assets/ existent
→ chmod 755 data/ && chmod 644 data/*.json
```

### Planning ne se génère pas
```
→ Vérifier format JSON (utiliser JSONLint.com)
→ Vérifier permissions fichiers
→ Consulter messages d'erreur en haut de page
```

### Affichage cassé (CSS ne charge pas)
```
→ Actualiser le navigateur : Ctrl+Shift+Del
→ Vérifier assets/css/style.css existe
→ Vérifier console (F12) pour erreurs
```

---

## 📊 Exemple Complet d'Utilisation

```powershell
# Terminal 1 : Lancer le serveur
cd c:\Users\kl\systeme-gestion-auditoire_SGA
php -S localhost:8000

# Terminal 2 : Vérifier les fichiers
dir data/
# Doit afficher : salles.json, promotions.json, cours.json, options.json
```

Puis dans le navigateur :

```
1. Ouvrir http://localhost:8000
   → Voir Dashboard

2. Cliquer "⚙️ Générer Planning"
   → Attendre la génération
   → planning.json est créé

3. Cliquer "📅 Planning"
   → Voir tableau planning

4. Cliquer "📈 Rapports"
   → Voir stats occupation
   → Télécharger rapport_occupation.txt

5. Cliquer "⚠️ Conflits"
   → Voir validation (✓ ou ⚠)
```

---

## 📝 Notes Importantes

✅ **Respecte exactement le PDF** :
- PHP procédural pur
- Fichiers JSON (pas TXT)
- Algorithme d'affectation automatique
- Validation des contraintes métier
- Sauvegarde et rechargement planning

✨ **Interface moderne** :
- Design responsive
- Animations smooth
- Navigation intuitive
- Accessibilité web

🚀 **Déploiement** :
- Prêt pour production
- Peut être hébergé sur tout serveur PHP
- Zéro dépendances externes
- Fichiers sécurisés

---

## 📞 Besoin d'aide ?

Consultez les messages affichés dans l'application. Ils expliquent chaque étape ! 🎯
