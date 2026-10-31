import { describe, expect, it } from 'vite-plus/test';
import {
    CONFIRMATION_BADGES,
    actorViewText,
    assignmentNoteOf,
    confirmationNoteOf,
    historyNoteOf,
    markedPeopleSegments,
    processorViewSegments,
    progressItemKeysOf,
    reachedDateOf,
} from './caseDetail';
import { segmentsText } from './nameSegments';

const yoshida = { id: 9, code: 'u0009', name: '吉田 達也' };
const takahashi = { id: 3, code: 'u0003', name: '高橋 誠' };
const noName = { id: 5, code: 'u0005', name: null };

describe('processorViewSegments: 処理実行者の名前の札(フロー情報の表)', () => {
    it('代理なら実行者と処理者の両方が札、字は履歴の行と同じ', () => {
        const actor = { ...takahashi, mainDepartmentId: 21 };
        const executor = { ...yoshida, mainDepartmentId: null };
        const segments = processorViewSegments({ actor, executor });

        expect(segments).toEqual([
            { person: executor, text: '吉田 達也' },
            '(',
            { person: actor, text: '高橋 誠さん' },
            'の代理)',
        ]);
        expect(segmentsText(segments)).toBe(actorViewText({ actor, executor }));
        expect(processorViewSegments({ actor, executor: null })).toEqual([
            { person: actor, text: '高橋 誠' },
        ]);
    });
});

describe('actorViewText: 処理実行者の表示', () => {
    it('実行者が処理者と違えば「{実行者}({処理者}さんの代理)」', () => {
        expect(actorViewText({ actor: takahashi, executor: yoshida })).toBe(
            '吉田 達也(高橋 誠さんの代理)',
        );
    });

    it('本人で処理したときは処理者の名前だけ(名前が無ければコード)', () => {
        expect(actorViewText({ actor: takahashi, executor: null })).toBe(
            '高橋 誠',
        );
        expect(actorViewText({ actor: noName, executor: null })).toBe('u0005');
        expect(actorViewText({ actor: takahashi, executor: noName })).toBe(
            'u0005(高橋 誠さんの代理)',
        );
    });
});

const base = { nodeName: '部長', users: [], moveTargetName: null };

describe('historyNoteOf: 履歴に添える 1 行', () => {
    it('担当変更は「担当変更: {振替先}」', () => {
        expect(
            historyNoteOf({ ...base, action: 'transfer', users: [yoshida] }),
        ).toBe('担当変更: 吉田 達也');
    });

    it('処理担当者の変更と再展開は「処理担当者: {名前を「、」で}」', () => {
        const users = [yoshida, takahashi];

        expect(historyNoteOf({ ...base, action: 'retarget', users })).toBe(
            '処理担当者: 吉田 達也、高橋 誠',
        );
        expect(historyNoteOf({ ...base, action: 'reexpand', users })).toBe(
            '処理担当者: 吉田 達也、高橋 誠',
        );
    });

    it('戻るは「戻り先: {ステップ名}」(戻り先が無ければ出さない)', () => {
        expect(
            historyNoteOf({
                ...base,
                action: 'move_back',
                moveTargetName: '課長',
            }),
        ).toBe('戻り先: 課長');
        expect(historyNoteOf({ ...base, action: 'move_back' })).toBeNull();
    });
});

describe('historyNoteOf: 指定の変更・ほかの種別・承認の指定', () => {
    it('指定の変更は「指定: {ステップ名} = {名前}」、0 人は「なし」', () => {
        expect(
            historyNoteOf({
                ...base,
                action: 'reassign',
                users: [yoshida, takahashi],
            }),
        ).toBe('指定: 部長 = 吉田 達也、高橋 誠');
        expect(historyNoteOf({ ...base, action: 'reassign' })).toBe(
            '指定: 部長 = なし',
        );
    });

    it('ほかの種別は添える行を持たない', () => {
        for (const action of [
            'apply',
            'approve',
            'hold',
            'release',
            'move_forward',
            'move_finish',
        ] as const) {
            expect(historyNoteOf({ ...base, action })).toBeNull();
        }
    });

    it('承認の行の指定は「指定: {ステップ名} = {名前}」、0 人は「省略」', () => {
        expect(
            assignmentNoteOf({ nodeName: '責任者', people: [takahashi] }),
        ).toBe('指定: 責任者 = 高橋 誠');
        expect(assignmentNoteOf({ nodeName: '合議', people: [] })).toBe(
            '指定: 合議 = 省略',
        );
    });
});

describe('確認の面', () => {
    it('1 行は自分が確認する人かで 2 つの形', () => {
        expect(confirmationNoteOf(true)).toBe(
            '確認は処理に影響しません。何度でも確認でき、確認した日時が残ります。',
        );
        expect(confirmationNoteOf(false)).toBe(
            '確認は処理に影響しません。確認する人は、ステップが処理済みになると確認できます。',
        );
    });

    it('状態の札の 6 つの語と色(省略・確認の行なしは札にしない)', () => {
        expect(
            Object.entries(CONFIRMATION_BADGES).map(
                ([status, badge]) => `${status}:${badge.label}:${badge.tone}`,
            ),
        ).toEqual([
            'unconfirmed:未確認:pending',
            'confirmed:確認済:settled',
            'waiting:承認待ち:pending',
            'reapply:再申請待ち:returned',
            'skipped:省略:null',
            'none:—:null',
        ]);
    });
});

describe('申請情報とフロー情報の値', () => {
    it('到達日は YYYY/MM/DD、無ければ null', () => {
        const deadline = {
            reachedOn: '2026-10-06',
            elapsedDays: 9,
            dueOn: null,
            overdue: false,
        };

        expect(reachedDateOf(deadline)).toBe('2026/10/06');
        expect(reachedDateOf({ ...deadline, reachedOn: null })).toBeNull();
        expect(reachedDateOf(null)).toBeNull();
    });

    it('処理担当者の列は「、」で並べ、自分に「(自分)」。名前は札の区切り。いなければ null', () => {
        const me = { ...yoshida, mainDepartmentId: 21, isMe: true };
        const other = { ...takahashi, mainDepartmentId: null, isMe: false };
        const segments = markedPeopleSegments([me, other]);

        expect(segments).toEqual([
            { person: me, text: '吉田 達也(自分)' },
            '、',
            { person: other, text: '高橋 誠' },
        ]);
        expect(segmentsText(segments ?? [])).toBe('吉田 達也(自分)、高橋 誠');
        expect(markedPeopleSegments([])).toBeNull();
    });
});

describe('progressItemKeysOf: 申請情報の進み具合の項目', () => {
    it('完了は現在のステップ(値は「—」)の後に完了日だけ', () => {
        expect(progressItemKeysOf('completed')).toEqual([
            'currentStep',
            'completedOn',
        ]);
    });

    it('未完了は現在のステップの後に期限・到達日・経過日数', () => {
        const expected = ['currentStep', 'due', 'reachedOn', 'elapsedDays'];

        expect(progressItemKeysOf('active')).toEqual(expected);
        expect(progressItemKeysOf('draft')).toEqual(expected);
    });
});
