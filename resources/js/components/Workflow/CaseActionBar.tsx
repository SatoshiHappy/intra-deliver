import { Link } from '@inertiajs/react';
import { Button } from '@/components/ui/button';
import { CaseActionButton } from '@/components/Workflow/CaseActionDialog';
import type { OpenCaseAction } from '@/components/Workflow/CaseActionDialog';
import { CaseConfirmButton } from '@/components/Workflow/CaseConfirmButton';
import {
    caseActionTargetOf,
    processButtonsOf,
} from '@/lib/Workflow/caseActions';
import type { ProcessButton } from '@/lib/Workflow/caseActions';
import { cn } from '@/lib/utils';
import { create as reapply } from '@/routes/workflow/reapplications';
import type { WorkflowCaseShowProps } from '@/types';

type Props = Pick<
    WorkflowCaseShowProps,
    | 'case'
    | 'can'
    | 'nodeActions'
    | 'pullBackNodes'
    | 'casePrincipal'
    | 'commentMaxLength'
> & { onOpen: OpenCaseAction };

/**
 * 処理のボタン 1 つ(ダイアログを開く・再申請の画面へのリンク・押すと送る確認)。
 */
function BarButton({
    button,
    show,
    onOpen,
}: {
    button: ProcessButton;
    show: Omit<Props, 'onOpen'>;
    onOpen: OpenCaseAction;
}) {
    switch (button.kind) {
        case 'dialog':
            return (
                <CaseActionButton
                    target={caseActionTargetOf(button.operation, {
                        ...show,
                        caseId: show.case.id,
                        caseName: show.case.name,
                        revision: show.case.revision,
                    })}
                    placement="header"
                    onOpen={onOpen}
                />
            );
        case 'reapply':
            return (
                <Button asChild>
                    <Link href={reapply(show.case.id)}>再申請</Link>
                </Button>
            );
        case 'confirm':
            return (
                <CaseConfirmButton caseId={show.case.id} variant="outline" />
            );
    }
}

/**
 * 処理のボタンの帯(詳細のカードの列の幅で画面の下に固定し、スクロールしても見える。並び = {@link processButtonsOf}。
 * docs の 設計/ワークフロー/案件の運用/画面/案件の画面.md の 4 節)。処理のボタンが無ければ帯を出さない。
 */
export function CaseActionBar({ onOpen, ...show }: Props) {
    const buttons = processButtonsOf(show);

    if (buttons.length === 0) {
        return null;
    }

    return (
        <div
            role="group"
            aria-label="処理"
            className={cn(
                'sticky bottom-0 z-10 mt-4 flex flex-wrap items-center justify-end gap-2',
                'border-t bg-background py-3',
            )}
        >
            {buttons.map((button) => (
                <BarButton
                    key={
                        button.kind === 'dialog'
                            ? button.operation
                            : button.kind
                    }
                    button={button}
                    show={show}
                    onOpen={onOpen}
                />
            ))}
        </div>
    );
}
