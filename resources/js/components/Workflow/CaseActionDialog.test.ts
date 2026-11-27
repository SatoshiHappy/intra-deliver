import { createElement } from 'react';
import { renderToStaticMarkup } from 'react-dom/server';
import { describe, expect, it } from 'vite-plus/test';
import { TooltipProvider } from '@/components/ui/tooltip';
import { CaseRowActionButton } from './CaseActionDialog';

describe('CaseRowActionButton(一覧の行の処理の名前つきのアイコンのボタン = 方針 12)', () => {
    it.each([
        ['withdraw', '取止め'],
        ['pullBack', '引戻し'],
    ] as const)(
        '一覧の行の %s は、読み上げの名前を「<案件名>の%s」にし、ダイアログを開くボタンにする',
        (operation, name) => {
            const markup = renderToStaticMarkup(
                createElement(
                    TooltipProvider,
                    null,
                    createElement(CaseRowActionButton, {
                        target: {
                            operation,
                            caseId: 1,
                            caseName: '展示会への出展',
                            revision: 1,
                            commentMaxLength: 1000,
                            nodes: [],
                            fixedPrincipalId: null,
                        },
                        onOpen: () => undefined,
                    }),
                ),
            );
            const tag = markup.match(/<button[^>]*>/)?.[0] ?? '';

            expect(tag).toContain(`aria-label="展示会への出展の${name}"`);
            expect(tag).toContain('aria-haspopup="dialog"');
        },
    );
});
