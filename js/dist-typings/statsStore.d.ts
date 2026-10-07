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
declare class StatsStore {
    data: StatsResponse | null;
    loading: boolean;
    error: string | null;
    load(): Promise<void>;
}
declare const _default: StatsStore;
export default _default;
