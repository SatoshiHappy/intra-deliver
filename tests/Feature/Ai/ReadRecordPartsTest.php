<?php

namespace Tests\Feature\Ai;

use App\Actions\Ai\Tools\ReadRecord;
use App\Actions\Records\Api\CreateRecord;
use App\Actions\Records\Api\RecordDetailRowInput;
use App\Actions\Records\Api\RecordInput;
use App\Enums\Ai\AiToolCallDecision;
use App\Enums\Records\RecordFieldType;
use App\Models\Ai\AiToolCallText;
use App\Queries\Ai\Api\RecordMarkers;
use Carbon\CarbonImmutable;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\Support\TransactionalTestCase;

/**
 * 本物の読むの返す部分と明細の行のページ(docs の 設計/AI/道具と権限/README.md 10.2 の読むの返す部分・明細の行のページ): 返す部分は値・明細・
 * コメント・更新履歴・添付から選び(省くと全部。知らない値は形の誤り)、選ばない部分の口は呼ばない。印・id・表示の名前・作成と更新の日時と人・
 * 参照制限は部分によらず返す。明細の行は表ごとに詳細の画面と同じ順で 1 ページ 20 行、表ごとの総数と最後のページを添え、最後の先のページは空。
 */
final class ReadRecordPartsTest extends TransactionalTestCase
{
    use BuildsToolRecords;
    use RunsRecordTools;

    /** 部分によらず返す鍵 */
    private const ALWAYS_RETURNED = [
        'record', 'id', 'name', 'createdAt', 'createdBy', 'updatedAt', 'updatedBy', 'rowAccess',
    ];

    protected function setUp(): void
    {
        parent::setUp();
        $this->travelTo(CarbonImmutable::parse('2026-10-11 12:00', 'Asia/Tokyo'));
        $this->arrangeRequester();
        $this->arrangeTasks();
        $this->taskFields['subs'] = $this->detailsField($this->tasks, 'subs', ['name' => '副明細']);
        $this->field($this->childOf($this->taskFields['subs']), 'item', RecordFieldType::String, ['name' => '品目']);
        $this->logRecordReads();
        $this->startRecordToolRun(['m_tasks']);
    }

    /**
     * 返す部分を 1 つずつ。各行 = 部分・呼ぶ口(閲覧の判定の後)。
     *
     * @return array<string, array{string, list<string>}>
     */
    public static function parts(): array
    {
        return [
            '値' => ['values', ['detail']],
            '明細' => ['details', ['detail']],
            'コメント' => ['comments', ['detail', 'comments']],
            '更新履歴' => ['revisions', ['detail', 'revisions']],
            '添付' => ['attachments', ['detail', 'forRecord']],
        ];
    }

    /**
     * @param  list<string>  $operations
     */
    #[DataProvider('parts')]
    public function test_one_part_returns_it_with_the_always_returned_items(string $part, array $operations): void
    {
        $id = $this->task(['title' => '件名'], ['品目 1']);

        $body = self::body($this->callTool('ai_read_record', ['module' => 'm_tasks', 'id' => $id, 'parts' => [$part]]));

        $this->assertSame([...self::ALWAYS_RETURNED, $part], array_keys($body));
        $this->assertSame(['canView', ...$operations], $this->operations());
    }

    /** 部分を省くと全部を、部分の並びで返す */
    public function test_omitting_the_parts_returns_all_of_them(): void
    {
        $id = $this->task(['title' => '件名']);

        $body = self::body($this->callTool('ai_read_record', ['module' => 'm_tasks', 'id' => $id]));

        $this->assertSame([...self::ALWAYS_RETURNED, ...ReadRecord::PARTS], array_keys($body));
        $this->assertSame(['canView', 'detail', 'comments', 'revisions', 'forRecord'], $this->operations());
    }

    /** 値を省いても、印・記録の名前・作成と更新の日時と人・参照制限が残る */
    public function test_the_marker_the_name_and_the_record_facts_remain_without_the_values(): void
    {
        $viewers = [['type' => 'user', 'id' => $this->requester->id]];
        $id = $this->task(['title' => '件名'], [], true, $viewers);

        $arguments = ['module' => 'm_tasks', 'id' => $id, 'parts' => ['comments']];
        $body = self::body($this->callTool('ai_read_record', $arguments));

        $marker = RecordMarkers::of('m_tasks', $id);
        $this->assertSame([$marker, $id, '件名'], [$body['record'], $body['id'], $body['name']]);
        $this->assertSame($this->writer->id, $body['createdBy']['id']);
        $this->assertSame($this->writer->id, $body['updatedBy']['id']);
        $this->assertNotNull($body['createdAt']);
        $this->assertNotNull($body['updatedAt']);
        $rowAccess = $body['rowAccess'];
        $this->assertSame([true, $this->requester->id], [$rowAccess['restricted'], $rowAccess['viewers'][0]['id']]);
        $names = AiToolCallText::query()->where('ai_tool_call_id', $this->toolRow()->id)->sole()->record_names;
        $this->assertSame(['件名'], array_column((array) $names, 'name'));
    }

    /** 知らない部分の値は形の誤りで、道具を呼ばない */
    public function test_an_unknown_part_is_an_argument_error(): void
    {
        $id = $this->task(['title' => '件名']);

        $this->callTool('ai_read_record', ['module' => 'm_tasks', 'id' => $id, 'parts' => ['values', 'files']]);

        $this->assertSame(AiToolCallDecision::InvalidInput, $this->toolRow()->decision);
        $this->assertSame([], $this->reads->calls);
    }

    /**
     * 明細の行のページ(境界の 19・20・21 行、最後の先、行の数の違う 2 つの表)。各行 = 明細の行の数・副明細の行の数・ページ・
     * 表ごとの [返す行の数, 総数, 今のページ, 最後のページ]。
     *
     * @return array<string, array{int, int, int, list<list<int>>}>
     */
    public static function detailPages(): array
    {
        return [
            '19 行' => [19, 0, 1, [[19, 19, 1, 1], [0, 0, 1, 1]]],
            '20 行' => [20, 0, 1, [[20, 20, 1, 1], [0, 0, 1, 1]]],
            '21 行の 1 ページ目' => [21, 0, 1, [[20, 21, 1, 2], [0, 0, 1, 1]]],
            '21 行の 2 ページ目' => [21, 0, 2, [[1, 21, 2, 2], [0, 0, 2, 1]]],
            '最後の先のページは空' => [21, 0, 3, [[0, 21, 3, 2], [0, 0, 3, 1]]],
            '行の数の違う 2 つの表' => [21, 3, 2, [[1, 21, 2, 2], [0, 3, 2, 1]]],
        ];
    }

    /**
     * @param  list<list<int>>  $tables
     */
    #[DataProvider('detailPages')]
    public function test_detail_rows_have_twenty_a_page_for_each_table(
        int $lines,
        int $subs,
        int $page,
        array $tables,
    ): void {
        $id = $this->taskWithDetails($lines, $subs);

        $arguments = ['module' => 'm_tasks', 'id' => $id, 'parts' => ['details'], 'details_page' => $page];
        $details = self::body($this->callTool('ai_read_record', $arguments))['details'];

        $this->assertSame(['lines', 'subs'], array_column($details, 'code'));
        $shape = array_map(
            fn (array $table): array => [
                count($table['rows']), $table['total'], $table['currentPage'], $table['lastPage'],
            ],
            $details,
        );
        $this->assertSame($tables, $shape);

        if ($lines === 21 && $page === 2) {
            $this->assertSame('明細 21', $details[0]['rows'][0]['values'][0]['value']);
        }
    }

    /** writer が書く、2 つの明細の表に行を持つ m_tasks のレコード */
    private function taskWithDetails(int $lines, int $subs): int
    {
        $rows = fn (string $prefix, int $count): array => array_map(
            fn (int $index): RecordDetailRowInput => new RecordDetailRowInput(values: ['item' => "{$prefix} {$index}"]),
            $count === 0 ? [] : range(1, $count),
        );
        $input = new RecordInput(['title' => '明細あり'], ['lines' => $rows('明細', $lines), 'subs' => $rows('副', $subs)]);

        return app(CreateRecord::class)->execute($this->contextFor($this->writer->id), 'm_tasks', $input);
    }

    /**
     * 呼んだ読む口の操作の名前(呼んだ順)。
     *
     * @return list<string>
     */
    private function operations(): array
    {
        return array_map(fn (string $call): string => explode('::', $call)[1] ?? '', $this->reads->calls);
    }
}
