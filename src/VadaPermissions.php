<?php

namespace Bale\Vada;

class VadaPermissions
{
    public const ACCESS = 'vada.access';

    public const MANAGE = 'vada.manage';

    public const ALL = [
        self::ACCESS,
        self::MANAGE,
    ];
}
