import { withApplicationTarget } from './applicationTargetSnapshot';
import {
    applicationTargetFlowOf,
    recordChanged,
} from './applicationTargetFlow';
import type {
    ApplicationTargetPorts,
    ApplicationTargetView,
} from './applicationTargetFlow';
import { createFormData } from '@/lib/recordForm';
import type { RecordFormData } from '@/lib/recordForm';
import type { ApplicationTargetSnapshot } from '@/types/workflowApplyTarget';
import type {
    PickedReference,
    RecordFieldDefinition,
    RecordFieldType,
    RecordFieldValue,
    RecordModuleDefinition,
} from '@/types';

/**
 * 申請対象の値の写しの単体テストが使う申請書の業務モジュール(申請対象の項目 project のほか、文字列・選択・整数・真偽・日時・
 * 部署・複数のユーザー・マイルストーンの明細)と値の組み立て。名前が `.testing.ts` のファイルは `*.test.ts` からだけ import できる。
 */

/** 項目の定義(写しが読む属性だけ。名前 = コード) */
function recordField(
    code: string,
    type: RecordFieldType,
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
        isListed: true,
        isSearchable: true,
        isTitle: false,
        grantsRowAccess: false,
        ...overrides,
    };
}

const milestone: RecordModuleDefinition = {
    code: 'milestone',
    name: 'マイルストーン',
    description: null,
    fields: [recordField('title', 'string'), recordField('done', 'boolean')],
    rules: [],
};

export const planModule: RecordModuleDefinition = {
    code: 'plan',
    name: '計画の申請',
    description: null,
    fields: [
        recordField('project', 'record'),
        recordField('name', 'string'),
        recordField('status', 'choice', {
            options: [
                { code: 'planning', name: '計画中' },
                { code: 'active', name: '進行中' },
            ],
        }),
        recordField('budget', 'integer'),
        recordField('flag', 'boolean'),
        recordField('start_at', 'datetime'),
        recordField('department', 'department'),
        recordField('members', 'user', { isMultiple: true }),
        recordField('milestones', 'details', { childModule: milestone }),
    ],
    rules: [],
};

/** planModule の項目の既定値を決めたもの(コード → 既定値。新規の画面が欄に入れる値) */
export function planModuleWithDefaults(
    defaults: Record<string, string | number | boolean | null>,
): RecordModuleDefinition {
    return {
        ...planModule,
        fields: planModule.fields.map((field) =>
            field.code in defaults
                ? { ...field, defaultValue: defaults[field.code] }
                : field,
        ),
    };
}

/** 申請対象の項目のコード */
export const APPLICATION_TARGET_FIELD = 'project';

/** 選んだ相手 */
export function picked(id: number, label = `相手 ${id}`): PickedReference {
    return { id, label };
}

/** 写しの値の参照 */
export function reference(id: number, label: string | null = null) {
    return { id, code: null, label, affiliation: null };
}

/** 何も入っていないフォームの値に、values と details を重ねたもの */
export function recordOf(
    values: RecordFormData['values'] = {},
    details: RecordFormData['details'] = {},
): RecordFormData {
    const empty = createFormData(planModule);

    return {
        values: { ...empty.values, ...values },
        details: { ...empty.details, ...details },
    };
}

/** 明細の既にある行 */
export function row(id: number, title: string, done = false) {
    return { key: `id-${id}`, id, values: { title, done } };
}

/** 写しの値(values の値は応答の形。details は行の値) */
export function snapshotOf(
    values: Record<string, RecordFieldValue> = {},
    details: ApplicationTargetSnapshot['details'] = {},
): ApplicationTargetSnapshot {
    return { values, details };
}

/** 応答の処理(microtask)が済むまで待つ */
const settled = () => new Promise<void>((resolve) => setTimeout(resolve, 0));

type FlowState = {
    record: RecordFormData;
    denied: number;
    views: ApplicationTargetView[];
};

type FlowRequest = {
    url: string;
    succeed: (values: ApplicationTargetSnapshot) => Promise<void>;
    fail: () => Promise<void>;
    crash: (error: unknown) => Promise<void>;
};

/** 偽の口(state に書き、requests に取った要求を足す) */
function fakePorts(
    state: FlowState,
    requests: FlowRequest[],
): ApplicationTargetPorts {
    return {
        module: planModule,
        applicant: 10,
        updateRecord: (update) => {
            const before = state.record;

            state.record = update(before);

            return { before, after: state.record };
        },
        urlOf: (id, applicant) => `/values/${id}?applicant=${applicant}`,
        fetch: (url) =>
            new Promise((resolve, reject) => {
                requests.push({
                    url,
                    succeed: (values) => {
                        resolve(values);

                        return settled();
                    },
                    fail: () => {
                        resolve(null);

                        return settled();
                    },
                    crash: (error) => {
                        reject(error);

                        return settled();
                    },
                });
            }),
        notifyFailed: () => {
            state.denied += 1;
        },
        publish: (view) => state.views.push(view),
    };
}

/** 偽の口と、取った要求への応答を手で返すための記録(応答の関数は、応答の処理が済むまで待つ) */
export function flowSetup(
    start: RecordFormData,
    targetField: string | null = APPLICATION_TARGET_FIELD,
) {
    const state = {
        record: start,
        denied: 0,
        views: [] as ApplicationTargetView[],
    };
    const requests: FlowRequest[] = [];
    const ports = fakePorts(state, requests);
    const applicationTarget = applicationTargetFlowOf(targetField, {
        record: start,
        confirm: null,
    });
    const lastView = () => state.views.at(-1);
    /** 申請対象の欄を選ぶ(null = 空にする)。返す Promise は応答を当て終わったとき */
    const chooseAndWait = (to: PickedReference | null) =>
        recordChanged(applicationTarget, ports, (record) =>
            withApplicationTarget(record, APPLICATION_TARGET_FIELD, to),
        );
    /** 同じく選ぶが、応答を待たない(応答は requests の関数で返す) */
    const choose = (to: PickedReference | null) => {
        void chooseAndWait(to);
    };

    return {
        state,
        requests,
        ports,
        applicationTarget,
        choose,
        chooseAndWait,
        lastView,
    };
}

export const snapshotFor = (name: string, id = 5) =>
    snapshotOf({ project: reference(id, `P${id}`), name });
export const opened = recordOf({ project: picked(9, '開いた時'), name: '' });
