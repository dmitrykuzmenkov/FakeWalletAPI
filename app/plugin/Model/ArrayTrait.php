<?php
namespace Plugin\Model;
trait ArrayTrait {
  public function offsetSet($k, $v) {
    $this->data[$k] = $v;
  }

  public function offsetGet($k) {
    return isset($this->data[$k]) ? $this->data[$k] : null;
  }

  public function offsetExists($k) {
    return isset($this->data[$k]);
  }

  public function offsetUnset($k) {
    $this->data[$k] = null;
  }
}
