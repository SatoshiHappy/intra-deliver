import { describe, expect, it } from 'vite-plus/test';
import {
    adminSurfaceViews,
    distinctErrors,
    ownSurfaceViews,
    surfaceHeading,
    settingPayload,
} from './noticeSettings';

describe('surfaceHeading', () => {
    it('面の見出しは接頭辞から引く(無い接頭辞は「通知」)。種類の語は noticeText.ts の札', () => {
        expect(surfaceHeading('workflow')).toBe('ワークフロー');
        expect(surfaceHeading('records')).toBe('レコード');
        expect(surfaceHeading('meeting')).toBe('会議');
        expect(surfaceHeading('unknown')).toBe('通知');
    });
});

/** 利用者の画面の 1 行の props(種類・本人のミュート・必須) */
function ownRow(type: string, muted: boolean, isRequiredType: boolean) {
    return { type, muted, isRequiredType };
}

const [OWN_SURFACE] = ownSurfaceViews([
    {
        prefix: 'workflow',
        rows: [
            ownRow('workflow.process_request', false, true),
            ownRow('workflow.transfer', false, false),
            ownRow('workflow.retarget', true, false),
        ],
    },
]);

describe('ownSurfaceViews(利用者の画面。3.2 の必須の行)', () => {
    it('1 行目 = 必須: ミュートしない・押せない・札「必須(管理者の設定)」', () => {
        expect(OWN_SURFACE.rows[0]).toEqual({
            type: 'workflow.process_request',
            label: '処理依頼',
            checked: false,
            locked: true,
            word: 'ミュートしない',
            switchName: '処理依頼をミュートする',
            badge: '必須(管理者の設定)',
        });
    });

    it('必須の行は本人の設定(muted = true)によらずミュートしない', () => {
        const [required] = ownSurfaceViews([
            {
                prefix: 'workflow',
                rows: [ownRow('workflow.process_request', true, true)],
            },
        ]);

        expect(required.rows[0]).toMatchObject({
            checked: false,
            locked: true,
            word: 'ミュートしない',
            badge: '必須(管理者の設定)',
        });
    });
});

describe('ownSurfaceViews(利用者の画面。3.2 の必須でない行)', () => {
    it('2 行目 = 必須でなくミュートしない: ミュートしない・押せる・札なし', () => {
        expect(OWN_SURFACE.rows[1]).toMatchObject({
            checked: false,
            locked: false,
            word: 'ミュートしない',
            switchName: '担当変更をミュートする',
            badge: null,
        });
    });

    it('3 行目 = 必須でなくミュート中: ミュート中・押せる・札なし', () => {
        expect(OWN_SURFACE.rows[2]).toMatchObject({
            checked: true,
            locked: false,
            word: 'ミュート中',
            switchName: '処理担当者の変更をミュートする',
            badge: null,
        });
    });
});

describe('ownSurfaceViews(面の並び)', () => {
    it('面の見出しは接頭辞から引き、面と行の並びは props のまま', () => {
        const views = ownSurfaceViews([
            { prefix: 'records', rows: [] },
            {
                prefix: 'workflow',
                rows: [
                    ownRow('workflow.result', true, false),
                    ownRow('workflow.confirm_request', true, false),
                ],
            },
        ]);

        expect(views.map((view) => view.heading)).toEqual([
            'レコード',
            'ワークフロー',
        ]);
        expect(views[1].rows.map((row) => row.label)).toEqual([
            '処理結果',
            '確認依頼',
        ]);
    });

    it('ゲスト(surfaces が空)は面を作らない', () => {
        expect(ownSurfaceViews([])).toEqual([]);
    });
});

describe('adminSurfaceViews(管理の画面。4.2 の 2 通り)', () => {
    const [surface] = adminSurfaceViews([
        {
            prefix: 'records',
            rows: [
                { type: 'records.comment', isRequiredType: true },
                { type: 'meeting.minutes_created', isRequiredType: false },
            ],
        },
    ]);

    it('必須: スイッチは入・語「必須」・読み上げ「{種類}を必須にする」', () => {
        expect(surface.rows[0]).toEqual({
            type: 'records.comment',
            label: 'コメント',
            checked: true,
            locked: false,
            word: '必須',
            switchName: 'コメントを必須にする',
            badge: null,
        });
    });

    it('必須でない: スイッチは切・語「必須でない」', () => {
        expect(surface.rows[1]).toMatchObject({
            checked: false,
            locked: false,
            word: '必須でない',
            switchName: '議事録の完了を必須にする',
        });
    });
});

describe('settingPayload・distinctErrors', () => {
    it('クリックした後の値を type と画面ごとの値のキー(muted・required)で送る', () => {
        expect(settingPayload('muted', 'workflow.transfer', true)).toEqual({
            type: 'workflow.transfer',
            muted: true,
        });
        expect(settingPayload('required', 'workflow.transfer', false)).toEqual({
            type: 'workflow.transfer',
            required: false,
        });
    });

    it('422 のエラーは、当たるキーが複数でも同じ文を 1 つにして出す', () => {
        const message = 'この通知の設定は保存できませんでした。';

        expect(distinctErrors({ type: message, muted: message })).toEqual({
            type: message,
        });
        expect(
            distinctErrors({
                type: '管理者が必須に設定しているため、変更できません。',
            }),
        ).toEqual({
            type: '管理者が必須に設定しているため、変更できません。',
        });
    });
});
