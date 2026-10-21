<?php

namespace Tests\Feature\Actions\Records;

use App\Actions\Records\Api\PurgeRecordMedia;
use App\Actions\Upload\Api\StoreUploads;
use App\Enums\Records\RecordFieldType;
use App\Models\Records\Record;
use App\Models\Upload\Upload;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Database\Events\QueryExecuted;
use Illuminate\Support\Facades\DB;
use LogicException;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\Support\BuildsCommonMaster;
use Tests\Support\BuildsPurgeTargets;
use Tests\Support\BuildsRecordModules;
use Tests\Support\BuildsUploads;
use Tests\Support\TransactionalTestCase;

/**
 * 期限の削除の口の確かめ(docs の 設計/業務データの入れ物/レコードの共通機能/添付ファイル/レコードの添付.md の 1.1 節の表):
 * 基準日は東京の日付で「より前」だけ消す、作りの誤りは LogicException、親の無いレコードだけ(404)、鍵は共有 → 行で台帳の鍵は取らない。
 */
final class PurgeRecordMediaGuardTest extends TransactionalTestCase
{
    use BuildsCommonMaster;
    use BuildsPurgeTargets;
    use BuildsRecordModules;
    use BuildsUploads;

    private bool $recording = false;

    protected function setUp(): void
    {
        parent::setUp();
        CarbonImmutable::setTestNow('2026-10-21 03:00:00 UTC');
        $this->fakeUploadDisk();
        $this->limitUploads(1_000, 3_000);
        $this->meetingsModule();
    }

    /**
     * @return array<string, array{string, string, int}> 期限の値・基準日(UTC)・消す数
     */
    public static function boundaries(): array
    {
        return [
            'the day before the base day in Tokyo' => ['2026-10-20', '2026-10-20 15:00:00 UTC', 1],
            'the same day in Tokyo (UTC is the day before)' => ['2026-10-20', '2026-10-20 14:59:59 UTC', 0],
            'the same day' => ['2026-10-21', '2026-10-21 03:00:00 UTC', 0],
            'a later day' => ['2026-10-22', '2026-10-21 03:00:00 UTC', 0],
        ];
    }

    #[DataProvider('boundaries')]
    public function test_only_a_due_date_before_the_tokyo_day_of_the_base_date_is_purged(
        string $due,
        string $base,
        int $count,
    ): void {
        $recordId = $this->meeting($due, [$this->uploadedFile('a.mp3', 'mp3')]);

        $this->assertSame($count, $this->purge($recordId, $base));
    }

    /**
     * @return array<string, array{string, string}>
     */
    public static function designErrors(): array
    {
        return [
            'a missing module' => ['missing', 'purge_on'],
            'a module of details' => ['rows_rows', 'purge_on'],
            'a missing field' => ['meetings', 'missing'],
            'a datetime field' => ['meetings', 'held_at'],
            'a string field' => ['meetings', 'title'],
        ];
    }

    #[DataProvider('designErrors')]
    public function test_a_design_error_is_refused(string $moduleCode, string $fieldCode): void
    {
        $this->field($this->meetings, 'held_at', RecordFieldType::Datetime);
        $this->detailsField($this->meetings, 'rows');
        $recordId = $this->meeting('2026-10-20', [$this->uploadedFile('a.mp3', 'mp3')]);

        $this->expectException(LogicException::class);
        app(PurgeRecordMedia::class)->execute($moduleCode, $recordId, $fieldCode, CarbonImmutable::now());
    }

    public function test_a_missing_record_a_record_of_another_module_and_a_detail_row_are_not_found(): void
    {
        $child = $this->childOf($this->detailsField($this->meetings, 'rows'));
        $other = Record::factory()->create(['record_module_id' => $this->recordModule('notes')->id]);
        $parent = Record::factory()->create(['record_module_id' => $this->meetings->id]);
        $row = Record::factory()->create([
            'record_module_id' => $child->id, 'parent_record_module_id' => $this->meetings->id,
            'parent_record_id' => $parent->id,
        ]);

        foreach ([$parent->id + 1000, $other->id, $row->id] as $id) {
            try {
                $this->purge($id);
                $this->fail("{$id} が 404 にならなかった");
            } catch (ModelNotFoundException) {
                $this->addToAssertionCount(1);
            }
        }
    }

    public function test_it_takes_the_module_lock_then_the_row_lock_and_never_the_ledger_lock(): void
    {
        $recordId = $this->meeting('2026-10-20', [$this->uploadedFile('a.mp3', 'mp3')]);
        $locks = [];
        $this->recording = true;
        DB::listen(function (QueryExecuted $query) use (&$locks) {
            $sql = $query->sql;
            $locks[] = match (true) {
                ! $this->recording => null,
                str_contains($sql, 'pg_advisory_xact_lock_shared(') => 'module',
                str_contains($sql, 'for no key update') && str_contains($sql, 'from "records"') => 'row',
                str_contains($sql, 'pg_advisory_xact_lock(')
                    && $query->bindings === [StoreUploads::LEDGER_LOCK_KEY] => 'ledger',
                default => null,
            };
        });

        $this->purge($recordId);
        $this->recording = false;

        $this->assertSame(['module', 'row'], array_values(array_filter($locks)));
        $this->assertSame(1, Upload::query()->whereNotNull('purged_at')->count());
    }
}
