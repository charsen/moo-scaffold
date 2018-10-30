<?php
namespace Charsen\Scaffold;

class Utility
{
    protected $prefix = 'scaffold';

    public function getDatabasePath($folder = 'schema', $relative = false)
    {
        if ($relative)
        {
            return '/' . trim($this->getConfig('database.' . $folder), '/') . '/';
        }

        return base_path() . '/' . trim($this->getConfig('database.' . $folder), '/') . '/';
    }

    public function getApiPath($folder = 'schema', $relative = false)
    {
        if ($relative)
        {
            return '/' . trim($this->getConfig('api.' . $folder), '/') . '/';
        }

        return base_path() . '/' . trim($this->getConfig('api.' . $folder), '/') . '/';
    }

    /**
     * Helper to get the config values.
     *
     * @param  string  $key
     * @param  string  $default
     *
     * @return mixed
     */
    public function getConfig($key, $default = null)
    {
        return config("{$this->prefix}.$key", $default);
    }

}
