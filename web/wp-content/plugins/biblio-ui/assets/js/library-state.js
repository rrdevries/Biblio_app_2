function assertLibraries(libraries) {
    if (!Array.isArray(libraries)) {
        throw new TypeError("The available Libraries must be an array.");
    }

    for (const library of libraries) {
        if (
            library === null
            || typeof library !== "object"
            || typeof library.library_id !== "string"
            || library.library_id.length === 0
            || typeof library.designated_personal !== "boolean"
        ) {
            throw new TypeError("An available Library has an invalid contract.");
        }
    }
}

function selected(library, canonicalize) {
    return Object.freeze({
        state: "selected",
        library,
        canonicalize,
    });
}

export function resolveLibraryContext(libraries, routeState) {
    assertLibraries(libraries);

    if (
        routeState === null
        || typeof routeState !== "object"
        || (routeState.libraryId !== null
            && typeof routeState.libraryId !== "string")
    ) {
        throw new TypeError("The Library route state is invalid.");
    }

    if (routeState.libraryId !== null) {
        const matches = libraries.filter(
            (library) => library.library_id === routeState.libraryId
        );

        if (matches.length === 1) {
            return selected(matches[0], false);
        }

        return Object.freeze({
            state: "unavailable",
            requestedLibraryId: routeState.libraryId,
        });
    }

    if (libraries.length === 0) {
        return Object.freeze({ state: "empty" });
    }

    if (libraries.length === 1) {
        return selected(libraries[0], true);
    }

    const designated = libraries.filter(
        (library) => library.designated_personal === true
    );

    if (designated.length === 1) {
        return selected(designated[0], true);
    }

    return Object.freeze({
        state: "chooser",
        libraries: Object.freeze([...libraries]),
    });
}
