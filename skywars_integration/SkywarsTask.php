<?php

declare(strict_types=1);

namespace practice\skywars;

use pocketmine\scheduler\Task;

class SkywarsTask extends Task {

    /** @var SkywarsGame */
    private SkywarsGame $game;

    public function __construct(SkywarsGame $game) {
        $this->game = $game;
    }

    public function onRun(): void {
        $this->game->tick();
    }
}
