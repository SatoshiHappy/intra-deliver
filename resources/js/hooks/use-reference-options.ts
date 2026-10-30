import { useMasterSearch } from '@/hooks/use-master-search';
import { useRecordCandidates } from '@/hooks/use-record-candidates';
import { nameOrCode } from '@/lib/nameOrCode';
import { hasPartyKind } from '@/lib/recordParty';
import type {
    MasterSearchResult,
    PickedReference,
    RecordFieldDefinition,
} from '@/types';

/**
 * 候補の一覧(ユーザー・組織は共通マスタの検索の口の群を 1 つだけ、型「ユーザーと組織」は両方の群を種類つきで、他モジュール参照は候補の口。
 * 群の見出し = 設計/業務データの入れ物/画面/README.md 4 節)。
 * 開いている間は必ず取るので、options が null なら結果を待っている(失敗は hasFailed)。ユーザーは主所属の名前を所属に持つ
 * (複数の参照の欄のチップに出す)。
 */
export function useReferenceOptions(
    field: RecordFieldDefinition,
    recordId: number | null,
    query: string,
    open: boolean,
): {
    options: PickedReference[] | null;
    hasFailed: boolean;
} {
    const isRecord = field.type === 'record';
    // 開いている間は、語が空でも先頭の候補を取る(閉じると語を空にして結果を捨てる)。使わない側の口は呼ばない。
    const master = useMasterSearch(isRecord ? '' : query, open && !isRecord);
    const records = useRecordCandidates(
        field.referencedModule?.code ?? '',
        isRecord ? query : '',
        recordId,
        open && isRecord,
    );

    if (isRecord) {
        return {
            options:
                records.result?.records.map((record) => ({
                    id: record.id,
                    label: record.label ?? `#${record.id}`,
                })) ?? null,
            hasFailed: records.hasFailed,
        };
    }

    return {
        options:
            master.result === null ? null : masterOptions(field, master.result),
        hasFailed: master.hasFailed,
    };
}

/**
 * 共通マスタの検索の口の結果を、項目の型の候補にする(ユーザーの項目はユーザー、組織の項目は組織、型「ユーザーと組織」は種類を持つ
 * 両方 = ユーザーの群の後に組織の群)。
 */
function masterOptions(
    field: RecordFieldDefinition,
    result: MasterSearchResult,
): PickedReference[] {
    const isParty = hasPartyKind(field);
    const users = result.users.map((user) => ({
        id: user.id,
        label: nameOrCode(user),
        affiliation:
            user.mainDepartment === null
                ? null
                : nameOrCode(user.mainDepartment),
        ...(isParty ? { type: 'user' as const } : {}),
    }));
    const departments = result.departments.map((department) => ({
        id: department.id,
        label: nameOrCode(department),
        ...(isParty ? { type: 'department' as const } : {}),
    }));

    if (field.type === 'user') {
        return users;
    }

    return isParty ? [...users, ...departments] : departments;
}
