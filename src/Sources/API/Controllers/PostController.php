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
 * Read and manage individual posts (messages).
 */
class PostController extends AbstractController
{
	/**
	 * Fields a client may write, mapped onto their {db_prefix}messages column.
	 * Content goes through SMF's modifyPost(); approval uses approvePosts() and
	 * the poster identity columns are written directly (they have no helper).
	 */
	private const WRITABLE = [
		'body'            => ['type' => 'raw', 'column' => 'body'],
		'subject'         => ['type' => 'string', 'column' => 'subject', 'length' => 255, 'optional' => true],
		'icon'            => ['type' => 'string', 'column' => 'icon', 'length' => 16, 'optional' => true],
		'smileys_enabled' => ['type' => 'bool', 'column' => 'smileys_enabled', 'optional' => true],
		'approved'        => ['type' => 'bool', 'column' => 'approved', 'optional' => true],
		'poster_name'     => ['type' => 'string', 'column' => 'poster_name', 'length' => 255, 'optional' => true],
		'poster_email'    => ['type' => 'email', 'column' => 'poster_email', 'optional' => true],
		'poster_time'     => ['type' => 'int', 'column' => 'poster_time', 'optional' => true],
		'modified_reason' => ['type' => 'string', 'column' => 'modified_reason', 'length' => 255, 'optional' => true],
	];

	/** SMF column names accepted in place of the API field names. */
	private const ALIASES = [
		'reason' => 'modified_reason',
	];

	/**
	 * List the posts (messages) of a topic. Nested route:
	 * GET /topics/{topic_id}/posts.
	 */
	public function index(array $params): Response
	{
		global $user_info;

		$topicId = $this->id($params, 'topic_id');

		// The topic must exist and be visible (approved, for non-admins).
		$topic = $this->first(
			(new Query())
				->from('{db_prefix}topics AS t
					INNER JOIN {db_prefix}boards AS b ON (b.id_board = t.id_board)')
				->where('{query_see_board} AND t.id_topic = {int:id}')
				->columns('t.id_topic, t.approved')
				->params(['id' => $topicId])
		);

		if ($topic === null || (empty($user_info['is_admin']) && (int) $topic['approved'] !== 1)) {
			throw ApiException::notFound('Topic not found');
		}

		$where       = '{query_see_board} AND m.id_topic = {int:topic}';
		$queryParams = ['topic' => $topicId];

		if (empty($user_info['is_admin'])) {
			$where .= ' AND m.approved = {int:approved}';

			$queryParams['approved'] = 1;
		}

		$query = (new Query())
			->from('{db_prefix}messages AS m
				INNER JOIN {db_prefix}boards AS b ON (b.id_board = m.id_board)')
			->where($where)
			->columns($this->messageColumns())
			->orderBy('m.id_msg')
			->params($queryParams);

		return $this->paginate($query);
	}

	public function show(array $params): Response
	{
		global $user_info;

		$row = $this->first($this->messageQuery()->params(['id' => $this->id($params)]));

		if ($row === null || (empty($user_info['is_admin']) && (int) $row['approved'] !== 1)) {
			throw ApiException::notFound('Post not found');
		}

		return $this->response()->data($this->transform($row));
	}

	/**
	 * Post a reply to an existing topic on behalf of the API member.
	 */
	public function store(array $params): Response
	{
		global $sourcedir, $user_info, $modSettings;

		$columns = $this->writableColumns(self::WRITABLE, false, self::ALIASES);
		$topicId = (int) $this->request->input('topic', 0);
		$body    = $columns['body'] ?? '';

		if ($topicId <= 0 || $body === '') {
			throw ApiException::badRequest('Fields "topic" and "body" are required');
		}

		// The topic must exist and be visible to the member.
		$topic = $this->first(
			(new Query())
				->from('{db_prefix}topics AS t
					INNER JOIN {db_prefix}boards AS b ON (b.id_board = t.id_board)
					INNER JOIN {db_prefix}messages AS ms ON (ms.id_msg = t.id_first_msg)')
				->where('{query_see_board} AND t.id_topic = {int:id}')
				->columns('t.id_board, t.locked, t.id_member_started, ms.subject')
				->params(['id' => $topicId])
		);

		if ($topic === null) {
			throw ApiException::notFound('Topic not found');
		}

		$board     = (int) $topic['id_board'];
		$isStarter = (int) $topic['id_member_started'] === (int) $user_info['id'];

		// A locked topic only accepts replies from board moderators/admins.
		if (! empty($topic['locked']) && ! allowedTo('moderate_board', $board)) {
			throw ApiException::forbidden('This topic is locked');
		}

		$canReply = allowedTo('post_reply_any', $board)
			|| ($isStarter && allowedTo('post_reply_own', $board))
			|| allowedTo('post_unapproved_replies_any', $board)
			|| ($isStarter && allowedTo('post_unapproved_replies_own', $board));

		if (! $canReply) {
			throw ApiException::forbidden('You cannot reply on this board');
		}

		require_once($sourcedir . '/Subs-Post.php');

		$preparedBody = $body;

		preparsecode($preparedBody);

		// Queue for approval when the member only holds "unapproved" rights.
		$becomesApproved = true;
		if (
			! empty($modSettings['postmod_active'])
			&& ! allowedTo('post_reply_any', $board)
			&& ! ($isStarter && allowedTo('post_reply_own', $board))
		) {
			$becomesApproved = false;
		}

		$subject = $columns['subject'] ?? $topic['subject'];
		if (stripos($subject, 'Re:') !== 0) {
			$subject = 'Re: ' . $subject;
		}

		$msgOptions = [
			'id'              => 0,
			'subject'         => $subject,
			'body'            => $preparedBody,
			'icon'            => $columns['icon'] ?? 'xx',
			'smileys_enabled' => $columns['smileys_enabled'] ?? true,
			'attachments'     => [],
			'approved'        => $becomesApproved,
		];

		$topicOptions = [
			'id'           => $topicId,
			'board'        => $board,
			'mark_as_read' => true,
			'is_approved'  => $becomesApproved,
		];

		$posterOptions = [
			'id'                => (int) $user_info['id'],
			'name'              => $user_info['name'],
			'email'             => $user_info['email'],
			'update_post_count' => true,
			'ip'                => $user_info['ip'] ?? ($_SERVER['REMOTE_ADDR'] ?? ''),
		];

		if (! createPost($msgOptions, $topicOptions, $posterOptions)) {
			throw ApiException::badRequest('The reply could not be created');
		}

		$row = $this->first($this->messageQuery()->params(['id' => (int) $msgOptions['id']]));

		return $this->response()
			->status(201)
			->data($this->transform($row));
	}

	/**
	 * Fully replace a post (PUT): "body" is required, every other writable
	 * field is optional.
	 */
	public function replace(array $params): Response
	{
		return $this->save($params, true);
	}

	/**
	 * Partially update a post (PATCH): any writable field, including the body.
	 */
	public function update(array $params): Response
	{
		return $this->save($params, false);
	}

	/**
	 * Delete a post (message). When it is the only message in its topic the
	 * whole topic goes with it. Honors the recycle bin when enabled.
	 */
	public function destroy(array $params): Response
	{
		global $sourcedir, $user_info;

		$id = $this->id($params);

		$row = $this->first(
			(new Query())
				->from('{db_prefix}messages AS m
					INNER JOIN {db_prefix}topics AS t ON (t.id_topic = m.id_topic)
					INNER JOIN {db_prefix}boards AS b ON (b.id_board = t.id_board)')
				->where('{query_see_board} AND m.id_msg = {int:id}')
				->columns('m.id_msg, m.id_member, m.poster_time, m.approved, t.id_board,
					t.id_first_msg, t.id_member_started, t.num_replies')
				->params(['id' => $id])
		);

		if (
			$row === null
			|| (
				empty($user_info['is_admin'])
				&& (int) $row['approved'] !== 1
				&& (int) $row['id_member'] !== (int) $user_info['id']
			)
		) {
			throw ApiException::notFound('Post not found');
		}

		$this->assertCanDelete($row);

		// removeMessage() aborts with an HTML fatal if asked to delete the opening
		// post of a topic that still has replies. Reject that as JSON instead.
		if ((int) $row['id_first_msg'] === (int) $row['id_msg'] && (int) $row['num_replies'] > 0) {
			throw ApiException::badRequest(
				'Cannot delete the first post while the topic has replies; delete the topic instead'
			);
		}

		require_once($sourcedir . '/RemoveTopic.php');

		// removeMessage() returns true only when it deletes a whole single-post
		// topic; for an ordinary reply it returns null after a successful delete.
		// Permission and existence were verified above, so the return value is not
		// a reliable success/failure signal — do not gate on it.
		removeMessage($id);

		return $this->noContent();
	}

	/**
	 * Shape a message row for output (parsed and raw body).
	 *
	 * @param array<string, mixed> $row
	 * @return array<string, mixed>
	 */
	protected function transform(array $row): array
	{
		return [
			'id'      => (int) $row['id_msg'],
			'topic'   => (int) $row['id_topic'],
			'board'   => (int) $row['id_board'],
			'subject' => $row['subject'],
			'author'  => [
				'id'    => (int) $row['id_member'],
				'name'  => $row['poster_name'],
				'email' => $row['poster_email'],
			],
			'poster_time'     => (int) $row['poster_time'],
			'modified_time'   => (int) $row['modified_time'],
			'modified_name'   => $row['modified_name'],
			'modified_reason' => $row['modified_reason'],
			'icon'            => $row['icon'],
			'smileys_enabled' => (bool) $row['smileys_enabled'],
			'approved'        => (bool) $row['approved'],
			'body'            => parse_bbc($row['body'], true, 'api-' . $row['id_msg']),
			'body_raw'        => $row['body'],
		];
	}

	/**
	 * Shared PUT/PATCH body: check the edit permission, run the new body through
	 * the same pipeline the editor uses, and apply it with SMF's modifyPost().
	 */
	private function save(array $params, bool $full): Response
	{
		global $sourcedir, $user_info, $modSettings;

		$id = $this->id($params);

		$row = $this->first(
			(new Query())
				->from('{db_prefix}messages AS m
					INNER JOIN {db_prefix}topics AS t ON (t.id_topic = m.id_topic)
					INNER JOIN {db_prefix}boards AS b ON (b.id_board = t.id_board)')
				->where('{query_see_board} AND m.id_msg = {int:id}')
				->columns('m.id_msg, m.id_topic, m.id_board, m.id_member, m.poster_time, m.approved,
					m.subject, m.body, m.icon, m.smileys_enabled, m.poster_name, m.poster_email')
				->params(['id' => $id])
		);

		if (
			$row === null
			|| (
				empty($user_info['is_admin'])
				&& (int) $row['approved'] !== 1
				&& (int) $row['id_member'] !== (int) $user_info['id']
			)
		) {
			throw ApiException::notFound('Post not found');
		}

		$board    = (int) $row['id_board'];
		$isAuthor = (int) $row['id_member'] === (int) $user_info['id'];

		// modify_any lets staff edit anything; otherwise only the author may
		// edit, and only within the configured time window.
		if (! allowedTo('modify_any', $board)) {
			if (! ($isAuthor && allowedTo('modify_own', $board))) {
				throw ApiException::forbidden('You cannot edit this post');
			}

			if (
				! empty($modSettings['edit_disable_time'])
				&& (int) $row['poster_time'] + $modSettings['edit_disable_time'] * 60 < time()
			) {
				throw ApiException::forbidden('The time limit for editing this post has passed');
			}
		}

		$columns = $this->writableColumns(self::WRITABLE, $full, self::ALIASES);

		// modifyPost() always needs the body, so the stored one is passed through
		// when only the metadata changes.
		$msgOptions = [
			'id'   => $id,
			'body' => $row['body'],
		];

		if (array_key_exists('body', $columns)) {
			if ($columns['body'] === '') {
				throw ApiException::badRequest('Field "body" cannot be empty');
			}

			require_once($sourcedir . '/Subs-Post.php');

			// SMF stores messages pre-parsed; run the same pipeline the editor uses.
			$preparedBody = $columns['body'];

			preparsecode($preparedBody);

			$msgOptions['body'] = $preparedBody;
		}

		if (array_key_exists('subject', $columns)) {
			if ($columns['subject'] === '') {
				throw ApiException::badRequest('Field "subject" cannot be empty');
			}

			$msgOptions['subject'] = $columns['subject'];
		}

		if (array_key_exists('icon', $columns)) {
			$msgOptions['icon'] = $columns['icon'];
		}

		if (array_key_exists('smileys_enabled', $columns)) {
			$msgOptions['smileys_enabled'] = $columns['smileys_enabled'];
		}

		// Every edit is stamped with who made it and why.
		$msgOptions['modify_time']   = time();
		$msgOptions['modify_name']   = $user_info['name'];
		$msgOptions['modify_reason'] = $columns['modified_reason'] ?? '';

		$topicOptions = [
			'id'    => (int) $row['id_topic'],
			'board' => $board,
			// modifyPost() reads the poll option unconditionally.
			'poll'  => null,
		];
		// Keep the original author for post-count and mention attribution.
		$posterOptions = [
			'id' => (int) $row['id_member'],
		];

		require_once($sourcedir . '/Subs-Post.php');

		modifyPost($msgOptions, $topicOptions, $posterOptions);

		$this->applyDirectColumns($id, $board, $columns, $row);

		$updated = $this->first($this->messageQuery()->params(['id' => $id]));

		return $this->response()->data($this->transform($updated));
	}

	/**
	 * Fields SMF has no helper for: approval, the poster identity columns and
	 * the modification reason when only those change.
	 *
	 * @param array<string, mixed> $columns
	 * @param array<string, mixed> $row
	 */
	private function applyDirectColumns(int $id, int $board, array $columns, array $row): void
	{
		global $sourcedir, $smcFunc;

		if (array_key_exists('approved', $columns) && $columns['approved'] !== (bool) $row['approved']) {
			if (! allowedTo('approve_posts', $board)) {
				throw ApiException::forbidden('You cannot approve or unapprove this post');
			}

			require_once($sourcedir . '/Subs-Post.php');

			approvePosts([$id], $columns['approved']);
		}

		$identity = array_intersect_key($columns, array_flip(['poster_name', 'poster_email', 'poster_time']));

		if ($identity === []) {
			return;
		}

		if (! allowedTo('moderate_forum')) {
			throw ApiException::forbidden('You cannot change the author of this post');
		}

		$sets   = [];
		$params = ['id' => $id];

		foreach ($identity as $column => $value) {
			$type = $column === 'poster_time' ? 'int' : 'string';

			$sets[]          = $column . ' = {' . $type . ':' . $column . '}';
			$params[$column] = $value;
		}

		$smcFunc['db_query']('', '
			UPDATE {db_prefix}messages
			SET ' . implode(', ', $sets) . '
			WHERE id_msg = {int:id}', $params);
	}

	/**
	 * The message columns every answer is built from.
	 */
	private function messageColumns(): string
	{
		return 'm.id_msg, m.id_topic, m.id_board, m.id_member, m.poster_name, m.poster_email, m.poster_time,
			m.subject, m.body, m.icon, m.smileys_enabled, m.approved, m.modified_time, m.modified_name, m.modified_reason';
	}

	private function messageQuery(): Query
	{
		return (new Query())
			->from('{db_prefix}messages AS m
				INNER JOIN {db_prefix}boards AS b ON (b.id_board = m.id_board)')
			->where('{query_see_board} AND m.id_msg = {int:id}')
			->columns($this->messageColumns());
	}

	/**
	 * Reject to delete with a JSON 403 unless the member holds the permission
	 * SMF's removeMessage() would demand — checked here so we can answer with
	 * JSON instead of triggering a fatal HTML error inside the core function.
	 *
	 * @param array<string, mixed> $row
	 */
	private function assertCanDelete(array $row): void
	{
		global $user_info, $modSettings;

		$board     = (int) $row['id_board'];
		$isAuthor  = (int) $row['id_member'] === (int) $user_info['id'];
		$isStarter = (int) $row['id_member_started'] === (int) $user_info['id'];
		$deleteAny = allowedTo('delete_any', $board);

		if (! $deleteAny) {
			if ($isAuthor) {
				$canOwn = allowedTo('delete_own', $board)
					&& (
						empty($modSettings['edit_disable_time'])
						|| (int) $row['poster_time'] + $modSettings['edit_disable_time'] * 60 >= time()
					);

				if (! $canOwn && ! ($isStarter && allowedTo('delete_replies', $board))) {
					throw ApiException::forbidden('You cannot delete this post');
				}
			} elseif (! ($isStarter && allowedTo('delete_replies', $board))) {
				throw ApiException::forbidden('You cannot delete this post');
			}
		}

		// Removing the opening message removes the whole topic, which needs the
		// topic-removal permission on top of the message one.
		if ((int) $row['id_first_msg'] === (int) $row['id_msg']) {
			if (
				! allowedTo('remove_any', $board)
				&& ! ($isAuthor && allowedTo('remove_own', $board))
			) {
				throw ApiException::forbidden('You cannot delete the first post of this topic');
			}
		}

		// A pending post you cannot approve stays invisible, so it stays undeletable.
		if (
			! empty($modSettings['postmod_active'])
			&& (int) $row['approved'] !== 1
			&& ! $isAuthor
			&& ! $deleteAny
			&& ! allowedTo('approve_posts', $board)
		) {
			throw ApiException::forbidden('You cannot delete this post');
		}
	}
}
