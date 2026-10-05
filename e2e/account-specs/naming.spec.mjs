import { expect, test } from "@playwright/test";

const login = async (page, username = "biblio_entry_e2e_owner", destination = "/mijn-biblio/") => {
    await page.goto(`/wp-login.php?redirect_to=${encodeURIComponent(process.env.BIBLIO_E2E_BASE_URL + destination)}`);
    await page.locator("#user_login").fill(username);
    await page.locator("#user_pass").fill(process.env.BIBLIO_E2E_ACTOR_PASSWORD);
    await page.locator("#wp-submit").click();
};

test("first naming, interruption, exact destination, cancel, rename and stable ID", async ({ page, browser }) => {
    await login(page, "biblio_entry_e2e_owner", "/verlanglijst/");
    await expect(page.getByRole("heading", { name: "Geef je bibliotheek een naam" })).toBeVisible();
    await expect(page.getByRole("textbox", { name: "Bibliotheeknaam", exact: true })).toHaveValue("");
    await page.getByRole("textbox", { name: "Bibliotheeknaam", exact: true }).fill("Onderbroken naam");
    await page.getByRole("link", { name: "Uitloggen" }).click();
    await expect(page.getByRole("link", { name: "Inloggen", exact: true })).toBeVisible();
    await login(page);
    await expect(page.getByRole("textbox", { name: "Bibliotheeknaam", exact: true })).toHaveValue("");
    await page.screenshot({ path: "project/bewijs/V2001-ENTRY-05-eerste-naam-desktop.png", fullPage: true });
    await page.setViewportSize({ width: 400, height: 756 });
    expect(await page.evaluate(() => document.documentElement.scrollWidth <= innerWidth)).toBe(true);
    await page.screenshot({ path: "project/bewijs/V2001-ENTRY-05-eerste-naam-mobiel.png", fullPage: true });
    await page.getByRole("textbox", { name: "Bibliotheeknaam", exact: true }).fill("Mijn Bibliotheek");
    await page.getByRole("button", { name: "Opslaan en verder" }).click();
    await expect(page).toHaveURL(/\/mijn-biblio\/$/);
    await page.getByRole("link", { name: "Mijn Bibliotheek", exact: true }).click();
    await expect(page.getByRole("heading", { name: "Mijn Bibliotheek", exact: true })).toBeVisible();
    const libraryId = new URL(page.url()).searchParams.get("library_id");
    expect(libraryId).toMatch(/^personal-/);
    await page.getByRole("link", { name: "Bibliotheeknaam wijzigen" }).click();
    await expect(page.getByRole("textbox", { name: "Bibliotheeknaam", exact: true })).toHaveValue("Mijn Bibliotheek");
    await page.getByRole("textbox", { name: "Bibliotheeknaam", exact: true }).fill("Niet bewaren");
    await page.getByRole("link", { name: "Annuleren" }).click();
    await expect(page.getByRole("heading", { name: "Mijn Bibliotheek", exact: true })).toBeVisible();
    await page.getByRole("link", { name: "Bibliotheeknaam wijzigen" }).click();
    await page.screenshot({ path: "project/bewijs/V2001-ENTRY-05-hernoemen-mobiel.png", fullPage: true });
    await page.getByRole("textbox", { name: "Bibliotheeknaam", exact: true }).fill("  Renées   testboeken  ");
    await page.getByRole("button", { name: "Opslaan", exact: true }).click();
    await expect(page.getByRole("heading", { name: "Renées testboeken", exact: true })).toBeVisible();
    expect(new URL(page.url()).searchParams.get("library_id")).toBe(libraryId);
    await page.setViewportSize({ width: 1280, height: 900 });
    await page.getByRole("link", { name: "Uitloggen", exact: true }).click();
    await login(page);
    await expect(page).toHaveURL(/\/mijn-biblio\/$/);
    await expect(page.getByRole("link", { name: "Renées testboeken", exact: true })).toBeVisible();
    // A different authenticated user cannot edit the owner's Library through a direct URL.
    const foreignContext = await browser.newContext({ ignoreHTTPSErrors: true, baseURL: process.env.BIBLIO_E2E_BASE_URL });
    const foreignPage = await foreignContext.newPage();
    await login(foreignPage, "biblio_entry_e2e_other", "/verlanglijst/");
    await foreignPage.getByRole("textbox", { name: "Bibliotheeknaam", exact: true }).fill("Andermans boeken");
    await foreignPage.getByRole("button", { name: "Opslaan en verder" }).click();
    await expect(foreignPage).toHaveURL(/\/verlanglijst\/$/);
    const denied = await foreignPage.goto(`/bibliotheek-home/?library_id=${encodeURIComponent(libraryId)}&biblio_name=1`);
    expect(denied.status()).toBe(403);
    await expect(foreignPage.getByRole("heading", { name: "Bibliotheek niet beschikbaar" })).toBeVisible();
    await foreignContext.close();
});
