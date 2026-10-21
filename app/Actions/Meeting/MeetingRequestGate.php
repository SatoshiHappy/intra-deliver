<?php

namespace App\Actions\Meeting;

use App\Actions\Ai\Api\RequestPrompt;
use App\Enums\Builtin\Meeting;
use App\Enums\Records\RecordModuleAction;
use App\Queries\Authz\Api\Requester;
use App\Queries\Records\Api\RecordFormQuery;
use App\Queries\Records\Api\RecordViewAccess;
use App\Support\AccessContext;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Database\Eloquent\ModelNotFoundException;

/**
 * 会議の作成と再作成の入口の判定(docs の 設計/業務ツール/会議/処理.md の 3.2・3.7)。受けた後に必ず失敗する依頼を受けないため、材料を読む read と
 * AI が依頼者の権限で書く update も入口で求める。ゲストは AI に依頼できない(設計/AI/公開の口.md の 3 節)。
 * 判定の順は入れ物の入口と同じ(403 をレコードの有無と行の判定より先に返す = 設計/業務データの入れ物/認可とメニュー/README.md の 2.2)。
 */
class MeetingRequestGate
{
    public function __construct(private RecordFormQuery $forms, private RecordViewAccess $access) {}

    /**
     * @throws AuthorizationException ゲスト・会議の業務モジュールの create・read・update のどれかを許されないとき
     */
    public function ensureCanCreate(AccessContext $context): void
    {
        $this->ensureAllowed($context, [
            RecordModuleAction::Create,
            RecordModuleAction::Read,
            RecordModuleAction::Update,
        ]);
    }

    /**
     * @throws AuthorizationException ゲスト・会議の業務モジュールの read・update のどれかを許されないとき
     * @throws ModelNotFoundException 会議が無い・会議の業務モジュールのレコードでない・行の判定を通らないとき
     */
    public function ensureCanRegenerate(AccessContext $context, int $recordId): void
    {
        $this->ensureAllowed($context, [RecordModuleAction::Read, RecordModuleAction::Update]);

        $requester = Requester::fromAccessContext($context);

        if (! $this->access->canView($requester, Meeting::CODE, $recordId, $context->date)) {
            throw new ModelNotFoundException("会議 {$recordId} が無いか、閲覧できない");
        }
    }

    /**
     * @param  list<RecordModuleAction>  $actions
     *
     * @throws AuthorizationException
     */
    private function ensureAllowed(AccessContext $context, array $actions): void
    {
        if ($context->userId === null) {
            throw new AuthorizationException(RequestPrompt::GUEST);
        }

        $requester = Requester::fromAccessContext($context);

        foreach ($actions as $action) {
            if (! $this->forms->allows($requester, Meeting::CODE, $action, $context->date)) {
                throw new AuthorizationException(RecordFormQuery::NOT_ALLOWED_MESSAGE);
            }
        }
    }
}
