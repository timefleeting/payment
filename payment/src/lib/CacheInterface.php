<?php

namespace Netflying\Payment\lib;


/**
 * 缓存类接口
 */
interface CacheInterface
{
    /**
     * 获取缓存
     */
    public function get($name, $default = false);

    /**
     * 写入缓存
     */
    public function set($name, $value, $expire = null);

}
