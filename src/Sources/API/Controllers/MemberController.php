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
 * Read and manage forum members.
 */
class MemberController extends AbstractController
{
	/**
	 * Fields a client may write, mapped onto their {db_prefix}members column.
	 * Everything is applied through SMF's updateMemberData(), which knows which
	 * columns are integers, floats and dates.
	 *
	 * Credentials and abuse counters (passwd_flood, member_ip, validation_code,
	 * tfa_secret, tfa_backup) stay server-owned on purpose: they are either
	 * secrets SMF manages itself or values that only make sense with an IP.
	 */
	private const WRITABLE = [
		'username'          => ['type' => 'string', 'column' => 'member_name', 'length' => 80],
		'name'              => ['type' => 'string', 'column' => 'real_name', 'length' => 255],
		'email'             => ['type' => 'email', 'column' => 'email_address', 'length' => 255],
		'password'          => ['type' => 'raw', 'column' => 'passwd', 'optional' => true],
		'primary_group'     => ['type' => 'int', 'column' => 'id_group', 'optional' => true],
		'post_group'        => ['type' => 'int', 'column' => 'id_post_group', 'optional' => true],
		'additional_groups' => ['type' => 'int_list', 'column' => 'additional_groups', 'optional' => true],
		'posts'             => ['type' => 'int', 'column' => 'posts', 'optional' => true],
		'personal_text'     => ['type' => 'raw', 'column' => 'personal_text', 'length' => 255, 'optional' => true],
		'signature'         => ['type' => 'raw', 'column' => 'signature', 'optional' => true],
		'birthdate'         => ['type' => 'date', 'column' => 'birthdate', 'optional' => true],
		'website_title'     => ['type' => 'string', 'column' => 'website_title', 'length' => 255, 'optional' => true],
		'website_url'       => ['type' => 'string', 'column' => 'website_url', 'length' => 255, 'optional' => true],
		'usertitle'         => ['type' => 'string', 'column' => 'usertitle', 'length' => 255, 'optional' => true],
		'avatar'            => ['type' => 'string', 'column' => 'avatar', 'length' => 255, 'optional' => true],
		'show_online'       => ['type' => 'bool', 'column' => 'show_online', 'optional' => true],
		'time_format'       => ['type' => 'raw', 'column' => 'time_format', 'length' => 80, 'optional' => true],
		'timezone'          => ['type' => 'string', 'column' => 'timezone', 'length' => 80, 'optional' => true],
		'time_offset'       => ['type' => 'float', 'column' => 'time_offset', 'optional' => true],
		'id_theme'          => ['type' => 'int', 'column' => 'id_theme', 'optional' => true],
		'lngfile'           => ['type' => 'string', 'column' => 'lngfile', 'length' => 255, 'optional' => true],
		'smiley_set'        => ['type' => 'string', 'column' => 'smiley_set', 'length' => 48, 'optional' => true],
		'pm_receive_from'   => ['type' => 'int', 'column' => 'pm_receive_from', 'max' => 3, 'optional' => true],
		'warning'           => ['type' => 'int', 'column' => 'warning', 'max' => 100, 'optional' => true],
		'ignore_boards'     => ['type' => 'int_list', 'column' => 'ignore_boards', 'optional' => true],
		'is_activated'      => ['type' => 'int', 'column' => 'is_activated', 'max' => 3, 'optional' => true],
		'secret_question'   => ['type' => 'string', 'column' => 'secret_question', 'length' => 255, 'optional' => true],
		'secret_answer'     => ['type' => 'string', 'column' => 'secret_answer', 'length' => 64, 'optional' => true],
	];

	/** SMF column names accepted in place of the API field names. */
	private const ALIASES = [
		'member_name'   => 'username',
		'real_name'     => 'name',
		'email_address' => 'email',
		'passwd'        => 'password',
		'id_group'      => 'primary_group',
		'id_post_group' => 'post_group',
	];

	public function index(array $params): Response
	{
		if (! allowedTo('view_mlist')) {
			throw ApiException::forbidden('You cannot view the member list');
		}

		$query = (new Query())
			->from('{db_prefix}members')
			->where('is_activated = {int:active}')
			->columns('id_member, member_name, real_name, posts, date_registered, id_group, id_post_group, additional_groups, last_login')
			->orderBy('id_member')
			->params(['active' => 1]);

		return $this->paginate($query);
	}

	public function show(array $params): Response
	{
		$row = $this->first($this->memberQuery()->params(['id' => $this->id($params)]));

		if ($row === null || (int) $row['is_activated'] !== 1) {
			throw ApiException::notFound('Member not found');
		}

		return $this->response()->data($this->transform($row, true));
	}

	/**
	 * Register a new member. Restricted to staff who may add members.
	 */
	public function store(array $params): Response
	{
		global $sourcedir;

		if (! allowedTo('moderate_forum')) {
			throw ApiException::forbidden('You cannot register members');
		}

		$columns  = $this->writableColumns(self::WRITABLE, false, self::ALIASES);
		$username = $columns['member_name'] ?? '';
		$email    = $columns['email_address'] ?? '';
		$password = $columns['passwd'] ?? '';

		if ($username === '' || $email === '') {
			throw ApiException::badRequest('Fields "username" and "email" are required');
		}

		require_once($sourcedir . '/Subs-Members.php');

		$regOptions = [
			'interface'           => 'admin',
			'username'            => $username,
			'email'               => $email,
			'password'            => $password,
			'password_check'      => $password,
			'check_reserved_name' => true,
			'require'             => 'nothing',
			'send_welcome_email'  => false,
			'memberGroup'         => 0,
		];

		// With return_errors the function hands back an array of language keys
		// instead of triggering a fatal error, so we can answer with JSON.
		$result = registerMember($regOptions, true);

		if (is_array($result)) {
			throw ApiException::badRequest(
				'The member could not be registered',
				['errors' => array_values($result)],
			);
		}

		$id = (int) $result;

		// The registration screen only knows the identity fields; everything else
		// the client sent is applied through the normal update path.
		$profile = array_diff_key($columns, array_flip(['member_name', 'email_address', 'passwd']));

		if ($profile !== []) {
			updateMemberData($id, $profile);
		}

		$row = $this->first($this->memberQuery()->params(['id' => $id]));

		return $this->response()
			->status(201)
			->data($this->transform($row, true));
	}

	/**
	 * Fully replace a member's editable profile (PUT): "name" and "email" are
	 * both required, everything else is optional.
	 */
	public function replace(array $params): Response
	{
		return $this->save($params, true);
	}

	/**
	 * Partially update a member's editable profile (PATCH).
	 */
	public function update(array $params): Response
	{
		return $this->save($params, false);
	}

	/**
	 * Delete a member account. Deleting your own account needs
	 * "profile_remove_own"; deleting anyone else needs "profile_remove_any".
	 */
	public function destroy(array $params): Response
	{
		global $sourcedir, $user_info;

		$id = $this->id($params);

		$row = $this->first(
			(new Query())
				->from('{db_prefix}members')
				->where('id_member = {int:id}')
				->columns('id_member, id_group, additional_groups')
				->params(['id' => $id])
		);

		if ($row === null) {
			throw ApiException::notFound('Member not found');
		}

		$isSelf = $id === (int) $user_info['id'];

		if ($isSelf ? ! allowedTo('profile_remove_own') : ! allowedTo('profile_remove_any')) {
			throw ApiException::forbidden('You cannot delete this member');
		}

		// Only administrators may delete another administrator.
		if ($this->isAdminMember($row) && empty($user_info['is_admin'])) {
			throw ApiException::forbidden('You cannot delete an administrator');
		}

		require_once($sourcedir . '/Subs-Members.php');

		// The second argument keeps deleteMembers() from touching admins when the
		// caller is not one — our check above already rejects that case.
		deleteMembers([$id], empty($user_info['is_admin']));

		return $this->noContent();
	}

	/**
	 * Shape a member row for output, hiding privileged fields from non-admins.
	 */
	protected function transform(array $row, bool $detailed = false): array
	{
		global $user_info;

		$member = [
			'id'                => (int) $row['id_member'],
			'username'          => $row['member_name'],
			'name'              => $row['real_name'],
			'posts'             => (int) $row['posts'],
			'date_registered'   => (int) $row['date_registered'],
			'last_login'        => (int) $row['last_login'],
			'primary_group'     => (int) $row['id_group'],
			'post_group'        => (int) $row['id_post_group'],
			'additional_groups' => array_map(intval(...), array_filter(explode(',', (string) ($row['additional_groups'] ?? '')))),
		];

		// The private half of the profile is only ever exposed to administrators.
		if ($detailed && ! empty($user_info['is_admin'])) {
			$member['email']           = $row['email_address'];
			$member['personal_text']   = $row['personal_text'];
			$member['signature']       = $row['signature'];
			$member['birthdate']       = $row['birthdate'];
			$member['website_title']   = $row['website_title'];
			$member['website_url']     = $row['website_url'];
			$member['usertitle']       = $row['usertitle'];
			$member['avatar']          = $row['avatar'];
			$member['show_online']     = (bool) $row['show_online'];
			$member['time_format']     = $row['time_format'];
			$member['timezone']        = $row['timezone'];
			$member['time_offset']     = (float) $row['time_offset'];
			$member['id_theme']        = (int) $row['id_theme'];
			$member['lngfile']         = $row['lngfile'];
			$member['smiley_set']      = $row['smiley_set'];
			$member['pm_receive_from'] = (int) $row['pm_receive_from'];
			$member['warning']         = (int) $row['warning'];
			$member['ignore_boards']   = $row['ignore_boards'];
			$member['secret_question'] = $row['secret_question'];
			$member['is_activated']    = (int) $row['is_activated'];
		}

		return $member;
	}

	/**
	 * Shared PUT/PATCH body: validate access and the fields, then apply them
	 * through SMF's updateMemberData() and answer with the stored member.
	 */
	private function save(array $params, bool $full): Response
	{
		global $sourcedir, $user_info;

		$id = $this->id($params);

		$row = $this->first(
			(new Query())
				->from('{db_prefix}members')
				->where('id_member = {int:id}')
				->columns('id_member, member_name, is_activated')
				->params(['id' => $id])
		);

		if ($row === null || (int) $row['is_activated'] !== 1) {
			throw ApiException::notFound('Member not found');
		}

		$isSelf = $id === (int) $user_info['id'];

		if ($isSelf ? ! allowedTo('profile_identity_own') : ! allowedTo('profile_identity_any')) {
			throw ApiException::forbidden('You cannot edit this member');
		}

		$columns = $this->writableColumns(self::WRITABLE, $full, self::ALIASES);

		if (array_key_exists('member_name', $columns)) {
			if ($columns['member_name'] === '') {
				throw ApiException::badRequest('Field "username" cannot be empty');
			}

			$this->assertUnique('member_name', $columns['member_name'], $id, 'This username is already taken');
		}

		if (array_key_exists('real_name', $columns) && $columns['real_name'] === '') {
			throw ApiException::badRequest('Field "name" cannot be empty');
		}

		if (array_key_exists('email_address', $columns)) {
			$this->assertUnique('email_address', $columns['email_address'], $id, 'This email address is already in use');
		}

		$this->assertCanWriteSensitive($columns);

		if (array_key_exists('passwd', $columns)) {
			$this->assertPassword($columns['passwd'], $isSelf);

			require_once($sourcedir . '/Subs-Auth.php');

			// bcrypt over the username, exactly like SMF's own password screens.
			$columns['passwd'] = hash_password(
				$columns['member_name'] ?? $row['member_name'],
				$columns['passwd'],
			);
		}

		require_once($sourcedir . '/Subs-Members.php');

		// updateMemberData() expects 0/1 for its integer columns, so the boolean
		// spellings accepted by the API are normalized before the update.
		$columns = array_map(static fn (mixed $value): mixed => is_bool($value) ? (int) $value : $value, $columns);

		updateMemberData($id, $columns);

		$member = $this->first($this->memberQuery()->params(['id' => $id]));

		return $this->response()->data($this->transform($member, true));
	}

	/**
	 * Group membership, counters and activation state are not part of a regular
	 * profile edit; SMF gates them behind its own admin permissions.
	 *
	 * @param array<string, mixed> $columns
	 */
	private function assertCanWriteSensitive(array $columns): void
	{
		$groups = ['id_group', 'id_post_group', 'additional_groups'];
		$staff  = ['posts', 'warning', 'is_activated', 'secret_question', 'secret_answer'];

		if (array_intersect($groups, array_keys($columns)) !== [] && ! allowedTo('manage_membergroups')) {
			throw ApiException::forbidden('You cannot change the groups of this member');
		}

		if (array_intersect($staff, array_keys($columns)) !== [] && ! allowedTo('moderate_forum')) {
			throw ApiException::forbidden('You cannot change the counters or the activation state of this member');
		}
	}

	/**
	 * A password may only be reset by its owner or by staff who moderate members.
	 */
	private function assertPassword(string $password, bool $isSelf): void
	{
		if (! $isSelf && ! allowedTo('moderate_forum')) {
			throw ApiException::forbidden('You cannot change this member\'s password');
		}

		if ($password === '') {
			throw ApiException::badRequest('Field "password" cannot be empty');
		}
	}

	/**
	 * Reject a value another member already uses (usernames and emails are unique
	 * in SMF, but updateMemberData() does not enforce it).
	 */
	private function assertUnique(string $column, string $value, int $id, string $message): void
	{
		$taken = $this->exists(
			(new Query())
				->from('{db_prefix}members')
				->where($column . ' = {string:value} AND id_member != {int:id}')
				->params(['value' => $value, 'id' => $id])
		);

		if ($taken) {
			throw ApiException::badRequest($message);
		}
	}

	/**
	 * The columns a member answer is built from.
	 */
	private function memberQuery(): Query
	{
		return (new Query())
			->from('{db_prefix}members')
			->where('id_member = {int:id}')
			->columns('id_member, member_name, real_name, email_address, posts, date_registered,
				id_group, id_post_group, additional_groups, last_login, is_activated, personal_text,
				signature, birthdate, website_title, website_url, usertitle, avatar, show_online,
				time_format, timezone, time_offset, id_theme, lngfile, smiley_set, pm_receive_from,
				warning, ignore_boards, secret_question, secret_answer');
	}

	/**
	 * Whether a member row belongs to the administrator group (as primary or
	 * additional group).
	 */
	private function isAdminMember(array $row): bool
	{
		if ((int) $row['id_group'] === 1) {
			return true;
		}

		$additional = array_map(intval(...), array_filter(explode(',', (string) $row['additional_groups'])));

		return in_array(1, $additional, true);
	}
}
