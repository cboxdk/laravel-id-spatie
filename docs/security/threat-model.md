---
title: Trust boundary & scope
description: What the adapter is responsible for, what it delegates, and its limits
weight: 41
---

# Trust boundary and scope

The adapter owns authorization metadata, explicit scoped grants, and directory
provenance. ID owns authentication; Spatie stores managed role permission links and
tenant projections. A native Spatie assignment alone grants no ID access.

Reads require the current environment and matching managed authority, exclude
other apps, and deny tenant access unless both team flags are enabled. Staff roles
are unavailable to tenant grants and directory mappings. Cross-organization groups
are refused. Explicit environment-wide grants are separate from tenant grants.

Native `hasPermissionTo` cannot enforce ID app scope or environment-wide grants. It
must not replace fresh ID decisions. Token claims are snapshots; protected actions
also require application-owned resource checks.

Legacy rows without environment metadata are quarantined. Rollback refuses before
altering schema while managed definitions/grants or scoped ledgers remain. New grants
and their native projection, events and audit writes are transactional.

The external adapter does not supply builtin manifest staging, activation, hierarchy
roll-down or staff-session elevation. Hosts must implement those capabilities
explicitly or use ID's builtin driver. Default external staff access denies.

Report vulnerabilities through GitHub Private Vulnerability Reporting.
