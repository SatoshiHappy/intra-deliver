<?php

namespace App\Queries\Authz\Api;

use App\Queries\Authz\JudgeRequesters;
use App\Queries\Authz\SubjectQuery;
use App\Support\AccessContext;
use InvalidArgumentException;

/**
 * 認可の判定を求める者(docs の 設計/共通マスタ/ロールと認可/対象者.md): ユーザー(いないことがある)、接続元の IPv4 アドレス(無いことがある)、
 * 選んだ組織(無いことがある。カレント組織を決める入力 = docs の 設計/デモユーザー切替/今の利用者.md 5.5)。
 *
 * 認証状態は持たない(d に有効なユーザーの有無で決まる = {@see SubjectQuery})。IPv6 の接続を null にするのは入口の仕事で、
 * IPv4 アドレスの形でない文字列は受けない(黙って「合わない」にしない)。
 *
 * Authz の公開の口(保証の正本 = docs の 設計/ワークフロー/業務データとの接続/公開の口.md 8.1 の表)。判定する人を替える作り手は
 * Authz の内部({@see JudgeRequesters})に置く(公開の範囲を広げない)。
 */
final readonly class Requester
{
    /**
     * @param  int|null  $userId  ユーザー(users.id)。d に有効でない・存在しないユーザーは、判定ではユーザーなしとして扱う
     * @param  string|null  $ipv4Address  接続元の IPv4 アドレス(10 進の 4 つの部分。先頭の 0 と空白を付けない)
     * @param  int|null  $selectedDepartmentId  選んだ組織(departments.id)。null = 選んでいない(カレント組織は主所属の組織になる)。
     *                                          候補(d に成り立つ既定の組織セットの所属)に無い組織も受け、判定では選んでいないものとして扱う
     *
     * @throws InvalidArgumentException 接続元が IPv4 アドレスの形でないとき
     */
    public function __construct(
        public ?int $userId,
        public ?string $ipv4Address,
        public ?int $selectedDepartmentId = null,
    ) {
        if ($ipv4Address !== null && filter_var($ipv4Address, FILTER_VALIDATE_IP, FILTER_FLAG_IPV4) === false) {
            throw new InvalidArgumentException('接続元の IPv4 アドレスとして読めない文字列を渡した');
        }
    }

    /**
     * 判定の入力から判定を求める者を作る 1 か所(docs の 設計/メニュー/判定の入力.md 5 節。共有は読み取りに依存できないので、変換はこちらに置く)。
     *
     * @throws InvalidArgumentException 接続元が IPv4 アドレスの形でないとき
     */
    public static function fromAccessContext(AccessContext $context): self
    {
        return new self($context->userId, $context->ipv4Address, $context->selectedDepartmentId);
    }
}
