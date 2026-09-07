# 51 — ADD-API-02 Add Book UI contract completion

Status: **GO / CLOSED**

Date: 2026-09-07

Task severity: **High**

## 1. Outcome and ADD-UI-01 readiness

The three proven contract gaps from ADD-UI-00 are closed without building the
Add Book Wizard. ADD-UI-01 can consume local Edition recognition context,
Library-scoped classification options and the current shared Work-discovery
response without hardcoded classification IDs or a parallel decoder.

## 2. Existing and local-ambiguous Edition context

Each local B5A match now contains the existing Work/Edition identity and title
fields plus:

- ordered central `authors`, allowlisted to `author_id` and `display_name`;
- `canonical_isbn`, normalized to ISBN-13; and
- the existing Library-scoped `existing_item_count` / `existing_items` context.

The Author relationship reads are batched across all local matches. Item reads
remain constrained by explicit Library ID and Edition IDs. Local exact and
local ambiguous paths still return before Metadata Hub lookup, so neither
provider is called for an existing Edition.

Cover, language, publisher and publication date are intentionally absent. The
current catalog UI projection reports those values as unknown and Core has no
approved reliable cover source. This slice adds no provider lookup, persistence
or new bibliographic model to fill those gaps.

## 3. Classification options contract

The new read-only route is:

`GET /biblio/v1/libraries/{library_id}/classification-options`

Its exact top-level allowlist is `library_id`, `book_types`, `genres` and
`subjects`. Each option contains only its existing Library-local ID and
`display_name`. The route delegates to the existing active-term reads on
`LibraryClassificationQueryService`; it introduces no seed-ID dependency,
taxonomy logic or mutation.

The normal authenticated REST permission callback applies. Core resolves the
actor's explicit Library Context before each scoped persistence read. Foreign
and missing Libraries return the same safe not-available response, inactive
terms are excluded and no foreign Library terms enter the payload.

There was no existing safe Location REST route to reuse. ADD-API-02 therefore
adds no Location endpoint or second Location system.

## 4. Shared `/me/works` frontend decoder

The catalog-wide `work-discovery.js` module exports `readWorkPage()` and
`readWorkDiscoveryWork()`. It strictly allowlists and validates the
current ADD-API-01 fields:

- `work_id` and `title`;
- ordered `authors` with `author_id` / `display_name`;
- `work_title_status` as `provisional` or `librarian_confirmed`; and
- `series` with `series_id`, `display_name` and nullable `position`.

Next Reading imports that shared page decoder while continuing to use its
separate minimal Work decoder only for its own list projection. No second
discovery-response decoder or product behavior was added.

## 5. Verification

Verified in the final working tree:

- focused Add Book unit: 5 tests, 56 assertions;
- focused REST integration: 50 tests, 1,055 assertions;
- complete Core unit suite: 410 tests, 1,735 assertions, with the two existing
  non-failing PHPUnit notices;
- complete Core MariaDB integration suite: 339 tests, 4,125 assertions;
- complete Biblio UI JavaScript suite: 192 tests;
- PHPStan level 6: passed;
- complete repository quality gate: passed;
- PHP syntax, Composer/platform, WordPress smoke, manifest JSON and Git
  whitespace checks: passed.

## 6. Scope and schema

Schema remains `1017`. No Add Book UI, Elementor work, camera scanner, Book
Detail edit mode, collector-field persistence/UI, Librarian UI, provider
fusion, automatic Work matching, Expression layer, classification taxonomy,
Location route, new capability or central metadata mutation was added.
