import { describe, expect, it } from 'vite-plus/test';
import {
    exceedsLimits,
    onPayloadTooLarge,
    sendShapeOf,
} from './attachmentSend';

function file(name: string): File {
    return new File(['x'], name);
}

describe('送る形', () => {
    const input = {
        name: '案件',
        values: { budget: 100, flag: false, empty: null, none: [] },
        details: { rows: [], keep: [{}, { done: true }] },
    };

    it('ファイルが 0 個なら入力のまま(JSON)', () => {
        expect(sendShapeOf(input, [])).toBe(input);
    });

    it('1 個以上なら封筒と attachments になり、封筒を戻すと入力と同じ(数・真偽・null・空の配列・値の無い行・キーの省略が保たれる)', () => {
        const files = [file('b.pdf'), file('a.png')];
        const sent = sendShapeOf(input, files) as {
            envelope: string;
            attachments: File[];
        };

        expect(Object.keys(sent).sort()).toEqual(['attachments', 'envelope']);
        expect(JSON.parse(sent.envelope)).toStrictEqual(input);
        expect(sent.attachments.map((item) => item.name)).toEqual([
            'b.pdf',
            'a.png',
        ]);
    });

    it('キーの省略は封筒でも省略のまま(undefined のキーは載らない)', () => {
        const sent = sendShapeOf({ name: 'a', comment: undefined }, [
            file('a.pdf'),
        ]) as { envelope: string };

        expect(Object.keys(JSON.parse(sent.envelope))).toEqual(['name']);
    });
});

describe('送る前の確かめ', () => {
    const limits = { maxFiles: 3, maxFileBytes: 1000, maxRequestBytes: 2500 };
    const sized = (...sizes: number[]) => sizes.map((size) => ({ size }));

    it('ファイルが 0 個なら確かめない', () => {
        expect(exceedsLimits([], limits)).toBe(false);
    });

    it('件数: 上限ちょうどは通り、1 個多いと止まる', () => {
        expect(exceedsLimits(sized(1, 1, 1), limits)).toBe(false);
        expect(exceedsLimits(sized(1, 1, 1, 1), limits)).toBe(true);
    });

    it('1 ファイル: 上限ちょうどは通り、1 バイト多いと止まる', () => {
        expect(exceedsLimits(sized(1000), limits)).toBe(false);
        expect(exceedsLimits(sized(1001), limits)).toBe(true);
        expect(exceedsLimits(sized(10, 1001), limits)).toBe(true);
    });

    it('1 回の合計: 上限ちょうどは通り、1 バイト多いと止まる', () => {
        expect(exceedsLimits(sized(1000, 1000, 500), limits)).toBe(false);
        expect(exceedsLimits(sized(1000, 1000, 501), limits)).toBe(true);
    });
});

describe('413 の受け方', () => {
    it('状態 413 ならトーストの文を 1 回出し、既定の表示を止める(false)', () => {
        const shown: string[] = [];
        const handle = onPayloadTooLarge(
            (message) => shown.push(message),
            '大きすぎます',
        );

        expect(handle({ status: 413 })).toBe(false);
        expect(shown).toEqual(['大きすぎます']);
    });

    it('ほかの状態は何も出さず、既定の扱いに任せる(false を返さない)', () => {
        const shown: string[] = [];
        const handle = onPayloadTooLarge(
            (message) => shown.push(message),
            '大きすぎます',
        );

        for (const status of [400, 403, 404, 419, 422, 500]) {
            expect(handle({ status })).toBeUndefined();
        }
        expect(shown).toEqual([]);
    });
});
