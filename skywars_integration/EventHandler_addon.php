<?php

declare(strict_types=1);

namespace practice\skywars;

// =========================================================================
// GUÍA DE INTEGRACIÓN DE ÍTEM DEL LOBBY Y MENÚ DE SELECCIÓN PARA SKYWARS
// =========================================================================
// Este archivo contiene fragmentos de ejemplo detallados que debes copiar
// e integrar directamente dentro de los archivos de tu OptimizeCore.

use pocketmine\item\VanillaItems;
use pocketmine\player\Player;
use pocketmine\event\player\PlayerInteractEvent;
use pocketmine\utils\TextFormat as TF;
use practice\session\Session;

/**
 * PARTE 1: Registrar el ítem de Lobby dentro de tu Session.php
 *
 * Busca la función `giveLobbyItems()` en `src/practice/session/Session.php`
 * y añade el Ojo de Ender para acceder a SkyWars:
 */
class SessionAddonExample {

    public function giveLobbyItemsExample(Player $player): void {
        // ... Otros ítems de tu lobby (Duels, Cosméticos, Ajustes, etc.) ...

        // Registramos el Ojo de Ender como ítem de selección de SkyWars
        $skywarsSelector = VanillaItems::ENDER_EYE();
        $skywarsSelector->setCustomName(TF::colorize("&r&l&eJugar SkyWars &7(Click Derecho)"));

        // Colocamos una etiqueta custom NBT para identificarlo en el Handler de eventos
        $skywarsSelector->getNamedTag()->setString("practice_item", "skywars_join");

        // Colocar el ítem en la ranura número 4 (Ranura central del Hotbar)
        $player->getInventory()->setItem(4, $skywarsSelector);
    }
}


/**
 * PARTE 2: Manejar la interacción en `EventHandler.php`
 *
 * Busca la función `handleInteract(PlayerInteractEvent $event)` en
 * `src/practice/EventHandler.php` e integra la detección del click:
 */
class EventHandlerAddonExample {

    public function handleInteractExample(PlayerInteractEvent $event): void {
        $player = $event->getPlayer();
        $item = $event->getItem();

        // Detectar si tiene la etiqueta del ítem de SkyWars
        if ($item->getNamedTag()->getString("practice_item", "") === "skywars_join") {
            $event->cancel();

            // Abrir el menú UI con el Formulario para seleccionar arena o unirse rápido
            $this->openSkywarsMenu($player);
            return;
        }
    }

    /**
     * Muestra una ventana de formulario rápida (Form UI) usando las API de tu Core
     * o la biblioteca compatible que use tu servidor.
     */
    private function openSkywarsMenu(Player $player): void {
        // Aquí puedes usar cosmicpe/form de tu core o FormAPI
        // Este es un ejemplo rápido usando FormAPI tradicional:

        /*
        $form = new SimpleForm(function(Player $player, ?int $data) {
            if ($data === null) return;

            switch($data) {
                case 0:
                    // Unirse a partida rápida
                    $player->sendMessage("§aBuscando partida de SkyWars disponible...");
                    // Lógica: buscar arena con estado STATUS_WAITING o STATUS_COUNTDOWN
                    // $arena->join($player);
                    break;
                case 1:
                    $player->sendMessage("§cRegresando al Lobby principal.");
                    break;
            }
        });

        $form->setTitle("§l§5Menú de SkyWars");
        $form->setContent("Selecciona una de las opciones disponibles:");
        $form->addButton("§l§a¡Partida Rápida!\n§r§8[Click para Unirse]");
        $form->addButton("§l§cSalir");
        $player->sendForm($form);
        */

        $player->sendMessage("§a[SkyWars] ¡Menú interactivo activado correctamente!");
    }
}
