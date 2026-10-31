import { describe, expect, it } from 'vite-plus/test';
import { isNavigatingVisit } from '@/lib/Menu/visit';

const current = new URL('https://example.test/workflow/cases?tab=a');
const base = {
    only: [],
    except: [],
    reset: [],
    preserveState: false,
    url: new URL('https://example.test/workflow/drafts'),
};

describe('isNavigatingVisit(部分の読み直し)', () => {
    it('only・except・reset が空で、別の画面へ行く訪問は移る訪問', () => {
        expect(isNavigatingVisit(base, current)).toBe(true);
    });

    it.each([
        ['only', { only: ['sections'] }],
        ['except', { except: ['account'] }],
        ['reset', { reset: ['rows'] }],
    ])('%s が空でない訪問は部分の読み直しなので移らない', (_name, override) => {
        expect(isNavigatingVisit({ ...base, ...override }, current)).toBe(
            false,
        );
    });
});

describe('isNavigatingVisit(同じ画面に留まる訪問)', () => {
    it('同じ URL で preserveState が真の訪問(オプションなしの router.reload())は移らない', () => {
        const reload = { ...base, preserveState: true, url: current };

        expect(isNavigatingVisit(reload, current)).toBe(false);
    });

    it('ハッシュだけ違う同じ URL でも、preserveState が真なら移らない', () => {
        const url = new URL('https://example.test/workflow/cases?tab=a#top');

        expect(
            isNavigatingVisit({ ...base, preserveState: true, url }, current),
        ).toBe(false);
    });

    it('今の画面のリンクを押す訪問(同じ URL・preserveState が偽)は移る訪問で、閉じる', () => {
        expect(isNavigatingVisit({ ...base, url: current }, current)).toBe(
            true,
        );
    });

    it('preserveState が真でも、クエリが違えば別の画面なので移る', () => {
        const url = new URL('https://example.test/workflow/cases?tab=b');

        expect(
            isNavigatingVisit({ ...base, preserveState: true, url }, current),
        ).toBe(true);
    });

    it('preserveState が関数のときは決まらないので、移る訪問として扱う', () => {
        const visit = { ...base, preserveState: () => true, url: current };

        expect(isNavigatingVisit(visit, current)).toBe(true);
    });
});
