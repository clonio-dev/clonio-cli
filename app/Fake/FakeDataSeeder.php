<?php

declare(strict_types=1);

namespace App\Fake;

use Illuminate\Support\Facades\DB;

/**
 * Seeds all fake-data tables for a given database connection.
 *
 * Strategy for large row counts
 * ──────────────────────────────
 *  • Data is inserted in batches of BATCH_SIZE to keep memory usage flat.
 *  • Parent IDs (needed for FK columns in child tables) are kept in memory up
 *    to MAX_IDS_IN_MEMORY entries.  When a parent table has more rows than that
 *    limit, a random sample is kept and children cycle through it — producing a
 *    realistic distribution without unbounded memory growth.
 *  • UUID primary keys are generated in PHP and passed as strings so every
 *    supported engine (MySQL, MariaDB, PostgreSQL, SQL Server, SQLite) receives
 *    a value it can store without special driver magic.
 *
 * @codeCoverageIgnore
 */
final class FakeDataSeeder
{
    private const int BATCH_SIZE = 500;

    private const int MAX_IDS_IN_MEMORY = 50_000;

    private readonly FakeDataGenerator $gen;

    public function __construct(
        private readonly string $connection,
        private readonly int $rowCount,
    ) {
        $this->gen = new FakeDataGenerator;
    }

    // ─── Public API ────────────────────────────────────────────────────────────

    /**
     * Seed all tables in dependency order.
     *
     * @param  callable(string $table, int $inserted, int $total): void  $onProgress
     * @return array<string, int> rows inserted per table
     */
    public function seed(callable $onProgress): array
    {
        $counts = [];

        // Independent root tables first
        [$userIds, $n] = $this->seedUsers($onProgress);
        $counts['users'] = $n;

        [$categoryIds, $n] = $this->seedCategories($onProgress);
        $counts['categories'] = $n;

        [$tagIds, $n] = $this->seedTags($onProgress);
        $counts['tags'] = $n;

        // Tables that depend on users
        [$projectIds, $n] = $this->seedProjects($onProgress, $userIds);
        $counts['projects'] = $n;

        $counts['user_login_history'] = $this->seedUserLoginHistory($onProgress, $userIds);

        // Tables that depend on projects + users
        [$issueIds, $n] = $this->seedIssues($onProgress, $projectIds, $userIds);
        $counts['issues'] = $n;

        // Tables that depend on categories
        [$productIds, $n] = $this->seedProducts($onProgress, $categoryIds);
        $counts['products'] = $n;

        // Junction / leaf tables
        $counts['product_tags'] = $this->seedProductTags($onProgress, $productIds, $tagIds);
        $counts['comments'] = $this->seedComments($onProgress, $issueIds, $userIds);

        return $counts;
    }

    // ─── Per-table seeders ─────────────────────────────────────────────────────

    /**
     * @return array{list<string>, int} [sampled UUIDs, total inserted]
     */
    private function seedUsers(callable $onProgress): array
    {
        $this->gen->resetUnique();
        $roles = ['admin', 'manager', 'member', 'viewer'];
        $statuses = ['active', 'active', 'active', 'suspended', 'deleted'];

        $ids = [];
        $inserted = 0;
        $batch = [];

        for ($i = 1; $i <= $this->rowCount; $i++) {
            $id = $this->gen->uuid();
            $ids[] = $id;

            $firstName = $this->gen->firstName();
            $lastName = $this->gen->lastName();
            $email = strtolower($firstName.'.'.$lastName.'.'.$i).'@example-fake.test';

            $batch[] = [
                'id' => $id,
                'name' => $firstName.' '.$lastName,
                'email' => $email,
                'password_hash' => $this->gen->passwordHash(),
                'role' => $this->gen->randomElement($roles),
                'status' => $this->gen->randomElement($statuses),
                'bio' => $this->gen->bool(40) ? $this->gen->sentence(12) : null,
                'email_verified_at' => $this->gen->bool(80) ? $this->gen->dateTimeBetween('-1 year') : null,
                'created_at' => $this->gen->dateTimeBetween('-2 years'),
                'updated_at' => $this->gen->dateTimeBetween('-1 year'),
            ];

            if (count($batch) >= self::BATCH_SIZE) {
                $this->insertBatch('users', $batch);
                $inserted += count($batch);
                $batch = [];
                $onProgress('users', $inserted, $this->rowCount);
            }
        }

        if ($batch !== []) {
            $this->insertBatch('users', $batch);
            $inserted += count($batch);
            $onProgress('users', $inserted, $this->rowCount);
        }

        return [$this->sample($ids), $inserted];
    }

    /**
     * @param  list<string>  $userIds
     * @return int total inserted
     */
    private function seedUserLoginHistory(callable $onProgress, array $userIds): int
    {
        $statuses = ['success', 'success', 'success', 'failed'];
        $inserted = 0;
        $batch = [];
        $idCount = count($userIds);

        for ($i = 0; $i < $this->rowCount; $i++) {
            $loggedIn = $this->gen->dateTimeBetween('-1 year');
            $loggedOut = $this->gen->bool(75) ? $this->gen->dateTimeBetween($loggedIn) : null;

            $batch[] = [
                'user_id' => $userIds[$i % $idCount],
                'ip_address' => $this->gen->ipv4(),
                'user_agent' => $this->gen->userAgent(),
                'status' => $this->gen->randomElement($statuses),
                'logged_in_at' => $loggedIn,
                'logged_out_at' => $loggedOut,
            ];

            if (count($batch) >= self::BATCH_SIZE) {
                $this->insertBatch('user_login_history', $batch);
                $inserted += count($batch);
                $batch = [];
                $onProgress('user_login_history', $inserted, $this->rowCount);
            }
        }

        if ($batch !== []) {
            $this->insertBatch('user_login_history', $batch);
            $inserted += count($batch);
            $onProgress('user_login_history', $inserted, $this->rowCount);
        }

        return $inserted;
    }

    /**
     * @param  list<string>  $userIds
     * @return array{list<string>, int}
     */
    private function seedProjects(callable $onProgress, array $userIds): array
    {
        $statuses = ['active', 'active', 'archived', 'completed'];
        $ids = [];
        $inserted = 0;
        $batch = [];
        $idCount = count($userIds);

        for ($i = 1; $i <= $this->rowCount; $i++) {
            $id = $this->gen->uuid();
            $ids[] = $id;
            $status = $this->gen->randomElement($statuses);
            $createdAt = $this->gen->dateTimeBetween('-2 years');

            $batch[] = [
                'id' => $id,
                'owner_id' => $userIds[$i % $idCount],
                'name' => ucfirst($this->gen->words(3)).' Project',
                'description' => $this->gen->bool(70) ? $this->gen->paragraph(2) : null,
                'status' => $status,
                'archived_at' => $status === 'archived' ? $this->gen->dateTimeBetween('-6 months') : null,
                'created_at' => $createdAt,
                'updated_at' => $this->gen->dateTimeBetween($createdAt),
            ];

            if (count($batch) >= self::BATCH_SIZE) {
                $this->insertBatch('projects', $batch);
                $inserted += count($batch);
                $batch = [];
                $onProgress('projects', $inserted, $this->rowCount);
            }
        }

        if ($batch !== []) {
            $this->insertBatch('projects', $batch);
            $inserted += count($batch);
            $onProgress('projects', $inserted, $this->rowCount);
        }

        return [$this->sample($ids), $inserted];
    }

    /**
     * @param  list<string>  $projectIds
     * @param  list<string>  $userIds
     * @return array{list<int>, int}
     */
    private function seedIssues(callable $onProgress, array $projectIds, array $userIds): array
    {
        $statuses = ['open', 'open', 'in_progress', 'resolved', 'closed'];
        $priorities = ['low', 'medium', 'medium', 'high', 'critical'];
        $ids = [];
        $inserted = 0;
        $batch = [];
        $projCount = count($projectIds);
        $userCount = count($userIds);

        for ($i = 0; $i < $this->rowCount; $i++) {
            $status = $this->gen->randomElement($statuses);
            $createdAt = $this->gen->dateTimeBetween('-2 years');
            $hasAssignee = $this->gen->bool(60);

            $batch[] = [
                'project_id' => $projectIds[$i % $projCount],
                'reporter_id' => $userIds[$i % $userCount],
                'assignee_id' => $hasAssignee ? $userIds[($i + 1) % $userCount] : null,
                'title' => ucfirst($this->gen->sentence(6)),
                'description' => $this->gen->bool(80) ? $this->gen->paragraph(3) : null,
                'status' => $status,
                'priority' => $this->gen->randomElement($priorities),
                'resolved_at' => in_array($status, ['resolved', 'closed'], true) ? $this->gen->dateTimeBetween($createdAt) : null,
                'created_at' => $createdAt,
                'updated_at' => $this->gen->dateTimeBetween($createdAt),
            ];

            if (count($batch) >= self::BATCH_SIZE) {
                $this->insertBatch('issues', $batch);
                $inserted += count($batch);
                $batch = [];
                $onProgress('issues', $inserted, $this->rowCount);
            }
        }

        if ($batch !== []) {
            $this->insertBatch('issues', $batch);
            $inserted += count($batch);
            $onProgress('issues', $inserted, $this->rowCount);
        }

        // Fetch actual auto-increment IDs from the DB (needed for FK in comments)
        $issueIds = DB::connection($this->connection)
            ->table('issues')
            ->orderBy('id')
            ->pluck('id')
            ->map(fn (mixed $v): int => is_numeric($v) ? (int) $v : 0)
            ->toArray();

        /** @var list<int> $issueIds */
        return [$this->sample($issueIds), $inserted];
    }

    /**
     * @param  list<int>  $issueIds
     * @param  list<string>  $userIds
     */
    private function seedComments(callable $onProgress, array $issueIds, array $userIds): int
    {
        $inserted = 0;
        $batch = [];
        $issueCount = count($issueIds);
        $userCount = count($userIds);

        for ($i = 0; $i < $this->rowCount; $i++) {
            $createdAt = $this->gen->dateTimeBetween('-1 year');

            $batch[] = [
                'issue_id' => $issueIds[$i % $issueCount],
                'user_id' => $userIds[$i % $userCount],
                'body' => $this->gen->paragraph(2),
                'created_at' => $createdAt,
                'updated_at' => $this->gen->dateTimeBetween($createdAt),
            ];

            if (count($batch) >= self::BATCH_SIZE) {
                $this->insertBatch('comments', $batch);
                $inserted += count($batch);
                $batch = [];
                $onProgress('comments', $inserted, $this->rowCount);
            }
        }

        if ($batch !== []) {
            $this->insertBatch('comments', $batch);
            $inserted += count($batch);
            $onProgress('comments', $inserted, $this->rowCount);
        }

        return $inserted;
    }

    /**
     * @return array{list<int>, int}
     */
    private function seedCategories(callable $onProgress): array
    {
        // Seed root categories first (10 %), then children referencing them
        $rootCount = max(1, (int) ($this->rowCount * 0.1));
        $childCount = $this->rowCount - $rootCount;

        $rootIds = [];
        $inserted = 0;
        $slugSuffix = 0;

        // Root categories (no parent)
        $batch = [];
        for ($i = 0; $i < $rootCount; $i++) {
            $slugSuffix++;
            $batch[] = [
                'parent_id' => null,
                'name' => ucfirst($this->gen->word()).' Category',
                'slug' => 'cat-root-'.$slugSuffix,
                'description' => $this->gen->bool(60) ? $this->gen->sentence(10) : null,
                'created_at' => $this->gen->dateTimeBetween('-2 years'),
                'updated_at' => $this->gen->dateTimeBetween('-1 year'),
            ];

            if (count($batch) >= self::BATCH_SIZE) {
                $this->insertBatch('categories', $batch);
                $inserted += count($batch);
                $batch = [];
                $onProgress('categories', $inserted, $this->rowCount);
            }
        }

        if ($batch !== []) {
            $this->insertBatch('categories', $batch);
            $inserted += count($batch);
            $onProgress('categories', $inserted, $this->rowCount);
        }

        // Fetch root IDs
        $rootIds = DB::connection($this->connection)
            ->table('categories')
            ->whereNull('parent_id')
            ->pluck('id')
            ->map(fn (mixed $v): int => is_numeric($v) ? (int) $v : 0)
            ->toArray();

        /** @var list<int> $rootIds */
        $rootCount2 = count($rootIds);

        // Child categories
        $batch = [];
        for ($i = 0; $i < $childCount; $i++) {
            $slugSuffix++;
            $batch[] = [
                'parent_id' => $rootIds[$i % $rootCount2],
                'name' => ucfirst($this->gen->words(2)).' Subcategory',
                'slug' => 'cat-'.$slugSuffix,
                'description' => $this->gen->bool(50) ? $this->gen->sentence(8) : null,
                'created_at' => $this->gen->dateTimeBetween('-2 years'),
                'updated_at' => $this->gen->dateTimeBetween('-1 year'),
            ];

            if (count($batch) >= self::BATCH_SIZE) {
                $this->insertBatch('categories', $batch);
                $inserted += count($batch);
                $batch = [];
                $onProgress('categories', $inserted, $this->rowCount);
            }
        }

        if ($batch !== []) {
            $this->insertBatch('categories', $batch);
            $inserted += count($batch);
            $onProgress('categories', $inserted, $this->rowCount);
        }

        $allIds = DB::connection($this->connection)
            ->table('categories')
            ->pluck('id')
            ->map(fn (mixed $v): int => is_numeric($v) ? (int) $v : 0)
            ->toArray();

        /** @var list<int> $allIds */
        return [$this->sample($allIds), $inserted];
    }

    /**
     * @return array{list<int>, int}
     */
    private function seedTags(callable $onProgress): array
    {
        $ids = [];
        $inserted = 0;
        $batch = [];

        for ($i = 1; $i <= $this->rowCount; $i++) {
            $name = 'tag-'.$i.'-'.$this->gen->word();

            $batch[] = [
                'name' => $name,
                'slug' => 'tag-'.$i,
                'created_at' => $this->gen->dateTimeBetween('-2 years'),
            ];

            if (count($batch) >= self::BATCH_SIZE) {
                $this->insertBatch('tags', $batch);
                $inserted += count($batch);
                $batch = [];
                $onProgress('tags', $inserted, $this->rowCount);
            }
        }

        if ($batch !== []) {
            $this->insertBatch('tags', $batch);
            $inserted += count($batch);
            $onProgress('tags', $inserted, $this->rowCount);
        }

        $ids = DB::connection($this->connection)
            ->table('tags')
            ->pluck('id')
            ->map(fn (mixed $v): int => is_numeric($v) ? (int) $v : 0)
            ->toArray();

        /** @var list<int> $ids */
        return [$this->sample($ids), $inserted];
    }

    /**
     * @param  list<int>  $categoryIds
     * @return array{list<string>, int}
     */
    private function seedProducts(callable $onProgress, array $categoryIds): array
    {
        $statuses = ['available', 'available', 'out_of_stock', 'discontinued'];
        $ids = [];
        $inserted = 0;
        $batch = [];
        $catCount = count($categoryIds);

        for ($i = 1; $i <= $this->rowCount; $i++) {
            $id = $this->gen->uuid();
            $ids[] = $id;

            $batch[] = [
                'id' => $id,
                'category_id' => $categoryIds[$i % $catCount],
                'name' => ucfirst($this->gen->words(3)),
                'description' => $this->gen->bool(80) ? $this->gen->paragraph(2) : null,
                'sku' => 'SKU-'.str_pad((string) $i, 8, '0', STR_PAD_LEFT),
                'price' => $this->gen->price(),
                'stock_qty' => $this->gen->randomInt(0, 5000),
                'status' => $this->gen->randomElement($statuses),
                'created_at' => $this->gen->dateTimeBetween('-2 years'),
                'updated_at' => $this->gen->dateTimeBetween('-6 months'),
            ];

            if (count($batch) >= self::BATCH_SIZE) {
                $this->insertBatch('products', $batch);
                $inserted += count($batch);
                $batch = [];
                $onProgress('products', $inserted, $this->rowCount);
            }
        }

        if ($batch !== []) {
            $this->insertBatch('products', $batch);
            $inserted += count($batch);
            $onProgress('products', $inserted, $this->rowCount);
        }

        return [$this->sample($ids), $inserted];
    }

    /**
     * Creates 1–5 tag associations per product.
     * Progress is reported in terms of products processed (not tag-rows inserted).
     *
     * @param  list<string>  $productIds
     * @param  list<int>  $tagIds
     */
    private function seedProductTags(callable $onProgress, array $productIds, array $tagIds): int
    {
        $tagCount = count($tagIds);
        $totalProducts = count($productIds);
        $inserted = 0;
        $processed = 0;
        $batch = [];

        foreach ($productIds as $productId) {
            $numTags = $this->gen->numberBetween(1, 5);

            // Pick unique tag offsets for this product
            $offsets = range(0, $tagCount - 1);
            shuffle($offsets);
            $offsets = array_slice($offsets, 0, min($numTags, $tagCount));

            foreach ($offsets as $offset) {
                $batch[] = [
                    'product_id' => $productId,
                    'tag_id' => $tagIds[$offset],
                    'created_at' => $this->gen->dateTimeBetween('-1 year'),
                ];
            }

            $processed++;

            if (count($batch) >= self::BATCH_SIZE) {
                $this->insertBatch('product_tags', $batch);
                $inserted += count($batch);
                $batch = [];
                $onProgress('product_tags', $processed, $totalProducts);
            }
        }

        if ($batch !== []) {
            $this->insertBatch('product_tags', $batch);
            $inserted += count($batch);
            $onProgress('product_tags', $processed, $totalProducts);
        }

        return $inserted;
    }

    // ─── Helpers ───────────────────────────────────────────────────────────────

    /** @param list<array<string, mixed>> $rows */
    private function insertBatch(string $table, array $rows): void
    {
        DB::connection($this->connection)->table($table)->insert($rows);
    }

    /**
     * Returns $arr unchanged if it fits within MAX_IDS_IN_MEMORY; otherwise
     * returns a random sample of that size to cap memory usage.
     *
     * @template T
     *
     * @param  list<T>  $arr
     * @return list<T>
     */
    private function sample(array $arr): array
    {
        if (count($arr) <= self::MAX_IDS_IN_MEMORY) {
            return $arr;
        }

        $keys = array_rand($arr, self::MAX_IDS_IN_MEMORY);
        sort($keys);

        return array_map(static fn (int $k): mixed => $arr[$k], $keys);
    }
}
