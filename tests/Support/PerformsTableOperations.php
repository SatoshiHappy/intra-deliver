<?php

namespace Tests\Support;

use App\Actions\Workflow\ApplyCase;
use App\Actions\Workflow\ApproveCase;
use App\Actions\Workflow\ApproveCaseEarly;
use App\Actions\Workflow\CaseContact;
use App\Actions\Workflow\ConfirmCase;
use App\Actions\Workflow\CreateDraft;
use App\Actions\Workflow\DeleteCase;
use App\Actions\Workflow\DeleteDraft;
use App\Actions\Workflow\DraftInput;
use App\Actions\Workflow\HoldCase;
use App\Actions\Workflow\MoveCaseNode;
use App\Actions\Workflow\PullBackCase;
use App\Actions\Workflow\ReapplyCase;
use App\Actions\Workflow\ReassignCaseNode;
use App\Actions\Workflow\ReexpandCaseNode;
use App\Actions\Workflow\RejectCase;
use App\Actions\Workflow\ReleaseCase;
use App\Actions\Workflow\RetargetCaseNode;
use App\Actions\Workflow\SendBackCase;
use App\Actions\Workflow\TransferCase;
use App\Actions\Workflow\UpdateDraft;
use App\Actions\Workflow\WithdrawCase;
use App\Enums\Workflow\WorkflowNoticeType;
use App\Enums\Workflow\WorkflowOperation;
use App\Models\Master\User;
use App\Models\Notice\Notice;
use App\Models\Workflow\WorkflowCase;
use App\Models\Workflow\WorkflowCaseNode;
use App\Support\AccessContext;
use Closure;

/**
 * 状態 × 操作の表のテスト({@see ChecksCaseOperationTable})の、操作 1 つを今の改訂番号で行う部分(docs の
 * 設計/ワークフロー/ルートの深さ/案件の操作/同時実行と検査.md 14 節)。処理の操作は代理元(principal_user_id)を渡せる
 * (設計/ワークフロー/案件の運用/案件の操作/README.md の 4.3 節)。案件操作は自分として行う(代理で行わない = 同じ文書の 3.2 節)。
 * 入力の形は {@see ChecksCaseOperationTable} の Inputs。
 *
 * @phpstan-import-type Inputs from ChecksCaseOperationTable
 */
trait PerformsTableOperations
{
    /** 振替先の既定(関わらない人。状況が入力 transferTo で替える) */
    private const TRANSFER_TO = 'U';

    /** 連絡を付けられる操作(設計/ワークフロー/案件の運用/案件の操作/操作.md の 10.5 節) */
    private const CONTACT_OPERATIONS = [
        'apply', 'reapply', 'approve', 'approve_early', 'reject', 'send_back', 'pull_back', 'withdraw',
        'hold', 'release', 'transfer',
    ];

    /**
     * 操作を今の改訂番号で行う(入力が無ければ差戻し先 = 申請のノード)。案件の無い操作は作った案件の id を返す。
     *
     * @param  Inputs|null  $inputs
     * @param  int|null  $principal  代理元(null = 自分として。一時保存の操作は受けない)
     */
    private function perform(
        WorkflowOperation $operation,
        ?User $user,
        ?WorkflowCase $case,
        ?array $inputs,
        ?int $principal = null,
    ): ?int {
        $context = $this->contextOf($user);
        $baseDate = $context->date->toDateString();

        $draft = new DraftInput('表', null, ['name' => '表'], []);

        if ($case === null) {
            return $operation === WorkflowOperation::SaveDraft
                ? app(CreateDraft::class)->execute($context, self::FLOW_CODE, $draft)
                : app(ApplyCase::class)->execute($context, self::FLOW_CODE, $this->projectInput(), $baseDate)->caseId;
        }

        $this->onCase($inputs, $principal)[$operation->value]($context, $case->id, $this->revisionOf($case));

        return null;
    }

    /**
     * 既にある案件の操作(操作 → 呼び出し)。入力があればノード・差戻し先・引戻すノード・指定・振替先はその値(全員に同じ)。
     * 入力の無いノードと引戻すノードは処理者(代理元か自分)で選ぶ。
     *
     * @param  Inputs|null  $inputs
     * @return array<string, Closure(AccessContext, int, int): mixed>
     */
    private function onCase(?array $inputs, ?int $principal): array
    {
        $nodeOf = fn (int $caseId, ?int $userId) => $inputs['node']
            ?? $this->operatedNodeId($caseId, $principal ?? $userId);
        $contact = $this->contactOf($inputs);
        $onNode = fn (string $class) => fn (AccessContext $c, int $id, int $rev) => app($class)
            ->execute($c, $id, $rev, $nodeOf($id, $c->userId), null, $principal, $contact);

        return [
            ...$this->onDraft($contact),
            ...$this->byManager($inputs, $nodeOf),
            'approve' => function (AccessContext $c, int $id, int $rev) use (
                $inputs, $nodeOf, $principal, $contact,
            ): void {
                $nodeId = $nodeOf($id, $c->userId);
                $assignments = $inputs['assignments'] ?? [];
                app(ApproveCase::class)->execute($c, $id, $rev, $nodeId, null, $assignments, $principal, $contact);
            },
            'approve_early' => $onNode(ApproveCaseEarly::class),
            'reject' => $onNode(RejectCase::class),
            'send_back' => function (AccessContext $c, int $id, int $rev) use (
                $inputs, $nodeOf, $principal, $contact,
            ): void {
                $nodeId = $nodeOf($id, $c->userId);
                $toNodeId = $inputs['sendBackTo'] ?? $this->applyNodeIdOf($id);
                app(SendBackCase::class)->execute($c, $id, $rev, $nodeId, $toNodeId, null, $principal, $contact);
            },
            'pull_back' => function (AccessContext $c, int $id, int $rev) use ($inputs, $principal, $contact): void {
                $nodeId = $inputs['pullBack'] ?? $this->pullBackNodeId($id, $principal ?? $c->userId);
                app(PullBackCase::class)->execute($c, $id, $rev, $nodeId, null, $principal, $contact);
            },
            'reapply' => function (AccessContext $c, int $id, int $rev) use ($principal, $contact): void {
                app(ReapplyCase::class)->execute($c, $id, $rev, $this->projectInput(), $principal, $contact);
            },
            'withdraw' => function (AccessContext $c, int $id, int $rev) use ($principal, $contact): void {
                app(WithdrawCase::class)->execute($c, $id, $rev, null, $principal, $contact);
            },
            'hold' => $onNode(HoldCase::class),
            'release' => $onNode(ReleaseCase::class),
            'transfer' => function (AccessContext $c, int $id, int $rev) use (
                $inputs, $nodeOf, $principal, $contact,
            ): void {
                $toUserId = $this->person($inputs['transferTo'] ?? self::TRANSFER_TO)?->id;
                $nodeId = $nodeOf($id, $c->userId);
                app(TransferCase::class)->execute($c, $id, $rev, $nodeId, $toUserId, null, $principal, $contact);
            },
            'confirm' => fn (AccessContext $c, int $id) => app(ConfirmCase::class)->execute($c, $id),
        ];
    }

    /**
     * 案件操作(処理対象者の変更の人・指定の変更の人の既定 = 関わらない人 U。指定の変更の既定のノード = 操作するノード)。
     *
     * @param  Inputs|null  $inputs
     * @param  Closure(int, int|null): int  $nodeOf
     * @return array<string, Closure(AccessContext, int, int): mixed>
     */
    private function byManager(?array $inputs, Closure $nodeOf): array
    {
        $people = fn (string $letters) => array_map(
            fn (string $letter) => $this->person($letter)->id ?? 0,
            str_split($letters),
        );
        $retargetTo = $people($inputs['retargetTo'] ?? self::TRANSFER_TO);
        $reassignTo = $people($inputs['reassignTo'] ?? self::TRANSFER_TO);

        return [
            'retarget' => fn (AccessContext $c, int $id, int $rev) => app(RetargetCaseNode::class)
                ->execute($c, $id, $rev, $nodeOf($id, $c->userId), $retargetTo, null),
            'reexpand' => fn (AccessContext $c, int $id, int $rev) => app(ReexpandCaseNode::class)
                ->execute($c, $id, $rev, $nodeOf($id, $c->userId), null),
            ...$this->moves($inputs, $nodeOf),
            'reassign' => fn (AccessContext $c, int $id, int $rev) => app(ReassignCaseNode::class)
                ->execute($c, $id, $rev, $inputs['reassign'] ?? $nodeOf($id, $c->userId), $reassignTo, null),
            'delete_case' => fn (AccessContext $c, int $id, int $rev) => app(DeleteCase::class)->execute($c, $id, $rev),
        ];
    }

    /**
     * ステップの移動(戻り先 = 入力の差戻し先。無ければ申請のノード)。
     *
     * @param  Inputs|null  $inputs
     * @param  Closure(int, int|null): int  $nodeOf
     * @return array<string, Closure(AccessContext, int, int): mixed>
     */
    private function moves(?array $inputs, Closure $nodeOf): array
    {
        $move = fn (string $direction) => fn (AccessContext $c, int $id, int $rev) => app(MoveCaseNode::class)->execute(
            $c,
            $id,
            $rev,
            $nodeOf($id, $c->userId),
            $direction,
            $direction === 'back' ? $inputs['sendBackTo'] ?? $this->applyNodeIdOf($id) : null,
            null,
        );

        return ['move_back' => $move('back'), 'move_forward' => $move('forward'), 'move_finish' => $move('finish')];
    }

    /**
     * 入力 contact(連絡する人の記号)の連絡(無ければ null)。
     *
     * @param  Inputs|null  $inputs
     */
    private function contactOf(?array $inputs): ?CaseContact
    {
        $letter = $inputs['contact'] ?? null;

        return $letter === null ? null : new CaseContact([$this->person($letter)->id ?? 0], null);
    }

    /**
     * 入力 contact の人への、その案件の連絡の通知の数(連絡を付けられる操作の成功で 1 つ増える)。
     *
     * @param  Inputs|null  $inputs
     */
    private function contactNoticeCount(?array $inputs, ?WorkflowCase $case): int
    {
        $letter = $inputs['contact'] ?? null;

        if ($letter === null || $case === null) {
            return 0;
        }

        return Notice::query()->where('type', WorkflowNoticeType::Contact->value)
            ->where('user_id', $this->person($letter)?->id)->where('target_id', $case->id)->count();
    }

    /**
     * 成功した操作が、連絡を付けられる操作なら連絡の通知を 1 つ作った(操作.md の 8 節の「連絡」の行)。
     *
     * @param  Inputs|null  $inputs
     */
    private function assertContactSent(
        WorkflowOperation $operation,
        ?array $inputs,
        ?WorkflowCase $case,
        int $before,
        string $label,
    ): void {
        $sends = in_array($operation->value, self::CONTACT_OPERATIONS, true) && ($inputs['contact'] ?? null) !== null;
        $expected = $before + ($sends && $case !== null ? 1 : 0);
        $this->assertSame($expected, $this->contactNoticeCount($inputs, $case), "{$label} の連絡");
    }

    /**
     * 一時保存の上書き・削除と一時保存からの申請(代理元を受けない = 操作.md の 10.8 節の持ち主)。
     *
     * @return array<string, Closure(AccessContext, int, int): mixed>
     */
    private function onDraft(?CaseContact $contact): array
    {
        return [
            'overwrite_draft' => function (AccessContext $c, int $id, int $rev): void {
                app(UpdateDraft::class)->execute($c, $id, $rev, new DraftInput('上書き', null, [], []));
            },
            'delete_draft' => function (AccessContext $c, int $id, int $rev): void {
                app(DeleteDraft::class)->execute($c, $id, $rev);
            },
            'apply' => function (AccessContext $c, int $id, int $rev) use ($contact): void {
                $baseDate = $c->date->toDateString();
                app(ApplyCase::class)->executeFromDraft($c, $id, $rev, $this->projectInput(), $baseDate, $contact);
            },
        ];
    }

    private function applyNodeIdOf(int $caseId): int
    {
        return (int) WorkflowCaseNode::query()->where('workflow_case_id', $caseId)->where('position', 1)->value('id');
    }
}
