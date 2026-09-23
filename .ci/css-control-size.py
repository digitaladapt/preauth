#!/usr/bin/env python3
"""
css-control-size.py — finds form controls whose rendered font-size is below 16px.

This is the precise version of the check. A naive `grep font-size` on the source
produces both false negatives and false positives:

  FALSE NEGATIVE: vital-pulse declares `font-size: 0.95rem` on its inputs.
                  0.95rem x 16px = 15.2px — under the iOS auto-zoom threshold
                  — but it never appears as a `px` literal.

  FALSE POSITIVE: task-weaver has many `font-size: 11px` rules for table
                  headers and labels. Those are fine. It's *form controls*
                  that matter, so only rules whose selector targets a control
                  should be judged.

  FALSE POSITIVE: preauth declares `button, input { font-size: 0.9em }`, which
                  looks small as an `em` value — but its root is
                  `html { font-size: 1.5em }` = 24px, so the real rendered
                  size is 21.6px. `em` must be resolved against the parsed root.

So this walks CSS rules, keeps only control-targeting selectors, resolves
px / rem / em to a pixel value, and reports anything under the threshold.

Usage:  css-control-size.py <threshold-px> <file> [file...]
Output: one line per violation: "<file>:<line-ish>\t<selector>\t<raw>\t<computed-px>"
Exit:   0 = clean, 1 = violations found
"""

from __future__ import annotations

import re
import sys

# Selectors that style an actual interactive form control.
CONTROL_RE = re.compile(
    r"""(?ix)
    (?:^|[\s,>+~\[.(#])          # boundary
    (?:
        input | select | textarea | button
      | \.input\b | \.form-control\b | \.btn\b
      | \[type= | \.tag-input\b | \.schedule-number\b
    )
    """,
)

# font-size: 14px  |  font-size: 0.95rem  |  font-size: 0.9em
FONT_SIZE_RE = re.compile(
    r"font-size\s*:\s*(?P<val>[0-9]*\.?[0-9]+)\s*(?P<unit>px|rem|em|pt)\b",
    re.IGNORECASE,
)

# font: 14px/1.5 ...  (shorthand — sets font-size implicitly)
FONT_SHORTHAND_RE = re.compile(
    r"font\s*:\s*(?:[^;{}]*?\s)?(?P<val>[0-9]*\.?[0-9]+)\s*(?P<unit>px|rem|em|pt)\b",
    re.IGNORECASE,
)

# html { font-size: 1.5em }  — establishes the em base.
ROOT_SELECTOR_RE = re.compile(r"(?i)^\s*(?:html|:root)\s*$")

DEFAULT_ROOT_PX = 16.0
PT_TO_PX = 4.0 / 3.0


def strip_comments(css: str) -> str:
    return re.sub(r"/\*.*?\*/", " ", css, flags=re.DOTALL)


def parse_rules(css: str):
    """Yield (selector, body, index) for every rule, including nested ones."""
    css = strip_comments(css)
    stack: list[str] = []
    acc = ""
    for i, ch in enumerate(css):
        if ch == "{":
            stack.append(acc.strip())
            acc = ""
        elif ch == "}":
            selector = stack.pop() if stack else ""
            if selector:
                # Body is what accumulated inside this rule; recover it from
                # the source between the opening brace and here.
                yield selector, "", i
            acc = ""
        else:
            acc += ch


def parse_rules_with_bodies(css: str):
    """Yield (selector, body). Handles nesting (@media) by tracking depth."""
    css = strip_comments(css)
    stack: list[str] = []
    out: list[tuple[str, str]] = []
    acc = ""
    body_start: list[int] = []
    for i, ch in enumerate(css):
        if ch == "{":
            stack.append(acc.strip())
            body_start.append(i + 1)
            acc = ""
        elif ch == "}":
            if stack:
                selector = stack.pop()
                start = body_start.pop() if body_start else 0
                out.append((selector, css[start:i]))
            acc = ""
        else:
            acc += ch
    return out


def resolve_px(value: float, unit: str, root_px: float) -> float:
    unit = unit.lower()
    if unit == "px":
        return value
    if unit == "rem":
        return value * DEFAULT_ROOT_PX
    if unit == "em":
        # Resolved against the root that we parsed. This is an approximation
        # (true `em` is parent-relative) but it is correct for the real case
        # that matters: a page-level `html { font-size: N }` scaling controls.
        return value * root_px
    if unit == "pt":
        return value * PT_TO_PX
    return value


def find_root_px(rules) -> float:
    """Find an explicit html/:root font-size to use as the em base."""
    for selector, body in rules:
        sel = selector.split(",")[0].strip()
        if ROOT_SELECTOR_RE.match(sel):
            m = FONT_SIZE_RE.search(body)
            if m:
                val = float(m.group("val"))
                unit = m.group("unit")
                # Root em is relative to the 16px default.
                return resolve_px(val, unit, DEFAULT_ROOT_PX)
    return DEFAULT_ROOT_PX


def check_file(path: str, threshold: float) -> list[tuple[str, str, str, float]]:
    violations: list[tuple[str, str, str, float]] = []
    try:
        with open(path, "r", encoding="utf-8", errors="replace") as fh:
            css = fh.read()
    except OSError:
        return violations

    # Only look at things that plausibly contain CSS.
    if "{" not in css:
        return violations

    rules = parse_rules_with_bodies(css)
    root_px = find_root_px(rules)

    for selector, body in rules:
        # Skip at-rule wrappers; their inner rules are yielded separately.
        head = selector.strip()
        if head.startswith("@"):
            continue
        # A rule may have several comma-separated selectors; judge each.
        for one in head.split(","):
            one = one.strip()
            if not one:
                continue

            is_inherited_base = bool(re.match(r"(?i)^(?:html|body)$", one))
            targets_control = bool(CONTROL_RE.search(one))
            if not targets_control and not is_inherited_base:
                continue

            # Ignore rules that only set colours etc. — we want font-size.
            m = FONT_SIZE_RE.search(body)
            if m:
                val = float(m.group("val"))
                unit = m.group("unit")
                raw = f"{m.group('val')}{unit}"
            elif is_inherited_base:
                # `body { font: 14px/1.5 }` sets the base every unstyled control
                # inherits. Only the shorthand carries a size here.
                m2 = FONT_SHORTHAND_RE.search(body)
                if not m2:
                    continue
                val = float(m2.group("val"))
                unit = m2.group("unit")
                raw = f"font-shorthand {m2.group('val')}{unit}"
            else:
                # A control-targeting rule with no size of its own inherits
                # whatever body provides, which is reported separately.
                continue

            computed = resolve_px(val, unit, root_px)
            if computed < threshold:
                violations.append((path, one, raw, computed))
    return violations


def main() -> int:
    if len(sys.argv) < 3:
        print(__doc__, file=sys.stderr)
        return 2
    try:
        threshold = float(sys.argv[1])
    except ValueError:
        print(f"threshold must be a number, got {sys.argv[1]!r}", file=sys.stderr)
        return 2

    total = 0
    for path in sys.argv[2:]:
        for vpath, selector, raw, computed in check_file(path, threshold):
            print(f"{vpath}\t{selector}\t{raw}\t{computed:.1f}px")
            total += 1

    if total:
        print(
            f"\n{total} control style(s) render below {threshold:.0f}px. "
            "iOS Safari auto-zooms any focused control under 16px — this is the "
            "trigger that `user-scalable=no` was masking (GUIDING-LIGHT §3.3a).",
            file=sys.stderr,
        )
        return 1
    return 0


if __name__ == "__main__":
    sys.exit(main())
