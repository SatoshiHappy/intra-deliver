<?php

namespace App\Actions\Ai\Tools;

use App\Actions\Ai\Api\AgentToolScope;
use App\Queries\Ai\Api\RecordMarkers;

/**
 * 汎用の道具「作る」「直す」がモデルに渡す説明と引数の説明(docs の 設計/AI/道具と権限/README.md 10.1 の説明・書く道具の説明で求めること、
 * 引数と値の形 = 10.2 の作る・直すの行と参照制限とユーザー・組織の名前、件どうしのつながり = 設計/AI/道具と権限/まとめた実行と送るデータ.md 10.5)。
 * 文は日本語で、範囲の業務モジュールと項目は実行の始めの範囲の説明用の写しから組む(10.6 の (1))。文は 1 文を 1 行にする({@see RecordToolDescriptions})。
 */
final class RecordWriteToolDescriptions
{
    /** 引数の説明(引数の鍵 → 文) */
    public const ARGUMENTS = [
        'module' => '業務モジュールのコード(必須)。説明の一覧の物だけを使える。',
        'values' => '項目のコードから値へのオブジェクト。値の形は説明の「値の書き方」による。明細の項目は `details` に書く。',
        'details' => '明細の項目のコードから行の列へのオブジェクト。行は `{"id": <行の id>, "values": {…}}` の形で、新しい行は `id` を省く。',
        'row_access' => '参照制限(参照を制限するかと見せる人)。渡さないキーの扱いは説明の「参照制限の書き方」による。',
        'row_access.restricted' => '参照を制限するか(`true` か `false`)。',
        'row_access.viewers' => '見せる人の列。要素は `{"type": "user" か "department", "id": <id>}` か、名前の物 `{"name": "…"}`。',
        'ref' => 'この件で作るレコードを同じ応答の後の件が指すときの仮の名前。同じ応答の中で重ならない名前にする。',
        'id' => '直すレコードの id(必須)。探す・読むの結果の `id` から取る。',
    ];

    /** 呼んだ後に書かれるまで(作る・直すで同じ) */
    private const CONFIRMATION = [
        '呼んでもすぐには書かれない。同じ応答の中で呼んだ作る・直すは 1 枚の確認のカードにまとまり、利用者がカードを見て「実行」を押した後に、'
            .'呼んだ順に 1 件ずつ書かれる。',
        '利用者が「実行」を押さなければ、どの件も書かれない。1 件が断られても、ほかの件は書かれる。',
    ];

    /** 一度に頼まれた書き込みと件どうしのつながり */
    private const LINKS = [
        '# 一度に頼まれた書き込み',
        '- 利用者が一度に頼んだ書き込み(複数のレコードを作る・直す)は、同じ応答の中で続けて呼ぶ。',
        '- 後の件が、前の件で作るレコードを参照の値に持つ(つながる件)ときも、前の件を指す印で同じ応答に入れる。前の作るの `ref` に仮の名前を'
            .'書き、後の件は参照の値を `{"ref": "<仮の名前>"}` にする。',
        '- 前の件を指す印で書けるときは、前の件の結果を待つ依存に当たらない。前の件の結果を待たずに、同じ応答の中で前の件の後に続けて呼ぶ。',
        '- 指せるのは、同じ応答の中で前に呼んで確認に出た作るの仮の名前だけ(後ろの件・直すの件・確認に出なかった件(誤り・断り)は指せない)。'
            .'仮の名前は、同じ応答の中で重ねない。',
        '- 指した作るの業務モジュールは、参照の項目の参照先の業務モジュールと同じでなければならない。',
        '- 指された前の件が書けなければ、指した後の件も断られる。指さない件は書かれる。',
        '- 同じレコードを直す件は、同じ応答の中で 1 つの直すにまとめる。',
        '- 応答を分けて呼ぶと、応答ごとに別の確認のカードになる。',
    ];

    /** 項目の値の書き方 */
    private const VALUES = [
        '# 値の書き方',
        '値は JSON の値で、型ごとに次の形で書く。探すの条件の値と違い、整数・真偽・id を文字列にしない。',
        '- 型「文字列」: 文字列。例 `{"title": "見積の依頼"}`。',
        '- 型「整数」: 整数。例 `{"amount": 5000}`。',
        '- 型「小数」: 数(小数は文字列でもよい)。例 `{"rate": "0.25"}`。',
        '- 型「日付」: `YYYY-MM-DD` の文字列。例 `{"due": "2026-12-01"}`。',
        '- 型「日時」: 時差つきの日時の文字列。例 `{"start": "2026-10-11T09:30+09:00"}`。',
        '- 型「真偽」: `true` か `false`。例 `{"done": true}`。',
        '- 型「選択」: 選択肢のコードの文字列。例 `{"status": "open"}`。',
        '- 型「業務モジュールのレコード」: 相手のレコードの id の整数(例 `{"project": 34}`)か、同じ応答の前の作るを指す'
            .' `{"ref": "<仮の名前>"}`(例 `{"project": {"ref": "p1"}}`)。',
        '- 型「ユーザー」「組織」: 相手の id の整数(例 `{"owner": 12}`)か、名前の物(例 `{"owner": {"name": "山田 太郎"}}`)。',
        '- 型「ユーザーと組織」: `{"type": "user" か "department", "id": <id>}`'
            .'(例 `{"contact": {"type": "department", "id": 5}}`)か、名前の物。',
        '- 「複数を選べる」項目: 値を列で書き、列が値の全体になる。例 `{"members": [{"type": "user", "id": 12}, {"name": "山田 太郎"}]}`。'
            .'ほかの項目は値を 1 つで書き、列にしない。',
        '- 値を空にする項目は `null` を書く。',
        '- 相手の id とレコードの id は、前の結果の値の中の `id`(型「ユーザーと組織」は `type` と `id`)から取り、推し量って書かない。'
            .'ユーザー・組織の相手で名前しか分からないときは、名前の物で書く。業務モジュールのレコードの参照には名前の物を使えない。',
        RecordToolDescriptions::NAME_UNRESOLVED,
    ];

    /** 参照制限の形(作る・直すで同じ) */
    private const ROW_ACCESS_SHAPE = [
        '# 参照制限の書き方',
        '- `row_access` は、`restricted`(参照を制限するか)と `viewers`(見せる人の列)を持つ。見せる人の要素は'
            .' `{"type": "user" か "department", "id": <id>}` か、名前の物。',
    ];

    /** 参照制限の見せる人の規則(作る・直すで同じ) */
    private const ROW_ACCESS_RULES = [
        '- `viewers` を渡すと、列が見せる人の全体になる。見せる人を空にするときは `[]` を書く(`null` を書かない)。',
        '- 参照を制限しないレコードには、見せる人を置けない。`restricted` を `true` にして見せる人と一緒に渡すと、参照を制限して'
            .'見せる人を置く。`restricted` が `false` で見せる人のある `viewers` を渡すと、断られる。',
    ];

    /** 誤りの返り方(作る・直すで同じ) */
    private const ERRORS = [
        '# 誤りのとき',
        '- 引数に誤り(無い項目・型に合わない値・無い選択肢・指す作るの無い仮の名前など)があると、確認のカードを出さずに誤りの文が返り、'
            .'その件は書かれない。名前の物の候補か当たらなかったことが返ったとき(上の「値の書き方」)のほかは、あなたは文に沿って'
            .'引数を直し、呼び直す。',
        '- 確認に出なかった件を指す後の件も誤りになる。印は応答をまたいで指せないので、前の件を直して呼び直すときは、それを指す後の件も'
            .'同じ応答の中で一緒に呼び直す。',
        '- 前の件は確認に出たが、それを指す後の件だけが引数の誤り(印を使えなかった誤りを含む)で確認に出なかったときは、前の件を'
            .'呼び直さない。前の件が書かれた後に結果に返る id を参照の値にし、引数を直して後の件を呼び直す。',
    ];

    private const CREATE = [
        'head' => [
            '業務モジュールのレコードを 1 件作る。',
            '書いた後に、作ったレコードの id と表示の名前か、断りの理由が結果に返る。',
        ],
        'arguments' => [
            '# 引数',
            '- `module`: 業務モジュールのコード(必須)。下の一覧の物だけを使える。',
            '- `values`: 項目のコードから値へのオブジェクト(下の「値の書き方」)。書かない項目は、空か項目の既定値になる。',
            '- `details`: 明細の項目のコードから行の列へのオブジェクト(下の「明細の行の書き方」)。',
            '- `row_access`: 参照制限(下の「参照制限の書き方」)。',
            '- `ref`: 同じ応答の後の件がこの件で作るレコードを指すときの仮の名前(上の「一度に頼まれた書き込み」)。指されないなら省く。',
        ],
        'values' => [],
        'details' => [
            '# 明細の行の書き方',
            '- `details` の行は `{"values": {…}}` の形で、`values` は行の項目のコードから値へのオブジェクト(値の形は上と同じ)。'
                .'例 `{"lines": [{"values": {"item": "部品"}}]}`。',
            '- 作るの行は、どれも新しい行になる。行に `id` を書かない。',
        ],
        'row_access' => [
            '- `row_access` を渡さなければ、参照を制限するかは業務モジュールの既定になり、見せる人は空になる。',
            '- `restricted` だけを渡すと、見せる人は空になる。見せる人のある `viewers` だけを渡すと、業務モジュールの既定が参照を'
                .'制限しないときに断られる(既定はこの説明に無い)。',
        ],
    ];

    private const UPDATE = [
        'head' => [
            '業務モジュールのレコード 1 件を `id` で直す。利用者がふだんの画面で読めて直せるレコードだけを直せる。',
            '書いた後に、書けたか、断りの理由が結果に返る。利用者が見られないか無いレコードは、呼んだときに見つからないと返る。',
        ],
        'arguments' => [
            '# 引数',
            '- `module`: 業務モジュールのコード(必須)。下の一覧の物だけを使える。',
            '- `id`: 直すレコードの id(必須)。探す・読むの結果の `id` から取り、推し量って書かない。',
            '- `values`: 直す項目のコードから値へのオブジェクト(下の「値の書き方」)。書かない項目は今の値のまま。',
            '- `details`: 直す明細の項目のコードから行の列へのオブジェクト(下の「明細の行の書き方」)。',
            '- `row_access`: 参照制限(下の「参照制限の書き方」)。',
        ],
        'values' => [
            '- 「複数を選べる」項目に相手を足すときは、読むの結果の今の値を含めた列を書く。今の値の相手は、型「ユーザー」「組織」は'
                .' `id` の整数、型「ユーザーと組織」は `type` と `id` だけを取った `{"type": …, "id": …}` で書く(ほかの鍵を写さない)。',
        ],
        'details' => [
            '# 明細の行の書き方',
            '- `details` の行は `{"id": <行の id>, "values": {…}}` の形で、新しい行は `id` を省く。`values` は行の項目のコードから値への'
                .'オブジェクト(値の形は上と同じ)。例 `{"lines": [{"id": 51, "values": {"item": "部品"}}, {"values": {"item": "工具"}}]}`。',
            '- 書いた明細の項目は、列がその表の行の全体になる。`id` のある行はその行を直し(`values` に無い項目は今の値のまま)、`id` の無い行は'
                .'足し、列に無い今の行は消える。',
            '- 変えずに残す行は `{"id": <行の id>, "values": {}}` と書く(`values` を省かない)。',
            '- 書かない明細の項目の行は今のまま。',
            '- 行の id は読むの結果の明細の行から取る。明細の項目を書くときは、全部のページの行を読んでから、残す行を全部書く。',
        ],
        'row_access' => [
            '- `row_access` を渡さなければ、参照制限は今のまま。',
            '- `restricted` だけを渡すと、`true` なら見せる人は今のまま、`false` なら見せる人は空になる。見せる人のある `viewers` だけを'
                .'渡すと、今のレコードが参照を制限しないときに断られる。',
            '- 見せる人を足すときは、今の見せる人(読むの結果の `rowAccess` の見せる人)を含めた列を書く。今の見せる人の要素は、'
                .'`type` と `id` だけを取った `{"type": …, "id": …}` で書く(ほかの鍵を写さない)。',
        ],
    ];

    /** 作るの説明 */
    public static function create(AgentToolScope $scope): string
    {
        return self::text(self::CREATE, $scope);
    }

    /** 直すの説明 */
    public static function update(AgentToolScope $scope): string
    {
        return self::text(self::UPDATE, $scope);
    }

    /**
     * 道具ごとの節と共通の節を並べ、業務モジュールの一覧を続ける。
     *
     * @param  array{head: list<string>, arguments: list<string>, values: list<string>, details: list<string>,
     *     row_access: list<string>}  $parts
     */
    private static function text(array $parts, AgentToolScope $scope): string
    {
        $sections = [
            [...$parts['head'], ...self::CONFIRMATION],
            self::LINKS,
            $parts['arguments'],
            [...self::VALUES, ...$parts['values']],
            $parts['details'],
            [...self::ROW_ACCESS_SHAPE, ...$parts['row_access'], ...self::ROW_ACCESS_RULES],
            self::ERRORS,
        ];

        $texts = array_map(fn (array $lines): string => implode("\n", $lines), $sections);

        return implode("\n\n", $texts)."\n\n# 業務モジュール\n\n".self::modules($scope);
    }

    /** 範囲の業務モジュールごとに、コード・名前と項目(明細の項目は行の項目も) */
    private static function modules(AgentToolScope $scope): string
    {
        $modules = [];

        foreach (RecordToolDescriptions::shown($scope) as $code => $module) {
            $lines = ['## '.RecordToolDescriptions::named($code, $module['name'])];

            foreach (RecordToolValues::fields($module['definition']) as $field) {
                $lines = [...$lines, ...self::fieldLines($field, '', '')];
            }

            $modules[] = implode("\n", $lines);
        }

        return implode("\n\n", $modules);
    }

    /**
     * 項目 1 つの行(コード・名前)と、その下の注の行(型と性質・選択肢・参照先・明細の行の項目)。
     *
     * @param  array<array-key, mixed>  $field
     * @return list<string>
     */
    private static function fieldLines(array $field, string $indent, string $label): array
    {
        $code = $field['code'] ?? null;
        $type = $field['type'] ?? null;

        if (! is_string($code) || ! is_string($type)) {
            return [];
        }

        $name = is_string($field['name'] ?? null) ? $field['name'] : $code;
        $lines = [$indent.'- '.$label.RecordToolDescriptions::named($code, $name)];
        $lines[] = $indent.'  - '.self::notes($field, $type);
        $child = is_array($field['childModule'] ?? null) ? $field['childModule'] : null;

        if ($type === 'details' && $child !== null) {
            foreach (RecordToolValues::fields($child) as $childField) {
                $lines = [...$lines, ...self::fieldLines($childField, $indent.'  ', '行の項目 ')];
            }

            return $lines;
        }

        $details = array_map(fn (string $line): string => $indent.$line, RecordToolDescriptions::details($field));

        return [...$lines, ...$details];
    }

    /**
     * 型と、書くときに要る性質の注。
     *
     * @param  array<array-key, mixed>  $field
     */
    private static function notes(array $field, string $type): string
    {
        if ($type === 'details') {
            $notes = ['型「明細」(`details` に行の列で書く)'];
            $flags = ['isRequired' => '必須(1 行以上)'];
        } else {
            $notes = ['型「'.(RecordToolDescriptions::TYPE_NAMES[$type] ?? RecordMarkers::neutralize($type)).'」'];
            $flags = ['isRequired' => '必須', 'isMultiple' => '複数を選べる', 'isTitle' => '表示の項目'];
        }

        foreach ($flags as $key => $note) {
            $notes = ($field[$key] ?? false) === true ? [...$notes, $note] : $notes;
        }

        return implode(' / ', $notes);
    }
}
