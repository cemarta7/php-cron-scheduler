<?php namespace Tests;

class FakeRedis
{
    public $values = [];

    public $options = [];

    public $history = [];

    public function set($key, $value, $options = null)
    {
        if (is_array($options) && in_array('nx', $options, true) && isset($this->values[$key])) {
            return false;
        }

        $this->values[$key] = $value;
        $this->options[$key] = $options;
        $this->history[] = [
            'key' => $key,
            'value' => $value,
            'options' => $options,
        ];

        return true;
    }

    public function exists($key)
    {
        return isset($this->values[$key]) ? 1 : 0;
    }

    public function eval($script, $args, $numKeys)
    {
        $key = $args[0];
        $token = $args[1];

        if (isset($this->values[$key]) && $this->values[$key] === $token) {
            unset($this->values[$key]);
            unset($this->options[$key]);

            return 1;
        }

        return 0;
    }
}
