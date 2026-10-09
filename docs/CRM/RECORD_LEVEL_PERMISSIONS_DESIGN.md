# Record-Level Permissions — Design Note

> Status: **shipped (Phase M)** — implemented as designed below. Two v1 scoping
> choices made during the build: (1) any share grants full (edit) access — the
> read/edit split is a documented follow-up; (2) background contexts (flow worker,
> webhooks, cron, inbound) run as system = privileged, so messaging is exempt and
> flow actions are NOT separately gated. Enforcement is the single `BaseModel`
> choke point; writes are covered by controllers' existing find-then-404.
> Goal: let a tenant restrict which records (deals, contacts, accounts, tickets,
> custom-object records, meetings) a non-privileged user can see and edit —
> "agents see only their own / their team's records," with explicit sharing.

---

## 1. Why this is more than what exists today

We already have the *ingredients*, but not enforcement:

- **`owner_id`** is present on every record-owning table: `accounts`, `contacts`,
  `deals`, `tickets`, `custom_object_records`, `meetings`, `saved_views`
  (see the `Create*`/`Alter*` migrations).
- **Teams** (`teams`, `team_members`) + `TeamVisibilityService::visibleUserIds()`
  resolve "self + teammates."
- **An opt-in scope** (`scope=mine|team|all`) in `CrmListController`.

The gap: that scope is a **display convenience the user chooses**, not a security
boundary. An agent can pass `scope=all` and see every record, and the filter only
exists on the unified list endpoint — direct `GET /deals/{id}`, `PUT`, `DELETE`,
search, export, dashboard aggregates, and flow actions are all unscoped. Record-
level permissions must be **mandatory, role-aware, and enforced in one place that
every read and write passes through.**

---

## 2. Model

### 2.1 Roles (already on `users.role`: owner / admin / agent)
- **owner, admin** → see and edit *all* records in the tenant. Unaffected.
- **agent** → governed by the visibility mode below.

Add `CurrentUser::role()` (reads `users.role`; today CurrentUser exposes only
`id()/tenantId()/tenantPlan()`). This is the linchpin the enforcement layer reads.

### 2.2 Per-tenant visibility mode (new column on `tenants`)
`record_visibility` ENUM:
- **`open`** (default, current behaviour) — agents see all. Zero-impact upgrade:
  existing tenants keep working exactly as today.
- **`owner`** — an agent sees a record only if they own it, it's shared with them,
  or they're privileged.
- **`team`** — `owner` + records owned by anyone on a shared team.

Gating visibility behind an explicit per-tenant mode (default `open`) is what makes
this a **safe, opt-in** rollout rather than a breaking change.

### 2.3 Explicit sharing (new table `record_shares`)
For the "share this one deal with a colleague / another team" case:

```
record_shares
  id, tenant_id,
  entity_type   (deal|contact|account|ticket|custom_record|meeting),
  entity_id,
  grantee_type  (user|team),
  grantee_id,
  access        (read|edit),
  created_by, created_at
```

A record is visible to an agent if **any** holds: they own it · it's shared to them
(or a team they're on) · they're owner/admin · mode is `open`. `access=edit`
additionally governs write.

---

## 3. Enforcement — one choke point

The only robust approach (we just learned this lesson hardening the test suite:
scatter the rule and an order/path will dodge it). Put it in **`BaseModel`**, which
every CRM model already extends and which already applies tenant scope.

### 3.1 Reads
Add `applyVisibility()` alongside the tenant scope. When the acting user is an
agent and the tenant mode ≠ `open`, AND the model opts in via a
`protected bool $ownable = true` flag, inject:

```sql
WHERE owner_id IN (:visibleUserIds)
   OR id IN (SELECT entity_id FROM record_shares
             WHERE tenant_id=? AND entity_type=? AND grantee… matches)
```

`visibleUserIds` = `[self]` in `owner` mode, `self + teammates` in `team` mode
(reuse `TeamVisibilityService`). Because it lives in the base model's query
builder, it covers `find()`, `findAll()`, the unified list, search, export, and
dashboard aggregates **for free** — no per-controller code.

The existing `scope=mine|team|all` in `CrmListController` stays as a *narrowing*
UI filter **on top of** the mandatory floor (an agent's `scope=all` can no longer
exceed what visibility allows).

### 3.2 Writes & single-record reads
`find($id)` returns null for an invisible record → controllers already 404 on
null, so `GET/PUT/DELETE /{id}` become safe automatically. Add an explicit
`assertCanEdit($id)` guard in the update/delete/stage-move paths for `access=read`
shares (visible but not editable) → 403.

### 3.3 Paths that bypass models
Audit and route through the same gate (or deliberately exempt with a comment):
flow actions (`FlowEngine` CRM actions), bulk actions, merge, recycle-bin
restore/purge, quote generation, WhatsApp/inbox contact lookups. **Inbound
messaging must stay exempt** — a WhatsApp reply has no acting user; the window/
inbox logic is system-level, not agent-scoped.

---

## 4. API & UI surface
- `tenants.record_visibility` editable by owner/admin in Settings.
- `POST /api/v1/{entity}/{id}/shares`, `DELETE …/shares/{shareId}`, `GET …/shares`.
- A "Share" affordance on each record page; a lock/owner chip on list rows the
  user has read-only.
- `403` (not 404) on edit-denied so the UI can show "read-only — request access."

---

## 5. Migration & rollout
1. Migration: `tenants.record_visibility` default `open`; create `record_shares`.
2. Ship with every tenant on `open` → **no behavioural change** until a tenant
   opts in. This is the whole de-risking strategy.
3. Add the test-schema columns/table to the relevant traits (now order-safe).

---

## 6. Test plan (the must-cover list)
- Agent in `owner` mode: sees own, not others'; `find(other)` → null; list/search/
  export/dashboard all exclude others'.
- `team` mode: sees teammates', not other teams'.
- Shares: `read` share → visible but `PUT` → 403; `edit` share → editable; team
  share covers all its members; revoke removes access.
- owner/admin: unaffected in every mode.
- `open` mode (default): byte-for-byte current behaviour (regression guard).
- Bypass paths: flow action can't mutate an invisible record; bulk action filtered;
  merge/restore scoped; **inbound message still creates/updates its contact.**
- Plan-gate (optional): restrict the feature to paid plans via `FeatureGate`.

---

## 7. Estimate & risks
- **~1 migration, 1 model (`RecordShareModel`), 1 enforcement method on BaseModel,
  `CurrentUser::role()`, ~3 share endpoints, settings + record-page UI, ~15 tests.**
- **Risk:** a missed bypass path leaks data. Mitigation: enforce in BaseModel (not
  controllers), and the bypass-path test list above is mandatory, not optional.
- **Risk:** performance of the `record_shares` subquery on large tenants. Mitigation:
  index `record_shares(tenant_id, entity_type, entity_id)` and
  `(tenant_id, grantee_type, grantee_id)`; the owner_id branch is already indexed.
- **Decision needed from product:** is default `team`-mode visibility "all teams I'm
  on" (proposed) or "a single primary team"? Affects `visibleUserIds`.
