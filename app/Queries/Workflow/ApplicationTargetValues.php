<?php

namespace App\Queries\Workflow;

use App\Enums\Records\RecordModuleAction;
use App\Models\Records\RecordModule;
use App\Models\Workflow\WorkflowFlow;
use App\Queries\Authz\Api\Requester;
use App\Queries\Records\Api\RecordDisplayQuery;
use App\Queries\Records\Api\RecordFormQuery;
use App\Queries\Records\Api\RecordViewAccess;
use App\Support\MixedValues;
use DateTimeInterface;
use LogicException;

/**
 * 申請対象の写しの値を申請の画面の初期値の形で作る(docs の 設計/ワークフロー/申請の周辺/既存のデータからの申請/申請対象からの申請.md の 10.3 節)。
 * 申請の画面の GET と写しの値の入口が同じ口を通るので、主体の判定をしない値の口は閲覧の判定の後にしか呼ばれない。写した後の値には、写す先の
 * 項目の宣言で写す時の確かめ(写しの初期値の口の「値から」= 10.1 の写す時の確かめ)を当て、今は使えない値を空にして clearedFields に名前を返す。
 *
 * @phpstan-type ApplicationTarget array{field: string, module: string}
 * @phpstan-type ApplicationTargetRow array{values: array<string, mixed>}
 * @phpstan-type ApplicationTargetValueSet array{values: array<string, mixed>,
 *     details: array<string, list<ApplicationTargetRow>>, clearedFields: list<string>}
 */
class ApplicationTargetValues
{
    public function __construct(
        private RecordFormQuery $forms,
        private RecordViewAccess $viewAccess,
        private RecordDisplayQuery $display,
        private ApplicationTargetSnapshot $snapshots,
    ) {}

    /**
     * コンテンツの申請対象の項目のコードと参照先の業務モジュールのコード(申請対象が無ければ null)。
     * 渡されたフローのモデルに関連を読む(同じモデルを渡す呼び手の間では、項目の行を引き直さない)。
     *
     * @return ApplicationTarget|null
     */
    public function applicationTargetOf(WorkflowFlow $flow): ?array
    {
        $flow->loadMissing('workflowContent.targetRecordField');
        $content = $flow->workflowContent ?? throw new LogicException("フロー {$flow->code} のコンテンツが無い");
        $field = $content->targetRecordField;
        $moduleId = $content->target_record_module_id;

        if ($field === null || $moduleId === null) {
            return null;
        }

        $moduleCode = RecordModule::query()->whereKey($moduleId)->value('code');

        return ['field' => $field->code, 'module' => (string) $moduleCode];
    }

    /**
     * 判定を求める者が申請対象を閲覧できるときだけ写しの値(できなければ null)。
     *
     * @param  ApplicationTarget  $applicationTarget
     * @param  array<string, mixed>  $applicationDefinition  申請書の業務モジュールの定義(呼び手が開けるアクションで引いたもの)
     * @param  int|null  $writtenRecordId  写す先の申請書のレコード(そのものを申請する・再申請。ほかは null = 10.3 の写す先のレコードの id)
     * @return ApplicationTargetValueSet|null
     */
    public function valuesFor(
        Requester $requester,
        array $applicationTarget,
        array $applicationDefinition,
        int $applicationTargetId,
        ?int $writtenRecordId,
        DateTimeInterface $date,
        string $localeCode,
    ): ?array {
        $moduleCode = $applicationTarget['module'];

        if (! $this->viewAccess->canView($requester, $moduleCode, $applicationTargetId, $date)) {
            return null;
        }

        $sourceDefinition = $this->forms->definition($requester, $moduleCode, RecordModuleAction::Read, $date);
        $read = $this->forms->editValues($requester, $moduleCode, $applicationTargetId, $date, $localeCode);
        $label = $this->display->titleOf($applicationTargetId);
        $reference = ['id' => $applicationTargetId, 'code' => null, 'label' => $label, 'affiliation' => null];
        $snapshot = $this->snapshots->snapshot(
            $applicationDefinition, $sourceDefinition, $read['values'], $read['details'],
            $applicationTarget['field'], $reference,
        );

        return $this->forms->copyFromValues(
            $requester, MixedValues::stringOf($applicationDefinition['code'] ?? null), $writtenRecordId,
            $snapshot['values'], $snapshot['details'], $date, $localeCode,
        );
    }

    /**
     * 申請書のレコードの申請対象の項目の値(参照の値)が指すレコードの写しの値。空・閲覧できなければ null。
     *
     * @param  ApplicationTarget  $applicationTarget
     * @param  array<string, mixed>  $applicationDefinition
     * @param  array<string, mixed>  $values  申請書のレコードの値(画面の初期値の形)
     * @param  int  $writtenRecordId  申請書のレコード(写す先)
     * @return ApplicationTargetValueSet|null
     */
    public function valuesOfRecord(
        Requester $requester,
        array $applicationTarget,
        array $applicationDefinition,
        array $values,
        int $writtenRecordId,
        DateTimeInterface $date,
        string $localeCode,
    ): ?array {
        $applicationTargetId = $this->applicationTargetIdOf($applicationTarget, $values);

        return $applicationTargetId === null
            ? null
            : $this->valuesFor(
                $requester, $applicationTarget, $applicationDefinition, $applicationTargetId, $writtenRecordId,
                $date, $localeCode,
            );
    }

    /**
     * 申請書のレコードの値から読む申請対象のレコードの id(項目が空なら null)。参照の値の `id` だけを読むので、画面の初期値の形と表示の形のどちらでもよい。
     *
     * @param  ApplicationTarget  $applicationTarget
     * @param  array<string, mixed>  $values
     */
    public function applicationTargetIdOf(array $applicationTarget, array $values): ?int
    {
        $reference = $values[$applicationTarget['field']] ?? null;

        return is_array($reference) && is_int($reference['id'] ?? null) ? $reference['id'] : null;
    }
}
