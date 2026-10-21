<?php

namespace App\Queries\Records;

use App\Models\Records\Record;
use App\Models\Records\RecordModule;
use App\Queries\Authz\Api\Requester;
use App\Support\AccessContext;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Database\Eloquent\ModelNotFoundException;

/**
 * コメントを書く人・宛先の候補を引く人の判定(docs の 設計/業務データの入れ物/レコードの共通機能/コメント.md の 1.1 節・6 節): 閲覧の判定
 * ({@see RecordViewGate}。業務モジュール 404 → 閲覧 403 → レコード 404)の後に、ユーザーがいること(ゲストは書けない)。
 * 入口の FormRequest(検証の 422 より前)と業務処理が同じ判定を使う 1 か所。
 */
class RecordCommentGate
{
    /** ユーザーがいない(ゲスト)ときの 403 の文言 */
    public const GUEST_MESSAGE = 'コメントを追加するには、ユーザーを選択してください。';

    public function __construct(private RecordViewGate $views) {}

    /**
     * @return int 判定を求める者のユーザー
     *
     * @throws ModelNotFoundException<RecordModule|Record> 業務モジュールが無い・明細のモジュール、読めるがレコードが見えないとき
     * @throws AuthorizationException 閲覧を許されないとき・ユーザーがいないとき
     */
    public function authorize(AccessContext $context, string $moduleCode, int $recordId): int
    {
        $this->views->authorize(Requester::fromAccessContext($context), $moduleCode, $recordId, $context->date);

        return $context->userId ?? throw new AuthorizationException(self::GUEST_MESSAGE);
    }
}
