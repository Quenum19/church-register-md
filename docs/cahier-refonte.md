# Cahier de refonte — Church Register (v2, amendé)

Version amendée du cahier du 22/09/2026 après revue. Les amendements sont marqués **[A]**.
Le contrat d'API détaillé est dans [api-contract.md](api-contract.md).

## Contexte

Refonte complète de Church Register (Église La Maison de la Destinée) à partir de zéro.
Projet en phase de conception : aucun utilisateur ni donnée de production, **base vide au lancement**,
aucune migration de données.

## Décisions

| Sujet | Décision |
|---|---|
| Hébergement | Hostinger Business (PHP + MySQL/MariaDB), un seul sous-domaine pour le SPA et l'API |
| Backend | **[A]** Laravel 13 (Laravel 11 n'a plus de correctifs de sécurité depuis mars 2026), PHP ≥ 8.3 |
| Frontend | Vite + **[A]** React 19 + TypeScript, react-router, react-hook-form + zod, TanStack Query (dashboard uniquement), Tailwind 4 |
| Node (CI/build) | **[A]** Node 22 LTS (Node 20 en fin de vie depuis avril 2026) |
| Base | MariaDB (tests locaux sur MariaDB 11.5, comme en production) |
| E-mails | API HTTP Brevo |
| Déploiement | **[A]** GitHub Actions → SSH (accès inclus dans Hostinger Business) : releases atomiques, migrations, caches, test de fumée |

## Structure du dépôt

```
api/        Laravel 13
web/        Vite + React + TypeScript
docs/       cahier, contrat d'API, déploiement
.github/workflows/  ci.yml, deploy.yml
README.md
```
Règles : `.env` jamais commité ; `.env.example` sans valeur réelle ; versions PHP/Node fixées ;
lint, analyse statique et tests bloquants en CI.

## Schéma de données

| Table | Colonnes clés | Index |
|---|---|---|
| families | id, name, position, active | unique(name) |
| family_rotations | id, family_id, year, month | unique(year, month) |
| visitors | id, phone (E.164), full_name, whatsapp (NULL si vide), commune, quartier, source, source_other, invited_by, **[A]** inviter_family_id, **[A]** wants_whatsapp_group, consent_at, status, timestamps | unique(phone), status, created_at, fulltext(full_name, commune, quartier) |
| visits | id, visitor_id, visit_number (1-3), visit_date (DATE), family_id (nullable), answers (JSON), idempotency_key, created_at | unique(visitor_id, visit_date), **[A]** unique(visitor_id, visit_number), (family_id, visit_date), unique(idempotency_key) |
| visitor_notes | id, visitor_id, user_id, body, created_at | visitor_id |
| members | id, visitor_id, converted_by, converted_at | unique(visitor_id) |
| users | id, name, email, password (argon2id), role, is_active, failed_attempts, locked_until, two_factor_secret (**[A]** chiffré), two_factor_recovery_codes (chiffré), two_factor_confirmed_at, last_login_at | unique(email), role |
| settings | key, value (JSON) | primary(key) |
| report_recipients | id, **[A]** family_id (NULL = tous les rapports), name, email, active | **[A]** unique(family_id, email) |
| **[A]** report_dispatches | id, family_id, year, month, sent_at, recipients (JSON), sent_by (NULL = automatique) | unique(year, month) |
| audit_logs | id, user_id, action, subject_type, subject_id, ip, meta (JSON), created_at | (subject_type, subject_id), created_at, action |
| sessions, password_reset_tokens, jobs, cache | tables Laravel standard | — |

- Téléphones normalisés en E.164 côté serveur avant toute lecture/écriture.
- `visitors.status` n'est modifié que par un service unique, dans la même transaction que la visite ou la conversion.
- **[A]** Fuseau `APP_TIMEZONE=Africa/Abidjan` : `visit_date` = date du jour dans ce fuseau.
- **[A]** Rotations : seeder 2025-11 → 2036-12, commande `rotations:extend` mensuelle, écran d'édition ;
  un mois sans rotation enregistre la visite avec `family_id` NULL et journalise une alerte.
- Rétention : purge des visiteurs **[A]** non membres sans visite depuis 24 mois, en cascade (visites, notes).

## Parcours visiteur (API publique)

Voir contrat §2. Points clés :
- Aucune donnée personnelle dans les réponses publiques ; jeton de parcours chiffré, 15 min, usage unique.
- Une visite par jour (index unique), idempotence (un renvoi après coupure réseau = succès), nom non modifiable depuis le public.
- **[A]** Limites : 300 requêtes/min par IP (le Wi-Fi de l'église partage une seule IP publique),
  5 identifications/heure par numéro, TrustProxies configuré.
- **[A]** Risque résiduel documenté : `step` révèle le nombre de visites d'un numéro, et un tiers connaissant un numéro
  peut enregistrer une visite à sa place. Seule une vérification OTP (WhatsApp/SMS) le supprimerait — évolution possible.
- Formulaires : visite 1 (nom, WhatsApp optionnel ou « même numéro », commune, quartier, source, invité par,
  famille de l'invitant, groupe WhatsApp, consentement obligatoire avec lien vers la mention d'information),
  visite 2 (raisons du retour), visite 3 (motivation **[A]** avec précision si « autre »), au-delà : « Parcours complet ».
- Famille d'accueil déterminée côté serveur depuis `family_rotations`.

## Dashboard admin

- Sanctum SPA en cookie httpOnly, même domaine : pas de CORS, pas de jeton en localStorage.
- Session : SameSite=Lax, CSRF, 2 h d'inactivité, déconnexion serveur réelle, changement de mot de passe qui
  invalide les autres sessions, désactivation qui coupe toutes les sessions.
- Login : **[A]** 5 échecs / 15 min par couple (email + IP), verrouillage 15 min du compte après 20 échecs / heure
  avec e-mail de notification (évite qu'un tiers bloque un admin à volonté), temps de réponse constant,
  2FA TOTP optionnel.
- Rôles et permissions : voir contrat §1.
- Admins : **[A]** invitation par lien signé valable 48 h (l'admin choisit son mot de passe, rien n'est envoyé en clair),
  activation/désactivation, impossible de se rétrograder ou de supprimer le dernier super_admin.
- **[A]** Premier super_admin : commande Artisan interactive `admin:create` exécutée en SSH.
- Pages : Accueil, Visiteurs, Fiche visiteur (notes horodatées par auteur), Membres, Rapports, Admins,
  Paramètres (profil, mot de passe, 2FA, rotations, destinataires, verset/nom/URL), QR code, **[A]** Journal d'audit.
- Exports serveur en streaming, filtres respectés, sans troncature silencieuse, **[A]** réservés au super_admin et journalisés.
- Rapports mensuels : famille de service uniquement, **[A]** destinataires par famille (+ destinataires globaux),
  **[A]** envoi idempotent via `report_dispatches` avec rattrapage automatique quotidien, e-mail via Brevo,
  liste des visiteurs consultable, sélecteur d'années dynamique.

## Frontend

- Deux bundles : parcours visiteur léger (objectif < 100 Ko gzip), dashboard chargé uniquement sur `/admin`.
- Parcours persistant (sessionStorage), historique du navigateur respecté, garde sur l'accès direct aux étapes.
- Réseau : délai 15 s, messages clairs, bouton désactivé pendant l'envoi, renvoi avec la même clé d'idempotence,
  **[A]** ré-identification transparente si le jeton expire pendant la saisie.
- Téléphone : masque par pays, 10 chiffres CI avec ou sans espaces.
- Mobile : champs 16 px, cibles 44 px, vrais `<form>`, confirmation avant « Retour à l'accueil ».
- Accessibilité : `lang="fr"`, labels liés, erreurs via `aria-live`, contrastes 4,5:1, navigation clavier, `prefers-reduced-motion`.
- Assets : logo WebP < 20 Ko, favicon, icônes manifest, meta description au nom de l'église ; polices auto-hébergées.
- Textes : « Église », « 1re / 2e / 3e », « Pasteur », « Ravis ».
- **[A]** Tests Vitest + Testing Library sur le parcours et les formulaires.

## Sécurité transverse

| Risque de l'audit | Réponse |
|---|---|
| Fuite via /identify | Aucune donnée personnelle publique ; jeton de parcours |
| XSS stockée (PDF, e-mails) | Blade échappé par défaut, DomPDF via Blade, CSP stricte |
| Double hachage, mot de passe faible | argon2id géré par Laravel uniquement, 12 caractères minimum |
| Mots de passe dans le dépôt | `admin:create` interactif, aucune valeur en dur |
| Limite de débit partagée | TrustProxies, limites distinctes public / login / admin |
| Secret faible | `APP_KEY` généré, distinct par environnement |
| Sessions non révocables | Sessions en base, révocation, expiration d'inactivité |
| Absence de validation | Form Request sur 100 % des routes |
| Injection regex / formules CSV | LIKE paramétré, neutralisation CSV |
| Scripts CDN sans SRI | Tout est bundlé, aucune ressource externe |
| Erreurs verbeuses | Messages génériques, détails dans les logs |
| Consentement absent | Case obligatoire, mention d'information, `consent_at` |
| **[A]** `.env` exposé | Application hors racine web, racine = `public/`, test de fumée `/.env` en CI |

En-têtes de sécurité via middleware, journal d'audit sur toute action sensible,
tests Pest : identify, idempotence, unicité par jour, permissions, verrouillage.

## Déploiement Hostinger (SSH)

1. hPanel : sous-domaine, base MariaDB, cron `php artisan schedule:run` chaque minute, clé SSH de déploiement.
2. Arborescence serveur : `~/church-register/releases/<sha>`, `~/church-register/shared/{.env,storage}`,
   `~/church-register/current` → release active ; la racine web du sous-domaine pointe vers `current/public`
   (lien symbolique). **L'application n'est jamais dans la racine web.**
3. `deploy.yml` : lint + tests, `composer install --no-dev -o`, `npm ci && npm run build`, copie du SPA,
   envoi rsync/SSH d'une nouvelle release, liens `shared`, `php artisan migrate --force`, `optimize`,
   bascule atomique du lien `current`, test de fumée (`/api/health` = 200, `/.env`, `/vendor/`, `/storage/` ≠ 200),
   conservation des 5 dernières releases.
4. `.env` de production créé une seule fois en SSH, `APP_DEBUG=false`.

## Hors code — à faire par l'équipe

- **[A]** Mention d'information / politique de confidentialité à faire valider ; vérifier les formalités ARTCI
  (loi ivoirienne 2013-450, données sensibles : convictions religieuses).
- **[A]** Sauvegardes quotidiennes de la base (hPanel) et test de restauration ; surveillance de disponibilité.
- **[A]** QR code imprimé pointant vers une URL stable du domaine de l'église.
- Compte Brevo et clé API.
