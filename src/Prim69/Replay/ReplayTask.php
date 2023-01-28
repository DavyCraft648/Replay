<?php
declare(strict_types=1);

namespace Prim69\Replay;

use pocketmine\entity\Entity;
use pocketmine\item\VanillaItems;
use pocketmine\math\Vector3;
use pocketmine\network\mcpe\convert\RuntimeBlockMapping;
use pocketmine\network\mcpe\convert\SkinAdapterSingleton;
use pocketmine\network\mcpe\convert\TypeConverter;
use pocketmine\network\mcpe\protocol\AddPlayerPacket;
use pocketmine\network\mcpe\protocol\ClientboundPacket;
use pocketmine\network\mcpe\protocol\RemoveActorPacket;
use pocketmine\network\mcpe\protocol\PlayerSkinPacket;
use pocketmine\network\mcpe\protocol\types\BlockPosition;
use pocketmine\network\mcpe\protocol\types\command\CommandPermissions;
use pocketmine\network\mcpe\protocol\types\DeviceOS;
use pocketmine\network\mcpe\protocol\types\entity\PropertySyncData;
use pocketmine\network\mcpe\protocol\types\GameMode;
use pocketmine\network\mcpe\protocol\types\inventory\ItemStackWrapper;
use pocketmine\network\mcpe\protocol\types\PlayerPermissions;
use pocketmine\network\mcpe\protocol\UpdateAbilitiesPacket;
use pocketmine\player\Player;
use pocketmine\scheduler\Task;
use pocketmine\block\Block;
use pocketmine\network\mcpe\protocol\UpdateBlockPacket;
use pocketmine\VersionInfo;
use pocketmine\world\World;
use Ramsey\Uuid\Uuid;
use function array_key_first;
use function count;
use function property_exists;

class ReplayTask extends Task{

	private bool $started = false;

	private int $eid;

	/** @var ClientboundPacket[] */
	private array $list;
	/** @var Block[] */
	private array $blocks;
	/** @var Block[] */
	private array $setBlocks = [];

	public function __construct(private Player $player, Player $target, Main $main){
		$this->list = $main->saved[$target->getName()]["packets"];
		$this->blocks = $main->saved[$target->getName()]["blocks"];

		/** @var Block $block */
		foreach($main->saved[$target->getName()]["preBlocks"] as $hash => $block){
			World::getBlockXYZ($hash, $blockX, $blockY, $blockZ);
			$player->getNetworkSession()->sendDataPacket(UpdateBlockPacket::create(
				new BlockPosition($blockX, $blockY, $blockZ),
				RuntimeBlockMapping::getInstance()->toRuntimeId(VersionInfo::BASE_VERSION[0] === "5" ? $block->getStateId() : $block->getFullId()),
				UpdateBlockPacket::FLAG_NETWORK,
				UpdateBlockPacket::DATA_LAYER_NORMAL
			));
		}

		$this->eid = Entity::nextRuntimeId();

		$p = $main->positions[$target->getName()];

		$player->getNetworkSession()->sendDataPacket(AddPlayerPacket::create(
			$uuid = Uuid::uuid4(),
			$target->getName(),
			$this->eid,
			"",
			new Vector3($p[2], $p[3], $p[4]),
			null,
			$p[1],
			$p[0],
			$p[0],
			ItemStackWrapper::legacy(TypeConverter::getInstance()->coreItemStackToNet(VanillaItems::AIR())),
			GameMode::SURVIVAL,
			[],
			new PropertySyncData([], []),
			UpdateAbilitiesPacket::create(CommandPermissions::NORMAL, PlayerPermissions::VISITOR, $this->eid, []),
			[],
			"",
			DeviceOS::UNKNOWN
		));
		$player->getNetworkSession()->sendDataPacket(PlayerSkinPacket::create(
			$uuid, "", "", SkinAdapterSingleton::get()->toSkinData($target->getSkin())
		));
	}

	public function onRun() : void{
		if(!$this->player->isOnline()){
			$this->getHandler()->cancel();
			return;
		}
		if(count($this->list) <= 0){
			if($this->started){
				$this->player->getNetworkSession()->sendDataPacket(RemoveActorPacket::create($this->eid));
				foreach($this->setBlocks as $block){
					if(!$block instanceof Block || !($blockPos = $block->getPosition())->isValid()) continue;
					$this->player->getNetworkSession()->sendDataPacket(UpdateBlockPacket::create(
						new BlockPosition($blockPos->x, $blockPos->y, $blockPos->z),
						RuntimeBlockMapping::getInstance()->toRuntimeId(VersionInfo::BASE_VERSION[0] === "5" ?
							$blockPos->getWorld()->getBlockAt($blockPos->x, $blockPos->y, $blockPos->z)->getStateId() :
							$blockPos->getWorld()->getBlockAt($blockPos->x, $blockPos->y, $blockPos->z)->getFullId()
						),
						UpdateBlockPacket::FLAG_NETWORK,
						UpdateBlockPacket::DATA_LAYER_NORMAL
					));
				}
			}
			$this->getHandler()->cancel();
			return;
		}
		if(!$this->started) $this->started = true;
		$key = array_key_first($this->list);

		$relayed = clone $this->list[$key];
		if($a = property_exists($relayed, 'actorUniqueId')) $relayed->actorUniqueId = $this->eid;
		if($b = property_exists($relayed, 'actorRuntimeId')) $relayed->actorRuntimeId = $this->eid;
		if($a || $b) $this->player->getNetworkSession()->sendDataPacket($relayed);

		if(isset($this->blocks[$key])){
			$relayed = $this->blocks[$key];
			if($relayed instanceof Block){
				$blockPos = $relayed->getPosition();
				$this->player->getNetworkSession()->sendDataPacket(UpdateBlockPacket::create(
					new BlockPosition($blockPos->x, $blockPos->y, $blockPos->z),
					RuntimeBlockMapping::getInstance()->toRuntimeId(VersionInfo::BASE_VERSION[0] === "5" ? $relayed->getStateId() : $relayed->getFullId()),
					UpdateBlockPacket::FLAG_NETWORK,
					UpdateBlockPacket::DATA_LAYER_NORMAL
				));
				$this->setBlocks[] = $relayed;
			}
		}

		unset($this->blocks[$key], $this->list[$key]);
	}
}
