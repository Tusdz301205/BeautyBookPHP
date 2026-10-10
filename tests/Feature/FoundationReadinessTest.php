<?php

namespace Tests\Feature;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class FoundationReadinessTest extends TestCase
{
    use RefreshDatabase;

    public function test_readiness_requires_database_and_seed(): void
    {
        $this->seed();

        $this->get('/ready')
            ->assertOk()
            ->assertJson([
                'status' => 'ready',
                'database' => true,
                'seeded' => true,
            ]);
    }
}
