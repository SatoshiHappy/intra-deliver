<?php

namespace App\Actions\Workflow;

use LogicException;

/**
 * 書いた版のルートが定義の検査に外れた(docs の 設計/ワークフロー/ルートの深さ/定義の管理.md 3 節。初期データ・複製の誤り = 呼び手の誤りなので LogicException)。
 * 違反の一覧を持つ(テストと、保存したノードの違反を 422 に直す 3a-2 のステップの設定の保存が読む)。
 */
class RouteDefinitionViolated extends LogicException
{
    /**
     * @param  list<RouteViolation>  $violations
     */
    public function __construct(public readonly int $versionId, public readonly array $violations)
    {
        parent::__construct("版 {$versionId} のルートが定義の検査に外れた: ".implode(' ', array_map(fn (RouteViolation $violation) => (string) $violation, $violations)));
    }
}
