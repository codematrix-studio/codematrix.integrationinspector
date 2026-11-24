# План реализации и структура кода

- **Версия:** 0.1
- **Дата:** 2026-11-24
- **Статус:** реализованы DTO/enum API, Sanitizer, UUID v4, EventService, рабочий/no-op handle и контракт EventStorage; SQL/ORM Storage, фасад, установщик и административная часть не реализованы.
- **Назначение:** разложить MVP на небольшие компоненты с понятными границами и порядком реализации.

## 1. Принципы структуры

- Runtime-код не зависит от Composer и сторонних пакетов.
- Production-загрузка классов выполняется через Bitrix Loader.
- Публичные DTO и интерфейсы отделены от внутренних реализаций.
- API не знает о таблицах, SQL, ORM и административном UI.
- Sanitizer выполняется до Storage.
- Storage не управляет транзакциями приложения.
- Административный интерфейс использует сервисы чтения, а не обращается к таблицам напрямую.
- Сбои Inspector перехватываются на границе фасада/сервиса и не выходят в бизнес-поток.

## 2. Предлагаемая структура модуля

```text
local/modules/codematrix.integrationinspector/
├── install/
│   ├── index.php
│   ├── version.php
│   └── db/
│       └── mysql/
├── admin/
│   ├── events.php
│   ├── event_detail.php
│   └── settings.php
├── lang/
│   └── ru/
│       ├── install/
│       └── admin/
├── lib/
│   ├── Api/
│   │   ├── Inspector.php
│   │   ├── EventHandle.php
│   │   ├── EventInput.php
│   │   ├── EventOutcome.php
│   │   ├── SourceDefinition.php
│   │   ├── RecordingStatus.php
│   │   └── FinalizeResult.php
│   ├── Event/
│   │   ├── EventService.php
│   │   ├── EventState.php
│   │   ├── EventIdGenerator.php
│   │   └── Handle/
│   │       ├── RecordedEventHandle.php
│   │       └── NullEventHandle.php
│   ├── Source/
│   │   └── SourceService.php
│   ├── Sanitizer/
│   │   ├── Sanitizer.php
│   │   ├── RedactionPolicy.php
│   │   └── PayloadLimiter.php
│   ├── Storage/
│   │   ├── StorageInterface.php
│   │   ├── ConnectionResolver.php
│   │   ├── MysqlStorage.php
│   │   └── SchemaManager.php
│   ├── Cleanup/
│   │   └── CleanupService.php
│   └── Admin/
│       ├── EventQueryService.php
│       └── AccessPolicy.php
├── include.php
├── options.php
└── README.md
```

Это ориентир, а не требование создавать каждый файл заранее. Неиспользуемые каталоги и классы не добавлять до появления реальной ответственности.

## 3. Направление зависимостей

```text
Integration code
    |
    v
Api\Inspector
    |
    v
EventService ------> Sanitizer
    |
    v
StorageInterface
    |
    v
MysqlStorage ------> ConnectionResolver
    |
    v
Bitrix Connection / MySQL

Admin pages -> AccessPolicy -> EventQueryService -> StorageInterface
Installer   -> SchemaManager
Agent/Cron  -> CleanupService -> StorageInterface
```

Правила:
- API не вызывает Admin.
- Storage не вызывает EventService.
- Sanitizer не обращается к БД и не знает о Bitrix.
- SchemaManager вызывается только установщиком/обновлением.
- Cleanup не запускается из API begin/succeed/fail.
- Логирование внутренних ошибок Inspector не проходит через Inspector повторно.

## 4. Ответственность ключевых компонентов

| Компонент | Ответственность | Не должен |
|---|---|---|
| Inspector | Публичная точка входа, возврат handle | Знать SQL и HTML |
| EventInput / EventOutcome | Валидация структуры входных значений | Сохранять данные |
| EventService | Оркестрация begin/finalize и жизненного цикла | Управлять транзакцией сайта |
| RecordedEventHandle | Делегирование финализации одного eventId | Повторно финализировать событие |
| NullEventHandle | Безопасные no-op вызовы | Выбрасывать ошибки Storage |
| Sanitizer | Маскирование и лимиты | Писать в БД |
| StorageInterface | Контракт записи/чтения | Раскрывать ORM/SQL наружу |
| ConnectionResolver | Выбор default/named connection | Молча менять режим после ошибки |
| MysqlStorage | SQL/ORM операции и атомарные переходы | Вмешиваться в бизнес-транзакцию |
| SchemaManager | Создание/обновление схемы | Удалять историю при обычном uninstall |
| AccessPolicy | Проверка прав административных действий | Доверять только скрытию ссылок в UI |

## 5. Сначала реализуемое ядро

Порядок реализации. Чистые PHP-компоненты можно разрабатывать до завершения DB/ORM spike; Storage и установщик — только после фиксации целевой версии ядра и проверки схемы:

1. Чистые PHP Value Objects и enum/status типы.
2. Sanitizer и PayloadLimiter с unit-тестами.
3. EventIdGenerator и тесты UUID v4.
4. Контракт EventStorage и чистый EventService с unit-тестами.
5. Завершить DDL/ORM и connection spike на целевом Bitrix.
6. Реализовать ConnectionResolver и SchemaManager.
7. MysqlStorage: begin, finalize, read; тесты отказов и конкурентной финализации.
8. Facade Inspector с best-effort границей.
9. SourceService.
10. CleanupService.
11. Административные сервисы и страницы.

Не начинать с UI: сначала нужен проверяемый безопасный серверный цикл.

## 6. Условия перед написанием Storage

Нужно получить результаты:
- DB-011—DB-014 из [TEST-PLAN.md](TEST-PLAN.md);
- проверки именованных соединений из [TRANSACTION-STRATEGY.md](TRANSACTION-STRATEGY.md);
- DDL/ORM spike из [DATA-MODEL.md](DATA-MODEL.md);
- минимальная версия ядра и точная модель установки из [INSTALLATION.md](INSTALLATION.md).

Если ORM не даёт предсказуемо выбрать нужное соединение, Storage использует прямой Bitrix Connection API с параметризованными запросами и SqlHelper. Это внутреннее решение, публичный API от него не зависит.

## 7. Критерий качества

Каждый компонент должен иметь:
- одну чёткую ответственность;
- минимальный публичный интерфейс;
- unit-тесты для чистой логики;
- интеграционные тесты для работы с Bitrix/БД;
- описание отказов и ограничений.

Файлы и классы добавляются по мере реализации. Эта структура не является обязательством создавать пустые заглушки.
