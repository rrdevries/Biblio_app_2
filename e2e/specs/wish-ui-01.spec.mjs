import { execFileSync } from "node:child_process";
import { mkdir } from "node:fs/promises";
import { expect, test } from "@playwright/test";

const PAGE = "/verlanglijst/";
const HISTORY_TITLE = "E2E Leesgeschiedenis";

function fixtureAction(action) {
    return JSON.parse(execFileSync("./scripts/e2e-fixture.sh", [action], {
        cwd: process.cwd(),
        encoding: "utf8",
    }));
}

function rows(page) {
    return page.locator(".biblio-ui__wishlist-row");
}

async function open(page) {
    await page.goto(PAGE);
    await expect(page.getByRole("heading", { level: 1, name: "Verlanglijst", exact: true })).toBeVisible();
    await expect(page.locator(".biblio-ui__loading")).toHaveCount(0);
}

async function addWork(page, query, title) {
    await page.getByRole("button", { name: "Boek toevoegen aan verlanglijst" }).click();
    const dialog = page.getByRole("dialog", { name: "Boek toevoegen aan verlanglijst" });
    await dialog.getByRole("searchbox", { name: "Zoek op titel, auteur of ISBN" }).fill(query);
    await dialog.getByRole("button", { name: "Zoeken" }).click();
    await expect(dialog.getByRole("status")).toContainText("gevonden");
    await dialog.locator('[data-result-type="local_work"]', { hasText: title })
        .getByRole("button", { name: "Uitgave maakt niet uit" }).click();
    return dialog;
}

async function expectNoHorizontalOverflow(page) {
    const widths = await page.evaluate(() => ({
        body: document.body.scrollWidth,
        document: document.documentElement.scrollWidth,
        viewport: window.innerWidth,
    }));
    expect(widths.body).toBeLessThanOrEqual(widths.viewport);
    expect(widths.document).toBeLessThanOrEqual(widths.viewport);
}

async function capture(page, name) {
    const directory = ".local/wish-disc-01-screenshots/after";
    await mkdir(directory, { recursive: true });
    await page.screenshot({
        animations: "disabled",
        fullPage: true,
        path: `${directory}/${name}.png`,
    });
}

function externalCandidate(overrides = {}) {
    return {
        candidate_id: "candidate-external-edition",
        type: "external_edition_candidate",
        work_id: null,
        edition_id: null,
        title: "The Dispossessed",
        subtitle: "An Ambiguous Utopia",
        contributors: ["Ursula K. Le Guin"],
        languages: ["eng"],
        publishers: ["Harper Perennial"],
        publication_date: "2003",
        page_count: 400,
        format: "Paperback",
        isbn_10: "006051275X",
        isbn_13: "9780060512750",
        provider_evidence: {
            provider_key: "open_library",
            provider_record_id: "/books/OL1M",
            provider_work_id: "/works/OL1W",
            retrieved_at: "2026-09-10T12:00:00+00:00",
            match_method: "text_search",
        },
        presentation_order: 0,
        capabilities: {
            can_add_work_only: true,
            can_add_edition_specific: true,
        },
        ...overrides,
    };
}

function localWorkCandidate(overrides = {}) {
    return {
        candidate_id: "candidate-local-work",
        type: "local_work",
        work_id: "e2e-work-missing-metadata",
        edition_id: null,
        title: "The Secret Commonwealth",
        subtitle: null,
        contributors: [],
        languages: [],
        publishers: [],
        publication_date: null,
        page_count: null,
        format: null,
        isbn_10: null,
        isbn_13: null,
        provider_evidence: null,
        presentation_order: 0,
        capabilities: {
            can_add_work_only: true,
            can_add_edition_specific: false,
        },
        ...overrides,
    };
}

function localEditionCandidate(overrides = {}) {
    return {
        ...localWorkCandidate(),
        candidate_id: "candidate-local-edition",
        type: "local_edition",
        work_id: "e2e-work-history",
        edition_id: "e2e-edition-history",
        title: HISTORY_TITLE,
        isbn_13: "9780306406157",
        capabilities: {
            can_add_work_only: true,
            can_add_edition_specific: true,
        },
        ...overrides,
    };
}

function localDiscoveryResponse(query, results) {
    return {
        query: { type: "text", normalized: query.toLowerCase() },
        status: "results",
        discovery_id: null,
        results,
        provider_attempts: ["open_library", "google_books"].map((provider_key) => ({
            provider_key,
            status: "miss",
            failure_reason: null,
        })),
    };
}

function wishlistEntry(overrides = {}) {
    return {
        wishlist_entry_id: "wishlist-entry-race",
        target_type: "work_only",
        work_id: "e2e-work-missing-metadata",
        edition_id: null,
        display_title: "The Secret Commonwealth",
        authors: [],
        created_at: "2026-09-10T12:00:00.000000Z",
        updated_at: "2026-09-10T12:00:00.000000Z",
        ...overrides,
    };
}

function discoveryResponse(query, results, status = "results") {
    const isbnQuery = /^97[89][0-9]{10}$/.test(query);
    const providerAttempts = isbnQuery
        ? []
        : status === "results"
        ? [{
            provider_key: "open_library",
            status: "candidates",
            failure_reason: null,
        }]
        : status === "no_results"
            ? ["open_library", "google_books"].map((provider_key) => ({
                provider_key,
                status: "miss",
                failure_reason: null,
            }))
            : [{
                provider_key: "open_library",
                status: "unavailable",
                failure_reason: "network",
            }, {
                provider_key: "google_books",
                status: "miss",
                failure_reason: null,
            }];
    return {
        query: {
            type: isbnQuery ? "isbn" : "text",
            normalized: query.toLowerCase(),
        },
        status,
        discovery_id: results.some((result) => result.type.startsWith("external_"))
            ? "lookup-11111111111111111111111111111111"
            : null,
        results,
        provider_attempts: providerAttempts,
    };
}

async function routeDiscovery(page, handler) {
    await page.route(/\/wp-json\/biblio\/v1\/me\/bibliographic-discoveries$/, async (route) => {
        const payload = JSON.parse(route.request().postData() ?? "{}");
        await route.fulfill({
            status: 200,
            contentType: "application/json",
            body: JSON.stringify({ data: await handler(payload.query) }),
        });
    });
}

test.describe.configure({ mode: "serial" });

test.beforeEach(() => fixtureAction("wishlist-seed"));

test("owner list keeps one Work wish and two Edition wishes separate in the shared shell", async ({ page }) => {
    await open(page);
    await expect(rows(page)).toHaveCount(3);
    await expect(rows(page).filter({ hasText: "The Secret Commonwealth" })).toContainText("Uitgave maakt niet uit");
    await expect(rows(page).filter({ hasText: HISTORY_TITLE })).toHaveCount(2);
    await expect(page.getByText("Specifieke uitgave", { exact: true })).toHaveCount(2);
    const navigation = page.getByRole("navigation", { name: "Hoofdnavigatie" });
    await expect(navigation.getByRole("link", { name: "Verlanglijst" })).toHaveAttribute("aria-current", "page");
    await expect(navigation.getByRole("link", { name: "Mijn Bibliotheek" })).toHaveAttribute("href", /mijn-bibliotheek/);
    await expect(navigation.getByRole("link", { name: "Hierna lezen" })).toHaveAttribute("href", /hierna-lezen/);
    await expect(rows(page).locator("a")).toHaveCount(0);
    await capture(page, "wishlist-seeded-desktop");
});

test("empty, add, duplicate and direct remove preserve authoritative state", async ({ page }) => {
    fixtureAction("wishlist-reset");
    await routeDiscovery(page, async (query) => localDiscoveryResponse(query, [
        localWorkCandidate(),
    ]));
    await open(page);
    await expect(page.getByRole("heading", { level: 2, name: "Je verlanglijst is nog leeg" })).toBeVisible();
    await capture(page, "wishlist-empty-desktop");

    await page.getByRole("button", { name: "Boek toevoegen aan verlanglijst" }).click();
    const searchDialog = page.getByRole("dialog", { name: "Boek toevoegen aan verlanglijst" });
    await searchDialog.getByRole("searchbox", { name: "Zoek op titel, auteur of ISBN" }).fill("The Secret Commonwealth");
    await searchDialog.getByRole("button", { name: "Zoeken" }).click();
    await expect(searchDialog.getByRole("status")).toContainText("gevonden");
    await capture(page, "wishlist-add-work-search");
    await searchDialog.locator('[data-result-type="local_work"]', { hasText: "The Secret Commonwealth" })
        .getByRole("button", { name: "Uitgave maakt niet uit" }).click();
    await expect(rows(page)).toHaveCount(1);
    const id = await rows(page).first().getAttribute("data-entry-id");
    await addWork(page, "The Secret Commonwealth", "The Secret Commonwealth");
    await expect(rows(page)).toHaveCount(1);
    await expect(rows(page).first()).toHaveAttribute("data-entry-id", id);
    await rows(page).first().getByRole("button", { name: /verwijderen/ }).click();
    await expect(rows(page)).toHaveCount(0);
    await expect(page.getByRole("dialog")).toHaveCount(0);
    await expect(page.getByRole("heading", { level: 2, name: "Je verlanglijst is nog leeg" })).toBeVisible();
});

test("local discovery keeps concrete Editions separate and adds the selected one", async ({ page }) => {
    fixtureAction("wishlist-reset");
    await routeDiscovery(page, async (query) => localDiscoveryResponse(query, [
        localWorkCandidate({
            candidate_id: "candidate-history-work",
            work_id: "e2e-work-history",
            title: HISTORY_TITLE,
        }),
        localEditionCandidate(),
        localEditionCandidate({
            candidate_id: "candidate-local-edition-other",
            edition_id: "e2e-edition-history-other",
            isbn_13: "9780380018529",
            presentation_order: 1,
        }),
    ]));
    await open(page);
    await page.getByRole("button", { name: "Boek toevoegen aan verlanglijst" }).click();
    const dialog = page.getByRole("dialog", { name: "Boek toevoegen aan verlanglijst" });
    await dialog.getByRole("searchbox").fill(HISTORY_TITLE);
    await dialog.getByRole("button", { name: "Zoeken" }).click();
    await expect(dialog.getByRole("status")).toContainText("gevonden");
    const editions = dialog.locator('[data-result-type="local_edition"]');
    expect(await editions.count()).toBeGreaterThan(1);
    await capture(page, "wishlist-local-results");
    await editions.first().getByRole("button", { name: "Deze specifieke uitgave" }).click();
    await expect(dialog).toHaveCount(0);
    await expect(rows(page)).toHaveCount(1);
    await expect(rows(page).first()).toContainText("Specifieke uitgave");
});

test("reverse Work-only conflict is product copy and never deletes two Edition wishes", async ({ page }) => {
    await routeDiscovery(page, async (query) => localDiscoveryResponse(query, [
        localWorkCandidate({
            candidate_id: "candidate-history-work",
            work_id: "e2e-work-history",
            title: HISTORY_TITLE,
        }),
    ]));
    await open(page);
    const before = await rows(page).count();
    const dialog = await addWork(page, HISTORY_TITLE, HISTORY_TITLE);
    await expect(dialog.getByRole("heading", { name: "Algemene wens niet toegevoegd" })).toBeVisible();
    await expect(dialog).toContainText("verwijdert of voegt die niet automatisch samen");
    await expect(dialog.locator("li")).toHaveCount(2);
    await expect(rows(page)).toHaveCount(before);
    await capture(page, "wishlist-reverse-conflict");
    await dialog.getByRole("button", { name: "Sluiten" }).click();
});

test("a concurrent reverse conflict reloads the authoritative Edition state", async ({ page }) => {
    fixtureAction("wishlist-reset");
    await routeDiscovery(page, async (query) => localDiscoveryResponse(query, [
        localWorkCandidate({
            candidate_id: "candidate-history-work",
            work_id: "e2e-work-history",
            title: HISTORY_TITLE,
        }),
    ]));
    const edition = wishlistEntry({
        target_type: "edition_specific",
        work_id: "e2e-work-history",
        edition_id: "e2e-edition-history",
        display_title: HISTORY_TITLE,
    });
    let reads = 0;
    await page.route(/\/wp-json\/biblio\/v1\/me\/wishlist$/, async (route) => {
        if (route.request().method() === "POST") {
            await route.fulfill({
                status: 409,
                contentType: "application/json",
                body: JSON.stringify({
                    code: "biblio_wishlist_intent_conflict",
                    message: "private",
                    data: { status: 409 },
                }),
            });
            return;
        }
        reads += 1;
        await route.fulfill({
            status: 200,
            contentType: "application/json",
            body: JSON.stringify({ data: { entries: reads === 1 ? [] : [edition] } }),
        });
    });

    await open(page);
    const dialog = await addWork(page, HISTORY_TITLE, HISTORY_TITLE);
    await expect(dialog.getByRole("heading", { name: "Algemene wens niet toegevoegd" })).toBeVisible();
    await expect(rows(page)).toHaveCount(1);
    await expect(rows(page).first()).toContainText("Specifieke uitgave");
    expect(reads).toBe(2);
});

test("one discovery field presents external title, author, ISBN and multiple Editions", async ({ page }) => {
    await page.setViewportSize({ width: 1440, height: 1200 });
    await routeDiscovery(page, async (query) => {
        if (/^978/.test(query)) {
            return discoveryResponse(query, [externalCandidate({
                candidate_id: "candidate-isbn",
                provider_evidence: {
                    ...externalCandidate().provider_evidence,
                    match_method: "exact_isbn",
                },
            })]);
        }
        return discoveryResponse(query, [
            externalCandidate({
                candidate_id: "candidate-work",
                type: "external_work_candidate",
                subtitle: null,
                languages: [],
                publishers: [],
                publication_date: null,
                page_count: null,
                format: null,
                isbn_10: null,
                isbn_13: null,
                capabilities: {
                    can_add_work_only: true,
                    can_add_edition_specific: false,
                },
            }),
            externalCandidate({ candidate_id: "candidate-paperback", presentation_order: 1 }),
            externalCandidate({
                candidate_id: "candidate-hardcover",
                title: "The Dispossessed — hardcover",
                publication_date: "1975",
                format: "Hardcover",
                isbn_10: null,
                isbn_13: "9780380018529",
                provider_evidence: {
                    ...externalCandidate().provider_evidence,
                    provider_record_id: "/books/OL2M",
                },
                presentation_order: 2,
            }),
        ]);
    });
    await open(page);
    await page.getByRole("button", { name: "Boek toevoegen aan verlanglijst" }).click();
    const dialog = page.getByRole("dialog", { name: "Boek toevoegen aan verlanglijst" });
    const searchbox = dialog.getByRole("searchbox", { name: "Zoek op titel, auteur of ISBN" });

    await searchbox.fill("Ursula Le Guin");
    await dialog.getByRole("button", { name: "Zoeken" }).click();
    await expect(dialog.getByRole("status")).toContainText("3 resultaten gevonden");
    await expect(dialog.locator(".biblio-ui__wishlist-discovery-result")).toHaveCount(3);
    await expect(dialog.getByText("Ursula K. Le Guin", { exact: true })).toHaveCount(3);
    await expect(dialog.getByText("Paperback", { exact: true })).toBeVisible();
    await expect(dialog.getByText("Hardcover", { exact: true })).toBeVisible();
    await expect(dialog.getByRole("button", { name: "Deze specifieke uitgave" })).toHaveCount(2);
    await expect(dialog).not.toContainText("Open Library");
    await capture(page, "wishlist-external-title-result");
    await capture(page, "wishlist-multiple-editions");
    await capture(page, "wishlist-work-edition-choice");

    await searchbox.fill("9780060512750");
    await dialog.getByRole("button", { name: "Zoeken" }).click();
    await expect(dialog.getByRole("status")).toContainText("1 resultaat gevonden");
    await expect(dialog).toContainText("9780060512750");
    await capture(page, "wishlist-isbn-result");
});

test("text discovery keeps local-first breadth regardless of Wishlist membership", async ({ page }) => {
    await routeDiscovery(page, async (query) => {
        if (query === "lokale providerfout") {
            return {
                ...discoveryResponse(query, [], "provider_failure"),
                status: "results",
                results: [localWorkCandidate()],
            };
        }
        return discoveryResponse(query, [
            localWorkCandidate(),
            externalCandidate({
                candidate_id: "candidate-other-work",
                type: "external_work_candidate",
                title: "The Book of Dust",
                subtitle: null,
                languages: [],
                publishers: [],
                publication_date: null,
                page_count: null,
                format: null,
                isbn_10: null,
                isbn_13: null,
                capabilities: {
                    can_add_work_only: true,
                    can_add_edition_specific: false,
                },
            }),
        ]);
    });
    await open(page);
    await page.getByRole("button", { name: "Boek toevoegen aan verlanglijst" }).click();
    const dialog = page.getByRole("dialog", { name: "Boek toevoegen aan verlanglijst" });
    const searchbox = dialog.getByRole("searchbox");

    await searchbox.fill("the secret commonwealth");
    await dialog.getByRole("button", { name: "Zoeken" }).click();
    await expect(dialog.locator(".biblio-ui__wishlist-discovery-result")).toHaveCount(2);
    await expect(dialog.locator(".biblio-ui__wishlist-discovery-result").first())
        .toHaveAttribute("data-result-type", "local_work");
    await expect(dialog.getByText("The Book of Dust", { exact: true })).toBeVisible();

    await searchbox.fill("lokale providerfout");
    await dialog.getByRole("button", { name: "Zoeken" }).click();
    await expect(dialog.locator('[data-result-type="local_work"]')).toBeVisible();
    await expect(dialog.getByRole("status")).toContainText("1 resultaat gevonden");
    await expect(dialog.getByRole("status")).toContainText(
        "Externe uitbreiding is tijdelijk niet volledig beschikbaar"
    );
});

test("a late search response cannot replace a newer query", async ({ page }) => {
    await routeDiscovery(page, async (query) => {
        if (query === "eerste zoekopdracht") {
            await new Promise((resolve) => setTimeout(resolve, 300));
        }
        return discoveryResponse(query, [externalCandidate({
            candidate_id: query === "eerste zoekopdracht" ? "candidate-old" : "candidate-new",
            title: query === "eerste zoekopdracht" ? "Oud resultaat" : "Nieuw resultaat",
        })]);
    });
    await open(page);
    await page.getByRole("button", { name: "Boek toevoegen aan verlanglijst" }).click();
    const dialog = page.getByRole("dialog", { name: "Boek toevoegen aan verlanglijst" });
    const searchbox = dialog.getByRole("searchbox");
    await searchbox.fill("eerste zoekopdracht");
    await dialog.locator("form").evaluate((form) => form.requestSubmit());
    await searchbox.fill("tweede zoekopdracht");
    await dialog.locator("form").evaluate((form) => form.requestSubmit());
    await expect(dialog.getByText("Nieuw resultaat", { exact: true })).toBeVisible();
    await page.waitForTimeout(400);
    await expect(dialog.getByText("Oud resultaat", { exact: true })).toHaveCount(0);
});

test("materialized canonical target retries only the Wishlist write and creates no possession", async ({ page }) => {
    fixtureAction("wishlist-reset");
    const before = fixtureAction("state").counts;
    let materializations = 0;
    let wishlistWrites = 0;
    await routeDiscovery(page, async (query) => discoveryResponse(query, [externalCandidate()]));
    await page.route(/\/wp-json\/biblio\/v1\/me\/bibliographic-discoveries\/[^/]+\/materializations$/, async (route) => {
        materializations += 1;
        expect(JSON.parse(route.request().postData())).toEqual({
            candidate_id: "candidate-external-edition",
            intent: "work_only",
        });
        await route.fulfill({
            status: 201,
            contentType: "application/json",
            body: JSON.stringify({ data: {
                work_id: "e2e-work-missing-metadata",
                edition_id: null,
                reused: true,
            } }),
        });
    });
    await page.route(/\/wp-json\/biblio\/v1\/me\/wishlist$/, async (route) => {
        if (route.request().method() === "POST") {
            wishlistWrites += 1;
            if (wishlistWrites === 1) {
                await route.fulfill({
                    status: 503,
                    contentType: "application/json",
                    body: JSON.stringify({
                        code: "rest_unavailable",
                        message: "unsafe",
                        data: { status: 503 },
                    }),
                });
                return;
            }
        }
        await route.continue();
    });

    await open(page);
    await page.getByRole("button", { name: "Boek toevoegen aan verlanglijst" }).click();
    const dialog = page.getByRole("dialog", { name: "Boek toevoegen aan verlanglijst" });
    await dialog.getByRole("searchbox").fill("The Dispossessed");
    await dialog.getByRole("button", { name: "Zoeken" }).click();
    await dialog.getByRole("button", { name: "Uitgave maakt niet uit" }).click();
    await expect(dialog.getByRole("heading", { name: "Wens nog niet toegevoegd" })).toBeVisible();
    await expect(dialog).toContainText("Probeer alleen het bewaren");
    await dialog.getByRole("button", { name: "Opnieuw proberen" }).click();
    await expect(dialog).toHaveCount(0);
    await expect(rows(page)).toHaveCount(1);
    expect(materializations).toBe(1);
    expect(wishlistWrites).toBe(2);

    const after = fixtureAction("state").counts;
    for (const key of [
        "items",
        "collections",
        "collection_memberships",
        "rounds",
        "catalog_contexts",
        "classification_terms",
        "activity_events",
    ]) {
        expect(after[key], `${key} must not change`).toBe(before[key]);
    }
});

test("external Edition add retries materialization and reuses an exact duplicate", async ({ page }) => {
    fixtureAction("wishlist-reset");
    let materializations = 0;
    let wishlistWrites = 0;
    await routeDiscovery(page, async (query) => discoveryResponse(query, [externalCandidate()]));
    await page.route(/\/wp-json\/biblio\/v1\/me\/bibliographic-discoveries\/[^/]+\/materializations$/, async (route) => {
        materializations += 1;
        expect(JSON.parse(route.request().postData())).toEqual({
            candidate_id: "candidate-external-edition",
            intent: "work_and_edition",
        });
        if (materializations === 1) {
            await route.fulfill({
                status: 503,
                contentType: "application/json",
                body: JSON.stringify({
                    code: "rest_unavailable",
                    message: "unsafe",
                    data: { status: 503 },
                }),
            });
            return;
        }
        await route.fulfill({
            status: 201,
            contentType: "application/json",
            body: JSON.stringify({ data: {
                work_id: "e2e-work-missing-metadata",
                edition_id: "e2e-edition-missing-metadata",
                reused: materializations > 2,
            } }),
        });
    });
    await page.route(/\/wp-json\/biblio\/v1\/me\/wishlist$/, async (route) => {
        if (route.request().method() === "POST") wishlistWrites += 1;
        await route.continue();
    });

    await open(page);
    let entryId = null;
    for (let attempt = 0; attempt < 2; attempt++) {
        await page.getByRole("button", { name: "Boek toevoegen aan verlanglijst" }).click();
        const dialog = page.getByRole("dialog", { name: "Boek toevoegen aan verlanglijst" });
        await dialog.getByRole("searchbox").fill("The Secret Commonwealth paperback");
        await dialog.getByRole("button", { name: "Zoeken" }).click();
        await dialog.getByRole("button", { name: "Deze specifieke uitgave" }).click();
        if (attempt === 0) {
            await expect(dialog.getByRole("heading", { name: "Boek nog niet voorbereid" })).toBeVisible();
            await dialog.getByRole("button", { name: "Opnieuw proberen" }).click();
        }
        await expect(dialog).toHaveCount(0);
        await expect(rows(page)).toHaveCount(1);
        await expect(rows(page).first()).toContainText("Specifieke uitgave");
        if (entryId === null) entryId = await rows(page).first().getAttribute("data-entry-id");
        else await expect(rows(page).first()).toHaveAttribute("data-entry-id", entryId);
    }

    expect(materializations).toBe(3);
    expect(wishlistWrites).toBe(2);
    await expect(rows(page)).toHaveCount(1);
});

test("external Edition choice explicitly refines a Work wish in place", async ({ page }) => {
    await routeDiscovery(page, async (query) => discoveryResponse(query, [externalCandidate()]));
    await page.route(/\/wp-json\/biblio\/v1\/me\/bibliographic-discoveries\/[^/]+\/materializations$/, async (route) => {
        await route.fulfill({
            status: 201,
            contentType: "application/json",
            body: JSON.stringify({ data: {
                work_id: "e2e-work-missing-metadata",
                edition_id: "e2e-edition-missing-metadata",
                reused: true,
            } }),
        });
    });
    await open(page);
    const original = rows(page).filter({ hasText: "The Secret Commonwealth" });
    const originalId = await original.getAttribute("data-entry-id");
    await page.getByRole("button", { name: "Boek toevoegen aan verlanglijst" }).click();
    const dialog = page.getByRole("dialog", { name: "Boek toevoegen aan verlanglijst" });
    await dialog.getByRole("searchbox").fill("The Secret Commonwealth paperback");
    await dialog.getByRole("button", { name: "Zoeken" }).click();
    await dialog.getByRole("button", { name: "Deze specifieke uitgave" }).click();
    await expect(dialog.getByRole("heading", { name: "Algemene wens verfijnen?" })).toBeVisible();
    await dialog.getByRole("button", { name: "Deze uitgave kiezen" }).click();
    await expect(dialog).toHaveCount(0);
    await expect(rows(page).filter({ hasText: "The Secret Commonwealth" }))
        .toHaveAttribute("data-entry-id", originalId);
    await expect(rows(page).filter({ hasText: "The Secret Commonwealth" }))
        .toContainText("Specifieke uitgave");
});

test("a concurrent Work-only wish is reloaded before Edition refinement", async ({ page }) => {
    fixtureAction("wishlist-reset");
    await routeDiscovery(page, async (query) => discoveryResponse(query, [externalCandidate()]));
    await page.route(/\/wp-json\/biblio\/v1\/me\/bibliographic-discoveries\/[^/]+\/materializations$/, async (route) => {
        await route.fulfill({
            status: 201,
            contentType: "application/json",
            body: JSON.stringify({ data: {
                work_id: "e2e-work-missing-metadata",
                edition_id: "e2e-edition-missing-metadata",
                reused: true,
            } }),
        });
    });
    await open(page);

    const workOnly = {
        wishlist_entry_id: "wishlist-entry-stale-race",
        target_type: "work_only",
        work_id: "e2e-work-missing-metadata",
        edition_id: null,
        display_title: "The Secret Commonwealth",
        authors: [],
        created_at: "2026-09-10T12:00:00.000000Z",
        updated_at: "2026-09-10T12:00:00.000000Z",
    };
    let listReads = 0;
    let creates = 0;
    let refinements = 0;
    await page.route(/\/wp-json\/biblio\/v1\/me\/wishlist(?:\/[^/]+)?$/, async (route) => {
        if (route.request().method() === "POST") {
            creates += 1;
            await route.fulfill({
                status: 409,
                contentType: "application/json",
                body: JSON.stringify({
                    code: "biblio_wishlist_intent_conflict",
                    message: "private",
                    data: { status: 409 },
                }),
            });
            return;
        }
        if (route.request().method() === "GET") {
            listReads += 1;
            await route.fulfill({
                status: 200,
                contentType: "application/json",
                body: JSON.stringify({ data: { entries: [workOnly] } }),
            });
            return;
        }
        refinements += 1;
        await route.fulfill({
            status: 200,
            contentType: "application/json",
            body: JSON.stringify({ data: {
                ...workOnly,
                target_type: "edition_specific",
                edition_id: "e2e-edition-missing-metadata",
                updated_at: "2026-09-10T12:01:00.000000Z",
            } }),
        });
    });

    await page.getByRole("button", { name: "Boek toevoegen aan verlanglijst" }).click();
    const dialog = page.getByRole("dialog", { name: "Boek toevoegen aan verlanglijst" });
    await dialog.getByRole("searchbox").fill("The Secret Commonwealth paperback");
    await dialog.getByRole("button", { name: "Zoeken" }).click();
    await dialog.getByRole("button", { name: "Deze specifieke uitgave" }).click();
    await expect(dialog.getByRole("heading", { name: "Algemene wens verfijnen?" })).toBeVisible();
    await expect(dialog.getByRole("heading", { name: "Algemene wens niet toegevoegd" })).toHaveCount(0);
    await dialog.getByRole("button", { name: "Deze uitgave kiezen" }).click();
    await expect(dialog).toHaveCount(0);
    await expect(rows(page)).toHaveCount(1);
    await expect(rows(page).first()).toHaveAttribute("data-entry-id", workOnly.wishlist_entry_id);
    await expect(rows(page).first()).toContainText("Specifieke uitgave");
    expect({ listReads, creates, refinements }).toEqual({
        listReads: 1,
        creates: 1,
        refinements: 1,
    });
});

test("a concurrent refinement reloads state instead of retrying a stale PATCH", async ({ page }) => {
    fixtureAction("wishlist-reset");
    await routeDiscovery(page, async (query) => discoveryResponse(query, [externalCandidate()]));
    await page.route(/\/wp-json\/biblio\/v1\/me\/bibliographic-discoveries\/[^/]+\/materializations$/, async (route) => {
        await route.fulfill({
            status: 201,
            contentType: "application/json",
            body: JSON.stringify({ data: {
                work_id: "e2e-work-missing-metadata",
                edition_id: "e2e-edition-missing-metadata",
                reused: true,
            } }),
        });
    });
    const workOnly = wishlistEntry();
    const concurrentEdition = wishlistEntry({
        target_type: "edition_specific",
        edition_id: "e2e-edition-primary",
        updated_at: "2026-09-10T12:01:00.000000Z",
    });
    let reads = 0;
    let refinements = 0;
    await page.route(/\/wp-json\/biblio\/v1\/me\/wishlist(?:\/[^/]+)?$/, async (route) => {
        if (route.request().method() === "PATCH") {
            refinements += 1;
            await route.fulfill({
                status: 409,
                contentType: "application/json",
                body: JSON.stringify({
                    code: "biblio_wishlist_intent_conflict",
                    message: "private",
                    data: { status: 409 },
                }),
            });
            return;
        }
        reads += 1;
        await route.fulfill({
            status: 200,
            contentType: "application/json",
            body: JSON.stringify({ data: { entries: reads === 1 ? [workOnly] : [concurrentEdition] } }),
        });
    });

    await open(page);
    await page.getByRole("button", { name: "Boek toevoegen aan verlanglijst" }).click();
    const dialog = page.getByRole("dialog", { name: "Boek toevoegen aan verlanglijst" });
    await dialog.getByRole("searchbox").fill("The Secret Commonwealth paperback");
    await dialog.getByRole("button", { name: "Zoeken" }).click();
    await dialog.getByRole("button", { name: "Deze specifieke uitgave" }).click();
    await dialog.getByRole("button", { name: "Deze uitgave kiezen" }).click();
    await expect(dialog.getByRole("heading", { name: "Controleer je verlanglijst" })).toBeVisible();
    await expect(dialog.getByRole("button", { name: "Opnieuw proberen" })).toHaveCount(0);
    await expect(rows(page)).toHaveCount(1);
    await expect(rows(page).first()).toContainText("Specifieke uitgave");
    expect({ reads, refinements }).toEqual({ reads: 2, refinements: 1 });
});

test("miss, provider failure and expired snapshot remain truthful and recoverable", async ({ page }) => {
    await routeDiscovery(page, async (query) => {
        if (query === "geen resultaat") return discoveryResponse(query, [], "no_results");
        if (query === "tijdelijke fout") return discoveryResponse(query, [], "provider_failure");
        return discoveryResponse(query, [externalCandidate()]);
    });
    await page.route(/\/wp-json\/biblio\/v1\/me\/bibliographic-discoveries\/[^/]+\/materializations$/, async (route) => {
        await route.fulfill({
            status: 409,
            contentType: "application/json",
            body: JSON.stringify({
                code: "biblio_metadata_lookup_snapshot_unavailable",
                message: "private",
                data: { status: 409 },
            }),
        });
    });
    await open(page);
    await page.getByRole("button", { name: "Boek toevoegen aan verlanglijst" }).click();
    const dialog = page.getByRole("dialog", { name: "Boek toevoegen aan verlanglijst" });
    const searchbox = dialog.getByRole("searchbox");

    await searchbox.fill("geen resultaat");
    await dialog.getByRole("button", { name: "Zoeken" }).click();
    await expect(dialog.getByRole("status")).toHaveText("Geen boeken gevonden.");
    await capture(page, "wishlist-provider-miss");
    await searchbox.fill("tijdelijke fout");
    await dialog.getByRole("button", { name: "Zoeken" }).click();
    await expect(dialog.getByRole("status")).toContainText("tijdelijk");
    await capture(page, "wishlist-provider-failure");
    await searchbox.fill("verlopen resultaat");
    await dialog.getByRole("button", { name: "Zoeken" }).click();
    await dialog.getByRole("button", { name: "Deze specifieke uitgave" }).click();
    await expect(dialog.getByRole("alert")).toContainText("verlopen of niet meer beschikbaar");
    await expect(dialog).not.toContainText("private");
    await dialog.getByRole("button", { name: "Nieuwe zoekopdracht" }).click();
    await expect(dialog.getByRole("searchbox")).toBeFocused();
    await expect(dialog.locator(".biblio-ui__wishlist-discovery-result")).toHaveCount(0);
    await expect(dialog.getByRole("status")).toContainText("zoek opnieuw");
});

test("Book Detail adds a specific Edition and refines a Work wish in place", async ({ page }) => {
    fixtureAction("wishlist-reset");
    await routeDiscovery(page, async (query) => localDiscoveryResponse(query, [
        localWorkCandidate({
            candidate_id: "candidate-history-work",
            work_id: "e2e-work-history",
            title: HISTORY_TITLE,
        }),
    ]));
    await page.goto("/mijn-bibliotheek/?library_id=e2e-library-other&item_id=e2e-item-history");
    const action = page.getByRole("button", { name: "Op verlanglijst" });
    await expect(action).toBeVisible();
    await capture(page, "book-detail-wishlist-entrypoint");
    await action.click();
    await expect(page.getByRole("dialog", { name: "Uitgave op verlanglijst zetten" })).toHaveCount(0);
    await expect(action).toHaveText("Staat op verlanglijst");

    fixtureAction("wishlist-reset");
    await page.goto(PAGE);
    await addWork(page, HISTORY_TITLE, HISTORY_TITLE);
    const originalId = await rows(page).first().getAttribute("data-entry-id");
    await page.goto("/mijn-bibliotheek/?library_id=e2e-library-other&item_id=e2e-item-history");
    await page.getByRole("button", { name: "Op verlanglijst" }).click();
    const dialog = page.getByRole("dialog", { name: "Uitgave op verlanglijst zetten" });
    await expect(dialog.getByRole("heading", { name: "Algemene wens verfijnen?" })).toBeVisible();
    await dialog.getByRole("button", { name: "Deze uitgave kiezen" }).click();
    await expect(dialog).toHaveCount(0);
    await page.goto(PAGE);
    await expect(rows(page)).toHaveCount(1);
    await expect(rows(page).first()).toHaveAttribute("data-entry-id", originalId);
    await expect(rows(page).first()).toContainText("Specifieke uitgave");
});

test("Book Detail reconciles concurrent add and refinement conflicts from server truth", async ({ page }) => {
    fixtureAction("wishlist-reset");
    const workOnly = wishlistEntry({
        wishlist_entry_id: "wishlist-entry-detail-race",
        work_id: "e2e-work-history",
        display_title: HISTORY_TITLE,
    });
    const concurrentEdition = wishlistEntry({
        wishlist_entry_id: workOnly.wishlist_entry_id,
        target_type: "edition_specific",
        work_id: "e2e-work-history",
        edition_id: "e2e-edition-history-other",
        display_title: HISTORY_TITLE,
        updated_at: "2026-09-10T12:01:00.000000Z",
    });
    let reads = 0;
    let creates = 0;
    let refinements = 0;
    await page.route(/\/wp-json\/biblio\/v1\/me\/wishlist(?:\/[^/]+)?$/, async (route) => {
        if (route.request().method() === "POST") {
            creates += 1;
            await route.fulfill({
                status: 409,
                contentType: "application/json",
                body: JSON.stringify({
                    code: "biblio_wishlist_intent_conflict",
                    message: "private",
                    data: { status: 409 },
                }),
            });
            return;
        }
        if (route.request().method() === "PATCH") {
            refinements += 1;
            await route.fulfill({
                status: 409,
                contentType: "application/json",
                body: JSON.stringify({
                    code: "biblio_wishlist_intent_conflict",
                    message: "private",
                    data: { status: 409 },
                }),
            });
            return;
        }
        reads += 1;
        const entries = reads === 1 ? [] : reads === 2 ? [workOnly] : [concurrentEdition];
        await route.fulfill({
            status: 200,
            contentType: "application/json",
            body: JSON.stringify({ data: { entries } }),
        });
    });

    await page.goto("/mijn-bibliotheek/?library_id=e2e-library-other&item_id=e2e-item-history");
    const action = page.getByRole("button", { name: "Op verlanglijst" });
    await action.click();
    const dialog = page.getByRole("dialog", { name: "Uitgave op verlanglijst zetten" });
    await expect(dialog.getByRole("heading", { name: "Algemene wens verfijnen?" })).toBeVisible();
    await expect(dialog).not.toContainText("Er staan al specifieke uitgaven");
    await dialog.getByRole("button", { name: "Deze uitgave kiezen" }).click();
    await expect(dialog.getByRole("heading", { name: "Controleer je verlanglijst" })).toBeVisible();
    await expect(dialog.getByRole("button", { name: "Opnieuw proberen" })).toHaveCount(0);
    expect({ reads, creates, refinements }).toEqual({ reads: 3, creates: 1, refinements: 1 });
});

test("failed load and failed remove remain explicit and preserve the visible entry", async ({ page }) => {
    await page.route("**/wp-json/biblio/v1/me/wishlist", async (route) => {
        await route.fulfill({ status: 503, contentType: "application/json", body: JSON.stringify({
            code: "rest_unavailable",
            message: "unsafe",
            data: { status: 503 },
        }) });
    });
    await page.goto(PAGE);
    await expect(page.getByRole("alert")).toContainText("Dat lukte niet");
    await page.unroute("**/wp-json/biblio/v1/me/wishlist");
    await page.getByRole("button", { name: "Opnieuw proberen" }).click();
    await expect(rows(page)).toHaveCount(3);

    await page.route("**/wp-json/biblio/v1/me/wishlist/*", async (route) => {
        if (route.request().method() === "DELETE") {
            await route.fulfill({ status: 503, contentType: "application/json", body: JSON.stringify({
                code: "rest_unavailable",
                message: "unsafe",
                data: { status: 503 },
            }) });
            return;
        }
        await route.continue();
    });
    const target = rows(page).first();
    const id = await target.getAttribute("data-entry-id");
    await target.getByRole("button", { name: /verwijderen/ }).click();
    await expect(page.locator(`[data-entry-id="${id}"]`)).toContainText("De wens staat nog op je lijst");
    await expect(rows(page)).toHaveCount(3);
});

test("ownership, Library switching, keyboard focus and 200% reflow stay safe", async ({ page, browser, baseURL }) => {
    await routeDiscovery(page, async (query) => discoveryResponse(query, [externalCandidate()]));
    await page.route(/\/wp-json\/biblio\/v1\/me\/bibliographic-discoveries\/[^/]+\/materializations$/, async (route) => {
        await route.fulfill({
            status: 201,
            contentType: "application/json",
            body: JSON.stringify({ data: {
                work_id: "e2e-work-missing-metadata",
                edition_id: "e2e-edition-missing-metadata",
                reused: true,
            } }),
        });
    });
    await page.setViewportSize({ width: 720, height: 900 });
    await open(page);
    await expectNoHorizontalOverflow(page);
    await page.getByRole("button", { name: "Boek toevoegen aan verlanglijst" }).click();
    let dialog = page.getByRole("dialog", { name: "Boek toevoegen aan verlanglijst" });
    await dialog.getByRole("searchbox").fill("The Dispossessed");
    await dialog.getByRole("searchbox").press("Enter");
    await expect(dialog.getByRole("status")).toHaveText("1 resultaat gevonden.");
    await expect(dialog.locator(".biblio-ui__wishlist-result-metadata")).toContainText("Harper Perennial");
    await expectNoHorizontalOverflow(page);
    await capture(page, "wishlist-200-percent-reflow-equivalent");
    const keyboardChoice = dialog.getByRole("button", { name: "Deze specifieke uitgave" });
    await keyboardChoice.focus();
    await page.keyboard.press("Enter");
    await expect(dialog.getByRole("heading", { name: "Algemene wens verfijnen?" })).toBeVisible();
    await dialog.getByRole("button", { name: "Algemene wens behouden" }).click();

    for (const width of [1440, 1024, 768]) {
        await page.setViewportSize({ width, height: 900 });
        await expectNoHorizontalOverflow(page);
        await expect(page.getByRole("button", { name: "Boek toevoegen aan verlanglijst" })).toBeVisible();
    }

    await page.setViewportSize({ width: 390, height: 844 });
    await expectNoHorizontalOverflow(page);
    const mobileRow = rows(page).first();
    const mobileIdentityWidth = await mobileRow.locator(".biblio-ui__wishlist-identity")
        .evaluate((element) => element.getBoundingClientRect().width);
    expect(mobileIdentityWidth).toBeGreaterThan(250);
    await page.getByRole("button", { name: "Boek toevoegen aan verlanglijst" }).focus();
    await page.keyboard.press("Enter");
    dialog = page.getByRole("dialog", { name: "Boek toevoegen aan verlanglijst" });
    await expect(dialog.getByRole("searchbox")).toBeFocused();
    await dialog.getByRole("searchbox").fill("The Dispossessed");
    await dialog.getByRole("searchbox").press("Enter");
    await expect(dialog.getByRole("status")).toHaveText("1 resultaat gevonden.");
    await expect(dialog.getByText("Paperback", { exact: true })).toBeVisible();
    await expect(dialog.getByRole("button", { name: "Uitgave maakt niet uit" })).toBeVisible();
    const mobileEditionChoice = dialog.getByRole("button", { name: "Deze specifieke uitgave" });
    await expect(mobileEditionChoice).toBeVisible();
    const mobileDialogFits = await dialog.evaluate((element) => (
        element.scrollWidth <= element.clientWidth
    ));
    expect(mobileDialogFits).toBe(true);
    await expectNoHorizontalOverflow(page);
    await capture(page, "wishlist-mobile-390");
    await mobileEditionChoice.scrollIntoViewIfNeeded();
    await mobileEditionChoice.focus();
    await expect(mobileEditionChoice).toBeFocused();
    await dialog.getByRole("searchbox").focus();
    await page.keyboard.press("Escape");
    await expect(dialog.getByRole("searchbox")).toHaveValue("");
    await page.keyboard.press("Escape");
    await expect(dialog).toHaveCount(0);
    await expect(page.getByRole("button", { name: "Boek toevoegen aan verlanglijst" })).toBeFocused();

    const before = await rows(page).evaluateAll((items) => items.map((item) => item.dataset.entryId).sort());
    await page.goto(`${PAGE}?library_id=e2e-library-other`);
    await expect(rows(page)).toHaveCount(3);
    const after = await rows(page).evaluateAll((items) => items.map((item) => item.dataset.entryId).sort());
    expect(after).toEqual(before);

    const context = await browser.newContext({ baseURL, ignoreHTTPSErrors: true });
    const other = await context.newPage();
    const redirect = encodeURIComponent(`${baseURL}${PAGE}`);
    await other.goto(`/wp-login.php?redirect_to=${redirect}`);
    await other.locator("#user_login").fill(process.env.BIBLIO_E2E_OTHER_USERNAME);
    await other.locator("#user_pass").fill(process.env.BIBLIO_E2E_OTHER_PASSWORD);
    await other.locator("#wp-submit").click();
    await expect(other.getByRole("heading", { level: 1, name: "Verlanglijst" })).toBeVisible();
    await expect(other.locator(".biblio-ui__wishlist-row")).toHaveCount(1);
    await expect(other.getByText("Ripper", { exact: true })).toBeVisible();
    await expect(other.getByText(HISTORY_TITLE, { exact: true })).toHaveCount(0);
    await context.close();
});
