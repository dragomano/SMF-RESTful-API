<?php

declare(strict_types=1);

use SMF\API\ApiException;
use SMF\API\Controllers\CategoryController;
use SMF\API\Response;
use Testo\Assert;
use Testo\Codecov\Covers;
use Testo\Test;

require_once __DIR__ . '/Environment.php';

#[Test]
#[Covers(CategoryController::class)]
final class CategoryControllerTest
{
    public function indexReturnsTransformedCategoriesWithPagination(): void
    {
        Environment::reset();

        $GLOBALS['api_total'] = 1;

        // Real DB drivers hand back strings; keep the fixtures string-typed so the
        // (int) casts in transform() are actually exercised.
        $GLOBALS['api_rows']  = [
            ['id_cat' => '1', 'name' => 'General', 'description' => 'Talk', 'cat_order' => '0', 'can_collapse' => '1'],
        ];

        $response = (new CategoryController(Environment::request()))->index([]);
        $payload  = Environment::readResponse($response)['payload'];

        Assert::same($payload['data'], [
            ['id' => 1, 'name' => 'General', 'description' => 'Talk', 'order' => 0, 'can_collapse' => true],
        ]);
        Assert::same($payload['meta']['total'], 1);
    }

    public function showReturnsAcategoryWithItsVisibleBoards(): void
    {
        Environment::reset();

        $GLOBALS['api_rows'] = [
            [
                'id_cat'       => '3',
                'name'         => 'News',
                'description'  => 'd',
                'cat_order'    => '2',
                'can_collapse' => '0',
            ],
            [
                'id_board'    => '10',
                'name'        => 'Announcements',
                'description' => 'bd',
                'num_topics'  => '5',
                'num_posts'   => '20',
                'board_order' => '1',
            ],
        ];

        $response = (new CategoryController(Environment::request()))->show(['id' => '3']);
        $payload  = Environment::readResponse($response)['payload']['data'];

        Assert::same($payload, [
            'id'           => 3,
            'name'         => 'News',
            'description'  => 'd',
            'order'        => 2,
            'can_collapse' => false,
            'boards'       => [
                [
                    'id'          => 10,
                    'name'        => 'Announcements',
                    'description' => 'bd',
                    'num_topics'  => 5,
                    'num_posts'   => 20,
                    'order'       => 1,
                ],
            ],
        ]);
    }

    public function showRejectsAmissingCategory(): void
    {
        Environment::reset();

        $exception = $this->error(fn () => (new CategoryController(Environment::request()))->show(['id' => '99']));

        Assert::same($exception->getStatusCode(), 404);
    }

    public function storeCreatesAcategoryAndAnswersTwoZeroOne(): void
    {
        Environment::reset();

        $GLOBALS['api_permissions'] = ['manage_boards'];
        $GLOBALS['api_new_id']      = 12;

        // The created category is read back through the same query as show().
        $GLOBALS['api_rows'] = [
            ['id_cat' => '12', 'name' => 'Support', 'description' => 'Help', 'cat_order' => '0', 'can_collapse' => '1'],
        ];

        $request  = Environment::request('POST', ['name' => 'Support', 'description' => 'Help']);
        $response = (new CategoryController($request))->store([]);
        $state    = Environment::readResponse($response);

        Assert::same($state['status'], 201);
        Assert::same($state['payload']['data'], [
            'id'           => 12,
            'name'         => 'Support',
            'description'  => 'Help',
            'order'        => 0,
            'can_collapse' => true,
        ]);
        Assert::same($GLOBALS['api_create_category'][0], ['cat_name' => 'Support', 'cat_desc' => 'Help']);
    }

    public function storeAcceptsTheOrderAndCollapseFlag(): void
    {

        Environment::reset();
        $GLOBALS['api_permissions'] = ['manage_boards'];
        $GLOBALS['api_new_id']      = 12;

        // moveAfter() lists the existing categories; the new one is read back next.
        $GLOBALS['api_rows'] = [
            ['id_cat' => '1'],
            ['id_cat' => '2'],
            null,
            ['id_cat' => '12', 'name' => 'Support', 'description' => '', 'cat_order' => '1', 'can_collapse' => '1'],
        ];

        $request = Environment::request('POST', ['name' => 'Support', 'order' => 1, 'can_collapse' => true]);

        (new CategoryController($request))->store([]);

        Assert::same($GLOBALS['api_create_category'][0], [
            'cat_name'       => 'Support',
            'cat_desc'       => '',
            'is_collapsible' => true,
            'move_after'     => 1,
        ]);
    }

    public function storeIsRefusedWithoutTheManageBoardsPermission(): void
    {
        Environment::reset();

        $request   = Environment::request('POST', ['name' => 'Support']);
        $exception = $this->error(fn () => (new CategoryController($request))->store([]));

        Assert::same($exception->getStatusCode(), 403);
    }

    public function storeRequiresAname(): void
    {
        Environment::reset();

        $GLOBALS['api_permissions'] = ['manage_boards'];

        $request   = Environment::request('POST', ['description' => 'no name']);
        $exception = $this->error(fn () => (new CategoryController($request))->store([]));

        Assert::same($exception->getStatusCode(), 400);
    }

    public function updateAppliesTheSuppliedFieldsAndReturnsTheStoredCategory(): void
    {
        Environment::reset();

        $GLOBALS['api_permissions'] = ['manage_boards'];
        $GLOBALS['api_num_rows']    = 1;

        $GLOBALS['api_rows'] = [
            ['id_cat' => 3, 'name' => 'Renamed', 'description' => 'd', 'cat_order' => 0, 'can_collapse' => 1],
        ];

        $request  = Environment::request('PATCH', ['name' => 'Renamed']);
        $response = (new CategoryController($request))->update(['id' => '3']);

        Assert::same(Environment::readResponse($response)['payload']['data']['name'], 'Renamed');
        Assert::same($GLOBALS['api_modify_category'][0], [3, ['cat_name' => 'Renamed']]);
    }

    public function replaceRejectsAmissingCategory(): void
    {
        Environment::reset();

        $GLOBALS['api_permissions'] = ['manage_boards'];
        $GLOBALS['api_num_rows']    = 0;

        $request   = Environment::request('PUT', ['name' => 'X', 'description' => 'Y']);
        $exception = $this->error(fn () => (new CategoryController($request))->replace(['id' => '3']));

        Assert::same($exception->getStatusCode(), 404);
    }

    public function updateRejectsAnEmptyName(): void
    {
        Environment::reset();

        $GLOBALS['api_permissions'] = ['manage_boards'];
        $GLOBALS['api_num_rows']    = 1;

        $request   = Environment::request('PATCH', ['name' => '   ']);
        $exception = $this->error(fn () => (new CategoryController($request))->update(['id' => '3']));

        Assert::same($exception->getStatusCode(), 400);
    }

    public function destroyRemovesTheCategoryAndItsBoards(): void
    {
        Environment::reset();

        $GLOBALS['api_permissions'] = ['manage_boards'];
        $GLOBALS['api_num_rows']    = 1;

        $response = (new CategoryController(Environment::request()))->destroy(['id' => '3']);

        Assert::same(Environment::readResponse($response)['status'], 204);
        Assert::same($GLOBALS['api_delete_categories'][0], [[3], null]);
    }

    public function destroyMovesBoardsToAnotherCategoryWhenAsked(): void
    {
        Environment::reset();

        $GLOBALS['api_permissions']    = ['manage_boards'];
        $GLOBALS['api_num_rows_queue'] = [1, 1];

        $request  = Environment::request('DELETE', [], ['move_boards_to' => '5']);
        $response = (new CategoryController($request))->destroy(['id' => '3']);

        Assert::same(Environment::readResponse($response)['status'], 204);
        Assert::same($GLOBALS['api_delete_categories'][0], [[3], 5]);
    }

    public function destroyRejectsMovingBoardsIntoTheCategoryBeingDeleted(): void
    {
        Environment::reset();

        $GLOBALS['api_permissions']    = ['manage_boards'];
        $GLOBALS['api_num_rows_queue'] = [1];

        $request   = Environment::request('DELETE', [], ['move_boards_to' => '3']);
        $exception = $this->error(fn () => (new CategoryController($request))->destroy(['id' => '3']));

        Assert::same($exception->getStatusCode(), 400);
    }

    public function replaceRequiresEveryWritableField(): void
    {
        Environment::reset();

        $GLOBALS['api_permissions'] = ['manage_boards'];
        $GLOBALS['api_num_rows']    = 1;

        // PUT is a full replace: omitting "description" must be rejected.
        $request   = Environment::request('PUT', ['name' => 'Only name']);
        $exception = $this->error(fn () => (new CategoryController($request))->replace(['id' => '3']));

        Assert::same($exception->getStatusCode(), 400);
    }

    public function destroyRejectsAnUnknownMoveTarget(): void
    {
        Environment::reset();

        $GLOBALS['api_permissions'] = ['manage_boards'];

        // The category exists (1), the move target does not (0).
        $GLOBALS['api_num_rows_queue'] = [1, 0];

        $request   = Environment::request('DELETE', [], ['move_boards_to' => '99']);
        $exception = $this->error(fn () => (new CategoryController($request))->destroy(['id' => '3']));

        Assert::same($exception->getStatusCode(), 400);
    }

    public function replaceKeepsOrderOptional(): void
    {
        Environment::reset();

        $GLOBALS['api_permissions'] = ['manage_boards'];
        $GLOBALS['api_num_rows']    = 1;

        $GLOBALS['api_rows'] = [
            ['id_cat' => '3', 'name' => 'X', 'description' => 'Y', 'cat_order' => '0', 'can_collapse' => '1'],
        ];

        $response = (new CategoryController(Environment::request('PUT', ['name' => 'X', 'description' => 'Y'])))->replace(['id' => '3']);

        Assert::same(Environment::readResponse($response)['status'], 200);
        Assert::same($GLOBALS['api_modify_category'][0], [3, ['cat_name' => 'X', 'cat_desc' => 'Y']]);
    }

    public function orderTranslatesToMoveAfterExcludingTheCurrentCategory(): void
    {
        // Categories in display order: 5, 3 (the one being moved), 8. Moving 3
        // to the top gives move_after = 0 (SMF's own "top" marker); moving it
        // to the last position targets the last of the remaining categories.
        foreach ([[0, 0], [2, 8]] as [$order, $after]) {
            Environment::reset();

            $GLOBALS['api_permissions'] = ['manage_boards'];
            $GLOBALS['api_num_rows']    = 1;

            $GLOBALS['api_rows'] = [
                ['id_cat' => '5'], ['id_cat' => '3'], ['id_cat' => '8'], null,
                ['id_cat' => '3', 'name' => 'X', 'description' => '', 'cat_order' => (string) $order, 'can_collapse' => '1'],
            ];

            $response = (new CategoryController(Environment::request('PATCH', ['order' => $order])))->update(['id' => '3']);

            Assert::same(Environment::readResponse($response)['payload']['data']['order'], $order);
            Assert::same($GLOBALS['api_modify_category'][0], [3, ['move_after' => $after]]);
        }
    }

    public function orderAcceptsTheSmfColumnName(): void
    {
        Environment::reset();

        $GLOBALS['api_permissions'] = ['manage_boards'];
        $GLOBALS['api_num_rows']    = 1;

        $GLOBALS['api_rows'] = [
            ['id_cat' => '5'], ['id_cat' => '3'], null,
            ['id_cat' => '3', 'name' => 'X', 'description' => '', 'cat_order' => '0', 'can_collapse' => '1'],
        ];

        (new CategoryController(Environment::request('PATCH', ['cat_order' => 0])))->update(['id' => '3']);

        Assert::same($GLOBALS['api_modify_category'][0], [3, ['move_after' => 0]]);
    }

    public function orderInTheCurrentPositionDoesNotTouchTheCategory(): void
    {
        Environment::reset();

        $GLOBALS['api_permissions'] = ['manage_boards'];
        $GLOBALS['api_num_rows']    = 1;

        $GLOBALS['api_rows'] = [
            ['id_cat' => '3'], ['id_cat' => '5'], null,
            ['id_cat' => '3', 'name' => 'X', 'description' => '', 'cat_order' => '0', 'can_collapse' => '1'],
        ];

        (new CategoryController(Environment::request('PATCH', ['order' => 0])))->update(['id' => '3']);

        Assert::same($GLOBALS['api_modify_category'][0], [3, []]);
    }

    public function orderRejectsInvalidIntegersAndOutOfRangePositions(): void
    {
        foreach ([-1, 1.0, false, null, [], '', '1.5', '1e0', '1x', '9999999999999999999999999', 2] as $order) {
            Environment::reset();

            $GLOBALS['api_permissions'] = ['manage_boards'];
            $GLOBALS['api_num_rows']    = 1;
            $GLOBALS['api_rows']        = [['id_cat' => '3'], ['id_cat' => '5'], null];

            $exception = $this->error(fn () => (new CategoryController(Environment::request('PATCH', ['order' => $order])))->update(['id' => '3']));

            Assert::same($exception->getStatusCode(), 400);
            Assert::same($GLOBALS['api_modify_category'], []);
        }
    }

    public function collapseFlagIsWrittenThroughModifyCategory(): void
    {
        Environment::reset();

        $GLOBALS['api_permissions'] = ['manage_boards'];
        $GLOBALS['api_num_rows']    = 1;

        $GLOBALS['api_rows'] = [
            ['id_cat' => '3', 'name' => 'X', 'description' => '', 'cat_order' => '0', 'can_collapse' => '0'],
        ];

        (new CategoryController(Environment::request('PATCH', ['can_collapse' => false])))->update(['id' => '3']);

        Assert::same($GLOBALS['api_modify_category'][0], [3, ['is_collapsible' => false]]);
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
