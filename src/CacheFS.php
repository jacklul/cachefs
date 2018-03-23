<?php
/**
 * This file is part of the CacheFS project.
 *
 * (c) Jack'lul <jacklulcat@gmail.com>
 *
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */

namespace jacklul\CacheFS;

use Psr\SimpleCache\CacheInterface;

/**
 * Handler that can use PSR-16 cache as a filesystem
 *
 * @package jacklul\CacheFS
 */
final class CacheFS
{
    /**
     * @var array
     */
    private $index;

    /**
     * @var string
     */
    private $path;

    /**
     * @var resource
     */
    private $fd;

    /**
     * @var string
     */
    private $mode;

    /**
     * @var array
     */
    private $dir;

    /**
     * @var CacheInterface
     */
    private static $cache;

    /**
     * @var string
     */
    private static $protocol;

    /**
     * @param CacheInterface $cache
     * @param string $protocol
     *
     * @return string
     */
    public static function register(CacheInterface $cache, $protocol = 'cachefs')
    {
        if (self::$cache === null) {
            self::$cache = $cache;
            self::$protocol = $protocol;
            stream_wrapper_register($protocol, self::class);
        }

        return self::$protocol . '://';
    }

    /**
     * @return CacheInterface
     */
    private static function cache()
    {
        return self::$cache;
    }

    /**
     * @param string $path
     *
     * @return array
     */
    private function dir_list($path)
    {
        $path = $this->fixPath($path);

        $dir = [];
        foreach ($this->index as $entry => $info) {
            if (strpos($entry, self::$protocol . '://') !== false) {
                if (strpos($entry, $path) !== false && $entry !== $path) {
                    $dir[] = $path . '/' . explode('/', str_replace($path, '', $entry))[1];
                }
            }
        }

        array_unshift($dir, self::$protocol . '://');   // Insert dummy element for next() to work correctly
        $dir = array_unique($dir);

        return $dir;
    }

    /**
     * Obtain filesystem lock then read filesystem index
     *
     * @throws \Psr\SimpleCache\InvalidArgumentException
     */
    public function __construct()
    {
        $time_limit = ini_get('max_execution_time');
        if ($time_limit <= 0) {
            $time_limit = 60;
        }

        $wait = 1000;
        while ($lock = self::cache()->get(self::$protocol . '_index_lock')) {
            if ($lock + $time_limit < time()) {
                break;
            }

            usleep(1000);

            if (128000 > $wait) {
                $wait *= 2;
            }
        }
        self::cache()->set(self::$protocol . '_index_lock', time(), $time_limit);

        $this->index = self::cache()->get(self::$protocol . '_index');
        if (!is_array($this->index)) {
            $this->index = [];
        }

        $this->fd = null;
        $this->mode = null;
        $this->path = null;
        $this->dir = null;
    }

    /**
     * Save filesystem index and release the lock
     *
     * @throws \Psr\SimpleCache\InvalidArgumentException
     */
    public function __destruct()
    {
        self::cache()->set(self::$protocol . '_index', $this->index, 0);
        self::cache()->delete(self::$protocol . '_index_lock');
    }

    /**
     * @param string $from
     * @param string $to
     *
     * @return bool
     * @throws \Psr\SimpleCache\InvalidArgumentException
     */
    public function rename($from, $to)
    {
        $from = $this->fixPath($from);
        $to = $this->fixPath($to);

        if (!isset($this->index[$from])) {
            trigger_error('File not found: ' . $from, E_USER_WARNING);

            return false;
        }

        $parent = substr($to, 0, strrpos($to, '/'));
        if ($parent !== self::$protocol . ':/' && !isset($this->index[$parent])) {
            trigger_error('Target path not found: ' . $to, E_USER_WARNING);

            return false;
        }

        if ($this->index[$from]['type'] === 'f') {
            $contents = self::cache()->get($from);

            if ($contents === null) {
                trigger_error('File contents not found, removing from index: ' . $from, E_USER_WARNING);
                unset($this->index[$from]);

                return false;
            }

            self::cache()->set($to, $contents, 2592000);
            self::cache()->delete($from);

            $this->index[$to] = $this->index[$from];
            unset($this->index[$from]);
        } else {
            unset($this->index[$from]);
            $this->index[$to]['type'] = 'd';
        }

        return true;
    }

    /**
     * Handle stream_close()
     *
     * @throws \Psr\SimpleCache\InvalidArgumentException
     */
    public function stream_close()
    {
        $this->stream_flush();
        fclose($this->fd);
        $this->path = null;
        $this->fd = null;
    }

    /**
     * Handle stream_eof()
     *
     * @return bool
     */
    public function stream_eof()
    {
        return feof($this->fd);
    }

    /**
     * Handle stream_flush()
     *
     * @return bool
     * @throws \Psr\SimpleCache\InvalidArgumentException
     */
    public function stream_flush()
    {
        $parent = substr($this->path, 0, strrpos($this->path, '/'));
        if ($parent !== self::$protocol . ':/' && !isset($this->index[$parent])) {
            trigger_error('Target parent directory not found: ' . $parent, E_USER_WARNING);

            return false;
        }

        switch ($this->mode) {
            case 'r+':
            case 'rb+':
            case 'w':
            case 'wb':
            case 'w+':
            case 'wb+':
            case 'a':
            case 'ab':
            case 'a+':
            case 'ab+':
                $origPos = ftell($this->fd);
                fseek($this->fd, 0, SEEK_END);
                $size = ftell($this->fd);
                fseek($this->fd, 0, SEEK_SET);
                $contents = fread($this->fd, $size);
                fseek($this->fd, $origPos, SEEK_SET);
                self::cache()->set($this->path, $contents, 2592000);
                $this->index[$this->path]['type'] = 'f';
                $this->index[$this->path]['ctime'] = time();
                $this->index[$this->path]['mtime'] = time();
                $this->index[$this->path]['atime'] = time();
                $this->index[$this->path]['size'] = strlen($contents);

                break;
        }

        return true;
    }

    /**
     * Handle stream_metadata()
     *
     * @return false
     */
    public function stream_metadata($path, $option, $value)
    {
        return false;
    }

    /**
     * Handle stream_open()
     *
     * @return bool
     * @throws \Psr\SimpleCache\InvalidArgumentException
     */
    public function stream_open($path, $mode, $options, &$opened_path)
    {
        $path = $this->fixPath($path);

        $fileExists = false;
        if (isset($this->index[$path])) {
            $contents = self::cache()->get($path);
            $fileExists = ($contents !== null);
        }

        switch ($mode) {
            case 'r':
            case 'rb':
            case 'r+':
            case 'rb+':
                if ($fileExists === false) {
                    trigger_error('File not found: ' . $path, E_USER_WARNING);

                    return false;
                }

                $this->fd = fopen('php://memory', "wb+");
                fwrite($this->fd, $contents);
                fseek($this->fd, 0, SEEK_SET);
                $this->index[$path]['atime'] = time();
                break;

            case 'w':
            case 'wb':
            case 'w+':
            case 'wb+':
                $this->fd = fopen('php://memory', "wb+");
                break;

            case 'a':
            case 'ab':
            case 'a+':
            case 'ab+':
                $this->fd = fopen('php://memory', "wb+");

                if ($fileExists === true) {
                    fwrite($this->fd, $contents);
                    fseek($this->fd, 0, SEEK_END);
                    $this->index[$path]['atime'] = time();
                }
                break;

            default:
                return false;
        }

        $this->path = $path;
        $this->mode = $mode;

        return true;
    }

    /**
     * Handle stream_read()
     *
     * @return string|false
     */
    public function stream_read($count)
    {
        return fread($this->fd, $count);
    }

    /**
     * Handle stream_seek()
     *
     * @return int
     */
    public function stream_seek($offset, $whence)
    {
        return fseek($this->fd, $offset, $whence);
    }

    /**
     * Handle stream_set_option()
     *
     * @return false
     */
    public function stream_set_option($option, $arg1, $arg2)
    {
        return false;
    }

    /**
     * Handle stream_stat()
     *
     * @return array
     * @throws \Psr\SimpleCache\InvalidArgumentException
     */
    public function stream_stat()
    {
        return $this->url_stat($this->path, 0);
    }

    /**
     * Handle stream_tell()
     *
     * @return bool
     */
    public function stream_tell()
    {
        return ftell($this->fd);
    }

    /**
     * Handle stream_write()
     *
     * @return bool
     */
    public function stream_write($data)
    {
        return fwrite($this->fd, $data);
    }

    /**
     * Handle unlink()
     *
     * @return bool
     * @throws \Psr\SimpleCache\InvalidArgumentException
     */
    public function unlink($path)
    {
        $path = $this->fixPath($path);

        if (isset($this->index[$path])) {
            if ($this->index[$path]['type'] === 'f') {
                unset($this->index[$path]);
                self::cache()->delete($path);

                return true;
            } else {
                trigger_error('Cannot remove a directory with unlink(), use rmdir() instead: ' . $path, E_USER_WARNING);
            }
        } else {
            trigger_error('File not found: ' . $path, E_USER_WARNING);
        }

        return false;
    }

    /**
     * Handle url_stat()
     *
     * @return array|false
     * @throws \Psr\SimpleCache\InvalidArgumentException
     */
    public function url_stat($path, $flags)
    {
        $path = $this->fixPath($path);

        if (!isset($this->index[$path])) {
            return false;
        }

        $now = time();
        $stat = [
            'dev'     => 0,
            'ino'     => 0,
            'mode'    => 0,
            'nlink'   => 1,
            'uid'     => 0,
            'gid'     => 0,
            'rdev'    => 0,
            'size'    => 0,
            'atime'   => $now,
            'mtime'   => $now,
            'ctime'   => $now,
            'blksize' => 512,
            'blocks'  => 0,
        ];

        if (isset($this->index[$path]['ctime'])) {
            $stat['ctime'] = $this->index[$path]['ctime'];
        }

        if ((isset($this->index[$path]) && $this->index[$path]['type'] === 'd') || $path === self::$protocol . '://') {
            $stat['mode'] = 040777;

            return $stat;
        }

        $contents = self::cache()->get($path);
        if ($contents === null) {
            trigger_error('File contents not found, removing from index: ' . $path, E_USER_WARNING);
            unset($this->index[$path]);

            return false;
        }

        if (isset($this->index[$path]['mtime'])) {
            $stat['mtime'] = $this->index[$path]['mtime'];
        }

        if (isset($this->index[$path]['atime'])) {
            $stat['atime'] = $this->index[$path]['atime'];
        }

        if (isset($this->index[$path]['size'])) {
            $stat['size'] = $this->index[$path]['size'];
        } else {
            $size = strlen($contents);
            $stat['size'] = $size;
        }

        $stat['mode'] = 0100777;
        $stat['blocks'] = (int)(($stat['size'] + 512) / 512);

        return $stat;
    }

    /**
     * Handle closedir()
     *
     * @return true
     */
    public function dir_closedir()
    {
        $this->dir = null;

        return true;
    }

    /**
     * Handle opendir()
     *
     * @return bool
     */
    public function dir_opendir($path, $options)
    {
        $this->dir = $this->dir_list($path);

        return true;
    }

    /**
     * Handle readdir()
     *
     * @return string|bool
     */
    public function dir_readdir()
    {
        if ($this->dir !== null) {
            return next($this->dir);
        }

        return false;
    }

    /**
     * Handle rewinddir()
     *
     * @return bool
     */
    public function dir_rewinddir()
    {
        reset($this->dir);
        return true;
    }

    /**
     * Handle mkdir()
     *
     * @return bool
     */
    public function mkdir($path, $mode, $options)
    {
        $path = $this->fixPath($path);

        if (isset($this->index[$path]) && $this->index[$path]['type'] === 'd') {
            return true;
        }

        $parentEnd = strrpos($path, '/');
        $parent = substr($path, 0, $parentEnd);

        if (isset($this->index[$parent]) && $this->index[$parent]['type'] === 'd') {
            return true;
        }

        while ($path !== self::$protocol . ':/') {
            if (isset($this->index[$path]) && $this->index[$path]['type'] === 'd') {
                break;
            } else {
                $this->index[$path]['type'] = 'd';
                $this->index[$path]['ctime'] = time();
            }

            $path = substr($path, 0, $parentEnd);
            $parentEnd = strrpos($path, '/');
        }

        return true;
    }

    /**
     * Handle rmdir()
     *
     * @return bool
     */
    public function rmdir($path, $options)
    {
        $path = $this->fixPath($path);
        $dir = $this->dir_list($path);

        if (empty($dir) || ($dir[0] === self::$protocol . '://')) {
            foreach ($this->index as $folder => $info) {
                if ($path === $folder && $this->index[$folder]['type'] === 'd') {
                    unset($this->index[$folder]);

                    return true;
                }
            }

            trigger_error('Directory not found: ' . $path, E_USER_WARNING);
        } else {
            trigger_error('Directory is not empty: ' . $path, E_USER_WARNING);
        }

        return false;
    }

    /**
     * Parse and fix path for compatibility
     *
     * @param $path
     *
     * @return string
     */
    private function fixPath($path)
    {
        // Swap backslashes with normal ones
        $path = str_replace('\\', '/', $path);

        // Temporarily remove handler prefix
        $path = str_replace(self::$protocol . '://', '', $path);

        // Replace double slashes and trim slashes
        $path = str_replace('//', '/', str_replace('\\', '/', $path));

        // Trim slashes
        $path = trim($path, '/');

        // Handle '../' relative paths
        $path_exploded = explode('/', $path);
        $path_modified = false;

        for ($i = 0; $i < count($path_exploded); $i++) {
            if ($path_exploded[$i] === '..') {
                unset($path_exploded[$i]);
                unset($path_exploded[$i - 1]);
                $path_exploded = array_values($path_exploded);
                $i = 0;
                $path_modified = true;
            }
        }

        if ($path_modified) {
            $path = implode('/', $path_exploded);
        }

        // Restore the handler prefix
        $path = self::$protocol . '://' . $path;

        return $path;
    }
}
