<?php

namespace App\Actions\Workflow;

use App\Actions\Records\Api\AttachRecordUploads;
use App\Enums\Workflow\WorkflowAction;
use App\Enums\Workflow\WorkflowOperation;
use App\Models\Records\Record;
use App\Models\Records\RecordModule;
use App\Models\Workflow\WorkflowCaseNode;
use App\Models\Workflow\WorkflowFlow;
use App\Queries\Workflow\CaseActor;
use App\Queries\Workflow\PrincipalContext;
use App\Queries\Workflow\WorkflowRecordHolds;
use App\Support\AccessContext;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Http\UploadedFile;
use Illuminate\Validation\ValidationException;
use InvalidArgumentException;
use LogicException;
use RuntimeException;

/**
 * ステップの処理(承認の系統・引戻し・取止め)で付ける添付の 1 か所(docs の 設計/ワークフロー/申請の周辺/添付/書く順と検査.md の 6 節、
 * 可否 = 設計/ワークフロー/申請の周辺/添付/README.md の 1.1 節・2 節)。可否は入力の規則の所で、口は判定と鍵の後・履歴を書く前に呼ぶ。
 * 添付が無ければフローも読まず、口を呼ばない(処理はレコードを書かない。添付の無い処理の問い合わせを増やさない)。
 */
class CaseStepAttachments
{
    public function __construct(
        private CaseAttachmentRules $rules,
        private ApplicationRecords $records,
        private AttachRecordUploads $attachUploads,
    ) {}

    /**
     * 送った添付があり、可否が偽なら拒む(判定の順の 7 = README.md の 3.2 節)。可否を読んだフローの行を返し、{@see self::attach()} に渡す
     * (同じ処理でフローを引き直さないため)。
     *
     * @param  WorkflowCaseNode  $node  操作するノード(引戻しは引戻す先、取止めは申請のノード)
     * @param  list<UploadedFile>  $files
     * @return WorkflowFlow|null 案件のフローの行(添付が無ければ読まずに null)
     *
     * @throws ValidationException 可否が偽のとき(attachments)
     */
    public function rejectFiles(
        WorkflowOperation $operation,
        CaseOperationPermit $permit,
        WorkflowCaseNode $node,
        array $files,
    ): ?WorkflowFlow {
        if ($files === []) {
            return null;
        }

        $case = $permit->case();
        $flow = WorkflowFlow::query()->findOrFail($case->workflow_flow_id);
        $this->rules->rejectFiles($operation, $flow, $files, $case, $node, $permit->situation);

        return $flow;
    }

    /**
     * 持ち主の鍵の形で添付を付け、口が書いた版の id を返す(付けないときは null = 履歴の行は版を指さない)。
     * 口の $context は処理者、契機の実行者は代理のときの実行者(上げた人・付けた人 = 実行者)。
     *
     * @param  WorkflowCaseNode  $node  操作するノード(契機のステップ名)
     * @param  WorkflowFlow|null  $flow  {@see self::rejectFiles()} が返したフローの行
     * @param  list<UploadedFile>  $files
     *
     * @throws ModelNotFoundException<RecordModule|Record> 業務モジュール・レコードが無いとき
     * @throws AuthorizationException 持ち主の鍵が効かないとき(口の判定)
     * @throws ValidationException 持ち主(record)・使用しない業務モジュール・ファイルの誤り(attachments・attachments.<添字>)
     * @throws InvalidArgumentException 口へ渡す値の形が違うとき(呼び手の誤り)
     * @throws LogicException フローの行が無いとき・未完了の案件がレコードを指していないとき・版が無いとき(呼び手の誤り)
     * @throws RuntimeException 実体を書けなかったとき
     */
    public function attach(
        CaseOperationPermit $permit,
        CaseActor $actor,
        AccessContext $context,
        WorkflowAction $action,
        WorkflowCaseNode $node,
        ?WorkflowFlow $flow,
        array $files,
    ): ?int {
        if ($files === []) {
            return null;
        }

        $flow ??= throw new LogicException('添付を付けるのに可否を読んだフローの行が無い');
        $case = $permit->case();
        $recordId = $case->record_id ?? throw new LogicException("未完了の案件 {$case->id} がレコードを指していない");
        $principal = PrincipalContext::of($context, $actor->processorUserId);
        $moduleCode = $this->records->moduleCodeOf($flow);
        $origin = $this->records->origin($action, $node->displayName(), $actor);
        $holder = WorkflowRecordHolds::holderOf($case->id);
        $this->attachUploads->execute($principal, $moduleCode, $recordId, $files, $holder, $origin);

        return $this->records->revisionOf($recordId);
    }
}
