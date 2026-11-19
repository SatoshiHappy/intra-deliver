<?php

namespace App\Actions\Ai\Tools;

use App\Actions\Ai\Api\AgentToolScope;
use App\Queries\Ai\Api\RecordMarkers;
use LogicException;

/**
 * 探す・読むの説明(モデルに渡す文。docs の 設計/AI/道具と権限/README.md 10.1 の説明・説明で求めること・10.2、残りの読み方 =
 * 設計/AI/道具と権限/まとめた実行と送るデータ.md 10.7)。文は道具の約束の項なので、指示の組み立ての口でなくここに持つ。載せる業務モジュールは
 * 渡せる範囲の物だけを範囲の並びのまま入れ、名前は 1 段目が会話の印の形を直した写しから取り、印の部品でも直す(10.6 の (1))。
 * 件数は道具の定数から入れる。文は 1 文を 1 行にし、行の長さはソースの行を継いでそろえる(行の途中の改行をモデルに渡さない)。
 */
final class RecordToolDescriptions
{
    /** 答えの根拠を結果のレコードの印で引く求め(要件を担う置き場 = 設計/AI/設定とプロンプト/README.md 11.1) */
    public const EVIDENCE = 'あなたは、答えの根拠に使ったレコードを、結果のレコードの印(`record` の値)で示す。'
        .'印は結果に現れたとおりの形で答えの中に書き、結果に無い印を作らない。';

    /** 項目の型の名前(定義の型 → 説明の名前。探すが載せない明細のほかの型を全部持つ) */
    public const TYPE_NAMES = [
        'string' => '文字列',
        'integer' => '整数',
        'decimal' => '小数',
        'date' => '日付',
        'datetime' => '日時',
        'boolean' => '真偽',
        'choice' => '選択',
        'user' => 'ユーザー',
        'department' => '組織',
        'user_or_department' => 'ユーザーと組織',
        'record' => '業務モジュールのレコード',
    ];

    /** 名前の物が決まらないときの求め(探すと作る・直すで同じ文) */
    public const NAME_UNRESOLVED = '- 名前の物は、名前が完全に一致する相手が 1 つのときだけ当たる。決まらないときは、候補か、当たらなかったことが返る。'
        .'候補が返ったら、推し量らずに利用者に選んでもらい、選んだ候補の値で呼び直す。当たらなかったときも、推し量らずに利用者に聞き返す。';

    /** 参照の相手の名前の読み方 */
    private const HIDDEN = '- 参照の相手の名前(`label`)が `#<id>` のときは、利用者がその相手を見られないか、相手に名前が無い。';

    private const SEARCH = [
        '業務モジュールのレコードのうち、利用者がふだんの一覧で見られて、条件に合う物を探す。',
        '結果は、その件数(`total`)・今のページ(`currentPage`)・最後のページ(`lastPage`)と、1 ページ {per_page} 件までの行'
            .'(`records`)。',
        '行は、レコードの印(`record`)・`id`・項目のコードから値へのオブジェクト(`values`)・更新日時(`updatedAt`)を持つ。',
        '',
        '# 引数',
        '- `module`: 業務モジュールのコード(必須)。下の一覧の物だけを使える。',
        '- `conditions`: 項目のコードから条件の値へのオブジェクト。下の一覧で「条件に使える」項目だけを使える。'
            .'条件はすべてを満たす物に当たる。',
        '- `sort`: 並び。項目のコード(昇順)か、頭に `-` を付けたコード(降順)。下の一覧で「並べられる」項目は、値を返す項目'
            .'(`fields` に入れた項目。省いたときは一覧の項目)か表示の項目のときだけ使える。ほかに `id` と `updated_at`'
            .'(結果の `updatedAt` の値で並べる)を使える。省くと `-id`(作った新しい順)。',
        '- `page`: ページの番号(1 から。省くと 1。最後のページより大きい番号は最後のページになる)。',
        '- `fields`: 値を返す項目のコードの列。省くと一覧の項目を返す。どちらの場合も、表示の項目の値を足して返す。',
        '',
        '# 条件の値の書き方',
        '値は JSON の文字列で書く。範囲は文字列を持つオブジェクト、ユーザー・組織の相手の名前は名前の物、複数の相手は列で書く。型ごとに次の形で書く。',
        '- 型「文字列」: 値に含まれる語。例 `{"title": "見積"}`。',
        '- 型「整数」「小数」「日付」「日時」: `from`(以上)と `to`(以下)の一方か両方を持つオブジェクト。'
            .'例 `{"amount": {"from": "1000", "to": "5000"}}`。日付は `YYYY-MM-DD`、日時は時差つき'
            .'(例 `2026-10-11T09:30+09:00`)で書く。',
        '- 型「真偽」: `"true"` か `"false"`。例 `{"done": "true"}`。',
        '- 型「選択」: 選択肢のコード。例 `{"status": "open"}`。',
        '- 型「ユーザー」「組織」: 相手の id の文字列(例 `{"owner": "12"}`)か、名前の物(例 `{"owner": {"name": "山田 太郎"}}`)。',
        '- 型「ユーザーと組織」: `user:<id>` か `department:<id>` の文字列(例 `{"members": "user:12"}`)か、名前の物。',
        '- 型「業務モジュールのレコード」: 相手のレコードの id の文字列。例 `{"project": "34"}`。',
        '- 「複数を選べる」項目(型「ユーザー」「組織」「ユーザーと組織」にだけある): 相手を列で書くと、列のすべてを値に持つ'
            .'レコードに当たる。例 `{"members": ["user:12", {"name": "山田 太郎"}]}`。',
        '- 相手の id は、前の結果の値の中の相手の `id`(型「ユーザーと組織」は `type` と `id`)から取り、推し量って書かない。'
            .'ユーザー・組織の相手で名前しか分からないときは、名前の物で書く。業務モジュールのレコードの参照には名前の物を使えない。',
        self::NAME_UNRESOLVED,
        '- 組織の条件は、その組織だけに当たり、配下の組織を含まない。',
        '',
        '# 結果の読み方',
        '- `total` は、利用者が見られるレコードのうち条件に合う物の数。',
        '- 条件に使った相手は `parties` に、項目のコード(`field`)・種類(`type`)・`id`・名前(`name`)・所属(`affiliation`)で'
            .'返る。名前を読めない相手は、名前と所属の代わりに `note` を持つ。',
        '- 使えない条件・並び・項目は黙って外し、結果の `ignored` に入れる。外した条件は項目のコード(範囲の片側だけを外したときは'
            .' `<コード>.from`・`<コード>.to`、範囲のオブジェクトの中の知らない鍵は `<コード>.<その鍵>`)、外した並びは `sort`、'
            .'外した項目は `fields` で示す。',
        '- 外した物があれば、この結果は外した条件・並び・項目を当てていない。あなたは、下の一覧の「条件に使える」「並べられる」と'
            .'値の書き方を見て引数を直し、呼び直す。直せないときは、外したことを答えに書く。',
        self::HIDDEN,
        '- 件数が 1 ページに収まらないときは、あなたは `page` で次のページを求めるか、条件を絞る。',
        '- 結果が長すぎて切れたときは、切れた行は次のページに無い。あなたは、`fields` で返す項目を減らすか条件を絞って、'
            .'同じページを読み直す。',
    ];

    private const READ = [
        '業務モジュールのレコード 1 件を `id` で読む。利用者がふだんの詳細の画面で読める物だけを返す。',
        '結果は、いつも返す物と、返す部分(`parts` で選ぶ)。',
        '- いつも返す物: レコードの印(`record`)・`id`・表示の名前(`name`)・作成と更新の日時と人(`createdAt`・`createdBy`・'
            .'`updatedAt`・`updatedBy`)・参照制限(`rowAccess` = 参照を制限するかのチェックと見せる人)。',
        '- 項目の値(`values`): 項目のコード・名前・値の列。',
        '- 明細の行(`details`): 明細の表ごとに、コード・名前・行(`rows`)・表の行の総数(`total`)・今のページ(`currentPage`)・'
            .'最後のページ(`lastPage`)。1 ページは表ごとに {details_per_page} 行。',
        '- コメント(`comments`)と更新履歴(`revisions`): どちらも新しい順に、1 ページにコメント {comments_per_page} 件・'
            .'更新履歴 {revisions_per_page} 件で、行(`rows`)・総数(`total`)・今のページ(`currentPage`)・最後のページ'
            .'(`lastPage`)を持つ。',
        '- 添付ファイル(`attachments`): 新しい順に {attachment_limit} 件までの名前・形式・大きさ(`items`)。'
            .'それより多ければ `hasMore` が真で、{attachment_next} 件目からは読めない。`hasMore` が真なら、全部は挙げられないと'
            .'答えに書く。添付ファイルの中身は読めない。',
        '見つからない(無い・読めない)レコードは、見つからないと返す。',
        '',
        '# 引数',
        '- `module`: 業務モジュールのコード(必須)。下の一覧の物だけを使える。',
        '- `id`: レコードの id(必須)。',
        '- `parts`: 返す部分の列。`values`・`details`・`comments`・`revisions`・`attachments` から選ぶ。省くと全部を返す。',
        '- `details_page`・`comments_page`・`revisions_page`: 明細の行・コメント・更新履歴のページの番号(1 から。省くと 1)。',
        '',
        '# 結果の読み方',
        self::HIDDEN,
        '- 明細の表の最後のページより大きい番号のページは、その表の行が空になる。',
        '- コメント・更新履歴の最後のページより大きい番号は、最後のページになる。',
        '- 古いコメント・更新履歴・明細の行は、あなたはページの番号で読む。',
        '- 結果が長すぎて切れたときは、あなたは `parts` で返す部分を絞って、同じページを読み直す。'
            .'1 つの部分だけで切れたら、切れた所は読めないので、読めなかったことを答えに書く。',
    ];

    /** 探すの説明(業務モジュールごとに、コード・名前と項目。明細の項目は探すで使えないので載せない) */
    public static function search(AgentToolScope $scope): string
    {
        $modules = [];

        foreach (self::shown($scope) as $code => $module) {
            $lines = ['## '.self::named($code, $module['name'])];

            foreach (RecordToolValues::fields($module['definition']) as $field) {
                $lines = [...$lines, ...(($field['type'] ?? null) === 'details' ? [] : self::fieldLines($field))];
            }

            $modules[] = implode("\n", $lines);
        }

        $text = strtr(implode("\n", self::SEARCH), ['{per_page}' => (string) SearchRecords::PER_PAGE]);

        return $text."\n\n".self::EVIDENCE."\n\n# 業務モジュール\n\n".implode("\n\n", $modules);
    }

    /** 読むの説明(業務モジュールのコードと名前だけ) */
    public static function read(AgentToolScope $scope): string
    {
        $modules = [];

        foreach (self::shown($scope) as $code => $module) {
            $modules[] = '- '.self::named($code, $module['name']);
        }

        $text = strtr(implode("\n", self::READ), [
            '{details_per_page}' => (string) ReadRecord::DETAILS_PER_PAGE,
            '{comments_per_page}' => (string) ReadRecord::COMMENTS_PER_PAGE,
            '{revisions_per_page}' => (string) ReadRecord::REVISIONS_PER_PAGE,
            '{attachment_limit}' => (string) ReadRecord::ATTACHMENT_LIMIT,
            '{attachment_next}' => (string) (ReadRecord::ATTACHMENT_LIMIT + 1),
        ]);

        return $text."\n\n".self::EVIDENCE."\n\n# 業務モジュール\n\n".implode("\n", $modules);
    }

    /**
     * 範囲の業務モジュールの、説明用の名前の写し(範囲の並び)。
     *
     * @return array<string, array{name: string, definition: array<string, mixed>}>
     *
     * @throws LogicException 範囲に説明用の写しが無いとき(1 段目の外で作った範囲。作りの誤り)
     */
    public static function shown(AgentToolScope $scope): array
    {
        $shown = [];

        foreach ($scope->codes() as $code) {
            $shown[$code] = $scope->shown[$code]
                ?? throw new LogicException("範囲の業務モジュール {$code} に説明用の名前の写しが無い");
        }

        return $shown;
    }

    /**
     * 項目 1 つの行(コード・名前)と、その下の注の行(型と性質・選択肢・参照先)。名前を行の終わりに置き、名前の中の字が区切りと
     * 重ならないようにする。
     *
     * @param  array<array-key, mixed>  $field
     * @return list<string>
     */
    private static function fieldLines(array $field): array
    {
        $code = $field['code'] ?? null;
        $type = $field['type'] ?? null;

        if (! is_string($code) || ! is_string($type)) {
            return [];
        }

        $flags = [
            'isListed' => '一覧の項目',
            'isTitle' => '表示の項目',
            'isMultiple' => '複数を選べる',
            'isSearchable' => '条件に使える',
        ];
        $notes = ['型「'.(self::TYPE_NAMES[$type] ?? RecordMarkers::neutralize($type)).'」'];

        foreach ($flags as $key => $note) {
            $notes = ($field[$key] ?? false) === true ? [...$notes, $note] : $notes;
        }

        $notes = in_array($type, RecordToolValues::SORTABLE_TYPES, true) ? [...$notes, '並べられる'] : $notes;
        $name = is_string($field['name'] ?? null) ? $field['name'] : $code;

        return ['- '.self::named($code, $name), '  - '.implode(' / ', $notes), ...self::details($field)];
    }

    /**
     * 選択肢(1 つを 1 行)と参照先の行。
     *
     * @param  array<array-key, mixed>  $field
     * @return list<string>
     */
    public static function details(array $field): array
    {
        $lines = array_map(
            fn (array $option): string => '  - 選択肢 '.self::named($option['code'], $option['name']),
            self::options($field),
        );
        $module = is_array($field['referencedModule'] ?? null) ? $field['referencedModule'] : [];

        return is_string($module['code'] ?? null) && is_string($module['name'] ?? null)
            ? [...$lines, '  - 参照先 業務モジュール '.self::named($module['code'], $module['name'])]
            : $lines;
    }

    /**
     * 選択肢のうち、コードと名前が文字列の物。
     *
     * @param  array<array-key, mixed>  $field
     * @return list<array{code: string, name: string}>
     */
    private static function options(array $field): array
    {
        $options = [];

        foreach (is_array($field['options'] ?? null) ? $field['options'] : [] as $option) {
            if (is_array($option) && is_string($option['code'] ?? null) && is_string($option['name'] ?? null)) {
                $options[] = ['code' => $option['code'], 'name' => $option['name']];
            }
        }

        return $options;
    }

    /** コードと名前(どちらも印の部品で直す) */
    public static function named(string $code, string $name): string
    {
        return '`'.RecordMarkers::neutralize($code).'` '.RecordMarkers::neutralize($name);
    }
}
