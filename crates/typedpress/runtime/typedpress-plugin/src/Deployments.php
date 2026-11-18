<?php
declare(strict_types=1);

namespace TypedPressPlugin;

/**
 * The site's deployments as a screen in TypedPress Admin (`/wp-admin/new/`), the admin a TypedPress app ships by
 * default: every deployment and switch, newest first, with "Make live" on an earlier build. It is the
 * same list the TypedPress screen in classic wp-admin shows ({@see Setup}), read through two abilities so
 * the admin runs them as the signed-in person:
 *
 *   tp/deployments          read-only: the list
 *   tp/deployments-live     make an earlier build live again ({@see Receiver::rollback()})
 *
 * Both are for people who may deploy, and neither is offered to agents: making a build live changes
 * the code the site runs.
 */
final class Deployments
{
    public const LIST = 'tp/deployments';
    public const LIVE = 'tp/deployments-live';

    public static function register(): void
    {
        add_action('wp_abilities_api_categories_init', static function (): void {
            wp_register_ability_category('typedpress', [
                'label' => 'TypedPress',
                'description' => 'The TypedPress app this site runs: its deployments.',
            ]);
        });
        add_action('wp_abilities_api_init', [self::class, 'abilities']);
        add_action('tp_admin_screens', [self::class, 'screen']);
    }

    public static function abilities(): void
    {
        $allowed = static fn (): bool|\WP_Error => Receiver::authorized(false);
        $hidden = ['public' => false, 'show_in_rest' => true, 'mcp' => ['public' => false]];
        wp_register_ability(self::LIST, [
            'label' => 'List deployments',
            'description' => 'Every deployment of a TypedPress app to this site and every switch to an earlier build, newest first.',
            'category' => 'typedpress',
            'input_schema' => ['type' => ['object', 'null'], 'properties' => (object) [], 'additionalProperties' => false],
            'output_schema' => ['type' => 'object', 'properties' => ['deployments' => ['type' => 'array', 'items' => ['type' => 'object']]]],
            'permission_callback' => $allowed,
            'execute_callback' => static fn (): array => ['deployments' => self::rows()],
            'meta' => $hidden + ['annotations' => ['readonly' => true, 'destructive' => false, 'idempotent' => true]],
        ]);
        wp_register_ability(self::LIVE, [
            'label' => 'Make an earlier build live',
            'description' => 'Switch the site to a build that was live here before. Code only: database migrations are not undone.',
            'category' => 'typedpress',
            'input_schema' => ['type' => 'object', 'required' => ['build'], 'additionalProperties' => false,
                'properties' => ['build' => ['type' => 'string', 'pattern' => '^[0-9a-f]{64}$']]],
            'output_schema' => ['type' => 'object', 'properties' => ['live' => ['type' => 'string']]],
            'permission_callback' => static fn (): bool|\WP_Error => Receiver::authorized(true),
            'execute_callback' => static function (mixed $input): array|\WP_Error {
                $build = is_array($input) ? (string) ($input['build'] ?? '') : '';
                try {
                    Receiver::rollback($build);
                } catch (\RuntimeException $error) {
                    return new \WP_Error('tp_unknown_build', $error->getMessage(), ['status' => 409]);
                }

                return ['live' => $build];
            },
            'meta' => $hidden + ['annotations' => ['readonly' => false, 'destructive' => false, 'idempotent' => true]],
        ]);
    }

    /**
     * The history, newest first, one row per entry. `id` is the entry's place in the history, because
     * one build can be in it several times (deployed, then made live again).
     *
     * @return list<array{id: int, at: string, build: string, short: string, app: string, by: string, how: string, state: string}>
     */
    public static function rows(): array
    {
        $live = Site::live();
        $rows = [];
        foreach (Site::history() as $i => $entry) {
            $build = (string) ($entry['build'] ?? '');
            $user = get_userdata((int) ($entry['user'] ?? 0));
            $rows[] = [
                'id' => $i + 1,
                'at' => (string) ($entry['at'] ?? ''),
                'build' => $build,
                'short' => substr($build, 0, 12),
                'app' => (string) ($entry['name'] ?? ''),
                'by' => $user instanceof \WP_User ? $user->user_login : 'user ' . (int) ($entry['user'] ?? 0),
                'how' => ($entry['kind'] ?? '') === 'rollback' ? 'Made live again' : 'Deployed',
                // What the build is now, which decides whether it can be made live from here.
                'state' => $build === $live ? 'Live' : (Site::ready($build) && ($manifest = Site::manifest($build)) !== null && Site::complete($manifest) ? 'Stored' : 'Files gone'),
            ];
        }

        return array_reverse($rows);
    }

    public static function screen(): void
    {
        if (!function_exists('tp_admin_register_screen')) {
            return;
        }
        tp_admin_register_screen('typedpress-deployments', [
            'title' => 'Deployments',
            'description' => 'Every deployment to this site, newest first.',
            'capability' => Site::CAPABILITY,
            // Classic wp-admin already has the TypedPress screen, which lists them with the setup steps.
            'classic' => false,
            'menu' => ['icon' => 'dashicons-cloud-upload'],
            'view' => [
                'ability' => self::LIST,
                'items' => 'deployments',
                'id' => 'id',
                'fields' => [
                    ['id' => 'short', 'label' => 'Build'],
                    ['id' => 'at', 'label' => 'When', 'type' => 'datetime'],
                    ['id' => 'app', 'label' => 'App'],
                    ['id' => 'by', 'label' => 'Who'],
                    ['id' => 'how', 'label' => 'How'],
                    ['id' => 'state', 'label' => 'Now'],
                ],
                'layout' => ['type' => 'table', 'fields' => ['at', 'app', 'by', 'how', 'state'], 'titleField' => 'short',
                    'sort' => ['field' => 'at', 'direction' => 'desc']],
            ],
            'actions' => [[
                'id' => 'live', 'label' => 'Make live', 'ability' => self::LIVE, 'input' => ['build' => '{item.build}'],
                'confirm' => 'Make this build the live one? The site switches to its code. Database migrations are not undone.',
            ]],
            'data' => static function (): array {
                $lines = ['Deploy from a project with typedpress push. Making an earlier build live switches the code only: database migrations are not undone.'];
                foreach (array_filter([Site::closed(), Site::exposure()]) as $warning) {
                    $lines[] = $warning;
                }

                return $lines;
            },
        ]);
    }
}
