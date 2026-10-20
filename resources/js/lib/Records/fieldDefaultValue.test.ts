import { describe, expect, it } from 'vite-plus/test';
import { recordField } from '@/lib/recordField.testing';
import type { RecordAdminField, RecordFieldType } from '@/types';
import {
    defaultValueField,
    defaultValuePayload,
    emptyDefaultFields,
    initialDefaultFields,
    maxLengthHint,
} from './fieldDefaultValue';
import type { DefaultValueFormData } from './fieldDefaultValue';

const stringMaxLengths = {
    singleLine: 1000,
    multiline: 4000,
    multilineMax: 20000,
};

function formData(
    type: RecordFieldType,
    overrides: Partial<DefaultValueFormData> = {},
): DefaultValueFormData {
    return {
        type,
        isMultiline: false,
        maxLength: '',
        options: [],
        ...emptyDefaultFields(type),
        ...overrides,
    };
}

function adminField(
    type: RecordFieldType,
    defaultValue: RecordAdminField['defaultValue'],
    relativeDefault: string | null,
): RecordAdminField {
    return {
        ...recordField('f', type, { name: '項目', defaultValue }),
        relativeDefault,
        maxLengthAttribute: null,
    };
}

describe('defaultValuePayload', () => {
    it('日付・日時の「指定しない」は null を送る(固定の値の欄が残っていても)', () => {
        for (const type of ['date', 'datetime'] as const) {
            expect(
                defaultValuePayload(
                    formData(type, { defaultValue: '2026-10-11' }),
                ),
            ).toEqual({ default_value: null });
        }
    });

    it('「固定の値」は欄の値を送る(日時は :00 と Asia/Tokyo の時差を付ける)', () => {
        expect(
            defaultValuePayload(
                formData('date', {
                    defaultMode: 'fixed',
                    defaultValue: '2026-10-11',
                }),
            ),
        ).toEqual({ default_value: '2026-10-11' });
        expect(
            defaultValuePayload(
                formData('datetime', {
                    defaultMode: 'fixed',
                    defaultValue: '2026-10-11T09:30',
                }),
            ),
        ).toEqual({ default_value: '2026-10-11T09:30:00+09:00' });
    });

    it('日付・日時でない型は持ち方に依らず欄の値を送る', () => {
        expect(
            defaultValuePayload(formData('integer', { defaultValue: '10' })),
        ).toEqual({ default_value: '10' });
        expect(defaultValuePayload(formData('user'))).toEqual({});
    });
});

describe('defaultValuePayload(登録の時から)', () => {
    it('「登録の時から」は期間のオブジェクトを送り、数の欄の誤りは止めずに送る', () => {
        const relative = (
            type: RecordFieldType,
            amount: string,
            unit: DefaultValueFormData['relativeUnit'],
        ) =>
            defaultValuePayload(
                formData(type, {
                    defaultMode: 'relative',
                    relativeAmount: amount,
                    relativeUnit: unit,
                }),
            );

        expect(relative('date', '6', 'M')).toEqual({
            default_value: { relative: 'P6M' },
        });
        expect(relative('datetime', ' 0 ', 'D')).toEqual({
            default_value: { relative: 'P0D' },
        });
        expect(relative('date', '1.5', 'Y')).toEqual({
            default_value: { relative: 'P1.5Y' },
        });
        expect(relative('date', '', 'D')).toEqual({
            default_value: { relative: 'PD' },
        });
    });
});

describe('initialDefaultFields', () => {
    it('相対の既定値は期間から「登録の時から」と数・単位を作る', () => {
        expect(initialDefaultFields(adminField('date', null, 'P6M'))).toEqual({
            defaultValue: '',
            defaultMode: 'relative',
            relativeAmount: '6',
            relativeUnit: 'M',
        });
        expect(
            initialDefaultFields(adminField('datetime', null, 'P0D'))
                .relativeAmount,
        ).toBe('0');
    });

    it('固定の値は「固定の値」、既定値が無ければ「指定しない」', () => {
        expect(
            initialDefaultFields(adminField('date', '2026-12-01', null)),
        ).toMatchObject({ defaultMode: 'fixed', defaultValue: '2026-12-01' });
        expect(
            initialDefaultFields(adminField('date', null, null)).defaultMode,
        ).toBe('none');
    });
});

describe('maxLengthHint', () => {
    it('複数行は既定の上限と指定できる上限、1 行は既定の上限', () => {
        expect(maxLengthHint(true, stringMaxLengths)).toBe(
            '空なら 4000 文字。20000 文字まで指定できます',
        );
        expect(maxLengthHint(false, stringMaxLengths)).toBe('空なら 1000 文字');
    });
});

describe('defaultValueField', () => {
    const maxLengthOf = (isMultiline: boolean, maxLength: string) =>
        defaultValueField(
            formData('string', { isMultiline, maxLength }),
            stringMaxLengths,
        )?.maxLength;

    it('既定値の欄の上限は指定できる範囲の最大の文字数、範囲の外と空は既定の上限', () => {
        expect(maxLengthOf(true, '20000')).toBe(20000);
        expect(maxLengthOf(true, '20001')).toBe(4000);
        expect(maxLengthOf(true, '')).toBe(4000);
        expect(maxLengthOf(false, '1000')).toBe(1000);
        expect(maxLengthOf(false, '1001')).toBe(1000);
        expect(maxLengthOf(false, '0')).toBe(1000);
    });

    it('選択肢は同じコードの先の行だけを使い、空のコードを除き、名前が空ならコードを出す', () => {
        const data = formData('choice', {
            options: [
                { code: 'open', name: '受付' },
                { code: '', name: '未入力' },
                { code: 'open', name: '重複' },
                { code: 'done', name: '' },
            ],
        });

        expect(defaultValueField(data, stringMaxLengths)?.options).toEqual([
            { code: 'open', name: '受付' },
            { code: 'done', name: 'done' },
        ]);
    });
});
