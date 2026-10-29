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
import {BuiltRow} from './BuiltRow.ts';
import type {RowServices} from './RowServices.ts';

/** The admin's parts a tree's rows use. */
export interface RowSettings {
    readonly api: ApiClient;
    readonly editors: PropertyEditors;
    readonly entities: EntityStore;
    readonly collapse: CollapseMemory;
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
 * A view disposes of its tree when it goes.
 */
export class RowTree implements RowServices {
    private readonly rows = new Map<string, BuiltRow>();
    private readonly listener = (change: SessionChange): void => this.changed(change);

    public constructor(
        public readonly session: EntitySession,
        private readonly settings: RowSettings,
        private readonly reloaded: Reloaded,
    ) {
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
        return this.list(this.catalog.fieldsOf(object.type), object.values, (name) => PropertyPath.child(prefix, join, name));
    }

    /** The rows of the entity's `fields`, those the user may read. */
    public rootFields(fields: readonly CatalogField[]): HTMLElement {
        return this.list(fields, this.session.values, (name) => name);
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

    /** The rows of `fields` the user may read, and `values` hold, each at the path `pathOf` gives. */
    private list(fields: readonly CatalogField[], values: Readonly<Record<string, unknown>>, pathOf: (name: string) => string): HTMLElement {
        return Elements.create('div', {classes: ['properties']}, fields
            .filter(({name, field}) => field.read && name in values)
            .map(({name}) => this.row(pathOf(name))));
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
        row.element.replaceWith(this.row(row.path));
    }

    /** Marks every row again. */
    private refresh(): void {
        for (const row of this.rows.values()) {
            row.refresh();
        }
    }
}
