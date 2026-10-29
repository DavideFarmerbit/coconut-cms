import {tabId, type TabId} from '../../public/workspace/Ids.ts';

/**
 * Makes new tabs' ids: random, so ids saved in a layout never clash with
 * new ones. Uses getRandomValues(), which works on any page; randomUUID()
 * needs https.
 */
export class TabIds {
    /** How many random bytes an id holds. */
    private static readonly BYTES = 12;

    /** A new id. */
    public static next(): TabId {
        const bytes = crypto.getRandomValues(new Uint8Array(TabIds.BYTES));

        return tabId(Array.from(bytes, (byte) => byte.toString(16).padStart(2, '0')).join(''));
    }
}
