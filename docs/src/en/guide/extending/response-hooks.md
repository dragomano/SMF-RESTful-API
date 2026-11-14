---
description: Adjusting responses via integrate_api_pre_send (CORS, wrappers, rate-limit headers) and intercepting requests via integrate_api_pre_dispatch with ApiException.
---

# Adjusting the Response & Request { #adjusting-the-response-request }

## Adjusting the response (for example, CORS) { #adjusting-the-response-for-example-cors }

Use `integrate_api_pre_send`. It fires before the response is sent and lets you adjust the body, status, and headers — for CORS, rate-limit headers, or envelope wrappers.

```php title="preSend"
<?php declare(strict_types=1);

public function preSend(mixed &$payload, int &$status, array &$headers): void
{
	$headers['Access-Control-Allow-Origin'] = '*';
}
```

Parameters are passed by reference:

- `&$payload` — the body that will be encoded as JSON; it is `null` for an empty `204` answer.
- `&$status` — the HTTP status code.
- `&$headers` — the response headers array (header name → value).

The hook also fires for error answers, so a wrapper can be applied to failures as well.

!!! tip "Browser clients and preflight"

    The API answers only `GET`, `POST`, `PUT`, `PATCH` and `DELETE`; a CORS preflight (`OPTIONS`) has to be handled by your own hook — for example in `integrate_api_pre_dispatch`, answer it there with the `Access-Control-Allow-*` headers.


## Adjusting the request before routing { #adjusting-the-request-before-routing }

Use `integrate_api_pre_dispatch`. It fires after the request is parsed but before authentication — good for global logic like throttling or CORS preflight. Throw an `ApiException` to abort the request with a JSON error.

```php title="preDispatch"
<?php declare(strict_types=1);

use SMF\API\ApiException;
use SMF\API\Request;

// ...

public function preDispatch(Request $request): void
{
	if (my_rate_limit_exceeded($request)) {
		throw new ApiException(429, 'Too many requests', 'too_many_requests');
	}
}
```

`ApiException` takes an HTTP status, a human-readable message, and an optional machine-readable error code (third argument). Authentication and routing are skipped when this exception is thrown.
