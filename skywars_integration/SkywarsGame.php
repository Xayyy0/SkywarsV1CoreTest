<?php

declare(strict_types=1);

namespace practice\skywars;

use pocketmine\Server;
use pocketmine\block\VanillaBlocks;
use pocketmine\event\block\BlockBreakEvent;
use pocketmine\event\block\BlockPlaceEvent;
use pocketmine\event\entity\EntityDamageByEntityEvent;
use pocketmine\event\entity\EntityDamageEvent;
use pocketmine\player\GameMode;
use pocketmine\item\VanillaItems;
use pocketmine\player\Player;
use pocketmine\math\Vector3;
use pocketmine\world\Position;
use pocketmine\world\World;
use pocketmine\world\sound\PopSound;
use pocketmine\world\sound\PotionSplashSound;
use pocketmine\world\sound\XpCollectSound;
use practice\Practice;
use practice\session\SessionFactory;
use practice\world\async\WorldCopyAsync;
use practice\world\async\WorldDeleteAsync;

class SkywarsGame {

    public const STATUS_WAITING = 0;
    public const STATUS_COUNTDOWN = 1;
    public const STATUS_CAGE = 2;
    public const STATUS_START = 3;
    public const STATUS_FINISHED = 4;

    /** @var Player[] */
    private array $players = [];

    /** @var Player[] */
    private array $playersLeft = [];

    /** @var string[] List of "X:Y:Z" spawn strings */
    private array $spawns = [];

    /** @var array Player name to spawn key */
    private array $playerSpawns = [];

    /** @var array Player name to game stats */
    private array $playerInfo = [];

    /** @var string[] List of "X:Y:Z" chest strings */
    private array $chests = [];

    /** @var array List of block positions modified during the game to restore or clean */
    private array $placedBlocks = [];

    private int $status = self::STATUS_WAITING;
    private int $countdown = 30; // 30 seconds wait once min players met
    private int $cageTime = 10;   // 10 seconds in glass cages
    private int $gameTimer = 900; // 15 minutes match duration
    private int $endTime = 10;    // 10 seconds to teleport back to Lobby

    private string $worldTemplate;
    private string $worldActiveName;
    private ?World $world = null;
    private bool $worldLoaded = false;

    public function __construct(
        private string $arenaName,
        string $worldTemplate,
        private string $lobbyWorldName,
        private Vector3 $lobbySpawnLocation,
        array $spawns,
        array $chests
    ) {
        $this->worldTemplate = $worldTemplate;
        $this->worldActiveName = "sw-" . $this->arenaName . "-" . mt_rand(1, 9999);
        $this->spawns = $spawns; // Up to 12 coordinates: ["x:y:z", "x:y:z", ...]
        $this->chests = $chests; // List of coordinates: ["x:y:z", ...]

        $this->prepareWorld();
    }

    /**
     * Clones the template world asynchronously using Practice's WorldCopyAsync.
     * To ensure compatibility with PocketMine thread serialization, we load and reference
     * the copied world safely in the main thread once copies are done.
     */
    private function prepareWorld(): void {
        $server = Server::getInstance();

        $server->getAsyncPool()->submitTask(new WorldCopyAsync(
            $this->worldTemplate,
            $server->getDataPath() . 'worlds',
            $this->worldActiveName,
            $server->getDataPath() . 'worlds',
            null // We pass null to avoid any closure serialization warnings/crashes
        ));
    }

    public function getArenaName(): string {
        return $this->arenaName;
    }

    public function getStatus(): int {
        return $this->status;
    }

    public function getPlayers(): array {
        return $this->players;
    }

    /**
     * Lazy load/fetch the world instance in the main thread
     */
    private function checkWorldLoaded(): bool {
        if ($this->worldLoaded) {
            return $this->world !== null;
        }

        $server = Server::getInstance();
        if ($server->getWorldManager()->isWorldGenerated($this->worldActiveName)) {
            if (!$server->getWorldManager()->isWorldLoaded($this->worldActiveName)) {
                $server->getWorldManager()->loadWorld($this->worldActiveName);
            }
            $this->world = $server->getWorldManager()->getWorldByName($this->worldActiveName);
            if ($this->world !== null) {
                $this->world->setTime(World::TIME_DAY);
                $this->world->stopTime();
                $this->worldLoaded = true;
                return true;
            }
        }
        return false;
    }

    public function join(Player $player): void {
        if (!$this->checkWorldLoaded()) {
            $player->sendMessage("§cEl mapa de SkyWars aún se está cargando. Inténtalo de nuevo en unos segundos.");
            return;
        }

        if (count($this->players) >= 12) {
            $player->sendMessage("§cLa arena de SkyWars está llena.");
            return;
        }
        if ($this->status !== self::STATUS_WAITING && $this->status !== self::STATUS_COUNTDOWN) {
            $player->sendMessage("§cLa partida de SkyWars ya ha comenzado.");
            return;
        }

        $name = strtolower($player->getName());
        $this->players[$name] = $player;
        $this->playersLeft[$name] = $player;
        $this->playerInfo[$name] = ["kills" => 0];

        // Assign spawn index
        $spawnIndex = count($this->players) - 1;
        $this->playerSpawns[$name] = $spawnIndex;

        $player->getInventory()->clearAll();
        $player->getArmorInventory()->clearAll();
        $player->getCursorInventory()->clearAll();

        $player->setGamemode(GameMode::ADVENTURE());
        $player->setHealth($player->getMaxHealth());
        $player->getHungerManager()->setFood(20);

        // Teleport to assigned cage
        $this->teleportToSpawn($player, $spawnIndex);

        $this->broadcastMessage("§e" . $player->getName() . " §fse ha unido a la partida de SkyWars. [§a" . count($this->players) . "§f/§a12§f]");

        if (count($this->players) >= 2 && $this->status === self::STATUS_WAITING) {
            $this->status = self::STATUS_COUNTDOWN;
        }
    }

    private function teleportToSpawn(Player $player, int $index): void {
        if (!isset($this->spawns[$index])) return;

        $coords = explode(":", $this->spawns[$index]);
        if (count($coords) >= 3 && $this->world !== null) {
            $x = (float)$coords[0] + 0.5;
            $y = (float)$coords[1];
            $z = (float)$coords[2] + 0.5;

            // Generate glass cage around player
            if ($this->status < self::STATUS_START) {
                $this->buildCage($x, $y, $z);
            }

            $player->teleport(new Position($x, $y + 1, $z, $this->world));
        }
    }

    private function buildCage(float $x, float $y, float $z): void {
        if ($this->world === null) return;
        $floorY = (int)$y;

        // Build a glass cage
        $glass = VanillaBlocks::GLASS();
        $positions = [
            // Floor
            new Vector3($x, $floorY, $z),
            // Walls
            new Vector3($x + 1, $floorY + 1, $z),
            new Vector3($x - 1, $floorY + 1, $z),
            new Vector3($x, $floorY + 1, $z + 1),
            new Vector3($x, $floorY + 1, $z - 1),
            new Vector3($x + 1, $floorY + 2, $z),
            new Vector3($x - 1, $floorY + 2, $z),
            new Vector3($x, $floorY + 2, $z + 1),
            new Vector3($x, $floorY + 2, $z - 1),
            // Roof
            new Vector3($x, $floorY + 3, $z)
        ];

        foreach ($positions as $pos) {
            $this->world->setBlock($pos, $glass);
        }
    }

    private function removeCage(float $x, float $y, float $z): void {
        if ($this->world === null) return;
        $floorY = (int)$y;
        $air = VanillaBlocks::AIR();

        $positions = [
            // Floor is kept or removed depending on map style, let's just clear wall and roof
            new Vector3($x + 1, $floorY + 1, $z),
            new Vector3($x - 1, $floorY + 1, $z),
            new Vector3($x, $floorY + 1, $z + 1),
            new Vector3($x, $floorY + 1, $z - 1),
            new Vector3($x + 1, $floorY + 2, $z),
            new Vector3($x - 1, $floorY + 2, $z),
            new Vector3($x, $floorY + 2, $z + 1),
            new Vector3($x, $floorY + 2, $z - 1),
            new Vector3($x, $floorY + 3, $z)
        ];

        foreach ($positions as $pos) {
            $this->world->setBlock($pos, $air);
        }
    }

    public function tick(): void {
        // Automatically check if our map has been completely cloned/loaded
        $this->checkWorldLoaded();

        switch ($this->status) {
            case self::STATUS_COUNTDOWN:
                if (count($this->players) < 2) {
                    $this->status = self::STATUS_WAITING;
                    $this->countdown = 30;
                    $this->broadcastMessage("§cJugadores insuficientes. Cuenta regresiva cancelada.");
                    return;
                }

                if ($this->countdown <= 5 && $this->countdown > 0) {
                    $this->broadcastMessage("§eLa partida empieza en §a" . $this->countdown . "§e segundos...");
                    $this->playGlobalSound(new PopSound());
                }

                if ($this->countdown === 0) {
                    $this->status = self::STATUS_CAGE;
                    $this->fillChests();
                }
                $this->countdown--;
                break;

            case self::STATUS_CAGE:
                if ($this->cageTime > 0) {
                    $this->broadcastPopup("§eLiberando en: §a" . $this->cageTime . "s");
                    $this->playGlobalSound(new PopSound());
                }

                if ($this->cageTime === 0) {
                    $this->status = self::STATUS_START;
                    $this->releasePlayers();
                }
                $this->cageTime--;
                break;

            case self::STATUS_START:
                if (count($this->playersLeft) <= 1) {
                    $this->status = self::STATUS_FINISHED;
                    $this->announceWinner();
                    return;
                }

                if ($this->gameTimer <= 0) {
                    $this->status = self::STATUS_FINISHED;
                    $this->broadcastMessage("§c¡Tiempo agotado! Empate.");
                    return;
                }

                $this->gameTimer--;
                break;

            case self::STATUS_FINISHED:
                if ($this->endTime > 0) {
                    $this->broadcastPopup("§7Regresando al Lobby en: §c" . $this->endTime . "s");
                }

                if ($this->endTime === 0) {
                    $this->endGame();
                }
                $this->endTime--;
                break;
        }
    }

    private function releasePlayers(): void {
        foreach ($this->players as $player) {
            if (!$player->isOnline()) continue;

            $name = strtolower($player->getName());
            $index = $this->playerSpawns[$name] ?? null;
            if ($index !== null && isset($this->spawns[$index])) {
                $coords = explode(":", $this->spawns[$index]);
                if (count($coords) >= 3) {
                    $x = (float)$coords[0] + 0.5;
                    $y = (float)$coords[1];
                    $z = (float)$coords[2] + 0.5;
                    $this->removeCage($x, $y, $z);
                }
            }

            $player->setGamemode(GameMode::SURVIVAL());
            $player->sendTitle("§l§a¡LUCHA!");
            $player->broadcastSound(new PotionSplashSound());
        }
        $this->broadcastMessage("§a¡Las jaulas se han abierto! ¡Mucha suerte!");
    }

    private function fillChests(): void {
        if ($this->world === null) return;

        // Custom OP loot table
        $items = [
            ["item" => VanillaItems::IRON_SWORD(), "chance" => 0.7],
            ["item" => VanillaItems::IRON_HELMET(), "chance" => 0.5],
            ["item" => VanillaItems::IRON_CHESTPLATE(), "chance" => 0.5],
            ["item" => VanillaItems::IRON_LEGGINGS(), "chance" => 0.5],
            ["item" => VanillaItems::IRON_BOOTS(), "chance" => 0.5],
            ["item" => VanillaItems::DIAMOND_SWORD(), "chance" => 0.25],
            ["item" => VanillaItems::DIAMOND_HELMET(), "chance" => 0.15],
            ["item" => VanillaItems::DIAMOND_CHESTPLATE(), "chance" => 0.15],
            ["item" => VanillaItems::DIAMOND_LEGGINGS(), "chance" => 0.15],
            ["item" => VanillaItems::DIAMOND_BOOTS(), "chance" => 0.15],
            ["item" => VanillaItems::GOLDEN_APPLE(), "chance" => 0.4],
            ["item" => VanillaItems::BOW(), "chance" => 0.3],
            ["item" => VanillaItems::ARROW()->setCount(16), "chance" => 0.3],
            ["item" => VanillaItems::STEAK()->setCount(16), "chance" => 0.8],
            ["item" => VanillaBlocks::OAK_PLANKS()->asItem()->setCount(64), "chance" => 0.9],
            ["item" => VanillaBlocks::COBBLESTONE()->asItem()->setCount(64), "chance" => 0.9],
            ["item" => VanillaItems::ENDER_PEARL()->setCount(2), "chance" => 0.2]
        ];

        foreach ($this->chests as $chestString) {
            $coords = explode(":", $chestString);
            if (count($coords) < 3) continue;

            $x = (int)$coords[0];
            $y = (int)$coords[1];
            $z = (int)$coords[2];
            $pos = new Vector3($x, $y, $z);

            // Re-set the block as chest just in case, to clear old states
            $this->world->setBlock($pos, VanillaBlocks::CHEST());
            $tile = $this->world->getTile($pos);

            if ($tile instanceof \pocketmine\block\tile\Chest) {
                $inventory = $tile->getInventory();
                $inventory->clearAll();

                // Select and populate random loot
                $chestLoot = [];
                foreach ($items as $itemData) {
                    if (mt_rand() / mt_getrandmax() < $itemData["chance"]) {
                        $chestLoot[] = clone $itemData["item"];
                    }
                }

                shuffle($chestLoot);

                // Distribute items in chest inventory
                foreach ($chestLoot as $lootItem) {
                    $slot = mt_rand(0, 26);
                    $inventory->setItem($slot, $lootItem);
                }
            }
        }
    }

    public function handleBreak(BlockBreakEvent $event): void {
        $block = $event->getBlock();
        $posString = $block->getPosition()->getX() . ":" . $block->getPosition()->getY() . ":" . $block->getPosition()->getZ();

        // Standard SkyWars protection: only allow breaking blocks placed by players
        if (!isset($this->placedBlocks[$posString])) {
            $event->cancel();
        } else {
            unset($this->placedBlocks[$posString]);
        }
    }

    public function handlePlace(BlockPlaceEvent $event): void {
        $block = $event->getBlock();
        $posString = $block->getPosition()->getX() . ":" . $block->getPosition()->getY() . ":" . $block->getPosition()->getZ();

        $this->placedBlocks[$posString] = true;
    }

    public function handleDamage(EntityDamageEvent $event): void {
        $player = $event->getEntity();
        if (!$player instanceof Player) return;

        if ($this->status < self::STATUS_START) {
            $event->cancel();
            return;
        }

        $finalHealth = $player->getHealth() - $event->getFinalDamage();
        if ($finalHealth <= 0.0) {
            $event->cancel();
            $this->eliminatePlayer($player, $event);
        }
    }

    private function eliminatePlayer(Player $player, EntityDamageEvent $event): void {
        $name = strtolower($player->getName());
        if (!isset($this->playersLeft[$name])) return;

        unset($this->playersLeft[$name]);

        // Drop player's items
        foreach (array_merge($player->getInventory()->getContents(), $player->getArmorInventory()->getContents()) as $item) {
            if (!$item->isNull()) {
                $player->getWorld()->dropItem($player->getPosition(), $item);
            }
        }

        $player->getInventory()->clearAll();
        $player->getArmorInventory()->clearAll();

        $player->setGamemode(GameMode::SPECTATOR());
        $player->sendTitle("§l§cELIMINADO");

        // Broadcast elimination message
        $killerMessage = "§7se cayó al vacío.";
        if ($event instanceof EntityDamageByEntityEvent) {
            $damager = $event->getDamager();
            if ($damager instanceof Player) {
                $damagerName = strtolower($damager->getName());
                if (isset($this->playerInfo[$damagerName])) {
                    $this->playerInfo[$damagerName]["kills"]++;
                }
                $killerMessage = "§f fue asesinado por §c" . $damager->getName() . "§f.";
            }
        }
        $this->broadcastMessage("§c" . $player->getName() . $killerMessage . " [§e" . count($this->playersLeft) . "§f vivos]");
    }

    private function announceWinner(): void {
        $winner = null;
        foreach ($this->playersLeft as $player) {
            $winner = $player;
            break;
        }

        if ($winner !== null) {
            $winner->sendTitle("§l§6¡VICTORIA!");
            $winner->broadcastSound(new XpCollectSound());
            $this->broadcastMessage("§r");
            $this->broadcastMessage("§a====================================");
            $this->broadcastMessage("§l§e  ¡PARTIDA DE SKYWARS FINALIZADA!  ");
            $this->broadcastMessage("§r");
            $this->broadcastMessage("§f  Ganador: §6" . $winner->getName());
            $this->broadcastMessage("§f  Asesinatos: §e" . ($this->playerInfo[strtolower($winner->getName())]["kills"] ?? 0));
            $this->broadcastMessage("§a====================================");
        } else {
            $this->broadcastMessage("§cLa partida terminó sin un ganador.");
        }
    }

    private function endGame(): void {
        $server = Server::getInstance();
        $defaultWorld = $server->getWorldManager()->getWorldByName($this->lobbyWorldName);
        $spawn = $defaultWorld !== null ? $defaultWorld->getSpawnLocation() : $server->getWorldManager()->getDefaultWorld()->getSpawnLocation();

        foreach ($this->players as $player) {
            if (!$player->isOnline()) continue;

            $player->setGamemode(GameMode::SURVIVAL());
            $player->getInventory()->clearAll();
            $player->getArmorInventory()->clearAll();
            $player->getEffects()->clear();
            $player->setHealth($player->getMaxHealth());
            $player->getHungerManager()->setFood(20);

            $player->teleport($spawn);

            // Give lobby items back using Practice's SessionFactory
            $session = SessionFactory::get($player);
            if ($session !== null) {
                $session->giveLobbyItems();
            }
        }

        // Clean and delete world asynchronously to prevent lag
        if ($this->world !== null) {
            $server->getWorldManager()->unloadWorld($this->world);
            $server->getAsyncPool()->submitTask(new WorldDeleteAsync(
                $this->worldActiveName,
                $server->getDataPath() . 'worlds'
            ));
        }

        $this->status = self::STATUS_FINISHED;
    }

    private function broadcastMessage(string $msg): void {
        foreach ($this->players as $player) {
            if ($player->isOnline()) {
                $player->sendMessage($msg);
            }
        }
    }

    private function broadcastPopup(string $msg): void {
        foreach ($this->players as $player) {
            if ($player->isOnline()) {
                $player->sendTip($msg);
            }
        }
    }

    private function playGlobalSound($sound): void {
        foreach ($this->players as $player) {
            if ($player->isOnline()) {
                $player->broadcastSound($sound);
            }
        }
    }
}
