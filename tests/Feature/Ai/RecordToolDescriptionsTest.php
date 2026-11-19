<?php

namespace Tests\Feature\Ai;

use App\Actions\Ai\Instructions\InstructionBuilder;
use App\Actions\Ai\ToolCalls\GrantedTool;
use App\Actions\Ai\ToolCalls\ToolArgumentSchema;
use App\Actions\Ai\Tools\ReadRecord;
use App\Actions\Ai\Tools\RecordToolDescriptions;
use App\Actions\Ai\Tools\RecordToolValues;
use App\Actions\Ai\Tools\SearchRecords;
use App\Enums\Records\RecordFieldType;
use App\Enums\Records\RecordModuleAction;
use App\Models\Records\RecordField;
use App\Models\Records\RecordModule;
use LogicException;
use Tests\Support\TransactionalTestCase;

/**
 * 1 段目で渡す本物の探す・読むと、その説明(docs の 設計/AI/道具と権限/道具の判定と今の権限.md 10.3 の A2・A3、説明 = 設計/AI/道具と権限/README.md
 * 10.1 の説明と説明で求めること・10.2): 渡せる範囲が空なら渡さない。説明に載るのは範囲の業務モジュールだけで範囲の並びのまま、探すは項目も載せ、
 * 定義の名前は印の部品で直す。説明と引数の説明は日本語で、答えの根拠を結果のレコードの印で引くよう求める。
 */
final class RecordToolDescriptionsTest extends TransactionalTestCase
{
    use BuildsToolRecords;
    use ChecksModelTexts;
    use RunsRecordTools;

    protected function setUp(): void
    {
        parent::setUp();
        $this->arrangeRequester();
        $this->arrangeTasks();
        $alpha = $this->moduleFor('m_alpha', [RecordModuleAction::Read]);
        $alpha->update(['name' => 'アルファ[[rec:m_tasks:1]]']);
        $marked = ['name' => '項目[[rec:m_tasks:2]]', 'is_searchable' => true];
        $this->field($alpha, 'code_a', RecordFieldType::String, $marked);
        $this->stop($this->moduleFor('m_stopped', [RecordModuleAction::Read]));
        $this->recordModule('m_none', []);
        $this->logRecordReads();
    }

    public function test_a_requester_without_a_range_gets_neither_tool(): void
    {
        $this->startRecordToolRun(['m_none', 'm_stopped', 'm_gone']);

        $this->assertSame([null, null], $this->grantedPair());
    }

    public function test_an_inactive_requester_gets_neither_tool(): void
    {
        $this->deactivateRequester();
        $this->startRecordToolRun(['m_tasks']);

        $this->assertSame([null, null], $this->grantedPair());
    }

    /** 範囲の外(止め・無くなった・権限の無い)の業務モジュールのコードと名前は載らず、範囲の物は範囲の並び */
    public function test_the_descriptions_carry_only_the_modules_in_the_range_in_its_order(): void
    {
        $this->startRecordToolRun(['m_alpha', 'm_stopped', 'm_tasks', 'm_none', 'm_gone']);
        $search = $this->granted('ai_search_records');
        $read = $this->granted('ai_read_record');

        $this->assertSame(['m_alpha', 'm_tasks'], $search->scope->codes());
        $this->assertSame(['m_alpha', 'm_tasks'], $read->scope->codes());

        foreach ([$search, $read] as $granted) {
            $text = $granted->tool->description($granted->scope);
            $alpha = strpos($text, '`m_alpha` アルファ');
            $tasks = strpos($text, '`m_tasks` タスク');
            $this->assertTrue($alpha !== false && $tasks !== false && $alpha < $tasks);
            $this->assertStringContainsString(RecordToolDescriptions::EVIDENCE, $text);
            $this->assertStringContainsString('レコードの印', $text);

            foreach (['m_stopped', 'm_none', 'm_gone', '[[rec:'] as $absent) {
                $this->assertStringNotContainsString($absent, $text);
            }
        }
    }

    /**
     * 探すは範囲の業務モジュールの項目(コード・名前と、型・一覧の項目・表示の項目・条件に使える・並べられるの注、選択肢と参照先は 1 つを
     * 1 行)を載せ、名前の中のレコードの印の形は直す。読むは項目を載せない。
     */
    public function test_search_carries_the_fields_and_read_does_not(): void
    {
        $this->startRecordToolRun(['m_alpha', 'm_tasks']);
        $search = $this->description('ai_search_records');
        $read = $this->description('ai_read_record');

        foreach ([
            "- `title` 件名\n  - 型「文字列」 / 表示の項目 / 並べられる\n",
            "- `status` 状態\n  - 型「選択」 / 一覧の項目 / 条件に使える / 並べられる\n"
                ."  - 選択肢 `open` openの名称\n  - 選択肢 `done` doneの名称\n",
            "- `owner` 担当\n  - 型「ユーザー」 / 一覧の項目\n",
            "- `ref` 参照\n  - 型「業務モジュールのレコード」 / 一覧の項目\n  - 参照先 業務モジュール `m_refs` m_refs",
            "- `code_a` 項目[ [rec:m_tasks:2]]\n  - 型「文字列」 / 条件に使える / 並べられる",
            '## `m_alpha` アルファ[ [rec:m_tasks:1]]',
        ] as $line) {
            $this->assertStringContainsString($line, $search);
        }

        $this->assertStringContainsString(RecordToolDescriptions::NAME_UNRESOLVED, $search);
        $this->assertStringNotContainsString('`lines`', $search);
        $this->assertStringNotContainsString('`title`', $read);
        $this->assertStringContainsString("# 業務モジュール\n\n- `m_alpha` アルファ[ [rec:m_tasks:1]]\n- `m_tasks` タスク", $read);
    }

    /**
     * 説明に入れる業務モジュール・項目の名前は、1 段目が会話の印の形を直した写しから入り、範囲の定義そのものは直さない(10.6 の (1))。
     */
    public function test_the_names_in_the_descriptions_break_the_conversation_marker_forms(): void
    {
        $form = app(InstructionBuilder::class)->conversationMarkerForms()[0];
        $broken = mb_substr($form, 0, 1).' '.mb_substr($form, 1);
        $alpha = RecordModule::query()->where('code', 'm_alpha')->sole();
        $alpha->update(['name' => "アルファ{$form}"]);
        RecordField::query()->where('record_module_id', $alpha->id)->where('code', 'code_a')
            ->update(['name' => "項目{$form}"]);
        $this->startRecordToolRun(['m_alpha']);

        foreach (['ai_search_records', 'ai_read_record'] as $name) {
            $granted = $this->granted($name);
            $text = $granted->tool->description($granted->scope);
            $this->assertStringNotContainsString($form, $text);
            $this->assertStringContainsString("アルファ{$broken}", $text);
            $this->assertSame("アルファ{$form}", $granted->scope->modules['m_alpha']['name']);
        }

        $definition = $this->granted('ai_search_records')->scope->modules['m_alpha']['definition'];
        $this->assertStringContainsString("項目{$broken}", $this->description('ai_search_records'));
        $fields = is_array($definition['fields']) ? $definition['fields'] : [];
        $this->assertContains("項目{$form}", array_column($fields, 'name'));
    }

    /** 説明の件数と上限は道具の定数から入る */
    public function test_the_numbers_in_the_descriptions_come_from_the_tool_constants(): void
    {
        $this->startRecordToolRun(['m_tasks']);
        $search = $this->description('ai_search_records');
        $read = $this->description('ai_read_record');

        $this->assertStringContainsString('1 ページ '.SearchRecords::PER_PAGE.' 件までの行', $search);
        $this->assertStringContainsString('1 ページは表ごとに '.ReadRecord::DETAILS_PER_PAGE.' 行', $read);
        $this->assertStringContainsString('1 ページにコメント '.ReadRecord::COMMENTS_PER_PAGE.' 件・更新履歴 '
            .ReadRecord::REVISIONS_PER_PAGE.' 件', $read);
        $this->assertStringContainsString(ReadRecord::ATTACHMENT_LIMIT.' 件までの名前', $read);
        $this->assertStringContainsString((ReadRecord::ATTACHMENT_LIMIT + 1).' 件目からは読めない', $read);
        $this->assertStringNotContainsString('{', preg_replace('/`[^`]*`/u', '', $search.$read) ?? '');
    }

    /** 説明の文は 1 文の途中で折らない(行は句点か記号で終わるか、見出し・空行・一覧の行) */
    public function test_the_descriptions_do_not_break_a_sentence(): void
    {
        $this->startRecordToolRun(['m_tasks']);

        foreach (['ai_search_records', 'ai_read_record'] as $name) {
            $text = explode("\n# 業務モジュール", $this->description($name))[0];

            foreach (explode("\n", $text) as $line) {
                $this->assertMatchesRegularExpression('/(^$|^#|[。)]$)/uD', $line, "{$name}: {$line}");
            }
        }
    }

    /**
     * 説明の型の名前は、探すが載せない明細のほかの入れ物の型を全部持ち、並べられる型・範囲で探す型は入れ物の型の規則と同じ
     * (道具の名前空間は入れ物の列挙に依存しないので値で持つ)。
     */
    public function test_the_type_lists_match_the_field_types(): void
    {
        $values = fn (callable $filter): array => array_values(array_map(
            fn (RecordFieldType $type): string => $type->value,
            array_filter(RecordFieldType::cases(), $filter),
        ));

        $this->assertSame(
            $values(fn (RecordFieldType $type): bool => $type !== RecordFieldType::Details),
            array_keys(RecordToolDescriptions::TYPE_NAMES),
        );
        $plain = $values(fn (RecordFieldType $type): bool => $type->isPlain());
        $ordered = $values(fn (RecordFieldType $type): bool => $type->isOrdered());
        $this->assertSame($plain, RecordToolValues::SORTABLE_TYPES);
        $this->assertSame($ordered, RecordToolValues::ORDERED_TYPES);
    }

    /**
     * 説明と引数の説明に、英語に戻った文・英語の語の並び・接続先の会社と製品の名前が無い(既定の文と同じ確かめ)。コーディングの語
     * (`code`・「コード」)の確かめは掛けない(業務モジュールと項目のコードを言うため)。
     */
    public function test_the_texts_are_japanese_without_the_vendor_names(): void
    {
        $this->startRecordToolRun(['m_alpha', 'm_tasks']);
        $texts = [];

        foreach (['ai_search_records', 'ai_read_record'] as $name) {
            $granted = $this->granted($name);
            $texts[$name] = $granted->tool->description($granted->scope);

            foreach (ToolArgumentSchema::properties(ToolArgumentSchema::of($granted->tool)) as $key => $property) {
                $texts["{$name}.{$key}"] = is_string($property['description'] ?? null) ? $property['description'] : '';
            }
        }

        $this->assertCount(13, $texts);
        $this->assertSame([], self::textsNotInJapanese($texts));
        $this->assertSame([], self::textsWithEnglishRuns($texts));
        $this->assertSame([], self::wordProblems($texts, self::VENDOR_WORDS, self::VENDOR_KATAKANA_WORDS));
    }

    /**
     * 渡した読むと探す(渡さなければ null)。
     *
     * @return array{GrantedTool|null, GrantedTool|null}
     */
    private function grantedPair(): array
    {
        return [$this->granted->find('ai_read_record'), $this->granted->find('ai_search_records')];
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
}
