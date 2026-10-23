import { describe, expect, it } from 'vite-plus/test';
import { linkUrlOf, plainTextExcerpt, safeLinkUrl } from './richText';

describe('safeLinkUrl', () => {
    it('http・https の絶対の URL は、そのまま通す', () => {
        expect(safeLinkUrl('http://example.com')).toBe('http://example.com');
        expect(safeLinkUrl('https://example.com/a?b=1#c')).toBe(
            'https://example.com/a?b=1#c',
        );
    });

    it('スキームの大文字小文字は問わない(ブラウザが同じに扱う)', () => {
        expect(safeLinkUrl('HTTPS://Example.com')).toBe('HTTPS://Example.com');
    });

    it('javascript: は、大文字を混ぜても通さない', () => {
        expect(safeLinkUrl('javascript:alert(1)')).toBeNull();
        expect(safeLinkUrl('JavaScript:alert(1)')).toBeNull();
        expect(safeLinkUrl('JAVASCRIPT:alert(1)')).toBeNull();
    });

    it('制御文字(タブ・改行・NUL)や空白を混ぜたスキームは通さない', () => {
        expect(safeLinkUrl('java\tscript:alert(1)')).toBeNull();
        expect(safeLinkUrl('java\nscript:alert(1)')).toBeNull();
        expect(safeLinkUrl('java\0script:alert(1)')).toBeNull();
        expect(safeLinkUrl('\tjavascript:alert(1)')).toBeNull();
        expect(safeLinkUrl(' https://example.com')).toBeNull();
        expect(safeLinkUrl('https://example.com ')).toBeNull();
        expect(safeLinkUrl('ht\ttps://example.com')).toBeNull();
        expect(safeLinkUrl('https://exa mple.com')).toBeNull();
    });

    it('data:・相対の URL・mailto:・ftp:・スキームだけ・空文字は通さない', () => {
        expect(safeLinkUrl('data:text/html,<script>alert(1)</script>')).toBe(
            null,
        );
        expect(safeLinkUrl('/records/data/issue/1')).toBeNull();
        expect(safeLinkUrl('//example.com')).toBeNull();
        expect(safeLinkUrl('mailto:a@example.com')).toBeNull();
        expect(safeLinkUrl('ftp://example.com')).toBeNull();
        expect(safeLinkUrl('https://')).toBeNull();
        expect(safeLinkUrl('https:example.com')).toBeNull();
        expect(safeLinkUrl('')).toBeNull();
    });
});

describe('linkUrlOf', () => {
    it('通る URL は、原文が明示の記法でも自動リンクでも通す', () => {
        expect(
            linkUrlOf('https://example.com', '[a](https://example.com)'),
        ).toBe('https://example.com');
        expect(linkUrlOf('https://example.com', 'https://example.com')).toBe(
            'https://example.com',
        );
        expect(linkUrlOf('https://example.com', null)).toBe(
            'https://example.com',
        );
    });

    it('www. から作られた自動リンクは、href が http でも通さない', () => {
        expect(
            linkUrlOf('http://www.example.com', 'www.example.com'),
        ).toBeNull();
        expect(
            linkUrlOf('http://www.example.com', 'WWW.example.com'),
        ).toBeNull();
    });

    it('文字が www. でも、明示の記法で書いた http の URL は通す', () => {
        expect(
            linkUrlOf(
                'http://www.example.com',
                '[www.example.com](http://www.example.com)',
            ),
        ).toBe('http://www.example.com');
    });

    it('通らない URL・URL の無いリンクは通さない', () => {
        expect(linkUrlOf('', '[a](javascript:alert(1))')).toBeNull();
        expect(linkUrlOf('mailto:a@example.com', 'a@example.com')).toBeNull();
        expect(linkUrlOf('/records/1', '[a](/records/1)')).toBeNull();
        expect(linkUrlOf(undefined, null)).toBeNull();
    });
});

describe('plainTextExcerpt(文・見出し・強調・箇条書き)', () => {
    it('記法の無い文はそのまま、改行は空白 1 つ、空文字は空文字', () => {
        expect(plainTextExcerpt('一行目\n二行目')).toBe('一行目 二行目');
        expect(plainTextExcerpt('一段落\n\n二段落')).toBe('一段落 二段落');
        expect(plainTextExcerpt('')).toBe('');
    });

    it('見出し・強調・取り消し線は記号を除く', () => {
        expect(plainTextExcerpt('# 見出し\n\n本文')).toBe('見出し 本文');
        expect(plainTextExcerpt('見出し\n===\n本文')).toBe('見出し 本文');
        expect(plainTextExcerpt('**太字**と*斜体*と __太字__ と _斜体_')).toBe(
            '太字と斜体と 太字 と 斜体',
        );
        expect(plainTextExcerpt('~~取り消し~~')).toBe('取り消し');
    });

    it('語の途中の記号や、空白に挟まれた * は強調にしない', () => {
        expect(plainTextExcerpt('snake_case_name')).toBe('snake_case_name');
        expect(plainTextExcerpt('2 * 3 * 4')).toBe('2 * 3 * 4');
    });

    it('箇条書き・番号の箇条・引用は、記号を除いて中の文字を空白でつなぐ', () => {
        expect(plainTextExcerpt('- 一\n- 二\n  - 三')).toBe('一 二 三');
        expect(plainTextExcerpt('1. 一\n2. 二')).toBe('一 二');
        expect(plainTextExcerpt('> 引用\n> 続き\n\n本文')).toBe(
            '引用 続き 本文',
        );
    });

    it('タスクリストの記法は描かないので、印の文字を残す', () => {
        expect(plainTextExcerpt('- [ ] 確認\n- [x] 済')).toBe(
            '[ ] 確認 [x] 済',
        );
    });
});

describe('plainTextExcerpt(コード・表)', () => {
    it('コードは行内もブロックも中の文字だけ(ブロックの行の間は空白)', () => {
        expect(plainTextExcerpt('`const a = 1` を書く')).toBe(
            'const a = 1 を書く',
        );
        expect(plainTextExcerpt('```ts\nconst a = 1;\nconst b = 2;\n```')).toBe(
            'const a = 1; const b = 2;',
        );
    });

    it('表は、記号と区切りの行を除いて、セルの文字を空白でつなぐ', () => {
        expect(plainTextExcerpt('| 項目 | 値 |\n|---|---|\n| 甲 | 1 |')).toBe(
            '項目 値 甲 1',
        );
    });
});

describe('plainTextExcerpt(リンク・画像・文字のまま残すもの)', () => {
    it('リンクは文字だけ残し、URL を出さない。自動リンクは文字(URL)のまま', () => {
        expect(plainTextExcerpt('[公式](https://example.com/a) を見る')).toBe(
            '公式 を見る',
        );
        expect(plainTextExcerpt('[参照][1]\n\n[1]: https://example.com')).toBe(
            '参照',
        );
        expect(plainTextExcerpt('<https://example.com>')).toBe(
            'https://example.com',
        );
        expect(plainTextExcerpt('https://example.com と www.example.com')).toBe(
            'https://example.com と www.example.com',
        );
    });

    it('画像は RichText が出す文字と同じ(説明の文字、無ければ通った URL、通らなければ出さない)', () => {
        expect(plainTextExcerpt('![図の説明](https://example.com/a.png)')).toBe(
            '図の説明',
        );
        expect(plainTextExcerpt('前 ![](https://example.com/a.png) 後')).toBe(
            '前 https://example.com/a.png 後',
        );
        expect(plainTextExcerpt('前 ![](/images/a.png) 後')).toBe('前 後');
    });

    it('生の HTML・脚注は描かないので、文字のまま残す', () => {
        expect(plainTextExcerpt('<b>太字</b>')).toBe('<b>太字</b>');
        expect(plainTextExcerpt('本文[^1]\n\n[^1]: 注')).toBe(
            '本文[^1] [^1]: 注',
        );
    });

    it('水平線は文字にならない', () => {
        expect(plainTextExcerpt('上\n\n---\n\n下')).toBe('上 下');
    });
});

describe('コメントの本文(一覧の RichText と入力のプレビューが使う規則)', () => {
    it('生の HTML は要素にせず、文字のまま残す', () => {
        expect(plainTextExcerpt('<script>alert(1)</script>')).toBe(
            '<script>alert(1)</script>',
        );
        expect(plainTextExcerpt('前\n<img src=x onerror=alert(1)>\n後')).toBe(
            '前 <img src=x onerror=alert(1)> 後',
        );
    });

    it('javascript: のリンクの記法は、リンクにならない URL として扱う', () => {
        expect(linkUrlOf('', '[a](javascript:alert(1))')).toBeNull();
        expect(
            linkUrlOf('javascript:alert(1)', '[a](javascript:alert(1))'),
        ).toBeNull();
        expect(plainTextExcerpt('[怪しい](javascript:alert(1))')).toBe(
            '怪しい',
        );
    });

    it('画像の記法は画像を作らず、説明の文字を残す', () => {
        expect(
            plainTextExcerpt('![説明](https://example.com/a.png) の後'),
        ).toBe('説明 の後');
        expect(plainTextExcerpt('![説明](javascript:alert(1))')).toBe('説明');
    });
});
