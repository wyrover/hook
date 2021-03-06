<?php namespace Hook\Database\Schema;

use Hook\Cache\Cache as Store;
use Hook\Security\Encryption\Encrypter as Encrypter;

use Illuminate\Cache\CacheManager;

class Cache
{
    protected static $store;

    public static function forever($collection, $value) {
        return static::getStore()->forever($collection, $value);
    }

    public static function get($collection) {
        return static::getStore()->get($collection) ?: array();
    }

    public static function getStore() {
        if (!static::$store) {
            $manager = new CacheManager(array(
                'encrypter' => Encrypter::getInstance(),
                'db' => \DLModel::getConnectionResolver(),
                'config' => array(
                    'cache.driver' => 'database',
                    'cache.connection' => 'default',
                    'cache.table' => 'cache',
                    'cache.prefix' => 'schema_'
                )
            ));
            static::$store = $manager->driver('database')->getStore();
        }
        return static::$store;
    }

}
