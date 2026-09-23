# Contrat d'API — Church Register v2

Source de vérité partagée entre `api/` (Laravel 13) et `web/` (Vite + React + TS).
Toute divergence entre le code et ce document est un bug. Un agent qui a besoin de
changer le contrat le signale au superviseur au lieu de le modifier seul.

## 0. Conventions générales

- Même origine en production (un seul sous-domaine) : le SPA et l'API sont servis par Laravel.
  En dev : Vite (`http://localhost:5173`) proxifie `/api` et `/sanctum` vers `http://127.0.0.1:8000`.
- JSON uniquement, `snake_case` partout, dates `YYYY-MM-DD`, horodatages ISO 8601 avec fuseau.
- Fuseau applicatif : `Africa/Abidjan` (`APP_TIMEZONE`). « Aujourd'hui » = date dans ce fuseau.
- Langue des messages : français (`APP_LOCALE=fr`).
- Identifiants : entiers auto-incrémentés (`id`).

### Format d'erreur (toutes routes)

```json
{ "message": "Texte lisible en français.", "code": "code_machine_optionnel", "errors": { "champ": ["msg"] } }
```

| Statut | Quand | `code` |
|---|---|---|
| 400 | requête inexploitable (ex. route d'authentification appelée sans session) | `bad_request` |
| 401 | non authentifié / jeton de parcours invalide | `unauthenticated` / `token_invalid` |
| 403 | rôle insuffisant | `forbidden` |
| 404 | ressource absente (et ids invalides) | `not_found` |
| 409 | conflit métier | voir chaque route |
| 410 | jeton de parcours expiré | `token_expired` |
| 413 | corps de requête hors des bornes publiques (taille, profondeur JSON, longueur de chaîne) | `payload_too_large` |
| 419 | CSRF expiré (admin) | `csrf_expired` |
| 422 | validation (`errors` rempli) | `validation` |
| 423 | compte verrouillé temporairement | `account_locked` |
| 429 | trop de requêtes (en-tête `Retry-After`) | `too_many_requests` |
| 500 | erreur serveur — message générique, jamais de détail technique | `server_error` |

### Pagination (listes admin)

Paramètres `page` (≥1, défaut 1) et `per_page` (1–100, défaut 20 ; hors bornes → 422).
Réponse :
```json
{ "data": [ ... ], "meta": { "current_page": 1, "last_page": 1, "per_page": 20, "total": 0 } }
```
`last_page` vaut au minimum 1 (jamais 0).

## 1. Domaine

### Familles (7, ordre de rotation)
`Puissance`, `Richesse`, `Sagesse`, `Force`, `Honneur`, `Gloire`, `Louange`.
Ancre historique : novembre 2025 = Puissance, puis une famille par mois dans cet ordre
(février 2026 = Force, septembre 2026 = Force). La famille d'un mois est lue dans
`family_rotations` (modifiable par un super_admin) ; le seeder pré-remplit 2025-11 → 2036-12.

### Statuts visiteur
`prospect` (1 visite) → `recurrent` (2) → `membre_potentiel` (3) → `membre` (converti par un super_admin).

### Sources (visite 1) — `source`
`invite_membre` (Invité(e) par un membre), `saint_esprit` (Saint-Esprit), `reseaux_sociaux` (Réseaux sociaux),
`affiche_tract` (Affiche / tract), `bouche_a_oreille` (Bouche-à-oreille), `passage` (Passage devant l'église), `autre` (Autre).

### Raisons du retour (visite 2) — `return_reasons[]`
`enseignement` (L'enseignement de la Parole), `chaleur_fraternelle` (La chaleur fraternelle),
`louange_adoration` (La louange et l'adoration), `accueil` (L'accueil reçu), `autres` (Autre(s) raison(s)).

### Motivation (visite 3) — `visit_reason`
`nouveau_resident` (Nouveau résident dans la ville), `devenir_membre` (Désir de devenir membre),
`vacances` (De passage / vacances), `autres` (Autre raison).

### Rôles et permissions (abilities)

| Ability | lecteur | moderateur | super_admin |
|---|:-:|:-:|:-:|
| `visitors.view` (liste, fiche, membres, stats, rapports) | ✓ | ✓ | ✓ |
| `visitors.update` (édition des champs de profil) | | ✓ | ✓ |
| `notes.create` | | ✓ | ✓ |
| `visitors.convert` / `visitors.unconvert` | | | ✓ |
| `visitors.delete` | | | ✓ |
| `visitors.export` | | | ✓ |
| `reports.send` | | | ✓ |
| `recipients.manage` | | | ✓ |
| `rotations.manage` | | | ✓ |
| `settings.update` (verset, nom, URL publique) | | | ✓ |
| `users.manage` | | | ✓ |
| `audit.view` | | | ✓ |

Suppression d'une note : son auteur ou un super_admin.
Règles `users.manage` : impossible de se rétrograder, se désactiver ou se supprimer soi-même ;
impossible de rétrograder / désactiver / supprimer le dernier super_admin actif.

### Pays acceptés (identification et WhatsApp)
`CI` (+225, 10 chiffres, défaut), `SN` (+221), `ML` (+223), `BF` (+226), `GH` (+233), `TG` (+228),
`BJ` (+229), `GN` (+224), `CM` (+237), `CD` (+243), `GA` (+241), `FR` (+33), `BE` (+32), `US` (+1),
`OTHER` (numéro international complet saisi avec son indicatif, ex. `+44 7…`).
Le serveur normalise en E.164 avec libphonenumber (région = `country`, sauf `OTHER`) ;
un numéro invalide → 422 sur le champ concerné. Le client accepte chiffres et espaces
(et `+` uniquement pour `OTHER`), un « 0 » initial local est géré par libphonenumber côté serveur.

## 2. API publique (sans session, sans cookie, sans CSRF)

Préfixe `/api/public`. Middleware : limite `public` = 300 requêtes/min par IP (IP réelle via
TrustProxies). **Aucune réponse publique ne contient de donnée personnelle.**

**Bornes du corps de requête** (applicatives, indépendantes de la configuration PHP —
`App\Http\Middleware\LimitPublicPayload`, placé en tête de la pile globale, **avant** `TrimStrings`
qui parcourt tout le corps) : 256 Kio maximum, profondeur JSON ≤ 8, chaîne ≤ 4096 caractères.
Au-delà → **413 `payload_too_large`**, message unique.
En complément, `answers` est borné avant toute validation (≤ 40 clés au total, ≤ 20 éléments par
tableau, profondeur ≤ 3) → 422 avec un seul message : une requête anonyme ne peut pas provoquer
un coût de validation proportionnel à sa taille.

### GET `/api/public/config`
Cache 60 s. Réponse 200 :
```json
{
  "church_name": "Église La Maison de la Destinée",
  "public_url": "https://registre.exemple.org",
  "verse": { "ref": "Jean 21:17", "text": "Si tu m'aimes, pais mes brebis." },
  "current_family": { "id": 4, "name": "Force" },
  "families": [ { "id": 1, "name": "Puissance" } ]
}
```
`current_family` peut être `null` si aucune rotation n'est définie pour le mois.

### POST `/api/public/identify`
Limites supplémentaires :
- **5 jetons/heure par numéro** normalisé (clé = hash du numéro). Seules les identifications qui
  **délivrent un jeton** sont comptées : une réponse `complete` / `done_today` ou une 422 n'entame
  pas le quota de la personne.
- **200 identifications/heure par IP** (défaut, variable `RATE_LIMIT_IDENTIFY_PER_IP`, voir
  `config/rate-limits.php`). Ferme l'oracle de présence (≈18 000 numéros testables/heure sans elle)
  tout en absorbant un dimanche chargé : tous les téléphones du Wi-Fi de l'Église partagent une
  seule IP publique, ne pas descendre sous ~150.

Les réponses de cette route ne portent **aucun en-tête `X-RateLimit-*`** (ils renseigneraient
l'attaquant sur l'état exact des compteurs) ; seul `Retry-After` accompagne une 429.

Requête : `{ "country": "CI", "phone": "07 00 00 00 00" }`
Réponse 200 (même forme dans tous les cas) :
```json
{ "step": 1, "session_token": "…", "expires_in": 900 }
```
- `step` ∈ `1 | 2 | 3 | "complete" | "done_today"`.
  - `1` : numéro inconnu ; `2`/`3` : prochaine visite ; `"complete"` : 3 visites déjà faites ;
    `"done_today"` : une visite existe déjà aujourd'hui pour ce numéro.
  - Pour `complete` et `done_today` : `session_token` = `null`, `expires_in` = `null`.
- Risque résiduel assumé et documenté : `step` révèle le nombre de visites d'un numéro.
  Atténuation : limites de débit ; seule une vérification OTP le supprimerait (hors périmètre v2).
- Jeton de parcours : chaîne opaque chiffrée + signée (APP_KEY) contenant
  `{ phone_e164, country, step, jti, exp }`, validité 15 min, à usage unique.

### POST `/api/public/visits`
Requête :
```json
{
  "session_token": "…",
  "idempotency_key": "uuid-v4",
  "consent": true,
  "answers": { }
}
```
Ordre de traitement (obligatoire) :
1. Si `idempotency_key` existe déjà → 200 avec le même corps que la création (même si le jeton
   a expiré ou a été consommé), à condition qu'il corresponde au même numéro ; sinon 409 `idempotency_conflict`.
2. Jeton invalide → 401 `token_invalid` ; expiré → 410 `token_expired` ; déjà utilisé → 409 `token_used`.
3. Transaction : l'étape du jeton doit correspondre au nombre de visites + 1, sinon 409 `step_mismatch`.
4. Insertion de la visite ; index uniques `(visitor_id, visit_date)` → 409 `already_today`
   et `(visitor_id, visit_number)`.
5. Mise à jour du statut, marquage du jeton comme consommé.

`answers` selon l'étape du jeton :

**Étape 1** (`consent` doit valoir `true`, sinon 422 ; stocké dans `consent_at`) :
```json
{
  "full_name": "≤100, requis",
  "commune": "≤80, requis",
  "quartier": "≤80, requis",
  "source": "enum requis",
  "source_other": "≤200, requis si source=autre",
  "invited_by": "≤100, requis si source=invite_membre",
  "inviter_family_id": "id famille, optionnel (seulement si invite_membre)",
  "whatsapp": { "country": "CI", "number": "07 00 00 00 00" },
  "whatsapp_same_as_phone": false,
  "wants_whatsapp_group": false
}
```
`whatsapp` optionnel (`null` accepté). Si `whatsapp_same_as_phone` = true, le WhatsApp stocké = le numéro
identifié. Si `wants_whatsapp_group` = true, un WhatsApp (explicite ou « même numéro ») est requis.
Un WhatsApp vide est stocké `NULL`.

**Étape 2** :
```json
{ "return_reasons": ["enseignement"], "return_reasons_other": "≤200, requis si 'autres' présent" }
```
`return_reasons` : 1 à 5 valeurs distinctes de l'enum.

**Étape 3** :
```json
{ "visit_reason": "enum requis", "visit_reason_other": "≤200, requis si visit_reason=autres" }
```

Le nom n'est jamais modifiable depuis le public (il n'est demandé qu'à l'étape 1).

Réponse 201 (création) ou 200 (rejeu idempotent) :
```json
{ "visit_number": 1, "family": { "id": 4, "name": "Force" }, "completed": false }
```
`completed` = true après la visite 3. `family` peut être `null`.

## 3. Authentification admin (Sanctum SPA, cookie httpOnly, CSRF)

Flux : `GET /sanctum/csrf-cookie` (204, pose `XSRF-TOKEN`) puis requêtes avec en-tête
`X-XSRF-TOKEN` (valeur du cookie décodée) et `credentials: 'same-origin'`.
Session en base, expiration après 120 min d'inactivité. Cookie `SameSite=Lax`, `HttpOnly`, `Secure` en prod.
Toute requête d'un compte désactivé → session détruite + 401.

Objet `User` :
```json
{ "id": 1, "name": "Jean K.", "email": "jean@exemple.org", "role": "super_admin",
  "is_active": true, "two_factor_enabled": false, "last_login_at": "2026-09-22T08:00:00+00:00",
  "created_at": "…", "invitation_pending": false }
```

| Méthode | Route | Corps | Réponse |
|---|---|---|---|
| POST | `/api/auth/login` | `{ email, password }` | 200 `{ user }` ou `{ two_factor_required: true }` ; 422 message générique « Identifiants incorrects. » ; 423 `account_locked` (**uniquement si le mot de passe est correct**) ; 429 |
| POST | `/api/auth/two-factor/challenge` | `{ code }` ou `{ recovery_code }` | 200 `{ user }` ; 422 (code faux) ; 422 `two_factor_expired` si la connexion en attente a expiré (> 5 min), n'existe pas ou a été abandonnée (compte ou 2FA désactivés entre-temps) : le SPA revient à la saisie des identifiants |
| POST | `/api/auth/logout` | — | 204 (session invalidée côté serveur) |
| GET | `/api/auth/me` | — | 200 `{ user, abilities: ["visitors.view", …] }` ; 401 |
| PATCH | `/api/auth/profile` | `{ name?, email?, current_password? }` (`current_password` **obligatoire si l'email change**) | 200 `{ user }` ; 422 sur `current_password` |
| PUT | `/api/auth/password` | `{ current_password, password, password_confirmation }` | 204 ; invalide toutes les autres sessions de l'utilisateur |
| POST | `/api/auth/forgot-password` | `{ email }` | 200 toujours (pas d'énumération) |
| POST | `/api/auth/reset-password` | `{ token, email, password, password_confirmation }` | 204 ; sert aussi à accepter une invitation |
| POST | `/api/auth/two-factor/enable` | `{ password }` | 200 `{ secret, otpauth_url, recovery_codes: [8 codes] }` ; 409 `two_factor_already_enabled` |
| POST | `/api/auth/two-factor/confirm` | `{ code }` | 204 |
| DELETE | `/api/auth/two-factor` | `{ password }` | 204 |

Politique de mot de passe : 12 caractères minimum, lettres et chiffres.
Hachage argon2id géré uniquement par Laravel (cast `hashed`).
Authentification **uniquement par cookie de session** : aucun jeton personnel n'est émis et un
en-tête `Authorization: Bearer …` n'authentifie jamais (le modèle `User` n'utilise pas `HasApiTokens`).

Limites login :
- 5 échecs / 15 min par couple (email + IP) → 429 avec `Retry-After` ;
- **20 échecs / heure sur un compte depuis une même IP** → cette IP est écartée 15 min pour ce
  compte ; le titulaire continue de se connecter normalement depuis ailleurs ;
- **100 échecs / heure sur un compte, toutes IP confondues** → verrouillage global 15 min
  (`users.locked_until`), pour le seul cas d'une attaque distribuée.

Un seuil unique « par compte » ferait du verrouillage une arme de déni de service (quelques IP
suffisaient à exclure un administrateur) : d'où le seuil par (compte + IP) et un seuil global
cinq fois plus haut.

**Pas d'énumération** : un mot de passe faux renvoie TOUJOURS la 422 générique
« Identifiants incorrects. », que le compte soit inconnu, invité, désactivé ou verrouillé.
La **423 `account_locked` n'apparaît qu'avec des identifiants corrects**. Temps de réponse constant
(vérification contre un hash factice si l'email est inconnu).
E-mail de notification de verrouillage : **au plus un par heure et par compte**.

Liens de réinitialisation / invitation : `{APP_URL}/admin/reinitialiser?token=…&email=…` ; le lien d'invitation
ajoute `&invitation=1` (le SPA affiche alors « Bienvenue — choisissez votre mot de passe »).
(invitation valable 48 h, réinitialisation 60 min).
Un lien n'est **jamais utilisable après la désactivation du compte** : les jetons en cours sont purgés
à la désactivation ET la consommation exige `is_active = true` (un lien déjà parti reste donc mort).
Les notifications d'invitation et de réinitialisation sont mises en file avec une charge utile
**chiffrée** (`ShouldBeEncrypted`) : le jeton n'apparaît en clair ni dans `jobs` ni dans `failed_jobs`.

## 4. API admin

Préfixe `/api/admin`, middleware `auth:sanctum` + compte actif + limite `admin` (120/min par utilisateur).
Toute action d'écriture sensible écrit une ligne dans `audit_logs`.

### Stats — GET `/api/admin/stats` (`visitors.view`, cache 5 min)
```json
{
  "total_visitors": 0, "new_today": 0, "visits_this_month": 0,
  "by_status": { "prospect": 0, "recurrent": 0, "membre_potentiel": 0, "membre": 0 },
  "current_family": { "id": 4, "name": "Force" }, "next_family": { "id": 5, "name": "Honneur" },
  "visits_by_family": [ { "family": { "id": 1, "name": "Puissance" }, "v1": 0, "v2": 0, "v3": 0 } ],
  "monthly_visits": [ { "year": 2026, "month": 9, "count": 0 } ]
}
```
`visits_by_family` : année civile en cours, toujours les 7 familles (même à 0). `monthly_visits` : 12 derniers mois, du plus ancien au plus récent.
Cache de 5 min : une visite enregistrée peut apparaître avec ce délai.

### Visiteurs
`VisitorSummary` :
```json
{ "id": 1, "full_name": "…", "phone": "+2250700000000", "whatsapp": null, "commune": "…", "quartier": "…",
  "status": "prospect", "visit_count": 1, "first_visit_date": "2026-09-20", "last_visit_date": "2026-09-20",
  "created_at": "…" }
```
`VisitorDetail` = `VisitorSummary` +
```json
{ "source": "invite_membre", "source_other": null, "invited_by": "…", "inviter_family": { "id": 1, "name": "…" },
  "wants_whatsapp_group": false, "consent_at": "…",
  "visits": [ { "id": 1, "visit_number": 1, "visit_date": "2026-09-20", "family": { "id": 4, "name": "Force" }, "answers": {} } ],
  "notes": [ { "id": 1, "body": "…", "author": { "id": 2, "name": "…" }, "created_at": "…", "can_delete": true } ],
  "member": { "converted_at": "…", "converted_by": { "id": 1, "name": "…" } } }
```
`member` = `null` si non converti. `answers` contient les réponses de l'étape (objet vide pour la visite 1).

| Méthode | Route | Ability | Détails |
|---|---|---|---|
| GET | `/api/admin/visitors` | view | filtres combinables (ET) : `search` (≤100, LIKE paramétré sur nom, commune, quartier, téléphone), `status` ∈ enum ∪ {`non_membre`}, `family_id` (a au moins une visite accueillie par cette famille), `from`/`to` (`YYYY-MM-DD`, sur la date de 1re visite), `sort` ∈ {`-created_at` (défaut), `created_at`, `full_name`, `-last_visit_date`} ; paginé |
| GET | `/api/admin/visitors/{id}` | view | `{ data: VisitorDetail }` |
| PATCH | `/api/admin/visitors/{id}` | update | `{ full_name?, whatsapp? ({country,number}|null), commune?, quartier?, invited_by?, wants_whatsapp_group? }` → `{ data: VisitorDetail }` |
| DELETE | `/api/admin/visitors/{id}` | delete | 204 (supprime visites, notes, membre en cascade) |
| POST | `/api/admin/visitors/{id}/convert` | convert | 200 `{ data: VisitorDetail }` ; 409 `not_eligible` si statut ≠ `membre_potentiel` ; 409 `already_member` |
| DELETE | `/api/admin/visitors/{id}/convert` | unconvert | 200 `{ data: VisitorDetail }` (statut recalculé) ; 409 `not_member` |
| POST | `/api/admin/visitors/{id}/notes` | notes.create | `{ body ≤2000 }` → 201 `{ data: Note }` |
| DELETE | `/api/admin/notes/{id}` | auteur ou super_admin | 204 |
| GET | `/api/admin/members` | view | `search`, paginé ; items = `VisitorSummary` + `converted_at`, `converted_by` |

### Exports — `visitors.export`, mêmes filtres que la liste
`GET /api/admin/exports/visitors.csv` | `.xlsx` | `.pdf` — téléchargement direct (cookie de session),
génération serveur en streaming, aucun plafond silencieux. CSV : séparateur `;`, UTF-8 avec BOM,
cellules commençant par `= + - @ \t \r` préfixées d'une apostrophe. XLSX : toutes les cellules sont typées texte
(aucune formule possible), sans préfixe. PDF : > 1 000 lignes → 422 `too_many_rows` (limite de dompdf sur
un hébergement mutualisé ; message invitant à utiliser CSV/Excel). Chaque export est journalisé avec
`meta = { filters (sans le texte recherché), rows }`. Identifiants non numériques → 404.

### Rapports mensuels
Un rapport = (année, mois) pour la famille de service de ce mois (`family_rotations`), calculé à la volée.
- `GET /api/admin/reports?year=2026` (view) →
```json
{ "data": [ { "year": 2026, "month": 9, "family": { "id": 4, "name": "Force" },
              "counts": { "v1": 0, "v2": 0, "v3": 0, "total": 0, "conversions": 0 },
              "dispatch": { "sent_at": "…", "recipients": ["a@b.c"] } } ],
  "meta": { "available_years": [2026] } }
```
  Mois de l'année demandée jusqu'au mois courant inclus, du plus récent au plus ancien.
  `available_years` : de l'année de la première visite (ou année courante) à l'année courante.
- `GET /api/admin/reports/{year}/{month}` (view) → `{ data: { …ligne ci-dessus…, "visitors": [ { "id", "full_name", "phone", "visit_number", "visit_date" } ], "conversions": [ { "id", "full_name", "converted_at" } ] } }`
- `POST /api/admin/reports/{year}/{month}/send` (reports.send) `{ force?: bool }` → 200 `{ data: dispatch }` ;
  409 `already_sent` si déjà envoyé sans `force` ; 409 `send_in_progress` si un envoi du même mois est en cours ;
  422 `no_recipients` ; 503 `mail_failed` si le transport échoue (aucune ligne d'envoi n'est alors écrite).
- Mois futur, invalide ou antérieur à `available_years` → 404 `not_found` (consultation et envoi) ;
  année hors de `available_years` sur la liste → 422 sur `year`. Un mois sans rotation renvoie 200 avec `family: null`.
- Les rapports et l'e-mail de test sont envoyés de façon **synchrone** (erreur visible immédiatement) ;
  un seul message par rapport, adressé à tous les destinataires **en copie cachée** (`bcc`), avec
  `to` = l'adresse d'expédition de l'Église (`MAIL_FROM_ADDRESS`) : les destinataires ne se voient pas
  mutuellement. La ligne `report_dispatches.recipients` enregistre la liste réelle.
- Définition « conversions » : membres convertis pendant le mois dont la 1re visite a été accueillie par la famille du rapport.

### Destinataires des rapports — `recipients.manage`
`Recipient` : `{ id, family: {id,name}|null, name, email, active }` (`family` null = reçoit tous les rapports).
`GET /api/admin/report-recipients` → `{ data: [Recipient] }` (globaux d'abord, puis par position de famille, puis par nom) ;
`POST` `{ family_id|null, name ≤100, email, active }` → 201 `{ data: Recipient }` ;
`PATCH /{id}` (mêmes champs, tous optionnels) → `{ data: Recipient }` ; `DELETE /{id}` → 204 ;
unicité (family_id, email) vérifiée aussi côté application (MariaDB ne l'impose pas quand family_id est NULL) → 422.
`POST /api/admin/report-recipients/test` `{ email? }` → envoie un e-mail de test (par défaut à l'utilisateur connecté) → 204 ;
422 sur `email` si invalide ; **429 au-delà de 5 envois par heure et par utilisateur** (limite dédiée : cette route
écrit vers une adresse arbitraire) ; 503 `mail_failed`. Chaque envoi écrit `report.test_sent` dans `audit_logs`
(`meta.email` = destinataire). Adresses enregistrées en minuscules ; `active` vaut `true` par défaut.

### Rotation des familles
`GET /api/admin/families` (view) → `{ data: [ { id, name, active, position } ] }`
`GET /api/admin/rotations?from=2026-01&months=12` (view, `months` 1–36) → `{ data: [ { year, month, family: {id,name}|null } ] }`
`PUT /api/admin/rotations/{year}/{month}` (rotations.manage) `{ family_id }` → `{ data: { year, month, family } }`

### Paramètres — `GET` (view) / `PUT` (settings.update) `/api/admin/settings`
```json
{ "church_name": "…", "public_url": "https://…",
  "verse": { "preset": 0, "ref": "Jean 21:17", "text": "…" },
  "verse_presets": [ { "ref": "Jean 21:17", "text": "Si tu m'aimes, pais mes brebis." },
                     { "ref": "Jean 3:16", "text": "Car Dieu a tant aimé le monde qu'il a donné son Fils unique, afin que quiconque croit en lui ne périsse point, mais qu'il ait la vie éternelle." },
                     { "ref": "Psaumes 23:1", "text": "L'Éternel est mon berger : je ne manquerai de rien." } ] }
```
**Sans enveloppe `data`** (comme `/api/admin/stats`). PUT accepte `{ church_name? ≤120, public_url? (URL https en prod), verse?: { preset: 0|1|2 } | { preset: null, ref ≤60, text ≤500 } }`
et renvoie le même objet que GET.
Le QR code est généré côté client à partir de `public_url`.

### Administrateurs — `users.manage`
`GET /api/admin/users` → `{ data: [User] }` ;
`POST /api/admin/users` `{ name ≤100, email, role }` → 201 `{ data: User }` + e-mail d'invitation (lien de définition du mot de passe, 48 h) ;
`PATCH /api/admin/users/{id}` `{ name?, email?, role?, is_active? }` → `{ data: User }` ; `DELETE /api/admin/users/{id}` → 204 ;
`POST /api/admin/users/{id}/invitation` → renvoie l'invitation (204) ; 409 `invitation_not_pending` si le compte a déjà un mot de passe
**ou s'il est désactivé** (le lien serait mort : la consommation exige `is_active`).
Violations des règles (soi-même, dernier super_admin) → 409 `forbidden_self_change` / `last_super_admin`.
Désactivation → toutes les sessions du compte sont supprimées **et ses liens de réinitialisation / d'invitation sont purgés**.

### Journal d'audit — GET `/api/admin/audit-logs` (`audit.view`)
Filtres `action`, `user_id`, paginé (plus récent d'abord). Item : `{ id, action, user: {id,name}|null, subject_type, subject_id, ip, created_at, meta }`.
`subject_type` est un alias court (morph map imposée) : `visitor`, `visit`, `note`, `member`, `user`, `family`, `rotation`, `recipient`, `report`, ou `null`
(`settings.updated` n'a pas de sujet : les champs modifiés sont dans `meta.fields`).
`meta` ne contient jamais de mot de passe, jeton ni donnée personnelle de visiteur (seulement ids et champs modifiés).
Actions : `auth.login`, `auth.login_failed`, `auth.account_locked`, `auth.logout`, `auth.password_changed`,
`auth.two_factor_enabled`, `auth.two_factor_disabled`,
`visitor.updated`, `visitor.deleted`, `visitor.converted`, `visitor.unconverted`, `note.created`, `note.deleted`,
`user.created`, `user.updated`, `user.deleted`, `settings.updated`, `rotation.updated`, `recipient.created`,
`recipient.updated`, `recipient.deleted`, `report.sent`, `report.test_sent`, `export.csv`, `export.xlsx`, `export.pdf`,
`visitors.purged` et `audit_logs.purged` (commande de rétention, sans utilisateur, `meta.count`).
- `auth.login_failed` : un échec d'identifiants ou de code 2FA. `user_id` = le compte visé quand l'adresse
  correspond à un compte éligible, sinon `null` ; `meta.stage` = `password` | `two_factor`. Ni mot de passe,
  ni code, ni adresse saisie ne sont journalisés.
- `auth.account_locked` : `meta.scope` = `ip` (cette IP écartée 15 min) | `account` (verrouillage global),
  `meta.minutes` = 15.
- Ces deux actions écrivent une **IP tronquée** (`203.0.x.x`, `2001:db8:…`) : l'appelant n'est pas
  authentifié, son adresse complète n'a pas à être conservée. Les autres actions gardent l'IP entière.
Exception assumée : `user.deleted` conserve en `meta` l'e-mail et le rôle de l'administrateur supprimé (donnée d'administration, traçabilité) ;
`report.test_sent` conserve l'adresse de test (la route écrit vers une adresse arbitraire : il faut savoir qui a écrit à qui).

## 5. Divers

- `GET /api/health` → 200 `{ "status": "ok", "db": "ok" }`, sans session ; **60 requêtes/min par IP**
  (la sonde interroge la base : sans limite, elle sert de levier d'épuisement des connexions).
- SPA : le build de `web/` est copié dans `api/public/` ; `index.html` y est renommé `spa.html`.
  Toute route GET non-API, non-fichier (`/`, `/visite/…`, `/admin/…`, `/qrcode`) renvoie `spa.html`
  via la route de repli Laravel (en-tête `Cache-Control: no-cache`), ce qui applique aussi les en-têtes de sécurité.
- En-têtes (middleware global) : CSP `default-src 'self'; script-src 'self'; style-src 'self' 'unsafe-inline';
  img-src 'self' data: blob:; font-src 'self'; connect-src 'self'; frame-ancestors 'none'; base-uri 'self';
  form-action 'self'; object-src 'none'`, HSTS (prod), `X-Frame-Options: DENY`, `X-Content-Type-Options: nosniff`,
  `Referrer-Policy: strict-origin-when-cross-origin`, `Permissions-Policy: camera=(), microphone=(), geolocation=()`.
- Tâches planifiées (cron hPanel `php artisan schedule:run` chaque minute) :
  `reports:dispatch` chaque jour à 08:00 (envoie le rapport du mois précédent s'il n'a pas été envoyé — rattrapage automatique),
  `visitors:purge` chaque jour à 03:00 (visiteurs non membres sans visite depuis 24 mois, suppression en cascade,
  **et purge des `audit_logs` de plus de 24 mois** : le journal contient des IP et des e-mails d'administrateurs).
  Une purge qui ne supprime rien n'écrit aucune ligne dans le journal,
  `rotations:extend` le 1er de chaque mois (garantit 24 mois de rotation à l'avance),
  `queue:work --stop-when-empty --max-time=50` chaque minute (sans chevauchement).
- E-mails : API Brevo (transport HTTP), templates Blade échappés. Rapports et e-mail de test : envoi synchrone.
  Notifications de compte (invitation, réinitialisation, verrouillage) : file d'attente `database` ;
  celles qui portent un jeton sont chiffrées dans la file (`ShouldBeEncrypted`).
- Sessions : `SESSION_ENCRYPT=true` par défaut (contenu des sessions chiffré en base).
- Export CSV : toute cellule dont le premier caractère **significatif** (blancs et caractères invisibles
  de tête ignorés, comme le fait un tableur) est `= + - @ \t \r` est préfixée d'une apostrophe.
