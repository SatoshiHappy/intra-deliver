// resources/js の直下(入口の組み立て)はどこにでも依存できる
import { index } from '@/pages/Records/Index';
import { layout } from '@/layouts/AppLayout';

export const app = [index, layout];
