import { expect, test } from "@playwright/test";

test("Catalogus archive reset respects both stored preferences and preserves the other query state", async ({ page }) => {
    const errors = [];
    const preferenceWrites = [];
    const catalogRequests = [];
    const catalogErrors = [];
    page.on("pageerror", error => errors.push(error.message));
    page.on("request", request => {
        if (request.method() !== "GET" && request.url().includes("/preferences")) preferenceWrites.push(request.url());
        if (new URL(request.url()).pathname.endsWith("/catalog")) catalogRequests.push(new URL(request.url()));
    });
    page.on("response", response => {
        if (new URL(response.url()).pathname.endsWith("/catalog") && !response.ok()) catalogErrors.push(response.status());
    });
    await page.goto("/wp-login.php");
    await page.locator("#user_login").fill("biblio_entry_e2e_owner");
    await page.locator("#user_pass").fill(process.env.BIBLIO_E2E_ACTOR_PASSWORD);
    await page.locator("#wp-submit").click();
    await page.getByRole("textbox", { name: "Bibliotheeknaam", exact: true }).fill("Catalogus testbibliotheek");
    await page.getByRole("button", { name: "Opslaan en verder" }).click();
    await page.getByRole("link", { name: "Catalogus testbibliotheek", exact: true }).click();
    const id = new URL(page.url()).searchParams.get("library_id");
    expect(id).toBeTruthy();
    const catalog = `/mijn-bibliotheek/?library_id=${id}`;
    const filters = async () => {
        const archive = page.getByRole("checkbox", { name: "Ook in archief zoeken" });
        if (!await archive.isVisible()) await page.getByRole("button", { name: /^Filters(?: \(\d+\))?$/ }).click();
        return archive;
    };
    for (const stored of [false, true]) {
        await page.goto(`/instellingen/?library_id=${id}`);
        const field = page.locator("fieldset").nth(1);
        const setting = field.getByRole("switch", { name: "Archief tonen" });
        if (await setting.isChecked() !== stored) {
            await setting.setChecked(stored);
            await expect(field.getByRole("status")).toHaveText("Opgeslagen");
        }
        await page.goto(catalog);
        await expect(page.getByRole("heading", { name: "Catalogus testbibliotheek", exact: true })).toBeVisible();
        const config = await page.locator("[data-biblio-ui-root]").evaluate(root => ({ nonce: root.dataset.restNonce, restRoot: root.dataset.restRoot }));
        const response = await page.request.get(`${config.restRoot}libraries/${id}/preferences`, { headers: { "X-WP-Nonce": config.nonce } });
        expect(response.ok()).toBe(true);
        const preferences = (await response.json()).data.preferences;
        expect(preferences.catalog_archive_visible.effective).toBe(stored);
        await page.evaluate(({ id, config, preferences, stored }) => {
            const scope = encodeURIComponent(`${config.nonce}:${id}:mijn-bibliotheek`);
            const state = preferences.catalog_archive_visible;
            sessionStorage.setItem(`biblio.catalog.presentation.${scope}.archive`, JSON.stringify({ value: !stored, stamp: JSON.stringify([state.version, state.value, state.effective, state.source, state.default_version ?? null]) }));
        }, { id, config, preferences, stored });
        await page.goto(`${catalog}&catalog_search=Dune&catalog_reading_status=not_read&catalog_sort=author&catalog_archive=${stored ? "active_only" : "active_and_archived"}`);
        await expect(await filters()).toBeChecked({ checked: stored });
        const search = page.getByRole("searchbox", { name: "Zoeken in deze bibliotheek" });
        await expect(search).toHaveValue("Dune");
        await page.getByRole("button", { name: "Lijst", exact: true }).click();
        const beforeTemporary = preferenceWrites.length;
        await (await filters()).setChecked(!stored);
        await expect(await filters()).toBeChecked({ checked: !stored });
        expect(new URL(page.url()).searchParams.has("catalog_archive")).toBe(false);
        await page.reload();
        await expect(await filters()).toBeChecked({ checked: stored });
        expect(catalogRequests.at(-1).searchParams.get("archive_scope")).toBe(stored ? "active_and_archived" : null);
        await expect(search).toHaveValue("Dune");
        await expect(page.getByRole("combobox", { name: "Sorteren" })).toHaveValue("author");
        await expect(page.getByRole("button", { name: "Lijst", exact: true })).toHaveAttribute("aria-pressed", "true");
        await expect(page.getByRole("checkbox", { name: "Niet gelezen", exact: true })).toBeChecked();
        await page.getByRole("search").getByRole("button", { name: "Zoekopdracht wissen", exact: true }).focus();
        await page.keyboard.press("Enter");
        await expect(search).toHaveValue("");
        await expect(search).toBeFocused();
        await expect(page.getByRole("checkbox", { name: "Niet gelezen", exact: true })).toBeChecked();
        await search.fill("Foundation");
        await expect.poll(() => new URL(page.url()).searchParams.get("catalog_search")).toBe("Foundation");
        await page.getByLabel("Actieve filters", { exact: true }).getByRole("button", { name: "Alle filters wissen", exact: true }).click();
        await expect(search).toHaveValue("Foundation");
        await expect(page.getByRole("checkbox", { name: "Niet gelezen", exact: true })).not.toBeChecked();
        await page.goto("/mijn-biblio/");
        await page.goto(catalog);
        await expect(await filters()).toBeChecked({ checked: stored });
        expect(preferenceWrites.length).toBe(beforeTemporary);
        await page.getByRole("button", { name: /^Filters(?: \(\d+\))?$/ }).click();
        await page.screenshot({ path: `project/bewijs/V2001-CATALOG-04-archive-${stored ? "aan" : "uit"}-desktop.png`, fullPage: true, animations: "disabled" });
    }
    await page.setViewportSize({ width: 400, height: 756 });
    expect(await page.evaluate(() => document.documentElement.scrollWidth <= innerWidth)).toBe(true);
    await filters();
    await expect(page.getByRole("button", { name: "Filters sluiten" })).toBeFocused();
    await page.screenshot({ path: "project/bewijs/V2001-CATALOG-04-mobiel-filterblad.png", fullPage: true, animations: "disabled" });
    await page.keyboard.press("Escape");
    await expect(page.getByRole("button", { name: /^Filters(?: \(\d+\))?$/ })).toBeFocused();
    expect(errors).toEqual([]);
    expect(catalogErrors).toEqual([]);
});
