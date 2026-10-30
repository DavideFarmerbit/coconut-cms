import type {View} from '../app/View.ts';
import {Elements} from '../dom/Elements.ts';
import type {AdminRuntime} from '../public/AdminRuntime.ts';
import type {User} from '../public/auth/User.ts';
import type {TabTypeId} from '../public/workspace/Ids.ts';
import type {JsonValue} from '../public/workspace/Json.ts';
import type {TabOpening} from '../public/workspace/TabOpening.ts';
import type {Workspace} from '../public/workspace/Workspace.ts';
import {Layout} from './model/Layout.ts';
import type {LayoutListener} from './model/LayoutListener.ts';
import {LayoutStore} from './model/LayoutStore.ts';
import {LocalLayoutStorage} from './model/LocalLayoutStorage.ts';
import {TabIds} from './model/TabIds.ts';
import {DirtyViews} from './ui/DirtyViews.ts';
import {MenuBar, type SignOutRequested} from './ui/MenuBar.ts';
import {Stripe} from './ui/Stripe.ts';
import {ViewContexts} from './ui/ViewContexts.ts';
import {ViewHost} from './ui/ViewHost.ts';
import {WorkspaceArea} from './ui/WorkspaceArea.ts';

/**
 * The signed-in admin: the menubar over the stripes and the workspace
 * between them. Holds the layout store; every change of the layout reaches
 * each part, which shows its own slice of it. The layout saved for this user
 * is reconciled with the tools and tab types registered now.
 */
export class Shell implements View, LayoutListener, Workspace {
    public readonly element: HTMLElement;
    private readonly store: LayoutStore;
    private readonly stripes: readonly [Stripe, Stripe];
    private readonly workspace: WorkspaceArea;
    private readonly host: ViewHost;

    /** Which views have unsaved changes. */
    private readonly dirty = new DirtyViews();

    /** Asks the browser to confirm leaving the page while there's unsaved work. */
    private readonly beforeUnload: (event: BeforeUnloadEvent) => void;

    /** @param onSignOut Told when the user asks to sign out. */
    public constructor(runtime: AdminRuntime, user: User, onSignOut: SignOutRequested) {
        const storage = new LocalLayoutStorage(user.id);
        const placed = runtime.tools.all().map((tool) => ({id: tool.id, placement: tool.placement}));
        this.store = new LayoutStore((storage.load() ?? Layout.initial()).reconciled(placed, runtime.tabTypes.ids()), storage);

        const parking = Elements.create('div', {classes: ['view-parking'], attributes: {'aria-hidden': 'true'}});
        this.host = new ViewHost(parking);
        const contexts = new ViewContexts(runtime, this.store, this, this.dirty);
        this.stripes = [new Stripe('left', runtime.tools, this.store), new Stripe('right', runtime.tools, this.store)];
        this.workspace = new WorkspaceArea(runtime.tools, runtime.tabTypes, this.store, this.host, contexts, this.dirty);
        this.dirty.onChange(() => this.render(this.store.current));
        // Unsaved work isn't left by accident: the browser asks first.
        const entities = runtime.entities;
        this.beforeUnload = (event) => {
            if (this.dirty.any || entities.hasUnsaved) {
                event.preventDefault();
            }
        };
        window.addEventListener('beforeunload', this.beforeUnload);

        this.element = Elements.create('div', {classes: ['shell']}, [
            new MenuBar(user, onSignOut).element,
            Elements.create('div', {classes: ['shell__body']}, [this.stripes[0].element, this.workspace.element, this.stripes[1].element]),
            parking,
        ]);

        this.store.subscribe(this);
        this.render(this.store.current);
    }

    public shown(): void {
    }

    /** Disposes of every view, which lets their sessions go, and stops asking before the page is left: once its user signed out. */
    public dispose(): void {
        window.removeEventListener('beforeunload', this.beforeUnload);
        this.host.disposeAll();
    }

    public layoutChanged(_previous: Layout, next: Layout): void {
        this.render(next);
    }

    public openTab(type: TabTypeId, title: string, params: JsonValue, key: string, opening: TabOpening): void {
        // A reusing open never replaces a tab with unsaved work.
        const keep = new Set(this.store.current.tabs.filter((tab) => this.dirty.has(Layout.tabKey(tab.id))).map((tab) => tab.id));
        this.store.update(this.store.current.openTab(TabIds.next(), type, title, params, key, opening, keep));
    }

    /** Shows `layout` in every part. */
    private render(layout: Layout): void {
        for (const stripe of this.stripes) {
            stripe.render(layout);
        }

        this.workspace.render(layout);
    }
}
