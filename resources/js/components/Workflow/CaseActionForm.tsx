import { usePage } from '@inertiajs/react';
import type { ComponentProps } from 'react';
import { Button } from '@/components/ui/button';
import {
    DialogClose,
    DialogDescription,
    DialogFooter,
    DialogHeader,
    DialogTitle,
} from '@/components/ui/dialog';
import {
    CaseActionLeadFields,
    CaseActionMiddleFields,
    CaseActionTailFields,
} from '@/components/Workflow/CaseActionFields';
import { CaseActionNotices } from '@/components/Workflow/CaseActionNotices';
import { useCaseActionForm } from '@/hooks/Workflow/use-case-action-form';
import { showsPrincipalField } from '@/lib/Workflow/caseActionForm';
import { GUARD_TITLES, OPERATION_TEXTS } from '@/lib/Workflow/caseActions';
import type { CaseActionTarget } from '@/lib/Workflow/caseActions';

/**
 * 「キャンセル」「決定」(拒む理由があるか送信中は決定を押せない)。
 */
function CaseActionFooter({
    isDestructive,
    isDisabled,
}: {
    isDestructive: boolean;
    isDisabled: boolean;
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
 * 欄の 3 つ(前半・中盤・後半)。高さを超えたらここだけが中でスクロールし、焦点の輪を切らないよう左右と上下に余白を取る。
 */
function CaseActionFieldArea(
    props: ComponentProps<typeof CaseActionLeadFields>,
) {
    return (
        <div className="-mx-6 flex min-h-0 flex-col gap-4 overflow-y-auto px-6 py-1">
            <CaseActionLeadFields {...props} />
            <CaseActionMiddleFields {...props} />
            <CaseActionTailFields {...props} />
        </div>
    );
}

/**
 * ダイアログの中身(開いた回ごとに作り直す = 呼ぶ側の key。初期値は開いた時の写しから作る = docs の 設計/ワークフロー/画面.md 6 節)。
 * 欄の並び = docs の 設計/ワークフロー/案件の運用/画面/案件の画面.md の 5 節。題・申請名・誤りの文言・ボタンは動かさず、欄だけが中でスクロールする。
 */
export function CaseActionForm({
    target,
    openCount,
    onSucceeded,
}: {
    target: CaseActionTarget;
    openCount: number;
    onSucceeded: (count: number) => void;
}) {
    const state = useCaseActionForm(target, openCount, onSucceeded);
    const { form, errors, node, submit } = state;
    const text = OPERATION_TEXTS[target.operation];
    const blockedTitle = GUARD_TITLES[target.operation];
    const blockedReason =
        blockedTitle === undefined ? null : (node?.blockedReason ?? null);
    const { account } = usePage().props;
    const selfName = account.user?.name ?? account.user?.code ?? '';
    // 処理担当者の欄が無いときの誤り(画面から起きるのは選択肢の古さだけ)は上部に出す
    const hiddenPrincipalError = showsPrincipalField(node)
        ? undefined
        : errors.principal_user_id;
    const fields = { ...state, target, selfName };

    return (
        <form
            noValidate
            onSubmit={submit}
            className="flex min-h-0 flex-col gap-4"
        >
            <DialogHeader>
                <DialogTitle>{text.title}</DialogTitle>
                <DialogDescription className="wrap-anywhere">
                    {target.caseName}
                </DialogDescription>
            </DialogHeader>
            <CaseActionNotices
                caseError={errors.case ?? hiddenPrincipalError}
                blockedTitle={blockedTitle}
                blockedReason={blockedReason}
            />
            <CaseActionFieldArea {...fields} />
            <CaseActionFooter
                isDestructive={text.isDestructive}
                isDisabled={form.processing || blockedReason !== null}
            />
        </form>
    );
}
