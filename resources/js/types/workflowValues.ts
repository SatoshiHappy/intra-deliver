/**
 * サーバーの列挙(App\Enums\Workflow)を写す値の型。値は列挙と同じ一覧で、tests/Architecture/TypeScriptUnionMirrorTest.php が照らす。
 */

export type WorkflowCaseState = 'draft' | 'active' | 'completed';
export type WorkflowCompletionReason =
    | 'approved'
    | 'approved_early'
    | 'rejected'
    | 'withdrawn';
export type WorkflowNodeState =
    | 'unreached'
    | 'waiting'
    | 'reapply'
    | 'done'
    | 'running'
    | 'passed'
    | 'skipped'
    | 'hold';
export type WorkflowNodeKind =
    | 'apply'
    | 'approve'
    | 'serial'
    | 'parallel'
    | 'branch_start'
    | 'branch_end'
    | 'sync_start'
    | 'sync_end';
export type WorkflowAction =
    | 'apply'
    | 'reapply'
    | 'approve'
    | 'approve_early'
    | 'reject'
    | 'send_back'
    | 'pull_back'
    | 'withdraw'
    | 'hold'
    | 'release'
    | 'transfer'
    | 'retarget'
    | 'reexpand'
    | 'move_back'
    | 'move_forward'
    | 'move_finish'
    | 'reassign';
