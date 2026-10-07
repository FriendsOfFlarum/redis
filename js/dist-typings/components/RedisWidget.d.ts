import DashboardWidget, { IDashboardWidgetAttrs } from 'flarum/admin/components/DashboardWidget';
import ItemList from 'flarum/common/utils/ItemList';
import type Mithril from 'mithril';
export default class RedisWidget extends DashboardWidget {
    oncreate(vnode: Mithril.VnodeDOM<IDashboardWidgetAttrs, this>): void;
    className(): string;
    content(): JSX.Element;
    /**
     * Controls on the right of the header. Ships with the refresh button.
     */
    headerActions(): ItemList<Mithril.Children>;
}
