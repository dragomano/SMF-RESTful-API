#!/usr/bin/env python3
"""Compare the anchors of the English and the Russian pages of the site."""

from __future__ import annotations

import pathlib
import re
import sys

SITE = pathlib.Path(__file__).resolve().parents[1] / "site"

PAGES = [
    "",
    "guide/getting-started/",
    "guide/clean-urls/",
    "guide/api-reference/",
    "guide/extending/overview/",
    "guide/extending/adding-resource/",
    "guide/extending/query/",
    "guide/extending/authentication/",
    "guide/extending/response-hooks/",
]

HEADING = re.compile(r'<h([1-6])[^>]*\bid="([^"]*)"')


def anchors(page: str) -> list[tuple[str, str]]:
    """Return the (level, id) of the headings of a page."""
    path = SITE / page / "index.html"
    if not path.exists():
        return []
    markup = path.read_text(encoding="utf-8")
    return [(level, anchor) for level, anchor in HEADING.findall(markup)]


def main() -> int:
    problems = 0
    for page in PAGES:
        english = anchors(page)
        russian = anchors(f'ru/{page}')
        print(f'--- {page or "index"}')
        print(f'    en: {[a for _, a in english]}')
        print(f'    ru: {[a for _, a in russian]}')
        if [a for _, a in english] != [a for _, a in russian]:
            problems += 1
            print('    the locales differ')
        for level, anchor in english + russian:
            if not re.fullmatch(r'[a-z0-9][a-z0-9-]*', anchor):
                problems += 1
                print(f'    odd anchor: {anchor!r}')
    print('The anchors match.' if not problems else f'{problems} problems found.')
    return 1 if problems else 0


if __name__ == '__main__':
    sys.exit(main())
