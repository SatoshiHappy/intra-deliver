<?php

namespace App\Queries\Meeting;

use App\Enums\Builtin\Meeting;
use App\Enums\Records\RecordModuleAction;
use App\Queries\Authz\Api\Requester;
use App\Queries\Records\Api\RecordFormQuery;
use App\Support\AccessContext;

/**
 * 会議の作成と再作成を許す人の判定の 1 か所(docs の 設計/業務ツール/会議/処理.md の 3.2・3.7)。入口の判定と、一覧と詳細の操作を出す条件が同じものを
 * 呼ぶ。ゲストは AI に依頼できない(設計/AI/公開の口.md の 3 節)。worker の中で読む材料の read と、AI が後で書く update も依頼の時点に
 * 求める(依頼の時点の確かめ = 設計/業務ツール/会議/処理.md の 3.11)。
 * 行の判定(再作成の会議を閲覧できるか)は呼び手が持つ。
 */
class MeetingPermissions
{
    public function __construct(private RecordFormQuery $forms) {}

    /**
     * ゲストでなく、会議の業務モジュールの create・read・update をすべて許されるか。
     */
    public function canCreate(AccessContext $context): bool
    {
        return $this->allows($context, [
            RecordModuleAction::Create,
            RecordModuleAction::Read,
            RecordModuleAction::Update,
        ]);
    }

    /**
     * ゲストでなく、会議の業務モジュールの read・update をすべて許されるか。
     */
    public function canRegenerate(AccessContext $context): bool
    {
        return $this->allows($context, [RecordModuleAction::Read, RecordModuleAction::Update]);
    }

    /**
     * @param  list<RecordModuleAction>  $actions
     */
    private function allows(AccessContext $context, array $actions): bool
    {
        if ($context->userId === null) {
            return false;
        }

        $requester = Requester::fromAccessContext($context);

        foreach ($actions as $action) {
            if (! $this->forms->allows($requester, Meeting::CODE, $action, $context->date)) {
                return false;
            }
        }

        return true;
    }
}
