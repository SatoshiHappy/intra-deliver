<?php

namespace Tests\Feature\Ai;

use App\Actions\Ai\Api\AgentToolResult;
use App\Actions\Ai\Tools\SearchIgnored;
use App\Enums\Ai\AiInstructionTemplate;
use App\Enums\Ai\AiToolCallStatus;
use App\Models\Ai\AiToolCallText;
use App\Models\Records\RecordModule;
use App\Queries\Ai\Api\RecordMarkers;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\Support\TransactionalTestCase;

/**
 * 本物の探す(docs の 設計/AI/道具と権限/README.md 10.2、表 1 の B4 = 設計/AI/道具と権限/道具の判定と今の権限.md 10.3): 行と件数とページは行の判定で
 * 絞った後の数。1 ページ 20 件。値を返す項目に表示の項目を足す。口が黙って外した条件・並び・項目を結果で返す(どれを外すかの正本は一覧の口)。
 * 行ごとの印と道具の行の読んだレコードがページの行とそろう。判定の後の競合は、見つからない例外が見つからない、権限の例外が包みの失敗の結果。
 */
final class SearchRecordsTest extends TransactionalTestCase
{
    use BuildsToolRecords;
    use RunsRecordTools;

    protected function setUp(): void
    {
        parent::setUp();
        $this->travelTo(CarbonImmutable::parse('2026-10-11 12:00', 'Asia/Tokyo'));
        $this->arrangeRequester();
        $this->arrangeTasks();
        $this->logRecordReads();
        $this->startRecordToolRun(['m_tasks']);
    }

    public function test_rows_and_counts_are_narrowed_by_the_row_check(): void
    {
        $visible = [$this->storedTask(['title' => 'a']), $this->storedTask(['title' => 'b'])];
        $this->storedTask(['title' => 'hidden'], restricted: true);

        $body = self::body($this->callTool('ai_search_records', ['module' => 'm_tasks']));

        $this->assertSame([2, 1, 1], [$body['total'], $body['currentPage'], $body['lastPage']]);
        $this->assertSame(array_reverse($visible), array_column($body['records'], 'id'));
        $this->assertSame(['RecordListQuery::list'], $this->reads->calls);
    }

    /**
     * ページ(件数 20 と 21 の境、21 件で 1 未満・1・最後・最後より後)。各行 = 件数・求めたページ・今のページ・最後のページ・行の数。
     *
     * @return array<string, array{int, int, int, int, int}>
     */
    public static function pages(): array
    {
        return [
            '20 件は 1 ページ' => [20, 1, 1, 1, 20],
            '21 件の 1 ページ目' => [21, 1, 1, 2, 20],
            '21 件の 2 ページ目(最後)' => [21, 2, 2, 2, 1],
            '最後より後は最後' => [21, 3, 2, 2, 1],
            '0 は 1' => [21, 0, 1, 2, 20],
            '負は 1' => [21, -1, 1, 2, 20],
        ];
    }

    #[DataProvider('pages')]
    public function test_a_page_has_twenty_rows_and_is_clamped(
        int $count,
        int $page,
        int $current,
        int $last,
        int $rows,
    ): void {
        for ($index = 0; $index < $count; $index++) {
            $this->storedTask(['title' => "t{$index}"]);
        }

        $body = self::body($this->callTool('ai_search_records', ['module' => 'm_tasks', 'page' => $page]));

        $this->assertSame([$count, $current, $last], [$body['total'], $body['currentPage'], $body['lastPage']]);
        $this->assertCount($rows, $body['records']);
    }

    /** 表示の項目は一覧の項目でなくても足す(渡した項目にも、渡さないときの一覧の項目にも) */
    public function test_the_title_field_is_added_to_the_given_or_the_listed_fields(): void
    {
        $this->task(['title' => '件名', 'status' => 'open', 'amount' => 3, 'note' => 'メモ']);

        $listed = self::body($this->callTool('ai_search_records', ['module' => 'm_tasks']));
        $arguments = ['module' => 'm_tasks', 'fields' => ['amount']];
        $given = self::body($this->callTool('ai_search_records', $arguments, 'c2'));

        $listedKeys = self::sortedKeys($listed['records'][0]['values']);
        $this->assertSame(['amount', 'owner', 'ref', 'status', 'title'], $listedKeys);
        $values = $given['records'][0]['values'];
        $this->assertSame(['amount', 'title'], self::sortedKeys($values));
        $this->assertSame(['件名', 3], [$values['title'], $values['amount']]);
    }

    /**
     * 口が外した物(無いコード・条件に使えない項目・選択肢に無い値の条件、返す項目に無い・参照の型の並び、無いコード・明細の項目)。
     * 各行 = 引数・外した物(無ければ鍵を出さない)・当たる件名。
     *
     * @return array<string, array{array<string, mixed>, array<string, mixed>|null, list<string>}>
     */
    public static function ignoredCases(): array
    {
        return [
            '条件・並び・項目を外す' => [
                [
                    'conditions' => ['nope' => 'x', 'note' => 'x', 'status' => 'open'],
                    'sort' => 'note',
                    'fields' => ['nope', 'lines', 'amount'],
                ],
                ['conditions' => ['nope', 'note'], 'sort' => 'note', 'fields' => ['nope', 'lines']],
                ['開いた'],
            ],
            '選択肢に無い値の条件' => [['conditions' => ['status' => 'zzz']], ['conditions' => ['status']], ['閉じた', '開いた']],
            '参照の型の並び' => [['sort' => 'owner', 'fields' => ['owner']], ['sort' => 'owner'], ['閉じた', '開いた']],
            '当てた物は出さない' => [
                [
                    'conditions' => ['status' => 'open', 'amount' => ['from' => '1']],
                    'sort' => '-amount',
                    'fields' => ['amount'],
                ],
                null,
                ['開いた'],
            ],
        ];
    }

    /**
     * @param  array<string, mixed>  $arguments
     * @param  array<string, mixed>|null  $ignored
     * @param  list<string>  $titles
     */
    #[DataProvider('ignoredCases')]
    public function test_what_the_list_dropped_is_returned(array $arguments, ?array $ignored, array $titles): void
    {
        $this->task(['title' => '開いた', 'status' => 'open', 'amount' => 5, 'note' => 'x']);
        $this->task(['title' => '閉じた', 'status' => 'done', 'amount' => 0, 'note' => 'x']);

        $body = self::body($this->callTool('ai_search_records', ['module' => 'm_tasks', ...$arguments]));

        $this->assertSame($ignored, $body['ignored'] ?? null);
        $this->assertSame($titles, array_map(fn (array $row) => $row['values']['title'], $body['records']));
    }

    /**
     * 範囲の型の条件の外した物(表 = {@see SearchIgnored})。各行 = 金額の条件の値・外した物(無ければ null)・当たる件名。
     *
     * @return array<string, array{mixed, list<string>|null, list<string>}>
     */
    public static function rangeCases(): array
    {
        $both = ['閉じた', '開いた'];

        return [
            '文字列' => ['5', ['amount'], $both],
            '数' => [5, ['amount'], $both],
            '列' => [['1', '9'], ['amount'], $both],
            '空のオブジェクト' => [[], ['amount'], $both],
            'from も to も無い(ほかの鍵も出さない)' => [['min' => '1'], ['amount'], $both],
            '送って読めた側は当てる' => [['from' => '1'], null, ['開いた']],
            '片側だけ読めない' => [['from' => 'x', 'to' => '3'], ['amount.from'], ['閉じた']],
            '送った片側が読めない' => [['to' => 'x'], ['amount.to'], $both],
            '両側とも読めない' => [['from' => 'x', 'to' => 'y'], ['amount'], $both],
            'ほかの鍵' => [['from' => '1', 'max' => '3'], ['amount.max'], ['開いた']],
            '両側とも読めない・ほかの鍵' => [['from' => 'x', 'to' => 'y', 'max' => '3'], ['amount', 'amount.max'], $both],
        ];
    }

    /**
     * @param  list<string>|null  $ignored
     * @param  list<string>  $titles
     */
    #[DataProvider('rangeCases')]
    public function test_what_a_range_condition_dropped_is_returned(mixed $value, ?array $ignored, array $titles): void
    {
        $this->task(['title' => '開いた', 'status' => 'open', 'amount' => 5]);
        $this->task(['title' => '閉じた', 'status' => 'done', 'amount' => 0]);

        $arguments = ['module' => 'm_tasks', 'conditions' => ['amount' => $value]];
        $body = self::body($this->callTool('ai_search_records', $arguments));

        $this->assertSame($ignored, $body['ignored']['conditions'] ?? null);
        $this->assertSame($titles, array_map(fn (array $row) => $row['values']['title'], $body['records']));
    }

    /** 実行の途中に表示の項目を替えても、探すと読むは実行の始めの表示の項目で同じレコードに同じ名前を付ける */
    public function test_search_and_read_name_a_record_by_the_title_field_of_the_run_start(): void
    {
        $id = $this->task(['title' => '件名の値']);
        $this->taskFields['title']->update(['is_title' => false]);
        $this->field($this->tasks, 'subject', attributes: ['name' => '題', 'is_title' => true]);

        $this->callTool('ai_search_records', ['module' => 'm_tasks']);
        $read = self::body($this->callTool('ai_read_record', ['module' => 'm_tasks', 'id' => $id], 'c2'));

        foreach (['c1', 'c2'] as $callId) {
            $row = $this->toolRow($callId);
            $names = AiToolCallText::query()->where('ai_tool_call_id', $row->id)->sole()->record_names;
            $this->assertSame(['件名の値'], array_column((array) $names, 'name'));
        }

        $this->assertSame('件名の値', $read['name']);
    }

    /** 行ごとの印と、道具の行の読んだレコードと名前が、ページの行とそろう(名前 = 画面の見出しと同じ表示の項目の値か #<id>) */
    public function test_the_markers_and_the_read_records_follow_the_rows(): void
    {
        $named = $this->task(['title' => '名前あり']);
        $unnamed = $this->task([]);

        $content = $this->callTool('ai_search_records', ['module' => 'm_tasks']);

        $markers = array_column(self::body($content)['records'], 'record');
        $this->assertSame([RecordMarkers::of('m_tasks', $unnamed), RecordMarkers::of('m_tasks', $named)], $markers);
        $read = [['module' => 'm_tasks', 'id' => $unnamed], ['module' => 'm_tasks', 'id' => $named]];
        $this->assertEquals($read, $this->toolRow()->records);
        $names = AiToolCallText::query()->where('ai_tool_call_id', $this->toolRow()->id)->sole()->record_names;
        $this->assertSame(["#{$unnamed}", '名前あり'], array_column((array) $names, 'name'));
        $this->assertSame($markers, array_map(
            fn (array $marker): string => RecordMarkers::of($marker['module'], $marker['id']),
            RecordMarkers::extract((string) $this->fullText()),
        ));
    }

    /** 見えない参照先は #<id>、ユーザーの名前は基準日の名前(画面と同じ) */
    public function test_a_hidden_reference_is_its_id_and_a_user_has_the_name_of_the_day(): void
    {
        $owner = $this->userNamedOver([['2020-01-01', '2026-10-10', '旧姓 花子'], ['2026-10-11', null, '新姓 花子']]);
        $refId = $this->refRecord();
        $this->task(['title' => '参照あり', 'owner' => $owner->id, 'ref' => $refId]);

        $values = self::body($this->callTool('ai_search_records', ['module' => 'm_tasks']))['records'][0]['values'];

        $this->assertSame(['新姓 花子', "#{$refId}"], [$values['owner']['label'], $values['ref']['label']]);
    }

    public function test_no_row_is_a_body_with_a_zero_count_and_not_the_empty_result(): void
    {
        $content = $this->callTool('ai_search_records', ['module' => 'm_tasks']);

        $this->assertNull($content['template']);
        $this->assertSame(['total' => 0, 'currentPage' => 1, 'lastPage' => 1, 'records' => []], self::body($content));
    }

    /** 判定の後に業務モジュールが消えた競合(見つからない例外)は見つからない */
    public function test_a_module_vanishing_after_the_judgement_is_not_found(): void
    {
        $missing = (new ModelNotFoundException)->setModel(RecordModule::class);
        $this->reads->before['RecordListQuery::list'] = fn () => throw $missing;

        $content = $this->callTool('ai_search_records', ['module' => 'm_tasks']);

        $this->assertSame(['error' => AgentToolResult::NOT_FOUND], self::body($content));
        $this->assertSame(AiToolCallStatus::Done, $this->toolRow()->status);
    }

    /** 判定の後に閲覧を外された競合(権限の例外)は捕まえず、包みの失敗の結果 */
    public function test_a_view_revoked_after_the_judgement_is_a_tool_failure(): void
    {
        $this->reads->before['RecordListQuery::list'] = fn () => $this->revokeRole();

        $content = $this->callTool('ai_search_records', ['module' => 'm_tasks']);

        $failure = [$content['template'], $content['is_error']];
        $this->assertSame([AiInstructionTemplate::ToolError->value, true], $failure);
        $this->assertSame(AiToolCallStatus::ToolError, $this->toolRow()->status);
    }

    /**
     * @param  array<string, mixed>  $values
     * @return list<string>
     */
    private static function sortedKeys(array $values): array
    {
        $keys = array_map(strval(...), array_keys($values));
        sort($keys);

        return $keys;
    }
}
