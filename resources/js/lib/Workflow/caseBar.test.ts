import { describe, expect, it } from 'vite-plus/test';
import { caseBarOf } from './caseBar';
import { panel } from './caseOperation.testing';

const OPERATION = panel();

describe('caseBarOf(帯の出し方の 4 行)', () => {
    it.each([
        { hasProcess: false, hasOperation: false, expected: null },
        {
            hasProcess: false,
            hasOperation: true,
            expected: { operate: OPERATION, process: false },
        },
        {
            hasProcess: true,
            hasOperation: false,
            expected: { operate: null, process: true },
        },
        {
            hasProcess: true,
            hasOperation: true,
            expected: { operate: OPERATION, process: true },
        },
    ])(
        '処理のボタン $hasProcess・申請操作 $hasOperation',
        ({ hasProcess, hasOperation, expected }) => {
            expect(
                caseBarOf(hasProcess, hasOperation ? OPERATION : null),
            ).toEqual(expected);
        },
    );
});
