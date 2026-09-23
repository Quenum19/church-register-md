# Church Register — Église La Maison de la Destinée

Registre des visiteurs de l'Église La Maison de la Destinée.

- **Parcours visiteur** (public, mobile, via QR code) : le visiteur s'identifie par son numéro de
  téléphone puis remplit le formulaire de sa 1re, 2e ou 3e visite. Aucune donnée personnelle
  n'est renvoyée par l'API publique.
- **Dashboard** (`/admin`, réservé aux administrateurs) : visiteurs, fiches et notes, membres,
  rapports mensuels par famille de service (envoyés par e-mail), exports, rotation des familles,
  administrateurs, paramètres, QR code, journal d'audit.

Cahier des charges : [docs/cahier-refonte.md](docs/cahier-refonte.md) · Contrat d'API :
[docs/api-contract.md](docs/api-contract.md) · Mise en production :
[docs/deploiement.md](docs/deploiement.md).

## Architecture

```
                     https://registre.exemple.org  (un seul sous-domaine)
                                   │
                    LiteSpeed / Apache  ─  racine web = current/public
                                   │         (.htaccess : HTTPS, blocages, cache)
               ┌───────────────────┴────────────────────┐
     fichier existant ?                            sinon → public/index.php (Laravel 13)
     /assets/*.js|css (hachés, cache 1 an)                 │
     favicon, manifeste…                ┌──────────────────┼─────────────────────┐
                                  /api/public/*        /api/auth/*          toute autre route GET
                                  (sans session,       /api/admin/*         (/, /visite/…, /admin/…)
                                   limites de débit)   (Sanctum, cookie     → spa.html (SPA React)
                                        │               httpOnly + CSRF)       + en-têtes de sécurité
                                        └─────────┬────────┘
                                                  ▼
                                   MariaDB (données, sessions, cache, file d'attente)
                                   Brevo (API HTTP, e-mails)       cron → schedule:run

Build : web/ (Vite + React 19 + TypeScript) ── npm run build ──► web/dist
        web/dist/* copié dans api/public/, index.html renommé spa.html (scripts/build-release.sh)
```

- `api/` — Laravel 13, PHP ≥ 8.3, MariaDB. Sert l'API **et** le SPA (même origine : ni CORS ni
  jeton dans le navigateur).
- `web/` — Vite 8, React 19, TypeScript, react-router, react-hook-form + zod, TanStack Query,
  Tailwind 4. Deux bundles : parcours visiteur léger (< 100 Ko gzip), dashboard chargé à la demande.
- Production : Hostinger Business (SSH, cron hPanel). Node ne sert qu'au build, en CI.

## Prérequis

| Outil | Version |
|---|---|
| PHP | 8.3 (extensions : bcmath, ctype, curl, fileinfo, gd, intl, mbstring, openssl, pdo_mysql, sodium, tokenizer, xml, zip) |
| Composer | 2.x |
| Node.js | 22 LTS (avec npm) |
| MariaDB | 11.x (la production et la CI utilisent MariaDB) |
| Git | 2.x (sous Windows : Git for Windows, qui fournit Git Bash) |

## Installation locale

### 1. Récupérer le code

```bash
git clone <url-du-dépôt> church-register
cd church-register
```

Le dépôt impose des fins de ligne LF (`.gitattributes`) : les scripts s'exécutent sous Linux.

### 2. Créer les bases de données

Une base pour le développement, une pour les tests :

```sql
CREATE DATABASE church_register CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;
CREATE DATABASE church_test     CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;
```

- **Windows / WAMP** : phpMyAdmin (`http://localhost/phpmyadmin`, serveur **MariaDB**) ou
  `C:\wamp64\bin\mariadb\mariadb11.x\bin\mariadb.exe -u root -P 3307`.
  Attention : quand MySQL et MariaDB sont installés tous les deux, WAMP place souvent MariaDB sur
  le port **3307** (clic gauche sur l'icône WAMP > MariaDB pour vérifier).
- **Linux / macOS** : `sudo mariadb` (ou `mariadb -u root -p`), puis les requêtes ci-dessus.

### 3. API (Laravel)

```bash
cd api
composer install
cp .env.example .env          # Windows (cmd) : copy .env.example .env
```

Dans `api/.env`, renseigner la base de développement :

```dotenv
DB_CONNECTION=mariadb
DB_HOST=127.0.0.1
DB_PORT=3306                  # WAMP : souvent 3307 pour MariaDB
DB_DATABASE=church_register
DB_USERNAME=root
DB_PASSWORD=
```

Puis :

```bash
php artisan key:generate
php artisan migrate --seed                    # schéma + données de référence (familles, rotations)
php artisan db:seed --class=DemoSeeder        # optionnel : visiteurs fictifs pour le développement
php artisan admin:create                      # premier super_admin (interactif)
php artisan serve                             # http://127.0.0.1:8000
```

- **Windows / WAMP** : utiliser le PHP 8.3 de WAMP en ligne de commande
  (`C:\wamp64\bin\php\php8.3.x` dans le `PATH`). Son `php.ini` (dans ce même dossier, distinct du
  `phpForApache.ini` d'Apache) doit activer `intl`, `gd`, `zip`, `sodium`, `pdo_mysql`, `bcmath`,
  `fileinfo`, `mbstring`, `openssl`. Vérifier avec `php -m`.
- **Linux / macOS** : paquets PHP 8.3 de la distribution (`php8.3-{intl,gd,zip,mysql,bcmath,mbstring,xml,curl}`)
  ou Homebrew (`brew install php@8.3 composer mariadb node@22`).

### 4. SPA (Vite + React)

Dans un second terminal :

```bash
cd web
npm ci
npm run dev                   # http://localhost:5173
```

Ouvrir **http://localhost:5173** : Vite proxifie `/api` et `/sanctum` vers `http://127.0.0.1:8000`
(même origine pour le navigateur, comme en production). Le dashboard est sur
http://localhost:5173/admin.

## Qualité : scripts

| Dossier | Commande | Rôle |
|---|---|---|
| `api/` | `composer lint` | style (Pint, mode vérification) |
| `api/` | `composer analyse` | analyse statique (Larastan) |
| `api/` | `composer test` | tests Pest (base MariaDB `church_test`) |
| `api/` | `composer audit` | vulnérabilités connues des dépendances PHP |
| `web/` | `npm run lint` | lint (oxlint) |
| `web/` | `npm run typecheck` | vérification TypeScript |
| `web/` | `npm test` | tests Vitest + Testing Library |
| `web/` | `npm run build` | build de production dans `web/dist` |
| `web/` | `npm audit --omit=dev --audit-level=high` | vulnérabilités des dépendances de production |
| racine | `node scripts/check-bundle-size.mjs [seuil_ko]` | budget gzip du chargement initial (défaut 100 Ko, après `npm run build`) |
| racine | `bash scripts/build-release.sh` | archive de production `release.tar.gz` (sans toucher `api/` ni `web/`) |
| racine | `bash scripts/smoke-test.sh https://…` | test de fumée d'un environnement déployé |

La CI (`.github/workflows/ci.yml`) exécute tous ces contrôles sur chaque push et pull request ;
ils sont bloquants.

## Déploiement

Un push sur `main` lance `.github/workflows/deploy.yml` : CI complète, build de la release,
envoi SSH vers Hostinger, migrations, bascule atomique, test de fumée et retour arrière automatique
en cas d'échec. Procédure d'installation du serveur, secrets et exploitation :
[docs/deploiement.md](docs/deploiement.md).

## Structure du dépôt

```
api/                    Laravel 13 (API + service du SPA)
  public/.htaccess      règles Apache/LiteSpeed (HTTPS, blocages, cache des assets)
web/                    Vite + React 19 + TypeScript (SPA)
docs/
  cahier-refonte.md     cahier des charges (décisions, sécurité, déploiement)
  api-contract.md       contrat d'API (source de vérité api/ ↔ web/)
  deploiement.md        mise en production et exploitation Hostinger
scripts/
  build-release.sh      construit l'archive de production
  check-bundle-size.mjs budget de taille du SPA
  smoke-test.sh         test de fumée post-déploiement
deploy/
  remote-deploy.sh      déploiement côté serveur (releases, bascule, rollback)
.github/workflows/
  ci.yml                lint, analyse, tests, audits, budget
  deploy.yml            déploiement en production
```

## Sécurité

- Aucun secret dans le dépôt : `.env` est ignoré, seuls les `.env.example` sans valeur réelle
  sont versionnés. Les secrets de production vivent dans GitHub Secrets et dans `shared/.env`
  sur le serveur.
- Droits sur le serveur : `deploy/remote-deploy.sh` impose `shared/` en `700` et `shared/.env`
  en `600` à chaque exécution (déploiement comme retour arrière) et **interrompt** le déploiement
  si ces droits ne peuvent pas être appliqués. `bash deploy/remote-deploy.sh --status` les affiche.
- Les actions GitHub tierces sont épinglées sur un SHA de commit complet (tag en commentaire de
  fin de ligne) : un tag est déplaçable, pas un SHA. Procédure de mise à jour en tête de
  `.github/workflows/ci.yml`.
- Parcours visiteur : l'état est gardé dans `sessionStorage` **60 minutes au plus**, puis ignoré
  et effacé du navigateur à la première lecture ; il est aussi effacé à la fin du parcours et au
  retour explicite à l'accueil (tablettes d'accueil partagées).
- Le jeton d'un lien de réinitialisation ou d'invitation est retiré de l'URL dès son arrivée
  (remplacement de l'entrée d'historique) : il ne reste ni dans l'historique ni dans un `Referer`.
- Signaler une vulnérabilité en privé aux responsables techniques de l'église, jamais dans une
  issue publique.
