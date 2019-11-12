<?php

/**
 * jrobens@interlated.com.au 2019-04
 */
class ColongShipping {

  const MODULE_NAME = 'colongshipping';

  // Keep a total of the domestic postage items.
  // Possibly should be it's own line item.
  var $domestic_postage = 0;

  var $australia_post = 0;

  var $shippingLineItemWrapped;

  var $orderWrapped;

  var $orderClone;

  var $updatedOrderClone = FALSE;

  /**
   * Construct
   *
   * @param \stdClass $shipping_line_item
   */
  public function __construct(stdClass $shipping_line_item) {
    $this->shippingLineItemWrapped = entity_metadata_wrapper('commerce_line_item', $shipping_line_item);

    // Only if regular parcel
    if ($this->checkValidity()) {
      $commerce_order = commerce_order_load($this->shippingLineItemWrapped->order_id->value());
      $this->orderWrapped = entity_metadata_wrapper('commerce_order', $commerce_order);
      $this->orderClone = clone($commerce_order);
    }
  }

  /**
   * Only applicable to regular parcel shipping. Specifically avoiding
   * international shipping.
   */
  public function checkValidity() {
    if ($this->shippingLineItemWrapped->commerce_shipping_service->value() != "regular_parcel_standard") {
      return FALSE;
    }

    return TRUE;
  }

  /**
   *
   */
  public function checkOrder() {
    // Remove items with a specified delivery price from the order. Add the delivery price and
    // get the australia post interface to calculate the rest.
    foreach ($this->orderWrapped->commerce_line_items->value() as $current_line_item) {
      $this->checkShippingLineItem($current_line_item);
    }

    $this->recalculateAusPostShipping();
    $commerce_unit_price = $this->shippingLineItemWrapped->commerce_unit_price->value();
    $commerce_unit_price['amount'] = $this->domestic_postage + $this->australia_post['amount'];
    watchdog(WATCHDOG_ALERT, "Recalculated domestic postage at %rate. Domestic postage is %dom. Auspost is %auspost ",
      array('%rate' =>$commerce_unit_price['amount'],
        '%dom' => $this->domestic_postage,
        '%auspost' => $this->australia_post['amount']));


    $this->shippingLineItemWrapped->commerce_unit_price->set($commerce_unit_price);
    $this->shippingLineItemWrapped->save();
  }

  /**
   * Take out the items with domestic postage and recalculate the postage
   * without them.
   *
   * @param stdClass $line_item
   *
   * @throws \EntityMetadataWrapperException
   */
  protected function checkShippingLineItem(stdClass $line_item) {
    //commerce_australia_post_service_rate_order($shipping_service, $order)
    $current_line_item_wrapped = entity_metadata_wrapper('commerce_line_item', $line_item);
    if ($current_line_item_wrapped->raw()->type != 'product') {
      return;
    }

    $product = $current_line_item_wrapped->commerce_product->value();
    $product_wrapped = entity_metadata_wrapper('commerce_product', $product);

    if ($product_wrapped->__isset('field_domestic_postage')) {
      if ($product_wrapped->field_domestic_postage->value() > 0) {
        $this->domestic_postage += $product_wrapped->field_domestic_postage->value()['amount'] * $current_line_item_wrapped->quantity->value();
        $this->removeLineItem($this->orderClone, $line_item->line_item_id);
      }
    }
  }

  /**
   * Remove a line item. Also could alter weight to ignore
   *   drupal_alter('commerce_physical_product_line_item_weight', $weight, $line_item);
   *
   * @param \stdClass $order
   * @param $line_item_id
   */
  private function removeLineItem(stdClass $order, $line_item_id) {
    $order_wrapped = entity_metadata_wrapper('commerce_order', $order);
    foreach ($order_wrapped->commerce_line_items as $line_item_key => $line_item) {
      if ($line_item->line_item_id->value() == $line_item_id) {
        $this->updatedOrderClone = TRUE;
        unset($order->commerce_line_items[LANGUAGE_NONE][$line_item_key]);
      }
    }
  }

  /**
   *
   */
  private function recalculateAusPostShipping() {
    // Get the new postage from the australia post module.
    // Check for module exists right at the start
    $shipping_service_name = $this->shippingLineItemWrapped->commerce_shipping_service->value();
    $shipping_service = commerce_shipping_service_load($shipping_service_name);
    // Overwrites each time, so ok to incrementally although the API is slow.
    if (module_exists('commerce_australia_post')) {
      // false should be OK.
      $order_wrapped = entity_metadata_wrapper('commerce_order', $this->orderClone);
      $clone_line_items_count = count($order_wrapped->commerce_line_items);
      if ($clone_line_items_count != 0) {
        $this->australia_post = commerce_australia_post_service_rate_order($shipping_service, $this->orderClone);
      }

      if ($clone_line_items_count != 0) {
        $this->australia_post = 0;
      }

      watchdog(WATCHDOG_ALERT, "Recalculated Australia post rate at $%rate based on %count items",
        array('%rate' => $this->australia_post['amount'],
          '%count' => $clone_line_items_count));
    }
  }

  /**
   * @param $message
   *
   * @return string
   */
  public static function errorMessage($message) {
    $error_markup = '<div class="alert alert-block alert-danger messages error">
                <a class="close" data-dismiss="alert" href="#">×</a>
                <h4 class="element-invisible">Error message</h4>';
    $error_markup .= t($message);
    $error_markup .= '</div>';
    return $error_markup;
  }

}
