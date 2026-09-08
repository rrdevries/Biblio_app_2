import { expect, test } from "@playwright/test";
import { mkdir } from "node:fs/promises";

const ACTOR_LIBRARY = "e2e-library-actor";
const OTHER_LIBRARY = "e2e-library-other";
const ACTOR_ITEM = "e2e-item-primary";
const OTHER_ITEM = "e2e-item-primary-other-library";

function detailUrl(libraryId, itemId) {
    return `/mijn-bibliotheek/?library_id=${libraryId}&item_id=${itemId}`;
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
    const directory = ".local/book-api-03-screenshots/after";
    await mkdir(directory, { recursive: true });
    await page.screenshot({
        animations: "disabled",
        fullPage: true,
        path: `${directory}/${name}.png`,
    });
}

test("BOOK-API-03 renders only the current Library assessment projection", async ({ page }) => {
    const assessmentRequests = [];
    page.on("request", (request) => {
        if (request.url().includes("/assessments")) {
            assessmentRequests.push(request.url());
        }
    });

    await page.setViewportSize({ width: 1440, height: 1000 });
    await page.goto(detailUrl(ACTOR_LIBRARY, ACTOR_ITEM));

    const section = page.locator("#beoordelingen");
    await expect(page.getByRole("heading", {
        level: 1,
        name: "Dagboek van een slecht jaar",
    })).toBeVisible();
    await expect(section.getByRole("heading", {
        level: 2,
        name: "Beoordelingen",
    })).toBeVisible();
    await expect(section.locator(".biblio-ui__assessment-summary"))
        .toHaveText("3,5 van 5 · 1 beoordeling");
    await expect(section.locator(".biblio-ui__assessment")).toHaveCount(6);
    await expect(section.locator("blockquote")).toHaveCount(3);
    await expect(section).toContainText("Zonder sterren, wel met een rustige observatie.");
    await expect(section).toContainText("Een tweede leesronde");
    await expect(section).toContainText("Een bedachtzame eerste beoordeling");
    await expect(section).toContainText("<script> als zichtbare tekst");
    await expect(section).not.toContainText("E2E OWN PRIVATE REVIEW MUST NEVER LEAK");
    await expect(section).not.toContainText("E2E OTHER PRIVATE REVIEW MUST NEVER LEAK");
    await expect(section).not.toContainText("Alleen gepubliceerd in de andere Library");
    await expect(section).not.toContainText("E2E WITHDRAWN REVIEW MUST NEVER LEAK");
    await expect(section.locator("script, [onclick], [onerror]")).toHaveCount(0);
    await expect(section.locator("button, a")).toHaveCount(0);
    await expect(page.getByRole("navigation", { name: "Boeksecties" })
        .getByRole("link", { name: "Beoordelingen" })).toHaveAttribute(
        "href",
        "#beoordelingen"
    );
    expect(assessmentRequests).toEqual([]);
    await expectNoHorizontalOverflow(page);
    await capture(page, "book-detail-assessments-1440");

    await page.setViewportSize({ width: 1024, height: 900 });
    await expectNoHorizontalOverflow(page);
    await capture(page, "book-detail-assessments-1024");

    await page.setViewportSize({ width: 390, height: 844 });
    await expectNoHorizontalOverflow(page);
    await expect(section.locator(".biblio-ui__assessment-review").first()).toHaveCSS(
        "white-space",
        "pre-wrap"
    );
    await capture(page, "book-detail-assessments-390");

    await page.setViewportSize({ width: 720, height: 900 });
    await expectNoHorizontalOverflow(page);
    await capture(page, "book-detail-assessments-200-percent-reflow-equivalent");

    await page.goto(detailUrl(OTHER_LIBRARY, OTHER_ITEM));
    const otherSection = page.locator("#beoordelingen");
    await expect(otherSection.locator(".biblio-ui__assessment")).toHaveCount(3);
    await expect(otherSection).toContainText("Alleen gepubliceerd in de andere Library");
    await expect(otherSection).toContainText("Publicatie uitsluitend in de andere Library");
    await expect(otherSection).not.toContainText("Een bedachtzame eerste beoordeling");
    await expect(otherSection).not.toContainText("E2E HIDDEN REVIEW MUST NEVER LEAK");
    await expectNoHorizontalOverflow(page);
});
