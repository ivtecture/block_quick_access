# Быстрый доступ (block_quick_access) — плагин блока для Moodle 4.5

Плагин блока **«Быстрый доступ» (Quick access)** для Moodle 4.5: список часто
нужных ссылок (Дашборд, Мои курсы, Календарь, Главная) плюс ссылка
«Администрирование», видимая только администраторам сайта.

Репозиторий содержит **и код плагина, и готовое dev-окружение** (Docker Compose):
PostgreSQL 16, Adminer, контейнер инициализации ядра Moodle и веб-контейнер
Apache + PHP 8.2. Разворачивается на чистой машине одной командой.

---

## Состав dev-стенда

| Сервис | Образ / версия | Назначение | Адрес |
|---|---|---|---|
| `web` | `moodlehq/moodle-php-apache:8.2` | Apache + PHP 8.2 с ядром Moodle 4.5 (ветка `MOODLE_405_STABLE`, клонируется при первом запуске); код плагина примонтирован из корня репозитория | http://localhost:8080 |
| `db` | `postgres:16-alpine` | СУБД PostgreSQL 16 | наружу не публикуется |
| `adminer` | `adminer:latest` | Веб-интерфейс для работы с БД | http://localhost:8081 |
| `moodle-init` | `moodlehq/moodle-php-apache:8.2` | Одноразовый инициализатор: клонирует ядро Moodle в volume `moodle_code`, готовит права на `moodledata` | — |

## Требования

- **Docker Desktop** (Windows/macOS) или **Docker Engine + Docker Compose v2** (Linux).
  Проверка: `docker compose version`.
- ~2–3 ГБ свободного места на диске (образ PHP/Apache ~1 ГБ, клон Moodle, образы postgres/adminer).
- Свободные порты **8080** (Moodle) и **8081** (Adminer).
- **git на хосте не нужен** — ядро Moodle клонирует контейнер `moodle-init`.

## Быстрый старт (первое развёртывание)

Все команды выполняются из корня репозитория (PowerShell, cmd или любой POSIX-шелл — синтаксис одинаков).

### 1. Запуск стека

```bash
docker compose up -d
```

Первый запуск скачивает образы (~1 ГБ) и клонирует ядро Moodle — это занимает несколько минут.

### 2. Проверка инициализации

```bash
docker compose logs moodle-init
```

В конце вывода должно быть `==> [moodle-init] Done. ...`, контейнер должен завершиться без ошибок.

### 3. CLI-установка Moodle

```bash
docker compose exec -u www-data web php admin/cli/install.php --non-interactive --agree-license --lang=en --dbtype=pgsql --dbhost=db --dbname=moodle --dbuser=moodle --dbpass=moodle --prefix=mdl_ --wwwroot=http://localhost:8080 --dataroot=/var/www/moodledata --adminuser=admin --adminpass=admin123 --adminemail=admin@example.com --fullname="Moodle 4.5 Dev" --shortname="dev"
```

Значения параметров соответствуют дефолтам из `.env`.

> **Почему `-u www-data`:** все Moodle CLI-скрипты нужно запускать от `www-data`.
> Если запускать от root (по умолчанию у `docker compose exec web`), `config.php`
> и кэши в `moodledata` создаются root-owned, и Apache получает `Permission denied`
> (лекарство — см. Troubleshooting).

### 4. Очистка кэша

```bash
docker compose exec -u www-data web php admin/cli/purge_caches.php
```

### 5. Вход

Открыть **http://localhost:8080**, логин `admin`, пароль `admin123`.

### 6. Проверка плагина

Дашборд → включить **режим редактирования** → «Добавить блок» → **«Quick access»**
(или **«Быстрый доступ»**, если включён русский интерфейс). В блоке появятся ссылки;
«Администрирование» видна только пользователю с правами администратора сайта.

---

## Развёртывание на другой машине

```bash
git clone <url-репозитория> block_quick_access
cd block_quick_access
```

или просто скопировать папку проекта целиком. Далее — те же команды из
«Быстрого старта» (шаги 1–4), затем вход и проверка блока (шаги 5–6).

> **Смена порта:** если меняете `MOODLE_PORT` в `.env`, не забудьте также
> поменять `MOODLE_WWWROOT` и `--wwwroot=...` в команде установки (шаг 3),
> иначе Moodle будет генерировать ссылки на старый порт.

## Как устроено монтирование плагина

| Что | Где живёт | Как попадает в контейнер |
|---|---|---|
| Код плагина | корень репозитория (на хосте) | bind mount `.:/var/www/html/blocks/quick_access` в сервисе `web` |
| Ядро Moodle | named volume `moodle_code` (на хосте не виден) | клонируется контейнером `moodle-init` из `MOODLE_405_STABLE` |
| Файлы данных Moodle | named volume `moodledata` | монтируется в `web` как `/var/www/moodledata` |
| База данных | named volume `pgdata` | монтируется в `db` как `/var/lib/postgresql/data` |

Инфра-файлы (`docker-compose.yml`, `.env` и т.п.) лежат в корне репозитория рядом
с файлами плагина — Moodle их игнорирует, это нормально.

## Workflow разработки

- Правки файлов блока в корне репозитория **сразу видны** контейнеру `web` — пересборка не нужна.
- После правок PHP-файлов — очистить кэш:
  ```bash
  docker compose exec -u www-data web php admin/cli/purge_caches.php
  ```
- После увеличения `$plugin->version` в `version.php` — зайти на `/admin/index.php`
  (запустится апгрейд) либо выполнить:
  ```bash
  docker compose exec -u www-data web php admin/cli/upgrade.php
  ```
- Включить русский интерфейс: **Администрирование сайта → Язык → Языковые пакеты** →
  установить `ru`, затем сменить язык в настройках профиля пользователя
  (Профиль → Настройки → Предпочитаемый язык). Блок будет называться «Быстрый доступ».

## Полезные команды

```bash
docker compose ps                                 # статус сервисов
docker compose logs -f web                        # логи веб-сервера
docker compose logs moodle-init                   # логи инициализатора
docker compose exec web bash                      # шелл внутри контейнера Moodle
docker compose exec db psql -U moodle -d moodle   # psql напрямую
docker compose exec -u root web bash /scripts/fix-permissions.sh  # починить права (см. Troubleshooting)
docker compose restart web                        # перезапустить веб-сервер
docker compose down                               # остановить стек (данные сохранятся)
docker compose down -v                            # ПОЛНЫЙ СБРОС: удалить volumes с данными
```

Доступ к БД через **Adminer** (http://localhost:8081): СУБД — `PostgreSQL`,
сервер — `db`, пользователь — `moodle`, пароль — `moodle`, база — `moodle`.

Полный сброс и развёртывание заново: `docker compose down -v`, затем снова
«Быстрый старт» (шаги 1–4).

## Структура проекта

```
block_quick_access/
├── .env                          # dev-дефолты портов, БД и админа (не для продакшена!)
├── .gitattributes                # LF для *.sh и docker-compose.yml
├── .gitignore
├── README.md
├── docker-compose.yml            # dev-стенд: web + db + adminer + moodle-init
├── scripts/
│   ├── moodle-init.sh            # одноразовый инициализатор ядра Moodle
│   └── fix-permissions.sh        # ремонт прав (root-owned файлы после CLI без -u www-data)
├── version.php                   # метаданные плагина
├── block_quick_access.php        # класс блока
└── lang/
    ├── en/
    │   └── block_quick_access.php
    └── ru/
        └── block_quick_access.php
```

## Troubleshooting

- **Порт 8080/8081 занят.** Поменять `MOODLE_PORT`/`ADMINER_PORT` в `.env`.
  `wwwroot` фиксируется в `config.php` при установке, поэтому проще всего выполнить
  полный сброс `docker compose down -v` и пройти «Быстрый старт» заново — с новым
  `MOODLE_WWWROOT` в `.env` и новым `--wwwroot=...` в шаге 3.
- **Docker не запущен.** Запустить Docker Desktop и дождаться статуса «Running»;
  проверка: `docker compose ps`.
- **Первый запуск долго висит.** Это нормально: контейнер `moodle-init` скачивает
  ядро Moodle. Следить: `docker compose logs -f moodle-init`.
- **Проблемы прав на moodledata** (о записи в dataroot). Перезапустить стек:
  `docker compose down && docker compose up -d` — `moodle-init` выполнится снова
  и поправит владельца/права.
- **`Permission denied` на `config.php` / в кэшах** (Fatal error при открытии сайта).
  Причина: Moodle CLI (`install.php`, `purge_caches.php`, `upgrade.php`, ...) запускался
  без `-u www-data` — файлы создались от root и недоступны Apache (www-data).
  Лечение — вернуть владельца `www-data:www-data`:
  ```bash
  docker compose exec -u root web bash /scripts/fix-permissions.sh
  ```
  Скрипт переносит владение `moodledata` и кода Moodle на `www-data` (кроме
  bind-mount плагина) и ставит `config.php` в `0644`.
- **Предупреждение о `.env`.** Файл содержит dev-дефолты (`admin/admin123`,
  `moodle/moodle`) и намеренно не игнорируется git. Он **не предназначен для
  продакшена** — на реальных серверах используйте секреты и сильные пароли.

---

## Лицензия

Плагин **block_quick_access** и dev-окружение распространяются по лицензии
**GPL-3.0-or-later** — это обязательное условие для плагинов Moodle (ядро Moodle
публикуется под GPLv3). Полный текст лицензии — в файле [LICENSE](LICENSE).
