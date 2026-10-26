import { describe, expect, it } from 'vite-plus/test';
import {
    APPLICANT_HELP,
    applicantChoiceOf,
    applicantNoteOf,
    applicantOfValue,
    applicantValueOf,
    candidateQueryOf,
    clearRecordReferences,
    showsApplicantField,
} from './applyApplicant';
import type { RecordFormData } from '@/lib/recordForm';
import type { RecordFieldDefinition, RecordModuleDefinition } from '@/types';

const self = { id: 9, name: '吉田 達也', department: '総務部', isSelf: true };
const yamada = { id: 10, name: '山田 恵', department: '経理部', isSelf: false };
const sato = { id: 11, name: '佐藤 花子', department: null, isSelf: false };

function field(
    code: string,
    type: RecordFieldDefinition['type'],
    overrides: Partial<RecordFieldDefinition> = {},
): RecordFieldDefinition {
    return {
        code,
        name: code,
        type,
        isRequired: false,
        isMultiline: false,
        isMultiple: false,
        maxLength: null,
        defaultValue: null,
        options: [],
        referencedModule: null,
        childModule: null,
        isListed: false,
        isSearchable: false,
        isTitle: false,
        grantsRowAccess: false,
        ...overrides,
    };
}

function moduleOf(fields: RecordFieldDefinition[]): RecordModuleDefinition {
    return { code: 'm', name: 'm', description: null, fields, rules: [] };
}

describe('申請者の欄を出すか', () => {
    it('選択肢が 2 人以上のときだけ出す', () => {
        expect(showsApplicantField([])).toBe(false);
        expect(showsApplicantField([yamada])).toBe(false);
        expect(showsApplicantField([self, yamada])).toBe(true);
        expect(showsApplicantField([yamada, sato])).toBe(true);
    });

    it('「?」の Tooltip は案件の画面 6.1 の文', () => {
        expect(APPLICANT_HELP).toBe(
            '自分と、自分を申請の代理先に設定している利用者から選択します。代理元を選択すると、申請者は代理元になります。',
        );
    });
});

describe('選択肢の札と値', () => {
    it('名前・所属と、自分には「自分」、代理元には「代理元」の語が付く', () => {
        expect(applicantChoiceOf(self)).toEqual({
            name: '吉田 達也',
            department: '総務部',
            word: '自分',
        });
        expect(applicantChoiceOf(sato)).toEqual({
            name: '佐藤 花子',
            department: null,
            word: '代理元',
        });
    });

    it('選択欄の値は、自分なら自分の行の id、代理元ならその id(行が無ければ空)', () => {
        expect(applicantValueOf([self, yamada], null)).toBe('9');
        expect(applicantValueOf([self, yamada], 10)).toBe('10');
        expect(applicantValueOf([yamada], null)).toBe('');
    });

    it('選んだ値から申請者に戻す(自分は null、代理元はその id、選択肢に無い値は null)', () => {
        expect(applicantOfValue([self, yamada], '9')).toBeNull();
        expect(applicantOfValue([self, yamada], '10')).toBe(10);
        expect(applicantOfValue([self, yamada], '99')).toBeNull();
    });
});

describe('代理元の注意の囲み', () => {
    it('申請者が代理元のときだけ出て、文に代理元と自分の名前が入る', () => {
        expect(applicantNoteOf([self, yamada], 10, '吉田 達也')).toEqual({
            title: '山田 恵さんとして申請します',
            text: '申請者と担当組織は山田 恵さんになります。履歴の処理実行者には、吉田 達也(山田 恵さんの代理)と記録されます。',
        });
        expect(applicantNoteOf([self, yamada], null, '吉田 達也')).toBeNull();
    });

    it('欄を出さない代理元 1 人(自分の行が無い)でも出る', () => {
        expect(applicantNoteOf([yamada], 10, '吉田 達也')?.title).toBe(
            '山田 恵さんとして申請します',
        );
    });
});

describe('参照項目の候補のクエリ', () => {
    const scope = {
        mode: 'new' as const,
        flowCode: 'project_plan',
        caseId: null,
        recordId: null,
    };

    it('再申請は case、ほかは flow', () => {
        expect(candidateQueryOf(scope, null)).toEqual({ flow: 'project_plan' });
        expect(
            candidateQueryOf({ ...scope, mode: 'draft', caseId: 5 }, null),
        ).toEqual({ flow: 'project_plan' });
        expect(
            candidateQueryOf({ ...scope, mode: 'reapply', caseId: 5 }, null),
        ).toEqual({ case: 5 });
    });

    it('既存のデータからは record も付く', () => {
        expect(
            candidateQueryOf({ ...scope, mode: 'record', recordId: 7 }, null),
        ).toEqual({ flow: 'project_plan', record: 7 });
    });

    it('申請者が代理元ならクエリ applicant が付く', () => {
        expect(candidateQueryOf(scope, 10)).toEqual({
            flow: 'project_plan',
            applicant: 10,
        });
        expect(
            candidateQueryOf({ ...scope, mode: 'reapply', caseId: 5 }, 10),
        ).toEqual({ case: 5, applicant: 10 });
    });
});

const picked = { id: 3, label: '案件 A' };
const user = { id: 4, label: '中村 美咲', affiliation: null };
const childModule = moduleOf([
    field('item', 'string'),
    field('related', 'record'),
]);
const formModule = moduleOf([
    field('name', 'string'),
    field('target', 'record'),
    field('owner', 'user'),
    field('rows', 'details', { childModule }),
]);
const formData: RecordFormData = {
    values: { name: '計画', target: picked, owner: user },
    details: {
        rows: [
            { key: 'a', id: null, values: { item: '項目', related: picked } },
        ],
    },
};

describe('申請者を変えたときの参照の値の消し方', () => {
    it('申請対象の項目を含む他モジュール参照の値と、明細の行の参照の値を消す', () => {
        const cleared = clearRecordReferences(formModule, formData);

        expect(cleared.values.target).toBeNull();
        expect(cleared.details.rows[0].values.related).toBeNull();
    });

    it('ほかの値(文字列・ユーザーの参照・明細の行の識別子)は保つ', () => {
        const cleared = clearRecordReferences(formModule, formData);

        expect(cleared.values.name).toBe('計画');
        expect(cleared.values.owner).toEqual(user);
        expect(cleared.details.rows[0]).toMatchObject({
            key: 'a',
            values: { item: '項目' },
        });
    });

    it('元の値を書き換えない', () => {
        clearRecordReferences(formModule, formData);

        expect(formData.values.target).toEqual(picked);
    });
});
