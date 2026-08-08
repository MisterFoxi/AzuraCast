#!/usr/bin/env bash
set -euo pipefail

usage() {
    cat <<'EOF'
Usage:
  ./deploy-preprod-image.sh --host user@host --compose-dir /path/to/compose [--export-dir /path/to/exports] [--no-confirm]

Required:
  --host HOST                 SSH target, example: ubuntu@preprod
  --compose-dir DIR           Remote directory containing docker-compose.yml

Options:
  --export-dir DIR            Local directory for Docker image exports
                              (default: /data/docker-exports)
  --no-confirm                Do not ask before build/deploy
  -h, --help                  Show this help

Example:
  ./deploy-preprod-image.sh \
    --host ubuntu@preprod \
    --compose-dir /data/preprod/AzuraCast \
    --export-dir /mnt/docker-exports
EOF
}

die() {
    echo "Erreur: $*" >&2
    exit 1
}

require_value() {
    local option="$1"
    local value="${2:-}"

    [[ -n "$value" && "$value" != --* ]] || die "${option} exige une valeur"
}

sanitize_tag() {
    echo "$1" | tr '/:@ ' '----' | tr -cd '[:alnum:]_.-'
}

HOST=""
COMPOSE_DIR=""
TARGET="final"
SERVICE="web"
BUILDER="azura-builder"
EXPORT_DIR="/data/docker-exports"
CONFIRM=1
LOCAL_TAR=""
BUILD_TAR=""
REMOTE_TAR=""
REMOTE_ARCHIVE_CREATED=0

cleanup() {
    local rc=$?

    [[ -z "$BUILD_TAR" ]] || rm -f -- "$BUILD_TAR"
    [[ -z "$LOCAL_TAR" ]] || rm -f -- "$LOCAL_TAR"

    if [[ "$REMOTE_ARCHIVE_CREATED" -eq 1 && -n "$REMOTE_TAR" ]]; then
        ssh "$HOST" "rm -f -- '${REMOTE_TAR}'" >/dev/null 2>&1 || true
    fi

    return "$rc"
}

trap cleanup EXIT

while [[ $# -gt 0 ]]; do
    case "$1" in
        --host)
            require_value "$@"
            HOST="${2:-}"
            shift 2
            ;;
        --compose-dir)
            require_value "$@"
            COMPOSE_DIR="${2:-}"
            shift 2
            ;;
        --export-dir)
            require_value "$@"
            EXPORT_DIR="${2:-}"
            shift 2
            ;;
        --no-confirm)
            CONFIRM=0
            shift
            ;;
        -h|--help)
            usage
            exit 0
            ;;
        *)
            die "Option inconnue: $1"
            ;;
    esac
done

[[ -n "$HOST" ]] || die "--host est obligatoire"
[[ -n "$COMPOSE_DIR" ]] || die "--compose-dir est obligatoire"
[[ "$COMPOSE_DIR" != *"'"* ]] || die "--compose-dir ne peut pas contenir d'apostrophe"

REPO_ROOT="$(git rev-parse --show-toplevel 2>/dev/null)" \
    || die "ce script doit être lancé depuis un dépôt Git"
cd "$REPO_ROOT"

CURRENT_BRANCH="$(git branch --show-current)"
SHORT_SHA="$(git rev-parse --short HEAD)"
[[ -n "$CURRENT_BRANCH" ]] || die "HEAD détachée: aucune branche actuelle à déployer"

BASE="$(git rev-parse --abbrev-ref --symbolic-full-name '@{upstream}' 2>/dev/null || true)"

echo "==> Préflight Compose sur ${HOST}"
ssh "$HOST" "test ! -e '${COMPOSE_DIR}/.git'" \
    || die "${COMPOSE_DIR} est encore un checkout Git; la cible doit contenir uniquement la configuration d'exploitation"

FULL_IMAGE="$(
    ssh "$HOST" \
        "cd '${COMPOSE_DIR}' && docker compose config --images '${SERVICE}'"
)" || die "impossible de lire la configuration Compose du service ${SERVICE}"

[[ -n "$FULL_IMAGE" ]] || die "service Compose introuvable ou sans image: ${SERVICE}"
[[ "$FULL_IMAGE" != *$'\n'* ]] || die "plusieurs images retournées pour le service ${SERVICE}"

[[ "${FULL_IMAGE##*/}" == *:* ]] \
    || die "l'image Compose doit avoir un tag explicite: ${FULL_IMAGE}"
IMAGE_NAME="${FULL_IMAGE%:*}"
IMAGE_TAG="${FULL_IMAGE##*:}"

mkdir -p "$EXPORT_DIR" || die "impossible de créer le répertoire d'export: ${EXPORT_DIR}"
ARCHIVE_NAME="$(sanitize_tag "${IMAGE_NAME}_${IMAGE_TAG}_${SHORT_SHA}").tar.gz"
LOCAL_TAR="${EXPORT_DIR%/}/${ARCHIVE_NAME}"
BUILD_TAR="${LOCAL_TAR%.gz}"
REMOTE_TAR="/tmp/${ARCHIVE_NAME}"

echo "==> Configuration"
cat <<EOF
Host:           ${HOST}
Compose dir:    ${COMPOSE_DIR}
Service:        ${SERVICE}
Branch:         ${CURRENT_BRANCH}
Tracking:       ${BASE:-aucune}
Image:          ${FULL_IMAGE}
Target:         ${TARGET}
Builder:        ${BUILDER}
Export dir:     ${EXPORT_DIR}
Local tar:      ${LOCAL_TAR}
Remote tar:     ${REMOTE_TAR}
EOF

if [[ -n "$(git status --porcelain)" ]]; then
    git status --short
    die "working tree non clean"
fi

if [[ -n "$BASE" ]]; then
    echo
    echo "==> Commits au-dessus de ${BASE}"
    git log --oneline "${BASE}..HEAD" || die "impossible de comparer avec ${BASE}"

    echo
    echo "==> Fichiers modifiés"
    git diff --name-status "${BASE}..HEAD" || die "impossible de lister les fichiers"
else
    echo
    echo "==> Aucune branche de tracking: comparaison Git ignorée"
fi

if [[ "$CONFIRM" -eq 1 ]]; then
    echo
    read -r -p "Déployer ${FULL_IMAGE} vers ${HOST} ? [y/N] " ANSWER
    [[ "$ANSWER" == "y" || "$ANSWER" == "Y" ]] || {
        echo "Annulé."
        exit 0
    }
fi

echo
echo "==> Vérification buildx builder ${BUILDER}"
docker buildx inspect "${BUILDER}" >/dev/null 2>&1 \
    || die "builder buildx introuvable: ${BUILDER}"

mkdir -p "$(dirname "$LOCAL_TAR")"

echo
echo "==> Build Docker target ${TARGET} via buildx"
rm -f "$BUILD_TAR" "$LOCAL_TAR"

docker buildx build \
    --builder "${BUILDER}" \
    --target "${TARGET}" \
    --progress=plain \
    --output "type=docker,dest=${BUILD_TAR}" \
    -t "${FULL_IMAGE}" \
    "$REPO_ROOT"

echo
echo "==> Compression image"
gzip -f "$BUILD_TAR"

echo
echo "==> Export image: ${LOCAL_TAR}"
[[ -f "$LOCAL_TAR" ]] || die "tarball local introuvable: ${LOCAL_TAR}"
file "$LOCAL_TAR"
ls -lh "$LOCAL_TAR"

ARCHIVE_MANIFEST="$(tar -xOzf "$LOCAL_TAR" manifest.json 2>/dev/null)" \
    || die "tarball Docker invalide: ${LOCAL_TAR}"
grep -Fq "\"${FULL_IMAGE}\"" <<< "$ARCHIVE_MANIFEST" \
    || die "le tarball ne contient pas l'image attendue: ${FULL_IMAGE}"

echo "Tarball vérifié: ${FULL_IMAGE}"

echo
echo "==> Copie vers ${HOST}:${REMOTE_TAR}"
scp "$LOCAL_TAR" "${HOST}:${REMOTE_TAR}"
REMOTE_ARCHIVE_CREATED=1

echo
echo "==> docker load sur pre-prod"
LOAD_OUTPUT="$(
    ssh "$HOST" "set -o pipefail; gunzip -c '${REMOTE_TAR}' | docker load" 2>&1
)" || {
    printf '%s\n' "$LOAD_OUTPUT" >&2
    die "docker load a échoué sur ${HOST}"
}
printf '%s\n' "$LOAD_OUTPUT"

if grep -Fq "Error unpacking image" <<< "$LOAD_OUTPUT"; then
    die "docker load n'a pas pu extraire l'image sur ${HOST}"
fi

echo "==> Suppression archive de transfert sur pre-prod"
ssh "$HOST" "rm -f -- '${REMOTE_TAR}'"
REMOTE_ARCHIVE_CREATED=0

LOADED_IMAGE_ID="$(
    ssh "$HOST" "docker image inspect '${FULL_IMAGE}' --format '{{.Id}}'"
)" || die "l'image chargée n'est pas disponible sous le tag ${FULL_IMAGE}"

echo "Image chargée: ${FULL_IMAGE} (${LOADED_IMAGE_ID})"

echo
echo "==> Image sur pre-prod"
ssh "$HOST" "docker images '${IMAGE_NAME}' --format 'table {{.Repository}}\t{{.Tag}}\t{{.ID}}\t{{.CreatedSince}}\t{{.Size}}'"

echo
echo "==> Recreate service ${SERVICE}"
ssh "$HOST" "cd '${COMPOSE_DIR}' && docker compose up -d --force-recreate --no-deps '${SERVICE}'"

echo
echo "==> Vérification image réellement exécutée"
EXPECTED_IMAGE_ID="$(
    ssh "$HOST" "docker image inspect '${FULL_IMAGE}' --format '{{.Id}}'"
)" || die "image attendue introuvable sur pre-prod: ${FULL_IMAGE}"

[[ "$EXPECTED_IMAGE_ID" == "$LOADED_IMAGE_ID" ]] \
    || die "le tag ${FULL_IMAGE} a changé depuis docker load"

RUNNING_IMAGE_IDS="$(
    ssh "$HOST" \
        "cd '${COMPOSE_DIR}' && docker compose ps -q '${SERVICE}' | xargs -r docker inspect --format '{{.Image}}'"
)" || die "impossible de déterminer l'image du service ${SERVICE}"

[[ -n "$RUNNING_IMAGE_IDS" ]] \
    || die "aucun conteneur en cours d'exécution pour le service ${SERVICE}"

while IFS= read -r RUNNING_IMAGE_ID; do
    [[ "$RUNNING_IMAGE_ID" == "$EXPECTED_IMAGE_ID" ]] \
        || die "image exécutée = ${RUNNING_IMAGE_ID}, attendu = ${EXPECTED_IMAGE_ID} (${FULL_IMAGE})"
done <<< "$RUNNING_IMAGE_IDS"

echo "Image vérifiée: ${FULL_IMAGE} (${EXPECTED_IMAGE_ID})"

echo
echo "==> État compose"
ssh "$HOST" "cd '${COMPOSE_DIR}' && docker compose ps && docker compose images"

echo
echo "==> Nettoyage des artefacts locaux"
rm -f -- "$BUILD_TAR" "$LOCAL_TAR"

echo
echo "Déploiement vérifié: ${FULL_IMAGE}"
