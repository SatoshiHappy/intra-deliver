import { useRef, useState } from 'react';
import type { RefObject } from 'react';
import type { OperationTarget } from '@/lib/Workflow/caseOperations';

/** 開くボタンを押したとき(対象の写しと、閉じた後にフォーカスを戻すボタンの要素) */
export type OpenCaseOperation = (
    target: OperationTarget,
    button: HTMLElement,
) => void;

/** {@see CaseOperationDialog} が受ける値 */
export type OperationDialogProps = {
    open: boolean;
    target: OperationTarget | null;
    openCount: number;
    returnFocusTo: RefObject<HTMLElement | null>;
    onClose: () => void;
    onSucceeded: (count: number) => void;
};

/**
 * ページが持つ申請操作のダイアログの状態(処理のダイアログと同じ形 = {@see useCaseActionDialog})。開いた時の対象の写しだけをダイアログが読む
 * (誤りの後に props が替わっても、選択肢が動かない)。閉じるときは open だけを偽にし、成功で閉じるのは送った回の番号が今の番号のときだけ。
 */
export function useOperationDialog(): {
    openOperation: OpenCaseOperation;
    dialogProps: OperationDialogProps;
} {
    const [open, setOpen] = useState(false);
    const [target, setTarget] = useState<OperationTarget | null>(null);
    const [openCount, setOpenCount] = useState(0);
    const openCountRef = useRef(0);
    const returnFocusRef = useRef<HTMLElement | null>(null);

    const openOperation: OpenCaseOperation = (next, button) => {
        openCountRef.current += 1;
        returnFocusRef.current = button;
        setTarget(next);
        setOpenCount(openCountRef.current);
        setOpen(true);
    };

    return {
        openOperation,
        dialogProps: {
            open,
            target,
            openCount,
            returnFocusTo: returnFocusRef,
            onClose: () => setOpen(false),
            onSucceeded: (count) => {
                if (count === openCountRef.current) {
                    setOpen(false);
                }
            },
        },
    };
}
