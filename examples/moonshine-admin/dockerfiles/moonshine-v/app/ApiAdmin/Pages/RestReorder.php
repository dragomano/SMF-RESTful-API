<?php

declare(strict_types=1);

namespace App\ApiAdmin\Pages;

use App\ApiAdmin\Resources\AbstractRestResource;
use Illuminate\Http\Request;
use MoonShine\Contracts\UI\ComponentContract;
use MoonShine\Contracts\UI\TableBuilderContract;
use MoonShine\Crud\JsonResponse;
use MoonShine\Crud\Pages\IndexPage;
use MoonShine\Support\Traits\Makeable;
use RuntimeException;

/**
 * Drag-and-drop reordering for a REST resource whose API exposes a
 * 0-based "order" field on PATCH /{resource}/{id}.
 *
 * The index page delegates to this class instead of implementing the
 * mechanics itself, so the injected code in a generated page stays a few
 * lines (see docker/gen-smf-resources.php) and the logic lives here.
 *
 * `grouped: true` is for resources that render a flat list of items whose
 * ordering is scoped to a sub-group — SMF boards are ordered among their
 * siblings (same category and parent), not across the whole list. The
 * position is then derived from the visual order the table sent, counting
 * only the rows of the dragged item's own group.
 */
final class RestReorder
{
    use Makeable;

    /** The API's own page-size ceiling. */
    private const int PAGE_SIZE = 100;

    public function __construct(
        private readonly IndexPage $page,
        private readonly bool $grouped = false,
    ) {
    }

    /**
     * Turn the page's table into a sortable one.
     */
    public function modifyListComponent(ComponentContract $component): ComponentContract
    {
        if (! $component instanceof TableBuilderContract) {
            return $component;
        }

        return $component->reorderable(
            url: $this->resource()->getAsyncMethodUrl('reorder', page: $this->page),
        );
    }

    /**
     * Async method behind the drop: translate the new position into a PATCH
     * request and ask the table to reload, because the API renumbers the
     * neighbouring rows as well.
     */
    public function handle(): JsonResponse
    {
        $request = request();
        $id = $this->positiveInt($request, 'id');
        $index = $this->index($request);

        $order = $this->grouped
            ? $this->siblingRank($request, $id, $index)
            : $index;

        $resource = $this->resource();

        $resource->httpClient()
            ->patch($resource->resolveItemEndpoint($id), ['order' => $order])
            ->throw();

        return JsonResponse::make()->events([$this->page->getListEventName()]);
    }

    /**
     * The dragged item's position inside its own group, based on the visual
     * order the table submitted (other groups' rows are ignored).
     */
    private function siblingRank(Request $request, int $id, int $index): int
    {
        $groups = $this->groupMap();
        $group = $groups[$id] ?? null;

        if ($group === null) {
            return $index;
        }

        $order = $request->input('data');
        $order = \is_array($order) ? $order : explode(',', (string) $order);

        // The rows arrive in the new visual order, so the rank is simply the
        // number of same-group rows that precede the dragged one.
        $rank = 0;

        foreach ($order as $candidate) {
            $candidate = (int) $candidate;

            if ($candidate === $id) {
                break;
            }

            if (($groups[$candidate] ?? null) === $group) {
                ++$rank;
            }
        }

        return min($rank, $this->groupSize($groups, $group) - 1);
    }

    /**
     * id => group key for every item of the list.
     *
     * Walks the pages because the API caps `limit` at 100; the loop stops on
     * a short page and is bounded in case an upstream ignores `offset`.
     *
     * @return array<int, string>
     */
    private function groupMap(): array
    {
        $resource = $this->resource();
        $client = $resource->httpClient();
        $endpoint = $resource->getDeclaredEndpoint();

        $groups = [];

        for ($page = 0; $page < 50; ++$page) {
            $rows = $client
                ->get($endpoint, ['limit' => self::PAGE_SIZE, 'offset' => $page * self::PAGE_SIZE])
                ->throw()
                ->json('data');

            if (! \is_array($rows) || $rows === []) {
                break;
            }

            foreach ($rows as $row) {
                if (\is_array($row) && isset($row['id'])) {
                    $groups[(int) $row['id']] = $this->groupKey($row);
                }
            }

            if (\count($rows) < self::PAGE_SIZE) {
                break;
            }
        }

        return $groups;
    }

    /**
     * @param  array<int, string>  $groups
     */
    private function groupSize(array $groups, string $group): int
    {
        return max(1, \count(array_filter($groups, static fn (string $key): bool => $key === $group)));
    }

    /**
     * @param  array<string, mixed>  $row
     */
    private function groupKey(array $row): string
    {
        return implode(':', [
            (int) ($row['category'] ?? 0),
            (int) ($row['parent'] ?? 0),
        ]);
    }

    private function resource(): AbstractRestResource
    {
        $resource = $this->page->getResourceOrFail();

        if (! $resource instanceof AbstractRestResource) {
            throw new RuntimeException('Reordering requires a REST resource');
        }

        return $resource;
    }

    private function positiveInt(Request $request, string $key): int
    {
        $value = $request->input($key);

        if (! \is_scalar($value) || (int) $value <= 0) {
            throw new RuntimeException('Missing "' . $key . '" parameter');
        }

        return (int) $value;
    }

    private function index(Request $request): int
    {
        $value = $request->input('index');

        if (! \is_scalar($value) || ! is_numeric($value) || (int) $value < 0) {
            throw new RuntimeException('Missing "index" parameter');
        }

        return (int) $value;
    }
}
