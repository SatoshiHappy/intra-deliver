<?php

namespace App\Queries\Workflow;

use App\Queries\Master\Api\MasterNames;
use App\Support\AccessContext;
use App\Support\PositiveIds;
use LogicException;

/**
 * 申請の画面の申請者の 1 か所(docs の 設計/ワークフロー/案件の運用/画面/案件の画面.md の 6.1 節の applicants・applicant と既定の申請者)。
 * 候補の集合は画面の種類ごとに呼び手が渡す(新規 = {@see ApplicableFlows::applicantIds()}、
 * 申請対象から = {@see RecordApplyFlows::applicantIdsForTarget()}、既存のデータから = 候補のうちのレコードの作成者、一時保存 = 持ち主、
 * 再申請 = 案件の申請者)。所属は人の候補の入口と同じ主所属の名前で、名前と同じく今日で読む。
 *
 * @phpstan-type Applicant array{id: int, name: string, department: string|null, isSelf: bool}
 * @phpstan-type ApplicantProps array{applicants: list<Applicant>, applicant: int|null}
 */
class ApplicationApplicants
{
    public function __construct(
        private WorkflowPeople $people,
        private MasterNames $names,
    ) {}

    /**
     * 既定の申請者を選び、その人の文脈(代理元なら選んだ組織なし)と props を返す。
     *
     * @param  list<int>  $candidateIds  候補(自分を先頭に、代理元は今ある代理元の並び。呼び手が 403 の後に渡すので空でない)
     * @param  mixed  $applicantId  クエリ applicant(解釈できない値・候補の代理元でない値は既定と同じ = 画面/README.md の 1.1 節)
     * @return array{AccessContext, ApplicantProps}
     *
     * @throws LogicException 候補が空のとき
     */
    public function choose(AccessContext $context, array $candidateIds, mixed $applicantId): array
    {
        $chosen = PositiveIds::of($applicantId);
        $principals = array_values(array_diff($candidateIds, [$context->userId]));
        $applicant = match (true) {
            in_array($chosen, $principals, true) => $chosen,
            in_array($context->userId, $candidateIds, true) => $context->userId,
            default => $principals[0] ?? throw new LogicException('申請できる人を確かめた後に申請者の候補が無い'),
        };

        $defaultId = (int) $applicant;

        return [PrincipalContext::of($context, $defaultId), $this->propsOf($context, $candidateIds, $defaultId)];
    }

    /**
     * 申請者が決まっている画面(一時保存 = 持ち主、再申請 = 案件の申請者、既存のデータから = 作成者)の props。
     *
     * @param  list<int>  $candidateIds
     * @return ApplicantProps
     */
    public function propsOf(AccessContext $context, array $candidateIds, int $applicantId): array
    {
        $people = $this->people->users($candidateIds, $context->date, $context->localeCode);
        $departments = $this->names->mainDepartmentNames($candidateIds, $context->date, $context->localeCode);

        return [
            'applicants' => array_map(fn (int $id) => [
                'id' => $id,
                'name' => $people[$id]['name'] ?? $people[$id]['code'],
                'department' => $departments[$id] ?? null,
                'isSelf' => $id === $context->userId,
            ], $candidateIds),
            'applicant' => $applicantId === $context->userId ? null : $applicantId,
        ];
    }
}
