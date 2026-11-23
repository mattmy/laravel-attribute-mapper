<?php

declare(strict_types=1);

namespace Mattmy\FillMap;

enum InputKeyStyle
{
    case Exact;
    case Camel;
    case Snake;
    case Pascal;
    case Kebab;
}
