---
description: How third-party mods extend the API through the mod's own integration hooks without patching its files; hook reference table and design notes.
---

# Extending SMF RESTful API — Overview & Hooks { #extending-smf-restful-api-overview-hooks }

The API is designed so that third-party mods can add their own resources and influence request handling through integration hooks the mod itself fires — without modifying this mod's files.

## Available hooks { #available-hooks }

These four hooks are the mod's own: SMF ships no such hooks, the mod fires them for third-party code. Register your handlers through `add_integration_function()` exactly like any SMF hook.

| Hook | Description | When it fires |
|---|---|---|
| `integrate_api_routes` (`Router $router`) | Register your own endpoints | When the router is initialized |
| `integrate_api_pre_dispatch` (`Request $request`) | Global logic (throttling, CORS preflight); may throw `ApiException` | After the request is parsed, before authentication |
| `integrate_api_authenticate` (`Request $request`, `?int &$idMember`) | Your own schemes (OAuth/JWT): set `$idMember` | At the start of authentication |
| `integrate_api_pre_send` (`mixed &$payload`, `int &$status`, `array &$headers`) | Adjust the body, status, and headers (CORS, rate limiting, wrappers) | Before the response is sent |

## Where hooks come from { #where-hooks-come-from }

The mod calls `call_integration_hook()` with these names, so any mod can subscribe to them with `add_integration_function()` — the same mechanism SMF itself uses. See [Adding a Resource](adding-resource.md) for a registration example.

## Version prefix and route style { #version-prefix-and-route-style }

Routes are registered *without* a version prefix. The leading version segment (`v1`) is stripped and validated by the `Router` itself, so patterns stay version-agnostic. The client addresses the resource as `index.php?action=api&path=v1/widgets`. See [Adding a Resource](adding-resource.md) for the nested collection rule and reserved parameter names.

## Telling API calls apart { #telling-api-calls-apart }

While an API request is being handled, the constant `SMF_API_REQUEST` is defined. Hooks that fire deep inside SMF — inside `createPost()`, for example — can check it and adapt instead of assuming that a browser is waiting for a redirect:

```php title="post handler"
<?php declare(strict_types=1);

if (defined('SMF_API_REQUEST')) {
	// The request came from the API: answer with JSON, not with a redirect.
}
```

The mod uses this itself: when the Merge Double Posts mod is installed, its redirect would abort the request before the JSON answer is written, so the mod neutralizes the merge for API requests and every `POST` creates a distinct message with a predictable id.
