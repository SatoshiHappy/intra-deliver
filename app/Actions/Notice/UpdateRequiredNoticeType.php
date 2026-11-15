<?php

namespace App\Actions\Notice;

use App\Models\Notice\NoticeRequiredType;
use Illuminate\Validation\ValidationException;
use LogicException;

/**
 * 通知の種類 1 つの必須の印を付け外しする(docs の 設計/ワークフロー/申請の周辺/通知の受け取りの設定/利用者と管理の画面.md の 4.3、
 * 書き方 = ADR 0045 の決定 4)。使える人はルートの認可だけで決まるので、判定を求める者を受けない。
 * 書いた後の値を受けて行の有無だけをそろえる(同時に来ても一意の制約で失敗しないため)。
 */
class UpdateRequiredNoticeType
{
    public function __construct(private NoticeSettingValidator $validator) {}

    /**
     * @param  mixed  $type  種類「モジュール.種類」
     * @param  mixed  $required  必須にするか(真偽)
     *
     * @throws ValidationException 種類が一覧に無いか切り替えられないとき・値が真偽でないとき({@see NoticeSettingValidator})
     * @throws LogicException 種類の一覧の実装の答えが規則に外れるとき
     */
    public function execute(mixed $type, mixed $required): void
    {
        [$type, $required] = $this->validator->validated($type, $required);

        if (! $required) {
            NoticeRequiredType::query()->where('type', $type)->delete();

            return;
        }

        $now = now();
        NoticeRequiredType::query()->insertOrIgnore(['type' => $type, 'created_at' => $now, 'updated_at' => $now]);
    }
}
