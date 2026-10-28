// Build-time only (vite.config.ts): FiveM's CEF is Chromium 103, which has no color-mix() (Chrome 111). For `bg-danger/10`
// Tailwind 4 falls back to the opaque token (`background-color: var(--danger)`), so `bg-danger/10 text-danger` turns into
// red text on solid red. This appends, for browsers without color-mix(), `transparent` for every mix below 50 %; 50 % and
// up keep Tailwind's opaque fallback. ponytail: a threshold, not real alpha; real alpha needs channel tokens. docs/dev/cef.md
const MIX = /@supports \(color:\s*color-mix\(in lab,\s*red,\s*red\)\)\s*\{([^{}]+)\{([a-z-]+):\s*color-mix\(in [a-z]+,\s*[^,{}]+?\s+(\d+(?:\.\d+)?)%,\s*transparent\)\}\}/g;

export function colorMixFallback(css: string): string {
    const rules = [...css.matchAll(MIX)].filter((m) => Number(m[3]) < 50).map((m) => `${m[1]}{${m[2]}:transparent}`);

    return rules.length ? `${css}@layer utilities{@supports not (color:color-mix(in lab, red, red)){${rules.join('')}}}` : css;
}

// Chromium 103 validates a var() fallback against the registered syntax, and an empty one is invalid for `*`. Tailwind 4
// composes with empty fallbacks of untyped properties (`var(--tw-ring-inset,)`, `var(--tw-ordinal,)`), so in 103 the
// whole declaration is dropped: no ring (focus-visible), tabular-nums, filter, rotate. This removes the untyped
// registrations without initial value and replaces their `inherits: false` by a reset on every element in Tailwind's
// first layer (`properties`, below every utility), as Tailwind's own fallback for browsers without @property does.
const UNTYPED = /@property\s+(--[\w-]+)\s*\{\s*syntax:\s*"\*"\s*;\s*inherits:\s*false\s*;?\s*\}/g;

export function untypedPropertyFallback(css: string): string {
    const names = [...css.matchAll(UNTYPED)].map((m) => m[1]);

    return names.length ? `${css.replace(UNTYPED, '')}@layer properties{*,:before,:after,::backdrop{${names.map((n) => `${n}:initial`).join(';')}}}` : css;
}
