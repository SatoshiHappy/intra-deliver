<?php

namespace App\Enums\Workflow;

/**
 * 認可のリソースタイプ「ワークフローのフロー」(workflow-flow)のアクションと、フローのリソースグループ ID・URI・セットのコードの作り方
 * (docs の 設計/ワークフロー/案件の運用/認可と一覧.md の 3 節)。作り方はここだけに置き、業務処理・読み取り・初期データが使う。
 *
 * セット workflow-flows の下に、フローごとのリソースグループ(workflow-flow-<コード>、URI workflow-flow://flow/<コード>)を置く。
 */
enum WorkflowFlowAction: string
{
    /** リソースタイプの ID */
    public const RESOURCE_TYPE = 'workflow-flow';

    /** このタイプのリソースを置くリソースグループセットの ID */
    public const SET_CODE = 'workflow-flows';

    /** 参照(全案件の参照 = 閲覧の判定の管理の述語) */
    case Read = 'read';

    /** 操作(案件操作) */
    case Operate = 'operate';

    /**
     * フローのコードから、リソースグループ ID を作る。
     */
    public static function resourceGroupCode(string $flowCode): string
    {
        return self::RESOURCE_TYPE.'-'.$flowCode;
    }

    /**
     * フローのコードから、リソース URI を作る。
     */
    public static function uri(string $flowCode): string
    {
        return self::RESOURCE_TYPE.'://flow/'.$flowCode;
    }
}
