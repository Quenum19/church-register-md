#!/usr/bin/env bash
# Construit l'archive de production de Church Register (API Laravel + SPA).
#
#   scripts/build-release.sh [-o|--output <fichier.tar.gz>]
#
# Étapes (toutes dans un dossier temporaire : api/ et web/ du dépôt ne sont jamais modifiés) :
#   1. copie de api/ sans les fichiers de dev/secrets (.env*, vendor, tests, storage, caches…) ;
#   2. composer install --no-dev --optimize-autoloader ;
#   3. copie de web/ (sans node_modules/dist), npm ci && npm run build ;
#   4. web/dist/* -> public/, index.html -> public/spa.html ;
#   5. contrôles (fichiers attendus présents, fichiers interdits absents) puis archive tar.gz.
#
# L'archive contient la racine de l'application Laravel (artisan, app/, public/, vendor/…),
# SANS storage/ ni .env : le serveur les relie à DEPLOY_PATH/shared (deploy/remote-deploy.sh).
#
# Prérequis : bash, php 8.3+, composer 2, node 22 + npm, tar, gzip, find.
# Fonctionne sous Linux (runner GitHub) et sous Windows avec Git Bash.
set -euo pipefail

ROOT="$(cd "$(dirname "${BASH_SOURCE[0]}")/.." && pwd)"
OUTPUT="$ROOT/release.tar.gz"

log() { printf '\n\033[1;34m==> %s\033[0m\n' "$*"; }
die() { printf '\033[1;31mErreur : %s\033[0m\n' "$*" >&2; exit 1; }

usage() { awk 'NR > 1 && /^#/ { sub(/^# ?/, ""); print; next } NR > 1 { exit }' "${BASH_SOURCE[0]}"; }

while [[ $# -gt 0 ]]; do
  case "$1" in
    -o | --output)
      [[ $# -ge 2 ]] || die "$1 attend un chemin de fichier."
      OUTPUT="$2"
      shift 2
      ;;
    --output=*)
      OUTPUT="${1#--output=}"
      shift
      ;;
    -h | --help)
      usage
      exit 0
      ;;
    *) die "argument inconnu : $1 (voir --help)" ;;
  esac
done

case "$OUTPUT" in
  [A-Za-z]:[\\/]*)
    # Chemin Windows (C:\…) : GNU tar prendrait « C: » pour un hôte distant.
    command -v cygpath > /dev/null 2>&1 || die "chemin Windows non pris en charge ici : $OUTPUT"
    OUTPUT="$(cygpath -u "$OUTPUT")"
    ;;
  /*) ;;
  *) OUTPUT="$PWD/$OUTPUT" ;;
esac

# --- Prérequis -------------------------------------------------------------------------
for tool in php composer node npm tar gzip find; do
  command -v "$tool" > /dev/null 2>&1 || die "commande « $tool » introuvable dans le PATH."
done
[[ -f "$ROOT/api/composer.json" && -f "$ROOT/api/composer.lock" ]] || die "api/composer.json ou api/composer.lock absent."
[[ -f "$ROOT/web/package.json" && -f "$ROOT/web/package-lock.json" ]] || die "web/package.json ou web/package-lock.json absent."

php -r 'exit(PHP_VERSION_ID >= 80300 ? 0 : 1);' || die "PHP 8.3 minimum requis (trouvé : $(php -r 'echo PHP_VERSION;'))."
node_major="$(node -p 'process.versions.node.split(".")[0]')"
if [[ "$node_major" != "22" ]]; then
  printf 'Attention : Node %s détecté, la CI et la production utilisent Node 22.\n' "$(node -v)" >&2
fi

# Révision : variable de la CI, sinon git, sinon « inconnue ».
REVISION="${GITHUB_SHA:-}"
if [[ -z "$REVISION" ]] && command -v git > /dev/null 2>&1; then
  REVISION="$(git -C "$ROOT" rev-parse HEAD 2> /dev/null || true)"
  if [[ -n "$REVISION" ]] && [[ -n "$(git -C "$ROOT" status --porcelain -- api web 2> /dev/null)" ]]; then
    REVISION="$REVISION-dirty"
  fi
fi
REVISION="${REVISION:-inconnue}"

WORK="$(mktemp -d "${TMPDIR:-/tmp}/church-release.XXXXXX")"
trap 'rm -rf "$WORK"' EXIT
APP="$WORK/app"
WEB="$WORK/web"
mkdir -p "$APP" "$WEB"

# Copie un arbre source vers une destination en excluant des chemins (find + tar : pas de rsync
# requis, fonctionne avec GNU tar et bsdtar).
#   copy_tree <source> <destination> <expression find de prune…> -- <filtres de fichiers…>
copy_tree() {
  local src="$1" dest="$2"
  shift 2
  local prune=() filters=()
  while [[ $# -gt 0 && "$1" != "--" ]]; do
    prune+=("$1")
    shift
  done
  [[ $# -gt 0 ]] && shift
  filters=("$@")
  (
    cd "$src"
    find . \( "${prune[@]}" \) -prune -o \( -type f -o -type l \) "${filters[@]}" -print0
  ) | (cd "$src" && tar --null -T - -cf -) | (cd "$dest" && tar -xf -)
}

# --- 1. Copie de l'API -----------------------------------------------------------------
log "Copie de api/ (sans fichiers de développement ni secrets)"
copy_tree "$ROOT/api" "$APP" \
  -path ./vendor -o -path ./node_modules -o -path ./storage -o -path ./tests \
  -o -path ./.git -o -path ./.github -o -path ./.idea -o -path ./.vscode -o -path ./.fleet \
  -o -path ./.zed -o -path ./.nova -o -path ./.cursor -o -path ./.codex -o -path ./.claude \
  -o -path ./.phpunit.cache -o -path ./coverage \
  -o -path ./public/build -o -path ./public/hot -o -path ./public/storage \
  -- \
  ! -name '.env' ! -name '.env.*' ! -name '*.log' ! -name '.DS_Store' ! -name 'Thumbs.db' \
  ! -name '*.sqlite' ! -name '*.sqlite-journal' ! -name '.phpunit.result.cache' \
  ! -path './bootstrap/cache/*.php' \
  ! -path './phpunit.xml' ! -path './phpunit.xml.dist' ! -path './phpunit.dist.xml' \
  ! -path './phpstan.neon' ! -path './phpstan.neon.dist' ! -path './phpstan-baseline.neon' \
  ! -path './pint.json' ! -path './rector.php' ! -path './.php-cs-fixer*' ! -path './.styleci.yml' \
  ! -path './.editorconfig' ! -path './.gitattributes' ! -path './.gitignore' ! -path './.npmrc' \
  ! -path './package.json' ! -path './package-lock.json' ! -path './vite.config.*' \
  ! -path './README.md' ! -path './CHANGELOG.md' ! -path './auth.json' ! -path './_ide_helper*.php' \
  ! -path './.phpstorm.meta.php' ! -path './Homestead.*' ! -path './docker-compose*' ! -path './compose.y*ml'

# Squelette minimal pour que les scripts Composer (artisan package:discover) puissent démarrer.
mkdir -p "$APP/bootstrap/cache" \
  "$APP/storage/app" "$APP/storage/framework/cache/data" "$APP/storage/framework/sessions" \
  "$APP/storage/framework/views" "$APP/storage/logs"

# --- 2. Dépendances PHP de production --------------------------------------------------
log "composer install --no-dev --optimize-autoloader"
(
  cd "$APP"
  COMPOSER_NO_INTERACTION=1 composer install \
    --no-dev --optimize-autoloader --no-interaction --no-progress --prefer-dist
)

# storage/ est remplacé sur le serveur par un lien vers shared/storage.
rm -rf "$APP/storage"

# --- 3. Build du SPA -------------------------------------------------------------------
log "Copie de web/ et build du SPA (npm ci && npm run build)"
copy_tree "$ROOT/web" "$WEB" \
  -path ./node_modules -o -path ./dist -o -path './.build-*' -o -path ./coverage \
  -o -path ./.git -o -path ./.idea -o -path ./.vscode \
  -- \
  ! -name '.env.local' ! -name '.env.*.local' ! -name '*.log' ! -name '.DS_Store' ! -name 'Thumbs.db'
(
  cd "$WEB"
  npm ci --no-audit --no-fund
  npm run build
)
[[ -f "$WEB/dist/index.html" ]] || die "web/dist/index.html absent après le build."

# --- 4. Intégration du SPA dans public/ ------------------------------------------------
log "Copie de web/dist dans public/ (index.html -> spa.html)"
for reserved in index.php .htaccess spa.html; do
  [[ ! -e "$WEB/dist/$reserved" ]] || die "web/dist contient « $reserved », qui écraserait un fichier de Laravel."
done
cp -R "$WEB/dist/." "$APP/public/"
mv "$APP/public/index.html" "$APP/public/spa.html"

printf '%s\n' "$REVISION" > "$APP/REVISION"

# --- 5. Contrôles de l'arborescence ----------------------------------------------------
log "Contrôles de la release"
# resources/images/logo.png : logo des exports PDF, propre à api/ (jamais repris de web/).
for required in artisan composer.json vendor/autoload.php bootstrap/app.php public/index.php public/.htaccess public/spa.html resources/images/logo.png REVISION; do
  [[ -e "$APP/$required" ]] || die "fichier attendu absent de la release : $required"
done
[[ -d "$APP/public/assets" ]] || die "public/assets/ absent : le build du SPA n'a rien produit ?"

forbidden="$(
  cd "$APP"
  find . -path ./vendor -prune -o \( -name '.env' -o -name '.env.*' -o -name 'node_modules' -o -name '.git' \
    -o -name 'phpunit.xml' -o -name '.phpunit.cache' -o -name '.phpunit.result.cache' -o -name 'auth.json' \
    -o -name '*.log' -o -name '*.sqlite' \) -print
  [[ ! -e tests ]] || echo ./tests
  [[ ! -e storage ]] || echo ./storage
)"
[[ -z "$forbidden" ]] || die "fichiers interdits dans la release :
$forbidden"

# --- 6. Archive ------------------------------------------------------------------------
log "Création de l'archive"
mkdir -p "$(dirname "$OUTPUT")"
rm -f "$OUTPUT"
tar -czf "$OUTPUT" -C "$APP" .

size="$(du -h "$OUTPUT" | cut -f1)"
if command -v sha256sum > /dev/null 2>&1; then
  checksum="$(sha256sum "$OUTPUT" | cut -d' ' -f1)"
else
  checksum="(sha256sum indisponible)"
fi
printf '\nRelease prête : %s\n  révision : %s\n  taille   : %s\n  sha256   : %s\n' "$OUTPUT" "$REVISION" "$size" "$checksum"
