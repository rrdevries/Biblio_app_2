import { execFileSync } from "node:child_process";
import { expect, test } from "@playwright/test";

const PAGE = "/hierna-lezen/";
const TITLE = "Dagboek van een slecht jaar";

function fixtureAction(action) {
    execFileSync("./scripts/e2e-fixture.sh", [action], {
        cwd: process.cwd(),
        encoding: "utf8",
    });
}

function rows(page) {
    return page.locator(".biblio-ui__next-reading-row");
}

async function open(page) {
    await page.goto(PAGE);
    await expect(page.getByRole("heading", { level: 1, name: "Hierna lezen", exact: true })).toBeVisible();
    await expect(page.locator(".biblio-ui__loading")).toHaveCount(0);
}

async function addBook(page, sourceLabel = null) {
    await page.getByRole("button", { name: "Boek toevoegen" }).click();
    const dialog = page.getByRole("dialog", { name: "Boek toevoegen" });
    await dialog.getByRole("searchbox", { name: "Zoek op titel" }).fill("Dagboek van een slecht jaar");
    await dialog.getByRole("button", { name: "Zoeken" }).click();
    await expect(dialog.getByRole("status")).toContainText("boeken gevonden");
    await dialog.getByRole("button", { name: TITLE }).click();
    if (sourceLabel !== null) {
        await dialog.getByRole("radio", { name: sourceLabel }).check();
    } else {
        await dialog.getByRole("radio", { name: "Geen voorkeursbron" }).check();
    }
    await dialog.getByRole("button", { name: "Toevoegen" }).click();
    await expect(dialog).toHaveCount(0);
}

async function restAdd(page) {
    return page.locator("[data-biblio-next-reading-root]").evaluate(async (mount) => {
        const response = await fetch(`${mount.dataset.restRoot}me/next-reading`, {
            method: "POST",
            credentials: "same-origin",
            headers: {
                Accept: "application/json",
                "Content-Type": "application/json",
                "X-WP-Nonce": mount.dataset.restNonce,
            },
            body: JSON.stringify({
                work_id: "e2e-work-primary",
                preferred_source: null,
            }),
        });
        return response.status;
    });
}

async function expectNoHorizontalOverflow(page) {
    const overflow = await page.evaluate(() => ({
        document: document.documentElement.scrollWidth - window.innerWidth,
        body: document.body.scrollWidth - window.innerWidth,
    }));
    expect(overflow.document).toBeLessThanOrEqual(0);
    expect(overflow.body).toBeLessThanOrEqual(0);
}

test.describe.configure({ mode: "serial" });

test("seeded owner list preserves duplicates and safely projects every source state", async ({ page }) => {
    await open(page);
    await expect(rows(page)).toHaveCount(5);
    await expect(rows(page).filter({ hasText: TITLE })).toHaveCount(4);
    await expect(page.getByText("Geen voorkeursbron", { exact: true })).toHaveCount(2);
    await expect(page.getByText("Bibliotheekexemplaar", { exact: true })).toBeVisible();
    await expect(page.getByText("Externe lening", { exact: true })).toBeVisible();
    const unavailable = rows(page).filter({ hasText: "C7 Verdwenen bron" });
    await expect(unavailable).toContainText("Voorkeursbron niet beschikbaar");
    await expect(unavailable).not.toContainText("e2e-item-c7-unavailable");
    await expect(rows(page).locator("a")).toHaveCount(0);

    fixtureAction("next-reading-reset");
    await page.reload();
    await expect(page.getByText("Nog niets gepland om hierna te lezen.", { exact: true })).toBeVisible();
    await expect(rows(page)).toHaveCount(0);
});

test("Work-first add supports no source, Item, Loan and fully identical duplicates", async ({ page }) => {
    await open(page);
    await addBook(page);
    await expect(rows(page)).toHaveCount(1);
    await expect(rows(page).first()).toContainText("Geen voorkeursbron");

    await addBook(page, "Exemplaar uit E2E Privébibliotheek");
    await expect(rows(page)).toHaveCount(2);
    await expect(rows(page).nth(1)).toContainText("Bibliotheekexemplaar");

    await addBook(page, "Externe lening");
    await addBook(page, "Externe lening");
    await expect(rows(page)).toHaveCount(4);
    await expect(rows(page).filter({ hasText: "Externe lening" })).toHaveCount(2);
    const ids = await rows(page).evaluateAll((items) => items.map((item) => item.dataset.entryId));
    expect(new Set(ids).size).toBe(4);
});

test("Omhoog and Omlaag submit authoritative full reorders with disabled boundaries", async ({ page }) => {
    await open(page);
    const before = await rows(page).evaluateAll((items) => items.map((item) => item.dataset.entryId));
    await expect(rows(page).first().getByRole("button", { name: "Omhoog" })).toBeDisabled();
    await expect(rows(page).last().getByRole("button", { name: "Omlaag" })).toBeDisabled();
    await rows(page).nth(1).getByRole("button", { name: "Omhoog" }).click();
    await expect(rows(page).first()).toHaveAttribute("data-entry-id", before[1]);
    const afterUp = await rows(page).evaluateAll((items) => items.map((item) => item.dataset.entryId));
    expect(afterUp[0]).toBe(before[1]);
    await rows(page).first().getByRole("button", { name: "Omlaag" }).click();
    await expect(rows(page).first()).toHaveAttribute("data-entry-id", before[0]);
    const afterDown = await rows(page).evaluateAll((items) => items.map((item) => item.dataset.entryId));
    expect(afterDown).toEqual(before);
});

test("direct remove opens no confirmation and server Undo restores the exact entry", async ({ page }) => {
    await open(page);
    const target = rows(page).first();
    const entryId = await target.getAttribute("data-entry-id");
    const count = await rows(page).count();
    await target.getByRole("button", { name: "Verwijderen" }).click();
    await expect(page.getByRole("dialog")).toHaveCount(0);
    await expect(rows(page)).toHaveCount(count - 1);
    const undo = page.getByRole("button", { name: "Ongedaan maken" });
    await expect(undo).toBeFocused();
    await undo.click();
    await expect(rows(page)).toHaveCount(count);
    await expect(page.locator(`[data-entry-id="${entryId}"]`)).toBeFocused();
});

test("preferred source can be chosen, changed and cleared without technical labels", async ({ page }) => {
    await open(page);
    const initialTarget = rows(page).filter({ hasText: "Geen voorkeursbron" }).first();
    const targetId = await initialTarget.getAttribute("data-entry-id");
    const target = page.locator(`[data-entry-id="${targetId}"]`);
    await target.getByRole("button", { name: "Voorkeursbron kiezen" }).click();
    let dialog = page.getByRole("dialog", { name: "Voorkeursbron kiezen" });
    await dialog.getByRole("radio", { name: "Externe lening" }).check();
    await dialog.getByRole("button", { name: "Opslaan" }).click();
    await expect(target).toContainText("Externe lening");

    await target.getByRole("button", { name: "Voorkeursbron wijzigen" }).click();
    dialog = page.getByRole("dialog", { name: "Voorkeursbron kiezen" });
    await dialog.getByRole("radio", { name: "Exemplaar uit E2E Privébibliotheek" }).check();
    await dialog.getByRole("button", { name: "Opslaan" }).click();
    await expect(target).toContainText("Bibliotheekexemplaar");
    await target.getByRole("button", { name: "Voorkeur verwijderen" }).click();
    await expect(target).toContainText("Geen voorkeursbron");
    await expect(target).not.toContainText("library_item");
    await expect(target).not.toContainText("external_loan");
});

test("stale concurrent mutation reloads authoritative state without retrying reorder", async ({ page }) => {
    await open(page);
    let reorderPosts = 0;
    page.on("request", (request) => {
        if (request.method() === "POST" && new URL(request.url()).pathname.endsWith("/me/next-reading/reorder")) reorderPosts += 1;
    });
    expect(await restAdd(page)).toBe(201);
    await rows(page).nth(1).getByRole("button", { name: "Omhoog" }).click();
    await expect(page.getByRole("status").first()).toContainText("intussen gewijzigd");
    await expect(rows(page)).toHaveCount(5);
    expect(reorderPosts).toBe(1);
});

test("foreign authenticated user sees only an empty owner list", async ({ browser, baseURL }) => {
    const context = await browser.newContext({ baseURL, ignoreHTTPSErrors: true });
    const page = await context.newPage();
    const redirect = encodeURIComponent(`${baseURL}${PAGE}`);
    await page.goto(`/wp-login.php?redirect_to=${redirect}`);
    await page.locator("#user_login").fill(process.env.BIBLIO_E2E_OTHER_USERNAME);
    await page.locator("#user_pass").fill(process.env.BIBLIO_E2E_OTHER_PASSWORD);
    await page.locator("#wp-submit").click();
    await expect(page.getByText("Nog niets gepland om hierna te lezen.", { exact: true })).toBeVisible();
    await expect(page.getByText(TITLE, { exact: true })).toHaveCount(0);
    await context.close();
});

test("keyboard-only core flow retains visible focus and closes native dialog", async ({ page }) => {
    await open(page);
    await page.getByRole("heading", { level: 1, name: "Hierna lezen", exact: true }).focus();
    await page.keyboard.press("Tab");
    const add = page.getByRole("button", { name: "Boek toevoegen" });
    await expect(add).toBeFocused();
    await page.keyboard.press("Enter");
    const search = page.getByRole("searchbox", { name: "Zoek op titel" });
    await expect(search).toBeFocused();
    await search.fill("Dagboek van een slecht jaar");
    await page.keyboard.press("Tab");
    await page.keyboard.press("Enter");
    const dialog = page.getByRole("dialog", { name: "Boek toevoegen" });
    await expect(dialog.getByRole("status")).toContainText("boeken gevonden");
    await dialog.getByRole("button", { name: TITLE }).focus();
    await page.keyboard.press("Enter");
    await expect(dialog.getByRole("radio", { name: "Geen voorkeursbron" })).toBeFocused();
    await page.keyboard.press("Escape");
    await expect(dialog).toHaveCount(0);
    await expect(add).toBeFocused();
});

test("narrow and 200%-equivalent reflow keep every action reachable without overflow", async ({ page }) => {
    await page.setViewportSize({ width: 640, height: 900 });
    await open(page);
    await expectNoHorizontalOverflow(page);
    const controls = rows(page).first().getByRole("button");
    await expect(controls.first()).toBeVisible();
    await expect(controls.last()).toBeVisible();
    const boxes = await controls.evaluateAll((buttons) => buttons.map((button) => button.getBoundingClientRect()).map(({ left, right, top, bottom }) => ({ left, right, top, bottom })));
    for (const box of boxes) {
        expect(box.left).toBeGreaterThanOrEqual(0);
        expect(box.right).toBeLessThanOrEqual(640);
        expect(box.bottom - box.top).toBeGreaterThanOrEqual(44);
    }
});
