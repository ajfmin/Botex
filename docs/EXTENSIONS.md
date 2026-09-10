# Building a Production Extension

Complete reference for the extension API of this Telegram bot SDK. Every
hook, class, method, constant and constraint an extension author can
touch is listed here, with the rules that govern it.

Written to be read start to finish by a human or an agent. Sections are
independent enough to be jumped into; cross-references use the same
`Namespace\Class::method()` form the code does.

**Conventions used below**

| Marker | Meaning |
| --- | --- |
| `→ T` | return type |
| *core* | ships in `src/`, always available |
| *hook* | a static method on your `Extension` class |
| **MUST** | violating this breaks the extension or its security model |

---

## 1. Runtime map

```
bootstrap/app.php        boot sequence, returns a configured Feeder
config/config.php        all settings, read through Botex\Support\Config
public/webhook.php       Telegram entry point
public/panel.php         read-only web panel (token gated)
bin/console              management CLI
src/                     core, PSR-4 as Botex\
extensions/<Slug>/       your extension, autoloaded as Extensions\<Slug>\
storage/extensions.json  which extensions are enabled
storage/extension-settings/<Slug>.json   admin setting overrides
storage/logs/app-YYYY-MM-DD.log          daily log files (section 26)
```

Three entry points share one boot: `bootstrap/app.php` loads `.env`,
builds the Eloquent capsule, and returns an `Botex\Bot\Feeder` container.
Nothing else constructs services.

### The request lifecycle

```
Telegram POST
  → public/webhook.php
  → new Botex\Telegram\Update($json)
  → Feeder->make(Botex\Bot\Router::class)
  → Router::handle(Update)
       message?  → Router::handleMessage()
       callback? → Router::handleCallback()
  → Botex\Bot\Dispatcher::dispatch($class, $update)
       runs $class::middleware() in order
       every middleware returns true → Feeder->make($class)->handle($update)
```

Nothing in the webhook path can delete files or write config. Extension
lifecycle operations live in `Botex\Extension\Manager`, reachable only from
`bin/console`.

### The job lifecycle

Separate process, no HTTP:

```
php bin/console jobs:work
  → Botex\Bot\Job\WorkerLease::acquire()   only one worker survives this
  → Botex\Bot\Job\Worker::run()
       loop: tick() → claim due rows → resolve via allowlist
             → Feeder->make($handler)->handle(JobContext)
```

---

## 2. Anatomy of an extension

Minimum viable extension, two files:

```
extensions/Hello/
  extension.json
  Extension.php
```

Realistic production extension:

```
extensions/Shop/
  extension.json          manifest (required)
  settings.json           declared settings + their defaults (optional)
  Extension.php           entry class (required)
  Command/Buy.php
  Callback/Confirm.php
  Action/PlaceOrder.php    Run actions
  Job/ExpireOrders.php     scheduled work
  Flow/CheckoutFlow.php
  Flow/Step/AskPlan.php
  Admin/ShopSection.php    a panel inside /admin
  Model/Order.php          Eloquent model on your own table
  Repository/OrderRepository.php
  Service/ShopService.php
```

The folder layout below `Extension.php` is yours. Only `extension.json`,
`settings.json` and `Extension.php` are looked for by name.

### 2.1 extension.json

```json
{
  "name": "Shop",
  "version": "1.0.0",
  "description": "Sells things.",
  "entry": "Extensions\\Shop\\Extension"
}
```

| Key | Required | Notes |
| --- | --- | --- |
| `name` | yes | display name, non-empty string |
| `version` | yes | shown in panel and CLI; use `major.minor.patch` |
| `entry` | yes | FQCN of your `Extension` class, `\\` escaped in JSON |
| `description` | no | defaults to `''` |

These are optional and only used when the extension is published to an
[archive](ARCHIVE.md). They are passed through untouched, so adding them
costs nothing if you never publish:

| Key | Notes |
| --- | --- |
| `requires` | `{"php": ">=8.2", "botex": "^1.0.0"}`; an unmet one refuses the install |
| `author` | shown on the package page |
| `homepage` | linked, but only when `http(s)` |
| `license` | shown on the package page |
| `keywords` | array of strings, searchable; first 10 kept |

`requires.extensions` is the exception to "only used when published": it is
enforced by the bot you are running on.

```json
"requires": {
  "php": ">=8.3",
  "extensions": { "Vpn": ">=1.1.0" }
}
```

Each key is another extension's **slug** and each value a constraint in the
same subset `requires.botex` uses (`*`, `1.2.3`, `>=1.2`, `^1.2`, `~1.2`,
or a comma-joined AND). `ext:install` and `ext:enable` refuse an extension
whose requirements are missing, disabled, or too old, and say which:

```
$ php bin/console ext:enable ManageMyVpn
ManageMyVpn needs Vpn, which is installed but disabled. Enable it with: php bin/console ext:enable Vpn
```

The guard runs the other way too: `ext:disable` and `ext:remove` refuse to
pull an extension out from under something that depends on it, unless you
add `--force`. Nothing is checked at boot — a dependency that disappears
by hand shows up as a class that will not autoload — so an extension built
on another one should still return `[]` from its hooks when the extension
below it is not loadable.

`version` is free-form as far as the local loader is concerned, but
publishing requires a real `major.minor.patch` — the archive compares
versions to decide what is newer, and it is what tells an installed bot
whether an update exists. `1.0` and `v1.0.0` are both refused at publish
time rather than silently reinterpreted.

Parsed by `Botex\Extension\Manifest::fromPath()` → `Manifest` with readonly
`slug`, `name`, `version`, `description`, `entry`, `path`.

**The slug is always the folder name**, never a value in the file, so it
can never disagree with the autoload path. `extensions/Shop/` is slug
`Shop`, case-sensitively, and every allowlist key uses that string.

A missing file, invalid JSON, or a missing required key does not crash the
bot: `Registry::all()` skips the extension and records the reason in
`Registry::errors()`, shown in the panel and in `ext:list`.

### 2.2 Namespace and autoloading

`Botex\Extension\Autoloader` maps `Extensions\<Slug>\Foo\Bar` to
`extensions/<Slug>/Foo/Bar.php` at runtime. Consequences:

- **MUST** namespace your classes `Extensions\<Slug>\...` matching the
  folder path exactly, or they will not be found.
- No `composer dump-autoload` after installing or removing an extension.
- Any path containing `..` is rejected before touching the filesystem.
- The autoloader is registered when `Registry` is constructed, which
  `bootstrap/app.php` always does. Extension classes are not available
  before that.

### 2.3 Slug rules

A slug travels inside allowlist keys of the form `<slug>:<name>`, so:

- **MUST** match `[A-Za-z0-9._-]+` to be usable with `Run::extension()` or
  `JobRequest::to()` — both throw `InvalidArgumentException` otherwise.
- **MUST NOT** contain a colon.
- `core` is reserved (`Run::CORE`, `JobRequest::CORE`).

---

## 3. The Extension class: every hook

```php
<?php

namespace Extensions\Shop;

use Botex\Extension\AbstractExtension;
use Botex\Support\Schema;

class Extension extends AbstractExtension
{
    public static function commands(): array { return [Command\Buy::class]; }
    public static function callbacks(): array { return [Callback\Confirm::class]; }
    public static function flows(): array { return [Flow\CheckoutFlow::class]; }
    public static function adminSections(): array { return [Admin\ShopSection::class]; }
    public static function runnables(): array { return [Action\PlaceOrder::class]; }
    public static function jobs(): array { return [Job\ExpireOrders::class]; }

    public static function install(): void { /* create tables */ }
    public static function uninstall(): void { /* drop tables */ }
}
```

**MUST extend `Botex\Extension\AbstractExtension`**, not implement
`ExtensionInterface` directly. `AbstractExtension` defaults every hook to
`[]` / no-op, so hooks added to the interface later cannot break an
extension already installed. (`Registry` also guards with
`method_exists()`, but extending is the supported path.)

| Hook | Returns | Collected into | Purpose |
| --- | --- | --- | --- |
| `commands()` | `array<class-string<CommandInterface>>` | `Router` | slash commands and menu-button labels |
| `callbacks()` | `array<class-string<CallbackInterface>>` | `Router` | inline-button callback data |
| `flows()` | `array<class-string<FlowInterface>>` | `Bot\Conversation\Flows` | multi-step conversations |
| `adminSections()` | `array<class-string<AdminSectionInterface>>` | `Bot\Admin\Sections` | panels inside `/admin` |
| `runnables()` | `array<class-string<RunnableInterface>>` | `Bot\Action\Runnables` | named actions a Run button binds to |
| `jobs()` | `array<class-string<JobInterface>>` | `Bot\Job\Jobs` | named work the job worker can run |
| `install()` | `void` | `Manager::install()` | create tables; **MUST be idempotent** |
| `uninstall()` | `void` | `Manager::remove()` | drop tables |

Everything is **static on purpose**: the registry and console inspect an
extension without booting it, and the console has no `Bot` to inject. Do
not put runtime state on the `Extension` class; put it in a service.

### 3.1 install() and uninstall()

```php
public static function install(): void
{
    // Schema::createIfMissing returns false when the table is already
    // there, which is what makes a second call harmless.
    Schema::createIfMissing('shop_orders', function ($table) {
        $table->id();
        $table->unsignedBigInteger('user_id')->index();
        $table->unsignedBigInteger('amount');
        $table->string('status', 16)->default('pending');
        $table->timestamps();
    });
}

public static function uninstall(): void
{
    Schema::dropIfExists('shop_orders');
}
```

- `install()` runs on `ext:install` and **MUST be safe to run twice**;
  `ext:enable` does not re-run it.
- `uninstall()` runs on `ext:remove`. A throw here is caught and logged so
  a broken extension stays removable — it does **not** abort the removal.
- **MUST prefix your table names** with the lower-cased slug
  (`shop_orders`, not `orders`) so two extensions cannot collide.
- Do not drop core tables (`users`, `wallets`, `wallet_transactions`,
  `conversations`, `run_actions`, `jobs`, `job_worker`).

### 3.2 What removal cleans up for you

`Botex\Extension\Manager::remove($slug, $keepFiles = false)`:

1. `uninstall()` (throws are logged, not fatal)
2. `State::forget($slug)` — drops the enabled flag
3. `Settings::forget()` — deletes `storage/extension-settings/<Slug>.json`
4. `ActionStore::forgetExtension($slug)` — dead Run bindings removed, so a
   press of an old button says "no longer available" instead of lingering
5. `JobRepository::forgetExtension($slug)` — scheduled jobs deleted
6. deletes the folder unless `--keep-files`

Steps 4 and 5 are wrapped in try/catch so an unmigrated database cannot
block a removal. Your own tables are **not** touched — that is what
`uninstall()` is for.

### 3.3 Lifecycle states

| State | `extensions.json` | Loaded? | Contributes? |
| --- | --- | --- | --- |
| dropped in, never installed | absent | yes | **yes** — absent means enabled |
| installed | `{"enabled": true}` | yes | yes |
| disabled | `{"enabled": false}` | yes (on disk) | no |
| removed | absent + folder gone | no | no |

`Botex\Extension\State::isEnabled()` returns `true` for an extension it has
never heard of, so dropping a folder in works without a write. Run
`ext:install` anyway — that is what creates your tables.

**Disabled is a hard off switch.** `Registry::enabled()` filters every
`collect*()` call, so a disabled extension contributes no commands, no
callbacks, no flows, no sections, no runnables and no jobs. Its stored
rows survive; its buttons report stale and its jobs get paused by the
worker rather than running.

---

## 4. Dependency injection: Feeder

`Botex\Bot\Feeder` is the container. Every handler class — command,
callback, middleware, flow, step, admin section, runnable, job — is built
with `Feeder::make()`.

| Method | Behaviour |
| --- | --- |
| `set(string $class, object $instance): void` | register a pre-built instance |
| `get(string $class): object` | fetch a registered instance; throws `RuntimeException` if absent |
| `make(string $class): object` | resolve, autowiring the constructor; caches the result |

### 4.1 The one autowiring rule

`make()` reflects the constructor and resolves **every parameter whose
type is a non-builtin class**. Anything else throws:

```
RuntimeException: Cannot resolve $foo in Extensions\Shop\Service\ShopService
```

```php
// works: all class types
public function __construct(
    private Bot $bot,
    private WalletService $wallet,
    private SettingsFactory $settings,
    private OrderRepository $orders,   // your own class, also autowired
) {}

// throws: string, int, array, nullable scalar, union of builtins,
// and any parameter with a default the container cannot see past
public function __construct(private string $apiKey) {}
```

**Get scalars from `Config` or `Settings`, never from the constructor.**

### 4.2 Everything you can inject

Pre-registered in `bootstrap/app.php` (built with values reflection cannot
supply, so they are `set()` rather than autowired):

| Class | Why it is pre-registered |
| --- | --- |
| `Botex\Support\Config` | takes the config array |
| `Botex\Bot\Feeder` | itself, so injecting the container gives you *this* one |
| `Botex\Extension\State` | takes a file path string |
| `Botex\Extension\Registry` | takes a path string |
| `Botex\Extension\SettingsFactory` | takes a path string |
| `Botex\Telegram\Bot` | takes the bot token string |
| `Botex\Support\Log\Logger` | built from config paths, levels and the notifier (section 26) |

Autowired on demand — inject any of these directly:

| Class | Use |
| --- | --- |
| `Botex\Bot\Dispatcher` | run a middleware chain yourself |
| `Botex\Bot\Conversation\FlowRunner` | start / drive a flow |
| `Botex\Bot\Conversation\Store` | read or clear a session directly |
| `Botex\Bot\Action\ActionBinder` | persist a `Run` intent; rarely needed — buttons bind themselves |
| `Botex\Bot\Action\ActionRunner` | run an action without a button |
| `Botex\Bot\Admin\Panel` | admin menu + back keyboard |
| `Botex\Service\UserService` | users: find, create, block, stats |
| `Botex\Service\WalletService` | money |
| `Botex\Service\JobService` | schedule jobs |
| `Botex\Repository\UserRepository` | lower-level user queries |
| `Botex\Repository\JobRepository` | lower-level job queries |
| your own services / repositories | plain classes, autowired the same way |

### 4.3 Instances are cached — treat them as singletons

`make()` and `set()` both populate the same cache, so the second
`make(ShopService::class)` returns the first instance.

- **MUST NOT** store per-update state on an injected service. One webhook
  invocation is one PHP process, so this is survivable today, but the job
  worker is a long-lived loop where a stale field persists across jobs.
- Pass request data as method arguments (`Update`, `RunContext`,
  `JobContext`, `Session`), never by mutating a service field.

---

## 5. Settings

Runtime-editable configuration that **survives an update**. Defaults ship
with your code; overrides live outside your folder.

```
extensions/Shop/settings.json                  defaults (you ship these)
storage/extension-settings/Shop.json           overrides (admin writes these)
```

Because an update replaces the extension folder wholesale and `storage/`
is untouched, an update can add new defaults without discarding anything
an admin configured.

### 5.1 settings.json

```json
{
  "intro_text": "Welcome to the shop.",
  "max_items": 5,
  "notify_admins": true,
  "plans": ["monthly", "yearly"]
}
```

Any JSON type. **This file is the schema**: `Settings::set()` refuses a key
that is not declared here, so a typo in the panel cannot create a setting
nothing reads.

### 5.2 Reading and writing

Inject `Botex\Extension\SettingsFactory` — never `Settings` itself, which
needs a slug reflection cannot supply.

```php
use Botex\Extension\SettingsFactory;

class ShopService
{
    private const SLUG = 'Shop';   // MUST equal the folder name

    public function __construct(private SettingsFactory $settings) {}

    public function setting(string $key, mixed $default = null): mixed
    {
        return $this->settings->for(self::SLUG)->get($key, $default);
    }
}
```

`SettingsFactory::for(string $slug): Settings` — caches per slug; throws
`RuntimeException` if the slug is not installed.

### 5.3 Settings API

| Method | Notes |
| --- | --- |
| `get(string $key, mixed $default = null): mixed` | override → default → `$default`. **Flat keys only**, no dot notation |
| `set(string $key, mixed $value): void` | writes the override file; **throws `RuntimeException` if `$key` is not in settings.json** |
| `reset(string $key): void` | drops the override, restoring the shipped default |
| `all(): array` | effective values (defaults merged with overrides) |
| `keys(): array` | keys you declared |
| `isOverridden(string $key): bool` | whether an admin changed it |
| `forget(): void` | deletes the override file; called by `Manager::remove()` |

**Always pass a `$default` to `get()`** even for a declared key. If
`settings.json` is missing or unreadable, `Settings` treats defaults as
empty rather than failing, so a hardcoded fallback keeps you running.

Writes are `LOCK_EX` and store **only the overrides**, so the file stays a
diff against your shipped defaults.

### 5.4 Editing settings from the CLI

```
php bin/console ext:settings Shop            # list keys, values, overridden flags
php bin/console ext:set Shop max_items 10    # write an override
php bin/console ext:reset Shop max_items     # restore the default
```

`ext:set` takes a string from argv. Cast on read (`(int)`, `(bool)`) — do
not assume the stored type matches the default's type.

### 5.5 Clamp values you read

An admin can set anything. Validate at the read site:

```php
public function maxItems(): int
{
    // keep the keyboard to one sane row whatever the setting says
    return max(1, min(10, (int) $this->setting('max_items', 5)));
}
```

---

## 6. Database: models, repositories, schema

Eloquent is booted globally by `bootstrap/app.php`. Extension models are
**ordinary Eloquent models**; only the table name is namespaced.

```php
namespace Extensions\Shop\Model;

use Illuminate\Database\Eloquent\Model;

class Order extends Model
{
    protected $table = 'shop_orders';      // MUST be slug-prefixed

    protected $fillable = ['user_id', 'amount', 'status'];

    protected $casts = [
        'amount' => 'integer',
        'paid_at' => 'datetime',
    ];
}
```

### 6.1 Botex\Support\Schema

Every method is idempotent, which is what makes `install()` safe to rerun.

| Method | Returns | Notes |
| --- | --- | --- |
| `createIfMissing(string $table, callable $definition): bool` | `true` if created | `$definition` receives an Illuminate `Blueprint` |
| `dropIfExists(string $table): void` | — | for `uninstall()` |
| `hasTable(string $table): bool` | — | |
| `hasColumn(string $table, string $column): bool` | — | |
| `addMissing(string $table, callable $definition): void` | — | add columns to an existing table |

Adding a column in a later version of your extension:

```php
public static function install(): void
{
    Schema::createIfMissing('shop_orders', function ($table) { /* ... */ });

    // v1.1 added this column; an existing install has to pick it up
    if (!Schema::hasColumn('shop_orders', 'coupon')) {
        Schema::addMissing('shop_orders', function ($table) {
            $table->string('coupon')->nullable();
        });
    }
}
```

There is no migration table. `install()` **is** your migration path, and it
is re-run by `ext:install`, so guard each change with a `hasColumn()` /
`hasTable()` check.

### 6.2 Botex\Support\Db

| Method | Notes |
| --- | --- |
| `transaction(callable $callback): mixed` | commits on return, rolls back on any throwable |
| `inTransaction(): bool` | |
| `level(): int` | nesting depth |

Eloquent nests transactions with savepoints, so calling `Db::transaction()`
inside an existing transaction **joins** it rather than starting a second.
That is what lets a wallet debit and your own order insert commit or roll
back together:

```php
use Botex\Support\Db;

Db::transaction(function () use ($userId, $price) {
    $tx = $this->wallet->debit($userId, $price, 'Order');
    Order::create(['user_id' => $userId, 'amount' => $price, 'tx' => $tx->id]);
    // a throw here rolls the debit back too
});
```

### 6.3 Repository pattern

Core keeps queries in `src/Repository/`. Mirror it:

```php
namespace Extensions\Shop\Repository;

use Extensions\Shop\Model\Order;

class OrderRepository
{
    public function find(int $id): ?Order
    {
        return Order::find($id);
    }

    public function pendingFor(int $userId): array
    {
        return Order::where('user_id', $userId)
            ->where('status', 'pending')
            ->orderByDesc('id')
            ->get()
            ->all();
    }
}
```

No constructor, so `Feeder` autowires it into any handler or service.

### 6.4 Two hard-won database rules

**Use a conditional UPDATE, not read-then-write, for anything
exactly-once.** Put the guard in the `WHERE` clause and read the answer
from the affected-row count. A read, a check in PHP, then a write lets two
concurrent requests both pass the check:

```php
// right: only one caller can get 1 back
$claimed = Order::where('id', $id)
    ->whereNull('paid_at')
    ->update(['paid_at' => Carbon::now()]);

if ($claimed !== 1) {
    return; // someone else already did this
}
```

This is exactly how `ActionStore::claim()`, `JobRepository::claim()` and
`WalletService::debit()` guarantee their invariants.

**`Model::raw()` does not work.** Eloquent's builder `__call` discards the
forwarded return value and hands back `$this`, so `raw()` silently injects
a Builder into your update. Use `Illuminate\Database\Query\Expression`:

```php
use Illuminate\Database\Query\Expression;

Order::where('id', $id)->update(['attempts' => new Expression('attempts + 1')]);
```

### 6.5 Dates

`Illuminate\Support\Carbon` is available. **The `now()` helper is not** —
`illuminate/support`'s global helpers are not autoloaded in this project.

```php
use Illuminate\Support\Carbon;

$when = Carbon::now()->addMinutes(30);   // right
$when = now()->addMinutes(30);           // Error: undefined function now()
```

---

## 7. Sending messages: the Telegram surface

Inject `Botex\Telegram\Bot`. It carries three methods (from the
`Botex\Telegram\Support\Method` trait):

| Method | Returns |
| --- | --- |
| `sendMessage(string $text): MessageBuilder` | fluent builder |
| `editMessage(string $text, int $messageId): MessageBuilder` | fluent builder |
| `answerCallback(string $callbackId, string $text = '', bool $alert = false): array` | sends immediately; $alert makes the text a popup |

### 7.1 MessageBuilder

| Method | Notes |
| --- | --- |
| `to(int\|string $chatId): self` | chat id or `@channel` |
| `parseMode(string $mode): self` | `'HTML'` or `'MarkdownV2'` |
| `replyMarkup(array $markup): self` | the array from `Keyboard::build()`; JSON-encoded for you |
| `replyTo(int $messageId): self` | reply threading |
| `execute(): array` | sends; **caches**, so a second call returns the same response |

**`__destruct()` sends the message automatically.** So both of these send
exactly once:

```php
$this->bot->sendMessage('Hi')->to($chatId);              // sent on destruct
$response = $this->bot->sendMessage('Hi')->to($chatId)->execute();  // sent now
```

Two consequences worth internalising:

- **MUST NOT** build a `MessageBuilder` you do not intend to send. There is
  no "cancel". Decide first, then build.
- Call `execute()` explicitly when you need the response array, or when
  ordering matters (e.g. sending inside a loop where a later throw would
  otherwise still flush the builder at an unpredictable moment).

### 7.2 HTML escaping

`parseMode('HTML')` makes every interpolated value a potential injection.
Core escapes at each site; do the same:

```php
private function escape(string $value): string
{
    return htmlspecialchars($value, ENT_QUOTES, 'UTF-8');
}

$text = '<b>Order</b>' . PHP_EOL . $this->escape($userSuppliedNote);
```

**MUST escape** anything from an update, your own tables, or settings when
using `parseMode('HTML')`.

### 7.3 Keyboards

`Botex\Telegram\Builders\Keyboard\Keyboard`:

| Method | Notes |
| --- | --- |
| `static inline(): self` | inline keyboard, attached to the message |
| `static menu(): self` | reply keyboard, replaces the user's keyboard |
| `row(InlineButton\|MenuButton ...$buttons): self` | one row per call; **throws `InvalidArgumentException` when a button type does not match the keyboard type** |
| `resize(bool $resize = true): self` | **throws `Exception` on an inline keyboard** |
| `bindWith(ActionBinder $binder): self` | supply the binder yourself; only needed where the container is unreachable, e.g. a test |
| `build(): array` | binds every pending `->action()` (section 14.3), then pass to `replyMarkup()` |

`InlineButton`:

| Method | Notes |
| --- | --- |
| `static make(string $text): self` | no target yet — for `->action()` to fill in |
| `static callback(string $text, ?string $callback = null): self` | callback data, **64 bytes max** (Telegram limit); pass `null` when `->action()` supplies the target |
| `static url(string $text, string $url): self` | opens a link |
| `action(Run $run): self` | attach a Run target; nothing is persisted until `build()` |
| `audience(Update\|int $audience): self` | who the button is shown to; optional for inline buttons |
| `emoji(string $emojiId): self` | custom emoji id |
| `style(string $style): self` | e.g. `'primary'` |
| `toArray(): array` | called by `Keyboard::build()` |

`MenuButton`: `static make(string $text)`, `action(Run $run)`,
`audience(Update|int $audience)`, `emoji()`, `style()`, `toArray()`.

```php
use Botex\Telegram\Builders\Keyboard\{Keyboard, InlineButton};

$markup = Keyboard::inline()
    ->row(
        InlineButton::callback('Buy', 'shop:buy'),
        InlineButton::callback('Cancel', 'shop:cancel'),
    )
    ->row(InlineButton::url('Help', 'https://example.com'))
    ->build();

$this->bot->sendMessage('Pick one')->to($chatId)->replyMarkup($markup);
```

Build keyboards in a dedicated class when they need dependencies —
see `Extensions\Clock\Keyboard\ClockKeyboard`, whose buttons carry
`->action(Run::...)` targets bound at `build()` time.

### 7.4 Reading the update

`Botex\Telegram\Update` — all accessors are null-safe:

| Method | Returns | Notes |
| --- | --- | --- |
| `raw(): array` | the decoded payload | anything not covered below |
| `isMessage(): bool` | | |
| `isCallback(): bool` | | |
| `text(): ?string` | message text | **null on a callback** |
| `messageId(): ?int` | | the callback's source message when `isCallback()` |
| `callbackId(): ?string` | | needed by `answerCallback()` |
| `fromId(): ?string` | the **user** id | note: `string`, cast it |
| `chatId(): int\|string\|null` | the **chat** id | |
| `callbackData(): ?string` | | null on a message |

`fromId()` and `chatId()` differ in groups: `fromId()` is the person,
`chatId()` is where to reply. **Use `fromId()` for identity and
permissions, `chatId()` for sending.**

---

## 8. Commands

`Botex\Bot\Command\CommandInterface`:

```php
namespace Extensions\Shop\Command;

use Botex\Bot\Command\CommandInterface;
use Botex\Bot\Middleware\NotBlocked;
use Botex\Telegram\{Bot, Update};

class Buy implements CommandInterface
{
    public function __construct(private Bot $bot) {}

    public static function command(): string { return '/buy'; }

    public static function button(): string { return 'Buy'; }

    public static function middleware(): array { return [NotBlocked::class]; }

    public function handle(Update $update): void
    {
        $this->bot->sendMessage('Shop')->to($update->chatId());
    }
}
```

| Member | Contract |
| --- | --- |
| `static command(): string` | the slash command, **including the leading `/`** |
| `static button(): string` | reply-keyboard label that stands in for it |
| `static middleware(): array` | `class-string<MiddlewareInterface>[]`, checked in order before `handle()` |
| `handle(Update $update): void` | do the work |

Register it in your `commands()` hook, or the router never sees it.

### 8.1 Arguments

`command()` is matched on the **first word** of the message, so
`/buy 3`, `/buy@YourBot` and `/buy@YourBot 3` all reach `Buy::handle()`.
Parse the rest yourself:

```php
private function argument(?string $text): string
{
    $parts = preg_split('/\s+/', trim((string) $text)) ?: [];

    return strtolower(trim($parts[1] ?? ''));
}
```

Clamp what you parse (`Clock\Command\Remind` caps minutes at 10080,
`Watch` at 1440) — an argument is user input.

`button()` is matched **whole**, since labels are free text and usually
contain spaces.

### 8.2 Naming constraints

- **MUST NOT** reuse a core command: `/start`, `/admin`, `/wallet`,
  `/cancel`. Core commands are matched **first**, so yours would be dead
  code.
- Same for `button()` labels: `Start`, `Admin`, `Wallet`, `Cancel`.
- Prefix to stay clear of other extensions: `/shop_buy` rather than `/buy`.
- Two extensions declaring the same command: the one that registers first
  wins (`Registry` iterates slugs alphabetically). Do not rely on it.

---

## 9. Callbacks

`Botex\Bot\Callback\CallbackInterface`:

| Member | Contract |
| --- | --- |
| `static callback(): string` | the exact callback data this class owns |
| `static middleware(): array` | same as commands |
| `handle(Update $update): void` | |

```php
class Confirm implements CallbackInterface
{
    public function __construct(private Bot $bot) {}

    public static function callback(): string { return 'shop:confirm'; }

    public static function middleware(): array { return []; }

    public function handle(Update $update): void
    {
        // MUST answer, or the button spins forever
        $id = $update->callbackId();
        if ($id !== null) {
            $this->bot->answerCallback($id);
        }

        $this->bot->editMessage('Confirmed.', (int) $update->messageId())
            ->to($update->chatId());
    }
}
```

**MUST call `answerCallback()`** in a callback handler. Telegram shows the
button as loading until you do. Optionally pass text for a toast:
`answerCallback($id, 'Saved.')`, or a third argument for a popup the user
has to dismiss: `answerCallback($id, 'That did not work.', true)`.

Telegram accepts **one** answer per press, and caps the text at 200
characters (longer text is cut for you). So a handler that might refuse
should answer *after* doing the work rather than before: answering early
to stop the spinner spends the only chance to say why something failed.

### 9.1 Callback families: MatchesCallback

One class handling `shop:item:1`, `shop:item:2`, … — implement the opt-in
marker `Botex\Bot\Callback\MatchesCallback`, adding
`static matches(string $data): bool`:

```php
use Botex\Bot\Callback\{CallbackInterface, MatchesCallback};

class ManageItem implements CallbackInterface, MatchesCallback
{
    public const PREFIX = 'shop:item:';

    public static function callback(): string { return self::PREFIX; }

    public static function matches(string $data): bool
    {
        return self::parse($data) !== null;
    }

    /** Validate the shape here, not in handle(). @return int|null */
    private static function parse(string $data): ?int
    {
        if (!str_starts_with($data, self::PREFIX)) {
            return null;
        }

        $id = substr($data, strlen(self::PREFIX));

        return ctype_digit($id) ? (int) $id : null;
    }
}
```

**Resolution order in `Router::findCallback()`:** every `callback()` exact
match is tried first, across all classes; only then is `matches()` tried.
So a family declaring `shop:item:` can never shadow an exact
`shop:item:new` owned by another class.

### 9.2 Callback data budget

Telegram caps `callback_data` at **64 bytes**. Never put a class name, a
serialized payload, or user text in it. Two supported shapes:

1. A short namespaced key you parse: `shop:item:42`.
2. A **Run action token** (section 12), which is 28 bytes and carries
   arbitrary structured data server-side.

Do not reserve prefixes core owns: `admin:`, `run:`, `flow:`, `step:`.

---

## 10. Middleware

`Botex\Bot\Middleware\MiddlewareInterface`:

```php
namespace Extensions\Shop\Middleware;

use Botex\Bot\Middleware\MiddlewareInterface;
use Botex\Telegram\{Bot, Update};

class HasSubscription implements MiddlewareInterface
{
    public function __construct(private Bot $bot) {}

    public function handle(Update $update): bool
    {
        if ($this->subscribed($update)) {
            return true;
        }

        // returning false means YOU tell the user why
        $this->bot->sendMessage('Subscribe first.')->to($update->chatId());

        return false;
    }
}
```

`handle(Update $update): bool` — `true` continues, `false` stops before the
handler runs.

**A middleware returning `false` is responsible for telling the user why.**
`Dispatcher` stops silently otherwise, and the user sees nothing happen.
The one deliberate exception is `NotBlocked`, which is silent on purpose:
telling someone they are blocked invites them to keep poking.

### 10.1 Core middleware you should reuse

| Class | Effect |
| --- | --- |
| `Botex\Bot\Middleware\NotBlocked` | stops blocked users, silently |
| `Botex\Bot\Middleware\IsAdmin` | requires `fromId()` in `config('admins')`, replies with a refusal |

**MUST reuse `IsAdmin`** for admin-only handlers rather than rolling your
own check. It is the single place the admin list is interpreted.

```php
public static function middleware(): array
{
    return [NotBlocked::class, IsAdmin::class];
}
```

Order matters: they run left to right, and the first `false` wins.

### 10.2 Where middleware is enforced

| Entry point | Chain source |
| --- | --- |
| command / callback | `Dispatcher::dispatch()` runs `$class::middleware()` |
| runnable (Run action) | `ActionRunner` calls `Dispatcher::guard()` with `RunnableInterface::middleware()` |
| admin section | **none of its own** — reaching it already passed `IsAdmin` on `OpenSection` |
| flow step | **none** — gate the command or callback that starts the flow |
| job | **none** — no update exists, so there is nobody to authorise |

`Dispatcher::guard(string $class, Update $update): bool` is public so you
can run a chain yourself without dispatching.

**Gate the entrance, not the room.** A flow's steps and a job's handler are
unreachable without going through something you already gated, so put the
check there and keep it in one place.

---

## 11. Router resolution order

Knowing this is how you avoid your handler being shadowed.

**Messages** — `Router::handleMessage()`:

1. If the text is **not** a slash command, offer it to the active flow.
   `FlowRunner::handle()` returning `true` ends processing.
   → *A slash command always wins, so `/cancel` works from inside a flow.
   Anything else is treated as an answer, so a free-text step cannot be
   hijacked by a reply that happens to match a button label.*
2. Match a command: first word against `command()`, whole text against
   `button()`. Core commands are checked before extension commands.
3. Only if nothing matched (`Command\Unknown`), try a reply-keyboard Run
   binding via `ActionRunner::handleText()`.
   → *Asked here rather than earlier so a core button can never be
   shadowed by a stored binding.*
4. Dispatch the matched command, or `Command\Unknown`.

**Callbacks** — `Router::handleCallback()`:

1. If **no** registered callback claims this data, offer it to the active
   flow. → *A registered callback wins over the flow, so the admin menu
   stays usable mid-conversation. `ChoiceStep` data (`step:<name>:<value>`)
   is deliberately unregistered, which is why the flow matches it.*
2. Dispatch the matched callback, or `Callback\Unknown`.

Precedence summary, highest first:

```
messages:   slash command  >  active flow  >  reply-keyboard Run binding
callbacks:  registered callback  >  active flow
both:       core handlers  >  extension handlers
```

---

## 12. Conversations (flows)

Multi-step data collection with server-side validation and a persisted
session. Use a flow whenever you need more than one answer.

Design split, and it is worth respecting: **steps are declarative, the
runner does everything else.** A step never sends a message and never
writes the session; `FlowRunner` sends, saves, advances, rejects and
clears. That is why steps stay testable and short.

### 12.1 FlowInterface

```php
namespace Extensions\Shop\Flow;

use Botex\Bot\Conversation\{FlowInterface, Session};
use Botex\Telegram\Bot;

class CheckoutFlow implements FlowInterface
{
    public function __construct(
        private Bot $bot,
        private ShopService $shop
    ) {}

    public static function name(): string { return 'shop.checkout'; }

    public static function steps(): array
    {
        return [Step\AskPlan::class, Step\AskNote::class];
    }

    public function complete(Session $session): void
    {
        $this->shop->order(
            $session->telegramId,
            (string) $session->get(Step\AskPlan::name()),
            (string) $session->get(Step\AskNote::name(), '')
        );

        $this->bot->sendMessage('Done.')->to($session->telegramId);
    }
}
```

| Member | Contract |
| --- | --- |
| `static name(): string` | stable id stored in the session. **Namespace it** (`shop.checkout`) |
| `static steps(): array` | ordered `class-string<StepInterface>[]`; **MUST NOT be empty** |
| `complete(Session $session): void` | runs after the last step |

Register in `flows()`. `FlowRunner::start()` throws `RuntimeException` for
an unregistered name or an empty step list.

**`complete()` runs with the session already cleared.** A throw in your
code cannot trap the user in a finished flow — but it also means the
session is gone, so read everything you need off `$session` (the object is
still populated) rather than expecting to re-read it later.

### 12.2 StepInterface

| Member | Contract |
| --- | --- |
| `static name(): string` | step id; also the **default session key** for its answer |
| `prompt(Session $session): Prompt` | what to ask |
| `validate(Update $update, Session $session): Answer` | accept or reject |

`Botex\Bot\Conversation\Prompt`:

- `new Prompt(string $text, array $rows = [])` — `$rows` is
  `array<int, array<InlineButton>>`
- `keyboard(): array` — **always appends a Cancel button**, so a user is
  never stranded inside a flow

`Botex\Bot\Conversation\Answer`:

- `static ok(mixed $value): self`
- `static error(string $message): self`
- readonly `valid`, `value`, `error`

Prompts are sent with `parseMode('HTML')`, so **escape interpolated values
in prompt text**.

### 12.3 Step base classes

**`Botex\Bot\Conversation\Step\TextStep`** — reads a line of text. Implement
`question(Session): string`; override `$minLength` (default 1) and
`$maxLength` (default 4096). Already rejects: non-text messages, anything
starting with `/` (the user is leaving, not answering), and out-of-range
lengths. Returns the **trimmed** string.

```php
class AskNote extends TextStep
{
    protected int $maxLength = 500;

    public static function name(): string { return 'note'; }

    public function question(Session $session): string
    {
        return 'Add a note, or press Cancel.';
    }
}
```

`TextStep` does not define `prompt()`. Either add one, or extend and call
`parent::validate()` for extra rules — see
`Botex\Bot\Admin\Flow\Step\AskTelegramId`, which layers a digits-only check
on top.

**`Botex\Bot\Conversation\Step\ChoiceStep`** — a fixed set of buttons.
Implement `question(Session): string` and
`choices(Session): array<string,string>` (value → label); override
`$perRow` (default 3). Provides both `prompt()` and `validate()`.

```php
class AskPlan extends ChoiceStep
{
    protected int $perRow = 2;

    public static function name(): string { return 'plan'; }

    public function question(Session $session): string { return 'Pick a plan.'; }

    public function choices(Session $session): array
    {
        return ['monthly' => 'Monthly', 'yearly' => 'Yearly'];
    }
}
```

The answer is matched against the declared keys, so **a stale keyboard from
an old message can never inject a value you did not offer**. Callback data
is namespaced `step:<name>:<value>` automatically, so two steps' choices
cannot collide.

### 12.4 Session

`Botex\Bot\Conversation\Session` — readonly `telegramId`, readonly `flow`,
**mutable** `step`, plus `get(string $key, mixed $default = null)`,
`set(string $key, mixed $value)`, `has(string $key)`, `all(): array`.

The session holds the flow **name**, never a class name, so nothing
resolved out of the database is ever instantiated directly.

Answers are stored under the step's `name()`, so read them with
`$session->get(AskPlan::name())`. Seed values passed to `start()` are
available from step one.

Persisted to the `conversations` table (one row per `telegram_id`,
`data` JSON-cast) — a table rather than a file so two concurrent updates
from the same user cannot interleave into a half-written state. **Only
JSON-encodable values**: no objects, no closures, no models.

### 12.5 Starting a flow

Inject `Botex\Bot\Conversation\FlowRunner`:

| Method | Notes |
| --- | --- |
| `start(string $flowName, Update $update, array $data = []): void` | replaces any unfinished flow, then asks step one |
| `active(int $telegramId): bool` | is a flow in progress |
| `handle(Update $update): bool` | called by `Router`; you rarely call it |
| `FlowRunner::CANCEL` | the `'flow:cancel'` callback data |

```php
public function handle(Update $update): void
{
    $plans = $this->shop->activePlans();

    if (!$plans) {
        $this->bot->sendMessage('Nothing for sale yet.')->to($update->chatId());
        return;
    }

    // Snapshot the options so an admin editing them mid-flow cannot
    // shift the user onto something they were never shown.
    $this->runner->start(CheckoutFlow::name(), $update, ['plans' => $plans]);
}
```

**Snapshot volatile data into the seed array.** This is the pattern
`Feedback` uses for admin-defined questions, and it is what keeps a
half-finished form coherent.

### 12.6 Variable-length steps: RepeatableStep

For a step that asks an unknown number of questions, implement
`Botex\Bot\Conversation\RepeatableStep` alongside a step base class:

| Method | Contract |
| --- | --- |
| `store(Session $session, mixed $value): void` | record one answer — **called instead of the default session write**, so you own the key |
| `hasMore(Session $session): bool` | `true` asks again, `false` advances |

The step stays current until `hasMore()` returns false. Because you own
storage, successive answers do not overwrite each other. See
`Extensions\Feedback\Flow\Step\AskQuestions` for the full pattern
(pending = seeded questions minus answered ones).

### 12.7 Cancellation and self-healing

- `/cancel` and the auto-appended Cancel button both clear the session.
- If the flow or step **vanishes mid-conversation** — you removed or
  disabled the extension — `FlowRunner` clears the session and returns
  `false`, letting the router handle the message normally. The user is not
  stuck answering a question nobody reads. No action needed on your side.
- Rejected answers re-ask the same step with your error text.
- Only one flow per user at a time; `start()` replaces any other.

---

## 13. Admin panel sections

`Botex\Bot\Admin\AdminSectionInterface` puts your panel on the `/admin` home
screen, indistinguishable from a core one.

| Member | Contract |
| --- | --- |
| `static key(): string` | stable key; **MUST NOT be empty or contain a colon** (it is the `admin:s:<key>` suffix) |
| `static title(): string` | button label on the admin home screen |
| `handle(Update $update): void` | render the panel |

```php
namespace Extensions\Shop\Admin;

use Botex\Bot\Admin\{AdminSectionInterface, Panel};
use Botex\Telegram\Builders\Keyboard\{InlineButton, Keyboard};
use Botex\Telegram\{Bot, Update};

class ShopSection implements AdminSectionInterface
{
    public const TOGGLE = 'shop:admin:toggle:';

    public function __construct(
        private Bot $bot,
        private OrderRepository $orders
    ) {}

    public static function key(): string { return 'shop'; }

    public static function title(): string { return 'Shop'; }

    public function handle(Update $update): void
    {
        $this->bot->editMessage($this->text(), (int) $update->messageId())
            ->to($update->chatId())
            ->parseMode('HTML')
            ->replyMarkup($this->keyboard());
    }

    /** Re-renders after a change, reusing the same message. */
    public function refresh(Update $update, string $notice = ''): void
    {
        $text = $notice === '' ? $this->text() : $notice . PHP_EOL . PHP_EOL . $this->text();

        $this->bot->editMessage($text, (int) $update->messageId())
            ->to($update->chatId())
            ->parseMode('HTML')
            ->replyMarkup($this->keyboard());
    }

    private function keyboard(): array
    {
        return Keyboard::inline()
            ->row(InlineButton::callback('Back', Panel::HOME))
            ->build();
    }
}
```

**Do not add `IsAdmin` to your section.** Reaching any section goes through
`Callback\Admin\OpenSection`, which is already gated, so the admin check
lives in exactly one place. Your section is unreachable without it.

**Use `editMessage()`, not `sendMessage()`** — panels navigate in place.
Always offer a way back: `Panel::HOME` (`'admin:home'`) as callback data,
or `Panel::backKeyboard()` for a ready-made single-button keyboard.

| `Botex\Bot\Admin\Panel` | |
| --- | --- |
| `Panel::HOME` | `'admin:home'` |
| `Panel::SECTION` | `'admin:s:'` |
| `static section(string $key): string` | builds `admin:s:<key>` |
| `static backKeyboard(): array` | one Back button to home |
| `menu(): array` | the home screen, two sections per row (injected instance) |

A `refresh()`-style method paired with your own callbacks is the standard
way to mutate and re-render; `Feedback` does exactly this. The section is
autowired, so a callback can inject it and call `refresh()` directly.

### 13.1 Section registration rules

- Register in `adminSections()`.
- Core sections (`stats`, `users`, `ext`) are registered first and **cannot
  be displaced** — a duplicate key is dropped with a logged warning.
- An invalid key (empty or containing a colon) is skipped and logged.
- Keys are global; prefix distinctively.

---

## 14. Run actions

A generic way to bind **any** button to a named target carrying arbitrary
structured data, without that data ever travelling through Telegram.

Use a Run action instead of a plain callback when the button needs to carry
state (which plan, which order, which zone), when you need single-use
semantics, when you need the same handler to serve both an inline button
and a reply-keyboard label, or when a button should press a command.

**How it works.** An action lives on the button that carries it:

```php
InlineButton::make('Buy')->action(Run::extension(PlaceOrder::name(), ['plan' => 'monthly']))
```

At render time — `Keyboard::build()` — the intent is persisted and the
button is given back an opaque token (inline) or its label is bound to one
user (menu). Telegram only ever sees `run:` + 24 hex characters, or — for a
reply keyboard — nothing but the label. On press, the token (or the label,
scoped to the user) resolves the row, and the row's target is resolved
through an allowlist: a runnable by `extension` + `action` names, a command
by verb.

### 14.1 RunnableInterface

```php
namespace Extensions\Shop\Action;

use Botex\Bot\Action\{RunContext, RunnableInterface};
use Botex\Telegram\{Bot, Update};

class PlaceOrder implements RunnableInterface
{
    public function __construct(
        private Bot $bot,
        private ShopService $shop
    ) {}

    public static function name(): string { return 'place_order'; }

    public static function middleware(): array { return []; }

    public function handle(Update $update, RunContext $context): void
    {
        $plan = $context->string('plan');
        $this->shop->place($update->fromId(), $plan);

        $this->bot->sendMessage('Ordered ' . $plan)->to($update->chatId());
    }
}
```

| Member | Contract |
| --- | --- |
| `static name(): string` | stable name stored on the row |
| `static middleware(): array` | checked via `Dispatcher::guard()` before `handle()` |
| `handle(Update $update, RunContext $context): void` | |

Register in `runnables()`. Addressed as `<Slug>:<name>`.

**Renaming `name()` orphans every button already sent** — they degrade to
"This button is no longer available." Treat it as a published API.

### 14.2 Run: describing the intent

`Botex\Bot\Action\Run` is the intent object — what a button should do when
pressed. It carries no token and knows nothing about Telegram; the binder
persists it when the keyboard builds. Immutable: every method returns a new
instance.

Three factories, one per kind of target:

| Factory | Resolves through | Notes |
| --- | --- | --- |
| `static command(string $command, array $arguments = []): self` | the command allowlist (`Botex\Bot\Command\Commands`) | `'/help'` or `'help'`; arguments are positional (section 14.4) |
| `static extension(string $action, array $data = [], ?string $slug = null): self` | the runnable allowlist (`Runnables`) | `$slug` inferred from the calling class's namespace when omitted |
| `static core(string $action, array $data = []): self` | `Runnables` under the reserved slug `core` | for runnables contributed by core |

Modifiers and queries:

| Method | Notes |
| --- | --- |
| `with(array $data): self` | replace data wholesale; re-validates for a command target |
| `set(string $key, mixed $value): self` | add/overwrite one key; **throws for a command target** — commands take positional arguments |
| `expiresIn(int $seconds): self` | throws on negative |
| `never(): self` | no expiry; use sparingly |
| `once(bool $once = true): self` | single-use |
| `isCommand(): bool`, `isAction(): bool`, `isCore(): bool` | which kind of target |
| `key(): string` | `"<extension>:<name>"`, how an action target is addressed in `Runnables` |
| `commandText(): string` | the text a command target presses as, e.g. `"/remind 30"` |
| `Run::CORE` | `'core'`, the reserved slug |
| `Run::NO_EXPIRY` | `0` |
| `Run::COMMAND` / `Run::ACTION` | the two target kinds, stored on the row's `target` column |

```php
use Botex\Bot\Action\Run;

$run = Run::extension(PlaceOrder::name(), ['plan' => 'monthly'])
    ->once()              // a double tap runs it once
    ->expiresIn(3600);
```

**Slug inference.** `Run::extension()` without a `$slug` reads the calling
class's namespace: a class under `Extensions\Clock\` addresses its own
runnables without repeating `'Clock'` at every call site. Anything outside
an `Extensions\<Slug>\` namespace **MUST** pass the slug explicitly — the
factory throws `InvalidArgumentException` rather than guess.

**Data rules.** `$data` **MUST be JSON-encodable**; `$slug` and `$action`
are validated against `[A-Za-z0-9._-]+` (they end up in a colon-joined
lookup key, so a colon in either would make that key ambiguous).

**MUST use `->once()` for anything with a side effect** — charging a
wallet, placing an order, granting access. Reusable is the default, which
suits a refresh button.

### 14.3 Attaching actions to buttons

Both button types take `->action(Run $run)`:

```php
use Botex\Bot\Action\Run;
use Botex\Telegram\Builders\Keyboard\{Keyboard, InlineButton, MenuButton};

$markup = Keyboard::inline()
    ->row(InlineButton::make('Buy monthly')->action($run))
    ->build();

$menu = Keyboard::menu()
    ->row(MenuButton::make('Show time')->action($run))
    ->build();
```

**Binding is deferred to `Keyboard::build()`.** Describing a keyboard and
never sending it writes nothing to the store, and a keyboard built twice
does not mint a second token. The binder is resolved from the container on
first need; a keyboard with no actions never touches it.

**Who the button is for.** Inside a webhook request there is an ambient
current update (`Botex\Bot\CurrentUpdate`, set once per update by
`public/webhook.php`), so the usual case needs nothing: the button binds to
whoever sent the update being handled. Outside a request — a job, a console
command — there is no ambient user:

- An **inline** button still works; its token identifies the row on its
  own. Pass `->audience(Update|int $audience)` to record who it was for.
- A **menu** button **MUST** name its audience with
  `->audience(Update|int $audience)` — the binder throws
  `InvalidArgumentException` otherwise, because a label binding that is not
  scoped to a user means nothing.

**Fail fast.** Binding checks the target against the allowlists, so a typo
throws `Botex\Bot\Action\UnknownAction` where the keyboard is built, not
silently when a user presses it. A *disabled* extension is not an error at
build time — binding for it is pointless but harmless, and the runner
degrades gracefully.

### 14.4 Command targets

`Run::command()` makes a button press a real command, exactly as if the
user had typed it:

```php
Keyboard::menu()
    ->row(MenuButton::make('Remind me in 30')->action(Run::command('remind', [30])))
    ->build();   // presses as "/remind 30"
```

Rules:

- The verb resolves through `Botex\Bot\Command\Commands`, the same allowlist
  the router uses — core commands first, then every enabled extension's
  `commands()`. A button cannot reach anything typing could not, and a
  command from a disabled extension resolves to nothing.
- **Arguments are positional scalars** — they become words in a command
  line. A non-list, a non-scalar, or a value containing whitespace throws
  `InvalidArgumentException`. `->set()` throws for the same reason.
- At press time the runner rebuilds the text from the stored verb and
  arguments (`commandText()`) and hands the command an update reshaped as a
  message (`Update::asCommand()`), so a command reads its arguments where
  it always does. Nothing that reaches the router was ever a free-form
  string off the wire.
- **Middleware runs.** A command target passes `Dispatcher::guard()`, so a
  button cannot walk past a gate typing would have hit.

Use a command target when the behaviour already lives in a command; use a
runnable when you need structured data or the handler should know it came
from a button (`RunContext`).

### 14.5 ActionBinder: binding without a button

Buttons bind themselves; `Botex\Bot\Action\ActionBinder` stays public for the
cases a button shape does not cover. Inject it, or let `Keyboard` resolve
it.

| Method | Notes |
| --- | --- |
| `inline(Run $run, Update\|int\|null $audience = null): string` | persists and returns the callback data |
| `menu(Run $run, string $label, Update\|int\|null $audience = null): void` | **audience required**; drops any earlier binding for the same label |
| `bind(Run $run, string $kind = ActionToken::INLINE, ?int $telegramId = null, ?string $label = null): ActionToken` | persist and get the token itself |
| `ttl(): int` | default ttl from config |
| `maybePrune(): int` | sampled cleanup; `ActionRunner` calls this for you |

### 14.6 Reply keyboards are scoped per user

A reply-keyboard label carries **nothing** back — no token, just the text.
So the binding is only unambiguous when scoped to one user, which is why
`menu()` requires an audience and `ActionStore::findByLabel()` filters by
`telegram_id` and takes the newest match. Two users pressing "Monthly"
resolve to their own rows.

`menu()` also drops earlier bindings for the same user and label, since a
new reply keyboard replaces the old one on screen and stale rows would
only pile up.

**MUST NOT** use a label that equals any command's `command()` or
`button()`: the router matches commands first, so the binding would never
be reached (step 3 in section 11).

### 14.7 RunContext

Everything here comes from the stored row, never from the update, so you
can trust it: the token proved the press, the row holds the intent.

| Member | Notes |
| --- | --- |
| `extension`, `action`, `data`, `presses`, `label`, `token` | readonly |
| `get(string $key, mixed $default = null): mixed` | **dot notation** for nesting |
| `has(string $key): bool` | |
| `int(string $key, int $default = 0): int` | |
| `string(string $key, string $default = ''): string` | |
| `isFirstPress(): bool` | `presses <= 1` |
| `isReply(): bool` | came from a reply-keyboard label |

`isReply()` is how one handler serves both button types — an inline press
has a message of yours to edit, a label press does not:

```php
if (!$context->isReply() && $update->messageId() !== null) {
    $this->bot->editMessage($text, $update->messageId())->to($update->chatId());
    return;
}

$this->bot->sendMessage($text)->to($update->chatId());
```

### 14.8 What the runner handles for you

`Botex\Bot\Action\ActionRunner` owns every side effect of a press, so
runnables stay as simple as steps:

| Condition | User sees |
| --- | --- |
| row gone, or its extension removed/disabled | `ActionRunner::STALE` — "This button is no longer available." |
| ttl elapsed | `ActionRunner::EXPIRED` — "This button has expired…" |
| single-use, already used | `ActionRunner::SPENT` — "That was already done." |
| your handler threw | `ActionRunner::FAILED` — "Something went wrong…", exception logged |

State is checked **before** the allowlist, so a user pressing an expired
button is told it expired rather than that it vanished.

The press is **claimed with a conditional UPDATE before your handler
runs**, so a handler that throws halfway cannot be retried into a double
charge: a `once` action is spent by the attempt, not by its outcome. That
claim — not the earlier `isSpent()` read — is the real guard against two
simultaneous presses.

You do not need to call `answerCallback()` in a runnable; the runner
already did.

`ActionRunner::run(Run $run, Update $update): bool` runs an intent
directly, letting one action chain into another (a confirm step handing off
to the action it confirms) without minting a throwaway button. Command
targets work here too.

### 14.9 Expiry and config

| Config key | Env | Default | Meaning |
| --- | --- | --- | --- |
| `actions.ttl` | `ACTION_TTL` | 604800 (1 week) | default button lifetime |
| `actions.prune_chance` | `ACTION_PRUNE_CHANCE` | 200 | prune roughly 1 update in N |

Per-action `expiresIn()` / `never()` overrides the default. Pruning rides
along on real traffic, so no cron entry is needed. `ActionStore::SPENT_GRACE`
(86400) keeps a spent single-use row briefly so a double tap reports
"already done" rather than the vaguer "no longer available".

**Upgrade path.** Rows written before command targets existed have no
`target` value of their own; the migration adds the column with a default
of `Run::ACTION`, so every old row keeps resolving through the runnable
allowlist exactly as before. Nothing needs re-sending or re-binding.

---

## 15. Jobs

Deferred and recurring work, run by a separate process. Use a job whenever
something must happen **later** or **repeatedly**.

**MUST NOT `sleep()` in a handler.** A webhook holds a Telegram connection;
a command that sleeps blocks the update. Schedule a job and return
immediately — `Clock\Command\Remind` writes one row and replies.

### 15.1 JobInterface

```php
namespace Extensions\Shop\Job;

use Botex\Bot\Job\{JobContext, JobInterface};
use Botex\Telegram\Bot;

class ExpireOrders implements JobInterface
{
    public function __construct(
        private Bot $bot,
        private OrderRepository $orders
    ) {}

    public static function name(): string { return 'expire_orders'; }

    public function handle(JobContext $context): void
    {
        foreach ($this->orders->stale() as $order) {
            $this->orders->expire((int) $order->id);
        }
    }
}
```

| Member | Contract |
| --- | --- |
| `static name(): string` | stable name stored on the row |
| `handle(JobContext $context): void` | throwing = failure + retry; returning = success |

Register in `jobs()`. Addressed as `<Slug>:<name>`.

**`handle()` takes no `Update`.** A job runs long after the message that
scheduled it, often with no message at all. Everything it needs — chat id,
user id, order id — **MUST travel in the job data**.

**Throw to retry, return to succeed.** A temporary problem (an API
timeout) should throw so the backoff picks it up. Permanently bad data
should return — see `Clock\Job\SendTime`, which returns early on a missing
chat id because retrying cannot fix it.

### 15.2 Schedule

`Botex\Bot\Job\Schedule` — immutable; built through named constructors so an
interval schedule can never end up without an interval.

| Constructor | Meaning |
| --- | --- |
| `now()` | once, as soon as the worker next looks |
| `in(int $seconds)` | once, N seconds from now — **the "+30 minutes" case** |
| `at(\DateTimeInterface $when)` | once, at a moment |
| `every(int $seconds, int $maxRuns = FOREVER)` | repeating, first run one interval from now |
| `everyMinutes(int $minutes, int $maxRuns = FOREVER)` | |
| `everyHours(int $hours, int $maxRuns = FOREVER)` | |
| `daily(int $maxRuns = FOREVER)` | `everyHours(24)` |

| Modifier / query | Notes |
| --- | --- |
| `startingNow(): self` | first run immediately instead of after one interval |
| `startingIn(int $seconds): self` | delay the first run, same rhythm after |
| `startingAt(\DateTimeInterface $when): self` | |
| `times(int $runs): self` | cap total runs |
| `repeats(): bool`, `runsForever(): bool` | |
| `nextAfter(Carbon $finishedAt): ?Carbon` | |
| `describe(): string` | e.g. `"every 30m, 12x"` |
| `static humanize(int $seconds): string` | 5400 → `"1h 30m"` |
| `Schedule::FOREVER` = 0, `Schedule::MIN_INTERVAL` = 1 | |

**The next run is measured from completion, not from the due time.** A job
taking longer than its interval falls behind rather than queueing a backlog
it can never clear.

### 15.3 Scheduling: JobService

Inject `Botex\Service\JobService`. Shortcuts:

| Method | Notes |
| --- | --- |
| `now(string $ext, string $job, array $data = []): Job` | |
| `in(string $ext, string $job, int $seconds, array $data = []): Job` | |
| `at(string $ext, string $job, \DateTimeInterface $when, array $data = []): Job` | |
| `every(string $ext, string $job, int $seconds, array $data = [], ?string $key = null, int $maxRuns = Schedule::FOREVER): Job` | |

Full control via `Botex\Bot\Job\JobRequest` (immutable, same shape as
`Run`):

| Method | Notes |
| --- | --- |
| `static to(string $ext, string $job, Schedule $schedule, array $data = []): self` | names validated `[A-Za-z0-9._-]+` |
| `with(array $data)` / `set(string $key, mixed $value)` | |
| `on(Schedule $schedule)` | |
| `keyed(string $key)` | dedupe key; throws on a blank key |
| `attempts(int $max)` | throws below 1 |
| `isCore()`, `handlerKey()` | |
| `JobRequest::CORE` | `'core'` |

| `JobService` method | Notes |
| --- | --- |
| `schedule(JobRequest $request): Job` | **throws `Botex\Bot\Job\UnknownJob`** if no handler answers to that name |
| `ensure(JobRequest $request): Job` | schedules only if that key is absent; leaves existing timing alone |
| `find(int $id): ?Job` / `findByKey(string $key): ?Job` | |
| `recent(int $limit = 50)` | |
| `runNow(int $id): bool` | brings the next run forward |
| `pause(int $id): bool` / `resume(int $id): bool` / `cancel(int $id): bool` | |
| `retryAt(Job $job): ?Carbon` | next retry, or null to give up |
| `stats(): array` | counts for a panel |
| `registered(): array` | the allowlist |
| `maxAttempts(): int` | |

### 15.4 Keys: the deduplication rule

**MUST key any recurring job.** Without a key, every call adds a row, so a
per-chat watcher scheduled twice sends every message twice, and a nightly
digest armed at boot stacks up one copy per restart.

```php
// one row per chat, re-armed rather than duplicated
$this->jobs->schedule(
    JobRequest::to('Shop', ExpireOrders::name(), Schedule::everyHours(1))
        ->keyed('Shop:expire')
);

// per-user, so include the id in the key
$this->jobs->schedule(
    JobRequest::to('Shop', Remind::name(), Schedule::daily(), $data)
        ->keyed('Shop:remind:' . $update->fromId())
);
```

`schedule()` on an existing key **updates** the row (re-arming its timing);
`ensure()` leaves an existing row completely alone. Use `ensure()` for jobs
you arm at boot, `schedule()` when the user just asked for it.

Keys are globally unique (`jobs.key` is a unique index), so **prefix with
your slug**.

Cancelling a keyed job:

```php
$job = $this->jobs->findByKey($key);
$stopped = $job !== null && $this->jobs->cancel((int) $job->id);
```

### 15.5 JobContext

| Member | Notes |
| --- | --- |
| `id`, `extension`, `job`, `data` | readonly |
| `run` | 1-based run counter |
| `attempt` | 1-based attempt within this run |
| `get`, `has`, `int`, `string`, `bool` | dot-notation accessors |
| `isFirstRun(): bool` | |
| `isRetry(): bool` | an earlier attempt at this run failed |
| `heartbeat(int $seconds = 60): bool` | push the lease out |
| `handlerKey(): string` | |

**Call `heartbeat()` periodically from anything slow.** Work that outlasts
the lease looks abandoned and gets reclaimed by another worker. It returns
`false` when this worker has already lost the job, so a long handler can
notice and stop early:

```php
foreach ($this->orders->stale() as $order) {
    if (!$context->heartbeat(120)) {
        return;   // lease lost; another worker owns this now
    }

    $this->orders->expire((int) $order->id);
}
```

### 15.6 Failure, retries and statuses

`Botex\Bot\Job\JobStatus`: `PENDING`, `RUNNING`, `DONE`, `FAILED`, `PAUSED`,
with `isActive()`, `isTerminal()`, `isResumable()`, `label()`.

Backoff after a failed attempt is `[30, 120, 600, 1800]` seconds, then the
job is marked `FAILED` once `max_attempts` (config `jobs.max_attempts`,
default 3) is used up. Per-job override: `JobRequest::attempts(5)`.

**A missing handler pauses the job, it does not fail it.** If your
extension is disabled or the handler renamed, the worker sets `PAUSED` and
logs it — so re-enabling the extension and calling `resume()` picks the work
back up instead of having lost it.

**Handlers should be idempotent.** A lease can lapse mid-run (a killed
worker, a handler slower than the lease) and the job then gets reclaimed, so
`handle()` may run more than once for the same logical work. Guard side
effects with a conditional UPDATE (section 6.4) or a wallet idempotency key
(section 16.3).

### 15.7 Running the worker

```
php bin/console jobs:work                 # long-running loop
php bin/console jobs:work --once          # one poll, then exit (cron-friendly)
php bin/console jobs:work --seconds=300   # run for 5 minutes, then exit
php bin/console jobs:worker               # who holds the lease
php bin/console jobs:worker --release     # force-release a dead lease
```

**Only one worker runs at a time**, enforced by an expiring lease row
(`job_worker`) rather than a pid file — so it self-heals after a `kill -9`
instead of needing a stale file cleaned up. A second `jobs:work` exits
immediately.

Under a supervisor, `--seconds=N` plus automatic restart is the robust
shape: bounded process lifetime, no lease to babysit.

| Config key | Env | Default | Meaning |
| --- | --- | --- | --- |
| `jobs.sleep` | `JOB_SLEEP` | 5 | seconds between polls when nothing is due |
| `jobs.lease` | `JOB_LEASE` | 300 | how long a claim stays owned |
| `jobs.max_attempts` | `JOB_MAX_ATTEMPTS` | 3 | attempts before `FAILED` |
| `jobs.keep_finished` | `JOB_KEEP_FINISHED` | 604800 | how long finished rows are kept |

`jobs.lease` **MUST comfortably exceed your slowest job**, or a still-running
job looks abandoned and gets picked up again. Alternatively call
`heartbeat()`.

Other job commands: `jobs:list`, `jobs:registered`, `jobs:schedule`,
`jobs:run <id>`, `jobs:pause <id>`, `jobs:resume <id>`, `jobs:cancel <id>`.

### 15.8 Sending a message from a job

There is no `Update`, so there is nothing to derive a chat from. `Bot` is
registered in `bootstrap/app.php` precisely so the worker has it:

```php
public function handle(JobContext $context): void
{
    $chatId = $context->int('chat');

    if ($chatId === 0) {
        return;   // bad data; retrying cannot fix it
    }

    $this->bot->sendMessage('Your order expired.')
        ->to($chatId)
        ->parseMode('HTML')
        ->execute();
}
```

Store `chat` (and any user id) in the job data when you schedule it.

## 16. Wallet

Inject `Botex\Service\WalletService`. It is the **only** place a balance
changes — never write `wallets` or `wallet_transactions` yourself, and never
compute a new balance in PHP.

### 16.1 The two rules that catch everyone

**1. Money is always an integer in minor units.** There are no floats
anywhere in the wallet. At `wallet.scale = 2`, `150000` means `1500.00`. At
the default scale `0`, `150000` means `150000`. Format for display only:

```php
$this->wallet->balanceMoney($userId)->format();   // "150000 IRT"
```

**2. Every method takes the *internal* user id, not the telegram id.**
Passing a telegram id will either throw `WalletOwnerNotFound` or, worse,
silently address a different user's wallet. Convert first:

```php
// UserService takes the telegram id and returns the internal row
$user = $this->users->findOrCreateUser((int) $update->fromId());

$this->wallet->debit((int) $user->id, 5000, 'Order #12');
```

`WalletOwnerNotFound` on a plausible-looking id is nearly always this
mistake.

### 16.2 Full method reference

| Method | Returns | Notes |
| --- | --- | --- |
| `atomic(callable $work)` | mixed | runs `$work` in a transaction; returns its value |
| `currency()` | `string` | from `wallet.currency`, default `IRT` |
| `scale()` | `int` | from `wallet.scale`, default `0` |
| `money(int $minor)` | `Money` | wraps a raw integer in the configured currency/scale |
| `wallet(int $userId)` | `Wallet` | created on first access; verifies the user exists |
| `balance(int $userId)` | `int` | minor units |
| `balanceMoney(int $userId)` | `Money` | the same value, formattable |
| `canAfford(int $userId, int $amount)` | `bool` | advisory only — see below |
| `credit(...)` | `WalletTransaction` | adds funds |
| `debit(...)` | `WalletTransaction` | removes funds, never below zero |
| `refund(...)` | `WalletTransaction` | writes a reversing entry |
| `history(int $userId, int $limit = 20, int $offset = 0)` | `array` | newest first |
| `historyCount(int $userId)` | `int` | for paging |
| `findByReference(Reference $reference)` | `array` | every entry you linked to one of your entities |
| `transaction(int $id)` | `WalletTransaction` | throws `TransactionNotFound` |
| `stats()` | `array{wallets:int,total:int,formatted:string}` | panel totals |
| `refundableAmount(int $transactionId)` | `int` | `0` if not a refundable debit |

`credit()` and `debit()` share one signature:

```php
credit(
    int $userId,
    int $amount,              // positive, minor units
    string $reason,           // short audit label, must not be blank
    ?Reference $reference = null,
    ?string $idempotencyKey = null,
    array $meta = []
): WalletTransaction
```

```php
refund(
    int $transactionId,
    ?int $amount = null,      // null = whatever is still unrefunded
    string $reason = 'Refund',
    ?string $idempotencyKey = null,
    array $meta = []
): WalletTransaction
```

**`canAfford()` is not a guard.** Between your check and your debit another
request can spend the balance. It is fine for deciding what to show in a
menu; it is never the thing that protects the balance. Always let `debit()`
be the check and catch `InsufficientFunds`:

```php
try {
    $this->wallet->debit((int) $user->id, $price, 'Order');
} catch (InsufficientFunds $e) {
    // the balance was never allowed to go negative
}
```

The guard lives in the SQL `WHERE` (section 6.4), so concurrent debits
cannot both pass it.

### 16.3 Idempotency keys

Pass an `idempotencyKey` whenever the same logical operation could be
attempted twice — a retried job, a double-tapped button, a payment webhook
delivered more than once. The key is unique across the ledger; a repeat
returns **the original entry** instead of moving the balance again.

```php
$this->wallet->credit(
    userId: (int) $user->id,
    amount: 50_000,
    reason: 'Top-up',
    reference: Reference::to('gateway_payment', $paymentId),
    idempotencyKey: "topup:{$paymentId}"      // derived from the payment, not random
);
```

The key must be **derived from the operation**, not generated per attempt —
`uniqid()` makes every retry a new charge, which is the bug the key exists to
prevent. Good keys: `"order:{$orderId}:charge"`, `"topup:{$paymentId}"`,
`"job:{$jobId}:payout"`.

Reusing a key with a *different* type or amount throws
`IdempotencyConflict` rather than quietly returning the earlier entry, since
that combination is always a caller bug. Concurrent requests racing on the
same key are handled internally: the loser unwinds its balance move and
returns the winner's entry.

This is what makes a job handler safe to run twice (section 15.6).

### 16.4 References — linking to your own entities

`Reference` is a free-form `type`/`id` pair so the wallet never needs to know
your extension exists. Both parts are non-empty, max 191 characters.

```php
use Botex\Wallet\Reference;

$reference = Reference::to('shop_order', $orderId);   // "shop_order#41"

$this->wallet->debit((int) $user->id, $price, 'Order', $reference);

foreach ($this->wallet->findByReference($reference) as $entry) {
    // every charge and refund against that order
}
```

Namespace the `type` with your slug (`shop_order`, not `order`) so two
extensions cannot collide on the same reference type.

### 16.5 Refunds

**A refund never modifies the original entry.** It writes a new `REFUND`
entry pointing back at the debit, so the ledger stays append-only and
auditable.

```php
$this->wallet->refund($transactionId);              // everything still unrefunded
$this->wallet->refund($transactionId, 2000);        // partial
```

Partial refunds accumulate and are capped at what the debit actually charged.
`refundableAmount($id)` tells you what is left. Only debits are refundable —
refunding a credit or a refund throws `NotRefundable`.

### 16.6 Composing with your own writes

Wallet operations nest with savepoints, so calling them inside your own
transaction joins it. That is how you charge and create an order atomically:
if the order insert fails, the debit rolls back with it.

```php
$order = $this->wallet->atomic(function () use ($user, $price) {
    $charge = $this->wallet->debit((int) $user->id, $price, 'Order');

    return Order::create([
        'user_id' => (int) $user->id,
        'transaction_id' => (int) $charge->id,
    ]);
});
```

Keep the callback short and free of network calls. A Telegram request inside
a transaction holds row locks for the duration of an HTTP round-trip — send
the confirmation message *after* `atomic()` returns.

### 16.7 Exceptions

All extend `Botex\Wallet\Exception\WalletException`, so `catch (WalletException)`
is a valid backstop.

| Exception | Thrown when |
| --- | --- |
| `InsufficientFunds` | a debit would go below zero |
| `InvalidAmount` | amount is not positive, reason is blank, or a `Reference` part is empty/too long |
| `WalletOwnerNotFound` | no user with that internal id (often a telegram id passed by mistake) |
| `TransactionNotFound` | unknown transaction id |
| `NotRefundable` | target is not a debit, is exhausted, or the refund exceeds the charge |
| `IdempotencyConflict` | a key was reused with a different type or amount |
| `DuplicateKeyRace` | internal; resolved to the winner's entry, you should not see it |

`InsufficientFunds` and `NotRefundable` are normal outcomes — catch and
explain them to the user. The rest indicate a bug.

## 17. Users

Two ids exist and they are not interchangeable:

| Id | Where it comes from | Used by |
| --- | --- | --- |
| **telegram id** | `$update->fromId()` | `UserService`, admin lookups, `sendMessage()->to()` |
| **internal id** | `$user->id` | `WalletService`, your own foreign keys |

Store the **internal id** in your tables. A telegram id is an external
identifier; keying your rows on it couples your data to Telegram and breaks
the moment you need a user who has no Telegram account.

### 17.1 UserService

Inject `Botex\Service\UserService`.

| Method | Returns | Notes |
| --- | --- | --- |
| `findOrCreateUser(int $telegramId)` | `User` | creates on first contact, status `active` |
| `find(int $telegramId)` | `?User` | null when unknown |
| `isBlocked(int $telegramId)` | `bool` | false for unknown users |
| `block(int $telegramId)` | `bool` | false when there is no such user |
| `unblock(int $telegramId)` | `bool` | false when there is no such user |
| `stats()` | `array{total:int,active:int,blocked:int,today:int,week:int}` | panel figures |
| `latest(int $limit = 10)` | `array<User>` | newest first |

`$update->fromId()` returns `?string`, so cast it:

```php
$telegramId = (int) $update->fromId();

if ($telegramId === 0) {
    return;    // no sender — channel post or similar
}

$user = $this->users->findOrCreateUser($telegramId);
```

### 17.2 The User model

`Botex\Model\User` — fillable `telegram_id`, `phone_number`, `status`;
constants `User::ACTIVE` and `User::BLOCKED`; helper `isBlocked()`.

### 17.3 Blocking is enforced for you

`BlockedUserMiddleware` runs before your handler (section 10), so **a blocked
user never reaches your code** — no handler, no callback, no flow step. You do
not need to re-check `isBlocked()` at the top of a handler.

Two things it does *not* cover, because there is no update to gate:

- **Jobs.** A job scheduled earlier still runs after its user is blocked.
  Check `isBlocked()` in `handle()` if that matters.
- **Anything you trigger yourself** from another job or a console command.

Blocking is a bot-level gate, not a wallet-level one: a blocked user's balance
is untouched and still refundable by an admin.

## 18. Config reference

Inject `Botex\Support\Config` and read with dot notation and a default:

```php
$ttl = (int) $this->config->get('actions.ttl', 604800);
```

Every value comes from `.env`. **Do not add your own keys here** — extension
configuration belongs in `settings.json` (section 5), which survives updates
and is editable from the panel.

| Key | Env | Default | Meaning |
| --- | --- | --- | --- |
| `bot_token` | `BOT_TOKEN` | — | required; the Telegram API token |
| `database.host` | `DB_HOST` | `127.0.0.1` | MySQL host |
| `database.name` | `DB_NAME` | — | database name |
| `database.user` | `DB_USER` | — | database user |
| `database.password` | `DB_PASSWORD` | — | database password |
| `admins` | `ADMIN_IDS` | `[]` | comma-separated **telegram** ids, e.g. `12345,67890` |
| `panel_token` | `PANEL_TOKEN` | `''` | web panel token; **empty disables the panel** |
| `wallet.currency` | `WALLET_CURRENCY` | `IRT` | display label only |
| `wallet.scale` | `WALLET_SCALE` | `0` | minor units per whole unit as a power of ten; `2` for cents |
| `actions.ttl` | `ACTION_TTL` | `604800` | seconds a Run button stays pressable |
| `actions.prune_chance` | `ACTION_PRUNE_CHANCE` | `200` | prune expired actions roughly every N updates |
| `jobs.sleep` | `JOB_SLEEP` | `5` | worker poll interval when idle |
| `jobs.lease` | `JOB_LEASE` | `300` | how long a claimed job stays owned |
| `jobs.max_attempts` | `JOB_MAX_ATTEMPTS` | `3` | attempts before `FAILED` |
| `jobs.keep_finished` | `JOB_KEEP_FINISHED` | `604800` | retention for finished rows; `0` disables the prune job |
| `logging.level` | `LOG_LEVEL` | `info` | quietest level written to the file; `debug`–`critical` or `0`–`5` |
| `logging.keep_days` | `LOG_KEEP_DAYS` | `14` | log files older than this are deleted; `0` keeps everything |
| `logging.notify_chat` | `LOG_CHAT` | `''` | chat id (a group is usual) admins are told in; **empty disables notifications** |
| `logging.notify_level` | `LOG_NOTIFY_LEVEL` | `error` | quietest level that sends a telegram notification (section 26.4) |
| `paths.extensions` | — | `<root>/extensions` | extension folders |
| `paths.storage` | — | `<root>/storage` | writable storage |
| `paths.settings` | — | `<root>/storage/extension-settings` | setting overrides, outside `extensions/` so updates cannot wipe them |

`wallet.scale` **cannot be changed after money exists.** Stored balances are
raw minor units; changing the scale silently reinterprets every historical
balance by a factor of ten.

`admins` holds telegram ids. That is the one place the telegram id is the
right identifier, because it is what an incoming update carries.

## 19. Web panel

`public/panel.php?token=<PANEL_TOKEN>` — a **read-only** overview: wallet
totals, action counts, worker state, recent jobs, installed extensions and
registered action names.

It is deliberately limited, and the limits are the design:

- **Read-only.** Enable, disable and remove stay in the CLI, so a leaked token
  cannot delete an extension's files or data.
- **Fails closed.** An empty `PANEL_TOKEN` returns 404. Comparison is
  `hash_equals()`, and a wrong token is also a 404 rather than a 403, so the
  page does not confirm it exists.
- **Aggregates only.** Wallet totals, never per-user balances. Action *counts*
  and names, never tokens or action data. Job names, timings and statuses,
  never job data — job data can hold anything a caller put there.

Your extension shows up automatically. Its jobs appear by name, its runnables
under its slug, and a broken extension appears as an error banner instead of
taking the page down.

**If your job data or action payload would be sensitive in an HTML page, that
is already handled — it is never rendered.** Keep it that way if you extend
the panel: pass counts and labels, not payloads.

The panel is a diagnostic surface, not an admin UI. User-facing management
belongs in an admin section (section 13), which is authenticated by telegram
id rather than a shared URL token.

## 20. Console reference

`php bin/console <command>`. With no arguments it runs `ext:list`.

### Schema

| Command | Effect |
| --- | --- |
| `migrate` | creates core tables; safe to re-run |

### Extensions

| Command | Effect |
| --- | --- |
| `ext:list` | every extension, version, enabled state, load errors |
| `ext:install <slug>` | registers it and runs `install()` |
| `ext:enable <slug>` | starts routing to its contributions |
| `ext:disable <slug>` | stops routing; keeps files and data |
| `ext:remove <slug> [--keep-files]` | runs `uninstall()`, forgets state/settings/actions/jobs, deletes the folder unless `--keep-files` |
| `ext:settings <slug>` | declared keys with current values |
| `ext:set <slug> <key> <value>` | writes an override; rejects undeclared keys |
| `ext:reset <slug> <key>` | drops the override, back to the declared default |

`ext:install` installs from `extensions/<slug>/` when that folder exists,
and from the archive when it does not. So it means the same thing whether
you are installing something you just wrote or something published.

### Archive

Needs `archive.url` set in `config/config.php`. Full detail in
[ARCHIVE.md](ARCHIVE.md) and [UPDATING.md](UPDATING.md).

| Command | Effect |
| --- | --- |
| `archive:ping` | reachability, channels, package count, whether it signs |
| `ext:remote [--fresh]` | what the archive offers, against what you have |
| `ext:search <query>` | search it |
| `ext:show <slug>` | description, versions, changelog, install commands |
| `ext:outdated [--fresh]` | installed extensions with a newer release |
| `ext:update <slug>\|--all [--dry-run] [--force]` | updates from the archive |

### Core

| Command | Effect |
| --- | --- |
| `core:check [--fresh]` | installed vs archive, and whether anything is edited |
| `core:update [--version=] [--dry-run] [--force]` | applies a release, or refuses and says why |
| `core:diff [--verbose]` | core files you have changed |
| `core:adopt` | records the current tree as the baseline |
| `core:backups` | what can be rolled back to |
| `core:rollback [<backup>]` | restores one; the newest by default |

A core update never writes `config/`, `.env`, `storage/`, `extensions/` or
`vendor/`, and refuses rather than overwriting a core file you edited. Which
is the practical argument for this whole document: behaviour that lives in an
extension is behaviour no update can conflict with.

### Diagnostics

| Command | Effect |
| --- | --- |
| `version` | version, edited core files, extension counts, archive |
| `doctor` | environment checks, including zlib and legacy `App\` aliases |

### Wallet

| Command | Effect |
| --- | --- |
| `wallet:show <telegram-id>` | balance and recent entries |
| `wallet:credit <telegram-id> <amount> <reason>` | adds funds |
| `wallet:debit <telegram-id> <amount> <reason>` | removes funds |
| `wallet:refund <transaction-id> [amount]` | reverses a debit; omit the amount for whatever remains |
| `wallet:stats` | wallets opened and total held |

Wallet commands take a **telegram id** and resolve it internally. They
deliberately **do not create the user**: crediting an id that never started
the bot is almost always a typo, and the money would sit where nobody can
spend it. `amount` is in minor units.

### Run actions

| Command | Effect |
| --- | --- |
| `action:list` | registered runnables per extension |
| `action:stats` | stored vs still-pressable counts |
| `action:prune` | deletes expired and long-spent rows |

### Jobs

| Command | Effect |
| --- | --- |
| `jobs:work [--once] [--seconds=N]` | the worker; `--once` polls once and exits |
| `jobs:list` | scheduled jobs with status, schedule, next run |
| `jobs:registered` | every job name the allowlist accepts |
| `jobs:schedule <extension> <job> [+seconds\|every:seconds] [key]` | schedules by name |
| `jobs:run <id>` | runs one now, ignoring its schedule |
| `jobs:pause <id>` / `jobs:resume <id>` | stop and restart without losing it |
| `jobs:cancel <id>` | removes it permanently |
| `jobs:worker [--release]` | who holds the lease; `--release` frees a dead one |

`jobs:schedule` goes through the same allowlist as your code, so an unknown
name is rejected rather than instantiated. Use it to test a job without
waiting for its trigger.

## 21. Security rules

These are the invariants the core enforces. Breaking one usually still
*works* in testing, which is why they are collected here.

### 21.1 Never put a class name in callback data

Callback data is **user-supplied**. Anyone can send arbitrary bytes in a
callback query, and buttons live in old messages forever.

```php
// WRONG — turns callback data into "instantiate this class"
Keyboard::inline()->row(InlineButton::callback('Go', 'run:' . MyRunnable::class));

// RIGHT — a registered name, resolved through the allowlist
Keyboard::inline()->row(
    InlineButton::make('Go')->action(Run::extension(Deploy::name(), ['id' => $id]))
);
```

Same rule for job names, flow names and section keys: the database stores a
**name**, and the name is resolved against a registry the code declares. A
tampered row, an expired token or a disabled extension resolves to *nothing*
rather than to a class of the attacker's choosing.

### 21.2 Never put a serialized payload in callback data

Telegram allows 64 bytes, and anything you put there round-trips through the
user. Store the payload server-side with `Run::with()` and send only the
opaque token. Never trust a price, a user id or a quantity that came back from
a button — look it up from the stored action.

### 21.3 Escape everything you interpolate into HTML

With `parseMode('HTML')`, an unescaped name containing `<` breaks the message,
and a crafted one injects markup:

```php
$this->bot->sendMessage('Hello ' . htmlspecialchars($name, ENT_QUOTES, 'UTF-8'))
    ->parseMode('HTML')
    ->execute();
```

Escape **all** user-controlled values: names, notes, feedback text, settings
someone else can edit.

### 21.4 Gate at the entrance, not per-handler

Authorization belongs in middleware (section 10), which runs before your
handler and before an action or a flow step. A check at the top of `handle()`
is one you can forget in the next handler — and the callback path, the flow
path and the action path each need it.

Admin-only work goes in an admin section or behind `AdminMiddleware`. Never
re-derive "is this an admin" from callback data.

### 21.5 Prefix your tables

Every table your extension creates starts with your slug lowercased
(`clock_reminders`). Two extensions cannot collide, and `uninstall()` knows
exactly what to drop.

### 21.6 Use `once()` for anything with a side effect

A user can tap a button twice, and Telegram can redeliver. `Run::once()`
makes the token single-use, enforced by an atomic claim rather than a read
followed by a write. Combine it with a wallet idempotency key (section 16.3)
for money.

### 21.7 Validate in the step, not after the flow

`TextStep` and `ChoiceStep` validate before storing (section 12). A choice is
checked against the declared keys, so a hand-crafted callback cannot inject an
option you never offered. Put your own constraints in the step's validation
too, not in `complete()`.

### 21.8 Money rules

Integer minor units only, `WalletService` only, `debit()` is the guard rather
than `canAfford()`, refunds are new entries, and an idempotency key is derived
from the operation. See section 16.

### 21.9 Secrets

Read credentials from `.env` through `Config`. Never commit a token, never
hard-code one in `settings.json` (it is world-readable to anyone with panel
access and is dumped by `ext:settings`), and never echo one into a Telegram
message or a log line.

## 22. Production checklist

**Manifest and layout**

- [ ] `extension.json` has `name`, `version`, `entry`; folder name is the slug
- [ ] namespace matches the folder exactly (`Extensions\<Slug>\...`)
- [ ] `settings.json` declares every key, flat, with sane defaults

**Lifecycle**

- [ ] `install()` is idempotent — `createIfMissing`, `addMissing`, re-runnable
- [ ] `uninstall()` drops every table you created and nothing else
- [ ] a new column ships via `addMissing()` in `install()`, not a hand-edited table
- [ ] the extension does something sensible while disabled (nothing at all)

**Handlers**

- [ ] every contributed class is listed in the matching hook, or it never routes
- [ ] constructor params are all non-builtin class types (section 4.1)
- [ ] commands read their own arguments; `/cmd` and `/cmd arg` both resolve
- [ ] button labels are unique enough not to collide with another extension's
- [ ] all user text escaped before `parseMode('HTML')`
- [ ] callback data holds names and tokens, never class names or payloads

**State**

- [ ] tables prefixed with the slug
- [ ] your own rows key on the **internal** user id
- [ ] side effects guarded by a conditional UPDATE, `once()`, or an idempotency key
- [ ] flows tolerate a step being removed mid-conversation (the runner self-heals)

**Money**

- [ ] integer minor units end to end, no floats
- [ ] `WalletService` for every balance change
- [ ] `InsufficientFunds` caught and explained, not left to bubble up
- [ ] idempotency keys derived from the operation
- [ ] `References` namespaced with the slug

**Jobs**

- [ ] `handle()` is idempotent — it can run twice
- [ ] `keyed()` used where duplicates must not accumulate
- [ ] `jobs.lease` exceeds the slowest job, or `heartbeat()` is called
- [ ] chat and user ids live in the job data, since there is no `Update`
- [ ] a worker is actually running in production (`jobs:worker`)

**Logging**

- [ ] the `Logger` is injected, never `new`'d and never `error_log()`
- [ ] every swallowed catch writes at least a `warning` with `extension` set
- [ ] failures that throw on use `exception()`, so the boundary sees them once
- [ ] no secrets or raw payloads in log calls (they reach a chat above the notify level)

**Verify before shipping**

- [ ] `php -l` clean on every file
- [ ] `ext:install` then `ext:remove` on a scratch database leaves no tables
- [ ] `ext:disable` then `ext:enable` round-trips without errors
- [ ] tested with a second, non-admin account
- [ ] tested tapping the same button twice, and an old button after a restart

### 22.1 Publishing it

Anything under `extensions/` is already installable by copying the folder.
Publishing to an [archive](ARCHIVE.md) makes it installable by name, and
updatable.

```bash
php hub/bin/hub publish extensions/Shop --changelog="First release."
```

Which gives the package page a set of commands anyone can copy:

```
php bin/console ext:install Shop
php bin/console ext:update Shop
php bin/console ext:remove Shop
```

Four things to get right before you publish:

**The folder name is the slug, and it must match `entry`.** `extensions/Shop`
with `Extensions\Shop\Extension` is right. Publishing from a folder called
`shop-1.2.0` is refused, because the slug becomes the install directory and
the entry class would never load from it.

**Use a real `major.minor.patch` version, and bump it.** Publishing the same
version twice is refused. An installed bot compares versions to decide
whether an update exists, so `1.0` or `v1.0.0` are refused rather than
guessed at.

**Write a `README.md`.** It becomes the package page. It is escaped and only
a small Markdown subset is re-tagged, so it cannot inject HTML into the
archive — but it is the only thing a stranger reads before installing your
code.

**Declare `requires` if you depend on something.** An unmet requirement
refuses the install with a message, which is better than a fatal error on
the first message the bot receives.

Not packaged, whatever is in your folder: `.git/`, `vendor/`,
`node_modules/`, `.env`, `storage/`, `*.log`, and editor droppings. A
published extension must not carry your configuration or someone's local
state.

Settings are a special case worth knowing. `settings.json` **is** packaged —
it declares the keys and their defaults, which the extension needs. The
*overrides* an operator has set are not, because they live in `storage/`.
An update replaces the declarations and keeps their choices.

## 23. Worked example

A complete extension that uses every hook: a paid subscription with a
wallet charge, a Run action, an expiry job and an admin section.

```
extensions/Subs/
├── extension.json
├── settings.json
├── Extension.php
├── Command/Subscribe.php
├── Action/Buy.php
├── Job/Expire.php
├── Admin/SubsSection.php
├── Model/Subscription.php
└── Service/SubsService.php
```

### 23.1 Manifest and settings

`extension.json`:

```json
{
    "name": "Subscriptions",
    "version": "1.0.0",
    "entry": "Extension.php",
    "description": "Paid subscriptions charged to the wallet."
}
```

`settings.json` — flat keys only, these are the defaults:

```json
{
    "price": 50000,
    "days": 30,
    "enabled": true
}
```

### 23.2 The entry class

```php
<?php

namespace Extensions\Subs;

use Botex\Extension\AbstractExtension;
use Botex\Support\Schema;
use Extensions\Subs\Action\Buy;
use Extensions\Subs\Admin\SubsSection;
use Extensions\Subs\Command\Subscribe;
use Extensions\Subs\Job\Expire;

class Extension extends AbstractExtension
{
    public static function commands(): array
    {
        return [Subscribe::class];
    }

    public static function runnables(): array
    {
        return [Buy::class];
    }

    public static function jobs(): array
    {
        return [Expire::class];
    }

    public static function adminSections(): array
    {
        return [SubsSection::class];
    }

    /** Idempotent: createIfMissing and addMissing both re-run safely. */
    public static function install(): void
    {
        Schema::createIfMissing('subs_subscriptions', function ($table) {
            $table->id();
            // the internal user id, not the telegram id
            $table->unsignedBigInteger('user_id')->index();
            $table->unsignedBigInteger('transaction_id')->nullable();
            $table->timestamp('expires_at')->nullable();
            // The expiry job's idempotency guard reads this column.
            $table->timestamp('notified_at')->nullable();
            $table->timestamps();
        });
    }

    public static function uninstall(): void
    {
        Schema::dropIfExists('subs_subscriptions');
    }
}
```

### 23.3 The command — renders a token, not a price

```php
<?php

namespace Extensions\Subs\Command;

use Botex\Bot\Action\Run;
use Botex\Bot\Command\CommandInterface;
use Botex\Extension\Settings;
use Botex\Telegram\Bot;
use Botex\Telegram\Builders\Keyboard\InlineButton;
use Botex\Telegram\Builders\Keyboard\Keyboard;
use Botex\Telegram\Update;
use Extensions\Subs\Action\Buy;

class Subscribe implements CommandInterface
{
    public function __construct(
        private Bot $bot,
        private Settings $settings
    ) {
    }

    public static function command(): string
    {
        return '/subscribe';
    }

    public static function button(): string
    {
        return 'Subscribe';
    }

    public static function middleware(): array
    {
        return [];
    }

    public function handle(Update $update): void
    {
        $price = (int) $this->settings->get('price', 50000);
        $days = (int) $this->settings->get('days', 30);

        // The price is stored server-side with the action. It is not in the
        // button, so it cannot be edited on the way back. The slug is
        // inferred from this file's namespace, so Run::extension() needs
        // no third argument.
        $keyboard = Keyboard::inline()->row(
            InlineButton::make("Pay {$price}")->action(
                Run::extension(Buy::name(), ['price' => $price, 'days' => $days])
                    ->once()          // one charge per button, atomically
                    ->expiresIn(3600) // the price is only good for an hour
            )
        );

        $this->bot->sendMessage("{$days} days for {$price}.")
            ->to($update->chatId())
            ->replyMarkup($keyboard->build());
    }
}
```

Two things to note. `once()` means a double tap charges once, enforced by an
atomic claim rather than a check. And the price travels in the `Run`'s data,
so the handler reads it from the stored action instead of trusting callback
data (section 21.2).

### 23.4 The action — charge, record and schedule atomically

```php
<?php

namespace Extensions\Subs\Action;

use Botex\Bot\Action\RunContext;
use Botex\Bot\Action\RunnableInterface;
use Botex\Bot\Job\JobRequest;
use Botex\Bot\Job\Schedule;
use Botex\Service\JobService;
use Botex\Service\UserService;
use Botex\Service\WalletService;
use Botex\Telegram\Bot;
use Botex\Telegram\Update;
use Botex\Wallet\Exception\InsufficientFunds;
use Botex\Wallet\Reference;
use Extensions\Subs\Job\Expire;
use Extensions\Subs\Model\Subscription;

class Buy implements RunnableInterface
{
    public function __construct(
        private Bot $bot,
        private WalletService $wallet,
        private UserService $users,
        private JobService $jobs
    ) {
    }

    public static function name(): string
    {
        return 'buy';
    }

    public static function middleware(): array
    {
        return [];
    }

    public function handle(Update $update, RunContext $context): void
    {
        $telegramId = (int) $update->fromId();

        if ($telegramId === 0) {
            return;
        }

        // Read from the stored action, never from the button.
        $price = $context->int('price');
        $days = $context->int('days', 30);

        $user = $this->users->findOrCreateUser($telegramId);
        $userId = (int) $user->id;

        try {
            // Charge and record together: if the insert fails the debit
            // rolls back with it, so no one pays for nothing.
            $subscription = $this->wallet->atomic(
                function () use ($userId, $price, $days) {
                    $charge = $this->wallet->debit(
                        userId: $userId,
                        amount: $price,
                        reason: 'Subscription',
                        reference: Reference::to('subs_subscription', $userId),
                        idempotencyKey: "subs:{$userId}:" . date('Y-m-d')
                    );

                    return Subscription::create([
                        'user_id' => $userId,
                        'transaction_id' => (int) $charge->id,
                        'expires_at' => (new \DateTimeImmutable())
                            ->modify("+{$days} days"),
                    ]);
                }
            );
        } catch (InsufficientFunds $e) {
            $this->bot->sendMessage('Not enough balance. Top up and try again.')
                ->to($update->chatId());

            return;
        }

        // Scheduled after the transaction commits, so the worker cannot
        // pick up a job for a row that has not been written yet.
        $this->jobs->schedule(
            JobRequest::to('Subs', Expire::name(), Schedule::in($days * 86400), [
                'chat' => $update->chatId(),
                'subscription' => (int) $subscription->id,
            ])->keyed('subs:expire:' . (int) $subscription->id)
        );

        $this->bot->sendMessage("Active for {$days} days.")
            ->to($update->chatId());
    }
}
```

The ordering matters twice. The Telegram send is **outside** `atomic()`, so a
network round-trip never holds a row lock (section 16.6). And the job is
scheduled **after** the commit — schedule it inside and a fast worker can
claim it before the subscription row is visible.

### 23.5 The job — no Update, idempotent

```php
<?php

namespace Extensions\Subs\Job;

use Botex\Bot\Job\JobContext;
use Botex\Bot\Job\JobInterface;
use Botex\Telegram\Bot;
use Extensions\Subs\Model\Subscription;

class Expire implements JobInterface
{
    public function __construct(
        private Bot $bot
    ) {
    }

    public static function name(): string
    {
        return 'expire';
    }

    public function handle(JobContext $context): void
    {
        $id = $context->int('subscription');
        $chatId = $context->int('chat');

        if ($id === 0) {
            // Bad data. Returning retires the job; throwing would retry it
            // three times and then fail, which fixes nothing.
            return;
        }

        // The guard is in the WHERE, so a second run affects zero rows and
        // sends nothing. This is what makes the handler safe to run twice.
        $expired = Subscription::where('id', $id)
            ->whereNull('notified_at')
            ->update(['notified_at' => new \DateTimeImmutable()]);

        if ($expired === 0 || $chatId === 0) {
            return;
        }

        $this->bot->sendMessage('Your subscription has expired.')
            ->to($chatId)
            ->execute();
    }
}
```

Note `execute()`. In a command the builder's destructor sends for you, but
being explicit in a job means a failure surfaces as a thrown exception the
worker can retry, rather than during destruction.

The chat id comes from the job data because **there is no `Update`** — by the
time this runs, the message that triggered it is long gone.

### 23.6 The admin section

```php
<?php

namespace Extensions\Subs\Admin;

use Botex\Bot\Admin\AdminSectionInterface;
use Botex\Bot\Admin\Panel;
use Botex\Telegram\Update;
use Extensions\Subs\Model\Subscription;

class SubsSection implements AdminSectionInterface
{
    public function __construct(
        private Panel $panel
    ) {
    }

    /** No colons: the key is part of the callback payload. */
    public static function key(): string
    {
        return 'subs';
    }

    public static function title(): string
    {
        return 'Subscriptions';
    }

    public function handle(Update $update): void
    {
        $active = Subscription::where('expires_at', '>', new \DateTimeImmutable())
            ->count();

        $this->panel->section(
            $update,
            '<b>Subscriptions</b>' . PHP_EOL . "Active: {$active}"
        );
    }
}
```

`Panel::section()` handles the back button and edits the panel message in
place, so the admin does not accumulate a message per tap.

### 23.7 The model

Every column written by `create()` or `update()` must be `$fillable`, or
Eloquent silently drops it — the row inserts with nulls and nothing warns you.

```php
<?php

namespace Extensions\Subs\Model;

use Illuminate\Database\Eloquent\Model;

class Subscription extends Model
{
    protected $table = 'subs_subscriptions';

    protected $fillable = [
        'user_id',
        'transaction_id',
        'expires_at',
        'notified_at',
    ];

    protected $casts = [
        'expires_at' => 'datetime',
        'notified_at' => 'datetime',
    ];
}
```

`$table` is explicit because Eloquent would otherwise infer `subscriptions`
and miss your prefix (section 21.5).

### 23.8 Installing it

```
php bin/console ext:install Subs
php bin/console ext:enable Subs
php bin/console ext:set Subs price 75000
php bin/console jobs:work
```

Then `/subscribe` in the bot. The expiry appears in `jobs:list` and on the
panel; the charge appears in `wallet:show <telegram-id>`.

## 24. Where to look in the source

The two shipped extensions are the reference implementations, and they are
kept working — if this document and the code disagree, the code is right.

| To see | Read |
| --- | --- |
| every hook used at once | `extensions/Feedback/Extension.php` |
| multi-step conversations | `extensions/Feedback/Flow/` |
| an admin section with its own data | `extensions/Feedback/Admin/FeedbackSection.php` |
| Run actions, inline and reply-keyboard | `extensions/Clock/Action/ShowTime.php` |
| one job class, both schedule shapes | `extensions/Clock/Job/SendTime.php`, `Command/Remind.php`, `Command/Watch.php` |
| commands with arguments | `extensions/Clock/Command/Remind.php` |
| settings read at runtime | `extensions/Clock/Service/ClockService.php` |

Core contracts worth reading before you extend them:
`src/Extension/AbstractExtension.php`, `src/Bot/Action/RunnableInterface.php`,
`src/Bot/Job/JobInterface.php`, `src/Bot/Admin/AdminSectionInterface.php`,
`src/Bot/Conversation/FlowRunner.php`, `src/Service/WalletService.php`,
`src/Support/Log/Logger.php` (section 26).

## 25. Quick reference

```php
// Telegram
$this->bot->sendMessage($text)->to($chatId)->parseMode('HTML')
    ->replyMarkup($keyboard)->execute();
$this->bot->editMessage($text, $messageId)->to($chatId);
$this->bot->answerCallback($update->callbackId(), 'Done');

// Keyboards
Keyboard::inline()->row(InlineButton::callback('Label', 'cb:data'))->build();
Keyboard::menu()->row(MenuButton::make('Label'))->resize()->build();

// Update
$update->text(); $update->chatId(); $update->fromId();      // ?string
$update->messageId(); $update->callbackId(); $update->callbackData();
$update->isMessage(); $update->isCallback();

// Settings (declared in settings.json, flat keys only)
$this->settings->get('key', $default);
$this->settings->set('key', $value);          // throws if undeclared

// Schema (in install(), must stay idempotent)
Schema::createIfMissing('slug_table', fn ($t) => ...);
Schema::addMissing('slug_table', fn ($t) => ...);
Schema::dropIfExists('slug_table');

// Users — telegram id in, internal id out
$user = $this->users->findOrCreateUser((int) $update->fromId());

// Wallet — internal user id, integer minor units
$this->wallet->debit($userId, $amount, $reason, $reference, $key);
$this->wallet->credit($userId, $amount, $reason, $reference, $key);
$this->wallet->refund($transactionId, $amount);
$this->wallet->balanceMoney($userId)->format();
$this->wallet->atomic(fn () => ...);

// Run actions — the payload on the server, a token (or label) on the button
Keyboard::inline()->row(
    InlineButton::make('Label')->action(
        Run::extension('name', ['k' => $v])->once()->expiresIn(3600)
    )
)->build();
MenuButton::make('Label')->action(Run::command('remind', [30]))
    ->audience($update);      // required outside a request

// Jobs — no Update; put the chat id in the data
$this->jobs->schedule(JobRequest::to('Slug', 'name', Schedule::in(60), $data)
    ->keyed('unique:key'));
Schedule::now(); Schedule::in($s); Schedule::at($when);
Schedule::every($s); Schedule::everyMinutes($n); Schedule::everyHours($n);
Schedule::daily();                       // every 24h from first run
Schedule::daily()->startingAt($when);    // pin the time of day
Schedule::every($s)->times($n);          // stop after n runs

// Escape everything user-supplied before parseMode('HTML')
htmlspecialchars($value, ENT_QUOTES, 'UTF-8');

// Logging — inject the Logger; user/chat arrive automatically
$this->log->info('Payment captured', ['extension' => 'Shop', 'order' => $id]);
$this->log->exception($e, 'Charge failed', Level::Error, ['extension' => 'Shop']);
```

**The five rules that cause the most bugs**

1. Constructor params must be non-builtin class types, or `Feeder` cannot
   build your class (section 4.1).
2. A contribution not listed in the matching hook never routes — no error,
   just silence (section 3).
3. Money is integer minor units and `WalletService` takes the **internal**
   user id (section 16.1).
4. Side effects need an atomic guard: a conditional UPDATE, `once()`, or an
   idempotency key (sections 6.4, 16.3).
5. Callback data carries names and tokens only — never class names, never
   payloads (section 21.1).

---

## 26. Logging

One entry point, `Botex\Support\Log\Logger`, writes every record to a daily
file under `storage/logs/` and forwards entries at or above the notify
threshold to a telegram chat for the admins. Every line carries the facts
you debug with — the level, an origin tag, the message, and a JSON context
block with the type of work, the extension, the command or action, and the
telegram user and chat.

Inject the `Logger` in a constructor and pick the level:

```php
use Botex\Support\Log\Logger;
use Botex\Support\Log\Level;

public function __construct(
    private ShopService $shop,
    private Logger $log,
) {
}

$this->log->info('Payment captured', ['extension' => 'Shop', 'order' => $id]);
$this->log->error('Charge failed', ['extension' => 'Shop', 'amount' => $amount]);
```

### 26.1 Levels

`Botex\Support\Log\Level` is an integer-backed enum, lowest to highest:

| Level | Value | Use it for |
| --- | --- | --- |
| `Debug` | 0 | per-update traces; off by default (`LOG_LEVEL=info`) |
| `Info` | 1 | normal facts worth keeping: purchases, installs, flows started |
| `Notice` | 2 | unusual but fine: a lease held past its expiry, a worker start |
| `Warning` | 3 | degraded: an extension failed to load, one admin notify failed |
| `Error` | 4 | a unit of work failed: a command, an action, a job |
| `Critical` | 5 | the process itself failed: an unhandled webhook exception |

Two independent thresholds read from `.env`: `LOG_LEVEL` gates the file,
`LOG_NOTIFY_LEVEL` gates the telegram chat (section 26.4), so `debug` can
fill the file without reaching anyone's phone.

### 26.2 Context: what arrives on its own

`Logger::log()` decorates every record before writing:

- `type` — `callback`, `message`, `webhook` or `console`, folded in from
  `Botex\Bot\CurrentUpdate` when one is loaded; jobs add `job` themselves
  (the worker does this before routing, extension jobs inherit it).
- `user`, `chat` — telegram ids from the same update, when present.
- `origin` — where the log call sits, resolved automatically as
  `slug:function` for extension code, `core:Class:function` for core, and
  a lowercase file name for file-scope code. You never pass this.

Anything you add wins over the ambient values, and empty strings and
`null`s are dropped. Recommended keys:

| Key | Meaning |
| --- | --- |
| `extension` | your slug — every line an extension writes should have it |
| `command` / `action` | which handler ran (`Remind`, `buy_vpn`) |
| `user` / `chat` | explicit when logging outside an update (jobs, console) |
| `handler` | the full class name, for core routing failures — stays in the file only |

No schema, no reserved words beyond these: keep values short and flat.

### 26.3 Exceptions and the seen-map

In a `catch` block use `exception()`, which records the message, class,
file:line, cause chain, and (at `Error` and above) the first 15 trace
frames:

```php
try {
    $this->shop->activate($userId);
} catch (\Throwable $e) {
    $this->log->exception($e, 'Activation failed', Level::Error, [
        'extension' => 'Shop',
        'user' => (int) $update->fromId(),
    ]);
    throw $e;
}
```

`exception()` marks the throwable in a static `WeakMap`; the boundary
catches in `webhook.php` and `bin/console` check `Log::seen($e)` and
log only what was **not** already recorded. That keeps one failure to one
file line and one admin notification even when it is logged deep down and
then rethrown. Do the same in any catch that rethrows after logging.

### 26.4 Notifying the admins

When `LOG_CHAT` is set, the bootstrap wires an `Botex\Support\Log\LogNotifier`
that posts entries at or above `LOG_NOTIFY_LEVEL` (default `error`) to that
chat as plain text. The chat message is deliberately a subset of the file
line:

- a context whitelist — `type`, `command`, `extension`, `action`, `user`,
  `chat`, `origin` — so callback payloads and raw updates never leave the
  server;
- namespaces stripped from messages (`Botex\Repository\JobRepository` →
  `JobRepository`), because a class name is a path into the codebase;
- a rate limit of 15 messages per minute, with one "limit reached" notice
  and then silence, so a crash loop cannot drown the group;
- plain text, no parse mode, so nothing in the message can break it.

A failed notification is written to the file as an `ERROR` record and never
retried — the file is the channel of record, the chat is the pager.

### 26.5 The `Log` facade

`Botex\Support\Log\Log` is a static front for code that runs before (or
without) dependency injection — `Registry`, bootstrap, and the entry
scripts. It forwards to the same instance once `bootstrap/app.php`
registers it via `Log::use($logger)`, and falls back to `error_log()`
until then, so the earliest boot errors are still visible.

```php
use Botex\Support\Log\Log;
use Botex\Support\Log\Level;

Log::warning('Something went wrong', ['extension' => $slug]);
Log::exception($e, 'Console command failed', Level::Error, ['type' => 'console']);
```

**Inject the `Logger` whenever you can** — the facade is the escape hatch,
not the pattern.

### 26.6 File format and retention

One file per day, `storage/logs/app-YYYY-MM-DD.log`, one line per record
(wrapped here, whole in the file):

```
[2026-08-31 14:02:11] [ERROR   ] [shop:activate] Activation failed
  {"user":1234,"extension":"Shop","exception":{"class":"Botex\\Support\\WalletException",
  "message":"Insufficient balance","file":".../WalletService.php:88"},"trace":[...]}
```

Files older than `LOG_KEEP_DAYS` (default 14) are deleted on a `critical`
write, which is rare enough to be free and needs no cron job.

### 26.7 What to log in an extension

- **Do** log state changes with their identifiers: payment captured,
  subscription renewed, job skipped.
- **Do** log every `catch` that swallows or degrades — a swallowed
  exception that never reaches a log is the kind of bug nobody finds.
- **Do** attach `extension` to every line, so a grep of one word returns
  your whole footprint.
- **Do not** log secret material (tokens, passwords, `.env` values) —
  the file is readable on disk and its summary fields go to a chat.
- **Do not** log full user payloads or callback data; log the fact and the
  id, and let `origin` point at the code.
- **Do not** construct a `Logger` yourself; it is pre-registered with the
  right path, level, notifier and retention, and building a second one
  would double the admin notifications.

