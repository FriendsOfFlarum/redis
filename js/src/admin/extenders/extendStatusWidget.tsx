import app from 'flarum/admin/app';
import { extend } from 'flarum/common/extend';
import StatusWidget from 'flarum/admin/components/StatusWidget';
import StatusWidgetItem from 'flarum/admin/components/StatusWidgetItem';

export default function extendStatusWidget() {
  extend(StatusWidget.prototype, 'items', function (items) {
    const cacheStore = app.data.cacheStore;
    const cacheVersion = app.data.cacheVersion;

    items.add('version-cache', <StatusWidgetItem icon="fas fa-layer-group" label="Cache store" value={`${cacheStore} ${cacheVersion}`} />, 75);
  });
}
