---
description: Пошаговое добавление ресурса widgets — точка входа мода, регистрация маршрутов, контроллер и зарезервированные параметры.
---

# Добавление нового ресурса { #adding-a-new-resource }

Ниже — минимальный мод, добавляющий ресурс `widgets`.

## 1. Точка входа мода { #s-1-mod-entry-point }

```php title="Integration.php"
<?php declare(strict_types=1);

namespace Vendor\Widgets;

if (! defined('SMF'))
	die('No direct access...');

class Integration
{
	public function hooks(): void
	{
		// Своя автозагрузка, чтобы SMF нашёл классы мода.
		add_integration_function('integrate_autoload', self::class . '::autoload#', false);
		add_integration_function('integrate_api_routes', self::class . '::routes#', false);
	}

	public function autoload(array &$classMap): void
	{
		$classMap['Vendor\\Widgets\\'] = 'Widgets/';
	}

	// routes() — см. следующий раздел
}
```

## 2. Регистрация маршрутов { #s-2-registering-routes }

Обработчик `integrate_api_routes` получает объект `Router` с fluent-методами `get()`, `post()`, `put()`, `patch()`, `delete()`. Сегмент `{id}` (или любой `{name}`) попадает в массив параметров действия.

```php title="routes"
<?php declare(strict_types=1);

use SMF\API\Router;

// ...

public function routes(Router $router): void
{
	$router->get('widgets', WidgetController::class, 'index');
	$router->get('widgets/{id}', WidgetController::class, 'show');
	$router->post('widgets', WidgetController::class, 'store');
}
```

Параметры пути приходят строками — проверяйте их через `$this->id($params)` перед использованием в запросе. Помимо хелперов стандартных методов (`get()`, `post()` и т. д.), `Router::add($method, $pattern, $class, $action)` регистрирует маршрут для любого другого метода.

!!! note "Префикс версии"

    Маршруты регистрируются *без* префикса версии. Ведущий сегмент версии (`v1`) снимает и проверяет сам `Router`, поэтому паттерны остаются версия-агностичными. Клиент обращается к ресурсу как `index.php?action=api&path=v1/widgets`.


!!! warning "Вложенные коллекции и зарезервированные параметры"

    Списки «детей» регистрируйте под родителем — `parent/{parent_id}/children`, а не через query-фильтр. Пример из ядра API: `GET boards/{board_id}/topics`, `GET categories/{cat_id}/boards`, `GET topics/{topic_id}/posts`. Причина не только в стиле: имена `board`, `topic`, `msg`, `start`, `action` — *служебные query-параметры SMF*. Если передать `?board=5`, ядро на этапе `loadBoard()` сочтёт запрос просмотром раздела и отдаст HTML-страницу — до `action=api` дело не дойдёт. Идентификатор родителя в пути (`{parent_id}`) этой коллизии избегает; для чтения используйте `$this->id($params, 'parent_id')`.


## 3. Контроллер { #s-3-controller }

Контроллер расширяет `SMF\API\Controllers\AbstractController` и получает готовую «обвязку»: доступ к запросу, фабрику ответов, построитель запросов `Query`, пагинацию и валидацию id.

```php title="WidgetController.php"
<?php declare(strict_types=1);

namespace Vendor\Widgets;

use SMF\API\ApiException;
use SMF\API\Query;
use SMF\API\Response;
use SMF\API\Controllers\AbstractController;

if (! defined('SMF'))
	die('No direct access...');

class WidgetController extends AbstractController
{
	public function index(array $params): Response
	{
		$query = (new Query())
			->from('{db_prefix}widgets AS w')
			->where('w.hidden = {int:hidden}')
			->columns('w.id_widget, w.name')
			->orderBy('w.name')
			->params(['hidden' => 0]);

		// Каждая строка проходит через transform();
		// отдаётся коллекция с мета-пагинацией.
		return $this->paginate($query);
	}

	public function show(array $params): Response
	{
		// id($params) валидирует {id}
		$row = $this->first(
			(new Query())
				->from('{db_prefix}widgets')
				->where('id_widget = {int:id}')
				->columns('id_widget, name')
				->params(['id' => $this->id($params)])
		);

		if ($row === null) {
			throw ApiException::notFound('Widget not found');
		}

		return $this->response()->data($this->transform($row));
	}

	protected function transform(array $row): array
	{
		return [
			'id'   => (int) $row['id_widget'],
			'name' => $row['name'],
		];
	}
}
```

Полный справочник по `Query` и хелперам контроллера см. в разделе [Query и контроллер](query.md).

!!! tip "Что возвращает действие контроллера"

    Действие должно возвращать `Response` — `$this->response()->data(...)` для одного ресурса, `$this->paginate($query)` для коллекции, `$this->noContent()` для пустого `204`. Обычный массив тоже подойдёт: роутер обернёт его в `data`. Любой брошенный `ApiException` превратится в JSON-ошибку с соответствующим кодом статуса.

