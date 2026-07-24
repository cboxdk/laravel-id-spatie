---
title: Trust boundary & scope
description: What the adapter is responsible for, what it delegates, and its limits
weight: 41
---

# Trust boundary & scope

This package is a thin adapter. It owns no authentication, no cryptography, and no
storage of its own — it translates between Cbox ID's authorization contracts and
spatie/laravel-permission.

## What it is responsible for

- **Correct translation.** Mapping the platform's `AccessChecker`/`Roles` calls onto
  Spatie faithfully, including organization→team scoping when teams are enabled.
- **Deny-by-default on the read path.** An unknown permission or an unresolved subject
  yields no access (`can` false, empty claims) rather than an error or an accidental
  grant.
- **Failing loud on misconfiguration.** A user model that is not a `SpatieSubject`
  raises `MisconfiguredUserModel`; assigning a role to a missing subject raises
  `UnknownSubject`; an unknown role raises the platform's `UnknownRole`. None of these
  degrade to a silent wrong answer.

## What it delegates

- **Authentication, SSO, tokens, SCIM** — Cbox ID. This package only supplies the
  authorization claims stamped into those tokens.
- **Role/permission storage and evaluation** — spatie/laravel-permission, including its
  permission cache and guard handling.

## Honest scope and limits

- **Shared identity assumption.** The adapter resolves a subject with
  `UserModel::find($subjectId)`, so the host's authorizable model must be keyed by the
  same id Cbox ID uses. A mismatched id space means checks resolve no user and deny.
- **No hierarchy roll-down.** The platform's ancestor-org roll-down is a builtin-driver
  feature; a flat or team-scoped Spatie backend does not model it. Compose it yourself
  in a custom `AccessChecker` if required.
- **Grant source comes from this package's ledger, not Spatie.** Spatie records no
  origin, so a grant is reported as `pushed` only if the directory bridge made it. A
  role granted directly in Spatie (bypassing the `Roles` contract) is indistinguishable
  from an admin's manual grant — which is the safe direction: reconciliation will never
  revoke it.
- **Priority is ordering only.** `priority` orders `forOrganization()`; a role is
  granted whenever any mapped group matches, so mappings union rather than override.
- **Default Spatie models only.** Custom role/permission model classes are not
  auto-resolved.

## Reporting

Report suspected vulnerabilities through this repository's GitHub **Private
Vulnerability Reporting**. There is no dedicated security mailbox for this adapter.
