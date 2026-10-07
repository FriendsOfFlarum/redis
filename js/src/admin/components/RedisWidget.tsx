import app from 'flarum/admin/app';
import DashboardWidget, { IDashboardWidgetAttrs } from 'flarum/admin/components/DashboardWidget';
import LoadingIndicator from 'flarum/common/components/LoadingIndicator';
import Button from 'flarum/common/components/Button';
import ItemList from 'flarum/common/utils/ItemList';
import Tooltip from 'flarum/common/components/Tooltip';
import Icon from 'flarum/common/components/Icon';
import type Mithril from 'mithril';
import statsStore from '../statsStore';
import { statTile } from '../statsView';

export default class RedisWidget extends DashboardWidget {
  oncreate(vnode: Mithril.VnodeDOM<IDashboardWidgetAttrs, this>) {
    super.oncreate(vnode);
    statsStore.load();
  }

  className() {
    return 'RedisWidget RedisWidget--redis';
  }

  content() {
    const { data, error } = statsStore;
    const redis = data?.redis;

    // Eviction policies like allkeys-lru are a perfectly reasonable choice
    // for a Flarum cache store. Only warn when the policy can evict AND the
    // server is actually approaching its memory limit.
    const underPressure = redis && redis.eviction_policy !== 'noeviction' && redis.memory_percentage !== null && redis.memory_percentage > 75;

    // AdminContent exposes the store type and version (the status widget uses
    // the same data), so the heading says what actually runs — e.g.
    // "Valkey 9.0.1", not "Redis".
    const storeType = (app.data.cacheStore as string | undefined) || '';
    const storeVersion = (app.data.cacheVersion as string | undefined) || '';
    const serverTitle = storeType ? storeType.charAt(0).toUpperCase() + storeType.slice(1) : app.translator.trans('fof-redis.admin.stats.kv_heading');

    return (
      <div className="RedisWidget-body">
        <div className="RedisWidget-header">
          <h3 className="RedisWidget-title">
            <Icon name="fas fa-database" /> {serverTitle}
            {storeVersion && <span className="RedisWidget-version">{storeVersion}</span>}
            {underPressure && (
              <Tooltip
                text={app.translator.trans('fof-redis.admin.stats.eviction_warning', {
                  policy: redis.eviction_policy,
                  usage: redis.memory_percentage,
                })}
              >
                <span className="RedisWidget-pill RedisWidget-pill--warning">
                  <Icon name="fas fa-exclamation-triangle" /> {app.translator.trans('fof-redis.admin.stats.eviction_pressure')}
                </span>
              </Tooltip>
            )}
          </h3>
          <div className="RedisWidget-headerActions">{this.headerActions().toArray()}</div>
        </div>

        {error && <div className="RedisWidget-error">{error}</div>}
        {!error && !redis && <LoadingIndicator />}
        {!error && redis && (
          <div className="RedisWidget-tiles">
            {statTile(
              'memory-used',
              'fof-redis.admin.stats.data.memory-used',
              redis.memory_used,
              redis.memory_percentage !== null ? `${redis.memory_percentage}%` : undefined
            )}
            {statTile('memory-peak', 'fof-redis.admin.stats.data.memory-peak', redis.memory_peak)}
            {statTile('memory-max', 'fof-redis.admin.stats.data.memory-max', redis.memory_max)}
            <Tooltip text={app.translator.trans('fof-redis.admin.stats.eviction_policy_tooltip')}>
              <a
                className="RedisWidget-tile RedisWidget-tile--eviction-policy"
                href="https://redis.io/docs/latest/develop/reference/eviction/"
                target="_blank"
                rel="noopener noreferrer"
              >
                <div className="RedisWidget-tileLabel">{app.translator.trans('fof-redis.admin.stats.data.eviction-policy')}</div>
                <div className="RedisWidget-tileValue">{redis.eviction_policy}</div>
              </a>
            </Tooltip>
            {statTile('ops-per-sec', 'fof-redis.admin.stats.data.ops-per-sec', redis.ops_per_sec)}
            {statTile('connected-clients', 'fof-redis.admin.stats.data.connected-clients', redis.connected_clients)}
            {statTile('blocked-clients', 'fof-redis.admin.stats.data.blocked-clients', redis.blocked_clients)}
          </div>
        )}
      </div>
    );
  }

  /**
   * Controls on the right of the header. Ships with the refresh button.
   */
  headerActions(): ItemList<Mithril.Children> {
    const items = new ItemList<Mithril.Children>();

    items.add(
      'refresh',
      <Button
        className="Button Button--icon Button--flat"
        icon="fas fa-sync-alt"
        loading={statsStore.loading}
        onclick={() => statsStore.load()}
        aria-label={app.translator.trans('fof-redis.admin.stats.refresh_button')}
      />,
      100
    );

    return items;
  }
}
