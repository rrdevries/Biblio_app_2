#!/usr/bin/env python3
"""Reject Git pushes that would publish Biblio's local vault or documentation."""

from __future__ import annotations

import subprocess
import sys


PRIVATE_PREFIXES = ("project/", "docs/", ".obsidian/")
PRIVATE_FILES = {"Biblio - Vandaag.md", "AGENTS.md"}


def git(*args: str) -> bytes:
    return subprocess.check_output(("git", *args), stderr=subprocess.PIPE)


def is_private(path: str) -> bool:
    return path in PRIVATE_FILES or path.startswith(PRIVATE_PREFIXES)


def paths_in_tree(commit: str) -> list[str]:
    raw = git("ls-tree", "-r", "--name-only", "-z", commit)
    return [path.decode("utf-8", "surrogateescape") for path in raw.split(b"\0") if path]


def changed_paths(commit: str) -> list[tuple[str, str]]:
    raw = git(
        "diff-tree", "--root", "--no-commit-id", "--name-status",
        "--no-renames", "-r", "-z", commit,
    )
    fields = [field for field in raw.split(b"\0") if field]
    if len(fields) % 2:
        raise ValueError(f"Onleesbare bestandstatus in commit {commit[:12]}")
    return [
        (fields[i].decode("ascii"), fields[i + 1].decode("utf-8", "surrogateescape"))
        for i in range(0, len(fields), 2)
    ]


def outgoing_commits(local: str, remote: str, remote_name: str) -> list[str]:
    if set(remote) == {"0"}:
        raw = git("rev-list", local, "--not", f"--remotes={remote_name}")
    else:
        subprocess.check_call(
            ("git", "merge-base", "--is-ancestor", remote, local),
            stdout=subprocess.DEVNULL,
            stderr=subprocess.DEVNULL,
        )
        raw = git("rev-list", f"{remote}..{local}")
    return raw.decode("ascii").splitlines()


def check_ref(local_ref: str, local: str, remote: str, remote_name: str) -> list[str]:
    if set(local) == {"0"}:
        return [f"{local_ref}: verwijderen van remote refs vereist aparte beoordeling"]

    problems = [
        f"{local_ref}: eindboom bevat uitgesloten pad {path}"
        for path in paths_in_tree(local) if is_private(path)
    ]
    for commit in outgoing_commits(local, remote, remote_name):
        for status, path in changed_paths(commit):
            # Removing a file already present on the public remote is allowed.
            if status != "D" and is_private(path):
                problems.append(f"{local_ref}: {commit[:12]} publiceert {path}")
    return problems


def main() -> int:
    remote_name = sys.argv[1] if len(sys.argv) > 1 else "origin"
    refs = [line.split() for line in sys.stdin if line.strip()]
    if not refs:
        print("Biblio-publicatiecontrole: geen pushrefs ontvangen", file=sys.stderr)
        return 1

    problems: list[str] = []
    for ref in refs:
        if len(ref) != 4:
            problems.append("Onleesbare pre-push-refgegevens")
            continue
        local_ref, local, _remote_ref, remote = ref
        try:
            problems.extend(check_ref(local_ref, local, remote, remote_name))
        except (OSError, subprocess.CalledProcessError, ValueError) as error:
            problems.append(f"{local_ref}: publicatiecontrole kon niet worden voltooid ({error})")

    if problems:
        print("Biblio: push gestopt. Vault, docs en AGENTS.md blijven lokaal.", file=sys.stderr)
        for problem in problems[:12]:
            print(f"- {problem}", file=sys.stderr)
        if len(problems) > 12:
            print(f"- en nog {len(problems) - 12} uitgesloten paden", file=sys.stderr)
        return 1

    print("Biblio-publicatiecontrole: geen uitgesloten paden in deze push")
    return 0


if __name__ == "__main__":
    raise SystemExit(main())
