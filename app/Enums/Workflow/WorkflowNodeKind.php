<?php

namespace App\Enums\Workflow;

/**
 * ノードの種類(docs の 設計/ワークフロー/データモデル/README.md 1 節。表 workflow_route_nodes・workflow_case_nodes の kind)。値は DB の検査制約と同じ一覧。
 * 申請は位置 1 のノードだけ(ワークフロー/データモデル/表.md 2.3)。履歴の種別 {@see WorkflowAction} とは列が別なので別の列挙にする。
 * 種類の集まり(承認する種類・処理対象者を持つ種類など)はこの列挙のメソッドだけに置く(DB の検査制約の式と同じ集まり)。
 */
enum WorkflowNodeKind: string
{
    /** 申請 */
    case Apply = 'apply';

    /** 承認 */
    case Approve = 'approve';

    /** 横配置(指定した人が順に処理する。展開の行の親) */
    case Serial = 'serial';

    /** 縦配置(指定した人の合議。展開の行の親) */
    case Parallel = 'parallel';

    /** 分岐開始 */
    case BranchStart = 'branch_start';

    /** 分岐終了 */
    case BranchEnd = 'branch_end';

    /** 同期開始 */
    case SyncStart = 'sync_start';

    /** 同期終了 */
    case SyncEnd = 'sync_end';

    /**
     * 処理対象者の指定(target_kind)を持つ種類(申請・承認・横配置・縦配置。差戻しできる先になれる種類 = ワークフロー/データモデル/辺と差戻し先と指定の表.md 2.11)。
     *
     * @return list<self>
     */
    public static function targeted(): array
    {
        return [self::Apply, self::Approve, self::Serial, self::Parallel];
    }

    /**
     * 処理対象者を持たない、ルートの形だけの種類(分岐・同期。ワークフロー/データモデル/表.md 2.3 の種類と指定の種類の組)。
     *
     * @return list<self>
     */
    public static function routing(): array
    {
        return [self::BranchStart, self::BranchEnd, self::SyncStart, self::SyncEnd];
    }

    /**
     * 承認する種類(申請者承認防止を持てる・差戻し元になれる = ワークフロー/データモデル/表.md 2.3・辺と差戻し先と指定の表.md 2.11)。
     *
     * @return list<self>
     */
    public static function approving(): array
    {
        return [self::Approve, self::Serial, self::Parallel];
    }

    /**
     * 処理期限の日数を持てる種類(承認・横配置・縦配置。横配置・縦配置は親に置き展開の行に写す = 案件の運用/定義の管理.md 1 節。
     * DB の検査制約 workflow_route_nodes_deadline_days_kind_check と同じ集まり)。
     *
     * @return list<self>
     */
    public static function withDeadline(): array
    {
        return [self::Approve, self::Serial, self::Parallel];
    }

    /**
     * 確認する人を持てる種類(申請・承認 = 展開の行を持たず処理済みになる種類。案件の運用/定義の管理.md 1 節。
     * DB の検査制約 workflow_route_nodes_confirm_authz_subject_group_id_kind_check と同じ集まり)。
     *
     * @return list<self>
     */
    public static function withConfirmers(): array
    {
        return [self::Apply, self::Approve];
    }

    /**
     * 展開の行を持つ種類(横配置・縦配置。処理の時に指定だけ = ワークフロー/データモデル/表.md 2.3、ワークフロー/データモデル/表.md 2.6)。
     *
     * @return list<self>
     */
    public static function expanding(): array
    {
        return [self::Serial, self::Parallel];
    }

    /**
     * 対の終了を持つ種類(分岐開始・同期開始 = ワークフロー/データモデル/表.md 2.3)。
     *
     * @return list<self>
     */
    public static function opening(): array
    {
        return [self::BranchStart, self::SyncStart];
    }

    /**
     * 対の終了になる種類(分岐終了・同期終了 = ルートの深さ/定義の管理.md 3 節の G-4・G-5)。
     *
     * @return list<self>
     */
    public static function closing(): array
    {
        return [self::BranchEnd, self::SyncEnd];
    }

    /**
     * ルートの最後(出る辺の無いノード)になれる種類(定義の管理.md 3 節の G-2)。
     *
     * @return list<self>
     */
    public static function terminal(): array
    {
        return [self::Approve, self::Serial, self::Parallel, self::BranchEnd, self::SyncEnd];
    }

    /**
     * 指定するノードになれる種類(承認だけ = 定義の管理.md 3 節の G-10、処理対象者.md 5.1)。
     *
     * @return list<self>
     */
    public static function designating(): array
    {
        return [self::Approve];
    }

    /**
     * 案件の操作の一本道のルートに置ける種類(申請・承認 = ワークフロー/データモデル/表.md 2.3、ルートの深さ/案件の操作/README.md 2.1)。
     *
     * @return list<self>
     */
    public static function linear(): array
    {
        return [self::Apply, self::Approve];
    }

    /**
     * 前処理者を起点にする指定の直前に置けない種類(分岐終了・同期終了・縦配置 = 処理対象者.md 4.4、定義の管理.md 3 節の G-9)。
     *
     * @return list<self>
     */
    public static function previousBlocking(): array
    {
        return [self::BranchEnd, self::SyncEnd, self::Parallel];
    }

    /**
     * 開始の種類の対の終了の種類(分岐開始 → 分岐終了、同期開始 → 同期終了。開始でなければ null = 定義の管理.md 3 節の G-5)。
     */
    public function closingKind(): ?self
    {
        return match ($this) {
            self::BranchStart => self::BranchEnd,
            self::SyncStart => self::SyncEnd,
            default => null,
        };
    }

    /**
     * 渡した集まりに入るか(集まり = この列挙の静的メソッドの結果)。
     *
     * @param  list<self>  $kinds
     */
    public function isIn(array $kinds): bool
    {
        return in_array($this, $kinds, true);
    }
}
