import { expect, test } from "@playwright/test";
import { mkdir } from "node:fs/promises";

const LIBRARY_ID = "e2e-library-other";
const ITEM_ID = "e2e-item-history";
const DETAIL_URL = `/mijn-bibliotheek/?library_id=${LIBRARY_ID}&item_id=${ITEM_ID}`;

async function capture(page, name, locator = null) {
    const directory = ".local/ui-book-01-screenshots/after";
    await mkdir(directory, { recursive: true });
    const target = locator ?? page;
    await target.screenshot({
        animations: "disabled",
        path: `${directory}/${name}.png`,
        ...(locator === null ? { fullPage: true } : {}),
    });
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

test("UI-BOOK-01 composes authoritative Book Detail data across responsive layouts", async ({ page }) => {
    await page.route(`**/libraries/${LIBRARY_ID}/items/${ITEM_ID}`, async (route) => {
        const response = await route.fetch();
        const body = await response.json();
        body.data = {
            ...body.data,
            authors: { state: "known", values: ["E2E Auteur"] },
            isbn: { state: "known", value: "9780306406157" },
            language: { state: "known", value: "Nederlands" },
            publisher: { state: "known", value: "E2E Uitgeverij" },
            publication_date: { state: "known", value: "2026" },
            series: { state: "known", value: "E2E Reeks" },
            location: { state: "known", value: "Kast B" },
            condition: { state: "known", value: "Goed" },
            acquisition: { state: "known", value: "Aankoop" },
            availability: { state: "known", value: "Beschikbaar" },
        };
        await route.fulfill({ response, json: body });
    });

    await page.setViewportSize({ width: 1440, height: 1000 });
    await page.goto(DETAIL_URL);
    const detail = page.locator("[data-biblio-view='detail']");
    const hero = page.locator(".biblio-ui__detail-hero");
    const content = page.locator(".biblio-ui__detail-content");
    const context = page.locator(".biblio-ui__detail-context");
    const subnav = page.getByRole("navigation", { name: "Boeksecties" });
    await expect(detail).toBeVisible();
    await expect(page.getByRole("heading", { name: "E2E Leesgeschiedenis", level: 1 })).toBeVisible();
    await expect(page.getByRole("img", {
        name: "Geen omslag beschikbaar voor E2E Leesgeschiedenis",
    })).toBeVisible();
    await expect(subnav.getByRole("link")).toHaveText([
        "Overzicht",
        "Leesgeschiedenis",
        "Mijn notities",
        "Boekdetails",
        "Uitgave",
        "Exemplaar",
    ]);
    await expect(hero.locator(".biblio-ui__control--primary")).toHaveCount(0);
    await expect(hero.getByRole("button", { name: "Leesronde afronden" })).toBeVisible();
    await expect(page.getByText("Huidige leesronde · gestart op 2 januari 2026")).toBeVisible();
    await expect(page.getByRole("heading", { name: "Leesgeschiedenis", level: 2 })).toBeVisible();
    await expect(page.getByRole("heading", { name: "Privénotities", level: 2 })).toBeVisible();
    await expect(page.getByRole("heading", { name: "Boekdetails", level: 2 })).toBeVisible();
    await expect(page.getByRole("heading", { name: "Uitgave", level: 2 })).toBeVisible();
    await expect(page.getByRole("heading", { name: "Exemplaar", level: 2 })).toBeVisible();
    await expect(page.getByText("9780306406157", { exact: true })).toBeVisible();
    await expect(page.getByText("Kast B", { exact: true })).toBeVisible();
    await expect(page.locator("body")).not.toContainText("Beoordelingen");
    await expect(page.locator("body")).not.toContainText("In collecties");
    await expectNoHorizontalOverflow(page);

    const heroBox = await hero.boundingBox();
    const contentBox = await content.boundingBox();
    const contextBox = await context.boundingBox();
    expect(heroBox?.height ?? 1000).toBeLessThan(420);
    expect(contextBox?.x ?? 0).toBeGreaterThan(contentBox?.x ?? 0);
    await capture(page, "book-detail-1440-no-cover");
    await capture(page, "book-detail-hero", hero);
    await capture(page, "book-detail-overview-history", content);
    await capture(page, "book-detail-private-notes", page.locator("#mijn-notities"));
    await capture(page, "book-detail-edition-item", context);

    await page.setViewportSize({ width: 1024, height: 900 });
    await expectNoHorizontalOverflow(page);
    const desktopContextBox = await context.boundingBox();
    const desktopContentBox = await content.boundingBox();
    expect(desktopContextBox?.x ?? 0).toBeGreaterThan(desktopContentBox?.x ?? 0);
    await capture(page, "book-detail-1024");

    await page.setViewportSize({ width: 768, height: 1024 });
    await expectNoHorizontalOverflow(page);
    const tabletContextBox = await context.boundingBox();
    const tabletContentBox = await content.boundingBox();
    expect(tabletContextBox?.y ?? 0).toBeGreaterThan(tabletContentBox?.y ?? 0);

    await page.setViewportSize({ width: 390, height: 844 });
    await expectNoHorizontalOverflow(page);
    const mobileSubnavWidths = await subnav.evaluate((node) => ({
        client: node.clientWidth,
        scroll: node.scrollWidth,
    }));
    expect(mobileSubnavWidths.scroll).toBeLessThanOrEqual(mobileSubnavWidths.client);
    const mobileCover = await page.getByRole("img", {
        name: "Geen omslag beschikbaar voor E2E Leesgeschiedenis",
    }).boundingBox();
    expect(mobileCover?.width ?? 0).toBeLessThanOrEqual(128);
    await expect(subnav).toBeVisible();
    await hero.getByRole("button", { name: "Leesronde afronden" }).focus();
    await expect(hero.getByRole("button", { name: "Leesronde afronden" })).toBeFocused();
    await capture(page, "book-detail-390");

    // 720 CSS px is the repository's guarded 1440px-at-200%-reflow equivalent.
    await page.setViewportSize({ width: 720, height: 900 });
    await expectNoHorizontalOverflow(page);
    await capture(page, "book-detail-200-percent-reflow-equivalent");

    await page.goto(`/mijn-bibliotheek/?library_id=${LIBRARY_ID}`);
    await expect(page.getByRole("heading", { name: "Mijn Bibliotheek" })).toBeVisible();
    await expectNoHorizontalOverflow(page);
    await capture(page, "mijn-bibliotheek-shell-regression");
});
