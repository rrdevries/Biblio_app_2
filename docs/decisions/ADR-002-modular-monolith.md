# ADR-002 — Modular monolith

Status: Accepted

## Decision

Build Biblio V2 as a modular WordPress application:

- WordPress infrastructure;
- Biblio Core;
- Crocoblock/JetEngine for suitable structured content and presentation support;
- Elementor Pro for app presentation/layout;
- targeted custom PHP/JS for integrity-sensitive or interaction-heavy workflows.

## Rationale

Biblio needs stronger domain boundaries and testability than a collection of loosely connected plugin settings, while a fully separate/headless custom application is unnecessary for v2.001.

## Consequences

- Biblio Core is the stable domain/application layer;
- plugin tools are adapters/building layers, not owners of business rules;
- complex workflows should not be assembled from hidden form fields and arbitrary snippets;
- Core workflows must be testable without Elementor;
- configuration must be reproducible/versioned where practical.
