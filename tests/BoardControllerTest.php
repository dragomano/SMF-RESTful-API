<?php

declare(strict_types=1);

use SMF\API\ApiException;
use SMF\API\Controllers\BoardController;
use Testo\Assert;
use Testo\Codecov\Covers;
use Testo\Test;

require_once __DIR__ . '/Environment.php';

#[Test]
#[Covers(BoardController::class)]
final class BoardControllerTest
{
    // Fixtures use string values on purpose: a real DB hands every column back
    // as a string, so the transform() (int) casts are only observable when the
    // raw row is a string. Native ints would make dropping the casts equivalent.
    private const BOARD_ROW = [
        'id_board'    => '4',
        'id_cat'      => '2',
        'id_parent'   => '0',
        'name'        => 'Chat',
        'description' => 'General chat',
        'num_topics'  => '8',
        'num_posts'   => '40',
        'board_order' => '1',
    ];

    public function indexReturnsTransformedBoards(): void
    {
        Environment::reset();

        $GLOBALS['api_total'] = 1;
        $GLOBALS['api_rows']  = [self::BOARD_ROW];

        $response = (new BoardController(Environment::request()))->index([]);
        $data     = Environment::readResponse($response)['payload']['data'];

        // Full-array assert against ints kills every transform() cast/field mutant.
        Assert::same($data[0], [
            'id'          => 4,
            'category'    => 2,
            'parent'      => 0,
            'name'        => 'Chat',
            'description' => 'General chat',
            'num_topics'  => 8,
            'num_posts'   => 40,
            'order'       => 1,
        ]);
    }

    public function indexScopedToAcategoryChecksItExists(): void
    {
        Environment::reset();

        $GLOBALS['api_num_rows'] = 1;
        $GLOBALS['api_total']    = 1;
        $GLOBALS['api_rows']     = [self::BOARD_ROW];

        $response = (new BoardController(Environment::request()))->index(['cat_id' => '2']);

        Assert::same(Environment::readResponse($response)['payload']['meta']['total'], 1);

        // The board query must keep the {query_see_board} guard alongside the
        // category filter (kills the ".=" -> "=" assignment mutant on the WHERE).
        $boardQuery = '';
        foreach ($GLOBALS['api_queries'] as $recorded) {
            if (str_contains($recorded['query'], '{db_prefix}boards AS b')) {
                $boardQuery = $recorded['query'];
                break;
            }
        }

        Assert::true(str_contains($boardQuery, '{query_see_board}'));
        Assert::true(str_contains($boardQuery, 'b.id_cat = {int:cat}'));
    }

    public function indexScopedToAmissingCategoryIsNotFound(): void
    {
        Environment::reset();

        $GLOBALS['api_num_rows'] = 0;

        $exception = $this->error(fn () => (new BoardController(Environment::request()))->index(['cat_id' => '99']));

        Assert::same($exception->getStatusCode(), 404);
    }

    public function showReturnsAboard(): void
    {
        Environment::reset();

        $GLOBALS['api_rows'] = [self::BOARD_ROW];

        $response = (new BoardController(Environment::request()))->show(['id' => '4']);

        Assert::same(Environment::readResponse($response)['payload']['data']['id'], 4);
    }

    public function showHidesAninvisibleBoardAsNotFound(): void
    {
        Environment::reset();

        $exception = $this->error(fn () => (new BoardController(Environment::request()))->show(['id' => '4']));

        Assert::same($exception->getStatusCode(), 404);
    }

    public function storeCreatesAboardInsideAcategory(): void
    {
        Environment::reset();

        $GLOBALS['api_permissions'] = ['manage_boards'];
        $GLOBALS['api_num_rows']    = 1;
        $GLOBALS['api_new_id']      = 15;

        // The created board is read back through the same query as show().
        $GLOBALS['api_rows'] = [['id_board' => '15'] + self::BOARD_ROW];

        // A string "category" (as a form request delivers) makes the (int) cast
        // on the target_category observable.
        $request  = Environment::request('POST', ['category' => '2', 'name' => 'Ideas', 'description' => 'd']);
        $response = (new BoardController($request))->store([]);
        $state    = Environment::readResponse($response);

        Assert::same($state['status'], 201);
        Assert::same($state['payload']['data']['id'], 15);
        Assert::same($GLOBALS['api_create_board'][0], [
            'board_name'        => 'Ideas',
            'board_description' => 'd',
            'move_to'           => 'bottom',
            'target_category'   => 2,
        ]);
    }

    public function storeRejectsAmissingCategory(): void
    {
        Environment::reset();

        $GLOBALS['api_permissions'] = ['manage_boards'];
        $GLOBALS['api_num_rows']    = 0;

        $request   = Environment::request('POST', ['category' => 99, 'name' => 'Ideas']);
        $exception = $this->error(fn () => (new BoardController($request))->store([]));

        Assert::same($exception->getStatusCode(), 404);
    }

    public function storeValidatesRequiredFields(): void
    {
        Environment::reset();

        $GLOBALS['api_permissions'] = ['manage_boards'];

        $request   = Environment::request('POST', ['name' => 'Ideas']);
        $exception = $this->error(fn () => (new BoardController($request))->store([]));

        Assert::same($exception->getStatusCode(), 400);
    }

    public function storeIsRefusedWithoutPermission(): void
    {
        Environment::reset();

        $request   = Environment::request('POST', ['category' => 2, 'name' => 'Ideas']);
        $exception = $this->error(fn () => (new BoardController($request))->store([]));

        Assert::same($exception->getStatusCode(), 403);
    }

    public function updateAppliesTheNameAndPassesTheOriginalCategory(): void
    {
        Environment::reset();

        $GLOBALS['api_permissions'] = ['manage_boards'];

        $GLOBALS['api_rows'] = [
            ['id_board' => '4', 'id_cat' => '2', 'id_parent' => '0'],
            ['name' => 'Renamed'] + self::BOARD_ROW,
        ];

        $request  = Environment::request('PATCH', ['name' => 'Renamed']);
        $response = (new BoardController($request))->update(['id' => '4']);

        Assert::same(Environment::readResponse($response)['payload']['data']['name'], 'Renamed');
        // Full recorded-args assert; old_id_cat coming back as int 2 pins the
        // (int) cast on $current['id_cat'].
        Assert::same($GLOBALS['api_modify_board'][0], [4, ['old_id_cat' => 2, 'board_name' => 'Renamed']]);
    }

    public function updateLeavesTheCategoryUntouchedWhenUnchanged(): void
    {
        Environment::reset();

        $GLOBALS['api_permissions'] = ['manage_boards'];
        // Current category is the string "2"; sending the string "2" back must be
        // recognised as "no change" (both sides go through (int)), so no move
        // options and no category-existence lookup are triggered.
        $GLOBALS['api_rows'] = [
            ['id_board' => '4', 'id_cat' => '2', 'id_parent' => '0'],
            self::BOARD_ROW,
        ];

        $request  = Environment::request('PATCH', ['name' => 'X', 'category' => '2']);
        $response = (new BoardController($request))->update(['id' => '4']);

        Assert::same(Environment::readResponse($response)['status'], 200);
        Assert::same($GLOBALS['api_modify_board'][0], [4, ['old_id_cat' => 2, 'board_name' => 'X']]);
    }

    public function updateMovesTheBoardToAnotherCategory(): void
    {
        Environment::reset();

        $GLOBALS['api_permissions'] = ['manage_boards'];
        $GLOBALS['api_num_rows'] = 1; // the target category exists
        $GLOBALS['api_rows'] = [
            ['id_board' => '4', 'id_cat' => '2', 'id_parent' => '0'],
            self::BOARD_ROW,
        ];

        $request  = Environment::request('PATCH', ['name' => 'Moved', 'category' => '5']);
        $response = (new BoardController($request))->update(['id' => '4']);

        Assert::same(Environment::readResponse($response)['status'], 200);
        Assert::same($GLOBALS['api_modify_board'][0], [
            4,
            ['old_id_cat' => 2, 'board_name' => 'Moved', 'move_to' => 'bottom', 'target_category' => 5],
        ]);
    }

    public function replaceRejectsAmissingBoard(): void
    {
        Environment::reset();

        $GLOBALS['api_permissions'] = ['manage_boards'];

        $request   = Environment::request('PUT', ['name' => 'X', 'description' => 'Y']);
        $exception = $this->error(fn () => (new BoardController($request))->replace(['id' => '4']));

        Assert::same($exception->getStatusCode(), 404);
    }

    public function replaceDemandsEveryWritableField(): void
    {
        Environment::reset();

        $GLOBALS['api_permissions'] = ['manage_boards'];

        // The board exists, but only "name" is supplied. A full replace (PUT)
        // must reject the partial body; a partial update (PATCH) would accept it.
        $GLOBALS['api_rows'] = [['id_board' => '4', 'id_cat' => '2']];

        $request   = Environment::request('PUT', ['name' => 'X']);
        $exception = $this->error(fn () => (new BoardController($request))->replace(['id' => '4']));

        Assert::same($exception->getStatusCode(), 400);
    }

    public function destroyRemovesTheBoard(): void
    {
        Environment::reset();

        $GLOBALS['api_permissions'] = ['manage_boards'];
        $GLOBALS['api_num_rows'] = 1;

        $response = (new BoardController(Environment::request()))->destroy(['id' => '4']);

        Assert::same(Environment::readResponse($response)['status'], 204);
        Assert::same($GLOBALS['api_delete_boards'][0], [[4], null]);
    }

    public function destroyMovesChildrenToAnotherBoard(): void
    {
        Environment::reset();

        $GLOBALS['api_permissions'] = ['manage_boards'];

        // The board being deleted and the move target both exist.
        $GLOBALS['api_num_rows_queue'] = [1, 1];

        $request  = Environment::request('DELETE', [], ['move_children_to' => '7']);
        $response = (new BoardController($request))->destroy(['id' => '4']);

        Assert::same(Environment::readResponse($response)['status'], 204);
        // The move target must be the int 7 (kills the (int) cast on the query param).
        Assert::same($GLOBALS['api_delete_boards'][0], [[4], 7]);
    }

    public function destroyRejectsMovingChildrenIntoTheSameBoard(): void
    {
        Environment::reset();

        $GLOBALS['api_permissions'] = ['manage_boards'];
        $GLOBALS['api_num_rows_queue'] = [1, 1];

        $request   = Environment::request('DELETE', [], ['move_children_to' => '4']);
        $exception = $this->error(fn () => (new BoardController($request))->destroy(['id' => '4']));

        Assert::same($exception->getStatusCode(), 400);
    }

    public function destroyRejectsMoveTargetEqualToTheBoardEvenWhenAbsent(): void
    {
        Environment::reset();

        $GLOBALS['api_permissions'] = ['manage_boards'];

        // Guard passes; the second existence lookup would report "missing".
        $GLOBALS['api_num_rows_queue'] = [1, 0];

        $request   = Environment::request('DELETE', [], ['move_children_to' => '4']);
        $exception = $this->error(fn () => (new BoardController($request))->destroy(['id' => '4']));

        Assert::same($exception->getStatusCode(), 400);
    }

    public function destroyRejectsAmissingMoveTarget(): void
    {
        Environment::reset();

        $GLOBALS['api_permissions'] = ['manage_boards'];

        // The board exists, but the move target does not.
        $GLOBALS['api_num_rows_queue'] = [1, 0];

        $request   = Environment::request('DELETE', [], ['move_children_to' => '7']);
        $exception = $this->error(fn () => (new BoardController($request))->destroy(['id' => '4']));

        Assert::same($exception->getStatusCode(), 400);
    }

    public function destroyIsRefusedWithoutPermission(): void
    {
        Environment::reset();

        $exception = $this->error(fn () => (new BoardController(Environment::request()))->destroy(['id' => '4']));

        Assert::same($exception->getStatusCode(), 403);
    }

    public function replaceKeepsOrderAndCategoryOptional(): void
    {
        Environment::reset();

        $GLOBALS['api_permissions'] = ['manage_boards'];
        $GLOBALS['api_rows']        = [self::BOARD_ROW, self::BOARD_ROW];

        $response = (new BoardController(Environment::request('PUT', ['name' => 'Chat', 'description' => 'General chat'])))->replace(['id' => '4']);

        Assert::same(Environment::readResponse($response)['status'], 200);
        Assert::same($GLOBALS['api_modify_board'][0], [4, ['old_id_cat' => 2, 'board_name' => 'Chat', 'board_description' => 'General chat']]);
    }

    public function orderSelectsThePositionAmongSiblings(): void
    {
        // Siblings in category 2 at the root level, ordered: 7, 4 (the edited
        // board), 8. Position "order" is 0-based, so 2 is the last slot.
        $siblings = [
            ['id_board' => '7', 'id_parent' => '0', 'board_order' => '1'],
            ['id_board' => '4', 'id_parent' => '0', 'board_order' => '2'],
            ['id_board' => '8', 'id_parent' => '0', 'board_order' => '3'],
            null,
        ];

        foreach ([[0, 'before', 7], [2, 'after', 8]] as [$order, $direction, $target]) {
            Environment::reset();

            $GLOBALS['api_permissions'] = ['manage_boards'];
            $GLOBALS['api_rows']        = [self::BOARD_ROW, ...$siblings, self::BOARD_ROW];

            $response = (new BoardController(Environment::request('PATCH', ['order' => $order])))->update(['id' => '4']);

            Assert::same(Environment::readResponse($response)['status'], 200);
            Assert::same($GLOBALS['api_modify_board'][0], [4, [
                'old_id_cat'   => 2,
                'move_to'      => $direction,
                'target_board' => $target,
            ]]);

            $query = $GLOBALS['api_queries'][1];

            Assert::same($query['params'], ['category' => 2]);
            Assert::true(str_contains($query['query'], 'id_cat = {int:category}'));
        }
    }

    public function orderInTheCurrentPositionDoesNotMoveTheBoard(): void
    {
        Environment::reset();

        $GLOBALS['api_permissions'] = ['manage_boards'];

        // Board 4 is second among the root-level siblings of category 2.
        $GLOBALS['api_rows'] = [
            self::BOARD_ROW,
            ['id_board' => '7', 'id_parent' => '0', 'board_order' => '1'],
            ['id_board' => '4', 'id_parent' => '0', 'board_order' => '2'],
            null,
            self::BOARD_ROW,
        ];

        (new BoardController(Environment::request('PATCH', ['order' => 1])))->update(['id' => '4']);

        Assert::same($GLOBALS['api_modify_board'][0], [4, ['old_id_cat' => 2]]);
    }

    public function orderCountsOnlySiblingsOfTheSameParent(): void
    {
        Environment::reset();

        $GLOBALS['api_permissions'] = ['manage_boards'];

        $GLOBALS['api_rows'] = [
            self::BOARD_ROW,
            ['id_board' => '4', 'id_parent' => '0', 'board_order' => '1'],
            // A child board shares the category but not the parent level.
            ['id_board' => '9', 'id_parent' => '4', 'board_order' => '2'],
            ['id_board' => '7', 'id_parent' => '0', 'board_order' => '3'],
            null,
            self::BOARD_ROW,
        ];

        // Sibling 9 is a child of board 4, so only board 7 counts: position 1
        // means "after the last root-level sibling".
        (new BoardController(Environment::request('PATCH', ['order' => 1])))->update(['id' => '4']);

        Assert::same($GLOBALS['api_modify_board'][0], [4, [
            'old_id_cat'   => 2,
            'move_to'      => 'after',
            'target_board' => 7,
        ]]);
    }

    public function orderRejectsLossyValuesAndPositionsPastTheLastSibling(): void
    {
        // Board 7 is the only sibling, so 0 and 1 are the valid positions.
        foreach ([-1, 1.5, true, null, [], '1e0', '1x', '999999999999999999999999', 2] as $order) {
            Environment::reset();

            $GLOBALS['api_permissions'] = ['manage_boards'];

            $GLOBALS['api_rows'] = [
                self::BOARD_ROW,
                ['id_board' => '7', 'id_parent' => '0', 'board_order' => '2'],
                null,
            ];

            $exception = $this->error(fn () => (new BoardController(Environment::request('PATCH', ['order' => $order])))->update(['id' => '4']));

            Assert::same($exception->getStatusCode(), 400);
            Assert::same($GLOBALS['api_modify_board'], []);
        }
    }

    public function categoryOnlyPatchMovesAndOrderSelectsTheDestinationPosition(): void
    {
        // Without "order" the board drops to the bottom; with 1 it lands after
        // the only root-level board of the destination category.
        foreach ([[], ['order' => 1]] as $extra) {
            Environment::reset();

            $GLOBALS['api_permissions'] = ['manage_boards'];
            $GLOBALS['api_num_rows']    = 1;
            $GLOBALS['api_rows']        = [self::BOARD_ROW];

            if ($extra !== []) {
                $GLOBALS['api_rows'][] = ['id_board' => '8', 'id_parent' => '0', 'board_order' => '6'];
                $GLOBALS['api_rows'][] = null;
            }

            $GLOBALS['api_rows'][] = self::BOARD_ROW;

            (new BoardController(Environment::request('PATCH', ['category' => '5'] + $extra)))->update(['id' => '4']);

            $options = ['old_id_cat' => 2, 'move_to' => $extra === [] ? 'bottom' : 'after', 'target_category' => 5];
            if ($extra !== []) {
                $options['target_board'] = 8;
            }

            Assert::same($GLOBALS['api_modify_board'][0], [4, $options]);
        }
    }

    public function categoryRejectsLossyCastsAndUnknownTargets(): void
    {
        foreach ([null, true, [], '2x', 2.5, 0, -1, '999999999999999999999999', 99] as $category) {
            Environment::reset();

            $GLOBALS['api_permissions'] = ['manage_boards'];
            $GLOBALS['api_rows']        = [self::BOARD_ROW];

            $exception = $this->error(fn () => (new BoardController(Environment::request('PATCH', ['category' => $category])))->update(['id' => '4']));

            Assert::same($exception->getStatusCode(), $category === 99 ? 404 : 400);
            Assert::same($GLOBALS['api_modify_board'], []);
        }
    }

    public function updateWritesTheAccessLists(): void
    {
        Environment::reset();

        $GLOBALS['api_permissions'] = ['manage_boards'];

        $GLOBALS['api_rows'] = [
            ['id_board' => '4', 'id_cat' => '2', 'id_parent' => '0'],
            self::BOARD_ROW,
        ];

        (new BoardController(Environment::request('PATCH', ['member_groups' => [0, 2], 'deny_member_groups' => '5'])))->update(['id' => '4']);

        // modifyBoard() wants plain arrays of ids, sorted for a stable payload.
        Assert::same($GLOBALS['api_modify_board'][0], [4, [
            'old_id_cat'    => 2,
            'access_groups' => [0, 2],
            'deny_groups'   => [5],
        ]]);
    }

    public function updateWritesTheRemainingBoardSettings(): void
    {
        Environment::reset();

        $GLOBALS['api_permissions'] = ['manage_boards'];

        $GLOBALS['api_rows'] = [
            ['id_board' => '4', 'id_cat' => '2', 'id_parent' => '0'],
            self::BOARD_ROW,
        ];

        (new BoardController(Environment::request('PATCH', [
            'id_profile'     => 2,
            'id_theme'       => 3,
            'override_theme' => true,
            'count_posts'    => true,
            'redirect'       => 'https://example.test',
        ])))->update(['id' => '4']);

        Assert::same($GLOBALS['api_modify_board'][0], [4, [
            'old_id_cat'     => 2,
            'profile'        => 2,
            'board_theme'    => 3,
            'override_theme' => true,
            // SMF stores the inverse: 0 means "this board counts posts".
            'posts_count'    => 0,
            'redirect'       => 'https://example.test',
        ]]);
    }

    public function storeCreatesAchildBoardUnderItsParent(): void
    {

        Environment::reset();
        $GLOBALS['api_permissions'] = ['manage_boards'];
        $GLOBALS['api_new_id']      = 15;
        $GLOBALS['api_num_rows']    = 1;

        $GLOBALS['api_rows'] = [
            // The parent lookup, then the read-back of the created board.
            ['id_board' => '4', 'id_cat' => '2', 'id_parent' => '0'],
            ['id_board' => '15'] + self::BOARD_ROW,
        ];

        (new BoardController(Environment::request('POST', ['category' => 2, 'parent' => 4, 'name' => 'Child', 'description' => 'd'])))->store([]);

        Assert::same($GLOBALS['api_create_board'][0], [
            'board_name'        => 'Child',
            'board_description' => 'd',
            'move_to'           => 'child',
            'target_category'   => 2,
            'target_board'      => 4,
        ]);
    }

    public function storeRefusesAParentFromAnotherCategory(): void
    {
        Environment::reset();

        $GLOBALS['api_permissions'] = ['manage_boards'];
        $GLOBALS['api_num_rows']    = 1;
        $GLOBALS['api_rows']        = [['id_board' => '4', 'id_cat' => '3']];

        $exception = $this->error(
            fn () => (new BoardController(Environment::request('POST', ['category' => 2, 'parent' => 4, 'name' => 'Child', 'description' => 'd'])))->store([])
        );

        Assert::same($exception->getStatusCode(), 400);
        Assert::same($GLOBALS['api_create_board'], []);
    }

    public function updateReparentsBoardsAndHandlesMissingParents(): void
    {
        foreach ([0, 8, 99] as $parent) {
            Environment::reset();

            $GLOBALS['api_permissions'] = ['manage_boards'];
            $GLOBALS['api_num_rows']    = $parent === 99 ? 0 : 1;
            $GLOBALS['api_rows']        = [ ['id_parent' => '3'] + self::BOARD_ROW ];

            if ($parent === 8) {
                $GLOBALS['api_rows'][] = ['id_board' => '8', 'id_cat' => '2'];
            }

            $GLOBALS['api_rows'][] = self::BOARD_ROW;

            $controller = new BoardController(Environment::request('PATCH', ['parent' => $parent]));

            if ($parent === 99) {
                Assert::same($this->error(fn () => $controller->update(['id' => '4']))->getStatusCode(), 404);
                Assert::same($GLOBALS['api_modify_board'], []);
            } else {
                $controller->update(['id' => '4']);

                $expected = $parent === 0
                    ? ['move_to' => 'bottom', 'target_category' => 2]
                    : ['move_to' => 'child', 'target_board' => 8];

                Assert::same($GLOBALS['api_modify_board'][0], [4, ['old_id_cat' => 2] + $expected]);
            }
        }
    }

    public function storePositionsTheFirstBoardWithoutSiblings(): void
    {
        Environment::reset();

        $GLOBALS['api_permissions'] = ['manage_boards'];
        $GLOBALS['api_num_rows']    = 1;
        $GLOBALS['api_rows']        = [null, self::BOARD_ROW];

        (new BoardController(Environment::request('POST', ['category' => 2, 'name' => 'First', 'order' => 0])))->store([]);

        Assert::same($GLOBALS['api_create_board'][0]['move_to'], 'bottom');
        Assert::same($GLOBALS['api_create_board'][0]['target_category'], 2);
    }

    public function storeRejectsMissingNameCategoryAndParent(): void
    {
        foreach ([['category' => 2, 'name' => ''], ['name' => 'X'], ['category' => 2, 'name' => 'X', 'parent' => 8]] as $body) {
            Environment::reset();

            $GLOBALS['api_permissions'] = ['manage_boards'];
            $GLOBALS['api_num_rows']    = 1;

            $exception = $this->error(fn () => (new BoardController(Environment::request('POST', $body)))->store([]));

            Assert::same($exception->getStatusCode(), isset($body['parent']) ? 404 : 400);
            Assert::same($GLOBALS['api_create_board'], []);
        }
    }

    public function writeAndDeleteRequirePermissionAndNonEmptyName(): void
    {
        Environment::reset();

        Assert::same($this->error(fn () => (new BoardController(Environment::request('PATCH', ['name' => 'X'])))->update(['id' => '4']))->getStatusCode(), 403);
        Assert::same($this->error(fn () => (new BoardController(Environment::request('DELETE')))->destroy(['id' => '4']))->getStatusCode(), 403);

        $GLOBALS['api_permissions'] = ['manage_boards'];
        $GLOBALS['api_rows']        = [self::BOARD_ROW];

        Assert::same($this->error(fn () => (new BoardController(Environment::request('PATCH', ['name' => ''])))->update(['id' => '4']))->getStatusCode(), 400);
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
