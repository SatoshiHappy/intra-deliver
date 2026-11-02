import { describe, expect, it } from 'vite-plus/test';
import {
    openedFormOf,
    openedOf,
    rememberKeyOf,
    restoredOf,
    strippedUrlOf,
    withOpened,
} from './applyOpened';
import {
    markOf,
    markedOf,
    picked,
    planModule,
    recordOf,
    reference,
    snapshotOf,
} from './applicationTarget.testing';
import type { ApplyForm } from './applyForm';
import type { WorkflowApplyProps } from '@/types';

/** 開いた時の props(要るキーだけ) */
function props(overrides: Partial<WorkflowApplyProps>): WorkflowApplyProps {
    return {
        mode: 'new',
        case: null,
        targetField: 'project',
        targetEntry: null,
        copy: null,
        ...overrides,
    } as WorkflowApplyProps;
}

const entry = { module: 'project', record: 5 };

describe('写しのためだけのクエリを外した URL(画面.md の 5 節)', () => {
    it('target と copy を外し、ほかのクエリ(record・applicant・base_date)は残す', () => {
        expect(
            strippedUrlOf(
                'https://x.test/workflow/flows/f/apply?target=5&applicant=3&base_date=2026-10-13',
            ),
        ).toBe(
            'https://x.test/workflow/flows/f/apply?applicant=3&base_date=2026-10-13',
        );
        expect(
            strippedUrlOf('https://x.test/workflow/flows/f/apply?copy=7'),
        ).toBe('https://x.test/workflow/flows/f/apply');
    });

    it('外すものが無ければ置き換えない(record は申請するものを決めるので外さない)', () => {
        expect(
            strippedUrlOf('https://x.test/workflow/flows/f/apply?record=9'),
        ).toBeNull();
        expect(
            strippedUrlOf('https://x.test/workflow/flows/f/apply'),
        ).toBeNull();
    });
});

describe('開いた時の props を保つ', () => {
    it('同じ申請の画面(入力の誤りで戻る GET・部分の再読み込み)では、開いた時の targetEntry を保つ', () => {
        const opened = openedOf(props({ targetEntry: entry }));

        expect(withOpened(props({}), opened).targetEntry).toEqual(entry);
    });

    it('一時保存して一時保存の画面へ移ったら保たない', () => {
        const opened = openedOf(props({ targetEntry: entry }));
        const draft = props({
            mode: 'draft',
            case: { id: 3, number: null, revision: 1 },
        });

        expect(withOpened(draft, opened).targetEntry).toBeNull();
        expect(rememberKeyOf(draft)).not.toBe(rememberKeyOf(props({})));
    });

    it('copy の無い前の形の覚えた値から戻ると、copy は null', () => {
        const { copy: _absent, ...older } = openedOf(props({}));

        expect(withOpened(props({}), older).copy).toBeNull();
    });
});

describe('戻る・進むで来た時(申請対象からの申請.md の 10.2 の「戻る・進むで来た時」の行)', () => {
    const left = {
        name: '',
        comment: '',
        base_date: '2026-10-20',
        applicant: null,
        record: recordOf({
            project: picked(6, '選びかけ'),
            name: '打ち直した',
        }),
        contact_users: [],
        contact_message: '',
    } as ApplyForm;

    it('欄は離れた時の値のまま、申請対象は最後に決まったものに戻り、確かめのダイアログは出さず、1 行は離れた時のまま', () => {
        const restored = restoredOf(
            left,
            { settled: picked(5, '決まった') },
            'project',
            { copy: [], target: [markOf('担当者')] },
        );

        expect(restored.data.record.values.name).toBe('打ち直した');
        expect(restored.data.record.values.project).toEqual(
            picked(5, '決まった'),
        );
        expect(restored.data.base_date).toBe('2026-10-20');
        expect(restored.opening).toEqual({
            record: restored.data.record,
            confirm: null,
            cleared: [markOf('担当者')],
        });
    });

    it('申請対象の項目が無い画面と、写しの状態を覚えていないときは欄をそのまま戻す', () => {
        const none = { copy: [], target: [] };

        expect(restoredOf(left, undefined, 'project', none).data).toEqual(left);
        expect(restoredOf(left, { settled: null }, null, none).data).toEqual(
            left,
        );
    });
});

/** 業務レコードの詳細から開いた申請の画面(開いた時に写す) */
const fromRecord = props({
    module: planModule,
    initial: null,
    baseDate: '2026-10-13',
    applicant: null,
    targetValues: snapshotOf(
        { project: reference(5, 'P5'), name: '写した名前' },
        {},
        ['担当者'],
    ),
    targetEntry: { module: 'project', record: 5 },
});

describe('開いた時のフォームの値(戻る・進むで来たか)', () => {
    it('初めて開いた: 開いた時の写しを当て、1 行を出す', () => {
        const opened = openedFormOf(fromRecord, 'k', () => undefined);

        expect(opened.restored).toBe(false);
        expect(opened.data.record.values.name).toBe('写した名前');
        expect(markedOf(opened.opening.cleared)).toEqual(['担当者']);
    });

    it('① 打ち直して離れて戻る: 写しが再び当たらず打ち直した値のまま。④ 確かめの途中で離れて戻る: 申請対象は最後に決まったもの、ダイアログなし', () => {
        const left = openedFormOf(fromRecord, 'k', () => undefined).data;
        const remembered: Record<string, unknown> = {
            'k:data': {
                ...left,
                record: recordOf({
                    project: picked(6, '確かめ中の B'),
                    name: '打ち直した',
                }),
            },
            'k:target': { settled: picked(5, 'P5') },
            'k:cleared': { copy: [], target: [markOf('担当者')] },
        };

        const opened = openedFormOf(fromRecord, 'k', (key) => remembered[key]);

        expect(opened.restored).toBe(true);
        expect(opened.data.record.values.name).toBe('打ち直した');
        expect(opened.data.record.values.project).toEqual(picked(5, 'P5'));
        expect(opened.opening.confirm).toBeNull();
        expect(markedOf(opened.opening.cleared)).toEqual(['担当者']);
    });
});

describe('印の形の前の履歴の項目から戻る', () => {
    it('覚えた入力があり印の形の前の履歴の項目(印を覚えていない)は、印を新しく作り、申請対象の写しの印は無い', () => {
        const left = openedFormOf(fromRecord, 'k', () => undefined).data;
        const remembered: Record<string, unknown> = {
            'k:data': left,
            'k:target': { settled: picked(5, 'P5'), cleared: ['担当者'] },
        };

        const opened = openedFormOf(fromRecord, 'k', (key) => remembered[key]);

        expect(opened.restored).toBe(true);
        expect(opened.opening.cleared).toEqual([]);
        expect(opened.copyMarks).toEqual([]);
    });
});
