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

    return year >= 1000
        && year <= 9999
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

export function createEndReadingView(root, {
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
        throw new TypeError("Valid End Reading view dependencies are required.");
    }

    let activeDialog = null;

    function open({ opener, submit }) {
        if (activeDialog !== null) {
            return activeDialog;
        }

        if (
            typeof opener?.focus !== "function"
            || typeof submit !== "function"
        ) {
            throw new TypeError(
                "An End Reading opener and submit action are required."
            );
        }

        dialogSequence += 1;
        const id = `biblio-end-reading-${dialogSequence}`;
        const titleId = `${id}-title`;
        const descriptionId = `${id}-description`;
        const outcomeErrorId = `${id}-outcome-error`;
        const dateErrorId = `${id}-date-error`;
        const dialog = element(documentImpl, "dialog", {
            className: "biblio-ui__reading-dialog biblio-ui__end-reading-dialog",
            attributes: {
                "aria-describedby": descriptionId,
                "aria-labelledby": titleId,
                "data-biblio-end-reading": "true",
            },
        });
        const form = element(documentImpl, "form", {
            attributes: { novalidate: "" },
        });
        const title = element(documentImpl, "h2", {
            text: "Leesronde afronden",
            attributes: { id: titleId },
        });
        const description = element(documentImpl, "p", {
            text: "Leg vast of je het boek hebt uitgelezen of bent gestopt.",
            attributes: { id: descriptionId },
        });
        const outcomeGroup = element(documentImpl, "fieldset", {
            className: "biblio-ui__outcome-group",
            attributes: { "aria-describedby": descriptionId },
        });
        const legend = element(documentImpl, "legend", { text: "Uitkomst" });
        const radioOptions = [
            ["completed", "Uitgelezen"],
            ["stopped", "Gestopt"],
        ].map(([value, labelText]) => {
            const input = element(documentImpl, "input", {
                attributes: {
                    id: `${id}-${value}`,
                    name: "outcome",
                    required: "",
                    type: "radio",
                    value,
                },
            });
            const label = element(documentImpl, "label", {
                className: "biblio-ui__radio-option",
                attributes: { for: input.getAttribute("id") },
            });
            label.append(input, element(documentImpl, "span", {
                text: labelText,
            }));

            return { input, label };
        });
        const radios = radioOptions.map(({ input }) => input);
        const outcomeError = element(documentImpl, "p", {
            className: "biblio-ui__field-error",
            attributes: { id: outcomeErrorId, "aria-live": "polite" },
        });
        outcomeGroup.append(legend);
        for (const { label } of radioOptions) {
            outcomeGroup.append(label);
        }
        outcomeGroup.append(outcomeError);

        const dateLabel = element(documentImpl, "label", {
            text: "Einddatum",
            attributes: { for: `${id}-date` },
        });
        const dateInput = element(documentImpl, "input", {
            attributes: {
                id: `${id}-date`,
                name: "finished_on",
                type: "date",
                required: "",
                "aria-describedby": descriptionId,
            },
        });
        dateInput.value = localCalendarDay(now());
        const dateError = element(documentImpl, "p", {
            className: "biblio-ui__field-error",
            attributes: { id: dateErrorId, "aria-live": "polite" },
        });
        const status = element(documentImpl, "p", {
            className: "biblio-ui__dialog-status",
            attributes: { "aria-live": "polite", tabindex: "-1" },
        });
        const controls = element(documentImpl, "div", {
            className: "biblio-ui__dialog-actions",
        });
        const cancelButton = button(documentImpl, "Annuleren");
        const submitButton = button(documentImpl, "Afronden", "submit");
        cancelButton.className = "biblio-ui__control biblio-ui__control--secondary";
        submitButton.className = "biblio-ui__control biblio-ui__control--primary";
        submitButton.disabled = true;
        controls.append(cancelButton, submitButton);
        form.append(
            title,
            description,
            outcomeGroup,
            dateLabel,
            dateInput,
            dateError,
            status,
            controls
        );
        dialog.append(form);

        let inFlight = false;
        let recoveryLocked = false;

        function selectedOutcome() {
            return radios.find((radio) => radio.checked === true)?.value ?? null;
        }

        function updateSubmitAvailability() {
            submitButton.disabled = inFlight
                || recoveryLocked
                || selectedOutcome() === null
                || !isCompleteCalendarDay(dateInput.value);
        }

        function setPending(pending) {
            inFlight = pending;
            radios.forEach((radio) => { radio.disabled = pending; });
            dateInput.disabled = pending;
            cancelButton.disabled = pending;
            form.setAttribute("aria-busy", pending ? "true" : "false");
            updateSubmitAvailability();
        }

        function clearFeedback() {
            outcomeError.textContent = "";
            dateError.textContent = "";
            status.textContent = "";
            outcomeGroup.setAttribute("aria-invalid", "false");
            outcomeGroup.setAttribute("aria-describedby", descriptionId);
            dateInput.setAttribute("aria-invalid", "false");
            dateInput.setAttribute("aria-describedby", descriptionId);
        }

        function outcomeFieldError(message) {
            outcomeError.textContent = message;
            outcomeGroup.setAttribute("aria-invalid", "true");
            outcomeGroup.setAttribute(
                "aria-describedby",
                `${descriptionId} ${outcomeErrorId}`
            );
            radios[0].focus();
        }

        function dateFieldError(message) {
            dateError.textContent = message;
            dateInput.setAttribute("aria-invalid", "true");
            dateInput.setAttribute(
                "aria-describedby",
                `${descriptionId} ${dateErrorId}`
            );
            dateInput.focus();
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

        function lockForRecovery(message, recoveryControl) {
            setPending(false);
            recoveryLocked = true;
            radios.forEach((radio) => { radio.disabled = true; });
            dateInput.disabled = true;
            status.textContent = message;
            controls.replaceChildren(cancelButton, recoveryControl);
            updateSubmitAvailability();
            recoveryControl.focus();
        }

        function reloadRecovery(message, label = "Pagina vernieuwen") {
            const reloadButton = button(documentImpl, label);
            reloadButton.className = [
                "biblio-ui__control",
                "biblio-ui__control--primary",
            ].join(" ");
            reloadButton.addEventListener("click", reload);
            lockForRecovery(message, reloadButton);
        }

        function authenticationRecovery() {
            const loginLink = element(documentImpl, "a", {
                className: "biblio-ui__control biblio-ui__control--primary",
                text: "Opnieuw inloggen",
                attributes: { href: loginUrl },
            });
            lockForRecovery("Je sessie is verlopen.", loginLink);
        }

        radios.forEach((radio) => {
            radio.addEventListener("change", () => {
                outcomeError.textContent = "";
                outcomeGroup.setAttribute("aria-invalid", "false");
                outcomeGroup.setAttribute("aria-describedby", descriptionId);
                updateSubmitAvailability();
            });
        });
        dateInput.addEventListener("input", updateSubmitAvailability);
        dateInput.addEventListener("change", updateSubmitAvailability);
        cancelButton.addEventListener("click", () => {
            if (!inFlight) {
                dismiss();
            }
        });
        dialog.addEventListener("cancel", (event) => {
            event.preventDefault();

            if (!inFlight) {
                dismiss();
            }
        });
        form.addEventListener("submit", async (event) => {
            event.preventDefault();

            if (inFlight || recoveryLocked) {
                return;
            }

            clearFeedback();
            const outcome = selectedOutcome();

            if (outcome === null) {
                outcomeFieldError("Kies Uitgelezen of Gestopt.");
                updateSubmitAvailability();
                return;
            }

            if (!isCompleteCalendarDay(dateInput.value)) {
                dateFieldError("Vul een volledige geldige einddatum in.");
                updateSubmitAvailability();
                return;
            }

            setPending(true);
            let result;

            try {
                result = await submit({
                    outcome,
                    finishedOn: dateInput.value,
                });
            } catch {
                result = { state: "outcome-unknown" };
            }

            if (result?.state === "reconciled") {
                dismiss(false);
                return;
            }

            if (result?.state === "aborted") {
                dismiss(false);
                return;
            }

            if (activeDialog !== dialog) {
                return;
            }

            if (result?.state === "validation-error") {
                setPending(false);
                status.textContent = "Controleer de gekozen uitkomst en einddatum.";
                status.focus();
                return;
            }

            if (result?.state === "session-refresh") {
                reloadRecovery(
                    "Je sessie moet worden vernieuwd.",
                    "Sessie vernieuwen"
                );
                return;
            }

            if (result?.state === "authentication-required") {
                authenticationRecovery();
                return;
            }

            if (result?.state === "refresh-failed") {
                reloadRecovery(
                    "De aanvraag is mogelijk verwerkt, maar de actuele leesstatus kon niet worden bevestigd. Vernieuw de pagina voordat je opnieuw probeert."
                );
                return;
            }

            if (
                result?.state === "outcome-unknown"
                || result?.state === "unavailable"
                || result?.state === "pending"
            ) {
                reloadRecovery(
                    "De actuele leesstatus is onzeker. Vernieuw de pagina voordat je opnieuw probeert."
                );
                return;
            }

            setPending(false);

            if (result?.state === "service-unavailable") {
                status.textContent = [
                    "Biblio is tijdelijk niet beschikbaar.",
                    "Probeer het later opnieuw.",
                ].join(" ");
            } else {
                status.textContent = [
                    "De leesronde kon niet worden afgerond.",
                    "Probeer het later opnieuw.",
                ].join(" ");
            }

            submitButton.textContent = "Opnieuw proberen";
            status.focus();
            updateSubmitAvailability();
        });

        root.append(dialog);
        activeDialog = dialog;

        if (typeof dialog.showModal !== "function") {
            dismiss(false);
            throw new TypeError("Native dialog support is required.");
        }

        dialog.showModal();
        radios[0].focus();

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
