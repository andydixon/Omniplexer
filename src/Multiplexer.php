<?php

namespace Omniplexer;

use Psr\Log\LoggerInterface;
use Psr\Log\NullLogger;

class Multiplexer
{
    private array $servers = [];
    private LoggerInterface $logger;

    // Self-metrics
    private int $requestCount = 0;
    private int $errorCount = 0;
    private float $startTime;
    private array $serverStats = [];

    public function __construct(private string $configFile, ?LoggerInterface $logger = null) {
        $this->logger = $logger ?? new NullLogger();
        $this->loadConfig();
        $this->startTime = microtime(true);
    }

    private function loadConfig(): void {
        if (!file_exists($this->configFile)) {
            throw new \RuntimeException("Config file not found: {$this->configFile}");
        }

        $config = parse_ini_file($this->configFile, true);

        foreach ($config as $name => $serverConfig) {
            if (!isset($serverConfig['url'])) {
                throw new \InvalidArgumentException("Server '{$name}' is missing required 'url' parameter.");
            }

            $auth = null;
            if (isset($serverConfig['username'], $serverConfig['password'])) {
                $auth = 'Basic ' . base64_encode("{$serverConfig['username']}:{$serverConfig['password']}");
            } elseif (isset($serverConfig['bearer'])) {
                $auth = 'Bearer ' . $serverConfig['bearer'];
            }

            $this->servers[$name] = [
                'url' => rtrim($serverConfig['url'], '/'),
                'prefix' => $serverConfig['prefix'] ?? null,
                'auth' => $auth,
            ];

            $this->serverStats[$name] = [
                'requests' => 0,
                'errors' => 0,
                'total_response_time' => 0.0,
                'response_times' => [],
                'min_response_time' => null,
                'max_response_time' => null,
            ];
        }
    }

    public function handleRequest(): void {
        $this->requestCount++;
        $path = $_SERVER['REQUEST_URI'] ?? '/';

        if ($path === '/health') {
            $this->handleHealthCheck();
            return;
        }

        if ($path === '/self-metrics' || $path === '/metrics') {
            // Aggregated self metrics are handled separately.
            $this->handleSelfMetrics();
            return;
        }

        // Collect servers whose prefix matches the start of the path.
        $matchedServers = [];
        foreach ($this->servers as $name => $server) {
            if ($server['prefix'] && str_starts_with($path, '/' . $server['prefix'])) {
                $matchedServers[$name] = $server;
            }
        }

        if (!empty($matchedServers)) {
            // Assume all matched servers share the same prefix.
            $targetPrefix = current($matchedServers)['prefix'];
            $newPath = substr($path, strlen('/' . $targetPrefix));
            
            if (count($matchedServers) === 1) {
                // Exactly one match: proxy directly.
                $serverName = array_key_first($matchedServers);
                $this->proxyRequest($matchedServers[$serverName], $newPath, $serverName);
                return;
            } else {
                // Multiple servers share the same prefix; loop through them.
                $responses = $this->fetchTargetMetrics($matchedServers, $newPath);
                header('Content-Type: text/plain; version=0.0.4');
                echo implode("\n", $responses);
                return;
            }
        }

        // If no prefix matched, then do an aggregated fetch,
        // where the metrics will be transformed (prefixed) accordingly.
        $responses = $this->fetchAllMetrics($path);
        header('Content-Type: text/plain; version=0.0.4');
        echo implode("\n", $responses);
    }

    private function fetchTargetMetrics(array $servers, string $path): array {
        $multiHandle = curl_multi_init();
        $curlHandles = [];
        $responses = [];

        foreach ($servers as $name => $server) {
            $ch = curl_init();
            curl_setopt_array($ch, [
                CURLOPT_URL => $server['url'] . $path,
                CURLOPT_RETURNTRANSFER => true,
                CURLOPT_TIMEOUT => 5,
                CURLOPT_FAILONERROR => false,
                CURLOPT_FOLLOWLOCATION => true,
            ]);
            if ($server['auth']) {
                curl_setopt($ch, CURLOPT_HTTPHEADER, [
                    'Authorization: ' . $server['auth'],
                ]);
            }
            $curlHandles[(int)$ch] = ['handle' => $ch, 'name' => $name, 'start_time' => microtime(true)];
            curl_multi_add_handle($multiHandle, $ch);
        }

        $running = null;
        do {
            curl_multi_exec($multiHandle, $running);
            curl_multi_select($multiHandle);
        } while ($running > 0);

        foreach ($curlHandles as $chInfo) {
            $ch = $chInfo['handle'];
            $name = $chInfo['name'];
            $startTime = $chInfo['start_time'];
            $elapsedTime = (microtime(true) - $startTime) * 1000;
            $this->recordResponseTime($name, $elapsedTime);

            $content = curl_multi_getcontent($ch);
            $statusCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);

            if (curl_errno($ch)) {
                $this->logger->error('Curl error', ['server' => $name, 'error' => curl_error($ch)]);
                $this->errorCount++;
                $this->serverStats[$name]['errors']++;
            } elseif ($statusCode >= 400) {
                $this->logger->error('HTTP error', ['server' => $name, 'status' => $statusCode]);
                $this->errorCount++;
                $this->serverStats[$name]['errors']++;
            } else {
                // In targeted mode, you might simply append a header comment to separate the responses.
                $responses[] = "# Metrics from {$name}\n" . $content;
            }
            curl_multi_remove_handle($multiHandle, $ch);
            curl_close($ch);
        }
        curl_multi_close($multiHandle);

        return $responses;
    }


    private function handleHealthCheck(): void
    {
        header('Content-Type: application/json');
        echo json_encode(['status' => 'ok']);
    }

    private function handleSelfMetrics(): void
    {
        header('Content-Type: text/plain; version=0.0.4');
        echo "# Self metrics for Omniplexer\n";
        echo "omniplexer_requests_total {$this->requestCount}\n";
        echo "omniplexer_errors_total {$this->errorCount}\n";
        echo "omniplexer_uptime_seconds " . (int)(microtime(true) - $this->startTime) . "\n";

        foreach ($this->serverStats as $name => $stats) {
            $requests = $stats['requests'];
            $errors = $stats['errors'];
            $avgResponseTime = $requests > 0 ? ($stats['total_response_time'] / $requests) : 0;

            printf("omniplexer_server_requests_total{server=\"%s\"} %d\n", $name, $requests);
            printf("omniplexer_server_errors_total{server=\"%s\"} %d\n", $name, $errors);
            printf("omniplexer_server_avg_response_time_msecs{server=\"%s\"} %.3f\n", $name, $avgResponseTime);

            $successRate = $requests > 0 ? (($requests - $errors) / $requests) * 100 : 100;
            printf("omniplexer_server_success_rate_percent{server=\"%s\"} %.2f\n", $name, $successRate);

            if (!empty($stats['response_times'])) {
                sort($stats['response_times']);
                $count = count($stats['response_times']);

                $percentile = function (float $percentile) use ($stats, $count) {
                    $index = (int) ceil($percentile * $count) - 1;
                    return $stats['response_times'][max(0, min($count - 1, $index))];
                };

                printf("omniplexer_server_response_time_p50_msecs{server=\"%s\"} %.3f\n", $name, $percentile(0.50));
                printf("omniplexer_server_response_time_p95_msecs{server=\"%s\"} %.3f\n", $name, $percentile(0.95));
                printf("omniplexer_server_response_time_p99_msecs{server=\"%s\"} %.3f\n", $name, $percentile(0.99));

                printf("omniplexer_server_min_response_time_msecs{server=\"%s\"} %.3f\n", $name, $stats['min_response_time']);
                printf("omniplexer_server_max_response_time_msecs{server=\"%s\"} %.3f\n", $name, $stats['max_response_time']);
            }
        }
    }

    private function proxyRequest(array $server, string $path, string $serverName): void
    {
        $response = $this->fetchMetrics($server, $path, $serverName);

        if ($response === null) {
            http_response_code(502);
            echo "# Error fetching metrics from server";
            $this->errorCount++;
            $this->serverStats[$serverName]['errors']++;
            return;
        }

        header('Content-Type: text/plain; version=0.0.4');
        echo $response;
    }

    private function fetchAllMetrics(string $path): array
    {
        $multiHandle = curl_multi_init();
        $curlHandles = [];
        $responses = [];

        foreach ($this->servers as $name => $server) {
            $ch = curl_init();

            curl_setopt_array($ch, [
                CURLOPT_URL => $server['url'] . $path,
                CURLOPT_RETURNTRANSFER => true,
                CURLOPT_TIMEOUT => 5,
                CURLOPT_FAILONERROR => false,
                CURLOPT_FOLLOWLOCATION => true,
            ]);

            if ($server['auth']) {
                curl_setopt($ch, CURLOPT_HTTPHEADER, [
                    'Authorization: ' . $server['auth'],
                ]);
            }

            $curlHandles[(int)$ch] = ['handle' => $ch, 'name' => $name, 'start_time' => microtime(true)];
            curl_multi_add_handle($multiHandle, $ch);
        }

        $running = null;
        do {
            curl_multi_exec($multiHandle, $running);
            curl_multi_select($multiHandle);
        } while ($running > 0);

        foreach ($curlHandles as $chInfo) {
            $ch = $chInfo['handle'];
            $name = $chInfo['name'];
            $startTime = $chInfo['start_time'];
            $elapsedTime = (microtime(true) - $startTime) * 1000;

            $this->recordResponseTime($name, $elapsedTime);

            $content = curl_multi_getcontent($ch);
            $statusCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);

            if (curl_errno($ch)) {
                $this->logger->error('Curl error', ['server' => $name, 'error' => curl_error($ch)]);
                $this->errorCount++;
                $this->serverStats[$name]['errors']++;
            } elseif ($statusCode >= 400) {
                $this->logger->error('HTTP error', ['server' => $name, 'status' => $statusCode]);
                $this->errorCount++;
                $this->serverStats[$name]['errors']++;
            } else {
                
                // If a prefix is set and it’s an aggregated (all-metrics) request, transform the metrics:
                if ($server['prefix']) {
                    $content = $this->transformMetrics($content, $server['prefix']);
                }
                $responses[] = "# Metrics from {$name}\n" . $content;
            }

            curl_multi_remove_handle($multiHandle, $ch);
            curl_close($ch);
        }

        curl_multi_close($multiHandle);

        return $responses;
    }

    private function fetchMetrics(array $server, string $path, string $serverName): ?string
    {
        $ch = curl_init();

        curl_setopt_array($ch, [
            CURLOPT_URL => $server['url'] . $path,
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_TIMEOUT => 5,
            CURLOPT_FAILONERROR => false,
            CURLOPT_FOLLOWLOCATION => true,
        ]);

        if ($server['auth']) {
            curl_setopt($ch, CURLOPT_HTTPHEADER, [
                'Authorization: ' . $server['auth'],
            ]);
        }

        $startTime = microtime(true);
        $response = curl_exec($ch);
        $elapsedTime = (microtime(true) - $startTime) * 1000;

        $this->recordResponseTime($serverName, $elapsedTime);

        if (curl_errno($ch)) {
            $this->logger->error('Curl error', ['error' => curl_error($ch)]);
            $this->errorCount++;
            $this->serverStats[$serverName]['errors']++;
            curl_close($ch);
            return null;
        }

        $statusCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);

        if ($statusCode >= 400) {
            $this->logger->error('HTTP error', ['status' => $statusCode]);
            $this->errorCount++;
            $this->serverStats[$serverName]['errors']++;
            return null;
        }

        return $response;
    }

    private function recordResponseTime(string $serverName, float $elapsedTime): void
    {
        $stats = &$this->serverStats[$serverName];
        $stats['requests']++;
        $stats['total_response_time'] += $elapsedTime;
        $stats['response_times'][] = $elapsedTime;

        if ($stats['min_response_time'] === null || $elapsedTime < $stats['min_response_time']) {
            $stats['min_response_time'] = $elapsedTime;
        }

        if ($stats['max_response_time'] === null || $elapsedTime > $stats['max_response_time']) {
            $stats['max_response_time'] = $elapsedTime;
        }
    }

    private function transformMetrics(string $content, string $prefix): string {
    $lines = explode("\n", $content);
    $result = [];
    
    foreach ($lines as $line) {
        $trimmedLine = trim($line);
        if ($trimmedLine === '') {
            continue;
        }
        // Handle Prometheus metadata lines
        if (str_starts_with($trimmedLine, '# TYPE ') ||
            str_starts_with($trimmedLine, '# HELP ')) {
            // Split into parts: e.g., "# TYPE metricName ..." or "# HELP metricName ..."
            $parts = explode(' ', $trimmedLine, 3);
            if (count($parts) >= 3) {
                $parts[2] = $prefix . '_' . $parts[2];
                $result[] = implode(' ', $parts);
                continue;
            }
        }
        // Leave other comment lines unchanged.
        if (str_starts_with($trimmedLine, '#')) {
            $result[] = $line;
            continue;
        }
        // For metric lines, capture the metric name at the very start.
        // We assume the metric name is the first token.
        if (preg_match('/^([a-zA-Z_:][a-zA-Z0-9_:]*)/', $line, $matches)) {
            $originalName = $matches[1];
            $newName = $prefix . '_' . $originalName;
            // Replace only the first occurrence (the metric name)
            $line = preg_replace('/^' . preg_quote($originalName, '/') . '/', $newName, $line, 1);
        }
        $result[] = $line;
    }
    return implode("\n", $result);
}

}