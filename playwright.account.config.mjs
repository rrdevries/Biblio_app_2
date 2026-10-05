import { defineConfig } from "@playwright/test";
process.loadEnvFile(".local/e2e.env");
export default defineConfig({
    testDir: "./e2e/account-specs", outputDir: ".local/account-e2e-results", workers: 1, retries: 0,
    timeout: 45_000, expect: { timeout: 8_000 }, reporter: [["list"]],
    use: { baseURL: process.env.BIBLIO_E2E_BASE_URL, ignoreHTTPSErrors: true, locale: "nl-NL", timezoneId: "Europe/Amsterdam", trace: "retain-on-failure", screenshot: "only-on-failure" },
});
