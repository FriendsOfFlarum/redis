import type Mithril from 'mithril';
/**
 * A single labeled stat tile. `labelKey` is the full translator key so every
 * label greps to its use site; `sub` carries context such as a percentage.
 */
export declare function statTile(key: string, labelKey: string, value: Mithril.Children, sub?: Mithril.Children, href?: string): Mithril.Children;
