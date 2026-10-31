import { describe, expect, it } from 'vite-plus/test';
import { fieldBadges } from './recordFieldBadges';

describe('fieldBadges', () => {
    it('必須と関係者の印の組み合わせで「必須」→「関係者」の順に出す', () => {
        expect([
            fieldBadges({ grantsRowAccess: false }, false),
            fieldBadges({ grantsRowAccess: false }, true),
            fieldBadges({ grantsRowAccess: true }, false),
            fieldBadges({ grantsRowAccess: true }, true),
        ]).toEqual([[], ['必須'], ['関係者'], ['必須', '関係者']]);
    });
});
