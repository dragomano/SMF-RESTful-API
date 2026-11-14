<?php declare(strict_types=1);

/**
 * AbstractController.php
 *
 * @package SMF RESTful API
 * @author Bugo <bugo@dragomano.ru>
 * @copyright 2026 Bugo
 * @license https://opensource.org/licenses/MIT The MIT License
 *
 * @version 0.1
 */

namespace SMF\API\Controllers;

use DateTimeImmutable;
use SMF\API\ApiException;
use SMF\API\Query;
use SMF\API\Request;
use SMF\API\Response;

if (! defined('SMF'))
	die('No direct access...');

/**
 * Shared plumbing for resource controllers: request access, pagination and a
 * response factory.
 */
abstract class AbstractController
{
	/** Hard ceiling on how many rows one collection request may return. */
	protected const MAX_LIMIT = 100;

	/** Default page size when the client does not ask for one. */
	protected const DEFAULT_LIMIT = 25;

	public function __construct(protected Request $request) {}

	protected function response(): Response
	{
		return new Response();
	}

	/**
	 * Read a required positive integer route parameter.
	 */
	protected function id(array $params, string $name = 'id'): int
	{
		$value = $params[$name] ?? '';

		if (! ctype_digit((string) $value) || (int) $value <= 0) {
			throw ApiException::badRequest('Invalid "' . $name . '" identifier');
		}

		return (int) $value;
	}

	/**
	 * Resolve limit/offset from the query string, clamped to sane bounds.
	 *
	 * @return array{0:int,1:int} [limit, offset]
	 */
	protected function pagination(): array
	{
		$limit  = (int) $this->request->query('limit', self::DEFAULT_LIMIT);
		$limit  = max(1, min($limit, self::MAX_LIMIT));
		$offset = max(0, (int) $this->request->query('offset', 0));

		return [$limit, $offset];
	}

	/**
	 * Run a paginated Query and shape it into a collection Response. Each row is
	 * passed through transform(), which collection controllers override.
	 */
	protected function paginate(Query $query): Response
	{
		[$limit, $offset] = $this->pagination();

		['rows' => $rows, 'total' => $total] = $query->fetchPage($limit, $offset);

		$items = array_map($this->transform(...), $rows);

		return $this->response()->collection($items, $total, $limit, $offset);
	}

	/**
	 * Fetch a single row for a Query, or null when nothing matches.
	 *
	 * @return array<string, mixed>|null
	 */
	protected function first(Query $query): ?array
	{
		return $query->first();
	}

	/**
	 * Whether at least one row matches the Query.
	 */
	protected function exists(Query $query): bool
	{
		return $query->exists();
	}

	/**
	 * Fetch every matching row of a Query (no pagination), as raw DB rows.
	 *
	 * @return array<int, array<string, mixed>>
	 */
	protected function fetchAll(Query $query): array
	{
		return $query->fetchAll();
	}

	/**
	 * Shape a raw DB row for output. Collection controllers override this; the
	 * default returns the row unchanged.
	 *
	 * @param array<string, mixed> $row
	 * @return array<string, mixed>
	 */
	protected function transform(array $row): array
	{
		return $row;
	}

	/**
	 * Sanitize a string coming from the request body for safe storage.
	 */
	protected function cleanString($value): string
	{
		global $smcFunc;

		return $smcFunc['htmlspecialchars'](trim((string) $value), ENT_QUOTES);
	}

	/**
	 * Map the request body onto SMF columns.
	 *
	 * Every accepted field is described in the resource's schema:
	 *
	 *     'name' => [
	 *         'type'     => 'string'|'raw'|'int'|'bool'|'email'|'date'|'int_list',
	 *         'column'   => 'smf_column',  // only when it differs from the field name
	 *         'min'      => 0,             // int bounds, negatives allowed when min < 0
	 *         'max'      => 100,
	 *         'length'   => 255,           // string/raw length cap in characters
	 *         'optional' => true,          // accepted, but not required by a full replace
	 *     ]
	 *
	 * A full replace (PUT) demands every non-optional field, a partial update
	 * (PATCH) keeps just the fields that were supplied; a PATCH that changes
	 * nothing is itself a bad request.
	 *
	 * @param array<string, array<string, mixed>> $schema Field name => description.
	 * @param array<string, string> $aliases SMF column name => field name.
	 * @return array<string, mixed> SMF column => value.
	 */
	protected function writableColumns(array $schema, bool $full, array $aliases = []): array
	{
		$body = $this->request->all();

		// Let clients address the fields by their SMF column name as well.
		foreach ($aliases as $alias => $field) {
			if (! array_key_exists($field, $body) && array_key_exists($alias, $body)) {
				$body[$field] = $body[$alias];
			}
		}

		$columns = [];

		foreach ($schema as $field => $definition) {
			if (! array_key_exists($field, $body)) {
				if ($full && empty($definition['optional'])) {
					throw ApiException::badRequest('Field "' . $field . '" is required for a full update');
				}

				continue;
			}

			$columns[$definition['column'] ?? $field] = $this->castValue($field, $body[$field], $definition);
		}

		if ($columns === []) {
			throw ApiException::badRequest('No writable fields provided');
		}

		return $columns;
	}

	/**
	 * Validate and normalize a single value according to its field definition.
	 *
	 * @param array<string, mixed> $definition
	 */
	protected function castValue(string $field, mixed $value, array $definition): mixed
	{
		return match ($definition['type'] ?? 'string') {
			'int'      => $this->integer(
				$value,
				$field,
				$definition['min'] ?? 0,
				$definition['max'] ?? PHP_INT_MAX,
				$definition['clamp'] ?? false,
			),
			'float'    => $this->float($value, $field),
			'bool'     => $this->boolean($value),
			'email'    => $this->email($value, $field),
			'date'     => $this->date($value, $field),
			'int_list' => $this->intList($value, $field),
			'raw'      => $this->rawString($value, $field, $definition['length'] ?? null),
			default    => $this->cleanString($this->assertLength($field, (string) $value, $definition['length'] ?? null)),
		};
	}

	/**
	 * Validate a floating point value (SMF stores time offsets as floats).
	 */
	protected function float($value, string $field): float
	{
		$number = is_int($value) || is_float($value)
			? (float) $value
			: (is_string($value) && is_numeric(trim($value)) ? (float) trim($value) : null);

		if ($number === null) {
			throw ApiException::badRequest('Field "' . $field . '" must be a number');
		}

		return round($number, 2);
	}

	/**
	 * Reject a value longer than the column allows, so MySQL does not silently
	 * truncate it.
	 */
	protected function assertLength(string $field, string $value, ?int $length): string
	{
		if ($length !== null && mb_strlen($value) > $length) {
			throw ApiException::badRequest(
				'Field "' . $field . '" must not be longer than ' . $length . ' characters'
			);
		}

		return $value;
	}

	/**
	 * Trim a value that SMF stores as-is (message bodies, signatures, ...).
	 */
	protected function rawString($value, string $field, ?int $length = null): string
	{
		return $this->assertLength($field, trim((string) $value), $length);
	}

	/**
	 * Validate an email address.
	 */
	protected function email($value, string $field): string
	{
		$email = trim((string) $value);

		if (! filter_var($email, FILTER_VALIDATE_EMAIL)) {
			throw ApiException::badRequest('Field "' . $field . '" is not a valid email address');
		}

		return $email;
	}

	/**
	 * Normalize a birthday: SMF keeps "not set" as 1004-01-01.
	 */
	protected function date($value, string $field): string
	{
		$date = trim((string) $value);

		if ($date === '') {
			return '1004-01-01';
		}

		$parsed = DateTimeImmutable::createFromFormat('!Y-m-d', $date);

		if ($parsed === false || $parsed->format('Y-m-d') !== $date) {
			throw ApiException::badRequest('Field "' . $field . '" must be a date in YYYY-MM-DD format');
		}

		return $date;
	}

	/**
	 * Normalize a list of ids (`"2,7"` or `[2, 7]`) into SMF's comma form.
	 */
	protected function intList($value, string $field): string
	{
		$values = is_array($value) ? $value : explode(',', (string) $value);
		$ids    = [];

		foreach ($values as $item) {
			$item = trim((string) $item);

			if ($item === '') {
				continue;
			}

			$ids[] = $this->integer($item, $field, 0, PHP_INT_MAX);
		}

		return implode(',', array_values(array_unique($ids)));
	}

	/**
	 * Validate an integer without PHP's lossy casts (also accepts decimal form
	 * values). Negative values are allowed only when the lower bound is
	 * negative; with $clamp the value is pinned to the bound instead of being
	 * rejected (SMF treats, for instance, min_posts = -1 as "not post-based").
	 */
	protected function integer($value, string $field, int $min, int $max, bool $clamp = false): int
	{
		$string = is_int($value) || is_string($value) ? trim((string) $value) : '';
		$digits = str_starts_with($string, '-') ? substr($string, 1) : $string;
		$limit  = (string) PHP_INT_MAX;

		$valid = $digits !== ''
			&& preg_match('/^[0-9]+$/D', $digits) === 1
			&& ! ($digits[0] === '0' && $digits !== '0')
			&& ($min < 0 || $clamp || ! str_starts_with($string, '-'))
			&& (strlen($digits) < strlen($limit) || (strlen($digits) === strlen($limit) && strcmp($digits, $limit) <= 0));

		if (! $valid) {
			throw ApiException::badRequest('Field "' . $field . '" must be an integer between ' . $min . ' and ' . $max);
		}

		$number = (int) $string;

		if ($number < $min || $number > $max) {
			if (! $clamp) {
				throw ApiException::badRequest('Field "' . $field . '" must be an integer between ' . $min . ' and ' . $max);
			}

			return max($min, min($max, $number));
		}

		return $number;
	}

	/**
	 * Interpret a request value as a boolean, accepting the usual JSON and
	 * form-encoded spellings (true/false, 1/0, "yes"/"no", "on"/"off").
	 */
	protected function boolean($value): bool
	{
		if (is_bool($value)) {
			return $value;
		}

		return in_array(strtolower((string) $value), ['1', 'true', 'yes', 'on'], true);
	}

	/**
	 * An empty 204 response for successful writes that return no body field.
	 */
	protected function noContent(): Response
	{
		return $this->response()->status(204);
	}
}
