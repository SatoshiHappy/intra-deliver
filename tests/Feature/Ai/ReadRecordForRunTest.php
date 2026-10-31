<?php

namespace Tests\Feature\Ai;

use App\Actions\Ai\Api\ReadRecordForRun;
use App\Actions\Ai\CompletingRun;
use App\Enums\Records\RecordModuleAction;
use App\Models\Ai\AiRun;
use App\Models\Records\Record;
use App\Models\Records\RecordModule;
use App\Queries\Records\Api\RecordShowUnchecked;
use App\Support\AccessContext;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use LogicException;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\Support\BuildsCommonMaster;
use Tests\Support\BuildsRecordModules;
use Tests\Support\RowAccessRestriction;
use Tests\Support\TransactionalTestCase;

/**
 * 実行の対象のレコードを読む口(docs の 設計/AI/公開の口.md 3.3、ADR 0029 の決定 5): 完了の受け手の中・処理中・対象を持つ実行だけが呼べ、
 * 依頼者がレコードを見られなくても読む(形は入れ物の判定しない読み取りと同じ)。対象が消されていれば無し、明細の行なら 404。
 */
final class ReadRecordForRunTest extends TransactionalTestCase
{
    use BuildsCommonMaster;
    use BuildsRecordModules;

    private const MODULE = 'ai_notes';

    /**
     * @param  'no-read'|'hidden-row'|'inactive'  $case
     */
    #[DataProvider('requestersWhoCannotView')]
    public function test_reads_the_target_even_when_the_requester_cannot_view_it(string $case): void
    {
        $record = $this->noteRecord($case === 'no-read' ? [RecordModuleAction::Create] : null);

        if ($case === 'hidden-row') {
            RowAccessRestriction::restrict([$record->record_module_id]);
        }

        $requester = $case === 'inactive' ? $this->user([['2020-01-01', '2021-01-01']]) : $this->user();
        $runId = $this->runOn($record->id, $requester->id);

        $read = $this->readDuringComplete($runId);

        $today = CarbonImmutable::today(AccessContext::TIMEZONE);
        $shown = app(RecordShowUnchecked::class)->show($record->id, $today, AccessContext::LOCALE_CODE);
        $this->assertSame($shown, $read);
        $this->assertSame('元の要約', $read['record']['values']['summary'] ?? null);
    }

    /**
     * @return array<string, array{'no-read'|'hidden-row'|'inactive'}>
     */
    public static function requestersWhoCannotView(): array
    {
        return [
            '業務モジュールの read を持たない' => ['no-read'],
            '行の判定で見えない(参照制限のあるレコードで関係者でない)' => ['hidden-row'],
            '読む時の当日に有効でない' => ['inactive'],
        ];
    }

    public function test_a_deleted_target_is_none(): void
    {
        $record = $this->noteRecord();
        $runId = $this->runOn($record->id, $this->user()->id);
        Record::query()->whereKey($record->id)->delete();

        $this->assertNull($this->readDuringComplete($runId));
    }

    public function test_a_details_row_as_the_target_is_not_found(): void
    {
        $record = $this->noteRecord();
        $rows = $this->detailsField(RecordModule::query()->findOrFail($record->record_module_id), 'rows');
        $row = Record::factory()->create([
            'record_module_id' => $rows->child_record_module_id,
            'parent_record_module_id' => $record->record_module_id,
            'parent_record_id' => $record->id,
        ]);
        $runId = $this->runOn($row->id, $this->user()->id);

        $this->assertThrows(fn () => $this->readDuringComplete($runId), ModelNotFoundException::class);
    }

    /**
     * 完了の受け手の外・処理中でない実行・対象を持たない実行は作りの誤り。
     */
    public function test_cannot_be_called_outside_complete_for_a_run_not_running_or_without_a_target(): void
    {
        $record = $this->noteRecord();
        $running = $this->runOn($record->id, $this->user()->id);
        $queued = AiRun::factory()->create($this->targetOf($record->id, $this->user()->id))->id;
        $withoutTarget = AiRun::factory()->running()->create()->id;

        $outside = fn () => app(ReadRecordForRun::class)->execute($running);
        $this->assertThrows($outside, LogicException::class, '完了の受け手の中');
        $this->assertThrows(fn () => $this->readDuringComplete($queued), LogicException::class, '処理中でない');
        $this->assertThrows(
            fn () => $this->readDuringComplete($withoutTarget),
            LogicException::class,
            '対象のレコードを持たない',
        );
    }

    /**
     * @return array<string, mixed>|null
     */
    private function readDuringComplete(int $runId): ?array
    {
        $read = null;
        app(CompletingRun::class)->during($runId, function () use ($runId, &$read): void {
            $read = app(ReadRecordForRun::class)->execute($runId);
        });

        return $read;
    }

    private function runOn(int $recordId, int $requesterUserId): int
    {
        return AiRun::factory()->running()->create($this->targetOf($recordId, $requesterUserId))->id;
    }

    /**
     * @return array<string, mixed>
     */
    private function targetOf(int $recordId, int $requesterUserId): array
    {
        return [
            'requester_user_id' => $requesterUserId,
            'record_id' => $recordId,
            'original_record_id' => $recordId,
            'record_module_code' => self::MODULE,
        ];
    }

    /**
     * 業務モジュール ai_notes(項目 summary)と、summary が「元の要約」のレコード(作成者なし)。
     *
     * @param  list<RecordModuleAction>|null  $permitted
     */
    private function noteRecord(?array $permitted = null): Record
    {
        $module = $this->recordModule(self::MODULE, $permitted);
        $summary = $this->field($module, 'summary');

        return Record::factory()->create([
            'record_module_id' => $module->id,
            'field_values' => [$summary->id => '元の要約'],
        ]);
    }
}
