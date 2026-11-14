<?php declare(strict_types=1);

/**
 * @package SMF RESTful API
 * @author Bugo <bugo@dragomano.ru>
 * @copyright 2026 Bugo
 * @license https://opensource.org/licenses/MIT The MIT License
 */

namespace SMF\API\Controllers;

use SMF\API\ApiException;
use SMF\API\Query;
use SMF\API\Response;

if (! defined('SMF'))
	die('No direct access...');

/**
 * Read and manage boards.
 */
class BoardController extends AbstractController
{
	/**
	 * Fields a client may write, mapped onto their {db_prefix}boards column.
	 * Everything goes through SMF's modifyBoard(), which expects its own option
	 * names (board_name, access_groups, posts_count, ...), so save() translates.
	 */
	private const WRITABLE = [
		'name'               => ['type' => 'string', 'column' => 'name', 'length' => 255],
		'description'        => ['type' => 'raw', 'column' => 'description'],
		'category'           => ['type' => 'int', 'column' => 'id_cat', 'min' => 1, 'optional' => true],
		'parent'             => ['type' => 'int', 'column' => 'id_parent', 'optional' => true],
		'order'              => ['type' => 'int', 'column' => 'board_order', 'optional' => true],
		'id_profile'         => ['type' => 'int', 'column' => 'id_profile', 'min' => 1, 'optional' => true],
		'id_theme'           => ['type' => 'int', 'column' => 'id_theme', 'optional' => true],
		'override_theme'     => ['type' => 'bool', 'column' => 'override_theme', 'optional' => true],
		'count_posts'        => ['type' => 'bool', 'column' => 'count_posts', 'optional' => true],
		'member_groups'      => ['type' => 'int_list', 'column' => 'member_groups', 'optional' => true],
		'deny_member_groups' => ['type' => 'int_list', 'column' => 'deny_member_groups', 'optional' => true],
		'redirect'           => ['type' => 'string', 'column' => 'redirect', 'length' => 255, 'optional' => true],
	];

	/** SMF column names accepted in place of the API field names. */
	private const ALIASES = [
		'id_cat'      => 'category',
		'id_parent'   => 'parent',
		'board_order' => 'order',
	];

	public function index(array $params): Response
	{
		$where       = '{query_see_board}';
		$queryParams = [];

		// Nested route /categories/{cat_id}/boards scopes the list to one category.
		if (isset($params['cat_id'])) {
			$catId = $this->id($params, 'cat_id');

			if (! $this->categoryExists($catId)) {
				throw ApiException::notFound('Category not found');
			}

			$where .= ' AND b.id_cat = {int:cat}';

			$queryParams['cat'] = $catId;
		}

		$query = (new Query())
			->from('{db_prefix}boards AS b')
			->where($where)
			->columns('b.id_board, b.id_cat, b.id_parent, b.name, b.description, b.num_topics, b.num_posts, b.board_order')
			->orderBy('b.board_order')
			->params($queryParams);

		return $this->paginate($query);
	}

	public function show(array $params): Response
	{
		$row = $this->first($this->boardQuery()->params(['id' => $this->id($params)]));

		// A hidden board is indistinguishable from a missing one on purpose.
		if ($row === null) {
			throw ApiException::notFound('Board not found');
		}

		return $this->response()->data($this->transform($row));
	}

	/**
	 * Create a new board inside a category. Restricted to board managers.
	 */
	public function store(array $params): Response
	{
		global $sourcedir;

		if (! allowedTo('manage_boards')) {
			throw ApiException::forbidden('You cannot manage boards');
		}

		$columns = $this->writableColumns(self::WRITABLE, false, self::ALIASES);

		if (($columns['name'] ?? '') === '') {
			throw ApiException::badRequest('Field "name" is required');
		}

		$category = $columns['id_cat'] ?? 0;

		if ($category <= 0) {
			throw ApiException::badRequest('Field "category" is required');
		}

		if (! $this->categoryExists($category)) {
			throw ApiException::notFound('Category not found');
		}

		$boardOptions = [
			'board_name'        => $columns['name'],
			'board_description' => $columns['description'] ?? '',
			'move_to'           => 'bottom',
			'target_category'   => $category,
		];

		$this->applyBoardOptions($boardOptions, $columns);

		require_once($sourcedir . '/Subs-Boards.php');

		$id = createBoard($boardOptions);

		if (empty($id)) {
			throw ApiException::badRequest('The board could not be created');
		}

		$row = $this->first($this->boardQuery()->params(['id' => (int) $id]));

		return $this->response()
			->status(201)
			->data($this->transform($row));
	}

	/**
	 * Fully replace a board (PUT): "name" and "description" are required. An
	 * optional "category" moves the board to another category.
	 */
	public function replace(array $params): Response
	{
		return $this->save($params, true);
	}

	/**
	 * Partially update a board (PATCH): only the supplied fields change.
	 */
	public function update(array $params): Response
	{
		return $this->save($params, false);
	}

	/**
	 * Delete a board. Child boards move to the board given by the
	 * "move_children_to" query parameter, or are removed together with the
	 * parent when none is given. Restricted to board managers.
	 */
	public function destroy(array $params): Response
	{
		global $sourcedir;

		if (! allowedTo('manage_boards')) {
			throw ApiException::forbidden('You cannot manage boards');
		}

		$id = $this->id($params);

		if (! $this->boardExists($id)) {
			throw ApiException::notFound('Board not found');
		}

		$moveChildrenTo = $this->request->query('move_children_to');
		$moveChildrenTo = $moveChildrenTo === null ? null : (int) $moveChildrenTo;

		if ($moveChildrenTo !== null && ($moveChildrenTo === $id || ! $this->boardExists($moveChildrenTo))) {
			throw ApiException::badRequest('Invalid "move_children_to" board');
		}

		require_once($sourcedir . '/Subs-Boards.php');

		deleteBoards([$id], $moveChildrenTo);

		return $this->noContent();
	}

	protected function transform(array $row): array
	{
		return [
			'id'          => (int) $row['id_board'],
			'category'    => (int) $row['id_cat'],
			'parent'      => (int) $row['id_parent'],
			'name'        => $row['name'],
			'description' => $row['description'],
			'num_topics'  => (int) $row['num_topics'],
			'num_posts'   => (int) $row['num_posts'],
			'order'       => (int) $row['board_order'],
		];
	}

	/**
	 * Shared PUT/PATCH body: validate access, apply the writable fields through
	 * SMF's modifyBoard(), then answer with the stored board.
	 */
	private function save(array $params, bool $full): Response
	{
		global $sourcedir;

		if (! allowedTo('manage_boards')) {
			throw ApiException::forbidden('You cannot manage boards');
		}

		$id = $this->id($params);

		$current = $this->first(
			(new Query())
				->from('{db_prefix}boards')
				->where('id_board = {int:id}')
				->columns('id_board, id_cat, id_parent, board_order')
				->params(['id' => $id])
		);

		if ($current === null) {
			throw ApiException::notFound('Board not found');
		}

		$columns = $this->writableColumns(self::WRITABLE, $full, self::ALIASES);

		// modifyBoard() refreshes the parsed-description cache for the board's
		// category and, when the board is not being moved, reads it from
		// old_id_cat. SMF's own board editor passes this; without it PHP 8 logs
		// an "Undefined array key: old_id_cat" notice.
		$boardOptions = ['old_id_cat' => (int) $current['id_cat']];

		if (array_key_exists('name', $columns)) {
			if ($columns['name'] === '') {
				throw ApiException::badRequest('Field "name" cannot be empty');
			}

			$boardOptions['board_name'] = $columns['name'];
		}

		if (array_key_exists('description', $columns)) {
			$boardOptions['board_description'] = $columns['description'];
		}

		$this->applyBoardOptions($boardOptions, $columns, $current);

		require_once($sourcedir . '/Subs-Boards.php');

		modifyBoard($id, $boardOptions);

		$row = $this->first($this->boardQuery()->params(['id' => $id]));

		return $this->response()->data($this->transform($row));
	}

	/**
	 * Translate the written columns into modifyBoard() options: plain settings
	 * first, then at most one move (position wins over parent, parent over a
	 * bare category change), because SMF applies a single move per call.
	 *
	 * @param array<string, mixed> $boardOptions
	 * @param array<string, mixed> $columns
	 * @param array<string, mixed>|null $current
	 */
	private function applyBoardOptions(array &$boardOptions, array $columns, ?array $current = null): void
	{
		if (array_key_exists('id_profile', $columns)) {
			$boardOptions['profile'] = $columns['id_profile'];
		}

		if (array_key_exists('id_theme', $columns)) {
			$boardOptions['board_theme'] = $columns['id_theme'];
		}

		if (array_key_exists('override_theme', $columns)) {
			$boardOptions['override_theme'] = $columns['override_theme'];
		}

		// SMF stores the inverse: count_posts = 0 means "this board counts posts".
		if (array_key_exists('count_posts', $columns)) {
			$boardOptions['posts_count'] = $columns['count_posts'] ? 0 : 1;
		}

		if (array_key_exists('redirect', $columns)) {
			$boardOptions['redirect'] = $columns['redirect'];
		}

		if (array_key_exists('member_groups', $columns)) {
			$boardOptions['access_groups'] = $this->groupIdList($columns['member_groups']);
		}

		if (array_key_exists('deny_member_groups', $columns)) {
			$boardOptions['deny_groups'] = $this->groupIdList($columns['deny_member_groups']);
		}

		$category = array_key_exists('id_cat', $columns)
			? $columns['id_cat']
			: ($current === null ? null : (int) $current['id_cat']);

		$parent = array_key_exists('id_parent', $columns)
			? $columns['id_parent']
			: ($current === null ? 0 : (int) $current['id_parent']);

		if ($current === null) {
			// Creation: the board lands at the bottom of its category or, when a
			// parent is given, as the last child of that board.
			if ($category === null) {
				throw ApiException::badRequest('Field "category" is required');
			}

			$this->assertCategoryExists($category);

			if ($parent > 0) {
				$this->assertParentInCategory($parent, $category);

				$boardOptions['move_to']      = 'child';
				$boardOptions['target_board'] = $parent;
			}
		} elseif ($category !== null && $category !== (int) $current['id_cat']) {
			$this->assertCategoryExists($category);

			$boardOptions['move_to']         = 'bottom';
			$boardOptions['target_category'] = $category;

			// A board dropped into another category becomes a root board there.
			$parent = 0;
		}

		if (array_key_exists('board_order', $columns) && $category !== null) {
			$this->moveToPosition($boardOptions, $columns['board_order'], $category, $parent, $current);
		} elseif ($current !== null && $parent !== (int) $current['id_parent'] && $category !== null) {
			$this->moveToParent($boardOptions, $parent, $category);
		}
	}

	/**
	 * Place the board at a 0-based position among its siblings (same category
	 * and parent), which is what the panel's drag-and-drop sends.
	 *
	 * @param array<string, mixed> $boardOptions
	 * @param array<string, mixed>|null $current
	 */
	private function moveToPosition(array &$boardOptions, int $order, int $category, int $parent, ?array $current): void
	{
		$id       = $current === null ? null : (int) $current['id_board'];
		$siblings = $this->siblings($category, $parent);
		$position = null;
		$others   = [];

		foreach ($siblings as $index => $row) {
			if ((int) $row['id_board'] === $id) {
				$position = $index;

				continue;
			}

			$others[] = (int) $row['id_board'];
		}

		if ($order > count($others)) {
			throw ApiException::badRequest(
				'Field "order" must be a position between 0 and ' . count($others)
			);
		}

		// Already there: no move at all.
		if ($position === $order) {
			return;
		}

		if ($order < count($others)) {
			$boardOptions['move_to']      = 'before';
			$boardOptions['target_board'] = $others[$order];

			return;
		}

		if ($others !== []) {
			$boardOptions['move_to']      = 'after';
			$boardOptions['target_board'] = $others[count($others) - 1];

			return;
		}

		// No siblings: a brand new first board in that place.
		$this->moveToParent($boardOptions, $parent, $category);
	}

	/**
	 * Make the board a child of $parent (or a root board of $category for 0).
	 *
	 * @param array<string, mixed> $boardOptions
	 */
	private function moveToParent(array &$boardOptions, int $parent, int $category): void
	{
		if ($parent === 0) {
			$boardOptions['move_to']         = 'bottom';
			$boardOptions['target_category'] = $category;

			return;
		}

		if (! $this->boardExists($parent)) {
			throw ApiException::notFound('Parent board not found');
		}

		$this->assertParentInCategory($parent, $category);

		$boardOptions['move_to']      = 'child';
		$boardOptions['target_board'] = $parent;
	}

	/**
	 * Boards that share a category and a parent level, in display order.
	 *
	 * @return array<int, array<string, mixed>>
	 */
	private function siblings(int $category, int $parent): array
	{
		$rows = $this->fetchAll(
			(new Query())
				->from('{db_prefix}boards')
				->columns('id_board, id_parent, board_order')
				->where('id_cat = {int:category}')
				->orderBy('board_order, id_board')
				->params(['category' => $category])
		);

		return array_values(array_filter(
			$rows,
			static fn (array $row): bool => (int) $row['id_parent'] === $parent
		));
	}

	/**
	 * modifyBoard() wants group ids as an array, the API takes "2,7" or [2, 7].
	 *
	 * @return array<int, int>
	 */
	private function groupIdList(string $value): array
	{
		$ids = array_map(
			intval(...),
			array_filter(explode(',', $value), static fn (string $id): bool => $id !== ''),
		);

		sort($ids);

		return $ids;
	}

	/**
	 * Whether a board with this id exists.
	 */
	private function boardExists(int $id): bool
	{
		return $this->exists(
			(new Query())
				->from('{db_prefix}boards')
				->where('id_board = {int:id}')
				->params(['id' => $id])
		);
	}

	/**
	 * Whether a category with this id exists.
	 */
	private function categoryExists(int $id): bool
	{
		return $this->exists(
			(new Query())
				->from('{db_prefix}categories')
				->where('id_cat = {int:id}')
				->params(['id' => $id])
		);
	}

	private function assertCategoryExists(int $id): void
	{
		if (! $this->categoryExists($id)) {
			throw ApiException::notFound('Category not found');
		}
	}

	/**
	 * A parent board has to live in the same category as the board itself,
	 * otherwise SMF would silently reparent the whole tree.
	 */
	private function assertParentInCategory(int $parent, int $category): void
	{
		$row = $this->first(
			(new Query())
				->from('{db_prefix}boards')
				->where('id_board = {int:id}')
				->columns('id_board, id_cat')
				->params(['id' => $parent])
		);

		if ($row === null) {
			throw ApiException::notFound('Parent board not found');
		}

		if ((int) $row['id_cat'] !== $category) {
			throw ApiException::badRequest('The parent board must be in the same category');
		}
	}

	/**
	 * The columns a board answer is built from.
	 */
	private function boardQuery(): Query
	{
		return (new Query())
			->from('{db_prefix}boards AS b')
			->where('{query_see_board} AND b.id_board = {int:id}')
			->columns('b.id_board, b.id_cat, b.id_parent, b.name, b.description, b.num_topics, b.num_posts, b.board_order');
	}
}
