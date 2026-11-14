<?php

declare(strict_types=1);

use SMF\API\ApiException;
use SMF\API\Controllers\GroupController;
use Testo\Assert;
use Testo\Codecov\Covers;
use Testo\Test;

require_once __DIR__ . '/Environment.php';

#[Test]
#[Covers(GroupController::class)]
final class GroupControllerTest
{
    // String values mirror what a real DB driver returns, so the (int)/(bool)
    // casts in transform() are observable. The shape matches SMF 2.1.8.
    private const GROUP_ROW = [
        'id_group'     => '5',
        'group_name'   => 'VIP',
        'description'  => 'Very important posters',
        'group_type'   => '0',
        'min_posts'    => '-1',
        'max_messages' => '100',
        'online_color' => '#ff0000',
        'icons'        => '1#vip.png',
        'hidden'       => '0',
        'id_parent'    => '-2',
        'tfa_required' => '0',
    ];

    private const GROUP = [
        'id'           => 5,
        'name'         => 'VIP',
        'description'  => 'Very important posters',
        'type'         => 0,
        'min_posts'    => -1,
        'max_messages' => 100,
        'color'        => '#ff0000',
        'icons'        => '1#vip.png',
        'hidden'       => 0,
        'parent'       => -2,
        'tfa_required' => false,
    ];

    public function indexReturnsTransformedGroups(): void
    {
        Environment::reset();

        $GLOBALS['api_total'] = 1;
        $GLOBALS['api_rows']  = [self::GROUP_ROW];

        $response = (new GroupController(Environment::request()))->index([]);
        $payload  = Environment::readResponse($response)['payload'];

        Assert::same($payload['data'], [self::GROUP]);
        Assert::same($payload['meta']['total'], 1);
    }

    public function indexHidesHiddenAndModeratorGroupsFromRegularMembers(): void
    {
        Environment::reset();

        $GLOBALS['api_total'] = 1;
        $GLOBALS['api_rows']  = [self::GROUP_ROW];

        (new GroupController(Environment::request()))->index([]);

        // A non-manager only sees visible groups and never group 3.
        Assert::same($GLOBALS['api_queries'][0]['params'], [
            'hidden'          => 0,
            'moderator_group' => 3,
        ]);
    }

    public function indexShowsEverythingToAdministrators(): void
    {
        Environment::reset();

        $GLOBALS['api_permissions'] = ['manage_membergroups', 'admin_forum'];
        $GLOBALS['api_total']       = 1;
        $GLOBALS['api_rows']        = [self::GROUP_ROW];

        (new GroupController(Environment::request()))->index([]);

        Assert::same($GLOBALS['api_queries'][0]['params'], []);
    }

    public function showReturnsAgroup(): void
    {
        Environment::reset();

        $GLOBALS['api_rows'] = [self::GROUP_ROW];

        $response = (new GroupController(Environment::request()))->show(['id' => '5']);

        Assert::same(Environment::readResponse($response)['payload']['data'], self::GROUP);
    }

    public function showHidesAhiddenGroupFromRegularMembers(): void
    {
        Environment::reset();

        $GLOBALS['api_rows'] = [['hidden' => '2'] + self::GROUP_ROW];

        $exception = $this->error(fn () => (new GroupController(Environment::request()))->show(['id' => '5']));

        Assert::same($exception->getStatusCode(), 404);
    }

    public function showRevealsAhiddenGroupToManagers(): void
    {
        Environment::reset();

        $GLOBALS['api_permissions'] = ['manage_membergroups'];
        $GLOBALS['api_rows']        = [['hidden' => '2'] + self::GROUP_ROW];

        $response = (new GroupController(Environment::request()))->show(['id' => '5']);

        Assert::same(Environment::readResponse($response)['payload']['data']['hidden'], 2);
    }

    public function showHidesTheModeratorGroupFromNonAdministrators(): void
    {
        Environment::reset();

        $GLOBALS['api_permissions'] = ['manage_membergroups'];
        $GLOBALS['api_rows']        = [['id_group' => '3'] + self::GROUP_ROW];

        $exception = $this->error(fn () => (new GroupController(Environment::request()))->show(['id' => '3']));

        Assert::same($exception->getStatusCode(), 404);
    }

    public function showRejectsAmissingGroup(): void
    {
        Environment::reset();

        $exception = $this->error(fn () => (new GroupController(Environment::request()))->show(['id' => '5']));

        Assert::same($exception->getStatusCode(), 404);
    }

    public function storeCreatesAregularGroup(): void
    {
        Environment::reset();

        $GLOBALS['api_permissions'] = ['manage_membergroups'];
        $GLOBALS['api_new_id']      = 12;

        $request = Environment::request('POST', [
            'name'         => 'VIP',
            'description'  => 'Very important posters',
            'max_messages' => '100',
            'color'        => '#ff0000',
            'icons'        => '1#vip.png',
        ]);

        $response = (new GroupController($request))->store([]);
        $state    = Environment::readResponse($response);

        Assert::same($state['status'], 201);
        Assert::same($state['payload']['data'], ['id' => 12] + self::GROUP);
        Assert::same($GLOBALS['api_db_insert'][0]['table'], '{db_prefix}membergroups');
        Assert::same($GLOBALS['api_db_insert'][0]['data'], [
            'Very important posters',
            'VIP',
            -1,
            100,
            '1#vip.png',
            '#ff0000',
            0,
            0,
            -2,
            0,
        ]);
        Assert::same($GLOBALS['api_updated_settings']['show_group_membership'] ?? null, null);
        Assert::true(array_key_exists('settings_updated', $GLOBALS['api_updated_settings']));
        Assert::same($GLOBALS['api_update_stats'], []);
    }

    public function storeUsesTheSmfDefaultIcons(): void
    {
        Environment::reset();

        $GLOBALS['api_permissions'] = ['manage_membergroups'];

        $request = Environment::request('POST', ['name' => 'VIP']);
        (new GroupController($request))->store([]);

        // Position 4 in the insert data is the icons column.
        Assert::same($GLOBALS['api_db_insert'][0]['data'][4], '1#icon.png');
    }

    public function storeCreatesApostCountGroup(): void
    {
        Environment::reset();

        $GLOBALS['api_permissions'] = ['manage_membergroups'];

        // min_posts >= 0 makes it post-based; SMF always stores group_type 0.
        $request  = Environment::request('POST', ['name' => 'Senior', 'type' => '2', 'min_posts' => '50']);
        $response = (new GroupController($request))->store([]);

        Assert::same(Environment::readResponse($response)['status'], 201);
        Assert::same($GLOBALS['api_db_insert'][0]['data'][2], 50);
        Assert::same($GLOBALS['api_db_insert'][0]['data'][6], 0);
        Assert::same($GLOBALS['api_update_stats'][0], ['postgroups', null]);
    }

    public function storeNormalizesANegativePostThreshold(): void
    {
        Environment::reset();

        $GLOBALS['api_permissions'] = ['manage_membergroups'];

        $request = Environment::request('POST', ['name' => 'VIP', 'min_posts' => '-20']);
        (new GroupController($request))->store([]);

        Assert::same($GLOBALS['api_db_insert'][0]['data'][2], -1);
    }

    public function storeEnablesGroupMembershipForJoinableGroups(): void
    {
        Environment::reset();

        $GLOBALS['api_permissions'] = ['manage_membergroups'];

        $request = Environment::request('POST', ['name' => 'Joinable', 'type' => '2']);
        (new GroupController($request))->store([]);

        Assert::same($GLOBALS['api_updated_settings']['show_group_membership'] ?? null, 1);
        Assert::true(array_key_exists('settings_updated', $GLOBALS['api_updated_settings']));
    }

    public function storeAllowsAprotectedGroupForAdministrators(): void
    {
        Environment::reset();

        $GLOBALS['api_permissions'] = ['manage_membergroups', 'admin_forum'];

        $request = Environment::request('POST', ['name' => 'Protected', 'type' => '1']);
        (new GroupController($request))->store([]);

        Assert::same($GLOBALS['api_db_insert'][0]['data'][6], 1);
    }

    public function storeRefusesAprotectedGroupForNonAdministrators(): void
    {
        Environment::reset();

        $GLOBALS['api_permissions'] = ['manage_membergroups'];

        $request   = Environment::request('POST', ['name' => 'Protected', 'type' => '1']);
        $exception = $this->error(fn () => (new GroupController($request))->store([]));

        Assert::same($exception->getStatusCode(), 403);
    }

    public function storeRejectsAmissingName(): void
    {
        Environment::reset();

        $GLOBALS['api_permissions'] = ['manage_membergroups'];

        $request   = Environment::request('POST', ['description' => 'no name']);
        $exception = $this->error(fn () => (new GroupController($request))->store([]));

        Assert::same($exception->getStatusCode(), 400);
    }

    public function storeRejectsAduplicateName(): void
    {
        Environment::reset();

        $GLOBALS['api_permissions'] = ['manage_membergroups'];
        $GLOBALS['api_num_rows']    = 1;

        $request   = Environment::request('POST', ['name' => 'VIP']);
        $exception = $this->error(fn () => (new GroupController($request))->store([]));

        Assert::same($exception->getStatusCode(), 400);
    }

    public function storeRejectsAnInvalidType(): void
    {
        Environment::reset();

        $GLOBALS['api_permissions'] = ['manage_membergroups'];

        $request   = Environment::request('POST', ['name' => 'VIP', 'type' => 9]);
        $exception = $this->error(fn () => (new GroupController($request))->store([]));

        Assert::same($exception->getStatusCode(), 400);
    }

    public function storeIsRefusedWithoutPermission(): void
    {
        Environment::reset();

        $request   = Environment::request('POST', ['name' => 'VIP']);
        $exception = $this->error(fn () => (new GroupController($request))->store([]));

        Assert::same($exception->getStatusCode(), 403);
    }

    public function updateAppliesTheSuppliedFields(): void
    {
        Environment::reset();

        $GLOBALS['api_permissions'] = ['manage_membergroups'];
        $GLOBALS['api_rows']        = [
            self::GROUP_ROW,
            ['group_name' => 'Renamed'] + self::GROUP_ROW,
        ];

        $request  = Environment::request('PATCH', ['name' => 'Renamed', 'description' => 'New']);
        $response = (new GroupController($request))->update(['id' => '5']);

        Assert::same(Environment::readResponse($response)['payload']['data']['name'], 'Renamed');
        $update = $this->updateQuery();

        Assert::same($update['params'], [
            'id'          => 5,
            'group_name'  => 'Renamed',
            'description' => 'New',
        ]);
        Assert::true(str_contains($update['query'], 'group_name = {string:group_name}'));
        Assert::true(str_contains($update['query'], 'description = {string:description}'));
    }

    public function updateTurnsApostGroupIntoAregularGroup(): void
    {
        Environment::reset();

        $GLOBALS['api_permissions'] = ['manage_membergroups'];
        $GLOBALS['api_rows']        = [
            ['min_posts' => '50'] + self::GROUP_ROW,
            self::GROUP_ROW,
        ];

        // Supplying "type" without min_posts resets the threshold to -1.
        $request = Environment::request('PATCH', ['type' => '2']);
        (new GroupController($request))->update(['id' => '5']);

        Assert::same($this->updateQuery()['params'], [
            'id'         => 5,
            'group_type' => 2,
            'min_posts'  => -1,
        ]);
        Assert::same($GLOBALS['api_update_stats'][0], ['postgroups', null]);
    }

    public function updateTurnsAregularGroupIntoApostGroup(): void
    {
        Environment::reset();

        $GLOBALS['api_permissions'] = ['manage_membergroups'];
        $GLOBALS['api_rows']        = [self::GROUP_ROW, self::GROUP_ROW];

        $request = Environment::request('PATCH', ['min_posts' => '100']);
        (new GroupController($request))->update(['id' => '5']);

        Assert::same($this->updateQuery()['params'], [
            'id'         => 5,
            'group_type' => 0,
            'min_posts'  => 100,
        ]);
        Assert::same($GLOBALS['api_update_stats'][0], ['postgroups', null]);
    }

    public function updateKeepsThePostThresholdWhenOnlyOtherFieldsChange(): void
    {
        Environment::reset();

        $GLOBALS['api_permissions'] = ['manage_membergroups'];
        $GLOBALS['api_rows']        = [self::GROUP_ROW, self::GROUP_ROW];

        $request = Environment::request('PATCH', ['max_messages' => '7']);
        (new GroupController($request))->update(['id' => '5']);

        Assert::same($this->updateQuery()['params'], [
            'id'           => 5,
            'max_messages' => 7,
        ]);
        Assert::same($GLOBALS['api_update_stats'], []);
    }

    public function updateClampsNegativeNumbers(): void
    {
        Environment::reset();

        $GLOBALS['api_permissions'] = ['manage_membergroups'];
        $GLOBALS['api_rows']        = [self::GROUP_ROW, self::GROUP_ROW];

        $request = Environment::request('PATCH', ['max_messages' => '-5', 'min_posts' => '-9']);
        (new GroupController($request))->update(['id' => '5']);

        $params = $this->updateQuery()['params'];

        Assert::same($params['max_messages'], 0);
        Assert::same($params['min_posts'], -1);
    }

    public function updateRejectsAduplicateName(): void
    {
        Environment::reset();

        $GLOBALS['api_permissions'] = ['manage_membergroups'];
        $GLOBALS['api_rows']        = [self::GROUP_ROW];
        $GLOBALS['api_num_rows']    = 1;

        $request   = Environment::request('PATCH', ['name' => 'Taken']);
        $exception = $this->error(fn () => (new GroupController($request))->update(['id' => '5']));

        Assert::same($exception->getStatusCode(), 400);
    }

    public function updateRejectsAmissingGroup(): void
    {
        Environment::reset();

        $GLOBALS['api_permissions'] = ['manage_membergroups'];

        $request   = Environment::request('PATCH', ['name' => 'Renamed']);
        $exception = $this->error(fn () => (new GroupController($request))->update(['id' => '5']));

        Assert::same($exception->getStatusCode(), 404);
    }

    public function updateRefusesAprotectedGroupForNonAdministrators(): void
    {
        Environment::reset();

        $GLOBALS['api_permissions'] = ['manage_membergroups'];
        $GLOBALS['api_rows']        = [['group_type' => '1'] + self::GROUP_ROW];

        $request   = Environment::request('PATCH', ['name' => 'Renamed']);
        $exception = $this->error(fn () => (new GroupController($request))->update(['id' => '5']));

        Assert::same($exception->getStatusCode(), 403);
    }

    public function updateLetsAdministratorsEditAprotectedGroup(): void
    {
        Environment::reset();

        $GLOBALS['api_permissions'] = ['manage_membergroups', 'admin_forum'];
        $GLOBALS['api_rows']        = [
            ['group_type' => '1'] + self::GROUP_ROW,
            ['group_name' => 'Renamed'] + self::GROUP_ROW,
        ];

        $request  = Environment::request('PATCH', ['name' => 'Renamed']);
        $response = (new GroupController($request))->update(['id' => '5']);

        Assert::same(Environment::readResponse($response)['payload']['data']['name'], 'Renamed');
    }

    public function updateRefusesCreatingAprotectedGroupForNonAdministrators(): void
    {
        Environment::reset();

        $GLOBALS['api_permissions'] = ['manage_membergroups'];
        $GLOBALS['api_rows']        = [self::GROUP_ROW];

        $request   = Environment::request('PATCH', ['type' => '1']);
        $exception = $this->error(fn () => (new GroupController($request))->update(['id' => '5']));

        Assert::same($exception->getStatusCode(), 403);
    }

    public function updateIsRefusedWithoutPermission(): void
    {
        Environment::reset();

        $GLOBALS['api_rows'] = [self::GROUP_ROW];

        $request   = Environment::request('PATCH', ['name' => 'Renamed']);
        $exception = $this->error(fn () => (new GroupController($request))->update(['id' => '5']));

        Assert::same($exception->getStatusCode(), 403);
    }

    public function replaceDemandsEveryWritableField(): void
    {
        Environment::reset();

        $GLOBALS['api_permissions'] = ['manage_membergroups'];
        $GLOBALS['api_rows']        = [self::GROUP_ROW];

        $request   = Environment::request('PUT', ['name' => 'VIP']);
        $exception = $this->error(fn () => (new GroupController($request))->replace(['id' => '5']));

        Assert::same($exception->getStatusCode(), 400);
    }

    public function replaceAppliesEveryField(): void
    {
        Environment::reset();

        $GLOBALS['api_permissions'] = ['manage_membergroups'];
        $GLOBALS['api_rows']        = [self::GROUP_ROW, self::GROUP_ROW];

        $request = Environment::request('PUT', [
            'name'         => 'Senior',
            'description'  => 'Old guard',
            'type'         => 0,
            'min_posts'    => 50,
            'max_messages' => 200,
            'color'        => '#00ff00',
            'icons'        => '2#senior.png',
        ]);

        (new GroupController($request))->replace(['id' => '5']);

        Assert::same($this->updateQuery()['params'], [
            'id'           => 5,
            'group_name'   => 'Senior',
            'description'  => 'Old guard',
            'group_type'   => 0,
            'min_posts'    => 50,
            'max_messages' => 200,
            'online_color' => '#00ff00',
            'icons'        => '2#senior.png',
        ]);
    }

    public function destroyRemovesAgroup(): void
    {
        Environment::reset();

        $GLOBALS['api_permissions'] = ['manage_membergroups'];
        $GLOBALS['api_rows']        = [self::GROUP_ROW];

        $response = (new GroupController(Environment::request()))->destroy(['id' => '5']);

        Assert::same(Environment::readResponse($response)['status'], 204);
        Assert::same($GLOBALS['api_delete_membergroups'][0], [5]);
    }

    public function destroyRefusesAprotectedGroupForNonAdministrators(): void
    {
        Environment::reset();

        $GLOBALS['api_permissions'] = ['manage_membergroups'];
        $GLOBALS['api_rows']        = [['group_type' => '1'] + self::GROUP_ROW];

        $exception = $this->error(fn () => (new GroupController(Environment::request()))->destroy(['id' => '5']));

        Assert::same($exception->getStatusCode(), 403);
    }

    public function destroyReportsAprotectedGroupAsUndeletableForAdministrators(): void
    {
        Environment::reset();

        $GLOBALS['api_permissions']                = ['manage_membergroups', 'admin_forum'];
        $GLOBALS['api_rows']                       = [['group_type' => '1'] + self::GROUP_ROW];
        $GLOBALS['api_delete_membergroups_result'] = 'no_group_found';

        $exception = $this->error(fn () => (new GroupController(Environment::request()))->destroy(['id' => '5']));

        Assert::same($exception->getStatusCode(), 400);
    }

    public function destroyReportsApaidSubscriptionGroup(): void
    {
        Environment::reset();

        $GLOBALS['api_permissions']                = ['manage_membergroups'];
        $GLOBALS['api_rows']                       = [self::GROUP_ROW];
        $GLOBALS['api_delete_membergroups_result'] = 'group_cannot_delete_sub';

        $exception = $this->error(fn () => (new GroupController(Environment::request()))->destroy(['id' => '5']));

        Assert::same($exception->getStatusCode(), 400);
    }

    public function destroyRejectsAmissingGroup(): void
    {
        Environment::reset();

        $GLOBALS['api_permissions'] = ['manage_membergroups'];

        $exception = $this->error(fn () => (new GroupController(Environment::request()))->destroy(['id' => '5']));

        Assert::same($exception->getStatusCode(), 404);
    }

    public function destroyIsRefusedWithoutPermission(): void
    {
        Environment::reset();

        $exception = $this->error(fn () => (new GroupController(Environment::request()))->destroy(['id' => '5']));

        Assert::same($exception->getStatusCode(), 403);
    }

    public function updateWritesTheHiddenFlagParentAndTwoFactor(): void
    {
        Environment::reset();

        $GLOBALS['api_permissions'] = ['manage_membergroups'];
        $GLOBALS['api_rows']        = [self::GROUP_ROW, self::GROUP_ROW];

        (new GroupController(Environment::request('PATCH', ['hidden' => true, 'parent' => 2, 'tfa_required' => true])))->update(['id' => '5']);

        Assert::same($this->updateQuery()['params'], [
            'id'           => 5,
            'id_parent'    => 2,
            'hidden'       => 1,
            'tfa_required' => 1,
        ]);
    }

    /**
     * @return array{query: string, params: array<string, mixed>}
     */
    private function updateQuery(): array
    {
        foreach (array_reverse($GLOBALS['api_queries']) as $recorded) {
            if (str_contains($recorded['query'], 'UPDATE {db_prefix}membergroups')) {
                return $recorded;
            }
        }

        Assert::fail('Expected an UPDATE query');
    }

    private function error(callable $callback): ApiException
    {
        try {
            $callback();
        } catch (ApiException $exception) {
            return $exception;
        }

        Assert::fail('Expected the request to be rejected');
    }
}
