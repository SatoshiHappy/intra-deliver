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
