<?php
declare(strict_types=1);

// Byte sink for every ingress transport. Transports own only how bytes arrive;
// this sink owns the invariants: bounds, sha256 identity, prefix capture,
// complete writes, durable flush, failure cleanup.
function _stattic_runtime_stream_sink_open(
    string $path,
    int $limit,
    int $prefixBytes = 0,
    string $mode = 'x+b'
): object|false {
    $output = @fopen($path, $mode);
    if ($output === false) {
        return false;
    }
    return (object) [
        'path' => $path,
        'output' => $output,
        'context' => hash_init('sha256'),
        'limit' => $limit,
        'prefix_bytes' => max(0, $prefixBytes),
        'prefix' => '',
        'size' => 0,
        'reason' => null,
        'closed' => false,
    ];
}

function _stattic_runtime_stream_sink_write(object $sink, string $chunk): int|false
{
    if ($sink->closed || $sink->reason !== null) {
        return false;
    }
    $length = strlen($chunk);
    $nextSize = $sink->size + $length;
    if ($nextSize > $sink->limit) {
        $sink->size = $nextSize;
        $sink->reason = 'too_large';
        return false;
    }

    hash_update($sink->context, $chunk);
    if (strlen($sink->prefix) < $sink->prefix_bytes) {
        $sink->prefix .= substr($chunk, 0, $sink->prefix_bytes - strlen($sink->prefix));
    }
    $offset = 0;
    while ($offset < $length) {
        $written = fwrite($sink->output, substr($chunk, $offset));
        if ($written === false || $written === 0) {
            $sink->reason = 'output_write_failed';
            return false;
        }
        $offset += $written;
    }
    $sink->size = $nextSize;
    return $length;
}

function _stattic_runtime_stream_sink_finish(object $sink): array
{
    if (!$sink->closed) {
        if ($sink->reason === null && !@fflush($sink->output)) {
            $sink->reason = 'output_flush_failed';
        }
        if ($sink->reason === null && function_exists('fsync') && !@fsync($sink->output)) {
            $sink->reason = 'output_sync_failed';
        }
        @fclose($sink->output);
        $sink->closed = true;
    }
    if ($sink->reason !== null) {
        @unlink($sink->path);
        return [
            'ok' => false,
            'reason' => $sink->reason,
            'size' => $sink->size,
            'prefix' => $sink->prefix,
        ];
    }
    return [
        'ok' => true,
        'tmp_path' => $sink->path,
        'sha256' => hash_final($sink->context),
        'size' => $sink->size,
        'prefix' => $sink->prefix,
    ];
}

function _stattic_runtime_stream_sink_abort(object $sink, string $reason): array
{
    if ($sink->reason === null) {
        $sink->reason = $reason;
    }
    return _stattic_runtime_stream_sink_finish($sink);
}

// Drains $input into $path through a sink, and owns $input's lifecycle: the
// handle is closed on every exit, success or failure.
function _stattic_runtime_stream_to_path(
    string $path,
    mixed $input,
    int $limit,
    int $prefixBytes = 0
): array {
    $sink = _stattic_runtime_stream_sink_open($path, $limit, $prefixBytes);
    if ($sink === false) {
        return ['ok' => false, 'reason' => 'output_open_failed'];
    }
    if (!is_resource($input)) {
        return _stattic_runtime_stream_sink_abort($sink, 'input_open_failed');
    }
    while (!feof($input)) {
        $chunk = fread($input, 1048576);
        if ($chunk === false || ($chunk === '' && !feof($input))) {
            @fclose($input);
            return _stattic_runtime_stream_sink_abort($sink, 'input_read_failed');
        }
        if ($chunk === '') {
            break;
        }
        if (_stattic_runtime_stream_sink_write($sink, $chunk) === false) {
            @fclose($input);
            return _stattic_runtime_stream_sink_finish($sink);
        }
    }
    @fclose($input);
    return _stattic_runtime_stream_sink_finish($sink);
}
