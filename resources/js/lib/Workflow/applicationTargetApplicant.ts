import { applicationTargetCleared } from '@/lib/Workflow/applicationTarget';
import { showApplicationTarget } from '@/lib/Workflow/applicationTargetFlow';
import type {
    ApplicationTargetFlow,
    ApplicationTargetPorts,
} from '@/lib/Workflow/applicationTargetFlow';
import { clearRecordReferences } from '@/lib/Workflow/applyApplicant';

/** 申請者を変えて申請対象が消えたときの片付けを 1 か所にする(docs の 設計/ワークフロー/申請の周辺/画面.md の 5 節の「申請対象の値の写し」) */
function clearApplicationTarget(
    applicationTarget: ApplicationTargetFlow,
    ports: ApplicationTargetPorts,
) {
    applicationTarget.state = applicationTargetCleared(applicationTarget.state);
    applicationTarget.confirm = null;
    showApplicationTarget(applicationTarget, ports);
}

/**
 * 申請者を変える(変わるなら申請対象が消えるので、空を最後に決まった申請対象にしてから change で変える)。
 */
export function applicantChanged(
    applicationTarget: ApplicationTargetFlow,
    ports: ApplicationTargetPorts,
    applicant: number | null,
    change: (applicant: number | null) => void,
) {
    if (applicant !== ports.applicant) {
        clearApplicationTarget(applicationTarget, ports);
    }

    change(applicant);
}

/**
 * 部分の読み直しでサーバーが別の申請者を選び直した。申請者を変えたのと同じに、参照の値を消し、待っている要求の応答は当てない。
 */
export function applicantReselected(
    applicationTarget: ApplicationTargetFlow,
    ports: ApplicationTargetPorts,
) {
    ports.updateRecord((record) => clearRecordReferences(ports.module, record));
    clearApplicationTarget(applicationTarget, ports);
}
