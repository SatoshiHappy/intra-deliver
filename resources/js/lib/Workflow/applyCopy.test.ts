import { describe, expect, it } from 'vite-plus/test';
import {
    APPLICATION_TARGET_FIELD,
    clearedOf,
    planModule,
    planModuleWithDefaults,
    reference,
    snapshotOf,
} from './applicationTarget.testing';
import { applyPayloadOf } from './applyForm';
import type { ApplyForm } from './applyForm';
import { openedFormOf, openedOf, withOpened } from './applyOpened';
import { backHrefOf } from './applyRoutes';
import { applyViewOf, toastedErrorsOf, unshownKeysOf } from './applyScreen';
import { segmentsText } from './nameSegments';
import type { WorkflowApplyProps } from '@/types';

// 案件からの複製で開いた申請の画面(docs の 設計/ワークフロー/申請の周辺/画面.md の 5 節・8 節、
// 既存のデータからの申請/案件からの複製/検査.md の 14 節の 13.2・13.4・13.5 のうち画面の部品のテストの層)。

const copy = {
    caseId: 12,
    caseNumber: 'WF-000012',
    name: '出張申請',
    clearedFields: [
        ...clearedOf(['manager']),
        {
            kind: 'viewers' as const,
            field: null,
            detail: null,
            row: null,
            name: '参照制限の参照者',
        },
    ],
    rowAccess: 'sealed-value',
};

/** 案件からの複製で開いた時の props(要るキーだけ) */
function props(
    overrides: Partial<WorkflowApplyProps> = {},
): WorkflowApplyProps {
    return {
        mode: 'new',
        case: null,
        recordId: null,
        module: planModule,
        flow: {
            code: 'project_plan',
            name: 'プロジェクト計画の承認',
            description: null,
        },
        titleField: null,
        baseDate: '2026-10-13',
        applicant: null,
        initial: {
            name: '出張申請',
            comment: null,
            values: {
                project: reference(5, 'P5'),
                name: '元の名前',
                budget: 100,
            },
            details: {},
        },
        next: {
            version: 1,
            noVersionMessage: null,
            departmentName: null,
            nodes: [],
            dependsOnContent: false,
        },
        targetField: APPLICATION_TARGET_FIELD,
        targetValues: null,
        targetEntry: null,
        copy,
        ...overrides,
    } as WorkflowApplyProps;
}

/** 開いた後の GET の props(copy・targetValues は返らない) */
const later = props({ copy: null, targetValues: null });

describe('開いた時の props を保つ(申請対象からの申請の targetEntry と同じ規則)', () => {
    it('同じ申請の画面(部分の再読み込み・入力の誤りで戻る GET)と戻る・進むでは、開いた時の copy を保つ', () => {
        const opened = openedOf(props());

        expect(withOpened(later, opened).copy).toEqual(copy);
    });

    it('一時保存して一時保存の画面へ移ったら保たない(1 行・キャンセルの移り先・暗号化した値が消える)', () => {
        const draft = props({
            mode: 'draft',
            case: { id: 30, number: null, revision: 0 },
            copy: null,
        });
        const kept = withOpened(draft, openedOf(props()));

        expect(kept.copy).toBeNull();
        expect(
            Object.keys(applyPayloadOf(kept, form(), 'apply')),
        ).not.toContain('copy_row_access');
    });
});

/** 送るフォームの値 */
function form(): ApplyForm {
    return {
        name: '出張申請',
        comment: '',
        base_date: '2026-10-13',
        applicant: null,
        record: { values: {}, details: {} },
        contact_users: [],
        contact_message: '',
    };
}

describe('見出しの下の頭の文(画面.md の 8 節)', () => {
    it('頭の文は案件からの複製の文で、申請対象が空になっても変えない', () => {
        const view = applyViewOf(later, { values: {}, details: {} }, '');
        const kept = applyViewOf(
            withOpened(later, openedOf(props())),
            { values: { project: null }, details: {} },
            '',
        );

        expect(segmentsText(view.headingLine)).toBe(
            '内容を入力して申請します。',
        );
        expect(segmentsText(kept.headingLine)).toBe(
            '出張申請(申請番号 WF-000012)を複製して申請します。',
        );
    });
});

describe('キャンセルと送るもの', () => {
    it('キャンセルは元の申請の詳細へ', () => {
        expect(backHrefOf(withOpened(later, openedOf(props()))).url).toBe(
            '/workflow/cases/12',
        );
        expect(backHrefOf(later).url).toBe('/workflow/flows');
    });

    it('申請と一時保存の POST に暗号化した値を送り返す(開いた後の GET の props でも保った値)', () => {
        const kept = withOpened(later, openedOf(props()));

        for (const kind of ['apply', 'saveDraft'] as const) {
            expect(applyPayloadOf(kept, form(), kind)).toMatchObject({
                copy_row_access: 'sealed-value',
            });
        }
    });

    it('書き込みの口の参照者の 422 は、フォームの先頭に出してトーストを出さない', () => {
        const errors = {
            'row_access_viewers.0': '参照者の値が正しくありません',
        };
        const attachment = { enabled: false, drafts: [] };

        expect(unshownKeysOf(errors, true, attachment)).toEqual([
            'row_access_viewers.0',
        ]);
        expect(toastedErrorsOf('new', errors)).toEqual({});
    });
});

describe('開いた時の申請対象の写し(10.2 の「開いた時: 案件からの複製」の行)', () => {
    const withTarget = props({
        targetValues: snapshotOf(
            { project: reference(5, 'P5'), name: '今の名前' },
            {},
            [],
        ),
    });

    it('写した値と申請対象の値が違えば確かめのダイアログ。戻る・進むで来たときは出さない', () => {
        const opened = openedFormOf(withTarget, 'k', () => undefined);

        expect(opened.opening.confirm).not.toBeNull();
        expect(opened.data.record.values.name).toBe('元の名前');

        const remembered: Record<string, unknown> = { 'k:data': opened.data };
        const back = openedFormOf(withTarget, 'k', (key) => remembered[key]);

        expect(back.restored).toBe(true);
        expect(back.opening.confirm).toBeNull();
    });

    it('違わなければ確かめずに写す', () => {
        const same = props({
            targetValues: snapshotOf(
                { project: reference(5, 'P5'), name: '元の名前' },
                {},
                [],
            ),
        });

        expect(
            openedFormOf(same, 'k', () => undefined).opening.confirm,
        ).toBeNull();
    });
});

describe('開いた時の申請対象の写しで確かめない行(入力済みに数えない欄だけが違う)', () => {
    it('元の申請の値が写す時の確かめで空になった項目だけが違えば、確かめずに写す', () => {
        const emptied = props({
            initial: {
                name: '出張申請',
                comment: null,
                values: {
                    project: reference(5, 'P5'),
                    name: null,
                    budget: 100,
                },
                details: {},
            },
            copy: { ...copy, clearedFields: clearedOf(['name']) },
            targetValues: snapshotOf(
                { project: reference(5, 'P5'), name: '今の名前' },
                {},
                [],
            ),
        });
        const opened = openedFormOf(emptied, 'k', () => undefined);

        expect(opened.opening.confirm).toBeNull();
        expect(opened.data.record.values.name).toBe('今の名前');
    });

    it('元の値が既定値と同じ欄だけが違えば、確かめずに写す', () => {
        const atDefault = props({
            module: planModuleWithDefaults({ budget: 100 }),
            targetValues: snapshotOf(
                { project: reference(5, 'P5'), name: '元の名前', budget: 200 },
                {},
                [],
            ),
        });
        const opened = openedFormOf(atDefault, 'k', () => undefined);

        expect(opened.opening.confirm).toBeNull();
        expect(opened.data.record.values.budget).toBe('200');
    });
});
