import { Label } from '@/components/ui/label';
import {
    Select,
    SelectContent,
    SelectItem,
    SelectTrigger,
    SelectValue,
} from '@/components/ui/select';
import { versionLabel } from '@/lib/Workflow/flowDefinitionTexts';
import type { WorkflowFlowVersionRow } from '@/types';

const SELECT_ID = 'flow-version-select';

/**
 * フロー図のタブの版の選択(docs の 設計/ワークフロー/ルートの深さ/画面/定義の管理の画面.md の 3.1 節。選択肢 =「版 {番号}」)。
 * 変えるとその版の図とステップの設定に切り替わる(呼び手が部分の再読み込みをする)。
 */
export function FlowVersionSelect({
    versions,
    selected,
    onChange,
}: {
    versions: WorkflowFlowVersionRow[];
    selected: number;
    onChange: (number: number) => void;
}) {
    return (
        <div className="flex items-center gap-2">
            <Label htmlFor={SELECT_ID}>版</Label>
            <Select
                value={String(selected)}
                onValueChange={(value) => onChange(Number(value))}
            >
                <SelectTrigger id={SELECT_ID} className="min-w-28">
                    <SelectValue />
                </SelectTrigger>
                <SelectContent>
                    {versions.map((version) => (
                        <SelectItem
                            key={version.number}
                            value={String(version.number)}
                        >
                            {versionLabel(version.number)}
                        </SelectItem>
                    ))}
                </SelectContent>
            </Select>
        </div>
    );
}
