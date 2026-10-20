<?php

namespace App\Queries\Authz;

use App\Queries\Authz\Api\Requester;
use App\Support\AccessContext;
use InvalidArgumentException;

/**
 * 認可の確認の画面と認可の編集の守りの、判定する人を替えた判定を求める者を作る(Authz の内部。公開の口 {@see Requester} に置かない =
 * docs の 設計/ワークフロー/業務データとの接続/公開の口.md 8 節)。
 */
final class JudgeRequesters
{
    /**
     * 判定する人を替えた判定を求める者(docs の 設計/共通マスタ/ロールと認可/認可の確認/README.md 3.3。認可の確認の画面と守りが使う): ユーザーは引数
     * (null = ゲスト)、接続元は判定の入力のもの、選んだ組織は null(組織・役職の条件は主所属で判定される)。
     *
     * @throws InvalidArgumentException 接続元が IPv4 アドレスの形でないとき
     */
    public static function forUserIn(AccessContext $context, ?int $userId): Requester
    {
        return new Requester($userId, $context->ipv4Address);
    }
}
