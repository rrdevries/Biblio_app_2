let dialogSequence = 0;

function element(documentImpl, tagName, {
    className,
    text,
    attributes = {},
} = {}) {
    const node = documentImpl.createElement(tagName);

    if (className !== undefined) {
        node.className = className;
    }

    if (text !== undefined) {
        node.textContent = text;
    }

    for (const [name, value] of Object.entries(attributes)) {
        node.setAttribute(name, value);
    }

    return node;
}

function localCalendarDay(date) {
    const year = String(date.getFullYear()).padStart(4, "0");
    const month = String(date.getMonth() + 1).padStart(2, "0");
    const day = String(date.getDate()).padStart(2, "0");

    return `${year}-${month}-${day}`;
}

function isLeapYear(year) {
    return year % 4 === 0 && (year % 100 !== 0 || year % 400 === 0);
}

function isCompleteCalendarDay(value) {
    const match = /^(?<year>[0-9]{4})-(?<month>[0-9]{2})-(?<day>[0-9]{2})$/
        .exec(value);

    if (match === null) {
        return false;
    }

    const year = Number(match.groups.year);
    const month = Number(match.groups.month);
    const day = Number(match.groups.day);
    const daysInMonth = [
        31,
        isLeapYear(year) ? 29 : 28,
        31,
        30,
        31,
        30,
        31,
        31,
        30,
        31,
        30,
        31,
    ];

    return year >= 1
        && month >= 1
        && month <= 12
        && day >= 1
        && day <= daysInMonth[month - 1];
}

function button(documentImpl, label, type = "button") {
    return element(documentImpl, "button", {
        text: label,
        attributes: { type },
    });
}

export function createStartReadingView(root, {
    documentImpl = globalThis.document,
    now = () => new Date(),
    reload = () => globalThis.location.reload(),
    loginUrl,
} = {}) {
    if (
        typeof root?.append !== "function"
        || typeof documentImpl?.createElement !== "function"
    ) {
        throw new TypeError("A Biblio UI mount and Document are required.");
    }

    if (
        typeof now !== "function"
        || typeof reload !== "function"
        || typeof loginUrl !== "string"
        || loginUrl.length === 0
    ) {
        throw new TypeError("Valid Start Reading view dependencies are required.");
    }

    let activeDialog = null;

    function persistentStatus(message, reloadLabel = null) {
        const status = element(documentImpl, "section", {
            className: "biblio-ui__mutation-status",
            attributes: {
                "aria-live": "polite",
                "data-biblio-mutation-status": "true",
            },
        });
        status.append(element(documentImpl, "p", { text: message }));

        if (reloadLabel !== null) {
            const reloadButton = button(documentImpl, reloadLabel);
            reloadButton.className = "biblio-ui__control biblio-ui__control--primary";
            reloadButton.addEventListener("click", reload);
            status.append(reloadButton);
        }

        root.append(status);
        return status;
    }

    function open({ opener, submit }) {
        if (activeDialog !== null) {
            return activeDialog;
        }

        if (
            typeof opener?.focus !== "function"
            || typeof submit !== "function"
        ) {
            throw new TypeError("A Start Reading opener and submit action are required.");
        }

        dialogSequence += 1;
        const id = `biblio-start-reading-${dialogSequence}`;
        const titleId = `${id}-title`;
        const descriptionId = `${id}-description`;
        const errorId = `${id}-error`;
        const dialog = element(documentImpl, "dialog", {
            className: "biblio-ui__reading-dialog biblio-ui__start-reading-dialog",
            attributes: {
                "aria-describedby": descriptionId,
                "aria-labelledby": titleId,
                "data-biblio-start-reading": "true",
            },
        });
        const form = element(documentImpl, "form", {
            attributes: { novalidate: "" },
        });
        const title = element(documentImpl, "h2", {
            text: "Lezen starten",
            attributes: { id: titleId },
        });
        const description = element(documentImpl, "p", {
            text: "Kies de dag waarop je met lezen bent begonnen.",
            attributes: { id: descriptionId },
        });
        const label = element(documentImpl, "label", {
            text: "Startdatum",
            attributes: { for: `${id}-date` },
        });
        const input = element(documentImpl, "input", {
            attributes: {
                id: `${id}-date`,
                name: "started_on",
                type: "date",
                required: "",
                "aria-describedby": descriptionId,
            },
        });
        input.value = localCalendarDay(now());
        const error = element(documentImpl, "p", {
            className: "biblio-ui__field-error",
            attributes: {
                id: errorId,
                "aria-live": "polite",
            },
        });
        const status = element(documentImpl, "p", {
            attributes: { "aria-live": "polite" },
        });
        const controls = element(documentImpl, "div", {
            className: "biblio-ui__dialog-actions",
        });
        const cancelButton = button(documentImpl, "Annuleren");
        const submitButton = button(documentImpl, "Lezen starten", "submit");
        cancelButton.className = "biblio-ui__control biblio-ui__control--secondary";
        submitButton.className = "biblio-ui__control biblio-ui__control--primary";
        controls.append(cancelButton, submitButton);
        form.append(
            title,
            description,
            label,
            input,
            error,
            status,
            controls
        );
        dialog.append(form);

        let inFlight = false;
        let acknowledged = false;
        let acknowledgement = null;

        function setPending(pending) {
            inFlight = pending;
            input.disabled = pending;
            cancelButton.disabled = pending;
            submitButton.disabled = pending;
            form.setAttribute("aria-busy", pending ? "true" : "false");
        }

        function clearFeedback() {
            error.textContent = "";
            status.textContent = "";
            input.setAttribute("aria-invalid", "false");
            input.setAttribute("aria-describedby", descriptionId);
        }

        function fieldError(message) {
            error.textContent = message;
            input.setAttribute("aria-invalid", "true");
            input.setAttribute(
                "aria-describedby",
                `${descriptionId} ${errorId}`
            );
            input.focus();
        }

        function dismiss(restoreFocus = true) {
            if (activeDialog !== dialog) {
                return;
            }

            if (dialog.open === true) {
                dialog.close();
            }

            dialog.remove();
            activeDialog = null;

            if (restoreFocus) {
                opener.focus();
            }
        }

        function acknowledge(message) {
            if (acknowledged) {
                return;
            }

            acknowledged = true;
            dismiss();
            acknowledgement = persistentStatus(message);
        }

        function sessionRefresh() {
            setPending(false);
            input.disabled = true;
            submitButton.disabled = true;
            status.textContent = "Je sessie moet worden vernieuwd.";
            const refreshButton = button(documentImpl, "Sessie vernieuwen");
            refreshButton.className = "biblio-ui__control biblio-ui__control--primary";
            refreshButton.addEventListener("click", reload);
            controls.replaceChildren(cancelButton, refreshButton);
        }

        function authenticationRequired() {
            setPending(false);
            input.disabled = true;
            submitButton.disabled = true;
            status.textContent = "Je sessie is verlopen.";
            controls.replaceChildren(
                cancelButton,
                element(documentImpl, "a", {
                    className: "biblio-ui__control biblio-ui__control--primary",
                    text: "Opnieuw inloggen",
                    attributes: { href: loginUrl },
                })
            );
        }

        cancelButton.addEventListener("click", () => {
            if (!inFlight) {
                dismiss();
            }
        });
        dialog.addEventListener("cancel", (event) => {
            if (inFlight) {
                event.preventDefault();
                return;
            }

            event.preventDefault();
            dismiss();
        });
        form.addEventListener("submit", async (event) => {
            event.preventDefault();

            if (inFlight) {
                return;
            }

            clearFeedback();

            if (!isCompleteCalendarDay(input.value)) {
                fieldError("Vul een volledige geldige startdatum in.");
                return;
            }

            setPending(true);
            let outcome;

            try {
                outcome = await submit(input.value, { acknowledge });
            } catch {
                outcome = { state: "retryable" };
            }

            if (outcome?.state === "reconciled") {
                return;
            }

            if (outcome?.state === "aborted") {
                if (!acknowledged) {
                    dismiss(false);
                }
                return;
            }

            if (outcome?.state === "refresh-failed" && acknowledged) {
                acknowledgement?.remove();
                persistentStatus(outcome.message, "Pagina vernieuwen");
                return;
            }

            if (activeDialog !== dialog) {
                return;
            }

            if (outcome?.state === "validation-error") {
                setPending(false);
                fieldError("Controleer de startdatum.");
                return;
            }

            if (outcome?.state === "session-refresh") {
                sessionRefresh();
                return;
            }

            if (outcome?.state === "authentication-required") {
                authenticationRequired();
                return;
            }

            setPending(false);
            status.textContent = "Lezen starten is niet gelukt.";
            submitButton.textContent = "Opnieuw proberen";
        });

        root.append(dialog);
        activeDialog = dialog;

        if (typeof dialog.showModal !== "function") {
            dismiss(false);
            throw new TypeError("Native dialog support is required.");
        }

        dialog.showModal();
        input.focus();

        return dialog;
    }

    function destroy() {
        if (activeDialog !== null) {
            const dialog = activeDialog;
            activeDialog = null;

            if (dialog.open === true) {
                dialog.close();
            }

            dialog.remove();
        }
    }

    return Object.freeze({ open, destroy });
}
