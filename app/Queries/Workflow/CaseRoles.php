<?php

namespace App\Queries\Workflow;

/**
 * 判定を求める者の、1 つの案件についての役割(docs の 設計/ワークフロー/案件の操作/README.md 1.3)。判定の表 {@see CaseOperationTable::permits()} と
 * can の入力。作るのは {@see ProcessorRoles}(d に有効でない・いない判定を求める者は、すべて偽)と、案件が無い操作の役割の無い値 =
 * 業務処理の CaseOperationGuard::permitWithoutCase()。can は {@see CaseOperationDecider::rowCansOf()}・{@see CaseOperationDecider::detailCanOf()}。
 *
 * canApply・canUpdateRecord は入口の 403 の判定(操作.md 3.1 の 2)で、表には含めず can を作るときに表の結果と AND する(表を純粋な関数に保つため)。
 * 業務処理の 403 は {@see ApplicableFlows::isApplicable()}・{@see CaseOperationDecider::isUpdatable()} で見る。
 */
final readonly class CaseRoles
{
    /**
     * @param  bool  $isApplicant  申請者(処理者 P が案件の user_id の人)
     * @param  bool  $isCurrentTarget  現在のノードの処理対象者({@see ProcessorRoles})
     * @param  bool  $isPullBackActor  引戻しできる処理者(引戻しの 1 規則 = ルートの深さ/案件の操作/操作.md 10.5 に合うノードがある。ノードを問うときはそのノード = {@see PullBackableNodes})
     * @param  bool  $canApply  申請できる人(案件のフローについて、申請のノードの対象者に本人の判定で合い、コンテンツの業務モジュールの create を許される)
     * @param  bool  $canUpdateRecord  コンテンツの業務モジュールの update を許される
     * @param  bool  $isHoldSide  保留中のノードの保留者側({@see ProcessorRoles})
     */
    public function __construct(
        public bool $isApplicant,
        public bool $isCurrentTarget,
        public bool $isPullBackActor,
        public bool $canApply,
        public bool $canUpdateRecord,
        public bool $isHoldSide = false,
    ) {}
}
