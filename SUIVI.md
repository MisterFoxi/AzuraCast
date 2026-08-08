# Suivi des points à traiter — AzuraCast (fork)

**Base :** `origin/FoxDev` (`MisterFoxi/AzuraCast`)
**Dernière MAJ :** 2026-08-08

> On repart de zéro. Ce fichier ne contient que des faits établis cette session.
> Rien n'est présumé à partir de sessions antérieures.

## État actuel — préparation playlists imbriquées

- **Branche de développement : `FoxDev`.** La branche de travail de nettoyage part directement de `origin/FoxDev`.
- **Clock Wheels : retirés du code applicatif et de l'UI.** Les entités, API, scheduler, écrans et tests dédiés sont supprimés.
- **DB :** une migration terminale supprime les tables/colonnes/FK Clock Wheels. Les anciennes migrations restent dans l'historique afin que les upgrades et installations rejouant toute la chaîne aboutissent au même schéma final.
- **Conservé :** TOPH station-wide, classification média, règles DNP/DMCA, playlists soft-strict et overrides de jours fériés côté playlist.
- `PLAYLIST-GROUPS.md` reste la référence pour la prochaine PR de playlists imbriquées ; les anciennes specs Clock Wheels ont été retirées pour éviter toute ambiguïté.

> Les sections Clock Wheels plus bas dans ce journal décrivent l'historique des investigations et ne représentent plus l'architecture active.

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
| Playlists groupées | ❌ Absentes — analyse fonctionnelle PR #8433 dans `PLAYLIST-GROUPS.md` (réimpl. à faire) |
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
Le correctif est désormais intégré au code de `FoxDev`; l'ancien patch autonome a été retiré du dépôt pour éviter de réintroduire un champ Clock Wheel supprimé.

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

## Clos (suite)

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

**✅ Déclenchements des PL externes aux horaires spécifiques : VALIDÉ.** Le comportement de
diffusion aux créneaux programmés était en réalité **correct** — le `.liq` régénéré contient bien
la PL externe et son horaire, et le flux part au bon moment. Le seul défaut était le **rechargement
du `.liq`** (résolu par force reload), que **l'UI ne signalait pas** (bannière « reload to apply
changes » jamais levée). Corrigé par le patch `needs_restart` ci-dessus (backend + frontend) :
l'UI signale désormais le besoin de recharge après ajout/modif/effacement d'un horaire de PL
Remote Stream. Rien à corriger côté planification elle-même.

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

### 3. Remote Stream fallback-yield (préemption Liquidsoap) — ✅ RÉGLÉ (2026-08-08)

Réglé et validé : le fallback Remote Stream s'arrête dès que la file AutoDJ est non vide, et
reprend si elle se revide. Comportement conforme à la dev note. Voir Journal.

Suite du thread ouvert « préemption Liquidsoap du flux distant » (distinct de `needs_restart`, clos #2).
Objectif (dev note lc) : une PL Remote Stream **non planifiée** doit servir de fallback de démarrage/
secours, pas de source prioritaire permanente. Règle : file AutoDJ vide → Remote Stream ;
file AutoDJ non vide (≥1 `StationQueue` `is_played=0`) → radio normale. Retour possible si la file
se revide. Pas de reload / disable-enable requis pour la bascule.

**Points ouverts de la note — RÉSOLUS par investigation code (autorisée, sans web) :**

- **Point d'injection `.liq`** = `ConfigWriter::writePlaylistConfiguration()`, bloc « Handle remote URL
  fallbacks » (~l.458-465). Généré aujourd'hui pour une PL Remote Stream **non planifiée**
  (`0 === scheduleItems->count()` → `$fallbackRemoteUrl`) :
  `remote_url = mksafe(buffer(buffer=N., input.http("URL")))`
  `radio = fallback(id="fallback_remote_url", track_sensitive=false, [remote_url, radio])`
  Ce bloc est AVANT `requests_fallback`/`interrupting_fallback` (qui restent donc au-dessus →
  requests/interrupting préemptent toujours le remote — inchangé).
- **Cause mécanique du bug** : `mksafe` rend `remote_url` toujours ready → dans un `fallback`
  `track_sensitive=false` il gagne en permanence, ne rend jamais la main.
- **Commande interne à étendre** (analogue `/nextsong`) : ajouter `case QueueStatus = 'queuestatus'`
  à `App\Radio\Enums\LiquidsoapCommands` + classe `QueueStatusCommand extends AbstractCommand`
  (calquée sur `NextSongCommand`), renvoyant `['ready' => bool]`. Dispatch déjà générique via
  `LiquidsoapAction` (`/api/internal/{id}/liquidsoap/{action}`, auth `X-Liquidsoap-Api-Key`).
- **Repo** : `StationQueueRepository::hasUnplayedQueue(Station): bool`, calqué sur
  `hasCuedPlaylistMedia()` (count(sq.id) WHERE is_played=0 > 0). `is_played` (pas `sent_to_autodj`)
  = choix de la note, justifié.
- **Runtime Liquidsoap 2.4 — primitives déjà présentes** dans `util/docker/stations/liquidsoap/`
  `azuracast.liq` : `ref()`/`.set` (état caché), `azuracast.api_call(endpoint, payload)` (renvoie
  string JSON ou `null` sur non-200/erreur), `thread.run.recurrent(delay=…, {…})` (polling, déjà
  utilisé par l'AutoDJ l.458), parsing `let json.parse ({ready}:{ready:bool}) = null.get(...)`.
  Le prédicat `switch(track_sensitive=false, [({pred}, s), ({true}, s2)])` est DÉJÀ l'idiome maison
  (schedule_switch). → **aucune recherche web nécessaire.**
- **Filet de sécurité final** : `azuracast.add_fallback(s)` = `fallback(id="safe_fallback",
  track_sensitive=false, [s, error_file(single fallback_path)])`. Donc un remote HS peut retomber
  sur l'error jingle **à condition de ne pas être `mksafe`**.

**Décisions d'archi en attente (lc) :**
NEANT

**Plan d'impl. proposé (patches, en attente d'autorisation d'écriture) :**
P1 `hasUnplayedQueue()` (repo) · P2 enum + `QueueStatusCommand` · P3 poller + ref dans `azuracast.liq`
· P4 `switch` conditionnel dans `ConfigWriter` (+ décision `mksafe`). Périmètre strict : uniquement
la PL Remote Stream **non planifiée** ; AutoDJ/`nextsong`/PL planifiées inchangés.

> Patches parasites en racine (`remote-stream-fallback-yield.patch`, `remote-stream-needs-restart.patch`,
> `frontend-remote-stream-needs-restart.patch`, `StationRequiresRestart.php.patched`, `diff.txt`,
> `diff2.txt`, `liquidsoap.orig`) : **déclarés obsolètes par lc, à effacer.** Non supprimés par Claude
> (action irréversible) ; suppression `rm` par lc, ou déplacement `_archive/` sur confirmation.
> `toph-dedup-1b.patch` CONSERVÉ (fix validé) sauf avis contraire.

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
- **2026-08-07** — Fallback-yield (à traiter #3) : investigation code autorisée (sans web). Points
  ouverts de la dev note tous résolus depuis le code : injection = `ConfigWriter` bloc
  `fallback_remote_url` (~l.458) ; commande interne = enum `LiquidsoapCommands` + `QueueStatusCommand` ;
  repo = `hasUnplayedQueue()` (patron `hasCuedPlaylistMedia`) ; runtime `azuracast.liq` a déjà
  `ref`/`api_call`/`thread.run.recurrent` + idiome `switch(pred)`. `mksafe` identifié comme cause
  mécanique du non-yield ; filet `safe_fallback` présent. Plan P1-P4 prêt, écriture patches à autoriser.
- **2026-08-07** — Patches parasites en racine déclarés obsolètes par lc (à effacer). Non supprimés
  par Claude ; `toph-dedup-1b.patch` conservé.
- **2026-08-07** — Fallback traité. Le fallback s'arrette des que la queue est non vide.
- **2026-08-08** — ✅ Déclenchements des PL externes aux horaires spécifiques VALIDÉS. La
  diffusion aux créneaux programmés était correcte ; le seul problème était la recharge du `.liq`
  (résolue par reload), que l'UI ne signalait pas. Couvert par le patch `needs_restart` (Clos #2).
  Aucune correction requise sur la planification. Point acté.
- **2026-08-08** — ✅ #3 Fallback-yield RÉGLÉ et acté.
