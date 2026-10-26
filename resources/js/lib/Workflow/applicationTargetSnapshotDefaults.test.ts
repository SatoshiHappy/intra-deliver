import { describe, expect, it } from 'vite-plus/test';
import { createFormData } from '@/lib/recordForm';
import {
    needsConfirm,
    applicationTargetChangesOf,
} from './applicationTargetSnapshot';
import {
    APPLICATION_TARGET_FIELD,
    snapshotOf,
    planModuleWithDefaults,
} from './applicationTarget.testing';

const module = planModuleWithDefaults({
    status: 'planning',
    flag: true,
    budget: 10,
});
const fresh = createFormData(module);

/** 写しの値を当てるときに確かめるか(今の値が入力済みの項目があるか) */
function confirms(
    values: Record<
        string,
        string | number | boolean | { code: string; name: string | null }
    >,
    current = fresh,
) {
    return needsConfirm(
        applicationTargetChangesOf(
            module,
            APPLICATION_TARGET_FIELD,
            current,
            snapshotOf(values),
        ),
    );
}

describe('入力済みの数え方(既定値は入力済みに数えない)', () => {
    it('何も入れていない新規の画面は、既定値と違う値の写しでも確かめない', () => {
        expect(
            confirms({
                status: { code: 'active', name: '進行中' },
                flag: false,
                budget: 1200,
            }),
        ).toBe(false);
    });

    it('既定値と同じ値を自分で選び直した欄は、入力済みに数えない', () => {
        const current = {
            ...fresh,
            values: { ...fresh.values, status: 'planning', budget: '10' },
        };

        expect(
            confirms(
                { status: { code: 'active', name: null }, budget: 99 },
                current,
            ),
        ).toBe(false);
    });

    it('既定値と違う値を入れた欄は入力済みで、写す値と違えば確かめる(同じなら確かめない)', () => {
        const current = {
            ...fresh,
            values: { ...fresh.values, status: 'active', budget: '5' },
        };

        expect(
            confirms({ status: { code: 'planning', name: null } }, current),
        ).toBe(true);
        expect(confirms({ budget: 99 }, current)).toBe(true);
        expect(
            confirms(
                { status: { code: 'active', name: null }, budget: 5 },
                current,
            ),
        ).toBe(false);
    });
});

describe('入力済みの数え方(真偽と既定値の無い項目)', () => {
    it('真偽は既定値と違えば入力済み(既定が真なら偽が、既定が偽なら真が)', () => {
        const off = { ...fresh, values: { ...fresh.values, flag: false } };

        expect(confirms({ flag: false })).toBe(false);
        expect(confirms({ flag: true }, off)).toBe(true);
    });

    it('既定値の無い項目は今のまま(空でなければ入力済み)', () => {
        const current = {
            ...fresh,
            values: { ...fresh.values, name: '手で入れた名前' },
        };

        expect(confirms({ name: '新名' })).toBe(false);
        expect(confirms({ name: '新名' }, current)).toBe(true);
    });
});
