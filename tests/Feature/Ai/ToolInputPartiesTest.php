<?php

namespace Tests\Feature\Ai;

use App\Actions\Ai\Api\AgentToolParties;
use App\Actions\Ai\Api\AgentToolScope;
use App\Actions\Ai\Api\RecordCurrentValues;
use App\Actions\Ai\ToolCalls\PartyCandidates;
use App\Actions\Ai\ToolCalls\ToolPartyNames;
use App\Actions\Ai\ToolCalls\ToolPartyResolution;
use App\Actions\Ai\ToolCalls\ToolSubjects;
use App\Enums\Ai\AiToolAction;
use App\Enums\Ai\AiToolPartyForm;
use App\Models\Ai\AiRun;
use App\Models\Master\Department;
use App\Models\Master\User;
use Carbon\CarbonImmutable;
use Tests\Feature\Ai\Tools\FakeRecordTool;
use Tests\Feature\Ai\Tools\Generic\GenericCreateTool;
use Tests\Feature\Ai\Tools\Generic\GenericUpdateTool;
use Tests\Support\TransactionalTestCase;

/**
 * 書く道具の入れ物の入力の形の相手(docs の 設計/AI/道具と権限/README.md 10.2 のユーザー・組織の名前と id の確かめ): 作るの id は
 * 確かめ(有効・無効・無い)、直すの id は呼ばれた時に確かめず 1 段目の後に今の値(欄ごと)に無い相手だけを確かめる(B19)。名前の物は完全一致
 * 1 件で決まり、0 件・2 件以上・部分一致だけ・上限・どちらでもない形は B1。型「ユーザー」「組織」「ユーザーと組織」・複数の値・見せる人・明細の行で
 * 通す。今の値に残す退職者を含む全体を渡しても B19 にならない。
 */
final class ToolInputPartiesTest extends TransactionalTestCase
{
    use ArrangesToolPermissions;
    use BuildsToolRecords;

    /** 引数の鍵と形(作る・直すの本物の道具と同じ置き方) */
    private const FORMS = [
        'values' => AiToolPartyForm::Input,
        'details' => AiToolPartyForm::InputDetails,
        'row_access' => AiToolPartyForm::InputRowAccess,
    ];

    private Department $general;

    private User $ichiro;

    private User $retired;

    private AiRun $run;

    private AgentToolScope $scope;

    protected function setUp(): void
    {
        parent::setUp();
        $this->travelTo(CarbonImmutable::parse('2026-10-11 12:00', 'Asia/Tokyo'));
        $this->arrangeRequester();
        $this->general = Department::query()->where('code', 'general-affairs')->sole();
        $this->ichiro = $this->userNamedOver([['2020-01-01', null, '書込 一郎']]);
        $this->join($this->ichiro, $this->general, isMain: true);
        $this->userNamedOver([['2020-01-01', null, '書込 一郎二']]);
        $this->retired = $this->userNamedOver([['2020-01-01', '2025-12-31', '退職 次郎']]);
        $this->run = $this->runFor([], []);
        $field = fn (string $code, string $type, bool $multiple = false): array => [
            'code' => $code,
            'type' => $type,
            'isMultiple' => $multiple,
            'isSearchable' => false,
        ];
        $definition = ['code' => 'm_w', 'name' => '書く先', 'fields' => [
            $field('charge', 'user'),
            $field('members', 'user', true),
            $field('section', 'department'),
            $field('party', 'user_or_department'),
            $field('title', 'string'),
            ['code' => 'lines', 'type' => 'details', 'childModule' => ['fields' => [$field('worker', 'user')]]],
        ]];
        $this->scope = new AgentToolScope(['m_w' => ['name' => '書く先', 'definition' => $definition]]);
    }

    /** 作るの id の形: 有効な相手は通り、コードと名前と所属が相手の列に入る */
    public function test_a_create_id_of_an_effective_party_passes_with_its_names(): void
    {
        $values = ['charge' => $this->ichiro->id, 'section' => $this->general->id];
        $resolution = $this->resolve($this->creator(), $values);

        $this->assertNull($resolution->error);
        $this->assertSame([
            ['field' => '項目 `charge`', 'type' => 'user', 'id' => $this->ichiro->id, 'code' => $this->ichiro->code,
                'name' => '書込 一郎', 'affiliation' => '総務部'],
            ['field' => '項目 `section`', 'type' => 'department', 'id' => $this->general->id, 'code' => 'general-affairs',
                'name' => '総務部', 'affiliation' => null],
        ], $resolution->parties->parties);
    }

    /** 作るの id の形: 有効でない・無い相手は B1(見せる人・明細の行・複数の値の要素ごと) */
    public function test_a_create_id_of_an_ineffective_or_missing_party_is_an_input_error(): void
    {
        $resolution = $this->resolve($this->creator(), ['members' => [$this->ichiro->id, $this->retired->id]], [
            'lines' => [['values' => ['worker' => 999999]]],
        ], ['viewers' => [['type' => 'department', 'id' => 999998]]]);

        $this->assertSame(implode("\n", [
            "項目 `members` のユーザー {$this->retired->id} は、今日は有効でないか無い。",
            '明細 `lines` の 1 行目の項目 `worker` のユーザー 999999 は、今日は有効でないか無い。',
            '見せる人 の組織 999998 は、今日は有効でないか無い。',
        ]), $resolution->error);
        $this->assertSame([], $resolution->parties->parties);
    }

    /** 名前の物は完全一致の 1 件で決まり、入力の形の id に直る(型「ユーザーと組織」と見せる人は種類つき) */
    public function test_a_name_resolves_to_the_input_id_form(): void
    {
        $resolution = $this->resolve($this->creator(), [
            'charge' => ['name' => '書込 一郎'],
            'party' => ['name' => '総務部'],
        ], [], ['viewers' => [['name' => '書込 一郎']]]);

        $this->assertNull($resolution->error);
        $this->assertSame($this->ichiro->id, $resolution->arguments['values']['charge']);
        $arguments = $resolution->arguments;
        $this->assertSame(['type' => 'department', 'id' => $this->general->id], $arguments['values']['party']);
        $this->assertSame([['type' => 'user', 'id' => $this->ichiro->id]], $arguments['row_access']['viewers']);
    }

    /** 名前の物が決まらない(0 件・部分一致だけ)と、どちらでもない形は B1。決まらない要素だけを返し、利用者に選んでもらう文を足す */
    public function test_an_undecided_name_and_a_wrong_form_are_input_errors(): void
    {
        $resolution = $this->resolve($this->creator(), [
            'charge' => ['name' => '書込'],
            'section' => ['name' => 'どこにも無い部'],
            'party' => 5,
        ]);

        $lines = explode("\n", (string) $resolution->error);
        $this->assertSame('項目 `charge` の名前「書込」の相手が 1 つに決まらない。候補:', $lines[0]);
        $this->assertContains("- `{$this->ichiro->id}` 書込 一郎(ユーザー。所属: 総務部)", $lines);
        $this->assertContains('項目 `section` の名前「どこにも無い部」に当たる相手がいない。', $lines);
        $this->assertContains('項目 `party` の相手の値の形が違う。相手は id の形 {"type": "user" か "department", "id": <id>} か、'
            .'名前の物 {"name": "…"} で書く。', $lines);
        $this->assertSame(ToolPartyNames::CHOOSE, end($lines));
    }

    /** 型「ユーザーと組織」の候補は入力の形の値で返す */
    public function test_the_candidates_of_a_user_or_department_show_the_input_form(): void
    {
        $resolution = $this->resolve($this->creator(), ['party' => ['name' => '書込']]);

        $this->assertStringContainsString(
            "- `{\"type\": \"user\", \"id\": {$this->ichiro->id}}` 書込 一郎",
            (string) $resolution->error,
        );
    }

    /** 件数が上限に達した名前は、完全一致があっても決まらない */
    public function test_a_name_at_the_limit_is_not_decided(): void
    {
        for ($i = 1; $i < PartyCandidates::LIMIT; $i++) {
            $this->userNamedOver([['2020-01-01', null, "書込 一郎{$i}"]]);
        }

        $resolution = $this->resolve($this->creator(), ['charge' => ['name' => '書込 一郎']]);

        $this->assertStringStartsWith('項目 `charge` の名前「書込 一郎」の相手が 1 つに決まらない。', (string) $resolution->error);
    }

    /** 直すの id の形は呼ばれた時に確かめない(無い id でも通り、相手の列は空) */
    public function test_an_update_id_is_not_checked_when_called(): void
    {
        $resolution = $this->resolve($this->updater(), ['charge' => 999999]);

        $this->assertNull($resolution->error);
        $this->assertSame([], $resolution->parties->parties);
    }

    /**
     * 直すの 1 段目の後: 今の値(欄ごと)にある相手は確かめず(退職者を含む全体を渡しても通る)、今の値に無い相手だけを確かめて読む。
     * 名前で決めた相手も、id が同じ欄の今の値にあれば今の値の側になる。
     */
    public function test_after_the_current_values_only_the_added_parties_are_checked(): void
    {
        $current = $this->current(['members' => [self::shown($this->retired)]], [
            'lines' => [['id' => 7, 'values' => ['worker' => self::shown($this->retired)]]],
        ], [['type' => 'user', 'id' => $this->retired->id, 'code' => $this->retired->code, 'label' => null,
            'affiliation' => null]]);
        $arguments = $this->arguments([
            'members' => [$this->retired->id, $this->ichiro->id],
            'charge' => $this->retired->id,
        ], ['lines' => [['id' => 7, 'values' => ['worker' => $this->retired->id]]]], [
            'viewers' => [['type' => 'user', 'id' => $this->retired->id]],
        ]);

        [$parties, $error] = $this->afterCurrent($arguments, $current);

        $this->assertSame("項目 `charge` のユーザー {$this->retired->id} は、今日は有効でないか無い。", $error);

        $arguments['values']['charge'] = $this->ichiro->id;
        [$parties, $error] = $this->afterCurrent($arguments, $current);

        $this->assertNull($error);
        $this->assertSame([['項目 `charge`', $this->ichiro->id], ['項目 `members`', $this->ichiro->id]], array_map(
            fn (array $party): array => [$party['field'], $party['id']],
            $parties->parties,
        ));
    }

    /** 1 段目の後の歩き直しの誤り(形の誤り)は、捨てずに呼ぶ時の確かめと同じ文(B1)になる */
    public function test_an_error_of_the_walk_after_the_current_values_is_not_dropped(): void
    {
        $current = $this->current([], []);
        $arguments = $this->arguments(['party' => 5], []);

        [$parties, $error] = $this->afterCurrent($arguments, $current);

        $this->assertSame([], $parties->parties);
        $this->assertSame('項目 `party` の相手の値の形が違う。相手は id の形 {"type": "user" か "department", "id": <id>} か、'
            .'名前の物 {"name": "…"} で書く。', $error);
    }

    /** 明細の新しい行と、今の値に無い id の行の相手は、今の値を持たないので確かめる */
    public function test_a_new_detail_row_has_no_current_values(): void
    {
        $row = ['id' => 7, 'values' => ['worker' => self::shown($this->retired)]];
        $current = $this->current([], ['lines' => [$row]]);
        $arguments = $this->arguments([], ['lines' => [
            ['values' => ['worker' => $this->retired->id]],
            ['id' => 8, 'values' => ['worker' => $this->retired->id]],
        ]]);

        [, $error] = $this->afterCurrent($arguments, $current);

        $this->assertSame(implode("\n", [
            "明細 `lines` の 1 行目の項目 `worker` のユーザー {$this->retired->id} は、今日は有効でないか無い。",
            "明細 `lines` の 2 行目の項目 `worker` のユーザー {$this->retired->id} は、今日は有効でないか無い。",
        ]), $error);
    }

    private function creator(): GenericCreateTool
    {
        return new GenericCreateTool('ai_c', readOnly: false, action: AiToolAction::Create, parties: self::FORMS);
    }

    private function updater(): GenericUpdateTool
    {
        return new GenericUpdateTool('ai_u', readOnly: false, action: AiToolAction::Update, parties: self::FORMS);
    }

    /**
     * @param  array<string, mixed>  $values
     * @param  array<string, mixed>  $details
     * @param  array<string, mixed>|null  $rowAccess
     */
    private function resolve(
        FakeRecordTool $tool,
        array $values,
        array $details = [],
        ?array $rowAccess = null,
    ): ToolPartyResolution {
        return app(ToolPartyNames::class)->resolve(
            $tool,
            $this->arguments($values, $details, $rowAccess),
            $this->scope,
            $this->run,
        );
    }

    /**
     * @param  array<string, mixed>  $arguments
     * @return array{AgentToolParties, string|null}
     */
    private function afterCurrent(array $arguments, RecordCurrentValues $current): array
    {
        $subject = app(ToolSubjects::class)->for($this->run);

        return app(ToolPartyNames::class)->afterCurrent($this->updater(), $arguments, $this->scope, $current, $subject);
    }

    /**
     * @param  array<string, mixed>  $values
     * @param  array<string, mixed>  $details
     * @param  array<string, mixed>|null  $rowAccess
     * @return array<string, mixed>
     */
    private function arguments(array $values, array $details = [], ?array $rowAccess = null): array
    {
        return [
            'module' => 'm_w',
            'values' => $values,
            ...($details === [] ? [] : ['details' => $details]),
            ...($rowAccess === null ? [] : ['row_access' => $rowAccess]),
        ];
    }

    /**
     * @param  array<string, mixed>  $values
     * @param  array<string, list<array{id: int, values: array<string, mixed>}>>  $details
     * @param  list<array{type: 'user'|'department', id: int, code: string|null, label: string|null,
     *     affiliation: string|null}>  $viewers
     */
    private function current(array $values, array $details = [], array $viewers = []): RecordCurrentValues
    {
        return new RecordCurrentValues(1, '今のレコード', $values, $details, [
            'restricted' => $viewers !== [],
            'viewers' => $viewers,
        ], 'v1');
    }

    /**
     * 今の値の相手(画面の初期値の形。名前の無い退職者)。
     *
     * @return array{id: int, code: string, label: null, affiliation: null}
     */
    private static function shown(User $user): array
    {
        return ['id' => $user->id, 'code' => $user->code, 'label' => null, 'affiliation' => null];
    }
}
