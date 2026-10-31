import { describe, expect, it } from 'vite-plus/test';
import { departmentChartUrl, userChartUrl } from './organizationChart';

describe('組織図の行き先', () => {
    it('主所属のあるユーザーは組織とユーザー(検索の画面の行き先と同じ)', () => {
        expect(userChartUrl(9, 21)).toBe(
            '/master/organization-chart?department=21&user=9',
        );
    });

    it('主所属の無いユーザーはユーザーだけ', () => {
        expect(userChartUrl(9, null)).toBe('/master/organization-chart?user=9');
    });

    it('組織は組織だけ', () => {
        expect(departmentChartUrl(21)).toBe(
            '/master/organization-chart?department=21',
        );
    });
});
