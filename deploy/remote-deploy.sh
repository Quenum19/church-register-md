#!/usr/bin/env bash
# Déploiement côté serveur de Church Register (Hostinger, SSH). Envoyé et lancé par
# .github/workflows/deploy.yml ; utilisable aussi à la main en SSH.
#
# Usage :
#   remote-deploy.sh <sha> [archive.tar.gz]   déploie la release <sha>
#                                             (archive par défaut : DEPLOY_PATH/uploads/<sha>.tar.gz)
#   remote-deploy.sh --rollback [<sha>]       repointe « current » sur la release précédente (ou <sha>)
#   remote-deploy.sh --status                 affiche la release active et les releases disponibles
#
# Variables d'environnement :
#   DEPLOY_PATH    racine du déploiement, hors racine web (défaut : $HOME/church-register)
#   PHP_BIN        PHP CLI 8.3+ (défaut : php ; Hostinger : ex. /opt/alt/php83/usr/bin/php)
#   KEEP_RELEASES  nombre de releases conservées (défaut : 5)
#   WEB_ROOT_MIRROR  plan B, vide par défaut. Si la racine web du sous-domaine ne peut pas être un
#                  lien symbolique vers current/public, chemin absolu de cette racine web : après
#                  chaque bascule, les fichiers statiques de current/public y sont copiés et son
#                  index.php délègue à current/public/index.php (voir docs/deploiement.md).
#
# Arborescence gérée :
#   DEPLOY_PATH/
#     current -> releases/<sha>     lien symbolique, bascule atomique (ln -sfn + mv -T)
#     releases/<sha>/               une release par commit ; .env et storage -> ../../shared
#     releases/.history             ordre des déploiements (un sha par ligne, le plus récent en bas)
#     shared/.env                   créé une seule fois à la main (jamais versionné, mode 600 imposé)
#     shared/storage/               logs, cache, sessions, vues compilées… (persistant)
#     uploads/                      archives envoyées par la CI (supprimées après déploiement)
#     bin/remote-deploy.sh          ce script (copié par la CI à chaque déploiement)
#
# Idempotent : relancer le même déploiement réutilise la release déjà préparée, rejoue les
# migrations (sans effet si à jour) et les caches, puis rebascule (sans effet si déjà active).
#
# Secrets : shared/ est ramené à 700 et shared/.env à 600 à chaque exécution. Si ces droits ne
# peuvent pas être appliqués (fichier appartenant à un autre compte), le déploiement s'arrête.
set -euo pipefail
umask 022

# --- Configuration ---------------------------------------------------------------------
DEPLOY_PATH="${DEPLOY_PATH:-$HOME/church-register}"
# Un « ~ » littéral (reçu entre guillemets) est remplacé par $HOME.
# shellcheck disable=SC2088
case "$DEPLOY_PATH" in
  "~") DEPLOY_PATH="$HOME" ;;
  "~/"*) DEPLOY_PATH="$HOME/${DEPLOY_PATH#"~/"}" ;;
esac
DEPLOY_PATH="${DEPLOY_PATH%/}"
PHP_BIN="${PHP_BIN:-php}"
KEEP_RELEASES="${KEEP_RELEASES:-5}"
WEB_ROOT_MIRROR="${WEB_ROOT_MIRROR:-}"
WEB_ROOT_MIRROR="${WEB_ROOT_MIRROR%/}"

RELEASES="$DEPLOY_PATH/releases"
SHARED="$DEPLOY_PATH/shared"
UPLOADS="$DEPLOY_PATH/uploads"
CURRENT="$DEPLOY_PATH/current"
HISTORY="$RELEASES/.history"
LOCK_DIR="$DEPLOY_PATH/.deploy.lock"
COMPLETE_MARKER=".deploy-complete"

LOCKED=0
NEW_RELEASE="" # release en cours de préparation, supprimée si le déploiement échoue avant la bascule
PERMS_ENFORCEABLE="" # « 1 » / « 0 », sondé une seule fois (voir perms_enforceable)

# --- Affichage -------------------------------------------------------------------------
step() { printf '\n==> %s\n' "$*"; }
info() { printf '    %s\n' "$*"; }
warn() { printf 'ATTENTION : %s\n' "$*" >&2; }
die() {
  printf '\nÉCHEC : %s\n' "$*" >&2
  exit 1
}

usage() { awk 'NR > 1 && /^#/ { sub(/^# ?/, ""); print; next } NR > 1 { exit }' "${BASH_SOURCE[0]}"; }

# --- Verrou et nettoyage ---------------------------------------------------------------
cleanup() {
  local status=$?
  if [[ -n "$NEW_RELEASE" && -d "$NEW_RELEASE" ]]; then
    if [[ "$(current_release 2> /dev/null || true)" != "$(basename "$NEW_RELEASE")" ]]; then
      printf 'Nettoyage de la release incomplète %s\n' "$NEW_RELEASE" >&2
      rm -rf -- "$NEW_RELEASE"
    fi
  fi
  if [[ "$LOCKED" == 1 ]]; then
    rm -rf -- "$LOCK_DIR"
  fi
  exit "$status"
}
trap cleanup EXIT
trap 'exit 129' HUP
trap 'exit 130' INT
trap 'exit 143' TERM

acquire_lock() {
  mkdir -p "$DEPLOY_PATH"
  if ! mkdir "$LOCK_DIR" 2> /dev/null; then
    local owner_pid
    owner_pid="$(cut -d' ' -f1 "$LOCK_DIR/owner" 2> /dev/null || true)"
    if [[ -n "$owner_pid" ]] && ! kill -0 "$owner_pid" 2> /dev/null; then
      warn "verrou orphelin (processus $owner_pid terminé) : récupération."
      rm -rf -- "$LOCK_DIR"
      mkdir "$LOCK_DIR" || die "impossible de prendre le verrou $LOCK_DIR."
    else
      die "un autre déploiement est en cours ($(cat "$LOCK_DIR/owner" 2> /dev/null || echo 'propriétaire inconnu')).
Si ce n'est pas le cas, supprimez le verrou : rm -rf \"$LOCK_DIR\""
    fi
  fi
  LOCKED=1
  printf '%s %s\n' "$$" "$(date '+%Y-%m-%d %H:%M:%S')" > "$LOCK_DIR/owner"
}

# --- Utilitaires -----------------------------------------------------------------------
current_release() {
  [[ -L "$CURRENT" ]] || return 1
  basename "$(readlink "$CURRENT")"
}

artisan() {
  local release="$1"
  shift
  (cd "$release" && "$PHP_BIN" artisan "$@" --no-interaction)
}

validate_sha() {
  [[ "$1" =~ ^[A-Za-z0-9][A-Za-z0-9._-]{0,63}$ ]] || die "identifiant de release invalide : « $1 »."
}

# --- Droits des fichiers de configuration ----------------------------------------------
# Droits en octal (« 600 »), vide si le système ne sait pas les donner.
file_mode() { stat -c '%a' "$1" 2> /dev/null || stat -f '%Lp' "$1" 2> /dev/null || true; }

# Vrai si le chemin est accessible au groupe ou aux autres, ne serait-ce qu'en lecture.
open_to_others() { [[ -n "$(find "$1" -maxdepth 0 -perm /g+rwx,o+rwx 2> /dev/null)" ]]; }

# Vrai si le système de fichiers applique réellement les droits POSIX. Sondé une fois : certains
# montages (NTFS sans « acl », partages réseau) acceptent chmod sans rien changer — inutile alors
# d'interrompre le déploiement, aucun chmod n'y changerait quoi que ce soit.
perms_enforceable() {
  if [[ -z "$PERMS_ENFORCEABLE" ]]; then
    local probe="$SHARED/.perm-probe.$$"
    PERMS_ENFORCEABLE=0
    if : > "$probe" 2> /dev/null; then
      if chmod 604 "$probe" 2> /dev/null && open_to_others "$probe" \
        && chmod 600 "$probe" 2> /dev/null && ! open_to_others "$probe"; then
        PERMS_ENFORCEABLE=1
      fi
      rm -f -- "$probe"
    fi
  fi
  [[ "$PERMS_ENFORCEABLE" == 1 ]]
}

# Restreint un chemin à son seul propriétaire, et refuse de continuer si c'est impossible.
# shared/ et shared/.env portent les secrets de production (APP_KEY, accès MariaDB, clé Brevo) :
# sur un hébergement mutualisé, ce que le groupe ou « autres » peuvent lire, d'autres comptes
# le peuvent aussi. Idempotent : sans effet si les droits sont déjà corrects.
harden() {
  local mode="$1" path="$2" before after
  before="$(file_mode "$path")"
  chmod "$mode" "$path" 2> /dev/null || true
  open_to_others "$path" || {
    [[ -z "$before" || "$before" == "$mode" ]] || info "droits de $path corrigés ($before -> $mode)"
    return 0
  }
  after="$(file_mode "$path")"
  if ! perms_enforceable; then
    warn "le système de fichiers de $DEPLOY_PATH n'applique pas les droits POSIX : impossible de restreindre $path (attendu $mode)."
    return 0
  fi
  die "droits trop permissifs sur $path (${after:-inconnus} au lieu de $mode), et « chmod $mode » n'a rien changé.
Ce chemin contient les secrets de production (APP_KEY, accès à MariaDB, clé d'API Brevo) : tant
qu'il reste lisible au-delà de son propriétaire, le déploiement est interrompu.
À corriger en SSH, puis relancer le déploiement :
  chmod $mode \"$path\"
Si chmod échoue, le chemin appartient à un autre compte :
  $(ls -ld -- "$path" 2> /dev/null || true)"
}

check_php() {
  command -v "$PHP_BIN" > /dev/null 2>&1 || die "PHP introuvable : PHP_BIN=$PHP_BIN.
Sur Hostinger, indiquez le PHP CLI 8.3 complet (ex. /opt/alt/php83/usr/bin/php ; voir ls -d /opt/alt/php*)."
  if ! "$PHP_BIN" -r 'exit(PHP_VERSION_ID >= 80300 ? 0 : 1);'; then
    die "PHP 8.3 minimum requis ; $PHP_BIN est en version $("$PHP_BIN" -r 'echo PHP_VERSION;').
Sur Hostinger, le « php » du SSH peut différer de la version du site : utilisez PHP_BIN=/opt/alt/php83/usr/bin/php."
  fi
  local modules missing=()
  modules="$("$PHP_BIN" -m 2> /dev/null | tr '[:upper:]' '[:lower:]')"
  for ext in pdo_mysql mbstring intl openssl bcmath gd zip sodium ctype fileinfo tokenizer xml curl; do
    grep -qx "$ext" <<< "$modules" || missing+=("$ext")
  done
  if [[ ${#missing[@]} -gt 0 ]]; then
    warn "extensions PHP absentes du CLI ($PHP_BIN) : ${missing[*]} (à activer dans hPanel > PHP)."
  fi
  "$PHP_BIN" -r 'exit(defined("PASSWORD_ARGON2ID") ? 0 : 1);' \
    || warn "ce PHP ne prend pas en charge argon2id (hachage des mots de passe)."
  info "PHP $("$PHP_BIN" -r 'echo PHP_VERSION;') ($PHP_BIN)"
}

ensure_shared() {
  mkdir -p "$RELEASES" "$UPLOADS" "$SHARED" \
    "$SHARED/storage/app/public" "$SHARED/storage/app/private" \
    "$SHARED/storage/framework/cache/data" "$SHARED/storage/framework/sessions" \
    "$SHARED/storage/framework/views" "$SHARED/storage/logs"
  # PHP s'exécute sous le compte Hostinger (suEXEC) : le propriétaire suffit, ni groupe ni « autres ».
  harden 700 "$SHARED"
  chmod -R u+rwX,go-rwx "$SHARED/storage"
}

first_deploy_help() {
  cat >&2 << EOF

ÉCHEC : $SHARED/.env est absent.

Premier déploiement — à faire une seule fois en SSH (détails : docs/deploiement.md) :
  1. Générer une clé d'application :
       $PHP_BIN -r 'echo "base64:".base64_encode(random_bytes(32)).PHP_EOL;'
  2. Créer le fichier de configuration de production et y coller la clé (APP_KEY=base64:…) :
       nano "$SHARED/.env"
     (modèle commenté : docs/deploiement.md, section « Fichier .env de production »)
  3. Restreindre ses droits (le déploiement les réimpose ensuite à chaque exécution) :
       chmod 600 "$SHARED/.env"
  4. Relancer le déploiement : GitHub > Actions > « Déploiement » > Run workflow.
EOF
  exit 1
}

check_env_file() {
  [[ -f "$SHARED/.env" ]] || first_deploy_help
  # Avant toute lecture : le fichier ne doit être lisible que par son propriétaire.
  harden 600 "$SHARED/.env"
  grep -Eq '^APP_KEY=["'\'']?[^"'\''[:space:]#]+' "$SHARED/.env" \
    || die "APP_KEY absent ou vide dans $SHARED/.env (générez-le : voir docs/deploiement.md)."
  if grep -Eiq '^APP_DEBUG=["'\'']?(true|1|on|yes)' "$SHARED/.env"; then
    die "APP_DEBUG est activé dans $SHARED/.env : interdit en production (fuite d'informations). Mettez APP_DEBUG=false."
  fi
  grep -Eq '^APP_ENV=["'\'']?production' "$SHARED/.env" || warn "APP_ENV n'est pas « production » dans $SHARED/.env."
}

# Crée ou remplace atomiquement un lien symbolique (la release peut déjà être en ligne).
atomic_link() {
  local target="$1" link="$2"
  [[ -L "$link" && "$(readlink "$link")" == "$target" ]] && return 0
  if [[ -e "$link" && ! -L "$link" ]]; then
    rm -rf -- "$link" # vrai fichier/dossier (ex. storage/ livré par erreur dans l'archive)
  fi
  ln -sfn "$target" "$link.tmp.$$"
  mv -Tf -- "$link.tmp.$$" "$link"
}

link_shared() {
  local release="$1"
  atomic_link ../../shared/.env "$release/.env"
  atomic_link ../../shared/storage "$release/storage"
  mkdir -p "$release/bootstrap/cache"
  chmod u+rwx "$release/bootstrap/cache"
}

record_history() {
  local sha="$1" tmp="$HISTORY.tmp.$$"
  touch "$HISTORY"
  { grep -vxF -- "$sha" "$HISTORY" || true; } > "$tmp"
  printf '%s\n' "$sha" >> "$tmp"
  mv -f -- "$tmp" "$HISTORY"
}

switch_current() {
  local sha="$1" tmp="$DEPLOY_PATH/.current.tmp.$$"
  if [[ -e "$CURRENT" && ! -L "$CURRENT" ]]; then
    die "$CURRENT existe et n'est pas un lien symbolique : déplacez-le puis relancez."
  fi
  ln -sfn "releases/$sha" "$tmp"
  mv -Tf -- "$tmp" "$CURRENT" # rename(2) : atomique, jamais d'état intermédiaire
  info "current -> releases/$sha"
}

# Plan B (WEB_ROOT_MIRROR) : la racine web est un vrai dossier. AVANT la bascule, on y ajoute les
# fichiers statiques de la release (les assets hachés s'ajoutent sans rien casser) ; son index.php
# délègue à current/public/index.php. spa.html est lu par Laravel dans la release : pas copié.
mirror_web_root() {
  [[ -n "$WEB_ROOT_MIRROR" ]] || return 0
  local release="$1" dest="$WEB_ROOT_MIRROR"
  if [[ -L "$dest" ]]; then
    warn "WEB_ROOT_MIRROR ($dest) est un lien symbolique : mode miroir inutile, ignoré."
    return 0
  fi
  info "copie des fichiers statiques vers la racine web $dest"
  mkdir -p "$dest"
  (cd "$release/public" && tar -cf - --exclude=./index.php --exclude=./spa.html .) | (cd "$dest" && tar -xf -)
  printf '%s\n' '<?php' \
    '// Généré par deploy/remote-deploy.sh (WEB_ROOT_MIRROR) : ne pas modifier.' \
    "require '$DEPLOY_PATH/current/public/index.php';" > "$dest/index.php.tmp.$$"
  mv -f -- "$dest/index.php.tmp.$$" "$dest/index.php"
  rm -f -- "$dest/spa.html"
}

# Supprime de la racine web miroir les assets qui n'existent plus dans aucune release conservée.
prune_web_root_assets() {
  [[ -n "$WEB_ROOT_MIRROR" && -d "$WEB_ROOT_MIRROR/assets" && ! -L "$WEB_ROOT_MIRROR" ]] || return 0
  local file rel dir used
  while IFS= read -r -d '' file; do
    rel="${file#"$WEB_ROOT_MIRROR/assets/"}"
    used=0
    for dir in "$RELEASES"/*/public/assets; do
      if [[ -e "$dir/$rel" ]]; then
        used=1
        break
      fi
    done
    [[ $used == 1 ]] || rm -f -- "$file"
  done < <(find "$WEB_ROOT_MIRROR/assets" -type f -print0)
}

after_switch() {
  # Étapes non bloquantes : la nouvelle release est déjà active.
  artisan "$CURRENT" queue:restart || warn "queue:restart a échoué (les workers finiront leur cycle d'une minute)."
  # LiteSpeed : redémarre les processus lsphp du compte (vide les caches realpath/OPcache qui
  # pourraient encore résoudre « current » vers l'ancienne release). Sans effet ailleurs.
  touch "$HOME/.lsphp_restart.txt" 2> /dev/null || true
}

prune_releases() {
  local current keep=() name dir
  current="$(current_release 2> /dev/null || true)"
  if [[ -f "$HISTORY" ]]; then
    mapfile -t keep < <(tail -n "$KEEP_RELEASES" "$HISTORY")
  fi
  for dir in "$RELEASES"/*/; do
    [[ -d "$dir" ]] || continue
    name="$(basename "$dir")"
    [[ "$name" == "$current" ]] && continue
    if [[ " ${keep[*]:-} " == *" $name "* ]]; then
      continue
    fi
    info "suppression de l'ancienne release $name"
    rm -rf -- "${RELEASES:?}/${name:?}"
  done
  # L'historique ne garde que les releases encore présentes.
  if [[ -f "$HISTORY" ]]; then
    local tmp="$HISTORY.tmp.$$" line
    : > "$tmp"
    while IFS= read -r line; do
      [[ -n "$line" && -d "$RELEASES/$line" ]] && printf '%s\n' "$line" >> "$tmp"
    done < "$HISTORY"
    mv -f -- "$tmp" "$HISTORY"
  fi
  find "$UPLOADS" -maxdepth 1 -name '*.tar.gz' -mtime +7 -delete 2> /dev/null || true
}

# --- Commandes -------------------------------------------------------------------------
deploy() {
  local sha="$1" archive="${2:-}"
  validate_sha "$sha"
  archive="${archive:-$UPLOADS/$sha.tar.gz}"
  local release="$RELEASES/$sha"

  step "Déploiement de $sha dans $DEPLOY_PATH"
  check_php
  ensure_shared
  check_env_file
  acquire_lock

  step "1/6 Préparation de la release"
  if [[ -f "$release/$COMPLETE_MARKER" ]]; then
    info "release déjà extraite et préparée : réutilisation (déploiement relancé)."
  else
    [[ -f "$archive" ]] || die "archive introuvable : $archive"
    if [[ -e "$release" ]]; then
      [[ "$(current_release 2> /dev/null || true)" != "$sha" ]] \
        || die "current pointe sur une release incomplète ($sha) : intervention manuelle requise."
      info "suppression d'une extraction incomplète précédente"
      rm -rf -- "${release:?}"
    fi
    NEW_RELEASE="$release"
    mkdir -p "$release"
    tar -xzf "$archive" -C "$release"
    [[ -f "$release/artisan" && -f "$release/vendor/autoload.php" && -f "$release/public/index.php" ]] \
      || die "archive incomplète (artisan, vendor/ ou public/index.php manquant)."
    info "extraite dans $release ($(cat "$release/REVISION" 2> /dev/null || echo "$sha"))"
  fi

  step "2/6 Liens vers shared/ (.env, storage)"
  link_shared "$release"
  artisan "$release" --version > /dev/null || die "l'application ne démarre pas avec $PHP_BIN (voir le message ci-dessus)."

  step "3/6 Migrations"
  artisan "$release" migrate --force

  step "4/6 Caches (config, routes, événements, vues)"
  artisan "$release" optimize
  touch "$release/$COMPLETE_MARKER"
  record_history "$sha"

  step "5/6 Bascule atomique"
  mirror_web_root "$release"
  switch_current "$sha"
  NEW_RELEASE=""
  after_switch

  step "6/6 Nettoyage (conservation des $KEEP_RELEASES dernières releases)"
  rm -f -- "$archive"
  prune_releases
  prune_web_root_assets

  printf '\nDéploiement terminé : %s est en ligne.\n' "$sha"
}

rollback() {
  local target="${1:-}" current
  step "Retour arrière dans $DEPLOY_PATH"
  check_php
  check_env_file
  acquire_lock

  current="$(current_release)" || die "aucune release active (current absent)."
  if [[ -z "$target" ]]; then
    local history=() i found=-1
    [[ -f "$HISTORY" ]] && mapfile -t history < "$HISTORY"
    for i in "${!history[@]}"; do
      [[ "${history[$i]}" == "$current" ]] && found=$i
    done
    [[ $found -ge 0 ]] || die "la release active ($current) est absente de l'historique ; précisez la cible : --rollback <sha>."
    for ((i = found - 1; i >= 0; i--)); do
      if [[ -f "$RELEASES/${history[$i]}/$COMPLETE_MARKER" ]]; then
        target="${history[$i]}"
        break
      fi
    done
    [[ -n "$target" ]] || die "aucune release précédente disponible (active : $current)."
  fi
  validate_sha "$target"
  [[ "$target" != "$current" ]] || die "$target est déjà la release active."
  [[ -f "$RELEASES/$target/$COMPLETE_MARKER" ]] || die "release $target introuvable ou incomplète."

  info "$current -> $target"
  link_shared "$RELEASES/$target"
  # Le cache de configuration est régénéré avec le shared/.env actuel (il a pu changer depuis).
  if ! artisan "$RELEASES/$target" optimize; then
    warn "optimize a échoué sur $target : caches vidés, l'application fonctionnera sans cache."
    artisan "$RELEASES/$target" optimize:clear || true
  fi
  mirror_web_root "$RELEASES/$target"
  switch_current "$target"
  after_switch
  warn "les migrations ne sont PAS annulées : le schéma reste celui de $current (migrations rétrocompatibles requises)."
  printf '\nRetour arrière terminé : %s est en ligne.\n' "$target"
}

status() {
  local current line mark
  current="$(current_release 2> /dev/null || echo '(aucune)')"
  printf 'DEPLOY_PATH : %s\nRelease active : %s\n\nReleases (de la plus ancienne à la plus récente) :\n' "$DEPLOY_PATH" "$current"
  if [[ -f "$HISTORY" ]]; then
    while IFS= read -r line; do
      [[ -n "$line" ]] || continue
      mark="  "
      [[ "$line" == "$current" ]] && mark="* "
      printf '  %s%s\n' "$mark" "$line"
    done < "$HISTORY"
  fi
  if [[ -f "$SHARED/.env" ]]; then
    printf '\nshared/ : %s   shared/.env : %s   (attendus : 700 et 600)\n' \
      "$(file_mode "$SHARED")" "$(file_mode "$SHARED/.env")"
  else
    printf '\nshared/.env absent : premier déploiement à préparer (docs/deploiement.md).\n'
  fi
}

# --- Point d'entrée --------------------------------------------------------------------
[[ "$KEEP_RELEASES" =~ ^[1-9][0-9]*$ ]] || die "KEEP_RELEASES doit être un entier ≥ 1."
[[ "$DEPLOY_PATH" == /* ]] || die "DEPLOY_PATH doit être un chemin absolu (reçu : $DEPLOY_PATH)."
[[ "$DEPLOY_PATH" != *[\'\"\\]* ]] || die "DEPLOY_PATH ne doit contenir ni guillemet ni antislash."
if [[ -n "$WEB_ROOT_MIRROR" ]]; then
  [[ "$WEB_ROOT_MIRROR" == /* ]] || die "WEB_ROOT_MIRROR doit être un chemin absolu."
  case "$WEB_ROOT_MIRROR/" in
    "$DEPLOY_PATH/"* | "$HOME/") die "WEB_ROOT_MIRROR ne peut pas être dans DEPLOY_PATH ni être le dossier personnel." ;;
  esac
fi

case "${1:-}" in
  --rollback)
    [[ $# -le 2 ]] || die "usage : remote-deploy.sh --rollback [<sha>]"
    rollback "${2:-}"
    ;;
  --status)
    status
    ;;
  -h | --help)
    usage
    ;;
  "" | -*)
    usage >&2
    exit 2
    ;;
  *)
    [[ $# -le 2 ]] || die "usage : remote-deploy.sh <sha> [archive.tar.gz]"
    deploy "$1" "${2:-}"
    ;;
esac
