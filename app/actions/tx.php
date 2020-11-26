<?php
/**
 * @route tx
 * @param array $addresses
 */

use App\Model\Tx;

return View::fromString(json_encode(
  array_values(Tx::create()->getList(['address' => $addresses]))
));
