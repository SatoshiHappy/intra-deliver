import { Link } from '@inertiajs/react';
import { Button } from '@/components/ui/button';
import { CaseActionButton } from '@/components/Workflow/CaseActionDialog';
import type { OpenCaseAction } from '@/components/Workflow/CaseActionDialog';
import { CaseConfirmButton } from '@/components/Workflow/CaseConfirmButton';
import {
    caseActionTargetOf,
    headerButtonsOf,
} from '@/lib/Workflow/caseActions';
import type { HeaderButton } from '@/lib/Workflow/caseActions';
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
 * 見出しの右のボタン 1 つ(ダイアログを開く・再申請の画面へのリンク・押すと送る確認)。
 */
function BarButton({
    button,
    show,
    onOpen,
}: {
    button: HeaderButton;
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
 * 見出しの右のボタンの並び(どれかのステップで許される操作だけ。並び = docs の 設計/ワークフロー/案件の運用/画面/案件の画面.md の 4 節。
 * 持ち主は {@link headerButtonsOf})。押すと処理のダイアログ、再申請は再申請の画面、確認は押すと送る。
 */
export function CaseActionBar({ onOpen, ...show }: Props) {
    const buttons = headerButtonsOf(show);

    if (buttons.length === 0) {
        return null;
    }

    return (
        <div className="flex flex-wrap items-center gap-2">
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
