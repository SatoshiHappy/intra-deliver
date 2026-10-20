<laravel-boost-guidelines>
=== .ai/intra-deliver rules ===

# intra-deliver Hard Rule(機械検査が止める規則)

正本 = docs リポジトリの `設計/コード構成/README.md`(層・依存・副作用・置き場と、それを止める検査 = 同じ階層の `検査.md` の 6 節)。ここに書くのは、機械検査で止まる規則と検査の走らせ方だけ。
設計の指針(機械で止められない判断の目安)= docs リポジトリの `設計/コード構成/否決案と指針.md` の 8 節。
クラスの分け方・責務の置き方は規約に無い(書き手に任せる)。名前とコメントは機械で止めない規約(`.ai/rules/general.md`。コードレビューで見る)。パスごとの補足は `.ai/rules/index.md` から読む。
検査は `composer ci:check`(= `scripts/ci/all`。区切りの既定)で走る。コミットの前に通す。D(依存の監査)は依存の入力が最後に成功してから変わっていない同日は飛ばす。全段は `composer ci:full`。区切りの途中の検査は composer・npm の script で走らせ(`composer types:check`・`lint:check`、`npm run check`・`types:check`・`deps:check`・`unused:check`)、バイナリを直接呼ばない(`scripts/ci/record` が結果を記録する。docs の `設計/コード構成/検査.md` 6 節「結果の記録」)。

## ① 層と置き場

- `app/` の直下は `Http`・`Actions`・`Queries`・`Models`・`Enums`・`Support`・`Providers`・`Jobs`・`Console` だけ(台帳① = `config/guardrails_allow.php` の `base_directories`)。ファイルは置かない
- `app/Http` の直下は `Controllers`・`Requests`・`Middleware`、`app/Http/Controllers` の直下は `Web`・`Api`・`Controller.php` だけ
- `<M>`(モジュール)を持つ置き場 = `Controllers\Web`・`Controllers\Api`・`Requests`・`Middleware`・`Actions`・`Queries`・`Models`・`Enums`・`Jobs`・`Console\Commands` と `resources/js/pages`。直下は宣言したモジュールのディレクトリだけで、ファイルは置かない(`Middleware` の直下だけはモジュールに依らないミドルウェアのファイルを置ける)。`resources/js` の `components`・`lib`・`hooks` も直下のディレクトリは宣言したモジュールだけ(`components` は加えて `ui`。直下のファイル = 共通の部品は置ける)
- モジュールの宣言 = `tests/Architecture/Policy/Modules.php`(依存の向きに並べた一覧。依存先に書けるのは一覧で自分より前のモジュールだけ)
- `app/` の PHP のファイルは `App\` の名前空間のクラス(interface・trait・enum を含む)を 1 つだけ宣言する。匿名クラスとクラスの外の処理は書かない。ディレクトリと名前空間は一致させる(PSR-4)
- `database/` のファイルはクラスの外に処理を書かない(マイグレーションの `return new class …;` だけ書ける)
- `resources/views/` は `app.blade.php` だけ。Inertia のページは `resources/js/pages/<M>/` に置き、ページ名を `<M>/…` にする(描くページのファイルが無いと PHPUnit で描画が失敗する)

## ② 層ごとに依存してよい先(書いていない先は禁止)

| 層                                                                                | 依存してよい先                                                                          |
| --------------------------------------------------------------------------------- | --------------------------------------------------------------------------------------- |
| 入口 `App\Http`(下の入口(共通)を除く)                                             | 業務処理・読み取り・入口・列挙・共有・外部(DB を除く)                                   |
| 入口(共通)= `App\Http\Middleware` の直下・`Controllers\Controller`                | 共有・外部(DB を除く)                                                                   |
| 業務処理 `App\Actions`                                                            | 読み取り・永続化・列挙・共有・ジョブ(投入)・外部                                        |
| 読み取り `App\Queries`                                                            | 永続化・列挙・共有・外部                                                                |
| 永続化 `App\Models`                                                               | 列挙・共有・外部                                                                        |
| 列挙 `App\Enums` / 共有 `App\Support`                                             | 共有・外部(DB を除く)/ 外部(DB を除く)                                                  |
| 起動 `App\Providers`                                                              | `App\` のすべてと外部(ほかの層から起動へは禁止)                                         |
| ジョブ `App\Jobs` / コマンド `App\Console`                                        | 業務処理・読み取り・列挙・共有・外部(DB を除く)                                         |
| `database/migrations` / `factories` / `seeders`                                   | 外部 / 永続化・列挙・共有・外部(require-dev を含む)/ 業務処理・永続化・列挙・共有・外部 |
| 配線のファイル(`routes/`・`bootstrap/`・`config/`・`public/index.php`・`artisan`) | 入口・共有・起動・コマンド・外部(DB を除く)                                             |

- 外部 = PHP 標準と `composer.lock` の `packages`(`--no-dev`)の名前空間。DB = `Illuminate\Database`・`Illuminate\Contracts\Database`・`DB` と `Schema` のファサード・PDO の拡張のクラス(`PDO`・`PDOStatement`・`PDORow`・`PDOException`・`Pdo\*`)
- 入口はモデルに依存できない(ルートモデル結合を使わず、識別子を業務処理・読み取りに渡す)。業務処理は、読み取り・永続化・列挙・共有の interface を実装せず、クラスを継承しない
- モジュール `<M>` のクラスが依存してよいのは、`<M>` 自身・宣言した依存先・どのモジュールにも属さないコードだけ
- **公開の口**: ほかのモジュールの業務処理・読み取りのクラスには `App\Actions\<M>\Api`・`App\Queries\<M>\Api` の下にだけ依存できる(内部に依存できるのは同じ `<M>` の層・起動・seeder。移行中の一覧 `Modules::TRANSITIONAL` = Master・Authz・Menu・Account は減らすだけ)。`Api` の下の public メソッドの引数・戻り値と public プロパティにモデル(`Illuminate\Database\Eloquent\Model`。配列・総称の型引数も)を使わない

## ③ 副作用は台帳②の場所だけ

- 副作用 = DB への書き込み(トランザクションを含む)・キューへの投入・通知(メールを含む)・ファイルの保存・AI の呼び出し・外部への HTTP・プロセスの起動。読み取り・セッション・キャッシュ・ログは数えない
- 判定は呼び出しの名前と静的に決まる型だけ(正本 = `tests/Architecture/Policy/SideEffects.php`)。`fopen`・`new SplFileObject` などはモードに依らず保存に数える(`new` は生成そのものが副作用になる型だけを数え、HTTP クライアントを作って束縛するのは数えない)。検査が止めない経路(読み取りの名前の呼び出しに書き込みの SQL を渡す・`Artisan::call`・URL を渡した `file_get_contents`・高階のプロキシ `each->delete()`・イベントの発行)で副作用を起こさない
- 使ってよい場所 = 台帳②(`config/guardrails_allow.php` の `capabilities`。AI の呼び出しとキューへの投入は `App\Actions\Ai` だけ、外部への HTTP はどこにも無い、ほかは `App\Actions`)。配線のファイルではいつも使えない。台帳②の上限(DB への書き込みは業務処理だけ、ほかは業務処理・ジョブ・コマンド)は `tests/Architecture/Policy/SideEffects.php`

## ④ 検査を黙って外さない

- 層・モジュール・副作用・ファイルの形の規則のエラーは、抑制の注釈で消せない
- ほかの検査の抑制(`@phpstan-ignore` など)・解析からの除外・skip・対象の絞り込みは、台帳⑧(`config/guardrails_allow.php` の `exclusions`)に理由つきで載せたものだけが通る。PHPStan の `ignoreErrors` と baseline は置けない。今の違反を許すのは、⑧ の `baseline` の行が指すベースライン(`tests/Architecture/baselines/`)だけで、数は上げない(下げたら同じ変更でベースラインも下げる。上げると基準の版との照合で落ちる)
- 直接依存(`composer.json`・`package.json`)を足す・消すときは、同じ変更で台帳⑤(`config/guardrails_ledger.php`)を直す
- 大きさの上限 = クラス 300 行・メソッド 50 行・認知的複雑度 8(`phpstan.neon`)、画面側(TS・TSX・JS)のファイル 300 行・関数 50 行、PHP と画面側の物理行 120 字。超えるなら分け方を見直す(上限を変えない。ベースラインに足さない)

=== foundation rules ===

# Laravel Boost Guidelines

## Foundational Context

This application is a Laravel application running on PHP 8.4. Always use the APIs that match the installed major version of each package — do not assume a version.

Before relying on a package's API, confirm its installed version:
- PHP packages: run `composer show --direct` to list direct dependencies with versions, or `composer show <vendor/package>` for a single package.
- JS packages: check `package.json` for the installed versions.

## Skills Activation

This project has domain-specific skills available in `**/skills/**`. You MUST activate the relevant skill whenever you work in that domain—don't wait until you're stuck.

## Conventions

- You must follow all existing code conventions used in this application. When creating or editing a file, check sibling files for the correct structure, approach, and naming.
- Use descriptive names for variables and methods. For example, `isRegisteredForDiscounts`, not `discount()`.
- Check for existing components to reuse before writing a new one.

## Verification Scripts

- Do not create verification scripts or tinker when tests cover that functionality and prove they work. Unit and feature tests are more important.

## Application Structure & Architecture

- Stick to existing directory structure; don't create new base folders without approval.
- Do not change the application's dependencies without approval.

## Frontend Bundling

- If a frontend change doesn't show in the UI or you get a "Unable to locate file in Vite manifest" error, run `npm run build` or ask the user to run `npm run dev` or `composer run dev`.

## Documentation Files

- You must only create documentation files if explicitly requested by the user.

=== boost rules ===

# Laravel Boost

## Tools

- Laravel Boost is an MCP server with tools designed specifically for this application. Prefer Boost tools over manual alternatives like shell commands or file reads.
- Use `database-query` to run read-only queries against the database instead of writing raw SQL in tinker.
- Use `database-schema` to inspect table structure before writing migrations or models.
- Use `get-absolute-url` to resolve the correct scheme, domain, and port for project URLs. Always use this before sharing a URL with the user.
- Use `browser-logs` to read browser logs, errors, and exceptions. Only recent logs are useful, ignore old entries.

## Searching Documentation (IMPORTANT)

- Use `search-docs` before changes that depend on Laravel ecosystem APIs, behavior, configuration, or version-specific syntax. Skip it for copy-only edits and other changes where package documentation is irrelevant. Reuse sufficient results already in context instead of searching again.
- Pass a `packages` array to scope results when you know which packages are relevant.
- Use multiple broad, topic-based queries: `['rate limiting', 'routing rate limiting', 'routing']`. Expect the most relevant results first.
- Do not add package names to queries because package info is already shared. Use `test resource table`, not `filament 4 test resource table`.

### Search Syntax

1. Use words for auto-stemmed AND logic: `rate limit` matches both "rate" AND "limit".
2. Use `"quoted phrases"` for exact position matching: `"infinite scroll"` requires adjacent words in order.
3. Combine words and phrases for mixed queries: `middleware "rate limit"`.
4. Use multiple queries for OR logic: `queries=["authentication", "middleware"]`.

## Project Rules

- This project contains committed, area-grouped rules in `.ai/rules` when that directory exists, including path-scoped framework guidelines under `.ai/rules/boost`. Before you enter plan mode or create/edit any file, you MUST first: open @.ai/rules/index.md (it maps file globs to rule files), read every rule file whose globs cover the path(s) in scope, and run `grep -rin 'keyword' .ai/rules` to catch what a path match alone misses. Do not write code until you have read and are following every matching rule. If `.ai/rules` does not exist, continue without it.

## Artisan

- Run Artisan commands directly via the command line (e.g., `php artisan route:list`). Use `php artisan list` to discover available commands and `php artisan [command] --help` to check parameters.
- Inspect routes with `php artisan route:list`. Filter with: `--method=GET`, `--name=users`, `--path=api`, `--except-vendor`, `--only-vendor`.
- Read configuration values using dot notation: `php artisan config:show app.name`, `php artisan config:show database.default`. Or read config files directly from the `config/` directory.

## Tinker

- Execute PHP in app context for debugging and testing code. Do not create models without user approval, prefer tests with factories instead. Prefer existing Artisan commands over custom tinker code.
- Always use single quotes to prevent shell expansion: `php artisan tinker --execute 'Your::code();'`
  - Double quotes for PHP strings inside: `php artisan tinker --execute 'User::where("active", true)->count();'`

=== php rules ===

# PHP

- Always use curly braces for control structures, even for single-line bodies.
- Use PHP 8 constructor property promotion: `public function __construct(public GitHub $github) { }`. Do not leave empty zero-parameter `__construct()` methods unless the constructor is private.
- Use explicit return type declarations and type hints for all method parameters: `function isAccessible(User $user, ?string $path = null): bool`
- Use TitleCase for Enum keys: `FavoritePerson`, `BestLake`, `Monthly`.
- Prefer PHPDoc blocks over inline comments. Only add inline comments for exceptionally complex logic.
- Use array shape type definitions in PHPDoc blocks.

=== deployments rules ===

# Deployment

- Laravel can be deployed using [Laravel Cloud](https://cloud.laravel.com/), which is the fastest way to deploy and scale production Laravel applications.

=== tests rules ===

# Test Enforcement

- Add or update tests for behavior and logic changes when a test provides meaningful regression coverage.
- Pure copy, styling, and layout-only changes do not require new or updated tests.
- When test coverage applies, run the affected tests and ensure they pass.
- Test the changed behavior and its important failure modes, but do not add tests beyond them.
- Read the `testing-best-practices` skill before writing tests.

=== inertia-laravel/core rules ===

# Inertia

- Inertia creates fully client-side rendered SPAs without modern SPA complexity, leveraging existing server-side patterns.
- Components live in `resources/js/pages` (unless specified in `vite.config.js`). Use `Inertia::render()` for server-side routing instead of Blade views.
- ALWAYS use `search-docs` tool for version-specific Inertia documentation and updated code examples.
- IMPORTANT: Activate `inertia-react-development` when working with Inertia client-side patterns.

# Inertia v3

- Use all Inertia features from v1, v2, and v3. Check the documentation before making changes to ensure the correct approach.
- New v3 features: standalone HTTP requests (`useHttp` hook), optimistic updates with automatic rollback, layout props (`useLayoutProps` hook), instant visits, simplified SSR via `@inertiajs/vite` plugin, custom exception handling for error pages.
- Carried over from v2: deferred props, infinite scroll, merging props, polling, prefetching, once props, flash data.
- When using deferred props, add an empty state with a pulsing or animated skeleton.
- Axios has been removed. Use the built-in XHR client with interceptors, or install Axios separately if needed.
- `Inertia::lazy()` / `LazyProp` has been removed. Use `Inertia::optional()` instead.
- Prop types (`Inertia::optional()`, `Inertia::defer()`, `Inertia::merge()`) work inside nested arrays with dot-notation paths.
- SSR works automatically in Vite dev mode with `@inertiajs/vite` - no separate Node.js server needed during development.
- Event renames: `invalid` is now `httpException`, `exception` is now `networkError`.
- `router.cancel()` replaced by `router.cancelAll()`.
- The `future` configuration namespace has been removed - all v2 future options are now always enabled.

=== laravel/core rules ===

# Do Things the Laravel Way

- Use `php artisan make:` commands to create new files (i.e. migrations, controllers, models, etc.). You can list available Artisan commands using `php artisan list` and check their parameters with `php artisan [command] --help`.
- If you're creating a generic PHP class, use `php artisan make:class`.
- Pass `--no-interaction` to all Artisan commands to ensure they work without user input. You should also pass the correct `--options` to ensure correct behavior.

### Model Creation

- When creating new models, create useful factories and seeders for them too. Ask the user if they need any other things, using `php artisan make:model --help` to check the available options.

## APIs & Eloquent Resources

- For APIs, default to using Eloquent API Resources and API versioning unless existing API routes do not, then you should follow existing application convention.

## URL Generation

- When generating links to other pages, prefer named routes and the `route()` function.

## Testing

- When creating models for tests, use the factories for the models. Check if the factory has custom states that can be used before manually setting up the model.
- Faker: Use methods such as `$this->faker->word()` or `fake()->randomDigit()`. Follow existing conventions whether to use `$this->faker` or `fake()`.
- When creating tests, make use of `php artisan make:test [options] {name}` to create a feature test, and pass `--unit` to create a unit test. Most tests should be feature tests.

=== wayfinder/core rules ===

# Laravel Wayfinder

Use Wayfinder to generate TypeScript functions for Laravel routes. Import from `@/actions/` (controllers) or `@/routes/` (named routes).

=== pint/core rules ===

# Laravel Pint Code Formatter

- If you have modified any PHP files, you must run `vendor/bin/pint --dirty --format agent` before finalizing changes to ensure your code matches the project's expected style.
- Do not run `vendor/bin/pint --test --format agent`, simply run `vendor/bin/pint --format agent` to fix any formatting issues.

=== phpunit/core rules ===

# PHPUnit

- This project uses PHPUnit. Create tests with `php artisan make:test --phpunit {name}`.
- Do not include the test suite directory in `{name}`. Use `SomeFeatureTest`, not `Feature/SomeFeatureTest`.
- Read the `testing-best-practices` skill for guidance on coverage, naming, structure, dependency isolation, and review.

## Running Tests

- Run the narrowest set of tests that covers the change. Pass a file path or `--filter=testName` to `php artisan test --compact`.
- Rerun a test after each change to it.
- Run `vendor/bin/phpunit` to call the test runner directly. It accepts the same file path and `--filter=testName` arguments.

=== inertia-react/core rules ===

# Inertia + React

- IMPORTANT: Activate `inertia-react-development` when working with Inertia React client-side patterns.

</laravel-boost-guidelines>
