import { describe, expect, it } from 'vite-plus/test';
import { clearedReasonId, invalidProps } from '@/components/FormBlock';
import { detailClearedReasonId } from '@/components/RecordDetailCell';
import { chipDescribedByOf } from '@/components/RecordMultipleReferencePicker';
import { previewPanelPropsOf } from '@/components/RichTextInput';

// 入力の説明の id の並び(空にした理由の 1 行を、誤りの文と同じ作法で入力の aria-describedby に結ぶ = docs の
// 設計/ワークフロー/申請の周辺/画面.md の 5 節の「空にした項目の知らせ」)。

describe('invalidProps の説明の並び', () => {
    it('誤りが無くても、空にした理由の 1 行を説明に入れる(aria-invalid は付けない)', () => {
        expect(
            invalidProps('f', undefined, false, false, [clearedReasonId('f')]),
        ).toEqual({ 'aria-describedby': 'f-cleared' });
    });

    it('並びは 誤り → 空にした理由 → 説明 → 文字数', () => {
        expect(
            invalidProps('f', 'x', true, true, [clearedReasonId('f')]),
        ).toEqual({
            'aria-invalid': true,
            'aria-describedby': 'f-error f-cleared f-help f-count',
        });
    });

    it('どれも無ければ空', () => {
        expect(invalidProps('f', undefined)).toEqual({});
    });
});

describe('明細の理由の 1 行の id', () => {
    it('明細の項目のコードと理由の組ごと(同じ理由のセルは同じ 1 行を指す)', () => {
        expect(detailClearedReasonId('lines', 'copy')).toBe(
            'record-field-lines-cleared-copy',
        );
        expect(detailClearedReasonId('lines', 'applicationTarget')).not.toBe(
            detailClearedReasonId('lines', 'copy'),
        );
    });
});

describe('プレビューの面とチップの外すボタンの説明', () => {
    it('プレビューの面は、誤りが無くても空にした理由を持ち(文字数と並べる)、誤りがあれば誤りの文を先に並べる', () => {
        const cleared = [clearedReasonId('f')];

        expect(previewPanelPropsOf('f', false, 100, cleared)).toEqual({
            'aria-describedby': 'f-cleared f-count',
        });
        expect(previewPanelPropsOf('f', true, null, cleared)).toEqual({
            'aria-invalid': true,
            'aria-describedby': 'f-error f-cleared',
        });
    });

    it('誤りのチップは欄の説明の並び、誤りの無いチップは空にした理由だけ(誤りの文を読ませない)', () => {
        expect(chipDescribedByOf(true, 'f-error f-cleared', 'f-cleared')).toBe(
            'f-error f-cleared',
        );
        expect(chipDescribedByOf(false, 'f-error f-cleared', 'f-cleared')).toBe(
            'f-cleared',
        );
        expect(chipDescribedByOf(false, 'f-error', undefined)).toBeUndefined();
    });
});
