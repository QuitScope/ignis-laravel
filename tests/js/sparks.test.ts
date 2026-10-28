import { expect, it } from 'vitest';
import { sparkLayout } from '@/components/auth/sparks';

it('caps the pixel ratio at 1.5 and the sparks at 26, one per 36 px', () => {
    expect(sparkLayout(1000, 800, 2)).toEqual({ ratio: 1.5, width: 1500, height: 1200, count: 26 });
    expect(sparkLayout(390, 844, 1)).toEqual({ ratio: 1, width: 390, height: 844, count: 11 });
    expect(sparkLayout(100, 100, 0).ratio).toBe(1);
});
