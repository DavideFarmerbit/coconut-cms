import {Elements} from '../dom/Elements.ts';
import type {ApiClient} from '../public/api/ApiClient.ts';
import type {EntitySession} from '../public/entities/EntitySession.ts';
import type {EntityStore} from '../public/entities/EntityStore.ts';
import type {SessionChange} from '../public/entities/SessionChange.ts';
import type {CollapseMemory} from '../public/properties/CollapseMemory.ts';
import type {PropertyEditors} from '../public/properties/PropertyEditors.ts';
import {PropertyPath} from '../public/properties/PropertyPath.ts';
import type {ObjectValue} from '../public/properties/RecordValues.ts';
import type {CatalogField, TypeCatalog} from '../public/types/TypeCatalog.ts';
import type {PropertyFilter} from '../public/properties/PropertyFilter.ts';
import {BuiltRow} from './BuiltRow.ts';
import {PropertyVisibility} from './PropertyVisibility.ts';
import type {RowServices} from './RowServices.ts';

/** The admin's parts a tree's rows use, and what the tree shows. */
export interface RowSettings {
    readonly api: ApiClient;
    readonly editors: PropertyEditors;
    readonly entities: EntityStore;
    readonly collapse: CollapseMemory;
    /** Narrows what the tree shows; null for everything. */
    readonly filter: PropertyFilter | null;
}

/** Told that the session reloaded: the view shows everything again. */
export type Reloaded = () => void;

/**
 * The rows one view shows of a session, kept by path, and kept up to date
 * from the session's changes, row by row:
 * - values changed: the rows at those paths are built again, but for the
 *   row that made the change, which keeps its widget;
 * - a change of shape: the row at its path is built again, with what's under it;
 * - any change: every row marks again where it stands;
 * - a reload: the view shows everything again.
 * Under a filter, the entity's fields and a plain parent's children show as
 * the filter says; under a property shown in full, everything shows.
 * A view disposes of its tree when it goes.
 */
export class RowTree implements RowServices {
    private readonly rows = new Map<string, BuiltRow>();
    private readonly visibility: PropertyVisibility;
    private readonly listener = (change: SessionChange): void => this.changed(change);

    public constructor(
        public readonly session: EntitySession,
        private readonly settings: RowSettings,
        private readonly reloaded: Reloaded,
    ) {
        this.visibility = new PropertyVisibility(session, settings.filter);
        session.onChange(this.listener);
    }

    public get catalog(): TypeCatalog {
        const catalog = this.session.types;
        if (catalog === undefined) {
            throw new Error('Rows are built once the session is ready.');
        }

        return catalog;
    }

    public get api(): ApiClient {
        return this.settings.api;
    }

    public get editors(): PropertyEditors {
        return this.settings.editors;
    }

    public get entities(): EntityStore {
        return this.settings.entities;
    }

    public get collapse(): CollapseMemory {
        return this.settings.collapse;
    }

    public row(path: string): HTMLElement {
        const info = this.session.describe(path);
        if (info === undefined) {
            return Elements.create('div');
        }

        const row = new BuiltRow(this, info);
        this.rows.set(path, row);
        row.refresh();

        return row.element;
    }

    public fields(prefix: string, object: ObjectValue, join: string): HTMLElement {
        return Elements.create('div', {classes: ['properties']}, this.catalog.fieldsOf(object.type)
            .filter(({name, field}) => field.read && name in object.values)
            .map(({name}) => this.row(PropertyPath.child(prefix, join, name))));
    }

    public shownUnder(path: string): HTMLElement {
        return this.shownAt(this.visibility.childPaths(path)) ?? Elements.create('div', {classes: ['properties']});
    }

    /** The rows of the entity's `fields` the filter shows; null when it shows none. */
    public rootFields(fields: readonly CatalogField[]): HTMLElement | null {
        const readable = new Set(this.visibility.rootPaths());

        return this.shownAt(fields.map(({name}) => name).filter((name) => readable.has(name)));
    }

    /** Forgets every row: before the view shows everything again. */
    public clear(): void {
        this.rows.clear();
    }

    /** Stops following the session. */
    public dispose(): void {
        this.session.offChange(this.listener);
        this.rows.clear();
    }

    /** The rows of `paths` the filter shows; null when it shows none. */
    private shownAt(paths: readonly string[]): HTMLElement | null {
        const rows = paths.map((path) => this.shown(path)).filter((row): row is HTMLElement => row !== null);

        return rows.length === 0 ? null : Elements.create('div', {classes: ['properties']}, rows);
    }

    /** The row of the property at `path` as the filter shows it: in full, as a plain parent, or none. */
    private shown(path: string): HTMLElement | null {
        const visibility = this.visibility.of(path);
        const info = this.session.describe(path);
        if (visibility === 'hidden' || info === undefined) {
            return null;
        }

        if (visibility === 'full') {
            return this.row(path);
        }

        const row = new BuiltRow(this, info, true);
        this.rows.set(path, row);
        row.refresh();

        return row.element;
    }

    private changed(change: SessionChange): void {
        switch (change.kind) {
            case 'values':
                for (const path of change.paths) {
                    const row = this.rows.get(path);
                    if (row !== undefined && row !== change.source) {
                        this.rebuild(row);
                    }
                }
                this.refresh();
                break;
            case 'shape': {
                const row = this.rows.get(change.path);
                if (row !== undefined) {
                    this.rebuild(row);
                }
                this.refresh();
                break;
            }
            case 'state':
                this.refresh();
                break;
            case 'reloaded':
                this.rows.clear();
                this.reloaded();
                break;
        }
    }

    /** Builds `row` again in place, with what's under it. */
    private rebuild(row: BuiltRow): void {
        for (const path of [...this.rows.keys()]) {
            if (path === row.path || PropertyPath.isUnder(path, row.path)) {
                this.rows.delete(path);
            }
        }
        row.element.replaceWith(this.shown(row.path) ?? Elements.create('div'));
    }

    /** Marks every row again. */
    private refresh(): void {
        for (const row of this.rows.values()) {
            row.refresh();
        }
    }
}
