import { Label } from '@/components/ui/label';
import {
    Select,
    SelectContent,
    SelectItem,
    SelectTrigger,
    SelectValue,
} from '@/components/ui/select';
import { versionOptionLabel } from '@/lib/Workflow/flowDefinitionTexts';
import type { WorkflowFlowVersionRow } from '@/types';

const SELECT_ID = 'flow-version-select';

/**
 * フロー図のタブの版の選択(docs の 設計/ワークフロー/ルートの深さ/画面/定義の管理の画面.md の 3.1 節。選択肢 =「版 {番号}」と全角の空白と「{開始日} から」。
 * 見える「版」のラベルは出さず、読み上げの名前「版」だけを残す)。
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
        <div className="flex items-center">
            <Label htmlFor={SELECT_ID} className="sr-only">
                版
            </Label>
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
                            {versionOptionLabel(version)}
                        </SelectItem>
                    ))}
                </SelectContent>
            </Select>
        </div>
    );
}
