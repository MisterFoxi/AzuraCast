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
| TOPH (Top Of The Hour) | ✅ CORRIGÉ (patch 1b, validé au test 12:00) |
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

**Diagnostic (cause racine) : garde anti-doublon INERTE (mauvais champ d'horodatage).**
- Le seul garde inter-cycle est `HourBoundaryPlanner::hasTopOfHourIdQueued()`
  (via `isTopOfHourIdDue()` qui renvoie `!hasTopOfHourIdQueued(...)`).
  Le garde per-tick du scheduler (`!empty(getNextSongs())`) ne protège que dans un même cycle.
- Le garde itère `StationQueueRepository::getUnplayedQueue()` (filtre `is_played = 0`) et lit
  `row->timestamp_played`. Or `timestamp_played` n'est renseigné QU'AU moment où `is_played`
  passe à 1 (setter `is_played` + `trackPlayed()`). Donc **toute ligne non jouée a
  `timestamp_played = null`**.
- Première ligne du garde : `if ($playedAt === null) continue;` → **saute TOUTES les lignes**
  → `hasTopOfHourIdQueued()` renvoie **toujours `false`**. Le garde est purement inerte :
  il ne détecte jamais un TOPH déjà en file.
- Résultat : `isTopOfHourIdDue()` se réduit au seul test de fenêtre temporelle et renvoie
  `true` à CHAQUE cycle de build dans la fenêtre → un TOPH par cycle → doublons.

> CORRECTION DE RAISONNEMENT (tracé volontairement) : une première hypothèse « décalage de
> bucket horaire » avait été posée à partir des lignes SQL HISTORIQUES (déjà jouées, donc avec
> `timestamp_played` renseigné). Elle est FAUSSE : le garde n'itère que la file NON jouée, où
> `timestamp_played` est null. Hypothèse abandonnée après lecture de `getUnplayedQueue()`.

**Fenêtre de déclenchement :** dépend de `buffer = finish_buffer + id_max`
(défauts 15+60 = 75 s), PAS du look-ahead. `isTopOfHourIdDue` ne devient vrai que quand
`secondsUntil <= 75 s` → ~:58:45→:59:59. Cohérent avec les 3 TOPH observés à :58–:59.
Le **look-ahead de 10 min n'intervient pas** dans le déclenchement (il sert à tronquer la musique).

**Effet collatéral (même défaut, hors correctif immédiat) :** `getPlannedSecondsIntoHour()`
itère aussi `getUnplayedQueue()` en lisant `timestamp_played` → boucle inerte → ignore les
items à venir. À corriger dans un second temps.

**Correctif retenu : PISTE 1b (dédup sur borne servie, dérivée de `timestamp_cued`, sans migration).**
- `hasTopOfHourIdQueued()` réécrit : pour chaque ligne non jouée marquée legal_id
  (`top_of_hour_legal_id` OU `clock_wheel_legal_id_substitute` OU média de type station ID),
  on calcule la borne servie via `resolveTopOfHourExpectedPlayAt($station, $row->timestamp_cued)`
  et on compare à la borne cible. `timestamp_cued` est toujours non-null → le garde n'est plus inerte.
- Réutilise la fonction de borne existante → alignement garanti avec le resolver/scheduler,
  pas de définition de borne divergente.
- Aucune migration, aucun changement de schéma.

État correctif : patch produit et validé `git apply --check` sur base vierge v0.29.7 (1987b4ba),
ASCII/LF strict. Lint PHP à faire côté Docker (`docker compose exec web php -l ...`).
Livrables : `toph-dedup-1b.patch` + `HourBoundaryPlanner.php` (fichier complet en repli).

**RÉSULTAT DU TEST (12:00) : ✅ CORRIGÉ, validé de bout en bout.**
- Une seule ligne TOPH cuée pour la borne 12:00 (id 54, `KTAR`) — plus d'empilement
  (contre 3 doublons ce matin à 11:00 avant patch).
- Placement correct : la queue a inséré l'ID à la suite de la piste finissant à :59
  (le `timestamp_cued` à :50 = moment d'insertion en file, PAS l'heure de diffusion).
- L'ID a bien été **joué à l'antenne** au passage d'heure.
- Reste (résiduel, non bloquant) : `getPlannedSecondsIntoHour()` a le même défaut de garde
  inerte — à corriger dans un second temps.

Fichiers concernés :
- `backend/src/Radio/AutoDJ/HourBoundaryPlanner.php` (`hasTopOfHourIdQueued` — MODIFIÉ ;
  `getPlannedSecondsIntoHour` — même défaut, à traiter ensuite)
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

## Environnement git

- **origin** = `MisterFoxi/AzuraCast` (repo lc, cible des push).
- **upstream** = `eternityready2/AzuraCast` (fork de base, référence).
- Branches : `main` = `dev` = `1987b4ba` (tag v0.29.7). Branche de travail : **`FoxDev`**
  (créée depuis `main`, porte le commit du SUIVI).
- Flux : on bosse le local (azuradev), on pousse vers `origin` quand l'état est OK.

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
- **2026-08-07** — Diagnostic TOPH CORRIGÉ : la cause n'est pas un bucket horaire mais un garde
  INERTE (`timestamp_played` toujours null sur la file non jouée → `hasTopOfHourIdQueued`
  renvoie toujours false). Hypothèse bucket abandonnée après lecture de `getUnplayedQueue()`.
- **2026-08-07** — Remotes réorganisés (origin=MisterFoxi, upstream=eternityready2).
  Branche `FoxDev` créée depuis `main`.
- **2026-08-07** — Correctif TOPH piste 1b implémenté (réécriture `hasTopOfHourIdQueued`,
  dédup sur borne servie via `timestamp_cued`). Patch validé `git apply --check`.
  À appliquer/tester sur azuradev.
- **2026-08-07** — Test TOPH à 12:00 : ✅ SUCCÈS. Un seul legal ID cué pour la borne,
  placé après la piste finissant à :59, et joué à l'antenne. Fix 1b validé empiriquement.
  Résiduel connu : `getPlannedSecondsIntoHour()` (même défaut, non traité).
