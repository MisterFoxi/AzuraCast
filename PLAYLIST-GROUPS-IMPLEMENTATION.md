# Playlist Groups — Implementation Umbrella

**Status:** implementation governance  
**Base branch:** `FoxDev`  
**Scope:** Phase 1 — core Playlist Groups  
**Reference:** `PLAYLIST-GROUPS.md` and the functional specification v0.3, as amended by the decisions below.

This document is the umbrella for the incremental implementation of Playlist Groups. It does not introduce functional code by itself. Each functional increment is developed and reviewed separately.

## 1. Product decisions already fixed

### 1.1 Group member order is sequential only

A Playlist Group is an ordered sequence of member occurrences.

- Members are visited in their configured order.
- Selection wraps to the first occurrence after the last one.
- The same playlist may appear multiple times; each appearance is a distinct occurrence.
- Group-level `Shuffle` and `Random` modes are not supported and must not be exposed by the API or UI.

Example:

```text
Jingle > Hot > Rotation > Hot > Station ID
```

The two `Hot` occurrences remain distinct positions.

### 1.2 Child track order remains independent

The sequential-only rule applies to the members of a group, not to tracks inside a child playlist.

A child playlist retains its own track playback order, including `Sequential`, `Shuffle` or `Random`. Adding it to a group must not mutate that configuration.

### 1.3 Full-cycle scope

`play_full_cycle` applies only to a finite track cycle of a `Songs` child playlist.

- It is supported initially for child playlists whose internal order is `Sequential` or `Shuffle`.
- It is unavailable for child playlists whose internal order is `Random`.
- It is not defined for a nested group in Phase 1.
- The UI and API must reject or prevent unsupported combinations rather than invent synthetic semantics.

### 1.4 Structural rules

- Groups may be nested.
- Direct and indirect cycles are forbidden and must be rejected before saving.
- Group members remain subject to their schedules and source/media availability.
- A playlist referenced by a group is excluded from top-level AutoDJ selection.
- A failed or empty member must not block queue construction.
- A group with no playable descendant must fail cleanly so AutoDJ can try another root source.
- Every nested group keeps its own selection state.
- A successful queue item preserves the complete selection path.
- Request Queue integration is Phase 2 and is outside the first implementation series.

## 2. Mandatory incremental workflow

### 2.1 Baseline before functional code

Before the first functional patch:

1. run the full non-regression suite on an unmodified `FoxDev`;
2. record the exact commands and results;
3. identify and document any pre-existing failures;
4. adapt or add a deterministic AutoDJ test harness before implementing group resolution.

A failure may only be classified as pre-existing when the baseline proves it.

### 2.2 One Change Request per functional increment

Every functional modification requires its own GitHub Change Request before implementation.

Each CR must state:

- the requested observable behavior;
- the functional rules and acceptance scenarios covered;
- explicit out-of-scope items;
- expected automated tests;
- validation evidence;
- the implementation PR and commits.

Preparatory refactoring may be attached to the functional CR only when it has no independent product behavior, remains narrowly scoped and has its own tests.

### 2.3 Small patches

Each implementation PR must cover one coherent increment. Data model, selection engine, scheduling, runtime state, API and UI must not be bundled unless the increment cannot be observed or tested without them.

Code from the removed Clock Wheels implementation or PR #8433 must not be ported implicitly. `PLAYLIST-GROUPS.md` is analysis material, not an implementation source.

### 2.4 Mandatory validation levels

Every patch must pass all three levels:

1. targeted tests for the behavior introduced or changed;
2. the complete test suite for the affected subsystem, including AutoDJ;
3. the full project non-regression suite using the same baseline commands.

A functional scenario that cannot yet be automated must be explicitly documented and manually validated in addition to, not instead of, automated tests.

### 2.5 Hard progression gate

Work on the next functional increment does not start until the current increment has:

- all targeted tests passing;
- all affected-subsystem tests passing;
- the full non-regression suite passing with no new failures;
- its functional scenario validated;
- its CR updated with validation evidence;
- its implementation PR reviewed and integrated into the reference branch.

If any gate fails, the implementation series stops on that increment.

## 3. First implementation boundary

The first increment is limited to the deterministic test foundation and the smallest sequential-group contract:

- stable, ordered member occurrences;
- repeated occurrences of the same child playlist;
- sequential wrap-around;
- acceptance sequence `A, B, C, A, B, C`;
- acceptance sequence `Jingle, Hot, Music, Hot`.

It must not include nesting, consecutive plays, full-cycle behavior, advanced scheduling interaction, Request Queue integration or UI work unless a separate CR has approved that scope.

## 4. Decisions still requiring explicit CR acceptance

The following semantics must be settled with concrete scenarios before their implementation increment begins:

- whether a temporarily scheduled-out member retains or consumes its position;
- composition of `play_full_cycle` and `consecutive_plays`;
- whether `consecutive_plays` is allowed on a nested-group occurrence and, if so, its unit;
- the authoritative commit point for rotation state across queue rebuilds and discarded queue items;
- duplicate-avoidance propagation through a selection path.

These open points do not block the baseline or the first sequential increment.

## 5. Umbrella tracking

Each implementation CR and PR must link back to this umbrella PR. This PR remains the implementation index and records, for every increment:

| Increment | CR | Implementation PR | Tests | Status |
| --- | --- | --- | --- | --- |
| Baseline and deterministic harness | TBD | TBD | TBD | Not started |
| Ordered occurrences and sequential wrap | TBD | TBD | TBD | Not started |

New rows are added only after the previous row has passed the hard progression gate.
