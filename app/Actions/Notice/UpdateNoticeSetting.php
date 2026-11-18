<?php

namespace App\Actions\Notice;

use App\Models\Notice\NoticeMute;
use App\Queries\Notice\NoticeReceivingQuery;
use App\Support\AccessContext;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Validation\ValidationException;
use LogicException;

/**
 * 判定を求める者のユーザーの、通知の種類 1 つをミュートするかを書く(docs の
 * 設計/ワークフロー/申請の周辺/通知の受け取りの設定/利用者と管理の画面.md の 3.3、書き方 = ADR 0050 の決定 4)。
 * 書いた後の値を受けて行の有無だけをそろえる(同時に来ても一意の制約で失敗しないため)。
 */
class UpdateNoticeSetting
{
    /** 値の入力のキー(意味の名前にする。キーが違えば値が無いので 422 になり、意味の違う値を書かない) */
    public const VALUE_KEY = 'muted';

    public const GUEST = 'ゲストは通知の設定を保存できません';

    /** 必須の種類を保存したときの文 */
    public const REQUIRED_MESSAGE = '管理者が必須に設定しているため、変更できません。';

    public function __construct(
        private NoticeSettingValidator $validator,
        private NoticeReceivingQuery $receiving,
    ) {}

    /**
     * @param  mixed  $type  種類「モジュール.種類」
     * @param  mixed  $muted  ミュートするか(真偽)
     *
     * @throws AuthorizationException ゲストのとき(403)
     * @throws ValidationException 種類が一覧に無いとき・値が真偽でないとき({@see NoticeSettingValidator})
     * @throws ValidationException 種類が必須のとき(キー type。書かない)
     * @throws LogicException 種類の一覧の実装の答えが規則に外れるとき
     */
    public function execute(AccessContext $context, mixed $type, mixed $muted): void
    {
        $userId = $context->userId ?? throw new AuthorizationException(self::GUEST);
        [$type, $muted] = $this->validator->validated($type, self::VALUE_KEY, $muted);

        if ($this->receiving->isRequiredType($type)) {
            throw ValidationException::withMessages(['type' => [self::REQUIRED_MESSAGE]]);
        }

        if (! $muted) {
            NoticeMute::query()->where('user_id', $userId)->where('type', $type)->delete();

            return;
        }

        $now = now();
        NoticeMute::query()->insertOrIgnore([
            'user_id' => $userId, 'type' => $type, 'created_at' => $now, 'updated_at' => $now,
        ]);
    }
}
