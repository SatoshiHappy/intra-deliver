// pages/<M>/ → layouts・共通・同じ <M>/
import { shared } from '@/components/Shared';
import { form } from '@/components/Records/Form';
import { layout } from '@/layouts/AppLayout';

export const index = [shared, form, layout];
