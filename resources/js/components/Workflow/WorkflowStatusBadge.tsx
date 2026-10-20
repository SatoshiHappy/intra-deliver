import { StatusBadge } from '@/components/StatusBadge';
import { cn } from '@/lib/utils';
import { ACTION_LABELS } from '@/lib/Workflow/workflowLabels';
import type {
    StatusTone,
    WorkflowAction,
    WorkflowCaseState,
    WorkflowCompletionReason,
    WorkflowStepState,
} from '@/types';

/** 完了の理由ごとの語・色・印(結果には ✓ / × = 意匠の 1.2) */
const COMPLETED: Record<
    WorkflowCompletionReason,
    { label: string; tone: StatusTone; mark: string | null }
> = {
    approved: { label: '完了(承認)', tone: 'settled', mark: '✓' },
    approved_early: { label: '完了(承認終了)', tone: 'settled', mark: '✓' },
    rejected: { label: '完了(否認)', tone: 'rejected', mark: '×' },
    withdrawn: { label: '完了(取止め)', tone: 'settled', mark: null },
};

/** 履歴の処理の種別ごとの色と印(設計/ワークフロー/画面.md 8 節の「履歴の処理の種別の札」。ここに無い種別 = 申請・再申請・取止めは処理済みの色で印なし) */
const HISTORY_ACTIONS: Partial<
    Record<WorkflowAction, { tone: StatusTone; mark: string | null }>
> = {
    approve: { tone: 'settled', mark: '✓' },
    approve_early: { tone: 'settled', mark: '✓' },
    reject: { tone: 'rejected', mark: '×' },
    send_back: { tone: 'returned', mark: null },
    pull_back: { tone: 'returned', mark: null },
};

/**
 * 案件の状態の札: 一時保存・承認待ち・再申請待ち・完了(理由)(設計/ワークフロー/認可と一覧.md 3.2 の行の状態)。
 */
export function CaseStateBadge({
    state,
    completionReason,
    currentNodeState,
}: {
    state: WorkflowCaseState;
    completionReason: WorkflowCompletionReason | null;
    currentNodeState: 'waiting' | 'reapply' | null;
}) {
    if (state === 'draft') {
        return <StatusBadge label="一時保存" tone="settled" />;
    }

    if (state === 'completed' && completionReason !== null) {
        return <StatusBadge {...COMPLETED[completionReason]} />;
    }

    return currentNodeState === 'reapply' ? (
        <StatusBadge label="再申請待ち" tone="returned" />
    ) : (
        <StatusBadge label="承認待ち" tone="pending" />
    );
}

/**
 * 処理済みのノード(「処理済み(承認)」など。処理済みの色。差戻し元は到達していないに戻るので「処理済み(差戻し)」は出ない = ルートの深さ/案件の操作/操作.md 10.4)と、
 * 履歴の処理の種別(差戻し・引戻しは戻った状態の色)の札。
 */
export function ProcessedBadge({
    action,
    prefix = '',
}: {
    action: WorkflowAction;
    /** 種別の前に付ける語(フロー情報 =「処理済み」、履歴 = なし) */
    prefix?: string;
}) {
    if (prefix !== '') {
        // フロー情報の「処理済み(<種別>)」は種別によらず処理済みの色で印なし(設計/ワークフロー/画面.md 8 節)
        return (
            <StatusBadge
                label={`${prefix}(${ACTION_LABELS[action]})`}
                tone="settled"
            />
        );
    }

    const { tone, mark } = HISTORY_ACTIONS[action] ?? {
        tone: 'settled',
        mark: null,
    };

    return (
        <StatusBadge label={ACTION_LABELS[action]} tone={tone} mark={mark} />
    );
}

/** ステップの状態ごとの札の色(到達していない・省略は札にしない = ルートの深さ/画面.md 8 節) */
const STEP_TONES: Record<WorkflowStepState['state'], StatusTone | null> = {
    done: 'settled',
    current: 'pending',
    returned: 'returned',
    upcoming: null,
    skipped: null,
};

/**
 * ステップの状態の札(フロー図・フロー情報の表・合議の進み具合)。語はサーバーの写しの表(stepState.label)をそのまま出す。到達していない・省略は札にせず文字だけ。
 */
export function StepStateBadge({
    stepState,
    className,
}: {
    stepState: WorkflowStepState;
    /** 札にしない語(到達していない・省略)の文字の書式(大きさは呼び手が決める。表は段 2 のまま本文の大きさ) */
    className?: string;
}) {
    const tone = STEP_TONES[stepState.state];

    return tone === null ? (
        <span className={cn('text-muted-foreground', className)}>
            {stepState.label}
        </span>
    ) : (
        <StatusBadge label={stepState.label} tone={tone} />
    );
}

/**
 * 案件のステップの札(フロー情報の表・図の箱・合議の行の 1 か所): 処理済み(処理の種別がある)は種別によらず「処理済み(<種別>)」(段 2 の表の札と同じ形)、
 * ほかは写しの表の語のまま({@link StepStateBadge})。
 */
export function CaseStepBadge({
    stepState,
    action,
    className,
}: {
    stepState: WorkflowStepState;
    action: WorkflowAction | null;
    /** 札にしない語の文字の書式({@link StepStateBadge}) */
    className?: string;
}) {
    return action === null ? (
        <StepStateBadge stepState={stepState} className={className} />
    ) : (
        <ProcessedBadge action={action} prefix={stepState.label} />
    );
}
