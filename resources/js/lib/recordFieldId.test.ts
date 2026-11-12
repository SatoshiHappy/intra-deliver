import { describe, expect, it } from 'vite-plus/test';
import { recordFieldId } from '@/lib/recordFieldId';

describe('recordFieldId', () => {
    it('項目のコードの前に record-field- を付ける', () => {
        expect(recordFieldId('title')).toBe('record-field-title');
        expect(recordFieldId('lines')).toBe('record-field-lines');
    });
});
