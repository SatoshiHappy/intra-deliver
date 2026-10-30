import { CountedTextInput } from '@/components/CountedTextInput';
import { FormBlock } from '@/components/FormBlock';
import { Button } from '@/components/ui/button';
import {
    DialogClose,
    DialogDescription,
    DialogFooter,
    DialogHeader,
    DialogTitle,
} from '@/components/ui/dialog';
import { CaseActionNodeSelect } from '@/components/Workflow/CaseActionNodeSelect';
import { CaseActionNotices } from '@/components/Workflow/CaseActionNotices';
import {
    AssignFields,
    ReexpandFields,
    RetargetFields,
} from '@/components/Workflow/CaseOperationFields';
import { MoveFields } from '@/components/Workflow/CaseOperationMoveFields';
import { useOperationForm } from '@/hooks/Workflow/use-operation-form';
import type { OperationFormState } from '@/hooks/Workflow/use-operation-form';
import {
    canDecideReexpand,
    nodeDefaultsOf,
    nodeIn,
    nodeSelectOf,
} from '@/lib/Workflow/caseOperations';
import type { OperationTarget } from '@/lib/Workflow/caseOperations';
import {
    OPERATION_TITLES,
    operationSubtitleOf,
} from '@/lib/Workflow/caseOperationTexts';

const COMMENT_ID = 'case-operation-comment';

type Props = {
    target: OperationTarget;
    state: OperationFormState;
};

/**
 * 種類ごとの欄(処理担当者の変更・再展開・ステップの移動・指定の変更)。
 */
function KindFields({ target, state }: Props) {
    const { form, errors } = state;
    const nodeId = form.data.node_id;

    switch (target.kind) {
        case 'retarget':
            return (
                <RetargetFields
                    caseId={target.caseId}
                    node={nodeIn(target.nodes, nodeId)}
                    form={form}
                    errors={errors}
                />
            );
        case 'reexpand':
            return <ReexpandFields node={nodeIn(target.nodes, nodeId)} />;
        case 'move':
            return (
                <MoveFields
                    node={nodeIn(target.nodes, nodeId)}
                    form={form}
                    errors={errors}
                />
            );
        case 'assign':
            return (
                <AssignFields
                    caseId={target.caseId}
                    node={nodeIn(target.nodes, nodeId)}
                    form={form}
                    errors={errors}
                />
            );
    }
}

/**
 * 欄の全体(先頭にステップの選択・種類ごとの欄・コメント)。高さを超えたらここだけが中でスクロールする。
 * ステップを替えたら、そのステップの既定(変更後の人・移動の種類・戻り先)に入れ直す。
 */
function FieldArea({ target, state }: Props) {
    const { form, errors } = state;
    const select = nodeSelectOf(target);

    return (
        <div className="-mx-6 flex min-h-0 flex-col gap-4 overflow-y-auto px-6 py-1">
            {select !== null && (
                <CaseActionNodeSelect
                    label={select.label}
                    nodes={select.choices}
                    value={form.data.node_id}
                    onChange={(value) =>
                        form.setData((data) => ({
                            ...data,
                            node_id: value,
                            ...nodeDefaultsOf(target, value),
                        }))
                    }
                    error={errors.node_id}
                />
            )}
            <KindFields target={target} state={state} />
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
        </div>
    );
}

/**
 * 「キャンセル」「決定」(送信中か、拒む理由があるときは決定を押せない。終了で申請を完了するときは危険な操作の色)。
 */
function Footer({
    isDisabled,
    isDestructive,
}: {
    isDisabled: boolean;
    isDestructive: boolean;
}) {
    return (
        <DialogFooter>
            <DialogClose asChild>
                <Button type="button" variant="outline">
                    キャンセル
                </Button>
            </DialogClose>
            <Button
                type="submit"
                variant={isDestructive ? 'destructive' : 'default'}
                disabled={isDisabled}
            >
                決定
            </Button>
        </DialogFooter>
    );
}

/**
 * 申請操作のダイアログの中身(開いた回ごとに作り直す = 呼ぶ側の key。docs の 設計/ワークフロー/案件の運用/画面/管理と通知の画面.md の 7.2 節の共通):
 * 題・2 行目(申請名とステップ名)・知らせ、欄、「キャンセル」「決定」。題・知らせ・ボタンは動かさず、欄だけが中でスクロールする。
 * 再展開は展開後が 0 人なら「決定」を押せない。
 */
export function CaseOperationForm({
    target,
    openCount,
    onSucceeded,
}: {
    target: OperationTarget;
    openCount: number;
    onSucceeded: (count: number) => void;
}) {
    const state = useOperationForm(target, openCount, onSucceeded);
    const { form, errors, submit } = state;
    const nodes: { nodeId: number; name: string }[] = target.nodes;
    const node = nodeIn(nodes, form.data.node_id);
    const canDecide =
        target.kind !== 'reexpand' ||
        canDecideReexpand(nodeIn(target.nodes, form.data.node_id));
    const isFinish = target.kind === 'move' && form.data.direction === 'finish';

    return (
        <form
            noValidate
            onSubmit={submit}
            className="flex min-h-0 flex-col gap-4"
        >
            <DialogHeader>
                <DialogTitle>{OPERATION_TITLES[target.kind]}</DialogTitle>
                <DialogDescription className="wrap-anywhere">
                    {operationSubtitleOf(
                        target.kind,
                        target.caseName,
                        node?.name ?? '',
                    )}
                </DialogDescription>
            </DialogHeader>
            <CaseActionNotices
                caseError={errors.case ?? errors.revision}
                blockedTitle={undefined}
                blockedReason={null}
            />
            <FieldArea target={target} state={state} />
            <Footer
                isDisabled={form.processing || !canDecide}
                isDestructive={isFinish}
            />
        </form>
    );
}
