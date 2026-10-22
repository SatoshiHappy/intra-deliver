import { describe, expect, it } from 'vite-plus/test';
import { regeneratePayload, regenerateView } from './regenerateMinutesForm';

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

describe('regenerateView', () => {
    const blocked = '今日の AI の利用の上限に近いため、音声は選べません。';

    it.each([
        {
            case: '書き起こしがあり、止めていない',
            hasTranscript: true,
            message: null,
            expected: {
                submitDisabled: false,
                shownErrorKeys: ['instructions'],
            },
        },
        {
            case: '書き起こしがあり、止めている',
            hasTranscript: true,
            message: blocked,
            expected: {
                submitDisabled: true,
                shownErrorKeys: ['instructions'],
            },
        },
        {
            case: '書き起こしが無く、止めていない',
            hasTranscript: false,
            message: null,
            expected: { submitDisabled: false, shownErrorKeys: [] },
        },
        {
            case: '書き起こしが無く、止めている',
            hasTranscript: false,
            message: blocked,
            expected: { submitDisabled: true, shownErrorKeys: [] },
        },
    ])('$case', ({ hasTranscript, message, expected }) => {
        expect(regenerateView(hasTranscript, message)).toEqual(expected);
    });
});
