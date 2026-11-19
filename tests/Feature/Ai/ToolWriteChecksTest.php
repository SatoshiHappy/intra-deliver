<?php

namespace Tests\Feature\Ai;

use App\Actions\Ai\Api\AgentToolScope;
use App\Actions\Ai\Api\PriorToolCall;
use App\Actions\Ai\Api\RecordCurrentValues;
use App\Actions\Ai\ToolCalls\GrantedTool;
use App\Actions\Ai\ToolCalls\GrantedTools;
use App\Actions\Ai\ToolCalls\ToolCallLinks;
use App\Actions\Ai\ToolCalls\ToolRowAccessWrites;
use App\Actions\Ai\ToolCalls\ToolWriteChecks;
use App\Enums\Ai\AiToolAction;
use App\Enums\Ai\AiToolCallDecision;
use App\Enums\Ai\AiToolPartyForm;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\Feature\Ai\Tools\FakeRecordTool;
use Tests\Feature\Ai\Tools\Generic\GenericCreateTool;
use Tests\Feature\Ai\Tools\Generic\GenericTool;
use Tests\Feature\Ai\Tools\Generic\GenericUpdateTool;
use Tests\TestCase;

/**
 * 書く道具の ③ に共通の確かめ(docs の 設計/AI/道具と権限/道具の判定と今の権限.md 10.3 の B1・B19): 定義による項目と値の形と選択肢、作るの
 * 参照制限の W 表(元 = 業務モジュールの既定。W3・W8 は B1、渡さなかったキーも書く値に入る)、直すの 1 段目の後の W 表(元 = 今の値。W3・W8 は
 * B19)、前の件を指す印(問うの前の作るだけを指せる・参照先と同じ業務モジュール・重なる仮の名前)、同じレコードを直す前の件。範囲の外の業務
 * モジュールには掛けない。
 */
final class ToolWriteChecksTest extends TestCase
{
    private const FORMS = [
        'values' => AiToolPartyForm::Input,
        'details' => AiToolPartyForm::InputDetails,
        'row_access' => AiToolPartyForm::InputRowAccess,
    ];

    /**
     * 定義による確かめの誤り。
     *
     * @return array<string, array{array<string, mixed>, string}>
     */
    public static function definitionErrors(): array
    {
        return [
            '無い項目' => [['values' => ['nothing' => 1]], '項目 `nothing` は無い。'],
            '明細の項目を値に置く' => [['values' => ['lines' => []]], '項目 `lines` は明細の項目で、values でなく details に書く。'],
            '型に合わない形' => [['values' => ['amount' => '3']], '項目 `amount` の値の形が型 integer に合わない。'],
            '今の選択肢に無いコード' => [['values' => ['status' => 'gone']], '項目 `status` の選択肢に、コード gone は無い。'],
            '日付の形' => [['values' => ['due' => '2026-02-30']], '項目 `due` は日付 YYYY-MM-DD で書く。'],
            '複数の値の項目に 1 つ' => [['values' => ['refs' => 3]], '項目 `refs` は複数の値の項目で、値を列で書く。'],
            '1 つの項目に列' => [['values' => ['parent' => [3]]], '項目 `parent` は値を 1 つで書く(列にしない)。'],
            '参照の形' => [['values' => ['parent' => 'x']], '項目 `parent` はレコードの id(整数)か、前の件を指す {"ref": "<仮の名前>"} で書く。'],
            '無い明細の項目' => [['details' => ['nothing' => []]], '明細の項目 `nothing` は無い。'],
            '明細の行の形' => [
                ['details' => ['lines' => [['item' => 'a']]]],
                '明細 `lines` の 1 行目の行が {"id": <行の id>(新しい行は省く), "values": {…}} の形でない。',
            ],
            '明細の行の無い項目' => [
                ['details' => ['lines' => [['values' => ['nothing' => 'a']]]]],
                '明細 `lines` の 1 行目の項目 `nothing` は無い。',
            ],
        ];
    }

    /**
     * @param  array<string, mixed>  $arguments
     */
    #[DataProvider('definitionErrors')]
    public function test_a_value_against_the_definition_is_an_input_error(array $arguments, string $error): void
    {
        [, $actual] = $this->whenCalled($this->creator(), [...$arguments, 'row_access' => []]);

        $this->assertSame($error, $actual);
    }

    /** 定義に合う値(null・選択肢・小数の文字列・複数の参照・前の件の参照・明細の行)は通る。範囲の外の業務モジュールには掛けない */
    public function test_values_that_fit_pass_and_out_of_scope_is_not_checked(): void
    {
        $prior = [$this->asked($this->creator(), ['module' => 'm_ref', 'ref' => 'new_ref'])];
        $values = ['title' => null, 'status' => 'open', 'amount' => 3, 'rate' => '1.5'];
        $values['refs'] = [3, ['ref' => 'new_ref']];

        [, $error] = $this->whenCalled($this->creator(), ['values' => $values, 'details' => [
            'lines' => [['values' => ['item' => 'a']], ['id' => 4, 'values' => ['item' => 'b']]],
        ]], $prior);
        [, $outside] = $this->whenCalled($this->creator(), ['module' => 'm_outside', 'values' => ['nothing' => 1]]);

        $this->assertSame([null, null], [$error, $outside]);
    }

    /**
     * 作るの参照制限(既定 × 渡したキー → 書く値か誤り)。
     *
     * @return array<string, array{bool, array<string, mixed>|null, array<string, mixed>|null}>
     */
    public static function createRowAccess(): array
    {
        $viewer = ['type' => 'user', 'id' => 5];

        return [
            '渡さない・既定が付き(W1a)' => [true, null, ['restricted' => true, 'viewers' => []]],
            '渡さない・既定が外れ(W1b)' => [false, null, ['restricted' => false, 'viewers' => []]],
            '見せる人だけ・既定が付き(W4)' => [true, ['viewers' => [$viewer]], ['restricted' => true, 'viewers' => [$viewer]]],
            '見せる人だけ・既定が外れ(W3)' => [false, ['viewers' => [$viewer]], null],
            'チェックだけ真・既定が外れ(W5b)' => [false, ['restricted' => true], ['restricted' => true, 'viewers' => []]],
            'チェック偽と見せる人(W8)' => [true, ['restricted' => false, 'viewers' => [$viewer]], null],
            'チェック偽だけ(W7)' => [true, ['restricted' => false], ['restricted' => false, 'viewers' => []]],
        ];
    }

    /**
     * @param  array<string, mixed>|null  $rowAccess
     * @param  array<string, mixed>|null  $expected
     */
    #[DataProvider('createRowAccess')]
    public function test_a_create_decides_the_row_access_from_the_default(
        bool $default,
        ?array $rowAccess,
        ?array $expected,
    ): void {
        [$arguments, $error] = $this->whenCalled(
            $this->creator(),
            $rowAccess === null ? [] : ['row_access' => $rowAccess],
            defaults: ['m_w' => $default],
        );

        $this->assertSame(
            $expected === null ? [null, ToolRowAccessWrites::VIEWERS_WITHOUT_RESTRICTION] : [$expected, null],
            [$expected === null ? null : $arguments['row_access'], $error],
        );
    }

    /** 直すの 1 段目の後: 元のチェックが外れのレコードに見せる人だけ(W3)は B19、両方渡さなければ今の値のまま(W1a) */
    public function test_an_update_decides_the_row_access_from_the_current_values(): void
    {
        $viewer = ['type' => 'user', 'id' => 5];
        $party = [...$viewer, 'code' => 'u5', 'label' => '五', 'affiliation' => null];
        $restricted = new RecordCurrentValues(1, '今', [], [], ['restricted' => true, 'viewers' => [$party]], 'v');
        $open = new RecordCurrentValues(1, '今', [], [], ['restricted' => false, 'viewers' => []], 'v');

        [, $w3] = ToolWriteChecks::afterCurrent($this->updater(), ['row_access' => ['viewers' => [$viewer]]], $open);
        [$kept, $w1a] = ToolWriteChecks::afterCurrent($this->updater(), [], $restricted);

        $this->assertSame(ToolRowAccessWrites::VIEWERS_WITHOUT_RESTRICTION, $w3);
        $this->assertNull($w1a);
        $this->assertSame(['restricted' => true, 'viewers' => [$viewer]], $kept['row_access']);
    }

    /**
     * 前の件を指す印の誤り。
     *
     * @return array<string, array{\Closure(self): list<PriorToolCall>, string}>
     */
    public static function linkErrors(): array
    {
        $unknown = '項目 `parent` の {"ref": "a"} が指す作るが無い。指せるのは、同じ応答で前に呼んで確認に出た作るの仮の名前だけ。';

        return [
            '前の作るに無い仮の名前(後ろ・自分を含む)' => [fn (self $test): array => [], $unknown],
            '問うにならなかった作る' => [
                fn (self $test): array => [
                    $test->prior($test->creator(), ['module' => 'm_ref', 'ref' => 'a'], AiToolCallDecision::Deny),
                ],
                $unknown,
            ],
            '形を通らなかった呼び出し' => [
                fn (self $test): array => [new PriorToolCall('p', 'ai_c', null, AiToolCallDecision::InvalidInput)],
                $unknown,
            ],
            '直すの件' => [
                fn (self $test): array => [
                    $test->asked($test->updater(), ['module' => 'm_ref', 'id' => 1, 'ref' => 'a']),
                ],
                $unknown,
            ],
            '参照先と違う業務モジュールの作る' => [
                fn (self $test): array => [$test->asked($test->creator(), ['module' => 'm_w', 'ref' => 'a'])],
                '項目 `parent` の {"ref": "a"} が指す作るの業務モジュール m_w は、項目の参照先 m_ref と違う。',
            ],
        ];
    }

    /**
     * @param  \Closure(self): list<PriorToolCall>  $prior
     */
    #[DataProvider('linkErrors')]
    public function test_a_wrong_link_is_an_input_error(\Closure $prior, string $error): void
    {
        $arguments = ['values' => ['parent' => ['ref' => 'a']], 'row_access' => []];
        [, $actual] = $this->whenCalled($this->creator(), $arguments, $prior($this));

        $this->assertSame($error, $actual);
    }

    /** 重なる仮の名前は B1。同じレコードを直す 2 件目は B1 で、前の直すが断られたら当たらない */
    public function test_a_repeated_name_and_the_same_record_are_input_errors(): void
    {
        $create = ['module' => 'm_ref', 'ref' => 'a'];
        $update = ['module' => 'm_w', 'id' => 7];

        $named = ['ref' => 'a', 'row_access' => []];
        [, $repeated] = $this->whenCalled($this->creator(), $named, [$this->asked($this->creator(), $create)]);
        [, $same] = $this->whenCalled($this->updater(), $update, [$this->asked($this->updater(), $update)]);
        $deniedUpdate = $this->prior($this->updater(), $update, AiToolCallDecision::Deny);
        [, $denied] = $this->whenCalled($this->updater(), $update, [$deniedUpdate]);

        $this->assertSame('仮の名前 `a` は、同じ応答の前の作るが使っている。別の名前にする。', $repeated);
        $this->assertSame('同じ応答の前の直すが、同じレコードを直す。1 つの直すにまとめる。', $same);
        $this->assertNull($denied);
    }

    /** 確認の内容の k は、同じステップの問うの中の順(作るでない問うも数え、問うでない呼び出しは数えない) */
    public function test_the_k_counts_the_asks_of_the_step(): void
    {
        $prior = [
            $this->asked($this->updater(), ['module' => 'm_w', 'id' => 7]),
            $this->prior($this->creator(), ['module' => 'm_ref', 'ref' => 'b'], AiToolCallDecision::Deny),
            $this->asked($this->creator(), ['module' => 'm_ref', 'ref' => 'a']),
        ];
        $arguments = ['module' => 'm_w', 'values' => ['parent' => ['ref' => 'a']]];
        $definition = $this->scope([])->modules['m_w']['definition'];

        $ks = ToolCallLinks::ks($this->creator(), $arguments, $definition, $prior, $this->tools([]));

        $this->assertSame(['a' => 2], $ks);
    }

    public function creator(): GenericCreateTool
    {
        return new GenericCreateTool('ai_c', readOnly: false, action: AiToolAction::Create, parties: self::FORMS);
    }

    public function updater(): GenericUpdateTool
    {
        return new GenericUpdateTool('ai_u', readOnly: false, action: AiToolAction::Update, parties: self::FORMS);
    }

    /**
     * 同じステップの前の、判定が問うの呼び出し。
     *
     * @param  array<string, mixed>  $arguments
     */
    public function asked(FakeRecordTool $tool, array $arguments): PriorToolCall
    {
        return $this->prior($tool, $arguments, AiToolCallDecision::Ask);
    }

    /**
     * @param  array<string, mixed>  $arguments
     */
    public function prior(FakeRecordTool $tool, array $arguments, AiToolCallDecision $decision): PriorToolCall
    {
        return new PriorToolCall('p'.$tool->name, $tool->name, $arguments, $decision);
    }

    /**
     * @param  array<string, mixed>  $arguments  業務モジュール(省くと m_w)と書く値
     * @param  list<PriorToolCall>  $prior
     * @param  array<string, bool>  $defaults
     * @return array{array<string, mixed>, string|null}
     */
    private function whenCalled(
        FakeRecordTool $tool,
        array $arguments,
        array $prior = [],
        array $defaults = ['m_w' => false, 'm_ref' => false],
    ): array {
        $arguments = ['module' => 'm_w', ...$arguments];

        return ToolWriteChecks::whenCalled($tool, $arguments, $this->scope($defaults), $prior, $this->tools($defaults));
    }

    /**
     * 渡した道具(作る・直す・読む)。
     *
     * @param  array<string, bool>  $defaults
     */
    private function tools(array $defaults): GrantedTools
    {
        return new GrantedTools(array_map(
            fn (FakeRecordTool $granted): GrantedTool => new GrantedTool($granted, $this->scope($defaults)),
            [$this->creator(), $this->updater(), new GenericTool('ai_r')],
        ), []);
    }

    /**
     * @param  array<string, bool>  $defaults
     */
    private function scope(array $defaults): AgentToolScope
    {
        $field = fn (string $code, string $type, array $extra = []): array => [
            'code' => $code,
            'type' => $type,
            ...$extra,
        ];
        $reference = ['referencedModule' => ['code' => 'm_ref', 'name' => '参照先']];
        $definition = ['fields' => [
            $field('title', 'string'),
            $field('amount', 'integer'),
            $field('rate', 'decimal'),
            $field('due', 'date'),
            $field('status', 'choice', ['options' => [
                ['code' => 'open', 'name' => '開'],
                ['code' => 'done', 'name' => '済'],
            ]]),
            $field('parent', 'record', $reference),
            $field('refs', 'record', [...$reference, 'isMultiple' => true]),
            $field('lines', 'details', ['childModule' => ['fields' => [$field('item', 'string')]]]),
        ]];
        $modules = [
            'm_w' => ['name' => '書く先', 'definition' => $definition],
            'm_ref' => ['name' => '参照先', 'definition' => ['fields' => []]],
        ];

        return new AgentToolScope($modules, $modules, $defaults + ['m_w' => false, 'm_ref' => false]);
    }
}
