<?php

namespace LuizMinecrapt\skywars\task;
use pocketmine\player\Player;
use pocketmine\scheduler\Task;
use pocketmine\utils\TextFormat as TF;
use LuizMinecrapt\skywars\Main;

class ItemHubTask extends Task
{
	/** @var Player */
	private $player;

	/** @var int */
	private int $cd = 5;

	/** 
	 * @param Player $player 
	 */
	public function __construct(Player $player)
	{
		$this->player = $player;
	}

	public function onRun(): void
	{
		if(!($this->player instanceof Player))
		{
			$this->getHandler()->cancel();
			return;
		}
		if($this->player->isOnline())
		{
			$itemInHand = $this->player->getInventory()->getItemInHand()->getName();
			if($game = Main::getInstance()->getPlayer($this->player))
			{			
				if($itemInHand == "Lobby")
				{
					if($this->cd == 5 || $this->cd == 4 || $this->cd == 3 || $this->cd == 2 ||$this->cd == 1)
					{
						$this->player->sendMessage("Teleporting you to lobby in " . TF::YELLOW . $this->cd);
					}
					if($this->cd == 0)
					{
						$this->cd = 5;
						$game->removePlayer($this->player);
						$game->checkArena();
					}
					$this->cd--;
				}			
			}	
			if($itemInHand == "Join")
			{
				if($this->cd == 5 || $this->cd == 4 || $this->cd == 3 || $this->cd == 2 ||$this->cd == 1)
				{
					$this->player->sendMessage("Join a new match in " . TF::YELLOW . $this->cd);
				}
				if($this->cd == 0)
				{
					$this->cd = 5;
					if($myGame = Main::getInstance()->getPlayer($this->player))
					{
						$myGame->removePlayer($this->player);
					}
					foreach(Main::getInstance()->getGames() as $game)
					{
						if(Main::getInstance()->getPlayer($this->player))
						{
							$this->player->sendMessage(Main::TAG. "You already in matches");
							return;
						}
						if($game->isStatus(true))
						{
							$game->addPlayer($this->player);
							return;
						} 
					}
				}
				$this->cd--;
			}	
		}else
		{
			$this->getHandler()->cancel();
		}
	}
}