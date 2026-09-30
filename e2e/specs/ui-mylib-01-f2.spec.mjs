import { expect, test } from "@playwright/test";

const LIBRARY_URL = "/mijn-bibliotheek/?library_id=e2e-library-actor";

test("List eye stays at the row edge and filtered empty results stay close to chips", async ({ page }, testInfo) => {
    await page.setViewportSize({ width: 1440, height: 900 });
    await page.goto(LIBRARY_URL);
    const list = page.locator(".biblio-ui__catalog-list");
    await expect(list.locator(".biblio-ui__catalog-item")).toHaveCount(24);
    await page.getByRole("button", { name: "Lijst" }).click();
    await expect(list).toHaveAttribute("data-catalog-view", "list");

    for (const filtersOpen of [false, true]) {
        if (filtersOpen) {
            await page.getByRole("button", { name: "Filters", exact: true }).click();
            await expect(page.locator("#biblio-filter-panel")).toBeVisible();
        }
        const first = list.locator(".biblio-ui__catalog-item").first();
        const eye = first.getByRole("button", { name: /Snel bekijken/ });
        await expect(first.locator(".biblio-ui__book-title")).toBeVisible();
        await expect(first.locator(".biblio-ui__authors")).toBeVisible();
        const geometry = await page.evaluate(() => {
            const row = document.querySelector(".biblio-ui__catalog-list .biblio-ui__catalog-item");
            const button = row.querySelector(".biblio-ui__quick-view-trigger");
            const icon = button.querySelector(".biblio-ui__icon");
            return {
                artifact: Array.from(document.querySelectorAll(".biblio-ui__catalog-list .biblio-ui__quick-view-trigger"))
                    .some((trigger) => getComputedStyle(trigger, "::before").content !== "none"),
                buttonLeft: button.getBoundingClientRect().left,
                rowRight: row.getBoundingClientRect().right,
                authorRight: row.querySelector(".biblio-ui__authors").getBoundingClientRect().right,
                iconMask: getComputedStyle(icon).maskImage,
                defaultColor: getComputedStyle(button).color,
                minTarget: button.getBoundingClientRect().width,
            };
        });
        expect(geometry.artifact).toBe(false);
        expect(geometry.rowRight - geometry.buttonLeft).toBeLessThanOrEqual(48);
        expect(geometry.buttonLeft).toBeGreaterThan(geometry.authorRight);
        expect(geometry.iconMask).toContain("tabler-eye.svg");
        expect(geometry.defaultColor).toBe("rgb(77, 83, 95)");
        expect(geometry.minTarget).toBeGreaterThanOrEqual(44);
        await page.screenshot({ animations: "disabled", path: testInfo.outputPath(`list-${filtersOpen ? "rail-open" : "rail-closed"}-default.png`) });

        await eye.hover();
        await expect(eye).toHaveCSS("color", "rgb(23, 43, 67)");
        await first.locator(".biblio-ui__book-link").focus();
        await page.keyboard.press("Tab");
        await expect(eye).toBeFocused();
        await expect(eye).toHaveCSS("outline-style", "solid");
        await expect(eye).toHaveCSS("color", "rgb(23, 43, 67)");
        await page.screenshot({ animations: "disabled", path: testInfo.outputPath(`list-${filtersOpen ? "rail-open" : "rail-closed"}.png`) });
    }

    await list.locator(".biblio-ui__catalog-item").first()
        .getByRole("button", { name: /Snel bekijken/ }).press("Enter");
    const quickView = page.locator("dialog.biblio-ui__quick-view");
    await expect(quickView).toBeVisible();
    await expect(quickView.getByRole("link", { name: "Volledige boekdetails" })).toBeVisible();
    await page.getByRole("button", { name: "Snel bekijken sluiten" }).click();
    await expect(quickView).toHaveCount(0);

    await page.getByRole("searchbox", { name: "Zoeken in deze bibliotheek" }).fill("Dagboek");
    await expect(list.locator(".biblio-ui__catalog-item")).toHaveCount(1);
    const reading = page.getByRole("group", { name: "Leesstatus" })
        .getByRole("checkbox", { name: "Uitgelezen" });
    await reading.check();
    await expect(reading).toBeChecked();
    const chips = page.locator(".biblio-ui__catalog-main .biblio-ui__filter-chips");
    const empty = page.locator(".biblio-ui__catalog-main .biblio-ui__empty-state");
    await expect(chips).toBeVisible();
    await expect(chips.getByRole("button", { name: /Uitgelezen/ })).toHaveCount(1);
    await expect(empty.getByRole("heading", { name: "Geen boeken gevonden" })).toBeVisible();
    await expect(empty).toContainText("Geen boeken passen bij deze zoekopdracht en filters.");
    const spacing = await page.evaluate(() => {
        const main = document.querySelector(".biblio-ui__catalog-main");
        const chips = main.querySelector(".biblio-ui__filter-chips");
        const empty = main.querySelector(".biblio-ui__empty-state");
        return {
            between: empty.getBoundingClientRect().top - chips.getBoundingClientRect().bottom,
            gap: getComputedStyle(main).rowGap,
            alignment: getComputedStyle(main).alignContent,
            topPadding: getComputedStyle(empty).paddingTop,
            background: getComputedStyle(empty).backgroundColor,
        };
    });
    expect(spacing.between).toBeLessThanOrEqual(16);
    expect(spacing.gap).toBe("16px");
    expect(spacing.alignment).toBe("start");
    expect(spacing.topPadding).toBe("16px");
    expect(spacing.background).toBe("rgba(0, 0, 0, 0)");
    await page.screenshot({ animations: "disabled", path: testInfo.outputPath("empty-one-filter.png") });

    await page.locator("#biblio-filter-panel").getByRole("button", { name: "Sluiten" }).click();
    await page.setViewportSize({ width: 390, height: 844 });
    await expect(empty.getByRole("heading", { name: "Geen boeken gevonden" })).toBeVisible();
    await expect(chips).toBeVisible();
    const widths = await page.evaluate(() => ({
        body: document.body.scrollWidth,
        viewport: window.innerWidth,
    }));
    expect(widths.body).toBeLessThanOrEqual(widths.viewport);

    await page.getByRole("button", { name: "Alle filters wissen" }).first().click();
    await page.getByRole("searchbox", { name: "Zoeken in deze bibliotheek" }).fill("");
    await expect(list.locator(".biblio-ui__catalog-item")).toHaveCount(24);
    await page.getByRole("button", { name: "Lijst" }).click();
    await expect(list).toHaveAttribute("data-catalog-view", "list");
    const mobile = await page.evaluate(() => ({
        artifact: Array.from(document.querySelectorAll(".biblio-ui__catalog-list .biblio-ui__quick-view-trigger"))
            .some((trigger) => getComputedStyle(trigger, "::before").content !== "none"),
        overflow: document.body.scrollWidth > window.innerWidth,
    }));
    expect(mobile.artifact).toBe(false);
    expect(mobile.overflow).toBe(false);
});
