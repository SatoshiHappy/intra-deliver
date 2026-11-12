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
import { cn } from '@/lib/utils';
import { isEmptyRecordValue, isMultilineString } from '@/lib/recordValues';
import {
    UNSET_VALUE,
    VALUE_AFTER_CLASS,
    VALUE_BEFORE_CLASS,
    multilineTextOf,
} from '@/lib/valueChange';
import type { RecordFieldDefinition, RecordFieldValue } from '@/types';

/** 複数行の前か後(docs の 設計/業務データの入れ物/レコードの共通機能/更新履歴.md の 5.2) */
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
                className={cn(
                    'line-clamp-3 wrap-anywhere whitespace-pre-wrap',
                    after ? VALUE_AFTER_CLASS : VALUE_BEFORE_CLASS,
                )}
            >
                {multilineTextOf(value)}
            </span>
        </span>
    );
}

type ChangeProps = {
    field: RecordFieldDefinition;
    before: RecordFieldValue;
    after: RecordFieldValue;
    /** 全文の Dialog の題の下の 1 行。無ければ出さない */
    fullTextHead?: string;
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
                {multilineTextOf(value)}
            </p>
        </div>
    );
}

/** 複数行の変化の全文(更新履歴.md の 5.2) */
function FullText({ field, before, after, fullTextHead }: ChangeProps) {
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
            <DialogContent
                className="sm:max-w-2xl"
                {...(fullTextHead === undefined && {
                    'aria-describedby': undefined,
                })}
            >
                <DialogHeader>
                    <DialogTitle>{`${field.name}の変更`}</DialogTitle>
                    {fullTextHead !== undefined && (
                        <DialogDescription>{fullTextHead}</DialogDescription>
                    )}
                </DialogHeader>
                <FullValue label="前" value={before} />
                <FullValue label="後" value={after} />
            </DialogContent>
        </Dialog>
    );
}

/** 1 行の値の前か後 */
function Side({
    field,
    value,
    after,
}: {
    field: RecordFieldDefinition;
    value: RecordFieldValue;
    after: boolean;
}) {
    return (
        <span className={after ? VALUE_AFTER_CLASS : VALUE_BEFORE_CLASS}>
            {isEmptyRecordValue(value) ? (
                UNSET_VALUE
            ) : (
                <RecordValue field={field} value={value} place="diff" />
            )}
        </span>
    );
}

/**
 * 項目 1 つの値の変化(更新履歴と申請対象への反映の確かめで見せ方を 1 つにする = docs の 設計/画面の意匠/README.md の採った方針 15)。
 */
export function ValueChange({
    field,
    before,
    after,
    fullTextHead,
}: ChangeProps) {
    if (isMultilineString(field)) {
        return (
            <span className="grid gap-1.5">
                <Clamped label="前" value={before} after={false} />
                <Clamped label="後" value={after} after />
                <FullText
                    field={field}
                    before={before}
                    after={after}
                    fullTextHead={fullTextHead}
                />
            </span>
        );
    }

    return (
        <span className="inline-flex flex-wrap items-center gap-2">
            <Side field={field} value={before} after={false} />
            <ArrowRight
                className="size-3.5 text-muted-foreground"
                aria-hidden="true"
            />
            <Side field={field} value={after} after />
        </span>
    );
}
