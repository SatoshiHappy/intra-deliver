import { describe, expect, it } from 'vite-plus/test';
import {
    CURRENT_VALUE_CLASS,
    NEXT_VALUE_CLASS,
} from './applicationTargetConfirmMarks';

// 写す前の確かめのダイアログの見分け(色だけに頼らない = 取り消し線と面)。

describe('今の値と反映する値の見分け', () => {
    it('今の値は薄い字と取り消し線、反映する値は主の色の薄い面で太字にしない', () => {
        const current = CURRENT_VALUE_CLASS.split(' ');
        const next = NEXT_VALUE_CLASS.split(' ');

        expect(current).toEqual(
            expect.arrayContaining(['text-muted-foreground', 'line-through']),
        );
        expect(next).toContain('bg-secondary');
        expect(next.filter((name) => name.startsWith('font-'))).toEqual([]);
        expect(next).not.toContain('line-through');
    });
});
