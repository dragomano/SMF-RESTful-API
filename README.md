# SMF RESTful API

[![SMF 2.1](https://img.shields.io/badge/SMF-2.1-ed6033.svg?style=flat)](https://github.com/SimpleMachines/SMF2.1)
![PHP](https://img.shields.io/badge/PHP-^8.2-blue.svg?style=flat)
![License](https://img.shields.io/github/license/dragomano/smf-restful-api)
![Hooks only: Yes](https://img.shields.io/badge/Hooks%20only-YES-blue)
[![Docs](https://img.shields.io/badge/Docs-Site-orange.svg)](https://dragomano.github.io/SMF-RESTful-API/)
[![Coverage Status](https://coveralls.io/repos/github/dragomano/smf-restful-api/badge.svg?branch=main)](https://coveralls.io/github/dragomano/smf-restful-api?branch=main)

**SMF RESTful API** is a modification for the [SMF 2.1](https://github.com/SimpleMachines/SMF2.1)
forum that layers a full-featured REST interface with JSON responses on top of the
engine.

The mod registers a single action, `index.php?action=api`, and passes the
request to its own router. A resource is addressed as
`index.php?action=api;path=v1/members/5`, or, with a rewrite rule in place, as
`/api/v1/members/5`.

[Full documentation](https://dragomano.github.io/SMF-RESTful-API/) — getting started guide and API reference.

## Requirements

- SMF 2.1
- PHP 8.2 or newer

## Installation

1. Install the released package through *Admin → Package Manager*.
2. Open *Admin → Configuration → Modification Settings → RESTful API*.
3. Tick **Enable the API** — while it is off every endpoint answers `503`.
4. Add an API key: each key is an opaque token bound to a member id, and every
   request made with that key acts with that member's permissions.

```bash
curl -H 'Authorization: Bearer YOUR_KEY' \
     'https://forum.example.com/index.php?action=api;path=v1/members/5'
```

## Resources

| Resource | Endpoints |
| --- | --- |
| `categories` | list, read, create, replace, update, delete, boards of a category |
| `boards` | list, read, create, replace, update, delete, topics of a board |
| `members` | list, read, create, replace, update, delete |
| `groups` | list, read, create, replace, update, delete |
| `topics` | list, read, create, replace, update, delete, posts of a topic |
| `posts` | read, create, replace, update, delete, posts of a topic |

Collections are paginated with `limit` (1–100, default 25) and `offset`; a
single resource is returned as `{"data": {...}}`, a collection as
`{"data": [...], "meta": {...}}` and a failure as
`{"error": {"code": "...", "message": "..."}}`.

The complete endpoint list, the writable fields of every resource and the error
codes live in the [API reference](https://dragomano.github.io/SMF-RESTful-API/guide/api-reference).

## Extending

Third-party mods extend the API through the integration hooks the mod itself
fires — `integrate_api_routes`, `integrate_api_pre_dispatch`,
`integrate_api_authenticate` and `integrate_api_pre_send` — without patching
this mod's files. See the [extending guide](https://dragomano.github.io/SMF-RESTful-API/guide/extending/overview).

A ready-made collection of requests is available in `postman_collection.json`.

## Development

```bash
composer install
composer test        # test suite
composer check       # Rector dry run
```

## License

[MIT](LICENSE)
