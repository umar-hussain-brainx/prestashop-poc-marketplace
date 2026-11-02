<?php

declare(strict_types=1);

namespace BrainX\Csv;

use BrainX\Csv\Validation\OrderStatusRowValidator;
use BrainX\Marketplace\Infrastructure\Logger;

/**
 * Applies a vendor order status update (CSV row or direct call) to bxmp_vendor_order and dispatches
 * actionBxmpVendorOrderStatusUpdate (id_vendor_order, old_status, new_status, + tracking_number, carrier_name, source)
 * when the status changes. Transitions: pending → accepted|shipped|cancelled, accepted → shipped|cancelled,
 * shipped → delivered (same status = tracking / carrier update only).
 */
final class OrderStatusService
{
    /**
     * @param int $idVendor 0 = any vendor (admin scope), else the order must belong to this vendor
     * @param string $reference bxmp_vendor_order.reference or numeric id_vendor_order
     *
     * @return array{id_vendor_order:int, old_status:string, new_status:string, changed:bool}
     *
     * @throws \InvalidArgumentException unknown order / forbidden transition
     */
    public function apply(int $idVendor, string $reference, ?string $status, ?string $tracking = null, ?string $carrier = null, string $source = 'csv'): array
    {
        $order = $this->findOrder($idVendor, $reference);
        if (!$order) {
            throw new \InvalidArgumentException('vendor_order_reference: "' . $reference . '" not found' . ($idVendor ? ' for this vendor' : ''));
        }
        $old = (string) $order['status'];
        $new = $status ?? $old;
        if (!OrderStatusRowValidator::canTransition($old, $new)) {
            throw new \InvalidArgumentException('status: transition ' . $old . ' → ' . $new . ' is not allowed');
        }
        $fields = ['status' => pSQL($new), 'date_upd' => date('Y-m-d H:i:s')];
        if ($tracking !== null && $tracking !== '') {
            $fields['tracking_number'] = pSQL($tracking);
        }
        if ($carrier !== null && $carrier !== '') {
            $fields['carrier_name'] = pSQL($carrier);
        }
        $changed = $new !== $old
            || (isset($fields['tracking_number']) && $tracking !== (string) $order['tracking_number'])
            || (isset($fields['carrier_name']) && $carrier !== (string) $order['carrier_name']);
        if ($changed) {
            \Db::getInstance()->update('bxmp_vendor_order', $fields, 'id_vendor_order = ' . (int) $order['id_vendor_order']);
        }
        if ($new !== $old) {
            \Hook::exec('actionBxmpVendorOrderStatusUpdate', [
                'id_vendor_order' => (int) $order['id_vendor_order'],
                'old_status' => $old,
                'new_status' => $new,
                'tracking_number' => $fields['tracking_number'] ?? $order['tracking_number'],
                'carrier_name' => $fields['carrier_name'] ?? $order['carrier_name'],
                'source' => $source,
            ]);
            Logger::channel('csv')->info('Vendor order status updated', ['id_vendor_order' => (int) $order['id_vendor_order'], 'old' => $old, 'new' => $new, 'source' => $source]);
        }

        return ['id_vendor_order' => (int) $order['id_vendor_order'], 'old_status' => $old, 'new_status' => $new, 'changed' => $changed];
    }

    private function findOrder(int $idVendor, string $reference): ?array
    {
        $reference = trim($reference);
        $vendorCond = $idVendor ? ' AND id_vendor = ' . $idVendor : '';
        $db = \Db::getInstance();
        $row = $db->getRow('SELECT * FROM `' . _DB_PREFIX_ . "bxmp_vendor_order` WHERE reference = '" . pSQL($reference) . "'" . $vendorCond . ' ORDER BY id_vendor_order DESC');
        if (!$row && ctype_digit($reference)) {
            $row = $db->getRow('SELECT * FROM `' . _DB_PREFIX_ . 'bxmp_vendor_order` WHERE id_vendor_order = ' . (int) $reference . $vendorCond);
        }

        return $row ?: null;
    }
}
