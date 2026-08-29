import { defineConfig } from "@playwright/test";
import { existsSync } from "node:fs";

if (existsSync(".local/e2e.env")) {
    process.loadEnvFile(".local/e2e.env");
}

export default defineConfig({
    testDir: "./e2e/specs",
    outputDir: ".local/e2e-results",
    globalSetup: "./e2e/global-setup.mjs",
    fullyParallel: false,
    workers: 1,
    retries: 0,
    timeout: 45_000,
    expect: { timeout: 8_000 },
    reporter: [["list"]],
    use: {
        baseURL: process.env.BIBLIO_E2E_BASE_URL,
        storageState: ".local/e2e-auth/actor.json",
        ignoreHTTPSErrors: true,
        locale: "nl-NL",
        timezoneId: "Europe/Amsterdam",
        trace: "retain-on-failure",
        screenshot: "only-on-failure",
        video: "retain-on-failure",
    },
    projects: [
        {
            name: "chromium",
            use: { browserName: "chromium" },
        },
    ],
});
