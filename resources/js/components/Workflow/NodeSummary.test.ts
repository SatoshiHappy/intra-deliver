import { createElement } from 'react';
import { renderToStaticMarkup } from 'react-dom/server';
import { describe, expect, it } from 'vite-plus/test';
import { PersonLinksContext } from '@/hooks/Workflow/use-person-links';
import type { WorkflowSummaryPart } from '@/types';
import { NodeSummary } from './NodeSummary';

function html(parts: WorkflowSummaryPart[], organizationChart: boolean) {
    return renderToStaticMarkup(
        createElement(
            PersonLinksContext,
            { value: { organizationChart, authz: false } },
            createElement(NodeSummary, { parts }),
        ),
    );
}

const PARTS: WorkflowSummaryPart[] = [
    '一般利用者、',
    { departmentId: 21, text: '第一営業部' },
    'が承認します。',
];

describe('NodeSummary(要約の組織の名前の「…」)', () => {
    it('組織の名前は折り返せ、nowrap で包むのは最後の 1 文字と「…」だけ', () => {
        const markup = html(PARTS, true);

        expect(markup).toContain(
            '一般利用者、第一営業<span class="whitespace-nowrap">部<button',
        );
        // 「…」のボタン自身の class は数えない(名前を包む span だけを数える)
        expect(markup.match(/<span class="whitespace-nowrap">/g)).toHaveLength(
            1,
        );
        expect(markup).toContain('aria-label="第一営業部のメニュー"');
        expect(markup).toContain('が承認します。');
    });

    it('組織図を許されないと「…」は出ず、文字だけが並ぶ', () => {
        const markup = html(PARTS, false);

        expect(markup).not.toContain('<button');
        expect(markup.replace(/<[^>]*>/g, '')).toBe(
            '一般利用者、第一営業部が承認します。',
        );
    });
});
