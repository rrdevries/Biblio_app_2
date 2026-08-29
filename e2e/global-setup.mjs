import { chromium } from "@playwright/test";
import { mkdirSync } from "node:fs";

export default async function globalSetup() {
    const baseURL = process.env.BIBLIO_E2E_BASE_URL;
    const username = process.env.BIBLIO_E2E_ACTOR_USERNAME;
    const password = process.env.BIBLIO_E2E_ACTOR_PASSWORD;

    if (!baseURL || !username || !password) {
        throw new Error("The local Biblio E2E environment is incomplete.");
    }

    mkdirSync(".local/e2e-auth", { recursive: true, mode: 0o700 });
    const browser = await chromium.launch();
    const context = await browser.newContext({
        baseURL,
        ignoreHTTPSErrors: true,
        locale: "nl-NL",
        timezoneId: "Europe/Amsterdam",
    });
    const page = await context.newPage();

    const redirect = encodeURIComponent(`${baseURL}/mijn-bibliotheek/`);
    await page.goto(`/wp-login.php?redirect_to=${redirect}`);
    await page.locator("#user_login").fill(username);
    await page.locator("#user_pass").fill(password);
    await page.locator("#wp-submit").click();
    await page.waitForURL(/\/mijn-bibliotheek\//);
    await page.locator("[data-biblio-view='overview']").waitFor();
    await context.storageState({ path: ".local/e2e-auth/actor.json" });
    await browser.close();
}
