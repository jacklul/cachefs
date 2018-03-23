# CacheFS

Functional virtual filesystem that uses PSR-16 cache interface as storage, useful for testing or as persistent cache filesystem.

Based on [vector-kerr/cachefs](https://github.com/vector-kerr/cachefs).

## Installation

Install with [Composer](https://github.com/composer/composer):

```bash
$ composer require jacklul/cachefs
```

## Usage

```php
$memcached = new MemcachedAdapter();    // PSR-16 compatible interface
jacklul\CacheFS\CacheFS::register($memcached);

jacklul\CacheFS\CacheFS::register($memcached, 'myfilesystem');  // using custom stream name

// Write to 'text.txt' file
file_put_contents('cachefs://test.txt', 'test123');

// Create 'test' directory
mkdir('cachefs://test/');

// List root filesystem contents
print_r(scandir('cachefs://'));
```

## License

See [LICENSE](LICENSE).
