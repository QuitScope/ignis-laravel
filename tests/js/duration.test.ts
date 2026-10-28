import { expect, it } from 'vitest';
import { formatBytes, relativeAge } from '@/lib/duration';

it('words an age in the given locale', () => {
    expect(relativeAge(30, 'en')).toBe('30 seconds ago');
    expect(relativeAge(180, 'en')).toBe('3 minutes ago');
    expect(relativeAge(180, 'de')).toBe('vor 3 Minuten');
    expect(relativeAge(7300, 'en')).toBe('2 hours ago');
    expect(relativeAge(-5, 'en')).toBe('now');
});

it('formats sizes with the locale decimal mark', () => {
    expect(formatBytes(512, 'en')).toBe('512 B');
    expect(formatBytes(1536, 'en')).toBe('1.5 KB');
    expect(formatBytes(1536, 'de')).toBe('1,5 KB');
});
