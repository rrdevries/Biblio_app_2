import { execFileSync } from "node:child_process";
import { expect, test } from "@playwright/test";

const IDS = Object.freeze({
    actorLibrary: "e2e-library-actor",
    otherLibrary: "e2e-library-other",
    zeroItem: "e2e-item-primary",
    editItem: "e2e-item-missing-metadata",
    deleteItem: "e2e-item-active-conflict",
    staleUpdateItem: "e2e-item-end-completed",
    staleDeleteItem: "e2e-item-end-stopped",
    unavailableItem: "e2e-item-end-stale",
    refreshItem: "e2e-item-end-nonce",
    reflowItem: "e2e-item-end-idempotent",
    historyItem: "e2e-item-history",
    sameEditionItem: "e2e-item-history-same-edition",
    otherEditionItem: "e2e-item-history-other-edition",
    injectedItem: "e2e-item-history-zero",
    zeroWork: "e2e-work-primary",
    editWork: "e2e-work-missing-metadata",
    deleteWork: "e2e-work-active-conflict",
    staleUpdateWork: "e2e-work-end-completed",
    staleDeleteWork: "e2e-work-end-stopped",
    unavailableWork: "e2e-work-end-stale",
    refreshWork: "e2e-work-end-nonce",
    reflowWork: "e2e-work-end-idempotent",
    historyWork: "e2e-work-history",
    injectedWork: "e2e-work-history-zero",
    editNote: "e2e-private-note-edit",
    deleteNote: "e2e-private-note-delete",
    staleUpdateNote: "e2e-private-note-stale-update",
    staleDeleteNote: "e2e-private-note-stale-delete",
    unavailableNote: "e2e-private-note-unavailable",
    refreshNote: "e2e-private-note-refresh",
    reflowNote: "e2e-private-note-reflow",
    foreignNote: "e2e-private-note-foreign",
});

const SELECT_ALL = process.platform === "darwin" ? "Meta+A" : "Control+A";

function libraryUrl(itemId = null, libraryId = IDS.actorLibrary) {
    const parameters = new URLSearchParams({ library_id: libraryId });

    if (itemId !== null) {
        parameters.set("item_id", itemId);
    }

    return `/mijn-bibliotheek/?${parameters.toString()}`;
}

function notesRegion(page) {
    return page.locator("[data-biblio-private-notes]");
}

function noteCards(page) {
    return notesRegion(page).locator(".biblio-ui__note-card");
}

function noteEditor(page) {
    return notesRegion(page).locator("[data-biblio-note-editor]");
}

function notePath(workId) {
    return `/me/works/${workId}/private-notes`;
}

function isNotesRequest(request, workId, method = "GET") {
    const url = new URL(request.url());

    return request.method() === method
        && url.pathname.endsWith(notePath(workId));
}

function isNoteMemberRequest(request, privateNoteId, method) {
    const url = new URL(request.url());

    return request.method() === method
        && url.pathname.endsWith(`/me/private-notes/${privateNoteId}`);
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

async function restConfig(page) {
    return page.locator("[data-biblio-ui-root]").evaluate((mount) => ({
        nonce: mount.dataset.restNonce,
        root: mount.dataset.restRoot,
    }));
}

function restError(code, status, message = "Veilige E2E-fout") {
    return {
        status,
        contentType: "application/json",
        body: JSON.stringify({ code, message, data: { status } }),
    };
}

async function openItem(page, itemId, libraryId = IDS.actorLibrary) {
    await page.goto(libraryUrl(itemId, libraryId));
    await expect(notesRegion(page)).toHaveAttribute("aria-busy", "false");
    await expect(page.getByRole("heading", { level: 2, name: "Privénotities" }))
        .toBeVisible();
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

async function appendEditorText(editor, text) {
    await editor.evaluate((node, value) => {
        const target = node.querySelector("blockquote, p, li") ?? node;
        target.append(document.createTextNode(value));
        node.dispatchEvent(new InputEvent("input", {
            bubbles: true,
            inputType: "insertText",
            data: value,
        }));
    }, text);
}

async function tabTo(page, target, maximumPresses = 12) {
    for (let press = 0; press < maximumPresses; press += 1) {
        const focused = await target.evaluate(
            (element) => element === element.ownerDocument.activeElement
        ).catch(() => false);

        if (focused) {
            return;
        }

        await page.keyboard.press("Tab");
    }

    await expect(target).toBeFocused();
}

test.describe.configure({ mode: "serial" });

test("zero state stays visible and create saves once then reconciles and persists", async ({ page }) => {
    let posts = 0;
    let observe = false;
    const sequence = [];
    page.on("request", (request) => {
        if (isNotesRequest(request, IDS.zeroWork, "POST")) {
            posts += 1;

            if (observe) {
                sequence.push("POST");
            }
        } else if (observe && isNotesRequest(request, IDS.zeroWork)) {
            sequence.push("GET");
        }
    });

    await openItem(page, IDS.zeroItem);
    await expect(page.getByRole("heading", { level: 1, name: "Dagboek van een slecht jaar" })).toBeVisible();
    await expect(page.getByRole("heading", { level: 2, name: "Lezen" })).toBeVisible();
    await expect(page.getByRole("button", { name: "Notitie toevoegen" })).toBeVisible();
    await expect(noteCards(page)).toHaveCount(0);
    await expect(notesRegion(page).getByRole("list")).toHaveCount(0);
    expect(posts).toBe(0);

    const add = page.getByRole("button", { name: "Notitie toevoegen" });
    await add.click();
    const editor = noteEditor(page);
    await expect(editor).toBeFocused();
    await editor.fill("E2E aangemaakte notitie");
    await editor.press(SELECT_ALL);
    await notesRegion(page).getByRole("button", { name: "Vet" }).click();
    await expect(editor.locator("strong, b")).toContainText("E2E aangemaakte notitie");

    observe = true;
    const postResponse = page.waitForResponse((response) => (
        isNotesRequest(response.request(), IDS.zeroWork, "POST")
    ));
    const reconcileResponse = page.waitForResponse((response) => (
        isNotesRequest(response.request(), IDS.zeroWork)
        && !new URL(response.url()).searchParams.has("cursor")
    ));
    await notesRegion(page).getByRole("button", { name: "Opslaan" }).click();
    expect((await postResponse).status()).toBe(201);
    expect((await reconcileResponse).status()).toBe(200);
    await expect(noteEditor(page)).toHaveCount(0);
    await expect(noteCards(page)).toHaveCount(1);
    await expect(noteCards(page).locator("strong")).toHaveText("E2E aangemaakte notitie");
    await expect(notesRegion(page).getByRole("status")).toHaveText("Privénotitie opgeslagen.");
    await expect(notesRegion(page).getByRole("status")).toBeFocused();
    expect(sequence).toEqual(["POST", "GET"]);
    expect(posts).toBe(1);
    const visibleText = await notesRegion(page).innerText();
    expect(visibleText).not.toContain("<strong>");
    expect(visibleText).not.toContain("private-note-");
    expect(visibleText).not.toContain("version");

    await page.reload();
    await expect(noteCards(page)).toHaveCount(1);
    await expect(noteCards(page).locator("strong")).toHaveText("E2E aangemaakte notitie");
});

test("edit preserves formatting, sends the current version once and reports the real semantic no-op", async ({ page }) => {
    await openItem(page, IDS.editItem);
    const card = noteCards(page).filter({ hasText: "E2E bewerkbare notitie" });
    await expect(card.locator("strong")).toHaveText("vet");
    await expect(card.locator("em")).toHaveText("cursief");
    await expect(card.locator("ul > li")).toHaveText("E2E lijstpunt");
    await expect(card.locator("blockquote")).toHaveText("E2E citaat");
    await card.getByRole("button", { name: "Bewerken" }).click();
    let editor = noteEditor(page);
    await expect(editor).toBeFocused();
    await expect(editor.locator("strong")).toHaveText("vet");
    await expect(editor.locator("em")).toHaveText("cursief");
    await appendEditorText(editor, " E2E gewijzigd");

    let patches = 0;
    let pageOneGets = 0;
    let patchPayload;
    page.on("request", (request) => {
        if (isNoteMemberRequest(request, IDS.editNote, "PATCH")) {
            patches += 1;
            patchPayload = request.postDataJSON();
        }

        if (isNotesRequest(request, IDS.editWork)) {
            pageOneGets += 1;
        }
    });
    const patchResponse = page.waitForResponse((response) => (
        isNoteMemberRequest(response.request(), IDS.editNote, "PATCH")
    ));
    await notesRegion(page).getByRole("button", { name: "Opslaan" }).click();
    const response = await patchResponse;
    expect(response.status()).toBe(200);
    expect(patchPayload.expected_version).toBe(1);
    expect(patchPayload.content).toContain("E2E gewijzigd");
    await expect(notesRegion(page).getByRole("status")).toHaveText("Privénotitie bijgewerkt.");
    await expect(notesRegion(page).getByRole("status")).toBeFocused();
    await expect(noteCards(page)).toContainText("E2E gewijzigd");
    expect(patches).toBe(1);
    expect(pageOneGets).toBe(1);

    await page.reload();
    await expect(noteCards(page)).toContainText("E2E gewijzigd");
    await noteCards(page).getByRole("button", { name: "Bewerken" }).click();
    editor = noteEditor(page);
    await expect(editor).toBeFocused();
    patches = 0;
    pageOneGets = 0;
    patchPayload = null;
    const noOpResponse = page.waitForResponse((candidate) => (
        isNoteMemberRequest(candidate.request(), IDS.editNote, "PATCH")
    ));
    await notesRegion(page).getByRole("button", { name: "Opslaan" }).click();
    const noOp = await noOpResponse;
    expect(noOp.status()).toBe(200);
    expect((await noOp.json()).data.version).toBe(2);
    expect(patchPayload.expected_version).toBe(2);
    expect(patches).toBe(1);
    expect(pageOneGets).toBe(1);
    await expect(noteCards(page)).toContainText("E2E gewijzigd");
});

test("clean and dirty Cancel retain or discard exactly and own beforeunload only while dirty", async ({ page }) => {
    await page.addInitScript(() => {
        const listeners = new Set();
        const add = window.addEventListener.bind(window);
        const remove = window.removeEventListener.bind(window);
        window.addEventListener = (type, listener, options) => {
            if (type === "beforeunload") {
                listeners.add(listener);
            }
            return add(type, listener, options);
        };
        window.removeEventListener = (type, listener, options) => {
            if (type === "beforeunload") {
                listeners.delete(listener);
            }
            return remove(type, listener, options);
        };
        window.__biblioE2eBeforeUnloadCount = () => listeners.size;
    });
    await openItem(page, IDS.editItem);
    const edit = noteCards(page).getByRole("button", { name: "Bewerken" });
    await edit.click();
    await expect.poll(() => page.evaluate(() => window.__biblioE2eBeforeUnloadCount())).toBe(0);
    await notesRegion(page).getByRole("button", { name: "Annuleren" }).click();
    await expect(noteEditor(page)).toHaveCount(0);
    await expect(edit).toBeFocused();

    let mutations = 0;
    page.on("request", (request) => {
        if (["POST", "PATCH", "DELETE"].includes(request.method())
            && new URL(request.url()).pathname.includes("private-notes")) {
            mutations += 1;
        }
    });
    await edit.click();
    const editor = noteEditor(page);
    await appendEditorText(editor, " E2E lokale dirty tekst");
    await expect.poll(() => page.evaluate(() => window.__biblioE2eBeforeUnloadCount())).toBe(1);
    await notesRegion(page).getByRole("button", { name: "Annuleren" }).click();
    let dialog = page.getByRole("dialog", { name: "Wijzigingen niet opslaan?" });
    await expect(dialog).toHaveAccessibleDescription("Je hebt wijzigingen die nog niet zijn opgeslagen.");
    await expect(dialog.getByRole("button", { name: "Terug naar notitie" })).toBeFocused();
    await dialog.getByRole("button", { name: "Terug naar notitie" }).click();
    await expect(editor).toBeFocused();
    await expect(editor).toContainText("E2E lokale dirty tekst");
    expect(mutations).toBe(0);

    await notesRegion(page).getByRole("button", { name: "Annuleren" }).click();
    dialog = page.getByRole("dialog", { name: "Wijzigingen niet opslaan?" });
    await dialog.getByRole("button", { name: "Doorgaan zonder opslaan" }).click();
    await expect(noteEditor(page)).toHaveCount(0);
    await expect(noteCards(page).getByRole("button", { name: "Bewerken" })).toBeFocused();
    await expect.poll(() => page.evaluate(() => window.__biblioE2eBeforeUnloadCount())).toBe(0);
    expect(mutations).toBe(0);
    await page.reload();
    await expect(noteCards(page)).toContainText("E2E bewerkbare notitie met vet en cursief. E2E gewijzigd");
    await expect(noteCards(page)).toContainText("E2E lijstpunt");
    await expect(noteCards(page)).toContainText("E2E citaat");
    await expect(noteCards(page)).not.toContainText("E2E lokale dirty tekst");
});

test("dirty overview navigation is deferred, retained, then discarded exactly once", async ({ page }) => {
    await openItem(page, IDS.editItem);
    let mutations = 0;
    page.on("request", (request) => {
        if (["POST", "PATCH", "DELETE"].includes(request.method())
            && new URL(request.url()).pathname.includes("private-notes")) {
            mutations += 1;
        }
    });
    await noteCards(page).getByRole("button", { name: "Bewerken" }).click();
    await appendEditorText(noteEditor(page), " E2E overview dirty");
    const routeBefore = page.url();
    const historyBefore = await page.evaluate(() => history.length);
    await page.getByRole("link", { name: "Terug naar bibliotheek" }).click();
    let dialog = page.getByRole("dialog", { name: "Wijzigingen niet opslaan?" });
    await dialog.getByRole("button", { name: "Terug naar notitie" }).click();
    expect(page.url()).toBe(routeBefore);
    await expect(noteEditor(page)).toBeFocused();
    expect(await page.evaluate(() => history.length)).toBe(historyBefore);

    await page.getByRole("link", { name: "Terug naar bibliotheek" }).click();
    dialog = page.getByRole("dialog", { name: "Wijzigingen niet opslaan?" });
    await dialog.getByRole("button", { name: "Doorgaan zonder opslaan" }).click();
    await expect(page.locator("[data-biblio-view='overview']")).toBeVisible();
    expect(new URL(page.url()).searchParams.get("item_id")).toBeNull();
    expect(await page.evaluate(() => history.length)).toBe(historyBefore + 1);
    expect(mutations).toBe(0);
});

test("dirty other-Item and browser-Back popstate keep the route until explicit discard", async ({ page }) => {
    await page.goto(libraryUrl());
    await page.locator(`[data-biblio-item-id='${IDS.reflowItem}']`).getByRole("link").click();
    await expect(noteCards(page)).toHaveCount(1);
    await noteCards(page).getByRole("button", { name: "Bewerken" }).click();
    await appendEditorText(noteEditor(page), " E2E popstate dirty");
    const original = page.url();
    let mutations = 0;
    page.on("request", (request) => {
        if (["POST", "PATCH", "DELETE"].includes(request.method())
            && new URL(request.url()).pathname.includes("private-notes")) {
            mutations += 1;
        }
    });

    await page.evaluate(({ libraryId, itemId }) => {
        const target = new URL(location.href);
        target.searchParams.set("library_id", libraryId);
        target.searchParams.set("item_id", itemId);
        history.pushState({}, "", target);
        dispatchEvent(new PopStateEvent("popstate"));
    }, { libraryId: IDS.actorLibrary, itemId: IDS.editItem });
    let dialog = page.getByRole("dialog", { name: "Wijzigingen niet opslaan?" });
    await expect(dialog).toBeVisible();
    await dialog.getByRole("button", { name: "Terug naar notitie" }).click();
    expect(page.url()).toBe(original);
    await expect(noteEditor(page)).toBeFocused();

    await page.evaluate(({ libraryId, itemId }) => {
        const target = new URL(location.href);
        target.searchParams.set("library_id", libraryId);
        target.searchParams.set("item_id", itemId);
        history.pushState({}, "", target);
        dispatchEvent(new PopStateEvent("popstate"));
    }, { libraryId: IDS.actorLibrary, itemId: IDS.editItem });
    dialog = page.getByRole("dialog", { name: "Wijzigingen niet opslaan?" });
    await dialog.getByRole("button", { name: "Doorgaan zonder opslaan" }).click();
    await expect(page.getByRole("heading", { level: 1, name: "The Secret Commonwealth" })).toBeVisible();
    expect(new URL(page.url()).searchParams.get("item_id")).toBe(IDS.editItem);
    expect(mutations).toBe(0);

    await page.getByRole("link", { name: "Terug naar bibliotheek" }).click();
    await page.locator(`[data-biblio-item-id='${IDS.reflowItem}']`).getByRole("link").click();
    await noteCards(page).getByRole("button", { name: "Bewerken" }).click();
    await appendEditorText(noteEditor(page), " E2E browser back dirty");
    await page.goBack();
    dialog = page.getByRole("dialog", { name: "Wijzigingen niet opslaan?" });
    await expect(dialog).toBeVisible();
    await dialog.getByRole("button", { name: "Terug naar notitie" }).click();
    expect(new URL(page.url()).searchParams.get("item_id")).toBe(IDS.reflowItem);
    await page.goBack();
    dialog = page.getByRole("dialog", { name: "Wijzigingen niet opslaan?" });
    await dialog.getByRole("button", { name: "Doorgaan zonder opslaan" }).click();
    await expect(page.locator("[data-biblio-view='overview']")).toBeVisible();
    expect(mutations).toBe(0);
});

test("delete Cancel preserves the Note and delete success sends one versioned request and persists", async ({ page }) => {
    await openItem(page, IDS.deleteItem);
    const card = noteCards(page).filter({ hasText: "E2E notitie voor verwijderen" });
    const opener = card.getByRole("button", { name: "Verwijderen" });
    let deletes = 0;
    let pageOneGets = 0;
    let deletePayload;
    page.on("request", (request) => {
        if (isNoteMemberRequest(request, IDS.deleteNote, "DELETE")) {
            deletes += 1;
            deletePayload = request.postDataJSON();
        }

        if (isNotesRequest(request, IDS.deleteWork)) {
            pageOneGets += 1;
        }
    });
    await opener.click();
    let dialog = page.getByRole("dialog", { name: "Privénotitie verwijderen?" });
    await expect(dialog).toHaveAccessibleDescription(
        "Deze notitie wordt definitief verwijderd. Dit kan niet ongedaan worden gemaakt."
    );
    await expect(dialog.getByRole("button", { name: "Annuleren" })).toBeFocused();
    await page.keyboard.press("Escape");
    await expect(dialog).toHaveCount(0);
    await expect(opener).toBeFocused();
    await expect(card).toBeVisible();
    expect(deletes).toBe(0);

    await opener.click();
    dialog = page.getByRole("dialog", { name: "Privénotitie verwijderen?" });
    const responsePromise = page.waitForResponse((response) => (
        isNoteMemberRequest(response.request(), IDS.deleteNote, "DELETE")
    ));
    await dialog.getByRole("button", { name: "Definitief verwijderen" }).click();
    expect((await responsePromise).status()).toBe(204);
    await expect(noteCards(page)).toHaveCount(0);
    await expect(dialog).toHaveCount(0);
    await expect(notesRegion(page).getByRole("status")).toHaveText("Privénotitie verwijderd.");
    await expect(notesRegion(page).getByRole("status")).toBeFocused();
    expect(deletePayload).toEqual({ expected_version: 1 });
    expect(deletes).toBe(1);
    expect(pageOneGets).toBe(1);

    await page.reload();
    await expect(noteCards(page)).toHaveCount(0);
    await expect(page.getByRole("button", { name: "Notitie toevoegen" })).toBeVisible();
});

test("stale update returns 409 once, retains local intent and refreshes GET-only to server truth", async ({ page }) => {
    await openItem(page, IDS.staleUpdateItem);
    await noteCards(page).getByRole("button", { name: "Bewerken" }).click();
    const editor = noteEditor(page);
    await appendEditorText(editor, " E2E lokale stale update");
    fixtureAction("note-stale-update");
    let patches = 0;
    let gets = 0;
    page.on("request", (request) => {
        if (isNoteMemberRequest(request, IDS.staleUpdateNote, "PATCH")) {
            patches += 1;
        }
        if (isNotesRequest(request, IDS.staleUpdateWork)) {
            gets += 1;
        }
    });
    const responsePromise = page.waitForResponse((response) => (
        isNoteMemberRequest(response.request(), IDS.staleUpdateNote, "PATCH")
    ));
    await notesRegion(page).getByRole("button", { name: "Opslaan" }).click();
    expect((await responsePromise).status()).toBe(409);
    await expect(editor).toContainText("E2E lokale stale update");
    await expect(notesRegion(page).getByText(
        "Deze notitie is intussen gewijzigd. Vernieuw de notities voordat je opnieuw bewerkt."
    )).toBeVisible();
    expect(patches).toBe(1);
    expect(gets).toBe(0);

    await notesRegion(page).getByRole("button", { name: "Notities vernieuwen" }).click();
    await expect(noteEditor(page)).toHaveCount(0);
    await expect(noteCards(page)).toContainText("E2E serverstate na stale update");
    await expect(page.getByRole("heading", { level: 2, name: "Privénotities" })).toBeFocused();
    expect(patches).toBe(1);
    expect(gets).toBe(1);
    await page.reload();
    await expect(noteCards(page)).toContainText("E2E serverstate na stale update");
    await expect(noteCards(page)).not.toContainText("E2E lokale stale update");
});

test("stale delete returns 409 once and refresh keeps the externally updated Note", async ({ page }) => {
    await openItem(page, IDS.staleDeleteItem);
    const card = noteCards(page);
    await card.getByRole("button", { name: "Verwijderen" }).click();
    fixtureAction("note-stale-delete");
    let deletes = 0;
    let gets = 0;
    page.on("request", (request) => {
        if (isNoteMemberRequest(request, IDS.staleDeleteNote, "DELETE")) {
            deletes += 1;
        }
        if (isNotesRequest(request, IDS.staleDeleteWork)) {
            gets += 1;
        }
    });
    const dialog = page.getByRole("dialog", { name: "Privénotitie verwijderen?" });
    const responsePromise = page.waitForResponse((response) => (
        isNoteMemberRequest(response.request(), IDS.staleDeleteNote, "DELETE")
    ));
    await dialog.getByRole("button", { name: "Definitief verwijderen" }).click();
    expect((await responsePromise).status()).toBe(409);
    await expect(dialog.getByText("Deze notitie is intussen gewijzigd. Vernieuw de notities.")).toBeVisible();
    await expect(card).toBeVisible();
    expect(deletes).toBe(1);
    expect(gets).toBe(0);
    await dialog.getByRole("button", { name: "Vernieuwen" }).click();
    await expect(dialog).toHaveCount(0);
    await expect(noteCards(page)).toContainText("E2E serverstate na stale delete");
    await expect(page.getByRole("heading", { level: 2, name: "Privénotities" })).toBeFocused();
    expect(deletes).toBe(1);
    expect(gets).toBe(1);
    await page.reload();
    await expect(noteCards(page)).toContainText("E2E serverstate na stale delete");
});

test("an externally deleted member returns 404 without retry and reconciles by GET only", async ({ page }) => {
    await openItem(page, IDS.unavailableItem);
    await noteCards(page).getByRole("button", { name: "Bewerken" }).click();
    const editor = noteEditor(page);
    await appendEditorText(editor, " E2E lokale unavailable edit");
    fixtureAction("note-unavailable-delete");
    let patches = 0;
    let gets = 0;
    page.on("request", (request) => {
        if (isNoteMemberRequest(request, IDS.unavailableNote, "PATCH")) {
            patches += 1;
        }
        if (isNotesRequest(request, IDS.unavailableWork)) {
            gets += 1;
        }
    });
    const responsePromise = page.waitForResponse((response) => (
        isNoteMemberRequest(response.request(), IDS.unavailableNote, "PATCH")
    ));
    await notesRegion(page).getByRole("button", { name: "Opslaan" }).click();
    expect((await responsePromise).status()).toBe(404);
    await expect(editor).toContainText("E2E lokale unavailable edit");
    await expect(notesRegion(page).getByText(
        "Deze notitie is niet meer beschikbaar. Vernieuw de notities."
    )).toBeVisible();
    expect(patches).toBe(1);
    expect(gets).toBe(0);
    await notesRegion(page).getByRole("button", { name: "Notities vernieuwen" }).click();
    await expect(noteCards(page)).toHaveCount(0);
    await expect(page.getByRole("heading", { level: 2, name: "Privénotities" })).toBeFocused();
    expect(patches).toBe(1);
    expect(gets).toBe(1);
});

test("foreign Notes never leak and manager access cannot override owner-scoped 404", async ({ page }) => {
    const fixture = fixtureAction("state");
    expect(fixture.state.actor_manages_other_library).toBe(true);
    expect(fixture.state.private_notes[IDS.foreignNote]).toMatchObject({
        owner: "other",
        version: 1,
        work_id: IDS.historyWork,
    });
    await openItem(page, IDS.historyItem, IDS.otherLibrary);
    await expect(noteCards(page)).toHaveCount(10);
    await notesRegion(page).getByRole("button", { name: "Meer laden" }).click();
    await expect(noteCards(page)).toHaveCount(13);
    await expect(notesRegion(page)).not.toContainText("E2E FOREIGN PRIVATE NOTE MUST NEVER LEAK");
    await expect(notesRegion(page).locator(`[data-private-note-id='${IDS.foreignNote}']`)).toHaveCount(0);
    expect((await notesRegion(page).innerText())).not.toContain(IDS.foreignNote);

    const config = await restConfig(page);
    const headers = { "X-WP-Nonce": config.nonce };
    const unknown = "e2e-private-note-unknown";
    const patchBody = {
        content: "<p>E2E verboden foreign update.</p>",
        expected_version: 1,
    };
    const foreignPatch = await page.request.patch(
        `${config.root}me/private-notes/${IDS.foreignNote}`,
        { data: patchBody, headers }
    );
    const unknownPatch = await page.request.patch(
        `${config.root}me/private-notes/${unknown}`,
        { data: patchBody, headers }
    );
    expect(foreignPatch.status()).toBe(404);
    expect(unknownPatch.status()).toBe(404);
    expect(await foreignPatch.json()).toEqual(await unknownPatch.json());
    const foreignDelete = await page.request.delete(
        `${config.root}me/private-notes/${IDS.foreignNote}`,
        { data: { expected_version: 1 }, headers }
    );
    const unknownDelete = await page.request.delete(
        `${config.root}me/private-notes/${unknown}`,
        { data: { expected_version: 1 }, headers }
    );
    expect(foreignDelete.status()).toBe(404);
    expect(unknownDelete.status()).toBe(404);
    expect(await foreignDelete.json()).toEqual(await unknownDelete.json());
    expect(fixtureAction("state").state.private_notes[IDS.foreignNote].version).toBe(1);
});

test("Work-wide pagination appends 13 ordered Notes once across Items and Editions", async ({ page }) => {
    const urls = [];
    page.on("request", (request) => {
        if (isNotesRequest(request, IDS.historyWork)) {
            urls.push(request.url());
        }
    });
    await openItem(page, IDS.historyItem, IDS.otherLibrary);
    await expect(noteCards(page)).toHaveCount(10);
    const firstPage = await noteCards(page).allTextContents();
    expect(firstPage.map((text) => text.match(/paginanotitie (\d+)/)?.[1]))
        .toEqual(["13", "12", "11", "10", "09", "08", "07", "06", "05", "04"]);
    const more = notesRegion(page).getByRole("button", { name: "Meer laden" });
    await more.focus();
    await page.keyboard.press("Enter");
    await expect(noteCards(page)).toHaveCount(13);
    await expect(notesRegion(page).getByRole("button", { name: "Meer laden" })).toHaveCount(0);
    await expect(page.getByRole("heading", { level: 2, name: "Privénotities" })).toBeFocused();
    const all = await noteCards(page).allTextContents();
    expect(new Set(all).size).toBe(13);
    expect(all.map((text) => text.match(/paginanotitie (\d+)/)?.[1]))
        .toEqual(["13", "12", "11", "10", "09", "08", "07", "06", "05", "04", "03", "02", "01"]);
    expect(urls).toHaveLength(2);
    expect(new URL(urls[0]).searchParams.get("cursor")).toBeNull();
    expect(new URL(urls[1]).searchParams.get("cursor")).toEqual(expect.any(String));

    for (const itemId of [IDS.sameEditionItem, IDS.otherEditionItem]) {
        await openItem(page, itemId, IDS.otherLibrary);
        await expect(noteCards(page)).toHaveCount(10);
        await expect(noteCards(page).first()).toContainText("E2E paginanotitie 13");
    }
});

test("pagination failure preserves page one and retries the identical cursor GET", async ({ page }) => {
    const continuationUrls = [];
    let failOnce = true;
    await page.route(`**${notePath(IDS.historyWork)}?*`, async (route) => {
        const url = new URL(route.request().url());

        if (url.searchParams.has("cursor")) {
            continuationUrls.push(url.toString());

            if (failOnce) {
                failOnce = false;
                await route.fulfill(restError("biblio_internal_error", 500));
                return;
            }
        }

        await route.continue();
    });
    await openItem(page, IDS.historyItem, IDS.otherLibrary);
    await notesRegion(page).getByRole("button", { name: "Meer laden" }).click();
    const retry = notesRegion(page).getByRole("button", { name: "Opnieuw proberen" });
    await expect(noteCards(page)).toHaveCount(10);
    await expect(notesRegion(page).getByText("Meer privénotities konden niet worden geladen.")).toBeVisible();
    await expect(retry).toBeFocused();
    await retry.click();
    await expect(noteCards(page)).toHaveCount(13);
    expect(continuationUrls).toHaveLength(2);
    expect(new URL(continuationUrls[0]).searchParams.get("cursor"))
        .toBe(new URL(continuationUrls[1]).searchParams.get("cursor"));
});

test("successful PATCH plus failed reconciliation never repeats the mutation and recovers GET-only", async ({ page }) => {
    let failRefresh = false;
    let pageOneGets = 0;
    let patches = 0;
    await page.route(`**${notePath(IDS.refreshWork)}?*`, async (route) => {
        if (failRefresh && !new URL(route.request().url()).searchParams.has("cursor")) {
            pageOneGets += 1;

            if (pageOneGets === 1) {
                await route.fulfill(restError("biblio_core_unavailable", 503));
                return;
            }
        }

        await route.continue();
    });
    page.on("request", (request) => {
        if (isNoteMemberRequest(request, IDS.refreshNote, "PATCH")) {
            patches += 1;
        }
    });
    await openItem(page, IDS.refreshItem);
    await noteCards(page).getByRole("button", { name: "Bewerken" }).click();
    await appendEditorText(noteEditor(page), " E2E persisted ondanks refresh failure");
    failRefresh = true;
    const responsePromise = page.waitForResponse((response) => (
        isNoteMemberRequest(response.request(), IDS.refreshNote, "PATCH")
    ));
    await notesRegion(page).getByRole("button", { name: "Opslaan" }).click();
    expect((await responsePromise).status()).toBe(200);
    await expect(notesRegion(page).getByText(
        "De wijziging is opgeslagen, maar de notitielijst kon niet worden vernieuwd."
    )).toBeVisible();
    await expect(noteCards(page)).toContainText("E2E persisted ondanks refresh failure");
    expect(patches).toBe(1);
    expect(pageOneGets).toBe(1);
    await notesRegion(page).getByRole("button", { name: "Notities vernieuwen" }).click();
    await expect(notesRegion(page).getByText(
        "De wijziging is opgeslagen, maar de notitielijst kon niet worden vernieuwd."
    )).toHaveCount(0);
    await expect(noteCards(page)).toContainText("E2E persisted ondanks refresh failure");
    expect(patches).toBe(1);
    expect(pageOneGets).toBe(2);
    await page.reload();
    await expect(noteCards(page)).toContainText("E2E persisted ondanks refresh failure");
});

test("supported HTML stays semantic, unsafe API and saved payloads never enter executable DOM, and paste is plain text", async ({ page }) => {
    await page.addInitScript(() => { window.__biblioE2eXss = 0; });
    await openItem(page, IDS.reflowItem);
    const body = noteCards(page).locator(".biblio-ui__note-body");
    await expect(body.locator("strong")).toHaveText("vet");
    await expect(body.locator("em")).toHaveText("cursief");
    await expect(body.locator("ol > li")).toHaveCount(2);
    await expect(body.locator("blockquote")).toHaveText("E2E responsive citaat.");
    await expect(body.locator("script, style, a, img, [onclick], [onerror], [style]")).toHaveCount(0);
    expect(await body.locator("*").evaluateAll((nodes) => (
        nodes.every((node) => node.attributes.length === 0)
    ))).toBe(true);

    const config = await restConfig(page);
    const headers = { "X-WP-Nonce": config.nonce };
    for (const content of [
        "<script>window.__biblioE2eXss = 1</script><p>E2E script</p>",
        "<p onclick=\"window.__biblioE2eXss = 1\">E2E event</p>",
        "<p style=\"color:red\">E2E style</p>",
    ]) {
        const response = await page.request.post(
            `${config.root}me/works/${IDS.reflowWork}/private-notes`,
            { data: { content }, headers }
        );
        expect(response.status()).toBe(422);
        expect(JSON.stringify(await response.json())).not.toContain(content);
    }
    await page.reload();
    await expect(noteCards(page)).toHaveCount(1);
    expect(await page.evaluate(() => window.__biblioE2eXss)).toBe(0);

    await page.getByRole("button", { name: "Notitie toevoegen" }).click();
    const editor = noteEditor(page);
    await editor.evaluate((node) => {
        node.focus();
        const clipboard = new DataTransfer();
        clipboard.setData("text/plain", "<strong>E2E geplakt</strong> gewone tekst");
        clipboard.setData("text/html", "<strong style='color:red' onclick='window.__biblioE2eXss=1'>E2E rijk</strong>");
        node.dispatchEvent(new ClipboardEvent("paste", {
            bubbles: true,
            cancelable: true,
            clipboardData: clipboard,
        }));
    });
    await expect(editor).toContainText("<strong>E2E geplakt</strong> gewone tekst");
    await expect(editor.locator("strong, [style], [onclick]")).toHaveCount(0);
    expect(await page.evaluate(() => window.__biblioE2eXss)).toBe(0);
    await notesRegion(page).getByRole("button", { name: "Annuleren" }).click();
    await page.getByRole("dialog", { name: "Wijzigingen niet opslaan?" })
        .getByRole("button", { name: "Doorgaan zonder opslaan" })
        .click();

    await page.route(`**${notePath(IDS.injectedWork)}?*`, async (route) => {
        await route.fulfill({
            status: 200,
            contentType: "application/json",
            body: JSON.stringify({
                data: {
                    items: [{
                        private_note_id: "e2e-private-note-injected",
                        content_html: "<p onclick=\"window.__biblioE2eXss=1\">E2E injected</p><script>window.__biblioE2eXss=1</script>",
                        version: 1,
                    }],
                    next_cursor: null,
                },
            }),
        });
    });
    await page.goto(libraryUrl(IDS.injectedItem, IDS.otherLibrary));
    await expect(notesRegion(page).getByText("Privénotities konden niet worden geladen.")).toBeVisible();
    await expect(notesRegion(page).locator("script, [onclick]")).toHaveCount(0);
    expect(await page.evaluate(() => window.__biblioE2eXss)).toBe(0);
});

test("keyboard-only Notes controls, toolbar, Save, Cancel and delete dialog remain operable", async ({ page }) => {
    await openItem(page, IDS.zeroItem);
    const add = page.getByRole("button", { name: "Notitie toevoegen" });
    await add.focus();
    await page.keyboard.press("Enter");
    const editor = noteEditor(page);
    await expect(editor).toBeFocused();
    await page.keyboard.press("Shift+Tab");
    const quote = notesRegion(page).getByRole("button", { name: "Citaat" });
    await expect(quote).toBeFocused();
    await page.keyboard.press("Space");
    await expect(editor).toBeFocused();
    await page.keyboard.type("E2E toetsenbordnotitie");
    const save = notesRegion(page).getByRole("button", { name: "Opslaan" });
    await tabTo(page, save);
    await page.keyboard.press("Enter");
    await expect(notesRegion(page).getByRole("status")).toHaveText("Privénotitie opgeslagen.");
    await expect(notesRegion(page).getByRole("status")).toBeFocused();
    const card = noteCards(page).filter({ hasText: "E2E toetsenbordnotitie" });
    await expect(card).toBeVisible();

    const edit = card.getByRole("button", { name: "Bewerken" });
    await edit.focus();
    await page.keyboard.press("Enter");
    await expect(noteEditor(page)).toBeFocused();
    await tabTo(page, notesRegion(page).getByRole("button", { name: "Opslaan" }));
    await tabTo(page, notesRegion(page).getByRole("button", { name: "Annuleren" }));
    await page.keyboard.press("Enter");
    await expect(edit).toBeFocused();

    const remove = card.getByRole("button", { name: "Verwijderen" });
    await remove.focus();
    await page.keyboard.press("Enter");
    let dialog = page.getByRole("dialog", { name: "Privénotitie verwijderen?" });
    await expect(dialog.getByRole("button", { name: "Annuleren" })).toBeFocused();
    await page.keyboard.press("Escape");
    await expect(remove).toBeFocused();
    await page.keyboard.press("Space");
    dialog = page.getByRole("dialog", { name: "Privénotitie verwijderen?" });
    await tabTo(page, dialog.getByRole("button", { name: "Definitief verwijderen" }));
    await page.keyboard.press("Enter");
    await expect(card).toHaveCount(0);
    await expect(notesRegion(page).getByRole("status")).toHaveText("Privénotitie verwijderd.");
});

test("real Notes pass 320, mobile, tablet, desktop and 640px reflow-equivalent semantics", async ({ page }) => {
    for (const viewport of [
        { width: 320, height: 800, label: "320px" },
        { width: 390, height: 844, label: "mobile" },
        { width: 640, height: 900, label: "200%-reflow-equivalent" },
        { width: 900, height: 900, label: "tablet" },
        { width: 1280, height: 900, label: "desktop" },
    ]) {
        await page.setViewportSize(viewport);
        await openItem(page, IDS.reflowItem);
        await expect(noteCards(page)).toHaveCount(1);
        const noteList = notesRegion(page).locator(".biblio-ui__note-list");
        await expect(noteList).toHaveCount(1);
        await expect(noteList.locator(":scope > .biblio-ui__note-card")).toHaveCount(1);
        await expectNoHorizontalOverflow(page);
        const widths = await notesRegion(page).evaluate((region) => {
            const body = region.querySelector(".biblio-ui__note-body");
            return {
                regionScroll: region.scrollWidth,
                regionClient: region.clientWidth,
                bodyScroll: body?.scrollWidth ?? 0,
                bodyClient: body?.clientWidth ?? 0,
            };
        });
        expect(widths.regionScroll, viewport.label).toBeLessThanOrEqual(widths.regionClient);
        expect(widths.bodyScroll, viewport.label).toBeLessThanOrEqual(widths.bodyClient);

        const edit = noteCards(page).getByRole("button", { name: "Bewerken" });
        await edit.focus();
        await page.keyboard.press("Enter");
        const editor = noteEditor(page);
        await expect(editor).toBeFocused();
        await expect(editor).toHaveRole("textbox");
        await expect(editor).toHaveAccessibleName("Privénotitie bewerken");
        await expect(editor).toHaveAttribute("aria-multiline", "true");
        const toolbar = notesRegion(page).getByRole("toolbar", { name: "Notitie opmaken" });
        await expect(toolbar).toBeVisible();
        await expect(toolbar.getByRole("button")).toHaveCount(5);
        const editorDimensions = await editor.evaluate((node) => ({
            scroll: node.scrollWidth,
            client: node.clientWidth,
        }));
        expect(editorDimensions.scroll, viewport.label).toBeLessThanOrEqual(editorDimensions.client);
        const controls = notesRegion(page).getByRole("button");
        for (let index = 0; index < await controls.count(); index += 1) {
            const box = await controls.nth(index).boundingBox();
            expect(box?.height ?? 0, `${viewport.label} target ${index}`).toBeGreaterThanOrEqual(44);
        }
        await notesRegion(page).getByRole("button", { name: "Annuleren" }).click();
        await expect(edit).toBeFocused();

        const remove = noteCards(page).getByRole("button", { name: "Verwijderen" });
        await remove.click();
        const dialog = page.getByRole("dialog", { name: "Privénotitie verwijderen?" });
        await expect(dialog).toHaveAccessibleDescription(
            "Deze notitie wordt definitief verwijderd. Dit kan niet ongedaan worden gemaakt."
        );
        const box = await dialog.boundingBox();
        expect(box?.width ?? Infinity, viewport.label).toBeLessThanOrEqual(viewport.width);
        expect(box?.height ?? Infinity, viewport.label).toBeLessThanOrEqual(viewport.height);

        if (viewport.width < 768) {
            expect(Math.abs((box?.y ?? 0) + (box?.height ?? 0) - viewport.height), viewport.label)
                .toBeLessThanOrEqual(1);
            expect(box?.width ?? 0, viewport.label).toBe(viewport.width);
        } else {
            expect(box?.width ?? Infinity, viewport.label).toBeLessThanOrEqual(512);
            expect(Math.abs((box?.x ?? 0) + (box?.width ?? 0) / 2 - viewport.width / 2), viewport.label)
                .toBeLessThanOrEqual(1);
        }
        await page.keyboard.press("Escape");
        await expect(remove).toBeFocused();
        await expectNoHorizontalOverflow(page);
    }
});
