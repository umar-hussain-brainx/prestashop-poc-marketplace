<?php
/**
 * Action endpoint used by the vendor split panel of the BO order page (displayAdminOrderMain):
 * updates status / carrier / tracking of one vendor sub-order (independent fulfilment per vendor) and dispatches
 * actionBxmpVendorOrderStatusUpdate, then redirects back to the order.
 */
if (!defined('_PS_VERSION_')) {
    exit;
}
require_once _PS_MODULE_DIR_ . 'bxmarketplace/vendor/autoload.php';

use BrainX\Marketplace\Infrastructure\Logger;

class AdminBxmpCheckoutVendorOrdersController extends ModuleAdminController
{
    public function __construct()
    {
        $this->bootstrap = true;
        parent::__construct();
    }

    public function postProcess()
    {
        $idOrder = (int) Tools::getValue('id_order');
        if (Tools::isSubmit('submitBxmpVendorOrder')) {
            $vo = new BxmpVendorOrder((int) Tools::getValue('id_vendor_order'));
            if (Validate::isLoadedObject($vo) && (int) $vo->id_order === $idOrder) {
                $old = (string) $vo->status;
                $new = (string) Tools::getValue('status');
                if (in_array($new, BxmpVendorOrder::STATUSES, true)) {
                    $vo->status = $new;
                }
                $vo->carrier_name = mb_substr(trim((string) Tools::getValue('carrier_name')), 0, 128) ?: null;
                $vo->tracking_number = mb_substr(trim((string) Tools::getValue('tracking_number')), 0, 128) ?: null;
                $vo->date_upd = date('Y-m-d H:i:s');
                if ($vo->update()) {
                    if ($old !== $vo->status) {
                        Hook::exec('actionBxmpVendorOrderStatusUpdate', ['id_vendor_order' => (int) $vo->id, 'old_status' => $old, 'new_status' => $vo->status]);
                    }
                    Logger::channel('checkout')->info('Vendor order updated from BO order page', ['id_vendor_order' => (int) $vo->id, 'status' => $vo->status,
                        'tracking' => $vo->tracking_number, 'id_employee' => (int) $this->context->employee->id]);
                }
            }
        }
        Tools::redirectAdmin($this->context->link->getAdminLink('AdminOrders', true, ['route' => 'admin_orders_view', 'orderId' => $idOrder], ['id_order' => $idOrder, 'vieworder' => 1]) . '&bxmpco_updated=1');
    }
}
