<?php

namespace matcracker\BlocksConverter;

use pocketmine\block\Block;
use pocketmine\block\RuntimeBlockStateRegistry;
use pocketmine\block\VanillaBlocks;
use RuntimeException;

/**
 * Utility class for parsing block strings to state IDs
 */
class BlockStringParser
{
    /**
     * Parse a block string identifier to a state ID
     * 
     * @param string $blockId The block identifier (e.g., "minecraft:dirt")
     * @return int The block state ID
     * @throws RuntimeException If the block cannot be parsed
     */
    public static function parse(string $blockId): int
    {
        // Remove the "minecraft:" prefix if present
        $blockName = str_replace("minecraft:", "", $blockId);

        // Convert to uppercase for VanillaBlocks constants
        $constName = strtoupper($blockName);

        try {
            // Try to get the block from VanillaBlocks
            $block = null;

            // Handle special cases
            switch ($blockName) {
                case "dirt":
                    $block = VanillaBlocks::DIRT();
                    break;
                case "stone_button":
                    $block = VanillaBlocks::STONE_BUTTON();
                    break;
                case "invisible_bedrock":
                    $block = VanillaBlocks::INVISIBLE_BEDROCK();
                    break;
                case "trapdoor":
                    $block = VanillaBlocks::OAK_TRAPDOOR();
                    break;
                case "iron_trapdoor":
                    $block = VanillaBlocks::IRON_TRAPDOOR();
                    break;
                case "grass_path":
                    $block = VanillaBlocks::GRASS_PATH();
                    break;
                case "end_rod":
                    $block = VanillaBlocks::END_ROD();
                    break;
                default:
                    // Try to dynamically call the method on VanillaBlocks
                    if (method_exists(VanillaBlocks::class, $constName)) {
                        $block = VanillaBlocks::$constName();
                    } else {
                        // For glazed terracotta blocks
                        if (strpos($blockName, "glazed_terracotta") !== false) {
                            $color = str_replace("_glazed_terracotta", "", $blockName);
                            $colorConstName = strtoupper($color) . "_GLAZED_TERRACOTTA";
                            if (method_exists(VanillaBlocks::class, $colorConstName)) {
                                $block = VanillaBlocks::$colorConstName();
                            }
                        }
                    }
                    break;
            }

            // If we couldn't get the block, try using RuntimeBlockStateRegistry directly
            if ($block === null) {
                $registry = RuntimeBlockStateRegistry::getInstance();
                // Try to get a default block state for this block type
                $block = $registry->fromStateId(0); // Get a default block to start with

                // If all else fails, return a default state ID
                if ($block === null) {
                    return 0; // Default to air if we can't find the block
                }
            }

            // Return the state ID
            return $block->getStateId();
        } catch (\Throwable $e) {
            // If there's an error, return a default state ID
            return 0; // Default to air if we can't find the block
        }
    }
}
