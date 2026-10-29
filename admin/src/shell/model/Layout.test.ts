import {describe, expect, it} from 'vitest';
import {tabId, tabTypeId, toolId} from '../../public/workspace/Ids.ts';
import {Layout, type PlacedTool} from './Layout.ts';

const types = toolId('coconut-cms:types');
const entities = toolId('coconut-cms:entities');
const log = toolId('app:log');
const typeTab = tabTypeId('coconut-cms:type');

const tools: PlacedTool[] = [
    {id: types, placement: {side: 'left', section: 'topTop'}},
    {id: entities, placement: {side: 'left', section: 'topTop'}},
    {id: log, placement: {side: 'right', section: 'bottom'}},
];

/** The initial layout with `tools` placed. */
function placed(): Layout {
    return Layout.initial().reconciled(tools, new Set([typeTab]));
}

describe('Layout', () => {
    it('places a new tool where it asks, after what is there', () => {
        const layout = placed();

        expect(layout.toolsIn('left', 'topTop')).toEqual([types, entities]);
        expect(layout.placementOf(log)).toEqual({side: 'right', section: 'bottom'});
        expect(layout.isContainerOpen('left')).toBe(false);
    });

    it('opens one tool per slot, toggling it closed', () => {
        let layout = placed().toggleTool(types);
        expect(layout.isOpen(types)).toBe(true);
        expect(layout.isContainerOpen('left')).toBe(true);

        layout = layout.toggleTool(entities);
        expect(layout.isOpen(entities)).toBe(true);
        expect(layout.isOpen(types)).toBe(false);

        layout = layout.toggleTool(entities);
        expect(layout.isContainerOpen('left')).toBe(false);
    });

    it('puts both bottom sections in the bottom container', () => {
        const layout = placed().toggleTool(log);

        expect(layout.isContainerOpen('bottom')).toBe(true);
        expect(layout.isContainerOpen('right')).toBe(false);
    });

    it('focuses an open tab of the same type and key instead of opening another', () => {
        let layout = placed().openTab(tabId('1'), typeTab, 'Car', {type: 'shop:car'}, 'shop:car');
        layout = layout.openTab(tabId('2'), typeTab, 'Maker', {type: 'shop:maker'}, 'shop:maker');
        layout = layout.openTab(tabId('3'), typeTab, 'Car', {type: 'shop:car'}, 'shop:car');

        expect(layout.tabs.map((tab) => tab.id)).toEqual(['1', '2']);
        expect(layout.activeTab).toBe('1');
    });

    it('closing the shown tab shows the one after it, or before it, and forgets its state', () => {
        let layout = placed();
        for (const id of ['1', '2', '3']) {
            layout = layout.openTab(tabId(id), typeTab, id, null, id);
        }
        layout = layout.activateTab(tabId('2')).withViewState(Layout.tabKey(tabId('2')), 'scroll', 40);

        layout = layout.closeTab(tabId('2'));
        expect(layout.activeTab).toBe('3');
        expect(layout.stateOf(Layout.tabKey(tabId('2')))).toEqual({});

        layout = layout.closeTab(tabId('3'));
        expect(layout.activeTab).toBe('1');
        expect(layout.closeTab(tabId('1')).activeTab).toBeNull();
    });

    it('reconciling drops what is no longer registered', () => {
        let layout = placed().toggleTool(entities).withViewState(Layout.toolKey(entities), 'selected', 'shop:car');
        layout = layout.openTab(tabId('1'), typeTab, 'Car', null, 'car');

        const reconciled = layout.reconciled(tools.filter((tool) => tool.id !== entities), new Set());

        expect(reconciled.toolsIn('left', 'topTop')).toEqual([types]);
        expect(reconciled.isContainerOpen('left')).toBe(false);
        expect(reconciled.tabs).toEqual([]);
        expect(reconciled.activeTab).toBeNull();
        expect(reconciled.stateOf(Layout.toolKey(entities))).toEqual({});
    });

    it('keeps sizes and splits within their bounds', () => {
        const layout = placed().resized('left', -20.4).split('bottom', 1.7);

        expect(layout.sizeOf('left')).toBe(0);
        expect(layout.splitOf('bottom')).toBe(1);
    });

    it('reads back only a layout of its own version', () => {
        const layout = placed().toggleTool(types);

        expect(Layout.fromData(JSON.parse(JSON.stringify(layout.toData())))?.isOpen(types)).toBe(true);
        expect(Layout.fromData({...layout.toData(), version: 0})).toBeNull();
        expect(Layout.fromData('nope')).toBeNull();
    });
});
