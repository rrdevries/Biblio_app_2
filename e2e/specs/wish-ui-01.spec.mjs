import { execFileSync } from "node:child_process";
import { mkdir } from "node:fs/promises";
import { expect, test } from "@playwright/test";

const PAGE = "/verlanglijst/";
const HISTORY_TITLE = "E2E Leesgeschiedenis";

function fixtureAction(action) {
    execFileSync("./scripts/e2e-fixture.sh", [action], {
        cwd: process.cwd(),
        encoding: "utf8",
    });
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
    await dialog.getByRole("searchbox", { name: "Zoek op titel of auteur" }).fill(query);
    await dialog.getByRole("button", { name: "Zoeken" }).click();
    await expect(dialog.getByRole("status")).toContainText("gevonden");
    await dialog.getByRole("button", { name: title, exact: true }).click();
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
    const directory = ".local/wish-ui-01-screenshots/after";
    await mkdir(directory, { recursive: true });
    await page.screenshot({
        animations: "disabled",
        fullPage: true,
        path: `${directory}/${name}.png`,
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
    await open(page);
    await expect(page.getByRole("heading", { level: 2, name: "Je verlanglijst is nog leeg" })).toBeVisible();
    await capture(page, "wishlist-empty-desktop");

    await page.getByRole("button", { name: "Boek toevoegen aan verlanglijst" }).click();
    const searchDialog = page.getByRole("dialog", { name: "Boek toevoegen aan verlanglijst" });
    await searchDialog.getByRole("searchbox", { name: "Zoek op titel of auteur" }).fill("The Secret Commonwealth");
    await searchDialog.getByRole("button", { name: "Zoeken" }).click();
    await expect(searchDialog.getByRole("status")).toContainText("gevonden");
    await capture(page, "wishlist-add-work-search");
    await searchDialog.getByRole("button", { name: "The Secret Commonwealth", exact: true }).click();
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

test("reverse Work-only conflict is product copy and never deletes two Edition wishes", async ({ page }) => {
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

test("Book Detail adds a specific Edition and refines a Work wish in place", async ({ page }) => {
    fixtureAction("wishlist-reset");
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
    await page.setViewportSize({ width: 720, height: 900 });
    await open(page);
    await expectNoHorizontalOverflow(page);
    await capture(page, "wishlist-200-percent-reflow-equivalent");

    for (const width of [1440, 1024, 768]) {
        await page.setViewportSize({ width, height: 900 });
        await expectNoHorizontalOverflow(page);
        await expect(page.getByRole("button", { name: "Boek toevoegen aan verlanglijst" })).toBeVisible();
    }

    await page.setViewportSize({ width: 390, height: 844 });
    await expectNoHorizontalOverflow(page);
    await capture(page, "wishlist-mobile-390");
    await page.getByRole("button", { name: "Boek toevoegen aan verlanglijst" }).focus();
    await page.keyboard.press("Enter");
    const dialog = page.getByRole("dialog", { name: "Boek toevoegen aan verlanglijst" });
    await expect(dialog.getByRole("searchbox")).toBeFocused();
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
