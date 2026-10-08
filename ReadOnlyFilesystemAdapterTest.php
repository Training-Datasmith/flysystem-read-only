<?php

declare(strict_types=1);

namespace League\Flysystem\ReadOnly;

use DateTimeImmutable;
use League\Flysystem\Config;
use League\Flysystem\DirectoryAttributes;
use League\Flysystem\FileAttributes;
use League\Flysystem\Filesystem;
use League\Flysystem\FilesystemOperationFailed;
use League\Flysystem\InMemory\InMemoryFilesystemAdapter;
use League\Flysystem\UnableToCopyFile;
use League\Flysystem\UnableToCreateDirectory;
use League\Flysystem\UnableToDeleteDirectory;
use League\Flysystem\UnableToDeleteFile;
use League\Flysystem\UnableToGeneratePublicUrl;
use League\Flysystem\UnableToGenerateTemporaryUrl;
use League\Flysystem\UnableToMoveFile;
use League\Flysystem\UnableToProvideChecksum;
use League\Flysystem\UnableToReadFile;
use League\Flysystem\UnableToSetVisibility;
use League\Flysystem\UnableToWriteFile;
use League\Flysystem\ChecksumProvider;
use League\Flysystem\UrlGeneration\PublicUrlGenerator;
use League\Flysystem\UrlGeneration\TemporaryUrlGenerator;
use League\Flysystem\Visibility;
use PHPUnit\Framework\TestCase;

use function iterator_to_array;
use function ltrim;
use function stream_get_contents;

class ReadOnlyFilesystemAdapterTest extends TestCase
{
    private const SEED_TIMESTAMP = 1700000000;
    private const READONLY_REASON = 'This is a readonly adapter.';

    public function test_file_exists_true_for_seeded_file(): void
    {
        $inner = $this->seededInnerAdapter();
        $adapter = new ReadOnlyFilesystemAdapter($inner);

        $this->assertTrue($adapter->fileExists('foo/bar.txt'));
    }

    public function test_file_exists_false_for_missing_file(): void
    {
        $inner = new InMemoryFilesystemAdapter();
        $adapter = new ReadOnlyFilesystemAdapter($inner);

        $this->assertFalse($inner->fileExists('missing'));
        $this->assertFalse($adapter->fileExists('missing'));
    }

    public function test_directory_exists_true_for_parent_of_seeded_file(): void
    {
        $inner = $this->seededInnerAdapter();
        $adapter = new ReadOnlyFilesystemAdapter($inner);

        $this->assertTrue($adapter->directoryExists('foo'));
    }

    public function test_directory_exists_false_for_missing_directory(): void
    {
        $inner = new InMemoryFilesystemAdapter();
        $adapter = new ReadOnlyFilesystemAdapter($inner);

        $this->assertFalse($inner->directoryExists('missing'));
        $this->assertFalse($adapter->directoryExists('missing'));
    }

    public function test_read_returns_seeded_bytes(): void
    {
        $inner = $this->seededInnerAdapter();
        $adapter = new ReadOnlyFilesystemAdapter($inner);

        $this->assertSame('content', $adapter->read('foo/bar.txt'));
    }

    public function test_read_missing_file_throws_unable_to_read_file(): void
    {
        $adapter = new ReadOnlyFilesystemAdapter(new InMemoryFilesystemAdapter());

        try {
            $adapter->read('missing.txt');
            $this->fail('Expected UnableToReadFile');
        } catch (UnableToReadFile $exception) {
            $this->assertStringContainsString('missing.txt', $exception->location());
            $this->assertSame(FilesystemOperationFailed::OPERATION_READ, $exception->operation());
        }
    }

    public function test_read_stream_returns_resource_with_seeded_bytes(): void
    {
        $inner = $this->seededInnerAdapter();
        $adapter = new ReadOnlyFilesystemAdapter($inner);

        $stream = $adapter->readStream('foo/bar.txt');
        $this->assertIsResource($stream);
        $this->assertSame('stream', get_resource_type($stream));
        $this->assertSame('content', stream_get_contents($stream));
    }

    public function test_visibility_returns_private_when_seeded_private(): void
    {
        $inner = new InMemoryFilesystemAdapter();
        $inner->write('foo/bar.txt', 'content', new Config([
            'timestamp' => self::SEED_TIMESTAMP,
            Config::OPTION_VISIBILITY => Visibility::PRIVATE,
        ]));
        $adapter = new ReadOnlyFilesystemAdapter($inner);

        $this->assertSame(Visibility::PRIVATE, $adapter->visibility('foo/bar.txt')->visibility());
    }

    public function test_file_size_returns_byte_length(): void
    {
        $inner = $this->seededInnerAdapter();
        $adapter = new ReadOnlyFilesystemAdapter($inner);

        $this->assertSame(7, $adapter->fileSize('foo/bar.txt')->fileSize());
    }

    public function test_last_modified_returns_configured_timestamp(): void
    {
        $inner = $this->seededInnerAdapter();
        $adapter = new ReadOnlyFilesystemAdapter($inner);

        $this->assertSame(self::SEED_TIMESTAMP, $adapter->lastModified('foo/bar.txt')->lastModified());
    }

    public function test_mime_type_matches_inner_adapter(): void
    {
        $inner = $this->seededInnerAdapter();
        $adapter = new ReadOnlyFilesystemAdapter($inner);

        $innerAttributes = $inner->mimeType('foo/bar.txt');
        $wrappedAttributes = $adapter->mimeType('foo/bar.txt');

        $this->assertInstanceOf(FileAttributes::class, $innerAttributes);
        $this->assertInstanceOf(FileAttributes::class, $wrappedAttributes);
        $innerMime = $innerAttributes->mimeType();
        $this->assertIsString($innerMime);
        $this->assertNotSame('', $innerMime);
        $this->assertSame($innerMime, $wrappedAttributes->mimeType());
        $this->assertSame($innerAttributes->path(), $wrappedAttributes->path());
        $this->assertStringContainsString('bar.txt', $wrappedAttributes->path());
    }

    public function test_list_contents_shallow_omits_nested_files(): void
    {
        $inner = new InMemoryFilesystemAdapter();
        $inner->write('foo/bar.txt', 'alpha', new Config(['timestamp' => self::SEED_TIMESTAMP]));
        $inner->write('foo/nested/baz.txt', 'beta-beta', new Config(['timestamp' => self::SEED_TIMESTAMP]));
        $adapter = new ReadOnlyFilesystemAdapter($inner);

        $items = iterator_to_array($adapter->listContents('foo', false), false);
        $this->assertCount(2, $items);

        $this->assertInstanceOf(FileAttributes::class, $items[0]);
        $this->assertSame('foo/bar.txt', $items[0]->path());
        $this->assertSame(5, $items[0]->fileSize());

        $this->assertInstanceOf(DirectoryAttributes::class, $items[1]);
        $this->assertSame('foo/nested', $items[1]->path());

        foreach ($items as $item) {
            $this->assertNotSame('foo/nested/baz.txt', $item->path());
        }
    }

    public function test_list_contents_deep_includes_nested_files(): void
    {
        $inner = new InMemoryFilesystemAdapter();
        $inner->write('foo/bar.txt', 'alpha', new Config(['timestamp' => self::SEED_TIMESTAMP]));
        $inner->write('foo/nested/baz.txt', 'beta-beta', new Config(['timestamp' => self::SEED_TIMESTAMP]));
        $adapter = new ReadOnlyFilesystemAdapter($inner);

        $paths = array_map(
            static fn ($item) => $item->path(),
            iterator_to_array($adapter->listContents('foo', true), false)
        );

        $this->assertSame(['foo/bar.txt', 'foo/nested', 'foo/nested/baz.txt'], $paths);

        $deepFile = iterator_to_array($adapter->listContents('foo', true), false)[2];
        $this->assertInstanceOf(FileAttributes::class, $deepFile);
        $this->assertSame(9, $deepFile->fileSize());
    }

    public function test_list_contents_of_missing_directory_is_empty(): void
    {
        $adapter = new ReadOnlyFilesystemAdapter(new InMemoryFilesystemAdapter());

        $this->assertSame([], iterator_to_array($adapter->listContents('missing', false), false));
    }

    public function test_write_throws_and_does_not_create_file(): void
    {
        $inner = new InMemoryFilesystemAdapter();
        $adapter = new ReadOnlyFilesystemAdapter($inner);

        try {
            $adapter->write('foo', 'content', new Config());
            $this->fail('Expected UnableToWriteFile');
        } catch (UnableToWriteFile $exception) {
            $this->assertSame('foo', $exception->location());
            $this->assertSame(self::READONLY_REASON, $exception->reason());
            $this->assertSame(FilesystemOperationFailed::OPERATION_WRITE, $exception->operation());
        }

        $this->assertFalse($inner->fileExists('foo'));
    }

    public function test_write_stream_throws_without_consuming_stream_or_creating_file(): void
    {
        $inner = new InMemoryFilesystemAdapter();
        $adapter = new ReadOnlyFilesystemAdapter($inner);

        $stream = fopen('php://memory', 'r+b');
        fwrite($stream, 'secret');
        rewind($stream);

        try {
            $adapter->writeStream('new.txt', $stream, new Config());
            $this->fail('Expected UnableToWriteFile');
        } catch (UnableToWriteFile $exception) {
            $this->assertSame('new.txt', $exception->location());
            $this->assertSame(self::READONLY_REASON, $exception->reason());
            $this->assertSame(FilesystemOperationFailed::OPERATION_WRITE, $exception->operation());
        }

        $this->assertIsResource($stream);
        $this->assertSame(0, ftell($stream));
        $this->assertSame('secret', stream_get_contents($stream));
        fclose($stream);

        $this->assertFalse($inner->fileExists('new.txt'));
    }

    public function test_delete_throws_and_leaves_file(): void
    {
        $inner = new InMemoryFilesystemAdapter();
        $inner->write('foo', 'content', new Config(['timestamp' => self::SEED_TIMESTAMP]));
        $adapter = new ReadOnlyFilesystemAdapter($inner);

        try {
            $adapter->delete('foo');
            $this->fail('Expected UnableToDeleteFile');
        } catch (UnableToDeleteFile $exception) {
            $this->assertSame('foo', $exception->location());
            $this->assertSame(self::READONLY_REASON, $exception->reason());
            $this->assertSame(FilesystemOperationFailed::OPERATION_DELETE, $exception->operation());
        }

        $this->assertSame('content', $inner->read('foo'));
    }

    public function test_delete_directory_throws_and_leaves_nested_file(): void
    {
        $inner = new InMemoryFilesystemAdapter();
        $inner->write('dir/file.txt', 'content', new Config(['timestamp' => self::SEED_TIMESTAMP]));
        $adapter = new ReadOnlyFilesystemAdapter($inner);

        try {
            $adapter->deleteDirectory('dir');
            $this->fail('Expected UnableToDeleteDirectory');
        } catch (UnableToDeleteDirectory $exception) {
            $this->assertSame('dir', $exception->location());
            $this->assertSame(self::READONLY_REASON, $exception->reason());
            $this->assertSame(FilesystemOperationFailed::OPERATION_DELETE_DIRECTORY, $exception->operation());
        }

        $this->assertSame('content', $inner->read('dir/file.txt'));
    }

    public function test_create_directory_throws_and_does_not_create_directory(): void
    {
        $inner = new InMemoryFilesystemAdapter();
        $adapter = new ReadOnlyFilesystemAdapter($inner);

        try {
            $adapter->createDirectory('new-dir', new Config());
            $this->fail('Expected UnableToCreateDirectory');
        } catch (UnableToCreateDirectory $exception) {
            $this->assertSame('new-dir', $exception->location());
            $this->assertSame(self::READONLY_REASON, $exception->reason());
            $this->assertSame(FilesystemOperationFailed::OPERATION_CREATE_DIRECTORY, $exception->operation());
        }

        $this->assertFalse($inner->directoryExists('new-dir'));
    }

    public function test_set_visibility_throws_and_leaves_private(): void
    {
        $inner = new InMemoryFilesystemAdapter();
        $inner->write('foo', 'content', new Config([
            'timestamp' => self::SEED_TIMESTAMP,
            Config::OPTION_VISIBILITY => Visibility::PRIVATE,
        ]));
        $adapter = new ReadOnlyFilesystemAdapter($inner);

        try {
            $adapter->setVisibility('foo', Visibility::PUBLIC);
            $this->fail('Expected UnableToSetVisibility');
        } catch (UnableToSetVisibility $exception) {
            $this->assertSame('foo', $exception->location());
            $this->assertSame(self::READONLY_REASON, $exception->reason());
            $this->assertSame(FilesystemOperationFailed::OPERATION_SET_VISIBILITY, $exception->operation());
        }

        $this->assertSame(Visibility::PRIVATE, $inner->visibility('foo')->visibility());
    }

    public function test_move_throws_and_leaves_source_in_place(): void
    {
        $inner = new InMemoryFilesystemAdapter();
        $inner->write('foo', 'content', new Config(['timestamp' => self::SEED_TIMESTAMP]));
        $adapter = new ReadOnlyFilesystemAdapter($inner);

        try {
            $adapter->move('foo', 'bar', new Config());
            $this->fail('Expected UnableToMoveFile');
        } catch (UnableToMoveFile $exception) {
            $this->assertStringContainsString('foo', $exception->getMessage());
            $this->assertStringContainsString('bar', $exception->getMessage());
            $this->assertStringContainsString('readonly', $exception->getMessage());
            $this->assertSame(FilesystemOperationFailed::OPERATION_MOVE, $exception->operation());
        }

        $this->assertSame('content', $inner->read('foo'));
        $this->assertFalse($inner->fileExists('bar'));
    }

    public function test_copy_throws_and_does_not_create_destination(): void
    {
        $inner = new InMemoryFilesystemAdapter();
        $inner->write('foo', 'content', new Config(['timestamp' => self::SEED_TIMESTAMP]));
        $adapter = new ReadOnlyFilesystemAdapter($inner);

        try {
            $adapter->copy('foo', 'bar', new Config());
            $this->fail('Expected UnableToCopyFile');
        } catch (UnableToCopyFile $exception) {
            $this->assertStringContainsString('foo', $exception->getMessage());
            $this->assertStringContainsString('bar', $exception->getMessage());
            $this->assertStringContainsString('readonly', $exception->getMessage());
            $this->assertSame(FilesystemOperationFailed::OPERATION_COPY, $exception->operation());
        }

        $this->assertSame('content', $inner->read('foo'));
        $this->assertFalse($inner->fileExists('bar'));
    }

    public function test_checksum_falls_back_to_md5_when_inner_is_not_a_provider(): void
    {
        $inner = new InMemoryFilesystemAdapter();
        $inner->write('foo/bar.txt', 'content', new Config(['timestamp' => self::SEED_TIMESTAMP]));
        $adapter = new ReadOnlyFilesystemAdapter($inner);

        $this->assertSame(
            '9a0364b9e99bb480dd25e1f0284c8555',
            $adapter->checksum('foo/bar.txt', new Config())
        );
    }

    public function test_checksum_honors_sha256_algo_config(): void
    {
        $inner = new InMemoryFilesystemAdapter();
        $inner->write('foo/bar.txt', 'content', new Config(['timestamp' => self::SEED_TIMESTAMP]));
        $adapter = new ReadOnlyFilesystemAdapter($inner);

        $this->assertSame(
            'ed7002b439e9ac845f22357d822bac1444730fbdb6016d3ec9432297b9ec9f73',
            $adapter->checksum('foo/bar.txt', new Config(['checksum_algo' => 'sha256']))
        );
    }

    public function test_checksum_of_empty_file_is_md5_of_empty_string(): void
    {
        $inner = new InMemoryFilesystemAdapter();
        $inner->write('empty.txt', '', new Config(['timestamp' => self::SEED_TIMESTAMP]));
        $adapter = new ReadOnlyFilesystemAdapter($inner);

        $this->assertSame(
            'd41d8cd98f00b204e9800998ecf8427e',
            $adapter->checksum('empty.txt', new Config())
        );
    }

    public function test_checksum_missing_file_throws_unable_to_provide_checksum(): void
    {
        $adapter = new ReadOnlyFilesystemAdapter(new InMemoryFilesystemAdapter());

        try {
            $adapter->checksum('missing.txt', new Config());
            $this->fail('Expected UnableToProvideChecksum');
        } catch (UnableToProvideChecksum $exception) {
            $this->assertStringContainsString('missing.txt', $exception->getMessage());
            $this->assertInstanceOf(UnableToReadFile::class, $exception->getPrevious());
        }
    }

    public function test_checksum_delegates_to_inner_provider(): void
    {
        $calls = [];
        $inner = new class($calls) extends InMemoryFilesystemAdapter implements ChecksumProvider {
            /** @var list<array{path: string, config: Config}> */
            private array $calls;

            /** @param list<array{path: string, config: Config}> $calls */
            public function __construct(array &$calls)
            {
                parent::__construct();
                $this->calls = &$calls;
            }

            public function checksum(string $path, Config $config): string
            {
                $this->calls[] = ['path' => $path, 'config' => $config];

                return 'provider-checksum';
            }
        };
        $inner->write('foo/bar.txt', 'content', new Config(['timestamp' => self::SEED_TIMESTAMP]));
        $adapter = new ReadOnlyFilesystemAdapter($inner);
        $config = new Config(['checksum_algo' => 'sha256']);

        $result = $adapter->checksum('foo/bar.txt', $config);

        $this->assertSame('provider-checksum', $result);
        $this->assertNotSame('9a0364b9e99bb480dd25e1f0284c8555', $result);
        $this->assertCount(1, $calls);
        $this->assertSame('foo/bar.txt', $calls[0]['path']);
        $this->assertSame($config, $calls[0]['config']);
    }

    public function test_public_url_forwards_path_and_config(): void
    {
        $recorded = [];
        $inner = new class($recorded) extends InMemoryFilesystemAdapter implements PublicUrlGenerator {
            /** @var list<array{path: string, config: Config}> */
            private array $recorded;

            /** @param list<array{path: string, config: Config}> $recorded */
            public function __construct(array &$recorded)
            {
                parent::__construct();
                $this->recorded = &$recorded;
            }

            public function publicUrl(string $path, Config $config): string
            {
                $this->recorded[] = ['path' => $path, 'config' => $config];

                return 'memory://' . ltrim($path, '/');
            }
        };
        $config = new Config(['cdn' => 'files']);
        $adapter = new ReadOnlyFilesystemAdapter($inner);

        $url = $adapter->publicUrl('/path.txt', $config);

        $this->assertSame('memory://path.txt', $url);
        $this->assertCount(1, $recorded);
        $this->assertSame('/path.txt', $recorded[0]['path']);
        $this->assertSame($config, $recorded[0]['config']);
        $this->assertSame('files', $recorded[0]['config']->get('cdn'));
    }

    public function test_public_url_throws_when_inner_is_not_a_generator(): void
    {
        $adapter = new ReadOnlyFilesystemAdapter(new InMemoryFilesystemAdapter());

        try {
            $adapter->publicUrl('/path.txt', new Config());
            $this->fail('Expected UnableToGeneratePublicUrl');
        } catch (UnableToGeneratePublicUrl $exception) {
            $this->assertStringContainsString('/path.txt', $exception->getMessage());
            $this->assertStringContainsString('No generator was configured', $exception->getMessage());
        }
    }

    public function test_temporary_url_forwards_path_expiry_and_config(): void
    {
        $recorded = [];
        $inner = new class($recorded) extends InMemoryFilesystemAdapter implements TemporaryUrlGenerator {
            /** @var list<array{path: string, expiresAt: DateTimeImmutable, config: Config}> */
            private array $recorded;

            /** @param list<array{path: string, expiresAt: DateTimeImmutable, config: Config}> $recorded */
            public function __construct(array &$recorded)
            {
                parent::__construct();
                $this->recorded = &$recorded;
            }

            public function temporaryUrl(string $path, \DateTimeInterface $expiresAt, Config $config): string
            {
                $this->recorded[] = [
                    'path' => $path,
                    'expiresAt' => $expiresAt,
                    'config' => $config,
                ];

                return 'https://files.example/temp';
            }
        };
        $expiresAt = new DateTimeImmutable('2020-01-02T03:04:05+00:00');
        $config = new Config(['ttl' => 15]);
        $adapter = new ReadOnlyFilesystemAdapter($inner);

        $url = $adapter->temporaryUrl('a.txt', $expiresAt, $config);

        $this->assertSame('https://files.example/temp', $url);
        $this->assertCount(1, $recorded);
        $this->assertSame('a.txt', $recorded[0]['path']);
        $this->assertSame($expiresAt, $recorded[0]['expiresAt']);
        $this->assertSame(1577934245, $recorded[0]['expiresAt']->getTimestamp());
        $this->assertSame($config, $recorded[0]['config']);
    }

    public function test_temporary_url_throws_when_inner_is_not_a_generator(): void
    {
        $adapter = new ReadOnlyFilesystemAdapter(new InMemoryFilesystemAdapter());
        $expiresAt = new DateTimeImmutable('2020-01-02T03:04:05+00:00');

        try {
            $adapter->temporaryUrl('a.txt', $expiresAt, new Config());
            $this->fail('Expected UnableToGenerateTemporaryUrl');
        } catch (UnableToGenerateTemporaryUrl $exception) {
            $this->assertStringContainsString('a.txt', $exception->getMessage());
            $this->assertStringContainsString('No generator was configured', $exception->getMessage());
        }
    }

    public function test_filesystem_reads_seeded_file(): void
    {
        $inner = new InMemoryFilesystemAdapter();
        $inner->write('a.txt', 'content', new Config([
            'timestamp' => self::SEED_TIMESTAMP,
            Config::OPTION_VISIBILITY => Visibility::PRIVATE,
        ]));
        $filesystem = new Filesystem(new ReadOnlyFilesystemAdapter($inner));

        $this->assertSame('content', $filesystem->read('a.txt'));
        $this->assertTrue($filesystem->fileExists('a.txt'));
        $this->assertSame(7, $filesystem->fileSize('a.txt'));
        $this->assertSame(Visibility::PRIVATE, $filesystem->visibility('a.txt'));
        $this->assertSame(self::SEED_TIMESTAMP, $filesystem->lastModified('a.txt'));
    }

    public function test_filesystem_write_is_rejected(): void
    {
        $inner = new InMemoryFilesystemAdapter();
        $filesystem = new Filesystem(new ReadOnlyFilesystemAdapter($inner));

        try {
            $filesystem->write('b.txt', 'nope');
            $this->fail('Expected UnableToWriteFile');
        } catch (UnableToWriteFile $exception) {
            $this->assertSame('b.txt', $exception->location());
        }

        $this->assertFalse($inner->fileExists('b.txt'));
    }

    private function seededInnerAdapter(): InMemoryFilesystemAdapter
    {
        $inner = new InMemoryFilesystemAdapter();
        $inner->write('foo/bar.txt', 'content', new Config(['timestamp' => self::SEED_TIMESTAMP]));

        return $inner;
    }
}
