import { expect, test } from "@playwright/test";

const LIBRARY_URL = "/mijn-bibliotheek/?library_id=e2e-library-actor";

async function navigationState(link) {
    return link.evaluate((node) => {
        const style = getComputedStyle(node);

        return {
            active: node.getAttribute("aria-current"),
            focused: node === document.activeElement,
            focusVisible: node.matches(":focus-visible"),
            outlineStyle: style.outlineStyle,
            outlineColor: style.outlineColor,
            outlineOffset: style.outlineOffset,
            boxShadow: style.boxShadow,
        };
    });
}

async function expectPointerState(link) {
    await link.evaluate((node) => {
        node.addEventListener("click", (event) => event.preventDefault(), { once: true });
    });
    await link.click();
    const state = await navigationState(link);
    expect(state.active).toBe("page");
    expect(state.focusVisible).toBe(false);
    expect(state.outlineStyle).toBe("none");
    expect(state.boxShadow).toContain("inset");
}

async function expectKeyboardState(page, link) {
    await page.keyboard.press("Tab");
    await link.focus();
    const state = await navigationState(link);
    expect(state.active).toBe("page");
    expect(state.focused).toBe(true);
    expect(state.focusVisible).toBe(true);
    expect(state.outlineStyle).toBe("solid");
    expect(state.outlineColor).toBe("rgb(157, 214, 255)");
    expect(state.outlineOffset).toBe("-2px");
    expect(state.boxShadow).toContain("inset");
}

test("active navigation and keyboard focus remain distinct on desktop, rail and mobile", async ({ page }, testInfo) => {
    await page.setViewportSize({ width: 1440, height: 900 });
    await page.goto(LIBRARY_URL);
    const nav = page.getByRole("navigation", { name: "Hoofdnavigatie" });
    const current = nav.locator('a[aria-current="page"]');
    await expect(current).toHaveCount(1);
    await expect(current).toHaveAttribute("aria-current", "page");
    await expectPointerState(current);
    await page.screenshot({ path: testInfo.outputPath("nav-desktop-pointer.png") });

    await expectKeyboardState(page, current);
    await page.screenshot({ path: testInfo.outputPath("nav-desktop-focus.png") });

    await page.getByRole("button", { name: "Navigatie inklappen" }).click();
    await expectPointerState(current);
    await expectKeyboardState(page, current);
    await page.screenshot({ path: testInfo.outputPath("nav-rail-focus.png") });

    await page.setViewportSize({ width: 400, height: 756 });
    await page.getByRole("button", { name: "Navigatie openen" }).click();
    await expect(page.locator(".biblio-ui__sidebar"))
        .toHaveCSS("transform", "matrix(1, 0, 0, 1, 0, 0)");
    await expectPointerState(current);
    await page.getByRole("button", { name: "Navigatie openen" }).click();
    await expect(page.locator(".biblio-ui__sidebar"))
        .toHaveCSS("transform", "matrix(1, 0, 0, 1, 0, 0)");
    await expectKeyboardState(page, current);
    expect((await page.locator(".biblio-ui__sidebar").boundingBox())?.x).toBe(0);
    await page.screenshot({ path: testInfo.outputPath("nav-mobile-focus.png") });
});
