import { describe, expect, it } from 'vite-plus/test';
import {
    attachmentSurfaceOf,
    filesToSendOf,
    removalsOf,
    removeIdsPayloadOf,
    toggledMarksOf,
} from './applyAttachment';

const mine = { id: 1, name: 'a.pdf', size: 10, removable: true };
const mineToo = { id: 2, name: 'b.pdf', size: 20, removable: true };
const others = { id: 3, name: 'c.pdf', size: 30, removable: false };

describe('面の出し方(設計/ワークフロー/申請の周辺/画面.md の 2 節の 3 つの場合)', () => {
    it('使えるなら、一時保存の添付の有無によらず中身をすべて出す', () => {
        expect(attachmentSurfaceOf({ enabled: true, drafts: [] })).toEqual({
            kind: 'full',
        });
        expect(
            attachmentSurfaceOf({ enabled: true, drafts: [others] }),
        ).toEqual({
            kind: 'full',
        });
    });

    it('使えなくて一時保存の添付が残るなら、行と 1 行だけ(外せない行が無い文)', () => {
        expect(
            attachmentSurfaceOf({ enabled: false, drafts: [mine, mineToo] }),
        ).toEqual({
            kind: 'drafts',
            line: 'この申請では添付ファイルを使用できません。申請する前に削除してください。',
        });
    });

    it('使えなくて、外せない行(ほかの利用者が追加した行)があるなら、もう 1 つの文', () => {
        expect(
            attachmentSurfaceOf({ enabled: false, drafts: [mine, others] }),
        ).toEqual({
            kind: 'drafts',
            line: 'この申請では添付ファイルを使用できません。ほかの利用者が追加した添付ファイルがあるため、一時保存を削除して作り直してください。',
        });
    });

    it('使えなくて一時保存の添付も無いなら、面を出さない', () => {
        expect(attachmentSurfaceOf({ enabled: false, drafts: [] })).toEqual({
            kind: 'none',
        });
    });
});

describe('見せる・送るファイル', () => {
    const files = [new File(['x'], 'a.pdf')];

    it('使えるなら選んだファイル、使えないなら空', () => {
        expect(filesToSendOf({ enabled: true }, files)).toBe(files);
        expect(filesToSendOf({ enabled: false }, files)).toEqual([]);
    });
});

describe('外す印', () => {
    const drafts = [mine, others, mineToo];

    it('付ける・元に戻す', () => {
        const marked = toggledMarksOf([], drafts, 1);

        expect(marked).toEqual([1]);
        expect(toggledMarksOf(marked, drafts, 2)).toEqual([1, 2]);
        expect(toggledMarksOf([1, 2], drafts, 1)).toEqual([2]);
        expect(toggledMarksOf([1], drafts, 1)).toEqual([]);
    });

    it('外せない行と今ない行には印を付けられない', () => {
        expect(toggledMarksOf([], drafts, 3)).toEqual([]);
        expect(toggledMarksOf([1], drafts, 99)).toEqual([1]);
    });

    it('送る印は行の並びの順で、外せる今ある行だけ', () => {
        expect(removalsOf([2, 1, 3, 99], drafts)).toEqual([1, 2]);
    });

    it('読み直しで一時保存の添付から消えた行の印だけ落ちる', () => {
        expect(removalsOf([1, 2], [mineToo, others])).toEqual([2]);
    });

    it('remove_attachment_ids は一時保存の画面だけが送り、印が無くても空の配列で送る', () => {
        expect(removeIdsPayloadOf('draft', [2])).toEqual({
            remove_attachment_ids: [2],
        });
        expect(removeIdsPayloadOf('draft', [])).toEqual({
            remove_attachment_ids: [],
        });

        for (const mode of ['new', 'reapply', 'record'] as const) {
            expect(removeIdsPayloadOf(mode, [1])).toEqual({});
        }
    });
});
