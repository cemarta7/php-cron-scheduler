<?php namespace GO;

use InvalidArgumentException;

class RedisLock
{
    /**
     * @var object
     */
    private $redis;

    /**
     * @var string
     */
    private $prefix = 'php-cron-scheduler:';

    /**
     * @param array|object $config
     */
    public function __construct($config)
    {
        if (is_array($config) && isset($config['prefix'])) {
            $this->prefix = (string) $config['prefix'];
        }

        if (is_array($config) && isset($config['client'])) {
            $this->redis = $config['client'];
            return;
        }

        if (is_object($config)) {
            $this->redis = $config;
            return;
        }

        if (! is_array($config)) {
            throw new InvalidArgumentException('Redis configuration should be an array or Redis client instance.');
        }

        if (! class_exists('Redis')) {
            throw new InvalidArgumentException('The Redis PHP extension is required to use Redis locks.');
        }

        $host = isset($config['host']) ? $config['host'] : (isset($config[0]) ? $config[0] : '127.0.0.1');
        $port = isset($config['port']) ? $config['port'] : (isset($config[1]) ? $config[1] : 6379);
        $timeout = isset($config['timeout']) ? $config['timeout'] : (isset($config[2]) ? $config[2] : 0.0);

        $redis = new \Redis();
        $redis->connect($host, $port, $timeout);

        if (isset($config['auth'])) {
            $redis->auth($config['auth']);
        }

        if (isset($config['database'])) {
            $redis->select((int) $config['database']);
        }

        $this->redis = $redis;
    }

    /**
     * @param  string  $key
     * @param  int     $ttl
     * @return array|false
     */
    public function acquire($key, $ttl)
    {
        $ttl = $this->normalizeTtl($ttl);
        $token = bin2hex(random_bytes(16));
        $redisKey = $this->key($key);

        if ($this->redis->set($redisKey, $token, ['nx', 'ex' => $ttl])) {
            return [
                'key' => $redisKey,
                'token' => $token,
            ];
        }

        return false;
    }

    /**
     * @param  string  $key
     * @param  int     $ttl
     * @return bool
     */
    public function acquireCooldown($key, $ttl)
    {
        $ttl = $this->normalizeTtl($ttl);

        return (bool) $this->redis->set($this->key($key), (string) time(), ['nx', 'ex' => $ttl]);
    }

    /**
     * @param  string  $key
     * @return bool
     */
    public function exists($key)
    {
        return (bool) $this->redis->exists($this->key($key));
    }

    /**
     * @param  array  $lock
     * @return bool
     */
    public function release(array $lock)
    {
        $script = '
            if redis.call("GET", KEYS[1]) == ARGV[1] then
                return redis.call("DEL", KEYS[1])
            else
                return 0
            end
        ';

        return (bool) $this->redis->eval($script, [$lock['key'], $lock['token']], 1);
    }

    /**
     * @param  string  $key
     * @return string
     */
    private function key($key)
    {
        return $this->prefix . $key;
    }

    /**
     * @param  int  $ttl
     * @return int
     */
    private function normalizeTtl($ttl)
    {
        $ttl = (int) $ttl;

        if ($ttl < 1) {
            throw new InvalidArgumentException('Redis lock TTL must be at least 1 second.');
        }

        return $ttl;
    }
}
