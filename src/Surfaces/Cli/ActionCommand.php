<?php

namespace Gtapps\LaravelAgentic\Surfaces\Cli;

use Gtapps\LaravelAgentic\AgenticManager;
use Gtapps\LaravelAgentic\Approvals\ApprovalRequiredException;
use Gtapps\LaravelAgentic\Enums\Surface;
use Gtapps\LaravelAgentic\Exceptions\ActionDenied;
use Gtapps\LaravelAgentic\Exceptions\ActionNotFound;
use Gtapps\LaravelAgentic\Kernel\ContextFactory;
use Illuminate\Console\Command;
use Illuminate\Contracts\Auth\Factory as AuthFactory;
use Illuminate\Validation\ValidationException;

/**
 * Local-trust surface: the process boundary is the auth line, --as
 * impersonates through the configured user provider.
 */
class ActionCommand extends Command
{
    protected $signature = 'agentic:action {name} {json? : Raw JSON arguments} {--as= : Run as this user id}';

    protected $description = 'Run an agentic action from the CLI';

    public function handle(AgenticManager $agentic, ContextFactory $contexts, AuthFactory $auth): int
    {
        $args = json_decode($this->argument('json') ?? '{}', true);

        if (! is_array($args)) {
            $this->error('Arguments must be a JSON object.');

            return self::FAILURE;
        }

        $user = $this->option('as') !== null
            ? $auth->guard()->getProvider()->retrieveById($this->option('as'))
            : null;

        $context = $contexts->make(Surface::Cli, $user);

        try {
            $result = $agentic->run($this->argument('name'), $args, $context);
        } catch (ApprovalRequiredException $e) {
            $this->warn($e->getMessage());
            $this->line("Approve with: php artisan agentic:approve {$e->key}");

            return self::FAILURE;
        } catch (ActionNotFound|ActionDenied $e) {
            $this->error($e->getMessage());

            return self::FAILURE;
        } catch (ValidationException $e) {
            $this->error('Invalid arguments: '.json_encode($e->errors()));

            return self::FAILURE;
        }

        $this->line(json_encode($result->value, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE));

        return self::SUCCESS;
    }
}
