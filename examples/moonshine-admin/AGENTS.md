# AGENTS.md

Notes for AI agents working in this folder. Read before changing anything:
most surprises come from *where* state lives (image vs. volume), not from the
code itself.

## What this folder is

A self-contained Docker Compose stack: an SMF 2.1.8 forum with the SMF RESTful
API mod pre-installed (seed dump) and a MoonShine V admin panel that manages
the forum through the API. The mod's sources are **this repository** (`src/`
at its root, `../../src/` relative to this folder) — the archive is packed
from them by the `mod-build` helper, nothing is copied from elsewhere.

User-facing documentation: [README.md](README.md) / [README.ru.md](README.ru.md)
(quick start), [STACK.md](STACK.md) / [STACK.ru.md](STACK.ru.md) (full details).

## Ground rules

- Do not run `docker compose down -v` without explicit permission — it destroys
  both databases, the forum files and the generated admin panel state.
- Never put secrets into tracked files: `.env` is ignored, and
  `dockerfiles/nginx/certs/*` (private keys) must stay out of the repository.
- Verify a change end to end (API call and/or the panel in a browser) before
  claiming it works.

## Where state lives

| Container | Image built from | Stateful paths |
|---|---|---|
| `php` | `dockerfiles/php/` | `/var/www/html` is the **`web` volume** (forum files, `Packages/`) |
| `db` | stock `mariadb:12` (no image build; the seed dump is rendered at container start by `dockerfiles/mariadb/render-smf-dump.sh`) | `data` volume (MariaDB), seeded from `dockerfiles/dump.sql` |
| `api-admin-fpm`, `moonshine-queue`, `moonshine-scheduler` | `dockerfiles/moonshine-v/` (target `production`) | `/var/www/html/storage` is the **`app_state` volume** |
| `moonshine-web` | same Dockerfile, target `web-local` | HTTPS with a self-signed certificate generated at build |
| `nginx` | `dockerfiles/nginx/` | forum: HTTPS + `:8081` plain HTTP inside the network |

- `app/`, `resources/views/`, `lang/`, `docker/*.php` inside `api-admin-fpm`
  come from the **image**. Editing them in a running container is a temporary
  hack — put the change in `dockerfiles/moonshine-v/` and rebuild.
- Generated panel resources (`app/MoonShine/ApiAdmin/...`) are symlinks into
  the `app_state` volume: they survive container recreation but **not** a
  volume reset.
- The forum's `/var/www/html` is a volume, so rebuilding the `php` image does
  **not** update the running forum — copy files in.

## Boot flow (no manual panel setup)

`docker compose up -d --build` runs the one-shot services in order:
`moonshine-init` (storage, `APP_KEY`) → `moonshine-migrate` (migrations,
administrator) → `moonshine-seed` (registers the `smf_forum` service, generates
the five panel resources from the forum API; waits for the forum API to answer;
idempotent) → then the panel itself.

Cold-start prerequisites: the SMF forum archive in `dockerfiles/downloads/`
(`smf_2-1-8_install.zip` — manual browser download, the mirror blocks `curl`)
and the mod archive (`docker compose run --rm mod-build` packs it from `src/`;
the build is deterministic — the same tree yields the same sha256, which
`dockerfiles/dump.sql` records).

## Change → verification cheat sheet

| You changed | What it takes to apply it |
|---|---|
| `compose.yaml`, `.env` | `docker compose up -d` |
| `dockerfiles/php/…` | `docker compose build php`, then recreate `php` **and** sync the files into the `web` volume |
| `dockerfiles/moonshine-v/…` | `docker compose build api-admin-fpm`, then recreate `api-admin-fpm` and restart `moonshine-web` |
| `lang/…` (translations) | rebuild + `docker compose exec api-admin-fpm php artisan optimize:clear` |
| the mod (`src/`) | `docker compose run --rm mod-build`, then `docker compose build php`, recreate `php` and sync the archive into the `web` volume |

After recreating `php` or `api-admin-fpm`, restart `nginx` and `moonshine-web`:
they resolve container addresses at startup and keep sending traffic to the
old IP (symptom: 404 "File not found." on every request).

## Verification recipes

Forum API (from the host):

```bash
curl -sk -H "X-API-Key: smf-dev-key-0001" https://localhost:8443/api/v1/categories
```

Inside the compose network the panel uses `http://nginx:8081/api/v1`.

Panel: `https://localhost:8080/smf-forum-admin`
(`admin@localhost` / `change-me` from `.env`). HTTPS uses a self-signed
certificate — accept the browser warning once per port. A resource page looks
like `https://localhost:8080/smf-forum-admin/resource/smf-forum-board-resource/board-index-page`.

Logs: `docker compose logs -f moonshine-seed` (first boot),
`docker compose logs -f api-admin-fpm`,
`docker compose exec api-admin-fpm tail -f storage/logs/laravel.log`.

## Panel conventions

- The panel PUTs the whole item (`AbstractRestResource::save()` uses
  `$item->toArray()`), so resources whose API treats read fields as positions
  strip them in an `applyFields()` override: categories and boards drop
  `order`; topics rename `is_sticky` → `sticky` and `body_raw` → `body`.
- Generated classes are disposable: `docker/gen-smf-resources.php` (shipped in
  the image) creates and localises the five resources idempotently and
  registers the `smf_forum` service with the panel prefix `smf-forum-admin`.
  The resource and index-page base code is generated from the supported
  `stubs/api-admin/*.stub` templates, copied into the image by its Dockerfile.
  Keep shared resource behavior in those templates; generated classes live in
  the `app_state` volume and are lost on `down -v`.
- The ordering contract: the mod's write field `order` is a **0-based
  position** (categories: among all categories; boards: among siblings with
  the same `id_cat` + `id_parent`). SMF ignores `cat_order`/`board_order`, so
  the controllers translate positions into `move_after` / `move_to: before|after`
  + `target_board`.
- Member usernames (logins) are displayed **only on the edit form** — the index
  table and the detail card show the real name, which carries the link to the
  member's card.
- The board form's **Parent board select is scoped to the picked category**
  (SMF requires a parent board to belong to the same category; the mod rejects
  the rest with 400). The link is reactive: `SmfPageFields::scopeBoardParent()`
  rebuilds the parent field in the category's `reactive()` callback — note that
  ReactiveController passes the callback only the form's *reactive* fields, so
  the parent field is recreated and appended, and it must carry the
  `field-parent-wrapper` / `data-reactive-column` markers (like
  `Reactivity::reactive()` adds), or the client can't swap the HTML and the
  watcher dies with "Unknown Error".
