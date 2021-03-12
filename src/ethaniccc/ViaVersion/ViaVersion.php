<?php

declare(strict_types=1);

namespace ethaniccc\ViaVersion;

use ethaniccc\ViaVersion\protocol\v419\PlayerListPacket419;
use ethaniccc\ViaVersion\protocol\v428\PlayerListPacket428;
use ethaniccc\ViaVersion\protocol\v428\SkinData428;
use ethaniccc\ViaVersion\protocol\v428\StartGamePacket428;
use Exception;
use pocketmine\event\player\PlayerQuitEvent;
use pocketmine\network\mcpe\protocol\BatchPacket;
use pocketmine\network\mcpe\protocol\PacketPool;
use pocketmine\utils\TextFormat;
use pocketmine\event\Listener;
use pocketmine\event\server\DataPacketReceiveEvent;
use pocketmine\event\server\DataPacketSendEvent;
use pocketmine\network\mcpe\protocol\LoginPacket;
use pocketmine\network\mcpe\protocol\PacketViolationWarningPacket;
use pocketmine\network\mcpe\protocol\ProtocolInfo;
use pocketmine\network\mcpe\protocol\PlayerListPacket;
use pocketmine\network\mcpe\protocol\StartGamePacket;
use pocketmine\plugin\PluginBase;
use RuntimeException;

class ViaVersion extends PluginBase implements Listener
{

    public const SUPPORTED_PROTOCOLS = [419, 422, 428];

    /** @var array */
    private array $protocol = [];
    /** @var array */
    private array $fabID = [];
    /** @var array */
    private array $lastSentPacket = [];
    /** @var array */
    private array $players = [];

    private static self $i;

    public function onEnable(): void
    {
        self::$i = $this;
        //$this->saveAllResources();
        $this->getServer()->getPluginManager()->registerEvents($this, $this);
    }

    public static function get(): self {
        return self::$i;
    }

    private function saveAllResources(): void {
        $resourcePath = $this->getFile() . "resources";
        $versions = scandir($resourcePath);

        foreach ($versions as $version) {
            if ($version === '.' || $version === '..' || $version === 'config.yml') {
                continue;
            }

            $files = scandir($resourcePath . "/" . $version);
            foreach ($files as $file) {
                if ($file === '.' || $file === '..') {
                    continue;
                }
                $this->saveResource($version . "/" . $file);
            }
        }
    }

    public function receivePacket(DataPacketReceiveEvent $event): void
    {
        $packet = $event->getPacket();
        if ($packet instanceof LoginPacket && in_array($packet->protocol, self::SUPPORTED_PROTOCOLS, true)) {
            $player = spl_object_hash($event->getPlayer());
            $this->protocol[$player] = $packet->protocol;
            if ($packet->protocol >= 428) {
                $this->fabID[$player] = $packet->clientData["PlayerFabId"] ?? "";
            }
            $packet->protocol = ProtocolInfo::CURRENT_PROTOCOL;
            if (!isset($this->players[TextFormat::clean($packet->username)])) {
                $this->players[TextFormat::clean($packet->username)] = $event->getPlayer();
            }
        } elseif ($packet instanceof PacketViolationWarningPacket) {
            var_dump($packet);
            //$this->getLogger()->notice($this->lastSentPacket[spl_object_hash($event->getPlayer())] ?? "No found last sent");
        }
    }

    public function sendPacket(DataPacketSendEvent $event): void
    {
        $packet = $event->getPacket();
        $player = $event->getPlayer();
        if (get_class($packet) === PlayerListPacket::class) {
            /** @var PlayerListPacket $packet */
            try {
                $packet->decode();
            } catch (RuntimeException $e) {
            }

            $hash = spl_object_hash($player);
            $protocol = $this->protocol[$hash];
            if ($protocol > 422 && $protocol !== ProtocolInfo::CURRENT_PROTOCOL) {
                $event->setCancelled();
                $enteries = [];
                foreach ($packet->entries as $entry) {
                    if ($entry->username === null && $entry->entityUniqueId === null) {
                        continue;
                    }

                    $p = $this->players[TextFormat::clean($entry->username)] ?? null;

                    if ($p === null) {
                        return;
                    }

                    $h = spl_object_hash($p);
                    $protokol = $this->protocol[$h] ?? 419;

                    if ($protokol >= 248) {
                        $entry->skinData = SkinData428::from($entry->skinData, $this->fabID[$hash]);
                    } else {
                        $entry->skinData = SkinData428::from($entry->skinData, "8a6bfa18-cfdd-46aa-a479-56b194cda178");
                    }

                    $enteries[] = $entry;
                }

                $pk = new PlayerListPacket428();
                $pk->entries = $enteries;
                $pk->type = $packet->type;
                $this->getLogger()->debug("sent new player list packet");
                $player->sendDataPacket($pk, false, true);
            } elseif ($protocol <= 422 && $protocol !== ProtocolInfo::CURRENT_PROTOCOL) {
                $event->setCancelled();
                $enteries = [];
                foreach ($packet->entries as $entry) {
                    $enteries[] = $entry;
                }
                $pk = new PlayerListPacket419();
                $pk->entries = $enteries;
                $pk->type = $packet->type;
                $player->sendDataPacket($pk, false, true);
            }
        } elseif (get_class($packet) === StartGamePacket::class) {
            /** @var StartGamePacket $packet */
            $hash = spl_object_hash($player);
            $protocol = $this->protocol[$hash];
            if ($protocol > 422 && $protocol !== ProtocolInfo::CURRENT_PROTOCOL) {
                $event->setCancelled();
                $pk = StartGamePacket428::from($packet);
                $this->getLogger()->debug("sent new start game packet");
                $player->sendDataPacket($pk, false, true);
            } else {
                $this->getLogger()->debug("failed conditions");
            }
        } elseif ($packet instanceof BatchPacket) {
            foreach ($packet->getPackets() as $buff) {
                $pk = PacketPool::getPacket($buff);
                try {
                    $pk->decode();
                } catch (Exception $e) {
                    continue;
                }
                if (get_class($pk) === PlayerListPacket::class) {
                    /** @var PlayerListPacket $pk */
                    if (count($pk->entries) === 0) {
                        return;
                    }

                    foreach ($pk->entries as $entry) {
                        if ($entry->skinData instanceof SkinData428) {
                            return;
                        }
                    }

                    $hash = spl_object_hash($player);
                    $protocol = $this->protocol[$hash];
                    if ($protocol > 422 && $protocol !== ProtocolInfo::CURRENT_PROTOCOL) {
                        $enteries = [];
                        foreach ($pk->entries as $entry) {
                            if ($entry->username === null && $entry->entityUniqueId === null) {
                                continue;
                            }

                            $p = $this->players[TextFormat::clean($entry->username)];
                            $this->getLogger()->debug("username entry=" . $entry->username);

                            if ($p === null) {
                                return;
                            }

                            $h = spl_object_hash($p);
                            $protokol = $this->protocol[$h] ?? 419;

                            if ($protokol >= 248) {
                                $entry->skinData = SkinData428::from($entry->skinData, $this->fabID[$hash]);
                            } else {
                                $entry->skinData = SkinData428::from($entry->skinData, "8a6bfa18-cfdd-46aa-a479-56b194cda178");
                            }

                            $enteries[] = $entry;
                        }
                        $pKK = new PlayerListPacket428();
                        $pKK->entries = $enteries;
                        $pKK->type = $pk->type;
                        $this->getLogger()->debug("sent new player list packet (batch)");
                        $player->sendDataPacket($pKK, false, true);
                        $event->setCancelled();
                    }
                }
            }
        }

        $this->lastSentPacket[spl_object_hash($player)] = $packet;
    }

    public function onQuit(PlayerQuitEvent $event): void
    {
        unset($this->players[$event->getPlayer()->getName()]);
    }
}