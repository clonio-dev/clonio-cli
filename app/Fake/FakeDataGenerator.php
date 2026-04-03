<?php

declare(strict_types=1);

namespace App\Fake;

use Faker\Factory as FakerFactory;
use Faker\Generator;

/**
 * Thin wrapper around Faker, providing typed helpers used by FakeDataSeeder.
 * Kept in its own class so the seeder stays readable and the generator is easy to swap.
 *
 * @codeCoverageIgnore
 */
final class FakeDataGenerator
{
    private readonly Generator $faker;

    public function __construct()
    {
        $this->faker = FakerFactory::create();
    }

    public function uuid(): string
    {
        return $this->faker->uuid();
    }

    public function name(): string
    {
        return $this->faker->name();
    }

    public function firstName(): string
    {
        return $this->faker->firstName();
    }

    public function lastName(): string
    {
        return $this->faker->lastName();
    }

    public function email(): string
    {
        return $this->faker->unique()->safeEmail();
    }

    public function safeEmail(): string
    {
        return $this->faker->safeEmail();
    }

    public function passwordHash(): string
    {
        // Pre-hashed bcrypt value — avoids expensive hashing during bulk seeding
        return '$2y$10$92IXUNpkjO0rOQ5byMi.Ye4oKoEa3Ro9llC/.og/at2.uheWG/igi';
    }

    public function ipv4(): string
    {
        return $this->faker->ipv4();
    }

    public function userAgent(): string
    {
        return $this->faker->userAgent();
    }

    public function sentence(int $nbWords = 8): string
    {
        return $this->faker->sentence($nbWords);
    }

    public function paragraph(int $nbSentences = 4): string
    {
        return $this->faker->paragraph($nbSentences);
    }

    public function text(int $maxNbChars = 500): string
    {
        return $this->faker->text($maxNbChars);
    }

    public function word(): string
    {
        return $this->faker->word();
    }

    public function words(int $nb = 3): string
    {
        $parts = [];
        for ($i = 0; $i < $nb; $i++) {
            $parts[] = $this->faker->word();
        }

        return implode(' ', $parts);
    }

    public function slug(string $base = ''): string
    {
        if ($base === '') {
            $base = $this->words(2);
        }

        return strtolower((string) preg_replace('/[^a-z0-9]+/i', '-', $base));
    }

    public function price(float $min = 0.99, float $max = 9999.99): float
    {
        return round($this->faker->randomFloat(2, $min, $max), 2);
    }

    public function sku(): string
    {
        return strtoupper($this->faker->bothify('??###-???##'));
    }

    public function bool(int $chanceOfGettingTrue = 50): bool
    {
        return $this->faker->boolean($chanceOfGettingTrue);
    }

    public function dateTimeBetween(string $startDate = '-2 years', string $endDate = 'now'): string
    {
        return $this->faker->dateTimeBetween($startDate, $endDate)->format('Y-m-d H:i:s');
    }

    /** @param non-empty-array<mixed> $array */
    public function randomElement(array $array): mixed
    {
        return $this->faker->randomElement($array);
    }

    public function randomInt(int $min = 0, int $max = PHP_INT_MAX): int
    {
        return $this->faker->numberBetween($min, $max);
    }

    public function numberBetween(int $min, int $max): int
    {
        return $this->faker->numberBetween($min, $max);
    }

    /** Reset uniqueness tracking (call between tables that share a unique field). */
    public function resetUnique(): void
    {
        $this->faker->unique(true);
    }
}
