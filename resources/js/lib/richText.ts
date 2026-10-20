import { isValidElement } from 'react';
import type { ReactElement, ReactNode } from 'react';
import Markdown from 'react-markdown';
import type { Options } from 'react-markdown';
import remarkBreaks from 'remark-breaks';
import remarkGfm from 'remark-gfm';

/**
 * リッチテキスト(文字列(複数行)の Markdown の表示 = docs の 設計/業務データの入れ物/画面/README.md 1.4)の、描く部品 RichText と
 * 平文の先頭 plainTextExcerpt が共有する設定と、リンクの判定。
 */

type Handlers = NonNullable<
    NonNullable<Options['remarkRehypeOptions']>['handlers']
>;

/**
 * 脚注は描かず、記法の文字のまま出す(1.4「記法の範囲」)。参照 [^1] は文字、定義 [^1]: … は段落の先頭に記法を戻す。
 * 脚注の節(末尾の一覧)は remark-rehype が参照の取り扱いから作るので、この 2 つを差し替えると出ない。
 */
const footnotesAsText: Handlers = {
    footnoteReference: (_state, node) => ({
        type: 'text',
        value: `[^${node.label ?? node.identifier}]`,
    }),
    footnoteDefinition: (state, node) => {
        const blocks = state.all(node);
        const marker = {
            type: 'text' as const,
            value: `[^${node.label ?? node.identifier}]: `,
        };
        const first = blocks[0];

        if (first?.type === 'element' && first.tagName === 'p') {
            first.children.unshift(marker);

            return blocks;
        }

        return [
            {
                type: 'element' as const,
                tagName: 'p',
                properties: {},
                children: [marker],
            },
            ...blocks,
        ];
    },
};

/**
 * 描く要素の範囲(これ以外は、中の文字だけを残す)。生の HTML は文字になるので要素にならず、脚注・タスクリストの要素も上の差し替えと
 * RichText の input の差し替えで文字にする。
 */
const ALLOWED_ELEMENTS = [
    'p',
    'br',
    'hr',
    'h1',
    'h2',
    'h3',
    'h4',
    'h5',
    'h6',
    'ul',
    'ol',
    'li',
    'blockquote',
    'pre',
    'code',
    'em',
    'strong',
    'del',
    'a',
    'img',
    'input',
    'table',
    'thead',
    'tbody',
    'tr',
    'th',
    'td',
];

/**
 * react-markdown に渡す設定(記法の範囲 = docs の 設計/業務データの入れ物/画面/README.md 1.4)。remark-gfm はタスクリスト・脚注も作るので、
 * 上の脚注の差し替えと RichText の input の差し替えで文字に戻す。
 */
export const markdownOptions: Options = {
    remarkPlugins: [remarkGfm, remarkBreaks],
    remarkRehypeOptions: { handlers: footnotesAsText },
    allowedElements: ALLOWED_ELEMENTS,
    unwrapDisallowed: true,
};

const HTTP_URL = /^https?:\/\//i;

/** gfm の自動リンクが `www.` の文字から作る URL を見分ける原文(明示の記法は [ か < で始まる) */
const WWW_LITERAL = /^www\./i;

/**
 * 空白・制御文字(U+0000〜U+0020、U+007F〜U+009F)を含むか。含む値はスキームを偽装できる(`java\tscript:` など)ので通さない。
 */
export function hasBlankOrControl(url: string): boolean {
    return Array.from(url).some((character) => {
        const code = character.codePointAt(0) ?? 0;

        return code <= 0x20 || (code >= 0x7f && code <= 0x9f);
    });
}

/**
 * リンクにしてよい URL か(スキームが http・https の絶対の URL だけ。スキームの大文字小文字は問わない = ブラウザが同じに扱うため)。
 * 通すなら URL をそのまま、通さないなら null(1.4「リンクの規則」。リンクの要素を作るのはこの関数を通った URL だけ)。
 */
export function safeLinkUrl(url: string): string | null {
    if (
        !HTTP_URL.test(url) ||
        hasBlankOrControl(url) ||
        !URL.canParse(url) ||
        new URL(url).hostname === ''
    ) {
        return null;
    }

    return url;
}

/**
 * 描くリンクの URL(通らなければ null)。href = 要素に渡った URL、raw = 原文のうちそのリンクの部分(分からなければ null)。
 * `www.` から作られた自動リンク(raw が `www.` で始まる)は、href が http になっていても通さない(1.4)。
 */
export function linkUrlOf(
    href: string | undefined,
    raw: string | null,
): string | null {
    if (href === undefined || (raw !== null && WWW_LITERAL.test(raw))) {
        return null;
    }

    return safeLinkUrl(href);
}

/** 平文にするとき、前後に空白を置く(ブロックの間) */
const BLOCK_TAGS = new Set([
    'p',
    'h1',
    'h2',
    'h3',
    'h4',
    'h5',
    'h6',
    'ul',
    'ol',
    'li',
    'blockquote',
    'pre',
    'table',
    'thead',
    'tbody',
    'tr',
    'th',
    'td',
    'hr',
]);

type ElementProps = {
    children?: ReactNode;
    alt?: string;
    src?: string;
    checked?: boolean;
};

/**
 * 描いた要素の木から文字を集める。ブロックの間・改行は空白、リンクは文字だけ。画像とタスクリストの印は RichText が出す文字と同じにする。
 */
function textOf(node: ReactNode): string {
    if (Array.isArray(node)) {
        return node.map(textOf).join('');
    }

    if (!isValidElement<ElementProps>(node)) {
        return typeof node === 'string' ? node : '';
    }

    const { type, props } = node;

    if (type === 'br') {
        return ' ';
    }

    if (type === 'img') {
        // 説明の文字、無ければ通った URL(RichText の img の差し替えと同じ)
        return props.alt === undefined || props.alt === ''
            ? (linkUrlOf(props.src, null) ?? '')
            : props.alt;
    }

    if (type === 'input') {
        return props.checked === true ? '[x]' : '[ ]';
    }

    const text = textOf(props.children);

    return typeof type === 'string' && BLOCK_TAGS.has(type)
        ? ` ${text} `
        : text;
}

/**
 * 表のセルに出す平文の先頭(docs の 設計/業務データの入れ物/画面/README.md 1.4「場所ごとの見え方」)。記法の解釈を 2 か所に持たないよう、
 * RichText と同じ設定で要素の木を作り、描かずに文字だけを集める。リンクは文字だけ残す(リンク先は出さない)ので、通らないリンクも
 * 詳細(原文のまま)と違って文字だけになる。長さでは切らない(切るのは CSS)。
 */
export function plainTextExcerpt(markdown: string): string {
    // Markdown は hooks を使わない同期の部品で、要素の木を返すだけ(描画の外で呼べる)
    const tree: ReactElement = Markdown({
        ...markdownOptions,
        children: markdown,
    });

    return textOf(tree).replace(/\s+/g, ' ').trim();
}
