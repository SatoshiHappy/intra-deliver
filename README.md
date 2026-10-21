# イントラデリバー(intra-deliver)

intra-mart を参考にした、ワークフローと業務アプリの非公式の再現。個人のポートフォリオとして作っている。
製品名は各社の商標で、このリポジトリは製品の提供元と関係がない。

## このリポジトリについて

- 開発は非公開のリポジトリで行い、ここにはその写しを置く(履歴は含めない)。CI(GitHub Actions の [ci.yml](.github/workflows/ci.yml))はこの写しで流れる。
- 設計と仕様の文書は非公開。コードのコメントの「docs の …」はその文書を指す。
- 技術: Laravel 13 + Inertia 3 + React + TypeScript、PostgreSQL 18 + PGroonga。
- 起点は Laravel 公式のスターターキット(Vue 版)。画面は React(shadcn/ui の部品)に書き換え、認証の画面とコードは外してある(認証は作らず、デモユーザー切替で利用者を選ぶ)。

## ライセンス

[LICENSE](LICENSE) を見る(利用は許可しない。第三者のソフトウェアに由来する部分は [THIRD_PARTY_NOTICES](THIRD_PARTY_NOTICES))。

## 開発の手順(以下は開発者向け)

設計の文書は、web の隣の `docs/`(非公開のリポジトリ)にある前提で書いている。

## 前提

- PHP 8.4(拡張 `pdo_pgsql`)、Composer、Node 22 / npm
- docker と docker-compose(開発用 DB)

## 起動の手順

1. `.env` を作る: `cp .env.example .env` → `DB_PASSWORD` に任意の値を入れる → `php artisan key:generate`
2. 依存を入れる: `composer install` → `npm install`
3. 開発用 DB を起動する: `docker-compose up -d db`
    - コンテナ `intra-deliver-portfolio-db`(PostgreSQL 18 + PGroonga のイメージ)が `127.0.0.1:5445` で待ち受ける。
    - 初回の起動で、開発用 DB とテスト用 DB ができる(main では `intra_deliver_portfolio` と `intra_deliver_portfolio_test`。[docker/initdb/10-create-test-db.sh](docker/initdb/10-create-test-db.sh))。ほかの作業木では `docker-compose` を走らせず(起動・停止・削除は main の作業木から。コンテナと volume を全作業木で共有するため)、動いているコンテナの中に開発用 DB とテスト用 DB を手で作る(docs の `設計/コード構成/検査/一括検査の段.md` 6.5)。
    - 接続情報は `.env` の `DB_*` から読む([compose.yml](compose.yml))。
    - 停止: `docker-compose stop db` / 再開: `docker-compose start db` / データごと消す: `docker-compose down -v`
4. マイグレーション: `php artisan migrate`
5. 開発サーバー: `composer run dev` → http://localhost:8020
    - `php artisan serve`(ポートは `.env` の `SERVER_PORT=8020`)、キュー、ログ、Vite をまとめて起動する。
6. DB を作り直す(初期データを入れ直すとき): `php artisan migrate:fresh --seed`
    - デモユーザー切替をオンにしているときは、続けてその初期データの seeder(`database/seeders/` の中。`DatabaseSeeder` は呼ばない)を `php artisan db:seed --class=<その seeder>` で流す。流さないと切替のルートが 403 になる(コマンド = docs の `設計/デモユーザー切替/デモの取り付け.md` 6.3)。
    - E2E は `scripts/ci/front --e2e` が自分でテスト用 DB を作り直すので、この手順は要らない。

## コードの読み方

- モジュールの一覧と依存の向き = `tests/Architecture/Policy/Modules.php`(docs の `設計/コード構成/README.md` 3 節)。
- 依存の逆転の口(他のモジュールが実装を足す口)は、サービスコンテナのタグで束ねる。登録は `app/Providers/` の 2 つのプロバイダ(`AppServiceProvider` と、デモユーザー切替のプロバイダ)にある(並びが画面の並びになる口は、両方を見る)。口の一覧は、プロバイダの `tag` の行が正本。

## 検査

まとめて実行する: `composer ci:check`(区切りの既定。D は依存の入力が、最後に成功し依存の監査の注意が 0 件だったときから変わっていない同日は飛ばす)。全段は `composer ci:full`

| 検査                                                        | コマンド                                             |
| ----------------------------------------------------------- | ---------------------------------------------------- |
| フロントの lint と整形(Oxlint + Oxfmt。Vite+ の `vp check`) | `npm run check`(直す: `npm run check:fix`)           |
| フロントの型検査(tsc)                                       | `npm run types:check`                                |
| Pint                                                        | `vendor/bin/pint --test`(`composer lint:check`)      |
| PHPStan + Larastan(level 8、baseline なし)                  | `vendor/bin/phpstan analyse`(`composer types:check`) |
| PHPUnit(テスト用 DB は作業木ごと)                           | `php artisan test`                                   |
| 本番ビルド                                                  | `npm run build`                                      |
| E2E(Playwright、Chromium)                                   | `scripts/ci/front --e2e`                             |

- `composer test` は Pint・PHPStan・PHPUnit を順に実行する。`composer ci:check`・`composer ci:full` は `scripts/ci/all` を呼ぶ(段の一覧と順 = `scripts/ci/all` の冒頭、docs の `設計/コード構成/検査/一括検査の段.md` 6.5)。
- 検査の記録の集計: `node scripts/quality/report.mjs`(docs の `設計/コード構成/検査/結果の記録.md` 6.6)。依存と実行環境の更新チェック: docs の `設計/更新チェック.md`。公開: `scripts/deploy`(docs の `設計/公開の配備/`)。
- PHPUnit は DB を作り直す(`RefreshDatabase`)。テスト用 DB の名前は `tests/bootstrap.php` が決める(docs の `設計/コード構成/検査/一括検査の段.md` 6.5。main では `intra_deliver_portfolio_test`)。`TestCase` は、設定がキャッシュされている(`bootstrap/cache/config.php` がある)とき(この指定が効かず開発用 DB が作り直されるため)と、接続した DB がテスト用 DB でないときに、DB に触れる前に止める。`composer test` は先に `config:clear` を実行する。
- E2E は `scripts/ci/front --e2e` から走らせる(テスト用 DB を作り直して E2E の初期データを入れてから `npm run test:e2e` を呼ぶ)。Playwright は組(`playwright.config.ts` の `groups`)ごとに、`scripts/ci/e2e-server` で組の DB(テスト用 DB の写し `<テスト用 DB>_e2e_<n>`)を作り直してサーバーを起動し(動いているサーバーは使わない)、組どうしを並列に走らせる。テスト用 DB の名前が `_test` で終わらないときは起動しない(接続した DB がテスト用 DB かを確かめるのは `scripts/ci/front` の側)。ポートは `scripts/ci/front` が渡す `E2E_PORT` から組の数だけ続く(main では 8021〜)。ほかの spec が変えた DB を前提にする spec は、前提にする spec と同じ組に置く(docs の 設計/コード構成/検査/一括検査の段.md 6.5 の並列(E))。`npm run test:e2e` を直接走らせると、テスト用 DB に初期データを入れ直さず、ポートは既定の 8021〜になる(使用中なら Playwright が止まる)。8021 は main の E2E のポートなので、作業木では直接走らせない。本番ビルドの資産を使うので、先に `npm run build` を実行しておく。
- E2E のブラウザ: `npx playwright install chromium`。Chromium が使うシステムのライブラリが無い場合は `sudo npx playwright install-deps chromium` で入れる。

## AI エージェント向け

Laravel Boost を導入してある(対象 = Claude Code と Codex。設定は [boost.json](boost.json))。
`CLAUDE.md`・`AGENTS.md`・`.claude/skills`・`.agents/skills`・`.mcp.json`・`.codex/config.toml` は Boost が生成する。
プロジェクトの規則(機械検査が止める Hard Rule)は `.ai/guidelines/intra-deliver.md` に書き、Boost が `CLAUDE.md`・`AGENTS.md` に取り込む。
パスごとの補足は `.ai/rules/`(入口 = `.ai/rules/index.md`)。`.ai/` を直したら `php artisan boost:update --no-discover --no-interaction` で作り直す。
作り直す: `php artisan boost:install --no-interaction`(`boost.json` の設定で非対話に実行される)。
WSL の中で実行すると MCP の起動コマンドが `wsl.exe` 経由になるので、WSL の中のエージェントで使うときは `env -u WSL_DISTRO_NAME php artisan boost:install --no-interaction` とする。
