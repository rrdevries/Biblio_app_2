import { expect, test } from "@playwright/test";

test.use({ storageState: { cookies: [], origins: [] } });

test("Biblio login and recovery stay usable on a narrow screen", async ({ page }) => {
    await page.setViewportSize({ width: 400, height: 756 });
    const libraryUrl = `${process.env.BIBLIO_E2E_BASE_URL}/mijn-bibliotheek/`;
    await page.goto(`/wp-login.php?redirect_to=${encodeURIComponent(libraryUrl)}`);

    await expect(page).toHaveTitle("Inloggen bij Biblio");
    await expect(page.getByRole("heading", { name: "Inloggen bij Biblio" })).toBeVisible();
    await expect(page.locator("#login h1.wp-login-logo")).toBeHidden();
    await expect(page.locator(".biblio-login__intro p")).toHaveCount(0);
    await expect(page.getByRole("button", { name: "Inloggen" })).toBeVisible();
    await expect(page.getByRole("link", { name: "Terug naar startpagina" }))
        .toHaveAttribute("href", `${process.env.BIBLIO_E2E_BASE_URL}/`);
    expect(await page.evaluate(() => document.documentElement.scrollWidth <= innerWidth))
        .toBe(true);

    await page.getByRole("textbox", { name: "Gebruikersnaam of e-mailadres" })
        .fill("biblio-login-nonexistent-e2e");
    await page.getByRole("textbox", { name: "Wachtwoord" })
        .fill("invalid-test-password");
    await page.getByRole("button", { name: "Inloggen" }).click();
    await expect(page.locator("#login_error")).toBeVisible();
    await expect(page.getByRole("heading", { name: "Inloggen bij Biblio" }))
        .toBeVisible();

    await page.getByRole("link", { name: "Je wachtwoord vergeten?" }).click();
    await expect(page).toHaveTitle("Wachtwoord herstellen | Biblio");
    await expect(page.getByRole("heading", { name: "Wachtwoord herstellen" }))
        .toBeVisible();
    await expect(page.locator("#login h1.wp-login-logo")).toBeHidden();
    await expect(page.getByRole("link", { name: "Inloggen", exact: true }))
        .toBeVisible();
    await expect(page.getByRole("link", { name: "Terug naar startpagina" }))
        .toHaveAttribute("href", `${process.env.BIBLIO_E2E_BASE_URL}/`);
    expect(await page.evaluate(() => document.documentElement.scrollWidth <= innerWidth))
        .toBe(true);

    await page.getByRole("link", { name: "Terug naar startpagina" }).click();
    await expect(page).toHaveURL(`${process.env.BIBLIO_E2E_BASE_URL}/`);
    await expect(page.getByRole("link", { name: "Inloggen" })).toBeVisible();
});

test("ordinary login without a destination opens Mijn Biblio", async ({ page }) => {
    await page.goto("/wp-login.php");
    await page.locator("#user_login").fill(process.env.BIBLIO_E2E_ACTOR_USERNAME);
    await page.locator("#user_pass").fill(process.env.BIBLIO_E2E_ACTOR_PASSWORD);
    await page.locator("#wp-submit").click();

    await page.waitForURL(/\/mijn-biblio\/$/);
    await expect(page.locator("[data-biblio-entry-root][data-entry-mode='personal']"))
        .toBeVisible();
});

test("a requested Biblio page takes priority over the login default", async ({ page }) => {
    const destination = `${process.env.BIBLIO_E2E_BASE_URL}/verlanglijst/`;
    await page.goto(`/wp-login.php?redirect_to=${encodeURIComponent(destination)}`);
    await page.locator("#user_login").fill(process.env.BIBLIO_E2E_ACTOR_USERNAME);
    await page.locator("#user_pass").fill(process.env.BIBLIO_E2E_ACTOR_PASSWORD);
    await page.locator("#wp-submit").click();

    await page.waitForURL(destination);
});

test("an explicitly requested WordPress page keeps priority", async ({ page }) => {
    const destination = `${process.env.BIBLIO_E2E_BASE_URL}/wp-admin/`;
    await page.goto(`/wp-login.php?redirect_to=${encodeURIComponent(destination)}`);
    await page.locator("#user_login").fill(process.env.BIBLIO_E2E_ACTOR_USERNAME);
    await page.locator("#user_pass").fill(process.env.BIBLIO_E2E_ACTOR_PASSWORD);
    await page.locator("#wp-submit").click();

    await page.waitForURL((url) => url.pathname.startsWith("/wp-admin/"));
});

test("an external requested destination does not leave the Biblio site", async ({ page }) => {
    const base = process.env.BIBLIO_E2E_BASE_URL;
    await page.goto(`/wp-login.php?redirect_to=${encodeURIComponent("https://example.invalid/elsewhere")}`);
    await page.locator("#user_login").fill(process.env.BIBLIO_E2E_ACTOR_USERNAME);
    await page.locator("#user_pass").fill(process.env.BIBLIO_E2E_ACTOR_PASSWORD);
    await page.locator("#wp-submit").click();

    await page.waitForURL((url) => url.pathname !== "/wp-login.php");
    expect(new URL(page.url()).origin).toBe(new URL(base).origin);
});
