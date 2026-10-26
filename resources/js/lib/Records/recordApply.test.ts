import { describe, expect, it } from 'vite-plus/test';
import { recordApplyButtonOf, recordApplyDialogTextOf } from './recordApply';
import type { RecordApplyEntry } from '@/types';

const plan: RecordApplyEntry = {
    name: 'プロジェクト計画の承認',
    description: '上長と部長の承認で決めます。',
    url: 'http://localhost/workflow/flows/project_plan/apply?record=5',
};
const second: RecordApplyEntry = {
    name: '計画の簡易承認',
    description: null,
    url: 'http://localhost/workflow/flows/plan_light/apply?record=5',
};

describe('「申請する」の出し方(詳細の 4 つの枝)', () => {
    it('入口が空なら出さない(持ち主があっても出さない)', () => {
        expect(recordApplyButtonOf([], false)).toEqual({ kind: 'none' });
        expect(recordApplyButtonOf([], true)).toEqual({ kind: 'none' });
    });

    it('持ち主があれば、入口が 1 つでも 2 つでも押せない形', () => {
        expect(recordApplyButtonOf([plan], true)).toEqual({
            kind: 'disabled',
        });
        expect(recordApplyButtonOf([plan, second], true)).toEqual({
            kind: 'disabled',
        });
    });

    it('入口が 1 つなら申請の画面への URL', () => {
        expect(recordApplyButtonOf([plan], false)).toEqual({
            kind: 'link',
            href: plan.url,
        });
    });

    it('入口が 2 つ以上ならフローを選ぶダイアログ(入口はそのまま渡す)', () => {
        expect(recordApplyButtonOf([plan, second], false)).toEqual({
            kind: 'dialog',
            entries: [plan, second],
        });
    });
});

describe('フローを選ぶダイアログの文', () => {
    it('題と、見出しと件数を入れた本文', () => {
        expect(recordApplyDialogTextOf('新製品の発売計画', 2)).toEqual({
            title: '申請するフローを選択',
            description: '新製品の発売計画を申請できるフローが2つあります。',
        });
    });
});
