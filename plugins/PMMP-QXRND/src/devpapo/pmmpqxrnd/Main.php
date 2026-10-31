<?php
declare(strict_types=1);

namespace devpapo\pmmpqxrnd;

use pocketmine\plugin\PluginBase;

class Main extends PluginBase {

    protected function onEnable(): void {
        $this->saveDefaultConfig();
        if (!(new SconLicenseGuard($this))->start((string) $this->getConfig()->get("license-key", ""))) {
            $this->getServer()->shutdown();
            return;
        }

        $this->getLogger()->info("§a[SCON] PMMP-QXRND inicializado y protegido correctamente.");
    }
}
