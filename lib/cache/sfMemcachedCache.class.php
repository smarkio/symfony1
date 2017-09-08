<?php
/**
 *
 *
 * @author     Rui Campos <rui.campos@smark.io>
 * @copyright  2017 SMARKIO
 * @license    [SMARKIO_URL_LICENSE_HERE]
 *
 * [SMARKIO_DISCLAIMER]
 */

/**
 * sfCache implementation to Memcache using memcached library.
 *
 * Similar to sfMemcacheCache but using 'memcached' instead of 'memcache'
 *
 * Requires 'memcached' extension to work properly.
 *
 * Class sfMemcachedCache
 */
class sfMemcachedCache extends sfCache
{
    /** @var Memcached */
    protected $memcached = null;

    /**
     * Initializes this sfCache instance.
     *
     * Available options:
     *
     * memcached: A memcached object (optional)
     * compression: 'true' to enable compression (optional)
     * binary_protocol: 'true' to enable binary protocol (optional)
     * servers:    An array of additional servers (keys: host, port, weight)
     *    * host:       The default host (default to localhost)
     *    * port:       The port for the default server (default to 11211)
     *    * weight:     The weight for the server
     *
     * * see sfCache for options available for all drivers
     *
     * @see sfCache
     */
    public function initialize($options = array())
    {
        parent::initialize($options);

        if (!extension_loaded('memcached'))
        {
            throw new sfInitializationException('You must have memcached installed and enabled to use sfMemcachedCache class.');
        }

        if ($this->getOption('memcached'))
        {
            $this->memcached = $this->getOption('memcached');
        }
        else
        {
            $this->memcached = new Memcached();

            if ($this->getOption('servers'))
            {
                $this->memcached->addServers($this->getOption('servers'));
            }
            else
            {
                // Fallback to localhost
                $this->memcached->addServer(
                    $this->getOption('host', 'localhost'),
                    $this->getOption('port', 11211),
                    $this->getOption('weight', 100));

            }
            // by default compression is false
            $compression = $this->getOption('compression') ? true : false;
            $this->memcached->setOption(\Memcached::OPT_COMPRESSION, $compression);
            // by default binary_protocol is false
            $binaryProtocol = $this->getOption('binary_protocol') ? true : false;
            $this->memcached->setOption(\Memcached::OPT_BINARY_PROTOCOL, $binaryProtocol);
        }
    }

    /**
     * @see sfCache
     */
    public function getBackend()
    {
        return $this->memcached;
    }

    /**
     * @see sfCache
     */
    public function get($key, $default = null)
    {
        $value = $this->memcached->get($this->getOption('prefix').$key);

        return false === $value ? $default : $value;
    }

    /**
     * @see sfCache
     */
    public function has($key)
    {
        return !(false === $this->memcached->get($this->getOption('prefix').$key));
    }

    /**
     * @see sfCache
     */
    public function set($key, $data, $lifetime = null)
    {
        $lifetime = null === $lifetime ? $this->getOption('lifetime') : $lifetime;

        // save metadata
        $this->setMetadata($key, $lifetime);

        // save key for removePattern()
        if ($this->getOption('storeCacheInfo', false))
        {
            $this->setCacheInfo($key);
        }

        if (false !== $this->memcached->replace($this->getOption('prefix').$key, $data, time() + $lifetime))
        {
            return true;
        }

        return $this->memcached->set($this->getOption('prefix').$key, $data, time() + $lifetime);
    }

    /**
     * @see sfCache
     */
    public function remove($key)
    {
        $this->memcached->delete($this->getOption('prefix').'_metadata'.self::SEPARATOR.$key);

        return $this->memcached->delete($this->getOption('prefix').$key);
    }

    /**
     * @see sfCache
     */
    public function clean($mode = sfCache::ALL)
    {
        if (sfCache::ALL === $mode)
        {
            return $this->memcached->flush();
        }
    }

    /**
     * @see sfCache
     */
    public function getLastModified($key)
    {
        if (false === ($retval = $this->getMetadata($key)))
        {
            return 0;
        }

        return $retval['lastModified'];
    }

    /**
     * @see sfCache
     */
    public function getTimeout($key)
    {
        if (false === ($retval = $this->getMetadata($key)))
        {
            return 0;
        }

        return $retval['timeout'];
    }

    /**
     * @see sfCache
     */
    public function removePattern($pattern)
    {
        if (!$this->getOption('storeCacheInfo', false))
        {
            throw new sfCacheException('To use the "removePattern" method, you must set the "storeCacheInfo" option to "true".');
        }

        $regexp = self::patternToRegexp($this->getOption('prefix').$pattern);

        foreach ($this->getCacheInfo() as $key)
        {
            if (preg_match($regexp, $key))
            {
                $this->memcached->delete($key);
            }
        }
    }

    /**
     * @see sfCache
     */
    public function getMany($keys)
    {
        $values = array();
        foreach ($this->memcached->get(array_map(create_function('$k', 'return "'.$this->getOption('prefix').'".$k;'), $keys)) as $key => $value)
        {
            $values[str_replace($this->getOption('prefix'), '', $key)] = $value;
        }

        return $values;
    }

    /**
     * Gets metadata about a key in the cache.
     *
     * @param string $key A cache key
     *
     * @return array An array of metadata information
     */
    protected function getMetadata($key)
    {
        return $this->memcached->get($this->getOption('prefix').'_metadata'.self::SEPARATOR.$key);
    }

    /**
     * Stores metadata about a key in the cache.
     *
     * @param string $key      A cache key
     * @param string $lifetime The lifetime
     */
    protected function setMetadata($key, $lifetime)
    {
        $this->memcached->set($this->getOption('prefix').'_metadata'.self::SEPARATOR.$key, array('lastModified' => time(), 'timeout' => time() + $lifetime),  $lifetime);
    }

    /**
     * Updates the cache information for the given cache key.
     *
     * @param string $key The cache key
     */
    protected function setCacheInfo($key)
    {
        $keys = $this->memcached->get($this->getOption('prefix').'_metadata');
        if (!is_array($keys))
        {
            $keys = array();
        }
        $keys[] = $this->getOption('prefix').$key;
        $this->memcached->set($this->getOption('prefix').'_metadata', $keys, 0);
    }

    /**
     * Gets cache information.
     */
    protected function getCacheInfo()
    {
        $keys = $this->memcached->get($this->getOption('prefix').'_metadata');
        if (!is_array($keys))
        {
            return array();
        }

        return $keys;
    }
}
