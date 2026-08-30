import { execFileSync } from "node:child_process";
import { expect, test } from "@playwright/test";

const IDS = Object.freeze({
    actorLibrary: "e2e-library-actor",
    otherLibrary: "e2e-library-other",
    completedItem: "e2e-item-end-completed",
    stoppedItem: "e2e-item-end-stopped",
    staleItem: "e2e-item-end-stale",
    nonceItem: "e2e-item-end-nonce",
    idempotentItem: "e2e-item-end-idempotent",
    lifecycleItem: "e2e-item-end-lifecycle",
    foreignItem: "e2e-item-foreign",
});

function libraryUrl(itemId = null, libraryId = IDS.actorLibrary) {
    const parameters = new URLSearchParams({ library_id: libraryId });

    if (itemId !== null) {
        parameters.set("item_id", itemId);
    }

    return `/mijn-bibliotheek/?${parameters.toString()}`;
}

function fixtureAction(action) {
    const output = execFileSync("./scripts/e2e-fixture.sh", [action], {
        cwd: process.cwd(),
        encoding: "utf8",
    });
    const jsonStart = output.indexOf("{");

    if (jsonStart === -1) {
        throw new Error(`Fixture action ${action} returned no JSON.`);
    }

    return JSON.parse(output.slice(jsonStart));
}

function fixtureRound(itemId) {
    return fixtureAction("state").state.rounds[itemId];
}

function definitionValue(page, label) {
    return page.locator("dt", { hasText: label })
        .locator("xpath=following-sibling::dd[1]");
}

async function restConfig(page) {
    return page.locator("[data-biblio-ui-root]").evaluate((mount) => ({
        nonce: mount.dataset.restNonce,
        root: mount.dataset.restRoot,
    }));
}

async function postEnd(page, round, data) {
    const config = await restConfig(page);
    return page.request.post(
        `${config.root}me/reading-rounds/${encodeURIComponent(round.reading_round_id)}/end`,
        {
            data,
            headers: { "X-WP-Nonce": config.nonce },
        }
    );
}

async function expectNoHorizontalOverflow(page) {
    const dimensions = await page.evaluate(() => ({
        body: document.body.scrollWidth,
        document: document.documentElement.scrollWidth,
        viewport: window.innerWidth,
    }));
    expect(dimensions.body).toBeLessThanOrEqual(dimensions.viewport);
    expect(dimensions.document).toBeLessThanOrEqual(dimensions.viewport);
}

async function chooseOutcomeAndDate(page, outcome, date) {
    const dialog = page.getByRole("dialog", { name: "Leesronde afronden" });
    await dialog.getByRole("radio", { name: outcome }).check();
    await dialog.getByLabel("Einddatum").fill(date);
    return dialog;
}

test.describe.configure({ mode: "serial" });

test("completed flow locks pending input, persists once and survives navigation", async ({ page }) => {
    await page.goto(libraryUrl());
    await page.locator(`[data-biblio-item-id='${IDS.completedItem}']`)
        .getByRole("link")
        .click();
    await expect(definitionValue(page, "Leesstatus")).toHaveText("Aan het lezen");
    const opener = page.getByRole("button", { name: "Leesronde afronden" });
    await opener.click();
    const dialog = page.getByRole("dialog", { name: "Leesronde afronden" });
    await expect(dialog.getByRole("radio", { name: "Uitgelezen" })).not.toBeChecked();
    await expect(dialog.getByRole("radio", { name: "Gestopt" })).not.toBeChecked();
    const localToday = await page.evaluate(() => {
        const now = new Date();
        return [
            now.getFullYear(),
            String(now.getMonth() + 1).padStart(2, "0"),
            String(now.getDate()).padStart(2, "0"),
        ].join("-");
    });
    await expect(dialog.getByLabel("Einddatum")).toHaveValue(localToday);
    await dialog.getByRole("radio", { name: "Uitgelezen" }).check();
    await dialog.getByLabel("Einddatum").fill("2026-08-20");

    let posts = 0;
    let releaseRequest;
    const requestReleased = new Promise((resolve) => { releaseRequest = resolve; });
    await page.route("**/me/reading-rounds/*/end", async (route) => {
        posts += 1;
        await requestReleased;
        await route.continue();
    });

    const responsePromise = page.waitForResponse((response) => (
        response.request().method() === "POST"
        && response.url().endsWith("/end")
    ));
    await dialog.getByRole("button", { name: "Afronden" }).click();
    await expect(dialog.locator("form")).toHaveAttribute("aria-busy", "true");
    await expect(dialog.getByRole("radio", { name: "Uitgelezen" })).toBeDisabled();
    await expect(dialog.getByLabel("Einddatum")).toBeDisabled();
    await expect(dialog.getByRole("button", { name: "Annuleren" })).toBeDisabled();
    await page.keyboard.press("Escape");
    await expect(dialog).toBeVisible();
    releaseRequest();

    const response = await responsePromise;
    expect(response.status()).toBe(200);
    await expect(dialog).toHaveCount(0);
    await expect(page.getByRole("status")).toHaveText("De leesstatus is bijgewerkt.");
    await expect(page.getByRole("status")).toBeFocused();
    await expect(definitionValue(page, "Leesstatus")).toHaveText("Uitgelezen");
    await expect(definitionValue(page, "Uitgelezen leesrondes")).toHaveText("1");
    await expect(page.getByRole("button", { name: "Leesronde afronden" })).toHaveCount(0);
    await expect(page.getByRole("button", { name: "Lezen starten" })).toBeVisible();
    expect(posts).toBe(1);
    expect(fixtureRound(IDS.completedItem)).toMatchObject({
        lifecycle: "ended",
        outcome: "completed",
        version: 2,
        row_count: 1,
        active_rounds: 0,
        finished_on: { year: 2026, month: 8, day: 20 },
    });

    await page.reload();
    await expect(definitionValue(page, "Leesstatus")).toHaveText("Uitgelezen");
    await page.getByRole("link", { name: "Terug naar bibliotheek" }).click();
    await expect(page.locator("[data-biblio-view='overview']")).toBeVisible();
    await page.goBack();
    await expect(definitionValue(page, "Uitgelezen leesrondes")).toHaveText("1");
    await page.goForward();
    await expect(page.locator("[data-biblio-view='overview']")).toBeVisible();
});

test("stopped flow persists the independent stopped outcome", async ({ page }) => {
    await page.goto(libraryUrl(IDS.stoppedItem));
    await page.getByRole("button", { name: "Leesronde afronden" }).click();
    const dialog = await chooseOutcomeAndDate(page, "Gestopt", "2026-08-21");
    const responsePromise = page.waitForResponse((response) => (
        response.request().method() === "POST"
        && response.url().endsWith("/end")
    ));
    await dialog.getByRole("button", { name: "Afronden" }).click();
    expect((await responsePromise).status()).toBe(200);
    await expect(definitionValue(page, "Leesstatus")).toHaveText("Niet gelezen");
    await expect(definitionValue(page, "Gestopte leesrondes")).toHaveText("1");
    expect(fixtureRound(IDS.stoppedItem)).toMatchObject({
        lifecycle: "ended",
        outcome: "stopped",
        version: 2,
        row_count: 1,
        active_rounds: 0,
        finished_on: { year: 2026, month: 8, day: 21 },
    });
    await page.reload();
    await expect(definitionValue(page, "Gestopte leesrondes")).toHaveText("1");
    await expect(definitionValue(page, "Uitgelezen leesrondes")).toHaveCount(0);
    await page.getByRole("link", { name: "Terug naar bibliotheek" }).click();
    await page.goBack();
    await expect(definitionValue(page, "Gestopte leesrondes")).toHaveText("1");
    await page.goForward();
    await expect(page.locator("[data-biblio-view='overview']")).toBeVisible();
});

test("same-owner stale finish returns 409 once and reconciles server truth", async ({ page }) => {
    await page.goto(libraryUrl(IDS.staleItem));
    await page.getByRole("button", { name: "Leesronde afronden" }).click();
    const dialog = await chooseOutcomeAndDate(page, "Gestopt", "2026-08-22");
    fixtureAction("stale-end");

    let posts = 0;
    page.on("request", (request) => {
        if (request.method() === "POST" && request.url().endsWith("/end")) {
            posts += 1;
        }
    });
    const responsePromise = page.waitForResponse((response) => (
        response.request().method() === "POST"
        && response.url().endsWith("/end")
    ));
    await dialog.getByRole("button", { name: "Afronden" }).click();
    const response = await responsePromise;
    expect(response.status()).toBe(409);
    expect(await response.json()).toMatchObject({
        code: "biblio_reading_round_stale",
        data: { status: 409 },
    });
    await expect(page.getByRole("status")).toHaveText("De leesstatus is gewijzigd.");
    await expect(definitionValue(page, "Leesstatus")).toHaveText("Uitgelezen");
    await expect(definitionValue(page, "Uitgelezen leesrondes")).toHaveText("1");
    expect(posts).toBe(1);
    expect(fixtureRound(IDS.staleItem)).toMatchObject({
        lifecycle: "ended",
        outcome: "completed",
        version: 2,
        row_count: 1,
        active_rounds: 0,
        finished_on: { year: 2026, month: 8, day: 18 },
    });
});

test("invalid nonce is not retried and leaves the round active", async ({ page }) => {
    await page.goto(libraryUrl(IDS.nonceItem));
    const round = fixtureRound(IDS.nonceItem);
    let posts = 0;
    await page.route(`**/me/reading-rounds/${round.reading_round_id}/end`, async (route) => {
        posts += 1;
        await route.continue({
            headers: {
                ...route.request().headers(),
                "x-wp-nonce": "invalid-e2e-nonce",
            },
        });
    });
    await page.getByRole("button", { name: "Leesronde afronden" }).click();
    const dialog = await chooseOutcomeAndDate(page, "Uitgelezen", "2026-08-23");
    const responsePromise = page.waitForResponse((response) => (
        response.request().method() === "POST"
        && response.url().endsWith("/end")
    ));
    await dialog.getByRole("button", { name: "Afronden" }).click();
    const response = await responsePromise;
    expect(response.status()).toBe(403);
    expect(await response.json()).toMatchObject({
        code: "rest_cookie_invalid_nonce",
        data: { status: 403 },
    });
    await expect(dialog.getByText("Je sessie moet worden vernieuwd.")).toBeVisible();
    expect(posts).toBe(1);
    expect(fixtureRound(IDS.nonceItem)).toMatchObject({
        lifecycle: "active",
        outcome: null,
        version: 1,
        row_count: 1,
        active_rounds: 1,
    });

    await page.unroute(`**/me/reading-rounds/${round.reading_round_id}/end`);
    await dialog.getByRole("button", { name: "Sessie vernieuwen" }).click();
    await expect(definitionValue(page, "Leesstatus")).toHaveText("Aan het lezen");
    await expect(page.getByRole("button", { name: "Leesronde afronden" })).toBeVisible();
    expect(posts).toBe(1);
});

test("manager access never overrides private ReadingRound ownership", async ({ page }) => {
    const state = fixtureAction("state").state;
    expect(state.actor_manages_other_library).toBe(true);
    const foreignRound = state.rounds[IDS.foreignItem];
    await page.goto(libraryUrl(IDS.foreignItem, IDS.otherLibrary));
    await expect(page.getByRole("heading", { name: "Ripper" })).toBeVisible();
    await expect(page.getByRole("button", { name: "Leesronde afronden" })).toHaveCount(0);
    const config = await restConfig(page);
    const payload = {
        outcome: "completed",
        finished_on: "2026-08-24",
        expected_version: 1,
    };
    const headers = { "X-WP-Nonce": config.nonce };
    const foreign = await page.request.post(
        `${config.root}me/reading-rounds/${encodeURIComponent(foreignRound.reading_round_id)}/end`,
        { data: payload, headers }
    );
    const unknown = await page.request.post(
        `${config.root}me/reading-rounds/e2e-reading-round-unknown/end`,
        { data: payload, headers }
    );
    expect(foreign.status()).toBe(404);
    expect(unknown.status()).toBe(404);
    const foreignBody = await foreign.json();
    const unknownBody = await unknown.json();
    expect(foreignBody).toEqual(unknownBody);
    expect(foreignBody).toMatchObject({
        code: "biblio_resource_not_available",
        data: { status: 404 },
    });
    expect(JSON.stringify(foreignBody)).not.toContain(IDS.foreignItem);
    expect(fixtureRound(IDS.foreignItem)).toMatchObject({
        lifecycle: "active",
        outcome: null,
        version: 1,
        row_count: 1,
        active_rounds: 1,
    });
});

test("identical stale retry is idempotent and does not write again", async ({ page }) => {
    await page.goto(libraryUrl(IDS.idempotentItem));
    const round = fixtureRound(IDS.idempotentItem);
    const request = {
        outcome: "completed",
        finished_on: "2026-08-25",
        expected_version: 1,
    };
    const first = await postEnd(page, round, request);
    expect(first.status()).toBe(200);
    const firstBody = await first.json();
    expect(firstBody).toMatchObject({
        data: {
            lifecycle: "ended",
            outcome: "completed",
            version: 2,
        },
    });
    const afterFirst = fixtureRound(IDS.idempotentItem);
    expect(afterFirst).toMatchObject({
        lifecycle: "ended",
        outcome: "completed",
        version: 2,
        row_count: 1,
        active_rounds: 0,
    });
    const second = await postEnd(page, round, request);
    expect(second.status()).toBe(200);
    expect(await second.json()).toEqual(firstBody);
    expect(fixtureRound(IDS.idempotentItem)).toEqual(afterFirst);
});

test("current-version incompatible second end returns 422 without mutation", async ({ page }) => {
    await page.goto(libraryUrl(IDS.lifecycleItem));
    const round = fixtureRound(IDS.lifecycleItem);
    const first = await postEnd(page, round, {
        outcome: "completed",
        finished_on: "2026-08-26",
        expected_version: 1,
    });
    expect(first.status()).toBe(200);
    const afterFirst = fixtureRound(IDS.lifecycleItem);
    const second = await postEnd(page, round, {
        outcome: "stopped",
        finished_on: "2026-08-27",
        expected_version: 2,
    });
    expect(second.status()).toBe(422);
    expect(await second.json()).toMatchObject({
        code: "biblio_validation_failed",
        data: { status: 422 },
    });
    expect(fixtureRound(IDS.lifecycleItem)).toEqual(afterFirst);
    expect(afterFirst).toMatchObject({
        lifecycle: "ended",
        outcome: "completed",
        version: 2,
        row_count: 1,
        active_rounds: 0,
    });
});

test("end dialog is responsive and keeps native keyboard semantics", async ({ page }) => {
    for (const viewport of [
        { width: 390, height: 844 },
        { width: 900, height: 900 },
        { width: 1280, height: 900 },
    ]) {
        await page.setViewportSize(viewport);
        await page.goto(libraryUrl(IDS.nonceItem));
        await page.getByRole("button", { name: "Leesronde afronden" }).click();
        const dialog = page.getByRole("dialog", { name: "Leesronde afronden" });
        await expect(dialog).toBeVisible();
        await expectNoHorizontalOverflow(page);
        const box = await dialog.boundingBox();
        expect(box?.width ?? Infinity).toBeLessThanOrEqual(viewport.width);
        expect(box?.height ?? Infinity).toBeLessThanOrEqual(viewport.height);
        await expect(dialog.getByRole("radio", { name: "Uitgelezen" })).toBeVisible();
        await expect(dialog.getByLabel("Einddatum")).toBeEditable();
        await expect(dialog.getByRole("button", { name: "Annuleren" })).toBeVisible();

        if (viewport.width === 390) {
            expect(Math.abs((box?.y ?? 0) + (box?.height ?? 0) - viewport.height))
                .toBeLessThanOrEqual(1);
            expect(box?.width ?? 0).toBe(viewport.width);
        } else {
            expect(box?.width ?? Infinity).toBeLessThanOrEqual(512);
            expect(Math.abs(
                (box?.x ?? 0) + (box?.width ?? 0) / 2 - viewport.width / 2
            )).toBeLessThanOrEqual(1);
        }
        await page.keyboard.press("Escape");
    }

    await page.setViewportSize({ width: 390, height: 844 });
    const opener = page.getByRole("button", { name: "Leesronde afronden" });
    await opener.focus();
    await page.keyboard.press("Enter");
    let dialog = page.getByRole("dialog", { name: "Leesronde afronden" });
    await expect(dialog.getByRole("radio", { name: "Uitgelezen" })).toBeFocused();
    await expect(dialog.getByRole("radio", { name: "Uitgelezen" })).not.toBeChecked();
    await expect(dialog.getByRole("radio", { name: "Gestopt" })).not.toBeChecked();
    await expect(dialog.getByLabel("Einddatum")).toBeVisible();
    await page.keyboard.press("Escape");
    await expect(opener).toBeFocused();

    await page.keyboard.press("Space");
    dialog = page.getByRole("dialog", { name: "Leesronde afronden" });
    await expect(dialog.getByRole("radio", { name: "Uitgelezen" })).toBeFocused();
    await page.keyboard.press("ArrowRight");
    await expect(dialog.getByRole("radio", { name: "Gestopt" })).toBeChecked();
    for (let index = 0; index < 10; index += 1) {
        await page.keyboard.press("Tab");
        const focus = await dialog.evaluate((node) => ({
            inside: node.contains(document.activeElement),
            tagName: document.activeElement?.tagName ?? null,
        }));
        expect(focus.inside || focus.tagName === "BODY").toBe(true);

        if (focus.tagName === "BODY") {
            await page.keyboard.press("Tab");
            expect(await dialog.evaluate((node) => (
                node.contains(document.activeElement)
            ))).toBe(true);
        }
    }
    await page.keyboard.press("Escape");
    await expect(opener).toBeFocused();
    expect(fixtureRound(IDS.nonceItem)).toMatchObject({
        lifecycle: "active",
        outcome: null,
        version: 1,
        row_count: 1,
        active_rounds: 1,
    });
});
