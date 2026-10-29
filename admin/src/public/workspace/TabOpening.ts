/**
 * How Workspace.openTab() opens a tab not open yet:
 * - "reuse" shows it in the tab of the same type shown most recently,
 *   replacing what that tab showed; a new tab when there's none;
 * - "new" always opens another tab.
 * Either way, a tab of that type open with the same key already is shown
 * instead.
 */
export type TabOpening = 'reuse' | 'new';
