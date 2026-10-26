<?php

namespace App\Actions\Meeting;

use App\Actions\Ai\Api\RunRequestMessages;
use App\Enums\Builtin\Meeting;
use App\Queries\Authz\Api\Requester;
use App\Queries\Meeting\MeetingPermissions;
use App\Queries\Records\Api\RecordFormQuery;
use App\Queries\Records\Api\RecordViewAccess;
use App\Support\AccessContext;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Database\Eloquent\ModelNotFoundException;

/**
 * 会議の作成と再作成の入口の判定(docs の 設計/業務ツール/会議/処理.md の 3.2・3.7)。許す人の判定は {@see MeetingPermissions}(一覧と詳細の
 * 操作を出す条件と同じ)で、ここは 403 の文を分ける(ゲスト = AI に依頼できない、ほか = 業務モジュールの操作)。
 * 判定の順は入れ物の入口と同じ(403 をレコードの有無と行の判定より先に返す = 設計/業務データの入れ物/認可とメニュー/README.md の 2.2)。
 */
class MeetingRequestGate
{
    public function __construct(private MeetingPermissions $permissions, private RecordViewAccess $access) {}

    /**
     * @throws AuthorizationException ゲスト・会議の業務モジュールの create・read・update のどれかを許されないとき
     */
    public function ensureCanCreate(AccessContext $context): void
    {
        self::ensureNotGuest($context);

        if (! $this->permissions->canCreate($context)) {
            throw new AuthorizationException(RecordFormQuery::NOT_ALLOWED_MESSAGE);
        }
    }

    /**
     * @throws AuthorizationException ゲスト・会議の業務モジュールの read・update のどれかを許されないとき
     * @throws ModelNotFoundException 会議が無い・会議の業務モジュールのレコードでない・行の判定を通らないとき
     */
    public function ensureCanRegenerate(AccessContext $context, int $recordId): void
    {
        self::ensureNotGuest($context);

        if (! $this->permissions->canRegenerate($context)) {
            throw new AuthorizationException(RecordFormQuery::NOT_ALLOWED_MESSAGE);
        }

        $requester = Requester::fromAccessContext($context);

        if (! $this->access->canView($requester, Meeting::CODE, $recordId, $context->date)) {
            throw new ModelNotFoundException("会議 {$recordId} が無いか、閲覧できない");
        }
    }

    /**
     * @throws AuthorizationException ゲストのとき
     */
    private static function ensureNotGuest(AccessContext $context): void
    {
        if ($context->userId === null) {
            throw new AuthorizationException(RunRequestMessages::GUEST);
        }
    }
}
