<?php

namespace LuizMinecrapt\skywars\page;

use pocketmine\Server;
use pocketmine\player\Player;
use pocketmine\utils\TextFormat as TF;
use jojoe77777\FormAPI\{CustomForm, Form, ModalForm, SimpleForm};
use LuizMinecrapt\skywars\Main;

class Page
{
	public function mainPage(Player $player): void
	{
		$form = new SimpleForm(function(Player $player, int $data = null){
			if($data === null)
			{
				return;
			}
			$name = $player->getName();
			switch($data)
			{
				case 0:
					foreach(Main::getInstance()->getGames() as $game)
					{
						if(Main::getInstance()->getPlayer($player))
						{
							$player->sendMessage(Main::TAG. "You already in matches");
							return;
						}
						if($game->isStatus(true))
						{
							$game->addPlayer($player);
							return;
						} else
						{
							$player->sendMessage(Main::TAG . "No arena available, maybe try again later");
						}
						return;
					}					
				break;
			}
		});
		$form->setTitle(TF::BOLD.TF::YELLOW."SKYLAND");
		$form->addButton(TF::AQUA."Play\n".TF::RESET.TF::WHITE."»» Play and survive in the sky",0, "textures/items/compass_item");
		$form->addButton(TF::RED."Exit");
		$form->sendToPlayer($player);
	}

	public function addPage(Player $player): void
	{
		$form = new CustomForm(function(Player $player, array $data = null){
			if($data === null)
			{
				return;
			}
			$name = $player->getName();
			if($data[0] === null)
			{
				$player->sendMessage(Main::TAG . "You must fill all of it");
				return;
			}

			if($data[1] === null)
			{
				$player->sendMessage(Main::TAG . "You must fill all of it");
				return;
			}

			if($data[2] === null)
			{
				$player->sendMessage(Main::TAG . "You must fill all of it");
				return;
			}

			if($data[3] === null)
			{
				$player->sendMessage(Main::TAG . "You must fill all of it");
				return;
			}
			Main::getInstance()->createArena($data[0], $data[3], $data[2], $data[1]);
			$game = Main::getInstance()->getGame($data[0]);
			$game->editArena($player);
			if(!(Server::getInstance()->getWorldManager()->isWorldLoaded($data[3])))
			{
				Server::getInstance()->getWorldManager()->loadWorld($data[3]);
			} else{
				$player->teleport(Server::getInstance()->getWorldManager()->getWorldByName($data[3])->getSafeSpawn());

			}
			if(Server::getInstance()->getWorldManager()->isWorldGenerated($data[3]))
			{
				$player->teleport(Server::getInstance()->getWorldManager()->getWorldByName($data[3])->getSafeSpawn());
			}			
			$player->sendMessage(Main::TAG . "Skywars name: $data[0]");
			$player->sendMessage(Main::TAG . "Map: $data[1]");
			$player->sendMessage(Main::TAG . "Max player: 12");
			$player->sendMessage(Main::TAG . "Max chest: 41");
			$player->sendMessage(Main::TAG . "Hub: $data[2]");
			$player->sendMessage(Main::TAG . "World name: $data[3]");
			$player->sendMessage(Main::TAG . "Successfully created arena with the name $data[0]");
		});
		$form->setTitle(TF::YELLOW."SKYWARS");
		$form->addInput("Skywars name:");
		$form->addInput("Map:");		
		$form->addInput("Hub name:");
		$form->addInput("World name:");
		$form->sendToPlayer($player);
	}
}