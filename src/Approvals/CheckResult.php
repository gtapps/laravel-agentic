<?php

namespace Gtapps\LaravelAgentic\Approvals;

enum CheckResult
{
    case Granted;
    case Pending;
    case None;
}
