import { Counter, Gauge, Histogram, Registry, collectDefaultMetrics } from 'prom-client';

/**
 * A single registry instance per process. Exposed on /metrics for Prometheus.
 * Deliberately small: connection counts, delivery counts and failure counts
 * answer "is fan-out healthy?" without cardinality explosions (no per-user or
 * per-incident labels).
 */
export const registry = new Registry();

collectDefaultMetrics({ register: registry, prefix: 'incidentflow_realtime_' });

export const connectionsGauge = new Gauge({
  name: 'incidentflow_realtime_connections',
  help: 'Currently open client connections',
  labelNames: ['transport'] as const,
  registers: [registry],
});

export const connectionsTotal = new Counter({
  name: 'incidentflow_realtime_connections_total',
  help: 'Client connections accepted since start',
  labelNames: ['transport'] as const,
  registers: [registry],
});

export const connectionsRejected = new Counter({
  name: 'incidentflow_realtime_connections_rejected_total',
  help: 'Client connections rejected',
  labelNames: ['transport', 'reason'] as const,
  registers: [registry],
});

export const subscriptionsGauge = new Gauge({
  name: 'incidentflow_realtime_subscriptions',
  help: 'Active (connection, topic) subscription pairs',
  registers: [registry],
});

export const redisChannelsGauge = new Gauge({
  name: 'incidentflow_realtime_redis_channels',
  help: 'Redis channels this node is currently subscribed to',
  registers: [registry],
});

export const eventsReceived = new Counter({
  name: 'incidentflow_realtime_events_received_total',
  help: 'Events received from Redis pub/sub',
  labelNames: ['outcome'] as const,
  registers: [registry],
});

export const eventsDelivered = new Counter({
  name: 'incidentflow_realtime_events_delivered_total',
  help: 'Event deliveries written to client connections',
  labelNames: ['transport'] as const,
  registers: [registry],
});

export const deliveryFailures = new Counter({
  name: 'incidentflow_realtime_delivery_failures_total',
  help: 'Deliveries that failed and closed the connection',
  labelNames: ['transport', 'reason'] as const,
  registers: [registry],
});

export const authFailures = new Counter({
  name: 'incidentflow_realtime_auth_failures_total',
  help: 'Rejected authentication attempts',
  labelNames: ['reason'] as const,
  registers: [registry],
});

export const httpRequestDuration = new Histogram({
  name: 'incidentflow_realtime_http_request_duration_seconds',
  help: 'HTTP request duration',
  labelNames: ['method', 'route', 'status'] as const,
  buckets: [0.005, 0.01, 0.025, 0.05, 0.1, 0.25, 0.5, 1, 2.5, 5],
  registers: [registry],
});
