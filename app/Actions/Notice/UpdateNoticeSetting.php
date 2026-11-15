<?php

namespace App\Actions\Notice;

use App\Models\Notice\NoticeOptOut;
use App\Queries\Notice\NoticeReceivingQuery;
use App\Support\AccessContext;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Validation\ValidationException;
use LogicException;

/**
 * 判定を求める者のユーザーの、通知の種類 1 つの受け取るかを書く(docs の
 * 設計/ワークフロー/申請の周辺/通知の受け取りの設定/利用者と管理の画面.md の 3.3、書き方 = ADR 0045 の決定 4)。
 * 書いた後の値を受けて行の有無だけをそろえる(同時に来ても一意の制約で失敗しないため)。
 */
class UpdateNoticeSetting
{
    public const GUEST = 'ゲストは通知の設定を保存できません';

    /** 必須の種類を保存したときの文 */
    public const REQUIRED_MESSAGE = '管理者が必須に設定しているため、変更できません。';

    public function __construct(
        private NoticeSettingValidator $validator,
        private NoticeReceivingQuery $receiving,
    ) {}

    /**
     * @param  mixed  $type  種類「モジュール.種類」
     * @param  mixed  $receives  受け取るか(真偽)
     *
     * @throws AuthorizationException ゲストのとき(403)
     * @throws ValidationException 種類が一覧に無いか切り替えられないとき・値が真偽でないとき({@see NoticeSettingValidator})
     * @throws ValidationException 種類が必須のとき(キー type。書かない)
     * @throws LogicException 種類の一覧の実装の答えが規則に外れるとき
     */
    public function execute(AccessContext $context, mixed $type, mixed $receives): void
    {
        $userId = $context->userId ?? throw new AuthorizationException(self::GUEST);
        [$type, $receives] = $this->validator->validated($type, $receives);

        if ($this->receiving->isRequiredType($type)) {
            throw ValidationException::withMessages(['type' => [self::REQUIRED_MESSAGE]]);
        }

        if ($receives) {
            NoticeOptOut::query()->where('user_id', $userId)->where('type', $type)->delete();

            return;
        }

        $now = now();
        NoticeOptOut::query()->insertOrIgnore([
            'user_id' => $userId, 'type' => $type, 'created_at' => $now, 'updated_at' => $now,
        ]);
    }
}
