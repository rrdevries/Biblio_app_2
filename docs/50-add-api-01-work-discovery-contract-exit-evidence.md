# 50 — ADD-API-01 Work discovery contract exit evidence

Status: **GO / CLOSED**

Date: 2026-09-07

Task severity: **Medium**

## 1. Outcome

The existing platform-wide `GET /biblio/v1/me/works` discovery route is reusable
for Add Book's explicit `Koppel aan bestaand werk` step. It remains the only
Work-search system and remains backwards-compatible for Next Reading.

The former Next Reading-specific Work search types were replaced by the
catalog-wide `Application\Catalog\Discovery` boundary. Next Reading-specific
preferred-source discovery remains separate and unchanged.

## 2. Contract and search

The request stays `q`, optional `limit`, and optional opaque `cursor`. A
non-empty normalized search term is required, its maximum remains 100
characters, the default and maximum limits remain 10 and 25, and the version-1
cursor payload remains byte-shape compatible.

Search is a literal escaped substring match against either:

- central `works.work_title`; or
- a linked central `authors.display_name` through `work_contributors`.

An `EXISTS` predicate prevents a Work that matches title and one or more Authors
from being duplicated. There is no relevance score, ranking, fuzzy matching,
entity matching or automatic selection. Pagination remains ordered by Work
title and Work ID.

## 3. Response allowlist

Each item contains exactly:

- `work_id`;
- `title` (the Work title);
- ordered `authors`, each with `author_id` and `display_name`;
- `work_title_status`, with CAT-T1 values `provisional` or
  `librarian_confirmed`;
- existing central `series`, each with `series_id`, `display_name` and nullable
  `position`.

Original language is absent because there is no current Work-level
persistence/query source. No new language model was introduced.

## 4. Authorization and privacy

The REST permission callback and Core application service both require an
authenticated actor. Discovery remains platform-wide and requires no Library
Context. It is read-only and grants no mutation right.

The result has no Library Item, membership, ownership, Library-local metadata or
personal data. The later MH-B5B commit continues to reauthorize explicit Library
Context and `catalog.item_add`, and verifies the selected Work server-side.

## 5. Verification

Focused proof covers title search, Author search, a title-plus-Author duplicate,
literal wildcard escaping, stable pagination and cursor mismatch, limits,
response field allowlisting, anonymous denial, central Series/title-status
projection and the existing Next Reading source-discovery privacy contract.

Verified in the final working tree:

- complete unit suite: 410 tests, 1,728 assertions, green with two existing
  PHPUnit notices;
- complete MariaDB integration suite: 338 tests, 4,096 assertions, green;
- complete PHP syntax pass: green;
- definitive PHPStan analysis at level 6: green;
- standalone WordPress smoke: plugin active, Core class loaded, init hook ran
  once and HTTP returned 200;
- `manifest.json` parse and Git whitespace checks: green;
- complete `./scripts/test-biblio-core-all.sh`: green in 160 seconds.

## 6. Scope and schema

Schema remains `1017`. No Add Book UI, automatic Work match, new Work/Edition
governance, Series Intelligence, external metadata, Librarian UI or MH-B5B
behavior was added.

The independent second review pass found no remaining architecture,
authorization, privacy, compatibility or regression issue.
