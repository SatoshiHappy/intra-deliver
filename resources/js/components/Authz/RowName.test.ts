import { createElement } from 'react';
import { renderToStaticMarkup } from 'react-dom/server';
import { describe, expect, it } from 'vite-plus/test';
import { TooltipProvider } from '@/components/ui/tooltip';
import { RowName } from './RowName';

function html(description: string | null) {
    return renderToStaticMarkup(
        createElement(
            TooltipProvider,
            null,
            createElement(RowName, { name: '申請操作', description }),
        ),
    );
}

describe('RowName(行の名前と説明の「?」)', () => {
    it('説明のある行は、名前の右に吹き出しの元になる「?」のボタンを置く', () => {
        const markup = html('すべての申請を一覧表示できます');

        expect(markup).toContain('<span>申請操作</span>');
        expect(markup).toContain('aria-label="申請操作の説明"');
        expect(markup).toContain('data-slot="tooltip-trigger"');
    });

    it('説明の無い行は、名前だけで「?」を置かない', () => {
        expect(html(null)).toBe('<span>申請操作</span>');
    });
});
