import { useRef, useState } from 'react';
import type { MouseEvent, RefObject } from 'react';
import { Button } from '@/components/ui/button';
import { Dialog, DialogContent } from '@/components/ui/dialog';
import { CaseActionForm } from '@/components/Workflow/CaseActionForm';
import type { PageAttach } from '@/lib/Workflow/caseActionAttach';
import { OPERATION_TEXTS } from '@/lib/Workflow/caseActions';
import type { CaseActionTarget } from '@/lib/Workflow/caseActions';

/** 開くボタンを押したとき(写しと、閉じた後にフォーカスを戻すボタンの要素) */
export type OpenCaseAction = (
    target: CaseActionTarget,
    button: HTMLElement,
) => void;

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
    const definition = OPERATION_TEXTS[target.operation];

    return (
        <Button
            type="button"
            variant={
                placement === 'row'
                    ? 'outline'
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

type DialogProps = ReturnType<typeof useCaseActionDialog>['dialogProps'] & {
    /** ページの今の props から作る添付の読み口(開いた時の写しでなく、誤りの戻りの後の可否を読む) */
    attach: PageAttach;
};

/** 戻し先のボタンが DOM に残っていればフォーカスを戻す */
function focusIfConnected(ref: RefObject<HTMLElement | null>) {
    if (ref.current?.isConnected === true) {
        ref.current.focus();
    }
}

/**
 * 案件の処理のダイアログ(docs の 設計/ワークフロー/画面/申請と案件.md 6 節、足した欄 = docs の 設計/ワークフロー/案件の運用/画面/案件の画面.md の 5 節)。
 * 案件の誤りはリダイレクトで props を描き直すので、ダイアログは描き分けの外(ページの直下)に 1 つ置き、開いた時の写し(target)だけを読む。
 * 閉じた後は、押したボタンが残っていればフォーカスを戻す。
 */
export function CaseActionDialog({
    open,
    target,
    openCount,
    returnFocusTo,
    attach,
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
                className="flex max-h-[90vh] flex-col"
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
                        attach={attach}
                        onSucceeded={onSucceeded}
                    />
                )}
            </DialogContent>
        </Dialog>
    );
}
