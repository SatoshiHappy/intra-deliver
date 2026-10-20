import { describe, expect, it, vi } from 'vite-plus/test';
import type { RecordFieldDefinition, RecordModuleDefinition } from '@/types';
import { recordField } from './recordField.testing';
import type { RecordFormData } from './recordForm';
import {
    fieldErrorOf,
    isRecordFieldKey,
    referenceItemErrors,
    shownRecordKeys,
} from './recordFormErrors';

function moduleOf(
    code: string,
    fields: RecordFieldDefinition[],
): RecordModuleDefinition {
    return { code, name: code, description: null, fields, rules: [] };
}

const members = recordField('members', 'user', { isMultiple: true });

const lineModule = moduleOf('line', [
    recordField('item', 'string'),
    recordField('amount', 'integer'),
]);

const issueModule = moduleOf('issue', [
    recordField('title', 'string'),
    recordField('lines', 'details', { childModule: lineModule }),
    recordField('orphan', 'details'),
]);

const meetingModule = moduleOf('meeting', [
    members,
    recordField('lines', 'details', {
        childModule: moduleOf('line', [members]),
    }),
]);

describe('shownRecordKeys', () => {
    it('通常の項目と、描く明細の行の数ぶんの id と列のキーを出す', () => {
        const data: RecordFormData = {
            values: {},
            details: {
                lines: [
                    { key: 'a', id: 1, values: {} },
                    { key: 'b', id: null, values: {} },
                ],
                orphan: [{ key: 'c', id: 2, values: {} }],
            },
        };

        expect(shownRecordKeys(issueModule, data)).toEqual([
            'values.title',
            'details.lines',
            'details.lines.0.id',
            'details.lines.0.values.item',
            'details.lines.0.values.amount',
            'details.lines.1.id',
            'details.lines.1.values.item',
            'details.lines.1.values.amount',
        ]);
    });

    it('複数の参照の欄は、選んだ相手の数ぶんの添字のキーも出す(明細の行の欄も同じ)', () => {
        const picked = [
            { id: 1, label: 'a' },
            { id: 2, label: 'b' },
        ];

        expect(
            shownRecordKeys(meetingModule, {
                values: { members: picked },
                details: {
                    lines: [{ key: 'a', id: null, values: { members: null } }],
                },
            }),
        ).toEqual([
            'values.members',
            'values.members.0',
            'values.members.1',
            'details.lines',
            'details.lines.0.id',
            'details.lines.0.values.members',
        ]);
    });
});

describe('isRecordFieldKey', () => {
    it('values・details そのものは偽、その下のキーは真', () => {
        expect(isRecordFieldKey('values')).toBe(false);
        expect(isRecordFieldKey('details')).toBe(false);
        expect(isRecordFieldKey('values.title')).toBe(true);
        expect(isRecordFieldKey('details.lines.0.id')).toBe(true);
        expect(isRecordFieldKey('record')).toBe(false);
    });
});

describe('fieldErrorOf', () => {
    it('欄のキーの誤りを先に、無ければ最も小さい添字の誤りを出す', () => {
        expect(
            fieldErrorOf(
                {
                    'values.members': '重複',
                    'values.members.1': '不正',
                },
                'values.members',
            ),
        ).toBe('重複');
        expect(
            fieldErrorOf(
                {
                    'values.members.3': '3 番目',
                    'values.members.1': '1 番目',
                    'values.members_x.0': 'ほかの欄',
                },
                'values.members',
            ),
        ).toBe('1 番目');
        expect(fieldErrorOf({}, 'values.members')).toBeUndefined();
    });
});

describe('referenceItemErrors', () => {
    it('誤りのある添字を昇順で返し、チップを外すとその欄の添字のキーの頭で消す', () => {
        const clear = vi.fn();
        const errors = referenceItemErrors(
            {
                'values.members.2': '不正',
                'values.members.0': '不正',
                'values.members': '重複',
                'values.members.extra': 'ほかのキー',
            },
            'values.members',
            clear,
        );

        expect(errors.invalidIndexes).toEqual([0, 2]);

        errors.onRemoved();

        expect(clear).toHaveBeenCalledWith('values.members.');
    });
});
