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

(NB : le point `getPlannedSecondsIntoHour()` n'est PAS un résiduel TOPH — voir section Clock Wheel.)

Fichiers concernés :
- `backend/src/Radio/AutoDJ/HourBoundaryPlanner.php` (`hasTopOfHourIdQueued` — MODIFIÉ)
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

## Observations — PL normale (baseline `.liq`)

But : comprendre ce qui déclenche (ou non) une (re)génération du `.liq`, avant de
s'attaquer au point « Schedule dans le formulaire playlist ». Image du `.liq` prise
avant manipulation comme référence.

| Action sur PL normale (`playlistTest`) | Effet sur `.liq` |
|---|---|
| Création d'une PL vide | ❌ aucune modification |
| Ajout d'un dossier mp3 | ❌ pas de création / MAJ |
| Changement de priorité | ❌ pas de MAJ |

> À suivre : quelles actions déclenchent effectivement une régénération du `.liq`
> (hypothèse de travail : le scheduling / `needs_restart`, à confirmer par la suite).

### Mécanique confirmée (investigation)

Comportement normal, pas de bug.

- **Création d'une PL** = écriture en BDD. L'AutoDJ l'exploite **directement depuis la
  base** à chaque build de queue. `PlaylistController.php` ne régénère PAS le `.liq`
  → explique les 3 observations baseline ci-dessus.
- **Écriture dans le `.liq`** = via `ConfigWriter.php`, **uniquement si** l'option
  **« Always Write Playlists to Liquidsoap »** est vraie. Sinon l'AutoDJ gère en base.
- **EXCEPTION** : une playlist avec **flux externe** (remote/stream) **DOIT** être écrite
  dans le `.liq`, indépendamment de l'option — l'AutoDJ ne sait pas router un flux externe
  depuis la base, il faut le câblage Liquidsoap.

> Lien avec le point ouvert `strict_start` / interruption de flux distant : le seul chemin
> pour qu'un flux externe passe à l'antenne, c'est le `.liq` (via `ConfigWriter`).

---

## Environnement git

- **origin** = `MisterFoxi/AzuraCast` (repo lc, cible des push).
- **upstream** = `eternityready2/AzuraCast` (fork de base, référence).
- Branches : `main` = `dev` = `1987b4ba` (tag v0.29.7). Branche de travail : **`FoxDev`**
  (créée depuis `main`, porte le commit du SUIVI).
- Flux : on bosse le local (azuradev), on pousse vers `origin` quand l'état est OK.

---

## À traiter

### 2. Reload to apply changes — Remote Stream (fork, lié `strict_start`) — ✅ CORRIGÉ & VALIDÉ

**Contexte.** Une PL de type **Remote URL → Stream** est gérée **directement par Liquidsoap**,
pas par l'AutoDJ PHP. Elle **exige** donc d'être présente dans le `.liq` (via `ConfigWriter`)
pour passer à l'antenne — y compris son **horaire de diffusion**.

**Fait établi — seul un force reload synchronise le `.liq` avec la base.** Aucune opération
CRUD sur une PL Remote Stream ne déclenchait de régénération ni ne levait `needs_restart`
(AutoDJ normal) → aucune bannière « reload to apply changes ».

| Opération | Régénération `.liq` | `needs_restart` (avant) |
|---|---|---|
| Création / modification de la PL | ❌ non | ❌ non |
| Ajout d'un horaire de diffusion | ❌ non | ❌ non |
| Effacement d'un horaire de diffusion | ❌ non | ❌ non |
| **Force reload de la conf** | ✅ oui | — |

**Le `.liq` lui-même était correct.** Après force reload, le `.liq` régénéré contenait bien la
PL externe **et son horaire**. Le défaut n'était pas dans `ConfigWriter` (la matérialisation
est bonne) mais dans l'**absence de déclencheur** : rien ne reliait une opération BDD à la regen.

**Correctif en deux volets (les deux nécessaires) — appliqués sur `Z:` et VALIDÉS au test.**

*Backend* — listener Doctrine `StationRequiresRestart` (onFlush) :
- Branche `StationPlaylist` étendue : marque restart si `use_manual_autodj` **OU**
  PL Remote Stream (`source = RemoteUrl` ET `remote_type = Stream`) — ajout, pas remplacement.
- Nouvelle branche `StationSchedule` : marque restart si son `playlist` parent est Remote Stream
  (couvre insert/update/**delete** d'horaire).
- Résolution station généralisée (`StationSchedule` n'a pas de `->station` → via `playlist?->station`).
- Gardes existantes conservées : filtrage `AuditIgnore` sur Update + `hasLocalServices()`.
- Helper `isRemoteStreamPlaylist(StationPlaylist): bool`.

*Frontend* — `Playlists.vue` :
- Garde `if (!useManualAutoDj.value) return;` retirée de `mayNeedRestart()`. Cette garde
  bloquait le refetch de la requête station sous AutoDJ normal → la bannière `Sidebar`
  (qui lit `station.needs_restart`) ne se rafraîchissait jamais, même flag DB posé.
  C'était la cause du « le patch ne fait rien ».
- `mayNeedRestart()` invalide désormais toujours. Sans effet de bord pour les PL songs
  (le backend ne pose pas le flag pour elles → bannière reste masquée après refetch).
- Imports `useStationData` / `toRefs` retirés (devenus inutiles).

**Fichiers modifiés (sur `Z:`, confirmés `git diff`) :**
- `backend/src/Doctrine/Event/StationRequiresRestart.php`
- `frontend/components/Stations/Playlists.vue`

**Patches (portage `main` / autres branches) :** `remote-stream-needs-restart.patch`,
`frontend-remote-stream-needs-restart.patch`, + `StationRequiresRestart.php.patched` (repli).

> **Rebuild frontend requis** pour que le `.vue` prenne effet.
> Reste ouvert (thread séparé) : `strict_start` / interruption de flux distant — c'est la
> question de la **préemption Liquidsoap** d'un flux externe, distincte du signal `needs_restart`
> traité ici.

---

## À traiter

### 1. Schedule dans le formulaire playlist
Le scheduling est encore présent dans la création des playlists sur `eternity/main`.
→ À planifier.

### 2b. Clock Wheel — `getPlannedSecondsIntoHour()` garde inerte (périmètre à confirmer)
Même défaut que le TOPH (itère `getUnplayedQueue()` en lisant `timestamp_played`, null sur
file non jouée → boucle inerte → ignore les items à venir), MAIS ce n'est PAS un point TOPH.
Seuls appelants : `backend/src/Radio/AutoDJ/ClockWheel/ClockWheelPlaybackPlanner.php`
(ligne 88 + wrapper privé 236/241). Impact : positionnement des slots de clock wheel.
Pertinent uniquement si les clock wheels sont utilisées sur la station — usage NON confirmé,
à valider avant d'y toucher. Correctif probable identique à 1b (dériver de `timestamp_cued`).

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
- **2026-08-07** — Requalification : `getPlannedSecondsIntoHour()` n'est PAS un résiduel TOPH
  mais un point Clock Wheel (seul appelant : `ClockWheelPlaybackPlanner`). Déplacé en « À traiter ».
- **2026-08-07** — Baseline PL normale (`playlistTest`) : création vide, ajout dossier mp3 et
  changement de priorité ne modifient PAS le `.liq`. Référence `.liq` avant manip conservée.
- **2026-08-07** — Mécanique `.liq` confirmée (comportement normal, pas de bug) : création PL =
  écriture BDD exploitée directement par l'AutoDJ (`PlaylistController` ne touche pas le `.liq`) ;
  écriture `.liq` via `ConfigWriter` seulement si « Always Write Playlists to Liquidsoap » ;
  EXCEPTION : PL à flux externe DOIT être écrite dans le `.liq`.
- **2026-08-07** — Point ouvert ajouté (À traiter #3) : PL Remote Stream ne déclenche ni
  régénération `.liq` ni `needs_restart` (ni à la création/modif, ni au scheduling), alors
  qu'elle exige le `.liq`. Flux distant reste inactif jusqu'à regen+reload manuel. Lié `strict_start`.
- **2026-08-07** — Synthèse #3 : seul un **force reload** synchronise le `.liq` (il contient alors
  bien la PL externe + son horaire → `ConfigWriter` OK). Asymétrie confirmée : ajout **et**
  effacement d'horaire ne régénèrent pas → créneau ajouté inactif, créneau effacé continue de
  jouer, jusqu'au reload. Défaut = absence de déclencheur, pas `ConfigWriter`.
- **2026-08-07** — Correctif #3 backend produit : `StationRequiresRestart` étendu (PL Remote Stream
  + `StationSchedule` de PL Remote Stream → `needs_restart`). Validé `git apply --check`, ASCII/LF.
  Livré sur `Z:` (`remote-stream-needs-restart.patch` + `.patched`). Appliqué sur `Z:` (vérifié).
- **2026-08-07** — Diagnostic « le patch ne fait rien » : backend OK mais garde frontend
  `if (!useManualAutoDj.value) return;` dans `Playlists.vue#mayNeedRestart` bloquait le refetch
  station sous AutoDJ normal → bannière jamais rafraîchie. Correctif frontend appliqué sur `Z:`
  (garde retirée, imports inutiles nettoyés). Patch : `frontend-remote-stream-needs-restart.patch`.
  Chaine complète = backend + frontend. **Rebuild frontend requis.**
- **2026-08-07** — ✅ Remote Stream `needs_restart` VALIDÉ au test (lc). Les deux volets (backend
  `StationRequiresRestart` + frontend `Playlists.vue`) appliqués sur `Z:` et confirmés par
  `git diff`. Point déplacé en « Clos » (#2). Reste ouvert et distinct : préemption Liquidsoap
  du flux externe (`strict_start`).
