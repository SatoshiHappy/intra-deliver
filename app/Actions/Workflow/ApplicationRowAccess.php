<?php

namespace App\Actions\Workflow;

use App\Actions\Records\Api\RecordInput;
use App\Models\Workflow\WorkflowCase;
use App\Models\Workflow\WorkflowFlow;
use App\Queries\Authz\Api\Requester;
use App\Queries\Records\Api\RecordFormQuery;
use App\Queries\Records\Api\RecordViewAccess;
use App\Queries\Workflow\ApplicationTargetValues;
use App\Queries\Workflow\CopiedRowAccess;
use App\Support\AccessContext;

/**
 * 申請・再申請が書き込みの口へ渡す参照制限を決める 1 か所(docs の 設計/ワークフロー/申請の周辺/既存のデータからの申請/申請対象からの申請.md の
 * 10.1 節の送る参照制限。申請対象の有無によらず、新規・一時保存からの申請・そのものを申請する・再申請のどれもここを通る)。
 * 偽を送らないのは、付いたチェックを外す書き込みを持たないため。
 * 申請対象の行に鍵を取らないので、書き込みの口と同じトランザクションの中の、入力の規則の後・口の前に呼ぶ。
 * 登録の経路は案件からの複製の元の印と参照者もここで得る(読み方 = 案件からの複製/写す値.md の 13.2 節)。参照者は書く時に有効な相手へ外し直す。
 *
 * @phpstan-import-type CopiedParty from CopiedRowAccess
 *
 * @phpstan-type RowAccessSend array{restricted: true|null, viewers: list<CopiedParty>|null}
 */
class ApplicationRowAccess
{
    public function __construct(
        private ApplicationTargetValues $targets,
        private RecordViewAccess $viewAccess,
        private RecordFormQuery $forms,
        private CopiedRowAccess $copied,
    ) {}

    /**
     * 登録の経路(新規の申請・一時保存からの申請)。元の印と参照者 = 新規の申請は送り返した暗号化した値(写す人 = 今の利用者)、
     * 一時保存からの申請は一時保存の入力のサーバーだけが書くキー(画面が送った暗号化した値は捨てる)。
     *
     * @param  AccessContext  $context  今の利用者
     * @param  AccessContext  $applicant  書き込みの口に渡す申請者(代理のときは代理元)
     * @param  WorkflowCase|null  $draft  一時保存からの申請の一時保存(新規の申請は null)
     * @return RowAccessSend
     */
    public function forRegistration(
        AccessContext $context,
        AccessContext $applicant,
        WorkflowFlow $flow,
        ApplicationInput $input,
        ?WorkflowCase $draft,
    ): array {
        $copied = $draft === null
            ? $this->copied->open($input->copyRowAccess, $context->userId, $flow->id)
            : $this->copied->ofDraft($draft->draft_input);

        if ($copied !== null && $copied['restricted']) {
            $viewers = $this->forms->effectiveViewers($copied['viewers'], $applicant->date);

            return ['restricted' => true, 'viewers' => $viewers];
        }

        $target = $this->targets->applicationTargetOf($flow);
        $id = $target === null ? null : self::idOf($input->record->values[$target['field']] ?? null);

        return ['restricted' => $this->decide($applicant, $target, $id), 'viewers' => null];
    }

    /**
     * 更新の経路(そのものを申請する・再申請)。申請書のレコードの今の値は、主体の判定をしない値の口で読む(申請者が作成者で、
     * 呼び手が閲覧を確かめたレコードのため)。
     *
     * @param  AccessContext  $applicant  書き込みの口に渡す申請者(代理のときは代理元)
     */
    public function forUpdate(
        AccessContext $applicant,
        WorkflowFlow $flow,
        RecordInput $record,
        string $moduleCode,
        int $recordId,
    ): ?true {
        $target = $this->targets->applicationTargetOf($flow);

        if ($target === null) {
            return null;
        }

        $field = $target['field'];
        $id = array_key_exists($field, $record->values)
            ? self::idOf($record->values[$field])
            : $this->currentIdOf($applicant, $target, $moduleCode, $recordId);

        return $this->decide($applicant, $target, $id);
    }

    /**
     * 送る値の表(docs の 設計/ワークフロー/申請の周辺/既存のデータからの申請/申請対象からの申請.md の 10.1 節)を当てる。
     * 閲覧できない申請対象のチェックは読まない(`editValues` の呼び手の前提 = docs の 設計/ワークフロー/業務データとの接続/公開の口.md の 7 節)。
     *
     * @param  array{field: string, module: string}|null  $target
     */
    private function decide(AccessContext $applicant, ?array $target, ?int $targetId): ?true
    {
        if ($target === null || $targetId === null) {
            return null;
        }

        $requester = Requester::fromAccessContext($applicant);
        $module = $target['module'];

        if (! $this->viewAccess->canView($requester, $module, $targetId, $applicant->date)) {
            return true;
        }

        $read = $this->forms->editValues($requester, $module, $targetId, $applicant->date, $applicant->localeCode);

        return $read['rowAccessRestricted'] ? true : null;
    }

    /**
     * @param  array{field: string, module: string}  $target
     */
    private function currentIdOf(AccessContext $applicant, array $target, string $moduleCode, int $recordId): ?int
    {
        $requester = Requester::fromAccessContext($applicant);
        $read = $this->forms->editValues($requester, $moduleCode, $recordId, $applicant->date, $applicant->localeCode);

        return $this->targets->applicationTargetIdOf($target, $read['values']);
    }

    /**
     * 入力の参照の値(id の整数)。整数でなければ空として扱う(書き込みの口が値の誤りで 422 にする)。
     */
    private static function idOf(mixed $value): ?int
    {
        return is_int($value) ? $value : null;
    }
}
