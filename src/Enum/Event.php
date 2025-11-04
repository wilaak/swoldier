<?php

namespace Swoldier\Enum;

enum Event
{
    case Start;

    case Shutdown;

    case WorkerStart;

    case WorkerStop;
}