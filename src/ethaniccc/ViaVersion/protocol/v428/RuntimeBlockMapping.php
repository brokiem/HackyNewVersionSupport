<?php /** @noinspection PhpFullyQualifiedNameUsageInspection */
declare(strict_types=1);

namespace ethaniccc\ViaVersion\protocol\v428;

use ethaniccc\ViaVersion\ViaVersion;
use pocketmine\block\BlockIds;
use pocketmine\nbt\NetworkLittleEndianNBTStream;
use pocketmine\nbt\tag\CompoundTag;
use pocketmine\network\mcpe\convert\R12ToCurrentBlockMapEntry;
use pocketmine\network\mcpe\NetworkBinaryStream;
use pocketmine\utils\AssumptionFailedError;

final class RuntimeBlockMapping
{
    /** @var int[] */
    private static array $legacyToRuntimeMap = [];
    /** @var int[] */
    private static array $runtimeToLegacyMap = [];
    /** @var CompoundTag[]|null */
    private static ?array $bedrockKnownStates = null;

    private function __construct()
    {
        //NOOP
    }

    public static function init(): void
    {
        $canonicalBlockStatesFile = file_get_contents(ViaVersion::get()->getDataFolder() . "data/v428/canonical_block_states.nbt");
        if ($canonicalBlockStatesFile === false) {
            throw new AssumptionFailedError("Missing required resource file");
        }
        $stream = new NetworkBinaryStream($canonicalBlockStatesFile);
        $list = [];
        while (!$stream->feof()) {
            $list[] = $stream->getNbtCompoundRoot();
        }
        self::$bedrockKnownStates = $list;

        self::setupLegacyMappings();
    }

    private static function setupLegacyMappings(): void
    {
        $legacyIdMap = json_decode(file_get_contents(ViaVersion::get()->getDataFolder() . "data/v428/block_id_map.json"), true);

        /** @var R12ToCurrentBlockMapEntry[] $legacyStateMap */
        $legacyStateMap = [];
        $legacyStateMapReader = new NetworkBinaryStream(file_get_contents(ViaVersion::get()->getDataFolder() . "data/v428/r12_to_current_block_map.bin"));
        $nbtReader = new NetworkLittleEndianNBTStream();

        while (!$legacyStateMapReader->feof()) {
            $id = $legacyStateMapReader->getString();
            $meta = $legacyStateMapReader->getLShort();

            $offset = $legacyStateMapReader->getOffset();
            $state = $nbtReader->read($legacyStateMapReader->getBuffer(), false, $offset);
            $legacyStateMapReader->setOffset($offset);
            if (!($state instanceof CompoundTag)) {
                throw new \RuntimeException("Blockstate should be a TAG_Compound");
            }
            $legacyStateMap[] = new R12ToCurrentBlockMapEntry($id, $meta, $state);
        }

        /**
         * @var int[][] $idToStatesMap string id -> int[] list of candidate state indices
         */
        $idToStatesMap = [];
        foreach (self::$bedrockKnownStates as $k => $state) {
            $idToStatesMap[$state->getString("name")][] = $k;
        }
        foreach ($legacyStateMap as $pair) {
            $id = $legacyIdMap[$pair->getId()] ?? null;
            if ($id === null) {
                throw new \RuntimeException("No legacy ID matches " . $pair->getId());
            }
            $data = $pair->getMeta();
            if ($data > 15) {
                //we can't handle metadata with more than 4 bits
                continue;
            }
            $mappedState = $pair->getBlockState();

            //TODO HACK: idiotic NBT compare behaviour on 3.x compares keys which are stored by values
            $mappedState->setName("");
            $mappedName = $mappedState->getString("name");
            if (!isset($idToStatesMap[$mappedName])) {
                throw new \RuntimeException("Mapped new state does not appear in network table");
            }
            foreach ($idToStatesMap[$mappedName] as $k) {
                $networkState = self::$bedrockKnownStates[$k];
                if ($mappedState->equals($networkState)) {
                    self::registerMapping($k, $id, $data);
                    continue 2;
                }
            }
            throw new \RuntimeException("Mapped new state does not appear in network table");
        }
    }

    private static function lazyInit(): void
    {
        if (self::$bedrockKnownStates === null) {
            self::init();
        }
    }

    public static function toStaticRuntimeId(int $id, int $meta = 0): int
    {
        self::lazyInit();
        /*
         * try id+meta first
         * if not found, try id+0 (strip meta)
         * if still not found, return update! block
         */
        return self::$legacyToRuntimeMap[($id << 4) | $meta] ?? self::$legacyToRuntimeMap[$id << 4] ?? self::$legacyToRuntimeMap[BlockIds::INFO_UPDATE << 4];
    }

    /**
     * @param int $runtimeId
     * @return int[] [id, meta]
     */
    public static function fromStaticRuntimeId(int $runtimeId): array
    {
        self::lazyInit();
        $v = self::$runtimeToLegacyMap[$runtimeId];
        return [$v >> 4, $v & 0xf];
    }

    private static function registerMapping(int $staticRuntimeId, int $legacyId, int $legacyMeta): void
    {
        self::$legacyToRuntimeMap[($legacyId << 4) | $legacyMeta] = $staticRuntimeId;
        self::$runtimeToLegacyMap[$staticRuntimeId] = ($legacyId << 4) | $legacyMeta;
    }

    /**
     * @return CompoundTag[]
     */
    public static function getBedrockKnownStates(): array
    {
        self::lazyInit();
        return self::$bedrockKnownStates;
    }
}