import { valueFields } from '@/lib/recordForm';
import type { RecordFieldDraft, RecordFormData } from '@/lib/recordForm';
import type {
    RecordFieldDefinition,
    RecordModuleDefinition,
    WorkflowApplicant,
    WorkflowApplyProps,
} from '@/types';

/**
 * 申請の画面の申請者(docs の 設計/ワークフロー/案件の運用/画面/案件の画面.md の 6.1 節)。選んだ申請者 = 代理元の id(自分なら null)。
 */

/** 申請者の欄の「?」の Tooltip */
export const APPLICANT_HELP =
    '自分と、自分を申請の代理先に設定している利用者から選択します。代理元を選択すると、申請者は代理元になります。';

/**
 * 申請者の欄を出すか(選択肢が 2 人以上のときだけ。1 人なら欄を出さず、代理元なら注意の囲みだけを出す)。
 */
export function showsApplicantField(applicants: WorkflowApplicant[]): boolean {
    return applicants.length >= 2;
}

/**
 * 選択肢の札(名前・所属・「自分」「代理元」の語)。
 */
export function applicantChoiceOf(choice: WorkflowApplicant): {
    name: string;
    department: string | null;
    word: '自分' | '代理元';
} {
    return {
        name: choice.name,
        department: choice.department,
        word: choice.isSelf ? '自分' : '代理元',
    };
}

/**
 * 選択欄の値(選んだ申請者の id の文字列。自分を選んでいる = 自分の行の id)。行が無ければ空。
 */
export function applicantValueOf(
    applicants: WorkflowApplicant[],
    applicant: number | null,
): string {
    const chosen =
        applicant === null
            ? applicants.find((choice) => choice.isSelf)
            : applicants.find((choice) => choice.id === applicant);

    return chosen === undefined ? '' : String(chosen.id);
}

/**
 * 選択欄の値から選んだ申請者(自分なら null。選択肢に無ければ null)。
 */
export function applicantOfValue(
    applicants: WorkflowApplicant[],
    value: string,
): number | null {
    const chosen = applicants.find((choice) => String(choice.id) === value);

    return chosen === undefined || chosen.isSelf ? null : chosen.id;
}

/**
 * 代理元の注意の囲み(見出しと文。申請者が代理元でなければ null)。selfName = 自分の名前。
 */
export function applicantNoteOf(
    applicants: WorkflowApplicant[],
    applicant: number | null,
    selfName: string,
): { title: string; text: string } | null {
    const principal = applicants.find((choice) => choice.id === applicant);

    if (applicant === null || principal === undefined) {
        return null;
    }

    return {
        title: `${principal.name}さんとして申請します`,
        text: `申請者と担当組織は${principal.name}さんになります。履歴の処理実行者には、${selfName}(${principal.name}さんの代理)と記録されます。`,
    };
}

/**
 * 参照項目の候補の口 workflow.record-candidates のクエリ(再申請 = case、ほか = flow。既存のデータからは record も。
 * applicant = 申請者が代理元ならその人)。
 */
export function candidateQueryOf(
    scope: {
        mode: WorkflowApplyProps['mode'];
        flowCode: string;
        caseId: number | null;
        recordId: number | null;
    },
    applicant: number | null,
): Record<string, string | number> {
    const query: Record<string, string | number> =
        scope.mode === 'reapply' && scope.caseId !== null
            ? { case: scope.caseId }
            : { flow: scope.flowCode };

    if (scope.mode === 'record' && scope.recordId !== null) {
        query.record = scope.recordId;
    }

    if (applicant !== null) {
        query.applicant = applicant;
    }

    return query;
}

/**
 * 他モジュール参照の欄の値(単一の参照)を空にする。ユーザー・組織の参照は共通マスタの候補で、申請者に依らないので消さない。
 */
function clearedValues(
    fields: RecordFieldDefinition[],
    values: Record<string, RecordFieldDraft>,
): Record<string, RecordFieldDraft> {
    const cleared = { ...values };

    for (const field of fields) {
        if (field.type === 'record') {
            cleared[field.code] = null;
        }
    }

    return cleared;
}

/**
 * 申請者を変えたとき、選んである他モジュール参照の値(申請対象の項目と、明細の行の参照を含む)を消す。候補の集合が申請者の閲覧できる範囲で変わるため。
 */
export function clearRecordReferences(
    module: RecordModuleDefinition,
    data: RecordFormData,
): RecordFormData {
    const childOf = (code: string) =>
        module.fields.find((field) => field.code === code)?.childModule ?? null;

    return {
        values: clearedValues(valueFields(module), data.values),
        details: Object.fromEntries(
            Object.entries(data.details).map(([code, rows]) => {
                const child = childOf(code);

                return [
                    code,
                    child === null
                        ? rows
                        : rows.map((row) => ({
                              ...row,
                              values: clearedValues(
                                  valueFields(child),
                                  row.values,
                              ),
                          })),
                ];
            }),
        ),
    };
}
