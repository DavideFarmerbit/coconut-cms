import type {Container} from '../model/Container.ts';
import type {LayoutStore} from '../model/LayoutStore.ts';
import type {SizeResolver} from '../model/SizeResolver.ts';
import type {DragRange, SplitterTarget} from './SplitterTarget.ts';
import type {WorkspaceArea} from './WorkspaceArea.ts';

/**
 * A container's edge on the canvas side, as a splitter drags it: from the
 * size shown, up to what the canvas's minimum leaves. Released, the size
 * becomes the container's preferred one.
 */
export class ContainerEdge implements SplitterTarget {
    public readonly axis: 'x' | 'y';
    public readonly direction: 1 | -1;

    public constructor(
        private readonly container: Container,
        private readonly store: LayoutStore,
        private readonly resolver: SizeResolver,
        private readonly workspace: WorkspaceArea,
    ) {
        this.axis = container === 'bottom' ? 'y' : 'x';
        // The left edge grows rightward; the right one leftward, the bottom one upward.
        this.direction = container === 'left' ? 1 : -1;
    }

    public begin(): DragRange {
        const area = this.workspace.area();
        const layout = this.store.current;

        return {
            value: this.resolver.resolve(layout, area)[this.container],
            min: this.resolver.minimum,
            max: this.resolver.maximumOf(this.container, layout, area),
        };
    }

    public move(value: number): void {
        this.workspace.showSize(this.container, value);
    }

    public commit(value: number): void {
        this.store.update(this.store.current.resized(this.container, value));
    }

    public cancel(): void {
        this.workspace.render(this.store.current);
    }
}
