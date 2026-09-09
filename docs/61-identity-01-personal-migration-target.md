# IDENTITY-01 — Personal user and migration target

Status: **TECHNICAL GO WITH HUMAN SETUP PENDING**

Schema: `1017` (unchanged)

Biblio UI: `0.11.0` (unchanged)
Scope: local identity bootstrap and explicit future MIG-02 target validation;
no V1 read/import, migration ledger, cleanup, schema or UI.

## D-IDENTITY-01

Renée has one persistent normal WordPress/Biblio user identity. Canonically
user-owned ReadingRounds, Notes, Ratings/Reviews, Wishlist and other personal
data remain attached to that identity. Her designated personal Library is a
separate Library-owned context named `Mijn Bibliotheek`, linked through active
`Eigenaar` + `Directe toegang` membership.

Platform privileges and Library ownership are independent. `Admin`, `Super
admin` and the separate `Biblio Librarian` capability may later be added to or
removed from the same account without another Renée account and without any
membership or personal-data mutation. IDENTITY-01 assigns none of them.

The DDEV/platform admin remains a separate identity and is never inferred as
the migration target.

## Identity and provisioning audit

- Biblio `UserId` is the decimal existing `wp_users.ID`; Core has no duplicate
  user table or admin persona.
- `CreateLibraryService` atomically persists Library, Owner membership and
  standard classification seeds.
- `ProvisionPersonalPrivateLibraryService` adds the explicit user→Library
  designation inside that transaction and safely reuses it on rerun/race.
- the designation, not the non-unique Library name, is the stable bootstrap
  identity;
- membership uniqueness is Library×user, exactly one active Owner is allowed
  per Library, and one designated Library is allowed per user and vice versa;
- WordPress roles/capabilities and Biblio membership are stored independently;
- the current Core has no implemented `Biblio Librarian` WordPress role or
  capability. That deferred platform-governance gap does not couple identity
  to Library ownership and is not implemented by this slice.

## Local operator commands

Account bootstrap is deliberately restricted to a WordPress `local`
environment running inside DDEV. It requires exact non-secret operator input:

```bash
ddev exec wp --path=web biblio identity bootstrap \
  --user-login=<login> \
  --user-email=<email> \
  --display-name='Renée'
```

The command creates a new `subscriber` through the WordPress user lifecycle,
uses a generated password that is never output, and sends WordPress's normal
user password-reset notification. It never accepts or commits a password.
Rerun requires the same login, email, display name and normal role; a conflict
fails closed. Output contains no email or credential.

Future target validation/readiness uses only explicit IDs:

```bash
ddev exec wp --path=web biblio identity validate \
  --target-user-id=<id> \
  --target-library-id=<id> \
  --require-empty
```

Omitting either parameter fails before Core invocation. The server rechecks:

- active WordPress user;
- exact user→Library personal designation;
- existing supported active Privébibliotheek;
- active `Eigenaar` membership with `Directe toegang`.

There is no fallback to current actor, first user, admin or display name.

## Cleanliness

The read-only readiness projection counts other Library members, target
Library Items, catalog contexts, custom classification terms, Locations,
Collections/memberships, archive periods, publications, activity/evidence and
pending metadata lookup state, plus all implemented
user-owned ExternalLoan, ReadingRound, Note, Rating, Review and Hierna-lezen
records. Standard classification seeds do not make a fresh Library non-empty.

Zero across all categories is `empty`. Any non-zero count is
`non_empty_requires_operator_review`; `--require-empty` fails. The system does
not guess whether existing records are test or real data and performs no
cleanup.

## Runtime audit before personal provisioning

The local runtime was schema `1017` and contained only two separate
administrator accounts. The already designated admin Library contained Items
and personal state, so it was explicitly classified non-empty and is not the
approved Renée migration target. No new personal account or Library was
created because the required login/e-mail input was not supplied.

## Human setup and acceptance

After Renée runs the bootstrap with her chosen local login/e-mail values:

1. record the returned `target_user_id` and `target_library_id` locally;
2. run `validate ... --require-empty`;
3. log out of the DDEV admin account;
4. use the WordPress reset message to set a private password and log in;
5. open Mijn Bibliotheek and confirm the empty personal Library;
6. confirm Owner/Add Book/normal catalog access;
7. confirm no admin-only controls or platform role were added.

This human login check remains separate from automated acceptance.
