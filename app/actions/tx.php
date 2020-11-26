<?php
/**
 * @route tx
 * @param array $addresses
 */

use App\Model\Tx;

return View::fromString(json_encode(
  Tx::create()->getList(['address' => $addresses])
));
