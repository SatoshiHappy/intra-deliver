import { Search, X } from 'lucide-react';
import { useState } from 'react';
import type { ComponentProps } from 'react';
import { formErrorId } from '@/components/FormBlock';
import { Button } from '@/components/ui/button';
import {
    Popover,
    PopoverContent,
    PopoverTrigger,
} from '@/components/ui/popover';
import { SubjectCandidateCommand } from '@/components/Workflow/SubjectCandidateCommand';
import type { PickHandler } from '@/components/Workflow/SubjectCandidateCommand';
import { SubjectMark } from '@/components/Workflow/SubjectMark';
import { cn } from '@/lib/utils';
import {
    subjectKey,
    withPicked,
    withoutSubject,
} from '@/lib/Workflow/subjectRows';
import type { SubjectRowDraft } from '@/lib/Workflow/subjectRows';

type Props = {
    /** 検索の欄のボタンの id(誤りの文言の id もここから作る) */
    id: string;
    /** 欄の名前(「参照者」「対象者」。検索の欄の「{名前}を追加」と読み上げの名前) */
    label: string;
    rows: SubjectRowDraft[];
    errors: Record<string, string | undefined>;
    /** 誤りのキー(並び全体のキー。札は <キー>.<添字>) */
    errorKey: string;
    onChange: (rows: SubjectRowDraft[]) => void;
};

/**
 * 選んだ相手の札 1 つ(頭の印・名前・ユーザーの主所属と、右に外す ×)。誤りがあれば赤の縁で、外すボタンに aria-invalid を付ける。
 */
function SubjectChip({
    row,
    invalid,
    describedBy,
    onRemove,
}: {
    row: SubjectRowDraft;
    invalid: boolean;
    describedBy: string;
    onRemove: () => void;
}) {
    return (
        <li
            className={cn(
                'flex h-9 max-w-full items-center gap-2 rounded-full border bg-background pr-1 pl-1 text-sm',
                invalid && 'border-destructive',
            )}
        >
            <SubjectMark type={row.type} label={row.name} />
            <span className="truncate font-medium">{row.name}</span>
            {(row.affiliation ?? null) !== null && (
                <span className="truncate text-xs text-muted-foreground">
                    {row.affiliation}
                </span>
            )}
            <Button
                type="button"
                variant="ghost"
                size="icon"
                className="size-6 rounded-full"
                aria-label={`${row.name}を外す`}
                aria-invalid={invalid || undefined}
                aria-describedby={invalid ? describedBy : undefined}
                onClick={onRemove}
            >
                <X aria-hidden="true" />
            </Button>
        </li>
    );
}

/**
 * 「{名前}を追加」のボタン(Popover の開閉の引き金。asChild で渡されるので ref と属性を受ける)。combobox は中の文を名前にしないので、
 * 同じ文を読み上げの名前にも付ける(会議の関係者の欄 RecordMultipleReferencePicker と同じ)。
 */
function SearchButton({
    label,
    isOpen,
    invalid,
    ...rest
}: {
    label: string;
    isOpen: boolean;
    invalid: boolean;
} & ComponentProps<'button'>) {
    return (
        <Button
            {...rest}
            type="button"
            variant="outline"
            role="combobox"
            aria-expanded={isOpen}
            aria-label={`${label}を追加`}
            aria-invalid={invalid || undefined}
            className="w-full justify-between font-normal"
        >
            <span className="truncate text-muted-foreground">{`${label}を追加`}</span>
            <Search className="size-4 shrink-0 opacity-50" />
        </Button>
    );
}

/** 検索の欄の入力(describedBy = 欄の下の誤りの文の id。誤りが無ければ undefined) */
type SearchProps = {
    id: string;
    label: string;
    rows: SubjectRowDraft[];
    invalid: boolean;
    describedBy: string | undefined;
    onPick: PickHandler;
};

/**
 * 札の下の検索の欄(「{名前}を追加」。押すと 1 つの候補の一覧から 4 種類を選べ、選ぶと閉じる。閉じると語も空に戻る)。
 */
function SubjectSearch({
    id,
    label,
    rows,
    invalid,
    describedBy,
    onPick,
}: SearchProps) {
    const [open, setOpen] = useState(false);
    const [query, setQuery] = useState('');
    const changeOpen = (next: boolean) => {
        setOpen(next);
        setQuery('');
    };

    return (
        <Popover open={open} onOpenChange={changeOpen}>
            <PopoverTrigger asChild>
                <SearchButton
                    id={id}
                    label={label}
                    isOpen={open}
                    invalid={invalid}
                    aria-describedby={describedBy}
                />
            </PopoverTrigger>
            <PopoverContent
                align="start"
                className="w-(--radix-popover-trigger-width) min-w-64 p-0"
            >
                <SubjectCandidateCommand
                    label={label}
                    rows={rows}
                    query={open ? query : ''}
                    onQueryChange={setQuery}
                    onSelect={(type, candidate) => {
                        onPick(type, candidate);
                        changeOpen(false);
                    }}
                />
            </PopoverContent>
        </Popover>
    );
}

/**
 * 欄の下の誤りの文(並び全体と札の誤り。同じ文は 1 つにまとめる)。
 */
function FieldErrors({ id, messages }: { id: string; messages: string[] }) {
    return (
        <div id={id} className="flex flex-col gap-1">
            {[...new Set(messages)].map((message) => (
                <p key={message} className="text-sm text-destructive">
                    {message}
                </p>
            ))}
        </div>
    );
}

/**
 * 対象者の欄(docs の 設計/ワークフロー/ルートの深さ/画面/定義の管理の画面.md の 3.3 節・3.4 節。会議の関係者の欄と同じ形 =
 * 業務データの複数の参照の欄): 選んだ相手を札で並びの順に並べ、その下の検索の欄で 1 つの候補の一覧からユーザー・組織・役職・ロールを選ぶ。
 * 選んだ相手は候補から消え、札の × で外す。参照者の面とステップの設定の「対象者で指定」が使う。
 * 誤りのキー = 並び全体は errorKey、札は <errorKey>.<添字>(札の誤りは赤の縁と、欄の下の文)。
 */
export function SubjectPartyField({
    id,
    label,
    rows,
    errors,
    errorKey,
    onChange,
}: Props) {
    const errorId = formErrorId(id);
    const rowErrors = rows.map((_, index) => errors[`${errorKey}.${index}`]);
    const messages = [errors[errorKey], ...rowErrors].filter(
        (message): message is string => message !== undefined,
    );

    return (
        <div className="flex w-full flex-col gap-2">
            {rows.length > 0 && (
                <ul
                    className="flex flex-wrap gap-2"
                    aria-label={`${label}の選択`}
                >
                    {rows.map((row, index) => (
                        <SubjectChip
                            key={subjectKey(row)}
                            row={row}
                            invalid={rowErrors[index] !== undefined}
                            describedBy={errorId}
                            onRemove={() =>
                                onChange(withoutSubject(rows, subjectKey(row)))
                            }
                        />
                    ))}
                </ul>
            )}
            <SubjectSearch
                id={id}
                label={label}
                rows={rows}
                invalid={errors[errorKey] !== undefined}
                describedBy={messages.length > 0 ? errorId : undefined}
                onPick={(type, candidate) =>
                    onChange(withPicked(rows, type, candidate))
                }
            />
            {messages.length > 0 && (
                <FieldErrors id={errorId} messages={messages} />
            )}
        </div>
    );
}
