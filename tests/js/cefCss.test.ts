import { readFileSync } from 'node:fs';
import { expect, it } from 'vitest';
import { colorMixFallback, untypedPropertyFallback } from '@/lib/cefCss';

const tw = (sel: string, prop: string, pct: number) =>
    `${sel}{${prop}:var(--danger)}@supports (color:color-mix(in lab, red, red)){${sel}{${prop}:color-mix(in oklab, var(--danger) ${pct}%, transparent)}}`;

it('makes mixes below 50 % transparent where color-mix() is missing, inside the utilities layer', () => {
    const css = tw('.bg-danger\\/10', 'background-color', 10) + tw('.ring-x\\/20:focus-visible', '--tw-ring-color', 20);
    expect(colorMixFallback(css)).toBe(
        css +
            '@layer utilities{@supports not (color:color-mix(in lab, red, red)){.bg-danger\\/10{background-color:transparent}.ring-x\\/20:focus-visible{--tw-ring-color:transparent}}}',
    );
});

it('keeps the opaque fallback from 50 % up and leaves other CSS alone', () => {
    const css = tw('.bg-primary\\/90', 'background-color', 90) + tw('.ring\\/50', 'outline-color', 50) + '.a{color:red}';
    expect(colorMixFallback(css)).toBe(css);
});

it('drops untyped registrations without initial value and resets them per element instead', () => {
    const css = '@property --tw-ordinal{syntax:"*";inherits:false}@property --tw-shadow{syntax:"*";inherits:false;initial-value:0 0 #0000}.a{color:red}';
    expect(untypedPropertyFallback(css)).toBe(
        '@property --tw-shadow{syntax:"*";inherits:false;initial-value:0 0 #0000}.a{color:red}@layer properties{*,:before,:after,::backdrop{--tw-ordinal:initial}}',
    );
    expect(untypedPropertyFallback('.a{color:red}')).toBe('.a{color:red}');
});

it('keeps the skin tokens and rules free of what Chromium 103 lacks', () => {
    for (const file of ['resources/css/tokens.css', 'resources/css/skin.css']) {
        const css = readFileSync(file, 'utf8').replace(/\/\*[\s\S]*?\*\//g, '');
        for (const banned of ['oklch(', 'color-mix(', ':has(', '@container']) expect(css, `${file}: ${banned}`).not.toContain(banned);
    }
});
