---
description: Fluent-построитель Query, режимы выборки (paginate/first/exists/fetchAll) и хелперы AbstractController включая writableColumns, boolean и transform.
---

# Query — построение и выполнение запросов { #query-building-and-running-queries }

`SMF\API\Query` — небольшой fluent-построитель. FROM/WHERE пишется один раз и переиспользуется всеми режимами выборки; `LIMIT`, `COUNT` и пагинация достраиваются автоматически.

## Сборка запроса { #building-a-query }

- `->from()` (с JOIN'ами)
- `->where()`
- `->columns()` (по умолчанию `*`)
- `->orderBy()`
- `->params()` (плейсхолдеры `{int:...}`, `{query_see_board}` и т.п.)

Сам построитель тоже умеет выполнять запрос: `first()`, `exists()`, `fetchAll()` и `fetchPage($limit, $offset)` — последний возвращает строки страницы вместе с общим числом строк.

## Запуск через хелперы контроллера { #running-via-the-controller-helpers }

- `$this->paginate($query)` — коллекция с мета-пагинацией; каждая строка идёт через `transform()`.
- `$this->first($query)` — одна строка или `null` (для `show` и проверок).
- `$this->exists($query)` — `bool` (для валидации в `store`).
- `$this->fetchAll($query)` — все строки без пагинации (сырые массивы).

## Что доступно в контроллере { #whats-available-in-the-controller }

- `$this->request` — объект `Request`. К нему обращаются через аксессоры, а не через публичные свойства: `getMethod()`, `getSegments()`, `segment($index)`, `header($name)`, `query($name)`, `input($name)` и `all()` (всё разобранное тело).
- `$this->response()` — новый `Response` (`->data()`, `->collection()`, `->status()`, `->header()`, `->error()`).
- `$this->paginate($query)` / `$this->first($query)` / `$this->exists($query)` / `$this->fetchAll($query)` — запуск `Query`.
- `$this->transform($row)` — форма строки для вывода; переопределяйте под свой ресурс (по умолчанию возвращает строку как есть).
- `$this->id($params)` — целочисленный `{id}` с проверкой; вторым аргументом читается другой параметр пути, например `$this->id($params, 'parent_id')`.
- `$this->pagination()` — `[limit, offset]` с разумными границами (`limit` 1–100, по умолчанию 25; `offset` по умолчанию 0).
- `$this->cleanString($value)` — безопасная строка из тела запроса.
- `$this->writableColumns($schema, $full, $aliases)` — сбор изменяемых полей из тела: при `$full = true` (PUT) требует все поля из `$schema`, не помеченные `optional`, при `false` (PATCH) оставляет только присланные (пустой PATCH → 400).
- `$this->boolean($value)` — приведение значения тела к `bool` (принимает `true/false`, `1/0`, `"yes"/"no"`, `"on"/"off"`).
- `$this->noContent()` — пустой ответ `204` для успешных операций без тела (обычно `DELETE`).
- личность и права — из глобального `$user_info` (его настраивает `Authenticator`), проверки — штатным `allowedTo()` / `$user_info['is_admin']`.

### Схема изменяемых полей { #the-writable-field-schema }

`writableColumns()` сопоставляет тело запроса с колонками SMF через схему — имя поля => описание. Ниже схема встроенного ресурса `categories`, её можно сравнить с `CategoryController`:

```php title="schema"
<?php declare(strict_types=1);

private const WRITABLE = [
	'name'         => ['type' => 'string', 'column' => 'name', 'length' => 255],
	'description'  => ['type' => 'raw', 'column' => 'description'],
	'order'        => ['type' => 'int', 'column' => 'cat_order', 'optional' => true],
	'can_collapse' => ['type' => 'bool', 'column' => 'can_collapse', 'optional' => true],
];
```

| Ключ | Значение |
| --- | --- |
| `type` | `string`, `raw`, `int`, `float`, `bool`, `email`, `date` или `int_list` |
| `column` | Колонка SMF, в которую пишем; по умолчанию совпадает с именем поля |
| `min`, `max` | Границы для целочисленных полей; нижняя граница по умолчанию `0`, отрицательная нижняя граница разрешает отрицательные значения |
| `clamp` | Прижимает значение к границе вместо отказа (SMF трактует `min_posts = -1` как «не по пост-критерию») |
| `length` | Максимальная длина в символах для полей `string` и `raw` |
| `optional` | Поле принимается, но не обязательно при полной замене |

Поведение типов:

- `string` — обрезается и экранируется через `htmlspecialchars()`;
- `raw` — обрезается, но сохраняется как есть (тексты сообщений, подписи, описания);
- `int` — целое число в заданных границах; дробные значения, ведущие нули (`01`) и значения с посторонними символами отклоняются;
- `float` — число, округлённое до двух знаков (SMF хранит смещения времени как float);
- `bool` — `true`, `1`, `"yes"` и `"on"` считаются истиной, всё остальное ложью;
- `email` — проверяется как адрес электронной почты;
- `date` — `YYYY-MM-DD`; пустое значение превращается в служебную дату SMF «не задано» `1004-01-01`;
- `int_list` — список id, заданный как `"2,7"` или `[2, 7]`, хранится как `"2,7"`.

Третий аргумент `writableColumns()` — список псевдонимов: имена колонок SMF, которые клиент может использовать вместо имён полей, например `['id_cat' => 'category']`. Псевдоним используется, только если само поле не прислано.

!!! warning "Запись — через штатные функции SMF"

    Для изменяющих действий (`PUT` / `PATCH` / `DELETE`) выполняйте запись через штатные функции SMF (`modifyBoard`, `deleteMembers`, `modifyPost` и т.п.) — `Query` предназначен только для чтения. Права *проверяйте сами* через `allowedTo()` до вызова этих функций и бросайте `ApiException`: многие функции ядра при отказе завершают запрос фатальной HTML-ошибкой, а не JSON.


## Пример: форма transform { #example-transform-shape }

Переопределите `transform()`, чтобы задать внешний вид ресурса — `paginate()` вызывает его для каждой строки:

```php title="transform"
<?php declare(strict_types=1);

protected function transform(array $row): array
{
	return [
		'id'   => (int) $row['id_widget'],
		'name' => $row['name'],
	];
}
```

См. также [Добавление ресурса](adding-resource.md) с полным примером контроллера.
