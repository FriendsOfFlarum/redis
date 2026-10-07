import app from 'flarum/admin/app';

export interface RedisStats {
  memory_used: string;
  memory_used_bytes: number;
  memory_peak: string;
  memory_max: string;
  memory_max_bytes: number;
  memory_percentage: number | null;
  eviction_policy: string;
  ops_per_sec: number;
  connected_clients: number;
  blocked_clients: number;
}

interface StatsResponse {
  redis: RedisStats;
  timestamp: number;
}

/**
 * The dashboard's Redis card's data, loaded when the card is created and
 * again when its refresh button is pressed.
 */
class StatsStore {
  data: StatsResponse | null = null;
  loading = false;
  error: string | null = null;

  async load(): Promise<void> {
    this.loading = true;
    this.error = null;
    m.redraw();

    try {
      this.data = await app.request<StatsResponse>({
        method: 'GET',
        url: app.forum.attribute('adminUrl') + '/redis/api/stats',
      });
    } catch (error: any) {
      this.error = error?.response?.error || app.translator.trans('fof-redis.admin.stats.error.fetch_failed', {}, true);
    }

    this.loading = false;
    m.redraw();
  }
}

export default new StatsStore();
