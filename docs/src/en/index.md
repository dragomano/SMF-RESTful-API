---
description: RESTful JSON API for SMF 2.1 — full-featured REST interface with authentication, pagination, and hook-based extensibility.
---

# SMF RESTful API { #smf-restful-api }

*SMF RESTful API* is a modification for [SMF 2.1](https://github.com/SimpleMachines/SMF2.1) that layers a full-featured REST interface with JSON responses on top of the engine. The mod registers a single action, `index.php?action=api`, and passes the request to its own router. A resource is addressed as `index.php?action=api;path=v1/members/5`, or, with a rewrite rule in place, as `/api/v1/members/5`.

The mod ships switched off: enable it and manage its keys at *Admin → Configuration → Modification Settings → RESTful API*. While it is off, every endpoint answers `503`.

Use [Getting Started](guide/getting-started.md) for the overview, the [API Reference](guide/api-reference.md) for every endpoint, field and error code, [Clean URLs](guide/clean-urls.md) for rewrite rules, and [Extending the API](guide/extending/overview.md) for integration hooks.

<div class="grid cards" markdown>

-   **Hooks only**

    ---

    No core file edits. The mod registers `index.php?action=api` and delegates to its own router — the API is switched on and its keys are managed at Admin → Configuration → Modification Settings → RESTful API.

-   **Full resource coverage**

    ---

    Categories, members, member groups, boards, topics, and posts with GET/POST/PUT/PATCH/DELETE. Reads use a Query builder with `{query_see_board}` and pagination; writes use SMF native functions.

-   **SMF-native authentication**

    ---

    API key via `Authorization: Bearer`, `X-API-Key`, or `api_key` parameter. After authentication the full SMF user context (`$user_info`, `allowedTo()`, `{query_see_board}`) is restored.

-   **Clean URLs optional**

    ---

    Reach the API at `index.php?action=api;path=v1/members/5` or via rewrites as `/api/v1/members/5`. Apache and Nginx rules are included.

-   **Extensible via hooks**

    ---

    Third-party mods register routes and authentication schemes and tweak responses through `integrate_api_*` hooks — no need to patch this mod.

-   **Validated builds**

    ---

    The documentation is built with Zensical — Markdown sources, link validation at build time, and localized English/Russian output.

</div>
