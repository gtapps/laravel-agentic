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
     * The method an action may define to authorize this surface specifically,
     * overriding its generic authorize(). Spelled out rather than derived so
     * every name is greppable from here; PHP method lookup is case-insensitive,
     * so authorizeMCP() answers to authorizeMcp too.
     */
    public function authorizeMethod(): string
    {
        return match ($this) {
            self::Mcp => 'authorizeMcp',
            self::AiTool => 'authorizeAiTool',
            self::Http => 'authorizeHttp',
            self::Cli => 'authorizeCli',
            self::Job => 'authorizeJob',
        };
    }

    /**
     * @param  Surface[]  $surfaces
     * @return list<string>
     */
    public static function values(array $surfaces): array
    {
        return array_map(fn (self $s) => $s->value, $surfaces);
    }
}
