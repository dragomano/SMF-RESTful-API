# Documentation on Zensical

The documentation of the SMF RESTful API, built with [Zensical](https://zensical.org)
— English and Russian, one site.

## Layout

```
docs/
├─ zensical.toml            # the configuration of the site
├─ requirements.txt         # the Python packages the build needs
├─ tools/                   # the scripts of the build
└─ src/
   ├─ en/                   # the English pages, built to the root of the site
   │  ├─ index.md
   │  └─ guide/…
   ├─ ru/                   # the Russian pages, built to /ru/
   │  ├─ index.md
   │  └─ guide/…
   ├─ robots.txt
   └─ stylesheets/extra.css
```

Two more folders appear while the site is built — `.zensical-i18n/` and
`site/`. The first is the scratch space of `zensical-i18n`: it lays the sources
of a locale out in `.zensical-i18n/docs/<locale>/`, builds each locale on its
own and keeps the cache there. The second is the result, the site itself. Both
are listed in `.gitignore`, and both can be deleted at any time — they come
back on the next build.

## Build

Python 3.11 or newer is needed. The commands below run in this folder,
`docs/`.

```sh
python -m venv .venv
.venv\Scripts\activate            # . .venv/bin/activate on Linux and macOS
pip install -r requirements.txt

zensical-i18n build --clean       # the site lands in site/
```

### Looking at the site

The whole site, both locales at once, is served from the built folder:

```sh
python -m http.server 8001 --directory site
```

The English locale is at `http://localhost:8001/`, the Russian one at
`http://localhost:8001/ru/`, and the language selector in the header switches
between them. The built pages link to each other with relative paths, so they
work under any prefix.

For a live preview with hot reload, the site is served by the wrapper itself:

```sh
zensical-i18n serve               # the English locale
zensical-i18n serve --lang ru     # the Russian locale
```

It serves one locale at a time, and only one preview may run at a time, because
the wrapper swaps the cache of the project while it serves. The address keeps
the path of `site_url`, so it is `http://localhost:8000/SMF-RESTful-API/` for
the English locale and `http://localhost:8000/SMF-RESTful-API/ru/` for the
Russian one.

The plain `zensical serve` doesn't work on this project: the two locales are
described in the `[project.plugins.i18n]` section of `zensical.toml`, which only
`zensical-i18n` reads. Zensical itself has no support for third-party plugins,
so it ignores that section, takes `src/en` and `src/ru` for ordinary
subfolders, and builds the pages under `/en/…` and `/ru/…` — where the
navigation, which points to `index.md` and `guide/…`, doesn't reach them.

### The versions of the two packages

Zensical itself has no multi-language support yet, so the two locales are built
by [zensical-i18n](https://github.com/hsandmann/zensical-i18n), which wraps the
`zensical` command and merges the results into one site. It hooks into the
internals of Zensical and therefore requires Zensical 0.0.x. The newest Zensical
is 0.1.1, and it has no multi-language support of its own — that is on the
[roadmap](https://zensical.org/roadmap/#internationalization), but it isn't
there yet — and `zensical-i18n` doesn't accept it anyway:

```
zensical-i18n 0.3.0  →  zensical>=0.0.70,<0.1
```

So the newest release the two work together on is **Zensical 0.0.74**, and
that is the lower bound `requirements.txt` sets.

### Windows

`zensical-i18n` 0.3.x hands the paths of the staged sources to Zensical as they
are, and on Windows they carry backslashes, which the Rust core of Zensical
rejects with `invariant: Id(Format(Path(Backslash)))`. The patch fixes the four
places that pass paths:

```sh
python tools/patch-zensical-i18n-windows.py
```

Run it once, after `pip install -r requirements.txt`, and with the Python of
the virtual environment: the script patches the `zensical-i18n` of the
environment it runs in, and it refuses to run when the package isn't there.
On Linux and macOS the paths are already POSIX and the patch isn't needed —
the workflow of GitHub Actions runs on Linux.

## The two locales

The sources of the locales live side by side, `src/en/` and `src/ru/`, and
`zensical.toml` describes them:

```toml
[project.plugins.i18n]
docs_structure = "folder"
fallback_to_default = true
```

The English pages are built to the root of the site, the Russian ones to `/ru/`.
`nav_translations` gives every entry of the navigation its Russian title, and
the Russian locale overrides `site_description` and `copyright`. A page that
has no Russian translation yet is taken from the English one and marked with a
notice.

Each locale is built on its own, so every locale gets its own `llms.txt`,
`sitemap.xml` and `search.json` — Zensical writes all three, the `llms.txt`
through the plugin of `zensical.toml`. The wrapper adds what a single build
can't:

* a language selector in the header, which takes the reader to the same page
  in the other locale,
* `hreflang` links between the translations in the sitemaps, and a section in
  every `llms.txt` that links to the `llms.txt` of the other locales,
* "edit this page" links that point to the source of the page, including the
  Russian one,
* the `lang` attribute of the language of the page.

## The anchors of the headings

Both locales of a page carry the same anchors, so a link to a section points to
the same place in either of them. They are written out explicitly, as
`## Heading { #heading }`, and the ones of the Russian pages are the ones of
their English counterparts — Zensical would derive them from the Russian text,
which leaves them empty, and number the leftovers.

The anchors are lowercase words joined by hyphens, the way the slugifier of
Material for MkDocs writes them. An anchor that starts with a digit takes a
`s-` prefix in front of it — `s-1-mod-entry-point` — which is the convention
of this project, not of the slugifier: that one would leave the digit in
front.

## Checking the build

`tools/check-build.py` checks the built site — the things the build itself
doesn't check:

* every page has exactly one heading of the first level,
* every internal link leads to a page that exists,
* every page of the navigation is in the search index,
* the language selector links to the other locale,
* the "edit this page" links point to the source of the page,
* the `llms.txt` files of both locales are there.

`tools/compare-anchors.py` compares the anchors of the two locales of every
page and rejects the ones that aren't a lowercase word, a number or a hyphen.

```sh
python tools/check-build.py
python tools/compare-anchors.py
```

The scripts look at the built site and don't depend on the folder they are
run from.

## Publishing

The workflow in `.github/workflows/docs.yml`, in the root of the repository,
builds the site, checks it and publishes it to GitHub Pages.
