import { expect, test } from "@playwright/test";
import { mkdir } from "node:fs/promises";

const LIBRARY_ID = "e2e-library-actor";
const ADD_AUTH_CORE_TITLE = "E2E ADD-AUTH-01B Core proof";
const ADD_AUTH_CORE_INVENTORY = "E2E-ADD-AUTH-01B";
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
    await page.getByLabel("Boektype").selectOption({ label: "Leesboek" });
    if (inventory !== "") {
        await page.getByLabel("Inventarisnummer (optioneel)").fill(inventory);
    }
}

async function capture(page, name) {
    const directory = ".local/qa-add-v1-screenshots";
    await mkdir(directory, { recursive: true });
    await page.locator("[data-biblio-ui-root] .biblio-ui__shell").screenshot({
        animations: "disabled",
        path: `${directory}/${name}.png`,
    });
}

async function expectNoHorizontalOverflow(page, label) {
    const widths = await page.evaluate(() => {
        const root = document.querySelector("[data-biblio-ui-root]");
        const wizard = document.querySelector(".biblio-ui__add-book");
        return {
            rootClient: root?.clientWidth ?? 0,
            rootScroll: root?.scrollWidth ?? 0,
            wizardClient: wizard?.clientWidth ?? 0,
            wizardScroll: wizard?.scrollWidth ?? 0,
        };
    });
    expect(widths.rootScroll, `${label} root`).toBeLessThanOrEqual(widths.rootClient);
    expect(widths.wizardScroll, `${label} wizard`).toBeLessThanOrEqual(widths.wizardClient);
}

async function expectControlsReachable(page, label) {
    const controls = await page.locator(".biblio-ui__add-book .biblio-ui__control").evaluateAll(
        (nodes) => nodes.map((node) => {
            const box = node.getBoundingClientRect();
            return { height: box.height, left: box.left, right: box.right };
        })
    );
    for (const [index, control] of controls.entries()) {
        expect(control.height, `${label} control ${index} height`).toBeGreaterThanOrEqual(44);
        expect(control.left, `${label} control ${index} left`).toBeGreaterThanOrEqual(0);
        expect(control.right, `${label} control ${index} right`).toBeLessThanOrEqual(
            await page.evaluate(() => window.innerWidth)
        );
    }
}

test("ADD-AUTH-01B one manual Author reaches the success flow with exact payload", async ({ page }) => {
    let commitBody = null;
    await page.route(`**/libraries/${LIBRARY_ID}/classification-options`, async (route) => {
        await route.fulfill({ json: success(CLASSIFICATION) });
    });
    await page.route(`**/libraries/${LIBRARY_ID}/items`, async (route) => {
        commitBody = route.request().postDataJSON();
        await route.fulfill({ status: 201, json: success({
            item_id: "add-auth-one-item",
            edition_id: "add-auth-one-edition",
            work_id: "add-auth-one-work",
            edition_title: commitBody.observed_fields.title,
            work_title_status: "provisional",
            existing_edition: false,
        }) });
    });

    await page.goto(`/mijn-bibliotheek/?library_id=${LIBRARY_ID}`);
    await openWizard(page);
    await page.getByRole("button", { name: "Geen ISBN" }).click();
    await page.getByLabel("Titel van deze uitgave").fill("Nieuwe Work met één auteur");
    await page.getByLabel("Auteur 1", { exact: true }).fill("Maryse Condé");
    await page.getByRole("button", { name: "Verder" }).click();
    await classify(page);
    await page.getByRole("button", { name: "Naar controle" }).click();
    await page.getByRole("button", { name: "Boek toevoegen" }).click();

    await expect(page.getByRole("heading", { name: "Boek toegevoegd" })).toBeVisible();
    expect(commitBody.authors).toEqual([{ display_name: "Maryse Condé" }]);
    expect(Object.keys(commitBody.authors[0])).toEqual(["display_name"]);
});

test("ADD-AUTH-01B manual Author controls preserve draft, accessibility and request order", async ({ page }) => {
    const commitBodies = [];

    await page.route(`**/libraries/${LIBRARY_ID}/classification-options`, async (route) => {
        await route.fulfill({ json: success(CLASSIFICATION) });
    });
    await page.route("**/wp-json/biblio/v1/me/works?*", async (route) => {
        await route.fulfill({ json: success({
            items: [{
                work_id: "work-linked",
                title: "Bestaand werk",
                authors: [{ author_id: "author-linked", display_name: "Bestaande Auteur" }],
                work_title_status: "librarian_confirmed",
                series: [],
            }],
            next_cursor: null,
        }) });
    });
    await page.route(`**/libraries/${LIBRARY_ID}/items`, async (route) => {
        const body = route.request().postDataJSON();
        commitBodies.push(body);
        await route.fulfill({ status: 201, json: success({
            item_id: `add-auth-item-${commitBodies.length}`,
            edition_id: `add-auth-edition-${commitBodies.length}`,
            work_id: body.selection.work_id ?? `add-auth-work-${commitBodies.length}`,
            edition_title: body.observed_fields.title,
            work_title_status: "provisional",
            existing_edition: false,
        }) });
    });

    await page.setViewportSize({ width: 1440, height: 1000 });
    await page.goto(`/mijn-bibliotheek/?library_id=${LIBRARY_ID}`);
    await openWizard(page);
    await page.getByRole("button", { name: "Geen ISBN" }).click();

    await expect(page.getByRole("group", { name: "Auteur(s) (optioneel)" })).toBeVisible();
    await expect(page.getByLabel("Auteur 1", { exact: true })).toHaveCount(1);
    await expect(page.getByLabel("Overige bijdragers (optioneel)")).toBeVisible();
    await expect(page.getByText("Bijvoorbeeld vertaler, illustrator, redacteur of samensteller.")).toBeVisible();
    await expect(page.getByRole("button", { name: "Verplaats auteur 1 omhoog" })).toBeDisabled();
    await expect(page.getByRole("button", { name: "Verplaats auteur 1 omlaag" })).toBeDisabled();

    await page.getByLabel("Titel van deze uitgave").fill("Handmatige uitgave met auteurs");
    await page.getByLabel("Auteur 1", { exact: true }).fill("George C. Clark Jr.");
    await page.getByRole("button", { name: "Auteur toevoegen" }).press("Enter");
    await expect(page.getByLabel("Auteur 2", { exact: true })).toBeFocused();
    await page.getByLabel("Auteur 2", { exact: true }).fill("J. Bibb Cain");
    await page.getByRole("button", { name: "Verplaats auteur 2 omhoog" }).press("Enter");
    await expect(page.getByLabel("Auteur 1", { exact: true })).toBeFocused();
    await expect(page.getByLabel("Auteur 1", { exact: true })).toHaveValue("J. Bibb Cain");
    await expect(page.getByLabel("Auteur 2", { exact: true })).toHaveValue("George C. Clark Jr.");

    await page.getByLabel("Auteur 1", { exact: true }).fill("George C. Clark Jr.");
    await expect(page.getByText("Deze naam staat meer dan één keer. Controleer of dat klopt.")).toHaveCount(2);
    await expect(page.getByLabel("Auteur 1", { exact: true })).not.toHaveAttribute("aria-invalid", "true");
    await page.getByLabel("Auteur 1", { exact: true }).fill("J. Bibb Cain");
    await expect(page.getByText("Deze naam staat meer dan één keer. Controleer of dat klopt.")).toHaveCount(0);

    await page.getByRole("button", { name: "Auteur toevoegen" }).click();
    await expect(page.getByLabel("Auteur 3", { exact: true })).toBeFocused();
    await page.getByRole("button", { name: "Verwijder auteur 3" }).press("Enter");
    await expect(page.getByLabel("Auteur 2", { exact: true })).toBeFocused();
    await expect(page.getByLabel(/^Auteur /)).toHaveCount(2);
    await capture(page, "H-add-auth-manual-two-authors-desktop-1440");

    await page.getByRole("button", { name: "Terug" }).click();
    await page.getByRole("button", { name: "Geen ISBN" }).click();
    await expect(page.getByLabel("Titel van deze uitgave")).toHaveValue("Handmatige uitgave met auteurs");
    await expect(page.getByLabel("Auteur 1", { exact: true })).toHaveValue("J. Bibb Cain");
    await expect(page.getByLabel("Auteur 2", { exact: true })).toHaveValue("George C. Clark Jr.");

    await page.getByLabel("Auteur 1", { exact: true }).fill("x".repeat(513));
    await page.getByRole("button", { name: "Verder" }).click();
    await expect(page.getByLabel("Auteur 1", { exact: true })).toBeFocused();
    await expect(page.getByLabel("Auteur 1", { exact: true })).toHaveAttribute("aria-invalid", "true");
    await expect(page.getByText("Vul een geldige auteursnaam van maximaal 512 tekens in.")).toBeVisible();
    await expect(page.getByLabel("Titel van deze uitgave")).toHaveValue("Handmatige uitgave met auteurs");
    await page.getByLabel("Auteur 1", { exact: true }).fill("J. Bibb Cain");
    await expect(page.getByLabel("Auteur 1", { exact: true })).not.toHaveAttribute("aria-invalid", "true");

    await page.getByRole("button", { name: "Koppel aan bestaand werk" }).click();
    await page.getByLabel("Zoek op werktitel of auteur").fill("Bestaande");
    await page.getByRole("button", { name: "Zoeken" }).click();
    await page.getByRole("button", { name: "Dit werk gebruiken" }).click();
    await expect(page.getByRole("group", { name: "Auteur(s) (optioneel)" })).toHaveCount(0);
    await expect(page.getByText("Bestaande Auteur", { exact: true })).toBeVisible();
    await expect(page.getByText("Auteurs van dit werk worden hier niet aangepast.")).toBeVisible();
    await capture(page, "K-add-auth-existing-work-read-only-desktop-1440");

    await page.getByRole("button", { name: "Koppeling verwijderen" }).click();
    await expect(page.getByLabel("Auteur 1", { exact: true })).toHaveValue("J. Bibb Cain");
    await expect(page.getByLabel("Auteur 2", { exact: true })).toHaveValue("George C. Clark Jr.");
    await page.getByRole("button", { name: "Koppel aan bestaand werk" }).click();
    await page.getByRole("button", { name: "Dit werk gebruiken" }).click();
    await page.getByLabel("Overige bijdragers (optioneel)").fill("Vertaler Voorbeeld");
    await page.getByRole("button", { name: "Verder" }).click();
    await classify(page);
    await page.getByRole("button", { name: "Naar controle" }).click();
    await page.getByRole("button", { name: "Boek toevoegen" }).click();
    await expect(page.getByRole("heading", { name: "Boek toegevoegd" })).toBeVisible();
    expect(commitBodies[0].selection).toEqual({ type: "manual", work_id: "work-linked" });
    expect(commitBodies[0]).not.toHaveProperty("authors");
    expect(commitBodies[0].observed_fields.contributors).toEqual(["Vertaler Voorbeeld"]);

    await page.getByRole("button", { name: "Nog een boek toevoegen" }).click();
    await page.getByRole("button", { name: "Geen ISBN" }).click();
    await page.getByLabel("Titel van deze uitgave").fill("Nieuwe Work met twee auteurs");
    await page.getByLabel("Auteur 1", { exact: true }).fill("  George C. Clark Jr.  ");
    await page.getByRole("button", { name: "Auteur toevoegen" }).click();
    await page.getByLabel("Auteur 2", { exact: true }).fill("J. Bibb Cain");
    await page.getByLabel("Overige bijdragers (optioneel)").fill("Illustrator Voorbeeld");

    await page.setViewportSize({ width: 900, height: 1000 });
    await expectNoHorizontalOverflow(page, "Author form tablet 900");
    await expectControlsReachable(page, "Author form tablet 900");
    await page.setViewportSize({ width: 720, height: 1000 });
    await expectNoHorizontalOverflow(page, "Author form 200% reflow equivalent");
    await expectControlsReachable(page, "Author form 200% reflow equivalent");
    await capture(page, "J-add-auth-manual-two-authors-reflow-200");
    await page.setViewportSize({ width: 390, height: 844 });
    await expectNoHorizontalOverflow(page, "Author form mobile 390");
    await expectControlsReachable(page, "Author form mobile 390");
    await capture(page, "I-add-auth-manual-two-authors-mobile-390");
    await page.setViewportSize({ width: 1440, height: 1000 });

    await page.getByRole("button", { name: "Verder" }).click();
    await classify(page);
    await page.getByRole("button", { name: "Naar controle" }).click();
    await page.getByRole("button", { name: "Boek toevoegen" }).click();
    await expect(page.getByRole("heading", { name: "Boek toegevoegd" })).toBeVisible();
    expect(commitBodies[1].authors).toEqual([
        { display_name: "George C. Clark Jr." },
        { display_name: "J. Bibb Cain" },
    ]);
    expect(commitBodies[1].authors.flatMap(Object.keys)).toEqual(["display_name", "display_name"]);
    expect(commitBodies[1].observed_fields.contributors).toEqual(["Illustrator Voorbeeld"]);

    await page.getByRole("button", { name: "Nog een boek toevoegen" }).click();
    await page.getByRole("button", { name: "Geen ISBN" }).click();
    for (let position = 2; position <= 32; position += 1) {
        await page.getByRole("button", { name: "Auteur toevoegen" }).click();
    }
    await expect(page.getByLabel(/^Auteur /)).toHaveCount(32);
    await expect(page.getByRole("button", { name: "Auteur toevoegen" })).toBeDisabled();
    await expect(page.getByRole("button", { name: "Verplaats auteur 1 omhoog" })).toBeDisabled();
    await expect(page.getByRole("button", { name: "Verplaats auteur 32 omlaag" })).toBeDisabled();
});

test("ADD-AUTH-01B real browser commit materializes ordered Authors through Core", async ({ page }) => {
    await page.setViewportSize({ width: 1440, height: 1000 });
    await page.goto(`/mijn-bibliotheek/?library_id=${LIBRARY_ID}`);
    await openWizard(page);
    await page.getByRole("button", { name: "Geen ISBN" }).click();
    await page.getByLabel("Titel van deze uitgave").fill(ADD_AUTH_CORE_TITLE);
    await page.getByLabel("Auteur 1", { exact: true }).fill("E2E Auteur Alpha 01B");
    await page.getByRole("button", { name: "Auteur toevoegen" }).click();
    await page.getByLabel("Auteur 2", { exact: true }).fill("E2E Auteur Beta 01B");
    await page.getByRole("button", { name: "Verder" }).click();
    await classify(page, ADD_AUTH_CORE_INVENTORY);
    await page.getByRole("button", { name: "Naar controle" }).click();
    await page.getByRole("button", { name: "Boek toevoegen" }).click();
    await expect(page.getByRole("heading", { name: "Boek toegevoegd" })).toBeVisible();
    await expect(page.getByText(ADD_AUTH_CORE_TITLE, { exact: true })).toBeVisible();

    await page.getByRole("button", { name: "Nog een boek toevoegen" }).click();
    await page.getByRole("button", { name: "Geen ISBN" }).click();
    await page.getByLabel("Titel van deze uitgave").fill("Niet opgeslagen zoekdraft");
    await page.getByRole("button", { name: "Koppel aan bestaand werk" }).click();
    await page.getByLabel("Zoek op werktitel of auteur").fill("E2E Auteur Alpha 01B");
    await page.getByRole("button", { name: "Zoeken" }).click();
    await expect(page.getByRole("heading", { name: ADD_AUTH_CORE_TITLE })).toBeVisible();
    await expect(page.locator(".biblio-ui__work-authors").filter({
        hasText: "E2E Auteur Alpha 01B, E2E Auteur Beta 01B",
    })).toBeVisible();
});

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
    await page.setViewportSize({ width: 1440, height: 1000 });
    await page.goto(`/mijn-bibliotheek/?library_id=${LIBRARY_ID}`);
    await expect(page.getByRole("heading", { name: "Mijn Bibliotheek" })).toBeVisible();

    // Unsupported camera stays in the one wizard with immediate manual fallback.
    await openWizard(page);
    await expect(page.locator(".biblio-ui__guided-header h1")).toBeFocused();
    await capture(page, "A-start-desktop-1440");
    await page.setViewportSize({ width: 390, height: 844 });
    await expectNoHorizontalOverflow(page, "start mobile");
    await expectControlsReachable(page, "start mobile");
    await capture(page, "A-start-mobile-390");
    await page.setViewportSize({ width: 1440, height: 1000 });
    await page.getByRole("button", { name: "Scan ISBN" }).click();
    await expect(page.locator("[data-add-book-step='start']")).toBeVisible();
    await expect(page.getByText("Camera scannen is hier niet beschikbaar. Voer het ISBN handmatig in.")).toBeAttached();

    // Manual/no-ISBN with deliberate platform-wide Work selection.
    await page.getByRole("button", { name: "Geen ISBN" }).click();
    await capture(page, "E-manual-edition-desktop-1440");
    await page.setViewportSize({ width: 390, height: 844 });
    await expectNoHorizontalOverflow(page, "manual mobile");
    await capture(page, "E-manual-edition-mobile-390");
    await page.setViewportSize({ width: 1440, height: 1000 });
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
    await capture(page, "G-success-desktop-1440");
    await page.setViewportSize({ width: 390, height: 844 });
    await expectNoHorizontalOverflow(page, "success mobile");
    await capture(page, "G-success-mobile-390");
    await page.setViewportSize({ width: 1440, height: 1000 });
    await expect(page.getByRole("button", { name: "Exemplaar verder beschrijven" })).toBeDisabled();
    await page.getByRole("button", { name: "Nog een boek toevoegen" }).click();

    // Existing mismatch blocks duplicate creation before retrying the same ISBN.
    await enterIsbn(page, "9780306406157");
    await expect(page.getByRole("heading", { name: "Is dit inderdaad mijn uitgave?" })).toBeVisible();
    await expect(page.getByRole("group", { name: "Auteur(s) (optioneel)" })).toHaveCount(0);
    await expect(page.getByText("Bestaande uitgave", { exact: true })).toBeVisible();
    await expect(page.locator(".biblio-ui__edition-authors")).toHaveText("Auteur een");
    await expect(page.getByText("Deze uitgave staat al 1× in je Bibliotheek.")).toBeVisible();
    await capture(page, "B-existing-edition-desktop-1440");
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
    await expect(page.getByRole("group", { name: "Auteur(s) (optioneel)" })).toHaveCount(0);
    await capture(page, "C-single-candidate-desktop-1440");
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
    await expect(page.locator(".biblio-ui__edition-card .biblio-ui__control--primary")).toHaveCount(0);
    await capture(page, "D-multiple-candidates-desktop-1440");
    await page.setViewportSize({ width: 1024, height: 900 });
    await expectNoHorizontalOverflow(page, "multiple tablet");
    await expectControlsReachable(page, "multiple tablet");
    await page.setViewportSize({ width: 640, height: 900 });
    await expectNoHorizontalOverflow(page, "multiple 200%-equivalent");
    await expectControlsReachable(page, "multiple 200%-equivalent");
    await page.setViewportSize({ width: 390, height: 844 });
    await expectNoHorizontalOverflow(page, "multiple mobile");
    await expectControlsReachable(page, "multiple mobile");
    await capture(page, "D-multiple-candidates-mobile-390");
    await page.setViewportSize({ width: 1440, height: 1000 });
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
    await expect(page.locator(".biblio-ui__status-panel--warning")).toBeVisible();
    await expect(page.getByRole("button", { name: "Opnieuw proberen" })).toBeVisible();
    await capture(page, "F-provider-failure-desktop-1440");
    await page.setViewportSize({ width: 390, height: 844 });
    await expectNoHorizontalOverflow(page, "provider failure mobile");
    await capture(page, "F-provider-failure-mobile-390");
    await page.setViewportSize({ width: 1440, height: 1000 });
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
