import { CaseBasicInfo } from '@/components/Workflow/CaseBasicInfo';
import { CaseHistoryList } from '@/components/Workflow/CaseHistoryList';
import { CaseTabFaces } from '@/components/Workflow/CaseTabFaces';
import { RelatedCases } from '@/components/Workflow/RelatedCases';
import type { WorkflowCaseShowProps } from '@/types';

/**
 * 左のタブ「申請」: 申請情報・処理履歴・つながる申請(面の並びと出す条件は
 * 設計/業務データの入れ物/レコードの共通機能/詳細の画面/申請の詳細.md の 3 節。フロー情報・確認はタブ「フロー」= CaseFlowTab)。
 */
export function CaseApplicationTab(props: WorkflowCaseShowProps) {
    return (
        <CaseTabFaces>
            <CaseBasicInfo data={props.case} />
            <CaseHistoryList histories={props.histories} />
            <RelatedCases related={props.related} />
        </CaseTabFaces>
    );
}
