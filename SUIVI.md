# Suivi des points à traiter — AzuraCast (fork)

**Base :** `eternityready2/AzuraCast` — **Repo :** `MisterFoxi/AzuraCast` — branches `main` et `dev`
**Dernière MAJ :** 2026-08-07

> On repart de zéro. Ce fichier ne contient que des faits établis cette session.
> Rien n'est présumé à partir de sessions antérieures.

---

## Cadre de travail

- Pas de recherche web sans autorisation explicite.
- Pas d'investigation code / lecture de fichiers sans autorisation explicite.
- Accès SQL : `docker compose exec web bash -lc 'mariadb -u"$MYSQL_USER" -p"$MYSQL_PASSWORD" "$MYSQL_DATABASE"'`
- Décisions d'architecture : lc. Claude = analyse, options, implémentation.
- Prod jamais modifiée. Tests sur `azuradev` (`https://azuradev.lan`).

---

## État de la branche `eternity/main`

| Élément | État |
|---|---|
| Batch MP3 | ✅ OK (modifs validées) |
| TOPH (Top Of The Hour) | ❌ En cours de test — **on attend le résultat** |
| TOPH (Top Of The Hour) | 🟡 En cours de test — **APRES VALIDATION CNFIG** |
| Playlists groupées | ❌ Absentes |
| Schedule dans création de playlist | ⚠️ Encore présent (à sortir du formulaire playlist) |

---

## En attente (voire A investiger pour potentiel liens)

- **[TOPH] Résultat du test** — bloque la suite. Rien à faire tant que le retour n'est pas là.

---

## Résultat des Tests
### 1. TOPH : Echec

**Symptôme :** plusieurs legal IDs consécutifs au passage d'heure.
Exemple relevé en base (`station_queue`), autour de 11:00 :
- id 34 → joué 10:59:38, `top_of_hour_legal_id = 1`
- id 35 → joué 11:00:22, `top_of_hour_legal_id = 1`
- id 36 → joué 11:00:42, `top_of_hour_legal_id = 1`

**Diagnostic (cause racine) : garde anti-doublon aveugle par décalage de bucket horaire.**
- Le seul garde inter-cycle est `HourBoundaryPlanner::hasTopOfHourIdQueued()`
  (via `isTopOfHourIdDue()` qui renvoie `!hasTopOfHourIdQueued(...)`).
  Le garde per-tick du scheduler (`!empty(getNextSongs())`) ne protège que dans un même cycle.
- Le garde cherche un TOPH déjà en file dont le passage tombe dans `[borne:00, borne+1h)`.
- Mais un legal ID de top-of-hour est délibérément placé pour *finir* à :00, donc il passe
  dans les dernières secondes de l'heure PRÉCÉDENTE (ex. id 34 protège 11:00 mais passe à 10:59:38,
  soit dans le bucket 10:00).
- Quand le scheduler calcule la borne 11:00 et cherche un doublon dans `[11:00, 12:00)`,
  il ne voit pas l'ID déjà posé (qui vit dans `[10:00, 11:00)`) → conclut « aucun TOPH en file »
  → en réinjecte un. Répété sur les cycles de la fenêtre → doublons.
- Le seuil de 30 s dans `resolveTopOfHourExpectedPlayAt()` fragilise encore (petite variation
  de prévision = changement de bucket).

**Fenêtre de déclenchement :** dépend de `buffer = finish_buffer + id_max`
(défauts 15+60 = 75 s), PAS du look-ahead. `isTopOfHourIdDue` ne devient vrai que quand
`secondsUntil <= 75 s` → ~:58:45→:59:59. Cohérent avec les 3 TOPH observés à :58–:59.
Le **look-ahead de 10 min n'intervient pas** dans le déclenchement (il sert à tronquer la musique).

**Effet collatéral :** `getPlannedSecondsIntoHour()` a le même travers (itère `getUnplayedQueue`,
raisonne par bucket de passage).

**Pistes de fix (non tranchées, non implémentées) :**
1. Dédupliquer par **borne servie** (marquer chaque TOPH avec la borne :00 qu'il protège
   et chercher les doublons sur cette borne) — le plus robuste.
2. Élargir la fenêtre de recherche pour inclure la zone `finish_buffer` avant :00
   (ex. `[borne - buffer, borne + …)`).
3. Aligner `resolveTopOfHourExpectedPlayAt()` et `hasTopOfHourIdQueued()` sur une seule
   définition de borne.

Fichiers concernés :
- `backend/src/Radio/AutoDJ/HourBoundaryPlanner.php` (`hasTopOfHourIdQueued`, `isTopOfHourIdDue`,
  `resolveTopOfHourExpectedPlayAt`, `getPlannedSecondsIntoHour`)
- `backend/src/Radio/AutoDJ/TopOfHourIdScheduler.php` (déclencheur, sur event `BuildQueue`)
- `backend/src/Radio/AutoDJ/HourBoundaryLegalIdResolver.php` (pose la ligne de queue,
  `top_of_hour_legal_id = true`)


## Clos
### 1. Reload to apply changes — DÉCOUPLÉ DU TOPH
Investigation menée. Conclusion : la bannière n'est PAS causée par le TOPH.
- `Sidebar.vue` = simple afficheur du flag `station.needs_restart` (aucune logique).
- Déclencheurs de `needs_restart` : listener Doctrine `StationRequiresRestart`
  (Mount / HLS / Remote / Playlist SI `use_manual_autodj`) + setters de l'entité `Station`
  (short_name, frontend_config, backend_config, url, enable_streamers, max_bitrate,
  max_mounts, max_hls_streams, fallback_path). Garde : ne « colle » que si
  `hasLocalServices() && has_started`.
- TOPH disculpé : GET = lecture seule ; runtime `TopOfHourIdScheduler` n'écrit pas
  `backend_config` ni d'entité déclencheuse ; PUT jamais déclenché par lc ;
  clés `top_of_hour_*` bien présentes en base (`has_toph_key = 1`) → pas d'injection de défaut.
- État actuel : `needs_restart = 0`, `use_manual_autodj = false`.
- Cause exacte de la bannière observée : NON déterminée avec certitude, et parkée
  (non prioritaire). Ne pas présumer que c'était la création de playlists.

---

## À traiter

### 1. Schedule dans le formulaire playlist
Le scheduling est encore présent dans la création des playlists sur `eternity/main`.
→ À planifier une fois le TOPH stabilisé.

---

## Journal

- **2026-08-07** — Création du fichier. Snapshot `eternity/main` posé, TOPH en test.
- **2026-08-07** — Test TOPH : échec (doublons de legal ID au passage d'heure).
  Diagnostic cause racine posé : garde anti-doublon `hasTopOfHourIdQueued` aveugle
  par décalage de bucket horaire (ID joué avant :00 = bucket heure précédente).
  Investigation « Reload to apply changes » close : découplée du TOPH, parkée.
