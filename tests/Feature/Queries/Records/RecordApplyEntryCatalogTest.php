<?php

namespace Tests\Feature\Queries\Records;

use App\Models\Records\Record;
use App\Queries\Authz\Api\Requester;
use App\Queries\Records\Api\RecordApplyEntries;
use App\Queries\Records\Api\RecordApplyEntry;
use App\Queries\Records\RecordApplyEntryCatalog;
use App\Queries\Workflow\WorkflowRecordApplyEntries;
use Carbon\CarbonImmutable;
use DateTimeInterface;
use Tests\Support\TransactionalTestCase;

/**
 * レコードから申請できる入口を束ねる 1 か所(docs の 設計/ワークフロー/申請の周辺/既存のデータからの申請.md の 2 節): 起動がワークフローの実装をタグで
 * 登録し、束ねる 1 か所はタグの実装を登録の順に呼んで結果をつなぐ。
 */
final class RecordApplyEntryCatalogTest extends TransactionalTestCase
{
    private int $registered = 0;

    public function test_the_workflow_implementation_is_registered_with_the_tag(): void
    {
        $implementations = iterator_to_array($this->app->tagged(RecordApplyEntries::TAG), false);

        $classes = array_map(fn (object $implementation) => $implementation::class, $implementations);
        $this->assertSame([WorkflowRecordApplyEntries::class], $classes);
    }

    public function test_the_entries_of_the_implementations_are_joined_in_the_registration_order(): void
    {
        $missingRecordId = (int) Record::query()->max('id') + 1000;
        $this->register([new RecordApplyEntry('2 番目', null, '/second')]);
        $this->register([new RecordApplyEntry('3 番目', '説明', '/third'), new RecordApplyEntry('4 番目', null, '/fourth')]);
        $this->register([]);

        $catalog = app(RecordApplyEntryCatalog::class);

        $entries = $catalog->entriesFor(new Requester(null, null), $missingRecordId, self::day());

        $this->assertSame(['2 番目', '3 番目', '4 番目'], array_map(fn (RecordApplyEntry $entry) => $entry->name, $entries));
        $urls = array_map(fn (RecordApplyEntry $entry) => $entry->url, $entries);
        $this->assertSame(['/second', '/third', '/fourth'], $urls);
    }

    public function test_without_implementations_there_is_no_entry(): void
    {
        $catalog = new RecordApplyEntryCatalog([]);

        $this->assertSame([], $catalog->entriesFor(new Requester(null, null), 1, self::day()));
    }

    private static function day(): CarbonImmutable
    {
        return CarbonImmutable::parse('2026-10-13');
    }

    /**
     * 起動と同じタグで、決めた入口を返すテストの実装を登録の順で後ろに足す。
     *
     * @param  list<RecordApplyEntry>  $entries
     */
    private function register(array $entries): void
    {
        $name = 'test.record-apply-entries.'.$this->registered++;
        $this->app->instance($name, new readonly class($entries) implements RecordApplyEntries
        {
            /** @param  list<RecordApplyEntry>  $entries */
            public function __construct(private array $entries) {}

            public function entriesFor(Requester $requester, int $recordId, DateTimeInterface $date): array
            {
                return $this->entries;
            }
        });
        $this->app->tag([$name], RecordApplyEntries::TAG);
    }
}
