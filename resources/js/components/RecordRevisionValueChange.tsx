import { ArrowRight } from 'lucide-react';
import { RecordValue } from '@/components/RecordValue';
import { Button } from '@/components/ui/button';
import {
    Dialog,
    DialogContent,
    DialogDescription,
    DialogHeader,
    DialogTitle,
    DialogTrigger,
} from '@/components/ui/dialog';
import { revisionFieldDefinition } from '@/lib/recordRevisions';
import { revisionActorText, revisionOperation } from '@/lib/recordShow';
import { EMPTY_VALUE, formatDatetime } from '@/lib/recordValues';
import type {
    RecordFieldValue,
    RecordRevisionField,
    RecordRevisionRow,
} from '@/types';

function textOf(value: RecordFieldValue): string {
    return typeof value === 'string' && value !== '' ? value : EMPTY_VALUE;
}

/** 複数行の前と後(記法のまま 3 行で切る = 更新履歴.md の 5.2 節) */
function Clamped({
    label,
    value,
    after,
}: {
    label: string;
    value: RecordFieldValue;
    after: boolean;
}) {
    return (
        <span className="grid grid-cols-[22px_minmax(0,1fr)] items-start gap-1.5 leading-relaxed">
            <span className="pt-0.5 text-[11px] text-muted-foreground">
                {label}
            </span>
            <span
                className={
                    after
                        ? 'line-clamp-3 font-bold wrap-anywhere whitespace-pre-wrap text-primary'
                        : 'line-clamp-3 wrap-anywhere whitespace-pre-wrap text-muted-foreground line-through'
                }
            >
                {textOf(value)}
            </span>
        </span>
    );
}

type ChangeProps = {
    field: RecordRevisionField;
    before: RecordFieldValue;
    after: RecordFieldValue;
    row: RecordRevisionRow;
};

function FullValue({
    label,
    value,
}: {
    label: string;
    value: RecordFieldValue;
}) {
    return (
        <div className="grid gap-1 text-sm">
            <span className="text-xs text-muted-foreground">{label}</span>
            <p className="max-h-[40svh] overflow-y-auto rounded-md border p-3 wrap-anywhere whitespace-pre-wrap">
                {textOf(value)}
            </p>
        </div>
    );
}

/**
 * 複数行の変化の全文(題「<項目>の変更」と、頭の日時・人・操作 = モック 2f)。
 */
function FullText({ field, before, after, row }: ChangeProps) {
    const head = [
        row.at === null ? null : formatDatetime(row.at),
        revisionActorText(row),
        revisionOperation(row).label,
    ];

    return (
        <Dialog>
            <DialogTrigger asChild>
                <Button
                    variant="link"
                    className="h-auto justify-self-start p-0"
                >
                    全文を表示
                </Button>
            </DialogTrigger>
            <DialogContent className="sm:max-w-2xl">
                <DialogHeader>
                    <DialogTitle>{`${field.name}の変更`}</DialogTitle>
                    <DialogDescription>
                        {head.filter((part) => part !== null).join('　')}
                    </DialogDescription>
                </DialogHeader>
                <FullValue label="前" value={before} />
                <FullValue label="後" value={after} />
            </DialogContent>
        </Dialog>
    );
}

/**
 * 項目 1 つの「<前> → <後>」(値は値の部品の差分の見え方。空は「—」)。複数行の文字列は前と後を 3 行で切り、「全文を表示」の Dialog。
 */
export function RecordRevisionValueChange({
    field,
    before,
    after,
    row,
}: ChangeProps) {
    if (field.type === 'string' && field.isMultiline) {
        return (
            <span className="grid gap-1.5">
                <Clamped label="前" value={before} after={false} />
                <Clamped label="後" value={after} after />
                <FullText
                    field={field}
                    before={before}
                    after={after}
                    row={row}
                />
            </span>
        );
    }

    const definition = revisionFieldDefinition(field);

    return (
        <span className="inline-flex flex-wrap items-center gap-2">
            <span className="text-muted-foreground">
                <RecordValue field={definition} value={before} place="diff" />
            </span>
            <ArrowRight
                className="size-3.5 text-muted-foreground"
                aria-hidden="true"
            />
            <span className="font-medium">
                <RecordValue field={definition} value={after} place="diff" />
            </span>
        </span>
    );
}
