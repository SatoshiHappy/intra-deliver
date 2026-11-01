import { describe, expect, it } from 'vite-plus/test';
import { flowSettingsFormOf } from './flowSettingsForm';

describe('flowSettingsFormOf', () => {
    it('サーバーの値がそのまま入力と送る値になる(キャンセルも同じ関数でサーバーの値へ戻す)', () => {
        expect(flowSettingsFormOf(true)).toEqual({
            is_attachment_enabled: true,
        });
        expect(flowSettingsFormOf(false)).toEqual({
            is_attachment_enabled: false,
        });
    });
});
