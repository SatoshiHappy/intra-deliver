import type { ComponentProps, ReactNode } from 'react';
import { useMemo } from 'react';
import Markdown from 'react-markdown';
import type { Components, ExtraProps } from 'react-markdown';
import {
    Table,
    TableBody,
    TableCell,
    TableHead,
    TableHeader,
    TableRow,
} from '@/components/ui/table';
import { linkUrlOf, markdownOptions } from '@/lib/richText';
import { cn } from '@/lib/utils';

const PRE_CLASS = cn(
    'max-w-full overflow-x-auto rounded-md bg-muted p-3 font-mono text-xs',
    'wrap-normal [&_code]:bg-transparent [&_code]:p-0',
);

const LINK_CLASS = 'text-primary underline underline-offset-4';

/**
 * 見出し(欄の名前 = text-sm より大きくしない。段は太さで分ける)。ページの見出しの段(h1)を増やさないよう、どの段も h4 で描く。
 */
function heading(weight: string): Components['h1'] {
    return ({ children }) => (
        <h4 className={cn('text-sm', weight)}>{children}</h4>
    );
}

/**
 * 原文のうち、その要素に当たる部分(react-markdown が要素に付ける位置から。分からなければ null)。
 */
function rawOf(source: string, node: ExtraProps['node']): string | null {
    const start = node?.position?.start.offset;
    const end = node?.position?.end.offset;

    return start === undefined || end === undefined
        ? null
        : source.slice(start, end);
}

/**
 * 通った URL だけのリンク(別のタブで開く)。通らなければ要素を作らず、文字を返す。
 */
function SafeLink({
    url,
    fallback,
    children,
}: {
    url: string | null;
    fallback: ReactNode;
    children: ReactNode;
}) {
    return url === null ? (
        fallback
    ) : (
        <a
            href={url}
            target="_blank"
            rel="noopener noreferrer"
            className={LINK_CLASS}
        >
            {children}
        </a>
    );
}

/**
 * リンクと画像の差し替え(原文を見る)。リンクの要素を作るのは safeLinkUrl を通った URL だけで、通らないものは原文のまま出す。
 * 画像は img を作らず、説明の文字(無ければ URL)を同じ規則のリンクにする(見る人のブラウザから外のサーバーへ要求を出させない)。
 */
function linkComponents(source: string): Components {
    return {
        a: ({ href, node, children }: ComponentProps<'a'> & ExtraProps) => {
            const raw = rawOf(source, node);

            return (
                <SafeLink url={linkUrlOf(href, raw)} fallback={raw ?? children}>
                    {children}
                </SafeLink>
            );
        },
        img: ({ src, alt }: ComponentProps<'img'> & ExtraProps) => {
            const url = typeof src === 'string' ? linkUrlOf(src, null) : null;

            return (
                <SafeLink url={url} fallback={alt ?? ''}>
                    {alt === undefined || alt === '' ? url : alt}
                </SafeLink>
            );
        },
    };
}

/** タスクリストは描かず、記法の文字(`[ ]`・`[x]`)のまま出す(1.4「記法の範囲」) */
const taskMark: Components['input'] = ({ checked }) =>
    checked === true ? '[x]' : '[ ]';

/** 見出し・段落・箇条書き・引用・コード・表の描き方(画面の意匠のトークンだけ。新しい色・字を足さない) */
const blockComponents: Components = {
    h1: heading('font-bold'),
    h2: heading('font-bold'),
    h3: heading('font-semibold'),
    h4: heading('font-semibold'),
    h5: heading('font-medium'),
    h6: heading('font-medium'),
    ul: ({ children }) => (
        <ul className="list-disc space-y-1 pl-5">{children}</ul>
    ),
    // 1 以外から始まる番号付きリストは、書いた番号から数える
    ol: ({ children, start }) => (
        <ol start={start} className="list-decimal space-y-1 pl-5">
            {children}
        </ol>
    ),
    blockquote: ({ children }) => (
        <blockquote className="grid grid-cols-1 gap-2 border-l-2 pl-3 text-muted-foreground">
            {children}
        </blockquote>
    ),
    // 長いコードの行は欄の幅を広げず、欄の中で横に送る(1.4「意匠」)
    pre: ({ children }) => <pre className={PRE_CLASS}>{children}</pre>,
    code: ({ children }) => (
        <code className="rounded bg-muted px-1 py-0.5 font-mono text-xs">
            {children}
        </code>
    ),
    hr: () => <hr className="border-border" />,
    input: taskMark,
};

/**
 * 表は横線だけの表(components/ui/table)。広い表は表の面(overflow-x-auto)が欄の中で横に送る。
 * セルの style は GFM の列の揃え(`:---:` など)から付く text-align なので、そのまま渡す。
 */
const tableComponents: Components = {
    table: ({ children }) => <Table>{children}</Table>,
    thead: ({ children }) => <TableHeader>{children}</TableHeader>,
    tbody: ({ children }) => <TableBody>{children}</TableBody>,
    tr: ({ children }) => <TableRow>{children}</TableRow>,
    th: ({ children, style }) => (
        <TableHead
            style={style}
            className="min-w-20 wrap-normal whitespace-normal"
        >
            {children}
        </TableHead>
    ),
    td: ({ children, style }) => (
        <TableCell
            style={style}
            className="min-w-20 wrap-normal whitespace-normal"
        >
            {children}
        </TableCell>
    ),
};

/**
 * リッチテキスト(文字列(複数行)の Markdown の表示 = docs の 設計/業務データの入れ物/画面/README.md 1.4)。詳細・申請内容・プレビューが
 * 同じこの部品を使う。React の要素に直す(HTML の文字列を経ない)。設定は lib/richText.ts(平文の先頭と共有)。
 * 根は幅 0 までたためる格子の 1 列にして、広い表と長いコードの行が親の幅を広げないようにする。
 */
export function RichText({
    source,
    className,
}: {
    source: string;
    className?: string;
}) {
    const components = useMemo(
        () => ({
            ...blockComponents,
            ...tableComponents,
            ...linkComponents(source),
        }),
        [source],
    );

    return (
        <div
            className={cn(
                'grid min-w-0 grid-cols-1 gap-2 text-sm wrap-anywhere',
                className,
            )}
        >
            <Markdown {...markdownOptions} components={components}>
                {source}
            </Markdown>
        </div>
    );
}
