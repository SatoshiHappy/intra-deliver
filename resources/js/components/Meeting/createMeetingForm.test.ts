import { describe, expect, it } from 'vite-plus/test';
import { unshownErrorKeys } from '@/lib/formErrors';
import { recordField } from '@/lib/recordField.testing';
import type { RecordModuleDefinition } from '@/types';
import type { CreateMeetingData } from './createMeetingForm';
import {
    acceptedFiles,
    blockedFileError,
    createMeetingPayload,
    dialogFields,
    importFileGuide,
    initialCreateMeetingData,
    namedErrors,
    shownErrorKeys,
} from './createMeetingForm';

/** 会議の業務モジュールの定義(項目の並びは定義の順。ダイアログは自分の並びで出す) */
const meeting: RecordModuleDefinition = {
    code: 'meeting',
    name: '会議',
    description: null,
    rules: [],
    fields: [
        recordField('title', 'string', { name: '件名', isRequired: true }),
        recordField('held_at', 'datetime', {
            name: '日時',
            isRequired: true,
            defaultValue: '2026-10-21T01:30:00Z',
        }),
        recordField('viewer_users', 'user', {
            name: '閲覧者',
            isMultiple: true,
        }),
        recordField('viewer_departments', 'department', {
            name: '閲覧組織',
            isMultiple: true,
        }),
        recordField('project', 'record', { name: 'プロジェクト' }),
        recordField('purge_due_on', 'date', {
            name: '削除予定日',
            isRequired: true,
            defaultValue: '2027-04-21',
        }),
        recordField('summary', 'string', { name: '要約', isMultiline: true }),
        recordField('agenda', 'string', {
            name: 'アジェンダ',
            isMultiline: true,
        }),
        recordField('notes', 'string', { name: '議事メモ', isMultiline: true }),
    ],
};

function data(overrides: Partial<CreateMeetingData> = {}): CreateMeetingData {
    return { ...initialCreateMeetingData(meeting), ...overrides };
}

describe('dialogFields・initialCreateMeetingData', () => {
    it('欄は 日時・件名・閲覧者・閲覧組織・プロジェクト・アジェンダ・議事メモ の順で、削除予定日と議事録の欄を出さない', () => {
        expect(dialogFields(meeting).map((field) => field.name)).toEqual([
            '日時',
            '件名',
            '閲覧者',
            '閲覧組織',
            'プロジェクト',
            'アジェンダ',
            '議事メモ',
        ]);
    });

    it('定義に無い項目は出さない', () => {
        const withoutNotes = {
            ...meeting,
            fields: meeting.fields.filter((field) => field.code !== 'notes'),
        };

        expect(dialogFields(withoutNotes).map((field) => field.code)).toEqual([
            'held_at',
            'title',
            'viewer_users',
            'viewer_departments',
            'project',
            'agenda',
        ]);
    });

    it('開いたときは札がファイルで、日時は定義の既定値(東京の時刻)、ほかは空', () => {
        expect(initialCreateMeetingData(meeting)).toEqual({
            source: 'file',
            file: null,
            text: '',
            values: {
                held_at: '2026-10-21T10:30',
                title: '',
                viewer_users: null,
                viewer_departments: null,
                project: null,
                agenda: '',
                notes: '',
            },
        });
    });
});

/** 欄を埋め、ファイルと貼り付けの両方を持つ値と、その欄の送る値 */
function payloadCase() {
    const picked = (id: number) => ({ id, label: `#${id}`, affiliation: null });
    const filled = data({
        file: new File(['発言'], '定例.txt'),
        text: '貼り付けた発言',
        values: {
            ...data().values,
            title: '',
            viewer_users: [picked(3), picked(5)],
            project: picked(9),
            agenda: '1. 進み具合',
        },
    });
    const values = {
        held_at: '2026-10-21T10:30:00+09:00',
        title: null,
        viewer_users: [3, 5],
        viewer_departments: null,
        project: 9,
        agenda: '1. 進み具合',
        notes: null,
    };

    return { filled, values };
}

describe('createMeetingPayload(ファイル)', () => {
    const { filled, values } = payloadCase();

    it('ファイルの札はファイルと、欄の値(空の件名は null = AI が付ける)の JSON の封筒を送る', () => {
        const payload = createMeetingPayload(meeting, filled);

        expect(payload).toEqual({
            file: filled.file,
            envelope: JSON.stringify({ values }),
        });
        expect(
            'envelope' in payload ? JSON.parse(payload.envelope) : null,
        ).toEqual({ values });
    });
});

describe('createMeetingPayload(貼り付け・ファイルの無いとき)', () => {
    const { filled, values } = payloadCase();

    it('貼り付けの札は文だけを送る', () => {
        expect(
            createMeetingPayload(meeting, { ...filled, source: 'paste' }),
        ).toEqual({ text: '貼り付けた発言', values });
    });

    it('ファイルを選んでいなければ取り込みを送らない(入口がどちらも無いと返す)', () => {
        expect(createMeetingPayload(meeting, data())).toEqual({
            values: {
                ...values,
                title: null,
                viewer_users: null,
                project: null,
                agenda: null,
            },
        });
    });
});

describe('shownErrorKeys', () => {
    it('欄の下に出すのは今の札の取り込みの欄と、ダイアログの欄(複数の参照の欄は添字も)', () => {
        const picked = { id: 1, label: 'A', affiliation: null };
        const shown = shownErrorKeys(
            meeting,
            data({
                source: 'paste',
                values: { ...data().values, viewer_users: [picked, picked] },
            }),
        );

        expect(shown).toContain('text');
        expect(shown).not.toContain('file');
        expect(shown).toEqual(
            expect.arrayContaining([
                'values.held_at',
                'values.title',
                'values.viewer_users.0',
                'values.viewer_users.1',
            ]),
        );
        expect(shown).not.toContain('values.purge_due_on');
    });
});

describe('namedErrors と欄の上の誤り', () => {
    const errors = {
        file: 'ファイルを選ぶか、テキストを貼り付けてください(どちらか 1 つ)',
        ai: 'AI の利用の上限に達しました',
        'values.purge_due_on': '削除予定日を入力してください',
        'values.held_at': '日時を入力してください',
        'values.unknown': '形が正しくありません',
        text: undefined,
    };

    it('項目の値のキーには項目の名前を添え、値の無いキーは除く', () => {
        expect(namedErrors(meeting, errors)).toEqual({
            file: 'ファイルを選ぶか、テキストを貼り付けてください(どちらか 1 つ)',
            ai: 'AI の利用の上限に達しました',
            'values.purge_due_on': '削除予定日：削除予定日を入力してください',
            'values.held_at': '日時：日時を入力してください',
            'values.unknown': '形が正しくありません',
        });
    });

    it('欄の上には欄の下に出さない誤りをキーの順に出す(札で取り込みの欄が替わる)', () => {
        const named = namedErrors(meeting, errors);

        expect(
            unshownErrorKeys(named, shownErrorKeys(meeting, data())),
        ).toEqual(['ai', 'values.purge_due_on', 'values.unknown']);
        expect(
            unshownErrorKeys(
                named,
                shownErrorKeys(meeting, data({ source: 'paste' })),
            ),
        ).toEqual(['ai', 'file', 'values.purge_due_on', 'values.unknown']);
    });
});

describe('importFileGuide・acceptedFiles', () => {
    it('案内は音声とテキストの形式と、音声 1 本の上限(割り切れる最大の単位)', () => {
        expect(importFileGuide(99_614_720)).toBe(
            '音声(m4a・mp3・wav)・テキスト(.txt・.vtt・.md)を 1 つ選択できます。1 ファイル 95MB までです。',
        );
    });

    it('選べるのは音声とテキストで、音声の取り込みを先に止めているときはテキストだけ', () => {
        expect(acceptedFiles(false)).toBe('.m4a,.mp3,.wav,.txt,.vtt,.md');
        expect(acceptedFiles(true)).toBe('.txt,.vtt,.md');
    });
});

describe('blockedFileError', () => {
    const blocked = '今日の AI の利用の上限に近いため、音声は選べません。';

    it('先に止めているときにファイルの札で音声を選ぶと、先の止め方の文を誤りにする(拡張子の大文字小文字を問わない)', () => {
        for (const name of ['定例.mp3', '定例.M4A', '定例.wav']) {
            expect(
                blockedFileError(data({ file: new File([''], name) }), blocked),
            ).toBe(blocked);
        }
    });

    it('テキストのファイルと貼り付けは選べる', () => {
        expect(
            blockedFileError(
                data({ file: new File([''], '定例.txt') }),
                blocked,
            ),
        ).toBeNull();
        expect(
            blockedFileError(
                data({ source: 'paste', file: new File([''], '定例.mp3') }),
                blocked,
            ),
        ).toBeNull();
        expect(blockedFileError(data(), blocked)).toBeNull();
    });

    it('先に止めていなければ音声も誤りにしない', () => {
        expect(
            blockedFileError(data({ file: new File([''], '定例.mp3') }), null),
        ).toBeNull();
    });
});
