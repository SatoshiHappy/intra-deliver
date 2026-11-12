import { ChevronRight } from 'lucide-react';
import { useState } from 'react';
import { RecordValue } from '@/components/RecordValue';
import { ValueChange } from '@/components/ValueChange';
import { Button } from '@/components/ui/button';
import {
    Collapsible,
    CollapsibleContent,
    CollapsibleTrigger,
} from '@/components/ui/collapsible';
import {
    Dialog,
    DialogContent,
    DialogDescription,
    DialogFooter,
    DialogHeader,
    DialogTitle,
} from '@/components/ui/dialog';
import {
    Table,
    TableBody,
    TableCell,
    TableHead,
    TableHeader,
    TableRow,
} from '@/components/ui/table';
import type {
    ShownRow,
    ApplicationTargetChange,
} from '@/lib/Workflow/applicationTargetSnapshot';
import { valueFields } from '@/lib/recordForm';
import { cn } from '@/lib/utils';
import {
    UNSET_VALUE,
    VALUE_AFTER_CLASS,
    VALUE_BEFORE_CLASS,
} from '@/lib/valueChange';
import type { RecordModuleDefinition } from '@/types';

/** 明細の行を開閉する見出しの見た目(明細の表の開閉と同じ作り) */
const TRIGGER_CLASS = cn(
    'group flex items-center gap-1 rounded-sm hover:underline',
    'focus-visible:ring-[3px] focus-visible:ring-ring/50 focus-visible:outline-none',
);

type DetailsChange = Extract<ApplicationTargetChange, { kind: 'details' }>;

/** 明細の行の表(列 = 明細の項目)。行が無ければ「未設定」だけを出す(見せ方 = docs の 設計/画面の意匠/README.md の採った方針 15) */
function RowsTable({
    title,
    child,
    rows,
    cellClass,
}: {
    title: string;
    child: RecordModuleDefinition;
    rows: ShownRow[];
    /** 今の行(前)か反映する行(後)かの見分け */
    cellClass: string;
}) {
    return (
        <div className="flex flex-col gap-1">
            <p className="text-xs text-muted-foreground">{title}</p>
            {rows.length === 0 ? (
                <p className={cn('text-sm', cellClass)}>{UNSET_VALUE}</p>
            ) : (
                <ValuesTable
                    fields={valueFields(child)}
                    rows={rows}
                    cellClass={cellClass}
                />
            )}
        </div>
    );
}

/** 明細の行の値の表(列 = 値の項目) */
function ValuesTable({
    fields,
    rows,
    cellClass,
}: {
    fields: ReturnType<typeof valueFields>;
    rows: ShownRow[];
    cellClass: string;
}) {
    return (
        <Table>
            <TableHeader>
                <TableRow>
                    {fields.map((field) => (
                        <TableHead key={field.code}>{field.name}</TableHead>
                    ))}
                </TableRow>
            </TableHeader>
            <TableBody>
                {rows.map((row, index) => (
                    <TableRow key={index}>
                        {fields.map((field) => (
                            <TableCell
                                key={field.code}
                                className={cn(
                                    'wrap-anywhere whitespace-normal',
                                    cellClass,
                                )}
                            >
                                <RecordValue
                                    field={field}
                                    value={row[field.code] ?? null}
                                    place="diff"
                                />
                            </TableCell>
                        ))}
                    </TableRow>
                ))}
            </TableBody>
        </Table>
    );
}

/** 明細の項目の行(開閉できる見出しと、今の行・反映する行の 2 つの表) */
function DetailsRow({ change }: { change: DetailsChange }) {
    const summary = `${change.current.length} 行 → ${change.next.length} 行`;

    return (
        <TableRow>
            <TableCell className="align-top">{change.field.name}</TableCell>
            <TableCell className="whitespace-normal">
                <Collapsible className="flex flex-col gap-2">
                    <CollapsibleTrigger className={TRIGGER_CLASS}>
                        <ChevronRight
                            aria-hidden="true"
                            className="size-4 transition-transform group-data-[state=open]:rotate-90"
                        />
                        {summary}
                    </CollapsibleTrigger>
                    <CollapsibleContent className="flex flex-col gap-3">
                        <RowsTable
                            title="今の行"
                            child={change.child}
                            rows={change.current}
                            cellClass={VALUE_BEFORE_CLASS}
                        />
                        <RowsTable
                            title="反映する行"
                            child={change.child}
                            rows={change.next}
                            cellClass={VALUE_AFTER_CLASS}
                        />
                    </CollapsibleContent>
                </Collapsible>
            </TableCell>
        </TableRow>
    );
}

/** 項目の行 */
function ValueRow({ change }: { change: ApplicationTargetChange }) {
    if (change.kind === 'details') {
        return <DetailsRow change={change} />;
    }

    return (
        <TableRow>
            <TableCell className="align-top">{change.field.name}</TableCell>
            <TableCell className="align-top whitespace-normal">
                <ValueChange
                    field={change.field}
                    before={change.current}
                    after={change.next}
                />
            </TableCell>
        </TableRow>
    );
}

/** 写すと変わる項目の表(項目と、今の値 → 反映する値) */
function ChangesTable({ changes }: { changes: ApplicationTargetChange[] }) {
    return (
        <Table>
            <TableHeader>
                <TableRow>
                    <TableHead>項目</TableHead>
                    <TableHead>今の値 → 反映する値</TableHead>
                </TableRow>
            </TableHeader>
            <TableBody>
                {changes.map((change) => (
                    <ValueRow key={change.field.code} change={change} />
                ))}
            </TableBody>
        </Table>
    );
}

/**
 * 申請対象の値を写す前の確かめのダイアログ(docs の 設計/ワークフロー/申請の周辺/画面.md の 5 節。文言は同じ文書の 8 節)。
 * 閉じる操作はどれもキャンセルと同じ。長い表でも題とボタンが見えるよう、表だけを縦にスクロールさせる。
 */
export function ApplicationTargetConfirmDialog({
    changes,
    onCancel,
    onReflect,
}: {
    /** 写すと変わる項目。null = ダイアログを出さない */
    changes: ApplicationTargetChange[] | null;
    onCancel: () => void;
    onReflect: () => void;
}) {
    // 閉じるアニメーションの間は、最後の表を保つ(changes が先に null になるため)
    const [kept, setKept] = useState<ApplicationTargetChange[]>([]);

    if (changes !== null && changes !== kept) {
        setKept(changes);
    }

    return (
        <Dialog
            open={changes !== null}
            onOpenChange={(next) => {
                if (!next) {
                    onCancel();
                }
            }}
        >
            <DialogContent className="flex max-h-[90vh] flex-col sm:max-w-3xl">
                <DialogHeader>
                    <DialogTitle>申請対象の値を反映しますか？</DialogTitle>
                    <DialogDescription>
                        次の項目の値が、申請対象の値に変わります。
                    </DialogDescription>
                </DialogHeader>
                <div className="min-h-0 overflow-y-auto">
                    <ChangesTable changes={changes ?? kept} />
                </div>
                <DialogFooter>
                    <Button type="button" variant="outline" onClick={onCancel}>
                        キャンセル
                    </Button>
                    <Button type="button" onClick={onReflect}>
                        反映
                    </Button>
                </DialogFooter>
            </DialogContent>
        </Dialog>
    );
}
