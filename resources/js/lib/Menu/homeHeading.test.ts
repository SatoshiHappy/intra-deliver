import { describe, expect, it } from 'vite-plus/test';
import { homeHeading, homeTitle } from '@/lib/Menu/homeHeading';

const workflow = { name: 'ワークフロー' };

describe('homeTitle', () => {
    it('アプリのホームの題はアプリの名前、全体のホームは「ホーム」', () => {
        expect(homeTitle(workflow)).toBe('ワークフロー');
        expect(homeTitle(null)).toBe('ホーム');
    });
});

describe('homeHeading(ホーム.md 6.1 の見出しの分岐 と 6.7 の表)', () => {
    it('アプリのホームは、ゲストでも利用者でもアプリの名前(あいさつの語を使わない)', () => {
        expect(homeHeading(workflow, null)).toEqual({
            kind: 'app',
            name: 'ワークフロー',
        });
        expect(
            homeHeading(workflow, { code: 'u0001', name: '佐々木 陽太' }),
        ).toEqual({ kind: 'app', name: 'ワークフロー' });
    });

    it('全体のホームのゲストは、ようこそのあいさつ', () => {
        expect(homeHeading(null, null)).toEqual({ kind: 'guest' });
    });

    it('全体のホームの利用者は氏名を添える(氏名が無ければコード)', () => {
        expect(
            homeHeading(null, { code: 'u0001', name: '佐々木 陽太' }),
        ).toEqual({
            kind: 'user',
            name: '佐々木 陽太',
        });
        expect(homeHeading(null, { code: 'u0001', name: null })).toEqual({
            kind: 'user',
            name: 'u0001',
        });
    });
});
