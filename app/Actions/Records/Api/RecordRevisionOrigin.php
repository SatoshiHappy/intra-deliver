<?php

namespace App\Actions\Records\Api;

use InvalidArgumentException;

/**
 * 更新履歴の版の契機(docs の 設計/業務データの入れ物/レコードの共通機能/更新履歴.md の 4.1 節)。契機を持つ呼び手(ワークフローの申請の経路など)だけが
 * 登録・更新の口に渡し、業務の画面の入口は渡さない。語は呼び手の画面の語の写しで、Records は呼び手の列挙を知らない。
 */
final readonly class RecordRevisionOrigin
{
    /** 処理の種別の最大の文字数(列 record_revisions.origin_operation の varchar(100)) */
    public const OPERATION_MAX = 100;

    /** ステップ名の最大の文字数(列 record_revisions.origin_node_name の varchar(1000)) */
    public const NODE_NAME_MAX = 1000;

    /**
     * @param  string  $operation  処理の種別の画面の語(例「申請」「再申請」)
     * @param  string|null  $nodeName  ステップ名(ステップを持たない契機は null)
     * @param  int|null  $executorUserId  実行者(代理のときだけ。null = 登録・更新の口に渡す AccessContext の利用者本人)
     *
     * @throws InvalidArgumentException 形が違うとき(空・長すぎる語、正でない実行者 = 呼び手の誤り)
     */
    public function __construct(
        public string $operation,
        public ?string $nodeName = null,
        public ?int $executorUserId = null,
    ) {
        if ($operation === '' || mb_strlen($operation) > self::OPERATION_MAX) {
            throw new InvalidArgumentException('契機の処理の種別は空でない '.self::OPERATION_MAX.' 文字までの語');
        }

        if ($nodeName !== null && ($nodeName === '' || mb_strlen($nodeName) > self::NODE_NAME_MAX)) {
            throw new InvalidArgumentException('契機のステップ名は null か空でない '.self::NODE_NAME_MAX.' 文字までの語');
        }

        if ($executorUserId !== null && $executorUserId < 1) {
            throw new InvalidArgumentException("契機の実行者は null か正の整数: {$executorUserId}");
        }
    }
}
