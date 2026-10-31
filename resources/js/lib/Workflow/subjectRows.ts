import type {
    WorkflowSubjectCandidate,
    WorkflowSubjectCandidatesResponse,
    WorkflowSubjectRow,
    WorkflowSubjectType,
} from '@/types';

/**
 * 対象者の欄の入力(参照者の面とステップの設定の「対象者で指定」が共有する。docs の
 * 設計/ワークフロー/ルートの深さ/画面/定義の管理の画面.md の 3.3 節・3.4 節)。選んだ相手を札で並べ、1 つの候補の一覧から
 * 4 種類(ユーザー・組織・役職・ロール)を選ぶ。保存の形は行 = 種類 + 相手(並びのまま)。
 */

/** 種類の語(並び = 候補の一覧の群の順。語は認可設定の列を追加と同じ) */
export const SUBJECT_TYPES: { value: WorkflowSubjectType; label: string }[] = [
    { value: 'user', label: 'ユーザー' },
    { value: 'department', label: '組織' },
    { value: 'company_post', label: '役職' },
    { value: 'role', label: 'ロール' },
];

/** 選んだ相手(サーバーの行と同じ形。affiliation = ユーザーの主所属の名前。札に添える。サーバーの行には無い) */
export type SubjectRowDraft = WorkflowSubjectRow & {
    affiliation?: string | null;
};

/** 送る行 */
export type SubjectRowPayload = {
    type: WorkflowSubjectType;
    id: number;
};

/** 候補の一覧の群 1 つ(選び済みの相手を除いた候補) */
export type SubjectCandidateGroup = {
    type: WorkflowSubjectType;
    label: string;
    candidates: WorkflowSubjectCandidate[];
};

/**
 * 相手の識別(種類と id。札の key と、選び済みの照合)。
 */
export function subjectKey(row: { type: WorkflowSubjectType; id: number }) {
    return `${row.type}:${row.id}`;
}

/**
 * サーバーの行から入力(並びのまま)。
 */
export function draftsOf(rows: WorkflowSubjectRow[]): SubjectRowDraft[] {
    return rows.map((row) => ({ ...row }));
}

/**
 * 候補を選んで札を末尾に足す(名前の無い相手はコード)。
 */
export function withPicked(
    drafts: SubjectRowDraft[],
    type: WorkflowSubjectType,
    candidate: WorkflowSubjectCandidate,
): SubjectRowDraft[] {
    return [
        ...drafts,
        {
            type,
            id: candidate.id,
            name: candidate.name ?? candidate.code,
            affiliation: candidate.mainDepartmentName,
        },
    ];
}

/**
 * 札を外す。
 */
export function withoutSubject(
    drafts: SubjectRowDraft[],
    key: string,
): SubjectRowDraft[] {
    return drafts.filter((draft) => subjectKey(draft) !== key);
}

/**
 * 候補の一覧の群(並び = 応答の群の順。選び済みの相手を除き、候補の無い群は出さない)。
 */
export function candidateGroupsOf(
    groups: WorkflowSubjectCandidatesResponse['groups'],
    picked: SubjectRowDraft[],
): SubjectCandidateGroup[] {
    const pickedKeys = new Set(picked.map(subjectKey));

    return groups.flatMap(({ type, candidates }) => {
        const rest = candidates.filter(
            (candidate) =>
                !pickedKeys.has(subjectKey({ type, id: candidate.id })),
        );
        const label =
            SUBJECT_TYPES.find((item) => item.value === type)?.label ?? type;

        return rest.length === 0 ? [] : [{ type, label, candidates: rest }];
    });
}

/**
 * 送る行(並びのまま)。
 */
export function rowsPayloadOf(drafts: SubjectRowDraft[]): SubjectRowPayload[] {
    return drafts.map(({ type, id }) => ({ type, id }));
}

/**
 * サーバーの行の識別(行が替わったら入力を作り直すための key)。
 */
export function rowsSignature(rows: WorkflowSubjectRow[]): string {
    return rows.map(subjectKey).join(',');
}
