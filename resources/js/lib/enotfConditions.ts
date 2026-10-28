/**
 * Plausibility, twin of modules/Enotf/Support/ConditionsService.php (same rules, same value conventions); both run
 * tests/Fixtures/enotf-conditions.json. Input: the flat legacy key -> value map of the protocol page.
 */
export interface Violation {
    field: string;
    rule: string;
    message_key: string;
    section: string;
}
export interface SectionState {
    status: 'filled' | 'partfilled' | 'unfilled' | 'nocheck';
    filled: number;
    total: number;
}
type Values = Record<string, unknown>;
type Rule = [section: string, field: string, db: string[], check: (d: Values) => boolean];

export const SECTIONS = ['rettdaten', 'anamnese', 'erstbefund', 'diagnose', 'massnahmen', 'verlauf', 'abschluss'];

export const ZERO_IS_VALID = [
    'patsex', 'awsicherung_neu', 'b_symptome', 'b_auskult', 'b_beatmung', 'c_kreislauf', 'c_ekg', 'c_zugang',
    'd_bewusstsein', 'd_ex_1', 'd_pupillenw_1', 'd_pupillenw_2', 'd_lichtreakt_1', 'd_lichtreakt_2', 'd_gcs_1', 'd_gcs_2', 'd_gcs_3',
    'v_muster_k', 'v_muster_t', 'v_muster_a', 'v_muster_al', 'v_muster_bl', 'v_muster_w', 'transportziel', 'medis',
    'naca_initial', 'naca_uebergabe',
];

export const REA_DETAILS = ['rea_ursache', 'rea_kollaps', 'rea_hdm_durch', 'rea_hdm_zeit', 'rea_rosc', 'rea_kh_aufnahme'];

const OVERRIDES: Record<number, string[]> = {
    4: [
        'patsex', 'spat', 'atemwege', 'zyanose', 'b_symptome', 'b_auskult', 'c_kreislauf', 'c_ekg', 'c_puls',
        'd_bewusstsein', 'd_extremitaeten', 'd_pupillen', 'd_gcs', 'psych', 'messwerte', 'naca_initial', 'diagnose_haupt',
        'awsicherung_neu', 'b_beatmung', 'c_zugang', 'medis', 'na_nachf', 'rea_status', 'rea_details',
    ],
};

const blank = (v: unknown) => v === null || v === undefined || v === '' || v === '0' || v === 0 || v === false;
const isNull = (v: unknown) => v === null || v === undefined;
const nul = (k: string) => (d: Values) => isNull(d[k]);
const emp = (k: string) => (d: Values) => blank(d[k]);
const anyNull = (...k: string[]) => (d: Values) => k.some((c) => isNull(d[c]));

const BASE: Record<string, Rule> = {
    patsex: ['anamnese', 'patsex', ['patsex'], nul('patsex')],
    edatum: ['rettdaten', 'edatum', ['edatum'], emp('edatum')],
    ezeit: ['rettdaten', 'ezeit', ['ezeit'], emp('ezeit')],
    transportziel: ['rettdaten', 'transportziel', ['transportziel'], nul('transportziel')],
    salarm: ['rettdaten', 'salarm', ['salarm'], emp('salarm')],
    spat: ['rettdaten', 'spat', ['spat'], emp('spat')],
    sende: ['rettdaten', 'sende', ['sende'], emp('sende')],
    eart: ['rettdaten', 'eart', ['eart'], nul('eart')],
    transp_adresse: ['rettdaten', 'transp_adresse', ['transp_adresse'], emp('transp_adresse')],
    atemwege: ['erstbefund', 'awfrei_1', ['awfrei_1'], (d) => blank(d.awfrei_1) && blank(d.awfrei_2) && blank(d.awfrei_3)],
    zyanose: ['erstbefund', 'zyanose_1', ['zyanose_1'], (d) => blank(d.zyanose_1) && blank(d.zyanose_2)],
    b_symptome: ['erstbefund', 'b_symptome', ['b_symptome'], nul('b_symptome')],
    b_auskult: ['erstbefund', 'b_auskult', ['b_auskult'], nul('b_auskult')],
    c_kreislauf: ['erstbefund', 'c_kreislauf', ['c_kreislauf'], nul('c_kreislauf')],
    c_ekg: ['erstbefund', 'c_ekg', ['c_ekg'], nul('c_ekg')],
    c_puls: ['erstbefund', 'c_puls_reg', ['c_puls_rad', 'c_puls_reg'], anyNull('c_puls_reg', 'c_puls_rad')],
    d_bewusstsein: ['erstbefund', 'd_bewusstsein', ['d_bewusstsein'], nul('d_bewusstsein')],
    d_extremitaeten: ['erstbefund', 'd_ex_1', ['d_ex_1'], nul('d_ex_1')],
    d_pupillen: ['erstbefund', 'd_pupillenw_1', ['d_pupillenw_1', 'd_pupillenw_2', 'd_lichtreakt_1', 'd_lichtreakt_2'],
        anyNull('d_pupillenw_1', 'd_lichtreakt_1', 'd_pupillenw_2', 'd_lichtreakt_2')],
    d_gcs: ['erstbefund', 'd_gcs_1', ['d_gcs_1', 'd_gcs_2', 'd_gcs_3'], anyNull('d_gcs_1', 'd_gcs_2', 'd_gcs_3')],
    psych: ['erstbefund', 'psych', ['psych'], nul('psych')],
    messwerte: ['erstbefund', 'spo2', ['spo2', 'atemfreq', 'rrsys', 'herzfreq', 'bz'],
        (d) => ['spo2', 'atemfreq', 'rrsys', 'herzfreq', 'bz'].some((c) => blank(d[c]))],
    naca_initial: ['diagnose', 'naca_initial', ['naca_initial'], nul('naca_initial')],
    elokation: ['anamnese', 'elokation', ['elokation'], nul('elokation')],
    diagnose_haupt: ['diagnose', 'diagnose_haupt', ['diagnose_haupt'], nul('diagnose_haupt')],
    awsicherung_neu: ['massnahmen', 'awsicherung_neu', ['awsicherung_neu'], nul('awsicherung_neu')],
    b_beatmung: ['massnahmen', 'b_beatmung', ['b_beatmung'], nul('b_beatmung')],
    c_zugang: ['massnahmen', 'c_zugang', ['c_zugang'], nul('c_zugang')],
    medis: ['massnahmen', 'medis', ['medis'], nul('medis')],
    ebesonderheiten: ['abschluss', 'ebesonderheiten', ['ebesonderheiten'], emp('ebesonderheiten')],
    na_nachf: ['abschluss', 'na_nachf', ['na_nachf'], (d) => Number(d.prot_by ?? 0) !== 1 && isNull(d.na_nachf)],
    rea_status: ['massnahmen', 'rea_status', ['rea_status'], nul('rea_status')],
    rea_details: ['massnahmen', 'rea_ursache', [], (d) => toInt(d.rea_status) === 2 && REA_DETAILS.some((c) => isNull(d[c]))],
    pfname: ['abschluss', 'pfname', ['pfname'], emp('pfname')],
    prot_by: ['rettdaten', 'prot_by', ['prot_by'], nul('prot_by')],
};

function toInt(v: unknown): number {
    if (typeof v === 'number') return Math.trunc(v);
    const n = Number.parseInt(String(v ?? ''), 10);
    return Number.isNaN(n) ? 0 : n;
}

export function activeRules(transportziel: number | null): Record<string, Rule> {
    const drop = new Set(transportziel === null ? [] : (OVERRIDES[transportziel] ?? []));
    const rules = Object.fromEntries(Object.entries(BASE).filter(([k]) => !drop.has(k)));
    if (transportziel === 2 || transportziel === 21 || transportziel === 22) {
        rules.ziel_adresse = ['rettdaten', 'ziel_adresse', ['ziel_adresse'], emp('ziel_adresse')];
        rules.s7 = ['rettdaten', 's7', ['s7'], emp('s7')];
        rules.s8 = ['rettdaten', 's8', ['s8'], emp('s8')];
    }
    return rules;
}

export function legacy(values: Values): Values {
    const out: Values = {};
    for (const [k, v] of Object.entries(values)) {
        if (typeof v === 'string') out[k] = v.trim() === '' ? null : v;
        else if (typeof v === 'boolean') out[k] = v ? 1 : 0;
        else if (Array.isArray(v)) out[k] = JSON.stringify(v);
        else if (v !== null && typeof v === 'object') out[k] = Object.values(v).every((x) => x === null || x === '') ? null : JSON.stringify(v);
        else out[k] = v ?? null;
    }
    if (isNull(out.transportziel) && !isNull(out.destination_poi_id)) out.transportziel = 0;
    if (isNull(out.medis) || out.medis === '[]') out.medis = [null, undefined, 0, '0'].includes(out.medis_keine as never) ? null : '0';
    return out;
}

export const transportziel = (d: Values) => (isNull(d.transportziel) ? null : toInt(d.transportziel));

export function evaluate(values: Values): Violation[] {
    const d = legacy(values);
    return Object.entries(activeRules(transportziel(d)))
        .filter(([, r]) => r[3](d))
        .map(([rule, [section, field]]) => ({ field, rule, message_key: `enotf.plausibility.${rule}`, section }));
}

export const filled = (field: string, v: unknown) => {
    const s = isNull(v) ? '' : String(v);
    return s !== '' && (s !== '0' || ZERO_IS_VALID.includes(field));
};

export function sectionStatus(values: Values): Record<string, SectionState> {
    const d = legacy(values);
    const cols: Record<string, string[]> = {};
    for (const [section, , db] of Object.values(activeRules(transportziel(d)))) {
        cols[section] = [...new Set([...(cols[section] ?? []), ...db])];
    }
    return Object.fromEntries(
        SECTIONS.map((section) => {
            const c = cols[section] ?? [];
            const n = c.filter((col) => filled(col, d[col])).length;
            const status = c.length === 0 ? 'nocheck' : n === c.length ? 'filled' : n > 0 ? 'partfilled' : 'unfilled';
            return [section, { status, filled: n, total: c.length }];
        }),
    );
}

export function countBySection(violations: Violation[]): Record<string, number> {
    const out: Record<string, number> = {};
    for (const v of violations) out[v.section] = (out[v.section] ?? 0) + 1;
    return out;
}
