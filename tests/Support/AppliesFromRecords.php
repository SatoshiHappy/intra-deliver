<?php

namespace Tests\Support;

use App\Actions\Records\Api\CreateRecord;
use App\Actions\Records\Api\RecordDetailRowInput;
use App\Actions\Records\Api\RecordInput;
use App\Actions\Records\Api\UpdateRecord;
use App\Actions\Workflow\ApplicationInput;
use App\Actions\Workflow\ApplyCase;
use App\Actions\Workflow\ApproveCase;
use App\Models\Master\User;
use App\Models\Records\Record;
use App\Models\Records\RecordField;
use App\Models\Records\RecordModule;
use App\Models\Records\RecordRevision;
use App\Models\Workflow\WorkflowCase;
use App\Models\Workflow\WorkflowCaseHistory;
use Closure;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Validation\ValidationException;

/**
 * 既存のデータからの申請のテストの手助け(docs の 設計/ワークフロー/申請の周辺/既存のデータからの申請.md の 8 節)。業務処理は本物を HTTP なしで呼ぶ。
 * フローは {@see RunsWorkflowOperations} の既定(業務モジュール project・承認 1 = u0002・承認 2 = u0003)。レコードは業務の画面と同じ口で作る。
 */
trait AppliesFromRecords
{
    use RunsWorkflowOperations;

    /** テストのフローのコンテンツの業務モジュール */
    private const PROJECT = 'project';

    /**
     * 業務の画面で登録したレコード(マイルストーン 1 行。版 1 = 登録)。
     *
     * @param  array<string, mixed>  $values  上書きする値
     */
    protected function projectRecord(User $user, array $values = []): Record
    {
        $input = $this->projectInput($values)->record;
        $id = app(CreateRecord::class)->execute($this->contextOf($user), self::PROJECT, $input);

        return Record::query()->findOrFail($id);
    }

    /**
     * 既存のデータから申請する(開いたときの版の既定 = 今の版。申請基準日 = 操作の基準日)。
     */
    protected function applyFromRecord(
        User $user,
        int $recordId,
        ?ApplicationInput $input = null,
        ?User $principal = null,
        ?string $version = null,
        ?string $baseDate = null,
    ): WorkflowCase {
        $context = $this->contextOf($user);
        $version ??= Record::query()->findOrFail($recordId)->version();
        [$input, $baseDate] = [$input ?? $this->projectInput(), $baseDate ?? $context->date->toDateString()];
        $result = app(ApplyCase::class)
            ->executeFromRecord($context, self::FLOW_CODE, $recordId, $version, $input, $baseDate, $principal?->id);

        return WorkflowCase::query()->findOrFail($result->caseId);
    }

    /**
     * 業務の画面からの更新(持ち主の鍵なし・契機なし)。
     *
     * @param  array<string, mixed>  $values
     * @param  array<string, list<RecordDetailRowInput>>  $details
     */
    protected function updateFromScreen(
        User $user,
        int $recordId,
        array $values,
        array $details = [],
        ?string $version = null,
    ): void {
        $version ??= Record::query()->findOrFail($recordId)->version();
        $input = new RecordInput($values, $details);
        app(UpdateRecord::class)->execute($this->contextOf($user), self::PROJECT, $recordId, $input, $version);
    }

    /**
     * 承認 1・承認 2 を承認して完了させる。
     */
    protected function complete(WorkflowCase $case): void
    {
        $this->operate(ApproveCase::class, $this->seededUser('u0002'), $case);
        $this->operate(ApproveCase::class, $this->seededUser('u0003'), $case);
    }

    /**
     * レコードの項目の値(業務モジュールの項目のコードで引く)。
     */
    protected function valueOf(int $recordId, string $code): mixed
    {
        $record = Record::query()->findOrFail($recordId);
        $fields = RecordField::query()->where('record_module_id', $record->record_module_id);
        $fieldId = $fields->where('code', $code)->sole()->id;

        return $record->field_values[$fieldId] ?? null;
    }

    /**
     * @return list<int> レコードの明細の行の id(並びの順)
     */
    protected function detailRowIds(int $recordId): array
    {
        $rows = Record::query()->where('parent_record_id', $recordId)->orderBy('sort_key');

        return array_values($rows->pluck('id')->all());
    }

    /**
     * @return list<int> レコードの版の id(古い順)
     */
    protected function revisionIds(int $recordId): array
    {
        $revisions = RecordRevision::query()->where('record_id', $recordId)->orderBy('revision');

        return array_values($revisions->pluck('id')->all());
    }

    /**
     * @return list<int|null> 案件の履歴の行が指す版(id の順)
     */
    protected function historyRevisionIds(WorkflowCase $case): array
    {
        $histories = WorkflowCaseHistory::query()->where('workflow_case_id', $case->id)->orderBy('id');

        return array_values($histories->pluck('record_revision_id')->all());
    }

    protected function moduleIdOf(string $code): int
    {
        return RecordModule::query()->where('code', $code)->sole()->id;
    }

    /**
     * 操作の結果(success・404・403・422:<キー>)。
     *
     * @param  Closure(): mixed  $operate
     */
    protected function outcome(Closure $operate): string
    {
        try {
            $operate();

            return 'success';
        } catch (ModelNotFoundException) {
            return '404';
        } catch (AuthorizationException) {
            return '403';
        } catch (ValidationException $exception) {
            return '422:'.implode(',', array_keys($exception->errors()));
        }
    }
}
