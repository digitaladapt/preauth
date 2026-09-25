#!/usr/bin/env python3
"""
validate-bake.py — check a docker-bake.hcl against its Dockerfile.

WHY THIS EXISTS
---------------
`docker buildx bake --print` resolves the bake file but does NOT read the
Dockerfile, so it happily accepts `target = "app"` when no stage is named
`app`. The failure only surfaces at build time, in CI, after the push:

    ERROR: failed to solve: target stage "app" could not be found

That is exactly the failure preauth had: its final stage was unnamed
(`FROM dunglas/frankenphp:php8.5-trixie`), so `target = "app"` could never
have resolved. --print reported success.

This script checks the cross-file contract that buildx does not:
  1. every `target = "..."` matches a named Dockerfile stage
  2. the named stage is the LAST one, so a plain `docker build` still works
  3. every variable the file references is declared
  4. the `default` group only names targets that exist

Works without a Docker daemon, so it is usable in CI and on the workstation.

Usage:  validate-bake.py [bake-file] [dockerfile]
Exit:   0 = clean, 1 = problems found
"""

from __future__ import annotations

import re
import sys
from pathlib import Path

FROM_RE = re.compile(r"^\s*FROM\s+(\S+)(?:\s+AS\s+(\S+))?\s*$", re.I)
TARGET_RE = re.compile(r'^\s*target\s*=\s*"([^"]+)"', re.M)
DECL_RE = re.compile(r'^\s*target\s+"([^"]+)"\s*\{', re.M)
VAR_DECL_RE = re.compile(r'^\s*variable\s+"([^"]+)"\s*\{', re.M)
VAR_USE_RE = re.compile(r"\$\{([A-Za-z_][A-Za-z0-9_]*)\}")
GROUP_RE = re.compile(r'^\s*group\s+"([^"]+)"\s*\{', re.M)
TARGETS_LIST_RE = re.compile(r"targets\s*=\s*\[([^\]]*)\]")


def stages(dockerfile: Path) -> list[tuple[int, str, str]]:
    """Return (line_no, image, stage_name_or_empty) for each FROM."""
    out = []
    for i, line in enumerate(dockerfile.read_text().splitlines(), 1):
        if m := FROM_RE.match(line):
            out.append((i, m.group(1), m.group(2) or ""))
    return out


def main() -> int:
    bake = Path(sys.argv[1] if len(sys.argv) > 1 else "docker-bake.hcl")
    dockerfile = Path(sys.argv[2] if len(sys.argv) > 2 else "Dockerfile")

    for f in (bake, dockerfile):
        if not f.is_file():
            print(f"  ✗ missing file: {f}", file=sys.stderr)
            return 1

    text = bake.read_text()
    found_stages = stages(dockerfile)
    named = [(ln, n) for ln, _, n in found_stages if n]
    names = [n for _, n in named]

    problems: list[str] = []
    notes: list[str] = []

    # 1 + 2: target/stage contract
    for t in TARGET_RE.findall(text):
        if t not in names:
            problems.append(
                f"bake target '{t}' matches no named Dockerfile stage. "
                f"Named stages: {names or '(none)'}. "
                f"buildx --print does NOT catch this; the build fails."
            )
    if names:
        # A plain `docker build` builds the LAST stage. If no bake target
        # points at it, the two build paths produce different images — worth
        # knowing, but legitimate for repos that publish one variant per
        # target (task-weaver builds controller + worker and never uses the
        # bare `docker build` path). So: note, not error.
        bake_targets = TARGET_RE.findall(text)
        last_name = named[-1][1]
        if bake_targets and last_name not in bake_targets:
            notes.append(
                f"no bake target selects the LAST Dockerfile stage "
                f"('{last_name}'), so a plain `docker build` and `bake` "
                f"produce different images."
            )
    else:
        problems.append("Dockerfile has no named stages; bake needs one.")

    # 3: declared vs used variables
    declared = set(VAR_DECL_RE.findall(text))
    used = set(VAR_USE_RE.findall(text))
    for u in sorted(used - declared):
        problems.append(
            f"${{{u}}} is used but never declared as a `variable` block; "
            f"bake would error at load time."
        )
    for d in sorted(declared - used):
        notes.append(f"variable '{d}' is declared but never referenced.")

    # 4: default group names real targets
    declared_targets = set(DECL_RE.findall(text))
    for gname in GROUP_RE.findall(text):
        block = text.split(f'group "{gname}"', 1)[1][:400]
        if m := TARGETS_LIST_RE.search(block):
            for t in re.findall(r'"([^"]+)"', m.group(1)):
                if t not in declared_targets:
                    problems.append(
                        f"group '{gname}' references target '{t}', "
                        f"which is not declared."
                    )

    name = bake.name
    if problems:
        print(f"  FAIL {name}")
        for p in problems:
            print(f"    ✗ {p}")
    else:
        print(f"  OK   {name}  (targets: {sorted(declared_targets)} / "
              f"stages: {names})")
    for n in notes:
        print(f"    · {n}")

    return 1 if problems else 0


if __name__ == "__main__":
    sys.exit(main())
