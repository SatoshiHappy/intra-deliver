import { describe, expect, it } from 'vite-plus/test';
import { flowShowUrl, propsOfTab } from './flowDefinitionUrl';

describe('flowShowUrl', () => {
    it('版・タブ・選んだステップをクエリに入れる', () => {
        expect(
            flowShowUrl('project_plan', {
                version: 3,
                tab: 'diagram',
                node: 'manager',
            }),
        ).toBe(
            '/workflow/admin/flows/project_plan?version=3&tab=diagram&node=manager',
        );
    });

    it('ステップを選んでいなければ node を付けない', () => {
        expect(
            flowShowUrl('project_plan', { version: 2, tab: 'versions' }),
        ).toBe('/workflow/admin/flows/project_plan?version=2&tab=versions');
    });
});

describe('propsOfTab', () => {
    it('フロー図は図と選んだステップと選択の欄の候補、版は版の一覧と今日、参照者は参照者を取り直す', () => {
        expect(propsOfTab('diagram')).toEqual([
            'tab',
            'selectedVersion',
            'diagram',
            'selectedNode',
            'options',
        ]);
        expect(propsOfTab('versions')).toEqual(['tab', 'versions', 'today']);
        expect(propsOfTab('referrers')).toEqual(['tab', 'referrers']);
    });
});
