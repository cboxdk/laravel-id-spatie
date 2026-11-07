---
title: Overview
description: Adopt Cbox ID for AuthN/SSO/OAuth/OIDC while an existing Spatie permission install stays the source of roles, permissions, and token claims
weight: 1
---

# Cbox ID for Spatie Permission

The external-driver adapter implements ID roles, live authorization and directory
mapping with environment, organization, app and staff boundaries. Managed tenant
grants project into native Spatie teams. Unmapped native data never becomes an ID grant.

Start with [installation](getting-started/installation.md). Existing hosts must use
the [scoped authorization upgrade](getting-started/scoped-authorization-upgrade.md)
and explicitly recreate approved grants before admitting users.
