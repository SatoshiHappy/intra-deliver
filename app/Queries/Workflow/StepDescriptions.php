<?php

namespace App\Queries\Workflow;

use App\Enums\Workflow\WorkflowNodeKind;

/**
 * ノードの設定から作る文の 1 か所(docs の 設計/ワークフロー/ルートの深さ/画面.md 9.3)。図の短い文(subtitle)を作る。値だけを扱い、DB を読まない。
 * 区切り 4-2 では処理の時に指定するノードの文だけを作る(対象者・基準にする人の文は対象者・役職の名前の口が要り、要約の 1 文と一緒に 3a-2 で足す)。
 */
final class StepDescriptions
{
    /**
     * 図の短い文(承認の動的承認・横配置 =「{指定するステップ}が指定」、縦配置 =「処理対象者は{指定するステップ}が指定」)。処理の時に指定しないノードは null。
     *
     * @param  string|null  $designatorName  指定するノードの名前(処理の時に指定しないノードは null)
     */
    public static function subtitle(WorkflowNodeKind $kind, ?string $designatorName): ?string
    {
        if ($designatorName === null) {
            return null;
        }

        return $kind === WorkflowNodeKind::Parallel ? "処理対象者は{$designatorName}が指定" : "{$designatorName}が指定";
    }
}
