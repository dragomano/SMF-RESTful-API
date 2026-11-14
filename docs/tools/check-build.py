#!/usr/bin/env python3
"""Check the built site in docs/site.

The checks are the ones the Zensical build itself doesn't make:

* every page has exactly one heading of the first level,
* every internal link points to a page that exists,
* every page of the navigation is in the search index,
* the language selector links to the other locale,
* the "edit this page" links point to the source of the page,
* the llms.txt of both locales is there.

Run it after a build:

    python docs/tools/check-build.py
"""

from __future__ import annotations

import html
import pathlib
import re
import sys
import urllib.parse

ROOT = pathlib.Path(__file__).resolve().parents[1]
SITE = ROOT / "site"

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
LOCALES = [("en", ""), ("ru", "ru/")]

H1 = re.compile(r'<h1[^>]*>(.*?)</h1>', re.S)
HREF = re.compile(r'<a[^>]+href="([^"]+)"')
EDIT = re.compile(r'https://github\.com/dragomano/SMF-RESTful-API/edit/[^"]+')
SOURCE = re.compile(r'docs/src/(?:[a-z-]+/)?[^"#]+')


def strip(markup: str) -> str:
    """Return the text of a fragment of HTML."""
    return html.unescape(re.sub(r'<[^>]+>', '', markup)).strip()


def check_page(page: str) -> list[str]:
    """Return the problems of one page."""
    path = SITE / page / "index.html"
    if not path.exists():
        return [f'{page or "index"}: the page was not built']
    markup = path.read_text(encoding="utf-8")
    problems = []
    headings = H1.findall(markup)
    if len(headings) != 1:
        problems.append(f'{page or "index"}: {len(headings)} headings of the first level')
    for href in HREF.findall(markup):
        href = html.unescape(href)
        if href.startswith(("http://", "https://", "mailto:", "#")):
            continue
        target = urllib.parse.urljoin(f'/{page}', href)
        target = urllib.parse.unquote(target).strip("/")
        resolved = SITE / target
        if not (resolved.exists() or (resolved / "index.html").exists()):
            problems.append(f'{page or "index"}: the link {href} leads nowhere')
    return problems


def check_selector() -> list[str]:
    """Return the problems of the language selector."""
    problems = []
    english = (SITE / "index.html").read_text(encoding="utf-8")
    russian = (SITE / "ru" / "index.html").read_text(encoding="utf-8")
    if "ru/" not in " ".join(html.unescape(h) for h in HREF.findall(english)):
        problems.append("the English pages don't link to the Russian locale")
    if not any(
        html.unescape(h).rstrip("/").endswith(("..", ".")) or html.unescape(h) in {"../", "./"}
        for h in HREF.findall(russian)
    ):
        problems.append("the Russian pages don't link to the English locale")
    return problems


def check_edits() -> list[str]:
    """Return the problems of the links that edit the source of a page."""
    problems = []
    for locale, prefix in LOCALES:
        page = SITE / prefix / "guide/getting-started" / "index.html"
        if not page.exists():
            continue
        links = EDIT.findall(page.read_text(encoding="utf-8"))
        if not links:
            problems.append(f'{locale}: the page has no link to edit its source')
            continue
        for link in links:
            source = SOURCE.search(link)
            if not source:
                problems.append(f'{locale}: the edit link {link} points nowhere')
            elif f'docs/src/{locale}/' not in source.group(0):
                problems.append(f'{locale}: the edit link {link} misses the locale')
    return problems


def check_search() -> list[str]:
    """Return the problems of the search indexes."""
    problems = []
    for locale, prefix in LOCALES:
        path = SITE / prefix / "search.json"
        if not path.exists():
            problems.append(f'{locale}: the search index is missing')
            continue
        text = path.read_text(encoding="utf-8")
        for page in PAGES:
            if page and page not in text:
                problems.append(f'{locale}: the search index misses {page}')
    return problems


def check_llms() -> list[str]:
    """Return the problems of the llms.txt files."""
    problems = []
    for locale, prefix in LOCALES:
        path = SITE / prefix / "llms.txt"
        if not path.exists():
            problems.append(f'{locale}: llms.txt is missing')
            continue
        text = path.read_text(encoding="utf-8")
        if "SMF RESTful API" not in text:
            problems.append(f'{locale}: llms.txt has no title')
    return problems


def main() -> int:
    if not SITE.exists():
        print("The site was not built: docs/site does not exist.")
        return 1
    problems: list[str] = []
    for locale, prefix in LOCALES:
        for page in PAGES:
            problems += check_page(f'{prefix}{page}')
    problems += check_selector()
    problems += check_edits()
    problems += check_search()
    problems += check_llms()
    for problem in problems:
        print(problem)
    print('No problems found.' if not problems else f'{len(problems)} problems found.')
    return 1 if problems else 0


if __name__ == '__main__':
    sys.exit(main())
