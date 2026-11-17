# MoonShine V + SMF (with the SMF RESTful API mod) in Docker

> Русская версия: [STACK.ru.md](STACK.ru.md)

A Compose stack that puts a MoonShine V admin panel in front of a live SMF forum:

- **SMF 2.1.8** — the Simple Machines Forum, pre-installed from a database seed dump (`dockerfiles/dump.sql`);
- **SMF RESTful API** — the [dragomano/smf-restful-api](https://github.com/dragomano/smf-restful-api) mod, installed through Package Manager (registered in the database, files unpacked); the archive is packed into `dockerfiles/downloads/smf_restful_api.zip` by the `mod-build` helper from this repository's sources (see below);
- **MoonShine V** ([moonshine-software/v](https://github.com/moonshine-software/v)) — a Laravel 13 / PHP 8.5 application (MoonShine 5 panel with MCP-driven resource generation) with PostgreSQL 18 and Redis 8.6.

## Stack layout

| Service | Image | Host port | Purpose |
|---|---|---|---|
| `nginx` | custom (dockerfiles/nginx) | **8085** → 80, **8443** → 443 | SMF forum (80 → 301 to 443); also listens on 8081 over plain HTTP inside the network for MoonShine V |
| `php` | custom (dockerfiles/php) | — | PHP-FPM 8.2 with SMF 2.1.8 + RESTful API mod (+ any SMF language packs placed in `downloads/`) |
| `db` | stock (mariadb:12) | — | MariaDB 12, loads `dockerfiles/dump.sql` on first start (rendered at container start) |
| `adminer` | adminer | **8082** | web UI for MariaDB (and Postgres: server `postgres`) |
| `postgres` | postgres:18-alpine | — | MoonShine V database |
| `redis` | redis:8.6-alpine | — | MoonShine V cache/sessions/queues |
| `moonshine-init` | custom (dockerfiles/moonshine-v) | — | one-shot: initialise `storage`, generate `APP_KEY` |
| `moonshine-migrate` | custom | — | one-shot: `artisan migrate` + create the panel administrator |
| `moonshine-seed` | custom | — | one-shot: register the `smf_forum` service and generate the panel resources from the forum API |
| `api-admin-fpm` | custom | — | PHP-FPM 8.5 for the MoonShine V application |
| `moonshine-queue` | custom | — | `artisan queue:work` |
| `moonshine-scheduler` | custom | — | `artisan schedule:work` |
| `moonshine-web` | custom (target `web-local`, Angie) | **8080** | MoonShine V web server |

The forum is published on **8085/8443** by default so it does not collide with the standard 80/443, which are usually taken. Every port is configurable in `.env` (`NGINX_HTTP_PORT`, `NGINX_HTTPS_PORT`, `MSV_APP_PORT`, `ADMINER_PORT`); when you change `NGINX_HTTPS_PORT`, update `FORUM_URL` as well.

## Quick start

```bash
cp .env.example .env
docker compose up -d --build
```

The first run builds the MoonShine V image from the GitHub sources (tag `MSV_VERSION`, `v1.0.0` by default) and runs migrations plus administrator creation.

| What | URL | Credentials |
|---|---|---|
| SMF forum | https://localhost:8443 (self-signed certificate) | `Test` / `test` |
| Forum REST API | https://localhost:8443/api/v1/... (see below) | `SMF_API_KEY` from `.env` |
| MoonShine V | https://localhost:8080/admin | `MSV_ADMIN_EMAIL` / `MSV_ADMIN_PASSWORD` from `.env` (`admin@localhost` / `change-me` by default) |
| Adminer | http://localhost:8082 | server `db`, user `smf`/`pass` (or server `postgres`) |

## Forum REST API

The mod is installed and enabled, and the key from `.env` is already registered in the forum:

```bash
# Clean URLs (nginx rewrite):
curl -H "X-API-Key: smf-dev-key-0001" https://localhost:8443/api/v1/members

# Or the always-working direct address:
curl -H "X-API-Key: smf-dev-key-0001" "https://localhost:8443/index.php?action=api;path=v1/members"
```

Resources: `categories`, `boards`, `topics`, `posts` (nested under topics), `members`, `groups` (GET/POST/PUT/PATCH/DELETE). Authentication: the `Authorization: Bearer ...` header, `X-API-Key`, or the `api_key` query parameter. Keys and the `api_enabled` switch live in the forum admin: **Admin → Configuration → Modification Settings → RESTful API**.

## What the API accepts on write

Writes go through `POST` (create), `PUT` (full replace) and `PATCH` (partial update). Each resource accepts every field SMF actually stores in its table, **except identifiers and server-owned values** (SMF counters, IPs, secrets, activation codes). A field can be sent either under the "friendly" name from the response or under the SMF column name.

| Resource | Writable fields | SMF column names |
|---|---|---|
| `categories` | `name`, `description`, `order`, `can_collapse` | `name`, `description`, `cat_order`, `can_collapse` |
| `boards` | `name`, `description`, `category`, `parent`, `order`, `id_profile`, `id_theme`, `override_theme`, `count_posts`, `member_groups`, `deny_member_groups`, `redirect` | `id_cat`, `id_parent`, `board_order`, `member_groups`, `deny_member_groups` |
| `topics` | `subject`, `body`, `locked`, `is_sticky`, `approved`, `num_views`, `board` | `is_sticky` (`sticky` stays as a legacy alias), `id_board` |
| `posts` | `body`, `subject`, `icon`, `smileys_enabled`, `approved`, `poster_name`, `poster_email`, `poster_time`, `modified_reason` | `reason` → `modified_reason` |
| `members` | `username`, `name`, `email`, `password`, `primary_group`, `post_group`, `additional_groups`, `posts`, `personal_text`, `signature`, `birthdate`, `website_title`, `website_url`, `usertitle`, `avatar`, `show_online`, `time_format`, `timezone`, `time_offset`, `id_theme`, `lngfile`, `smiley_set`, `pm_receive_from`, `warning`, `ignore_boards`, `is_activated`, `secret_question`, `secret_answer` | `member_name`, `real_name`, `email_address`, `passwd`, `id_group`, `id_post_group` |
| `groups` | `name`, `description`, `type`, `min_posts`, `max_messages`, `color`, `icons`, `hidden`, `parent`, `tfa_required` | `group_name`, `group_type`, `online_color`, `id_parent` |

Details:

- `PUT` requires only the resource core (`categories`/`boards` — `name` and `description`, `topics` — `subject`, `posts` — `body`, `members` — `username`, `name`, `email`, `groups` — `name`, `description`, `type`, `min_posts`, `max_messages`); every other field is optional on both `PUT` and `PATCH`.
- Values are validated by column type: bounded integers, booleans in the usual spellings, emails, `YYYY-MM-DD` dates (`''` means "not set"), id lists (`2,7` or `[2,7]`), floats; a value longer than its column is rejected with 400 instead of being truncated.
- Changes SMF can only make through its own helpers go through them: `modifyCategory()`, `modifyBoard()`, `modifyPost()`, `approveTopics()`, `approvePosts()`, `moveTopics()`, and `updateMemberData()` with a bcrypt password hash — so counters, the search index and the recycle bin stay in sync.
- Permissions mirror SMF's own: groups and member counters need `manage_membergroups`/`moderate_forum`, changing a message author needs `moderate_forum`, moving a topic needs `move_any`/`move_own`, the view counter needs `moderate_board`.
- `order` is a **0-based position**: among all categories for categories, among siblings (same category and parent) for boards. The mod translates it into `move_after`/`move_to`, because SMF only accepts relative moves.

## Connecting MoonShine V to the forum

MoonShine V treats the REST API as a service: panel → **Services → Add service**. Verified on this build:

| Form field | Value |
|---|---|
| API base URL | `http://nginx:8081/api/v1` (nginx's internal HTTP endpoint; from the host it is `https://localhost:8443/api/v1`) |
| Service name | a slug without spaces, e.g. `smf_forum` |
| Endpoint paths | **one per line**: `/members`, `/boards`, `/topics`, `/categories` |
| Auth type | `header` |
| Auth header | `X-API-Key` |
| Token or credential | `SMF_API_KEY` from `.env` |
| Health path | `/members` |

**Probe API** walks every path and proposes a resource (the SMF `{"data": ...}` envelope is detected automatically: `data_key=data`, `primary_key=id`). Then **Review proposal** → **Generate service and resource**: the service appears in the list, its resource (Members) reads the forum through the API, and Create/Edit writes through POST/PUT.

Details worth knowing:

- The service key is read from the `SMF_FORUM_API_KEY` environment variable (the generated config only holds a stub). This stack passes it through from `SMF_API_KEY`, which covers a service named `smf_forum`; for another service name set the matching variable (`<SERVICE_NAME>_API_KEY`).
- The generator derives form fields from a GET response. They must match the write contract: for example `POST /v1/members` requires `username` and `email`, so the generated member form gets `email`/`password` fields (resource classes live in the `app_state` volume; edit them with `docker compose exec api-admin-fpm` or through an MCP agent).
- The panel side is seeded automatically: the one-shot `moonshine-seed` service registers the `smf_forum` service (an upsert — the API key itself stays in `SMF_FORUM_API_KEY`) and runs `docker/gen-smf-resources.php`, which creates Categories, Boards, Topics, Groups and Members through the same MCP tools the "Add service" wizard uses, waits for the forum API to answer and skips everything already present. To re-run it by hand:

  Resources and their index, detail and form pages use the templates in `dockerfiles/moonshine-v/stubs/api-admin/`. SMF-specific fields, payload mapping and ordering only apply to `smf_forum`. Run `composer test:moonshine` from the repository root to check generation without Docker or a running forum.

```bash
docker compose exec api-admin-fpm php artisan tinker --execute='require "docker/gen-smf-resources.php";'
```

- There is no flat "Posts" resource: the mod returns messages nested under topics (`/v1/topics/{id}/posts`), and a single message is available at `/v1/posts/{id}`. Generated resources live in the `app_state` volume — a full reset (`docker compose down -v`) removes them, and the `moonshine-seed` service re-creates them automatically on the next `docker compose up -d` (or run the script above by hand).
- UNIX timestamps (member registration/last login, topic posting time) are rendered as `dd.mm.yyyy hh:mm` (UTC; the field definitions live in `app/ApiAdmin/Resources/SmfPageFields.php`).
- Members: "Primary group" is a dropdown; "Additional groups" is a multi-select (SMF stores them as a comma-separated id list; the id → name map is read from `/groups` once per request via `MemberResource::groupOptions()`, group `0` is "Regular members"). An empty selection clears the column, a multi-selection is submitted as an array and normalized to the `2,3` string in `applyFields()`. The edit form drops the post count and the registration/last-login timestamps — those are facts, not editable data. The password field is optional — the API only needs it when creating a member, and `required()` on the edit form silently blocked saving. Group names in both columns link to the group's page in the Groups resource (`MemberResource::groupLink()`; the synthetic group `0` carries no link).
- Boards: the form picks the category and the parent board from dropdowns fed by `/categories` and `/boards` (`BoardResource::categoryOptions()` / `boardOptions()`; the board itself is excluded from the parent list, and the explicit "None" entry is `id_parent = 0`, a root board). Tables and the detail page show the referenced titles instead of ids — and those titles are links: a category title opens the Categories resource, a parent board title opens that board's page (`categoryLink()` / `boardLink()`; "None" for a board without a parent carries no link, unknown ids fall back to plain text). The API rejects a parent from another category with a clear 400, so the parent list is not filtered by the chosen category.
- Index tables link the primary column to the item's own detail page — member username, category/board/group name, topic subject (`selfLink()` injected into every resource; `changePreview` with the field's row via `$field->getData()`, because the formatted-closure path HTML-escapes its output and would print the raw anchor markup).
- Visual components: the group colour is a MoonShine `Color` field (picker in the form; in lists and on the detail page only the swatch is shown, with the value in a hover tooltip — see `dockerfiles/moonshine-v/views/color.blade.php`); the group type is a `Select` with localised options (Private/Protected/Requestable/Free) and a label in lists via `changePreview`; booleans (locked/is_sticky/hidden/tfa_required) are `Switcher` toggles. Because the mod's API reads the sticky flag as `is_sticky` but expects `sticky` on write (PUT is a full replace), the generated TopicResource renames the key in outgoing payloads (an `applyFields` override).
- Topics: the board is picked from a dropdown and shown as a link to the Boards resource everywhere (`TopicResource::boardOptions()` / `boardLink()`); picking another board moves the topic through SMF's own `moveTopics()`. The message body lives on the detail card (rendered as the forum does) and in the edit form — the index table deliberately carries no body column. The form edits the BBC **source** (`body_raw`) and the resource renames it to `body` on write — binding the parsed HTML to the form would store the markup back through the full-replace PUT and corrupt the post. (The mod's topic index still returns `body`/`body_raw` since the 2026-11-16 rework; the panel just does not show it in the list.)

## Reordering categories and boards (drag-and-drop)

The "Categories" and "Boards" tables are sortable: drag a row with the mouse and the new order goes straight to the API and to SMF.

- The shared generated index-page stub wires `modifyListComponent()` and `reorder()` to `App\ApiAdmin\Pages\RestReorder` for `/categories` and `/boards`; other resources remain non-sortable. The helper ships in the image (`app/ApiAdmin/Pages/RestReorder.php`).
- The handler receives `id`, `index` and `data` (ids in the new visual order) from SortableJS, issues `PATCH {resource}/{id}` with the `order` field, and then reloads the table (the API renumbers neighbouring rows too).
- The mod's `order` contract is a **0-based position**: for categories it is the place among all categories, for boards the place among "siblings" (same forum category and same parent). Internally the mod translates the position into SMF's native `move_after`/`move_to` (`Subs-Categories.php`/`Subs-Boards.php`), because those functions do not accept `cat_order`/`board_order` directly. Boards can be dragged between categories too — SMF then moves the board together with its child boards, exactly like its own admin panel.
- `PUT` (full replace) still requires only `name` + `description`: `order` and `category` are optional. The numeric "Order" field was removed from the edit forms, and `CategoryResource`/`BoardResource` strip `order` from outgoing payloads (an `applyFields` override): the panel sends the whole item, and the stored number would be read as a new position (and rejected with a 400 when SMF's numbering has gaps).

Grouping for boards is computed on the panel side: `RestReorder` reads the list page by page (`limit`/`offset`), determines the dragged row's "category + parent" pair, and counts its new position only among the rows of that group.

## Moving between the hub and service panels

- The hub is the **Services** page (`https://localhost:8080/admin`): the connected services and the "Add service" entry point.
- Inside a service panel, the sidebar header has a grid button (**All services**) that returns to the hub; it is hidden while you are already on the hub.
- The panel dropdown (the button with the panel name under the logo) lists service panels only — MoonShine filters the hub out of it — which is exactly why the dedicated button exists. The hub is also reachable directly at `/admin`.

## Panel menu

The order of the sidebar items is explicit (`HasPanelChrome::MENU_ORDER`) rather than "whatever order the resources were registered in": **Groups**, **Members**, divider, **Categories**, **Boards**, **Topics**.

- Item icons come from `HasPanelChrome::MENU_ICONS` (`user-group`, `users`, `folder`, `view-columns`, `chat-bubble-left`). Every item is built with `onlyIcon()`, so the collapsed sidebar (chevron button at the bottom of the menu) shows the icon alone with the label in a tooltip instead of a truncated caption; expanded, the labels are visible as usual.
- Resources missing from the map are appended in registration order; the divider is drawn before position `30` (categories).
- Panel layouts call `apiAdminMenu()` instead of the stock `panelMenu()` (which ignores `#[Order]` attributes). The common patch runner handles both the layout stub and layouts already generated in a volume; incompatible or partially patched files fail explicitly:

```bash
docker compose exec -u root api-admin-fpm php docker/apply-patches.php --runtime
```

## Service hub and localisation

- The hub page ("Services") and the panel switcher are localised through `panel.hub.*` in `lang/{en,ru}/panel.php` (page title, "Select a service", "Add service", Online/Degraded/Offline, search). These upstream changes are part of `docker/apply-patches.php`.
- The switcher dropdown no longer shows a "Services" heading row — it came from the `title` attribute of `x-moonshine::dropdown` and was not clickable. The way back to the service list is the grid button in the sidebar header.
- The dashboard page is localised: its title comes from `moonshine::ui.dashboard` ("Dashboard" in EN, «Дашборд» in RU) instead of a hard-coded `Dashboard`.

## Colour scheme switcher

After the first installation the panel starts in **dark mode** with the **neutral** palette. A palette button sits in the sidebar header next to the light/dark toggle: 17 MoonShine schemes (Neutral, Purple, Cyan, Gray, Green, Halloween, Lime, Orange, Pink, Retro, Rose, Sky, Spring, Teal, Valentine, Winter, Yellow).

- The choice is stored in the session (`_palette` in the URL → `api_admin_palette` in the session), so it applies to every hub and service-panel page and does not depend on a frontend build. Until a visitor picks one, `HasPanelChrome::DEFAULT_PALETTE` (neutral) applies.
- Dark mode as the default comes from a script in the footer: if `localStorage` has no `darkMode` key yet (the one MoonShine's Alpine store persists to), it is written once as `true`. An explicit choice by the user (the light/dark toggle) is never overwritten.
- The "Made with ❤️ by CutCode" copyright and the Documentation/MoonShine links are removed — the footer component is replaced with a hidden one that remains in the markup only as the carrier of the theme script.
- Implementation: the `App\ApiAdmin\Layouts\HasPanelChrome` trait, mixed into `HasPanelSwitcher` (which every panel layout uses) by `docker/apply-patches.php`; it overrides `colors()`, `sidebarTopSlot()` and `getFooterComponent()`.
- Palette labels live in `lang/{en,ru}/panel.php`.

## Panel title

The title comes from `storage/api-admin/generated/panels.php`, written by the service wizard. The generators (`RegisterServiceTool`, `InitializeLocalServiceTool`) use `App\ApiAdmin\Support\PanelTitle`: short words are treated as abbreviations, so the service `smf_forum` yields **SMF Forum Admin** instead of "Smf Forum Admin". To re-apply the runtime patch set, including stored titles of earlier panels, run:

```bash
docker compose exec -u root api-admin-fpm php docker/apply-patches.php --runtime
```

## Panel language (English / Russian)

Both the hub and the service panels have a language switcher in the header; the choice is stored in the session (`?_lang=ru`). Localised:

- MoonShine's own UI strings (buttons, filters, search…) — translations in `lang/vendor/moonshine/ru/`;
- SMF resource titles and fields — `lang/{en,ru}/smf_forum.php`; the resource template and `SmfPageFields` call `__('smf_forum.*')`.

Strings belonging to the MoonShine V application itself (the Services page, etc.) stay in English — they are part of the upstream sources.

`API_ADMIN_ALLOW_PRIVATE_UPSTREAM=true` in `.env` allows requests to private compose-network addresses (not blocked in local mode, but better kept explicit).

The MoonShine V MCP server (for connecting agents):

```bash
docker compose exec -T api-admin-fpm php artisan api-admin:mcp-server
```

## How it is put together

- **SMF is pre-installed**: the `db` container loads `dockerfiles/dump.sql`, but only when the `data` volume is created. The dump was produced from a real SMF 2.1.8 installation (see below) and is rendered at container start — before exec'ing the stock MariaDB entrypoint — by `dockerfiles/mariadb/render-smf-dump.sh`: `SMF_VERSION`, `FORUM_URL` (avatar/smiley/theme URLs) and `SMF_API_KEY` are substituted. This happens at runtime instead of in an image build so no ARG has to carry the API key (Docker flags secret-bearing ARGs: `SecretsUsedInArgOrEnv`).
- **RESTful API mod**: the archive `dockerfiles/downloads/smf_restful_api.zip` (built from the mod repository) is copied into `Packages/`, its files are unpacked into the web root (as Package Manager does), and the fact of installation (the `integrate_pre_load` hook plus a `smf_log_packages` row) lives in the dump.
- **Settings.php** reads the database and forum settings from environment variables (`DB_*`, `FORUM_*`).
- **MoonShine V**: the Dockerfile mirrors the project's own `docker/Dockerfile` (the `production` and `web-local` stages) but pulls the sources from GitHub via `ADD`. `APP_KEY` is generated once by the `moonshine-init` service into the `app_state` volume; the administrator is created by `moonshine-migrate` from `MSV_ADMIN_*`.

### Rebuilding the mod

The mod sources live in this very repository (`src/` at its root, `../../src/` relative to this folder); only the archive enters the stack. The `mod-build` helper (a `tools`-profile service, skipped by `up`) packs it into `dockerfiles/downloads/smf_restful_api.zip`:

```bash
# pack the archive from src/ (package-info.xml + Sources/ + Themes/)
docker compose run --rm mod-build
```

To apply a changed mod to an already-running forum without a full reset (the web volume is not overwritten by the image):

```bash
docker compose build php
docker compose cp dockerfiles/downloads/smf_restful_api.zip php:/var/www/html/Packages/smf_restful_api.zip
docker compose exec php sh -c 'unzip -q -o /var/www/html/Packages/smf_restful_api.zip -d /var/www/html'
```

A full reset (`docker compose down -v && docker compose up -d --build`) achieves the same from scratch. The mod's tests (Testo + Rector) run inside the `php` container, which already has `pdo_sqlite`/`sqlite3`.

## Resetting data

The dump and the initialisation only apply to empty volumes:

```bash
docker compose down -v   # removes both databases, the forum files and MoonShine V storage
docker compose up -d --build
```

## Regenerating the SMF dump

To rebuild the dump from scratch (change the forum's pre-seeded state):

```bash
docker compose -f compose.install.yaml up -d --build
# open http://localhost:8000/install.php and complete the installation (DB: server db, user smf, password pass)
# install the mod: Admin → Package Manager → Browse Packages → SMF RESTful API → Install
# optionally enable the API: Admin → Modification Settings → RESTful API (api_enabled + key smf-dev-key-0001)
# then:
docker compose -f compose.install.yaml down -v   # export the dump first, see below
```

Exporting the dump (before `down -v`):

```bash
printf "TRUNCATE smf_sessions; TRUNCATE smf_log_online; TRUNCATE smf_log_errors; TRUNCATE smf_log_banned;\n" \
  | docker compose -f compose.install.yaml exec -T db mariadb -usmf -ppass smf
docker compose -f compose.install.yaml exec -T db sh -c 'mariadb-dump -uroot -proot smf 2>/dev/null' \
  > dockerfiles/dump.sql
```

## Environment variables

The full list is in [.env.example](.env.example): SMF/PHP versions, ports, database settings, the forum administrator, `SMF_API_KEY`, the moonshine-v version (`MSV_VERSION`), and the panel port and account (`MSV_*`).

## Repository layout

```
compose.yaml                 main stack (SMF + MoonShine V and dependencies)
compose.install.yaml         one-off stack for a manual SMF installation (browser)
.env.example                 every supported variable
dockerfiles/php/             PHP-FPM 8.2 + SMF 2.1.8 + RESTful API mod
dockerfiles/mariadb/         dump renderer for the stock mariadb:12 image
dockerfiles/nginx/           nginx for the forum (TLS + /api rewrite)
dockerfiles/moonshine-v/     MoonShine V image, patches and runtime scripts
dockerfiles/dump.sql         SMF seed dump (rendered at container start)
dockerfiles/downloads/       SMF archives and the packaged mod
```
