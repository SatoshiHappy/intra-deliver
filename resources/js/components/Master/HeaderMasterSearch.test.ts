import { createElement } from 'react';
import { renderToStaticMarkup } from 'react-dom/server';
import { describe, expect, it } from 'vite-plus/test';
import { HeaderMasterSearch } from './HeaderMasterSearch';

function html(visible: boolean) {
    return renderToStaticMarkup(createElement(HeaderMasterSearch, { visible }));
}

describe('HeaderMasterSearch(見出しの検索欄の出し分け)', () => {
    it('組織図を許された人には検索欄を描く', () => {
        expect(html(true)).toContain('ユーザー・組織を検索');
    });

    it('組織図を許されない人には検索欄を描かず、場所だけを残す', () => {
        expect(html(false)).toBe(
            '<div class="ml-4 flex max-w-[480px] min-w-60 flex-1"></div>',
        );
    });
});
