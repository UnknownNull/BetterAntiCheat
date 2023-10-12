<?php

namespace veroxcode\Guardian\Listener;

use pocketmine\event\block\BlockBreakEvent;
use pocketmine\event\entity\EntityDamageByEntityEvent;
use pocketmine\event\entity\EntityMotionEvent;
use pocketmine\event\Listener;
use pocketmine\event\player\PlayerJoinEvent;
use pocketmine\event\player\PlayerQuitEvent;
use pocketmine\event\server\DataPacketReceiveEvent;
use pocketmine\math\Vector2;
use pocketmine\network\mcpe\protocol\InventoryTransactionPacket;
use pocketmine\network\mcpe\protocol\PlayerAuthInputPacket;
use pocketmine\network\mcpe\protocol\types\inventory\UseItemOnEntityTransactionData;
use pocketmine\player\Player;
use veroxcode\Guardian\Buffers\AttackFrame;
use veroxcode\Guardian\Buffers\MovementFrame;
use veroxcode\Guardian\Guardian;
use veroxcode\Guardian\User\User;
use veroxcode\Guardian\Utils\Random;

class EventListener implements Listener
{

    /**
     * @param DataPacketReceiveEvent $event
     * @return void
     */
    public function onPacketReceive(DataPacketReceiveEvent $event): void
    {
        $packet = $event->getPacket();
        $player = $event->getOrigin()->getPlayer();

        if ($player == null || Guardian::getInstance()->getUserManager()->getUser($player->getUniqueId()->toString()) == null){
            return;
        }

        $uuid = $player->getUniqueId()->toString();
        $user = Guardian::getInstance()->getUserManager()->getUser($uuid);

        if ($user == null){
            return;
        }

        if ($packet instanceof InventoryTransactionPacket){
            $data = $packet->trData;

            if ($data instanceof UseItemOnEntityTransactionData){
                $NewBuffer = new AttackFrame(
                    $this->getServerTick(),
                    $player->getNetworkSession()->getPing(),
                    $user->getLastAttack()
                );
                Guardian::getInstance()->getUserManager()->getUser($uuid)->addToAttackBuffer($NewBuffer);
            }
        }

        if ($packet instanceof PlayerAuthInputPacket){

            $user->preMove($packet, $player);

            foreach (Guardian::getInstance()->getCheckManager()->getChecks() as $Check){
                $Check->onMove($player, $packet, $user);
            }

            $NewBuffer = new MovementFrame(
                $this->getServerTick(),
                $packet->getTick(),
                $packet->getPosition(),
                new Vector2($packet->getPitch(), $packet->getYaw()),
                $packet->getHeadYaw(),
                $event->getOrigin()->getPlayer()->isOnGround(),
                $event->getOrigin()->getPlayer()->boundingBox
            );
            $user->addToMovementBuffer($NewBuffer);

        }

    }

    public function onAttack(EntityDamageByEntityEvent $event): void
    {
        $damager = $event->getDamager();
        $victim = $event->getEntity();

        if ($victim instanceof Player){
            $victimUser = Guardian::getInstance()->getUserManager()->getUser($victim->getUniqueId()->toString());
            $victimUser->setLastKnockbackTick($this->getServerTick());
        }

        if ($damager instanceof Player){
            $user = Guardian::getInstance()->getUserManager()->getUser($damager->getUniqueId()->toString());
            foreach (Guardian::getInstance()->getCheckManager()->getChecks() as $Check){
                $Check->onAttack($event, $user);
            }
            $user->setLastAttack($this->getServerTick());

            if ($user->isPunishNext()){
                $user->setPunishNext(false);
                $event->cancel();
            }
        }
    }

    public function onBlockBreak(BlockBreakEvent $event): void
    {
        $player = $event->getPlayer();
        $user = Guardian::getInstance()->getUserManager()->getUser($player->getUniqueId()->toString());

        foreach (Guardian::getInstance()->getCheckManager()->getChecks() as $Check){
            $Check->onBlockBreak($event, $user);
        }
    }

    public function onMotion(EntityMotionEvent $event){
        $entity = $event->getEntity();

        if ($entity instanceof Player) {
            $user = Guardian::getInstance()->getUserManager()->getUser($entity->getUniqueId()->toString());
            foreach (Guardian::getInstance()->getCheckManager()->getChecks() as $Check){
                $Check->onMotion($event, $user);
            }
        }
    }

    /**
     * @param PlayerJoinEvent $event
     * @return void
     */
    public function onJoin(PlayerJoinEvent $event) : void
    {
        $player = $event->getPlayer();
        $uuid = $player->getUniqueId()->toString();
        $user = new User($uuid);

        Guardian::getInstance()->getUserManager()->registerUser($user);

        foreach (Guardian::getInstance()->getCheckManager()->getChecks() as $Check){
            $Check->onJoin($event, $user);
        }
    }

    /**
     * @param PlayerQuitEvent $event
     * @return void
     */
    public function onQuit(PlayerQuitEvent $event) : void
    {
        $player = $event->getPlayer();
        $uuid = $player->getUniqueId()->toString();

        Guardian::getInstance()->getUserManager()->unregisterUser($uuid);
    }

    public function getServerTick() : int
    {
        return Guardian::getInstance()->getServer()->getTick();
    }

}