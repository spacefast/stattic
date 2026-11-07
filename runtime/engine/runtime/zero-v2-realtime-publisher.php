<?php
declare(strict_types=1);

// Loaded by _stattic_zero_v2_register_realtime after the selected Zero runtime is mounted.
// One request may commit many transactions. Their notices merge into one batch, sent once
// after the response; nothing is dropped, only collapsed (see _stattic_zero_v2_realtime_merge).
final class Spacefast_Zero_Realtime_Publisher implements \Zero\Realtime\Publisher
{
    /** @var array<string, array{ops: array<string, true>, ids: array<string, int|string>|null}> */
    private array $changes = [];
    private ?string $at = null;
    private bool $scheduled = false;

    /** @param array{url: string, token: string, space_id: string, version_id: string} $config */
    public function __construct(private readonly array $config)
    {
    }

    public function publish(array $notice): void
    {
        // One room serves every viewer of the Space: keep ids only where any visitor may list them.
        $notice = \Zero\Realtime\Module::shared($notice);
        $this->changes = _stattic_zero_v2_realtime_merge($this->changes, $notice['changes']);
        $this->at = $notice['at'];
        if ($this->scheduled) return;
        $this->scheduled = true;
        $send = function (): void {
            $this->scheduled = false;
            $bodies = _stattic_zero_v2_realtime_bodies($this->config, $this->changes, (string) $this->at);
            $this->changes = [];
            // One budget for the whole delivery, however many notices it takes.
            $deadline = microtime(true) + STATTIC_ZERO_V2_REALTIME_BUDGET_MS / 1000;
            foreach ($bodies as $body) {
                _stattic_zero_v2_realtime_post($this->config['url'], $this->config['token'], $body, $deadline);
            }
        };
        function_exists('_stattic_defer') ? _stattic_defer($send) : $send();
    }
}
