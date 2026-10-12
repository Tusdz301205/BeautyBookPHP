<?php

namespace Tests\Unit;

use App\Services\Beautybook\SourceDistrictCatalog;
use App\Services\Beautybook\SourceReferenceMedia;
use Illuminate\Database\Connection;
use Illuminate\Database\Query\Builder;
use Illuminate\Support\Facades\DB;
use Mockery;
use Tests\TestCase;

class BeautybookSourceMemoizationTest extends TestCase
{
    public function test_media_and_reference_share_one_query_including_missing_metadata(): void
    {
        $connection = $this->connection('source', 'source');
        DB::shouldReceive('connection')->andReturn($connection);
        $metadata = ['media' => ['url' => '/source.png'], 'reference' => ['sourceUuid' => 'source-id']];
        $this->expectMetadata($connection, 'SERVICE', 7, json_encode($metadata, JSON_THROW_ON_ERROR));
        $this->expectMetadata($connection, 'SERVICE', 8, null);
        $media = new SourceReferenceMedia;

        $this->assertSame($metadata['media'], $media->for('service', 7));
        $this->assertSame($metadata['reference'], $media->reference('SERVICE', '7'));
        $this->assertNull($media->for('SERVICE', 8));
        $this->assertNull($media->reference('service', '8'));
    }

    public function test_media_cache_separates_entity_types_ids_and_database_connections(): void
    {
        $first = $this->connection('source', 'tenant_a');
        $second = $this->connection('source', 'tenant_b');
        $third = $this->connection('other', 'tenant_a');
        DB::shouldReceive('connection')->andReturn($first, $first, $first, $second, $third);
        $this->expectMetadata($first, 'SERVICE', 7, '{"media":{"url":"/service.png"}}');
        $this->expectMetadata($first, 'BRANCH', 7, '{"media":{"url":"/branch.png"}}');
        $this->expectMetadata($first, 'SERVICE', 8, '{"media":{"url":"/other-service.png"}}');
        $this->expectMetadata($second, 'SERVICE', 7, '{"media":{"url":"/tenant-b.png"}}');
        $this->expectMetadata($third, 'SERVICE', 7, '{"media":{"url":"/other-connection.png"}}');
        $media = new SourceReferenceMedia;

        $this->assertSame(['url' => '/service.png'], $media->for('SERVICE', 7));
        $this->assertSame(['url' => '/branch.png'], $media->for('BRANCH', 7));
        $this->assertSame(['url' => '/other-service.png'], $media->for('SERVICE', 8));
        $this->assertSame(['url' => '/tenant-b.png'], $media->for('SERVICE', 7));
        $this->assertSame(['url' => '/other-connection.png'], $media->for('SERVICE', 7));
    }

    public function test_new_scoped_media_instance_reads_mutated_metadata(): void
    {
        $connection = $this->connection('source', 'source');
        DB::shouldReceive('connection')->andReturn($connection);
        $query = $this->expectMetadata($connection, 'SERVICE', 7, null, 2);
        $query->shouldReceive('value')->with('metadata')->twice()
            ->andReturn('{"media":{"url":"/before.png"}}', '{"media":{"url":"/after.png"}}');
        $this->app->scoped(SourceReferenceMedia::class);

        $first = $this->app->make(SourceReferenceMedia::class);
        $this->assertSame(['url' => '/before.png'], $first->for('SERVICE', 7));
        $this->assertSame(['url' => '/before.png'], $first->for('SERVICE', 7));
        $this->app->forgetScopedInstances();
        $second = $this->app->make(SourceReferenceMedia::class);
        $this->assertNotSame($first, $second);
        $this->assertSame(['url' => '/after.png'], $second->for('SERVICE', 7));
    }

    public function test_district_fixture_is_read_once_per_instance_and_returns_independent_arrays(): void
    {
        $originalPath = $this->app->databasePath();
        $path = sys_get_temp_dir().'/beautybook-source-'.bin2hex(random_bytes(8));
        mkdir($path.'/data', 0777, true);
        $file = $path.'/data/beautybook-source-reference.json';

        try {
            $this->app->useDatabasePath($path);
            file_put_contents($file, '{"provinces":[{"code":"HN","name":"Hanoi","districts":["Central"]}]}');
            $catalog = new SourceDistrictCatalog;
            $expected = $catalog->all();
            $this->assertSame([[
                'id' => SourceDistrictCatalog::districtId('HN', 'Central'),
                'name' => 'Central',
                'provinceId' => 'source-hn',
                'province' => ['id' => 'source-hn', 'name' => 'Hanoi', 'code' => 'HN'],
            ]], $expected);
            $modified = $catalog->all();
            $modified[0]['province']['name'] = 'Changed by caller';
            file_put_contents($file, '{"provinces":[]}');
            $this->assertSame($expected, $catalog->all());
            $this->assertSame([], (new SourceDistrictCatalog)->all());
        } finally {
            $this->app->useDatabasePath($originalPath);
            unlink($file);
            rmdir($path.'/data');
            rmdir($path);
        }
    }

    private function connection(string $name, string $database): Connection
    {
        $connection = Mockery::mock(Connection::class);
        $connection->shouldReceive('getName')->andReturn($name);
        $connection->shouldReceive('getDatabaseName')->andReturn($database);

        return $connection;
    }

    private function expectMetadata(Connection $connection, string $type, int $id, ?string $raw, int $times = 1): Builder
    {
        $query = Mockery::mock(Builder::class);
        $connection->shouldReceive('table')->with('beautybook_source_entities')->times($times)->andReturn($query);
        $query->shouldReceive('where')->with('entity_type', $type)->times($times)->andReturnSelf();
        $query->shouldReceive('where')->with('local_id', $id)->times($times)->andReturnSelf();
        if ($times === 1) {
            $query->shouldReceive('value')->with('metadata')->once()->andReturn($raw);
        }

        return $query;
    }
}
