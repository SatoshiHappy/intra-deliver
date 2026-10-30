import { ChevronRight } from 'lucide-react';
import { useState } from 'react';
import type { ComponentProps, FormEvent } from 'react';
import { DateInput } from '@/components/DateInput';
import { RecordChoicePicker } from '@/components/RecordChoicePicker';
import { RecordMultipleReferencePicker } from '@/components/RecordMultipleReferencePicker';
import { RecordReferencePicker } from '@/components/RecordReferencePicker';
import { Badge } from '@/components/ui/badge';
import { Button } from '@/components/ui/button';
import {
    Collapsible,
    CollapsibleContent,
    CollapsibleTrigger,
} from '@/components/ui/collapsible';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import {
    Select,
    SelectContent,
    SelectItem,
    SelectTrigger,
    SelectValue,
} from '@/components/ui/select';
import type { SearchDraft } from '@/lib/Records/recordSearch';
import { isReferenceField } from '@/lib/recordForm';
import {
    draftsOf,
    filterValue,
    initialDraft,
    isPicked,
    isRanged,
} from '@/lib/Records/recordSearch';
import { SELECT_NONE } from '@/lib/selectNone';
import type { QueryParams } from '@/wayfinder';
import type { RecordFieldDefinition, RecordListQuery } from '@/types';

/** 検索欄の開閉を覚える localStorage のキー(利用者のブラウザに 1 つ。業務モジュールに依らない) */
const OPEN_STORAGE_KEY = 'records.searchOpen';

type Props = {
    /** 検索条件にする項目(項目の並び) */
    fields: RecordFieldDefinition[];
    query: RecordListQuery;
    /** 成功した訪問ごとに進む数(変わったら欄を query で作り直す) */
    version: number;
    /** 検索条件(クエリの filter)を送る。null = 条件を解除 */
    onSearch: (filter: QueryParams | null) => void;
};

/**
 * 利用者が最後に選んだ検索欄の開閉(読めなければ畳む)。
 */
function storedOpen(): boolean {
    try {
        return window.localStorage.getItem(OPEN_STORAGE_KEY) === 'true';
    } catch {
        // 保存先が使えない(無効にされた・容量の外など)ときは既定の畳んだ形にする
        return false;
    }
}

/**
 * 検索欄の開閉を覚える(書けなければ覚えない。開閉そのものは変わる)。
 */
function storeOpen(open: boolean): void {
    try {
        window.localStorage.setItem(OPEN_STORAGE_KEY, String(open));
    } catch {
        // 保存先が使えないときは、この表示の間だけの開閉にする
    }
}

/**
 * 範囲の下限・上限の入力(日付・日時は DateInput = クリックでカレンダー、数値は Input)。
 */
function BoundInput({
    type,
    ...props
}: Omit<ComponentProps<typeof Input>, 'type'> & {
    type: 'date' | 'datetime-local' | 'text';
}) {
    return type === 'text' ? (
        <Input {...props} />
    ) : (
        <DateInput type={type} {...props} />
    );
}

/**
 * 参照の項目の欄(1 つは参照の選択欄、複数選べる参照は複数の参照の欄 = 画面/業務の画面.md 2.1)。
 */
function ReferenceSearchInput({
    id,
    field,
    draft,
    onChange,
}: {
    id: string;
    field: RecordFieldDefinition;
    draft: SearchDraft;
    onChange: (draft: SearchDraft) => void;
}) {
    if (field.isMultiple) {
        return (
            <RecordMultipleReferencePicker
                id={id}
                field={field}
                value={Array.isArray(draft) ? draft : []}
                onChange={onChange}
            />
        );
    }

    return (
        <RecordReferencePicker
            id={id}
            field={field}
            recordId={null}
            value={isPicked(draft) ? draft : null}
            onChange={onChange}
        />
    );
}

/**
 * 1 つの項目の欄(型ごとの入力 = 画面/業務の画面.md 2.1)。
 */
function DraftInput({
    field,
    draft,
    onChange,
}: {
    field: RecordFieldDefinition;
    draft: SearchDraft;
    onChange: (draft: SearchDraft) => void;
}) {
    const id = `record-filter-${field.code}`;

    if (isReferenceField(field)) {
        return (
            <ReferenceSearchInput
                id={id}
                field={field}
                draft={draft}
                onChange={onChange}
            />
        );
    }

    if (field.type === 'choice') {
        return (
            <RecordChoicePicker
                id={id}
                field={field}
                value={typeof draft === 'string' ? draft : ''}
                onChange={onChange}
                isClearable
            />
        );
    }

    if (field.type === 'boolean') {
        return (
            <Select
                value={
                    typeof draft === 'string' && draft !== ''
                        ? draft
                        : SELECT_NONE
                }
                onValueChange={(next) =>
                    onChange(next === SELECT_NONE ? '' : next)
                }
            >
                <SelectTrigger id={id} className="w-full">
                    <SelectValue />
                </SelectTrigger>
                <SelectContent>
                    <SelectItem value={SELECT_NONE}>指定なし</SelectItem>
                    <SelectItem value="true">はい</SelectItem>
                    <SelectItem value="false">いいえ</SelectItem>
                </SelectContent>
            </Select>
        );
    }

    if (
        isRanged(field) &&
        typeof draft === 'object' &&
        draft !== null &&
        'from' in draft
    ) {
        const inputType =
            field.type === 'date'
                ? 'date'
                : field.type === 'datetime'
                  ? 'datetime-local'
                  : 'text';

        return (
            <div className="flex items-center gap-2">
                <BoundInput
                    id={id}
                    type={inputType}
                    aria-label={`${field.name}の下限`}
                    value={draft.from}
                    onChange={(event) =>
                        onChange({ ...draft, from: event.target.value })
                    }
                    className="font-num tabular-nums"
                />
                <span className="text-muted-foreground">〜</span>
                <BoundInput
                    type={inputType}
                    aria-label={`${field.name}の上限`}
                    value={draft.to}
                    onChange={(event) =>
                        onChange({ ...draft, to: event.target.value })
                    }
                    className="font-num tabular-nums"
                />
            </div>
        );
    }

    return (
        <Input
            id={id}
            value={typeof draft === 'string' ? draft : ''}
            onChange={(event) => onChange(event.target.value)}
        />
    );
}

/**
 * 一覧の検索欄(docs の 設計/業務データの入れ物/画面/業務の画面.md 2.1 の「検索欄」)。
 */
export function RecordSearchForm({ fields, query, version, onSearch }: Props) {
    const [drafts, setDrafts] = useState<Record<string, SearchDraft>>(() =>
        draftsOf(fields, query, {}),
    );
    const [syncedVersion, setSyncedVersion] = useState(version);
    const conditionCount = Object.keys(query.filters).length;
    const [open, setOpen] = useState(() => conditionCount > 0 || storedOpen());

    // 訪問が成功したら、欄をサーバーが解釈した条件で作り直す(捨てられた条件を欄に残さない)。描画中に状態を合わせる React の定石
    if (version !== syncedVersion) {
        setSyncedVersion(version);
        setDrafts((current) => draftsOf(fields, query, current));
    }

    const toggle = (next: boolean) => {
        setOpen(next);
        storeOpen(next);
    };

    const submit = (event: FormEvent) => {
        event.preventDefault();
        onSearch(
            Object.fromEntries(
                fields.map((field) => [
                    field.code,
                    filterValue(field, drafts[field.code] ?? null),
                ]),
            ),
        );
    };

    const clear = () => {
        setDrafts(
            Object.fromEntries(
                fields.map((field) => [
                    field.code,
                    initialDraft(field, undefined),
                ]),
            ),
        );
        onSearch(null);
    };

    return (
        <Collapsible
            open={open}
            onOpenChange={toggle}
            className="flex flex-col gap-4 rounded-xl border bg-card p-5 text-card-foreground"
        >
            <div className="flex items-center gap-2">
                <h2 className="text-[15px] font-semibold">
                    <CollapsibleTrigger className="group flex items-center gap-1 rounded-sm hover:underline focus-visible:ring-[3px] focus-visible:ring-ring/50 focus-visible:outline-none">
                        <ChevronRight
                            aria-hidden="true"
                            className="size-4 transition-transform group-data-[state=open]:rotate-90"
                        />
                        検索条件
                    </CollapsibleTrigger>
                </h2>
                {conditionCount > 0 && (
                    <Badge
                        variant="secondary"
                        className="font-num tabular-nums"
                    >
                        {conditionCount} 件
                    </Badge>
                )}
            </div>
            <CollapsibleContent>
                <form
                    role="search"
                    aria-label="レコードの検索"
                    onSubmit={submit}
                    className="flex flex-col gap-4"
                >
                    <div className="grid grid-cols-[repeat(auto-fill,minmax(16rem,1fr))] gap-4">
                        {fields.map((field) => (
                            <div
                                key={field.code}
                                className="flex flex-col gap-2"
                            >
                                <Label htmlFor={`record-filter-${field.code}`}>
                                    {field.name}
                                </Label>
                                <DraftInput
                                    field={field}
                                    draft={drafts[field.code] ?? null}
                                    onChange={(draft) =>
                                        setDrafts((current) => ({
                                            ...current,
                                            [field.code]: draft,
                                        }))
                                    }
                                />
                            </div>
                        ))}
                    </div>
                    <div className="flex gap-2">
                        <Button type="submit">検索</Button>
                        <Button type="button" variant="outline" onClick={clear}>
                            条件を解除
                        </Button>
                    </div>
                </form>
            </CollapsibleContent>
        </Collapsible>
    );
}
