# AzuraCast — Playlist Groups / Clock Wheels

## Functional Specification

**Status:** Draft for implementation design  
**Version:** 0.1  
**Reference material:** AzuraCast PR #8433  
**Purpose:** Define the desired functional behavior independently from the implementation proposed by PR #8433.

---

## 1. Goal

AzuraCast must support **Playlist Groups**, also referred to here as **Clock Wheels**.

A Playlist Group is a playlist whose members are other playlists rather than media files. Its purpose is to define radio programming structures such as:

```text
Morning Clock
  Jingle
  Hot Rotation
  Music
  Hot Rotation
  Station ID
```

The group controls **which member is selected next**. The selected member retains responsibility for **which track it returns**.

This separation is fundamental:

> Group ordering and track ordering are independent behaviors.

The feature must compose with the existing AzuraCast playlist scheduler instead of creating a second scheduling system.

---

## 2. Scope

### 2.1 Phase 1 — Core Clock Wheels

The initial implementation MUST cover:

- playlist groups;
- ordered group members;
- nested groups;
- cycle prevention;
- Sequential, Shuffle and Random member ordering;
- independent playback order for member playlists;
- repeated occurrences of a playlist within a group;
- per-member consecutive plays;
- full-cycle playback for eligible member playlists;
- normal playlist eligibility and scheduling rules;
- exclusion of group members from top-level AutoDJ rotation;
- graceful fallback when a member cannot produce a track;
- duplicate-prevention inheritance;
- preservation of the complete selection path.

### 2.2 Phase 2 — Request integration

Request Queue integration SHOULD be treated as a separate implementation phase:

- Request Queue as a playlist source;
- Request Queue playlists inside groups;
- per-member request policy;
- scheduled request blocking;
- optional replacement of the legacy global request-injection behavior.

### 2.3 Explicitly out of scope

The following PR #8433 changes are not requirements of Clock Wheels:

- JSON station export/import;
- dummy-media generation;
- general AutoDJ test framework changes;
- unrelated scheduler bug fixes;
- unrelated Schedule View/UI refactors;
- persistence mechanisms used by the PR implementation.

---

## 3. Terminology

### Playlist

An existing AzuraCast playlist capable of providing a playable item according to its own source, playback order, schedule and eligibility rules.

### Playlist Group / Clock Wheel

A playlist whose contents are references to playlists or other Playlist Groups.

### Member

A single **occurrence** of a playlist or group inside a parent group.

A member is not the referenced playlist itself. Two occurrences referencing the same playlist are two distinct members and MAY have different per-member settings.

### Root playlist

A playlist considered directly by the station's normal top-level AutoDJ selection process.

### Selection path

The ordered chain traversed to obtain a playable track.

Example:

```text
Morning Clock > Music Block > Gold > track.mp3
```

### Member cycle

One traversal of a group's eligible members according to its playback order.

### Track cycle

One traversal of the tracks of a member playlist according to that playlist's own playback-order semantics.

---

## 4. Functional model

### FR-001 — A group contains playlist references

**MUST**

A Playlist Group MUST contain zero or more ordered member occurrences referencing playlists.

The same playlist MAY:

- belong to multiple groups;
- appear multiple times in the same group.

Example:

```text
Clock
  Jingle
  Hot
  Music
  Hot
```

The two `Hot` entries MUST remain distinct positions in the Clock.

### FR-002 — Groups may be nested

**MUST**

A group MAY reference another group.

Selection therefore MAY recurse through several groups before reaching a playable source.

No arbitrary functional nesting-depth limit is required by this specification. An implementation MAY impose a defensive technical limit if necessary, provided normal valid configurations are not affected.

### FR-003 — Cycles are forbidden

**MUST**

The group graph MUST be acyclic.

Direct cycles and indirect cycles MUST be rejected.

Invalid:

```text
A > A
```

Invalid:

```text
A > B > C > A
```

Cycle validation SHOULD occur before the configuration is committed.

---

## 5. Group member selection

### FR-010 — Sequential order

**MUST**

For a group configured as `Sequential`, member occurrences MUST be considered in their configured order and wrap to the beginning after the last member.

Given:

```text
A, B, C
```

the nominal sequence is:

```text
A, B, C, A, B, C, ...
```

### FR-011 — Shuffle order

**MUST**

For a group configured as `Shuffle`, each member occurrence MUST appear once per completed member cycle, in randomized order.

After the cycle is completed, a new randomized cycle MUST be generated.

For `A, B, C`, valid cycles include:

```text
B, A, C | C, B, A | ...
```

Within a completed cycle, an occurrence MUST NOT be selected twice while another eligible occurrence has not yet been selected.

### FR-012 — Random order

**MUST**

For a group configured as `Random`, each selection MUST be an independent random choice among candidates that can be considered at that point.

Immediate repetition is allowed unless another existing AzuraCast rule prevents it.

Example:

```text
A, A, C, B, A, C, C, ...
```

### FR-013 — Group order is independent of track order

**MUST**

The member-order policy of a group MUST NOT replace or mutate the playback-order policy of a member playlist.

Example:

```text
Clock: Sequential
  Jingles: Shuffle
  Hot: Sequential
  Music: Random
```

`Clock` decides whether `Jingles`, `Hot` or `Music` is selected. Each member independently decides which track it returns.

---

## 6. Per-member playback behavior

### FR-020 — Consecutive plays

**MUST**

Each member occurrence MUST support a positive `consecutive plays` value, defaulting to `1`.

When an occurrence with `consecutive plays = N` successfully produces a track, the group SHOULD continue selecting that same occurrence until N successful plays have been produced before advancing normally.

Given:

```text
A (consecutive=2)
B (consecutive=1)
```

the expected source sequence is:

```text
A, A, B, A, A, B, ...
```

The counter belongs to the member occurrence, not globally to the referenced playlist.

Failed attempts MUST NOT be counted as successful consecutive plays.

### FR-021 — Play full cycle before advancing

**MUST**, for playlist orders for which a finite cycle is meaningful.

A member MAY be configured to complete one track cycle before its parent group advances to the next member occurrence.

Given:

```text
Clock: Sequential
  A: [a1, a2, a3], full-cycle=true
  B: [b1]
```

the source sequence is:

```text
a1, a2, a3, b1, a1, a2, a3, b1, ...
```

Without full-cycle playback:

```text
a1, b1, a2, b1, a3, b1, ...
```

### FR-022 — Full-cycle semantics for Random

**TO DECIDE**

`Random` has no natural finite cycle. The product must decide whether `play full cycle` is:

1. unavailable for Random playlists; or
2. defined using a synthetic finite cycle.

Preferred rule: disable `play full cycle` where the playlist's playback order does not expose a meaningful finite cycle.

### FR-023 — Interaction between full-cycle and consecutive plays

**TO DECIDE**

If both options are configured on the same occurrence, their composition must be explicitly defined.

Preferred rule: treat full-cycle as the unit of advancement and avoid exposing `consecutive plays` simultaneously when full-cycle is enabled. This prevents ambiguous meanings such as "N tracks" versus "N complete cycles".

---

## 7. Eligibility and scheduling

### FR-030 — Membership does not override eligibility

**MUST**

Being referenced by a group MUST NOT make a playlist playable when it would otherwise be ineligible.

Existing eligibility rules remain authoritative, including as applicable:

- enabled/disabled state;
- schedule;
- playlist type;
- source availability;
- media availability;
- other existing AutoDJ constraints.

General rule:

> Group membership controls selection structure, not playlist eligibility.

### FR-031 — Existing schedules remain authoritative

**MUST**

A group member MUST retain its own schedule.

Example:

```text
Clock
  Day Rotation       08:00-20:00
  Night Rotation     20:00-08:00
```

The group MUST only obtain tracks from a member while that member is eligible according to the existing scheduler.

A root group MAY itself have the same scheduling/playlist-type rules available to a normal root playlist.

### FR-032 — Group members are not top-level AutoDJ candidates

**MUST**

Once a playlist is referenced as a member of at least one Playlist Group, it MUST NOT independently participate in the normal top-level AutoDJ rotation.

It remains reachable through every group that references it.

This prevents a child playlist from being selected both through its Clock and independently outside that Clock.

### FR-033 — Shared members

**MUST**

A playlist referenced from several groups remains one playlist with one playlist-level configuration, but each membership occurrence retains its own group-position settings.

Changes to the playlist itself apply everywhere it is referenced.

Changes to occurrence settings affect only that occurrence.

---

## 8. Unavailable members and fallback

### FR-040 — An unavailable member must not block the group

**MUST**

If a selected/considered member cannot provide a playable track, the group MUST attempt another eligible member according to its selection semantics.

Possible causes include:

- disabled member;
- member outside its schedule;
- empty playlist;
- unavailable source;
- nested group unable to resolve a playable descendant;
- playlist otherwise ineligible.

### FR-041 — An empty group must fail cleanly

**MUST**

If no member of a group can provide a playable item, that group MUST return a clean "no playable item" result to its caller.

If the group is a top-level AutoDJ candidate, the normal AutoDJ selection process MUST then be able to continue with another root candidate.

A broken or temporarily empty Clock MUST NOT stall the AutoDJ queue builder.

### FR-042 — Failed-member advancement

**TO DECIDE — IMPORTANT**

The product must define whether a temporarily ineligible Sequential/Shuffle member is **consumed** from the current group cycle or merely skipped for the current resolution attempt.

PR #8433 effectively advances past such members. This specification does not adopt that behavior automatically.

Preferred semantics:

- a member that is structurally unusable (deleted, disabled, empty with no prospect of a result) may be advanced;
- a member that is temporarily unavailable only because of scheduling should not necessarily lose its intended position;
- the final rule must avoid retry loops and starvation.

This needs explicit scenarios before implementation.

---

## 9. Nested groups and state

### FR-050 — Each group owns its selection state

**MUST**

Every group in a nested hierarchy MUST maintain its own logical member-selection state.

Advancing a parent MUST NOT reset a child's internal playlist/group ordering unless a separately defined rule explicitly requires a reset.

Example:

```text
Top: Sequential
  Branch: Sequential
    A
    B
    C
  Jingle
```

Repeated visits to `Branch` SHOULD continue its sequence rather than implicitly restarting at `A` each time.

### FR-051 — Selection path

**MUST**

Every successfully selected queue item MUST preserve enough information to identify the full logical path through which it was selected.

Example:

```text
Morning Clock > Music > Gold > track.mp3
```

This information SHOULD be available to consumers such as:

- queue/timeline displays;
- Now Playing diagnostics;
- API/debugging;
- future programming analytics.

The specification requires the information, not a specific persistence format or UI presentation.

---

## 10. Duplicate prevention

### FR-060 — Duplicate-prevention inheritance

**MUST**

Duplicate-prevention restrictions MUST accumulate while traversing a group hierarchy.

If duplicate prevention is enabled at any applicable ancestor, a descendant MUST NOT weaken it.

Example:

```text
Root Group: avoid_duplicates=true
  Child Group: avoid_duplicates=false
    Playlist: avoid_duplicates=false
```

The final selection remains subject to duplicate prevention.

Conceptually:

```text
effective_avoid_duplicates = OR(all applicable ancestors and selected playlist)
```

### FR-061 — Other inherited policies

**SHOULD**

Any future group-level policy that propagates to descendants should define an explicit composition rule rather than mutating child playlist configuration.

---

## 11. Request Queue integration — Phase 2

### FR-100 — Requests as a playlist source

**SHOULD**

AzuraCast SHOULD support a playlist source that obtains its next playable item from the station Request Queue.

This allows a Clock such as:

```text
Jingle
Music
Request
Music
Request
```

instead of requests being exclusively injected by an external priority mechanism.

### FR-101 — Empty Request Queue

**MUST**, if FR-100 is implemented.

If a Request Queue playlist has no valid request to return, it MUST behave like any other member that cannot currently provide an item. Its parent group MUST be free to continue to another member.

### FR-102 — Legacy global request mode

**SHOULD**

The station SHOULD provide a migration mode allowing administrators to choose between:

- legacy automatic/global request injection; and
- requests played only through Request Queue playlists.

The exact UI naming is not specified here.

### FR-103 — Per-member request policy

**SHOULD**

A group-member occurrence MAY expose a request policy with these functional meanings:

| Policy | Meaning |
| --- | --- |
| `none` | Requests are not accepted at this position. |
| `playlist` | Only valid requests belonging to the selected playlist/subtree are accepted. |
| `any` | Any valid station request may be accepted. |

The policy belongs to the occurrence/context, not to the referenced playlist globally.

### FR-104 — Scheduled request blocking

**SHOULD**

A schedule window MAY explicitly prevent legacy/global requests while the window is active.

Example:

```text
12:00-13:00 Special Show
prevent_requests = true
```

This rule must be expressed as a scheduling policy, not by changing the underlying playlist's permanent request configuration.

### FR-105 — Random-group request semantics

**TO DECIDE**

Request filtering in a Random group needs an explicit contract. The implementation must not infer a restrictive subtree-wide policy merely because the next random member is not yet known.

Preferred approach: first select the logical member according to normal group semantics, then evaluate the request policy in the context of that selected path.

---

## 12. Configuration and data semantics

### FR-200 — Stable membership occurrences

**SHOULD**

Membership occurrences SHOULD have stable identity independent of the referenced playlist ID.

This supports:

- the same playlist appearing twice in one group;
- per-occurrence settings;
- editing/reordering without destroying logical identity;
- runtime-state association without encoding it onto the playlist itself.

### FR-201 — Editing a group

**SHOULD**

Saving a group SHOULD preserve unchanged membership occurrences and their identity.

The functional model does not require a delete-and-recreate-all-members operation.

### FR-202 — Configuration vs runtime state

**MUST**

Persistent configuration and runtime rotation state are conceptually distinct.

Configuration includes, for example:

- member position;
- referenced playlist;
- consecutive plays;
- full-cycle option;
- request policy.

Runtime state includes, for example:

- current Sequential position;
- current Shuffle cycle/order;
- consecutive-play progress;
- full-cycle progress.

This specification does not require either transient or database persistence for runtime state. That is an architectural decision.

---

## 13. Expected UI behavior

### FR-300 — Playlist source/type

**MUST**

The playlist editor MUST allow an administrator to create a Playlist Group and manage its members.

### FR-301 — Member list

**MUST**

The UI MUST make member occurrence order visible and editable for Sequential semantics.

It MUST allow the same playlist to appear more than once.

### FR-302 — Preserve child playlist configuration

**MUST**

Adding a playlist to a group MUST NOT silently change or hide its own track playback-order configuration.

In particular, `Sequential`, `Shuffle`, `Random`, or equivalent playlist-level ordering remains a property of the child playlist.

### FR-303 — Prevent invalid group selection

**MUST**

The UI/API MUST reject group membership changes that would create a cycle.

### FR-304 — Explain top-level exclusion

**SHOULD**

The UI SHOULD make it clear when a playlist no longer participates independently in top-level AutoDJ rotation because it is a group member.

---

## 14. Acceptance scenarios

These scenarios form the initial behavioral contract. They are intentionally implementation-independent and should become automated tests.

### AC-01 — Sequential group

Given a group containing `A, B, C`, all playable,  
when six items are requested from the group,  
then source selection is:

```text
A, B, C, A, B, C
```

### AC-02 — Independent child order

Given a Sequential group containing `A` and `B`,  
and `A` has its own Sequential track order `a1, a2`,  
when four items are resolved,  
then the result is:

```text
a1, b1, a2, b2
```

subject to `B`'s own playback policy.

### AC-03 — Repeated playlist occurrence

Given:

```text
Jingle, Hot, Music, Hot
```

when the group completes a Sequential cycle,  
then `Hot` is visited twice at its two configured positions.

### AC-04 — Consecutive plays

Given `A(consecutive=2), B(consecutive=1)`,  
when six successful items are resolved,  
then sources are:

```text
A, A, B, A, A, B
```

### AC-05 — Full child cycle

Given `A=[a1,a2,a3]` with full-cycle enabled and `B=[b1]`,  
when eight items are resolved,  
then:

```text
a1, a2, a3, b1, a1, a2, a3, b1
```

### AC-06 — Shuffle group

Given three playable occurrences `A, B, C`,  
when two Shuffle cycles complete,  
then each cycle contains exactly one occurrence of `A`, `B`, and `C`.

### AC-07 — Random group

Given playable occurrences `A, B, C`,  
when Random selection is used,  
then repeated occurrences are permitted and selection is not constrained to one-of-each cycles.

### AC-08 — Scheduled child

Given a group containing a child whose schedule is inactive and another playable child,  
when the group resolves a track,  
then it MUST NOT obtain a track from the inactive child and MUST be able to resolve through the other child.

### AC-09 — Empty child

Given a selected child that is empty and another playable child,  
when the group resolves a track,  
then the empty child does not block resolution.

### AC-10 — Empty root group

Given a root Clock with no playable descendants and another eligible root playlist,  
when AutoDJ builds the queue,  
then failure of the Clock does not prevent the other root playlist from being selected.

### AC-11 — Nested group state

Given a child Sequential group `A,B,C` visited repeatedly from a parent,  
then successive visits continue the child's own ordering unless a defined cycle rule resets it.

### AC-12 — Cycle rejection

Given `A > B > C`,  
when the administrator attempts to add `A` as a child of `C`,  
then the configuration is rejected and the previous valid configuration remains intact.

### AC-13 — Child not globally selected

Given playlist `A` is a member of Clock `C`,  
when AutoDJ evaluates top-level candidates,  
then `A` is not independently selected outside `C`.

### AC-14 — Duplicate prevention inheritance

Given duplicate prevention enabled on an ancestor group and disabled on its selected child,  
when the child resolves a track,  
then the ancestor restriction remains effective.

### AC-15 — Selection path

Given:

```text
Top > Branch > Music > track.mp3
```

when the track is queued,  
then the queue result retains `Top > Branch > Music` as its selection path.

### AC-16 — Request source empty (Phase 2)

Given a Request Queue member followed by a Music member and no valid request exists,  
when the group resolves an item,  
then it can proceed to Music without failing the Clock.

---

## 15. Non-functional implementation constraints derived from the spec

These are design constraints, not a prescribed architecture.

### NF-01 — Deterministic testability

Sequential and Shuffle/Random behavior MUST be testable. Randomized behavior SHOULD accept a controllable/random-source abstraction or otherwise permit deterministic test scenarios.

### NF-02 — Resolution must terminate

Group resolution MUST have bounded behavior for a single queue request. Invalid or unavailable descendants MUST NOT cause infinite recursion or retry loops.

### NF-03 — Existing playlists remain compatible

Stations not using Playlist Groups MUST retain their existing AutoDJ behavior.

### NF-04 — Existing scheduler remains authoritative

Clock Wheels SHOULD consume the existing playlist eligibility/scheduling contract rather than duplicate it in a group-specific scheduler.

### NF-05 — Do not mutate child configuration during traversal

Effective inherited policies SHOULD be computed as selection context. Traversing a group SHOULD NOT rewrite the referenced child's persistent settings.

---

## 16. Decisions required before technical design

The following points are intentionally not inherited from PR #8433 and must be decided explicitly:

1. **Skipped scheduled members:** does a temporarily inactive member consume its Sequential/Shuffle position or retain it?
2. **Full-cycle + Random:** unsupported, synthetic cycle, or other semantics?
3. **Full-cycle + consecutive plays:** mutually exclusive, N tracks, or N complete cycles?
4. **Runtime state lifetime:** what happens across queue rebuilds, service restarts and configuration edits?
5. **Root-member rule:** if a playlist belongs to a group, is top-level exclusion unconditional or should an explicit override ever exist? This draft says unconditional.
6. **Nested state reset:** should completing a parent cycle ever reset child cycles? This draft says no implicit reset.
7. **Request Random semantics:** selection-first then request filtering is preferred, but must be confirmed for Phase 2.
8. **Request legacy coexistence:** exact precedence between Request Queue playlists and the legacy global request mechanism during migration.

---

## 17. Recommended implementation boundary

The functional behavior naturally separates into these responsibilities:

```text
AutoDJ root selection
        |
        v
Playlist eligibility / scheduler
        |
        v
Group member selection (recursive)
        |
        v
Leaf playlist track selection
        |
        v
Queue item + selection path
```

This is not a class design. It expresses the required separation of concerns:

- top-level AutoDJ chooses among root candidates;
- the existing scheduler determines whether a playlist/group is eligible;
- a group chooses a member occurrence;
- recursion continues until a leaf source is reached;
- the leaf playlist chooses its media according to its own rules;
- contextual restrictions accumulate along the path;
- the final queue item records how it was selected.

The implementation should therefore avoid turning the main queue builder into the owner of every Clock-specific state and rule.

---

## 18. Definition of done for Phase 1

Phase 1 is functionally complete when:

- all MUST requirements from sections 4–10 and 13 are implemented;
- acceptance scenarios AC-01 through AC-15 pass;
- the unresolved decisions that affect Phase 1 have explicit answers and tests;
- ordinary stations that do not use groups behave unchanged;
- nested groups cannot deadlock or indefinitely loop AutoDJ queue generation;
- group membership does not destroy or overwrite a member playlist's own playback-order configuration.

Request integration (section 11 and AC-16) is not required to declare Phase 1 complete.

