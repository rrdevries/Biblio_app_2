# MIG-01 — V1 mapping and reconciliation design

Status: **MIGRATION DESIGN BLOCKED BY REMAINING DOMAIN TARGET GAPS**

Scope: mapping/reconciliation design. MIG-FND-01 has since implemented only the
source-neutral ledger foundation; no V1 parser, import or product-domain write.

V1 blijft source of truth tot de finale cutover.

> **Actual-source rule (2026-09-09):** the export profiled below is historical
> design and regression evidence. It is not automatically the current or final
> migration source. Every source-dependent dry-run, trial, reconciliation,
> migration or release decision must use a fresh `/data/` directory/export that
> Renée explicitly supplies or designates. Every run pins its own snapshot and
> hash. DATA-01 remains regression coverage only.

## 1. Besluit en harde grens

De toen voor de audit aangewezen V1-bron is eenduidig vastgesteld en volledig
read-only geprofileerd. De mapping-, identity-, preservation-, quarantine-,
reconciliation-, dry-run-, retry- en MIG-02-contracten zijn hieronder
uitgewerkt. MIG-02-writes mogen nog niet worden gebouwd: meerdere door echte
V1-data bewezen V2.001-doelen ontbreken of kunnen de bronwaarheid niet
representeren.

De harde regel is:

> Unknown is not disposable.

Ieder source record krijgt in MIG-02 exact één eindclassificatie:
`MAPPED`, `TRANSFORMED`, `PRESERVED_DEFERRED`, `QUARANTINED`, `FAILED` of
`INTENTIONALLY_DROPPED_WITH_REASON`. De laatste klasse blijft leeg totdat Renée per
concrete categorie, reden en bronpopulatie expliciet akkoord geeft.

Een source entity is een top-level record of een embedded subrecord/slot met
eigen semantiek en stable identity, bijvoorbeeld copy, note, rating-slot of
classification-assignment. Een book-aggregate krijgt één disposition en kan
meerdere target edges opleveren; zelfstandig gereconcileerde embedded slots
vallen niet nogmaals in de book-total. `MAPPED` betekent dezelfde semantiek en
cardinaliteit naar een actief target; `TRANSFORMED` betekent een aantoonbare
shape-, schaal-, split-, dedupe- of lifecycletransformatie naar een actief
target. Preservation en quarantine zijn nooit impliciete target-skips.

## 2. Autoriteit, methode en reproduceerbaarheid

Deze historische audit gebruikte de toen actuele Git-state, canonieke
projectdocumenten, V2-code en schema-1017-contracten, de hieronder beschreven
V1-export en DATA-01. Die bronkwalificatie is niet overdraagbaar naar een
toekomstige run; de actual-source rule hierboven is leidend. Historische chats
en oude overdrachten zijn niet gebruikt. Er zijn geen providers aangeroepen.

De V1-JSON en assets zijn vanuit het archief naar een tijdelijke read-only
analysemap geëxtraheerd. Alle tellingen gebruiken de volledige array of map in
het genoemde source member; `non-empty` betekent na trim niet leeg voor tekst
en niet leeg voor arrays/maps; `distinct` is exacte UTF-8-waarde tenzij een
expliciet canonical-ISBN-regel wordt genoemd. Orphans worden geteld als een
child reference waarvan de exacte parent-ID niet voorkomt. Duplicaten worden
alleen op expliciete ID, exacte bronwaarde of canonieke ISBN-identiteit
vastgesteld; nooit fuzzy op titel of naam.

Reproduceerbare baselinechecks:

```sh
shasum -a 256 .local/fixture-source/data.zip
unzip -p .local/fixture-source/data.zip Biblio/package.json | jq '.version'
unzip -p .local/fixture-source/data.zip Biblio/data/books.json | jq '.schemaVersion'
bash scripts/test-data-01.sh
```

De detailtellingen zijn definities voor de latere MIG-02-profiler. MIG-02 moet
ze opnieuw uit dezelfde bytes berekenen en met zijn machine-readable dry-run
publiceren; de getallen hieronder zijn geen handmatige importparameters.

## 3. Historical audit source baseline

| Eigenschap | Vastgestelde waarde |
|---|---|
| Toen aangewezen auditbestand | `.local/fixture-source/data.zip` |
| V1-pakket/baseline | Biblio `507.0.13` |
| Hoofddata | `Biblio/data/books.json`, schemaVersion `29` |
| Auteurdata | `Biblio/data/authors.json`, schemaVersion `2` |
| SHA-256 | `b2ce31c76401ad929fe539259cb0252523f1749218df3985b4991e9f77eb298f` |
| Waarom deze bron toen bruikbaar was | De MIG-01-audit valideerde precies dit pad, deze hash en deze members. Dit maakt de tellingen reproduceerbaar als historisch bewijs, niet actueel. |
| DATA-01 | 47 exact geselecteerde `books[].bookNumber`-cases uit hetzelfde archief; regressiebewijs, geen volledige migratiebron of V2-transformatie. |

De volledige export bevat 1.138 boeken tegenover 47 DATA-01-boeken. DATA-01
dekt doelbewust bijzondere cases, maar bewijst geen volledige populatietelling,
geen complete conflictverdeling en geen cutover-reconciliation.

## 4. Volledige source inventory en profiel

### 4.1 Hoofdpopulaties

| Source category | Record count | Null/duplicate/malformed/orphan/lifecycle-profiel |
|---|---:|---|
| `books.json.books` | 1.138 | 1.138 unieke `id`; 1.138 unieke niet-lege `bookNumber`; 0 lege titels; 2 boeken met ongeldige ISBN-claims |
| `books.json.copies` | 1.105 | 1.105 unieke `id` en `copyNumber`; 0 orphan `bookId`; 1.082 actief, 23 gearchiveerd; 6 boeken hebben twee copies |
| Book records zonder copy | 39 | alle 39 horen bij Wishlist; geen stil te negeren catalogus-orphans |
| `books.json.wishlistItems` | 41 | 41 unieke ID's, actief, edition-type; 0 orphans/duplicates; 2 verwijzen naar een boek met uitsluitend een archived copy |
| `authors.json.authors` | 257 | 257 unieke ID's, 0 lege namen, 14 niet door `authorIds` gerefereerd |
| Embedded auteurrelaties | 1.145 | 750 exacte namen; 469 ID-links naar 243 IDs; 530 boeken hebben namen maar geen IDs; 7 duidelijk verdachte/untyped waarden |
| Seriesrelaties | 164 flags | 161 namen, 58 exacte namen, 108 posities; 3 flags missen naam; 1 positie zonder naam; posities `2019` en `2020` zijn semantisch verdacht |
| Variantrelaties | 5 | alle `variantOfBookId`-parents bestaan |
| Omnibus/contained works | 11 flags / 22 children | 10 boeken hebben 22 child payloads; 1 flag heeft geen children; 5 children hebben een ISBN; 0 daarvan matcht een hoofdboekclaim |
| Private Notes | 17 | 17 unieke note-ID's op 17 boeken; tekst niet leeg; timestamps aanwezig/geldig |
| Ratings | 15 | schaal 1–5: `1×1`, `3×1`, `4×7`, `5×6`; bron heeft geen ratingtimestamp of expliciete round-link |
| Written reviews | 1 | niet-lege tekst en exacte UTC-datum; geen review-ID en geen expliciete round-link |
| Reflections | 5 | vrij tekstveld; semantiek tussen private reflection/note/review niet beslisbaar |
| Quotes | 0 | lege bronpopulatie |
| ReadingRounds | 52 | 52 unieke round-ID's op 52 boeken; 48 completed, 2 stopped, 2 active; geen reread in deze populatie |
| Read registrations | 395 | 392 `unknown_date`, 3 `partial_finish` met maandprecisie |
| Read markers | 1.138 | 447 yes, 331 no, 360 unknown |
| `readHistory` | 1.653 | 1.138 added + 515 status-events; compat/audit, niet de primaire rondebron |
| CirculationRounds op copies | 9 | 5 borrowed, 4 lent_out; 8 open, 1 closed; 0 orphan/malformed; alle 8 open hebben Item, counterparty en startdatum |
| Archived copies | 23 | 20 `duplicate_correction`, 2 `incorrectly_registered`, 1 `wishlist_correction`; alle met archive timestamp; geen open loan |

### 4.2 Bibliografische velden

| V1-veld/category | Filled | Bijzonderheden |
|---|---:|---|
| ISBN-loos | 150 boeken | geen ISBN-claim; afwezigheid bewijst niet automatisch `explicitly_no_isbn` |
| `isbn` | 988 | onderdeel van 2.215 totale veldclaims |
| `isbn10` | 324 | valide claims canonicaliseren via de huidige V2 ISBN-regel |
| `isbn13` | 903 | vier malforme veldclaims op twee boeken, dubbel aanwezig in `isbn` en `isbn13` |
| Canonieke ISBN-13 | 969 distinct | 17 duplicate canonical-ISBN-groepen, samen 34 boekrecords; geen conflicterende niet-lege bibliografie, wel twee additive populated-vs-empty paren |
| Title | 1.138 | exact bewaren als concrete Edition title |
| Subtitle | 0 | lege bronpopulatie |
| Publisher | 723 | geen actief canoniek Edition-veld; behoud als bron-evidence |
| Publication year/date | 939 | string/jaarwaarheid behouden; geen fictieve dag |
| Language | 760 | bronlabel behouden; geen stille normalisatie |
| Binding | 26 | bronlabel/evidence; geen actuele algemene bindingtarget |
| Edition format / carrier | 1.138 / 1.138 | `editionFormat` gevuld; carrier is fysieke boekbron; mapping alleen via expliciete allowlist |
| Page count | 658 | integerbron, als evidence behouden |
| Description | 606 | geen huidig actief canoniek detailtarget |
| Special-edition types/features | 10 / 9 | collector/bibliografische dimensie niet veilig automatisch te plaatsen |
| Provenance/enrichment history | 1 / 1 | unieke bron-evidence; niet als providerbevestiging behandelen |
| Google link | 956 | externe referentie, geen bibliografische identiteit |

De twee malforme ISBN-records zijn `BK-000712` (`9780008303616`) en
`BK-001047` (`9789026112815`). Zij en de afhankelijke Edition/Item-relaties
blijven `QUARANTINED`; de migrator corrigeert geen checksum automatisch.

### 4.3 Library-classificatie

| Category | Assignments | Distinct | Huidige V2 exact match | Niet exact gemapt |
|---|---:|---:|---:|---:|
| Book Type | 1.138 | 7 | 1.064 | 74 (`Jeugdboek` 50, `Kinderboek` 24) |
| Genre | 1.037 op 444 boeken | 28 | 280 | 757 |
| Legacy category | 1.262 op 1.030 boeken | 9 | 0 als besloten Subject-mapping | 1.262 |

Alle labels bestonden in de geprofileerde auditsnapshot managed lists; daarin waren geen orphan
labels of duplicaten binnen één boek. Dat maakt ze niet automatisch een V2
term. ADR-006 verbiedt een technische `Onbekend`/`Overig`-fallback en
automatische semantische gelijkstelling. Exacte bestaande Book Types en Genres
kunnen worden hergebruikt. Alle overige waarden vereisen een vooraf
goedgekeurde Library-lokale mapping/termset of een record-level quarantine.
Zonder oplossing voor de 74 Book Types kan voor die Works geen verplichte
LibraryCatalogContext en dus geen complete afhankelijke Item-write worden
aangemaakt.

### 4.4 Item/copy-profiel

| V1 Item-feit | Count | Actuele doelstatus |
|---|---:|---|
| Inventory/copy number | 1.105 | actief Item-target |
| Location | 0 | actief target, lege bron |
| Condition | 0 | functioneel V2.001-feit, nog geen huidige persistence |
| Acquisition | 77 copy-records | V2.001-feit, huidig target ontbreekt |
| Copy notes | 3 | huidig Item-target ontbreekt |
| Exemplar photo | 1 | huidig cover/assettarget ontbreekt |
| Disposal | 1 | bronfeit behouden; huidige archive-reasonset is niet passend |
| Circulation | 9 | ExternalLoan bestaat; InternalLoan ontbreekt en bronsemantiek is deels ambigu |
| Archived | 23 | Item lifecycle bestaat; alle drie V1-reasonwaarden vallen buiten de huidige V2-enum |

`legacyBookNumber`, `sourceBookNumber`, legacy book-level acquisition/lending
en `collectionStatus` zijn compatibiliteitsvelden. De canonical V1-bronnen
zijn copy `id`/`copyNumber`, `ownershipStatus`, `acquisition`, `disposal` en
`circulationRounds[]`. Compatibiliteitsvelden worden niet als extra events
gemigreerd, maar blijven in preservation/reconciliation traceerbaar.

### 4.5 Overige bestanden en embedded state

| Source member/category | Count | Profiel |
|---|---:|---|
| `book_types.json` | 7 | managed V1 term records |
| `categories.json` | 14 | managed/alias records |
| `genres.json` | 44 | managed/alias records |
| `carriers.json` | 3 | managed carrier records |
| `next_to_read.json.items` | 0 | expliciet geen V1 Hierna-lezen-state |
| `reading_goals.json.goals` | 2 | 1 inactive books-per-year, 1 active series-collection goal |
| `home_prefs.json` | 1 singleton | 5 widget-orderwaarden, 0 hidden widgets |
| `recommendations_prefs.json` | 313 shown / 0 ignored | persoonlijke recommendation-state |
| `recommendations_ignored.json` | 0 | lege compat/sourcepopulatie |
| `releases.json` | 428 authors / 294 mergedDiff | release cache/state plus runmetadata |
| `releases_dismissed.json` | 21 | expliciete dismissal-state |
| release excluded/merged/tracked | 0 / 0 / 0 | lege populaties |
| `taxonomy_review_queue.json` | 442 | 439 pending, 3 ignored; 427 Open Library, 15 Google Books |
| `taxonomy_aliases.json` | 7 | source rules, niet automatisch V2-governance |
| `book_enrich_cache.json` | 8 | cache/evidence candidates |
| `cache.json` | 403 | provider/cache entries |
| `migration_reports/` | 6 files | 3 Book Type reports, dataset cleanup, 14 manual reading cleanups, taxonomy report |
| Cover-cache metadata | 902 | content-addressed JSON references |
| Cover-cache binaries | 901 | 716 jpg, 181 img, 1 gif, 1 png, 2 webp; 181 bestanden kleiner dan 100 bytes |

Er is één cover-metadatafile zonder bijbehorend asset en geen asset zonder
metadata. `books.coverUrl` is gevuld op 882 boeken: 876 externe HTTP(S)-URLs
en 6 lokale references, samen 870 distinct waarden. Rechten/licentie zijn niet
structureel geregistreerd. De bronbestandsnaam, metadata, hash, MIME/extension,
relatie en ontbrekende-assetstatus moeten daarom als preservation-evidence
blijven bestaan; een nieuwe cover-engine valt buiten MIG-01.

Er zijn geen afzonderlijke V1 Collections, Collection-memberships,
Collection-orderrecords, locatiecatalogus, gewenste-aanwinstenrecords of
accountrecords gevonden. `collectionStatus` betekent legacy bezit/circulatie
en is geen V2 Collection. Stats/Jaaroverzicht/Tijdlijn hebben geen aparte
canonieke opslag; de betekenisvolle leesfeiten komen uit ReadingRounds en
read-registration, terwijl `readHistory` audit/compat-evidence blijft.

## 5. Actuele V2-target inventory

MIG-FND-01 verhoogt runtime, migratieregister en schema health van `1017` naar
`1018`. Alle bestaande domeintargets hieronder behouden hun eerdere status.

| Target | Ownership/scope | Kernconstraints | V2.001-status |
|---|---|---|---|
| WordPress User | platform/user | authenticated bestaande user; migrator kiest geen accountnaam | actief, targetbinding open |
| Library + membership + personal designation | Library/platform | expliciete Library Context; owner/member; designation niet heuristisch | actief, targetbinding open |
| Work | platform | stable ID, title, `provisional` of `librarian_confirmed` | actief |
| Edition | platform | exact één Work; concrete Edition title; ISBN is tri-state: valide claim, werkelijk expliciet no-ISBN, of nog onbekend/niet ingevoerd | actief |
| ISBN claim/provenance | platform | unieke canonical ISBN-13; fail-closed conflict | actief |
| Author + Work contributor | platform | ordered `author`/`co_author`; geen Library ownership | actief |
| Series + Work membership | platform | nullable decimal position; onbekend blijft null | actief |
| Work containment | platform | ordered, acyclic Work-relaties | actief |
| Item | Library | exact één Library en Edition; status/version; optioneel inventory/location | actief |
| LibraryCatalogContext | Library × Work | exact één Book Type, 0..n Genre/Subject | actief |
| Collection + membership | Library | exact Item-membership; manual order; active/archive history | actief, bronpopulatie 0 |
| ExternalLoan | user | Work-scoped user-owned external source | actief foundation; full feature deferred |
| ReadingRound | user | Work; active heeft precies Item of ExternalLoan; historical manual vereist einddatum | actief |
| Private Note | user | Work, optioneel owner-matching round, timestamps/version | actief |
| Rating / WrittenReview | user | Work, optioneel owner-/Work-matching round; eigen lifecycle | actief |
| Assessment publication/readmodel | user source + Library publication | huidige Book Detail leest alleen Library-public contributions | actief public read; private migrated read ontbreekt |
| Hierna lezen | user | Work-based, optionele preferred source, manual order, duplicates toegestaan | actief; bronpopulatie 0 |
| Item archive period | Library | Item state/history; closed reason vocabulary | actief maar V1 reasons niet representabel |
| Metadata Hub evidence | platform/Library evidence | provider/user-observation source contexts zijn gesloten allowlists | actief, niet geschikt als generieke V1-preservationstore |
| Wishlist | user | vereist door V2.001-scope | **geen huidig entity/table/service-target** |
| InternalLoan | Library/user relation | nodig voor `lent_out` en mogelijk ReadingRound source | **geen huidig target** |
| Goals | user | feature V2.002+ | geen actief target; preserve-only toegestaan |
| Cover/assets | bibliografisch/Item | feature V2.002+ | geen Biblio-owned target; preserve-only toegestaan |
| Migration run/source map/quarantine/preservation | technisch bewijs | stabiele source→target ledger en payload evidence | **actief source-neutraal fundament in schema 1018; geen importer/executor** |
| Personal Reading Truth | user | source-neutrale user×Work waarheid zonder concrete leesdatum of ronde | **actief target in schema 1019; exact read-known/date-unknown, explicit-not-read en unknown** |

De huidige metadata-user-observationstabel accepteert uitsluitend
`physical_copy_add_book` als source context. V1-importdata daarin schrijven zou
het bestaande contract vervalsen en is niet toegestaan.

## 6. Mappingmatrix

Een rij met gemengde uitkomst is bewust opgesplitst in disjuncte
bronpopulaties. Daardoor krijgt ieder individueel record later exact één
eindstatus.

| Source category/population | V2 target | Status | Belangrijkste transformatie |
|---|---|---|---|
| 1.136 boeken zonder ISBN-fout | Work + Edition | TRANSFORMED | ISBN/variant identity; Edition title exact; Work title provisional |
| 2 boeken met malforme ISBN | quarantine | QUARANTINED | gehele afhankelijke identity-chain blijft ongeschreven tot review |
| 1.103 copies zonder ISBN-quarantined parent | Item | TRANSFORMED | structureel één Item per copy; write blijft afhankelijk van verplichte Book Type mapping en Item/archive-targetgaps |
| 2 copies met quarantined parent | quarantine | QUARANTINED | bronpayload en parentrelatie behouden |
| 257 author records | Author | MAPPED | bestaande source-ID via ledger aan V2-ID binden |
| 469 bestaande `authorIds` contributor-relaties | Work contributor | TRANSFORMED | source book + author-ID + expliciete arrayorder naar ordered author/co-author link |
| Clear embedded auteurs zonder author ID | Author + Work contributor | TRANSFORMED | exact V1-normalized unique name; ordered author/co-author |
| Embedded auteur zonder unieke registry-match | quarantine/evidence | QUARANTINED | geen persoon, split of rol raden; een gereviewde mapping kan dit later oplossen |
| Clear Series names/positions | Series + Work relation | TRANSFORMED | exact name identity; decimal of null position |
| Missing/verdachte Seriesdimensies | quarantine plus safe relation | QUARANTINED | alleen zekere naamrelatie mag apart doorgaan |
| 22 contained-work payloads | child Work, eventueel Edition, containment | TRANSFORMED | explicit parent/child; 5 ISBN-child Editions; geen title merge |
| Omnibusflag zonder children | quarantine | QUARANTINED | `structural_ambiguity` |
| Exact bestaande Book Type/Genre values | LibraryCatalogContext | MAPPED | bestaande targetterm-ID hergebruiken |
| Niet gemapte Book Type/Genre/category values | classification quarantine | QUARANTINED | geen term of Subject automatisch creëren |
| Managed term records: 7 Book Types, 14 categories, 44 Genres | migration term evidence | PRESERVED_DEFERRED | naam/status/alias/description volledig bewaren; alleen gereviewde assignments worden actief gekoppeld |
| 3 managed carrier records | migration carrier evidence | PRESERVED_DEFERRED | geen algemene V2 carrier-taxonomie of automatische binding creëren |
| Publisher/date/language/binding/pages/description/special fields | preservation link aan Edition/Item | PRESERVED_DEFERRED | exact source value + precision bewaren; huidige canonical target ontbreekt deels |
| Favorite/manual/reviewed/lock/title-group en overige boekflags | migration evidence | PRESERVED_DEFERRED | betekenis per exact source field bewaren; geen afgeleide V2-feature activeren |
| Uitgebreide Author metadata (aliases/bio/name parts/photo/links/provenance) | Author-linked evidence | PRESERVED_DEFERRED | alleen huidige Author identity/displayrelatie is actief gemapt |
| 52 ReadingRounds | ReadingRound | TRANSFORMED | 48 completed, 2 stopped, 2 active; known precision behouden |
| 3 partial-finish registrations | historical ReadingRound | TRANSFORMED | completed, maandprecisie, source-free |
| Historical unknown-date registrations from the audited snapshot | PersonalReadingTruth `read_known_date_unknown` | TRANSFORMED | owner×Work target is representable without a ReadingRound or invented date; current counts require a newly designated source |
| Historical explicit `no` markers from the audited snapshot | PersonalReadingTruth `explicit_not_read` | TRANSFORMED | explicit negative truth remains distinct; completed-round contradiction must quarantine; no round is created |
| Historical `unknown` markers from the audited snapshot | PersonalReadingTruth `unknown` | TRANSFORMED | explicit unknown remains distinct from explicit not-read and absence; current counts require a newly designated source |
| `readHistory`/snapshot compat | preservation/audit link | PRESERVED_DEFERRED | niet als tweede ReadingRound-eventbron gebruiken |
| 17 Notes | Private Note | TRANSFORMED | user-owned Work note; existing note-ID in source ledger |
| 15 Ratings | Rating | TRANSFORMED | score ×2 naar V2 half-step integer; geen round-link/publication; timestamp gap oplossen vóór writes |
| 1 Review | WrittenReview | TRANSFORMED | exact text/date; geen round-link/publication |
| 5 Reflections | quarantine | QUARANTINED | note versus review versus round reflection is onbeslist |
| 0 Collections/memberships | Collection | MAPPED | lege population; geen membership afleiden |
| 41 Wishlist entries | vereist persoonlijk Wishlist-target | PRESERVED_DEFERRED | basisdata intact houden tot ontbrekend V2.001-target bestaat |
| 41 `titleGroupKey` hints | toekomstige Wishlist grouping | PRESERVED_DEFERRED | geen grouping/completeness activeren |
| 0 Hierna-lezen entries | Hierna lezen | MAPPED | expliciete lege bron; geen afleiding uit Wishlist |
| 23 archived copies | Item + archive period | TRANSFORMED | Item mag niet actief terugkomen; reason gap eerst oplossen |
| 77 Item acquisitions | toekomstig actief Item target | PRESERVED_DEFERRED | exacte type/date precision/source behouden; target gap blokkeert trial import |
| 3 copy notes | toekomstig Item-local note target | PRESERVED_DEFERRED | niet naar user-owned Private Notes verplaatsen |
| 1 exemplar photo | deferred Item asset target | PRESERVED_DEFERRED | bytes/reference/provenance behouden; geen cover-engine bouwen |
| 1 disposal payload | archive/disposal evidence | PRESERVED_DEFERRED | geen onware V2 archive reason kiezen |
| 1 closed borrowed circulation round op owned copy | circulation evidence/quarantine | QUARANTINED | ExternalLoan versus Library Item/circulation is niet uit de bron beslisbaar |
| 8 open circulation rounds | minimum lifecycle of preservation | PRESERVED_DEFERRED | productbesluit vereist; geen automatische beëindiging |
| 2 Reading Goals | deferred preservation | PRESERVED_DEFERRED | volledige config/status/timestamps behouden; geen engine |
| cover URLs/cache/assets | deferred preservation | PRESERVED_DEFERRED | referentie, bytes/hash, relatie en missing state behouden |
| Home preferences | deferred preservation | PRESERVED_DEFERRED | singleton exact bewaren |
| Recommendation/release state | deferred preservation | PRESERVED_DEFERRED | expliciete user state bewaren; pure rebuildable cache als evidence classificeren |
| Taxonomy queue/aliases/reports | deferred governance evidence | PRESERVED_DEFERRED | pending/ignored/source/status en reports intact bewaren |
| Import/migration reports | migration provenance | PRESERVED_DEFERRED | source audit-evidence, geen domeinentity |

Er is in MIG-01 geen categorie `INTENTIONALLY_DROPPED_WITH_REASON` gebruikt.

## 7. Work / Edition / Item identity

### 7.1 Deterministische regels

1. Parse iedere niet-lege ISBN-claim met de huidige V2
   `CanonicalIsbnIdentity`; ISBN-10 converteert naar de canonieke ISBN-13-key.
2. Meer dan één verschillende geldige canonieke claim binnen één V1-book is
   een quarantine. Een malforme claim wordt niet genegeerd omdat een ander
   veld leeg of gelijk is.
3. Dezelfde geldige canonieke ISBN claimt dezelfde Edition. De 17 aangetroffen
   duplicate ISBN-groepen produceren afzonderlijke Items voor iedere copy en
   niet twee Editions.
4. `variantOfBookId` is de enige aangetroffen expliciete Work-link. Gelijke
   variantfamilie betekent hetzelfde Work; verschillende geldige ISBNs blijven
   verschillende Editions.
5. Een boek zonder ISBN krijgt één source-stabiele Edition onder één
   source-stabiele Work, tenzij een expliciete variantlink al een Work bindt.
   Geen fuzzy titel-/auteurmerge. Afwezige ISBN wordt niet automatisch
   `explicitly_no_isbn`.
6. Iedere V1-copy wordt exact één Item. Extra copies delen Edition maar houden
   eigen source ID, inventory number, state en Item-evidence.
7. Edition title is de exacte V1-title. Work title wordt met dezelfde tekst
   `provisional`; nooit `librarian_confirmed` door migratie.
8. Een archived copy wordt een archived Item en nooit een actief Item. De
   archiveperiode en originele reason moeten aantoonbaar blijven.

Vóór target reuse/quarantine zijn er 969 valid canonical-ISBN
Editiongroepen en 150 no-ISBN hoofdboeken. Na uitsluiting van de twee malforme
boeken geeft dat 1.119 hoofd-Editionkandidaten. ISBN- en variantlinks reduceren
de 1.136 geldige hoofdboekrecords tot 1.116 Workcomponenten. De 22 expliciete
contained Works voegen afzonderlijke child Works toe; hun 5 ISBNs voegen child
Editions toe. Dit zijn dry-run expectations, geen vooraf toegewezen IDs.

### 7.2 Omnibus

Een V1 omnibus-book blijft het Work/Edition van de fysieke omnibus. Iedere
expliciete contained entry wordt een child Work; een valide child-ISBN kan een
Edition onder dat child Work maken/reusen. De ordered Work-containmentrelatie
volgt de expliciete arrayvolgorde alleen als relatie-order, niet als source
identity. De child source identity gebruikt parent book-ID plus een hash van
de exact gecanonicaliseerde child payload. Een gewijzigde child payload in een
nieuwere export is een source-version conflict, geen stille nieuwe child.

Geen contained title wordt op titel met een bestaand Work samengevoegd. Lege
author/series/position blijft onbekend. `hasMultipleWorks=true` zonder children
is `structural_ambiguity` en wordt quarantined.

## 8. Authors en Series

Bestaande V1-author IDs zijn leidend. Embedded namen zonder ID mogen alleen
via de in de auditsnapshot gebruikte conservatieve V1-normalisatie aan precies één registry record
worden gekoppeld. Bij nul of meer dan één match volgt
`ambiguous_contributor`-quarantine totdat een expliciet gereviewde
migration-mapping een bestaande of nieuw aan te maken Author aanwijst. V2 kent
geen `provisional Author`-state. Voor een volledig door IDs of gereviewde
mapping bepaalde contributorlist is het eerste element `author` en zijn de
volgende ordered `co_author`.
V1 bevat geen betrouwbare translator/illustrator/editor-rolvelden; MIG-02
verzint die Edition-rollen niet en introduceert geen Expression.

Series identity is exacte displaynaam plus source ledger. Casingvarianten
zoals `Laws of the Blood`/`Laws of the blood` worden niet automatisch gemerged.
Valide decimal position wordt behouden; leeg blijft `NULL`. De concreet
aangetroffen values `2019` en `2020` en iedere positie zonder seriesnaam worden
met een expliciete source-ID-rule quarantined; MIG-02 gebruikt geen algemene
heuristiek “lijkt op een jaar”.
De bron bevat maximaal één seriesrelatie per hoofdboek en geen expliciete
side/companion- of publisher-seriesdimensie. Er worden geen completenessclaims
afgeleid.

## 9. Reading reconciliation en transformatie

De per run aangewezen actuele V1-bron bepaalt zelf de authorityvolgorde:

1. `readingRounds[]` is canoniek voor concrete leesrondes;
2. `readMarker` + `readRegistration` is canoniek voor leeswaarheid zonder
   concrete ronde;
3. `readHistory`, `readStatus`, `startDates`, `finishedDates`, `finishDates`,
   `startedAt` en `finishedAt` zijn compat/audit/snapshot-evidence.

MIG-02 dedupliceert niet op alleen datum/titel. Voor ieder canoniek round-ID
worden compat-events met gelijke eventsoort en dezelfde exacte of partial date
als evidence aan die ronde gereconcileerd; ze maken geen tweede ronde. Een
onverklaard extra compat-event blijft preservation-evidence of wordt bij echte
tegenspraak quarantined.

De 48 completed en 2 stopped rounds behouden exact hun day/month precision.
De 2 active rounds hebben ieder precies één actieve owned copy en kunnen na
targetbinding die Item-bron krijgen. De 3 `partial_finish`-registraties worden
source-free `historical_manual` completed rounds met maandprecisie. Rereads
zouden afzonderlijke round-ID's blijven; de auditsnapshot bevatte geen Work met
meer dan één canonieke ronde.

De 392 `unknown_date`-registraties zijn wel gelezentrouw maar kunnen niet naar
de huidige V2 `historical_manual` ReadingRound, omdat ADR-007 een inhoudelijke
einddatum vereist. `now()`, 1 januari of een andere fictieve datum is verboden.
Ook het onderscheid tussen 331 expliciet `no` en 360 `unknown` verdwijnt in de
huidige afgeleide V2-status. Dit is een cutover-targetgap: vóór trial import is
een minimale truth-preserving representatie en readprojectie nodig.

## 10. Notes, Ratings en Reviews

Notes blijven privé en user-owned. Een V1-note bindt aan de gemapte Work; een
ReadingRound-link wordt alleen gezet als de source die expliciet draagt. De
bron doet dat niet, dus alle 17 Notes blijven Work-scoped. Geen Libraryrol of
publication wordt toegevoegd.

Ratings schalen deterministisch van V1 `1..5` naar V2 `2..10` half-step units.
Geen van de 15 ratings heeft een timestamp of expliciete round-link. Het
bestaan van één ronde op hetzelfde boek is geen bewijs voor associatie. De
review heeft wel een UTC-date, maar ook geen expliciete round-link. V1 kent
geen Library-publicationconcept, dus alle gemigreerde assessments blijven
private en unpublished.

De huidige Book Detail-projectie leest alleen Library-public contributions en
mag deze private historische data dus niet tonen. V2.001 vereist een
owner-scoped readable projection voor de gemigreerde private assessments.
Daarnaast is voor ratings een waarheidstrouw timestampcontract nodig: de
migration-run-tijd mag hoogstens als technische importtijd worden vastgelegd,
niet als inhoudelijke ratingdatum. Deze twee punten blokkeren cutover, maar
activeren geen nieuwe write/publication-UI.

## 11. Collections, Wishlist, Hierna lezen en Archive

### Collections

De V1-export bevat geen echte Collection-aggregate, membership of manual
order. Daarom worden nul Collections en nul memberships gemigreerd. Niets
wordt via Work/Edition, legacy `collectionStatus`, Series of Wishlist afgeleid.
De huidige exacte Item-membershipfoundation is inhoudelijk geschikt wanneer
latere brondata die populatie wel bevat. MIG-01 bewijst geen extra
Collection-writeflow boven de reeds canonieke V2.001-scope.

### Wishlist

Alle 41 records zijn persoonlijke actieve edition-intenties met een stable ID,
book link en title/author snapshots. Basis Wishlist is V2.001-MUST, maar schema
1017 bevat geen Wishlist-target. Totdat dat target bestaat worden de 41
records en hun source→Work/Edition-intent `PRESERVED_DEFERRED`; dit is geen
toestemming om Wishlist na cutover onbruikbaar te laten. Alle 41
`titleGroupKey`-waarden zijn uitsluitend preserved grouping hints voor
V2.002+; ze veroorzaken geen merge of completenessclaim.

### Hierna lezen

De bronpopulatie is expliciet nul. MIG-02 schrijft niets en leidt geen queue af
uit Wishlist, Reading Goals of recommendations.

### Archive

Alle 23 archived copies blijven dezelfde Item-identiteit houden, blijven
terugvindbaar en behouden history, Notes/Reading-relaties en voormalige
Collectionrelaties (nul in de auditsnapshot). Zij mogen niet in de actieve catalogus
terugkomen. Omdat geen van de drie V1-reasons in de huidige V2-enum past, mag
MIG-02 geen `sold`, `donated` of andere onware reason kiezen. Een minimale
legacy-reason/evidence-uitbreiding is vóór archive-writes nodig.

## 12. Circulation — kritiek beslispunt

Copy-level `circulationRounds[]` is canoniek; legacy book-level lending is
alleen compat-output en wordt niet dubbel gemigreerd.

| Classificatie | Count | Beschikbare gegevens |
|---|---:|---|
| Historical/closed | 1 | borrowed; Item/copy, counterparty, exacte start/einddatum, notes |
| Active/open borrowed | 4 | Item/copy, counterparty, exacte startdatum; 1 copy heeft ownership `borrowed`, 3 `owned` |
| Active/open lent_out | 4 | owned Item/copy, counterparty, exacte startdatum |
| Malformed | 0 | — |
| Orphan | 0 | — |
| Ambiguous | 4 borrowed-on-owned cases | ExternalLoan versus Library Item/circulation kan niet uit het label alleen veilig worden gekozen |

De 4 `lent_out`-records hebben geen huidig InternalLoan-target. Voor borrowed
bestaat ExternalLoan, maar vier van vijf rounds staan op een copy met
`ownershipStatus=owned`; alleen het ene `borrowed` ownershiprecord is zonder
nadere keuze een duidelijke ExternalLoan-kandidaat. ReadingRound-koppeling mag
alleen worden gemaakt als de fysieke source-relatie bewijsbaar gelijk blijft.

Preserved-only is technisch mogelijk vóór cutover, maar dan kunnen 8 open
loans niet in V2 worden beëindigd. Voor dagelijks gebruik is daarom een kleine
productkeuze vereist. Technisch aanbevolen minimum als Renée settlement nodig
vindt: bestaande open state owner-/Library-safe lezen en exact die bestaande
round beëindigen/terugmelden; geen create, renew, request, reservation,
algemene lending UI of volledig InternalLoan-product.

## 13. Reading Goals, covers en deferred productstate

De twee Goals blijven volledig `PRESERVED_DEFERRED`, inclusief ID, type,
active flag, config, filters/scope en timestamps. Geen progress wordt tijdens
migratie als nieuw canoniek feit geschreven; latere engines reconstrueren
progress uit gemigreerde leeswaarheid.

Cover references/assets, Home prefs, recommendation shown/ignored state,
release dismissal/state, taxonomy review/aliases, caches en migration reports
worden als source evidence geïnventariseerd. Betekenisvolle expliciete user
state blijft preserved. Data die aantoonbaar puur deterministisch afleidbare
cache is mag door MIG-02 als `TRANSFORMED` naar “rebuild from canonical data”
worden geclassificeerd, maar alleen met een named rule en ledger entry; zonder
zo'n bewijs blijft zij `PRESERVED_DEFERRED`. Er is geen silent cache-drop.

## 14. Timestamps en historische waarheid

De source-audit vond 1.138 geldige `dateAdded` en `addedToSystemAt` UTC-instants,
1.055 geldige book `updatedAt`-instants en 83 lege book `updatedAt`-waarden.
Alle 1.105 copies hebben geldige `createdAt` en `updatedAt`; alle 23 archived
copies hebben een geldige `archivedAt`. Alle 41 Wishlist-records hebben geldige
created/updated timestamps. De 17 Notes en ene Review hebben geldige source
timestamps. In de 178 gecontroleerde partial-dateobjecten voor acquisition,
ReadingRound, read registration en circulation zijn geen ongeldige
value/precision-combinaties gevonden.

Een timestamp met `Z` is een UTC-instant. Een `{value, precision}`-datum blijft
jaar-, maand- of dagprecisie; timezone en ontbrekende kalendercomponenten
worden niet uit importlocatie of runtimezone afgeleid. V1-timestamps worden
niet door `now()` vervangen wanneer bronwaarheid bestaat.

Fallbackbeleid bij ontbrekende bronwaarheid:

- nullable inhoudelijke datum blijft `NULL`/unknown;
- een target-required inhoudelijke datum veroorzaakt quarantine of een
  voorafgaande targetgap, nooit fictieve precisie;
- een migration-run instant mag alleen als afzonderlijke technische importtijd
  worden gebruikt wanneer het targetcontract haar expliciet niet als
  historische content presenteert;
- een required technische targettimestamp krijgt een run-stabiele waarde die
  bij rerun gelijk blijft en expliciet als importtijd traceerbaar is;
- source `updatedAt` mag niet als created/rated/read/archive time worden
  hergebruikt zonder expliciete source semantics.

Daarom blijft de timestampbehandeling van de 15 timestamp-loze Ratings een
target prerequisite en niet een convenience-default.

## 15. Stable source identity en entity mapping

De bronidentiteit heeft twee niveaus:

```text
source_family: biblio-v1
source_snapshot: 507.0.13/sha256:b2ce31c76401ad929fe539259cb0252523f1749218df3985b4991e9f77eb298f
```

De persistente logical identity is uniek op
`source_family + source_type + source_id`. Iedere gelezen exportobservatie is
apart uniek op `source_snapshot + source_type + source_id + payload_hash`.
Hierdoor herkent een nieuwere export dezelfde logical entity, terwijl een
gewijzigde payload expliciet als nieuwe observation/conflict wordt beoordeeld.

| Source type | Stable identifier |
|---|---|
| book | `books/<book.id>`; `bookNumber` als gecontroleerde alternatieve sleutel |
| copy | `copies/<copy.id>`; `copyNumber` als gecontroleerde alternatieve sleutel |
| wishlist | `wishlistItems/<id>` |
| author | `authors/<id>` |
| reading round | `books/<book.id>/readingRounds/<round.id>` |
| read registration/marker/rating | singleton slot onder `books/<book.id>/<field>` |
| note | `books/<book.id>/notes/<note.id>` |
| review zonder ID | voor de één-recordpopulatie in de auditsnapshot `books/<book.id>/review`; payloadhash blijft een aparte observation, toekomstige multi-review parent-set zonder IDs wordt quarantined |
| circulation | `copies/<copy.id>/circulationRounds/<round.id>` |
| contained work zonder ID | parent-ID + SHA-256 van exact canonical child JSON; wijziging tussen snapshots quarantinet de parent-set zolang correlatie ontbreekt |
| Series/term zonder ID | source category + SHA-256 van exacte UTF-8 displaywaarde |
| singleton preferences | bestandsnaam + vaste singleton key |
| id-loze list entry | bestandsnaam + content hash; nooit arraypositie |

MIG-02 houdt per source entity minimaal bij:

```text
source_family
source_snapshot
source_type
source_id
source_payload_hash
classification
target_type
target_id(s)
reason_code
migration_run
```

Eén V1-book kan zo afzonderlijke ledger edges naar Work, Edition en nul of
meer Items hebben. Doel-IDs komen uitsluitend uit de persistente mapping of
een gecontroleerde target-reuse, nooit uit importvolgorde.

## 16. Conflict- en quarantinecontract

| Conflict | Automatisch veilig? | Uitkomst | Release-impact/review |
|---|---|---|---|
| Duplicate valid ISBN, gelijke/additive identiteit | ja | TRANSFORMED: één Edition, meerdere Items | dry-run meldt group |
| Same ISBN, conflicting non-empty bibliography | nee | QUARANTINED | identity review; blocks affected chain |
| Malforme/conflicting ISBN claims | nee | QUARANTINED | 2 records in auditsnapshot; blocks affected chain |
| Same bookNumber/copyNumber, conflicting payload | nee | QUARANTINED | source blocker |
| Missing required Edition title | nee | QUARANTINED | 0 in auditsnapshot |
| Orphan copy/author/membership | nee | QUARANTINED | copy/author 0 in auditsnapshot; membership population 0 |
| Duplicate read event in compat naast round | ja bij exact bewezen overlap | TRANSFORMED to evidence | geen tweede ronde |
| Conflicting read date/outcome | nee | QUARANTINED | human review |
| Duplicate Wishlist stable ID | nee | QUARANTINED | 0 in auditsnapshot |
| Active + archived Item conflict | nee | QUARANTINED | geen onoplosbaar conflict in auditsnapshot |
| Malformed/orphan circulation | nee | QUARANTINED | 0 in auditsnapshot |
| Unknown taxonomy | nee | QUARANTINED assignment | 1.075 books hebben minstens één unresolved classificatiedimensie |
| Unresolved Work identity | nee | QUARANTINED | geen fuzzy merge |
| Omnibusflag zonder children | nee | QUARANTINED | 1 record in auditsnapshot |
| Ambiguous contributor/reflection | nee | QUARANTINED | exact source value behouden |

De kleine reason-taxonomie die door MIG-01-conflicttypen wordt gerechtvaardigd:

- `invalid_isbn_claim`;
- `canonical_isbn_identity_conflict`;
- `source_identity_conflict`;
- `missing_required_target_field`;
- `orphan_reference`;
- `reading_truth_conflict`;
- `unknown_taxonomy_mapping`;
- `unresolved_work_identity`;
- `structural_ambiguity`;
- `ambiguous_contributor`;
- `ambiguous_circulation_semantics`;
- `unsupported_target_representation`.

Een quarantine entry bewaart minimaal source category/stable ID,
payloadreferentie en hash, reason code, leesbare uitleg, detected-at,
eventueel proposed target, migration-run-ID en resolved/unresolved state.
`detected_at` is technische audittijd en geen bronhistorie.

## 17. Preserved-deferred contract

Per preserved source entity moet later reconstrueerbaar blijven:

- exacte source family, snapshot, type/ID en payloadhash;
- lossless originele payload of immutable payloadreferentie;
- reason waarom de feature deferred was;
- eventuele Work/Edition/Item/User/Library targetlinks;
- migration-run en bronversie;
- de eindclassificatie en latere activation/resolution state.

Schema 1018 biedt hiervoor het afzonderlijke, Core-owned MIG-FND-01-contract:
runs, observations, target edges, quarantine en preservation zijn expliciete
records. Bounded canonical JSON of een durable reference is alleen evidence bij
een observation; het is geen generieke JSON-dumptabel en vervangt identiteit,
status of relaties niet. Dit fundament neemt de overige domeintargetgaps niet
weg en bouwt geen MIG-02-writes.

## 18. Reconciliationmodel

### 18.1 Category totals

MIG-02 produceert per disjuncte source population:

```text
SOURCE: total, valid, invalid, active, archived, deferred, quarantined
TARGET: created, reused, transformed, skipped_by_explicit_rule,
        preserved_deferred, quarantined
RELATIONSHIPS: expected, created, reused, failed
```

De verplichte invariant is:

```text
source_total
= mapped
 + transformed
 + preserved_deferred
 + quarantined
 + intentionally_dropped_with_reason
 + failed
```

`skipped_by_explicit_rule` is alleen een target-actioncount en moet naar een
van de zes source-eindklassen verwijzen; het is geen extra disposition.
`FAILED` blijft afzonderlijk van terminale quarantine, vereist een reden en
blokkeert succesvolle run completion. Parent- en
childpopulaties worden afzonderlijk gereconcileerd, zodat één V1-book met
Work+Edition+Item niet driemaal in de book-total telt.

### 18.2 Machine-readable entity ledger

Minimale output per edge:

```json
{
  "source_family": "biblio-v1",
  "source_snapshot": "507.0.13/sha256:...",
  "source_type": "book",
  "source_id": "books/1769798279407",
  "classification": "TRANSFORMED",
  "target_type": "Work|Edition|Item",
  "target_id": ["..."],
  "reason": "work_edition_item_split",
  "migration_run": "..."
}
```

Een logical source-dispositionrow is uniek op
`source_family+source_type+source_id`; snapshot-observations zijn uniek op die
logical key plus `source_snapshot+payload_hash`. Target edges zijn one-to-many.
Reconciliation publiceert ook payloadhashes, source archive hash, target
context en schema version, zodat totals naar records terug te leiden zijn.

## 19. Idempotency, nieuwe exports en failure boundaries

### Dezelfde export opnieuw

Dezelfde snapshot, source ID en payloadhash hergebruikt exact dezelfde
mapping. De logical family mapping wordt vervolgens tegen de echte domain
identity gecontroleerd. Geen nieuwe Work/Edition/Item ontstaat.
Quarantine blijft quarantine totdat een expliciete resolution met auditbewijs
bestaat.

### Nieuwere V1-export vóór cutover

Een nieuwe archive hash is een nieuwe snapshot onder dezelfde `biblio-v1`
family. Gelijke logical source ID + gelijke payload hergebruikt de mapping;
gewijzigde payload doorloopt een expliciete compare/classify-fase. Nieuwe
records kunnen worden toegevoegd. Verdwenen of gewijzigde records veroorzaken
nooit automatische target-delete of overwrite; zij worden als source delta
gereconcileerd en vereisen de domeinspecifieke lifecycle-/conflictregel. Voor
id-loze contained children wordt bij een veranderde parent-set de hele set
quarantined totdat oude en nieuwe children expliciet zijn gecorreleerd. Hetzelfde
geldt wanneer een nieuwere export meerdere id-loze reviews onder één book bevat:
geen arraypositie of tekstwijziging wordt dan als stable identity gebruikt.

### Interrupted/partial failure

Een run heeft status `planned`, `running`, `failed`, `completed` en een
checkpoint per fase/batch. Een record-level aggregate write omvat alle
verplichte source-map/target edges en de volledige Work→Edition→Item/context
chain in één transactie. Geen ledger “mapped” vóór target commit. Shared
identity conflict rollbackt de verliezende chain, her-resolvet na rollback en
schrijft dan reuse of quarantine.

Batchgrenzen volgen aggregate dependencies, niet willekeurige arrayposities.
Na crash herneemt MIG-02 alleen committed checkpoints, valideert mappings en
herhaalt de idempotente recordunit. Preservation/quarantine-output moet even
transactioneel met de source disposition zijn als target writes. Een run wordt
pas `completed` wanneer alle categorieën exact reconciliëren en nul
onverklaarde records/relations overblijven.

## 20. Dry-run contract

Dry-run leest en valideert de volledige archive bytes, voert alle mapping- en
identityregels uit, simuleert target reuse tegen een read-only targetsnapshot
en produceert exact dezelfde dispositions, edges, quarantine en reconciliation
als write mode. Het schrijft geen production-target, migration ledger,
quarantine of preserved payloads.

Verplichte artifacts:

- run manifest met source hash/package/schema en target schema/context;
- category profile en reconciliation JSON;
- entity mapping proposal JSONL;
- create/reuse/transform plan;
- quarantine JSONL met reason codes;
- preserved-deferred manifest;
- conflict/decision/blocker summary;
- deterministic checksum per artifact.

Dry-run faalt gesloten bij verkeerde archive hash/version/member/schema,
onbekende source structure, niet-eenduidige target user/Library, ongezonde
target schema of een reconciliationverschil. Een dry-run mag blockers wel
volledig rapporteren zonder eerdere categorieën te verbergen.

## 21. MIG-02 uitvoerbaar contract

### Inputs

- een actuele V1 `/data/`-folder of export die Renée expliciet voor deze fase
  aanwijst, plus de door die run vastgelegde immutable snapshot en SHA-256;
- package- en schemaversies die uit die aangewezen actuele bron zijn gelezen en
  expliciet door de betreffende migratorversie worden ondersteund; de
  historische `507.0.13`/`29`/`2` waarden zijn geen toekomstige allowlist;
- verplichte `target_user_id` en `target_library_id` voor apply/write/resume;
- IDENTITY-01-validatie van actieve bestaande V2 user, exact designated
  personal Library en actieve Owner/direct membership/context;
- target schema version/health (minimaal de expliciet ondersteunde
  MIG-FND-01-versie `1018`);
- migration mode `dry-run|write|resume`;
- optionele bestaande migration-run-ID voor resume/retry;
- versiegebonden, gereviewde taxonomy mapping allowlist.

### Phases

1. bytes/hash/member/source-version validation;
2. volledige source inventory/profile en stable-ID uniqueness;
3. target context/ownership/schema health validation;
4. migration run + immutable source snapshot registration;
5. Authors en Series identity resolution;
6. hoofd-Works, contained Works en Work relationships;
7. Editions + canonical ISBN claims;
8. Items + verplichte LibraryCatalogContext/classification + Item evidence +
   archive current state en period/history in één aggregate-transactie;
9. read-only post-commitreconciliation van Item/context/classification-relaties;
10. Collections/memberships (alleen overslaan als de actuele profiler nul bevestigt);
11. ReadingRounds/read-truth reconciliation;
12. Private Notes;
13. Ratings/Reviews zonder publication;
14. Wishlist;
15. Hierna lezen (alleen overslaan als de actuele profiler nul bevestigt);
16. circulation volgens het goedgekeurde minimumcontract;
17. preserved-deferred/quarantine payloads en targetlinks;
18. complete reconciliation/postconditions en run finalization.

Series identity mag vroeg worden voorbereid, maar Work-Series links worden pas
na Work creation geschreven. Classification kan pas na Work en target Library.
User-owned data volgt pas na expliciete target user; Library-owned data pas na
Library/membershipvalidatie.

### Writes en failures

MIG-02 gebruikt named Core/application-import boundaries en omzeilt geen
domain authorization/invarianten met losse wpdb-writes. Shared identity,
Library-owned Item/context, user-owned records en evidence/ledger hebben
expliciete transactiedeelnemers. Onbekende structure of target health stopt de
run; recordconflicts gaan alleen door als traceerbare quarantine en de
reconciliation exact blijft. Geen provider lookup, metadata-correctie,
fixturemutatie of V1-write.

## 22. V2.001 minimumgaps uit echte V1-data

| Gap | V1 evidence | Waarom cutover-blocker | Minimum oplossing | Volledige feature deferred? |
|---|---|---|---|---|
| Basis Wishlist-target | 41 actieve records | dagelijkse lijst kan niet worden gemigreerd/gebruikt | user-owned Work/Edition-intent met stable ID, view/add/remove en migration binding | grouping/smart groups ja |
| **RESOLVED by READ-MIG-01 — onbekende leesdatum/-status** | historische audit bewees read/date-unknown, unknown en explicit no; geen count is actuele V1-waarheid | schema 1019 Personal Reading Truth bewaart de drie user×Work states zonder fictieve ronde/datum en met ownerprojectie | actief source-neutraal target + MIG-FND participant; normale write-UI volgt gericht als READ-UI-01 | rijke history-management UI ja |
| Item acquisition/local evidence | 77 acquisitions, 3 copy notes, 1 exemplar photo, 1 disposal | bronfeiten hebben geen actief target en mogen niet verdwijnen | minimale Item-data persistence/read of traceerbare actieve migration-evidence volgens bestaande ownershipgrens | specialist collector/cover ja |
| Legacy archive reason | 23 archived Items, 0 reason matches | archived state kan niet eerlijk via huidige archiveperiode worden geschreven | legacy reason/evidence zonder vertaling naar onware enum; archived Item blijft vindbaar | volledige archive-managementuitbreiding ja |
| Private migrated assessments leesbaar | 15 ratings, 1 review; V1 kent geen publication | huidige Book Detail toont alleen publicaties; publishing zou waarheid veranderen | owner-scoped read-only projection en unknown-rating-timebeleid | nieuwe writes/publication/moderation ja |
| Migration evidence storage | alle bronpopulaties, plus deferred/quarantine | zonder durable ledger/payloadbewijs geen idempotency of no-loss proof | **MIG-FND-01 gerealiseerd in schema 1018** | generieke import-UI ja |
| Open circulation settlement | 8 open rounds | preserved-only kan dagelijkse beëindiging blokkeren | alleen na Renée-besluit: minimale read/end lifecycle voor bestaande rounds | volledige lending ja |

Migration evidence storage and Personal Reading Truth are no longer gaps; the
remaining four domain targets are technical/product prerequisites. Open
circulation remains first a product decision. Unknown taxonomy requires daarnaast een gereviewde
migration allowlist/termset; dit is data-curation en geen toestemming voor
automatische termcreatie.

## 23. Release dependencies

| Classificatie | Prerequisite |
|---|---|
| BLOCKS MIG-02 WRITES | basis Wishlist persistence; archive reason contract; rating timestamp/import contract. MIG-FND-01 ledger/preservation and READ-MIG-01 Personal Reading Truth are no longer in this row. |
| BLOCKS FIRST FULL TRIAL IMPORT | Item acquisition/local evidence target; taxonomy mapping/termset voor 74 Book Types en overige gewenste labels; private assessment import/read boundary; circulation mapping na productbesluit |
| BLOCKS FINAL CUTOVER | basis Wishlist view/add/remove; remaining concrete read-history migration; archived Item discoverability; private ratings/review readability; chosen treatment of open loans based on a newly designated source; complete zero-silent-drop reconciliation |
| DOES NOT BLOCK MIGRATION | rich Goals/Home/Stats/Timeline/Audit UI; cover acquisition/management; Wishlist grouping; smart Collections; Authors/Series dedicated UI; new assessment writes/publication; full lending; general import UI; CAT-UI/QA-ADD human follow-up |

## 24. Product decisions voor Renée

1. **RESOLVED — V1 source owner → V2 user + target Library.** D-IDENTITY-01
   kiest één blijvende normale Renée-user en haar afzonderlijke designated
   personal `Mijn Bibliotheek`. De bestaande DDEV/platform admin is niet het
   target. IDENTITY-01 provisiont de context uitsluitend uit expliciete lokale
   login/e-mailinput en geeft environment-specifieke IDs terug; canonical docs
   hardcoden die IDs niet. Iedere MIG-02 apply/write/resume-run vereist
   `--target-user-id=<id>` en `--target-library-id=<id>`. Servervalidatie kent
   geen current-actor-, first-user-, admin- of display-namefallback en weigert
   ontbrekende/inactieve users, designationmismatch, vreemde Library of
   non-Owner/inactive membership. Cleanliness is een aparte read-only gate;
   non-empty targets vereisen operatorreview en worden nooit automatisch
   opgeschoond.
2. **RESOLVED — Personal Reading Truth.** READ-MIG-01 kiest één normale,
   source-neutrale user×Work state met exact `read_known_date_unknown`,
   `explicit_not_read` en `unknown`. Dit is geen ReadingRound en bewaart geen
   migratieprovenance; schema 1019 en MIG-FND-integratie zijn actief.
3. **Actieve/open loans.** Kies, na inventarisatie van een nieuw aangewezen
   actuele bron, of V2.001 ze alleen preserved toont, of
   dat bestaande rounds ook beëindigd moeten kunnen worden. Technisch
   aanbevolen voor dagelijkse continuïteit: het kleine read+end-contract uit
   §12; volledige lending blijft V2.002+.

Taxonomyconflicten, verdachte auteurs/series, reflections en individuele
circulationsemantiek zijn human data-review, geen nieuw productbesluit.

## 25. Teststrategie voor MIG-02/MIG-03

| Laag | Verplicht bewijs |
|---|---|
| A. Unit mapping | ISBN canonicalisatie, Work/Edition/Item split, stable IDs, dates, scales, reason classification |
| B. DATA-01 | exacte 47-case deterministic regression; source snapshot blijft immutable |
| C. Malformed/quarantine | invalid ISBN, missing parent/title, unknown structures/terms, id-loze hash identity |
| D. Duplicate/conflict | same ISBN safe reuse versus conflicting identity; duplicate source ID; read conflict |
| E. Idempotency | tweede identieke run creëert nul nieuwe targets en behoudt dezelfde mappings/counts |
| F. Interruption/resume | crash op iedere phase/batchgrens; geen half chain; committed checkpoint herbruikbaar |
| G. Full V1 dry-run | exacte hash van de voor die run door Renée aangewezen actuele source; alle category counts en blocker artifacts |
| H. Clean trial import | schone V2 database, vooraf gevalideerde target context, geen V1/runtime mutation |
| I. Reconciliation | source totals exact gelijk aan zes dispositions; relation expected=created+reused+failed |
| J. Second full import | nul duplicates, gelijke ledger edges en artifactchecksums waar context gelijk is |
| K. Newer export rehearsal | same/changed/new/missing source records expliciet gedifferentieerd; geen delete by absence |
| L. Final cutover rehearsal | V1 freeze, dry-run, backup/restore, timed write, functional reads, signed reconciliation |

Volledige V1-data wordt niet als gewone unit fixture gecommit. Trial imports
gebruiken een geïsoleerde database en vernietigen of muteren nooit de actieve
V1-bron.

## 26. Onafhankelijke reviewchecklist

De verplichte tweede pass controleert na de docdiff opnieuw:

- alle top-level files, embedded structures en unknown fields zijn ingedeeld;
- geen categorie valt impliciet weg of gebruikt
  `INTENTIONALLY_DROPPED_WITH_REASON` zonder expliciet besluit en reden;
- Work/Edition/Item volgt ISBN, explicit variant en CAT-T1 zonder fuzzy merge;
- user-/Library-ownership en targetbinding blijven expliciet;
- de in de historische audit aangetroffen rounds, registrations en compat
  history worden niet dubbel geteld; actuele aantallen komen alleen uit een
  nieuw aangewezen bron;
- Wishlist, Archive, Notes, assessments, circulation, Goals en assets blijven
  traceerbaar;
- iedere source population kan exact naar één disposition reconciliëren;
- rerun/resume kan geen dubbele chain of stille quarantine-acceptatie maken;
- MIG-02 hoeft buiten de benoemde prerequisites geen productregels te raden.

## 27. MIG-01 exit

Bronzekerheid, inventaris, mappings, preservation, quarantine, reconciliation,
identity/idempotency, dry-run en MIG-02-fasen zijn voldoende concreet.
IDENTITY-01 heeft de expliciete user+Library-binding uit §24 opgelost.
READ-MIG-01 heeft de unknown-date/status targetgap opgelost met normale
source-neutrale Personal Reading Truth in schema 1019. De huidige targetlaag
voldoet nog niet aan de exitcriteria voor writes of cutover door de overige
gaps in §22. MIG-FND-01 blijft de source-neutrale schema-1018 ledger,
transactionele recordboundary, reconciliation en traceability. Er is geen
V1-parser, import, domeincleanup of broninhoudelijke mappingregel gebouwd.

**MIGRATION DESIGN BLOCKED BY REMAINING DOMAIN TARGET GAPS**
