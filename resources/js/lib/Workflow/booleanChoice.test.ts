import { describe, expect, it } from 'vite-plus/test';
import {
    ATTACHMENT_ADD_CHOICES,
    ATTACHMENT_USE_CHOICES,
    choiceKeyOf,
    choiceValueOf,
} from './booleanChoice';

describe('選択肢の並びと語', () => {
    it('面「添付ファイル」は「使用する」「使用しない」の順で、使用するが真', () => {
        expect(ATTACHMENT_USE_CHOICES).toEqual([
            { value: true, label: '使用する' },
            { value: false, label: '使用しない' },
        ]);
    });

    it('カード「添付ファイル」は「追加できる」「追加できない」の順で、追加できないが真', () => {
        expect(ATTACHMENT_ADD_CHOICES).toEqual([
            { value: false, label: '追加できる' },
            { value: true, label: '追加できない' },
        ]);
    });
});

describe('ラジオの値と真偽の行き来', () => {
    it('真偽は文字列の値になり、選択肢の中の値だけが真偽に戻る', () => {
        expect(choiceKeyOf(true)).toBe('true');
        expect(choiceKeyOf(false)).toBe('false');
        expect(choiceValueOf('true', ATTACHMENT_USE_CHOICES)).toBe(true);
        expect(choiceValueOf('false', ATTACHMENT_ADD_CHOICES)).toBe(false);
        expect(choiceValueOf('', ATTACHMENT_USE_CHOICES)).toBeNull();
        expect(choiceValueOf('yes', ATTACHMENT_USE_CHOICES)).toBeNull();
    });
});
