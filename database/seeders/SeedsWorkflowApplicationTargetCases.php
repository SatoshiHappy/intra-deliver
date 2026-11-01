<?php

namespace Database\Seeders;

use App\Actions\Records\Api\RecordDetailRowInput;
use App\Actions\Records\Api\RecordInput;
use App\Actions\Workflow\ApplicationInput;
use App\Enums\Records\RecordFieldType;
use App\Models\Records\Record;
use App\Models\Records\RecordField;
use Illuminate\Database\Eloquent\Collection;

/**
 * {@see WorkflowSeeder} の申請対象からの申請の見本(docs の 設計/ワークフロー/申請の周辺/初期データ.md の 2 節)。使う側は申請・承認の業務処理と、
 * applicationInputOf()・projectIdOf()・approveAs()・contextAt()・seedingDay() を持つ。
 */
trait SeedsWorkflowApplicationTargetCases
{
    /** 申請対象にするプロジェクト */
    private const APPLICATION_TARGET_PROJECT = '新規顧客開拓キャンペーン';

    /**
     * 操作の時刻は基準の日に書いた値(設計/ワークフロー/案件の運用/初期データ.md 1.2)。
     */
    private function createApplicationTargetCases(): void
    {
        $base = $this->seedingDay()->toDateString();
        $projectId = $this->projectIdOf(self::APPLICATION_TARGET_PROJECT);
        $comment = '新規顧客開拓キャンペーンの計画を申請します。';
        $seventeenth = $this->applyForProject('2026-09-24 09:00', $projectId, $comment, $base);
        $this->approveAs('2026-09-24 15:00', 'u0002', $seventeenth, '内容を確認しました。', ['u0009', 'u0010']);
        $this->approveAs('2026-09-25 10:00', 'u0003', $seventeenth, null);
        $this->approveAs('2026-09-26 11:00', 'u0009', $seventeenth, null);
        $this->approveAs('2026-09-26 14:00', 'u0010', $seventeenth, null);
        $this->approveAs('2026-09-27 10:00', 'u0003', $seventeenth, null);

        $this->applyForProject('2026-10-13 09:30', $projectId, null, $base);
    }

    /**
     * u0001 がプロジェクトを申請対象にして申請する。プロジェクトのレコードは書かない(設計/ワークフロー/初期データ.md の 4 節)。
     *
     * @return int 案件の id
     */
    private function applyForProject(string $at, int $projectId, ?string $comment, string $baseDate): int
    {
        $context = $this->contextAt($at, 'u0001');
        $values = $this->currentInputOf($projectId);
        $record = $this->applicationInputOf(WorkflowApplicationModules::PLAN, $values, $projectId);

        $input = new ApplicationInput(null, $comment, $record);

        return $this->apply->execute($context, 'project_plan', $input, $baseDate)->caseId;
    }

    /**
     * レコードの今の値と明細の行を永続化の層から読む(seeder は読み取りの層を呼べない)。
     */
    private function currentInputOf(int $recordId): RecordInput
    {
        $record = Record::query()->findOrFail($recordId);
        $details = [];

        foreach ($this->fieldsOf($record) as $field) {
            if ($field->type !== RecordFieldType::Details) {
                continue;
            }

            $rows = Record::query()->where('parent_record_id', $record->id)
                ->where('record_module_id', $field->child_record_module_id)->orderBy('sort_key')->orderBy('id')->get();
            $details[$field->code] = array_values($rows->map(
                fn (Record $row) => new RecordDetailRowInput(values: $this->storedValuesOf($row)),
            )->all());
        }

        return new RecordInput($this->storedValuesOf($record), $details);
    }

    /**
     * 行の項目のコード → 今の値(明細の項目を除く)。
     *
     * @return array<string, mixed>
     */
    private function storedValuesOf(Record $record): array
    {
        $values = [];

        foreach ($this->fieldsOf($record) as $field) {
            if ($field->type === RecordFieldType::Details) {
                continue;
            }

            $values[$field->code] = match (true) {
                $field->type->isReference() => $record->referenceValue($field),
                $field->type === RecordFieldType::Boolean => (bool) ($record->field_values[$field->id] ?? false),
                default => $record->field_values[$field->id] ?? null,
            };
        }

        return $values;
    }

    /**
     * 行の業務モジュールの項目(定義の並び)。
     *
     * @return Collection<int, RecordField>
     */
    private function fieldsOf(Record $record): Collection
    {
        return RecordField::query()->where('record_module_id', $record->record_module_id)
            ->orderBy('sort_key')->orderBy('id')->get();
    }
}
