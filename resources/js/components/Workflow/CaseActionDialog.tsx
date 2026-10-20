import { useForm } from '@inertiajs/react';
import { useRef, useState } from 'react';
import type { FormEvent, MouseEvent, RefObject } from 'react';
import { CountedTextInput } from '@/components/CountedTextInput';
import { FormBlock } from '@/components/FormBlock';
import { Button } from '@/components/ui/button';
import {
    Dialog,
    DialogClose,
    DialogContent,
    DialogDescription,
    DialogFooter,
    DialogHeader,
    DialogTitle,
} from '@/components/ui/dialog';
import type { PickedAssignee } from '@/components/Workflow/AssigneeField';
import { CaseActionAssigneeFields } from '@/components/Workflow/CaseActionAssigneeFields';
import { CaseActionNodeSelect } from '@/components/Workflow/CaseActionNodeSelect';
import { CaseActionNotices } from '@/components/Workflow/CaseActionNotices';
import { CaseActionSendBackFields } from '@/components/Workflow/CaseActionSendBackFields';
import {
    approve,
    approveEarly,
    pullBack,
    reject,
    sendBack,
    withdraw,
} from '@/routes/workflow/cases';
import type { WorkflowDesignation, WorkflowPullBackNode } from '@/types';
import type { RouteDefinition } from '@/wayfinder';

/** ダイアログで行う処理(詳細の can のキーと同じ名前) */
export type CaseOperation =
    | 'approve'
    | 'approveEarly'
    | 'reject'
    | 'sendBack'
    | 'pullBack'
    | 'withdraw';

/** 処理ごとの開くボタン・題・説明・決定のボタンの見た目・送り先(設計/ワークフロー/画面.md 6 節の表) */
const OPERATIONS: Record<
    CaseOperation,
    {
        trigger: string;
        title: string;
        note: string | null;
        isDestructive: boolean;
        action: (caseId: number) => RouteDefinition<'post'>;
    }
> = {
    approve: {
        trigger: '承認',
        title: '承認する',
        note: null,
        isDestructive: false,
        action: approve,
    },
    approveEarly: {
        trigger: '承認終了',
        title: '承認を終了する',
        note: '後のステップへ進まずに申請を完了します。',
        isDestructive: false,
        action: approveEarly,
    },
    reject: {
        trigger: '否認',
        title: '否認する',
        note: '申請を否認して完了します。この操作は取り消せません。',
        isDestructive: true,
        action: reject,
    },
    sendBack: {
        trigger: '差戻し',
        title: '差戻しする',
        note: null,
        isDestructive: false,
        action: sendBack,
    },
    pullBack: {
        trigger: '引戻し',
        title: '引戻す',
        note: '次のステップの処理対象者が処理する前に、自分のステップへ戻します。',
        isDestructive: false,
        action: pullBack,
    },
    withdraw: {
        trigger: '取止め',
        title: '取止める',
        note: '申請を取止めて完了します。この操作は取り消せません。',
        isDestructive: true,
        action: withdraw,
    },
};

/**
 * ダイアログで選べるステップ 1 つ(承認・承認終了・否認・差戻し = 詳細の nodeActions のうちその操作を許すもの、引戻し = 引戻せるステップ)。
 * blockedReason = 申請者承認防止の文言(承認・承認終了・否認・引戻しで「決定」を押せない)、sendBackTargets = 差戻し先(写した並び。既定は先頭)、
 * sendBackRestricted = 差戻しの制限の 1 行を出すか、isCouncilVote = 縦配置の子(否認の文)、designations = 指定するステップ(承認だけ)。
 */
export type CaseActionNode = {
    id: number;
    name: string;
    blockedReason: string | null;
    sendBackTargets: { id: number; name: string }[];
    sendBackRestricted: boolean;
    isCouncilVote: boolean;
    designations: WorkflowDesignation[];
};

/**
 * 開いた時の対象の写し(開くボタンを押した時の props から取る。開いている間、ダイアログはこれだけを読む)。
 * revision = 画面を開いた時の改訂番号(詳細 = 案件の値、一覧 = 行の値)、commentMaxLength = コメントの文字数の上限(画面の props の値)、nodes = 選べるステップ(node_id で送る。2 つ以上なら選択の欄 = ルートの深さ/画面.md 6 節。
 * 取止めは空 = node_id を送らない)。
 */
export type CaseActionTarget = {
    operation: CaseOperation;
    caseId: number;
    caseName: string;
    revision: number;
    commentMaxLength: number;
    nodes: CaseActionNode[];
};

/**
 * 引戻せるステップから、引戻しのダイアログで選べるステップを作る(申請者承認防止の文言は写す。引戻しは差戻し・指定を持たない)。
 */
export function pullBackNodesOf(
    nodes: WorkflowPullBackNode[],
): CaseActionNode[] {
    return nodes.map((node) => ({
        ...node,
        sendBackTargets: [],
        sendBackRestricted: false,
        isCouncilVote: false,
        designations: [],
    }));
}

/** 開くボタンを押したとき(写しと、閉じた後にフォーカスを戻すボタンの要素) */
export type OpenCaseAction = (
    target: CaseActionTarget,
    button: HTMLElement,
) => void;

/**
 * 送る形 { comment, revision, node_id?, send_back_node_id?, assignments? }(revision は写しの値を送る時に入れる)。
 * assignments = 指定されるステップの id → 選んだ人(送る時にユーザーの id の並びにする)。
 */
type ActionForm = {
    comment: string;
    node_id: string;
    send_back_node_id: string;
    assignments: Record<string, PickedAssignee[]>;
};

const COMMENT_ID = 'case-action-comment';

/** 申請者承認防止で拒まれる操作(処理対象者.md 6.1、引戻し = 操作.md 10.5)と、拒むときの見出し(ルートの深さ/画面.md 6 節) */
const GUARDED: Partial<Record<CaseOperation, string>> = {
    approve: '承認できません',
    approveEarly: '承認できません',
    reject: '承認できません',
    pullBack: '引戻しできません',
};

/** 縦配置の子の否認のダイアログの文(ルートの深さ/画面.md 6 節) */
const COUNCIL_REJECT_NOTE =
    '否認の票を記録します。可決できなくなった場合は、申請を否認して完了します。';

/**
 * ステップの欄の初期値(差戻し先 = 候補の先頭、指定 = 今の指定)。
 */
function nodeDefaults(
    node: CaseActionNode | undefined,
): Pick<ActionForm, 'send_back_node_id' | 'assignments'> {
    return {
        send_back_node_id:
            node === undefined || node.sendBackTargets.length === 0
                ? ''
                : String(node.sendBackTargets[0].id),
        assignments: Object.fromEntries(
            (node?.designations ?? []).map((assigneeField) => [
                String(assigneeField.nodeId),
                assigneeField.initial,
            ]),
        ),
    };
}

/** 選んでいるステップ(id の文字列で引く。無ければ undefined) */
function nodeOf(
    nodes: CaseActionNode[],
    nodeId: string,
): CaseActionNode | undefined {
    return nodes.find((candidate) => String(candidate.id) === nodeId);
}

/**
 * 送る内容。assignments は指定の欄ごとに、選んだ人をユーザーの id の並びにする。
 */
function payloadOf(
    data: ActionForm,
    target: CaseActionTarget,
    assigneeFields: WorkflowDesignation[],
) {
    return {
        comment: data.comment,
        revision: target.revision,
        ...(data.node_id === '' ? {} : { node_id: Number(data.node_id) }),
        ...(target.operation === 'sendBack'
            ? { send_back_node_id: Number(data.send_back_node_id) }
            : {}),
        ...(assigneeFields.length > 0
            ? {
                  assignments: Object.fromEntries(
                      assigneeFields.map((assigneeField) => [
                          String(assigneeField.nodeId),
                          (
                              data.assignments[String(assigneeField.nodeId)] ??
                              []
                          ).map((person) => person.id),
                      ]),
                  ),
              }
            : {}),
    };
}

/**
 * 処理のダイアログを開くボタン(見た目 = 置き場ごと。一覧の行では「<案件名>の<操作>」を名前にする)。押すと写しとボタンの要素を onOpen へ渡す。
 * 呼ぶ側は can のときだけ描く(ダイアログ本体は {@see CaseActionDialog} でページの直下に 1 つ)。
 */
export function CaseActionButton({
    target,
    placement,
    onOpen,
}: {
    target: CaseActionTarget;
    /** 置き場(詳細の見出しの右 = header、一覧の行の操作 = row) */
    placement: 'header' | 'row';
    onOpen: OpenCaseAction;
}) {
    const definition = OPERATIONS[target.operation];

    return (
        <Button
            type="button"
            variant={
                placement === 'row'
                    ? 'ghost'
                    : definition.isDestructive
                      ? 'destructive'
                      : target.operation === 'approve'
                        ? 'default'
                        : 'outline'
            }
            size={placement === 'row' ? 'sm' : 'default'}
            aria-haspopup="dialog"
            aria-label={
                placement === 'row'
                    ? `${target.caseName}の${definition.trigger}`
                    : undefined
            }
            onClick={(event: MouseEvent<HTMLButtonElement>) =>
                onOpen(target, event.currentTarget)
            }
        >
            {definition.trigger}
        </Button>
    );
}

/**
 * ページが持つ処理のダイアログの状態。openAction を開くボタンへ、dialogProps を {@see CaseActionDialog} へ渡す。
 * 閉じるときは open だけを偽にする(閉じる動きの間も題・案件名を描けるように、写しは次に開くときに置き換える)。
 * 成功で閉じるのは、送った時の回の番号が今の番号(useRef)と同じときだけ(useForm は部品が外れた後も onSuccess を呼ぶので、比べるのはページの側)。
 */
export function useCaseActionDialog() {
    const [open, setOpen] = useState(false);
    const [target, setTarget] = useState<CaseActionTarget | null>(null);
    const [openCount, setOpenCount] = useState(0);
    const openCountRef = useRef(0);
    const returnFocusRef = useRef<HTMLElement | null>(null);

    const openAction: OpenCaseAction = (next, button) => {
        openCountRef.current += 1;
        returnFocusRef.current = button;
        setTarget(next);
        setOpenCount(openCountRef.current);
        setOpen(true);
    };

    return {
        openAction,
        dialogProps: {
            open,
            target,
            openCount,
            returnFocusTo: returnFocusRef,
            onClose: () => setOpen(false),
            onSucceeded: (count: number) => {
                if (count === openCountRef.current) {
                    setOpen(false);
                }
            },
        },
    };
}

type DialogProps = ReturnType<typeof useCaseActionDialog>['dialogProps'];

/**
 * 案件の処理のダイアログ(docs の 設計/ワークフロー/画面.md 6 節、足した欄 = ルートの深さ/画面.md 6 節)。
 * 案件の誤りはリダイレクトで props を描き直すので、ダイアログは描き分けの外(ページの直下)に 1 つ置き、開いた時の写し(target)だけを読む。
 * 閉じた後は、押したボタンが残っていればフォーカスを戻す。
 */
export function CaseActionDialog({
    open,
    target,
    openCount,
    returnFocusTo,
    onClose,
    onSucceeded,
}: DialogProps) {
    return (
        <Dialog
            open={open}
            onOpenChange={(next) => {
                if (!next) {
                    onClose();
                }
            }}
        >
            <DialogContent
                onCloseAutoFocus={(event) => {
                    event.preventDefault();
                    focusIfConnected(returnFocusTo);
                }}
            >
                {target !== null && (
                    <CaseActionForm
                        key={openCount}
                        target={target}
                        openCount={openCount}
                        onSucceeded={onSucceeded}
                    />
                )}
            </DialogContent>
        </Dialog>
    );
}

/** 戻し先のボタンが DOM に残っていればフォーカスを戻す */
function focusIfConnected(ref: RefObject<HTMLElement | null>) {
    if (ref.current?.isConnected === true) {
        ref.current.focus();
    }
}

/**
 * ダイアログの中身(開いた回ごとに作り直す = 呼ぶ側の key。初期値は開いた時の写しから作る = 画面.md 6 節)。
 */
function CaseActionForm({
    target,
    openCount,
    onSucceeded,
}: {
    target: CaseActionTarget;
    openCount: number;
    onSucceeded: (count: number) => void;
}) {
    const definition = OPERATIONS[target.operation];
    const form = useForm<ActionForm>({
        comment: '',
        node_id: target.nodes.length > 0 ? String(target.nodes[0].id) : '',
        ...nodeDefaults(target.nodes[0]),
    });
    const errors = form.errors as Record<string, string | undefined>;
    const node = nodeOf(target.nodes, form.data.node_id);
    const blockedTitle = GUARDED[target.operation];
    const blockedReason =
        blockedTitle !== undefined && node !== undefined
            ? node.blockedReason
            : null;
    const assigneeFields =
        target.operation === 'approve' ? (node?.designations ?? []) : [];
    const note =
        target.operation === 'reject' && node?.isCouncilVote === true
            ? COUNCIL_REJECT_NOTE
            : definition.note;

    const submit = (event: FormEvent) => {
        event.preventDefault();
        form.transform((data) => payloadOf(data, target, assigneeFields));
        form.submit(definition.action(target.caseId), {
            preserveScroll: true,
            onSuccess: () => onSucceeded(openCount),
        });
    };

    return (
        <form noValidate onSubmit={submit} className="grid gap-4">
            <DialogHeader>
                <DialogTitle>{definition.title}</DialogTitle>
                <DialogDescription className="wrap-anywhere">
                    {target.caseName}
                </DialogDescription>
            </DialogHeader>
            <CaseActionNotices
                caseError={errors.case}
                blockedTitle={blockedTitle}
                blockedReason={blockedReason}
            />
            {target.nodes.length > 1 && (
                <CaseActionNodeSelect
                    label={
                        target.operation === 'pullBack'
                            ? '引戻すステップ'
                            : '処理するステップ'
                    }
                    nodes={target.nodes}
                    value={form.data.node_id}
                    onChange={(value) =>
                        form.setData((data) => ({
                            ...data,
                            node_id: value,
                            ...nodeDefaults(nodeOf(target.nodes, value)),
                        }))
                    }
                    error={errors.node_id}
                />
            )}
            {note !== null && <p className="text-sm">{note}</p>}
            <CaseActionAssigneeFields
                caseId={target.caseId}
                assigneeFields={assigneeFields}
                assignments={form.data.assignments}
                errors={errors}
                onChange={(nodeId, picked) =>
                    form.setData((data) => ({
                        ...data,
                        assignments: {
                            ...data.assignments,
                            [String(nodeId)]: picked,
                        },
                    }))
                }
            />
            {target.operation === 'sendBack' && (
                <CaseActionSendBackFields
                    targets={node?.sendBackTargets ?? []}
                    isRestricted={node?.sendBackRestricted === true}
                    value={form.data.send_back_node_id}
                    onChange={(value) =>
                        form.setData('send_back_node_id', value)
                    }
                    error={errors.send_back_node_id}
                />
            )}
            <FormBlock
                id={COMMENT_ID}
                label="コメント"
                help="任意の入力です。"
                error={errors.comment}
            >
                <CountedTextInput
                    id={COMMENT_ID}
                    isMultiline
                    value={form.data.comment}
                    max={target.commentMaxLength}
                    invalid={errors.comment !== undefined}
                    hasHelp
                    onChange={(event) =>
                        form.setData('comment', event.target.value)
                    }
                />
            </FormBlock>
            <DialogFooter>
                <DialogClose asChild>
                    <Button type="button" variant="outline">
                        キャンセル
                    </Button>
                </DialogClose>
                <Button
                    type="submit"
                    variant={
                        definition.isDestructive ? 'destructive' : 'default'
                    }
                    disabled={form.processing || blockedReason !== null}
                >
                    決定
                </Button>
            </DialogFooter>
        </form>
    );
}
