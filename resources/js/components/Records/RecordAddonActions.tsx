import { createElement, Suspense, useEffect, useState } from 'react';
import { recordAddonDialog } from '@/components/Records/addonDialogs';
import { Button } from '@/components/ui/button';
import {
    Tooltip,
    TooltipContent,
    TooltipTrigger,
} from '@/components/ui/tooltip';
import {
    addonActionState,
    shownAddonActions,
} from '@/lib/Records/recordAddons';
import type { RecordAddonAction } from '@/types';

/**
 * 操作 1 つ(押すと登録した部品を open で開く)。押せないボタンはポインターの出来事を受けないので、Tooltip の受け口は包みの span にする。
 * 部品は閉じている間も描く(遅延で読み込む部品は、ボタンを描いた時に読み込みが始まる)。
 */
function AddonActionButton({
    action,
    held,
}: {
    action: RecordAddonAction;
    held: boolean;
}) {
    const [open, setOpen] = useState(false);
    const state = addonActionState(action, held);
    const dialog = recordAddonDialog(action.dialog);
    const button = (
        <Button
            variant="outline"
            disabled={state.disabled}
            onClick={() => setOpen(true)}
        >
            {action.label}
        </Button>
    );

    return (
        <>
            {state.tooltip === null ? (
                button
            ) : (
                <Tooltip>
                    <TooltipTrigger asChild>
                        <span tabIndex={0}>{button}</span>
                    </TooltipTrigger>
                    <TooltipContent>{state.tooltip}</TooltipContent>
                </Tooltip>
            )}
            {/* 部品は遅延で読み込まれうるので、読み込みの間は何も描かない(ボタンは包まない) */}
            {dialog !== undefined && (
                <Suspense fallback={null}>
                    {createElement(dialog, {
                        open,
                        onOpenChange: setOpen,
                        values: action.props,
                    })}
                </Suspense>
            )}
        </>
    );
}

/**
 * 後ろのモジュールが足す操作のボタン(docs の 設計/業務データの入れ物/画面/業務の画面.md の 2.5)。held = 詳細の持ち主(申請中)の有無
 * (一覧は false)。
 */
export function RecordAddonActions({
    actions,
    held,
}: {
    actions: RecordAddonAction[];
    held: boolean;
}) {
    const { shown, missing } = shownAddonActions(
        actions,
        (name) => recordAddonDialog(name) !== undefined,
    );
    const missingKey = missing.join(',');

    useEffect(() => {
        for (const name of missingKey === '' ? [] : missingKey.split(',')) {
            console.error(`後ろのモジュールの部品 ${name} が登録されていない`);
        }
    }, [missingKey]);

    return shown.map((action) => (
        <AddonActionButton key={action.code} action={action} held={held} />
    ));
}
