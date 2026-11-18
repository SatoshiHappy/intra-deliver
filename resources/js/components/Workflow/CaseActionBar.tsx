import { Link, usePage } from '@inertiajs/react';
import { ActionBar } from '@/components/ActionBar';
import { Button } from '@/components/ui/button';
import { CaseActionButton } from '@/components/Workflow/CaseActionDialog';
import type { OpenCaseAction } from '@/components/Workflow/CaseActionDialog';
import { CaseConfirmButton } from '@/components/Workflow/CaseConfirmButton';
import { CaseOperationMenu } from '@/components/Workflow/CaseOperationMenu';
import { useEdgeBelow } from '@/hooks/use-edge-below';
import type { OpenCaseOperation } from '@/hooks/Workflow/use-operation-dialog';
import { caseBarOf } from '@/lib/Workflow/caseBar';
import {
    caseActionTargetOf,
    processButtonsOf,
} from '@/lib/Workflow/caseActions';
import type { ProcessButton } from '@/lib/Workflow/caseActions';
import { reapplicationHrefOf } from '@/lib/Workflow/applyRoutes';
import type { WorkflowCaseShowProps } from '@/types';

type Props = Pick<
    WorkflowCaseShowProps,
    | 'case'
    | 'can'
    | 'nodeActions'
    | 'pullBackNodes'
    | 'casePrincipal'
    | 'commentMaxLength'
    | 'operation'
> & {
    onOpen: OpenCaseAction;
    onOpenOperation: OpenCaseOperation;
};

/** 再申請の画面へのボタン(今の URL を読むのはこのボタンだけ) */
function ReapplyButton({ caseId }: { caseId: number }) {
    const { url } = usePage();

    return (
        <Button asChild>
            <Link href={reapplicationHrefOf(caseId, url)}>再申請</Link>
        </Button>
    );
}

/**
 * 処理のボタン 1 つ(ダイアログを開く・再申請の画面へのリンク・押すと送る確認)。
 */
function BarButton({
    button,
    show,
    onOpen,
}: {
    button: ProcessButton;
    show: Omit<Props, 'onOpen' | 'onOpenOperation'>;
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
            return <ReapplyButton caseId={show.case.id} />;
        case 'confirm':
            return (
                <CaseConfirmButton caseId={show.case.id} variant="outline" />
            );
    }
}

/** 右寄せの処理のボタンの並び({@link processButtonsOf}) */
function ProcessButtons({
    buttons,
    show,
    onOpen,
}: {
    buttons: ProcessButton[];
    show: Omit<Props, 'onOpen' | 'onOpenOperation'>;
    onOpen: OpenCaseAction;
}) {
    return (
        <div
            role="group"
            aria-label="処理"
            className="flex flex-wrap items-center justify-end gap-2"
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

/**
 * 下に固定した処理のボタンの帯(docs の 設計/業務データの入れ物/レコードの共通機能/詳細の画面/申請の詳細.md の 1 節、画面の意匠の方針 14)。
 * 詳細のカードの下端の中に置き(CaseRecordFrame の MainCard が受ける)、カードの左右の余白(px-5)を打ち消してカードの幅いっぱいにする。
 * 影はカードの下端の印が画面に見えないときだけ付ける(帯の下にまだ中身が続く合図。印を置くためカードの section は relative)。
 */
export function CaseActionBar({ onOpen, onOpenOperation, ...show }: Props) {
    const { markRef, isBelow } = useEdgeBelow();
    const buttons = processButtonsOf(show);
    const parts = caseBarOf(buttons.length > 0, show.operation);

    if (parts === null) {
        return null;
    }

    const bar = (
        <ActionBar
            raised={isBelow}
            className="sticky bottom-0 z-10 -mx-5 flex-wrap"
            start={
                parts.operate === null ? undefined : (
                    <CaseOperationMenu
                        case={show.case}
                        nodeActions={show.nodeActions}
                        commentMaxLength={show.commentMaxLength}
                        operation={parts.operate}
                        onOpenOperation={onOpenOperation}
                        onOpenAction={onOpen}
                    />
                )
            }
        >
            {parts.process && (
                <ProcessButtons buttons={buttons} show={show} onOpen={onOpen} />
            )}
        </ActionBar>
    );

    return (
        <>
            {bar}
            <div
                ref={markRef}
                aria-hidden="true"
                className="pointer-events-none absolute inset-x-0 bottom-0 h-px"
            />
        </>
    );
}
