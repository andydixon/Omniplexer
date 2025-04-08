# Omniplexer

> Multiplex and proxy multiple OpenMetrics endpoints into a unified, observable endpoint.

Omniplexer is a PSR-4 compliant PHP application that takes multiple upstream OpenMetrics servers and:
- Proxies based on configured prefixes
- Aggregates multiple metrics endpoints
- Exposes its own health and internal performance metrics
- Supports both Basic and Bearer authentication
- Exposes metrics on `/metrics` or `/self-metrics`
- Includes parallel fetching for efficiency

---

## Installation

1. Clone the repository.
2. Install dependencies:

```bash
composer install
```

3. Configure your metrics servers in `config.ini` (see below).

4. Serve the application:

```bash
php -S localhost:8000 index.php
```

If you're using Apache, make sure to include the recommended `.htaccess` file.

---

## Configuration (`config.ini`)

You can specify multiple servers. Each server requires at least a `url`.

```ini
[server1]
url = "http://metrics-server-1.local:9090/metrics"
prefix = "srvr1_"            ; Optional - for direct proxying (Prefixes metrics with this string)
username = "user1"            ; Optional - Basic Auth username
password = "pass1"            ; Optional - Basic Auth password
bearer = "token123"           ; Optional - Bearer token auth

[server2]
url = "http://metrics-server-2.local:9090/metrics"
; No prefix means this server will be included in aggregation
```

**Notes:**
- Either **Basic** (username & password) **or** **Bearer** authentication can be used per server.
- Prefixes allow you to call `/server1/...` to proxy directly to server1, stripping the prefix.
- No prefix? It will be aggregated!

---

## Endpoints

| Endpoint           | Description                                   |
|-------------------|------------------------------------------------|
| `/metrics`        | Self-metrics, Prometheus scrape compatible     |
| `/self-metrics`   | Alias to `/metrics`                            |
| `/health`         | Simple health check                            |
| `/server-prefix/*`| Proxies to the server with matching prefix     |
| `/`               | Aggregates metrics from all configured servers |

---

## Built-in Metrics (Omniplexer self-monitoring)

All metrics are Prometheus-friendly.

### Global Metrics

| Metric | Description |
|--------|-------------|
| `omniplexer_requests_total` | Total number of HTTP requests handled |
| `omniplexer_errors_total` | Total number of errors across all servers |
| `omniplexer_uptime_seconds` | Process uptime in seconds |

### Per-Server Metrics (Label: `server="name"`)

| Metric | Description |
|--------|-------------|
| `omniplexer_server_requests_total{server="name"}` | Requests routed to this server |
| `omniplexer_server_errors_total{server="name"}` | Errors encountered for this server |
| `omniplexer_server_success_rate_percent{server="name"}` | Successful requests percentage |
| `omniplexer_server_avg_response_time_msecs{server="name"}` | Average response time in ms |
| `omniplexer_server_min_response_time_msecs{server="name"}` | Fastest response time recorded |
| `omniplexer_server_max_response_time_msecs{server="name"}` | Slowest response time recorded |
| `omniplexer_server_response_time_p50_msecs{server="name"}` | 50th percentile (median) response time |
| `omniplexer_server_response_time_p95_msecs{server="name"}` | 95th percentile response time |
| `omniplexer_server_response_time_p99_msecs{server="name"}` | 99th percentile response time |

---

## Logging

By default, Omniplexer uses PSR-3 logging. You can inject your own PSR-3 compatible logger (e.g., Monolog).

Errors and cURL issues are logged with context for observability.

---

## Roadmap / Ideas 

- [ ] Histogram buckets for response times
- [ ] Environment or region labels for multi-cluster deployments
- [ ] Graceful shutdown & signal handling

---

## Contributions

Feel free to contribute! Forks and pull requests are welcome.

---

## License

GPLv3 - see the `LICENSE` file for more information.

---

Enjoy!

If you have questions or improvements, open an issue or drop a message.

---
