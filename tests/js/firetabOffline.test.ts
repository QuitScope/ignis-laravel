import { expect, it } from 'vitest';
import { OFFLINE_HINT_MS, offlineTooLong } from '@/lib/firetabOffline';

it('shows the hint only after more than 15 minutes of failing contact', () => {
    expect(offlineTooLong(null, 10 * OFFLINE_HINT_MS)).toBe(false);
    expect(offlineTooLong(1000, 1000 + OFFLINE_HINT_MS)).toBe(false);
    expect(offlineTooLong(1000, 1001 + OFFLINE_HINT_MS)).toBe(true);
});
