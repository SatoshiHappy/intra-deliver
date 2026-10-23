<?php

namespace Tests\Feature\Queries\Records;

use App\Enums\Records\RecordModuleAction;
use App\Models\Records\Record;
use App\Queries\Authz\Api\Requester;
use App\Queries\Records\RecordListQuery;
use App\Queries\Records\RecordPageQuery;
use Carbon\CarbonImmutable;
use Tests\Support\BuildsRecordModules;
use Tests\Support\TransactionalTestCase;

/**
 * 業務の一覧のページ番号(一覧の 3 つが共有する 1 か所 = App\Support\PageNumbers): 最後のページより大きいページは最後のページを返し、
 * 件数 0 なら 1 ページ目。読めないページは 1。
 */
final class RecordListPageTest extends TransactionalTestCase
{
    use BuildsRecordModules;

    public function test_a_page_past_the_last_page_returns_the_last_page(): void
    {
        $module = $this->recordModule('projects');
        $records = Record::factory()->count(21)->create(['record_module_id' => $module->id, 'field_values' => []]);
        $last = $this->list('projects', '5')['records'];

        $this->assertSame([2, 2, 21], [$last['currentPage'], $last['lastPage'], $last['total']]);
        $this->assertSame([$records->modelKeys()[0]], array_column($last['data'], 'id'));

        foreach (['0', '-1', '1.5', 'abc', '1000000000'] as $page) {
            $this->assertSame(1, $this->list('projects', $page)['records']['currentPage'], $page);
        }
    }

    public function test_an_empty_list_returns_the_first_page(): void
    {
        $this->recordModule('projects');
        $empty = $this->list('projects', '3')['records'];

        $this->assertSame([1, 1, 0, []], [$empty['currentPage'], $empty['lastPage'], $empty['total'], $empty['data']]);
    }

    /**
     * @return array{records: array{data: list<array{id: int}>, currentPage: int, lastPage: int, total: int}}
     */
    private function list(string $moduleCode, string $page): array
    {
        $requester = new Requester(null, null);
        $date = CarbonImmutable::parse('2026-10-11');
        $opened = app(RecordPageQuery::class)->open($requester, $moduleCode, $date, RecordModuleAction::Read);

        return app(RecordListQuery::class)->list($requester, $opened, [], null, $page, $date, 'ja');
    }
}
