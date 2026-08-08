# Playlists groupées — Extraction fonctionnelle (base : PR AzuraCast #8433)

> **Objet :** extraire la logique **fonctionnelle** du PR #8433 (Vaalyn, `feature/playlist-groups`)
> pour bâtir **notre propre implémentation** en connaissance de cause.
> **Ceci n'est PAS du code porté** — description comportementale + modèle de données + critique.
> **Source :** PR https://github.com/AzuraCast/AzuraCast/pull/8433 — **WIP, non mergé, non revu** par le mainteneur.
> **merge-base analysé :** `b62d3179` (head PR `pr8433`). Fichiers clés : `QueueBuilder.php`,
> `Scheduler.php`, `StationPlaylistRepository.php`, entité `StationPlaylistGroup`, migrations.
> **MAJ :** 2026-08-08

---

## 0. Périmètre du PR (4 features empaquetées)

Le PR mélange plusieurs choses. Notre **cœur de besoin** = les 2 premières. Les autres sont séparables.

1. **Playlist Groups + nesting** ← cœur
2. **Clockwheel** (passages consécutifs par membre) ← cœur
3. **Request Queue playlists** (`source=Requests`) + `prevent_requests` (créneau) + `requests_only_via_playlists` (station) ← séparable, 2e temps
4. **Export/Import de config playlists** (JSON) + **harnais de test in-memory** ← périphérique / outil

---

## 1. Concept central (et le principal piège de design)

Il n'y a **pas** d'entité « groupe » dédiée :

- un **groupe** = un `StationPlaylist` ordinaire dont `source = Playlists` ;
- ses **membres** = d'autres `StationPlaylist` ;
- le lien = table de jointure `station_playlist_group`, en réalité une **arête d'appartenance**
  (mal nommée) qui porte l'état de rotation + les réglages clockwheel / requêtes.

Le **nesting** en découle : un membre peut lui-même être `source=Playlists`.

Sur `StationPlaylist`, deux collections symétriques :
- `playlists` = arêtes où il est le **groupe** (mappedBy `playlist_group`) ;
- `playlist_groups` = arêtes où il est **membre** (mappedBy `playlist`).

> ⚠️ Conséquence : `StationPlaylist` est surchargé (conteneur **et** feuille). Le scheduling,
> `isPlayable`, la rotation, `hasCued*` branchent sur `source` **partout**. Voir §7.1.

---

## 2. Modèle de données ajouté par le PR

### Enums
- `PlaylistSources` : ajout de `Playlists = 'playlists'` (groupe) et `Requests = 'requests'` (file de requêtes).
- `PlaylistGroupAllowedRequests` : `any` | `playlist` | `none`.

### Table `station_playlist_group` (arête d'appartenance)
| Colonne | Type | Rôle fonctionnel |
|---|---|---|
| `playlist_id` | FK → station_playlists | le **membre** |
| `playlist_group_id` | FK → station_playlists | le **groupe** (source=Playlists) |
| `weight` | int | ordre du membre dans le groupe |
| `is_queued` | bool | état round-robin (rotation Sequential/Shuffle) |
| `last_played` | int (ts) | dernier passage |
| `consecutive_plays` | int (déf. 0) | clockwheel : nb de passages consécutifs voulus |
| `consecutive_plays_count` | int (déf. 0) | compteur courant |
| `play_full_cycle` | bool (déf. 0) | jouer toute la playlist membre avant d'avancer |
| `allowed_requests` | enum (déf. 'any') | filtre requêtes du membre |

FK `ON DELETE CASCADE` des deux côtés.

### Autres colonnes
- `station.requests_only_via_playlists` (bool) — coupe la file de requêtes automatique globale.
- `station_schedules.prevent_requests` (bool) — bloque les requêtes pendant le créneau.
- `station_queue.playlist_chain` / `song_history.playlist_chain` (JSON `list<string>`) — chaîne de
  groupes traversée, pour affichage (badge source).

### Suppression
- Option `OPTION_PRIORITIZE_OVER_REQUESTS` (« prioritize over requests ») retirée et **purgée des
  `backend_options`** par migration. Remplacée par les leviers §5.

---

## 3. Sélection à la construction de queue (`QueueBuilder`) — le cœur

1. **`assembleActivePlaylistsByType`** — parcourt les playlists station et **exclut toute playlist
   membre d'un groupe** (`playlist_groups->count() > 0`). → un membre ne concourt **jamais** au
   scheduling top-level ; il ne joue que via son groupe. Les playlists groupe concourent normalement.
2. **Priorité inchangée** : `OncePerHour → OncePerXSongs → OncePerXMinutes → Standard`, chacun
   × `scheduled → unscheduled`, weighted-shuffle dans le bucket. Le `PlaylistType` est orthogonal
   à la `source` : un groupe peut être OncePerHour, Standard, etc.
3. **`playSongFromPlaylist`** — dispatch `match($source)` : RemoteUrl / Playlists / Songs / Requests.
4. **`playSongFromPlaylistGroup`** — itère la file des membres (§ordre ci-dessous) :
   - membre non programmé maintenant → avance rotation (`played(forceAdvance)`) et saute ;
   - sinon appel **récursif** `playSongFromPlaylist` sur le membre ; **premier succès** → marque le
     groupe `played_at`, marque le membre `played(...)`, retourne `true`.
5. **Ordre interne du groupe** (`getPlaylistGroupQueue`) :
   - `Random` → `ORDER BY RAND()` à chaque fois, **sans** `is_queued` ;
   - `Sequential` / `Shuffle` → round-robin sur `is_queued=1` ordonné par `weight` ; file vide →
     `resetPlaylistGroupQueue` (tous `is_queued=1` ; poids ré-aléatoirisés pour Shuffle).
   - **= exactement la mécanique de la file média SPM** (deux machines à états parallèles, voir §7.2).
6. **Clockwheel** (`consecutive_plays`, via `StationPlaylistGroup::played()`) : tant que
   `consecutive_plays_count < consecutive_plays`, le membre reste `is_queued` → rejoué au cycle
   suivant. Atteint → `is_queued=false`, compteur remis à 0. ⇒ N passages consécutifs avant d'avancer.
7. **`play_full_cycle`** (membres `Songs` en Sequential/Shuffle seulement) : force
   `consecutive_plays=0` et **garde le membre en file tant que sa file média n'est pas épuisée**
   (via `count(getQueue)` avant lecture ; `keepQueued = queuedBeforePlay > 1`). ⇒ joue toute la
   playlist membre avant d'avancer le groupe.

> ⚠️ **Pas de garde anti-cycle** dans cette récursion (contrairement au Scheduler). Nesting
> cyclique mal configuré → récursion infinie. Voir §7.5.

### Traçabilité
- `playlist_chain` = snapshot des **noms** de la chaîne de groupes, posé **seulement** si la chaîne
  a ≥ 2 éléments (donc quand on passe par un groupe). Persisté sur `StationQueue`, recopié vers
  `SongHistory`. Purement affichage.

---

## 4. Scheduling (`Scheduler`)

- `shouldPlaylistPlayNow` sur une playlist groupe route vers les variantes groupe :
  `isPlaylistGroupQueueEmpty` / `...CompletelyFilled` / `resetPlaylistGroupQueue` /
  `hasCuedPlaylistGroupMedia` (récursif sur l'arbre) au lieu des variantes SPM.
- **`isPlaylistBlockedByGroupSchedule` / `isGroupActiveThroughoutWindow`** : un membre programmé
  *hors* de la fenêtre où un groupe-ancêtre est actif ne jouera pas → sert à afficher un
  **warning agenda**. Cette récursion **a un garde anti-cycle** (`visitedIdsInChain`).
- Corrections annexes empaquetées (indépendantes des groupes) : `isScheduleDateInRange`
  (bornes start/end date par fenêtre) ; suppression d'un `shouldSchedulePlayOnCurrentDate` en amont.

---

## 5. Requêtes auditeurs — 3 leviers imbriqués (séparable, 2e temps)

- **File automatique globale** (`getNextSongFromRequests`, priorité 5, avant tout le reste) :
  - coupée entièrement si `station.requests_only_via_playlists` ;
  - bloquée si un créneau `prevent_requests` est actif maintenant ;
  - sautée si une playlist `source=Requests` est due maintenant (elle prend la main) ;
  - pour les groupes programmés, `isRequestBlockedInHierarchy` (**descente**) applique
    `allowed_requests` : `none` → bloque ; `playlist` → bloque si le média demandé n'est pas dans
    le sous-arbre du membre.
- **Playlist Request Queue** (`source=Requests`, `playSongFromRequestsPlaylist`) : atteinte via le
  scheduling normal, gardée par `areRequestsBlockedByAncestors` (**remontée** : bloque seulement si
  une arête ancêtre a `allowed_requests=none` ET que la racine est programmée+active).

---

## 6. Périphérique

- **Export/Import config playlists** (JSON dump ; re-link média/dossiers/groupes ; médias factices
  `ISRC=DUMMY` pour les manquants). Outil de test/migration inter-instances. Hors cœur.
- **Harnais de test AutoDJ in-memory** (`InMemoryAutoDjHarness` + fixtures JSON de scénarios) :
  teste QueueBuilder/Scheduler **sans BDD**. ⭐ **Seule partie recommandée à reprendre** — colle à la
  méthode « test qui échoue avant / passe après ».

---

## 7. Points faibles à NE PAS reproduire

1. **`StationPlaylist` surchargé (conteneur + feuille).** Arête mal nommée `station_playlist_group`.
   Branche sur `source` partout. → séparer clairement la notion de groupe et d'appartenance.
2. **Deux machines à états de rotation persistées et mutées en plein build** (`is_queued` média SPM
   **+** `is_queued`/`consecutive_plays_count` d'arête), écrites via `em->persist` pendant la
   construction. **Cause probable de l'instabilité `clearUpcomingQueue` déjà connue** : vider/
   reconstruire la file ne rollback pas ces compteurs → dérive rotation ↔ file réelle. Rendre l'état
   dérivable, ou couplé transactionnellement à la file.
3. **`play_full_cycle` couplé au compteur interne de la file média** (`count(getQueue)`) — fragile ;
   casse si la file média est réinitialisée ailleurs.
4. **Blocage requêtes éclaté sur 3 méthodes** aux sémantiques divergentes (descente autoritaire pour
   Random ; remontée conditionnée à la racine programmée). À unifier en un prédicat unique.
5. **Aucun garde anti-cycle dans la récursion du QueueBuilder** (le Scheduler en a un). Incohérent.
6. **`getNextSongFromRequests` triple-boucle `station.playlists`** avec filtres qui se recouvrent →
   une passe.
7. **Config d'un membre éclatée** entre l'arête (`weight`, `consecutive_plays`, `allowed_requests`,
   `play_full_cycle`) et la playlist (`order`, `source`, `avoid_duplicates`).
8. **Scope creep** (voir §0) — livrer les groupes+clockwheel d'abord, requêtes ensuite.

Bug amont ouvert (thread PR) : playlists en groupe « perdent » leur `Song Playback Order` dans l'UI
(défaut de sérialisation front).

---

## 8. À garder / s'inspirer

- Le **harnais de test in-memory** + fixtures de scénarios (déterministe, sans BDD).
- L'idée du **`playlist_chain`** pour l'observabilité des décisions de queue.
- La forme « douce » du dispatch par `source` dans le QueueBuilder (le problème n'est pas la
  récursion, c'est l'**état de rotation persisté mutable**).

---

## 9. Notre implémentation — décisions à arrêter (lc)

> Section ouverte. lc décide l'archi ; Claude fournit options/analyse.

- [ ] **Modèle** : garder `source=Playlists` sur `StationPlaylist`, ou introduire une entité
      groupe/membership distincte ?
- [ ] **État de rotation** : dérivé de la file réelle / historique, ou compteurs persistés mais
      couplés transactionnellement au (re)build de queue ?
- [ ] **Clockwheel** : `consecutive_plays` sur l'arête — repris tel quel, ou modélisé autrement ?
- [ ] **`play_full_cycle`** : découpler de la file média SPM (comment déterminer « cycle épuisé » ?).
- [ ] **Garde anti-cycle** : à imposer dès la construction (et à la config : refuser un nesting cyclique).
- [ ] **Requêtes** : traiter en lot séparé (2e temps) ; prédicat de blocage unifié.
- [ ] **Tests** : reprendre/adapter le harnais in-memory avant d'écrire la logique.

---

## Journal
- **2026-08-08** — Création. Extraction fonctionnelle du PR #8433 (analyse merge-base `b62d3179`).
  Aucun code porté. En attente des décisions d'archi lc (§9).
