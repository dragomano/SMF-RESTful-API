---
description: Правка ответа через integrate_api_pre_send (CORS, обёртки, заголовки лимитов) и перехват запроса через integrate_api_pre_dispatch с ApiException.
---

# Правка запроса и ответа { #adjusting-the-response-request }

## Правка ответа (например, CORS) { #adjusting-the-response-for-example-cors }

Используйте `integrate_api_pre_send`. Хук срабатывает перед отправкой ответа и позволяет править тело, статус и заголовки — для CORS, заголовков лимитов или обёрток-конвертов.

```php title="preSend"
<?php declare(strict_types=1);

public function preSend(mixed &$payload, int &$status, array &$headers): void
{
	$headers['Access-Control-Allow-Origin'] = '*';
}
```

Параметры передаются по ссылке:

- `&$payload` — тело, которое будет закодировано в JSON; для пустого ответа `204` оно равно `null`.
- `&$status` — HTTP-код статуса.
- `&$headers` — массив заголовков ответа (имя → значение).

Хук срабатывает и для ответов с ошибкой, так что обёртку можно применять и к неудачным запросам.

!!! tip "Браузерные клиенты и preflight"

    API отвечает только на `GET`, `POST`, `PUT`, `PATCH` и `DELETE`; CORS-preflight (`OPTIONS`) нужно обработать своим хуком — например в `integrate_api_pre_dispatch`, ответив там заголовками `Access-Control-Allow-*`.


## Правка запроса до маршрутизации { #adjusting-the-request-before-routing }

Используйте `integrate_api_pre_dispatch`. Хук срабатывает после разбора запроса, но до аутентификации — подходит для глобальной логики вроде лимитов или CORS-preflight. Бросьте `ApiException`, чтобы прервать запрос с JSON-ошибкой.

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

`ApiException` принимает HTTP-статус, человекочитаемое сообщение и опциональный машинный код ошибки (третий аргумент). При выбросе исключения аутентификация и маршрутизация пропускаются.
