<?php

namespace Gtapps\LaravelAgentic\Enums;

enum Mismatch: string
{
    case Warn = 'warn';
    case Strict = 'strict';
    case Fallback = 'fallback';
}
