import { Input } from '@/components/ui/input';
import { TabsList, TabsTrigger } from '@/components/ui/tabs';

type Props = {
    query: string;
    onQueryChange: (query: string) => void;
};

/**
 * 表の上の行(左 = 札「設定」「判定」、右端 = 検索)。Tabs の中に置く。
 */
export function GridToolbar({ query, onQueryChange }: Props) {
    return (
        <div className="flex items-center justify-between gap-4">
            <TabsList
                variant="line"
                className="h-auto max-w-full flex-wrap justify-start"
            >
                <TabsTrigger value="settings" className="flex-none">
                    設定
                </TabsTrigger>
                <TabsTrigger value="judgements" className="flex-none">
                    判定
                </TabsTrigger>
            </TabsList>
            <Input
                value={query}
                onChange={(event) => onQueryChange(event.target.value)}
                placeholder="リソース名で検索"
                aria-label="リソース名で検索"
                className="max-w-64"
            />
        </div>
    );
}
