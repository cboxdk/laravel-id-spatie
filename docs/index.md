---
title: Overview
description: Adopt Cbox ID for AuthN/SSO/OAuth/OIDC while an existing Spatie permission install stays the source of roles, permissions, and token claims
weight: 1
---

# Cbox ID for Spatie Permission

`cboxdk/laravel-id-spatie` lets an application that already uses
[spatie/laravel-permission](https://github.com/spatie/laravel-permission) adopt the
Cbox ID platform for authentication, SSO/SAML, the OAuth/OIDC provider, and SCIM —
while its existing Spatie install remains the single source of **roles, permissions,
and token claims**.

## The mental model

Cbox ID ships its own hierarchy-aware RBAC, but it never resolves authorization
directly: it depends on two contracts — `AccessChecker` (the read path and token
claims) and `Roles` (the write path used by SCIM and access governance). This package
implements both against Spatie and binds them under the platform's **external**
access-control driver.

```
Cbox ID (AuthN, SSO, OAuth/OIDC, SCIM)
        │  resolves authorization through
        ▼
AccessChecker / Roles  ──bound to──▶  Spatie roles & permissions (your backend)
```

Under the external driver the platform gates its own RBAC tables and services off, so
Spatie owns the `roles`/`permissions` tables and there is no collision.

## Sections

- **[Getting started](getting-started/_index.md)** — installation, the one-line model
  change, and testing.
- **[Core concepts](core-concepts/_index.md)** — how the adapter maps the platform's
  contracts onto Spatie, and how organizations map onto Spatie teams.
- **[Extending](extension-points/_index.md)** — customizing the mapping and the
  SCIM group→role bridge.
- **[Security](security/_index.md)** — the trust boundary and honest scope.

New here? Start with the **[quickstart](quickstart.md)**.
