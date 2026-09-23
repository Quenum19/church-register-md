# Déploiement — Church Register (Hostinger Business, SSH)

Procédure complète pour mettre en production et exploiter Church Register sur un hébergement
mutualisé **Hostinger Business** (LiteSpeed, PHP, MariaDB, accès SSH, cron hPanel).
Chaque étape se termine par une **vérification**. Les valeurs d'exemple sont à remplacer :

| Exemple | Signification |
|---|---|
| `u123456789` | identifiant SSH / compte Hostinger |
| `203.0.113.10` | adresse IP SSH du serveur (hPanel > Avancé > Accès SSH) |
| `65002` | port SSH Hostinger (à confirmer dans hPanel) |
| `exemple.org` | domaine de l'église |
| `registre.exemple.org` | sous-domaine de l'application |
| `/home/u123456789/church-register` | `DEPLOY_PATH` : dossier de l'application, **hors racine web** |
| `/opt/alt/php83/usr/bin/php` | `PHP_BIN` : PHP CLI 8.3 du serveur |

## Sommaire

1. [Vue d'ensemble](#1-vue-densemble)
2. [hPanel : sous-domaine, SSL, PHP, base de données](#2-hpanel--sous-domaine-ssl-php-base-de-données)
3. [SSH : clé de déploiement et empreinte du serveur](#3-ssh--clé-de-déploiement-et-empreinte-du-serveur)
4. [GitHub : environnement, secrets et variables](#4-github--environnement-secrets-et-variables)
5. [Serveur : dossiers et fichier `.env` de production](#5-serveur--dossiers-et-fichier-env-de-production)
6. [Racine web du sous-domaine → `current/public`](#6-racine-web-du-sous-domaine--currentpublic)
7. [Tâches planifiées (cron)](#7-tâches-planifiées-cron)
8. [Premier déploiement et premier administrateur](#8-premier-déploiement-et-premier-administrateur)
9. [Vérifications après chaque déploiement](#9-vérifications-après-chaque-déploiement)
10. [Retour arrière (rollback)](#10-retour-arrière-rollback)
11. [Sauvegardes et test de restauration](#11-sauvegardes-et-test-de-restauration)
12. [Surveillance de disponibilité](#12-surveillance-de-disponibilité)
13. [QR code imprimé : URL stable](#13-qr-code-imprimé--url-stable)
14. [Sécurité](#14-sécurité)
15. [Dépannage](#15-dépannage)

---

## 1. Vue d'ensemble

```
push sur main (ou « Run workflow »)
        │
        ▼
GitHub Actions ─ deploy.yml
  ├─ ci        : ci.yml complet (lint, analyse, tests MariaDB, audits, budget du bundle)
  ├─ build     : scripts/build-release.sh  →  release.tar.gz  (aucun secret dans ce job)
  └─ deploy    : environnement « production » (secrets SSH)
        ├─ scp  release.tar.gz + deploy/remote-deploy.sh  →  serveur
        ├─ ssh  remote-deploy.sh <sha>
        │        extraction → liens shared/ → migrate --force → optimize
        │        → bascule ATOMIQUE de current → queue:restart → 5 releases conservées
        ├─ test de fumée (scripts/smoke-test.sh)
        └─ si échec : remote-deploy.sh --rollback  → job en échec
```

Arborescence sur le serveur :

```
/home/u123456789/
├── church-register/                  DEPLOY_PATH (jamais servi par le web)
│   ├── current -> releases/<sha>     release active (lien symbolique)
│   ├── releases/
│   │   ├── <sha>/                    application Laravel + SPA (public/spa.html, public/assets/)
│   │   │   ├── .env    -> ../../shared/.env
│   │   │   └── storage -> ../../shared/storage
│   │   └── .history                  ordre des déploiements
│   ├── shared/
│   │   ├── .env                      configuration de production (créée une seule fois)
│   │   └── storage/                  journaux, cache, vues compilées
│   ├── uploads/                      archives reçues (supprimées après déploiement)
│   └── bin/remote-deploy.sh          script de déploiement (mis à jour à chaque déploiement)
└── domains/exemple.org/public_html/
    └── registre -> /home/u123456789/church-register/current/public   racine web du sous-domaine
```

La racine web ne contient **que** `public/` (index.php, .htaccess, spa.html, assets/…) :
`.env`, `vendor/`, `storage/` et le code ne sont jamais accessibles par HTTP.

---

## 2. hPanel : sous-domaine, SSL, PHP, base de données

### 2.1 Sous-domaine

1. hPanel > **Domaines** > **Sous-domaines** : créer `registre` sur `exemple.org`.
2. Noter le **dossier** créé par hPanel (en général `public_html/registre`, soit
   `/home/u123456789/domains/exemple.org/public_html/registre`). Il sera remplacé par un lien
   symbolique à l'étape 6.

**Vérification** : `https://registre.exemple.org` affiche la page par défaut de Hostinger
(après propagation DNS, quelques minutes).

### 2.2 Certificat SSL

hPanel > **Sécurité** > **SSL** : installer le certificat gratuit pour `registre.exemple.org`.
La redirection HTTP → HTTPS est assurée par `api/public/.htaccess`.

**Vérification** : le cadenas s'affiche sur `https://registre.exemple.org`.

### 2.3 Version de PHP

1. hPanel > **Avancé** > **Configuration PHP** : choisir **PHP 8.3** pour le site.
2. Onglet **Extensions PHP** : vérifier que sont cochées `bcmath`, `ctype`, `curl`, `fileinfo`,
   `gd`, `intl`, `mbstring`, `openssl`, `pdo_mysql`, `sodium`, `tokenizer`, `xml`, `zip`.
3. Le **PHP en ligne de commande** (SSH, cron) peut être une autre version que celui du site.
   En SSH (étape 3) :

   ```bash
   php -v                                   # version du « php » par défaut
   ls -d /opt/alt/php*/usr/bin/php          # versions disponibles (CloudLinux)
   /opt/alt/php83/usr/bin/php -v            # doit afficher PHP 8.3.x
   /opt/alt/php83/usr/bin/php -m | grep -Ei 'intl|pdo_mysql|mbstring|sodium|gd|zip|bcmath'
   /opt/alt/php83/usr/bin/php -r 'var_dump(defined("PASSWORD_ARGON2ID"));'   # bool(true)
   ```

   Le chemin qui affiche 8.3 est le `PHP_BIN` à utiliser partout (variable GitHub, cron, commandes
   manuelles). Si `php -v` affiche déjà 8.3, `PHP_BIN=php` suffit.

### 2.4 Base de données MariaDB

1. hPanel > **Bases de données** > **Gestion** : créer une base et un utilisateur dédiés
   (Hostinger préfixe les noms : `u123456789_church`), mot de passe **généré** (32 caractères).
2. Noter : nom de la base, utilisateur, mot de passe, hôte (`localhost` pour une application
   hébergée sur le même serveur).
3. Interclassement : `utf8mb4_unicode_ci` (celui des migrations Laravel).

**Vérification** (en SSH, après l'étape 3) :

```bash
mariadb -h localhost -u u123456789_church -p u123456789_church -e 'SELECT VERSION();'
```

Noter la version de MariaDB : la CI teste sur `mariadb:11` (à aligner si la production diffère).

---

## 3. SSH : clé de déploiement et empreinte du serveur

### 3.1 Activer SSH

hPanel > **Avancé** > **Accès SSH** : activer, puis noter **IP**, **port** (généralement `65002`)
et **nom d'utilisateur** (`u123456789`).

### 3.2 Créer la clé de déploiement (sur votre poste)

Une clé **dédiée** au déploiement, sans phrase secrète (elle est stockée dans GitHub Secrets) :

```bash
ssh-keygen -t ed25519 -C "github-deploy church-register" -f ./church-deploy -N ""
# church-deploy      -> clé privée  (secret GitHub SSH_PRIVATE_KEY, puis à supprimer du poste)
# church-deploy.pub  -> clé publique (à ajouter dans hPanel)
```

### 3.3 Autoriser la clé sur le serveur

hPanel > **Avancé** > **Accès SSH** > **Clés SSH** > **Ajouter une clé SSH** : coller le contenu de
`church-deploy.pub`.

**Vérification** :

```bash
ssh -i ./church-deploy -p 65002 u123456789@203.0.113.10 'echo connexion OK; uname -a'
```

### 3.4 Épingler l'empreinte du serveur (`known_hosts`)

Le workflow refuse de se connecter à un serveur dont l'empreinte n'est pas connue
(`StrictHostKeyChecking yes`, jamais `no`) : protection contre l'usurpation du serveur.

```bash
ssh-keyscan -p 65002 -t ed25519,ecdsa,rsa 203.0.113.10 > known_hosts_production
ssh-keygen -lf known_hosts_production        # affiche les empreintes SHA256
```

**Vérification** : comparer ces empreintes avec celles du serveur, obtenues par un canal de
confiance (session SSH ouverte depuis le terminal de hPanel, ou support Hostinger) :

```bash
for f in /etc/ssh/ssh_host_*_key.pub; do ssh-keygen -lf "$f"; done
```

Le contenu de `known_hosts_production` (lignes `[203.0.113.10]:65002 ssh-ed25519 AAAA…`)
devient le secret `SSH_KNOWN_HOSTS`. Si Hostinger change la clé de son serveur, le déploiement
échouera avec « Host key verification failed » : refaire cette étape **en vérifiant** l'empreinte.

---

## 4. GitHub : environnement, secrets et variables

1. Dépôt > **Settings** > **Environments** > **New environment** : `production`.
2. **Deployment branches and tags** : *Selected branches* → `main` uniquement.
3. (Recommandé) **Required reviewers** : un responsable valide chaque mise en production.
4. Dans l'environnement `production`, créer les **secrets** :

| Secret | Valeur |
|---|---|
| `SSH_HOST` | IP (ou nom d'hôte) SSH, ex. `203.0.113.10` |
| `SSH_PORT` | port SSH, ex. `65002` (65002 si absent) |
| `SSH_USER` | `u123456789` |
| `SSH_PRIVATE_KEY` | contenu complet de `church-deploy` (y compris les lignes `-----BEGIN/END…-----`) |
| `SSH_KNOWN_HOSTS` | contenu de `known_hosts_production` (étape 3.4) |
| `DEPLOY_PATH` | chemin **absolu**, ex. `/home/u123456789/church-register` (pas de `~`) |
| `APP_URL` | `https://registre.exemple.org` (sans `/` final) |

5. Créer les **variables** (onglet *Variables* de l'environnement) :

| Variable | Valeur |
|---|---|
| `PHP_BIN` | PHP CLI 8.3 trouvé à l'étape 2.3, ex. `/opt/alt/php83/usr/bin/php` (défaut : `php`) |
| `WEB_ROOT_MIRROR` | **vide** sauf plan B de l'étape 6.3 |

6. Supprimer la clé privée de votre poste : `rm ./church-deploy` (la clé publique peut rester).

**Vérification** : Settings > Environments > production liste 7 secrets et au moins `PHP_BIN`.

---

## 5. Serveur : dossiers et fichier `.env` de production

En SSH :

```bash
ssh -p 65002 u123456789@203.0.113.10
mkdir -p ~/church-register/shared
chmod 700 ~/church-register/shared
```

### 5.1 Générer la clé d'application

```bash
/opt/alt/php83/usr/bin/php -r 'echo "base64:".base64_encode(random_bytes(32)).PHP_EOL;'
```

Une clé **propre à la production** (jamais celle d'un poste de développement). La conserver aussi
dans le gestionnaire de mots de passe de l'église : sans elle, les secrets 2FA chiffrés en base
sont illisibles après une restauration.

### 5.2 Fichier `.env` de production

```bash
nano ~/church-register/shared/.env
chmod 600 ~/church-register/shared/.env
```

Modèle commenté (la liste de référence des variables reste `api/.env.example` ; toute variable
ajoutée par l'API doit y figurer et être reportée ici) :

```dotenv
# --- Application ----------------------------------------------------------------------
APP_NAME="Church Register"
APP_ENV=production
# Jamais true en production : les erreurs afficheraient la configuration (le déploiement refuse).
APP_DEBUG=false
# Clé générée à l'étape 5.1 (distincte de celle du développement).
APP_KEY=base64:REMPLACER
# URL publique exacte, en https, sans / final (liens des e-mails d'invitation et de réinitialisation).
APP_URL=https://registre.exemple.org
APP_TIMEZONE=Africa/Abidjan
APP_LOCALE=fr
APP_FALLBACK_LOCALE=fr
APP_MAINTENANCE_DRIVER=file

# --- Journaux (storage/logs, hors racine web) ------------------------------------------
LOG_CHANNEL=stack
LOG_STACK=daily
LOG_DAILY_DAYS=14
LOG_LEVEL=warning

# --- Base de données (hPanel > Bases de données) --------------------------------------
DB_CONNECTION=mariadb
DB_HOST=localhost
DB_PORT=3306
DB_DATABASE=u123456789_church
DB_USERNAME=u123456789_church
# Entre guillemets si le mot de passe contient des caractères spéciaux (# $ espace…).
DB_PASSWORD="REMPLACER"

# --- Sessions (dashboard admin, cookie httpOnly) --------------------------------------
SESSION_DRIVER=database
# Expiration après 120 minutes d'inactivité.
SESSION_LIFETIME=120
SESSION_EXPIRE_ON_CLOSE=false
SESSION_PATH=/
# null = cookie limité au sous-domaine exact (recommandé).
SESSION_DOMAIN=null
# Cookie envoyé uniquement en HTTPS.
SESSION_SECURE_COOKIE=true
SESSION_HTTP_ONLY=true
SESSION_SAME_SITE=lax
# Chiffre le contenu des sessions stockées en base (identifiant de connexion, jeton CSRF, état 2FA).
# ⚠️ Changer cette valeur invalide les sessions existantes : les administrateurs connectés sont
# déconnectés une fois, au premier déploiement qui l'active.
SESSION_ENCRYPT=true

# --- Limite anti-énumération du formulaire public --------------------------------------
# Identifications par heure et par IP. Tous les téléphones du Wi-Fi de l'Église partagent une
# seule IP publique : ne pas descendre sous ~150, sous peine de bloquer l'accueil un dimanche.
RATE_LIMIT_IDENTIFY_PER_IP=200

# --- Sanctum (SPA servi par la même origine) -------------------------------------------
# Hôte exact du sous-domaine, sans schéma ni port.
SANCTUM_STATEFUL_DOMAINS=registre.exemple.org

# --- Proxies de confiance --------------------------------------------------------------
# VIDE par défaut. À renseigner uniquement si le CDN Hostinger est activé, avec les plages IP du
# CDN (jamais « * » si le serveur reste joignable en direct : les limites de débit par IP
# deviendraient contournables). Vérification : voir § 15 « IP des visiteurs ».
TRUSTED_PROXIES=

# --- Cache et file d'attente (en base : pas de Redis ni de processus permanent) --------
CACHE_STORE=database
QUEUE_CONNECTION=database
FILESYSTEM_DISK=local
BROADCAST_CONNECTION=log

# --- E-mails : API HTTP Brevo ---------------------------------------------------------
MAIL_MAILER=brevo
# Brevo > SMTP & API > Clés API. Clé dédiée à la production.
BREVO_API_KEY=REMPLACER
# Adresse d'un domaine authentifié dans Brevo (enregistrements DKIM/DMARC dans la zone DNS hPanel).
MAIL_FROM_ADDRESS="registre@exemple.org"
MAIL_FROM_NAME="Église La Maison de la Destinée"
```

**Vérification** :

```bash
ls -l ~/church-register/shared/.env          # -rw------- u123456789 …
grep -E '^(APP_ENV|APP_DEBUG|APP_URL)=' ~/church-register/shared/.env
```

Le script de déploiement refuse de continuer si `.env` est absent, si `APP_KEY` est vide ou si
`APP_DEBUG` est activé.

---

## 6. Racine web du sous-domaine → `current/public`

### 6.1 Lien symbolique (solution standard)

```bash
WEBROOT=~/domains/exemple.org/public_html/registre     # dossier noté à l'étape 2.1
ls -la "$WEBROOT"                                      # page par défaut Hostinger uniquement
mv "$WEBROOT" "$WEBROOT.hostinger-origine"             # conservé le temps de valider
ln -s /home/u123456789/church-register/current/public "$WEBROOT"
ls -l "$(dirname "$WEBROOT")" | grep registre
# registre -> /home/u123456789/church-register/current/public
```

Le lien peut être créé **avant** le premier déploiement (il pointe alors vers un dossier qui
n'existe pas encore). Une fois le site validé : `rm -rf "$WEBROOT.hostinger-origine"`.

> Si le sous-domaine est dans le `public_html` du domaine principal et que ce dernier a son propre
> `.htaccess` (WordPress…), ses directives non liées à la réécriture (en-têtes, protections) peuvent
> s'appliquer aussi au sous-domaine : le vérifier. Nos règles de réécriture remplacent les siennes.

### 6.2 Vérification

Après le premier déploiement (étape 8) : `https://registre.exemple.org/api/health` répond
`{"status":"ok","db":"ok"}` et `https://registre.exemple.org/.env` répond 403.

### 6.3 Plan B : si le lien symbolique n'est pas possible

Symptômes : hPanel recrée le dossier, LiteSpeed renvoie 403/404 sur le lien, ou la suppression du
dossier est refusée. Dans ce cas, la racine web reste un **vrai dossier** qui ne contient que les
fichiers statiques et un `index.php` qui délègue à la release active :

1. Remettre le dossier d'origine : `rm "$WEBROOT" && mv "$WEBROOT.hostinger-origine" "$WEBROOT"`,
   puis le vider : `rm -rf "$WEBROOT"/* "$WEBROOT"/.htaccess`.
2. GitHub > environnement `production` > variable `WEB_ROOT_MIRROR` =
   `/home/u123456789/domains/exemple.org/public_html/registre`.
3. Redéployer. À chaque déploiement (et retour arrière), `remote-deploy.sh` copie avant la bascule
   `.htaccess`, `assets/`, icônes et manifeste dans ce dossier, écrit un `index.php` qui fait
   `require '/home/u123456789/church-register/current/public/index.php';`, et supprime les anciens
   assets qui ne servent plus à aucune release conservée.

Le code, `.env`, `vendor/` et `storage/` restent hors de la racine web ; seule la bascule des
fichiers statiques n'est plus atomique (sans conséquence : les noms d'assets sont hachés).

---

## 7. Tâches planifiées (cron)

hPanel > **Avancé** > **Tâches Cron** > *Personnalisé* :

- Fréquence : `* * * * *` (chaque minute)
- Commande :

```bash
cd /home/u123456789/church-register/current && /opt/alt/php83/usr/bin/php artisan schedule:run >> /dev/null 2>&1
```

Le planificateur Laravel déclenche : `reports:dispatch` (08:00), `visitors:purge` (03:00),
`rotations:extend` (1er du mois) et `queue:work --stop-when-empty --max-time=50` (chaque minute,
envoi des e-mails en file d'attente). Le chemin passe par `current` : rien à modifier après un
déploiement.

**Vérification** (après le premier déploiement) :

```bash
cd ~/church-register/current && /opt/alt/php83/usr/bin/php artisan schedule:list
# Une minute plus tard, la table jobs doit se vider après un envoi d'e-mail de test (§ 9).
```

---

## 8. Premier déploiement et premier administrateur

1. Pré-requis : étapes 2 à 7 faites (`shared/.env` présent, lien de la racine web créé).
2. GitHub > **Actions** > **Déploiement** > **Run workflow** (branche `main`), ou un push sur `main`.
3. Suivre le job « Mise en production » : envoi, `migrate --force`, `optimize`, bascule, test de fumée.
4. Données de référence (familles, rotations 2025-11 → 2036-12) si les migrations ne les créent
   pas elles-mêmes — une seule fois, le seeder de production étant idempotent :

   ```bash
   cd ~/church-register/current && /opt/alt/php83/usr/bin/php artisan db:seed --force
   ```

   Ne **jamais** lancer `DemoSeeder` en production.
5. Premier super_admin (commande interactive : le mot de passe n'apparaît nulle part) :

   ```bash
   cd ~/church-register/current && /opt/alt/php83/usr/bin/php artisan admin:create
   ```

6. Se connecter sur `https://registre.exemple.org/admin`, activer la 2FA, puis inviter les autres
   administrateurs depuis l'écran Admins (lien signé valable 48 h).

Si `shared/.env` manque, le job échoue avec la marche à suivre ; rien n'est modifié sur le serveur.

---

## 9. Vérifications après chaque déploiement

Automatiques (job « Mise en production », `scripts/smoke-test.sh`) :

- `GET /api/health` → 200 `{"status":"ok","db":"ok"}` ;
- `/.env`, `/.git/config`, `/composer.json`, `/composer.lock`, `/vendor/autoload.php`,
  `/storage/logs/laravel.log`, `/artisan`, `/.htaccess` → jamais 2xx ;
- `/` → 200, HTML du SPA, en-tête `Content-Security-Policy` présent ;
- avertissements : HSTS, X-Frame-Options, Referrer-Policy absents ; HTTP non redirigé vers HTTPS.

Le même test depuis un poste : `bash scripts/smoke-test.sh https://registre.exemple.org`.

Manuelles, après une mise en production importante :

```bash
ssh -p 65002 u123456789@203.0.113.10
bash ~/church-register/bin/remote-deploy.sh --status          # release active et historique
tail -n 50 ~/church-register/shared/storage/logs/laravel-$(date +%F).log
cd ~/church-register/current && /opt/alt/php83/usr/bin/php artisan about --only=environment
```

- Parcours visiteur complet sur un téléphone (scan du QR code → identification → formulaire).
- Dashboard : connexion, liste des visiteurs, export CSV.
- Paramètres > destinataires > « e-mail de test » : reçu en moins de 2 minutes (file + cron).
- Console du navigateur : aucune violation de CSP.

---

## 10. Retour arrière (rollback)

**Automatique** : si le test de fumée échoue, le workflow exécute
`remote-deploy.sh --rollback` (retour à la release précédente) puis termine en échec.

**Manuel** (SSH) :

```bash
export DEPLOY_PATH=/home/u123456789/church-register PHP_BIN=/opt/alt/php83/usr/bin/php
bash "$DEPLOY_PATH/bin/remote-deploy.sh" --status              # releases disponibles (* = active)
bash "$DEPLOY_PATH/bin/remote-deploy.sh" --rollback            # release précédente
bash "$DEPLOY_PATH/bin/remote-deploy.sh" --rollback 1a2b3c4…   # release précise
```

(en plan B, ajouter `WEB_ROOT_MIRROR=…` à l'`export`).

Chaque `--rollback` recule d'un cran dans l'historique. La bascule est atomique ; le cache de
configuration est régénéré avec le `.env` actuel.

**Limites** :

- les **migrations ne sont pas annulées** : une migration doit rester compatible avec la version
  précédente du code (ajouter une colonne/table d'abord, supprimer l'ancienne dans un déploiement
  ultérieur) ;
- seules les 5 dernières releases sont conservées ;
- pour un retour durable, préférer un `git revert` sur `main`, qui redéploie proprement.

---

## 11. Sauvegardes et test de restauration

### 11.1 Sauvegardes

1. **Hostinger** : hPanel > **Fichiers** > **Sauvegardes** — le plan Business inclut des
   sauvegardes quotidiennes (fichiers et bases). Vérifier qu'elles sont actives et leur durée de
   conservation.
2. **Export quotidien complémentaire**, conservé 14 jours hors racine web :

   ```bash
   mkdir -p ~/backups && chmod 700 ~/backups
   cat > ~/.my.cnf << 'EOF'
   [client]
   user=u123456789_church
   password="REMPLACER"
   host=localhost
   EOF
   chmod 600 ~/.my.cnf
   ```

   Tâche cron quotidienne (ex. 02:30) dans hPanel :

   ```bash
   mariadb-dump --single-transaction --quick --routines u123456789_church | gzip > ~/backups/church-$(date +\%F).sql.gz && find ~/backups -name 'church-*.sql.gz' -mtime +14 -delete
   ```

   (`%` doit être échappé en `\%` dans une ligne cron ; `mysqldump` si `mariadb-dump` n'existe pas.)
3. Hors serveur : télécharger une sauvegarde par mois (hPanel ou `scp`) vers un stockage de l'église.
4. Conserver `shared/.env` (surtout `APP_KEY`) dans le gestionnaire de mots de passe.

### 11.2 Test de restauration (chaque trimestre, et avant la mise en service)

1. hPanel : créer une base temporaire `u123456789_restore` et son utilisateur.
2. Restaurer la dernière sauvegarde :

   ```bash
   gunzip -c ~/backups/church-AAAA-MM-JJ.sql.gz | mariadb -u u123456789_restore -p u123456789_restore
   mariadb -u u123456789_restore -p u123456789_restore \
     -e 'SELECT COUNT(*) FROM visitors; SELECT COUNT(*) FROM visits; SELECT MAX(created_at) FROM visits;'
   ```

3. Comparer avec la production (mêmes requêtes), noter la date et le résultat du test.
4. Supprimer la base temporaire dans hPanel.

---

## 12. Surveillance de disponibilité

- Sonde externe gratuite (UptimeRobot, Better Stack, Hetrix Tools…) sur
  `https://registre.exemple.org/api/health`, toutes les 5 minutes, mot-clé attendu `"db":"ok"`,
  alertes par e-mail à **deux** personnes au moins.
- Alerte d'expiration du certificat SSL (option de la même sonde).
- Consulter chaque semaine les journaux : `~/church-register/shared/storage/logs/`.
- Le dimanche matin (pic d'usage), un responsable vérifie le parcours visiteur.

---

## 13. QR code imprimé : URL stable

Le QR code imprimé ne doit jamais changer, même si l'hébergement change :

1. Choisir une URL **sur le domaine de l'église**, sans paramètre :
   `https://registre.exemple.org/` (ou `https://exemple.org/visite` redirigée vers le sous-domaine).
2. Jamais d'URL temporaire Hostinger (`*.hostingersite.com`), d'adresse IP ni de raccourcisseur tiers.
3. Dashboard > Paramètres : renseigner cette URL dans **URL publique** ; le QR code est généré à
   partir d'elle (écran QR code).
4. Tester le QR code avec 3 téléphones différents (Android, iPhone, ancien modèle) avant impression.
5. Si l'application déménage un jour, seule la cible (DNS du sous-domaine ou redirection) change :
   les affiches restent valables.

---

## 14. Sécurité

- **Secrets uniquement** dans GitHub Secrets (environnement `production`) et dans `shared/.env`
  sur le serveur (droits `600`). Aucun `.env` dans le dépôt (`.gitignore`), seuls les
  `.env.example` sans valeur réelle sont versionnés. Aucun secret dans les issues, PR ou messages.
- Le job qui manipule les secrets n'exécute ni `npm` ni `composer` (le build est fait dans un job
  sans secret), et la connexion SSH exige l'empreinte épinglée du serveur.
- `APP_DEBUG=false` en production (vérifié à chaque déploiement), `APP_KEY` distincte par environnement.
- Droits : `shared/` en `700`, `shared/.env` en `600`, `storage/` sans accès groupe ni « autres ».
  Le script de déploiement les **réimpose** à chaque déploiement et à chaque retour arrière, avant
  même de lire le `.env`. S'il n'y parvient pas (fichier appartenant à un autre compte, par exemple),
  il **interrompt** le déploiement en affichant le mode constaté, le mode attendu, la commande `chmod`
  à passer et le `ls -ld` du chemin. Sur un système de fichiers qui n'applique pas les droits POSIX,
  il se contente d'un avertissement. `bash deploy/remote-deploy.sh --status` affiche les droits constatés.
- **Rotation** (et immédiatement en cas de départ d'un administrateur technique ou de doute) :

| Secret | Procédure |
|---|---|
| Clé SSH de déploiement | générer une nouvelle clé (§ 3.2), l'ajouter dans hPanel, mettre à jour `SSH_PRIVATE_KEY`, lancer un déploiement, puis **supprimer l'ancienne clé** dans hPanel. Chaque année. |
| Mot de passe de la base | hPanel > changer le mot de passe → `DB_PASSWORD` dans `shared/.env` (et `~/.my.cnf`) → `cd ~/church-register/current && $PHP_BIN artisan config:cache`. |
| `BREVO_API_KEY` | créer une nouvelle clé dans Brevo → `shared/.env` → `config:cache` → e-mail de test → révoquer l'ancienne. |
| `APP_KEY` | à éviter hors compromission : déplacer l'ancienne clé dans `APP_PREVIOUS_KEYS`, mettre la nouvelle dans `APP_KEY`, `config:cache`. Toutes les sessions sont déconnectées. |
| Comptes admin | désactiver un compte coupe toutes ses sessions (dashboard > Admins). |

- Revoir chaque semestre : collaborateurs du dépôt GitHub, relecteurs de l'environnement
  `production`, clés SSH autorisées dans hPanel, comptes admin actifs, journal d'audit.

---

## 15. Dépannage

| Symptôme | Cause probable / solution |
|---|---|
| `Host key verification failed` | `SSH_KNOWN_HOSTS` absent, incomplet (port `[ip]:65002`) ou clé du serveur changée : § 3.4, en vérifiant l'empreinte. |
| `Permission denied (publickey)` | clé publique absente de hPanel, ou `SSH_PRIVATE_KEY` tronquée (copier tout le fichier). |
| `PHP 8.3 minimum requis` | variable `PHP_BIN` à renseigner (§ 2.3). |
| `shared/.env est absent` | premier déploiement : § 5. |
| `un autre déploiement est en cours` | un déploiement a été interrompu : vérifier qu'aucun n'est actif, puis `rm -rf ~/church-register/.deploy.lock`. |
| Test de fumée : `/` sans `Content-Security-Policy` | en-têtes du middleware Laravel absents sur la route de repli du SPA : corriger l'API. |
| Test de fumée : `/api/health` en erreur | `laravel-*.log` dans `shared/storage/logs/` ; identifiants `DB_*` ; extensions PHP du site (§ 2.3). |
| Page blanche / erreur 500 sans log | version PHP du **site** ≠ 8.3 (hPanel > Configuration PHP). |
| Anciennes pages après déploiement | caches PHP de LiteSpeed : le script crée `~/.lsphp_restart.txt` ; attendre 1 à 2 minutes. |
| E-mails non envoyés | cron absent (§ 7) ; `BREVO_API_KEY` ; table `failed_jobs`. |
| IP des visiteurs | après une connexion admin, le journal d'audit doit afficher votre IP publique et non une IP du CDN ; sinon, ajuster `TRUSTED_PROXIES` (§ 5.2). |
