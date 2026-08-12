#!/usr/bin/env bash
#
# ════════════════════════════════════════════════════════════════════
#  RAZ complète — environnement de dev AzuraCast (fork eternityready2)
#  ⚠  DESTRUCTIF : supprime la base, les stations, les uploads, etc.
# ════════════════════════════════════════════════════════════════════
set -euo pipefail

# ─── Configuration ──────────────────────────────────────────────────
REPO_URL="https://github.com/eternityready2/AzuraCast.git"

# Branche du fork à utiliser comme base.
#   Laisse vide ("") pour rester sur la branche par défaut du fork.
#   Sinon mets le nom exact (ex. main / master / development…).
BRANCH=""

# Branche de travail créée localement par-dessus $BRANCH.
# Garde tes commits isolés du fork et te permet de pull ses MAJ.
WORK_BRANCH="dev"

CLONE_DIR="/data/dev/AzuraCast"
BUILDER="azura-builder"          # builder buildx stocké sur /data

# IP d'écoute HTTP/HTTPS.
#   127.0.0.1 = accessible uniquement depuis la VM (défaut sécurisé).
#   0.0.0.0   = accessible depuis ton réseau (et via redirection Freebox).
BIND_IP="127.0.0.1"

# Les 10 volumes de l'install (déclarés external:true dans l'override).
VOLUMES=(
  azuracast_acme
  azuracast_backups
  azuracast_db_data
  azuracast_geolite_install
  azuracast_rsas_install
  azuracast_sftpgo_data
  azuracast_shoutcast2_install
  azuracast_station_data
  azuracast_stereo_tool_install
  azuracast_www_uploads
)
# ────────────────────────────────────────────────────────────────────

# ─── Confirmation (destructif) ──────────────────────────────────────
echo "⚠  RAZ AzuraCast — cette opération va SUPPRIMER DÉFINITIVEMENT :"
echo "     • tous les volumes Docker azuracast_* (BASE, STATIONS, UPLOADS…)"
echo "     • les images azuracast_custom / updater"
echo "     • le dossier $CLONE_DIR (re-cloné à neuf)"
echo
if [[ "${ASSUME_YES:-0}" != "1" ]]; then
  read -rp "Tape RAZ pour confirmer : " confirm
  [[ "$confirm" == "RAZ" ]] || { echo "Annulé."; exit 1; }
fi

# ─── 1. Arrêt + suppression conteneurs / réseaux / volumes projet ───
echo "→ Arrêt de la stack…"
if [[ -f "$CLONE_DIR/docker-compose.yml" ]]; then
  ( cd "$CLONE_DIR" && docker compose down --remove-orphans --volumes ) || true
fi

# ─── 2. Suppression des volumes externes (non retirés par down) ─────
echo "→ Suppression des volumes…"
for v in "${VOLUMES[@]}"; do
  if docker volume rm "$v" >/dev/null 2>&1; then
    echo "   supprimé : $v"
  fi
done
# Garde-fou : la RAZ n'a de sens que si plus aucun volume ne subsiste.
if docker volume ls -q | grep -q '^azuracast_'; then
  echo "‼  Des volumes azuracast_* subsistent (probablement 'in use')."
  docker volume ls | grep azuracast_ || true
  echo "   Relance le script, ou vire-les à la main, avant de continuer."
  exit 1
fi

# ─── 3. Suppression des images ──────────────────────────────────────
echo "→ Suppression des images…"
docker image rm ghcr.io/eternityready2/azuracast_custom:development >/dev/null 2>&1 || true
docker image rm ghcr.io/azuracast/updater:latest                    >/dev/null 2>&1 || true

# ─── 4. Re-clone à neuf ─────────────────────────────────────────────
echo "→ Clone frais de $REPO_URL…"
rm -rf "$CLONE_DIR"
git clone "$REPO_URL" "$CLONE_DIR"
cd "$CLONE_DIR"

# ─── 5. Branches ────────────────────────────────────────────────────
[[ -n "$BRANCH" ]] && git checkout "$BRANCH"
git checkout -b "$WORK_BRANCH" 2>/dev/null || git checkout "$WORK_BRANCH"
echo "   Branche active : $(git rev-parse --abbrev-ref HEAD)  (base : ${BRANCH:-défaut})"


# ─── 7. Recréer les volumes vides (override les exige en external) ──
echo "→ Recréation des volumes vides…"
for v in "${VOLUMES[@]}"; do docker volume create "$v" >/dev/null; done

# ─── 8. Builder sur /data + build + up ──────────────────────────────
echo "→ Préparation du builder $BUILDER…"
docker buildx inspect "$BUILDER" >/dev/null 2>&1 \
  || docker buildx create --name "$BUILDER" --driver docker-container --bootstrap >/dev/null
docker buildx use "$BUILDER"
export BUILDX_BUILDER="$BUILDER"

echo "→ Build + démarrage (peut prendre plusieurs minutes)…"
docker compose up -d --build

# ─── 9. Peupler vendor + node_modules dans le conteneur ─────────────
# Clone frais = vendor/ et node_modules/ vides (bind-montés depuis l'hôte).
echo "→ Attente du conteneur web…"
for _ in $(seq 1 30); do
  docker compose exec -T web true 2>/dev/null && break
  sleep 2
done

echo "→ composer install…"
docker compose exec -T web bash -lc \
  'cd /var/azuracast/www && COMPOSER_ALLOW_SUPERUSER=1 composer install --no-interaction'

echo "→ npm ci…"
docker compose exec -T web bash -lc \
  'cd /var/azuracast/www && (npm ci || npm install)'

echo "→ Redémarrage des services supervisés…"
docker compose exec -T web supervisorctl restart all || true

# ─── Fin ────────────────────────────────────────────────────────────
echo
echo "✅  RAZ terminée."
if [[ "$BIND_IP" == "127.0.0.1" ]]; then
  echo "   Interface : http://127.0.0.1  (depuis la VM ; sinon tunnel SSH)"
else
  echo "   Interface : http://<IP-de-la-VM>  (bind $BIND_IP)"
fi
echo "   Suivre le boot : docker compose logs -f web"
echo "   → l'assistant d'installation AzuraCast t'attend (base vierge)."