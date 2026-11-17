# MoonShine V + SMF (с модом SMF RESTful API) в Docker

> English version: [STACK.md](STACK.md)

Compose-стек:

- **SMF 2.1.8** — форум Simple Machines Forum, предустановленный через сид-дамп базы (`dockerfiles/dump.sql`);
- **SMF RESTful API** — мод [dragomano/smf-restful-api](https://github.com/dragomano/smf-restful-api), установленный через Package Manager (зарегистрирован в БД, файлы распакованы); архив собирается в `dockerfiles/downloads/smf_restful_api.zip` helper-сервисом `mod-build` из исходников этого репозитория (см. ниже);
- **MoonShine V** ([moonshine-software/v](https://github.com/moonshine-software/v)) — Laravel 13 / PHP 8.5 приложение (админ-панель на MoonShine 5 с генерацией ресурсов через MCP) с PostgreSQL 18 и Redis 8.6.

## Состав стека

| Сервис | Образ | Порт хоста | Назначение |
|---|---|---|---|
| `nginx` | свой (dockerfiles/nginx) | **8085** → 80, **8443** → 443 | форум SMF (80 → 301 на 443); внутри сети ещё слушает 8081 по HTTP для MoonShine V |
| `php` | свой (dockerfiles/php) | — | PHP-FPM 8.2 с SMF 2.1.8 + мод RESTful API (+ любые языковые пакеты SMF, положенные в `downloads/`) |
| `db` | готовый (mariadb:12) | — | MariaDB 12, при первом старте заливает `dockerfiles/dump.sql` (рендерится при старте контейнера) |
| `adminer` | adminer | **8082** | веб-админка для MariaDB (и Postgres: сервер `postgres`) |
| `postgres` | postgres:18-alpine | — | база MoonShine V |
| `redis` | redis:8.6-alpine | — | кэш/сессии/очереди MoonShine V |
| `moonshine-init` | свой (dockerfiles/moonshine-v) | — | one-shot: инициализация `storage`, генерация `APP_KEY` |
| `moonshine-migrate` | свой | — | one-shot: `artisan migrate` + создание администратора панели |
| `moonshine-seed` | свой | — | one-shot: регистрация сервиса `smf_forum` и генерация ресурсов панели из API форума |
| `api-admin-fpm` | свой | — | PHP-FPM 8.5 приложения MoonShine V |
| `moonshine-queue` | свой | — | `artisan queue:work` |
| `moonshine-scheduler` | свой | — | `artisan schedule:work` |
| `moonshine-web` | свой (target `web-local`, Angie) | **8080** | веб-сервер MoonShine V |

По умолчанию форум публикуется на портах **8085/8443**, чтобы не конфликтовать с уже занятыми стандартными 80/443. Все порты меняются в `.env` (`NGINX_HTTP_PORT`, `NGINX_HTTPS_PORT`, `MSV_APP_PORT`, `ADMINER_PORT`); при смене `NGINX_HTTPS_PORT` также обновите `FORUM_URL`.

## Быстрый старт

```bash
cp .env.example .env
docker compose up -d --build
```

Первый запуск собирает образ MoonShine V из исходников GitHub (тег `MSV_VERSION`, по умолчанию `v1.0.0`) и прогоняет миграции + создание админа.

| Что | Адрес | Логин |
|---|---|---|
| Форум SMF | https://localhost:8443 (самоподписанный сертификат) | `Test` / `test` |
| REST API форума | https://localhost:8443/api/v1/... (см. ниже) | ключ `SMF_API_KEY` из `.env` |
| MoonShine V | https://localhost:8080/admin | `MSV_ADMIN_EMAIL` / `MSV_ADMIN_PASSWORD` из `.env` (по умолчанию `admin@localhost` / `change-me`) |
| Adminer | http://localhost:8082 | сервер `db`, пользователь `smf`/`pass` (или сервер `postgres`) |

## REST API форума

Мод установлен и включён, ключ из `.env` уже прописан в форуме:

```bash
# Чистые URL (rewrite в nginx):
curl -H "X-API-Key: smf-dev-key-0001" https://localhost:8443/api/v1/members

# Или прямой адрес (работает всегда):
curl -H "X-API-Key: smf-dev-key-0001" "https://localhost:8443/index.php?action=api;path=v1/members"
```

Ресурсы: `categories`, `boards`, `topics`, `posts` (вложены в темы), `members`, `groups` (GET/POST/PUT/PATCH/DELETE). Авторизация: заголовок `Authorization: Bearer ...`, `X-API-Key` или параметр `api_key`. Ключи и переключатель `api_enabled` меняются в админке: **Admin → Configuration → Modification Settings → RESTful API**.

## Что можно писать в API

Запись идёт через `POST` (создание), `PUT` (полная замена) и `PATCH` (частичное обновление). Каждый ресурс принимает все поля, которые SMF реально хранит в своей таблице, **кроме идентификаторов и служебных значений** (счётчики SMF, IP, секреты, коды активации). Поле можно передавать как под «человеческим» именем из ответа, так и под именем столбца SMF.

| Ресурс | Поля записи | Имена SMF-столбцов |
|---|---|---|
| `categories` | `name`, `description`, `order`, `can_collapse` | `name`, `description`, `cat_order`, `can_collapse` |
| `boards` | `name`, `description`, `category`, `parent`, `order`, `id_profile`, `id_theme`, `override_theme`, `count_posts`, `member_groups`, `deny_member_groups`, `redirect` | `id_cat`, `id_parent`, `board_order`, `member_groups`, `deny_member_groups` |
| `topics` | `subject`, `body`, `locked`, `is_sticky`, `approved`, `num_views`, `board` | `is_sticky` (`sticky` — устаревший алиас), `id_board` |
| `posts` | `body`, `subject`, `icon`, `smileys_enabled`, `approved`, `poster_name`, `poster_email`, `poster_time`, `modified_reason` | `reason` → `modified_reason` |
| `members` | `username`, `name`, `email`, `password`, `primary_group`, `post_group`, `additional_groups`, `posts`, `personal_text`, `signature`, `birthdate`, `website_title`, `website_url`, `usertitle`, `avatar`, `show_online`, `time_format`, `timezone`, `time_offset`, `id_theme`, `lngfile`, `smiley_set`, `pm_receive_from`, `warning`, `ignore_boards`, `is_activated`, `secret_question`, `secret_answer` | `member_name`, `real_name`, `email_address`, `passwd`, `id_group`, `id_post_group` |
| `groups` | `name`, `description`, `type`, `min_posts`, `max_messages`, `color`, `icons`, `hidden`, `parent`, `tfa_required` | `group_name`, `group_type`, `online_color`, `id_parent` |

Особенности:

- `PUT` требует только «ядро» ресурса (`categories`/`boards` — `name` и `description`, `topics` — `subject`, `posts` — `body`, `members` — `username`, `name`, `email`, `groups` — `name`, `description`, `type`, `min_posts`, `max_messages`); остальные поля необязательны и в `PUT`, и в `PATCH`.
- Значения валидируются по типу столбца: целые с границами, булевы в привычных написаниях, email, дата `YYYY-MM-DD` (`''` = «не указана»), списки id (`2,7` или `[2,7]`), числа с плавающей точкой; строка длиннее столбца отклоняется с 400, а не обрезается.
- Изменения, которые SMF умеет делать только своими функциями, идут через них: `modifyCategory()`, `modifyBoard()`, `modifyPost()`, `approveTopics()`, `approvePosts()`, `moveTopics()`, `updateMemberData()` с bcrypt-хешем пароля — то есть с пересчётом счётчиков, поискового индекса и корзины.
- Права проверяются как в SMF: группы и счётчики участника требуют `manage_membergroups`/`moderate_forum`, смена автора сообщения — `moderate_forum`, перенос темы — `move_any`/`move_own`, счётчик просмотров — `moderate_board`.
- `order` — **0-based позиция**: у категорий среди всех категорий, у разделов среди «соседей» (та же категория и тот же родитель). Внутри мод переводит её в `move_after`/`move_to`, потому что SMF принимает только относительные перемещения.

## Связка MoonShine V ↔ SMF

MoonShine V подключается к REST API как к сервису: панель → **Services → Add service**. Проверено на этой сборке:

| Поле формы | Значение |
|---|---|
| API base URL | `http://nginx:8081/api/v1` (внутренний HTTP-эндпоинт nginx; снаружи — `https://localhost:8443/api/v1`) |
| Service name | slug без пробелов, например `smf-forum` |
| Endpoint paths | **по одному на строку**: `/members`, `/boards`, `/topics`, `/categories` |
| Auth type | `header` |
| Auth header | `X-API-Key` |
| Token or credential | `SMF_API_KEY` из `.env` |
| Health path | `/members` |

Кнопка **Probe API** проходит по всем путям и генерирует proposal ресурса (у SMF API конверт `{"data": ...}` определяется автоматически: `data_key=data`, `primary_key=id`). Дальше — «Review proposal» → «Generate service and resource»: сервис появится в списке, его ресурс (Members) сразу читает данные форума через API, а «Create/Edit» пишет через POST/PUT.

Нюансы:

- Ключ сервиса MoonShine V берёт из переменной окружения `SMF_FORUM_API_KEY` (сгенерированный конфиг хранит только заглушку). В этом стеке она пробрасывается автоматически из `SMF_API_KEY` — работает для сервиса с именем `smf-forum`; для сервиса с другим именем задайте соответствующую переменную (`<ИМЯ_СЕРВИСА>_API_KEY`).
- Генератор строит поля формы из GET-ответа. Для записи они должны совпадать с контрактом API: например, `POST /v1/members` требует `username` и `email`, поэтому в сгенерированную форму стоит добавить поля `email`/`password` (классы ресурса лежат в томе `app_state`, их можно править через `docker compose exec api-admin-fpm` или через MCP-агента).
- Сторона панели засеивается автоматически: one-shot сервис `moonshine-seed` регистрирует сервис `smf_forum` (upsert — сам API-ключ остаётся в `SMF_FORUM_API_KEY`) и запускает `docker/gen-smf-resources.php`, который создаёт Categories, Boards, Topics, Groups и Members теми же MCP-инструментами, что и мастер «Add service», дожидается ответа API форума и пропускает всё уже созданное. Запустить вручную:

  Ресурсы и их страницы списка, просмотра и редактирования формируются по шаблонам из `dockerfiles/moonshine-v/stubs/api-admin/`. Поля, преобразования данных и сортировка SMF применяются только к `smf_forum`. Команда `composer test:moonshine` из корня репозитория проверяет генерацию без Docker и запущенного форума.

```bash
docker compose exec api-admin-fpm php artisan tinker --execute='require "docker/gen-smf-resources.php";'
```

- Плоского ресурса «Posts» нет: API мода отдаёт сообщения вложенными (`/v1/topics/{id}/posts`), а отдельные сообщения доступны по `/v1/posts/{id}`. Сгенерированные ресурсы живут в томе `app_state` — при полном сбросе (`docker compose down -v`) они пересоздаются автоматически сервисом `moonshine-seed` при следующем `docker compose up -d` (или скриптом выше вручную).
- UNIX-метки дат (регистрация/вход участника, время темы) отображаются в панели как `дд.мм.гггг чч:мм` (время UTC, определения полей находятся в `app/ApiAdmin/Resources/SmfPageFields.php`).
- Участники: «Основная группа» — выпадающий список; «Дополнительные группы» — мультивыбор (в SMF их несколько, хранятся списком id через запятую; карта id → имя читается из `/groups` один раз за запрос, `MemberResource::groupOptions()`; группа `0` — «Обычные участники»). Пустой выбор очищает колонку, множественный выбор отправляется массивом и нормализуется в строку `2,3` в `applyFields()`. В форме редактирования нет «Сообщений», дат регистрации и последнего входа — это факты, а не редактируемые данные. Поле «Пароль» необязательное: API требует его только при создании, а `required()` на редактировании молча блокировал сохранение. Названия групп в обоих столбцах — ссылки на карточку группы в ресурсе «Группы» (`MemberResource::groupLink()`; для синтетической группы `0` ссылки нет).
- Разделы: в форме категория и родительский раздел выбираются из выпадающих списков, которые заполняются из `/categories` и `/boards` (`BoardResource::categoryOptions()` / `boardOptions()`; сам раздел исключён из списка родителей, а явный пункт «Нет» — это `id_parent = 0`, корневой раздел). В таблицах и на карточке вместо id показываются названия, и они же — ссылки: название категории ведёт в ресурс «Категории», название родительского раздела — на его карточку (`categoryLink()` / `boardLink()`; «Нет» у раздела без родителя ссылкой не является, неизвестные id заменяются простым текстом). API отклоняет родителя из другой категории с понятной ошибкой 400, поэтому список родителей не фильтруется по выбранной категории.
- В списках первый содержательный столбец — ссылка на карточку самого элемента: логин участника, название категории/раздела/группы, тема (`selfLink()` добавлен в каждый ресурс; реализовано через `changePreview` с доступом к строке через `$field->getData()`, потому что путь через замыкание-форматирование HTML-экранирует вывод и печатает разметку якоря как текст).
- Визуальные компоненты: цвет группы — поле MoonShine `Color` (пикер при редактировании; в таблицах и карточке — только цветной квадратик, значение видно в подсказке при наведении, см. `dockerfiles/moonshine-v/views/color.blade.php`); тип группы — `Select` с локализованными вариантами (Закрытая/Защищённая/По запросу/Открытая) и подписью в списках через `changePreview`; булевы поля (locked/is_sticky/hidden/tfa_required) — `Switcher`-тумблеры. Поскольку API мода читает флаг закрепления как `is_sticky`, но ожидает `sticky` на записи (PUT — полная замена), сгенерированный TopicResource переименовывает ключ в исходящих payload'ах (`applyFields`-оврайд).
- Темы: раздел выбирается из выпадающего списка и везде показывается ссылкой на ресурс «Разделы» (`TopicResource::boardOptions()` / `boardLink()`); выбор другого раздела переносит тему штатным `moveTopics()` SMF. Текст сообщения живёт на карточке (отрисован как в форуме) и в форме редактирования — в таблице списка колонки с текстом нет намеренно. Форма правит **источник** (`body_raw`), и ресурс переименовывает его в `body` при записи — если привязать к форме распарсенный HTML, PUT полной замены записал бы разметку обратно и испортил сообщение. (Индексный эндпоинт мода по-прежнему возвращает `body`/`body_raw` с переработки 2026-11-16 — панель просто не показывает его в списке.)

## Перестановка категорий и разделов (drag-and-drop)

В списках «Категории» и «Разделы» таблица сортируемая: строку можно перетащить мышью, порядок сразу уходит в API и в SMF.

- Общий stub индексной страницы подключает `modifyListComponent()` и `reorder()` к `App\ApiAdmin\Pages\RestReorder` для `/categories` и `/boards`; остальные ресурсы остаются без сортировки. Вспомогательный класс входит в образ (`app/ApiAdmin/Pages/RestReorder.php`).
- Обработчик получает от SortableJS `id`, `index` и `data` (id в новом визуальном порядке), затем делает `PATCH {resource}/{id}` с полем `order`, после чего перезагружает таблицу (API перенумеровывает и соседние записи).
- Контракт `order` в API мода — **0-based позиция**: для категорий это место среди всех категорий, для разделов — место среди «соседей» (тот же раздел форума и тот же родитель). Внутри мод переводит позицию в родные для SMF `move_after`/`move_to` (`Subs-Categories.php`/`Subs-Boards.php`), потому что `cat_order`/`board_order` эти функции напрямую не принимают. Разделы можно перетаскивать и между категориями — SMF при этом переносит раздел вместе с подразделами, как в своей админке.
- `PUT` (полная замена) по-прежнему требует только `name` + `description`: `order` и `category` — необязательные поля. Числовое поле «Порядок» из форм редактирования убрано, а `CategoryResource`/`BoardResource` вырезают `order` из исходящего payload'а (`applyFields`-оврайд): панель отправляет весь элемент целиком, и сохранённое число было бы прочитано как новая позиция (а при «дырах» в нумерации SMF — отклонено с 400).

Группировка для разделов считается на стороне панели: `RestReorder` постранично читает список (`limit`/`offset`), определяет пару «категория + родитель» перетаскиваемой строки и считает её новую позицию только по строкам этой группы.

## Навигация между хабом и панелями сервисов

- Хаб — страница **Services** (`https://localhost:8080/admin`): список подключённых сервисов, подключение новых.
- Внутри панели сервиса в шапке бокового меню есть кнопка-«плитка» (**Все сервисы**) — она возвращает в хаб. В самом хабе кнопки нет.
- Дополнительно из панели сервиса можно вернуться по прямому адресу `/admin`; выпадающий список панелей (кнопка с названием панели под логотипом) содержит только панели сервисов — сам хаб MoonShine из него исключён, поэтому и понадобилась отдельная кнопка.

## Меню панели

Порядок пунктов задан явно (`HasPanelChrome::MENU_ORDER`), а не порядком регистрации ресурсов: **Группы**, **Участники**, разделитель, **Категории**, **Разделы**, **Темы**.

- Иконки пунктов — карта `HasPanelChrome::MENU_ICONS` (`user-group`, `users`, `folder`, `view-columns`, `chat-bubble-left`). Каждый пункт создаётся с `onlyIcon()`, поэтому в свёрнутом сайдбаре (кнопка-шеврон внизу меню) вместо обрезанных подписей остаются только иконки, а подпись показывается во всплывающей подсказке; в развёрнутом виде подписи на месте.
- Ресурсы, которых нет в карте, попадают в конец списка в порядке регистрации; разделитель рисуется перед позицией `30` (категории).
- Макеты панелей вызывают `apiAdminMenu()` вместо штатного `panelMenu()` (тот игнорирует атрибуты `#[Order]`). Шаблон и ранее созданные макеты обрабатываются общим исполнителем исправлений; несовместимый или частично обновлённый файл приводит к ошибке.

```bash
docker compose exec -u root api-admin-fpm php docker/apply-patches.php --runtime
```

## Хаб сервисов и локализация

- Страница хаба («Сервисы») и переключатель панелей локализованы: `panel.hub.*` в `lang/{en,ru}/panel.php` (заголовок страницы, «Выберите сервис», «Добавить сервис», статусы Онлайн/Ограниченно/Офлайн, поиск). Эти upstream-правки входят в `docker/apply-patches.php`.
- В выпадающем списке переключателя больше нет строки-заголовка «Services»: она рисовалась атрибутом `title` у `x-moonshine::dropdown` и не была кликабельной. Возврат к списку сервисов — кнопка-«плитка» в шапке бокового меню.
- Страница «Дашборд» локализована: заголовок берётся из `moonshine::ui.dashboard` (в RU — «Дашборд», в EN — «Dashboard») вместо жёстко прописанного `Dashboard`.

## Переключатель цветовых схем

По умолчанию после первой установки включены **тёмная тема** и **нейтральная палитра**; в шапке бокового меню, рядом с кнопкой светлой/тёмной темы, есть кнопка с палитрой — 17 схем MoonShine (Neutral, Purple, Cyan, Gray, Green, Halloween, Lime, Orange, Pink, Retro, Rose, Sky, Spring, Teal, Valentine, Winter, Yellow).

- Выбор хранится в сессии (`_palette` в URL → `api_admin_palette` в сессии), поэтому применяется на всех страницах хаба и панелей сервисов и не зависит от сборки фронтенда. Пока выбор не сделан, действует `HasPanelChrome::DEFAULT_PALETTE` (нейтральная).
- Тёмная тема по умолчанию задаётся скриптом в подвале: если в `localStorage` ещё нет ключа `darkMode` (его использует Alpine-стор MoonShine), туда один раз записывается `true`. Явный выбор пользователя (переключатель светлой/тёмной темы) не перетирается.
- Копирайт «Made with ❤️ by CutCode» и ссылки Documentation/MoonShine из подвала убраны — компонент подвала подменён на скрытый (он остаётся в разметке только как носитель скрипта темы).
- Реализация — трейт `App\ApiAdmin\Layouts\HasPanelChrome`, подмешанный в `HasPanelSwitcher` (его используют все макеты панелей) набором `docker/apply-patches.php`; он переопределяет `colors()`, `sidebarTopSlot()` и `getFooterComponent()`.
- Подписи палитр — в `lang/{en,ru}/panel.php`.

## Заголовок панели

Заголовок берётся из `storage/api-admin/generated/panels.php`, который создаёт мастер подключения сервиса. Генераторы (`RegisterServiceTool`, `InitializeLocalServiceTool`) используют `App\ApiAdmin\Support\PanelTitle`: короткие слова считаются аббревиатурами, поэтому сервис `smf_forum` даёт **SMF Forum Admin**, а не «Smf Forum Admin». Для повторного применения полного runtime-набора, включая обновление названий уже созданных панелей, выполните:

```bash
docker compose exec -u root api-admin-fpm php docker/apply-patches.php --runtime
```

## Язык панели (English / Русский)

В шапке панели (и хаба, и панели сервиса) есть переключатель языка — выбор сохраняется в сессии (`?_lang=ru`). Локализованы:

- служебные строки MoonShine (кнопки, фильтры, поиск...) — переводы в `lang/vendor/moonshine/ru/`;
- заголовки и поля ресурсов SMF — `lang/{en,ru}/smf_forum.php`; шаблон ресурса и `SmfPageFields` используют `__('smf_forum.*')`.

Строки самого приложения MoonShine V (страница Services и т. п.) остаются на английском — они часть исходников апстрима.

Переменная `API_ADMIN_ALLOW_PRIVATE_UPSTREAM=true` в `.env` разрешает обращения к приватным адресам compose-сети (в local-режиме это не блокируется, но лучше держать включённой явно).

MCP-сервер MoonShine V (для подключения агентов):

```bash
docker compose exec -T api-admin-fpm php artisan api-admin:mcp-server
```

## Как это устроено

- **SMF предустановлен**: контейнер `db` заливает `dockerfiles/dump.sql` только при создании тома `data`. Дамп получен из реальной установки SMF 2.1.8 (см. ниже) и рендерится при старте контейнера — до запуска штатного entrypoint MariaDB — скриптом `dockerfiles/mariadb/render-smf-dump.sh`: подставляются `SMF_VERSION`, `FORUM_URL` (URL аватаров/смайлов/темы) и `SMF_API_KEY`. Рендер делается в рантайме, а не при сборке образа, чтобы ключ не приходилось передавать через ARG (Docker помечает такие ARG предупреждением `SecretsUsedInArgOrEnv`).
- **Мод RESTful API**: архив `dockerfiles/downloads/smf_restful_api.zip` (собран из репозитория мода) кладётся в `Packages/`, его файлы распаковываются в веб-корень (как это делает Package Manager), а факт установки (хук `integrate_pre_load` + запись в `smf_log_packages`) живёт в дампе.
- **Settings.php** читает параметры БД и форум из переменных окружения (`DB_*`, `FORUM_*`).
- **MoonShine V**: Dockerfile повторяет авторский `docker/Dockerfile` проекта (стадии `production` и `web-local`), но исходники тянутся по `ADD` из GitHub. `APP_KEY` генерируется один раз сервисом `moonshine-init` в томе `app_state`; администратор создаётся сервисом `moonshine-migrate` из `MSV_ADMIN_*`.

Исправления upstream-кода панели упорядочены в `docker/apply-patches.php`:
сборка запускает полный набор, а `moonshine-init` повторно запускает runtime-набор
для уже созданного `app_state`. Повторный запуск безопасен; отсутствующий якорь
завершает процесс с ошибкой, поэтому после обновления MoonShine проверьте имя
скрипта и файл из сообщения. `docker/gen-smf-resources.php` отвечает только за
регистрацию SMF API и преобразование сгенерированных ресурсов.

### Пересборка мода

Исходники мода лежат в этом же репозитории (`src/` в его корне, `../../src/` относительно этой папки), в стек попадает только архив. Его собирает helper-сервис `mod-build` (профиль `tools`, при `up` не запускается):

```bash
# собрать архив из src/ (package-info.xml + Sources/ + Themes/)
docker compose run --rm mod-build
```

Чтобы применить изменённый мод к уже работающему форуму без полного сброса (том web образом не перезаписывается):

```bash
docker compose build php
docker compose cp dockerfiles/downloads/smf_restful_api.zip php:/var/www/html/Packages/smf_restful_api.zip
docker compose exec php sh -c 'unzip -q -o /var/www/html/Packages/smf_restful_api.zip -d /var/www/html'
```

Полный сброс (`docker compose down -v && docker compose up -d --build`) делает то же самое с нуля. Тесты мода (Testo + Rector) гоняются в контейнере `php`: в него уже установлены `pdo_sqlite`/`sqlite3`.

## Сброс данных

Дамп и инициализация применяются только к пустым томам:

```bash
docker compose down -v   # удалит базы (SMF, Postgres), файлы форума и storage MoonShine V
docker compose up -d --build
```

## Перегенерация дампа SMF

Если нужно пересобрать дамп с нуля (изменить форум «до засева»):

```bash
docker compose -f compose.install.yaml up -d --build
# открыть http://localhost:8000/install.php, пройти установку (БД: сервер db, пользователь smf, пароль pass)
# установить мод: Admin → Package Manager → Browse Packages → SMF RESTful API → Install
# при желании включить API: Admin → Modification Settings → RESTful API (api_enabled + ключ smf-dev-key-0001)
# затем:
docker compose -f compose.install.yaml down -v   # перед этим экспортировать дамп (см. ниже)
```

Экспорт дампа (перед `down -v`):

```bash
printf "TRUNCATE smf_sessions; TRUNCATE smf_log_online; TRUNCATE smf_log_errors; TRUNCATE smf_log_banned;\n" \
  | docker compose -f compose.install.yaml exec -T db mariadb -usmf -ppass smf
docker compose -f compose.install.yaml exec -T db sh -c 'mariadb-dump -uroot -proot smf 2>/dev/null' \
  > dockerfiles/dump.sql
```

## Переменные окружения

Полный список — в [.env.example](.env.example): версия SMF/PHP, порты, параметры БД, учётка админа форума, `SMF_API_KEY`, версия moonshine-v (`MSV_VERSION`), порт и учётка панели (`MSV_*`).

## Структура репозитория

```
compose.yaml                 основной стек (SMF + MoonShine V и зависимости)
compose.install.yaml         отдельный стек для ручной установки SMF (через браузер)
.env.example                 все поддерживаемые переменные
dockerfiles/php/             PHP-FPM 8.2 + SMF 2.1.8 + мод RESTful API
dockerfiles/mariadb/         рендер дампа для готового образа mariadb:12
dockerfiles/nginx/           nginx для форума (TLS + rewrite /api)
dockerfiles/moonshine-v/     образ MoonShine V, патчи и runtime-скрипты
dockerfiles/dump.sql         сид-дамп SMF (рендерится при старте контейнера)
dockerfiles/downloads/       архивы SMF и упакованный мод
```
