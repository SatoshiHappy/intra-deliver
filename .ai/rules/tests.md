---
paths:
    - 'tests/**'
    - 'scripts/ci/**'
    - 'resources/js/**/*.test.ts'
---

# テストと検査コード

## テスト

- Feature のテストは `Tests\Support\TransactionalTestCase` を継承する(テスト DB `*_test` だけを初期化する保護がある)
- 期待値を弱めて緑にしない。抑制・skip・対象の絞り込みを書かない(台帳⑧に無いものは `scripts/ci/verify` で落ちる。画面の単体テストで照合が見る形と見ない形 = docs リポジトリの `設計/コード構成/検査.md` 6 節の vp test の行)
- `tests/` と `scripts/` には依存の規則と副作用の照合を掛けない
- 画面の単体テストは対象と同じフォルダの `<名前>.test.ts`(vp test。`vite-plus/test` から import する。DOM・部品の描画は使わず、分岐は lib の関数に出して確かめる。書いている途中は `npx vp test <ファイル>` で走らせる = 記録しない。正本 = docs リポジトリの `設計/コード構成/検査.md` の 6 節)

## 検査コード

- Engine(`tests/Architecture/Engine/`)は汎用の判定だけを持ち、intra-deliver 固有の値は Policy(`tests/Architecture/Policy/`・`phpstan.neon`・台帳)から渡す
- 検査を足すときの自己証明・警告の扱いは、docs リポジトリの `設計/コード構成/検査.md` の 6 節「検査の作り方(目安)」(fixture は `tests/Architecture/fixtures/<検査>/{violating,passing}`)
- phpat と PHPStan の自作規則は、抑制の注釈で消せない形(`nonIgnorable`)にする

## E2E(tests/e2e)

- spec は `test` と `expect` を `tests/e2e/support/test.ts` から読む(`@playwright/test` から直に読まない)。worker の最初に画面を 1 回描き、ブラウザの立ち上げの費用を 1 件目のテストの時間切れの外で払うため
- 書く前に、引く部品の描き方を実物で読む。下は実物を読まずに書いて落ちた所
- フォームの欄は `FormBlock`(`resources/js/components/FormBlock.tsx`)が欄の全体を `role="group"` とラベルの名前にするので、`getByLabel('<欄>')` は欄と入力の 2 つに当たって strict mode で落ちる。`getByRole('group', { name, exact: true })` の中の入力の要素(`input`・`textarea`・`getByRole` で引ける部品)で引く
- 状態の札(`StatusBadge`)の印(「✓」「×」。印の無い札もある)は文字より先に描かれ、`aria-hidden` なので、文字の照合(`toHaveText`)には入り、札を含む要素の名前(`getByRole` の `name`)には入らない
- `page.route` で止めて横取りした要求は、ページの側で取り消されても `requestfailed` が届かない(待つと時間切れになる)。テストは「古い応答が画面に届かない(`page.on('response')` で 0 件)・URL と表示が最後の値のまま」で確かめる。取り消しそのものは trace の network の `net::ERR_ABORTED` で見る
- 部分再読み込みの途中で送ると、E2E の単一プロセスの `php artisan serve` で送信の応答が待たされることがある。申請基準日のように再読み込みを起こす欄を変えた直後は、応答で替わる URL の値を `toHaveURL` で待ってから送る。`waitForLoadState('networkidle')` は最初の読み込みで付いた印を次の画面遷移まで持つので、部分再読み込み(XHR)を待たずに返る(機械が混んで応答が遅いと、待たずに次へ進んで落ちる)
- spec は workers 1・名前の順で 1 つの DB を共有するので、後の spec の件数の期待は前の spec の筋に依存する
- 取っ手のキーボードの並べ替え(dnd-kit)は、Space でつかんだ後に 1 タスク待ってから矢印を押す。KeyboardSensor はつかんだ後のタイマーで keydown を受け始め、「自分の位置へ」の読み上げはそれより前に出るので、読み上げは受け口の印にならない(デモユーザー切替の持ち物の E2E のうち、レコードの管理画面の spec の `moveByKeyboard`)
