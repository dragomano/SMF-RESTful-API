<?php declare(strict_types=1);

/**
 * GroupController.php
 *
 * @package SMF RESTful API
 * @author Bugo <bugo@dragomano.ru>
 * @copyright 2026 Bugo
 * @license https://opensource.org/licenses/MIT The MIT License
 *
 * @version 0.1
 */

namespace SMF\API\Controllers;

use SMF\API\ApiException;
use SMF\API\Query;
use SMF\API\Response;

if (! defined('SMF'))
	die('No direct access...');

/**
 * Read and manage SMF member groups.
 *
 * SMF 2.1 has no addMembergroup()/modifyMembergroup() helpers: the admin
 * screens write to {db_prefix}membergroups directly. The write methods below
 * follow the same columns and defaults as ManageMembergroups.php, while
 * deleteMembergroups() remains the core helper for deletion.
 */
class GroupController extends AbstractController
{
	/**
	 * SMF group types. A post-count based group is represented by
	 * min_posts >= 0 and always has group_type 0.
	 */
	private const GROUP_TYPES = [0, 1, 2, 3];

	/** Fields a client may write, in the order a full replace expects them. */
	private const WRITABLE = [
		'name',
		'description',
		'type',
		'min_posts',
		'max_messages',
		'color',
		'icons',
	];

	/** Default icons value used by SMF's "add membergroup" screen. */
	private const DEFAULT_ICONS = '1#icon.png';

	public function index(array $params): Response
	{
		$where       = ['1=1'];
		$queryParams = [];

		// Hidden groups are only listed for member group managers; the
		// moderator group is only listed for administrators (as in SMF).
		if (! allowedTo('manage_membergroups')) {
			$where[] = 'hidden = {int:hidden}';

			$queryParams['hidden'] = 0;
		}

		if (! allowedTo('admin_forum')) {
			$where[] = 'id_group != {int:moderator_group}';

			$queryParams['moderator_group'] = 3;
		}

		$query = (new Query())
			->from('{db_prefix}membergroups')
			->where(implode(' AND ', $where))
			->columns('id_group, group_name, description, group_type, min_posts, max_messages, online_color, icons, hidden, id_parent, tfa_required')
			->orderBy('id_group')
			->params($queryParams);

		return $this->paginate($query);
	}

	public function show(array $params): Response
	{
		$row = $this->first(
			(new Query())
				->from('{db_prefix}membergroups')
				->where('id_group = {int:id}')
				->columns('id_group, group_name, description, group_type, min_posts, max_messages, online_color, icons, hidden, id_parent, tfa_required')
				->params(['id' => $this->id($params)])
		);

		// Hidden groups and the moderator group stay invisible to members who
		// could not see them in SMF's own group lists.
		if (
			$row === null
			|| (! allowedTo('manage_membergroups') && (int) $row['hidden'] !== 0)
			|| (! allowedTo('admin_forum') && (int) $row['id_group'] === 3)
		) {
			throw ApiException::notFound('Group not found');
		}

		return $this->response()->data($this->transform($row));
	}

	/**
	 * Create a member group. Restricted to member group managers.
	 */
	public function store(array $params): Response
	{
		global $smcFunc, $sourcedir, $modSettings;

		if (! allowedTo('manage_membergroups')) {
			throw ApiException::forbidden('You cannot manage member groups');
		}

		$name = $this->cleanString($this->request->input('name', ''));

		if ($name === '') {
			throw ApiException::badRequest('Field "name" is required');
		}

		if ($this->nameTaken($name)) {
			throw ApiException::badRequest('A member group with this name already exists');
		}

		$type = $this->groupType($this->request->input('type', 0));

		if ($type === 1 && ! allowedTo('admin_forum')) {
			throw ApiException::forbidden('Only administrators can create protected member groups');
		}

		$minPosts = $this->minPosts($this->request->input('min_posts', -1));

		// SMF marks post-count based groups by min_posts and stores group_type 0.
		if ($minPosts >= 0) {
			$type = 0;
		}

		$options = [
			'description'  => $this->cleanString($this->request->input('description', '')),
			'group_name'   => $name,
			'min_posts'    => $minPosts,
			'max_messages' => max(0, (int) $this->request->input('max_messages', 0)),
			'icons'        => $this->cleanString($this->request->input('icons', self::DEFAULT_ICONS)),
			'online_color' => $this->cleanString($this->request->input('color', '')),
			'group_type'   => $type,
			'hidden'       => 0,
			'id_parent'    => -2,
			'tfa_required' => 0,
		];

		call_integration_hook('integrate_pre_add_membergroup', []);

		$id = $smcFunc['db_insert'](
			'',
			'{db_prefix}membergroups',
			[
				'description'  => 'string',
				'group_name'   => 'string-80',
				'min_posts'    => 'int',
				'max_messages' => 'int',
				'icons'        => 'string',
				'online_color' => 'string',
				'group_type'   => 'int',
				'hidden'       => 'int',
				'id_parent'    => 'int',
				'tfa_required' => 'int',
			],
			[
				$options['description'],
				$options['group_name'],
				$options['min_posts'],
				$options['max_messages'],
				$options['icons'],
				$options['online_color'],
				$options['group_type'],
				$options['hidden'],
				$options['id_parent'],
				$options['tfa_required'],
			],
			['id_group'],
			1
		);

		if (empty($id)) {
			throw ApiException::badRequest('The member group could not be created');
		}

		call_integration_hook('integrate_add_membergroup', [$id, $minPosts >= 0]);

		if ($minPosts >= 0) {
			updateStats('postgroups');
		}

		$settings = ['settings_updated' => time()];

		// Joinable groups make SMF show group membership in profiles.
		if ($type > 1 && empty($modSettings['show_group_membership'])) {
			$settings['show_group_membership'] = 1;
		}

		updateSettings($settings);

		return $this->response()
			->status(201)
			->data($this->transform(['id_group' => $id] + $options));
	}

	/**
	 * Fully replace a member group (PUT): every writable field is required.
	 */
	public function replace(array $params): Response
	{
		return $this->save($params, true);
	}

	/**
	 * Partially update a member group (PATCH): only the supplied fields change.
	 */
	public function update(array $params): Response
	{
		return $this->save($params, false);
	}

	/**
	 * Delete a member group through SMF's own helper, which also moves the
	 * affected members back to the regular group and removes permissions.
	 */
	public function destroy(array $params): Response
	{
		global $sourcedir;

		if (! allowedTo('manage_membergroups')) {
			throw ApiException::forbidden('You cannot manage member groups');
		}

		$id = $this->id($params);

		$row = $this->first(
			(new Query())
				->from('{db_prefix}membergroups')
				->where('id_group = {int:id}')
				->columns('id_group, group_type')
				->params(['id' => $id])
		);

		if ($row === null) {
			throw ApiException::notFound('Group not found');
		}

		if ((int) $row['group_type'] === 1 && ! allowedTo('admin_forum')) {
			throw ApiException::forbidden('Only administrators can delete protected member groups');
		}

		require_once($sourcedir . '/Subs-Membergroups.php');

		$result = deleteMembergroups([$id]);

		if ($result === true) {
			return $this->noContent();
		}

		if ($result === 'group_cannot_delete_sub') {
			throw ApiException::badRequest('This member group is used by a paid subscription');
		}

		throw ApiException::badRequest('This member group cannot be deleted');
	}

	/**
	 * Shared PUT/PATCH body: validate access, apply the writable fields with the
	 * same columns and side effects as SMF's EditMembergroup(), then answer with
	 * the stored group.
	 */
	private function save(array $params, bool $full): Response
	{
		global $smcFunc, $modSettings;

		if (! allowedTo('manage_membergroups')) {
			throw ApiException::forbidden('You cannot manage member groups');
		}

		$id = $this->id($params);

		$current = $this->first(
			(new Query())
				->from('{db_prefix}membergroups')
				->where('id_group = {int:id}')
				->columns('id_group, group_name, description, group_type, min_posts, max_messages, online_color, icons, hidden, id_parent, tfa_required')
				->params(['id' => $id])
		);

		if ($current === null) {
			throw ApiException::notFound('Group not found');
		}

		if ((int) $current['group_type'] === 1 && ! allowedTo('admin_forum')) {
			throw ApiException::forbidden('Only administrators can edit protected member groups');
		}

		$fields  = $this->writable(self::WRITABLE, $full);
		$options = [];

		if (array_key_exists('name', $fields)) {
			$name = $this->cleanString($fields['name']);

			if ($name === '') {
				throw ApiException::badRequest('Field "name" cannot be empty');
			}

			if ($this->nameTaken($name, $id)) {
				throw ApiException::badRequest('A member group with this name already exists');
			}

			$options['group_name'] = $name;
		}

		if (array_key_exists('description', $fields)) {
			$options['description'] = $this->cleanString($fields['description']);
		}

		$typeProvided  = array_key_exists('type', $fields);
		$postsProvided = array_key_exists('min_posts', $fields);
		$type          = $typeProvided ? $this->groupType($fields['type']) : (int) $current['group_type'];

		if ($typeProvided && $type === 1 && ! allowedTo('admin_forum')) {
			throw ApiException::forbidden('Only administrators can create protected member groups');
		}

		if ($typeProvided || $postsProvided) {
			// Changing "type" without "min_posts" converts a post-count group
			// into a regular one; passing min_posts >= 0 makes it post-based.
			$minPosts = $postsProvided
				? $this->minPosts($fields['min_posts'])
				: ($typeProvided ? -1 : (int) $current['min_posts']);

			if ($minPosts >= 0) {
				$type = 0;
			}

			$options['group_type'] = $type;
			$options['min_posts']  = $minPosts;
		}

		if (array_key_exists('max_messages', $fields)) {
			$options['max_messages'] = max(0, (int) $fields['max_messages']);
		}

		if (array_key_exists('color', $fields)) {
			$options['online_color'] = $this->cleanString($fields['color']);
		}

		if (array_key_exists('icons', $fields)) {
			$options['icons'] = $this->cleanString($fields['icons']);
		}

		$sets   = [];
		$params = ['id' => $id];

		$columnTypes = [
			'group_name'   => 'string',
			'description'  => 'string',
			'group_type'   => 'int',
			'min_posts'    => 'int',
			'max_messages' => 'int',
			'online_color' => 'string',
			'icons'        => 'string',
		];

		foreach ($options as $column => $value) {
			$sets[] = $column . ' = {' . $columnTypes[$column] . ':' . $column . '}';

			$params[$column] = $value;
		}

		$smcFunc['db_query']('', '
			UPDATE {db_prefix}membergroups
			SET ' . implode(', ', $sets) . '
			WHERE id_group = {int:id}', $params);

		$postsChanged = array_key_exists('min_posts', $options)
			&& (int) $options['min_posts'] !== (int) $current['min_posts'];

		call_integration_hook('integrate_save_membergroup', [$id]);

		if ($postsChanged) {
			updateStats('postgroups');
		}

		$settings = ['settings_updated' => time()];

		// Joinable groups make SMF show group membership in profiles.
		if ($type > 1 && empty($modSettings['show_group_membership'])) {
			$settings['show_group_membership'] = 1;
		}

		updateSettings($settings);

		$row = $this->first(
			(new Query())
				->from('{db_prefix}membergroups')
				->where('id_group = {int:id}')
				->columns('id_group, group_name, description, group_type, min_posts, max_messages, online_color, icons, hidden, id_parent, tfa_required')
				->params(['id' => $id])
		);

		return $this->response()->data($this->transform($row));
	}

	/**
	 * Whether another member group already uses this name.
	 */
	private function nameTaken(string $name, ?int $except = null): bool
	{
		$where  = 'group_name = {string:name}';
		$params = ['name' => $name];

		if ($except !== null) {
			$where .= ' AND id_group != {int:id}';

			$params['id'] = $except;
		}

		return $this->exists(
			(new Query())
				->from('{db_prefix}membergroups')
				->where($where)
				->params($params)
		);
	}

	/**
	 * Validate the SMF group type: 0 private, 1 protected, 2 requestable,
	 * 3 free.
	 */
	private function groupType($value): int
	{
		$type = (int) $value;

		if (! in_array($type, self::GROUP_TYPES, true)) {
			throw ApiException::badRequest('Field "type" must be 0, 1, 2 or 3');
		}

		return $type;
	}

	/**
	 * Normalize the post threshold: negative means a regular (non-post) group.
	 */
	private function minPosts($value): int
	{
		return max(-1, (int) $value);
	}

	protected function transform(array $row): array
	{
		return [
			'id'           => (int) $row['id_group'],
			'name'         => $row['group_name'],
			'description'  => $row['description'],
			'type'         => (int) $row['group_type'],
			'min_posts'    => (int) $row['min_posts'],
			'max_messages' => (int) $row['max_messages'],
			'color'        => $row['online_color'],
			'icons'        => $row['icons'],
			'hidden'       => (int) $row['hidden'],
			'parent'       => (int) $row['id_parent'],
			'tfa_required' => (bool) $row['tfa_required'],
		];
	}
}
