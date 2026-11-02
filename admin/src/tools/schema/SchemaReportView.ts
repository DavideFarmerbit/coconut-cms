import {Elements} from '../../dom/Elements.ts';
import type {EntrySample, SchemaReport} from '../../schema/SchemaReport.ts';

/**
 * A schema save's report, as the preview shows it: what refuses it, what it
 * breaks, the steps it runs, the default warnings, and a few entries
 * before and after.
 */
export class SchemaReportView {
    /** The sections `report` has something in, in that order. */
    public static of(report: SchemaReport): HTMLElement[] {
        const failures = Object.entries(report.failures);
        const refusals = [
            ...report.blocked.map((each) => `Blocked by the project's policy: ${each}`),
            ...failures.map(([id, why]) => `Entry ${id} can't move: ${why}`),
            ...report.duplicates.map((each) => `${each.type} would hold ${JSON.stringify(each.values)} twice, in ${each.entities.join(', ')}.`),
        ];

        return [
            ...(refusals.length === 0 ? [] : [SchemaReportView.section('Refused: fix the draft first', refusals, 'schema-report__section--refused')]),
            ...(report.breaks.length === 0 ? [] : [SchemaReportView.section('Breaks other types', report.breaks.map((each) => `${each.type}: ${each.description}${each.setAside === null ? '' : ` Set aside by ${each.setAside}.`}`))]),
            SchemaReportView.section('Steps', report.fixups.length === 0 ? ['Only definitions change: no entry moves.'] : report.fixups),
            ...(report.defaultBreaks.length === 0 ? [] : [SchemaReportView.section('Warnings: defaults breaking a rule', report.defaultBreaks.map((each) => `${each.type}${each.path === '' ? '' : `, ${each.path}`}: ${each.error}`))]),
            ...(report.samples.length === 0 ? [] : [SchemaReportView.samples(report.samples)]),
        ];
    }

    /** A titled list of `lines`. */
    private static section(title: string, lines: readonly string[], modifier: string | null = null): HTMLElement {
        return Elements.create('section', {classes: ['schema-report__section', ...(modifier === null ? [] : [modifier])]}, [
            Elements.create('h3', {classes: ['schema-report__title'], text: title}),
            Elements.create('ul', {classes: ['schema-report__list']}, lines.map((line) => Elements.create('li', {text: line}))),
        ]);
    }

    /** Each sampled entry: a table of its paths, before and after. */
    private static samples(samples: readonly EntrySample[]): HTMLElement {
        return Elements.create('section', {classes: ['schema-report__section']}, [
            Elements.create('h3', {classes: ['schema-report__title'], text: 'Entries before and after'}),
            ...samples.map((sample) => {
                const paths = [...new Set([...Object.keys(sample.before), ...Object.keys(sample.after)])];
                const row = (cells: readonly string[], tag: 'th' | 'td'): HTMLElement => Elements.create('tr', {}, cells.map((text) => Elements.create(tag, {text})));

                return Elements.create('div', {classes: ['schema-report__sample']}, [
                    Elements.create('p', {classes: ['muted'], text: `${sample.id}, ${sample.type}`}),
                    Elements.create('table', {classes: ['schema-report__table']}, [
                        row(['Path', 'Before', 'After'], 'th'),
                        ...paths.map((path) => row([path, SchemaReportView.text(sample.before, path), SchemaReportView.text(sample.after, path)], 'td')),
                    ]),
                ]);
            }),
        ]);
    }

    /** What `values` holds at `path`, as text; "(none)" when it holds nothing there. */
    private static text(values: Readonly<Record<string, unknown>>, path: string): string {
        if (!(path in values)) {
            return '(none)';
        }
        const value = values[path];

        return typeof value === 'string' ? value : JSON.stringify(value);
    }
}
