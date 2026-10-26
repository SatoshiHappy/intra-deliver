import { router } from '@inertiajs/react';
import { useState } from 'react';
import { Button } from '@/components/ui/button';
import {
    Dialog,
    DialogClose,
    DialogContent,
    DialogDescription,
    DialogFooter,
    DialogHeader,
    DialogTitle,
    DialogTrigger,
} from '@/components/ui/dialog';
import { Label } from '@/components/ui/label';
import { RadioGroup, RadioGroupItem } from '@/components/ui/radio-group';
import { recordApplyDialogTextOf } from '@/lib/Records/recordApply';
import type { RecordApplyEntry } from '@/types';

/**
 * フローの行(名前・説明)のラジオ。選びは行の URL で持つ。移動中は選び直せない。
 */
function FlowRows({
    entries,
    selected,
    moving,
    onSelect,
}: {
    entries: RecordApplyEntry[];
    selected: string;
    moving: boolean;
    onSelect: (url: string) => void;
}) {
    return (
        <RadioGroup
            value={selected}
            onValueChange={onSelect}
            disabled={moving}
            aria-label="申請するフロー"
            className="gap-2"
        >
            {entries.map((entry, index) => (
                <div
                    key={entry.url}
                    className="flex items-start gap-3 rounded-md border p-3"
                >
                    <RadioGroupItem
                        id={`record-apply-flow-${index}`}
                        value={entry.url}
                        className="mt-1"
                    />
                    <Label
                        htmlFor={`record-apply-flow-${index}`}
                        className="flex flex-col items-start gap-1"
                    >
                        <span className="wrap-anywhere">{entry.name}</span>
                        {entry.description !== null && (
                            <span className="font-normal wrap-anywhere text-muted-foreground">
                                {entry.description}
                            </span>
                        )}
                    </Label>
                </div>
            ))}
        </RadioGroup>
    );
}

/**
 * ダイアログの下のボタン。移動の途中(応答を待つ間)は「選択」もキャンセルも押せなくする。
 */
function FlowFooter({
    chosen,
    moving,
    onMove,
}: {
    chosen: RecordApplyEntry | undefined;
    moving: boolean;
    onMove: (entry: RecordApplyEntry) => void;
}) {
    return (
        <DialogFooter>
            <DialogClose asChild>
                <Button type="button" variant="outline" disabled={moving}>
                    キャンセル
                </Button>
            </DialogClose>
            <Button
                type="button"
                disabled={chosen === undefined || moving}
                onClick={() => {
                    if (chosen !== undefined) {
                        onMove(chosen);
                    }
                }}
            >
                選択
            </Button>
        </DialogFooter>
    );
}

/**
 * 選んだ行と移動の状態。移動(応答を待つ間)が終わるまで、続けて移動させない。
 */
function useFlowChoice(entries: RecordApplyEntry[]) {
    const [selected, setSelected] = useState('');
    const [moving, setMoving] = useState(false);
    const chosen = entries.find((entry) => entry.url === selected);
    const move = (entry: RecordApplyEntry) => {
        if (moving) {
            return;
        }

        setMoving(true);
        router.visit(entry.url, { onFinish: () => setMoving(false) });
    };

    return { selected, setSelected, chosen, moving, move };
}

/**
 * フローを選ぶダイアログ(docs の 設計/ワークフロー/申請の周辺/画面.md の 5 節)。選びは閉じると空に戻る。
 */
export function RecordApplyFlowDialog({
    entries,
    heading,
}: {
    entries: RecordApplyEntry[];
    /** 業務データの見出し(本文に入れる) */
    heading: string;
}) {
    const [open, setOpen] = useState(false);
    const { selected, setSelected, chosen, moving, move } =
        useFlowChoice(entries);
    const text = recordApplyDialogTextOf(heading, entries.length);

    return (
        <Dialog
            open={open}
            onOpenChange={(next) => {
                // 移動の途中は、Escape・外側の押下・閉じるボタンでも閉じない(閉じたのに移動する形にしない)
                if (moving) {
                    return;
                }

                setOpen(next);
                setSelected('');
            }}
        >
            <DialogTrigger asChild>
                <Button variant="outline">申請する</Button>
            </DialogTrigger>
            <DialogContent showCloseButton={!moving}>
                <DialogHeader>
                    <DialogTitle>{text.title}</DialogTitle>
                    <DialogDescription className="wrap-anywhere">
                        {text.description}
                    </DialogDescription>
                </DialogHeader>
                <FlowRows
                    entries={entries}
                    selected={selected}
                    moving={moving}
                    onSelect={setSelected}
                />
                <FlowFooter chosen={chosen} moving={moving} onMove={move} />
            </DialogContent>
        </Dialog>
    );
}
