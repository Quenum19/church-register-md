#!/usr/bin/env bash
# Test de fumée de la production (lancé par deploy.yml après la bascule, utilisable à la main).
#
#   scripts/smoke-test.sh <url_de_base>      ex. scripts/smoke-test.sh https://registre.exemple.org
#
# Vérifie :
#   - GET /api/health          -> 200 avec {"status":"ok","db":"ok"} (plusieurs essais) ;
#   - fichiers sensibles       -> jamais 2xx (/.env, /.git/config, /composer.json, /vendor/autoload.php,
#                                 /storage/logs/laravel.log, /artisan…) ;
#   - GET /                    -> 200, HTML du SPA, en-tête Content-Security-Policy présent ;
#   - http:// -> https://      -> redirection (avertissement seulement).
# Code de sortie : 0 si tout passe, 1 sinon.
set -euo pipefail

BASE_URL="${1:-}"
[[ -n "$BASE_URL" ]] || {
  echo "Usage : $0 <url_de_base>" >&2
  exit 2
}
BASE_URL="${BASE_URL%/}"
[[ "$BASE_URL" =~ ^https?://[^/]+$ ]] || {
  echo "URL de base invalide (attendu : https://hote, sans chemin) : $BASE_URL" >&2
  exit 2
}

HEALTH_ATTEMPTS="${SMOKE_HEALTH_ATTEMPTS:-6}"
HEALTH_DELAY="${SMOKE_HEALTH_DELAY:-5}"

TMP="$(mktemp -d)"
trap 'rm -rf "$TMP"' EXIT

failures=0
ok() { printf '  OK     %s\n' "$*"; }
ko() {
  printf '  ÉCHEC  %s\n' "$*"
  [[ -n "${GITHUB_ACTIONS:-}" ]] && printf '::error title=Test de fumée::%s\n' "$*"
  failures=$((failures + 1))
}
warn_msg() {
  printf '  ATTENTION  %s\n' "$*"
  [[ -n "${GITHUB_ACTIONS:-}" ]] && printf '::warning title=Test de fumée::%s\n' "$*"
  return 0
}

# fetch <url> : écrit $TMP/headers et $TMP/body, affiche le code HTTP (000 si injoignable).
fetch() {
  rm -f "$TMP/headers" "$TMP/body"
  curl --silent --show-error --max-time 20 --connect-timeout 10 \
    --user-agent 'church-register-smoke-test' --header 'Cache-Control: no-cache' \
    --dump-header "$TMP/headers" --output "$TMP/body" --write-out '%{http_code}' \
    "$1" 2> "$TMP/curl-error" || true
}

# header_value <nom> : valeur du dernier en-tête <nom> de la dernière réponse (vide si absent).
header_value() {
  { grep -i "^$1:" "$TMP/headers" 2> /dev/null || true; } | tail -n 1 | cut -d: -f2- | tr -d '\r' | sed 's/^ *//'
}

echo "Test de fumée : $BASE_URL"
[[ "$BASE_URL" == https://* ]] || warn_msg "l'URL testée n'est pas en HTTPS."

# 1. Santé de l'application (quelques essais : la bascule peut mettre quelques secondes à se propager).
echo "- /api/health"
health_ok=0
for ((attempt = 1; attempt <= HEALTH_ATTEMPTS; attempt++)); do
  code="$(fetch "$BASE_URL/api/health")"
  if [[ "$code" == 200 ]] \
    && grep -Eq '"status"[[:space:]]*:[[:space:]]*"ok"' "$TMP/body" \
    && grep -Eq '"db"[[:space:]]*:[[:space:]]*"ok"' "$TMP/body"; then
    health_ok=1
    break
  fi
  printf '    essai %d/%d : HTTP %s %s\n' "$attempt" "$HEALTH_ATTEMPTS" "$code" "$(head -c 200 "$TMP/body" 2> /dev/null | tr -d '\n' || true)$(cat "$TMP/curl-error" 2> /dev/null || true)"
  [[ $attempt -lt $HEALTH_ATTEMPTS ]] && sleep "$HEALTH_DELAY"
done
if [[ $health_ok == 1 ]]; then
  ok "/api/health -> 200 {status: ok, db: ok}"
else
  ko "/api/health ne répond pas 200 avec status=ok et db=ok"
fi

# 2. Aucun fichier sensible ne doit être servi.
echo "- fichiers sensibles"
for path in /.env /.env.example /.git/config /.git/HEAD /composer.json /composer.lock /vendor/autoload.php \
  /storage/logs/laravel.log /artisan /.htaccess; do
  code="$(fetch "$BASE_URL$path")"
  if [[ "$code" =~ ^2 ]]; then
    ko "$path -> HTTP $code (doit être refusé)"
  elif [[ "$code" == 000 ]]; then
    ko "$path -> injoignable ($(cat "$TMP/curl-error" 2> /dev/null || true))"
  else
    ok "$path -> HTTP $code"
  fi
done

# 3. Page d'accueil : le SPA, servi par Laravel avec ses en-têtes de sécurité.
echo "- page d'accueil"
code="$(fetch "$BASE_URL/")"
content_type="$(header_value 'content-type')"
csp="$(header_value 'content-security-policy')"
if [[ "$code" != 200 ]]; then
  ko "/ -> HTTP $code (attendu 200)"
elif [[ "$content_type" != text/html* ]]; then
  ko "/ -> Content-Type « $content_type » (attendu text/html)"
elif ! grep -q 'id="root"' "$TMP/body"; then
  ko "/ -> le HTML ne contient pas le point de montage du SPA (id=\"root\")"
else
  ok "/ -> 200 text/html (SPA)"
fi
if [[ -n "$csp" ]]; then
  ok "/ -> Content-Security-Policy : $csp"
else
  ko "/ -> en-tête Content-Security-Policy absent"
fi
for h in strict-transport-security x-content-type-options x-frame-options referrer-policy; do
  [[ -n "$(header_value "$h")" ]] || warn_msg "/ -> en-tête $h absent"
done

# 4. Redirection HTTP -> HTTPS (non bloquant : peut aussi être faite en amont par l'hébergeur).
if [[ "$BASE_URL" == https://* ]]; then
  code="$(fetch "http://${BASE_URL#https://}/")"
  location="$(header_value 'location')"
  if [[ "$code" =~ ^30[1278]$ && "$location" == https://* ]]; then
    ok "http:// -> $code vers $location"
  else
    warn_msg "http:// ne redirige pas vers https:// (HTTP $code)"
  fi
fi

echo
if [[ $failures -gt 0 ]]; then
  echo "Test de fumée : $failures échec(s)."
  exit 1
fi
echo "Test de fumée : tout est vert."
