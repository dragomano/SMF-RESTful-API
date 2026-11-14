<?php

declare(strict_types=1);

use SMF\API\ApiException;
use SMF\API\Controllers\AbstractController;
use SMF\API\Query;
use SMF\API\Request;
use SMF\API\Response;
use Testo\Assert;
use Testo\Codecov\Covers;
use Testo\Data\DataSet;
use Testo\Test;

require_once __DIR__ . '/Environment.php';

/**
 * Exposes AbstractController's protected helpers so they can be exercised
 * directly. Not a test case itself (no #[Test]).
 */
final class ProbeController extends AbstractController
{
    public function callId(array $params, string $name = 'id'): int
    {
        return $this->id($params, $name);
    }

    public function callPagination(): array
    {
        return $this->pagination();
    }

    public function callPaginate(Query $query): Response
    {
        return $this->paginate($query);
    }

    public function callWritableColumns(array $schema, bool $full, array $aliases = []): array
    {
        return $this->writableColumns($schema, $full, $aliases);
    }

    public function callInteger(mixed $value, string $field = 'order', int $min = 0, int $max = 10): int
    {
        return $this->integer($value, $field, $min, $max);
    }

    public function callFirst(Query $query): ?array
    {
        return $this->first($query);
    }

    public function callExists(Query $query): bool
    {
        return $this->exists($query);
    }

    public function callFetchAll(Query $query): array
    {
        return $this->fetchAll($query);
    }

    public function callEmail(mixed $value): string
    {
        return $this->email($value, 'email');
    }

    public function callIntList(mixed $value): string
    {
        return $this->intList($value, 'ids');
    }

    public function callClampedInteger(mixed $value, int $min, int $max): int
    {
        return $this->integer($value, 'order', $min, $max, true);
    }

    public function callFloat(mixed $value, string $field = 'offset'): float
    {
        return $this->float($value, $field);
    }

    public function callDate(mixed $value, string $field = 'birthdate'): string
    {
        return $this->date($value, $field);
    }

    public function callBoolean(mixed $value): bool
    {
        return $this->boolean($value);
    }

    public function callClean(mixed $value): string
    {
        return $this->cleanString($value);
    }

    public function callNoContent(): Response
    {
        return $this->noContent();
    }

    public function callTransform(array $row): array
    {
        return $this->transform($row);
    }
}

#[Test]
#[Covers(AbstractController::class)]
final class AbstractControllerTest
{
    public function readsApositiveIdentifier(): void
    {
        Assert::same($this->probe()->callId(['id' => '5']), 5);
    }

    #[DataSet([['id' => 'abc']], 'non-numeric')]
    #[DataSet([['id' => '0']], 'zero')]
    #[DataSet([['id' => '-3']], 'negative')]
    #[DataSet([[]], 'missing')]
    public function rejectsAninvalidIdentifier(array $params): void
    {
        $exception = $this->badRequest(fn () => $this->probe()->callId($params));

        Assert::same($exception->getStatusCode(), 400);
        Assert::same($exception->getMessage(), 'Invalid "id" identifier');
    }

    public function queryHelpersDelegateToTheQueryObject(): void
    {
        $probe = $this->probe();

        $GLOBALS['api_rows']     = [['id' => 1], ['id' => 2]];
        $GLOBALS['api_num_rows'] = 1;

        Assert::same($probe->callFirst((new Query())->from('{db_prefix}boards')->where('1=1')), ['id' => 1]);
        Assert::true($probe->callExists((new Query())->from('{db_prefix}boards')->where('1=1')));
        Assert::same($probe->callFetchAll((new Query())->from('{db_prefix}boards')->where('1=1')), [['id' => 2]]);
    }

    public function emailRejectsAnInvalidAddress(): void
    {
        $exception = $this->badRequest(fn () => $this->probe()->callEmail('not-an-email'));

        Assert::same($exception->getMessage(), 'Field "email" is not a valid email address');
    }

    public function intListSkipsBlankItems(): void
    {
        Assert::same($this->probe()->callIntList('3,,1,3'), '3,1');
    }

    public function integerClampsOutOfRangeValuesWhenRequested(): void
    {
        Assert::same($this->probe()->callClampedInteger('-9', -2, 10), -2);
        Assert::same($this->probe()->callClampedInteger('99', -2, 10), 10);
    }

    public function paginationDefaultsToTheFirstPage(): void
    {
        Assert::same($this->probe()->callPagination(), [25, 0]);
    }

    #[DataSet([['limit' => '50'], 50, 0], 'an explicit limit is honoured')]
    #[DataSet([['limit' => '500'], 100, 0], 'the limit is capped')]
    #[DataSet([['limit' => '0'], 1, 0], 'the limit floors at one')]
    #[DataSet([['offset' => '10'], 25, 10], 'an offset is honoured')]
    #[DataSet([['offset' => '-5'], 25, 0], 'a negative offset floors at zero')]
    public function paginationClampsToSaneBounds(array $query, int $limit, int $offset): void
    {
        Assert::same($this->probe($query)->callPagination(), [$limit, $offset]);
    }

    public function writableColumnsDemandsEveryRequiredFieldForAfullReplace(): void
    {
        $probe     = $this->probe([], ['name' => 'A']);
        $exception = $this->badRequest(fn () => $probe->callWritableColumns($this->categorySchema(), true));

        Assert::same($exception->getStatusCode(), 400);
        Assert::same($exception->getMessage(), 'Field "description" is required for a full update');
    }

    public function writableColumnsMapsFieldsOntoTheirColumnNames(): void
    {
        $probe = $this->probe([], ['name' => ' A ', 'description' => 'B']);

        Assert::same($probe->callWritableColumns($this->categorySchema(), true), [
            'name'        => 'A',
            'description' => 'B',
        ]);
    }

    public function writableColumnsKeepsOnlyTheSuppliedFieldsForApatch(): void
    {
        $probe = $this->probe([], ['description' => 'B']);

        Assert::same($probe->callWritableColumns($this->categorySchema(), false), ['description' => 'B']);
    }

    public function writableColumnsAcceptsSmfColumnNamesAsAliases(): void
    {
        $probe = $this->probe([], ['cat_order' => 3]);

        Assert::same(
            $probe->callWritableColumns($this->categorySchema(), false, ['cat_order' => 'order']),
            ['cat_order' => 3]
        );
    }

    public function writableColumnsRejectsAnEmptyPatch(): void
    {
        $probe     = $this->probe([], ['unrelated' => 'x']);
        $exception = $this->badRequest(fn () => $probe->callWritableColumns($this->categorySchema(), false));

        Assert::same($exception->getStatusCode(), 400);
        Assert::same($exception->getMessage(), 'No writable fields provided');
    }

    public function writableColumnsCastsEverySupportedType(): void
    {
        $probe = $this->probe([], [
            'flag'  => 'yes',
            'count' => '7',
            'email' => 'admin@example.org',
            'date'  => '1999-12-31',
            'tags'  => '3, 1,3',
            'ratio' => '1.256',
            'text'  => ' <b>x</b> ',
        ]);

        Assert::same($probe->callWritableColumns($this->typedSchema(), false), [
            'flag'  => true,
            'count' => 7,
            'email' => 'admin@example.org',
            'date'  => '1999-12-31',
            'tags'  => '3,1',
            'ratio' => 1.26,
            'text'  => '&lt;b&gt;x&lt;/b&gt;',
        ]);
    }

    public function writableColumnsRejectsValuesLongerThanTheColumn(): void
    {
        $probe = $this->probe([], ['text' => 'xxxxx']);

        $exception = $this->badRequest(fn () => $probe->callWritableColumns([
            'text' => ['type' => 'string', 'length' => 4],
        ], false));

        Assert::same($exception->getMessage(), 'Field "text" must not be longer than 4 characters');
    }

    public function emptyDateFallsBackToSmfsNotSetValue(): void
    {
        Assert::same($this->probe()->callDate(''), '1004-01-01');
    }

    public function dateRejectsAnImpossibleDay(): void
    {
        $exception = $this->badRequest(fn () => $this->probe()->callDate('2023-02-30'));

        Assert::same($exception->getMessage(), 'Field "birthdate" must be a date in YYYY-MM-DD format');
    }

    #[DataSet([2, 2.0], 'integer')]
    #[DataSet(['-1.5', -1.5], 'negative string')]
    #[DataSet([0.0, 0.0], 'zero')]
    public function floatAcceptsNumericValues(mixed $value, float $expected): void
    {
        Assert::same($this->probe()->callFloat($value), $expected);
    }

    public function floatRejectsANonNumericValue(): void
    {
        $exception = $this->badRequest(fn () => $this->probe()->callFloat('half past two'));

        Assert::same($exception->getMessage(), 'Field "offset" must be a number');
    }

    public function integerAcceptsNegativesOnlyWhenTheLowerBoundIsNegative(): void
    {
        Assert::same($this->probe()->callInteger('-2', 'parent', -2, 10), -2);

        $exception = $this->badRequest(fn () => $this->probe()->callInteger('-2', 'parent', 0, 10));

        Assert::same($exception->getStatusCode(), 400);
    }

    #[DataSet([0, 0], 'zero int')]
    #[DataSet([10, 10], 'upper bound int')]
    #[DataSet(['0', 0], 'zero string')]
    #[DataSet(['7', 7], 'decimal string')]
    public function integerAcceptsInRangeIntsAndDecimalStrings(mixed $value, int $expected): void
    {
        Assert::same($this->probe()->callInteger($value), $expected);
    }

    #[DataSet([-1], 'below min')]
    #[DataSet([11], 'above max')]
    #[DataSet([1.5], 'float')]
    #[DataSet([true], 'bool')]
    #[DataSet([null], 'null')]
    #[DataSet(['01'], 'leading zero')]
    #[DataSet(['5x'], 'trailing junk')]
    #[DataSet(['999999999999999999999999'], 'overflow')]
    public function integerRejectsOutOfRangeOrLossyValues(mixed $value): void
    {
        $exception = $this->badRequest(fn () => $this->probe()->callInteger($value));

        Assert::same($exception->getStatusCode(), 400);
        Assert::same($exception->getMessage(), 'Field "order" must be an integer between 0 and 10');
    }

    #[DataSet([true, true], 'boolean true')]
    #[DataSet(['yes', true], 'the word yes')]
    #[DataSet(['on', true], 'the word on')]
    #[DataSet(['1', true], 'the string one')]
    #[DataSet(['YES', true], 'an uppercase word is lower-cased first')]
    #[DataSet(['On', true], 'a mixed-case word is lower-cased first')]
    #[DataSet([false, false], 'boolean false')]
    #[DataSet(['no', false], 'the word no')]
    #[DataSet(['0', false], 'the string zero')]
    #[DataSet(['', false], 'the empty string')]
    public function interpretsTheUsualBooleanSpellings(mixed $value, bool $expected): void
    {
        Assert::same($this->probe()->callBoolean($value), $expected);
    }

    public function cleanStringTrimsAndEscapes(): void
    {
        Assert::same($this->probe()->callClean('  <b>hi</b>  '), '&lt;b&gt;hi&lt;/b&gt;');
    }

    public function noContentAnswersWithTwoZeroFour(): void
    {
        Assert::same(Environment::readResponse($this->probe()->callNoContent())['status'], 204);
    }

    public function transformPassesRowsThroughByDefault(): void
    {
        Assert::same($this->probe()->callTransform(['a' => 1, 'b' => 2]), ['a' => 1, 'b' => 2]);
    }

    public function paginateShapesAcollectionResponse(): void
    {
        Environment::reset();

        $GLOBALS['api_rows']  = [['id' => 1]];
        $GLOBALS['api_total'] = 5;

        $probe    = new ProbeController(Environment::request());
        $response = $probe->callPaginate((new Query())->from('{db_prefix}boards')->where('1=1'));
        $payload  = Environment::readResponse($response)['payload'];

        Assert::same($payload['data'], [['id' => 1]]);
        Assert::same($payload['meta'], [
            'total'  => 5,
            'limit'  => 25,
            'offset' => 0,
            'count'  => 1,
        ]);
    }

    public function paginateAppliesTransformToEachRow(): void
    {
        Environment::reset();

        $GLOBALS['api_rows']  = [['id' => 1]];
        $GLOBALS['api_total'] = 1;

        $probe = new class (Environment::request()) extends AbstractController {
            public function run(Query $query): Response
            {
                return $this->paginate($query);
            }

            protected function transform(array $row): array
            {
                return ['wrapped' => $row];
            }
        };

        $response = $probe->run((new Query())->from('{db_prefix}boards')->where('1=1'));

        Assert::same(Environment::readResponse($response)['payload']['data'], [['wrapped' => ['id' => 1]]]);
    }

    /**
     * @return array<string, array<string, mixed>>
     */
    private function categorySchema(): array
    {
        return [
            'name'        => ['type' => 'string', 'column' => 'name', 'length' => 255],
            'description' => ['type' => 'raw', 'column' => 'description'],
            'order'       => ['type' => 'int', 'column' => 'cat_order', 'optional' => true],
        ];
    }

    /**
     * @return array<string, array<string, mixed>>
     */
    private function typedSchema(): array
    {
        return [
            'flag'  => ['type' => 'bool'],
            'count' => ['type' => 'int'],
            'email' => ['type' => 'email'],
            'date'  => ['type' => 'date'],
            'tags'  => ['type' => 'int_list'],
            'ratio' => ['type' => 'float'],
            'text'  => ['type' => 'string'],
        ];
    }

    private function probe(array $query = [], array $body = []): ProbeController
    {
        Environment::reset();

        return new ProbeController(Environment::request($body === [] ? 'GET' : 'POST', $body, $query));
    }

    private function badRequest(callable $callback): ApiException
    {
        try {
            $callback();
        } catch (ApiException $exception) {
            return $exception;
        }

        Assert::fail('Expected a bad request');
    }
}
