import { expect, test } from "@playwright/test";

const pages = [
    "/mijn-bibliotheek/?library_id=e2e-library-actor",
    "/zoeken/",
    "/verlanglijst/",
    "/hierna-lezen/",
];

test("an authenticated reader sees the same account action across Biblio pages", async ({ page }, testInfo) => {
    for (const path of pages) {
        await page.goto(path);
        const mount = page.locator("[data-biblio-ui-root]");
        await expect(mount).toHaveAttribute("data-account-state", "authenticated");
        const name = await mount.getAttribute("data-account-name");
        expect(name?.trim()).toBeTruthy();
        const account = page.getByRole("navigation", { name: "Account" });
        await expect(account.locator(".biblio-ui__sidebar-context")).toContainText(name);
        const logout = account.getByRole("link", { name: "Uitloggen" });
        await expect(logout).toBeVisible();
        expect(new URL(await logout.getAttribute("href")).searchParams.get("redirect_to"))
            .toBe(`${process.env.BIBLIO_E2E_BASE_URL}/`);
        await expect(account.getByRole("link", { name: "Inloggen" })).toHaveCount(0);
        await expect(account.getByRole("link", { name: "Instellingen" })).toHaveCount(0);
    }

    await page.goto("/mijn-bibliotheek/?library_id=e2e-library-actor");
    await page.locator("[data-biblio-view='overview']").waitFor();
    const account = page.getByRole("navigation", { name: "Account" });
    await page.evaluate(() => window.scrollTo(0, document.body.scrollHeight));
    expect(await page.evaluate(() => window.scrollY)).toBeGreaterThan(0);
    await expect(account.getByRole("link", { name: "Uitloggen" })).toBeInViewport();
    const desktopSidebar = await page.locator(".biblio-ui__sidebar").boundingBox();
    expect(desktopSidebar?.y ?? -1).toBeGreaterThanOrEqual(0);
    expect((desktopSidebar?.y ?? 0) + (desktopSidebar?.height ?? 0))
        .toBeLessThanOrEqual(901);
    await page.screenshot({ path: testInfo.outputPath("account-desktop-scrolled.png") });
    await page.getByRole("navigation", { name: "Hoofdnavigatie" })
        .getByRole("link", { name: "Hierna lezen" }).focus();
    await page.keyboard.press("Tab");
    await expect(account.getByRole("link", { name: "Uitloggen" })).toBeFocused();
    await page.getByRole("button", { name: "Navigatie inklappen" }).click();
    await expect(account.getByRole("link", { name: "Uitloggen" })).toBeVisible();
    const railTarget = await account.getByRole("link", { name: "Uitloggen" }).boundingBox();
    expect(railTarget?.height ?? 0).toBeGreaterThanOrEqual(44);

    await page.setViewportSize({ width: 400, height: 756 });
    await page.getByRole("button", { name: "Navigatie openen" }).click();
    await expect(page.locator(".biblio-ui__sidebar"))
        .toHaveCSS("transform", "matrix(1, 0, 0, 1, 0, 0)");
    await expect(account.getByRole("link", { name: "Uitloggen" })).toBeVisible();
    await expect(account.getByRole("link", { name: "Uitloggen" })).toBeInViewport();
    await expect(account.locator(".biblio-ui__sidebar-context .biblio-ui__nav-label"))
        .toBeVisible();
    await expect(page.locator(".biblio-ui__brand .biblio-ui__nav-label"))
        .toBeVisible();
    const mobileSidebar = await page.locator(".biblio-ui__sidebar").boundingBox();
    expect(mobileSidebar?.x ?? -1).toBeGreaterThanOrEqual(0);
    expect(mobileSidebar?.width ?? 0).toBeLessThanOrEqual(272);
    expect((mobileSidebar?.y ?? 0) + (mobileSidebar?.height ?? 0))
        .toBeLessThanOrEqual(757);
    await page.screenshot({ path: testInfo.outputPath("account-mobile-drawer.png") });
    const mobileTarget = await account.getByRole("link", { name: "Uitloggen" }).boundingBox();
    expect(mobileTarget?.height ?? 0).toBeGreaterThanOrEqual(44);
    await page.setViewportSize({ width: 400, height: 360 });
    await expect(account.getByRole("link", { name: "Uitloggen" })).toBeInViewport();
    expect(await page.locator(".biblio-ui__nav").evaluate((nav) =>
        nav.scrollHeight > nav.clientHeight)).toBe(true);
    await page.keyboard.press("Escape");
    await expect(page.getByRole("button", { name: "Navigatie openen" })).toBeFocused();
});

test.describe("without a session", () => {
    test.use({ storageState: { cookies: [], origins: [] } });

    test("Inloggen is reachable and leads to WordPress", async ({ page }) => {
        await page.goto("/mijn-bibliotheek/");
        const mount = page.locator("[data-biblio-ui-root]");
        await expect(mount).toHaveAttribute("data-account-state", "guest");
        const account = page.getByRole("navigation", { name: "Account" });
        const login = account.getByRole("link", { name: "Inloggen" });
        await expect(login).toBeVisible();
        await expect(account.getByRole("link", { name: "Uitloggen" })).toHaveCount(0);
        await expect(page.locator("[data-biblio-item-id]")).toHaveCount(0);
        await page.getByRole("navigation", { name: "Hoofdnavigatie" })
            .getByRole("link", { name: "Hierna lezen" }).focus();
        await page.keyboard.press("Tab");
        await expect(login).toBeFocused();
        await login.click();
        await expect(page.locator("#user_login")).toBeVisible();
        expect(new URL(page.url()).searchParams.get("redirect_to"))
            .toBe(`${process.env.BIBLIO_E2E_BASE_URL}/mijn-bibliotheek/`);
    });
});

test("WordPress logout returns from a Library to the public guest root", async ({ page }) => {
    await page.goto("/bibliotheek-home/?library_id=e2e-library-actor");
    await expect(page.locator("[data-biblio-entry-root][data-entry-mode='library']"))
        .toHaveAttribute("data-account-state", "authenticated");
    await page.getByRole("navigation", { name: "Account" })
        .getByRole("link", { name: "Uitloggen" }).click();
    await expect(page).toHaveURL(`${process.env.BIBLIO_E2E_BASE_URL}/`);
    await expect(page.getByRole("heading", { name: "Biblio", exact: true })).toBeVisible();
    await expect(page.getByRole("link", { name: "Inloggen" })).toBeVisible();
    await expect(page.getByRole("link", { name: "Naar Mijn Biblio" })).toHaveCount(0);
});
