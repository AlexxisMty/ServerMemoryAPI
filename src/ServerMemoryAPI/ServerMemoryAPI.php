<?php

namespace IndexDev\ServerMemoryAPI;

use pocketmine\plugin\PluginBase;
use pocketmine\scheduler\Task;
use pocketmine\Server;
use pocketmine\player\Player;
use pocketmine\utils\SingletonTrait;

class ServerMemory extends PluginBase {
    use SingletonTrait;

    private array $memory = [];
    private array $expirations = [];
    private array $listeners = [];
    private array $locks = [];
    private array $namespaces = [];
    private string $storagePath = "memory.json";

    protected function onEnable(): void {
        self::setInstance($this);
        $this->loadData();
        Server::getInstance()->getScheduler()->scheduleRepeatingTask(new class() extends Task {
            public function onRun(): void {
                ServerMemory::getInstance()->cleanup();
            }
        }, 20);
    }

    private function loadData(): void {
        if (file_exists($this->storagePath)) {
            $this->memory = json_decode(file_get_contents($this->storagePath), true) ?: [];
        }
    }

    private function saveData(): void {
        file_put_contents($this->storagePath, json_encode($this->memory, JSON_PRETTY_PRINT));
    }

    public function set(string $contextId, string $key, mixed $value, int $ttl = -1, bool $persistent = false, string $namespace = ''): void {
        $namespace = $namespace ?: 'default';
        if ($persistent) {
            $this->memory[$namespace][$contextId][$key] = $value;
            $this->saveData();
        } else {
            $this->memory[$namespace][$contextId][$key] = $value;
        }

        if ($ttl > 0) {
            $this->expirations[$contextId][$key] = time() + $ttl;
        }

        foreach ($this->listeners[$key] ?? [] as $listener) {
            $listener(null, $value);
        }
    }

    public function get(string $contextId, string $key, string $namespace = ''): mixed {
        $namespace = $namespace ?: 'default';
        return $this->memory[$namespace][$contextId][$key] ?? null;
    }

    public function subscribeContext(string $contextId, callable $listener): void {
        if (!isset($this->listeners[$contextId])) {
            $this->listeners[$contextId] = [];
        }
        $this->listeners[$contextId][] = $listener;
    }

    public function increment(string $contextId, string $key, int $amount = 1, string $namespace = ''): void {
        $namespace = $namespace ?: 'default';
        $current = $this->get($contextId, $key, $namespace) ?? 0;
        $this->set($contextId, $key, $current + $amount, -1, false, $namespace);
    }

    public function lock(string $contextId, string $key, int $duration): void {
        $this->locks["$contextId:$key"] = time() + $duration;
    }

    public function isLocked(string $contextId, string $key): bool {
        return isset($this->locks["$contextId:$key"]) && $this->locks["$contextId:$key"] > time();
    }

    private function cleanup(): void {
        $now = time();
        foreach ($this->expirations as $contextId => $keys) {
            foreach ($keys as $key => $expiry) {
                if ($expiry <= $now) {
                    unset($this->memory[$contextId][$key]);
                    unset($this->expirations[$contextId][$key]);
                }
            }
        }
    }

    public function showMemory(Player $player): void {
        $memoryInfo = print_r($this->memory, true);
        $player->sendMessage("Datos activos en memoria:\n" . $memoryInfo);
    }

    public static function idFromPlayer(Player $player): string {
        return "player:" . $player->getUniqueId()->toString();
    }
}
