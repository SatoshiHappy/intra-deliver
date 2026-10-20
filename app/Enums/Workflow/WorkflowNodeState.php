<?php

namespace App\Enums\Workflow;

/**
 * 案件のノードの状態(docs の 設計/ワークフロー/ルートの深さ/データモデル/README.md 1 節。表 workflow_case_nodes の state)。値は DB の検査制約と同じ一覧。
 * 種類ごとにとりうる状態は検査制約が止める(案件の表.md 3.2)。保留は承認のノードだけ(設計/ワークフロー/案件の運用/データモデル/表.md の 3.2 節)。
 * 状態の集まり(現在のノードの状態・済んだ状態など)はこの列挙のメソッドだけに置く(`WorkflowNodeKind` の種類の集まりと同じ規則)。
 */
enum WorkflowNodeState: string
{
    /** 到達していない */
    case Unreached = 'unreached';

    /** 承認待ち(承認のノードだけ) */
    case Waiting = 'waiting';

    /** 再申請待ち(申請のノードだけ) */
    case Reapply = 'reapply';

    /** 処理済み(処理済みにした履歴の行を指す) */
    case Done = 'done';

    /** 展開中(横配置・縦配置の展開の行が処理の途中) */
    case Running = 'running';

    /** 通過(自分の処理なしに通った) */
    case Passed = 'passed';

    /** 省略(処理されずに終わった) */
    case Skipped = 'skipped';

    /** 保留(承認のノードだけ。処理対象者の行と到達日時を保つ) */
    case Hold = 'hold';

    /**
     * 現在のノードの状態(承認待ち・再申請待ち・保留)。現在のノードは案件の中の、状態がこの一覧に入るノードの集合
     * (ルートの深さ/案件の操作/README.md 1.3、設計/ワークフロー/案件の運用/案件の操作/README.md の 1.3 節)。
     *
     * @return list<self>
     */
    public static function current(): array
    {
        return [self::Waiting, self::Reapply, self::Hold];
    }

    /**
     * 現在のノードの状態か({@see self::current()})。
     */
    public function isCurrent(): bool
    {
        return in_array($this, self::current(), true);
    }

    /**
     * 済んだ状態(処理済み・通過・省略 = ルートの深さ/案件の操作/README.md 1.2。合流の判定と不変の条件に使う)。
     *
     * @return list<self>
     */
    public static function settled(): array
    {
        return [self::Done, self::Passed, self::Skipped];
    }

    /**
     * 省く前になれる状態(到達していない・展開中と、現在のノードの状態の一覧 = README.md 2.1 の「省く」。一覧に足す状態もここに入る)。
     *
     * @return list<self>
     */
    public static function skippable(): array
    {
        return [self::Unreached, self::Running, ...self::current()];
    }

    /**
     * 自分の処理を通った状態(処理済み・通過。省略は含まない = 差戻しの候補になる祖先の状態、ルートの深さ/案件の操作/操作.md 10.4)。
     *
     * @return list<self>
     */
    public static function traversed(): array
    {
        return [self::Done, self::Passed];
    }

    /**
     * 展開の行を作った後の状態(展開中・通過 = 縦配置の合議の親が進み具合を持つ状態。案件の表.md 3.2)。
     *
     * @return list<self>
     */
    public static function expanded(): array
    {
        return [self::Running, self::Passed];
    }

    /**
     * 通過にできる前の状態(到達していない・展開中 = 分岐・同期は到達していないから、横配置・縦配置は展開中から。ルートの深さ/案件の操作/README.md 2.1)。
     *
     * @return list<self>
     */
    public static function passable(): array
    {
        return [self::Unreached, self::Running];
    }

    /**
     * 済んだ状態か({@see self::settled()})。
     */
    public function isSettled(): bool
    {
        return in_array($this, self::settled(), true);
    }
}
