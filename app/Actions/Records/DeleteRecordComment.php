<?php

namespace App\Actions\Records;

use App\Models\Records\Record;
use App\Models\Records\RecordComment;
use App\Models\Records\RecordCommentMention;
use App\Models\Records\RecordModule;
use App\Queries\Authz\Api\Requester;
use App\Queries\Records\RecordModuleGate;
use App\Queries\Records\RecordViewGate;
use App\Support\AccessContext;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Support\Facades\DB;

/**
 * コメントを消す(内部。削除の印を残す = 本文と宛先の行を消し、削除した日時と人を書く。docs の
 * 設計/業務データの入れ物/レコードの共通機能/コメント.md の 4 節)。入口は 2 つで、書いた本人の経路(閲覧の判定と本人の確かめ)と、
 * 管理の経路(ルートの認可だけ。業務モジュールの判定をしない)。送った通知は残す。
 */
class DeleteRecordComment
{
    use LocksRecordModule;

    /** 本人でないときの 403 の文言 */
    public const NOT_ALLOWED = 'このコメントは削除できません';

    /** 管理の経路でユーザーがいない(ゲスト)ときの 403 の文言(削除した人を残せない) */
    public const GUEST_MESSAGE = 'コメントを削除するには、ユーザーを選択してください。';

    public function __construct(
        private RecordModuleGate $modules,
        private RecordViewGate $views,
    ) {}

    /**
     * @throws ModelNotFoundException<RecordModule|Record|RecordComment> 業務モジュール・レコード・そのレコードの削除していないコメントが無いとき
     * @throws AuthorizationException 閲覧を許されないとき・書いた本人でないとき
     */
    public function byAuthor(AccessContext $context, string $moduleCode, int $recordId, int $commentId): void
    {
        DB::transaction(function () use ($context, $moduleCode, $recordId, $commentId): void {
            $module = $this->modules->topLevelModule($moduleCode);
            $this->lockRecordModuleShared($module);
            $this->views->authorize(Requester::fromAccessContext($context), $moduleCode, $recordId, $context->date);
            $comment = $this->lockComment($module, $recordId, $commentId);

            if ($context->userId === null || (int) $comment->user_id !== $context->userId) {
                throw new AuthorizationException(self::NOT_ALLOWED);
            }

            $this->erase($comment, $context->userId);
        });
    }

    /**
     * ルートの認可(コメントの管理の権限)は入口が済ませている。
     *
     * @throws ModelNotFoundException<RecordModule|Record|RecordComment> コメントが無い・削除済み、レコードが消えたとき
     * @throws AuthorizationException ユーザーがいない(ゲスト)とき
     */
    public function byAdministrator(AccessContext $context, int $commentId): void
    {
        DB::transaction(function () use ($context, $commentId): void {
            $found = RecordComment::query()->whereNull('deleted_at')->findOrFail($commentId);
            $module = RecordModule::query()->findOrFail($found->record_module_id);
            $this->lockRecordModuleShared($module);
            $comment = $this->lockComment($module, $found->record_id, $commentId);
            $this->erase($comment, $context->userId ?? throw new AuthorizationException(self::GUEST_MESSAGE));
        });
    }

    /**
     * レコードの行を共有で、続けてコメントの行を排他で取る(順 = 業務モジュール → レコード → コメント)。
     *
     * @throws ModelNotFoundException<Record|RecordComment> レコードが無い・そのレコードの削除していないコメントでないとき
     */
    private function lockComment(RecordModule $module, int $recordId, int $commentId): RecordComment
    {
        Record::query()->where('record_module_id', $module->id)->lock('for key share')->findOrFail($recordId);

        return RecordComment::query()
            ->where('record_module_id', $module->id)->where('record_id', $recordId)->whereNull('deleted_at')
            ->lock('for update')->findOrFail($commentId);
    }

    private function erase(RecordComment $comment, int $deletedByUserId): void
    {
        $comment->update(['body' => null, 'deleted_at' => now(), 'deleted_by_user_id' => $deletedByUserId]);
        RecordCommentMention::query()->where('record_comment_id', $comment->id)->delete();
    }
}
