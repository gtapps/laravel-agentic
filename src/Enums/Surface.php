<?php

namespace Gtapps\LaravelAgentic\Enums;

/**
 * Represents both the surfaces an action is exposed on and the caller
 * surface recorded in ActionContext. Both use the same five cases.
 */
enum Surface: string
{
    case Mcp = 'mcp';
    case AiTool = 'ai-tool';
    case Http = 'http';
    case Cli = 'cli';
    case Job = 'job';

    /**
     * @param  Surface[]  $surfaces
     * @return list<string>
     */
    public static function values(array $surfaces): array
    {
        return array_map(fn (self $s) => $s->value, $surfaces);
    }
}
