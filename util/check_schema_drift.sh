#!/usr/bin/env bash
#
# Detect Doctrine entity ↔ migration drift (the talk_frequency class of bug).
#
# Preferred CI usage (fresh empty DB):
#   1. Start MariaDB / app with an empty database
#   2. Run:  php bin/console azuracast:setup:migrate
#   3. Run:  util/check_schema_drift.sh
#
# Local usage (against the current Docker stack):
#   docker compose exec --user=azuracast web bash util/check_schema_drift.sh
#
# Exit 0 = schema matches migrations. Exit 1 = drift detected.
#
set -euo pipefail

ROOT="$(cd "$(dirname "${BASH_SOURCE[0]}")/.." && pwd)"
cd "$ROOT"

CONSOLE=(php bin/console)
if [[ ! -f bin/console ]] && [[ -f backend/bin/console ]]; then
  CONSOLE=(php backend/bin/console)
fi

echo "==> Validating ORM mapping metadata..."
"${CONSOLE[@]}" orm:validate-schema --skip-sync || {
  echo "ERROR: ORM mapping metadata is invalid." >&2
  exit 1
}

echo "==> Checking whether the live DB schema matches entity mappings..."
# orm:validate-schema without --skip-sync compares mapped entities to the DB.
# After a clean migrate on an empty DB, any failure means a missing/outdated migration.
if ! "${CONSOLE[@]}" orm:validate-schema; then
  echo >&2
  echo "ERROR: Schema drift detected — entity mappings do not match the database." >&2
  echo "Likely cause: an #[ORM\\Column] was added without a Doctrine migration." >&2
  echo "Fix: add a migration under backend/src/Entity/Migration/, then re-run." >&2
  echo >&2
  echo "Optional: generate a draft with:" >&2
  echo "  ${CONSOLE[*]} migrations:diff" >&2
  exit 1
fi

echo "OK: entity mappings and database schema are in sync."
