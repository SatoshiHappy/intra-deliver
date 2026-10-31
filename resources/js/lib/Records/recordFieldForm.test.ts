import { describe, expect, it } from 'vite-plus/test';
import type { RecordAdminField, RecordFieldType } from '@/types';
import { recordField } from '@/lib/recordField.testing';
import { FIELD_TYPE_CHOICES, typeChoiceOf } from './recordDefinitions';
import {
    initialFieldData,
    toFieldPayload,
    withTypeChoice,
} from './recordFieldForm';
import type { FieldFormData } from './recordFieldForm';

function formData(
    type: RecordFieldType,
    defaultValue: FieldFormData['defaultValue'],
): FieldFormData {
    return {
        ...initialFieldData(null),
        code: 'f',
        name: '項目',
        type,
        defaultValue,
        defaultMode: 'fixed',
    };
}

function adminField(
    type: RecordFieldType,
    defaultValue: RecordAdminField['defaultValue'],
): RecordAdminField {
    return {
        ...recordField('f', type, { name: '項目', defaultValue }),
        relativeDefault: null,
        maxLengthAttribute: null,
    };
}

describe('toFieldPayload', () => {
    it('整数・小数の既定値は欄の文字列のまま送る', () => {
        expect(
            toFieldPayload(formData('integer', '10'), true).default_value,
        ).toBe('10');
        expect(
            toFieldPayload(formData('decimal', '1.5'), true).default_value,
        ).toBe('1.5');
    });

    it('日時の既定値に :00 と Asia/Tokyo の時差を付ける', () => {
        expect(
            toFieldPayload(formData('datetime', '2026-10-11T09:30'), true)
                .default_value,
        ).toBe('2026-10-11T09:30:00+09:00');
    });

    it('空の既定値は null で送る', () => {
        expect(
            toFieldPayload(formData('datetime', ''), true).default_value,
        ).toBeNull();
    });

    it('既定値を持てない型は既定値を送らない', () => {
        expect(toFieldPayload(formData('user', null), true)).not.toHaveProperty(
            'default_value',
        );
    });
});

describe('toFieldPayload(1 つか複数か)', () => {
    const chosen = (value: string) => {
        const choice = FIELD_TYPE_CHOICES.find((item) => item.value === value);

        if (choice === undefined) {
            throw new Error(`型の選択肢 ${value} が無い`);
        }

        return withTypeChoice({ ...initialFieldData(null), code: 'f' }, choice);
    };

    it('「ユーザー(複数)」は型 user と is_multiple: true を送る', () => {
        expect(toFieldPayload(chosen('user_multiple'), true)).toMatchObject({
            type: 'user',
            is_multiple: true,
        });
    });

    it('「組織」は is_multiple: false を送る', () => {
        expect(toFieldPayload(chosen('department'), true)).toMatchObject({
            type: 'department',
            is_multiple: false,
        });
    });

    it('欄の値から、選んだ型の選択肢を引ける', () => {
        expect(
            typeChoiceOf(chosen('user_multiple'), FIELD_TYPE_CHOICES)?.value,
        ).toBe('user_multiple');
        expect(typeChoiceOf(chosen('user'), FIELD_TYPE_CHOICES)?.value).toBe(
            'user',
        );
    });

    it('is_multiple は追加のときのユーザー・組織だけ送る', () => {
        expect(
            toFieldPayload(chosen('user_multiple'), false),
        ).not.toHaveProperty('is_multiple');
        expect(toFieldPayload(chosen('string'), true)).not.toHaveProperty(
            'is_multiple',
        );
    });
});

describe('toFieldPayload(関係者)', () => {
    const flagged = (type: RecordFieldType): FieldFormData => ({
        ...formData(type, null),
        grantsRowAccess: true,
    });

    it('ユーザー・組織(1 つと複数)は追加でも編集でも grants_row_access を送る', () => {
        expect(toFieldPayload(flagged('user'), true)).toMatchObject({
            grants_row_access: true,
        });
        expect(
            toFieldPayload(
                { ...flagged('department'), isMultiple: true },
                false,
            ),
        ).toMatchObject({ grants_row_access: true });
        expect(
            toFieldPayload(
                { ...flagged('user'), grantsRowAccess: false },
                false,
            ),
        ).toMatchObject({ grants_row_access: false });
    });

    it('ほかの型は欄が真でも送らない', () => {
        for (const type of ['string', 'record', 'details'] as const) {
            expect(toFieldPayload(flagged(type), true)).not.toHaveProperty(
                'grants_row_access',
            );
        }
    });
});

describe('initialFieldData', () => {
    it('追加は関係者の印がオフ、編集は今の旗を持つ', () => {
        expect(initialFieldData(null).grantsRowAccess).toBe(false);
        expect(
            initialFieldData({
                ...adminField('department', null),
                grantsRowAccess: true,
            }).grantsRowAccess,
        ).toBe(true);
    });

    it('編集は今の定義の 1 つか複数かを持つ', () => {
        expect(
            initialFieldData({
                ...adminField('user', null),
                isMultiple: true,
            }).isMultiple,
        ).toBe(true);
    });

    it('日時の既定値を Asia/Tokyo の欄の形にする', () => {
        expect(
            initialFieldData(adminField('datetime', '2026-10-11T00:30:00Z'))
                .defaultValue,
        ).toBe('2026-10-11T09:30');
    });

    it('既定値が無ければ空の欄(真偽は偽)', () => {
        expect(initialFieldData(adminField('integer', null)).defaultValue).toBe(
            '',
        );
        expect(initialFieldData(adminField('boolean', null)).defaultValue).toBe(
            false,
        );
    });
});
