import { createElement } from 'react';
import type { ReactElement } from 'react';
import { renderToStaticMarkup } from 'react-dom/server';
import { describe, expect, it } from 'vite-plus/test';
import { ConfirmDeleteDialog } from '@/components/ConfirmDeleteDialog';
import { TooltipProvider } from '@/components/ui/tooltip';
import { RowActionButton } from './RowActionButton';

function html(element: ReactElement) {
    return renderToStaticMarkup(createElement(TooltipProvider, null, element));
}

/** 最初の button 要素の開きタグ */
function buttonTag(markup: string) {
    return markup.match(/<button[^>]*>/)?.[0] ?? '';
}

describe('RowActionButton(行の操作の名前つきのアイコンのボタン = 方針 12)', () => {
    it('押せるボタンは、名前を読み上げの名前にし、Tooltip の元にする(押せない形の属性は付けない)', () => {
        const tag = buttonTag(
            html(createElement(RowActionButton, { label: 'プレビュー' })),
        );

        expect(tag).toContain('aria-label="プレビュー"');
        expect(tag).toContain('data-slot="tooltip-trigger"');
        expect(tag).not.toContain('aria-disabled');
        expect(tag).not.toContain('aria-describedby');
        expect(tag).not.toContain('disabled=""');
    });

    it('押せない理由を渡すと、aria-disabled の形にし(フォーカスと Tooltip を受ける)、読み上げの説明に理由を読む', () => {
        const markup = html(
            createElement(RowActionButton, {
                label: '削除',
                'aria-label': 'a.pngを削除',
                destructive: true,
                disabledReason: '申請済みのため削除できません',
            }),
        );
        const tag = buttonTag(markup);
        const describedBy = tag.match(/aria-describedby="([^"]+)"/)?.[1];

        expect(tag).toContain('aria-label="a.pngを削除"');
        expect(tag).toContain('aria-disabled="true"');
        expect(tag).toContain('text-destructive');
        expect(tag).not.toContain('disabled=""');
        expect(describedBy).toBeDefined();
        expect(markup).toContain(
            `<span id="${describedBy}" class="sr-only">申請済みのため削除できません</span>`,
        );
    });
});

describe('ConfirmDeleteDialog の icon の形', () => {
    it('削除の確かめの icon の形は、赤いアイコンのボタンで、読み上げの名前は triggerLabel', () => {
        const tag = buttonTag(
            html(
                createElement(ConfirmDeleteDialog, {
                    triggerVariant: 'icon',
                    triggerLabel: 'a.pngを削除',
                    title: '添付ファイルの削除',
                    description: 'a.pngを削除しますか。',
                    action: { url: '/attachments/1', method: 'delete' },
                }),
            ),
        );

        expect(tag).toContain('aria-label="a.pngを削除"');
        expect(tag).toContain('text-destructive');
        expect(tag).toContain('aria-haspopup="dialog"');
    });
});
