import { candidateQueryOf } from '@/lib/Workflow/applyApplicant';
import {
    create as applicationCreate,
    store as applicationStore,
} from '@/routes/workflow/applications';
import { index as casesIndex, show as caseShow } from '@/routes/workflow/cases';
import {
    apply as draftApply,
    edit as draftEdit,
    store as draftStore,
    update as draftUpdate,
} from '@/routes/workflow/drafts';
import { index as flowsIndex } from '@/routes/workflow/flows';
import {
    create as reapplicationCreate,
    store as reapplicationStore,
} from '@/routes/workflow/reapplications';
import { show as recordShow } from '@/routes/records/data';
import { recordCandidates, targetValues } from '@/routes/workflow';
import type { WorkflowApplyProps } from '@/types';
import type { RouteDefinition } from '@/wayfinder';

type Props = WorkflowApplyProps;

/**
 * 一時保存・再申請の案件の id(この 2 つの mode は案件を持つ。新規で呼ばない)。
 */
function caseIdOf(props: Props): number {
    if (props.case === null) {
        throw new Error('新規の申請の画面には案件が無い');
    }

    return props.case.id;
}

/**
 * 既存のデータからの申請の、書くレコードの id(この mode は recordId を持つ。ほかで呼ばない)。
 */
function recordIdOf(props: Props): number {
    if (props.recordId === null) {
        throw new Error('既存のデータからの申請の画面にレコードが無い');
    }

    return props.recordId;
}

/**
 * 申請の送り先(新規・既存のデータ = 申請、一時保存 = 一時保存からの申請、再申請 = 再申請)。既存のデータは新規と同じルートで、
 * レコードは送る内容の record_id・record_version が指す。
 */
export function applyActionOf(props: Props): RouteDefinition<'post'> {
    if (props.mode === 'new' || props.mode === 'record') {
        return applicationStore(props.flow.code);
    }

    return props.mode === 'draft'
        ? draftApply(caseIdOf(props))
        : reapplicationStore(caseIdOf(props));
}

/** 一時保存の送り先(新規 = 作成、一時保存 = 更新。既存のデータからの申請は一時保存を持たない = showsSaveDraft が出さない) */
export function draftActionOf(props: Props): RouteDefinition<'post' | 'put'> {
    return props.mode === 'new'
        ? draftStore(props.flow.code)
        : draftUpdate(caseIdOf(props));
}

/** キャンセルの戻り先(新規 = フローの選択、一時保存 = 一覧の一時保存、再申請 = 案件の詳細、既存のデータ = そのレコードの詳細) */
export function backHrefOf(props: Props) {
    switch (props.mode) {
        case 'new':
            return flowsIndex();
        case 'draft':
            return casesIndex({ query: { tab: 'draft' } });
        case 'record':
            return recordShow({
                module: props.module.code,
                record: recordIdOf(props),
            });
        case 'reapply':
            return caseShow(caseIdOf(props));
    }
}

/** パンくずのフローの先(その画面そのもの。既存のデータからの申請はクエリ record を残す) */
function flowCrumbHrefOf(props: Props): RouteDefinition<'get'> {
    switch (props.mode) {
        case 'draft':
            return draftEdit(caseIdOf(props));
        case 'record':
            return applicationCreate(props.flow.code, {
                query: { record: recordIdOf(props) },
            });
        default:
            return applicationCreate(props.flow.code);
    }
}

/** パンくず(再申請 = 申請一覧 → 案件 → 再申請、ほか = 申請の種類を選ぶ → フロー。既存のデータのフローの先は同じレコードの申請の画面) */
export function breadcrumbsOf(props: Props) {
    if (props.mode === 'reapply') {
        return [
            { title: '申請一覧', href: casesIndex() },
            {
                title: props.initial?.name ?? '',
                href: caseShow(caseIdOf(props)),
            },
            { title: '再申請', href: reapplicationCreate(caseIdOf(props)) },
        ];
    }

    return [
        { title: '申請の種類を選ぶ', href: flowsIndex() },
        {
            title: props.flow.name,
            href: flowCrumbHrefOf(props),
        },
    ];
}

/**
 * 参照項目の候補の口 workflow.record-candidates の URL(docs の 設計/ワークフロー/案件の運用/画面/案件の画面.md の 6.1 節)。query = 語と除くレコード
 * (共有の部品が付ける)、scope = 候補のクエリの元(再申請は case、ほかは flow、既存のデータからは record も)、applicant = 申請者が代理元ならその人。
 */
export function recordCandidatesUrlOf(
    moduleCode: string,
    query: { q?: string; exclude?: number },
    scope: Parameters<typeof candidateQueryOf>[0],
    applicant: number | null,
): string {
    return recordCandidates.url(
        { module: moduleCode },
        { query: { ...query, ...candidateQueryOf(scope, applicant) } },
    );
}

/**
 * 申請対象の値の写しの口 workflow.target-values の URL(docs の 設計/ワークフロー/申請の周辺/既存のデータからの申請/申請対象からの申請.md の 10.3 節)。
 * クエリ flow・case・applicant・record は参照項目の候補の口と同じ読み(scope・applicant の意味も同じ)。
 */
export function applicationTargetSnapshotUrlOf(
    applicationTargetId: number,
    scope: Parameters<typeof candidateQueryOf>[0],
    applicant: number | null,
): string {
    return targetValues.url(
        { target: applicationTargetId },
        { query: candidateQueryOf(scope, applicant) },
    );
}
