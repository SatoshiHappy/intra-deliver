import { describe, expect, it } from 'vite-plus/test';
import {
    attachmentErrorsOf,
    attachmentSurfaceOf,
    filesToSendOf,
} from './applyAttachment';
import { unshownKeysOf } from './applyScreen';

/**
 * 使えなくなったときの添付の誤りの出し方の表(設計/ワークフロー/申請の周辺/画面.md の 2 節の「使えなくなったとき」)。
 * 軸 = 使える/使えない × 行の誤り(attachments.<添字>)/面の誤り(attachments・remove_attachment_ids) × 面の出し方(全部・一時保存の行だけ・出さない)。
 */

const draft = { id: 1, name: 'a.pdf', size: 10, removable: true };
const errors = {
    'attachments.0': '行の誤り',
    attachments: '面の誤り',
    remove_attachment_ids: '外す誤り',
};

type Case = {
    name: string;
    attachment: { enabled: boolean; drafts: (typeof draft)[] };
    surfaceKind: 'full' | 'drafts' | 'none';
    /** 呼び手が渡す数(使えない間は選んだファイルを出さないので 0) */
    fileCount: number;
    rows: Record<number, string>;
    surface: string[];
    top: string[];
};

/** 面の下の誤りは、キーの並びの順(attachments → attachments.<添字> → remove_attachment_ids) */
const cases: Case[] = [
    {
        name: '使える・面は全部・選んだファイルあり: 行の誤りは行の下、面の誤りは面の下',
        attachment: { enabled: true, drafts: [] },
        surfaceKind: 'full',
        fileCount: 1,
        rows: { 0: '行の誤り' },
        surface: ['面の誤り', '外す誤り'],
        top: [],
    },
    {
        name: '使える・面は全部・選んだファイルなし: 行が無いので行の誤りも面の下',
        attachment: { enabled: true, drafts: [draft] },
        surfaceKind: 'full',
        fileCount: 0,
        rows: {},
        surface: ['面の誤り', '行の誤り', '外す誤り'],
        top: [],
    },
    {
        name: '使えない・一時保存の行だけ: 行の誤りは出さず、面の誤りは面の下',
        attachment: { enabled: false, drafts: [draft] },
        surfaceKind: 'drafts',
        fileCount: 0,
        rows: {},
        surface: ['面の誤り', '外す誤り'],
        top: [],
    },
    {
        name: '使えない・面を出さない: 行の誤りは出さず、面の誤りはフォームの先頭',
        attachment: { enabled: false, drafts: [] },
        surfaceKind: 'none',
        fileCount: 0,
        rows: {},
        surface: ['面の誤り', '外す誤り'],
        top: ['attachments', 'remove_attachment_ids'],
    },
];

describe('使えるかと面の出し方ごとの添付の誤り', () => {
    it.each(cases)('$name', (item) => {
        const placed = attachmentErrorsOf(
            errors,
            item.fileCount,
            item.attachment.enabled,
        );

        expect(attachmentSurfaceOf(item.attachment).kind).toBe(
            item.surfaceKind,
        );
        expect(placed.rows).toEqual(item.rows);
        expect(placed.surface).toEqual(item.surface);
        expect(unshownKeysOf(errors, true, item.attachment)).toEqual(item.top);
    });
});

describe('使える → 使えない → 使える', () => {
    it('使えない間は行の誤りをどこにも出さず、使えるに戻ると選んだファイルと行の誤りが行の下に戻る', () => {
        const only = { 'attachments.0': '行の誤り' };
        const chosen = [new File(['x'], 'a.txt')];
        const on = { enabled: true, drafts: [draft] };
        const off = { enabled: false, drafts: [draft] };
        const placedOf = (attachment: typeof on) =>
            attachmentErrorsOf(
                only,
                filesToSendOf(attachment, chosen).length,
                attachment.enabled,
            );

        expect(placedOf(on)).toEqual({
            rows: { 0: '行の誤り' },
            surface: [],
        });
        expect(placedOf(off)).toEqual({ rows: {}, surface: [] });
        expect(unshownKeysOf(only, true, off)).toEqual([]);
        expect(placedOf(on)).toEqual({
            rows: { 0: '行の誤り' },
            surface: [],
        });
    });
});
