<?php

declare(strict_types=1);

namespace App\Fake;

use App\Enums\DatabaseConnectionType;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Creates (and optionally drops) the fake-data demo schema on a given connection.
 *
 * Two domain groups:
 *   - Task management : users, user_login_history, projects, issues, comments
 *   - Product catalog : categories, tags, products, product_tags
 *
 * @codeCoverageIgnore
 */
final readonly class FakeSchemaBuilder
{
    public function __construct(
        private string $connection,
        private DatabaseConnectionType $type,
    ) {}

    // ─── Public API ────────────────────────────────────────────────────────────

    public function createAll(): void
    {
        $this->createUsers();
        $this->createCategories();
        $this->createTags();
        $this->createProjects();
        $this->createUserLoginHistory();
        $this->createIssues();
        $this->createProducts();
        $this->createProductTags();
        $this->createComments();
    }

    public function dropAll(): void
    {
        $this->withFkChecksDisabled(function (): void {
            foreach ($this->dropOrder() as $table) {
                Schema::connection($this->connection)->dropIfExists($table);
            }
        });
    }

    // ─── Schema definitions ────────────────────────────────────────────────────

    private function createUsers(): void
    {
        if (Schema::connection($this->connection)->hasTable('users')) {
            return;
        }

        Schema::connection($this->connection)->create('users', function (Blueprint $table): void {
            // UUID primary key — demonstrates non-integer PKs
            $table->uuid('id')->primary();
            $table->string('name');
            $table->string('email')->unique();
            $table->string('password_hash');
            $table->string('role', 32)->default('member'); // admin | manager | member | viewer
            $table->string('status', 32)->default('active'); // active | suspended | deleted
            $table->text('bio')->nullable();
            $table->timestamp('email_verified_at')->nullable();
            $table->timestamps();
        });
    }

    private function createUserLoginHistory(): void
    {
        if (Schema::connection($this->connection)->hasTable('user_login_history')) {
            return;
        }

        Schema::connection($this->connection)->create('user_login_history', function (Blueprint $table): void {
            // bigint auto-increment PK — demonstrates integer PKs alongside UUID FKs
            $table->id();
            $table->uuid('user_id');
            $table->string('ip_address', 45);
            $table->text('user_agent')->nullable();
            $table->string('status', 16)->default('success'); // success | failed
            $table->timestamp('logged_in_at');
            $table->timestamp('logged_out_at')->nullable();

            $table->foreign('user_id')->references('id')->on('users')->cascadeOnDelete();
            $table->index('user_id');
            $table->index('logged_in_at');
        });
    }

    private function createProjects(): void
    {
        if (Schema::connection($this->connection)->hasTable('projects')) {
            return;
        }

        Schema::connection($this->connection)->create('projects', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->uuid('owner_id');
            $table->string('name');
            $table->text('description')->nullable();
            $table->string('status', 32)->default('active'); // active | archived | completed
            $table->timestamp('archived_at')->nullable();
            $table->timestamps();

            $table->foreign('owner_id')->references('id')->on('users')->cascadeOnDelete();
            $table->index('owner_id');
            $table->index('status');
        });
    }

    private function createIssues(): void
    {
        if (Schema::connection($this->connection)->hasTable('issues')) {
            return;
        }

        Schema::connection($this->connection)->create('issues', function (Blueprint $table): void {
            $table->id();
            $table->uuid('project_id');
            $table->uuid('reporter_id');
            $table->uuid('assignee_id')->nullable();
            $table->string('title');
            $table->text('description')->nullable();
            $table->string('status', 32)->default('open'); // open | in_progress | resolved | closed
            $table->string('priority', 16)->default('medium'); // low | medium | high | critical
            $table->timestamp('resolved_at')->nullable();
            $table->timestamps();

            $table->foreign('project_id')->references('id')->on('projects')->cascadeOnDelete();
            $table->foreign('reporter_id')->references('id')->on('users');
            $table->foreign('assignee_id')->references('id')->on('users')->nullOnDelete();
            $table->index('project_id');
            $table->index('status');
            $table->index('priority');
        });
    }

    private function createComments(): void
    {
        if (Schema::connection($this->connection)->hasTable('comments')) {
            return;
        }

        Schema::connection($this->connection)->create('comments', function (Blueprint $table): void {
            $table->id();
            $table->unsignedBigInteger('issue_id');
            $table->uuid('user_id');
            $table->text('body');
            $table->timestamps();

            $table->foreign('issue_id')->references('id')->on('issues')->cascadeOnDelete();
            $table->foreign('user_id')->references('id')->on('users')->cascadeOnDelete();
            $table->index('issue_id');
            $table->index('user_id');
        });
    }

    private function createCategories(): void
    {
        if (Schema::connection($this->connection)->hasTable('categories')) {
            return;
        }

        Schema::connection($this->connection)->create('categories', function (Blueprint $table): void {
            $table->id();
            // Self-referencing FK added after table creation (see addCategoryFk)
            $table->unsignedBigInteger('parent_id')->nullable();
            $table->string('name');
            $table->string('slug')->unique();
            $table->text('description')->nullable();
            $table->timestamps();

            $table->index('parent_id');
        });

        // Add self-ref FK in a second step to keep creation order simple
        Schema::connection($this->connection)->table('categories', function (Blueprint $table): void {
            $table->foreign('parent_id')->references('id')->on('categories')->nullOnDelete();
        });
    }

    private function createTags(): void
    {
        if (Schema::connection($this->connection)->hasTable('tags')) {
            return;
        }

        Schema::connection($this->connection)->create('tags', function (Blueprint $table): void {
            $table->id();
            $table->string('name')->unique();
            $table->string('slug')->unique();
            $table->timestamp('created_at')->nullable();
        });
    }

    private function createProducts(): void
    {
        if (Schema::connection($this->connection)->hasTable('products')) {
            return;
        }

        Schema::connection($this->connection)->create('products', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->unsignedBigInteger('category_id');
            $table->string('name');
            $table->text('description')->nullable();
            $table->string('sku')->unique();
            $table->decimal('price', 10, 2);
            $table->integer('stock_qty')->default(0);
            $table->string('status', 32)->default('available'); // available | out_of_stock | discontinued
            $table->timestamps();

            $table->foreign('category_id')->references('id')->on('categories');
            $table->index('category_id');
            $table->index('status');
            $table->index('price');
        });
    }

    private function createProductTags(): void
    {
        if (Schema::connection($this->connection)->hasTable('product_tags')) {
            return;
        }

        Schema::connection($this->connection)->create('product_tags', function (Blueprint $table): void {
            // Composite PK — demonstrates non-surrogate primary keys
            $table->uuid('product_id');
            $table->unsignedBigInteger('tag_id');
            $table->timestamp('created_at')->nullable();

            $table->primary(['product_id', 'tag_id']);
            $table->foreign('product_id')->references('id')->on('products')->cascadeOnDelete();
            $table->foreign('tag_id')->references('id')->on('tags')->cascadeOnDelete();
        });
    }

    // ─── Helpers ───────────────────────────────────────────────────────────────

    /** @return list<string> */
    private function dropOrder(): array
    {
        return [
            'product_tags',
            'comments',
            'user_login_history',
            'issues',
            'products',
            'projects',
            'categories',
            'tags',
            'users',
        ];
    }

    private function withFkChecksDisabled(callable $callback): void
    {
        match ($this->type) {
            DatabaseConnectionType::Mysql,
            DatabaseConnectionType::MariaDB => $this->wrapMysqlFkOff($callback),
            DatabaseConnectionType::PostgreSQL => $this->wrapPgsqlFkOff($callback),
            DatabaseConnectionType::SqlServer => $this->wrapMssqlFkOff($callback),
            DatabaseConnectionType::Sqlite => $this->wrapSqliteFkOff($callback),
        };
    }

    private function wrapMysqlFkOff(callable $callback): void
    {
        DB::connection($this->connection)->statement('SET FOREIGN_KEY_CHECKS=0');
        try {
            $callback();
        } finally {
            DB::connection($this->connection)->statement('SET FOREIGN_KEY_CHECKS=1');
        }
    }

    private function wrapPgsqlFkOff(callable $callback): void
    {
        // Drop tables in dependency order — PostgreSQL CASCADE handles the rest
        $callback();
    }

    private function wrapMssqlFkOff(callable $callback): void
    {
        // For MSSQL we drop in reverse dependency order; no global FK toggle exists
        $callback();
    }

    private function wrapSqliteFkOff(callable $callback): void
    {
        DB::connection($this->connection)->statement('PRAGMA foreign_keys = OFF');
        try {
            $callback();
        } finally {
            DB::connection($this->connection)->statement('PRAGMA foreign_keys = ON');
        }
    }
}
