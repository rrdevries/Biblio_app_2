import { expect, test } from "@playwright/test";

const LIBRARY_ID = "e2e-library-actor";
const CLASSIFICATION = {
    library_id: LIBRARY_ID,
    book_types: [{ book_type_id: "book-type", display_name: "Leesboek" }],
    genres: [{ genre_id: "genre", display_name: "Roman" }],
    subjects: [{ subject_id: "subject", display_name: "Geschiedenis" }],
};
const FIELD_BINDINGS = [{
    field: "title",
    target: "edition_title_evidence",
    explicit_mappings: [],
    fallback_target: null,
}, {
    field: "contributors",
    target: "evidence_only",
    explicit_mappings: {
        author: "work",
        translator: "edition",
    },
    fallback_target: "evidence_only",
}];

function success(data) {
    return { data };
}

function localEdition(suffix, count = 0) {
    return {
        work_id: `work-${suffix}`,
        work_title: `Werk ${suffix}`,
        work_title_status: "provisional",
        edition_id: `edition-${suffix}`,
        edition_title: `Lokale uitgave ${suffix}`,
        authors: [{ author_id: `author-${suffix}`, display_name: `Auteur ${suffix}` }],
        canonical_isbn: "9780306406157",
        existing_item_count: count,
        existing_items: count === 0 ? [] : [{
            item_id: `existing-item-${suffix}`,
            inventory_number: "INV-BESTAAND",
            location: { location_id: "location-1", display_name: "Kast A" },
        }],
    };
}

function candidate(suffix, isbn, overrides = {}) {
    return {
        candidate_id: `candidate-${suffix}`,
        source: {
            provider_key: "e2e-provider",
            retrieved_at: "2026-09-07T10:00:00.000000Z",
            match_method: "exact_isbn",
        },
        quality: "sufficient",
        identifier: { isbn_10: null, isbn_13: isbn },
        fields: {
            title: `Kandidaat ${suffix}`,
            subtitle: null,
            contributors: [`Auteur ${suffix}`],
            languages: ["nld"],
            publishers: ["Testuitgever"],
            publication_date: "2026",
            page_count: 240,
            format: null,
            ...overrides,
        },
        work_signal: null,
    };
}

function lookup(status, isbn, {
    localMatches = [],
    candidates = [],
    retry = false,
} = {}) {
    return success({
        library_id: LIBRARY_ID,
        status,
        identifier: { isbn_10: null, isbn_13: isbn },
        lookup_id: candidates.length > 0 ? `lookup-${isbn}` : null,
        local_matches: localMatches,
        candidates,
        field_bindings: FIELD_BINDINGS,
        manual_available: true,
        retry_available: retry,
    });
}

async function openWizard(page) {
    await page.getByRole("button", { name: "Boek toevoegen" }).click();
    await expect(page.locator("[data-add-book-step='start']")).toBeVisible();
}

async function enterIsbn(page, isbn) {
    await page.getByLabel("ISBN invoeren").fill(isbn);
    await page.getByRole("button", { name: "Boekgegevens zoeken" }).click();
}

async function classify(page, inventory = "") {
    await page.getByLabel("Boektype").selectOption("book-type");
    if (inventory !== "") {
        await page.getByLabel("Inventarisnummer (optioneel)").fill(inventory);
    }
}

test("ADD-UI-01 browsermatrix covers canonical selection, recovery and fallback paths", async ({ page }) => {
    let candidateCommitAttempts = 0;
    let failureLookups = 0;
    let itemSequence = 0;

    await page.route(`**/libraries/${LIBRARY_ID}/classification-options`, async (route) => {
        await route.fulfill({ json: success(CLASSIFICATION) });
    });
    await page.route(`**/libraries/${LIBRARY_ID}/metadata-lookups`, async (route) => {
        const { identifier } = route.request().postDataJSON();
        let response;
        switch (identifier) {
        case "9780306406157":
            response = lookup("existing_edition", identifier, {
                localMatches: [localEdition("een", 1)],
            });
            break;
        case "9780441172719":
            response = lookup("local_ambiguous", identifier, {
                localMatches: [
                    { ...localEdition("a"), canonical_isbn: identifier },
                    { ...localEdition("b"), canonical_isbn: identifier },
                ],
            });
            break;
        case "9780140328721":
            response = lookup("single_candidate", identifier, {
                candidates: [candidate("een", identifier)],
            });
            break;
        case "9780061120084":
            response = lookup("multiple_candidates", identifier, {
                candidates: [
                    candidate("A", identifier, { format: "Paperback" }),
                    candidate("B", identifier, { format: "Hardcover", page_count: 256 }),
                ],
            });
            break;
        case "9783161484100":
            response = lookup("no_usable_candidate", identifier);
            break;
        case "9780131103627":
            failureLookups += 1;
            response = failureLookups <= 2
                ? lookup("provider_failure", identifier, { retry: true })
                : lookup("no_usable_candidate", identifier);
            break;
        default:
            throw new Error(`Unexpected ISBN ${identifier}`);
        }
        await route.fulfill({ json: response });
    });
    await page.route("**/wp-json/biblio/v1/me/works?*", async (route) => {
        await route.fulfill({ json: success({
            items: [{
                work_id: "work-linked",
                title: "Bestaand werk",
                authors: [{ author_id: "author-linked", display_name: "Ada Auteur" }],
                work_title_status: "librarian_confirmed",
                series: [{ series_id: "series-1", display_name: "Reeks", position: "2" }],
            }],
            next_cursor: null,
        }) });
    });
    await page.route(`**/libraries/${LIBRARY_ID}/items`, async (route) => {
        if (route.request().method() !== "POST") {
            await route.fallback();
            return;
        }
        const body = route.request().postDataJSON();
        if (body.selection.type === "candidate" && candidateCommitAttempts === 0) {
            candidateCommitAttempts += 1;
            await route.fulfill({
                status: 409,
                json: {
                    code: "biblio_metadata_lookup_snapshot_unavailable",
                    message: "The requested change conflicts with the current state.",
                    data: { status: 409 },
                },
            });
            return;
        }
        itemSequence += 1;
        await route.fulfill({ status: 201, json: success({
            item_id: `added-item-${itemSequence}`,
            edition_id: `added-edition-${itemSequence}`,
            work_id: body.selection.work_id ?? `added-work-${itemSequence}`,
            edition_title: body.observed_fields.title ?? "Gekozen uitgave",
            work_title_status: "provisional",
            existing_edition: body.selection.type === "existing_edition",
        }) });
    });

    await page.addInitScript(() => {
        Object.defineProperty(globalThis, "BarcodeDetector", {
            configurable: true,
            value: undefined,
        });
    });
    await page.goto(`/mijn-bibliotheek/?library_id=${LIBRARY_ID}`);
    await expect(page.getByRole("heading", { name: "Mijn Bibliotheek" })).toBeVisible();

    // Unsupported camera stays in the one wizard with immediate manual fallback.
    await openWizard(page);
    await page.getByRole("button", { name: "Scan ISBN" }).click();
    await expect(page.locator("[data-add-book-step='start']")).toBeVisible();
    await expect(page.getByText("Camera scannen is hier niet beschikbaar. Voer het ISBN handmatig in.")).toBeAttached();

    // Manual/no-ISBN with deliberate platform-wide Work selection.
    await page.getByRole("button", { name: "Geen ISBN" }).click();
    await page.getByLabel("Titel van deze uitgave").fill("Handmatige uitgave");
    await page.getByRole("button", { name: "Koppel aan bestaand werk" }).click();
    await page.getByLabel("Zoek op werktitel of auteur").fill("Ada");
    await page.getByRole("button", { name: "Zoeken" }).click();
    await expect(page.getByText("Bestaand werk", { exact: true })).toBeVisible();
    await expect(page.getByText("Bibliografisch bevestigd")).toBeVisible();
    await expect(page.getByText("Serie: Reeks · 2")).toBeVisible();
    await page.getByRole("button", { name: "Dit werk gebruiken" }).click();
    await page.getByRole("button", { name: "Verder" }).click();
    await classify(page, "INV-NIEUW");
    await page.getByRole("button", { name: "Naar controle" }).click();
    await expect(page.locator("[data-add-book-step='summary']")).toContainText("Bestaand werk");
    await page.getByRole("button", { name: "Boek toevoegen" }).click();
    await expect(page.getByRole("heading", { name: "Boek toegevoegd" })).toBeVisible();
    await expect(page.getByRole("button", { name: "Exemplaar verder beschrijven" })).toBeDisabled();
    await page.getByRole("button", { name: "Nog een boek toevoegen" }).click();

    // Existing mismatch blocks duplicate creation before retrying the same ISBN.
    await enterIsbn(page, "9780306406157");
    await expect(page.getByRole("heading", { name: "Is dit inderdaad mijn uitgave?" })).toBeVisible();
    await expect(page.getByText("Deze uitgave staat al 1× in je Bibliotheek.")).toBeVisible();
    await page.getByRole("button", { name: "Klopt niet?" }).click();
    await page.getByRole("button", { name: "Dit is niet mijn uitgave" }).click();
    await expect(page.getByText("Er wordt niet automatisch een dubbele uitgave gemaakt")).toBeVisible();
    await page.getByRole("button", { name: "Terug naar ISBN invoeren" }).click();

    // Existing Edition with intentional extra-copy confirmation and direct commit.
    await enterIsbn(page, "9780306406157");
    await page.getByRole("button", { name: "Deze uitgave gebruiken" }).click();
    await page.getByRole("button", { name: "Nog een exemplaar toevoegen" }).click();
    await classify(page);
    await page.getByRole("button", { name: "Boek toevoegen" }).click();
    await expect(page.getByRole("heading", { name: "Boek toegevoegd" })).toBeVisible();
    await page.getByRole("button", { name: "Nog een boek toevoegen" }).click();

    // A checked local correction remains evidence and still requires extra-copy confirmation.
    await enterIsbn(page, "9780306406157");
    await page.getByRole("button", { name: "Klopt niet?" }).click();
    await page.getByRole("button", { name: "De uitgave klopt, maar gegevens zijn fout" }).click();
    await page.getByLabel("Titel van deze uitgave").fill("Gecontroleerde lokale titel");
    await page.getByRole("button", { name: "Verder" }).click();
    await page.getByRole("button", { name: "Nog een exemplaar toevoegen" }).click();
    await classify(page);
    await page.getByRole("button", { name: "Naar controle" }).click();
    await page.getByRole("button", { name: "Boek toevoegen" }).click();
    await expect(page.getByRole("heading", { name: "Boek toegevoegd" })).toBeVisible();
    await page.getByRole("button", { name: "Nog een boek toevoegen" }).click();

    // Local ambiguity remains an explicit, unranked local choice.
    await enterIsbn(page, "9780441172719");
    await expect(page.getByRole("heading", { name: "Welke uitgave heb je?" })).toBeVisible();
    await expect(page.locator(".biblio-ui__edition-card")).toHaveCount(2);
    await page.getByRole("button", { name: "Geen van deze" }).click();
    await expect(page.getByText("Er wordt niet automatisch een dubbele uitgave gemaakt")).toBeVisible();
    await page.getByRole("button", { name: "Terug naar uitgaven" }).click();
    await page.getByRole("button", { name: "Deze uitgave" }).nth(1).click();
    await classify(page);
    await page.getByRole("button", { name: "Boek toevoegen" }).click();
    await expect(page.getByRole("heading", { name: "Boek toegevoegd" })).toBeVisible();
    await page.getByRole("button", { name: "Nog een boek toevoegen" }).click();

    // Candidate snapshot expiry starts a fresh review and keeps Item input.
    await enterIsbn(page, "9780140328721");
    await page.getByRole("button", { name: "Gegevens aanpassen" }).click();
    await page.getByLabel("Ondertitel (optioneel)").fill("Gecontroleerd");
    await page.getByRole("button", { name: "Verder" }).click();
    await classify(page, "INV-BEHOUDEN");
    await page.getByRole("button", { name: "Naar controle" }).click();
    await page.getByRole("button", { name: "Boek toevoegen" }).click();
    await expect(page.getByRole("heading", { name: "Is dit de juiste uitgave?" })).toBeVisible();
    await expect(page.getByText("Controleer de opnieuw opgehaalde boekgegevens.")).toBeAttached();
    await page.getByRole("button", { name: "Ja, deze uitgave" }).click();
    await expect(page.getByLabel("Boektype")).toHaveValue("book-type");
    await expect(page.getByLabel("Inventarisnummer (optioneel)")).toHaveValue("INV-BEHOUDEN");
    await page.getByRole("button", { name: "Naar controle" }).click();
    await page.getByRole("button", { name: "Boek toevoegen" }).click();
    await expect(page.getByRole("heading", { name: "Boek toegevoegd" })).toBeVisible();
    await page.getByRole("button", { name: "Nog een boek toevoegen" }).click();

    // Multiple candidates visibly preserve differences and can fall back manually.
    await enterIsbn(page, "9780061120084");
    await expect(page.locator(".biblio-ui__edition-card")).toHaveCount(2);
    await expect(page.locator(".biblio-ui__edition-fact--different")).not.toHaveCount(0);
    await page.getByRole("button", { name: "Geen van deze / Handmatig invoeren" }).click();
    await expect(page.getByRole("heading", { name: "Uitgave handmatig invoeren" })).toBeVisible();
    await page.getByRole("button", { name: "Annuleren" }).click();

    // Provider miss/failure stays non-technical, retryable and manual.
    await openWizard(page);
    await enterIsbn(page, "9783161484100");
    await expect(page.getByRole("heading", { name: "Geen bruikbare boekgegevens gevonden" })).toBeVisible();
    await expect(page.getByRole("button", { name: "Handmatig invoeren" })).toBeVisible();
    await page.getByRole("button", { name: "Annuleren" }).click();
    await openWizard(page);
    await enterIsbn(page, "9780131103627");
    await expect(page.getByText("Boekgegevens konden tijdelijk niet worden opgehaald.")).toBeVisible();
    await expect(page.getByRole("button", { name: "Opnieuw proberen" })).toBeVisible();
    await page.getByRole("button", { name: "Handmatig invoeren" }).click();
    await expect(page.locator("[data-add-book-step='edition-form']")).toBeVisible();
    await expect(page.getByRole("heading", { name: "Uitgave handmatig invoeren" })).toBeVisible();
    await expect(page.getByLabel("ISBN")).toHaveValue("9780131103627");
    await page.getByRole("button", { name: "Annuleren" }).click();
    await openWizard(page);
    await enterIsbn(page, "9780131103627");
    await page.getByRole("button", { name: "Opnieuw proberen" }).click();
    await expect(page.getByRole("heading", { name: "Geen bruikbare boekgegevens gevonden" })).toBeVisible();

    expect(candidateCommitAttempts).toBe(1);
    expect(itemSequence).toBe(5);
    expect(new URL(page.url()).searchParams.get("library_id")).toBe(LIBRARY_ID);
});
