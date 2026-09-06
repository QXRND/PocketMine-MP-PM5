<?php

/*
 * Copyright (c) 2024 - present pocketmine
 *        _      _           _                ___   ___ ____
 *       (_)    | |         | |              / _ \ / _ \___ \
 *  _ __  _  ___| |__   ___ | | __ _ ___ ___| | | | | | |__) |
 * | '_ \| |/ __| '_ \ / _ \| |/ _` / __/ __| | | | | | |__ <
 * | | | | | (__| | | | (_) | | (_| \__ \__ \ |_| | |_| |__) |
 * |_| |_|_|\___|_| |_|\___/|_|\__,_|___/___/\___/ \___/____/
 *
 * This program is free software: you can redistribute it and/or modify
 * it under the terms of the GNU Lesser General Public License as published by
 * the Free Software Foundation, either version 3 of the License, or
 * (at your option) any later version.
 *
 * @author  DevPapo
 * @link    https://github.com/pocketmine/
 *
 *
 */

declare(strict_types=1);

namespace pocketmine\redstone\component;

use pocketmine\block\Block;

interface RedstoneComponent{

    public const ACTION_BREAK = 0;
    public const ACTION_PLACE = 1;

    public function getBlock() : Block;

    public function scheduleUpdate(int $delayTick = 1) : void;

    public function handleComponents(int $action) : void;
}
