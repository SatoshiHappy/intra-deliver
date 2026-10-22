import { describe, expect, it } from 'vite-plus/test';
import { regeneratePayload } from './regenerateMinutesForm';

describe('regeneratePayload', () => {
    it('書き起こしがあれば追加の指示を送る(空でも送る)', () => {
        expect(regeneratePayload(true, '決定事項を先に')).toEqual({
            instructions: '決定事項を先に',
        });
        expect(regeneratePayload(true, '')).toEqual({ instructions: '' });
    });

    it('書き起こしが無ければ追加の指示を送らない(音声から作り直す)', () => {
        expect(regeneratePayload(false, '残った入力')).toEqual({});
    });
});
