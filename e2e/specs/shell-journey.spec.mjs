import { expect, test } from "@playwright/test";

test.use({ storageState: { cookies: [], origins: [] } });

async function tabTo(page, target, limit = 100) {
    await expect(target).toBeVisible();
    for (let index = 0; index < limit; index += 1) {
        if (await target.evaluate((node) => node === document.activeElement)) {
            return;
        }
        await page.keyboard.press("Tab");
    }
    throw new Error(`Target was not reachable with Tab after ${limit} steps`);
}

test("a guest signs in, chooses a Library and returns to Mijn Biblio", async ({ page }) => {
    await page.goto("/");
    await expect(page.getByRole("heading", { name: "Biblio", exact: true })).toBeVisible();
    await page.getByRole("link", { name: "Inloggen" }).click();
    await expect(page.getByRole("heading", { name: "Inloggen bij Biblio" })).toBeVisible();

    await page.locator("#user_login").fill(process.env.BIBLIO_E2E_ACTOR_USERNAME);
    await page.locator("#user_pass").fill(process.env.BIBLIO_E2E_ACTOR_PASSWORD);
    await page.locator("#wp-submit").click();

    await expect(page).toHaveURL(`${process.env.BIBLIO_E2E_BASE_URL}/mijn-biblio/`);
    const personal = page.locator("[data-biblio-entry-root][data-entry-mode='personal']");
    await expect(personal).toHaveAttribute("data-account-state", "authenticated");
    await expect(personal.getByRole("heading", { name: "Mijn Biblio" })).toBeVisible();
    await expect(personal.locator(".biblio-ui__entry-libraries"))
        .toContainText("E2E Privébibliotheek");
    expect(new URL(page.url()).searchParams.has("library_id")).toBe(false);

    await personal.locator(".biblio-ui__entry-libraries")
        .getByRole("link", { name: "E2E Privébibliotheek" }).click();
    await expect(page).toHaveURL(/\/bibliotheek-home\/\?library_id=e2e-library-actor$/);
    await expect(page.locator("[data-biblio-entry-root][data-entry-mode='library']")
        .getByRole("heading", { name: "E2E Privébibliotheek" })).toBeVisible();

    await page.getByRole("link", { name: "Catalogus bekijken" }).click();
    await expect(page).toHaveURL(/\/mijn-bibliotheek\/\?library_id=e2e-library-actor$/);
    await expect(page.locator("[data-biblio-view='overview']")
        .getByRole("heading", { name: "E2E Privébibliotheek" })).toBeVisible();

    await page.getByRole("navigation", { name: "Hoofdnavigatie" })
        .getByRole("link", { name: "Mijn Biblio" }).click();
    await expect(page).toHaveURL(`${process.env.BIBLIO_E2E_BASE_URL}/mijn-biblio/`);
    await expect(page.locator("[data-biblio-entry-root][data-entry-mode='personal']")
        .getByRole("heading", { name: "Mijn Biblio" })).toBeVisible();
});

test("the personal Library journey and logout are reachable with a keyboard", async ({ page }) => {
    await page.goto("/");
    await tabTo(page, page.getByRole("link", { name: "Inloggen", exact: true }));
    await page.keyboard.press("Enter");
    await expect(page.getByRole("heading", { name: "Inloggen bij Biblio" })).toBeVisible();

    await tabTo(page, page.locator("#user_login"));
    await page.keyboard.type(process.env.BIBLIO_E2E_ACTOR_USERNAME);
    await tabTo(page, page.locator("#user_pass"));
    await page.keyboard.type(process.env.BIBLIO_E2E_ACTOR_PASSWORD);
    await tabTo(page, page.locator("#wp-submit"));
    await page.keyboard.press("Enter");

    await expect(page).toHaveURL(`${process.env.BIBLIO_E2E_BASE_URL}/mijn-biblio/`);
    const library = page.locator(".biblio-ui__entry-libraries")
        .getByRole("link", { name: "E2E Privébibliotheek" });
    await tabTo(page, library);
    await page.keyboard.press("Enter");
    await expect(page).toHaveURL(/\/bibliotheek-home\/\?library_id=e2e-library-actor$/);

    await tabTo(page, page.getByRole("link", { name: "Catalogus bekijken" }));
    await page.keyboard.press("Enter");
    await expect(page).toHaveURL(/\/mijn-bibliotheek\/\?library_id=e2e-library-actor$/);

    const platformLink = page.getByRole("navigation", { name: "Hoofdnavigatie" })
        .getByRole("link", { name: "Mijn Biblio" });
    await tabTo(page, platformLink);
    await page.keyboard.press("Enter");
    await expect(page).toHaveURL(`${process.env.BIBLIO_E2E_BASE_URL}/mijn-biblio/`);

    const logout = page.getByRole("navigation", { name: "Account" })
        .getByRole("link", { name: "Uitloggen" });
    await tabTo(page, logout);
    await page.keyboard.press("Enter");
    await expect(page).toHaveURL(`${process.env.BIBLIO_E2E_BASE_URL}/`);
    await expect(page.getByRole("link", { name: "Inloggen" })).toBeVisible();
});

test("a manager opens a second authorized Library and logs out as the same account", async ({ page }) => {
    await page.goto("/wp-login.php");
    await page.locator("#user_login").fill(process.env.BIBLIO_E2E_ACTOR_USERNAME);
    await page.locator("#user_pass").fill(process.env.BIBLIO_E2E_ACTOR_PASSWORD);
    await page.locator("#wp-submit").click();

    await expect(page).toHaveURL(`${process.env.BIBLIO_E2E_BASE_URL}/mijn-biblio/`);
    const personal = page.locator("[data-biblio-entry-root][data-entry-mode='personal']");
    const libraries = personal.locator(".biblio-ui__entry-libraries");
    await expect(libraries.getByRole("link", { name: "E2E Privébibliotheek" })).toBeVisible();
    await libraries.getByRole("link", { name: "E2E Andere bibliotheek" }).click();

    await expect(page).toHaveURL(/\/bibliotheek-home\/\?library_id=e2e-library-other$/);
    const home = page.locator("[data-biblio-entry-root][data-entry-mode='library']");
    await expect(home.getByRole("heading", { name: "E2E Andere bibliotheek" })).toBeVisible();
    await expect(home).toHaveAttribute("data-account-name", process.env.BIBLIO_E2E_ACTOR_USERNAME);

    await page.getByRole("link", { name: "Catalogus bekijken" }).click();
    await expect(page).toHaveURL(/\/mijn-bibliotheek\/\?library_id=e2e-library-other$/);
    await expect(page.locator("[data-biblio-view='overview']")
        .getByRole("heading", { name: "E2E Andere bibliotheek" })).toBeVisible();

    await page.getByRole("navigation", { name: "Account" })
        .getByRole("link", { name: "Uitloggen" }).click();
    await expect(page).toHaveURL(`${process.env.BIBLIO_E2E_BASE_URL}/`);
    await expect(page.getByRole("link", { name: "Inloggen" })).toBeVisible();
});
