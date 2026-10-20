import { useEffect, useRef, useState } from 'react';
import type { FocusEvent } from 'react';
import { CharacterCount } from '@/components/CountedTextInput';
import { invalidProps } from '@/components/FormBlock';
import { RichText } from '@/components/RichText';
import { Tabs, TabsContent, TabsList, TabsTrigger } from '@/components/ui/tabs';
import { Textarea } from '@/components/ui/textarea';
import { EMPTY_VALUE } from '@/lib/recordValues';
import { cn } from '@/lib/utils';

type Tab = 'edit' | 'preview';

type Props = {
    /** 入力の id(文字数の要素の id もここから作る) */
    id: string;
    value: string;
    onChange: (value: string) => void;
    /** 文字数の上限(null = 数を出さない) */
    max: number | null;
    /** 誤りがある(誤りの文言は使い手が formErrorId(id) の要素で描く) */
    invalid: boolean;
    /** 今の入力で必須か(aria-required) */
    isRequiredNow: boolean;
    /** ラベルを持たない欄(明細の表のセル)の名前 */
    ariaLabel?: string;
};

/**
 * 札の状態と、送った後のフォーカスの受け方(送った後に誤りの入力へ移す = docs の 設計/業務データの入れ物/画面/業務の画面.md 2.2)。
 * 誤りが付いたら描画の中で「編集」に戻す(同じ描画で入力が出るので、使い手の移動が入力に届く)。誤りが変わらないまま「プレビュー」を
 * 開いて送り直すと、移動は誤りの印を持つ面へ来るので、それを受けて「編集」にし入力へ移す。面へのフォーカスが欄の外(送るボタン・
 * ほかの欄)か null から来たら送った後の移動とみなし、同じ欄の札から来たらプレビューを読みに来たとみなして戻さない(ウィンドウから
 * 戻ったとき・ほかの欄から面を押したときも戻るが、移る先は誤りの入力で値は失われないので受け入れる)。
 * 入力は札を切り替えても残す(forceMount)。Radix の札の中身は切り替えの描画が 1 回遅れるので、遅れなしに入力を出す。
 */
function useRichTextTab(invalid: boolean) {
    const [tab, setTab] = useState<Tab>('edit');
    const [wasInvalid, setWasInvalid] = useState(invalid);
    const root = useRef<HTMLDivElement>(null);
    const textarea = useRef<HTMLTextAreaElement>(null);
    const focusAfterEdit = useRef(false);

    if (invalid !== wasInvalid) {
        setWasInvalid(invalid);
        if (invalid) {
            setTab('edit');
        }
    }

    useEffect(() => {
        if (tab === 'edit' && focusAfterEdit.current) {
            focusAfterEdit.current = false;
            textarea.current?.focus();
        }
    }, [tab]);

    const moveToEdit = (event: FocusEvent) => {
        // 面の中のリンクなどへのフォーカスと、同じ欄の札からの移動では動かさない
        const from = event.relatedTarget;
        const fromThisField =
            from instanceof Node && root.current?.contains(from) === true;

        if (invalid && event.target === event.currentTarget && !fromThisField) {
            focusAfterEdit.current = true;
            setTab('edit');
        }
    };

    const select = (next: string) =>
        setTab(next === 'preview' ? 'preview' : 'edit');

    return { tab, select, root, textarea, moveToEdit };
}

const PREVIEW_CLASS = cn(
    'grid min-h-16 min-w-0 grid-cols-1 rounded-md border border-input',
    'px-3 py-2 text-sm aria-invalid:border-destructive',
);

/**
 * 「プレビュー」の面(空なら「—」)。誤りがあり開いている間は、入力と同じ誤りの印と説明を持つ。印は送った後のフォーカスの移動
 * (RecordFields の INVALID_SELECTOR)を面へ向けるため、説明は面に来た人に誤りの文言を読ませるため。
 */
function PreviewPanel({
    id,
    value,
    max,
    invalid,
    onFocus,
}: {
    id: string;
    value: string;
    max: number | null;
    invalid: boolean;
    onFocus: (event: FocusEvent) => void;
}) {
    return (
        <TabsContent
            value="preview"
            {...invalidProps(id, invalid ? '' : undefined, false, max !== null)}
            onFocus={onFocus}
            className={PREVIEW_CLASS}
        >
            {value.trim() === '' ? (
                <span>{EMPTY_VALUE}</span>
            ) : (
                <RichText source={value} />
            )}
        </TabsContent>
    );
}

/**
 * 文字列(複数行)の入力(docs の 設計/業務データの入れ物/画面/業務の画面.md 2.2 の表、画面/README.md 1.4)。プレビューは詳細と
 * 同じ RichText で描く。文字数の要素は札の外に 1 つ置き、どちらの札でも見えるようにする(結び方は CountedTextInput と同じ)。
 * 誤りの印は見えている側(入力か面)だけが持つ(隠れた入力へはフォーカスを移せないため)。
 */
export function RichTextInput({
    id,
    value,
    onChange,
    max,
    invalid,
    isRequiredNow,
    ariaLabel,
}: Props) {
    const { tab, select, root, textarea, moveToEdit } = useRichTextTab(invalid);

    return (
        <div ref={root} className="grid w-full min-w-0 grid-cols-1 gap-1">
            <Tabs value={tab} onValueChange={select}>
                <TabsList>
                    <TabsTrigger value="edit">編集</TabsTrigger>
                    <TabsTrigger value="preview">プレビュー</TabsTrigger>
                </TabsList>
                <TabsContent value="edit" forceMount hidden={tab !== 'edit'}>
                    <Textarea
                        ref={textarea}
                        id={id}
                        aria-label={ariaLabel}
                        aria-required={isRequiredNow || undefined}
                        {...invalidProps(
                            id,
                            invalid && tab === 'edit' ? '' : undefined,
                            false,
                            max !== null,
                        )}
                        value={value}
                        onChange={(event) => onChange(event.target.value)}
                    />
                </TabsContent>
                <PreviewPanel
                    id={id}
                    value={value}
                    max={max}
                    invalid={invalid && tab === 'preview'}
                    onFocus={moveToEdit}
                />
            </Tabs>
            {max !== null && (
                <CharacterCount inputId={id} value={value} max={max} />
            )}
        </div>
    );
}
