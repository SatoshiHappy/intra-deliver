import { describe, expect, it } from 'vite-plus/test';
import {
    applyViewOf,
    blockerOf,
    headingLineOf,
    isFieldKey,
    nextSegments,
    operationOf,
    showsSaveDraft,
    toastedErrorsOf,
    unshownKeysOf,
} from './applyScreen';
import { segmentsText } from './nameSegments';
import type { WorkflowApplyProps } from '@/types';

type Next = WorkflowApplyProps['next'];

function next(overrides: Partial<Next> = {}): Next {
    return {
        version: 1,
        noVersionMessage: null,
        departmentName: null,
        nodes: [],
        dependsOnContent: false,
        ...overrides,
    };
}

const person = { id: 3, code: 'u0003', name: '高橋 誠', mainDepartmentId: 21 };

/** 申請すると誰の未処理に出るかの 1 文の字 */
const nextText = (value: Next, operation: string) =>
    segmentsText(nextSegments(value, operation));

/** 画面の文と値(見出しの下の 1 行は字にして比べる) */
const viewOf = (...args: Parameters<typeof applyViewOf>) => {
    const view = applyViewOf(...args);

    return { ...view, headingLine: segmentsText(view.headingLine) };
};

describe('申請できない理由', () => {
    it('処理担当者がいないステップの文は「処理担当者」を使う(処理対象者は画面に出さない)', () => {
        const props = {
            mode: 'new',
            next: next({
                nodes: [{ name: '部長', targets: [], blockedReason: null }],
            }),
        } as WorkflowApplyProps;

        expect(blockerOf(props)).toBe(
            '部長の処理担当者がいないため、申請できません',
        );
    });

    it('版が無ければ版の無い文、再申請は版を見ない', () => {
        const noVersion = next({ version: null, noVersionMessage: '版なし' });

        expect(
            blockerOf({ mode: 'new', next: noVersion } as WorkflowApplyProps),
        ).toBe('版なし');
        expect(
            blockerOf({
                mode: 'reapply',
                next: noVersion,
            } as WorkflowApplyProps),
        ).toBeNull();
    });
});

describe('申請すると誰の未処理に出るかの 1 文', () => {
    it('先頭のステップの処理担当者を示し、ステップが複数ならほかを添える', () => {
        const nodes = [
            { name: '課長', targets: [person], blockedReason: null },
            { name: '部長', targets: [person], blockedReason: null },
        ];

        expect(nextText(next({ nodes }), '申請')).toBe(
            '申請すると、課長の高橋 誠さんほかの未処理に表示されます。',
        );
        expect(nextText(next({ nodes: nodes.slice(0, 1) }), '再申請')).toBe(
            '再申請すると、課長の高橋 誠さんの未処理に表示されます。',
        );
    });

    it('内容で決まるときと、出せる人がいないとき', () => {
        expect(nextText(next({ dependsOnContent: true }), '申請')).toBe(
            '申請すると、内容に応じたステップの未処理に表示されます。',
        );
        expect(nextText(next(), '申請')).toBe('');
    });

    it('人の名前は札の区切り(3 人を超えたら先頭の人の札と「ほか N 人」)', () => {
        const people = [1, 2, 3, 4].map((id) => ({ ...person, id }));
        const nodes = [{ name: '課長', targets: people, blockedReason: null }];

        expect(nextSegments(next({ nodes }), '申請')).toEqual([
            '申請すると、課長の',
            { person: people[0], text: '高橋 誠さん' },
            'ほか 3 人',
            'の未処理に表示されます。',
        ]);
        expect(
            nextSegments(
                next({ nodes: [{ ...nodes[0], targets: people.slice(0, 2) }] }),
                '申請',
            ),
        ).toEqual([
            '申請すると、課長の',
            { person: people[0], text: '高橋 誠さん' },
            '、',
            { person: people[1], text: '高橋 誠さん' },
            'の未処理に表示されます。',
        ]);
    });
});

describe('画面の出し分け', () => {
    it('見出しの下の 1 行は、申請できない理由が無いときだけ次の 1 文を添える', () => {
        expect(
            headingLineOf('内容を入力して申請します。', ['次の文。'], null),
        ).toEqual(['内容を入力して申請します。', '次の文。']);
        expect(
            headingLineOf('内容を入力して申請します。', ['次の文。'], '理由'),
        ).toEqual(['内容を入力して申請します。']);
    });

    it('一時保存のボタンは新規と一時保存の画面だけ出す(再申請と既存のデータからの申請は出さない)', () => {
        expect(showsSaveDraft('new')).toBe(true);
        expect(showsSaveDraft('draft')).toBe(true);
        expect(showsSaveDraft('reapply')).toBe(false);
        expect(showsSaveDraft('record')).toBe(false);
        expect(operationOf('reapply')).toBe('再申請');
        expect(operationOf('new')).toBe('申請');
    });

    it('連絡の誤りは欄の下、申請者の誤りは欄があるときだけ欄の下(ないときはフォームの先頭)', () => {
        expect(isFieldKey('contact.user_ids', false)).toBe(true);
        expect(isFieldKey('contact.message', false)).toBe(true);
        expect(isFieldKey('applicant_user_id', true)).toBe(true);
        expect(isFieldKey('applicant_user_id', false)).toBe(false);
        expect(isFieldKey('principal_user_id', false)).toBe(false);
        expect(isFieldKey('case', true)).toBe(false);
        expect(isFieldKey('values.name', false)).toBe(true);
    });
});

const record = { values: {}, details: {} };
const recordProps = (overrides: Partial<WorkflowApplyProps> = {}) =>
    ({
        mode: 'record',
        recordId: 7,
        recordTitle: '新製品の発売計画',
        titleField: null,
        flow: { code: 'project_plan', name: 'プロジェクト計画の承認' },
        next: next({
            nodes: [{ name: '課長', targets: [person], blockedReason: null }],
        }),
        ...overrides,
    }) as WorkflowApplyProps;

describe('既存のデータからの申請の見出しの 1 行', () => {
    it('「{表示の項目の値}(既存のデータ)から申請します。」に、申請できない理由が無いときだけ次の 1 文を添える', () => {
        const view = viewOf(recordProps(), record, '');

        expect(view.heading).toBe('プロジェクト計画の承認');
        expect(view.headingLine).toBe(
            '新製品の発売計画(既存のデータ)から申請します。申請すると、課長の高橋 誠さんの未処理に表示されます。',
        );
        expect(
            viewOf(
                recordProps({
                    next: next({
                        nodes: [
                            { name: '課長', targets: [], blockedReason: null },
                        ],
                    }),
                }),
                record,
                '',
            ).headingLine,
        ).toBe('新製品の発売計画(既存のデータ)から申請します。');
    });

    it('表示の項目の値が無いレコードは #<id>', () => {
        expect(
            viewOf(recordProps({ recordTitle: null }), record, '').headingLine,
        ).toMatch(/^#7\(既存のデータ\)から申請します。/);
    });

    it('ほかの画面の 1 行は変わらない', () => {
        expect(
            viewOf(recordProps({ mode: 'new' }), record, '').headingLine,
        ).toMatch(/^内容を入力して申請します。/);
        expect(
            viewOf(
                recordProps({
                    mode: 'reapply',
                    initial: { name: 'N' },
                } as Partial<WorkflowApplyProps>),
                record,
                '',
            ).headingLine,
        ).toMatch(/^内容を入力して再申請します。/);
    });
});

describe('申請対象からの申請の見出しの 1 行(業務レコードの詳細から開いた画面だけ、今の申請対象の欄で決める)', () => {
    const targetProps = (overrides: Partial<WorkflowApplyProps> = {}) =>
        recordProps({
            mode: 'new',
            targetField: 'project',
            targetEntry: { module: 'project', record: 5 },
            ...overrides,
        });
    const target = (label: string | null) => ({
        values: { project: label === null ? null : { id: 5, label } },
        details: {},
    });

    it('申請対象が入っていれば「{申請対象の表示の項目の値}を申請対象にして申請します。」に次の 1 文を添える', () => {
        expect(
            viewOf(targetProps(), target('新製品の発売計画'), '').headingLine,
        ).toBe(
            '新製品の発売計画を申請対象にして申請します。申請すると、課長の高橋 誠さんの未処理に表示されます。',
        );
    });

    it('申請対象が空になれば(申請者を変えた・空にした)新規の申請の 1 行', () => {
        expect(viewOf(targetProps(), target(null), '').headingLine).toMatch(
            /^内容を入力して申請します。/,
        );
    });

    it('業務レコードの詳細から開いていない画面(targetEntry が無い)は、申請対象が入っていても今の 1 行のまま', () => {
        expect(
            viewOf(
                targetProps({ targetEntry: null }),
                target('新製品の発売計画'),
                '',
            ).headingLine,
        ).toMatch(/^内容を入力して申請します。/);
    });
});

describe('送信の誤りのうちトーストでも出すもの', () => {
    const errors = {
        record: '申請済みのため申請できません',
        name: '案件名が長すぎます',
        case: 'x',
    };

    it('既存のデータからの申請は record だけ。欄の誤りと封筒の誤りは出さない', () => {
        expect(toastedErrorsOf('record', errors)).toEqual({
            record: '申請済みのため申請できません',
        });
        expect(toastedErrorsOf('record', { name: '長すぎます' })).toEqual({});
        expect(toastedErrorsOf('record', {})).toEqual({});
    });

    it('新規・一時保存・再申請の画面は、record があっても出さない', () => {
        for (const mode of ['new', 'draft', 'reapply'] as const) {
            expect(toastedErrorsOf(mode, errors)).toEqual({});
        }
    });
});

describe('添付の誤りの出し方', () => {
    const errors = {
        record: 'レコードの誤り',
        attachments: '面の誤り',
        'attachments.0': '行の誤り',
        remove_attachment_ids: '外す誤り',
        name: '案件名の誤り',
    };

    it('添付の面を出しているときは面の下に出るので、フォームの先頭には出さない', () => {
        expect(
            unshownKeysOf(errors, true, { enabled: true, drafts: [] }),
        ).toEqual(['record']);
    });

    it('添付の面を出していないときは、添付の誤りもフォームの先頭に出す(使えない間の行の誤りは出さない)', () => {
        expect(
            unshownKeysOf(errors, true, { enabled: false, drafts: [] }),
        ).toEqual(['attachments', 'record', 'remove_attachment_ids']);
    });

    it('添付の誤りはトーストにしない(面の下の誤りと二重に出さない)。トーストにするのは既存のデータからの申請の record だけ', () => {
        for (const mode of ['new', 'draft', 'reapply', 'record'] as const) {
            const toasted = toastedErrorsOf(mode, errors);

            expect(Object.keys(toasted)).toEqual(
                mode === 'record' ? ['record'] : [],
            );
        }
    });
});
