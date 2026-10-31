import { NameSegmentsText } from '@/components/Workflow/PersonMenu';
import { markedPeopleSegments } from '@/lib/Workflow/caseDetail';
import type { DiagramInputNode } from '@/lib/Workflow/diagramTypes';
import { segmentsText } from '@/lib/Workflow/nameSegments';

/**
 * 図の箱の人の並び(自分には「(自分)」。名前は札の「…」つき = docs の 設計/ワークフロー/申請の周辺/メニューと導線.md 2.2)。
 */
export function DiagramPeople({ node }: { node: DiagramInputNode }) {
    const segments = markedPeopleSegments(node.people);

    if (segments === null) {
        return null;
    }

    const text = segmentsText(segments);

    return (
        <span className="min-w-0 truncate" title={text}>
            <NameSegmentsText segments={segments} />
        </span>
    );
}
