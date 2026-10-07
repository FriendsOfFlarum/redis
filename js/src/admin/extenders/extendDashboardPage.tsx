import { extend } from 'flarum/common/extend';
import type Mithril from 'mithril';

import DashboardPage from 'flarum/admin/components/DashboardPage';
import ItemList from 'flarum/common/utils/ItemList';
import RedisWidget from '../components/RedisWidget';

export default function extendDashboardPage() {
  extend(DashboardPage.prototype, 'availableWidgets', function (widgets: ItemList<Mithril.Children>) {
    // The server card (memory / ops / eviction) is its own concern, not a
    // queue duplicate, so it gets its own widget slot.
    widgets.add('fof-redis', <RedisWidget />, 12);
  });
}
