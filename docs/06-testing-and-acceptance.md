# 06 — Testing and acceptance

This file converts canonical product rules into a baseline for domain, integration and end-to-end acceptance.

It is not a complete test-case catalogue yet.

## 1. Tenant isolation

Acceptance:
- Library A data is never returned merely because user belongs to Library B.
- Every Library-scoped query/mutation has explicit Library Context.
- Former membership does not allow Library collection/archive search.
- Historical private records may retain historical Library labels without reopening protected Library data.

No-go:
- any cross-Library data leak;
- any Library-scoped write accepted without authorization.

## 2. User ownership/privacy

Acceptance:
- ReadingRounds, Notes, private Ratings/Reviews, Goals, Verlanglijst, Hierna lezen and personal insights are readable/writable only by the owning user unless an explicitly designed publication projection applies.
- Library Eigenaar/Beheerder cannot inspect another user's private ReadingRounds.
- Support access never grants private user-owned data access.

No-go:
- role-based Library admin access bypasses ownership.

## 3. Membership and use access

Test matrix must cover:
- Eigenaar · Directe toegang;
- Beheerder · Directe toegang/Lenen/Alleen bekijken;
- Lid · Directe toegang/Lenen/Alleen bekijken.

Acceptance:
- management role and physical use access operate independently;
- non-owner initial membership is Lid · Alleen bekijken;
- Beheerder has baseline shared catalog/Item management but no automatic rights in additional management domains;
- Beheerder cannot self-escalate;
- Beheerder with `Leden/toegang beheren` may change use access and deactivate/reactivate ordinary Lid memberships only;
- that permission cannot create platform accounts, create initial membership links, promote to Beheerder or manage Beheerders;
- Beheerder permission loss occurs on demotion;
- re-promotion does not silently restore old permissions;
- Eigenaar transfer sets new Eigenaar direct access and explicitly resolves former Eigenaar access.

## 4. Platform account vs membership

Acceptance:
- a newly created platform account may initially have no Library;
- first relevant reading/borrowing action auto-creates the user's designated personal Privébibliotheek once if absent;
- auto-created personal membership is Eigenaar · Directe toegang;
- a user has at most one designated personal Privébibliotheek in v2.001;
- personal ReadingRounds/external loans remain user-owned after that Library is created;
- an external borrowed physical source is not converted into a Library Item;
- one platform account can have multiple Library memberships;
- Platformbeheer reuses existing account;
- platform deactivation blocks login but does not rewrite memberships;
- reactivation restores reachability only to memberships still active;
- normal platform deactivation is blocked while user owns a Privébibliotheek.

## 5. Reading source invariants

Acceptance:
- new active ReadingRound cannot exist without one valid concrete physical source;
- at most one active round per user + source;
- same user may have two active rounds for same Work using different sources;
- Work status becomes Aan het lezen when one or more rounds active;
- a second unused source of same Work remains eligible for Leesvoorraad;
- source lifecycle/access changes do not silently end private rounds.

Historical acceptance:
- closed round may retain unknown source;
- partial date precision is preserved.

## 6. Leesvoorraad

Acceptance:
- direct-access available Item appears for eligible user if no active round on exact Item;
- same Item may appear for multiple direct-access users;
- internal loan appears for borrower;
- external active loan appears;
- previously read Work does not remove source;
- same source with internal-loan context does not duplicate;
- Alleen bekijken Library Item does not appear;
- Item being read on exact source does not appear.

## 7. Hierna lezen

Acceptance:
- Work and specific-source entries supported;
- identical Work entry cannot duplicate;
- identical source entry cannot duplicate;
- Work + multiple different source entries may coexist;
- starting reading never auto-removes;
- source becoming unavailable never auto-removes;
- manual order persists.

## 8. Internal lending

Acceptance:
- Lenen member requires active loan before Item is personal reading source;
- Directe toegang member may use without loan;
- Directe toegang member may still receive explicit loan;
- Alleen bekijken member cannot receive internal loan;
- member cannot self-request/create a loan in v2.001;
- active loan remains after access downgrade or membership termination;
- former member may still use that active loan source;
- return removes current physical access from the loan without deleting history.

## 9. Archive

Acceptance:
- ordinary archive blocked by active internal loan;
- special not-returned/given-away route settles loan then archives;
- archived Item excluded from current Leesvoorraad;
- archive never deletes or auto-closes private reading data;
- restore reuses same Item ID;
- Collection memberships not silently restored;
- Collection goal snapshot not silently modified.

## 10. Collections

Acceptance:
- only active Items from same Library;
- multi-Collection membership allowed;
- draft edit can cancel without persistence;
- save commits atomically;
- removal selection needs no redundant confirmation before save;
- archived Collection read-only;
- completion-goal snapshot deduplicates Works.

## 11. Central bibliographic identity

Acceptance:
- the personal Privébibliotheek Eigenaar can create the minimum missing central Work/Edition/Auteur/Serie identity needed by a valid personal read/borrow flow;
- Biblio searches existing central identities before creating a new one;
- this creation never makes an external borrowed source a Library Item;
- same Work can be referenced by Items in multiple Libraries;
- same user's rereads across different Libraries still resolve to one Work;
- Work can exist without Edition/Item for legitimate central personal use case;
- local Boeksoort/Genre/Onderwerp do not mutate central Work/Edition.

Governance:
- one-Library central record can be directly ordinarily corrected by authorized admin;
- once shared across multiple Libraries, ordinary Library admin cannot directly mutate central record;
- correction proposal can be submitted;
- structural merge/split remains platform-managed.

## 12. Search

Mijn Biblio:
- respects access/ownership;
- can group by Work but keeps every concrete source visible;
- former Library collection/archive disappears after membership loss;
- private historical context remains searchable.

Library:
- strict current Library;
- active Items default;
- temporary archive search resets on refresh/navigation;
- inventory number works;
- no Item deduplication.

No-result:
- no hidden external metadata search;
- add Item requires valid target Library and authorization.

## 13. Home

Acceptance:
- fixed elements cannot be disabled;
- module visibility/order persists account-wide;
- Openstaande acties with zero content occupies no space;
- Nu aan het lezen lists rounds, not unique Works;
- Hierna lezen uses first three manual entries;
- Leesvoorraad is source-based;
- no duplicate Home configuration under Mijn voorkeuren.

## 14. Ratings/reviews/notes

Acceptance:
- private contributions need no Library;
- publication requires one explicit active Library context with active Item of Work;
- publication never silently duplicates across Libraries;
- Library moderator cannot edit another user's text;
- Notes always private.

## 15. Reading goals/statistics

Acceptance:
- successful ReadingRound only counts as completion;
- stopped round does not;
- reread classification based on chronological successful completion;
- two overlapping successful rounds can yield 2 rounds / 1 unique Work / 1 reread;
- Library-scoped personal goal/stat requires actual ReadingRound source context;
- Collection/Series snapshots do not silently mutate.

## 16. Library audit

Acceptance:
- Eigenaar sees full Library audit;
- Beheerder sees only domains covered by current rights;
- Lid sees none;
- private user data never leaks through audit;
- contextual Item/history view uses same ActivityEvent records;
- actor is visible/stable;
- users cannot edit/delete ActivityEvents;
- navigation/search/filter actions do not create audit entries.

## 17. Settings/platform admin

Acceptance:
- only implemented v2.001 settings visible;
- Home settings only Home aanpassen;
- only Standaardweergave is Library default;
- Archief tonen is personal per Library;
- Platform Gebruikersbeheer does not expose private user content;
- Platform Bibliotheken module does not expose Library content absent Supporttoegang;
- only Super admin manages Admins/platform rights in v2.001;
- recovery actions are traceable.

## 18. Integrity and concurrency

Acceptance:
- compound domain mutations are atomic;
- invalid transition blocked with reason;
- concurrent shared-data edits do not silently overwrite;
- hidden/stale form fields do not mutate data;
- failure leaves previous valid state intact.

## 19. E2E critical flows

Minimum E2E candidates:
- platform account exists without Library → first relevant reading/borrowing action auto-creates designated personal Privébibliotheek;
- Platformbeheer links existing account to another Library;
- Eigenaar changes member access/role;
- add physical book/Edition/Item;
- central metadata correction direct vs proposal when shared;
- Library search and temporary archive search;
- Start/finish/stop ReadingRound;
- two simultaneous rounds same Work on different sources;
- Collection draft save/cancel;
- archive/restore;
- internal loan/return;
- external loan;
- Home customization;
- permission/privacy boundaries.

## 20. Technical test layers

### Unit/domain
Biblio Core rules without Elementor.

### Integration
WordPress + database + Biblio Core:
- persistence;
- queries;
- migrations;
- APIs/adapters;
- authorization;
- integrity.

### End-to-end
Playwright for critical user flows and permission boundaries.

Responsive E2E must verify same functionality, not pixel identity.
