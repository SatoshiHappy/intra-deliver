<?php

namespace Tests\Feature\Ai;

use App\Actions\Ai\Instructions\InstructionBuilder;
use App\Actions\Ai\ToolCalls\GrantedTool;
use App\Actions\Ai\ToolCalls\GrantedTools;
use App\Actions\Ai\ToolCalls\ToolArgumentSchema;
use App\Actions\Ai\ToolCalls\ToolGrants;
use App\Actions\Ai\ToolCalls\ToolSubjects;
use App\Actions\Ai\Tools\RecordToolDescriptions;
use App\Actions\Ai\Tools\RecordWriteToolDescriptions;
use App\Enums\Records\RecordFieldType;
use App\Enums\Records\RecordModuleAction;
use App\Models\Records\RecordField;
use App\Models\Records\RecordModule;
use LogicException;
use Tests\Support\TransactionalTestCase;

/**
 * 1 段目で渡す本物の作る・直すの説明と引数の説明(docs の 設計/AI/道具と権限/README.md 10.1 の説明と書く道具の説明で求めること・10.2 の作る・直すの行、
 * 件どうしのつながり = 設計/AI/道具と権限/まとめた実行と送るデータ.md 10.5): 日本語で、一度に頼まれた書き込みを同じ応答で続けて呼び、つながる件を
 * 前の件を指す印で入れるよう求め、確認の後に書かれると言う。載るのは範囲の業務モジュールと項目だけで、名前は説明用の写しから入る。
 */
final class RecordWriteToolDescriptionsTest extends TransactionalTestCase
{
    use ArrangesToolPermissions;
    use BuildsToolRecords;
    use ChecksModelTexts;

    private const TOOLS = ['ai_create_record', 'ai_update_record'];

    /** 作る・直すのどちらの説明にも 1 行として入る見出しと文 */
    private const SHARED_LINES = [
        '# 一度に頼まれた書き込み', '# 引数', '# 値の書き方', '# 明細の行の書き方', '# 参照制限の書き方', '# 誤りのとき',
        '呼んでもすぐには書かれない。同じ応答の中で呼んだ作る・直すは 1 枚の確認のカードにまとまり、利用者がカードを見て「実行」を押した後に、呼んだ順に 1 件ずつ書かれる。',
        '利用者が「実行」を押さなければ、どの件も書かれない。1 件が断られても、ほかの件は書かれる。',
        '- 利用者が一度に頼んだ書き込み(複数のレコードを作る・直す)は、同じ応答の中で続けて呼ぶ。',
        '- 後の件が、前の件で作るレコードを参照の値に持つ(つながる件)ときも、前の件を指す印で同じ応答に入れる。前の作るの `ref` に仮の名前を書き、'
            .'後の件は参照の値を `{"ref": "<仮の名前>"}` にする。',
        '- 前の件を指す印で書けるときは、前の件の結果を待つ依存に当たらない。前の件の結果を待たずに、同じ応答の中で前の件の後に続けて呼ぶ。',
        '- 指せるのは、同じ応答の中で前に呼んで確認に出た作るの仮の名前だけ(後ろの件・直すの件・確認に出なかった件(誤り・断り)は指せない)。仮の名前は、同じ応答の中で重ねない。',
        '- 指した作るの業務モジュールは、参照の項目の参照先の業務モジュールと同じでなければならない。',
        '- 指された前の件が書けなければ、指した後の件も断られる。指さない件は書かれる。',
        '- 同じレコードを直す件は、同じ応答の中で 1 つの直すにまとめる。',
        '- 応答を分けて呼ぶと、応答ごとに別の確認のカードになる。',
        '- `module`: 業務モジュールのコード(必須)。下の一覧の物だけを使える。',
        '- `row_access`: 参照制限(下の「参照制限の書き方」)。',
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
        '- `row_access` は、`restricted`(参照を制限するか)と `viewers`(見せる人の列)を持つ。'
            .'見せる人の要素は `{"type": "user" か "department", "id": <id>}` か、名前の物。',
        '- `viewers` を渡すと、列が見せる人の全体になる。見せる人を空にするときは `[]` を書く(`null` を書かない)。',
        '- 参照を制限しないレコードには、見せる人を置けない。`restricted` を `true` にして見せる人と一緒に渡すと、参照を制限して'
            .'見せる人を置く。`restricted` が `false` で見せる人のある `viewers` を渡すと、断られる。',
        '- 引数に誤り(無い項目・型に合わない値・無い選択肢・指す作るの無い仮の名前など)があると、確認のカードを出さずに誤りの文が返り、その件は書かれない。'
            .'名前の物の候補か当たらなかったことが返ったとき(上の「値の書き方」)のほかは、あなたは文に沿って引数を直し、呼び直す。',
        '- 確認に出なかった件を指す後の件も誤りになる。印は応答をまたいで指せないので、前の件を直して呼び直すときは、それを指す後の件も同じ応答の中で一緒に呼び直す。',
        '- 前の件は確認に出たが、それを指す後の件だけが引数の誤り(印を使えなかった誤りを含む)で確認に出なかったときは、前の件を'
            .'呼び直さない。前の件が書かれた後に結果に返る id を参照の値にし、引数を直して後の件を呼び直す。',
    ];

    /** 作るの説明にだけ入る文 */
    private const CREATE_LINES = [
        '業務モジュールのレコードを 1 件作る。',
        '書いた後に、作ったレコードの id と表示の名前か、断りの理由が結果に返る。',
        '- `values`: 項目のコードから値へのオブジェクト(下の「値の書き方」)。書かない項目は、空か項目の既定値になる。',
        '- `details`: 明細の項目のコードから行の列へのオブジェクト(下の「明細の行の書き方」)。',
        '- `ref`: 同じ応答の後の件がこの件で作るレコードを指すときの仮の名前(上の「一度に頼まれた書き込み」)。指されないなら省く。',
        '- `details` の行は `{"values": {…}}` の形で、`values` は行の項目のコードから値へのオブジェクト(値の形は上と同じ)。'
            .'例 `{"lines": [{"values": {"item": "部品"}}]}`。',
        '- 作るの行は、どれも新しい行になる。行に `id` を書かない。',
        '- `row_access` を渡さなければ、参照を制限するかは業務モジュールの既定になり、見せる人は空になる。',
        '- `restricted` だけを渡すと、見せる人は空になる。見せる人のある `viewers` だけを渡すと、業務モジュールの既定が参照を制限しないときに'
            .'断られる(既定はこの説明に無い)。',
    ];

    /** 直すの説明にだけ入る文 */
    private const UPDATE_LINES = [
        '業務モジュールのレコード 1 件を `id` で直す。利用者がふだんの画面で読めて直せるレコードだけを直せる。',
        '書いた後に、書けたか、断りの理由が結果に返る。利用者が見られないか無いレコードは、呼んだときに見つからないと返る。',
        '- `id`: 直すレコードの id(必須)。探す・読むの結果の `id` から取り、推し量って書かない。',
        '- `values`: 直す項目のコードから値へのオブジェクト(下の「値の書き方」)。書かない項目は今の値のまま。',
        '- `details`: 直す明細の項目のコードから行の列へのオブジェクト(下の「明細の行の書き方」)。',
        '- 「複数を選べる」項目に相手を足すときは、読むの結果の今の値を含めた列を書く。今の値の相手は、型「ユーザー」「組織」は `id` の整数、'
            .'型「ユーザーと組織」は `type` と `id` だけを取った `{"type": …, "id": …}` で書く(ほかの鍵を写さない)。',
        '- `details` の行は `{"id": <行の id>, "values": {…}}` の形で、新しい行は `id` を省く。'
            .'`values` は行の項目のコードから値へのオブジェクト(値の形は上と同じ)。'
            .'例 `{"lines": [{"id": 51, "values": {"item": "部品"}}, {"values": {"item": "工具"}}]}`。',
        '- 書いた明細の項目は、列がその表の行の全体になる。`id` のある行はその行を直し(`values` に無い項目は今の値のまま)、`id` の無い行は足し、列に無い今の行は消える。',
        '- 変えずに残す行は `{"id": <行の id>, "values": {}}` と書く(`values` を省かない)。',
        '- 書かない明細の項目の行は今のまま。',
        '- 行の id は読むの結果の明細の行から取る。明細の項目を書くときは、全部のページの行を読んでから、残す行を全部書く。',
        '- `row_access` を渡さなければ、参照制限は今のまま。',
        '- `restricted` だけを渡すと、`true` なら見せる人は今のまま、`false` なら見せる人は空になる。見せる人のある `viewers` だけを渡すと、'
            .'今のレコードが参照を制限しないときに断られる。',
        '- 見せる人を足すときは、今の見せる人(読むの結果の `rowAccess` の見せる人)を含めた列を書く。今の見せる人の要素は、'
            .'`type` と `id` だけを取った `{"type": …, "id": …}` で書く(ほかの鍵を写さない)。',
    ];

    private GrantedTools $granted;

    protected function setUp(): void
    {
        parent::setUp();
        $this->arrangeRequester();
        $this->arrangeTasks();
        $this->permit('m_tasks', [RecordModuleAction::Create, RecordModuleAction::Update]);
        $writes = [RecordModuleAction::Read, RecordModuleAction::Create, RecordModuleAction::Update];
        $alpha = $this->moduleFor('m_alpha', $writes);
        $alpha->update(['name' => 'アルファ[[rec:m_tasks:1]]']);
        $marked = ['name' => '項目[[rec:m_tasks:2]]', 'is_required' => true];
        $this->field($alpha, 'code_a', RecordFieldType::String, $marked);
        $this->moduleFor('m_create', [RecordModuleAction::Create]);
        $this->moduleFor('m_read', [RecordModuleAction::Read]);
        $this->stop($this->moduleFor('m_stopped', $writes));
        $this->recordModule('m_none', []);
    }

    /** 範囲の外(止め・権限の無い・無くなった・操作の足りない)の業務モジュールは載らず、範囲の物は範囲の並び */
    public function test_the_descriptions_carry_only_the_modules_in_the_range_in_its_order(): void
    {
        $this->grantWriteTools(['m_alpha', 'm_create', 'm_read', 'm_stopped', 'm_tasks', 'm_none', 'm_gone']);

        $this->assertSame(['m_alpha', 'm_create', 'm_tasks'], $this->granted('ai_create_record')->scope->codes());
        $this->assertSame(['m_alpha', 'm_tasks'], $this->granted('ai_update_record')->scope->codes());

        foreach (self::TOOLS as $name) {
            $text = $this->description($name);
            $alpha = strpos($text, '## `m_alpha` アルファ[ [rec:m_tasks:1]]');
            $tasks = strpos($text, '## `m_tasks` タスク');
            $this->assertTrue($alpha !== false && $tasks !== false && $alpha < $tasks, $name);

            foreach (['m_read', 'm_stopped', 'm_none', 'm_gone', '[[rec:'] as $absent) {
                $this->assertStringNotContainsString($absent, $text, $name);
            }
        }

        $this->assertStringContainsString('## `m_create`', $this->description('ai_create_record'));
        $this->assertStringNotContainsString('m_create', $this->description('ai_update_record'));
    }

    /** 項目はコード・名前と、型と書くときの性質(明細の必須を含む)・選択肢・参照先の注を 1 つを 1 行で載せ、明細の項目は行の項目も載せる */
    public function test_the_descriptions_carry_the_fields_with_their_types(): void
    {
        $this->taskFields['lines']->update(['is_required' => true]);
        $this->grantWriteTools(['m_alpha', 'm_tasks']);

        foreach (self::TOOLS as $name) {
            $text = $this->description($name);

            foreach ([
                "- `code_a` 項目[ [rec:m_tasks:2]]\n  - 型「文字列」 / 必須\n",
                "- `title` 件名\n  - 型「文字列」 / 表示の項目\n",
                "- `status` 状態\n  - 型「選択」\n  - 選択肢 `open` openの名称\n  - 選択肢 `done` doneの名称\n",
                "- `amount` 金額\n  - 型「整数」\n",
                "- `owner` 担当\n  - 型「ユーザー」\n",
                "- `ref` 参照\n  - 型「業務モジュールのレコード」\n  - 参照先 業務モジュール `m_refs` m_refs\n",
                "- `lines` 明細\n  - 型「明細」(`details` に行の列で書く) / 必須(1 行以上)\n  - 行の項目 `item` 品目\n    - 型「文字列」",
            ] as $line) {
                $this->assertStringContainsString($line, $text, $name);
            }
        }
    }

    /**
     * 一度に頼まれた書き込みを同じ応答で続けて呼び、つながる件を前の件を指す印で入れ、印で書けるときは結果を待たないと言い分け、確認の後に
     * 書かれると言う。見出しと文は共通の物と道具ごとの物でちょうどになる(道具ごとの物はその道具にだけ入り、確かめない行も残らない)。
     */
    public function test_the_descriptions_carry_the_rules_of_each_tool(): void
    {
        $this->grantWriteTools(['m_tasks']);
        $own = ['ai_create_record' => self::CREATE_LINES, 'ai_update_record' => self::UPDATE_LINES];

        foreach (self::TOOLS as $name) {
            $lines = array_diff(explode("\n", explode("\n# 業務モジュール", $this->description($name))[0]), ['']);
            $expected = [...self::SHARED_LINES, RecordToolDescriptions::NAME_UNRESOLVED, ...$own[$name]];
            $this->assertEqualsCanonicalizing($expected, array_values($lines), $name);
        }
    }

    /** 説明に入れる名前は、1 段目が会話の印の形を直した写しから入り、範囲の定義そのものは直さない(10.6 の (1)) */
    public function test_the_names_in_the_descriptions_break_the_conversation_marker_forms(): void
    {
        $form = app(InstructionBuilder::class)->conversationMarkerForms()[0];
        $broken = mb_substr($form, 0, 1).' '.mb_substr($form, 1);
        $alpha = RecordModule::query()->where('code', 'm_alpha')->sole();
        $alpha->update(['name' => "アルファ{$form}"]);
        RecordField::query()->where('record_module_id', $alpha->id)->where('code', 'code_a')
            ->update(['name' => "項目{$form}"]);
        $this->grantWriteTools(['m_alpha']);

        foreach (self::TOOLS as $name) {
            $text = $this->description($name);
            $this->assertStringNotContainsString($form, $text, $name);
            $this->assertStringContainsString("## `m_alpha` アルファ{$broken}", $text, $name);
            $this->assertStringContainsString("- `code_a` 項目{$broken}", $text, $name);
            $this->assertSame("アルファ{$form}", $this->granted($name)->scope->modules['m_alpha']['name']);
        }
    }

    /** 説明の文は 1 文の途中で折らない(行は句点か記号で終わるか、見出し・空行・一覧の行) */
    public function test_the_descriptions_do_not_break_a_sentence(): void
    {
        $this->grantWriteTools(['m_tasks']);

        foreach (self::TOOLS as $name) {
            $text = explode("\n# 業務モジュール", $this->description($name))[0];

            foreach (explode("\n", $text) as $line) {
                $this->assertMatchesRegularExpression('/(^$|^#|[。)]$)/uD', $line, "{$name}: {$line}");
            }
        }
    }

    /** 引数の説明は 8 つの鍵がどれも空でなく、引数の形の説明に同じ文が入る */
    public function test_the_argument_texts_are_filled_and_used_by_the_schemas(): void
    {
        $rowAccess = ['row_access', 'row_access.restricted', 'row_access.viewers'];
        $keys = ['module', 'values', 'details', ...$rowAccess, 'ref', 'id'];
        $this->assertSame($keys, array_keys(RecordWriteToolDescriptions::ARGUMENTS));

        foreach (RecordWriteToolDescriptions::ARGUMENTS as $key => $text) {
            $this->assertNotSame('', trim($text), $key);
        }

        $this->grantWriteTools(['m_tasks']);
        $used = [];

        foreach (self::TOOLS as $name) {
            $used = [...$used, ...$this->argumentTexts($name)];
        }

        $this->assertSame([], array_values(array_diff(RecordWriteToolDescriptions::ARGUMENTS, $used)));
    }

    /**
     * 説明と引数の説明に、英語に戻った文・英語の語の並び・接続先の会社と製品の名前が無い(探す・読むの説明と同じ確かめ)。コーディングの語の
     * 確かめは掛けない(業務モジュールと項目のコードを言うため)。
     */
    public function test_the_texts_are_japanese_without_the_vendor_names(): void
    {
        $this->grantWriteTools(['m_alpha', 'm_tasks']);
        $texts = [];

        foreach (self::TOOLS as $name) {
            $texts[$name] = $this->description($name);

            foreach ($this->argumentTexts($name) as $key => $text) {
                $texts["{$name}.{$key}"] = $text;
            }
        }

        $this->assertCount(16, $texts);
        $this->assertSame([], self::textsNotInJapanese($texts));
        $this->assertSame([], self::textsWithEnglishRuns($texts));
        $this->assertSame([], self::wordProblems($texts, self::VENDOR_WORDS, self::VENDOR_KATAKANA_WORDS));
    }

    /**
     * 作る・直すを渡す実行の 1 段目を済ませる。
     *
     * @param  list<string>  $knowledge
     */
    private function grantWriteTools(array $knowledge): void
    {
        $run = $this->runFor(self::TOOLS, $knowledge);
        $subjects = app(ToolSubjects::class)->for($run);
        $this->granted = app(ToolGrants::class)->grant($run->agentDefinition()->sole(), $subjects);
    }

    private function granted(string $name): GrantedTool
    {
        return $this->granted->find($name) ?? throw new LogicException("{$name} を渡していない");
    }

    private function description(string $name): string
    {
        $granted = $this->granted($name);

        return $granted->tool->description($granted->scope);
    }

    /**
     * 引数の形の説明(鍵の道 → 文。オブジェクトの中の鍵も)。
     *
     * @return array<string, string>
     */
    private function argumentTexts(string $name): array
    {
        $texts = [];
        $pending = ['' => ToolArgumentSchema::of($this->granted($name)->tool)];

        while ($pending !== []) {
            $path = (string) array_key_first($pending);
            $schema = $pending[$path];
            unset($pending[$path]);

            foreach (ToolArgumentSchema::properties($schema) as $key => $property) {
                $key = ToolArgumentSchema::join($path, (string) $key);
                $texts[$key] = is_string($property['description'] ?? null) ? $property['description'] : '';
                $pending[$key] = $property;
            }
        }

        return $texts;
    }
}
